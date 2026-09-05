<?php

namespace Native\Api\Client\Auth;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use Native\Api\Client\Support\Contracts\Client;

/**
 * Register this middleware in bootstrap/app.php to enable automatic retrieval of tokens using the client
 * credentials grant. Append it to the global stack, or alias it and attach it to specific routes or
 * route groups. See the README for both.
 */
class ClientAuthentication
{
    protected $app;

    protected $path;

    /**
     * @var OAuthProvider
     */
    protected $provider;

    /**
     * @var Request|null
     */
    protected $request;

    /**
     * @var Filesystem
     */
    protected $storage;

    /**
     * @var Parser
     */
    protected $token_parser;

    public function __construct(Application $app, OAuthProvider $provider)
    {
        $this->app = $app;
        $this->storage = $this->app->make('filesystem');
        $this->token_parser = new Parser(new JoseEncoder);
        $this->provider = $provider;
    }

    /**
     * Handle the request.
     *
     * @param  Request  $request
     * @param  string  ...$scopes
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
     * Check to see if the given token is well-formed and hasn't expired.
     *
     * @param  string  $token
     * @return bool
     */
    private function isTokenValid($token)
    {
        try {
            return ! $this->token_parser->parse($token)->isExpired(new DateTimeImmutable);
        } catch (JwtException) {
            return false;
        }
    }

    /**
     * Check to see if the given token has the requested scopes.
     *
     * @param  string  $token
     * @return bool
     */
    private function hasRequestedScopes($token, array $scopes = [])
    {
        try {
            $parsed_token = $this->token_parser->parse($token);
        } catch (JwtException) {
            return false;
        }

        if (! $parsed_token instanceof UnencryptedToken) {
            return false;
        }

        $scopes = $this->getCombinedScopes($scopes);

        return empty(array_diff($scopes, (array) $parsed_token->claims()->get('scopes', [])));
    }

    /**
     * Get requested scopes combined with any default scopes.
     *
     * @return array
     */
    private function getCombinedScopes(array $scopes = [])
    {
        return array_unique(array_merge($this->provider->getDefaultScopes() ?? [], $scopes));
    }

    /**
     * Attempt to get a matching stored token from storage.
     *
     * @return string|null
     */
    private function getTokenFromStorage()
    {
        $path = $this->getTokenStoragePath();

        if ($this->storage->exists($path)) {
            return $this->storage->get($path);
        }

        return null;
    }

    /**
     * Generate a token using the client credentials grant.
     *
     * @return string
     *
     * @throws IdentityProviderException
     */
    private function generateToken(array $scopes = [])
    {
        $scopes = implode(' ', $this->getCombinedScopes($scopes));

        return $this->provider->getAccessToken('client_credentials', ['scope' => $scopes])->getToken();
    }

    /**
     * Generate a secure, repeatable file name for the token.
     *
     * @return string
     */
    private function getTokenStoragePath()
    {
        if (! $this->path) {
            $this->path = md5($this->request->getMethod().$this->request->getPathInfo());
        }

        return $this->path;
    }
}
