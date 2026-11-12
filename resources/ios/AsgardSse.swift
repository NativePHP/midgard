import Foundation

// Pure Server-Sent Events logic: no networking, no UIKit, no NativePHP types.
// That keeps it testable on its own (see tests/Native/ios/main.swift).
// The Kotlin twin is resources/android/AsgardSse.kt.

/// One dispatched event.
struct AsgardSseEvent: Equatable {
    var event: String
    var data: String
    /// The most recent `id:` the server sent on this stream, if any.
    var lastEventId: String?
}

/// Incremental parser for the text/event-stream format
/// (https://html.spec.whatwg.org/multipage/server-sent-events.html#parsing-an-event-stream).
///
/// Feed it bytes as they arrive, in chunks of any size. It splits lines on
/// CRLF, LF or a lone CR (a CR at the end of one chunk and an LF at the start
/// of the next count as one line break), strips a leading BOM, ignores
/// comments and unknown fields, and returns each event when its blank line
/// arrives. An event cut off by the end of the stream is never returned.
struct AsgardSseParser {
    /// The last `id:` seen. Survives across events, and a new parser can be
    /// seeded with it so a reconnect carries on from the same place.
    private(set) var lastEventId: String?

    /// The last valid `retry:` value in milliseconds, if the server sent one.
    private(set) var retryMs: Int?

    private var line: [UInt8] = []
    private var previousWasCR = false
    private var atStreamStart = true
    private var eventType = ""
    private var dataBuffer = ""

    init(lastEventId: String? = nil) {
        self.lastEventId = (lastEventId?.isEmpty ?? true) ? nil : lastEventId
    }

    mutating func feed(_ bytes: [UInt8]) -> [AsgardSseEvent] {
        var events: [AsgardSseEvent] = []

        for byte in bytes {
            if previousWasCR {
                previousWasCR = false
                if byte == 0x0A { continue }
            }

            if byte == 0x0D {
                previousWasCR = true
                processLine(&events)
            } else if byte == 0x0A {
                processLine(&events)
            } else {
                line.append(byte)
            }
        }

        return events
    }

    mutating func feed(_ data: Data) -> [AsgardSseEvent] {
        return feed([UInt8](data))
    }

    mutating func feed(_ text: String) -> [AsgardSseEvent] {
        return feed(Array(text.utf8))
    }

    private mutating func processLine(_ events: inout [AsgardSseEvent]) {
        var bytes = line
        line.removeAll(keepingCapacity: true)

        if atStreamStart {
            atStreamStart = false
            if bytes.count >= 3 && bytes[0] == 0xEF && bytes[1] == 0xBB && bytes[2] == 0xBF {
                bytes.removeFirst(3)
            }
        }

        if bytes.isEmpty {
            dispatch(&events)
            return
        }

        // A line starting with a colon is a comment.
        if bytes[0] == 0x3A { return }

        let nameBytes: [UInt8]
        var valueBytes: [UInt8]

        if let colon = bytes.firstIndex(of: 0x3A) {
            nameBytes = Array(bytes[0..<colon])
            valueBytes = Array(bytes[(colon + 1)...])
            if let first = valueBytes.first, first == 0x20 {
                valueBytes.removeFirst()
            }
        } else {
            nameBytes = bytes
            valueBytes = []
        }

        let name = String(decoding: nameBytes, as: UTF8.self)
        let value = String(decoding: valueBytes, as: UTF8.self)

        switch name {
        case "event":
            eventType = value
        case "data":
            dataBuffer += value
            dataBuffer += "\n"
        case "id":
            if !valueBytes.contains(0) {
                lastEventId = value.isEmpty ? nil : value
            }
        case "retry":
            if !valueBytes.isEmpty && valueBytes.allSatisfy({ $0 >= 0x30 && $0 <= 0x39 }) {
                if let parsed = Int(value) {
                    retryMs = parsed
                }
            }
        default:
            break
        }
    }

    private mutating func dispatch(_ events: inout [AsgardSseEvent]) {
        let type = eventType
        var data = dataBuffer
        eventType = ""
        dataBuffer = ""

        // No data lines means nothing to dispatch (the id above still stands).
        if data.isEmpty { return }

        data.removeLast()
        events.append(AsgardSseEvent(
            event: type.isEmpty ? "message" : type,
            data: data,
            lastEventId: lastEventId
        ))
    }
}

/// What to do with an HTTP status on the stream request.
enum AsgardSseStatus {
    enum Action: Equatable {
        /// 2xx other than 204: the stream is live.
        case accept
        /// 204 and 4xx: the server does not want this stream. Do not reconnect.
        case stop
        /// Anything else: try again after a delay.
        case retry
    }

    static func classify(_ status: Int) -> Action {
        if status == 204 { return .stop }
        if status >= 200 && status < 300 { return .accept }
        if status >= 400 && status < 500 { return .stop }
        return .retry
    }
}

enum AsgardSseBackoff {
    static let defaultRetryMs = 3000
    static let maxDelayMs = 30_000

    /// How long to wait before reconnect number `failures` (1 for the first
    /// retry after a good connection). The server's retry value is the base,
    /// it doubles on each consecutive failure, and it never exceeds 30s.
    static func delayMs(retryMs: Int, failures: Int) -> Int {
        let base = max(retryMs, 0)
        let exponent = max(failures - 1, 0)

        if exponent >= 20 { return maxDelayMs }

        let result = base.multipliedReportingOverflow(by: 1 << exponent)
        if result.overflow { return maxDelayMs }

        return min(result.partialValue, maxDelayMs)
    }
}
