<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeComponent;

/**
 * The patch feed screen, sending frames the way a device with subtree memo
 * does: REUSE markers for what native already holds. Off a device the
 * runtime flags are 0, so this is the only way to exercise that path.
 */
class MemoPatchFeedScreen extends PatchFeedScreen
{
    protected function subtreeMemoEnabled(): bool
    {
        return true;
    }

    /** Render and serialize one frame, as the runloop does, without a runloop. */
    public static function frame(self $screen): array
    {
        return \Closure::bind(function () {
            $this->nativeCallbacks ??= new CallbackRegistry;

            return $this->memoizedToArray($this->renderToElement());
        }, $screen, NativeComponent::class)();
    }
}
