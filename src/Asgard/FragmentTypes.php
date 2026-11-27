<?php

namespace Native\Api\Client\Asgard;

use Native\Mobile\Edge\ElementRegistry;

/**
 * Which element types a fragment may use (Asgard protocol §6.1, §9.3, §9.7).
 *
 * Every client renders the core types. The rest of what this build can
 * render is advertised to the server in the handshake's `elements` list:
 * every type in the ElementRegistry, which holds mobile's own elements and
 * whatever UI packages (nativephp/mobile-ui, say) registered at boot, less
 * the core set, chrome and `server_region`. A fragment may use a core type
 * or an advertised one, and nothing else.
 *
 * The handshake (in the transport package) and the fragment check
 * (ServerRegion) both read advertised(), so what the client promises and
 * what it accepts are one list.
 */
final class FragmentTypes
{
    /** The types every client must render (§9.7). */
    public const CORE = ['column', 'row', 'stack', 'scroll_view', 'text', 'button', 'image', 'spacer', 'divider'];

    /**
     * Types a fragment never uses (§9.3). Chrome belongs to the screen that
     * owns the region, and a region is declared by the client's own view,
     * so a server cannot open one.
     */
    public const NEVER = [
        'top_bar', 'top_bar_action', 'top_bar_title',
        'bottom_nav', 'bottom_nav_item', 'bottom_bar',
        'side_nav', 'side_nav_item', 'side_nav_group', 'side_nav_header',
        'native_drawer', 'fab', 'server_region',
    ];

    /** What a type name in `elements` must look like (§6.1). */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /** At most this many types may be advertised in `elements` (§6.1). */
    public const MAX_ADVERTISED = 256;

    /**
     * The extra types this build can render, for the handshake's `elements`.
     *
     * @return list<string>
     */
    public static function advertised(): array
    {
        $types = [];

        foreach (array_keys(ElementRegistry::all()) as $type) {
            $type = (string) $type;

            if (in_array($type, self::CORE, true) || in_array($type, self::NEVER, true)
                || preg_match(self::NAME_PATTERN, $type) !== 1) {
                continue;
            }

            $types[] = $type;
        }

        sort($types);

        return array_slice($types, 0, self::MAX_ADVERTISED);
    }

    /**
     * Why a fragment cannot use this type, or null when it can. A core type
     * this build has no element for (`button` without a UI package, say)
     * cannot be rendered either, so it is refused rather than left to throw
     * mid-render.
     */
    public static function problem(string $type): ?string
    {
        if (in_array($type, self::NEVER, true)) {
            return $type === 'server_region'
                ? "'server_region' is declared by the client, never by a server"
                : "'{$type}' is chrome, which is screen-level and not a region's to place";
        }

        if (! in_array($type, self::CORE, true) && ! in_array($type, self::advertised(), true)) {
            return "'{$type}' is not a core type or one this client advertised";
        }

        if (! ElementRegistry::has($type)) {
            return "'{$type}' is not an element type this build has";
        }

        return null;
    }
}
