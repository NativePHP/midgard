<?php

namespace Native\Api\Client\Asgard\Attributes;

use Attribute;

/**
 * Lets the server read a public prop but never change it.
 *
 *     #[ServerReadOnly]
 *     public string $userId = '';
 *
 * The prop still goes to the server in `state` with every request, so the
 * server half can read it. Every request that sends state also sends
 * `read_only`, the state keys the server may not change: these props and
 * the component's #[Computed] values. The server leaves them out of every
 * delta, and the device ignores one that names them anyway.
 *
 * #[LocalOnly] wins: a prop that is both is not sent at all.
 *
 * It is inherited. A subclass keeps it on the parent's props, even one it
 * declares again.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ServerReadOnly {}
