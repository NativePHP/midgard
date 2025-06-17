<?php

namespace Native\Api\Client\Auth;

use Illuminate\Support\Facades\Session;
use Illuminate\Contracts\Auth\Authenticatable;
use League\OAuth2\Client\Provider\GenericProvider as OAuthClient;
use Illuminate\Contracts\Auth\UserProvider as UserProviderContract;

class UserProvider implements UserProviderContract
{
    protected $user;

    protected $oauth;

    protected $token;

    protected $config;

    public function __construct(OAuthClient $oauth, array $config = [])
    {
        $this->oauth = $oauth;
        $this->config = $config;

        $this->token = Session::get(config('api.session_keys.oauth_access_token'));
    }

    /**
     * Retrieve a user by their unique identifier.
     *
     * @param  mixed $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable
     */
    public function retrieveById($identifier)
    {
        return Session::get("user-{$identifier}");
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * @param  mixed $identifier
     * @param  string $token
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByToken($identifier, $token)
    {
        // Required by interface but not used in this implementation
    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable $user
     * @param  string $token
     * @return void
     */
    public function updateRememberToken(Authenticatable $user, $token)
    {
        // Required by interface but not used in this implementation
    }

    /**
     * Retrieve a user by the given credentials.
     *
     * @param  array $credentials
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveByCredentials(array $credentials)
    {
        return $this->fetchUser($credentials['token']);
    }

    /**
     * Validate a user against the given credentials.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable $user
     * @param  array $credentials
     * @return bool
     */
    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        return $user->active;
    }

    /**
     * Build an Authenticatable instance out of the oauth resource owner.
     *
     * @param string $token
     * @return Authenticatable
     */
    private function fetchUser($token)
    {
        if ($this->user) {
            return $this->user;
        }

        $user = $this->oauth->getResourceOwner($token)->toArray()['user'];

        $user['token'] = $token;

        $guard = $this->config['defaults']['guard'];
        $provider = $this->config['guards'][$guard]['provider'] ?? null;
        $driver = $this->config['providers'][$provider]['driver'] ?? null;

        // If there's a model defined in the config, let's create a local record and use that as our Authenticatable
        if ($model = $this->config['providers'][$driver]['model'] ?? null) {
            $this->user = $model::firstOrCreate(['guid' => $user['guid']], $user);

            // Set these post-create so we don't attempt to save them
            $this->user->active = $user['active'];
            $this->user->internal = $user['internal'];
            $this->user->name = $user['name'];
        } else {
            $this->user = SystemUser::make($user);
        }

        // Put the user in session
        Session::put("user-{$this->user->guid}", $this->user);
        
        return $this->user;
    }
}
