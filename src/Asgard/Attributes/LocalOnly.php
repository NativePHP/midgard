<?php

namespace Native\Api\Client\Asgard\Attributes;

use Attribute;

/**
 * Keeps a public property, or a #[Computed] method's value, on the device
 * (docs/ASGARD-SERVER-METHODS.md §2.1).
 *
 *     #[LocalOnly]
 *     public string $draft = '';
 *
 * A component's public props are its state, and its state goes to the
 * server with every region request, action, `#[Server]` call, upload and
 * push subscription. Some public props are only public so a view or a
 * binding can reach them: a draft the user has not sent, a token the app
 * keeps for itself. This one is left out of all of it, and a server delta
 * that names it is ignored, so the server can neither read it nor set it.
 *
 * Computed values are state too, so a #[Computed] method takes it as well.
 *
 * It is inherited. A subclass keeps it on the parent's props and computed
 * methods, even one it declares again.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class LocalOnly {}
