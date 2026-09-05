# Midgard

A Laravel package that enables a client application to connect to an OAuth-protected Laravel API and use Eloquent
models as the transportation layer.

Requires PHP 8.2+ and Laravel 12 or 13.

## Setup

Add the API server credentials to your `.env` file:

```dotenv
API_SERVER_URL=
OAUTH_CLIENT_ID=
OAUTH_CLIENT_SECRET=
```

Publish the config if you need to change anything else:

```shell
php artisan vendor:publish --tag=api-config
```

### The API client

Every request goes through an implementation of `Native\Api\Client\Support\Contracts\Client`. The package binds
`Native\Api\Client\Support\Base` as the default, which is a Guzzle client that understands the API's JSON envelope
(`data`, `meta`, `links`, `errors`). Bind your own implementation in a service provider if you need different
behaviour:

```php
$this->app->bind(
    \Native\Api\Client\Support\Contracts\Client::class,
    \App\Api\Client::class,
);
```

## Client tokens

### Acting as an anonymous user

For applications that don't require authenticating on behalf of specific users, e.g. for anonymous visitors only, your
application should generate client access tokens using the Client Credentials OAuth grant.

Add the `ClientAuthentication` middleware in `bootstrap/app.php`:

```php
// bootstrap/app.php

use Native\Api\Client\Auth\ClientAuthentication;

return Application::configure(basePath: dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        // As global middleware that will run for every request
        $middleware->append(ClientAuthentication::class);

        // Or as an alias you can attach to specific routes or route groups
        $middleware->alias([
            'auth.client' => ClientAuthentication::class,
        ]);
    })
    ->create();
```

Used as an alias, the middleware accepts a list of scope names that should be assigned to the token generated for any
routes that use it:

```php
Route::get('/cars', CarController::class)->middleware('auth.client:read-cars,write-cars');
```

Tokens are stored on your default filesystem disk, keyed by request method and path, so your application doesn't have
to generate a token on every request. A stored token is reused until it expires or the requested scopes change.

### Acting as a known user

For applications that need to behave as a specific user (e.g. an admin system), your application will need to follow a
full OAuth 2 Auth Code flow. This package makes this straightforward, but some setup will be required.

Add the following to your app's `config/auth.php`:

```php
return [
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'oauth',
        ],
    ],

    'providers' => [
        'oauth' => [
            'driver' => 'oauth',
        ],
    ],
];
```

Then point a couple of routes at the package's controller. These have to match the `redirect_uri` and `failure_uri` in
`config/api.php`, which default to `/oauth/authenticate` and `/oauth/unauthenticated`:

```php
// routes/web.php

use Native\Api\Client\Auth\OAuthController;

Route::get('/oauth/authenticate', [OAuthController::class, 'authenticate']);
Route::get('/oauth/unauthenticated', [OAuthController::class, 'unauthenticated']);
```

The failure page ships with the package. Publish it if you want to style it yourself:

```shell
php artisan vendor:publish --tag=api-views
```

## Resources

You interact with API resources as if they were Eloquent models. In fact you can create Eloquent models how you
normally would, simply extend the built-in `Resource` class instead of Laravel's Eloquent Model:

```php
<?php

namespace App\Models;

use Native\Api\Client\Support\Resource;

class Car extends Resource
{
}
```

Then you can use many of the methods you're used to with Eloquent models, but instead of building queries and
persisting to a database, your data is retrieved and stored via HTTP calls, all with the same syntax you'd use for a
database-backed model:

```php
Car::find($id);
Car::findOrFail($id);
Car::orderBy('column1', 'desc');
Car::get(['column1', 'column2']);
Car::paginate();
Car::with('related')->get();
```

Resources use the `api` connection and default to a `internal_id` primary key. The endpoint is the kebab-cased plural
of the class name unless you set `$endpoint`:

```php
class Car extends Resource
{
    protected $endpoint = 'vehicles';
}
```

Declaring `$with` on a resource asks the API to inline those relations in the response (an `include` query param)
rather than issuing a second request:

```php
class Car extends Resource
{
    protected $with = ['wheels'];
}
```

Anything the API returns under a `relationships` key is hydrated onto the model when it's retrieved.

## Testing

```shell
composer test
composer format
```
