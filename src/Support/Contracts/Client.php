<?php

namespace Native\Api\Client\Support\Contracts;

use Native\Api\Client\Support\Base;

/**
 * @see Base
 */
interface Client
{
    public function get($uri, $options = []);

    public function post($uri, $options = []);

    public function patch($uri, $options = []);

    public function delete($uri, $options = []);

    /**
     * Disable PUT.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function put();

    public function getMeta($key = null);

    public function getLinks($key = null);

    public function getValidationErrors();
}
