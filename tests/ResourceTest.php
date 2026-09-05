<?php

use Native\Api\Client\Support\Eloquent\ApiConnection;
use Native\Api\Client\Support\Eloquent\ApiQueryBuilder;
use Native\Api\Client\Tests\Fixtures\Car;
use Native\Api\Client\Tests\Fixtures\Wheel;

test('it uses the `api` connection', function () {
    expect((new Car)->getConnectionName())->toBe('api');
    expect((new Car)->getConnection())->toBeInstanceOf(ApiConnection::class);
});

test('it derives the endpoint from the class name', function () {
    expect((new Car)->getTable())->toBe('cars');
});

test('it builds queries with the api query builder', function () {
    expect((new Car)->newQuery()->toBase())->toBeInstanceOf(ApiQueryBuilder::class);
});

test('it qualifies columns without a table prefix', function () {
    expect((new Car)->qualifyColumn('cars.name'))->toBe('name')
        ->and((new Car)->qualifyColumn('name'))->toBe('name');
});

test('it derives a snake case foreign key', function () {
    expect((new Car)->getForeignKey())->toBe('car_id');
});

test('it maps relation method names onto resource endpoints', function () {
    expect((new Car)->getRelationResources())->toBe(['wheels' => 'wheels'])
        ->and((new Car)->getRelationNameForResource('wheels'))->toBe('wheels');
});

test('it hydrates inlined relationships once per class', function () {
    $this->client()->respondWith('get', [
        [
            'internal_id' => 1,
            'name' => 'Herbie',
            'relationships' => [
                'wheels' => [
                    ['internal_id' => 10, 'size' => 15],
                    ['internal_id' => 11, 'size' => 15],
                ],
            ],
        ],
    ]);

    $car = Car::query()->first();

    expect($car->relationLoaded('wheels'))->toBeTrue()
        ->and($car->wheels)->toHaveCount(2)
        ->and($car->wheels[0]->size)->toBe(15);
});

test('it does not stack a retrieved listener per instance', function () {
    // Boot the model first so the class-level listener is already in place
    new Car;

    $event = 'eloquent.retrieved: '.Car::class;
    $before = count(Car::getEventDispatcher()->getListeners($event));

    for ($i = 0; $i < 5; $i++) {
        new Car;
    }

    expect(count(Car::getEventDispatcher()->getListeners($event)))->toBe($before)
        ->and($before)->toBe(1);
});

test('hasManyThrough points the related resource at a sub-resource endpoint', function () {
    $car = (new Car)->newFromBuilder(['internal_id' => 3]);

    $wheels = $car->hasManyThrough(Wheel::class, Car::class);

    expect($wheels)->toBeInstanceOf(Wheel::class)
        ->and($wheels->getEndpointPrefix())->toBe('cars/3/');
});

test('it paginates through the client', function () {
    $this->client()->respondWith('get', function (string $uri) {
        return str_contains($uri, 'aggregate=count')
            ? ['aggregate' => 3]
            : [['internal_id' => 1, 'name' => 'Herbie']];
    });

    $page = Car::query()->paginate(5);

    expect($page->items())->toHaveCount(1)
        ->and($page->total())->toBe(3)
        ->and($this->client()->calls[0]['uri'])->toContain('aggregate=count');
});
