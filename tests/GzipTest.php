<?php

use Native\Api\Client\Asgard\Security\Gzip;
use Native\Api\Client\Asgard\Security\SecurityException;

it('round trips, and reads what PHP and any gzip tool read', function () {
    $data = str_repeat('{"type":"text"},', 500);

    expect(Gzip::decode(Gzip::encode($data)))->toBe($data)
        ->and(gzdecode(Gzip::encode($data)))->toBe($data)
        ->and(Gzip::decode(gzencode($data)))->toBe($data)
        ->and(Gzip::decode(Gzip::encode('')))->toBe('');
});

it('stops at the cap, and accepts exactly the cap', function () {
    expect(strlen(Gzip::decode(Gzip::encode(str_repeat("\0", Gzip::MAX_INFLATED)))))->toBe(Gzip::MAX_INFLATED)
        ->and(fn () => Gzip::decode(Gzip::encode(str_repeat("\0", Gzip::MAX_INFLATED + 1))))->toThrow(SecurityException::class, 'inflates past')
        ->and(fn () => Gzip::decode(Gzip::encode(str_repeat('a', 2000)), 1000))->toThrow(SecurityException::class, 'inflates past');
});

it('refuses gzip that is corrupt or cut short', function () {
    $gz = Gzip::encode(str_repeat('hello ', 1000));
    $flipped = $gz;
    $flipped[20] = chr(ord($flipped[20]) ^ 0xFF);

    expect(fn () => Gzip::decode(substr($gz, 0, -8)))->toThrow(SecurityException::class)
        ->and(fn () => Gzip::decode($flipped))->toThrow(SecurityException::class)
        ->and(fn () => Gzip::decode('not gzip at all'))->toThrow(SecurityException::class);
});
