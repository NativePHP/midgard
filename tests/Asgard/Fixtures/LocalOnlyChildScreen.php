<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Api\Client\Asgard\Attributes\LocalOnly;

/**
 * Inherits LocalOnlyScreen's local-only props, declares `token` again
 * without the attribute, and adds one of its own.
 */
class LocalOnlyChildScreen extends LocalOnlyScreen
{
    public ?string $token = 'child-token';

    #[LocalOnly]
    public string $pin = '1234';

    public string $mode = 'child';
}
