<?php

namespace Native\Api\Client\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use League\OAuth2\Client\Provider\GenericProvider as OAuthProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

class OAuthController extends Controller
{
    /**
     * @var Request
     */
    private $request;

    /**
     * @var OAuthProvider
     */
    private $provider;

    /**
     * @var string
     */
    private $state_key;

    /**
     * @var string
     */
    private $token_key;

    /**
     * @var string
     */
    private $failure_uri;

    public function __construct(Request $request, OAuthProvider $provider)
    {
        $this->request = $request;
        $this->provider = $provider;
        $this->state_key = config('api.session_keys.oauth_state');
        $this->token_key = config('api.session_keys.oauth_access_token');
        $this->failure_uri = config('api.oauth_server.failure_uri');
    }

    /**
     * A standard auth attempt action.
     *
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function authenticate()
    {
        // If we've been directed here with an error, then let's just flip out
        if ($this->request->query->has('error')) {
            return $this->redirectToFailureUri($this->request->query('message'));
        }

        if (! $token = $this->getTokenFromSession()) {
            // If we don't have an authorization code then let's go get one
            if (! $this->request->has('code')) {
                return $this->getAuthCode();
            }

            $this->checkState();

            // Try to get an access token using the authorization code grant.
            try {
                $token = $this->provider->getAccessToken('authorization_code', [
                    'code' => $this->request->input('code'),
                ]);
            } catch (IdentityProviderException $e) {
                return $this->redirectToFailureUri();
            }

            // Ok, we have an access token, which we may use in authenticated requests against the API.
            Session::put($this->token_key, $token);
        }

        // Attempt to log the user in and redirect to where they wanted to go
        if (! Auth::attempt(compact('token'))) {
            return $this->redirectToFailureUri();
        }

        return redirect()->intended();
    }

    /**
     * A standard auth fail response.
     *
     * @return string
     * @throws \Exception
     */
    public function unauthenticated()
    {
        return response()->view('api::auth.failure', [], 401);
    }

    /**
     * Get the current token from session if it's set. Attempt to refresh it if it's expired.
     *
     * @return \League\OAuth2\Client\Token\AccessToken
     */
    private function getTokenFromSession()
    {
        if ($token = Session::get($this->token_key)) {
            if ($token->hasExpired()) {
                $token = $this->provider->getAccessToken('refresh_token', [
                    'refresh_token' => $token->getRefreshToken()
                ]);

                // Update the token in session
                Session::put($this->token_key, $token);
            }
        }

        return $token;
    }

    /**
     * Generate the redirect to the auth code endpoint.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    private function getAuthCode()
    {
        // XXX: Could add default OAuth scopes here
        $auth_url = $this->provider->getAuthorizationUrl();

        // Store generated state in session to check on the return.
        Session::put($this->state_key, $this->provider->getState());

        return redirect($auth_url);
    }

    /**
     * Generate the redirect to the auth failure route.
     *
     * @param  string  $message
     * @return \Illuminate\Http\RedirectResponse
     */
    private function redirectToFailureUri($message = null)
    {
        return redirect($this->failure_uri)->with($message);
    }

    /**
     * Check given state against previously stored one to mitigate CSRF attack.
     *
     * @throws \Exception
     */
    private function checkState()
    {
        if ($this->request->has('state')
            && Session::has($this->state_key)
            && $this->request->input('state') === Session::pull($this->state_key)
        ) {
            return;
        }

        throw new \Exception('Invalid state returned');
    }
}
