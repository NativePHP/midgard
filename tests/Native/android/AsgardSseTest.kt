package com.nativephp.plugins.asgard

import kotlin.system.exitProcess

// Tests for resources/android/AsgardSse.kt on a plain JVM. From the package root:
//
//   kotlinc resources/android/AsgardSse.kt tests/Native/android/AsgardSseTest.kt -include-runtime -d /tmp/asgard-sse-tests.jar
//   java -cp /tmp/asgard-sse-tests.jar com.nativephp.plugins.asgard.AsgardSseTestKt
//
// Exits non-zero on the first failure. Mirrors tests/Native/ios/main.swift.

private var failures = 0

private fun <T> check(actual: T, expected: T, name: String) {
    if (actual == expected) {
        println("ok   $name")
    } else {
        failures++
        println("FAIL $name\n  expected: $expected\n  actual:   $actual")
    }
}

private fun ev(event: String, data: String, id: String? = null) = AsgardSseEvent(event, data, id)

fun main() {
    run {
        val p = AsgardSseParser()
        check(p.feed("data: hello\n\n"), listOf(ev("message", "hello")), "single data line")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("event: region\ndata: {\"a\":1}\n\n"), listOf(ev("region", "{\"a\":1}")), "named event")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("data: one\ndata: two\ndata: three\n\n"), listOf(ev("message", "one\ntwo\nthree")), "multi-line data")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("data:\n\n"), listOf(ev("message", "")), "empty data line dispatches")
        check(p.feed("data\n\n"), listOf(ev("message", "")), "data with no colon dispatches")
        check(p.feed("data:\ndata:\n\n"), listOf(ev("message", "\n")), "two empty data lines")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("data:  two spaces\n\n"), listOf(ev("message", " two spaces")), "one leading space stripped")
        check(p.feed("data:nospace\n\n"), listOf(ev("message", "nospace")), "no space after colon")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed(": keep-alive\n\n"), emptyList(), "comment alone dispatches nothing")
        check(p.feed(": hi\ndata: x\n: again\n\n"), listOf(ev("message", "x")), "comments between fields")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("event: ping\n\ndata: x\n\n"), listOf(ev("message", "x")), "event type does not leak into next event")
    }

    run {
        val p = AsgardSseParser()
        val out = p.feed("id: 7\ndata: a\n\ndata: b\n\nid: 9\ndata: c\n\n")
        check(out, listOf(ev("message", "a", "7"), ev("message", "b", "7"), ev("message", "c", "9")), "id persists until changed")
        check(p.lastEventId, "9", "parser lastEventId")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("id: 5\n\n"), emptyList(), "id with no data dispatches nothing")
        check(p.lastEventId, "5", "id with no data is still remembered")
        check(p.feed("data: x\n\n"), listOf(ev("message", "x", "5")), "remembered id attached to next event")
    }

    run {
        val p = AsgardSseParser()
        p.feed("id: 5\n")
        p.feed("id: \n")
        check(p.lastEventId, null, "empty id clears it")
    }

    run {
        val p = AsgardSseParser()
        p.feed(byteArrayOf('i'.code.toByte(), 'd'.code.toByte(), ':'.code.toByte(), ' '.code.toByte(), 'a'.code.toByte(), 0, 'b'.code.toByte(), 10))
        check(p.lastEventId, null, "id containing NUL is ignored")
    }

    run {
        val p = AsgardSseParser("42")
        check(p.feed("data: x\n\n"), listOf(ev("message", "x", "42")), "seeded last event id")
    }

    run {
        val p = AsgardSseParser()
        p.feed("retry: 1500\n")
        check(p.retryMs, 1500L, "retry parsed")
        p.feed("retry: abc\n")
        check(p.retryMs, 1500L, "non-numeric retry ignored")
        p.feed("retry: 12x\n")
        check(p.retryMs, 1500L, "partly numeric retry ignored")
        p.feed("retry: 99999999999999999999999\n")
        check(p.retryMs, 1500L, "overflowing retry ignored")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("data: a\r\n\r\n"), listOf(ev("message", "a")), "CRLF")
        check(p.feed("data: b\r\r"), listOf(ev("message", "b")), "lone CR")
        check(p.feed("data: c\n\n"), listOf(ev("message", "c")), "LF")
        check(p.feed("data: d\r\ndata: e\r\n\r\n"), listOf(ev("message", "d\ne")), "CRLF multi-line")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("data: a\r"), emptyList(), "CR at end of chunk")
        check(p.feed("\n\r\n"), listOf(ev("message", "a")), "LF at start of next chunk joins the CR")
    }

    run {
        val stream = "event: tick\nid: 1\ndata: {\"n\":1}\n\ndata: x\ndata: y\n\n"
        val expected = listOf(ev("tick", "{\"n\":1}", "1"), ev("message", "x\ny", "1"))

        check(AsgardSseParser().feed(stream), expected, "whole stream")

        val p = AsgardSseParser()
        val out = ArrayList<AsgardSseEvent>()
        for (b in stream.toByteArray(Charsets.UTF_8)) {
            out.addAll(p.feed(byteArrayOf(b)))
        }
        check<List<AsgardSseEvent>>(out, expected, "one byte at a time")
    }

    run {
        val p = AsgardSseParser()
        val out = ArrayList<AsgardSseEvent>()
        for (b in "data: héllo 😀\n\n".toByteArray(Charsets.UTF_8)) {
            out.addAll(p.feed(byteArrayOf(b)))
        }
        check<List<AsgardSseEvent>>(out, listOf(ev("message", "héllo 😀")), "UTF-8 split across chunks")
    }

    run {
        val p = AsgardSseParser()
        val bom = byteArrayOf(0xEF.toByte(), 0xBB.toByte(), 0xBF.toByte())
        check(p.feed(bom + "data: x\n\n".toByteArray(Charsets.UTF_8)), listOf(ev("message", "x")), "leading BOM stripped")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("foo: bar\nevent\ndata: x\n\n"), listOf(ev("message", "x")), "unknown field and empty event name")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("data: half"), emptyList(), "no newline, no event")
        check(p.feed("\n"), emptyList(), "line done but no blank line, no event")
        check(p.feed("\n"), listOf(ev("message", "half")), "blank line dispatches")
    }

    run {
        val p = AsgardSseParser()
        check(p.feed("data: a:b:c\n\n"), listOf(ev("message", "a:b:c")), "colon in value")
    }

    check(AsgardSseStatus.classify(200), AsgardSseStatus.Action.ACCEPT, "200 accepted")
    check(AsgardSseStatus.classify(299), AsgardSseStatus.Action.ACCEPT, "299 accepted")
    check(AsgardSseStatus.classify(204), AsgardSseStatus.Action.STOP, "204 stops")
    check(AsgardSseStatus.classify(400), AsgardSseStatus.Action.STOP, "400 stops")
    check(AsgardSseStatus.classify(404), AsgardSseStatus.Action.STOP, "404 stops")
    check(AsgardSseStatus.classify(499), AsgardSseStatus.Action.STOP, "499 stops")
    check(AsgardSseStatus.classify(500), AsgardSseStatus.Action.RETRY, "500 retries")
    check(AsgardSseStatus.classify(503), AsgardSseStatus.Action.RETRY, "503 retries")
    check(AsgardSseStatus.classify(0), AsgardSseStatus.Action.RETRY, "no status retries")

    check(AsgardSseBackoff.delayMs(3000, 1), 3000L, "first retry waits retry ms")
    check(AsgardSseBackoff.delayMs(3000, 2), 6000L, "second retry doubles")
    check(AsgardSseBackoff.delayMs(3000, 3), 12000L, "third retry doubles again")
    check(AsgardSseBackoff.delayMs(3000, 5), 30000L, "capped at 30s")
    check(AsgardSseBackoff.delayMs(3000, 500), 30000L, "huge failure count stays capped")
    check(AsgardSseBackoff.delayMs(60000, 1), 30000L, "server retry above cap is capped")
    check(AsgardSseBackoff.delayMs(500, 1), 500L, "small retry honoured")
    check(AsgardSseBackoff.delayMs(Long.MAX_VALUE, 19), 30000L, "overflow capped")

    if (failures > 0) {
        println("\n$failures failure(s)")
        exitProcess(1)
    }

    println("\nall passed")
}
