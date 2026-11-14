<?php

namespace Native\Api\Client\Asgard;

/**
 * The rule for media URLs in anything a server sends (protocol §9.6).
 *
 * In an app's own view a relative `src` is an asset the app shipped, and a
 * path starting with `/` is a file on the device (ImageSource). Neither
 * means anything coming from a server. Worse, a server could use them to
 * show the app's own files. So in a fragment, or in server chrome, a prop
 * that loads a URL must be an absolute `https:` URL. Plain `http:` is
 * allowed while `app.debug` is on, for a server on the developer's machine.
 *
 * null, an empty string and an absent prop all mean no image (§9.6).
 *
 * App-authored views never come through here and keep today's behaviour.
 */
final class MediaUrl
{
    /**
     * Prop names that load a URL, on any element type: `image` and
     * `webview` take `src`, `list_item` takes `leadingAvatar` and
     * `leadingImage`. `src` is checked on every type, so a plugin element
     * that loads one is covered too.
     */
    public const PROPS = ['src', 'leadingAvatar', 'leadingImage'];

    private const MAX_LENGTH = 2048;

    /** Why this node's media props cannot be used, or null if they can. */
    public static function nodeProblem(string $type, mixed $props): ?string
    {
        if (! is_array($props)) {
            return null;
        }

        foreach (self::PROPS as $name) {
            if (array_key_exists($name, $props) && ($problem = self::problem($props[$name])) !== null) {
                return "'{$type}' prop '{$name}' {$problem}";
            }
        }

        // A web view that loads the app's own content is the app's to open,
        // not a server's (§9.3). `local` turns that on in the protocol, and
        // `php` is what this build's web view still calls it, so both are
        // refused unless they are false or null. The src was checked above.
        if ($type === 'webview') {
            foreach (['local', 'php'] as $name) {
                if (array_key_exists($name, $props) && $props[$name] !== false && $props[$name] !== null) {
                    return "'webview' cannot load the app's own content in a fragment (prop '{$name}')";
                }
            }
        }

        return null;
    }

    /** Why this value is not a usable media URL, or null if it is. */
    public static function problem(mixed $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (! is_string($url)) {
            return 'must be a URL string';
        }

        if (strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return 'is not a valid URL';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        $allowed = config('app.debug') ? ['https', 'http'] : ['https'];

        if (! in_array($scheme, $allowed, true) || ! is_string($host) || $host === '') {
            return config('app.debug')
                ? 'must be an absolute https:// or http:// URL'
                : 'must be an absolute https:// URL';
        }

        return null;
    }
}
