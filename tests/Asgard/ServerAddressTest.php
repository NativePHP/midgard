<?php

use Native\Api\Client\Asgard\Attributes\EntangleWith;
use Native\Api\Client\Asgard\ComponentAddress;
use Native\Api\Client\Asgard\ServerMethods;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Api\Client\Asgard\Testing\Asgard;
use Native\Api\Client\Tests\Asgard\Fixtures\FragmentSourceChild;
use Native\Api\Client\Tests\Asgard\Fixtures\FragmentSourceScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\HyphenatedScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\NamedRegionScreen;
use Native\Api\Client\Tests\Asgard\Fixtures\NamedServerMethodScreen;
use Native\Mobile\Edge\ComponentRegistry;
use Native\Mobile\Support\NativeCallbacks;
use Native\Mobile\Testing\Native;

/**
 * #[EntangleWith] (Asgard protocol §4): a component can name the
 * address its server half is found under, instead of its class name in
 * dot notation.
 */
beforeEach(function () {
    app('view')->addLocation(__DIR__.'/Fixtures/views');
    ComponentRegistry::reset();
    ComponentRegistry::components(['fragment-source-child' => FragmentSourceChild::class]);
    ServerRegion::flush();
    ServerMethods::flush();
    NativeCallbacks::flush();
});

afterEach(function () {
    ComponentRegistry::reset();
    ServerRegion::flush();
    ServerMethods::flush();
    NativeCallbacks::flush();
});

it('uses the address the class names', function () {
    expect(ComponentAddress::for(NamedRegionScreen::class))->toBe('sales');
    expect(ComponentAddress::for('\\'.NamedRegionScreen::class))->toBe('sales');
});

it('does not hand the address down to subclasses of a named class', function () {
    $child = new class extends NamedRegionScreen {};

    expect(ComponentAddress::for($child))->not->toBe('sales');
});

it('falls back to the class name without the attribute', function () {
    expect(ComponentAddress::for(FragmentSourceScreen::class))->toBe('native.api.client.tests.asgard.fixtures.fragment_source_screen');
    expect(ComponentAddress::for('App\Native\Screens\Home'))->toBe('app.native.screens.home');
});

it('refuses an address that is not dot notation of lower-case segments', function (string $address) {
    new EntangleWith($address);
})->with(['', 'Home', 'app\\home', 'home.', '.home', 'home..x', 'home screen', 'home/screen', 'Home-Screen'])
    ->throws(InvalidArgumentException::class);

it('takes hyphens in an address', function (string $address) {
    expect((new EntangleWith($address))->address)->toBe($address);
})->with(['component-name', 'orders.order-summary', 'a-b_c.d-1']);

it('sends requests to a hyphenated address', function () {
    $source = Asgard::fakeFragmentSource();

    Native::test(HyphenatedScreen::class);

    expect(ComponentAddress::for(HyphenatedScreen::class))->toBe('order-summary')
        ->and($source->lastRequest()->component)->toBe('order-summary');
});

it('sends region requests to the named address', function () {
    $source = Asgard::fakeFragmentSource();

    Native::test(NamedRegionScreen::class);

    expect($source->lastRequest()->component)->toBe('sales');
});

it('paints pushes for the named address and not the class name', function () {
    $source = Asgard::fakeFragmentSource();

    $screen = Native::test(NamedRegionScreen::class);
    $state = $source->lastRequest()->state;
    $fragment = ['type' => 'text', 'key' => 'total', 'props' => ['text' => 'Revenue: £12,400']];

    expect(ServerRegion::push('native.api.client.tests.asgard.fixtures.named_region_screen', $state, 'sales-summary', $fragment))->toBe(0);
    expect(ServerRegion::push('sales', $state, 'sales-summary', $fragment))->toBe(1);

    $screen->tap('tap')->assertSee('Revenue: £12,400');
});

it('sends #[Server] calls to the named address', function () {
    $server = Asgard::fakeServerTransport();

    Native::test(NamedServerMethodScreen::class)->tap('Refresh');

    expect($server->lastRequest()->component)->toBe('reports.chart');
});
