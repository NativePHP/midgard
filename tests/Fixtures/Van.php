<?php

namespace Native\Api\Client\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Native\Api\Client\Support\Resource;

class Van extends Resource
{
    protected $endpoint = 'vans';

    protected $with = ['wheels'];

    public function wheels(): HasMany
    {
        return $this->hasMany(Wheel::class);
    }
}
