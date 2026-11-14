<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Api\Client\Asgard\Concerns\UsesServer;

/** Brings in a UsesServer method under another name. */
class AliasedUsesServerScreen extends ServerRegionScreen
{
    use UsesServer {
        refreshServerRegions as public reloadRegions;
    }
}
