<?php

namespace Native\Api\Client\Auth;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\InvalidTokenStructure;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Native\Api\Client\Support\Contracts\Client;

/**
 * Add this middleware to your global middleware stack in app/Http/Kernel.php to enable automatic retrieval of tokens
 * using the client credentials grant.
 *
 * Alternatively, add it as a route middleware if you only want it to be used on specific routes or route groups.
 *
 * Class ClientAuthentication
 * @package Native\Api\Client\Auth
 */
class ClientAuthentication
{
    protected $app;
    protected $path;

    /**
     * @var OAuthProvider
     */
    protected $provider;

    protected $request;
    protected $storage;
    protected $token_parser;

    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application $app
     */
    public function __construct(Application $app, OAuthProvider $provider)
    {
        $this->app = $app;
        $this->storage = $this->app->make('filesystem');
        $this->token_parser = new Parser(new JoseEncoder());
        $this->provider = $provider;
    }

    /**
     * Handle the request.
     *
     * @param Request $request
     * @param Closure $next
     * @param array ...$scopes
     * @return mixed
     */
    public function handle($request, Closure $next, ...$scopes)
    {
        $this->request = $request;

        // Check to see if we already have a valid token
        if ($token = $this->getTokenFromStorage()) {
            if (! $this->isTokenValid($token) || ! $this->hasRequestedScopes($token, $scopes)) {
                $token = $this->generateToken($scopes);
            }
        } else {
            $token = $this->generateToken($scopes);
        }

        // Store the token for repeated use
        $this->storage->put($this->getTokenStoragePath(), $token);

        session()->forget([config('api.session_keys.oauth_access_token')]);


        // Finally, add the retrieved token to the client instance
        $this->app->extend(Client::class, function ($provider) use ($token) {
            return new $provider(['headers' => ['Authorization' => 'Bearer '.$token]]);
        });


        return $next($request);
    }

    /**
     * Check to see if the given token has expired.
     *
     * @param  string  $token
     * @return bool
     */
    private function isTokenValid($token)
    {
        try {
            $this->token_parser->parse($token);
            return true;
        } catch (InvalidTokenStructure|UnsupportedHeaderFound) {
            return false;
        }
    }

    /**
     * Check to see if the given token has the requested scopes.
     *
     * @param  string  $token
     * @param  array  $scopes
     * @return bool
     */
    private function hasRequestedScopes($token, array $scopes = [])
    {
        $parsed_token = $this->token_parser->parse($token);

        $scopes = $this->getCombinedScopes($scopes);

        return empty(array_diff($scopes, $parsed_token->claims()->get('scopes')));
    }

    /**
     * Get requested scopes combined with any default scopes.
     *
     * @param  array  $scopes
     * @return array
     */
    private function getCombinedScopes(array $scopes = [])
    {
        return array_unique(array_merge($this->provider->getDefaultScopes(), $scopes));
    }

    /**
     * Attempt to get a matching stored token from storage.
     *
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    private function getTokenFromStorage()
    {
        $path = $this->getTokenStoragePath();

        if ($this->storage->exists($path)) {
            return $this->storage->get($path);
        }
    }

    /**
     * Generate a token using the client credentials grant.
     *
     * @param array $scopes
     * @return string
     */
    private function generateToken(array $scopes = [])
    {
        $scopes = implode(' ', $this->getCombinedScopes($scopes));

        try {
            return $this->provider->getAccessToken('client_credentials', ['scope' => $scopes])->getToken();
        } catch (IdentityProviderException $e) {
            // TODO: Log this (and report something to the user?)
            dd($e);
        }
    }

    /**
     * Generate a secure, repeatable file name for the token.
     *
     * @return string
     */
    private function getTokenStoragePath()
    {
        if (! $this->path) {
            $this->path = md5($this->request->method.$this->request->pathInfo);
        }

        return $this->path;
    }
}
