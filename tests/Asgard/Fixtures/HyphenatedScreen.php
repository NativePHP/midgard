<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Api\Client\Asgard\Attributes\EntangleWith;

/** FragmentSourceScreen under an address with a hyphen in it. */
#[EntangleWith('order-summary')]
class HyphenatedScreen extends FragmentSourceScreen {}
