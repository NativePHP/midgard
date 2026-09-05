<?php

use Illuminate\Contracts\Auth\UserProvider as UserProviderContract;
use Illuminate\Support\Facades\Session;
use League\OAuth2\Client\Provider\GenericProvider as OAuthClient;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Native\Api\Client\Auth\SystemUser;
use Native\Api\Client\Auth\UserProvider;

function oauthUserProvider(array $user = []): array
{
    $owner = Mockery::mock(ResourceOwnerInterface::class);
    $owner->shouldReceive('toArray')->andReturn(['user' => $user + [
        'guid' => 'abc-123',
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'active' => true,
        'internal' => false,
    ]]);

    $oauth = Mockery::mock(OAuthClient::class);
    $oauth->shouldReceive('getResourceOwner')->andReturn($owner);

    return [new UserProvider($oauth, config('auth')), $oauth];
}

it('satisfies the current UserProvider contract', function () {
    $contract = new ReflectionClass(UserProviderContract::class);

    foreach ($contract->getMethods() as $method) {
        expect(method_exists(UserProvider::class, $method->getName()))->toBeTrue(
            "UserProvider is missing {$method->getName()}()"
        );
    }

    expect(new ReflectionClass(UserProvider::class))->not->toBeNull()
        ->and(is_subclass_of(UserProvider::class, UserProviderContract::class))->toBeTrue();
});

it('implements rehashPasswordIfRequired as a no-op', function () {
    [$provider] = oauthUserProvider();

    expect($provider->rehashPasswordIfRequired(new SystemUser, ['token' => 'x']))->toBeNull();
});

it('builds a SystemUser from the oauth resource owner', function () {
    [$provider] = oauthUserProvider();

    $user = $provider->retrieveByCredentials(['token' => new AccessToken(['access_token' => 'abc'])]);

    expect($user)->toBeInstanceOf(SystemUser::class)
        ->and($user->getAuthIdentifier())->toBe('abc-123')
        ->and(Session::get('user-abc-123'))->toBe($user);
});

it('retrieves a user back out of the session by identifier', function () {
    [$provider] = oauthUserProvider();

    $user = $provider->retrieveByCredentials(['token' => new AccessToken(['access_token' => 'abc'])]);

    expect($provider->retrieveById('abc-123'))->toBe($user);
});

it('returns null when there is no token in the credentials', function () {
    [$provider] = oauthUserProvider();

    expect($provider->retrieveByCredentials([]))->toBeNull();
});

it('validates credentials against the active flag', function () {
    [$provider] = oauthUserProvider();

    expect($provider->validateCredentials(new SystemUser(['active' => true]), []))->toBeTrue()
        ->and($provider->validateCredentials(new SystemUser(['active' => false]), []))->toBeFalse();
});

it('does not support remember tokens', function () {
    [$provider] = oauthUserProvider();

    expect($provider->retrieveByToken('abc-123', 'token'))->toBeNull()
        ->and($provider->updateRememberToken(new SystemUser, 'token'))->toBeNull();
});
