<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use Native\Api\Client\Support\Base;

function apiClient(array $responses): Base
{
    return new Base(['handler' => HandlerStack::create(new MockHandler($responses))]);
}

function json(int $status, array $body): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
}

it('unwraps the data envelope', function () {
    $client = apiClient([json(200, ['data' => [['id' => 1]]])]);

    expect($client->get('cars'))->toBe([['id' => 1]]);
});

it('captures meta and links from the response', function () {
    $client = apiClient([json(200, [
        'data' => [],
        'meta' => ['total' => 9],
        'links' => ['next' => 'cars?page=2'],
    ])]);

    $client->get('cars');

    expect($client->getMeta('total'))->toBe(9)
        ->and($client->getLinks('next'))->toBe('cars?page=2');
});

it('treats 204 as an empty result', function () {
    $client = apiClient([new Response(204)]);

    expect($client->get('cars'))->toBe([]);
});

it('rejects responses that are not declared as json', function () {
    $client = apiClient([new Response(200, ['Content-Type' => 'text/html'], '<h1>nope</h1>')]);

    $client->get('cars');
})->throws(Exception::class, 'Unexpected or malformed API response');

it('turns a 422 into a Laravel validation exception', function () {
    $client = apiClient([json(422, [
        'errors' => [
            ['detail' => 'The name is required', 'meta' => ['field' => 'name']],
        ],
    ])]);

    try {
        $client->post('cars');
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422)
            ->and($e->errors())->toBe(['name' => ['The name is required']])
            ->and($client->getValidationErrors())->toHaveCount(1);
    }
});

it('surfaces other error responses as exceptions', function () {
    $client = apiClient([json(500, ['errors' => [['detail' => 'Boom']]])]);

    $client->get('cars');
})->throws(Exception::class, 'There was a problem communicating with the API: Boom');

it('refuses PUT', function () {
    apiClient([])->put();
})->throws(Exception::class, 'PUT is not supported by the API. Use PATCH.');
