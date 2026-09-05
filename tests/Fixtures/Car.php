<?php

namespace Native\Api\Client\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Native\Api\Client\Support\Resource;

class Car extends Resource
{
    protected $relation_methods = ['wheels'];

    public function wheels(): HasMany
    {
        return $this->hasMany(Wheel::class);
    }
}
