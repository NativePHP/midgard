<?php

namespace Native\Api\Client\Tests\Fixtures;

use Native\Api\Client\Support\Contracts\Client;

/**
 * A Client that records every call and replays canned responses, so tests can assert on
 * the URLs the query grammar builds without touching the network.
 */
class RecordingClient implements Client
{
    /** @var array<int, array{method: string, uri: string, options: array}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    public array $responses = [];

    public array $meta = [];

    public array $links = [];

    public array $validationErrors = [];

    /**
     * Queue a response for the given method. Pass a closure to vary it by URI.
     */
    public function respondWith(string $method, $response): static
    {
        $this->responses[$method] = $response;

        return $this;
    }

    public function get($uri, $options = [])
    {
        return $this->record('get', $uri, $options);
    }

    public function post($uri, $options = [])
    {
        return $this->record('post', $uri, $options);
    }

    public function patch($uri, $options = [])
    {
        return $this->record('patch', $uri, $options);
    }

    public function delete($uri, $options = [])
    {
        return $this->record('delete', $uri, $options);
    }

    public function put()
    {
        throw new \Exception('PUT is not supported by the API. Use PATCH.');
    }

    public function getMeta($key = null)
    {
        return is_null($key) ? $this->meta : ($this->meta[$key] ?? null);
    }

    public function getLinks($key = null)
    {
        return is_null($key) ? $this->links : ($this->links[$key] ?? null);
    }

    public function getValidationErrors()
    {
        return $this->validationErrors;
    }

    public function lastUri(): ?string
    {
        return $this->calls ? $this->calls[array_key_last($this->calls)]['uri'] : null;
    }

    public function lastCall(): ?array
    {
        return $this->calls ? $this->calls[array_key_last($this->calls)] : null;
    }

    private function record(string $method, string $uri, array $options)
    {
        $this->calls[] = compact('method', 'uri', 'options');

        $response = $this->responses[$method] ?? [];

        return $response instanceof \Closure ? $response($uri, $options) : $response;
    }
}
