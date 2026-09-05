<?php

use Native\Api\Client\Tests\Fixtures\Car;

it('compiles a select into a GET against the resource endpoint', function () {
    $this->client()->respondWith('get', []);

    Car::query()->get();

    expect($this->client()->lastCall()['method'])->toBe('get')
        ->and($this->client()->lastUri())->toStartWith('cars?');
});

it('turns a primary key lookup into a single-resource GET', function () {
    $this->client()->respondWith('get', []);

    Car::find(123);

    expect($this->client()->lastUri())->toStartWith('cars/123?');
});

it('turns wheres into filter query params', function () {
    $this->client()->respondWith('get', []);

    Car::query()->where('colour', 'red')->get();

    expect($this->client()->lastUri())->toContain('filter[0][colour][]="red",=');
});

it('turns orderBy into a sort param', function () {
    $this->client()->respondWith('get', []);

    Car::query()->orderBy('name', 'desc')->get();

    expect(urldecode($this->client()->lastUri()))->toContain('sort=-name');
});

it('turns a limit into a limit param', function () {
    $this->client()->respondWith('get', []);

    Car::query()->limit(10)->get();

    expect(urldecode($this->client()->lastUri()))->toContain('limit=9');
});

it('turns selected columns into a fields param', function () {
    $this->client()->respondWith('get', []);

    Car::query()->get(['name', 'colour']);

    expect(urldecode($this->client()->lastUri()))->toContain('fields=name&fields=colour');
});

it('hydrates models from the client response', function () {
    $this->client()->respondWith('get', [
        ['internal_id' => 1, 'name' => 'Herbie'],
        ['internal_id' => 2, 'name' => 'KITT'],
    ]);

    $cars = Car::query()->get();

    expect($cars)->toHaveCount(2)
        ->and($cars->first()->name)->toBe('Herbie')
        ->and($cars->first()->getKey())->toBe(1);
});

it('posts on insert and reads the id back out of the response', function () {
    $this->client()->respondWith('post', [['id' => 42, 'name' => 'Herbie']]);

    $car = Car::create(['name' => 'Herbie']);

    expect($this->client()->lastCall()['method'])->toBe('post')
        ->and($this->client()->lastCall()['uri'])->toBe('cars')
        ->and($this->client()->lastCall()['options']['json'])->toMatchArray(['name' => 'Herbie'])
        ->and($car->getKey())->toBe(42);
});

it('patches on update', function () {
    $this->client()->respondWith('patch', []);

    $car = (new Car)->newFromBuilder(['internal_id' => 7, 'name' => 'Herbie']);
    $car->name = 'Love Bug';
    $car->save();

    expect($this->client()->lastCall()['method'])->toBe('patch')
        ->and($this->client()->lastCall()['uri'])->toBe('cars/7')
        ->and($this->client()->lastCall()['options']['json'])->toMatchArray(['name' => 'Love Bug']);
});

it('deletes through the client', function () {
    $this->client()->respondWith('delete', []);

    $car = (new Car)->newFromBuilder(['internal_id' => 7, 'name' => 'Herbie']);
    $car->delete();

    expect($this->client()->lastCall()['method'])->toBe('delete')
        ->and($this->client()->lastCall()['uri'])->toBe('cars/7');
});
