<?php

namespace Native\Api\Client\Asgard\Attributes;

use Attribute;
use InvalidArgumentException;
use Native\Api\Client\Asgard\ComponentAddress;

/**
 * Names the address a component's server half is found under
 * (Asgard protocol §4).
 *
 *     #[EntangleWith('home')]
 *     class Home extends NativeComponent
 *
 * Without it the address is the class name in dot notation
 * (`app.native.screens.home`). With it, the server maps `home` to its
 * component and the client's class name is no longer part of the
 * protocol, so moving or renaming the class on the client changes
 * nothing on the server.
 *
 * The address is dot notation. Each segment is lower-case letters, digits,
 * `_` and `-`, so `#[EntangleWith('order-summary')]` works. It is the
 * same shape the server's map accepts.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class EntangleWith
{
    public function __construct(public string $address)
    {
        if (! preg_match(ComponentAddress::PATTERN, $address)) {
            throw new InvalidArgumentException("Asgard address [{$address}] must be dot notation with segments of a-z, 0-9, _ and -.");
        }
    }
}
