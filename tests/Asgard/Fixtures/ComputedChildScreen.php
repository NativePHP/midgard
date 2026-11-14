<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Mobile\Attributes\Computed;

/** Overrides the parent's local-only computed without repeating #[LocalOnly]. */
class ComputedChildScreen extends ComputedScreen
{
    #[Computed]
    public function secret(): string
    {
        return 'child-shh';
    }
}
