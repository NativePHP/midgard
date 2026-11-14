<?php

use Illuminate\Encryption\Encrypter;
use Native\Mobile\Events\Async\AsyncTaskFailed;
use Native\Mobile\Events\Async\AsyncTaskFinished;
use Native\Mobile\Support\AsyncTaskTransport;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\FakeBridge;

/**
 * Helpers for code that runs work on an AsyncTask lane. Tasks go through
 * nativephp/mobile's real dispatch, with a fake bridge that accepts them
 * (or refuses them) and never runs them. A test then finishes or fails a
 * task by hand, as the lane's completion would.
 */
function holdLanes(bool $available = true): FakeBridge
{
    // The payload is sealed with the app key, which every app on a device has.
    if (! config('app.key')) {
        config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'))]);
    }

    return FakeBridge::enable()->respondTo('AsyncTask.Dispatch', ['success' => $available]);
}

/**
 * The tasks dispatched so far, oldest first, as the params the bridge got:
 * the task's `id`, and its watchdog `timeout` when it has one.
 *
 * @return list<array{id: string, timeout?: int}>
 */
function heldTasks(): array
{
    return array_map(fn (array $call) => $call['params'], FakeBridge::current()?->callsTo('AsyncTask.Dispatch') ?? []);
}

/** The id of the last task dispatched. */
function lastTaskId(): string
{
    $tasks = heldTasks();

    return end($tasks)['id'] ?? throw new RuntimeException('No task was dispatched.');
}

/** The serialized work of a held task, as the lane would read it. */
function taskPayload(string $id): string
{
    return AsyncTaskTransport::readPayload($id) ?? throw new RuntimeException("Task [{$id}] has no payload.");
}

/** Finish a held task with this result, as its completion would. */
function finishTask(string $id, mixed $result): void
{
    $callback = NativeCallbacks::resolve($id, AsyncTaskFinished::class);

    expect($callback)->toBeInstanceOf(Closure::class);

    $callback($result);
}

/** Fail a held task, as its completion or its watchdog would. */
function failTask(string $id, Throwable $e): void
{
    $callback = NativeCallbacks::resolve($id, AsyncTaskFailed::class);

    expect($callback)->toBeInstanceOf(Closure::class);

    $callback($e);
}
