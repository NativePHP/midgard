<?php

namespace Native\Api\Client\Asgard;

use Native\Api\Client\Asgard\Contracts\UploadTransport;
use Native\Mobile\AsyncTask;

/**
 * Sends files from uploadToServer() to an Asgard server over HTTP
 * (protocol §10.6).
 *
 * It works like HttpServerTransport. upload() makes the request id, starts
 * an AsyncTask lane and returns the id. The lane reads the file from its
 * path, frames it, seals it and sends it, so the bytes never pass through
 * the UI thread or the serialized closure. The answer is a call's, and it
 * goes back through HttpServerTransport::answered() to
 * ServerMethods::deliver().
 *
 * Uploads have their own HTTP timeout (`midgard.asgard.http_upload_timeout`, 150
 * seconds). The lane's own watchdog is set past it, so a slow upload ends
 * on the HTTP timeout and not on the lane's default 60 seconds.
 */
class HttpUploadTransport implements UploadTransport
{
    /**
     * @param  array{base: string, key: string, timeout: float, upload_timeout?: float, store: ?string}  $config
     */
    public function __construct(protected array $config) {}

    public function upload(UploadRequest $request): string
    {
        $id = 'upload-'.bin2hex(random_bytes(8));
        $header = self::header($request);
        $endpoint = rtrim($this->config['base'], '/').'/upload';
        $what = "upload to {$request->component}::{$request->method}()";

        $task = AsyncTask::dispatch(self::uploadWork($this->config, $header, $request->path))
            ->anyScreen()
            ->timeout(self::laneTimeout($this->config))
            // Named, not static::, as a callback may be bound to a screen.
            ->finished(static function ($result) use ($id, $endpoint, $what) {
                HttpServerTransport::answered($id, $result, $endpoint, $what);
            })
            ->failed(static function (\Throwable $e) use ($id, $what) {
                HttpServerTransport::lost($id, $e, $what);
            });

        $task->start();

        return $id;
    }

    /**
     * The frame header as plain JSON data. Anything JSON cannot carry
     * throws here, on the UI thread, which ServerMethods reports as a send
     * that failed.
     *
     * @return array<string, mixed>
     */
    public static function header(UploadRequest $request): array
    {
        $json = json_encode(
            ['protocol' => 1, ...$request->toArray()],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Seconds the lane may run: the HTTP timeout, plus room for a handshake and a retry. */
    public static function laneTimeout(array $config): int
    {
        return (int) ceil((float) ($config['upload_timeout'] ?? 150)) + 30;
    }

    /**
     * The work an AsyncTask lane runs. Built here, on a line of its own,
     * because a serialized closure is found by the line it starts on.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $header
     */
    public static function uploadWork(array $config, array $header, string $path): \Closure
    {
        return static fn () => RenderCall::upload($config, $header, $path);
    }
}
