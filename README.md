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

## Asgard

Asgard server regions, screens, actions and `#[Server]` methods are set up
under `midgard.asgard` in config/midgard.php. Each option is described there,
and `#[Server]` methods are covered in
[docs/ASGARD-SERVER-METHODS.md](docs/ASGARD-SERVER-METHODS.md).

### Telling the server about the app

The Asgard protocol has no field for the platform, app version or anything
else about the app. Send what the server should know with `state`, which adds
keys to the state of every Asgard request (region renders and batches,
actions, `#[Server]` calls, uploads and each push stream subscription):

```php
'state' => [
    'platform' => [\Native\Api\Client\Asgard\ServerRegion::class, 'platform'], // the default
    'app_version' => [\App\AppInfo::class, 'version'],
    'channel' => 'beta',
],
```

- A value is sent as it is, or called each time a request is built when it is
  a closure or a `[Class::class, 'method']` callable. Prefer the callable array:
  a closure in config stops `php artisan config:cache` working, and NativePHP
  runs that on the device.
- Keys are property names (letters, digits and underscores, not starting with
  a digit). Values are scalars, null or arrays of those. Anything else throws.
- A component's own public prop or `#[Computed]` of the same name wins, and
  its value is sent instead.
- These keys are not props of the component, so a server delta that names one
  is ignored and logged under debug.
- They are ordinary state, so they count wherever state counts, including the
  server's shared push cache. A server component reads one with a public
  property of the same name.

`headers` sends plain HTTP headers with every Asgard request, the push stream
and the handshake included:

```php
'headers' => [
    'X-App-Version' => [\App\AppInfo::class, 'version'],
],
```

The server reads them with `request()->header()`. TLS covers them, but the
protocol's body encryption and signature do not, so do not put anything in
them that the encryption is meant to protect. The server's shared push cache
is not keyed by headers, so a shared region must not depend on one; send it in
`state` instead. Names the transport or the protocol already uses (`Accept`,
`Authorization`, `Connection`, `Content-*`, `Cookie`, `Host`, `Last-Event-ID`,
`Signature`, `Signature-Input`, `Transfer-Encoding`, the method override
headers, and anything starting `Asgard-` or `X-Asgard-`) are refused with an
exception. A callable that gives null leaves the header out of that request.

### The region cache

The app can keep a copy of each region and server screen it gets, and paint
it at once the next time that region loads, even after the app was closed. It
is off until you give it a TTL in seconds:

```php
'cache' => [
    'ttl' => 300,     // ASGARD_CACHE_TTL, 0 is off
    'store' => null,  // ASGARD_CACHE_STORE, null is the app's default store
],
```

A region can set its own TTL, and 0 turns it off for that region. Server
screens use the config value.

```blade
@server('stats', cacheTtl: 60)
    <native:text>Loading…</native:text>
@endserver
```

The store must keep its data when the app quits, so not `array`. The app's
default store does on a device. Copies are keyed by the server, the component,
the region and the state the request sent, so other state never paints
another state's copy.

A cached copy does not stop the request. The request declares each copy that
is still within its TTL, and the server either answers that the copy is still
good or sends a new fragment. A copy past its TTL is still painted while the
request is out, but it is not declared. The server's `max_age` for a region
can cut the TTL shorter. A region painted from the cache joins the push stream
only once the server has answered for it.

The server can force a redraw with `Asgard-Cache: invalidate`. Before such a
fragment replaces a cached copy, `RegionCacheInvalidation` fires. The new
fragment is applied on the screen's next turn unless a listener keeps the copy:

```php
use Native\Api\Client\Asgard\Events\CacheDecision;
use Native\Api\Client\Asgard\Events\RegionCacheInvalidation;

#[On(RegionCacheInvalidation::class, when: ['region' => 'drafts'])]
public function draftsChanged(CacheDecision $decision, ?int $oldMadeAt, ?int $newMadeAt): void
{
    $decision->ignore();
}
```

A Laravel listener gets the event and can call `$event->ignore()`.

Clear the cache when the user logs out, so the next user never sees the last
one's fragments:

```php
\Native\Api\Client\Asgard\RegionCache::flush();
```

## Testing

```shell
composer test
composer format
```
