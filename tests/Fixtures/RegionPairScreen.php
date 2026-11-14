<?php

namespace Native\Api\Client\Tests\Fixtures;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/** A screen with two @server regions, so its first frame asks for both in one batch. */
class RegionPairScreen extends NativeComponent
{
    public int $id = 1;

    public function render(): View
    {
        return view('region-pair-screen');
    }
}
