<?php

namespace Native\Api\Client\Asgard;

use Illuminate\Support\Carbon;
use Native\Api\Client\Asgard\Contracts\FragmentSource;
use Native\Api\Client\Asgard\Events\RegionCacheInvalidation;
use Native\Api\Client\Asgard\Events\ServerActionFailed;
use Native\Api\Client\Asgard\Events\ServerRegionFailed;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeElementCollector;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\System;

/**
 * The client half of an Asgard region (Asgard protocol §3).
 *
 * `@server('sales-summary') ... @endserver` compiles to open()/close()
 * around the placeholder body. open() emits the `server_region` node and
 * answers one question: does the placeholder render, or is there a
 * fragment to paint instead?
 *
 *     if (ServerRegion::open('sales-summary')) { ...placeholder... }
 *     ServerRegion::close();
 *
 * Everything here is client-side. There is no transport: a fragment
 * arrives through applyFragment() from whatever fetches it, and until
 * one does the placeholder is what the screen shows. That is the
 * intended degraded state, not an error (§3).
 *
 * ## Where the namespacing happens
 *
 * Nowhere in this class, on purpose. The region node is keyed with the
 * region name, and the two existing key-path mechanisms take it from
 * there: `Element::toArray()` builds each child's path from its
 * parent's, and the collector's streaming path pushes the same segment
 * onto its key-path stack. A fragment node whose relative key is
 * `total` therefore lands at `/sales-summary/total` in both paths
 * without this class hashing anything (§9.1).
 *
 * Callback ids are namespaced the other way, because they are derived
 * from a registry scope rather than a key path: fragment nodes register
 * into a `FragmentCallbacks` registry scoped `server:<region>`, exactly
 * as a child component's nodes register into one scoped by its identity
 * key. A region in a child component chains the child's scope in front
 * (`cards|key:a>server:<region>`), so two cards with the same region
 * never derive the same ids. That scope is applied by pinning it on the
 * region node, which `Element::toArray()` then hands down to every
 * descendant.
 *
 * ## Taps inside a fragment (§10)
 *
 * A callback slot is an object, `{"call", "args", "target"}`, checked
 * when the fragment is applied (FragmentCallbacks::parseSlot()). The
 * component's dispatch resolves a fragment id through registriesOf():
 * a `client` target runs a `#[ClientAction]` method on the component
 * that owns the region, and a `server` target goes out through action()
 * as an ActionRequest, whose answer comes back through deliverBatch().
 *
 * ## Asking for fragments
 *
 * After a frame in which a region painted its placeholder, the component
 * that rendered it asks the bound `FragmentSource` for one, once per
 * component instance (requestMissing()). refresh() asks again. Every
 * request is a RegionBatch: an instance that wants several regions after
 * one frame asks for them in one, and each region in it is still tracked
 * on its own. The source answers later through deliverBatch(), which
 * hands each part to the right component through deliver() and flags a
 * repaint.
 *
 * refresh() only marks an instance and a region, so many marks before the
 * next frame make one fetch. Stream signals (signal()) and refresh hints
 * from an answer (hint()) mark the same way. An action or #[Server] call
 * answer can also carry other regions of the same component, which
 * paintInline() paints on the instance that asked.
 *
 * ## The push stream (§8.1)
 *
 * Every region that got a good answer is watched on the device's one push
 * stream. The set is worked out from live owners: per owner, each answered
 * region, grouped by address and the state its answer was rendered for.
 * sync() hands the whole set to the source whenever it changes, and the
 * source opens, reopens or closes the stream to match. It runs at the end
 * of every frame and after the router unmounts a screen, so changes that
 * land together make one call. The stream tells a region it changed
 * through signal(), which fetches it again, or for a shared region hands
 * over the fragment itself through push(). A `patch` changes one list in a
 * region the device holds through patch(), and falls back to what signal()
 * does when it cannot apply. All three name the subscription's address and
 * state.
 *
 * ## The region cache
 *
 * With a TTL above 0 (`midgard.asgard.cache.ttl`, or `cacheTtl:` on the
 * directive) each fragment that lands is also stored in RegionCache. A
 * region that has nothing to paint yet paints the stored copy for its
 * address and state at once, even an expired one, and still asks the
 * server. Every request declares the copies still within their TTL in its
 * `cache` member. The server answers `{"cached": true}` for a copy it holds
 * good, which keeps what is painted and counts as a good answer.
 *
 * A copy painted from the cache is not an answer: the region is not put on
 * the push stream until the server has answered for it.
 *
 * A response with a signed `Asgard-Cache: invalidate` redraws every region
 * it carries. A region that holds a cached copy fires RegionCacheInvalidation
 * first, and its fragment waits for the screen's next turn, so an `#[On]`
 * listener can tell the client to keep the copy (settle()).
 *
 * ## Timeouts and failures
 *
 * A server action waits at most actionTimeout() of its component for
 * an answer, a region request at most regionTimeout(), and a
 * server screen at most its screenTimeout() for its first body. A source reports a hard failure (no network, a server error,
 * an answer that fails verification) through failed(), and deliver()
 * reports a fragment it cannot render the same way. Either one is acted on
 * at the next turn of the runloop of the screen the component is on:
 * expire() runs there, and nextDeadline() tells the loop when to wake for
 * the next one. A server screen gives up and leaves. An action fires
 * ServerActionFailed on its screen, and a region request fires
 * ServerRegionFailed. An answer that lands after any of them is dropped.
 * A region that failed is not asked for again until refresh() names it.
 *
 * ## What is deliberately NOT here
 *
 * HTTP, sessions and crypto belong to other packages. This class holds
 * fragments, paints them and says when one is wanted or an action is
 * sent. It never fetches one itself.
 */
class ServerRegion
{
    /**
     * Wire callback slot => the collector attr that carries an
     * expression. Servers send `{"call", "args", "target"}` objects,
     * never ids (§9.1) — the two sides derive ids in different scopes,
     * so an id from a server is meaningless here.
     */
    private const CALLBACK_ATTRS = [
        'on_press' => '_press',
        'on_long_press' => '_longPress',
        'on_double_tap' => '_doubleTap',
        'on_change' => '_change',
        'on_submit' => '_submit',
    ];

    /**
     * How deep a fragment may nest (§9.1). The root is at depth 1, and each
     * level of `children` adds one. Deeper is refused, not truncated.
     */
    public const MAX_DEPTH = 64;

    /**
     * Fragments held per owning component: `owner => [region => nodes]`.
     *
     * Keyed by component instance, not by region name alone, so two
     * screens (or two mounts of one child component) that each declare a
     * `sales-summary` region never paint each other's fragment. An
     * instance is a stricter key than the component's address: two
     * `<native:sales-card>` children share an address but not their
     * state, and the server rendered each fragment for one state. A
     * WeakMap also lets a fragment go when its component does.
     *
     * @var \WeakMap<NativeComponent, array<string, array>>|null
     */
    protected static ?\WeakMap $fragments = null;

    /**
     * Fragments for a region rendered outside any component (a bare
     * `view()->render()`, which only tests do). Nothing can request
     * these; they arrive through applyFragment() without an owner.
     */
    protected static array $detached = [];

    /**
     * Callback registries for regions rendered outside any component,
     * keyed by region name.
     *
     * @var array<string, FragmentCallbacks>
     */
    protected static array $registries = [];

    /**
     * Callback registries per owning component: `owner => [region =>
     * registry]`. A registry is dropped when its region's fragment is
     * replaced or cleared, so an id from a fragment no longer on screen
     * resolves to nothing.
     *
     * @var \WeakMap<NativeComponent, array<string, FragmentCallbacks>>|null
     */
    protected static ?\WeakMap $ownedRegistries = null;

    /**
     * Regions recorded in the current frame, in render order. Each is
     * `['name', 'key_path', 'has_fragment', 'owner' => ?WeakReference]`.
     */
    protected static array $frame = [];

    /** Names of the regions currently open, outermost first. */
    protected static array $open = [];

    /** Per-frame duplicate counters, keyed by owner and the name as authored. */
    protected static array $duplicates = [];

    /**
     * The latest request id per owner and region:
     * `owner => [region => id]`. A region with an entry has been asked
     * for, so the next frame does not ask again. An answer whose id is
     * not the latest one is stale and dropped.
     *
     * @var \WeakMap<NativeComponent, array<string, string>>|null
     */
    protected static ?\WeakMap $requested = null;

    /**
     * Regions refreshServerRegions() asked to fetch again after the next
     * frame: `owner => [region => true]`, or `owner => ['*' => true]`.
     * The value is `push` instead of true when a push signal made the mark.
     *
     * @var \WeakMap<NativeComponent, array<string, true|string>>|null
     */
    protected static ?\WeakMap $refreshing = null;

    /**
     * Requests in flight: `id => ['owner' => WeakReference, 'region' => name,
     * 'deadline' => ?ms, 'request' => RegionRequest]`. A server screen's
     * body has no deadline here: its screen has one of its own (awaitBody()).
     */
    protected static array $pending = [];

    /** Set when a fragment lands; cleared when the next frame begins. */
    protected static bool $repaint = false;

    protected static int $sequence = 0;

    /**
     * The state each owner sent with its latest region request:
     * `owner => [region => state]`. Kept as it was sent, so a prop that
     * changed since does not make the owner look like another instance.
     *
     * @var \WeakMap<NativeComponent, array<string, array>>|null
     */
    protected static ?\WeakMap $requestedStates = null;

    /**
     * What each owner's answered regions were rendered for:
     * `owner => [region => ['id' => subscription id, 'state' => …, 'version' => ?token]]`.
     * Set when an answer is delivered, so only regions with a good answer
     * are watched on the push stream. The version is updated by pushes.
     *
     * @var \WeakMap<NativeComponent, array<string, array{id: string, state: array, version: ?string}>>|null
     */
    protected static ?\WeakMap $answered = null;

    /**
     * The set last handed to the source's watch(), without versions, so an
     * unchanged set is not sent again.
     */
    protected static array $watching = [];

    /** At most this many subscriptions, and regions per subscription, on the stream. */
    public const MAX_SUBSCRIPTIONS = 16;

    /** At most this many regions in one render request, and hints read from one answer. */
    public const MAX_REGIONS = 16;

    /** The `reason` of a fetch a push stream signal caused. */
    public const PUSH = 'push';

    /** Why a request failed: no answer in time. */
    public const TIMEOUT = 'timeout';

    /** Why a request failed: it never reached the server, or nothing came back. */
    public const NETWORK = 'network';

    /** Why a request failed: the server answered with an error. */
    public const SERVER = 'server';

    /** Why a request failed: the answer failed verification, or its fragment cannot be rendered. */
    public const INVALID = 'invalid';

    /**
     * Server screens still waiting for their first body:
     * `screen => ['at' => ?deadline ms, 'failure' => ?['reason', 'status', 'message']]`.
     * A failure, or a deadline that has passed, makes the screen give up at
     * its next turn.
     *
     * @var \WeakMap<ServerScreen, array{at: ?float, failure: ?array{reason: string, status: ?int, message: ?string}}>|null
     */
    protected static ?\WeakMap $awaiting = null;

    /**
     * The `cacheTtl:` each owner's regions were drawn with:
     * `owner => [region => ?seconds]`, null meaning the config default.
     *
     * @var \WeakMap<NativeComponent, array<string, ?int>>|null
     */
    protected static ?\WeakMap $cacheTtls = null;

    /**
     * What each owner's regions show, as far as the cache is concerned:
     * `owner => [region => ['made_at' => ?ms, 'cached' => bool]]`. `cached`
     * is true while the fragment came from the cache and the server has not
     * answered for it yet.
     *
     * @var \WeakMap<NativeComponent, array<string, array{made_at: ?int, cached: bool}>>|null
     */
    protected static ?\WeakMap $copies = null;

    /**
     * Fragments that replace a cached copy, held until the screen's next
     * turn so RegionCacheInvalidation listeners can keep the copy.
     *
     * @var list<array{owner: \WeakReference<NativeComponent>, region: string, id: string, request: ?RegionRequest, state: array, tree: array|string, chrome: ?array, version: ?string, made_at: ?int, max_age: ?int, event: RegionCacheInvalidation}>
     */
    protected static array $held = [];

    /**
     * Requests sent again because the server said a copy the device no
     * longer holds is good: `id => true`. A `cached` answer to one fails.
     *
     * @var array<string, true>
     */
    protected static array $retries = [];

    /**
     * Failed actions and regions waiting for their component's next turn.
     *
     * @var list<array{owner: \WeakReference<NativeComponent>, event: ServerActionFailed|ServerRegionFailed}>
     */
    protected static array $failures = [];

    /**
     * What patches keep per owner and region, so the next one is cheap:
     * `owner => [region => ['targets' => [key => path], 'uncached' => bool,
     * 'stale' => bool]]`. A path is the child indexes from the region's
     * node list down to the target. `uncached` says the region's cache
     * entry is already dropped, and `stale` that its callback registry is
     * rebuilt on its next render. A full fragment resets it.
     *
     * @var \WeakMap<NativeComponent, array<string, array{targets: array<string, list<int>>, uncached: bool, stale: bool}>>|null
     */
    protected static ?\WeakMap $patchState = null;

    /** At most this many nodes in one patch. */
    public const PATCH_MAX_NODES = 100;

    /** At most this many keys in one remove. */
    public const PATCH_MAX_KEYS = 1000;

    /** The largest `keep` a patch may send. */
    public const PATCH_MAX_KEEP = 100000;

    /**
     * Open a region. Returns true when the caller should render the
     * placeholder body, false when a fragment was painted in its place.
     *
     * The region belongs to whichever component is rendering, which is a
     * child component when the block sits in a child's view.
     *
     * A duplicate name in one component's frame is a bug in the view: the
     * name addresses a server component (§4) and a fragment is stored
     * under it, so two regions sharing one would paint each other's
     * content. With `app.debug` on it throws; otherwise the later regions
     * are suffixed (`sales-summary#2`) so they resolve to nothing and keep
     * their placeholders while the first region still works. That is the
     * same "loud in debug, degrade in production" trade §5.3 makes for
     * prop drift.
     *
     * `$cacheTtl` is the region's TTL in the region cache, in seconds:
     * `@server('stats', cacheTtl: 300)`. Null uses `midgard.asgard.cache.ttl`
     * and 0 turns caching off for this region. A region with nothing to
     * paint yet paints its cached copy, when there is one.
     */
    public static function open(string $name, array $attrs = [], ?int $cacheTtl = null): bool
    {
        $owner = NativeElementCollector::owner();
        $name = static::deduplicate($name, $owner);

        if ($owner !== null) {
            $ttls = static::cacheTtls()[$owner] ?? [];
            $ttls[$name] = $cacheTtl;
            static::cacheTtls()[$owner] = $ttls;
        }

        // Extra attrs are the author's (`@server('x', ['class' => '…'])`),
        // but the name and the key it derives are not theirs to override.
        NativeElementCollector::open('server_region', array_merge($attrs, [
            'region' => $name,
            'native-key' => $name,
        ]));

        $fragment = static::fragmentFor($name, $owner);

        if ($fragment === null && $owner !== null) {
            $fragment = static::paintFromCache($name, $owner);
        }

        static::$frame[] = [
            'name' => $name,
            'key_path' => static::keyPath($name),
            'has_fragment' => $fragment !== null,
            'from_cache' => $owner !== null && ((static::copies()[$owner] ?? [])[$name]['cached'] ?? false),
            'owner' => $owner !== null ? \WeakReference::create($owner) : null,
        ];

        static::$open[] = $name;

        if ($fragment === null) {
            return true;
        }

        // The collector's bound registry is the owner's own, so its
        // scope is what a child's region chains in front of its own.
        // A patched region gets a fresh registry now, so ids of nodes a
        // patch removed stop resolving. Until this render the old one
        // still answers taps on what is on screen.
        $callbacks = (static::takeStaleRegistry($name, $owner) ? null : static::registryFor($name, $owner))
            ?? static::newRegistry($name, $owner, NativeElementCollector::callbacks()?->scope() ?? '');

        // Element-tree path: pinning the region node is enough. toArray()
        // resolves `ownedCallbacks` first and passes the result down, so
        // the whole fragment registers in the region's scope.
        NativeElementCollector::openElement()?->ownCallbacks($callbacks);

        // Streaming path: there is no element to pin — nodes are written
        // straight to C and register against whatever the collector has
        // bound — so swap the bound registry for the length of the emit.
        $bound = NativeElementCollector::callbacks();
        NativeElementCollector::setCallbacks($callbacks);

        try {
            static::emit($fragment);
        } finally {
            NativeElementCollector::setCallbacks($bound);
        }

        return false;
    }

    /** Close the region opened by the matching open(). */
    public static function close(): void
    {
        array_pop(static::$open);

        NativeElementCollector::close();
    }

    /**
     * Hand a region the fragment it should paint from now on.
     *
     * `$owner` is the component that renders the region. Leave it null
     * only for a region rendered outside any component. A transport
     * answering a request should use deliver() instead, which knows the
     * owner and drops stale answers.
     *
     * The tree is logical: type names, props, children, relative keys
     * and callback expressions (§9). It may be the decoded array or the
     * raw `tree/json` body. No ids, and any this class finds are ignored
     * rather than trusted (§9.1).
     *
     * Applying does not repaint on its own. The fragment is held, and
     * the region paints it the next time the screen renders. A live
     * element tree is only one of the two render paths (the streaming
     * path has no tree to mutate), so "store, then render" is the one
     * path that works in both. It does flag that a repaint is wanted,
     * which the runloop reads so a fragment that lands outside an event
     * still reaches the screen.
     *
     * Returns false for a fragment the client cannot render, in which
     * case the region keeps its placeholder. Throws instead when
     * `app.debug` is on, because in development an unrenderable fragment
     * is a mismatch between the two halves and silence would hide it.
     */
    public static function applyFragment(string $region, array|string $tree, ?NativeComponent $owner = null): bool
    {
        $nodes = is_string($tree) ? static::decode($tree) : static::normalize($tree);

        $rejection = $nodes === null
            ? 'the body is not a JSON tree'
            : static::rejectionReason($nodes);

        if ($rejection !== null) {
            if (config('app.debug')) {
                throw new \RuntimeException(
                    "Asgard fragment for region '{$region}' cannot be rendered: {$rejection}"
                );
            }

            return false;
        }

        $nodes = static::checkCallbacks($nodes, $region);

        if ($owner === null) {
            static::$detached[$region] = $nodes;
            unset(static::$registries[$region]);
        } else {
            $held = static::fragments()[$owner] ?? [];
            $held[$region] = $nodes;
            static::fragments()[$owner] = $held;
            static::dropRegistry($region, $owner);
            static::forgetPatchState($region, $owner);

            // A server screen's body has landed, so its timer is off. A
            // later answer that fails leaves this one on screen.
            if ($owner instanceof ServerScreen && $region === ServerScreen::REGION) {
                unset(static::awaiting()[$owner]);
            }
        }

        static::$repaint = true;

        return true;
    }

    /**
     * Deliver one region's part of an answer. deliverBatch() calls it for
     * each part, and a test can call it directly.
     *
     * The fragment goes to the component that rendered the region, which
     * is not necessarily the one an AsyncTask `->finished()` callback is
     * bound to (that is always the screen). It is dropped, and false
     * returned, when the request is unknown, when a newer request for the
     * same region has gone out since, or when the component has gone.
     * Otherwise this is applyFragment() for that component.
     *
     * An ActionRequest is a RegionRequest, and its answer is delivered
     * the same way.
     *
     * `$chrome` is the chrome part of a server screen's answer (§12). It
     * goes to the ServerScreen that asked, once its fragment is accepted,
     * and is ignored for any other component.
     *
     * `$version` is the answer's version token (§8.1). An accepted answer
     * puts its region on the push stream, for the state it was asked with.
     *
     * `$fragments` and `$refresh` are what an action's answer carries when
     * the action called refresh() on the server: other regions of the same
     * component, `{region: {fragment, version?, chrome?}}`, painted as
     * paintInline() says, and hints for other components, handled as
     * hints() says.
     *
     * A fragment that cannot be rendered is a failure with reason `invalid`
     * (see failed()). For a server screen's body, or an action, that is all
     * that happens. Any other region also throws with `app.debug` on, as
     * applyFragment() does.
     *
     * `$madeAt` and `$maxAge` are the server's `made_at` and `max_age` for
     * the fragment. With both a TTL and a `made_at` it is stored in the
     * region cache. `$invalidate` is the response's `Asgard-Cache:
     * invalidate`: a region that holds a cached copy then fires
     * RegionCacheInvalidation, and its fragment waits for settle().
     */
    public static function deliver(RegionRequest $request, array|string $tree, ?array $chrome = null, ?string $version = null, array $fragments = [], array $refresh = [], ?int $madeAt = null, ?int $maxAge = null, bool $invalidate = false): bool
    {
        $entry = static::$pending[$request->id] ?? null;

        if ($entry === null) {
            NativeRouter::debugLog("Asgard: fragment for request [{$request->id}] is not one in flight — dropped.");

            return false;
        }

        $owner = $entry['owner']->get();

        if ($owner === null) {
            unset(static::$pending[$request->id]);
            NativeRouter::debugLog("Asgard: fragment for [{$request->region}] arrived after its component was gone — dropped.");

            return false;
        }

        // An action's answer can carry more (refresh() on the server):
        // other regions of this component, and hints for other components.
        // They are applied after the action's own fragment, whatever
        // became of it, and each one is checked on its own.
        try {
            return static::deliverTo($owner, $entry, $request, $tree, $chrome, $version, $madeAt, $maxAge, $invalidate);
        } finally {
            if ($fragments !== []) {
                static::paintInline($owner, $request->id, $fragments, $request->state, $invalidate);
            }

            if ($refresh !== []) {
                static::hints($refresh);
            }
        }
    }

    /**
     * deliver() for an entry whose owner is still alive.
     *
     * @param  array{owner: \WeakReference<NativeComponent>, region: string}  $entry
     */
    protected static function deliverTo(NativeComponent $owner, array $entry, RegionRequest $request, array|string $tree, ?array $chrome, ?string $version, ?int $madeAt = null, ?int $maxAge = null, bool $invalidate = false): bool
    {
        $latest = (static::requested()[$owner] ?? [])[$entry['region']] ?? null;

        unset(static::$pending[$request->id], static::$retries[$request->id]);

        if ($latest !== $request->id) {
            NativeRouter::debugLog("Asgard: fragment for [{$request->region}] was superseded by a newer request — dropped.");

            return false;
        }

        if ($invalidate && static::hold($owner, $entry['region'], $request->id, $request, $request->state, $tree, $chrome, $version, $madeAt, $maxAge)) {
            return true;
        }

        return static::applyAnswer($owner, $entry['region'], $request, $tree, $chrome, $version, $madeAt, $maxAge);
    }

    /**
     * Paint an answer to a request that was the region's latest, store it,
     * and put the region on the push stream. A fragment that cannot be
     * rendered fails the request instead.
     */
    protected static function applyAnswer(NativeComponent $owner, string $region, RegionRequest $request, array|string $tree, ?array $chrome, ?string $version, ?int $madeAt, ?int $maxAge): bool
    {
        $rejected = null;

        try {
            $applied = static::applyFragment($region, $tree, $owner);
        } catch (\RuntimeException $e) {
            $applied = false;
            $rejected = $e;
        }

        if (! $applied) {
            $handled = static::fail($owner, $region, $request, self::INVALID, null, $rejected?->getMessage() ?? 'the fragment cannot be rendered');
            $quiet = $handled && ($request instanceof ActionRequest || static::isScreenBody($owner, $region));

            // In debug a region's fragment the client cannot render is a
            // mismatch worth hearing about, as applyFragment() says, even
            // though ServerRegionFailed fires too. A server screen still
            // waiting for its body, and an action, fail quietly instead.
            if ($rejected !== null && ! $quiet) {
                throw $rejected;
            }

            return false;
        }

        static::applyChrome($owner, $chrome);
        static::answer($owner, $region, $request->state, $version);
        static::remember($owner, $region, $request->state, $tree, $chrome, $version, $madeAt, $maxAge);

        return true;
    }

    /**
     * The server said the copy the request declared is still good
     * (`{"cached": true}`): the seam a FragmentSource calls in place of
     * deliver() for such an answer.
     *
     * The region keeps what it shows, or paints the stored copy for the
     * request's state when it shows something else. The entry is kept as it
     * is, since the server's `made_at` is unchanged. It is a good answer: a
     * server screen stops waiting, and the region goes on the push stream
     * with the stored version token.
     *
     * When the device no longer holds the copy (flushed or evicted while the
     * request was out), the region is asked for again at once, without
     * declaring a copy (resend()). Only once: a `cached` answer to that
     * second request fails it with reason `invalid`. `$fragments` and
     * `$refresh` are handled as for deliver(). Returns whether the region
     * kept or painted a copy.
     */
    public static function deliverCached(RegionRequest $request, array $fragments = [], array $refresh = [], bool $invalidate = false): bool
    {
        $entry = static::$pending[$request->id] ?? null;

        if ($entry === null) {
            NativeRouter::debugLog("Asgard: cached answer for request [{$request->id}] is not one in flight — dropped.");

            return false;
        }

        $owner = $entry['owner']->get();

        if ($owner === null) {
            unset(static::$pending[$request->id]);

            return false;
        }

        try {
            if (((static::requested()[$owner] ?? [])[$entry['region']] ?? null) !== $request->id) {
                unset(static::$pending[$request->id]);
                NativeRouter::debugLog("Asgard: cached answer for [{$request->region}] was superseded by a newer request — dropped.");

                return false;
            }

            unset(static::$pending[$request->id]);
            $retry = isset(static::$retries[$request->id]);
            unset(static::$retries[$request->id]);

            if (static::keepCached($owner, $entry['region'], $request->state)) {
                return true;
            }

            if ($retry) {
                static::fail($owner, $entry['region'], $request, self::INVALID, null, 'the server said the cached copy is good, and the device holds none, even after asking again');
            } else {
                static::resend($owner, $entry['region'], $request->reason);
            }

            return false;
        } finally {
            if ($fragments !== []) {
                static::paintInline($owner, $request->id, $fragments, $request->state, $invalidate);
            }

            if ($refresh !== []) {
                static::hints($refresh);
            }
        }
    }

    /**
     * Keep, or paint, the stored copy of a region the server answered
     * `cached` for, and count it as answered. False when there is no copy.
     */
    protected static function keepCached(NativeComponent $owner, string $region, array $state): bool
    {
        $stored = RegionCache::get(RegionCache::key(ComponentAddress::for($owner), $region, $state));

        if ($stored === null) {
            return false;
        }

        $shown = (static::copies()[$owner] ?? [])[$region] ?? null;

        if (($shown['made_at'] ?? null) !== $stored['made_at'] || ! static::hasFragment($region, $owner)) {
            try {
                if (! static::applyFragment($region, $stored['fragment'], $owner)) {
                    return false;
                }
            } catch (\RuntimeException) {
                return false;
            }

            static::applyChrome($owner, $stored['chrome']);
        } elseif ($owner instanceof ServerScreen && $region === ServerScreen::REGION) {
            unset(static::awaiting()[$owner]);
        }

        static::setCopy($owner, $region, $stored['made_at'], false);
        static::answer($owner, $region, $state, $stored['version']);

        return true;
    }

    /**
     * A request failed without an answer the client can use: the seam a
     * FragmentSource calls instead of deliver(). `$reason` is one of
     * TIMEOUT, NETWORK, SERVER or INVALID, and `$status` the HTTP status
     * when the server answered with one.
     *
     * The request stops being in flight, so a late answer to it is dropped.
     * Then, on the next turn of the runloop of the screen it belongs to:
     *
     *  - A server screen still waiting for its body gives up and leaves
     *    (ServerScreen::screenTimeout() has the details).
     *  - An action fires ServerActionFailed on the screen it was sent
     *    from.
     *  - Any other region keeps what it had, its placeholder or the last
     *    fragment it painted, and ServerRegionFailed fires on the screen
     *    it is on. It is not asked for again until refresh() names it.
     *  - A server screen's body that already landed keeps it, and nothing
     *    fires.
     *
     * A failure for a request that is not in flight, or that a newer one
     * for the same region has superseded, is ignored. Returns whether it
     * was acted on.
     */
    public static function failed(RegionRequest $request, string $reason, ?int $status = null, ?string $message = null): bool
    {
        $entry = static::$pending[$request->id] ?? null;

        if ($entry === null) {
            NativeRouter::debugLog("Asgard: failure for request [{$request->id}] is not one in flight — ignored.");

            return false;
        }

        unset(static::$pending[$request->id], static::$retries[$request->id]);
        $owner = $entry['owner']->get();

        if ($owner === null) {
            return false;
        }

        if (((static::requested()[$owner] ?? [])[$entry['region']] ?? null) !== $request->id) {
            NativeRouter::debugLog("Asgard: failure for [{$request->region}] was superseded by a newer request — ignored.");

            return false;
        }

        return static::fail($owner, $entry['region'], $request, $reason, $status, $message);
    }

    /**
     * Start a server screen's wait for its body. Called by the screen when
     * it first renders. `$seconds` of 0 or less waits for ever, though a
     * hard failure still ends the wait.
     */
    public static function awaitBody(ServerScreen $screen, float $seconds): void
    {
        if (isset(static::awaiting()[$screen]) || static::hasFragment(ServerScreen::REGION, $screen)) {
            return;
        }

        static::awaiting()[$screen] = [
            'at' => $seconds > 0 ? static::now() + $seconds * 1000 : null,
            'failure' => null,
        ];
    }

    /** Whether a server screen is still waiting for its first body. */
    public static function awaitingBody(ServerScreen $screen): bool
    {
        return isset(static::awaiting()[$screen]);
    }

    /**
     * Whether any timer or failure is waiting to be acted on, anywhere. A
     * cheap check the runloop makes before it works out which are its own.
     */
    public static function hasDeadlines(): bool
    {
        if (static::$failures !== [] || static::$held !== [] || (static::$awaiting !== null && count(static::$awaiting) > 0)) {
            return true;
        }

        foreach (static::$pending as $entry) {
            if ($entry['deadline'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the next timer or failure of these components is due, in
     * milliseconds on the clock of now(), or null when there is none. A
     * failure waiting to be acted on is due now. The runloop passes the
     * components of its screen, so it never wakes for a covered screen's.
     *
     * @param  iterable<NativeComponent>  $owners
     */
    public static function nextDeadline(iterable $owners): ?float
    {
        $mine = static::idsOf($owners);

        // A fragment held for RegionCacheInvalidation settles on the next
        // turn, whichever screen takes it.
        $next = static::$held === [] ? null : static::now();

        foreach (static::awaiting() as $screen => $wait) {
            if (isset($mine[spl_object_id($screen)])) {
                $at = $wait['failure'] !== null ? static::now() : $wait['at'];
                $next = $at === null ? $next : min($next ?? $at, $at);
            }
        }

        foreach (static::$pending as $entry) {
            $owner = $entry['owner']->get();

            if ($entry['deadline'] !== null && $owner !== null && isset($mine[spl_object_id($owner)])) {
                $next = min($next ?? $entry['deadline'], $entry['deadline']);
            }
        }

        foreach (static::$failures as $failure) {
            $owner = $failure['owner']->get();

            if ($owner !== null && isset($mine[spl_object_id($owner)])) {
                $next = min($next ?? static::now(), static::now());
            }
        }

        return $next;
    }

    /**
     * Act on every timer and failure of these components that is due:
     * a server screen whose body did not come gives up, an action that
     * timed out or failed fires ServerActionFailed, and a region request
     * that did fires ServerRegionFailed. The runloop of the
     * screen is the live one, so the screen hears it on its next turn.
     * Called by the runloop at the top of each turn, with its screen's
     * components. Returns whether anything was acted on, which the loop
     * takes as a reason to draw a frame.
     *
     * A screen that gives up is leaving, so nothing else is acted on in
     * that turn.
     *
     * @param  iterable<NativeComponent>  $owners
     */
    public static function expire(iterable $owners): bool
    {
        $mine = static::idsOf($owners);
        $now = static::now();

        foreach (static::awaiting() as $screen => $wait) {
            if (! isset($mine[spl_object_id($screen)])) {
                continue;
            }

            if ($wait['failure'] === null && ($wait['at'] === null || $wait['at'] > $now)) {
                continue;
            }

            unset(static::awaiting()[$screen]);
            $failure = $wait['failure'] ?? ['reason' => self::TIMEOUT, 'status' => null, 'message' => null];

            \Closure::bind(
                fn () => $this->giveUp($failure['reason'], $failure['status'], $failure['message']),
                $screen,
                ServerScreen::class,
            )();

            return true;
        }

        $due = [];

        foreach (static::$pending as $id => $entry) {
            if ($entry['deadline'] === null || $entry['deadline'] > $now) {
                continue;
            }

            $owner = $entry['owner']->get();

            if ($owner === null) {
                unset(static::$pending[$id]);

                continue;
            }

            if (! isset($mine[spl_object_id($owner)])) {
                continue;
            }

            unset(static::$pending[$id]);

            // A superseded request's answer would have been dropped anyway.
            if (((static::requested()[$owner] ?? [])[$entry['region']] ?? null) !== $id) {
                continue;
            }

            $request = $entry['request'];
            $message = 'no answer within '.round(($entry['deadline'] - $entry['sent']) / 1000, 3).' seconds';

            if ($request instanceof ActionRequest) {
                NativeRouter::debugLog("Asgard: action [{$request->call}] for region [{$request->region}] timed out.");

                $due[] = new ServerActionFailed(
                    $request->call, $request->component, $request->region, self::TIMEOUT, null, $request->id, $message,
                );

                continue;
            }

            NativeRouter::debugLog("Asgard: request for region [{$request->region}] timed out.");

            $due[] = static::regionFailure($owner, $entry['region'], $request, self::TIMEOUT, null, $message);
        }

        foreach (static::$failures as $i => $failure) {
            $owner = $failure['owner']->get();

            if ($owner === null) {
                unset(static::$failures[$i]);

                continue;
            }

            if (isset($mine[spl_object_id($owner)])) {
                unset(static::$failures[$i]);
                $due[] = $failure['event'];
            }
        }

        static::$failures = array_values(static::$failures);

        foreach ($due as $event) {
            static::fire($event);
        }

        return $due !== [];
    }

    /**
     * Fire an event through Laravel, best-effort: a listener that throws
     * must not take the screen down. An event marked BroadcastsGlobally
     * also lands in the live screen's inbox, for its next turn.
     */
    public static function fire(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            NativeRouter::debugLog('Asgard: a listener for '.$event::class.' failed: '.$e->getMessage());
        }
    }

    /**
     * Seconds a server action or `#[Server]` call from this component
     * waits for its answer. A component that has serverActionTimeout(),
     * from UsesServer or its own, decides. Any other reads
     * `midgard.asgard.action_timeout`, 15 by default.
     */
    public static function actionTimeout(NativeComponent $owner): float
    {
        return method_exists($owner, 'serverActionTimeout')
            ? (float) $owner->serverActionTimeout()
            : (float) config('midgard.asgard.action_timeout', 15);
    }

    /**
     * Seconds a request for one of this component's regions waits for its
     * answer. A component that has serverRegionTimeout(), from UsesServer
     * or its own, decides. Any other reads
     * `midgard.asgard.region_timeout`, 15 by default.
     */
    public static function regionTimeout(NativeComponent $owner): float
    {
        return method_exists($owner, 'serverRegionTimeout')
            ? (float) $owner->serverRegionTimeout()
            : (float) config('midgard.asgard.region_timeout', 15);
    }

    /**
     * The clock timers run on, in milliseconds. It is Carbon's, so a test
     * can move it with `$this->travel()` or Carbon::setTestNow().
     */
    public static function now(): float
    {
        return (float) Carbon::now()->getPreciseTimestamp(3);
    }

    /**
     * Apply a fragment the server pushed, rather than one answering a request.
     * Only a shared region is pushed this way (§8.1); any other gets a
     * signal().
     *
     * A push (an SSE event) carries no request id, so deliver() would drop
     * it. It names its subscription instead: a component address and the
     * state the server rendered it for. It lands on every live instance of
     * that address that asked for the region with that state. A region a
     * screen never declared gets nothing, so a server cannot use a push to
     * paint somewhere the client did not offer it.
     *
     * Instances of one address with other state are other subscriptions:
     * two server screens of one route with other parameters (`/promos/1`
     * under `/promos/2`), or two cards. Each gets its own pushes, and a
     * push for one never paints the other.
     *
     * Returns how many instances it painted. A server screen's chrome
     * rides along as for deliver(), and `$version` becomes the token each
     * painted instance holds for the region.
     */
    public static function push(string $component, array $state, string $region, array|string $tree, ?array $chrome = null, ?string $version = null, ?int $madeAt = null, ?int $maxAge = null): int
    {
        $painted = 0;

        foreach (static::subscribers($component, $state, $region) as $owner) {
            if (static::applyFragment($region, $tree, $owner)) {
                static::applyChrome($owner, $chrome);
                static::answer($owner, $region, $state, $version);
                static::remember($owner, $region, $state, $tree, $chrome, $version, $madeAt, $maxAge);
                $painted++;
            }
        }

        if ($painted === 0) {
            NativeRouter::debugLog("Asgard: pushed fragment for [{$component}] [{$region}] has no live region with its state — dropped.");
        }

        return $painted;
    }

    /**
     * A region changed on the server, and the stream said so without
     * sending it (a `signal`, §8.1). Every live instance of that address
     * that asked for the region with that state, and does not already hold
     * that version, fetches it again: the same as refresh(), so the request
     * goes out after the next frame that draws the region and the fragment
     * on screen stays until the answer lands. An instance that is not on
     * screen (a screen under another) asks when it is drawn again.
     *
     * Returns how many instances will fetch.
     */
    public static function signal(string $component, array $state, string $region, string $version): int
    {
        $stale = 0;

        foreach (static::subscribers($component, $state, $region) as $owner) {
            if (((static::answered()[$owner] ?? [])[$region]['version'] ?? null) === $version) {
                continue;
            }

            static::refresh($owner, $region, push: true);
            $stale++;
        }

        if ($stale > 0) {
            // Take a turn of the runloop, so the next frame asks.
            static::$repaint = true;
        }

        return $stale;
    }

    /**
     * Change one list in a region the device already holds, from a `patch`
     * event on the push stream (§8.1).
     *
     * `$patch` is the op: `prepend` or `append` with `nodes` and an optional
     * `keep`, or `remove` with `keys`. Its `target` names a node by `key`, the
     * first one depth first, compared as strings, and the op changes that
     * node's children. New nodes go through the checks a fragment's nodes
     * do, element types, media URLs and callback slots, and their taps work
     * the same.
     *
     * It lands on every live instance of the address that asked for the
     * region with that state, as push() does. An instance that already holds
     * `$version` is left alone. One that shows a tree the server sent, holds
     * `$from`, and has the target gets the op applied to its held tree and
     * holds `$version`. Any other, and any op that is not well formed or
     * whose nodes fail a check, is handled as signal() handles it: the region
     * is fetched again whole.
     *
     * Applying is cheap. There is no HTTP, the new nodes are the only ones
     * checked, and the target's path is remembered, so the next patch to it
     * does not search the tree. The region repaints with the next frame,
     * like any fragment that lands. A patch never fires
     * RegionCacheInvalidation. It drops the region's cached copy, once, since
     * that copy no longer matches what is on screen, and the next full answer
     * stores a fresh one.
     *
     * Returns how many instances it patched.
     */
    public static function patch(string $component, array $state, string $region, string $from, string $version, mixed $patch): int
    {
        $patched = 0;
        $fallback = 0;
        $op = static::parsePatch($patch, $reason);

        foreach (static::subscribers($component, $state, $region) as $owner) {
            $held = (static::answered()[$owner] ?? [])[$region]['version'] ?? null;

            if ($held === $version) {
                continue;
            }

            $why = $op === null ? $reason : static::applyPatch($owner, $region, $held, $from, $op);

            if ($why === null) {
                static::answer($owner, $region, $state, $version);
                static::dropCachedCopy($owner, $region, $state);
                $patched++;

                continue;
            }

            NativeRouter::debugLog("Asgard: patch for [{$component}] [{$region}] not applied ({$why}). Fetching the region instead.");
            static::refresh($owner, $region, push: true);
            $fallback++;
        }

        if ($patched + $fallback > 0) {
            static::$repaint = true;
        }

        return $patched;
    }

    /**
     * A patch op in the form applyPatch() takes, or null with `$reason`.
     *
     * @return array{op: string, target: string, nodes?: list<array>, keep?: ?int, keys?: array<string, true>}|null
     */
    protected static function parsePatch(mixed $patch, ?string &$reason = null): ?array
    {
        $reason = null;

        if (! is_array($patch)) {
            $reason = 'it is not an object';

            return null;
        }

        $op = $patch['op'] ?? null;
        $target = $patch['target'] ?? null;

        if ((! is_string($target) && ! is_int($target)) || (string) $target === '') {
            $reason = 'it has no target';

            return null;
        }

        $target = (string) $target;

        if ($op === 'remove') {
            $keys = $patch['keys'] ?? null;

            if (! is_array($keys) || ! array_is_list($keys) || count($keys) > self::PATCH_MAX_KEYS) {
                $reason = 'its keys are not a list of at most '.self::PATCH_MAX_KEYS;

                return null;
            }

            $set = [];
            foreach ($keys as $key) {
                if (! is_string($key) && ! is_int($key)) {
                    $reason = 'a key is not a string';

                    return null;
                }

                $set[(string) $key] = true;
            }

            return ['op' => 'remove', 'target' => $target, 'keys' => $set];
        }

        if ($op !== 'prepend' && $op !== 'append') {
            $reason = 'it has no known op';

            return null;
        }

        $nodes = $patch['nodes'] ?? null;

        if (! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > self::PATCH_MAX_NODES) {
            $reason = 'its nodes are not a list of at most '.self::PATCH_MAX_NODES;

            return null;
        }

        $keep = $patch['keep'] ?? null;

        if ($keep !== null && (! is_int($keep) || $keep < 1 || $keep > self::PATCH_MAX_KEEP)) {
            $reason = 'its keep is not a whole number from 1 to '.self::PATCH_MAX_KEEP;

            return null;
        }

        return ['op' => $op, 'target' => $target, 'nodes' => $nodes, 'keep' => $keep];
    }

    /**
     * Apply a parsed op to the tree an owner holds for a region. Null when it
     * was applied, or why it was not, in which case nothing changed.
     */
    protected static function applyPatch(NativeComponent $owner, string $region, ?string $held, string $from, array $op): ?string
    {
        $all = static::fragments()[$owner] ?? [];
        $nodes = $all[$region] ?? null;

        if ($nodes === null) {
            return 'the region has no tree';
        }

        if ($held !== $from) {
            return 'the device holds another version';
        }

        if ((static::copies()[$owner] ?? [])[$region]['cached'] ?? false) {
            return 'the region shows a cached copy';
        }

        foreach (static::$held as $entry) {
            if ($entry['region'] === $region && $entry['owner']->get() === $owner) {
                return 'a fragment is waiting to replace the region';
            }
        }

        $state = (static::patchState()[$owner] ?? [])[$region] ?? ['targets' => [], 'uncached' => false, 'stale' => false];
        $path = $state['targets'][$op['target']] ?? null;

        if ($path === null || ! static::pathLeadsTo($nodes, $path, $op['target'])) {
            $path = static::findTarget($nodes, $op['target']);
        }

        if ($path === null) {
            return "no node has the key [{$op['target']}]";
        }

        $add = [];

        if ($op['op'] !== 'remove') {
            // The new nodes sit one level under the target.
            $depth = count($path) + 1;
            $add = array_map(fn ($node) => is_array($node) ? $node : null, $op['nodes']);

            if (($problem = static::rejectionReason($add, $depth)) !== null) {
                return "a node cannot be rendered: {$problem}";
            }

            try {
                $add = static::checkCallbacks($add, $region, $depth);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
        }

        // Walk down to the target by reference, so only the arrays on the
        // path are copied, and swap its children.
        $list = &$nodes;
        $last = count($path) - 1;

        foreach ($path as $level => $index) {
            if ($level === $last) {
                break;
            }

            $list = &$list[$index]['children'];
        }

        $target = &$list[$path[$last]];
        $children = $target['children'] ?? [];
        $keep = $op['keep'] ?? null;

        $children = match ($op['op']) {
            'prepend' => $keep !== null && count($add) + count($children) > $keep
                ? array_slice([...$add, ...$children], 0, $keep)
                : [...$add, ...$children],
            'append' => $keep !== null && count($add) + count($children) > $keep
                ? array_slice([...$children, ...$add], -$keep)
                : [...$children, ...$add],
            'remove' => array_values(array_filter(
                $children,
                fn (array $child) => ! (isset($child['key']) && (is_string($child['key']) || is_int($child['key'])) && isset($op['keys'][(string) $child['key']])),
            )),
        };

        $target['children'] = $children;
        unset($target, $list);

        $all[$region] = $nodes;
        static::fragments()[$owner] = $all;

        // Only this target's path is still known to be right: the op may
        // have moved any other one, or put a node with its key first.
        $state['targets'] = [$op['target'] => $path];
        $state['stale'] = true;
        $patches = static::patchState()[$owner] ?? [];
        $patches[$region] = $state;
        static::patchState()[$owner] = $patches;

        return null;
    }

    /** The child indexes down to the first node, depth first, keyed `$target`. */
    protected static function findTarget(array $nodes, string $target): ?array
    {
        foreach ($nodes as $index => $node) {
            $key = $node['key'] ?? null;

            if ((is_string($key) || is_int($key)) && (string) $key === $target) {
                return [$index];
            }

            if (! empty($node['children']) && is_array($node['children'])
                && ($path = static::findTarget($node['children'], $target)) !== null) {
                return [$index, ...$path];
            }
        }

        return null;
    }

    /** Whether a remembered path still ends at a node keyed `$target`. */
    protected static function pathLeadsTo(array $nodes, array $path, string $target): bool
    {
        $node = null;

        foreach ($path as $index) {
            if (! is_array($nodes[$index] ?? null)) {
                return false;
            }

            $node = $nodes[$index];
            $nodes = is_array($node['children'] ?? null) ? $node['children'] : [];
        }

        $key = $node['key'] ?? null;

        return (is_string($key) || is_int($key)) && (string) $key === $target;
    }

    /** Drop a patched region's RegionCache entry, the first time only. */
    protected static function dropCachedCopy(NativeComponent $owner, string $region, array $state): void
    {
        $patches = static::patchState()[$owner] ?? [];

        if ($patches[$region]['uncached'] ?? false) {
            return;
        }

        static::setCopy($owner, $region, null, false);

        if (static::cacheTtl($owner, $region) > 0 && ! str_contains($region, '#')) {
            RegionCache::forget(RegionCache::key(ComponentAddress::for($owner), $region, $state));
        }

        $patches[$region]['uncached'] = true;
        static::patchState()[$owner] = $patches;
    }

    /** Whether a region's registry is to be rebuilt, clearing the flag. */
    protected static function takeStaleRegistry(string $region, ?NativeComponent $owner): bool
    {
        if ($owner === null || ! ((static::patchState()[$owner] ?? [])[$region]['stale'] ?? false)) {
            return false;
        }

        $patches = static::patchState()[$owner];
        $patches[$region]['stale'] = false;
        static::patchState()[$owner] = $patches;

        return true;
    }

    protected static function forgetPatchState(string $region, NativeComponent $owner): void
    {
        $patches = static::patchState()[$owner] ?? null;

        if ($patches === null || ! isset($patches[$region])) {
            return;
        }

        unset($patches[$region]);
        static::patchState()[$owner] = $patches;
    }

    /** @return \WeakMap<NativeComponent, array<string, array{targets: array<string, list<int>>, uncached: bool, stale: bool}>> */
    protected static function patchState(): \WeakMap
    {
        return static::$patchState ??= new \WeakMap;
    }

    /**
     * The server named a component, and maybe one of its regions, as worth
     * drawing again (a `refresh` hint from refresh() on the server, §10.2).
     * It is handled like a stream signal without a version: every live
     * instance of that address that drew the region is marked, and fetches
     * it with its own current state after the next frame that draws it.
     * An instance on a covered screen fetches when that screen is drawn
     * again. With no region, every region the instance drew is marked.
     *
     * A region nobody drew, and a screen that has closed, get nothing.
     * Marks collapse: many hints and signals for one instance and region
     * before it is drawn make one fetch. Returns how many regions were
     * marked.
     */
    public static function hint(string $component, ?string $region = null): int
    {
        $marked = 0;

        foreach (static::requested() as $owner => $regions) {
            if (ComponentAddress::for($owner) !== $component) {
                continue;
            }

            $names = $region === null ? array_keys($regions) : (isset($regions[$region]) ? [$region] : []);

            foreach ($names as $name) {
                // A duplicate name (`stats#2`) never addresses anything.
                if (str_contains((string) $name, '#')) {
                    continue;
                }

                static::refresh($owner, (string) $name);
                $marked++;
            }
        }

        if ($marked > 0) {
            // Take a turn of the runloop, so the next frame asks.
            static::$repaint = true;
        }

        return $marked;
    }

    /**
     * hint() for each `{component, region?}` in an answer's `refresh`. An
     * entry of any other shape is skipped and logged, and at most
     * MAX_REGIONS are read. Returns how many regions were marked.
     */
    public static function hints(array $refresh): int
    {
        $marked = 0;

        foreach (array_slice(array_values($refresh), 0, self::MAX_REGIONS) as $hint) {
            $component = is_array($hint) ? ($hint['component'] ?? null) : null;
            $region = is_array($hint) ? ($hint['region'] ?? null) : null;

            if (! is_string($component) || ($region !== null && ! is_string($region))) {
                NativeRouter::debugLog('Asgard: a refresh hint that is not {component, region?}, skipped.');

                continue;
            }

            $marked += static::hint($component, $region);
        }

        return $marked;
    }

    /**
     * Paint regions an answer carried for a component beyond the one it was
     * for: the `fragments` of an action's answer, or of a `#[Server]`
     * call's (refresh() on the server). Each is
     * `{region: {fragment, version?, chrome?}}`.
     *
     * `$id` is the request the answer is for, and `$state` the state that
     * request carried. A region is painted only when this component drew
     * it, and only when no request for it went out after `$id`, since that
     * answer will be newer. It is checked and painted as deliver() would,
     * chrome and version included, and it supersedes any older request for
     * it still in flight. Nothing fires for a part that is refused.
     *
     * A part may be `{cached: true}`: the copy the request declared is still
     * good, so the stored copy stays or is painted, as deliverCached() does.
     * With `$invalidate`, a part for a region that holds a cached copy fires
     * RegionCacheInvalidation and waits for settle(), as deliver() says.
     *
     * Returns `region => why` for each part that was not painted.
     *
     * @return array<string, string>
     */
    public static function paintInline(NativeComponent $owner, string $id, array $fragments, array $state, bool $invalidate = false): array
    {
        $refused = [];
        $sequence = static::sequenceOf($id);

        foreach ($fragments as $region => $part) {
            $region = (string) $region;
            $latest = (static::requested()[$owner] ?? [])[$region] ?? null;
            $cached = is_array($part) && ! array_key_exists('fragment', $part) && ($part['cached'] ?? null) === true;

            $reason = match (true) {
                ! $cached && (! is_array($part) || ! array_key_exists('fragment', $part) || ! (is_array($part['fragment']) || is_string($part['fragment']))) => 'it is not a fragment',
                $latest === null => 'this component never drew it',
                $latest !== $id && static::sequenceOf($latest) > $sequence => 'a newer request for it went out',
                default => null,
            };

            if ($reason === null && $cached) {
                if (static::keepCached($owner, $region, $state)) {
                    $requested = static::requested()[$owner];
                    $requested[$region] = $id;
                    static::requested()[$owner] = $requested;

                    continue;
                }

                // The copy went while the request was out: ask for it again.
                static::resend($owner, $region);

                continue;
            }

            $madeAt = is_int($part['made_at'] ?? null) ? $part['made_at'] : null;
            $maxAge = is_int($part['max_age'] ?? null) ? $part['max_age'] : null;
            $chrome = is_array($part['chrome'] ?? null) ? $part['chrome'] : null;
            $version = is_string($part['version'] ?? null) ? $part['version'] : null;

            if ($reason === null && $invalidate && static::hold($owner, $region, $id, null, $state, $part['fragment'], $chrome, $version, $madeAt, $maxAge)) {
                $requested = static::requested()[$owner];
                $requested[$region] = $id;
                static::requested()[$owner] = $requested;

                continue;
            }

            if ($reason === null) {
                try {
                    $reason = static::applyFragment($region, $part['fragment'], $owner) ? null : 'the fragment cannot be rendered by this build';
                } catch (\RuntimeException $e) {
                    $reason = $e->getMessage();
                }
            }

            if ($reason !== null) {
                $refused[$region] = $reason;
                NativeRouter::debugLog("Asgard: fragment for region [{$region}] refused: {$reason}");

                continue;
            }

            $requested = static::requested()[$owner];
            $requested[$region] = $id;
            static::requested()[$owner] = $requested;

            static::applyChrome($owner, $chrome);
            static::answer($owner, $region, $state, $version);
            static::remember($owner, $region, $state, $part['fragment'], $chrome, $version, $madeAt, $maxAge);
        }

        return $refused;
    }

    /**
     * Answer a render request (§3.1): the seam a FragmentSource calls with
     * the answer's `fragments` map. Each region's part goes through
     * deliver() as if it had been asked for alone. A region the answer left
     * out fails with reason `invalid`. Returns how many regions were painted.
     *
     * In debug, a fragment that cannot be rendered still throws as
     * deliver() does, once every region has been handled.
     *
     * A part may be `{cached: true}`, which goes through deliverCached().
     * `$invalidate` is the response's `Asgard-Cache: invalidate`.
     *
     * For an action, the part named after its own region is the action's
     * answer. Any other parts are regions of the same component the action
     * redrew, painted as paintInline() says, and `$refresh` holds hints for
     * other components, handled as hints() says. Both are applied whatever
     * became of the action's own part. A plain render's answer carries
     * neither, and parts it was not asked for are ignored.
     *
     * @param  array<string, mixed>  $fragments  `{region: {fragment, version?, chrome?, made_at?, max_age?} | {cached: true}}`
     * @param  list<array{component: string, region?: string}>  $refresh
     */
    public static function deliverBatch(RegionBatch $batch, array $fragments, bool $invalidate = false, array $refresh = []): int
    {
        $action = $batch->action();

        if ($action !== null) {
            return static::deliverAction($action, $fragments, $refresh, $invalidate);
        }

        $painted = 0;
        $thrown = null;

        foreach ($batch->requests as $request) {
            try {
                $painted += static::deliverPart($request, $fragments[$request->region] ?? null, [], [], $invalidate) ? 1 : 0;
            } catch (\RuntimeException $e) {
                $thrown ??= $e;
            }
        }

        if ($thrown !== null) {
            throw $thrown;
        }

        return $painted;
    }

    /** An action's answer: its own part, then the rest. Returns 1 when its own region painted. */
    protected static function deliverAction(ActionRequest $action, array $fragments, array $refresh, bool $invalidate): int
    {
        $others = $fragments;
        unset($others[$action->region]);
        $part = $fragments[$action->region] ?? null;

        if (static::isPart($part)) {
            return static::deliverPart($action, $part, $others, $refresh, $invalidate) ? 1 : 0;
        }

        // The action's own region is missing. It fails, and the rest of the
        // answer still lands on the instance that sent it.
        $owner = (static::$pending[$action->id] ?? null)['owner'] ?? null;
        $owner = $owner?->get();

        static::failed($action, self::INVALID, null, 'the answer has no fragment for this region');

        if ($owner !== null && $others !== []) {
            static::paintInline($owner, $action->id, $others, $action->state, $invalidate);
        }

        if ($refresh !== []) {
            static::hints($refresh);
        }

        return 0;
    }

    /** Whether a part is a fragment or `{cached: true}`. */
    protected static function isPart(mixed $part): bool
    {
        if (! is_array($part)) {
            return false;
        }

        if (! array_key_exists('fragment', $part)) {
            return ($part['cached'] ?? null) === true;
        }

        return is_array($part['fragment']) || is_string($part['fragment']);
    }

    /** Deliver one region's part, or fail the region when the part is missing or not one. */
    protected static function deliverPart(RegionRequest $request, mixed $part, array $others, array $refresh, bool $invalidate): bool
    {
        if (! static::isPart($part)) {
            static::failed($request, self::INVALID, null, 'the answer has no fragment for this region');

            return false;
        }

        if (! array_key_exists('fragment', $part)) {
            return static::deliverCached($request, $others, $refresh, $invalidate);
        }

        return static::deliver(
            $request,
            $part['fragment'],
            is_array($part['chrome'] ?? null) ? $part['chrome'] : null,
            is_string($part['version'] ?? null) ? $part['version'] : null,
            $others,
            $refresh,
            is_int($part['made_at'] ?? null) ? $part['made_at'] : null,
            is_int($part['max_age'] ?? null) ? $part['max_age'] : null,
            $invalidate,
        );
    }

    /**
     * A render request failed as a whole: each region in it fails as failed() says,
     * with its own event. Returns how many were acted on.
     */
    public static function failedBatch(RegionBatch $batch, string $reason, ?int $status = null, ?string $message = null): int
    {
        $acted = 0;

        foreach ($batch->requests as $request) {
            $acted += static::failed($request, $reason, $status, $message) ? 1 : 0;
        }

        return $acted;
    }

    /**
     * A request id that is not sent anywhere, in the same sequence as
     * region and action requests. A `#[Server]` call takes one when it
     * goes out, so fragments its answer carries can be ordered against
     * region requests (paintInline()).
     */
    public static function stamp(): string
    {
        return 'call-'.(++static::$sequence);
    }

    /** Where an id from this class falls in its sequence, or 0 for any other id. */
    protected static function sequenceOf(string $id): int
    {
        return preg_match('/^(?:region|action|call)-(\d+)$/D', $id, $match) ? (int) $match[1] : 0;
    }

    /**
     * The live instances of an address that asked for a region with this
     * state: the state their fragment was answered for, or the state they
     * have asked with since. Either one makes them this subscription's.
     *
     * @return list<NativeComponent>
     */
    protected static function subscribers(string $component, array $state, string $region): array
    {
        $id = static::subscriptionId($component, $state);
        $owners = [];

        foreach (static::requested() as $owner => $regions) {
            if (! isset($regions[$region]) || ComponentAddress::for($owner) !== $component) {
                continue;
            }

            $answered = (static::answered()[$owner] ?? [])[$region]['id'] ?? null;
            $asked = (static::requestedStates()[$owner] ?? [])[$region] ?? null;

            if ($answered === $id || ($asked !== null && static::subscriptionId($component, $asked) === $id)) {
                $owners[] = $owner;
            }
        }

        return $owners;
    }

    /**
     * The subscription id for an address and a state: a short hash of the
     * address and the state in a canonical form, so the same instance
     * always gets the same id and an unchanged set reads as unchanged.
     */
    public static function subscriptionId(string $component, array $state): string
    {
        $canonical = json_encode([$component, static::canonical($state)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return substr(hash('sha256', (string) $canonical), 0, 16);
    }

    /**
     * The push stream subscriptions live owners want now (§8.1): each
     * answered region, grouped by address and the state it was answered
     * for. Sorted by id, with regions sorted, so equal sets compare equal.
     * A region's version is sent only when every owner holds the same one.
     *
     * @return list<array{id: string, component: string, regions: list<string>, state: array, versions: array<string, string>}>
     */
    public static function subscriptions(): array
    {
        $set = [];
        $tokens = [];

        foreach (static::answered() as $owner => $regions) {
            $component = ComponentAddress::for($owner);

            foreach ($regions as $region => $entry) {
                $id = $entry['id'];
                $set[$id] ??= ['id' => $id, 'component' => $component, 'regions' => [], 'state' => $entry['state'], 'versions' => []];
                $set[$id]['regions'][$region] = $region;
                $tokens[$id][$region][] = $entry['version'];
            }
        }

        ksort($set);

        if (count($set) > self::MAX_SUBSCRIPTIONS) {
            NativeRouter::debugLog('Asgard: '.count($set).' subscriptions on screen, and the push stream carries '.self::MAX_SUBSCRIPTIONS.'. The rest get no pushes.');
            $set = array_slice($set, 0, self::MAX_SUBSCRIPTIONS, true);
        }

        foreach ($set as $id => $subscription) {
            $regions = $subscription['regions'];
            ksort($regions);
            $regions = array_slice(array_values($regions), 0, self::MAX_REGIONS);

            $versions = [];
            foreach ($regions as $region) {
                $held = array_unique($tokens[$id][$region], SORT_REGULAR);

                if (count($held) === 1 && is_string($held[array_key_first($held)])) {
                    $versions[$region] = $held[array_key_first($held)];
                }
            }

            $set[$id]['regions'] = $regions;
            $set[$id]['versions'] = $versions;
        }

        return array_values($set);
    }

    /**
     * Hand the push stream set to the source when it has changed since the
     * last call. Versions alone changing is not a change. Called at the end
     * of every frame and by the router after it unmounts a screen.
     *
     * A source that throws is logged and otherwise ignored.
     */
    public static function sync(): void
    {
        $subscriptions = static::subscriptions();
        $shape = array_map(fn (array $s) => array_diff_key($s, ['versions' => true]), $subscriptions);

        if ($shape === static::$watching) {
            return;
        }

        static::$watching = $shape;

        try {
            app(FragmentSource::class)->watch($subscriptions);
        } catch (\Throwable $e) {
            NativeRouter::debugLog('Asgard: the push stream set could not be handed over: '.$e->getMessage());
        }
    }

    /** Drop a region's fragment; it goes back to its placeholder. */
    public static function clearFragment(string $region, ?NativeComponent $owner = null): void
    {
        if ($owner === null) {
            unset(static::$detached[$region], static::$registries[$region]);

            return;
        }

        $held = static::fragments()[$owner] ?? [];
        unset($held[$region]);
        static::fragments()[$owner] = $held;
        static::dropRegistry($region, $owner);
        static::forgetPatchState($region, $owner);
    }

    public static function hasFragment(string $region, ?NativeComponent $owner = null): bool
    {
        return static::fragmentFor($region, $owner) !== null;
    }

    /**
     * The regions of the current frame:
     * `name => ['name' => …, 'key_path' => …, 'has_fragment' => bool]`.
     *
     * Pass a component to see only the regions it rendered. Without one,
     * every region of the frame is listed, and a name two components
     * share shows the later one.
     *
     * The record survives until the next frame begins, so reading it
     * straight after a render gives that render's regions.
     *
     * The key path is built from the enclosing regions' names, which is
     * the node's real key path unless the view also keyed an ancestor of
     * the region by hand. The path then gains that prefix, and only the
     * encoder, which walks the actual tree, sees it.
     */
    public static function rendered(?NativeComponent $owner = null): array
    {
        $regions = [];

        foreach (static::$frame as $record) {
            if ($owner !== null && $record['owner']?->get() !== $owner) {
                continue;
            }

            unset($record['owner']);
            $regions[$record['name']] = $record;
        }

        return $regions;
    }

    /**
     * Start a new frame: drop the previous record and the per-frame
     * duplicate counters. Called by AsgardExtension at the top of a
     * render, which is the only place that reliably knows a frame is
     * starting — a screen can render without touching the collector at
     * all, and one that does must read as having no regions rather than
     * inheriting the last screen's.
     *
     * The frame about to be drawn paints every fragment held so far, so
     * any repaint a delivery asked for is satisfied by it.
     */
    public static function beginFrame(): void
    {
        static::$frame = [];
        static::$open = [];
        static::$duplicates = [];
        static::$repaint = false;
    }

    /**
     * Ask for what the frame just drawn is missing. Called by
     * AsgardExtension once a frame has rendered, on both render paths.
     *
     * A region that painted its placeholder is requested once per
     * component instance, so later frames do not ask again. That holds
     * after its request failed too, so a region that cannot be fetched
     * does not ask on every frame. A region refreshServerRegions() named
     * is requested even though it holds a fragment, which stays on screen
     * until the new one lands. That is also how a failed region tries
     * again. A region rendered outside any component has nothing to
     * address and is never requested.
     *
     * A source that throws fails the request with reason `network`: the
     * region keeps its placeholder and ServerRegionFailed fires, but the
     * screen is not broken.
     *
     * Then the push stream set is synced (sync()).
     */
    public static function requestMissing(): void
    {
        $wanted = [];

        foreach (static::$frame as $record) {
            $owner = $record['owner']?->get();

            if ($owner === null) {
                continue;
            }

            $name = $record['name'];
            $refreshing = static::refreshing()[$owner] ?? [];
            $asked = isset($refreshing['*']) || isset($refreshing[$name]);
            $push = ($refreshing[$name] ?? null) === self::PUSH || ($refreshing['*'] ?? null) === self::PUSH;

            // A copy painted from the cache still asks: the server decides
            // whether it is good.
            if (! $asked && (($record['has_fragment'] && ! $record['from_cache']) || isset((static::requested()[$owner] ?? [])[$name]))) {
                continue;
            }

            unset($refreshing[$name]);
            static::refreshing()[$owner] = $refreshing;

            // A fetch a push signal caused says so, so it goes in a request of its own.
            $key = spl_object_id($owner).($push ? '|push' : '');
            $wanted[$key] ??= ['owner' => $owner, 'regions' => [], 'push' => $push];
            $wanted[$key]['regions'][$name] = $name;
        }

        // One request per component instance, however many regions it wants.
        foreach ($wanted as $want) {
            static::request($want['owner'], array_values($want['regions']), $want['push'] ? self::PUSH : null);
        }

        // A refresh of everything covers this frame's regions only.
        $everything = [];

        foreach (static::refreshing() as $owner => $refreshing) {
            if (isset($refreshing['*'])) {
                $everything[] = $owner;
            }
        }

        foreach ($everything as $owner) {
            $refreshing = static::refreshing()[$owner];
            unset($refreshing['*']);
            static::refreshing()[$owner] = $refreshing;
        }

        // The frame is the point where changes to the stream set settle.
        static::sync();
    }

    /**
     * Fetch a component's regions again after its next frame: one region
     * by name, or every region it renders. The fragment on screen stays
     * until the new one arrives, and an answer still in flight for an
     * earlier request is dropped when it lands.
     *
     * Deferred to the end of the frame rather than sent now, so the
     * request carries the state the calling action leaves behind, not
     * the state halfway through it.
     *
     * `$push` marks a fetch a push stream signal caused. Its request
     * carries `"reason": "push"`, also when it goes out later because the
     * screen was covered when the signal came. Once a push has marked a
     * region, other marks before the fetch leave that reason in place.
     */
    public static function refresh(NativeComponent $owner, ?string $region = null, bool $push = false): void
    {
        $refreshing = static::refreshing()[$owner] ?? [];
        $key = $region ?? '*';
        $refreshing[$key] = $push || ($refreshing[$key] ?? null) === self::PUSH ? self::PUSH : true;
        static::refreshing()[$owner] = $refreshing;
    }

    /** Whether a fragment has landed, or a signal asked for one, since the current frame was drawn. */
    public static function repaintPending(): bool
    {
        return static::$repaint;
    }

    /**
     * Forget a component's requests. Called when it unmounts, so an
     * answer that lands later is dropped rather than held for nobody.
     * Its regions leave the push stream at the next sync().
     */
    public static function forget(NativeComponent $owner): void
    {
        foreach (static::$pending as $id => $entry) {
            $held = $entry['owner']->get();

            if ($held === null || $held === $owner) {
                unset(static::$pending[$id]);
            }
        }

        unset(
            static::requested()[$owner],
            static::refreshing()[$owner],
            static::fragments()[$owner],
            static::ownedRegistries()[$owner],
            static::requestedStates()[$owner],
            static::answered()[$owner],
            static::awaiting()[$owner],
            static::cacheTtls()[$owner],
            static::copies()[$owner],
            static::patchState()[$owner],
        );

        static::$held = array_values(array_filter(
            static::$held,
            fn (array $held) => ! in_array($held['owner']->get(), [null, $owner], true),
        ));

        static::$failures = array_values(array_filter(
            static::$failures,
            fn (array $failure) => ! in_array($failure['owner']->get(), [null, $owner], true),
        ));
    }

    /**
     * The registry a region's current fragment registered its callbacks
     * in, or null when no fragment has rendered since it last changed.
     *
     * Scoped `server:<region>`, after the owner's own scope when the
     * owner is a child component. Ids stay content-addressed within that
     * scope, so a re-render of the same fragment reproduces them.
     */
    public static function registryFor(string $region, ?NativeComponent $owner = null): ?FragmentCallbacks
    {
        if ($owner === null) {
            return static::$registries[$region] ?? null;
        }

        return (static::ownedRegistries()[$owner] ?? [])[$region] ?? null;
    }

    /**
     * Every fragment registry a component holds, `region => registry`.
     * Dispatch reads these to resolve a tap on a fragment node.
     *
     * @return array<string, FragmentCallbacks>
     */
    public static function registriesOf(NativeComponent $owner): array
    {
        return static::ownedRegistries()[$owner] ?? [];
    }

    /**
     * Send a server action for a tap inside a component's region (§10).
     *
     * The request carries the component's state as it is now, and it
     * becomes the region's latest request, so an answer to an older one
     * is dropped when it lands. The answer comes back through deliver().
     * A source that throws fails the action with reason `network`, as for a
     * source that reports the failure itself (failed()).
     *
     * It waits at most actionTimeout() of the component for its
     * answer. After that it fails with reason `timeout`.
     */
    public static function action(NativeComponent $owner, string $region, string $call, array $args): ActionRequest
    {
        $state = static::stateOf($owner);

        $request = new ActionRequest(
            id: 'action-'.(++static::$sequence),
            component: ComponentAddress::for($owner),
            region: $region,
            state: $state,
            call: $call,
            args: array_values($args),
            componentClass: $owner::class,
            cache: static::declaredCache($owner, $state),
        );

        $seconds = static::actionTimeout($owner);

        static::track($owner, $region, $request, $seconds > 0 ? static::now() + $seconds * 1000 : null);

        try {
            app(FragmentSource::class)->action($request);
        } catch (\Throwable $e) {
            NativeRouter::debugLog("Asgard: action [{$call}] for region [{$region}] could not start: ".$e->getMessage());

            static::failed($request, self::NETWORK, null, $e->getMessage());
        }

        return $request;
    }

    /** Forget every fragment, request, registry and frame record. */
    public static function flush(): void
    {
        static::$fragments = null;
        static::$detached = [];
        static::$registries = [];
        static::$ownedRegistries = null;
        static::$frame = [];
        static::$open = [];
        static::$duplicates = [];
        static::$requested = null;
        static::$refreshing = null;
        static::$pending = [];
        static::$repaint = false;
        static::$requestedStates = null;
        static::$answered = null;
        static::$watching = [];
        static::$awaiting = null;
        static::$failures = [];
        static::$cacheTtls = null;
        static::$copies = null;
        static::$held = [];
        static::$retries = [];
        static::$patchState = null;
    }

    /**
     * The state a component sends (ComponentState), kept to what JSON can
     * carry (see RegionRequest). A server screen sends its route parameters
     * instead of its props, and the app-wide keys from `midgard.asgard.state`
     * are added to those too.
     */
    public static function stateOf(NativeComponent $component): array
    {
        if ($component instanceof Contracts\AddressedComponent) {
            return array_filter(ComponentState::withConfigured($component->asgardState()), static::jsonSafe(...));
        }

        return array_filter(ComponentState::of($component), static::jsonSafe(...));
    }

    // ── The region cache ────────────────────────────

    /**
     * The TTL in seconds a region of this component is cached for: its
     * `cacheTtl:` when it set one, or `midgard.asgard.cache.ttl`. A server
     * screen's body always uses the config. 0 is off.
     */
    public static function cacheTtl(NativeComponent $owner, string $region): int
    {
        $ttl = static::isScreenBody($owner, $region) ? null : ((static::cacheTtls()[$owner] ?? [])[$region] ?? null);

        return $ttl === null ? RegionCache::defaultTtl() : RegionCache::clamp($ttl);
    }

    /**
     * The `cache` member for a request from this component with this state:
     * `{region: {made_at, ttl}}` for each region it drew whose stored copy
     * is still within its TTL by the device's clock. An expired copy is not
     * declared, though it may be on screen.
     *
     * @return array<string, array{made_at: int, ttl: int}>
     */
    public static function declaredCache(NativeComponent $owner, array $state): array
    {
        $declared = [];
        $now = static::now();
        $address = ComponentAddress::for($owner);

        foreach (array_keys(static::cacheTtls()[$owner] ?? []) as $region) {
            $region = (string) $region;
            $ttl = str_contains($region, '#') ? 0 : static::cacheTtl($owner, $region);

            if ($ttl <= 0) {
                continue;
            }

            $stored = RegionCache::get(RegionCache::key($address, $region, $state));

            if ($stored === null) {
                continue;
            }

            $ttl = RegionCache::effectiveTtl($ttl, $stored['max_age']);
            $age = $now - $stored['stored_at'];

            if ($ttl > 0 && $age >= 0 && $age < $ttl * 1000) {
                $declared[$region] = ['made_at' => $stored['made_at'], 'ttl' => $ttl];
            }

            if (count($declared) >= RegionCache::MAX_DECLARED) {
                break;
            }
        }

        return $declared;
    }

    /**
     * Whether a region of this component shows a copy from the cache that
     * the server has not answered for yet.
     */
    public static function showsCachedCopy(NativeComponent $owner, string $region): bool
    {
        return (static::copies()[$owner] ?? [])[$region]['cached'] ?? false;
    }

    /**
     * Apply the fragments held for RegionCacheInvalidation whose listeners
     * have had their turn: each that was not ignored is painted and stored
     * as any answer is. An ignored one keeps the copy on screen and in the
     * cache, and the region still counts as answered. A fragment a newer
     * request superseded, or whose component has gone, is dropped.
     *
     * Called by AsgardExtension on each turn, after the screen's `#[On]`
     * listeners for the events fired since the last turn have run. Returns
     * whether anything was applied or kept.
     */
    public static function settle(): bool
    {
        $held = static::$held;
        static::$held = [];
        $acted = false;

        foreach ($held as $entry) {
            $owner = $entry['owner']->get();

            if ($owner === null || ((static::requested()[$owner] ?? [])[$entry['region']] ?? null) !== $entry['id']) {
                continue;
            }

            $acted = true;

            if ($entry['event']->ignored()) {
                NativeRouter::debugLog("Asgard: kept the cached copy of [{$entry['region']}]: a listener ignored the invalidation.");

                $shown = (static::copies()[$owner] ?? [])[$entry['region']]['made_at'] ?? null;
                static::setCopy($owner, $entry['region'], $shown, false);
                static::answer($owner, $entry['region'], $entry['state'], $entry['version']);

                if ($owner instanceof ServerScreen && $entry['region'] === ServerScreen::REGION) {
                    unset(static::awaiting()[$owner]);
                }

                continue;
            }

            if ($entry['request'] !== null) {
                try {
                    static::applyAnswer($owner, $entry['region'], $entry['request'], $entry['tree'], $entry['chrome'], $entry['version'], $entry['made_at'], $entry['max_age']);
                } catch (\RuntimeException $e) {
                    NativeRouter::debugLog("Asgard: fragment for region [{$entry['region']}] refused: ".$e->getMessage());
                }

                continue;
            }

            try {
                if (! static::applyFragment($entry['region'], $entry['tree'], $owner)) {
                    continue;
                }
            } catch (\RuntimeException $e) {
                NativeRouter::debugLog("Asgard: fragment for region [{$entry['region']}] refused: ".$e->getMessage());

                continue;
            }

            static::applyChrome($owner, $entry['chrome']);
            static::answer($owner, $entry['region'], $entry['state'], $entry['version']);
            static::remember($owner, $entry['region'], $entry['state'], $entry['tree'], $entry['chrome'], $entry['version'], $entry['made_at'], $entry['max_age']);
        }

        if ($acted) {
            static::$repaint = true;
        }

        return $acted;
    }

    /**
     * Ask for one region again at once, without declaring a copy of it: the
     * server said a copy is good that the device no longer holds. A render
     * request, whatever the first one was, since an action has already run.
     * It becomes the region's latest request and carries the first one's
     * `reason`.
     */
    protected static function resend(NativeComponent $owner, string $region, ?string $reason = null): void
    {
        $state = static::stateOf($owner);
        $cache = static::declaredCache($owner, $state);
        unset($cache[$region]);

        $request = new RegionRequest(
            id: 'region-'.(++static::$sequence),
            component: ComponentAddress::for($owner),
            region: $region,
            state: $state,
            componentClass: $owner::class,
            cache: $cache,
            reason: $reason,
        );

        NativeRouter::debugLog("Asgard: the server said the cached copy of [{$region}] is good, and the device holds none. Asking again.");

        $seconds = static::isScreenBody($owner, $region) ? 0 : static::regionTimeout($owner);
        static::track($owner, $region, $request, $seconds > 0 ? static::now() + $seconds * 1000 : null);
        static::$retries[$request->id] = true;

        $states = static::requestedStates()[$owner] ?? [];
        $states[$region] = $state;
        static::requestedStates()[$owner] = $states;

        try {
            app(FragmentSource::class)->request(new RegionBatch([$request]));
        } catch (\Throwable $e) {
            static::failed($request, self::NETWORK, null, $e->getMessage());
        }
    }

    /** Whether any fragment waits for settle(). */
    public static function hasHeld(): bool
    {
        return static::$held !== [];
    }

    /**
     * Paint the stored copy of a region that has nothing yet, when caching
     * is on for it and a copy is stored for its address and state. The
     * copy is not an answer: the region still asks, and is not watched on
     * the push stream until the server answers. A copy that no longer
     * renders (the app changed) is dropped from the cache.
     */
    protected static function paintFromCache(string $region, NativeComponent $owner): ?array
    {
        if (str_contains($region, '#') || static::cacheTtl($owner, $region) <= 0) {
            return null;
        }

        $key = RegionCache::key(ComponentAddress::for($owner), $region, static::stateOf($owner));
        $stored = RegionCache::get($key);

        if ($stored === null) {
            return null;
        }

        // Painting a copy is part of drawing this frame, not a reason to
        // draw another one.
        $repaint = static::$repaint;

        try {
            $applied = static::applyFragment($region, $stored['fragment'], $owner);
        } catch (\RuntimeException) {
            $applied = false;
        } finally {
            static::$repaint = $repaint;
        }

        if (! $applied) {
            RegionCache::forget($key);

            return null;
        }

        if ($stored['chrome'] !== null && $owner instanceof ServerScreen) {
            try {
                $owner->receiveChrome($stored['chrome']);
            } catch (\RuntimeException) {
                // Chrome that no longer parses leaves the default.
            }
        }

        static::setCopy($owner, $region, $stored['made_at'], true);

        return static::fragmentFor($region, $owner);
    }

    /**
     * Hold a fragment from an invalidating response for a region that
     * shows or stores a cached copy, and fire RegionCacheInvalidation.
     * False, and nothing held, when the region has no cached copy.
     */
    protected static function hold(NativeComponent $owner, string $region, string $id, ?RegionRequest $request, array $state, array|string $tree, ?array $chrome, ?string $version, ?int $madeAt, ?int $maxAge): bool
    {
        $shown = (static::copies()[$owner] ?? [])[$region] ?? null;
        $oldMadeAt = ($shown['cached'] ?? false) ? $shown['made_at'] : null;

        if ($oldMadeAt === null && static::cacheTtl($owner, $region) > 0) {
            $oldMadeAt = RegionCache::get(RegionCache::key(ComponentAddress::for($owner), $region, $state))['made_at'] ?? null;
        }

        if ($oldMadeAt === null) {
            return false;
        }

        $fragment = is_string($tree) ? json_decode($tree, true) : $tree;

        $event = new RegionCacheInvalidation(
            region: $region,
            component: ComponentAddress::for($owner),
            oldMadeAt: $oldMadeAt,
            newMadeAt: $madeAt,
            fragment: is_array($fragment) ? $fragment : [],
            requestId: $id,
        );

        static::$held[] = [
            'owner' => \WeakReference::create($owner),
            'region' => $region,
            'id' => $id,
            'request' => $request,
            'state' => $state,
            'tree' => $tree,
            'chrome' => $chrome,
            'version' => $version,
            'made_at' => $madeAt,
            'max_age' => $maxAge,
            'event' => $event,
        ];

        // Laravel listeners decide now. The screen's #[On] listeners run on
        // its next turn, which settle() waits for.
        static::fire($event);
        static::$repaint = true;

        return true;
    }

    /**
     * Store a fragment that landed, when caching is on for its region and
     * the server sent a `made_at`. A fragment whose effective TTL is 0 (the
     * server's `max_age` is 0) is not stored, and drops an older copy.
     */
    protected static function remember(NativeComponent $owner, string $region, array $state, array|string $tree, ?array $chrome, ?string $version, ?int $madeAt, ?int $maxAge): void
    {
        static::setCopy($owner, $region, $madeAt, false);

        $ttl = static::cacheTtl($owner, $region);

        if ($ttl <= 0 || str_contains($region, '#')) {
            return;
        }

        $key = RegionCache::key(ComponentAddress::for($owner), $region, $state);
        $ttl = RegionCache::effectiveTtl($ttl, $maxAge);
        $fragment = is_string($tree) ? json_decode($tree, true) : $tree;

        if ($key === null) {
            return;
        }

        if ($ttl <= 0 || $madeAt === null || ! is_array($fragment)) {
            RegionCache::forget($key);

            return;
        }

        RegionCache::put($key, [
            'fragment' => $fragment,
            'chrome' => $chrome,
            'version' => $version,
            'made_at' => $madeAt,
            'max_age' => $maxAge,
            'stored_at' => (int) static::now(),
            'ttl' => $ttl,
        ]);
    }

    protected static function setCopy(NativeComponent $owner, string $region, ?int $madeAt, bool $cached): void
    {
        $copies = static::copies()[$owner] ?? [];
        $copies[$region] = ['made_at' => $madeAt, 'cached' => $cached];
        static::copies()[$owner] = $copies;
    }

    /** @return \WeakMap<NativeComponent, array<string, ?int>> */
    protected static function cacheTtls(): \WeakMap
    {
        return static::$cacheTtls ??= new \WeakMap;
    }

    /** @return \WeakMap<NativeComponent, array<string, array{made_at: ?int, cached: bool}>> */
    protected static function copies(): \WeakMap
    {
        return static::$copies ??= new \WeakMap;
    }

    // ── Server screens (§12) ─────────────────────────

    /**
     * Check a fragment that sits in a server screen's chrome (a title view,
     * bottom bar or tab accessory) by the same rules as a region's, and
     * give back its node list, or null with `$reason`. Never throws.
     */
    public static function checkContent(mixed $tree, ?string &$reason = null): ?array
    {
        $reason = null;
        $nodes = is_array($tree) ? static::normalize($tree) : null;

        if ($nodes === null || $nodes === []) {
            $reason = 'it is not a fragment';

            return null;
        }

        if (($reason = static::rejectionReason($nodes)) !== null) {
            return null;
        }

        try {
            return static::checkCallbacks($nodes, 'chrome');
        } catch (\RuntimeException $e) {
            $reason = $e->getMessage();

            return null;
        }
    }

    /**
     * Emit checked content through the collector, as a region paints its
     * fragment. The caller pins the callback registry.
     */
    public static function paint(array $nodes): void
    {
        static::emit($nodes);
    }

    /**
     * The callback registry of a component's region, made now if no
     * fragment has made it yet. A server screen's chrome registers into
     * its `screen` region's registry, so a chrome button resolves and
     * dispatches exactly like a button in the body.
     */
    public static function registryForChrome(string $region, NativeComponent $owner): FragmentCallbacks
    {
        return static::registryFor($region, $owner) ?? static::newRegistry($region, $owner, '');
    }

    protected static function applyChrome(NativeComponent $owner, ?array $chrome): void
    {
        if ($chrome !== null && $owner instanceof ServerScreen) {
            $owner->receiveChrome($chrome);
        }
    }

    // ── Internals ────────────────────────────────────

    protected static function deduplicate(string $name, ?NativeComponent $owner): string
    {
        $counter = ($owner !== null ? spl_object_id($owner) : 0).'|'.$name;
        $seen = static::$duplicates[$counter] ?? 0;
        static::$duplicates[$counter] = $seen + 1;

        if ($seen === 0) {
            return $name;
        }

        if (config('app.debug')) {
            throw new \RuntimeException(
                "Duplicate Asgard region '{$name}' in one component. Region names address a "
                .'server component and hold its fragment, so they must be unique per component.'
            );
        }

        return $name.'#'.($seen + 1);
    }

    /**
     * Ask for regions of a component, and remember each one. They go out as
     * one RegionBatch per 16 regions, sharing one copy of the state, and
     * each region in it is still tracked on its own. Each waits at most regionTimeout()
     * of the component for its answer. A server screen's body waits on
     * the screen's own timer instead.
     *
     * @param  list<string>  $regions
     */
    protected static function request(NativeComponent $owner, array $regions, ?string $reason = null): void
    {
        $state = static::stateOf($owner);
        $cache = static::declaredCache($owner, $state);
        $requests = [];

        foreach ($regions as $region) {
            $request = new RegionRequest(
                id: 'region-'.(++static::$sequence),
                component: ComponentAddress::for($owner),
                region: $region,
                state: $state,
                componentClass: $owner::class,
                cache: $cache,
                reason: $reason,
            );

            $seconds = static::isScreenBody($owner, $region) ? 0 : static::regionTimeout($owner);

            static::track($owner, $region, $request, $seconds > 0 ? static::now() + $seconds * 1000 : null);

            $states = static::requestedStates()[$owner] ?? [];
            $states[$region] = $state;
            static::requestedStates()[$owner] = $states;

            $requests[] = $request;
        }

        foreach (array_chunk($requests, self::MAX_REGIONS) as $chunk) {
            try {
                app(FragmentSource::class)->request(new RegionBatch($chunk));
            } catch (\Throwable $e) {
                foreach ($chunk as $request) {
                    NativeRouter::debugLog("Asgard: request for region [{$request->region}] could not start: ".$e->getMessage());

                    static::failed($request, self::NETWORK, null, $e->getMessage());
                }
            }
        }
    }

    /** Make a request the region's latest, and remember it as in flight. */
    protected static function track(NativeComponent $owner, string $region, RegionRequest $request, ?float $deadline = null): void
    {
        $requested = static::requested()[$owner] ?? [];
        $requested[$region] = $request->id;
        static::requested()[$owner] = $requested;

        static::$pending[$request->id] = [
            'owner' => \WeakReference::create($owner),
            'region' => $region,
            'deadline' => $deadline,
            'sent' => $deadline === null ? null : static::now(),
            'request' => $request,
        ];
    }

    /**
     * Act on a failed request that was the region's latest. A server
     * screen's first body, an action or an ordinary region is acted on at
     * the owner's next turn (see expire()), and true is returned. A server
     * screen's body that already landed keeps it, and false is returned.
     */
    protected static function fail(NativeComponent $owner, string $region, RegionRequest $request, string $reason, ?int $status, ?string $message): bool
    {
        NativeRouter::debugLog("Asgard: request [{$request->id}] for region [{$region}] failed ({$reason}".($status === null ? '' : " {$status}").'): '.($message ?? ''));

        if ($owner instanceof ServerScreen && $region === ServerScreen::REGION && isset(static::awaiting()[$owner])) {
            static::awaiting()[$owner] = [
                'at' => static::awaiting()[$owner]['at'],
                'failure' => ['reason' => $reason, 'status' => $status, 'message' => $message],
            ];

            // Take a turn of the runloop, which acts on it.
            static::$repaint = true;

            return true;
        }

        if ($request instanceof ActionRequest) {
            static::$failures[] = [
                'owner' => \WeakReference::create($owner),
                'event' => new ServerActionFailed($request->call, $request->component, $region, $reason, $status, $request->id, $message),
            ];

            static::$repaint = true;

            return true;
        }

        if (static::isScreenBody($owner, $region)) {
            return false;
        }

        static::$failures[] = [
            'owner' => \WeakReference::create($owner),
            'event' => static::regionFailure($owner, $region, $request, $reason, $status, $message),
        ];

        static::$repaint = true;

        return true;
    }

    /** Whether a region is a server screen's own body, which has a failure path of its own. */
    protected static function isScreenBody(NativeComponent $owner, string $region): bool
    {
        return $owner instanceof ServerScreen && $region === ServerScreen::REGION;
    }

    protected static function regionFailure(NativeComponent $owner, string $region, RegionRequest $request, string $reason, ?int $status, ?string $message): ServerRegionFailed
    {
        return new ServerRegionFailed(
            $region, $request->component, $reason, $status, $request->id,
            placeholder: ! static::hasFragment($region, $owner),
            message: $message,
        );
    }

    /** @return \WeakMap<ServerScreen, array{at: ?float, failure: ?array}> */
    protected static function awaiting(): \WeakMap
    {
        return static::$awaiting ??= new \WeakMap;
    }

    /**
     * @param  iterable<NativeComponent>  $owners
     * @return array<int, NativeComponent>
     */
    protected static function idsOf(iterable $owners): array
    {
        $ids = [];

        foreach ($owners as $owner) {
            $ids[spl_object_id($owner)] = $owner;
        }

        return $ids;
    }

    protected static function newRegistry(string $region, ?NativeComponent $owner, string $ownerScope): FragmentCallbacks
    {
        $scope = $ownerScope === '' ? 'server:'.$region : $ownerScope.'>server:'.$region;
        $registry = new FragmentCallbacks($scope);

        if ($owner === null) {
            return static::$registries[$region] = $registry;
        }

        $held = static::ownedRegistries()[$owner] ?? [];
        $held[$region] = $registry;
        static::ownedRegistries()[$owner] = $held;

        return $registry;
    }

    protected static function dropRegistry(string $region, NativeComponent $owner): void
    {
        $held = static::ownedRegistries()[$owner] ?? [];
        unset($held[$region]);
        static::ownedRegistries()[$owner] = $held;
    }

    /** Remember what a region's fragment was rendered for, which puts it on the push stream. */
    protected static function answer(NativeComponent $owner, string $region, array $state, ?string $version): void
    {
        $held = static::answered()[$owner] ?? [];
        $held[$region] = [
            'id' => static::subscriptionId(ComponentAddress::for($owner), $state),
            'state' => $state,
            'version' => $version,
        ];
        static::answered()[$owner] = $held;
    }

    /** @return \WeakMap<NativeComponent, array<string, array{id: string, state: array, version: ?string}>> */
    protected static function answered(): \WeakMap
    {
        return static::$answered ??= new \WeakMap;
    }

    /** Assoc arrays with sorted keys, all the way down, so equal state hashes equally. */
    protected static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(static::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @return \WeakMap<NativeComponent, array<string, array>> */
    protected static function requestedStates(): \WeakMap
    {
        return static::$requestedStates ??= new \WeakMap;
    }

    /** @return \WeakMap<NativeComponent, array<string, FragmentCallbacks>> */
    protected static function ownedRegistries(): \WeakMap
    {
        return static::$ownedRegistries ??= new \WeakMap;
    }

    /**
     * The platform the app runs on, `ios` or `android`. Off a device (tests,
     * a web server) System reports none, and this says `unknown` rather than
     * guessing. The default `midgard.asgard.state` sends it as `platform`.
     */
    public static function platform(): string
    {
        try {
            return app(System::class)->platform() ?? 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    protected static function fragmentFor(string $region, ?NativeComponent $owner): ?array
    {
        if ($owner === null) {
            return static::$detached[$region] ?? null;
        }

        return (static::fragments()[$owner] ?? [])[$region] ?? null;
    }

    /** @return \WeakMap<NativeComponent, array<string, array>> */
    protected static function fragments(): \WeakMap
    {
        return static::$fragments ??= new \WeakMap;
    }

    /** @return \WeakMap<NativeComponent, array<string, string>> */
    protected static function requested(): \WeakMap
    {
        return static::$requested ??= new \WeakMap;
    }

    /** @return \WeakMap<NativeComponent, array<string, true>> */
    protected static function refreshing(): \WeakMap
    {
        return static::$refreshing ??= new \WeakMap;
    }

    /** Scalars, null, and arrays of those, with no NaN or infinity. */
    protected static function jsonSafe(mixed $value): bool
    {
        if (is_float($value)) {
            return is_finite($value);
        }

        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! static::jsonSafe($item)) {
                return false;
            }
        }

        return true;
    }

    /** A `tree/json` body as a node list, or null when it is not one. */
    protected static function decode(string $json): ?array
    {
        $tree = json_decode($json, true);

        return is_array($tree) ? static::normalize($tree) : null;
    }

    /** The region's key path: its own name under any enclosing regions'. */
    protected static function keyPath(string $name): string
    {
        return '/'.implode('/', [...static::$open, $name]);
    }

    /**
     * Emit a normalized node list through the collector's public
     * open()/leaf()/close(), which is what makes a fragment work
     * identically in the Element-tree and streaming paths.
     */
    protected static function emit(array $nodes): void
    {
        foreach ($nodes as $node) {
            $type = (string) $node['type'];
            $attrs = static::attrsFor($node);
            $children = static::normalize($node['children'] ?? []);

            if ($children === []) {
                NativeElementCollector::leaf($type, $attrs);

                continue;
            }

            NativeElementCollector::open($type, $attrs);
            static::emit($children);
            NativeElementCollector::close();
        }
    }

    /**
     * Translate one logical node into the attribute bag the collector
     * already speaks — the same vocabulary a `<native:*>` tag compiles
     * to, so fragments and client markup go down one code path.
     *
     * Only known keys are read. A `props` bag is an untrusted map from a
     * server, and the collector drops attrs it does not recognize, so
     * nothing in it can reach a node field the element itself does not
     * expose. The compiler-internal spellings are stripped first: `_*`
     * is how a compiled `@tap` / `@navigate` binding travels, and a
     * server that could write those would be choosing which client
     * method runs (or navigating the app) rather than naming a handler
     * of its own.
     */
    protected static function attrsFor(array $node): array
    {
        $attrs = is_array($node['props'] ?? null) ? $node['props'] : [];

        foreach (array_keys($attrs) as $key) {
            $key = (string) $key;

            if (str_starts_with($key, '_') || str_starts_with($key, 'native-') || str_starts_with($key, 'native:')) {
                unset($attrs[$key]);
            }
        }

        if (isset($node['class']) && is_string($node['class'])) {
            $attrs['class'] = $node['class'];
        }

        // Relative key (§9.1) — the collector hashes it against the
        // region's path, so the server never sees a node id.
        if (isset($node['key']) && (is_string($node['key']) || is_int($node['key']))) {
            $attrs['native-key'] = (string) $node['key'];
        }

        // Callback slots were checked when the fragment was applied
        // (checkCallbacks()). Each becomes an expression only this
        // region's FragmentCallbacks registry reads, carrying its target.
        foreach (self::CALLBACK_ATTRS as $slot => $attr) {
            $callback = is_array($node[$slot] ?? null)
                ? FragmentCallbacks::canonical($node[$slot]['target'] ?? null, $node[$slot]['call'] ?? null, $node[$slot]['args'] ?? null)
                : null;

            if ($callback !== null) {
                $attrs[$attr] = FragmentCallbacks::expressionFor($callback);
            }
        }

        // Ids are the client's (§9.1) — never take one off the wire.
        unset($attrs['id'], $attrs['node_id']);

        return $attrs;
    }

    /**
     * Check every callback slot in a fragment, keeping the canonical
     * form of a good one. A bad one (a string, a bad method name, args
     * that are not JSON values, an unknown target) is dropped, so the
     * node renders without that handler, and logged. With `app.debug` on
     * it throws instead, as an unrenderable fragment does.
     */
    protected static function checkCallbacks(array $nodes, string $region, int $depth = 1): array
    {
        // rejectionReason() refuses a fragment this deep before it gets
        // here. This guard is so the recursion never relies on that.
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException("Asgard fragment for region '{$region}' nests deeper than ".self::MAX_DEPTH.' levels');
        }

        foreach ($nodes as $i => $node) {
            foreach (array_keys(self::CALLBACK_ATTRS) as $slot) {
                if (! array_key_exists($slot, $node)) {
                    continue;
                }

                $callback = FragmentCallbacks::parseSlot($node[$slot], $reason, $slot);

                if ($callback !== null) {
                    $node[$slot] = $callback;

                    continue;
                }

                $message = "Asgard fragment for region '{$region}' has a bad {$slot} on a '{$node['type']}': {$reason}";

                if (config('app.debug')) {
                    throw new \RuntimeException($message);
                }

                NativeRouter::debugLog($message.' — the handler was dropped.');
                unset($node[$slot]);
            }

            if (isset($node['children'])) {
                $node['children'] = static::checkCallbacks(static::normalize($node['children']), $region, $depth + 1);
            }

            $nodes[$i] = $node;
        }

        return $nodes;
    }

    /** Accept a single node or a list of them; always return a list. */
    protected static function normalize(array $tree): array
    {
        if ($tree === []) {
            return [];
        }

        return isset($tree['type']) ? [$tree] : array_values($tree);
    }

    /**
     * Why this fragment cannot be rendered, or null if it can.
     *
     * An unregistered type would throw out of createElement() mid-render
     * and take the whole screen with it, which is the opposite of §3's
     * "a region that never resolves keeps its placeholder". Checking at
     * the seam means a bad fragment is never stored (§9.4).
     *
     * A fragment may use only core types and the types this client
     * advertised in its handshake (FragmentTypes), may not nest deeper
     * than MAX_DEPTH, and its media props must pass MediaUrl.
     */
    protected static function rejectionReason(array $nodes, int $depth = 1): ?string
    {
        if ($nodes !== [] && $depth > self::MAX_DEPTH) {
            return 'it nests deeper than '.self::MAX_DEPTH.' levels';
        }

        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['type']) || ! is_string($node['type'])) {
                return 'a node has no type';
            }

            $type = $node['type'];

            if (($problem = FragmentTypes::problem($type)) !== null) {
                return $problem;
            }

            if (($problem = MediaUrl::nodeProblem($type, $node['props'] ?? null)) !== null) {
                return $problem;
            }

            $children = $node['children'] ?? [];

            if (! is_array($children)) {
                return "'{$type}' has children that are not a list";
            }

            if (($reason = static::rejectionReason(static::normalize($children), $depth + 1)) !== null) {
                return $reason;
            }
        }

        return null;
    }
}
