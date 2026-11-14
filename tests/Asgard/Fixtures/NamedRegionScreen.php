<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Api\Client\Asgard\Attributes\EntangleWith;

/** FragmentSourceScreen under an address of its own choosing. */
#[EntangleWith('sales')]
class NamedRegionScreen extends FragmentSourceScreen {}
