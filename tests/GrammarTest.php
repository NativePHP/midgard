<?php

use Native\Api\Client\Tests\Fixtures\Car;
use Native\Api\Client\Tests\Fixtures\Van;

beforeEach(function () {
    $this->client()->respondWith('get', []);
});

function lastQuery(): string
{
    return urldecode(test()->client()->lastUri());
}

it('compiles whereIn', function () {
    Car::query()->whereIn('colour', ['red', 'blue'])->get();

    expect(lastQuery())->toContain('filter[0][colour][]="red,blue",in');
});

it('compiles whereNotIn', function () {
    Car::query()->whereNotIn('colour', ['red', 'blue'])->get();

    expect(lastQuery())->toContain('filter[0][colour][]="red,blue",not in');
});

it('compiles whereNull', function () {
    Car::query()->whereNull('colour')->get();

    expect(lastQuery())->toContain('filter[0][colour][]="null",=');
});

it('compiles whereNotNull', function () {
    Car::query()->whereNotNull('colour')->get();

    expect(lastQuery())->toContain('filter[0][colour][]="null",!=');
});

it('compiles whereBetween into two bounds', function () {
    Car::query()->whereBetween('doors', [2, 5])->get();

    expect(lastQuery())
        ->toContain('filter[0][doors][]="2",>=')
        ->toContain('filter[0][doors][]="5",<=');
});

it('compiles a nested or group and flags the filter style', function () {
    Car::query()->where(function ($query) {
        $query->where('colour', 'red')->orWhere('colour', 'blue');
    })->get();

    expect(lastQuery())
        ->toContain('filter[0][colour][]="red",=')
        ->toContain('filter[0][colour][]="blue",=')
        ->toContain('filter_style=or');
});

it('compiles a whereHas into a dotted relation filter', function () {
    Car::query()->whereHas('wheels', function ($query) {
        $query->where('size', 17);
    })->get();

    expect(lastQuery())->toContain('filter[0][wheels:size][]="17",=');
});

it('strips the star from an explicit select', function () {
    Car::query()->select('*')->get();

    expect(lastQuery())->not->toContain('fields');
});

it('sends a model\'s declared $with as an include param', function () {
    Van::query()->get();

    expect(lastQuery())->toContain('include=wheels');
});

it('eager loads a runtime with() as a second request', function () {
    $this->client()->respondWith('get', [['internal_id' => 1, 'name' => 'Herbie']]);

    Car::with('wheels')->get();

    expect($this->client()->calls)->toHaveCount(2)
        ->and($this->client()->calls[0]['uri'])->toStartWith('cars?')
        ->and($this->client()->calls[1]['uri'])->toStartWith('wheels?');
});

it('turns an offset into a page number', function () {
    Car::query()->limit(11)->offset(20)->get();

    expect(lastQuery())->toContain('page=3');
});
