import Foundation

// Tests for resources/ios/AsgardSse.swift. Run from the package root:
//
//   swiftc resources/ios/AsgardSse.swift tests/Native/ios/main.swift -o /tmp/asgard-sse-tests && /tmp/asgard-sse-tests
//
// Exits non-zero on the first failure.

var failures = 0

func check<T: Equatable>(_ actual: T, _ expected: T, _ name: String, line: Int = #line) {
    if actual == expected {
        print("ok   \(name)")
    } else {
        failures += 1
        print("FAIL \(name) (line \(line))\n  expected: \(expected)\n  actual:   \(actual)")
    }
}

func ev(_ event: String, _ data: String, _ id: String? = nil) -> AsgardSseEvent {
    return AsgardSseEvent(event: event, data: data, lastEventId: id)
}

// A simple message

do {
    var p = AsgardSseParser()
    check(p.feed("data: hello\n\n"), [ev("message", "hello")], "single data line")
}

// A named event

do {
    var p = AsgardSseParser()
    check(p.feed("event: region\ndata: {\"a\":1}\n\n"), [ev("region", "{\"a\":1}")], "named event")
}

// Multi-line data is joined with \n

do {
    var p = AsgardSseParser()
    check(p.feed("data: one\ndata: two\ndata: three\n\n"), [ev("message", "one\ntwo\nthree")], "multi-line data")
}

// An empty data line still counts as data

do {
    var p = AsgardSseParser()
    check(p.feed("data:\n\n"), [ev("message", "")], "empty data line dispatches")
    check(p.feed("data\n\n"), [ev("message", "")], "data with no colon dispatches")
    check(p.feed("data:\ndata:\n\n"), [ev("message", "\n")], "two empty data lines")
}

// Only one leading space is stripped

do {
    var p = AsgardSseParser()
    check(p.feed("data:  two spaces\n\n"), [ev("message", " two spaces")], "one leading space stripped")
    check(p.feed("data:nospace\n\n"), [ev("message", "nospace")], "no space after colon")
}

// Comments are ignored

do {
    var p = AsgardSseParser()
    check(p.feed(": keep-alive\n\n"), [], "comment alone dispatches nothing")
    check(p.feed(": hi\ndata: x\n: again\n\n"), [ev("message", "x")], "comments between fields")
}

// Blank line with no data resets the event type

do {
    var p = AsgardSseParser()
    check(p.feed("event: ping\n\ndata: x\n\n"), [ev("message", "x")], "event type does not leak into next event")
}

// Ids persist across events

do {
    var p = AsgardSseParser()
    let out = p.feed("id: 7\ndata: a\n\ndata: b\n\nid: 9\ndata: c\n\n")
    check(out, [ev("message", "a", "7"), ev("message", "b", "7"), ev("message", "c", "9")], "id persists until changed")
    check(p.lastEventId, "9", "parser lastEventId")
}

do {
    var p = AsgardSseParser()
    check(p.feed("id: 5\n\n"), [], "id with no data dispatches nothing")
    check(p.lastEventId, "5", "id with no data is still remembered")
    check(p.feed("data: x\n\n"), [ev("message", "x", "5")], "remembered id attached to next event")
}

do {
    var p = AsgardSseParser()
    _ = p.feed("id: 5\n")
    _ = p.feed("id: \n")
    check(p.lastEventId, nil, "empty id clears it")
}

do {
    var p = AsgardSseParser()
    _ = p.feed(Array("id: a".utf8) + [0x00] + Array("b\n".utf8))
    check(p.lastEventId, nil, "id containing NUL is ignored")
}

do {
    var p = AsgardSseParser(lastEventId: "42")
    check(p.feed("data: x\n\n"), [ev("message", "x", "42")], "seeded last event id")
}

// Retry

do {
    var p = AsgardSseParser()
    _ = p.feed("retry: 1500\n")
    check(p.retryMs, 1500, "retry parsed")
    _ = p.feed("retry: abc\n")
    check(p.retryMs, 1500, "non-numeric retry ignored")
    _ = p.feed("retry: 12x\n")
    check(p.retryMs, 1500, "partly numeric retry ignored")
    _ = p.feed("retry: 99999999999999999999999\n")
    check(p.retryMs, 1500, "overflowing retry ignored")
}

// Line endings

do {
    var p = AsgardSseParser()
    check(p.feed("data: a\r\n\r\n"), [ev("message", "a")], "CRLF")
    check(p.feed("data: b\r\r"), [ev("message", "b")], "lone CR")
    check(p.feed("data: c\n\n"), [ev("message", "c")], "LF")
    check(p.feed("data: d\r\ndata: e\r\n\r\n"), [ev("message", "d\ne")], "CRLF multi-line")
}

// A CRLF split across chunks is one line break, not two

do {
    var p = AsgardSseParser()
    check(p.feed("data: a\r"), [], "CR at end of chunk")
    check(p.feed("\n\r\n"), [ev("message", "a")], "LF at start of next chunk joins the CR")
}

// Chunk boundaries anywhere

do {
    let stream = "event: tick\nid: 1\ndata: {\"n\":1}\n\ndata: x\ndata: y\n\n"
    let expected = [ev("tick", "{\"n\":1}", "1"), ev("message", "x\ny", "1")]
    var whole = AsgardSseParser()
    check(whole.feed(stream), expected, "whole stream")

    var byteAtATime = AsgardSseParser()
    var out: [AsgardSseEvent] = []
    for b in Array(stream.utf8) {
        out += byteAtATime.feed([b])
    }
    check(out, expected, "one byte at a time")
}

// Multi-byte UTF-8 split across chunks

do {
    let bytes = Array("data: h\u{00E9}llo \u{1F600}\n\n".utf8)
    var p = AsgardSseParser()
    var out: [AsgardSseEvent] = []
    for b in bytes {
        out += p.feed([b])
    }
    check(out, [ev("message", "h\u{00E9}llo \u{1F600}")], "UTF-8 split across chunks")
}

// BOM

do {
    var p = AsgardSseParser()
    check(p.feed([0xEF, 0xBB, 0xBF] + Array("data: x\n\n".utf8)), [ev("message", "x")], "leading BOM stripped")
}

// Unknown fields and a field with no colon

do {
    var p = AsgardSseParser()
    check(p.feed("foo: bar\nevent\ndata: x\n\n"), [ev("message", "x")], "unknown field and empty event name")
}

// An unfinished event at the end of the stream is never dispatched

do {
    var p = AsgardSseParser()
    check(p.feed("data: half"), [], "no newline, no event")
    check(p.feed("\n"), [], "line done but no blank line, no event")
    check(p.feed("\n"), [ev("message", "half")], "blank line dispatches")
}

// A colon inside the value is kept

do {
    var p = AsgardSseParser()
    check(p.feed("data: a:b:c\n\n"), [ev("message", "a:b:c")], "colon in value")
}

// Status handling

check(AsgardSseStatus.classify(200), .accept, "200 accepted")
check(AsgardSseStatus.classify(299), .accept, "299 accepted")
check(AsgardSseStatus.classify(204), .stop, "204 stops")
check(AsgardSseStatus.classify(400), .stop, "400 stops")
check(AsgardSseStatus.classify(404), .stop, "404 stops")
check(AsgardSseStatus.classify(499), .stop, "499 stops")
check(AsgardSseStatus.classify(500), .retry, "500 retries")
check(AsgardSseStatus.classify(503), .retry, "503 retries")
check(AsgardSseStatus.classify(0), .retry, "no status retries")

// Backoff

check(AsgardSseBackoff.delayMs(retryMs: 3000, failures: 1), 3000, "first retry waits retry ms")
check(AsgardSseBackoff.delayMs(retryMs: 3000, failures: 2), 6000, "second retry doubles")
check(AsgardSseBackoff.delayMs(retryMs: 3000, failures: 3), 12000, "third retry doubles again")
check(AsgardSseBackoff.delayMs(retryMs: 3000, failures: 5), 30000, "capped at 30s")
check(AsgardSseBackoff.delayMs(retryMs: 3000, failures: 500), 30000, "huge failure count stays capped")
check(AsgardSseBackoff.delayMs(retryMs: 60000, failures: 1), 30000, "server retry above cap is capped")
check(AsgardSseBackoff.delayMs(retryMs: 500, failures: 1), 500, "small retry honoured")
check(AsgardSseBackoff.delayMs(retryMs: Int.max, failures: 19), 30000, "overflow capped")

if failures > 0 {
    print("\n\(failures) failure(s)")
    exit(1)
}

print("\nall passed")
