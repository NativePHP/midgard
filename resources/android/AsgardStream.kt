package com.nativephp.plugins.asgard

import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.ConcurrentHashMap

// Native SSE transport for the Asgard plugin.
//
// PHP calls Asgard.Stream.Open / Asgard.Stream.Close (AsgardStreamFunctions
// below). The connection lives here, on a background thread per stream, so
// PHP never blocks on it. Everything that happens on a stream goes back to
// PHP as a native event. iOS twin: resources/ios/AsgardStream.swift.

object AsgardStreamEvents {
    const val MESSAGE = "Native\\Api\\Client\\Asgard\\Events\\StreamMessage"
    const val OPENED = "Native\\Api\\Client\\Asgard\\Events\\StreamOpened"
    const val CLOSED = "Native\\Api\\Client\\Asgard\\Events\\StreamClosed"
}

/**
 * Owns every open stream, by id. Process-wide on purpose: bridge function
 * instances are recreated with each activity, but a stream must outlive that.
 * Events do not need an activity, so none is held.
 */
object AsgardStreamManager {
    private const val TAG = "AsgardStream"

    private val sessions = ConcurrentHashMap<String, AsgardStreamSession>()
    private val mainHandler = Handler(Looper.getMainLooper())

    /**
     * Send an event to PHP. Posted to the main thread so events from every
     * stream arrive in order, the way the framework's other async native
     * events do.
     */
    fun emit(event: String, payload: JSONObject) {
        val json = payload.toString()
        mainHandler.post {
            try {
                NativeElementBridge.sendNativeEvent(event, json)
            } catch (e: Exception) {
                Log.w(TAG, "Could not deliver $event", e)
            }
        }
    }

    fun payload(vararg pairs: Pair<String, Any?>): JSONObject {
        val json = JSONObject()
        for ((key, value) in pairs) {
            json.put(key, value ?: JSONObject.NULL)
        }
        return json
    }

    /**
     * Open a stream. An id that is already open is replaced, and the old
     * connection stops without a StreamClosed.
     */
    fun open(id: String, url: String, method: String, headers: Map<String, String>, body: String?) {
        val session = AsgardStreamSession(id, url, method, headers, body)
        sessions.put(id, session)?.stop()
        session.start()
    }

    /**
     * Close a stream for good. A stream that was open reports StreamClosed
     * with no error and no reconnect.
     */
    fun close(id: String) {
        val session = sessions.remove(id) ?: return
        session.stop()
        emit(
            AsgardStreamEvents.CLOSED,
            payload("id" to id, "error" to null, "willReconnect" to false)
        )
    }

    /** A session gave up on its own. */
    fun finished(session: AsgardStreamSession) {
        sessions.remove(session.id, session)
    }
}

/** One stream: a connection plus the reconnect loop around it, on its own thread. */
class AsgardStreamSession(
    val id: String,
    private val url: String,
    private val method: String,
    private val headers: Map<String, String>,
    private val body: String?,
) {
    @Volatile
    private var stopped = false

    @Volatile
    private var connection: HttpURLConnection? = null

    private var thread: Thread? = null

    // Only touched by the worker thread.
    private var lastEventId: String? = null
    private var retryMs = AsgardSseBackoff.DEFAULT_RETRY_MS
    private var failures = 0

    fun start() {
        thread = Thread({ loop() }, "asgard-sse-$id").also {
            it.isDaemon = true
            it.start()
        }
    }

    fun stop() {
        stopped = true
        // Disconnecting from another thread is how a blocked read is cancelled.
        connection?.disconnect()
        thread?.interrupt()
    }

    private fun emit(event: String, payload: JSONObject) {
        if (!stopped) AsgardStreamManager.emit(event, payload)
    }

    private fun loop() {
        while (!stopped) {
            var reason: String? = null
            var fatal = false

            try {
                val conn = URL(url).openConnection() as HttpURLConnection
                connection = conn

                conn.requestMethod = method
                conn.connectTimeout = 30_000
                // Idle timeout: a stream that goes silent this long is treated as dropped.
                conn.readTimeout = 300_000
                conn.useCaches = false
                conn.instanceFollowRedirects = true
                conn.setRequestProperty("Accept", "text/event-stream")
                conn.setRequestProperty("Cache-Control", "no-cache")
                // Asking for identity turns off transparent gzip, which would hold events back.
                conn.setRequestProperty("Accept-Encoding", "identity")
                for ((name, value) in headers) {
                    conn.setRequestProperty(name, value)
                }
                lastEventId?.let { conn.setRequestProperty("Last-Event-ID", it) }

                if (method == "POST" && body != null) {
                    val bytes = body.toByteArray(Charsets.UTF_8)
                    conn.doOutput = true
                    conn.setFixedLengthStreamingMode(bytes.size)
                    conn.outputStream.use { it.write(bytes) }
                }

                val status = conn.responseCode

                when (AsgardSseStatus.classify(status)) {
                    AsgardSseStatus.Action.ACCEPT -> {
                        failures = 0
                        emit(AsgardStreamEvents.OPENED, AsgardStreamManager.payload("id" to id))
                        readStream(conn)
                    }
                    AsgardSseStatus.Action.STOP -> {
                        fatal = true
                        reason = "HTTP $status"
                    }
                    AsgardSseStatus.Action.RETRY -> reason = "HTTP $status"
                }
            } catch (e: Exception) {
                if (stopped) return
                reason = e.message ?: e.javaClass.simpleName
            } finally {
                connection?.disconnect()
                connection = null
            }

            if (stopped) return

            if (fatal) {
                stopped = true
                AsgardStreamManager.emit(
                    AsgardStreamEvents.CLOSED,
                    AsgardStreamManager.payload("id" to id, "error" to reason, "willReconnect" to false)
                )
                AsgardStreamManager.finished(this)
                return
            }

            failures++
            val delay = AsgardSseBackoff.delayMs(retryMs, failures)

            emit(
                AsgardStreamEvents.CLOSED,
                AsgardStreamManager.payload("id" to id, "error" to reason, "willReconnect" to true)
            )

            try {
                Thread.sleep(delay)
            } catch (e: InterruptedException) {
                return
            }
        }
    }

    private fun readStream(conn: HttpURLConnection) {
        val parser = AsgardSseParser(lastEventId)
        val buffer = ByteArray(4096)

        conn.inputStream.use { stream ->
            while (!stopped) {
                val read = stream.read(buffer)
                if (read < 0) break

                val events = parser.feed(buffer, read)

                parser.retryMs?.let { retryMs = it }
                lastEventId = parser.lastEventId

                for (event in events) {
                    emit(
                        AsgardStreamEvents.MESSAGE,
                        AsgardStreamManager.payload(
                            "id" to id,
                            "event" to event.event,
                            "data" to event.data,
                            "lastEventId" to event.lastEventId,
                        )
                    )
                }
            }
        }
    }
}

/** Asgard.Stream.* bridge functions. Registration comes from nativephp.json. */
object AsgardStreamFunctions {

    /** `Asgard.Stream.Open` {id, url, method, headers, body} */
    class Open(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = (parameters["id"] as? String)?.takeIf { it.isNotEmpty() }
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "id is required")

            val url = parameters["url"] as? String
            if (url == null || !(url.startsWith("http://", true) || url.startsWith("https://", true))) {
                return BridgeResponse.error("INVALID_PARAMETERS", "url must be an http or https URL")
            }

            val method = ((parameters["method"] as? String) ?: "GET").uppercase()
            if (method != "GET" && method != "POST") {
                return BridgeResponse.error("INVALID_PARAMETERS", "method must be GET or POST")
            }

            val headers = HashMap<String, String>()
            when (val raw = parameters["headers"]) {
                is Map<*, *> -> for ((k, v) in raw) {
                    if (k != null && v != null) headers[k.toString()] = v.toString()
                }
                is JSONObject -> for (k in raw.keys()) {
                    headers[k] = raw.optString(k)
                }
            }

            val body = parameters["body"] as? String

            return try {
                AsgardStreamManager.open(id, url, method, headers, body)
                BridgeResponse.success(mapOf("id" to id))
            } catch (e: Exception) {
                BridgeResponse.error("OPERATION_FAILED", e.message ?: "Could not open stream")
            }
        }
    }

    /** `Asgard.Stream.Close` {id} */
    class Close(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val id = (parameters["id"] as? String)?.takeIf { it.isNotEmpty() }
                ?: return BridgeResponse.error("INVALID_PARAMETERS", "id is required")

            AsgardStreamManager.close(id)

            return BridgeResponse.success(mapOf("id" to id))
        }
    }
}
