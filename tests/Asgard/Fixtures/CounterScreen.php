<?php

namespace Native\Api\Client\Tests\Asgard\Fixtures;

use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Button;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\Elements\Text;
use Native\Mobile\Edge\NativeComponent;

/**
 * A screen that builds its tree in PHP, with no Blade and no collector, so
 * it can never hold an @server region.
 */
class CounterScreen extends NativeComponent
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): Element
    {
        return Column::make(
            Text::make("Count: {$this->count}"),
            Button::make('Increment')->onPress('increment'),
        );
    }
}
