package com.nativephp.plugins.asgard

import java.io.ByteArrayOutputStream

// Pure Server-Sent Events logic: no networking, no Android types. That keeps
// it testable on a plain JVM (see tests/Native/android/AsgardSseTest.kt).
// The Swift twin is resources/ios/AsgardSse.swift.

/** One dispatched event. [lastEventId] is the most recent `id:` seen on the stream, if any. */
data class AsgardSseEvent(
    val event: String,
    val data: String,
    val lastEventId: String?,
)

/**
 * Incremental parser for the text/event-stream format
 * (https://html.spec.whatwg.org/multipage/server-sent-events.html#parsing-an-event-stream).
 *
 * Feed it bytes as they arrive, in chunks of any size. It splits lines on
 * CRLF, LF or a lone CR (a CR at the end of one chunk and an LF at the start
 * of the next count as one line break), strips a leading BOM, ignores
 * comments and unknown fields, and returns each event when its blank line
 * arrives. An event cut off by the end of the stream is never returned.
 */
class AsgardSseParser(lastEventId: String? = null) {
    /** The last `id:` seen. A new parser can be seeded with it so a reconnect carries on. */
    var lastEventId: String? = lastEventId?.takeIf { it.isNotEmpty() }
        private set

    /** The last valid `retry:` value in milliseconds, if the server sent one. */
    var retryMs: Long? = null
        private set

    private val line = ByteArrayOutputStream()
    private var previousWasCR = false
    private var atStreamStart = true
    private var eventType = ""
    private val dataBuffer = StringBuilder()

    fun feed(bytes: ByteArray, length: Int = bytes.size): List<AsgardSseEvent> {
        val events = ArrayList<AsgardSseEvent>()

        for (i in 0 until length) {
            val b = bytes[i].toInt() and 0xFF

            if (previousWasCR) {
                previousWasCR = false
                if (b == 0x0A) continue
            }

            when (b) {
                0x0D -> {
                    previousWasCR = true
                    processLine(events)
                }
                0x0A -> processLine(events)
                else -> line.write(b)
            }
        }

        return events
    }

    fun feed(text: String): List<AsgardSseEvent> = feed(text.toByteArray(Charsets.UTF_8))

    private fun processLine(events: MutableList<AsgardSseEvent>) {
        var bytes = line.toByteArray()
        line.reset()

        if (atStreamStart) {
            atStreamStart = false
            if (bytes.size >= 3 &&
                bytes[0] == 0xEF.toByte() &&
                bytes[1] == 0xBB.toByte() &&
                bytes[2] == 0xBF.toByte()
            ) {
                bytes = bytes.copyOfRange(3, bytes.size)
            }
        }

        if (bytes.isEmpty()) {
            dispatch(events)
            return
        }

        val colonByte = ':'.code.toByte()

        // A line starting with a colon is a comment.
        if (bytes[0] == colonByte) return

        val colon = bytes.indexOf(colonByte)
        val name: String
        var valueStart: Int

        if (colon >= 0) {
            name = String(bytes, 0, colon, Charsets.UTF_8)
            valueStart = colon + 1
            if (valueStart < bytes.size && bytes[valueStart] == ' '.code.toByte()) {
                valueStart++
            }
        } else {
            name = String(bytes, Charsets.UTF_8)
            valueStart = bytes.size
        }

        val valueBytes = bytes.copyOfRange(valueStart, bytes.size)
        val value = String(valueBytes, Charsets.UTF_8)

        when (name) {
            "event" -> eventType = value
            "data" -> {
                dataBuffer.append(value)
                dataBuffer.append('\n')
            }
            "id" -> {
                if (valueBytes.none { it.toInt() == 0 }) {
                    lastEventId = value.ifEmpty { null }
                }
            }
            "retry" -> {
                if (valueBytes.isNotEmpty() && valueBytes.all { it.toInt() in 0x30..0x39 }) {
                    value.toLongOrNull()?.let { retryMs = it }
                }
            }
        }
    }

    private fun dispatch(events: MutableList<AsgardSseEvent>) {
        val type = eventType
        val data = dataBuffer.toString()
        eventType = ""
        dataBuffer.setLength(0)

        // No data lines means nothing to dispatch (the id above still stands).
        if (data.isEmpty()) return

        events.add(
            AsgardSseEvent(
                event = type.ifEmpty { "message" },
                data = data.substring(0, data.length - 1),
                lastEventId = lastEventId,
            )
        )
    }
}

/** What to do with an HTTP status on the stream request. */
object AsgardSseStatus {
    enum class Action {
        /** 2xx other than 204: the stream is live. */
        ACCEPT,

        /** 204 and 4xx: the server does not want this stream. Do not reconnect. */
        STOP,

        /** Anything else: try again after a delay. */
        RETRY,
    }

    fun classify(status: Int): Action = when {
        status == 204 -> Action.STOP
        status in 200..299 -> Action.ACCEPT
        status in 400..499 -> Action.STOP
        else -> Action.RETRY
    }
}

object AsgardSseBackoff {
    const val DEFAULT_RETRY_MS = 3000L
    const val MAX_DELAY_MS = 30_000L

    /**
     * How long to wait before reconnect number [failures] (1 for the first
     * retry after a good connection). The server's retry value is the base,
     * it doubles on each consecutive failure, and it never exceeds 30s.
     */
    fun delayMs(retryMs: Long, failures: Int): Long {
        val base = maxOf(retryMs, 0L)
        val exponent = maxOf(failures - 1, 0)

        if (exponent >= 20) return MAX_DELAY_MS

        return try {
            minOf(Math.multiplyExact(base, 1L shl exponent), MAX_DELAY_MS)
        } catch (e: ArithmeticException) {
            MAX_DELAY_MS
        }
    }
}
