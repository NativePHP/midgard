<?php

use Illuminate\Support\Carbon;
use Native\Api\Client\Asgard\ComponentState;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Asgard\Fixtures\LocalOnlyScreen;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Testing\Native;

/**
 * `midgard.asgard.state`: app-wide keys added to the state of every
 * request. LocalOnlyScreen sends `range` and `taps` of its own, and draws
 * two regions, so its first frame asks for both in one batch.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    ServerRegion::flush();
    ServerMethods::flush();
    config(['app.debug' => false]);
    Carbon::setTestNow(Carbon::now());

    $this->photo = tempnam(sys_get_temp_dir(), 'configured-state-');
    file_put_contents($this->photo, 'x');

    $this->version = '1.0.0';
    config(['midgard.asgard.state' => [
        'platform' => 'ios',
        'app_version' => fn () => $this->version,
    ]]);
});

afterEach(function () {
    ServerRegion::flush();
    ServerMethods::flush();
    Carbon::setTestNow();
    @unlink($this->photo);
});

function answerConfigured($source): void
{
    $source->respondToBatch(fragments: [
        'stats' => ['fragment' => [
            'type' => 'pressable',
            'key' => 'go',
            'on_press' => ['call' => 'shuffle', 'args' => [], 'target' => 'server'],
            'children' => [['type' => 'text', 'props' => ['text' => 'Shuffle']]],
        ]],
        'feed' => ['fragment' => ['type' => 'text', 'props' => ['text' => 'Feed 1']]],
    ]);
}

const CONFIGURED_STATE = ['range' => '7d', 'taps' => 0, 'platform' => 'ios', 'app_version' => '1.0.0'];

it('sends the platform by default, from System', function () {
    config(['midgard.asgard.state' => (require __DIR__.'/../../config/midgard.php')['asgard']['state']]);
    $source = Asgard::fakeFragmentSource();

    Native::test(LocalOnlyScreen::class, platform: 'android');

    expect($source->lastBatch()->state())->toBe(['range' => '7d', 'taps' => 0, 'platform' => 'android']);
});

it('keeps the default config cacheable, since NativePHP runs config:cache on the device', function () {
    $config = require __DIR__.'/../../config/midgard.php';

    $cached = eval('return '.var_export($config, true).';');

    expect($cached['asgard']['state'])->toBe($config['asgard']['state'])
        ->and(ComponentState::configured())->toBeArray();
});

it('adds the keys to a batch and a single region request', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(LocalOnlyScreen::class);

    expect($source->lastBatch()->toArray()['state'])->toBe(CONFIGURED_STATE)
        ->and($source->lastBatch()->toArray())->not->toHaveKey('platform');

    answerConfigured($source);
    $screen->instance()->refreshServerRegions('feed');

    expect($source->lastRequest('feed')->toArray()['state'])->toBe(CONFIGURED_STATE)
        ->and($source->lastRequest('feed')->toArray())->not->toHaveKey('platform');
});

it('adds the keys to a server action', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(LocalOnlyScreen::class);
    answerConfigured($source);

    $screen->tap('type')->tap('Shuffle');

    expect($source->lastAction('stats')->state)->toBe(CONFIGURED_STATE)
        ->and($source->lastAction('stats')->toArray())->not->toHaveKey('platform');
});

it('adds the keys to a #[Server] call and an upload', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyScreen::class);

    $screen->instance()->callServerMethod('save');
    $screen->instance()->uploadToServer('savePhoto', $this->photo);

    expect($server->lastRequest()->toArray()['state'])->toBe(CONFIGURED_STATE)
        ->and($server->lastRequest()->toArray())->not->toHaveKey('platform')
        ->and($server->lastUpload()->state)->toBe(CONFIGURED_STATE)
        ->and($server->lastUpload()->toArray())->not->toHaveKey('platform');
});

it('adds the keys to the push subscription', function () {
    $source = Asgard::fakeFragmentSource();
    $screen = Native::test(LocalOnlyScreen::class);
    answerConfigured($source);
    $screen->tap('tap');

    expect($source->watching())->toHaveCount(1)
        ->and($source->watching()[0]['state'])->toBe(CONFIGURED_STATE);
});

it('works a closure out each time a request is built', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyScreen::class);

    $screen->instance()->callServerMethod('save');
    $this->version = '1.1.0';
    $screen->instance()->callServerMethod('save');

    $sent = array_values($server->sent());

    expect($sent[0]->state['app_version'])->toBe('1.0.0')
        ->and($sent[1]->state['app_version'])->toBe('1.1.0');
});

it('calls a [Class, method] callable', function () {
    config(['midgard.asgard.state' => ['platform' => [ServerRegion::class, 'platform']]]);

    expect(ComponentState::configured())->toBe(['platform' => ServerRegion::platform()]);
});

it('lets a component\'s own prop or computed of the same name win', function () {
    config(['midgard.asgard.state' => ['range' => 'from-config', 'platform' => 'ios']]);
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyScreen::class);

    $screen->instance()->callServerMethod('save');

    expect($server->lastRequest()->state)->toBe(['range' => '7d', 'taps' => 0, 'platform' => 'ios']);
});

it('ignores a delta that names a configured key, logs it, and applies the rest', function () {
    $server = Asgard::fakeServerTransport();
    $screen = Native::test(LocalOnlyScreen::class);

    $debugLogging = new ReflectionProperty(NativeRouter::class, 'debugLogging');
    $debugLogging->setValue(null, true);
    $log = storage_path('logs/edge-nav.log');
    @mkdir(dirname($log), 0777, true);
    @unlink($log);

    try {
        $screen->instance()->callServerMethod('save');
        expect($server->resolve(delta: ['platform' => 'android', 'app_version' => '9', 'range' => '30d']))->toBeTrue();
    } finally {
        $debugLogging->setValue(null, null);
    }

    expect($screen->get('range'))->toBe('30d')
        ->and(ComponentState::configured())->toBe(['platform' => 'ios', 'app_version' => '1.0.0'])
        ->and(file_get_contents($log))
        ->toContain('delta prop [platform] is a midgard.asgard.state key')
        ->toContain('delta prop [app_version] is a midgard.asgard.state key');

    @unlink($log);
});

it('refuses a key that is not a property name', function (mixed $key) {
    config(['midgard.asgard.state' => [$key => 'x']]);

    ComponentState::configured();
})->with([
    'a hyphen' => ['app-version'],
    'a leading digit' => ['1st'],
    'an int' => [0],
    'empty' => [''],
])->throws(InvalidArgumentException::class, 'is not a property name');

it('refuses a value JSON cannot carry as plain data', function (mixed $value) {
    config(['midgard.asgard.state' => ['thing' => $value]]);

    ComponentState::configured();
})->with([
    'an object' => [fn () => new stdClass],
    'NaN' => [fn () => NAN],
    'a nested object' => [fn () => ['a' => [new stdClass]]],
])->throws(InvalidArgumentException::class, 'midgard.asgard.state [thing]');
