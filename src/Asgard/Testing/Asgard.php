<?php

namespace Native\Api\Client\Asgard\Testing;

/**
 * Asgard's fakes for an app's tests, next to nativephp/mobile's own
 * `Native::test()` harness:
 *
 *     $source = Asgard::fakeFragmentSource();
 *     $server = Asgard::fakeServerTransport();
 *
 *     Native::test(Dashboard::class)->tap('Refresh');
 *
 *     $server->assertSent('refreshChart');
 */
final class Asgard
{
    /**
     * The fake fragment source for the current test. Enable it before the
     * component mounts to capture the region requests its first frame
     * makes, then answer them with respond().
     */
    public static function fakeFragmentSource(): FakeFragmentSource
    {
        return FakeFragmentSource::enable();
    }

    /**
     * The fake transport for the current test. Enable it before the
     * component mounts to capture `#[Server]` requests and uploads and
     * answer them by hand.
     */
    public static function fakeServerTransport(): FakeServerTransport
    {
        return FakeServerTransport::enable();
    }
}
