<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Facades\Event;
use Native\Api\Client\Asgard\Events\StreamClosed;
use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\Events\StreamOpened;
use PHPUnit\Framework\Assert;

/**
 * Test double for Stream. Get one with Stream::fake().
 *
 * It records every open and close, and its emit methods dispatch the same
 * events the native side would, so listeners can be tested without a device.
 */
class FakeStream
{
    /**
     * Every Stream::open() call, oldest first.
     *
     * @var list<array{id: string, url: string, method: string, headers: array<string, string>, body: ?string}>
     */
    public array $opens = [];

    /**
     * Every Stream::close() call, oldest first.
     *
     * @var list<string>
     */
    public array $closes = [];

    /** @var array<string, true> */
    protected array $live = [];

    /**
     * @param  array<string, string>  $headers
     */
    public function recordOpen(string $id, string $url, string $method, array $headers, ?string $body): void
    {
        $this->opens[] = compact('id', 'url', 'method', 'headers', 'body');
        $this->live[$id] = true;
    }

    public function recordClose(string $id): void
    {
        $this->closes[] = $id;
        unset($this->live[$id]);
    }

    public function isOpen(string $id): bool
    {
        return isset($this->live[$id]);
    }

    /**
     * The most recent open for an id, or null.
     *
     * @return array{id: string, url: string, method: string, headers: array<string, string>, body: ?string}|null
     */
    public function lastOpen(string $id): ?array
    {
        foreach (array_reverse($this->opens) as $open) {
            if ($open['id'] === $id) {
                return $open;
            }
        }

        return null;
    }

    // Emitting events, as the native side would.

    public function emitOpened(string $id): static
    {
        Event::dispatch(new StreamOpened($id));

        return $this;
    }

    public function emit(string $id, string $data, string $event = 'message', ?string $lastEventId = null): static
    {
        Event::dispatch(new StreamMessage($id, $event, $data, $lastEventId));

        return $this;
    }

    public function emitClosed(string $id, ?string $error = null, bool $reconnect = false, ?int $status = null): static
    {
        if (! $reconnect) {
            unset($this->live[$id]);
        }

        Event::dispatch(new StreamClosed($id, $error, $reconnect, $status));

        return $this;
    }

    // Assertions.

    /**
     * @param  (callable(array{id: string, url: string, method: string, headers: array<string, string>, body: ?string}): bool)|null  $filter
     */
    public function assertOpened(string $id, ?callable $filter = null): static
    {
        $matches = array_filter($this->opens, fn (array $open) => $open['id'] === $id
            && ($filter === null || $filter($open) === true));

        Assert::assertNotEmpty($matches, "Stream [{$id}] was not opened".($filter ? ' with the expected details.' : '.'));

        return $this;
    }

    public function assertNotOpened(string $id): static
    {
        Assert::assertNull($this->lastOpen($id), "Stream [{$id}] was opened.");

        return $this;
    }

    public function assertNothingOpened(): static
    {
        Assert::assertSame([], $this->opens, 'Expected no streams to be opened.');

        return $this;
    }

    public function assertClosed(string $id): static
    {
        Assert::assertContains($id, $this->closes, "Stream [{$id}] was not closed.");

        return $this;
    }

    public function assertNotClosed(string $id): static
    {
        Assert::assertNotContains($id, $this->closes, "Stream [{$id}] was closed.");

        return $this;
    }
}
