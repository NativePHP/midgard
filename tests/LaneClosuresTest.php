<?php

use Laravel\SerializableClosure\SerializableClosure;
use Native\Api\Client\Asgard\AsgardServiceProvider;
use Native\Api\Client\Asgard\HttpServerTransport;
use Native\Api\Client\Asgard\HttpUploadTransport;

// A serialized closure is found by the line it starts on. Work written
// inline next to the closure that dispatches it shipped that outer closure
// to the lane instead, which then died with "too few arguments".
it('ships the work closure, not the dispatching one, to the lane', function (string $method, string $call) {
    $work = (new ReflectionMethod(AsgardServiceProvider::class, $method))->invoke(null, ['base' => 'https://example.test/_asgard']);

    $code = serialize(new SerializableClosure($work));

    expect($code)->toContain($call)->not->toContain('Closure $then');
})->with([
    ['routesWork', 'RenderCall::routes($config)'],
    ['handshakeWork', 'RenderCall::handshake($config)'],
]);

it('ships the #[Server] call to the lane, not the send() around it', function () {
    $work = HttpServerTransport::callWork(['base' => 'https://example.test/_asgard'], ['method' => 'roll']);

    $code = serialize(new SerializableClosure($work));

    expect($code)->toContain('RenderCall::serverMethod($config, $body)')->not->toContain('->finished(');
});

it('ships the upload to the lane, not the upload() around it', function () {
    $work = HttpUploadTransport::uploadWork(['base' => 'https://example.test/_asgard'], ['method' => 'savePhoto'], '/tmp/a.jpg');

    $code = serialize(new SerializableClosure($work));

    expect($code)->toContain('RenderCall::upload($config, $header, $path)')->not->toContain('->finished(');
});
