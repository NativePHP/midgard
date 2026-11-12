<?php

namespace Native\Mobile\Events\Contracts;

// Stand-in for nativephp/mobile's marker interface, which is not a dev
// dependency of this package. A real install already has it.
if (! interface_exists(DispatchableFromNative::class)) {
    interface DispatchableFromNative {}
}

namespace Native\Mobile\Events\Concerns;

use Native\Mobile\Events\Contracts\DispatchableFromNative;

// Same for the marker that sends a native event through Laravel's dispatcher.
if (! interface_exists(BroadcastsGlobally::class)) {
    interface BroadcastsGlobally extends DispatchableFromNative {}
}
