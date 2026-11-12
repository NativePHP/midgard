import Foundation

// Native SSE transport for the Asgard plugin.
//
// PHP calls Asgard.Stream.Open / Asgard.Stream.Close (AsgardStreamFunctions
// below). The connection lives here so PHP never blocks on it. Everything
// that happens on a stream goes back to PHP as a native event. Android twin:
// resources/android/AsgardStream.kt.

enum AsgardStreamEvents {
    static let message = "Native\\Api\\Client\\Asgard\\Events\\StreamMessage"
    static let opened = "Native\\Api\\Client\\Asgard\\Events\\StreamOpened"
    static let closed = "Native\\Api\\Client\\Asgard\\Events\\StreamClosed"
}

/// Owns every open stream, by id.
///
/// All state, and every URLSession delegate callback, runs on `queue`, so the
/// sessions need no locking. Events reach PHP through `sink`, which hops to
/// the main thread the way the framework's other async native events do.
final class AsgardStreamManager {
    static let shared = AsgardStreamManager()

    let queue = DispatchQueue(label: "com.nativephp.asgard.stream")

    private var sessions: [String: AsgardStreamSession] = [:]

    /// Where events go. Replaced in tests.
    var sink: (_ event: String, _ payload: [String: Any?]) -> Void = { event, payload in
        DispatchQueue.main.async {
            LaravelBridge.shared.send?(event, payload)
        }
    }

    func emit(_ event: String, _ payload: [String: Any?]) {
        sink(event, payload)
    }

    /// Open a stream. An id that is already open is replaced, and the old
    /// connection stops without a StreamClosed.
    func open(id: String, url: URL, method: String, headers: [String: String], body: Data?) {
        queue.async {
            self.sessions[id]?.stop()

            let session = AsgardStreamSession(
                manager: self,
                id: id,
                url: url,
                method: method,
                headers: headers,
                body: body
            )
            self.sessions[id] = session
            session.start()
        }
    }

    /// Close a stream for good. A stream that was open reports StreamClosed
    /// with no error and no reconnect.
    func close(id: String) {
        queue.async {
            guard let session = self.sessions.removeValue(forKey: id) else { return }
            session.stop()
            self.emit(AsgardStreamEvents.closed, ["id": id, "error": nil, "willReconnect": false])
        }
    }

    /// A session gave up on its own. Must be called on `queue`.
    func finished(_ session: AsgardStreamSession) {
        if sessions[session.id] === session {
            sessions.removeValue(forKey: session.id)
        }
    }
}

/// One stream: a connection plus the reconnect loop around it.
/// Only touched on the manager's queue.
final class AsgardStreamSession: NSObject, URLSessionDataDelegate {
    let id: String

    private let manager: AsgardStreamManager
    private let url: URL
    private let method: String
    private let headers: [String: String]
    private let body: Data?

    private var stopped = false
    private var connection: URLSession?
    private var parser = AsgardSseParser()
    private var lastEventId: String?
    private var retryMs = AsgardSseBackoff.defaultRetryMs
    private var failures = 0
    private var connected = false
    private var fatalReason: String?
    private var httpFailure: String?

    init(manager: AsgardStreamManager, id: String, url: URL, method: String, headers: [String: String], body: Data?) {
        self.manager = manager
        self.id = id
        self.url = url
        self.method = method
        self.headers = headers
        self.body = body
        super.init()
    }

    func start() {
        connect()
    }

    func stop() {
        stopped = true
        connection?.invalidateAndCancel()
        connection = nil
    }

    private func connect() {
        if stopped { return }

        var request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 300)
        request.httpMethod = method
        request.setValue("text/event-stream", forHTTPHeaderField: "Accept")
        request.setValue("no-cache", forHTTPHeaderField: "Cache-Control")
        for (name, value) in headers {
            request.setValue(value, forHTTPHeaderField: name)
        }
        if let last = lastEventId, !last.isEmpty {
            request.setValue(last, forHTTPHeaderField: "Last-Event-ID")
        }
        if method == "POST", let body = body {
            request.httpBody = body
        }

        let configuration = URLSessionConfiguration.ephemeral
        configuration.timeoutIntervalForRequest = 300
        configuration.timeoutIntervalForResource = 7 * 24 * 60 * 60

        let delegateQueue = OperationQueue()
        delegateQueue.maxConcurrentOperationCount = 1
        delegateQueue.underlyingQueue = manager.queue

        let session = URLSession(configuration: configuration, delegate: self, delegateQueue: delegateQueue)

        connection = session
        parser = AsgardSseParser(lastEventId: lastEventId)
        connected = false
        fatalReason = nil
        httpFailure = nil

        session.dataTask(with: request).resume()
    }

    // MARK: URLSessionDataDelegate

    func urlSession(
        _ session: URLSession,
        dataTask: URLSessionDataTask,
        didReceive response: URLResponse,
        completionHandler: @escaping (URLSession.ResponseDisposition) -> Void
    ) {
        if stopped || session !== connection {
            completionHandler(.cancel)
            return
        }

        let status = (response as? HTTPURLResponse)?.statusCode ?? 0

        switch AsgardSseStatus.classify(status) {
        case .accept:
            connected = true
            failures = 0
            manager.emit(AsgardStreamEvents.opened, ["id": id])
            completionHandler(.allow)
        case .stop:
            fatalReason = "HTTP \(status)"
            completionHandler(.cancel)
        case .retry:
            httpFailure = "HTTP \(status)"
            completionHandler(.cancel)
        }
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        if stopped || session !== connection || !connected { return }

        let events = parser.feed(data)

        if let retry = parser.retryMs {
            retryMs = retry
        }
        lastEventId = parser.lastEventId

        for event in events {
            manager.emit(AsgardStreamEvents.message, [
                "id": id,
                "event": event.event,
                "data": event.data,
                "lastEventId": event.lastEventId,
            ])
        }
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        if stopped || session !== connection { return }

        session.finishTasksAndInvalidate()
        connection = nil

        if let fatal = fatalReason {
            stopped = true
            manager.emit(AsgardStreamEvents.closed, ["id": id, "error": fatal, "willReconnect": false])
            manager.finished(self)
            return
        }

        let reason: String? = httpFailure ?? error?.localizedDescription

        failures += 1
        let delay = AsgardSseBackoff.delayMs(retryMs: retryMs, failures: failures)

        manager.emit(AsgardStreamEvents.closed, ["id": id, "error": reason, "willReconnect": true])

        manager.queue.asyncAfter(deadline: .now() + .milliseconds(delay)) { [weak self] in
            self?.connect()
        }
    }
}

// MARK: - Bridge functions

/// Asgard.Stream.* bridge functions. Registration comes from nativephp.json.
enum AsgardStreamFunctions {

    /// `Asgard.Stream.Open` {id, url, method, headers, body}
    class Open: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String, !id.isEmpty else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "id is required")
            }

            guard let urlString = parameters["url"] as? String,
                  let url = URL(string: urlString),
                  let scheme = url.scheme?.lowercased(),
                  scheme == "http" || scheme == "https" else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "url must be an http or https URL")
            }

            let method = ((parameters["method"] as? String) ?? "GET").uppercased()
            guard method == "GET" || method == "POST" else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "method must be GET or POST")
            }

            var headers: [String: String] = [:]
            if let raw = parameters["headers"] as? [String: Any] {
                for (name, value) in raw {
                    headers[name] = "\(value)"
                }
            }

            let body = (parameters["body"] as? String)?.data(using: .utf8)

            AsgardStreamManager.shared.open(id: id, url: url, method: method, headers: headers, body: body)

            return BridgeResponse.success(data: ["id": id])
        }
    }

    /// `Asgard.Stream.Close` {id}
    class Close: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let id = parameters["id"] as? String, !id.isEmpty else {
                return BridgeResponse.error(code: "INVALID_PARAMETERS", message: "id is required")
            }

            AsgardStreamManager.shared.close(id: id)

            return BridgeResponse.success(data: ["id": id])
        }
    }
}
