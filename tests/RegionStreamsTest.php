<?php

use Native\Api\Client\Asgard\Events\StreamMessage;
use Native\Api\Client\Asgard\RegionStreams;
use Native\Api\Client\Asgard\Stream;

beforeEach(function () {
    $this->stream = Stream::fake();
    $this->pushed = [];
    $this->streams = new RegionStreams('https://demo.test/_asgard/stream', function ($component, $region, $fragment) {
        $this->pushed[] = [$component, $region, $fragment];

        return 1;
    });
});

afterEach(fn () => Stream::unfake());

function regionRequest(string $region, array $state = ['name' => 'Simon']): object
{
    return (object) ['component' => 'app.native.screens.home', 'region' => $region, 'state' => $state, 'platform' => 'ios'];
}

it('opens one stream per component carrying every region that asked', function () {
    $this->streams->subscribe(regionRequest('greeting'));
    $this->streams->subscribe(regionRequest('stats'));

    $open = $this->stream->lastOpen('asgard:app.native.screens.home');

    expect($open['url'])->toBe('https://demo.test/_asgard/stream')
        ->and($open['method'])->toBe('POST')
        ->and(json_decode($open['body'], true))->toBe([
            'protocol' => 1,
            'component' => 'app.native.screens.home',
            'regions' => ['greeting', 'stats'],
            'state' => ['name' => 'Simon'],
            'platform' => 'ios',
        ])
        ->and($open['headers']['X-Asgard-Method'])->toBe('QUERY');
    expect($this->stream->opens)->toHaveCount(2);
});

it('does not reopen when nothing changed, and does when the state did', function () {
    $this->streams->subscribe(regionRequest('greeting'));
    $this->streams->subscribe(regionRequest('greeting'));
    expect($this->stream->opens)->toHaveCount(1);

    $this->streams->subscribe(regionRequest('greeting', ['name' => 'Ada']));
    expect($this->stream->opens)->toHaveCount(2);
});

it('hands a pushed fragment for a subscribed region to the pusher', function () {
    $this->streams->subscribe(regionRequest('stats'));

    $this->streams->receive(new StreamMessage('asgard:app.native.screens.home', 'fragment',
        json_encode(['protocol' => 1, 'region' => 'stats', 'fragment' => ['type' => 'text']]), '3'));

    expect($this->pushed)->toBe([['app.native.screens.home', 'stats', ['type' => 'text']]]);
});

it('ignores events that are not fragments for a subscribed region', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $id = 'asgard:app.native.screens.home';

    $this->streams->receive(new StreamMessage($id, 'message', json_encode(['region' => 'stats', 'fragment' => []])));
    $this->streams->receive(new StreamMessage($id, 'fragment', json_encode(['region' => 'greeting', 'fragment' => []])));
    $this->streams->receive(new StreamMessage($id, 'fragment', 'not json'));
    $this->streams->receive(new StreamMessage('asgard:app.other', 'fragment', json_encode(['region' => 'stats', 'fragment' => []])));
    $this->streams->receive(new StreamMessage('someone-else', 'fragment', json_encode(['region' => 'stats', 'fragment' => []])));

    expect($this->pushed)->toBe([]);
});

it('closes every stream it opened', function () {
    $this->streams->subscribe(regionRequest('stats'));
    $this->streams->close();

    $this->stream->assertClosed('asgard:app.native.screens.home');
    expect($this->streams->subscriptions())->toBe([]);
});
