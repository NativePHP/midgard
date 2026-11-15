<?php

namespace Native\Api\Client\Asgard;

use Closure;
use Native\Mobile\Edge\ComponentMethodInvoker;
use Native\Mobile\Edge\Enums\ActionSource;
use Native\Mobile\Edge\Exceptions\ComponentMethodNotFoundException;
use Native\Mobile\Edge\Exceptions\DirectlyCallingLifecycleHooksNotAllowedException;
use Native\Mobile\Edge\Exceptions\RemoteActionNotAllowedException;
use Native\Mobile\Edge\Extensions\RunloopExtensionBase;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Edge\NativeRouter;
use Native\Mobile\Edge\Transition;

/**
 * Asgard's hook into every screen's runloop. Registered once by
 * AsgardServiceProvider.
 *
 * - A frame starts a fresh record of the regions on screen, and once it
 *   has rendered, the regions still on their placeholder ask for their
 *   fragments (protocol §3).
 * - A fragment that landed without an event wakes the loop to paint it,
 *   and Asgard's timers wake it when they come due (nextWake, tick).
 * - A fragment that replaces a cached copy waits one turn, so the
 *   screen's RegionCacheInvalidation listeners can keep the copy (tick).
 * - A `#[Server]` answer arrives as a native event the loop is already
 *   waiting on, and is claimed here before any listener sees it.
 * - A tap inside a fragment resolves in that region's own callback
 *   registry (§9.2, §10).
 * - A `#[Server]` method runs its body, sends, and holds its closure
 *   instead of running to completion.
 * - An unmounted component's requests are dropped.
 */
class AsgardExtension extends RunloopExtensionBase
{
    public function beginFrame(): void
    {
        // The record of which regions are on screen belongs to one frame.
        // A screen that renders without the collector (a programmatic
        // Element tree) must read as having no regions, not as still
        // having the previous screen's.
        ServerRegion::beginFrame();
    }

    public function endFrame(NativeComponent $root): void
    {
        // Regions that painted a placeholder this frame ask for their
        // fragment now, once per component.
        ServerRegion::requestMissing();
    }

    public function nextWake(array $tree): ?int
    {
        // A fragment landed since the last frame without an event to wake
        // the loop (a source that answered at once, from a cache say).
        if (ServerRegion::repaintPending()) {
            return 0;
        }

        if (! ServerRegion::hasDeadlines() && ! ServerMethods::hasDeadlines()) {
            return null;
        }

        // A server screen or action that waits too long fails on time.
        // The timers run on Carbon's clock, which tests can move.
        $deadlines = array_filter(
            [ServerRegion::nextDeadline($tree), ServerMethods::nextDeadline($tree)],
            fn (?float $at) => $at !== null,
        );

        if ($deadlines === []) {
            return null;
        }

        return max(0, (int) ceil(min($deadlines) - ServerRegion::now()));
    }

    public function tick(array $tree): bool
    {
        if (! ServerRegion::hasDeadlines() && ! ServerMethods::hasDeadlines()) {
            return false;
        }

        // Fragments held for RegionCacheInvalidation. The screen's #[On]
        // listeners ran at the top of this turn, so every one has decided.
        $acted = ServerRegion::settle();

        $acted = ServerRegion::expire($tree) || $acted;

        // A server screen that gave up has left, and its calls with it.
        if (! ($tree[0] ?? null)?->hasNavigationIntent()) {
            $acted = ServerMethods::expire($tree) || $acted;
        }

        return $acted;
    }

    public function handleNativeEvent(string $name, mixed $payload): bool
    {
        // A #[Server] answer (ASGARD-SERVER-METHODS.md §3). The request ran
        // natively while the loop blocked, so the answer comes back on the
        // channel the loop already waits on. It is plumbing, not an event a
        // component listens for, and the next frame paints what it changed.
        if ($name !== ServerMethods::RESPONSE_EVENT) {
            return false;
        }

        ServerMethods::deliver(is_array($payload) ? $payload : []);

        return true;
    }

    public function resolveCallback(NativeComponent $component, int $id): ?Closure
    {
        if ($id === 0) {
            return null;
        }

        $matches = [];

        foreach (ServerRegion::registriesOf($component) as $region => $registry) {
            if (($callback = $registry->resolve($id)) !== null) {
                $matches[] = [$region, $callback];
            }
        }

        if ($matches === []) {
            return null;
        }

        // Two of this component's regions claim the id. The fragments chose
        // their own ids, so neither is guessed at. Claiming it still matters:
        // a callback the screen owns with the same id must not run either.
        if (count($matches) > 1) {
            return function () use ($id) {
                NativeRouter::debugLog("Asgard: callback id [{$id}] is claimed by more than one region, dropped.");
            };
        }

        [$region, $callback] = $matches[0];

        return fn (mixed ...$event) => $this->dispatch($component, $region, $callback, $event);
    }

    public function aroundAction(NativeComponent $component, string $method, array $args, ActionSource $source, Closure $next): mixed
    {
        // A #[Server] method does not run to completion here. Its body runs,
        // the state it leaves behind is sent, and the closure it returned is
        // held until the server answers (ASGARD-SERVER-METHODS.md §2). A
        // call whose name came from outside the app runs as it is, as it
        // did before this was an extension.
        if (! $source->isRemote() && ServerMethods::isServerMethod($component, $method)) {
            return ServerMethods::call($component, $method, $args);
        }

        return $next();
    }

    public function componentUnmounted(NativeComponent $component): void
    {
        // Both hold the component (a pending #[Server] closure is bound to
        // it), so leaving entries behind would keep a dead screen alive. An
        // answer that lands after this is dropped. Both run for every
        // component, since any view can hold an @server region.
        ServerMethods::forget($component);
        ServerRegion::forget($component);
    }

    /**
     * Run a tap on one of a component's fragment callbacks (§10).
     *
     * A `server` target sends an ActionRequest with the state as it is now,
     * and nothing runs here. A `client` target runs a `#[ClientAction]`
     * method of the component as a remote call. A method the fragment may
     * not call runs nothing and is logged, or throws with `app.debug` on.
     * Either way a client callback never falls back to the server.
     */
    protected function dispatch(NativeComponent $component, string $region, array $callback, array $event): void
    {
        $target = $callback['target'] ?? null;
        $method = $callback['method'];
        $args = [...$callback['args'], ...$event];

        if ($target === FragmentCallbacks::TARGET_SERVER) {
            ServerRegion::action($component, $region, $method, $args);

            return;
        }

        if ($target === FragmentCallbacks::TARGET_NAVIGATE) {
            $this->navigate($component, $region, $method, $callback['args']);

            return;
        }

        if ($target !== FragmentCallbacks::TARGET_CLIENT) {
            return;
        }

        try {
            ComponentMethodInvoker::assertRemoteCallable($component, $method, $args);
        } catch (ComponentMethodNotFoundException|DirectlyCallingLifecycleHooksNotAllowedException|RemoteActionNotAllowedException $e) {
            if (config('app.debug')) {
                throw $e;
            }

            NativeRouter::debugLog("Asgard: fragment in region [{$region}] asked for [{$method}], refused: ".$e->getMessage());

            return;
        }

        $component->__invokeInteraction($method, $args, ActionSource::Remote);
    }

    /**
     * Move the app for a navigation slot in a fragment or a server screen's
     * chrome (§12.4). Any route the app knows may be the target, its own or
     * a server screen's, and nothing else: a URI that matches no route runs
     * nothing and is logged, or throws with `app.debug` on. It never falls
     * through to the web the way an unmatched navigate() would.
     *
     * @param  array{0: ?string, 1: ?string}  $args  [uri, transition]
     */
    protected function navigate(NativeComponent $component, string $region, string $mode, array $args): void
    {
        [$uri, $transition] = $args;

        if ($mode !== 'back' && ! NativeRouter::isNativeRoute((string) $uri)) {
            $message = "Asgard: region [{$region}] asked to navigate to [{$uri}], which is not a route this app knows.";

            if (config('app.debug')) {
                throw new \RuntimeException($message);
            }

            NativeRouter::debugLog($message.' Nothing happened.');

            return;
        }

        match ($mode) {
            'back' => $component->back(),
            'replace' => $component->replace((string) $uri),
            default => $component->navigate((string) $uri),
        };

        if ($transition !== null) {
            $component->transition(Transition::from($transition));
        }
    }
}
