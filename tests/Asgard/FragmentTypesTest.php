<?php

use Native\Api\Client\Asgard\FragmentTypes;
use Native\Api\Client\Asgard\ServerChrome;
use Native\Api\Client\Asgard\ServerRegion;
use Native\Mobile\Edge\ElementRegistry;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;

/**
 * Which types a fragment may use, and how deep it may nest (Asgard
 * protocol §6.1, §9.1, §9.3, §9.7).
 */
beforeEach(function () {
    ServerRegion::flush();
    config(['app.debug' => false]);
    $this->registered = ElementRegistry::all();
});

afterEach(function () {
    ElementRegistry::reset();
    foreach ($this->registered as $type => $class) {
        ElementRegistry::register($type, $class);
    }
    ServerRegion::flush();
});

/** A column nested to exactly $depth levels, the root counting as 1. */
function nestedFragment(int $depth): array
{
    $node = ['type' => 'text', 'props' => ['text' => 'deep']];

    for ($level = 1; $level < $depth; $level++) {
        $node = ['type' => 'column', 'children' => [$node]];
    }

    return $node;
}

it('advertises what the registry holds beyond core, without chrome or regions', function () {
    ElementRegistry::register('list_item', Column::class);
    ElementRegistry::register('toggle', Column::class);

    $advertised = FragmentTypes::advertised();

    expect($advertised)->toContain('list_item', 'toggle', 'icon', 'canvas')
        ->and($advertised)->not->toContain(...FragmentTypes::CORE)
        ->and($advertised)->not->toContain(...FragmentTypes::NEVER)
        ->and($advertised)->toBe(array_values(array_unique($advertised)));
});

it('leaves a registered type out when its name is not a valid element name', function () {
    ElementRegistry::register('stripe.payment_sheet', Column::class);
    ElementRegistry::register('BigChart', Column::class);
    ElementRegistry::register('a'.str_repeat('b', 64), Column::class);
    ElementRegistry::register('a'.str_repeat('c', 63), Column::class);

    expect(FragmentTypes::advertised())->not->toContain('stripe.payment_sheet', 'BigChart', 'a'.str_repeat('b', 64))
        ->and(FragmentTypes::advertised())->toContain('a'.str_repeat('c', 63));

    expect(ServerRegion::applyFragment('promo', ['type' => 'stripe.payment_sheet']))->toBeFalse();
    expect(ServerRegion::applyFragment('promo', ['type' => 'a'.str_repeat('b', 64)]))->toBeFalse();
});

it('caps advertised elements at 256 entries', function () {
    for ($i = 0; $i < 300; $i++) {
        ElementRegistry::register('custom_el_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), Column::class);
    }

    expect(FragmentTypes::advertised())->toHaveCount(FragmentTypes::MAX_ADVERTISED);
});

it('picks up a type a UI package registers at runtime', function () {
    expect(FragmentTypes::advertised())->not->toContain('list_item');
    expect(ServerRegion::applyFragment('promo', ['type' => 'list_item']))->toBeFalse();

    ElementRegistry::register('list_item', Column::class);

    expect(FragmentTypes::advertised())->toContain('list_item');
    expect(ServerRegion::applyFragment('promo', ['type' => 'list_item']))->toBeTrue();
});

it('refuses chrome and server_region even when registered', function (string $type) {
    expect(FragmentTypes::problem($type))->not->toBeNull()
        ->and(ServerRegion::applyFragment('promo', ['type' => 'column', 'children' => [['type' => $type]]]))->toBeFalse();
})->with(['top_bar', 'bottom_nav', 'side_nav', 'fab', 'server_region']);

it('refuses a core type this build has no element for', function () {
    ElementRegistry::reset();
    ElementRegistry::register('column', Column::class);
    ElementRegistry::register('text', Text::class);

    expect(FragmentTypes::problem('button'))->toContain('not an element type this build has')
        ->and(ServerRegion::applyFragment('promo', ['type' => 'button', 'props' => ['label' => 'Go']]))->toBeFalse();
});

it('accepts a fragment nested exactly 64 levels deep', function () {
    expect(ServerRegion::applyFragment('promo', nestedFragment(64)))->toBeTrue();
});

it('refuses a fragment nested deeper than 64 levels', function () {
    expect(ServerRegion::applyFragment('promo', nestedFragment(65)))->toBeFalse()
        ->and(ServerRegion::hasFragment('promo'))->toBeFalse();
});

it('says why a too-deep fragment was refused in debug', function () {
    config(['app.debug' => true]);

    ServerRegion::applyFragment('promo', nestedFragment(65));
})->throws(RuntimeException::class, 'nests deeper than 64 levels');

it('counts a root array as depth 1', function () {
    expect(ServerRegion::applyFragment('promo', [nestedFragment(64), nestedFragment(2)]))->toBeTrue();
    expect(ServerRegion::applyFragment('promo', [nestedFragment(2), nestedFragment(65)]))->toBeFalse();
});

it('applies the depth limit to chrome content', function () {
    expect(ServerRegion::checkContent(nestedFragment(64)))->not->toBeNull();
    expect(ServerRegion::checkContent(nestedFragment(65), $reason))->toBeNull()
        ->and($reason)->toContain('nests deeper than 64');

    expect(ServerChrome::parse(['native' => true, 'bottom_bar' => nestedFragment(65)], $reason))->toBeNull()
        ->and($reason)->toContain('chrome.bottom_bar');
});
