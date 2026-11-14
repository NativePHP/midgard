<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Api\Client\Asgard\Attributes\EntangleWith;

/** ServerMethodScreen under an address of its own choosing. */
#[EntangleWith('reports.chart')]
class NamedServerMethodScreen extends ServerMethodScreen {}
