<?php

namespace Native\Mobile\Events\Contracts;

// Stand-in for nativephp/mobile's marker interface, which is not a dev
// dependency of this package. A real install already has it.
if (! interface_exists(DispatchableFromNative::class)) {
    interface DispatchableFromNative {}
}
