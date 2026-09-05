<?php

namespace Native\Api\Client;

use Exception;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider as BaseProvider;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use Native\Api\Client\Auth\UserProvider;
use Native\Api\Client\Support\Base;
use Native\Api\Client\Support\Concerns\ConfiguresCurl;
use Native\Api\Client\Support\Contracts\Client;
use Native\Api\Client\Support\Eloquent\ApiConnection;
use Native\Api\Client\Support\Eloquent\ApiConnector;

class ServiceProvider extends BaseProvider
{
    use ConfiguresCurl;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerAuthProvider();

        $this->loadViewsFrom($this->getViewsPath(), 'api');

        $this->publishAssets();
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();

        $this->bindOAuthClient();

        $this->registerMacros();

        $this->fixPaginator();
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom($this->getConfigPath(), 'api');

        // Fake a database config so the `api` connection can be resolved like any other
        $this->app['config']->set('database.connections.api', [
            'driver' => 'api',
            'database' => 'api',
            'prefix' => '',
        ]);

        // Applications are free to bind their own client; this is only the fallback
        $this->app->bindIf(Client::class, Base::class);

        Connection::resolverFor('api', function ($connection, $database, $prefix, $config) {
            return new ApiConnection($this->app->make(Client::class), $config);
        });

        $this->app->bind('db.connector.api', function ($app) {
            return new ApiConnector($app->make(Client::class));
        });
    }

    protected function publishAssets()
    {
        $this->publishes([
            $this->getConfigPath() => config_path('api.php'),
        ], 'api-config');

        $this->publishes([
            $this->getViewsPath() => resource_path('views/vendor/api'),
        ], 'api-views');
    }

    protected function registerMacros()
    {
        Collection::macro('paginate', function ($perPage, $total = null, $page = null, $pageName = 'page') {
            $page = $page ?: LengthAwarePaginator::resolveCurrentPage($pageName);

            return new LengthAwarePaginator($this->forPage($page, $perPage), $total ?: $this->count(), $perPage, $page, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]);
        });
    }

    protected function fixPaginator()
    {
        Paginator::currentPathResolver(function () {
            $request = $this->app['request'];

            $query = collect($request->query())->except('page')->all();

            return $request->url().'?'.http_build_query($query);
        });
    }

    /**
     * Creates the instance that we'll be using for all OAuth server connections.
     */
    protected function bindOAuthClient()
    {
        $this->app->singleton(OAuthProvider::class, function ($app, $options = []) {
            $base = config('api.oauth_server.uri');

            if ($ip = config('api.oauth_server.ip')) {
                $this->manuallyResolveHost($base, $ip, $options);
            }

            if (! $client_id = config('api.oauth_server.client_id')) {
                throw new Exception('Missing env key: OAUTH_CLIENT_ID');
            }

            if (! $client_secret = config('api.oauth_server.client_secret')) {
                throw new Exception('Missing env key: OAUTH_CLIENT_SECRET');
            }

            $redirect_uri = config('api.oauth_server.redirect_uri');
            $scopes = config('api.oauth_server.default_scopes');

            $defaultHeaders = ['accept' => 'application/json'];

            $options['headers'] = isset($options['headers'])
                ? $options['headers'] + $defaultHeaders
                : $defaultHeaders;

            return new OAuthProvider(
                [
                    'clientId' => $client_id,
                    'clientSecret' => $client_secret,
                    'redirectUri' => $redirect_uri,
                    'urlAuthorize' => $base.'/oauth/authorize',
                    'urlAccessToken' => $base.'/oauth/token',
                    'urlResourceOwnerDetails' => $base.'/api/user',
                    'scopes' => $scopes,
                ],
                [
                    'httpClient' => new HttpClient($options),
                ]
            );
        });
    }

    /**
     * Register our UserProvider.
     */
    protected function registerAuthProvider()
    {
        Auth::provider('oauth', function (Application $app) {
            return new UserProvider(
                $app->make(OAuthProvider::class),
                $app['config']->get('auth'),
            );
        });
    }

    protected function getConfigPath()
    {
        return __DIR__.'/../config/api.php';
    }

    protected function getViewsPath()
    {
        return __DIR__.'/../resources/views';
    }
}
