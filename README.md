# Midgard

A Laravel package that enables a client application to connect to an OAuth-protected Laravel API and use Eloquent
models as the transportation layer.

## Setup

Add the API server credentials to your `.env` file:

```dotenv
API_SERVER_URL=
OAUTH_CLIENT_ID=
OAUTH_CLIENT_SECRET=
```

#### Client tokens

##### Acting as an anonymous user
For applications that don't require authenticating on behalf of specific users, e.g. for anonymous visitors only, your
application should generate client access tokens using the Client Credentials OAuth grant.

This is easily done by including the `ClientTokenMiddleware` in your application's Kernel:

```php
// app/Http/Kernel.php

// As a global middleware that will run for every request
protected $middleware = [
    \Native\Api\Client\Auth\ClientAuthentication::class,
];

// Or as a route middleware which you can assign to specific routes
protected $routeMiddleware = [
    'auth.client' => \Native\Api\Client\Auth\ClientAuthentication::class,
];
``` 

The route middleware accepts a list of scope names that should be assigned to the token generated for any routes that
use it.

This will attempt to store generated tokens using your default storage driver so that your application doesn't have to
generate tokens unnecessarily.


##### Acting as a known user
For applications that need to behave as a specific user (e.g. an admin system), your application will need to follow a full OAuth 2 Auth Code flow. This package makes this straightforward, but some setup will be required.

Add the following to your app's `config/auth.php`:

```
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
]
```

#### Resources
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
Car::orderBy('column1','desc']);
Car::get(['column1','column2']);
Car::paginate();
Car::with('related')->get();
```
