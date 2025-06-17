<?php

use Native\Api\Client\Support\Resource;
use Illuminate\Database\Eloquent\Model;

test('it uses the `api` connection', function () {
    $resource = mock(Resource::class, Model::class)->makePartial();

    expect($resource->getConnectionName())->toBe('api');
});
