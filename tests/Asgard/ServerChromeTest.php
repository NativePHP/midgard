<?php

use Native\Api\Client\Asgard\ServerChrome;
use Native\Api\Client\Asgard\ServerRegion;

/**
 * Unknown members in chrome (Asgard protocol §5.3, §12.2): ignored and
 * left out everywhere, except in a slot or a navigation, which still
 * refuse them.
 */
beforeEach(function () {
    ServerRegion::flush();
    config(['app.debug' => false]);
});

it('ignores and drops members it does not know, at every level', function () {
    $chrome = ServerChrome::parse([
        'future' => 1,
        'nav_bar' => [
            'title' => 'Promo',
            'glow' => true,
            'logo' => ['src' => 'https://example.test/logo.png', 'alt' => 'Logo'],
            'search' => ['placeholder' => 'Find', 'voice' => true],
            'actions' => [
                ['id' => 'share', 'label' => 'Share', 'tooltip' => 'Share it', 'items' => [
                    ['id' => 'sep', 'divider' => true, 'thickness' => 2],
                    ['id' => 'copy', 'label' => 'Copy', 'items' => []],
                ]],
            ],
        ],
        'tab_bar' => [
            'blur' => 'heavy',
            'presentation' => ['id' => 'floating', 'size' => 'large'],
            'tabs' => [['id' => 'home', 'label' => 'Home', 'url' => '/', 'pulse' => true]],
        ],
    ], $reason);

    expect($reason)->toBeNull()
        ->and($chrome)->toBe([
            'nav_bar' => [
                'title' => 'Promo',
                'logo' => ['src' => 'https://example.test/logo.png', 'height' => 28.0],
                'search' => ['placeholder' => 'Find'],
                'actions' => [
                    ['id' => 'share', 'label' => 'Share', 'items' => [
                        ['id' => 'sep', 'divider' => true],
                        ['id' => 'copy', 'label' => 'Copy'],
                    ]],
                ],
            ],
            'tab_bar' => [
                'presentation' => ['id' => 'floating', 'configuration' => []],
                'tabs' => [['id' => 'home', 'label' => 'Home', 'url' => '/']],
            ],
        ]);
});

it('still refuses a known member with the wrong type', function () {
    expect(ServerChrome::parse(['nav_bar' => ['elevation' => 'high']], $reason))->toBeNull()
        ->and($reason)->toContain('chrome.nav_bar.elevation');
});

it('still refuses an unknown member in a button slot', function () {
    expect(ServerChrome::parse(['nav_bar' => ['actions' => [
        ['id' => 'a', 'on_press' => ['call' => 'share', 'args' => [], 'urgent' => true]],
    ]]], $reason))->toBeNull()
        ->and($reason)->toContain('unknown keys');
});

it('still refuses an unknown member in a navigation', function () {
    expect(ServerChrome::parse(['tab_bar' => ['tabs' => [
        ['id' => 'a', 'label' => 'A', 'on_press' => ['navigate' => '/inbox', 'external' => true]],
    ]]], $reason))->toBeNull()
        ->and($reason)->toContain('unknown keys');
});

it('still refuses an unknown member in a search query slot', function () {
    expect(ServerChrome::parse(['nav_bar' => ['search' => [
        'on_query' => ['call' => 'find', 'args' => [], 'extra' => 1],
    ]]], $reason))->toBeNull()
        ->and($reason)->toContain('unknown keys');
});

it('refuses non-bool divider and explicit null on optional numbers or objects', function (array $chrome, string $fragment) {
    expect(ServerChrome::parse($chrome, $reason))->toBeNull()
        ->and($reason)->toContain($fragment);
})->with([
    'non-bool divider' => [['nav_bar' => ['actions' => [
        ['id' => 'a', 'divider' => 1, 'on_press' => ['call' => 'share', 'args' => []]],
    ]]], 'divider'],
    'null logo height' => [['nav_bar' => ['logo' => [
        'src' => 'https://cdn.example.test/logo.png',
        'height' => null,
    ]]], 'logo.height'],
    'null presentation configuration' => [['tab_bar' => ['presentation' => [
        'id' => 'sheet',
        'configuration' => null,
    ]]], 'configuration'],
]);

it('accepts a 1500-byte https media url on logo and actions and refuses over 2048 bytes', function () {
    $good = 'https://cdn.example.test/'.str_repeat('a', 1500 - strlen('https://cdn.example.test/'));
    $tooLong = 'https://cdn.example.test/'.str_repeat('a', 2049 - strlen('https://cdn.example.test/'));

    $parsed = ServerChrome::parse([
        'nav_bar' => [
            'logo' => ['src' => $good],
            'actions' => [[
                'id' => 'a',
                'image' => $good,
                'on_press' => ['call' => 'share', 'args' => []],
            ]],
        ],
    ], $reason);

    expect($parsed['nav_bar']['logo']['src'] ?? null)->toBe($good)
        ->and($parsed['nav_bar']['actions'][0]['image'] ?? null)->toBe($good);

    expect(ServerChrome::parse(['nav_bar' => ['logo' => ['src' => $tooLong]]], $reason))->toBeNull();
    expect(ServerChrome::parse(['nav_bar' => ['actions' => [[
        'id' => 'a',
        'image' => $tooLong,
        'on_press' => ['call' => 'share', 'args' => []],
    ]]]], $reason))->toBeNull();
});
