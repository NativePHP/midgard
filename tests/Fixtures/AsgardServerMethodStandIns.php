<?php

namespace Native\Mobile\Edge\Asgard\Contracts;

use Native\Mobile\Edge\Asgard\ServerRequest;
use Native\Mobile\Edge\Asgard\UploadRequest;

// Stand-ins for the parts of nativephp/mobile that HttpServerTransport
// talks to, which is not a dev dependency of this package. A real install
// already has them. ServerMethods only records what it was handed, and
// AsyncTask holds the lane's work until a test runs it.
if (! interface_exists(ServerTransport::class)) {
    interface ServerTransport
    {
        public function send(ServerRequest $request): string;
    }
}

if (! interface_exists(UploadTransport::class)) {
    interface UploadTransport
    {
        public function upload(UploadRequest $request): string;
    }
}

namespace Native\Mobile\Edge\Asgard;

if (! class_exists(UploadRequest::class)) {
    final class UploadRequest
    {
        public function __construct(
            public readonly string $component,
            public readonly string $method,
            public readonly array $args,
            public readonly array $state,
            public readonly string $platform,
            public readonly string $path,
            public readonly string $filename,
            public readonly string $mime,
            public readonly int $size,
        ) {}

        public function toArray(): array
        {
            return ['component' => $this->component, 'method' => $this->method, 'args' => $this->args, 'state' => $this->state, 'platform' => $this->platform, 'filename' => $this->filename, 'mime' => $this->mime, 'size' => $this->size];
        }
    }
}

if (! class_exists(ServerRequest::class)) {
    final class ServerRequest
    {
        public function __construct(
            public readonly string $component,
            public readonly string $method,
            public readonly array $args,
            public readonly array $state,
            public readonly string $platform = 'ios',
        ) {}

        public function toArray(): array
        {
            return ['component' => $this->component, 'method' => $this->method, 'args' => $this->args, 'state' => $this->state, 'platform' => $this->platform];
        }
    }
}

if (! class_exists(ServerMethods::class)) {
    class ServerMethods
    {
        /** @var list<array> */
        public static array $delivered = [];

        public static function deliver(array $payload): bool
        {
            static::$delivered[] = $payload;

            return true;
        }
    }
}

namespace Native\Mobile;

if (! class_exists(AsyncTask::class)) {
    class AsyncTask
    {
        /** @var list<PendingAsyncTask> */
        public static array $dispatched = [];

        /** When false, start() fails the task at once, as a lane that is not available does. */
        public static bool $available = true;

        public static function dispatch(\Closure $work): PendingAsyncTask
        {
            return static::$dispatched[] = new PendingAsyncTask($work);
        }
    }

    class PendingAsyncTask
    {
        public bool $anyScreen = false;

        public bool $started = false;

        public ?int $timeout = null;

        public ?\Closure $finished = null;

        public ?\Closure $failed = null;

        public function __construct(public \Closure $work) {}

        public function anyScreen(): static
        {
            $this->anyScreen = true;

            return $this;
        }

        public function timeout(int $seconds): static
        {
            $this->timeout = $seconds;

            return $this;
        }

        public function finished(\Closure $callback): static
        {
            $this->finished = $callback;

            return $this;
        }

        public function failed(\Closure $callback): static
        {
            $this->failed = $callback;

            return $this;
        }

        public function start(): bool
        {
            $this->started = true;

            if (! AsyncTask::$available) {
                ($this->failed)(new \RuntimeException('The background lane is not available.'));

                return false;
            }

            return true;
        }

        /** Run the work as the lane would, and hand its result back. */
        public function run(): void
        {
            ($this->finished)(($this->work)());
        }
    }
}
