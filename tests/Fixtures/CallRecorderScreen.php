<?php

namespace Native\Api\Client\Tests\Fixtures;

use Closure;
use Illuminate\View\View;
use Native\Api\Client\Asgard\Attributes\Server;
use Native\Api\Client\Asgard\Concerns\UsesServer;
use Native\Api\Client\Asgard\ServerResponse;
use Native\Mobile\Edge\NativeComponent;

/**
 * A screen with one #[Server] method and one region, that keeps every
 * answer its calls get. For the HTTP transports: what they hand to
 * ServerMethods::deliver() shows up here as a ServerResponse.
 */
class CallRecorderScreen extends NativeComponent
{
    use UsesServer;

    public int $roll = 0;

    public string $photo = '';

    /** @var list<ServerResponse> */
    public static array $responses = [];

    #[Server]
    public function roll(): Closure
    {
        return function (ServerResponse $response) {
            static::$responses[] = $response;
        };
    }

    public function render(): View
    {
        return view('call-recorder-screen');
    }
}
