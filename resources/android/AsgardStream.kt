package com.nativephp.plugins.asgard

import android.os.Handler
import android.os.Looper
import android.util.Base64
import android.util.Log
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.bridge.BridgeResponse
import com.nativephp.mobile.ui.nativerender.NativeElementBridge
import okhttp3.Call
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit

// Native SSE transport for the Asgard plugin.
//
// PHP calls Asgard.Stream.Open / Asgard.Stream.Close (AsgardStreamFunctions
// below). The connection lives here, on a background thread per stream, so
// PHP never blocks on it. Everything that happens on a stream goes back to
// PHP as a native event. iOS twin: resources/ios/AsgardStream.swift.
//
// The request goes through OkHttp, not HttpURLConnection, because the stream
// may be opened with the QUERY method (Asgard protocol §8), and
// HttpURLConnection refuses any method outside its fixed list.

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

    /** Shared, so streams reuse one connection pool. */
    val http: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(30, TimeUnit.SECONDS)
            // Idle timeout: a stream that goes silent this long is treated as dropped.
            .readTimeout(300, TimeUnit.SECONDS)
            .callTimeout(0, TimeUnit.MILLISECONDS)
            .followRedirects(true)
            .retryOnConnectionFailure(false)
            .build()
    }

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
     * connection stops without a StreamClosed. Opening an id that is waiting
     * to be reopened (it fired StreamClosed with reconnect) carries its
     * Last-Event-ID and backoff over to the new connection.
     */
    fun open(id: String, url: String, method: String, headers: Map<String, String>, body: ByteArray?) {
        val session = AsgardStreamSession(id, url, method, headers, body)
        val previous = sessions.put(id, session)
        previous?.stop()
        if (previous != null && previous.awaitingReopen) {
            session.resume(previous)
        }
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
            payload("id" to id, "error" to null, "reconnect" to false, "status" to null)
        )
    }

    /** A session gave up on its own. */
    fun finished(session: AsgardStreamSession) {
        sessions.remove(session.id, session)
    }
}

/**
 * One stream connection, on its own thread.
 *
 * It never reconnects by itself. The request it was given is signed, and the
 * signature expires, so replaying it later would be refused. After a drop it
 * waits out the backoff, then fires StreamClosed with reconnect and waits for
 * PHP to open the id again with fresh headers.
 */
class AsgardStreamSession(
    val id: String,
    private val url: String,
    private val method: String,
    private val headers: Map<String, String>,
    private val body: ByteArray?,
) {
    @Volatile
    private var stopped = false

    @Volatile
    private var call: Call? = null

    private var thread: Thread? = null

    // Written by the worker thread. Read by resume() once the worker is done.
    @Volatile
    private var lastEventId: String? = null

    @Volatile
    private var retryMs = AsgardSseBackoff.DEFAULT_RETRY_MS

    @Volatile
    private var failures = 0

    /** True once this connection has dropped, waited, and asked PHP to reopen it. */
    @Volatile
    var awaitingReopen = false
        private set

    /** Carry the Last-Event-ID and backoff over from the connection this one replaces. */
    fun resume(previous: AsgardStreamSession) {
        lastEventId = previous.lastEventId
        retryMs = previous.retryMs
        failures = previous.failures
    }

    fun start() {
        thread = Thread({ loop() }, "asgard-sse-$id").also {
            it.isDaemon = true
            it.start()
        }
    }

    fun stop() {
        stopped = true
        // Cancelling from another thread is how a blocked read is cancelled.
        call?.cancel()
        thread?.interrupt()
    }

    private fun emit(event: String, payload: JSONObject) {
        if (!stopped) AsgardStreamManager.emit(event, payload)
    }

    private fun loop() {
        if (stopped) return

        var reason: String? = null
        var fatal = false
        var status: Int? = null

        try {
            val builder = Request.Builder()
                .url(url)
                .header("Accept", "text/event-stream")
                .header("Cache-Control", "no-cache")
                // Asking for identity turns off transparent gzip, which would hold events back.
                .header("Accept-Encoding", "identity")
            for ((name, value) in headers) {
                builder.header(name, value)
            }
            lastEventId?.let { builder.header("Last-Event-ID", it) }

            // POST, or real QUERY (§8): both carry the body. OkHttp takes
            // any method with a body except GET and HEAD.
            val requestBody = if (method != "GET") {
                (body ?: ByteArray(0)).toRequestBody(headers.entries.firstOrNull { it.key.equals("Content-Type", true) }?.value?.toMediaTypeOrNull())
            } else {
                null
            }
            builder.method(method, requestBody)

            val newCall = AsgardStreamManager.http.newCall(builder.build())
            call = newCall
            if (stopped) {
                newCall.cancel()
                return
            }

            newCall.execute().use { response ->
                val code = response.code
                status = code

                when (AsgardSseStatus.classify(code)) {
                    AsgardSseStatus.Action.ACCEPT -> {
                        failures = 0
                        emit(AsgardStreamEvents.OPENED, AsgardStreamManager.payload("id" to id))
                        readStream(response)
                    }
                    AsgardSseStatus.Action.STOP -> {
                        fatal = true
                        reason = "HTTP $code"
                    }
                    AsgardSseStatus.Action.RETRY -> reason = "HTTP $code"
                }
            }
        } catch (e: Exception) {
            if (stopped) return
            reason = e.message ?: e.javaClass.simpleName
        } finally {
            call = null
        }

        if (stopped) return

        if (fatal) {
            stopped = true
            AsgardStreamManager.emit(
                AsgardStreamEvents.CLOSED,
                AsgardStreamManager.payload("id" to id, "error" to reason, "reconnect" to false, "status" to status)
            )
            AsgardStreamManager.finished(this)
            return
        }

        // A 2xx stream that ended or dropped is not an HTTP failure.
        if (status != null && status in 200..299) status = null

        failures++
        val delay = AsgardSseBackoff.delayMs(retryMs, failures)

        try {
            Thread.sleep(delay)
        } catch (e: InterruptedException) {
            return
        }

        if (stopped) return

        awaitingReopen = true
        emit(
            AsgardStreamEvents.CLOSED,
            AsgardStreamManager.payload("id" to id, "error" to reason, "reconnect" to true, "status" to status)
        )
    }

    private fun readStream(response: Response) {
        val parser = AsgardSseParser(lastEventId)
        val buffer = ByteArray(4096)
        val source = response.body ?: return

        source.byteStream().use { stream ->
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
            if (method != "GET" && method != "POST" && method != "QUERY") {
                return BridgeResponse.error("INVALID_PARAMETERS", "method must be GET, POST or QUERY")
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

            // The body crosses the bridge in base64, because it is ciphertext.
            val body = try {
                (parameters["body"] as? String)?.let { Base64.decode(it, Base64.DEFAULT) }
            } catch (e: IllegalArgumentException) {
                return BridgeResponse.error("INVALID_PARAMETERS", "body must be base64")
            }

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
