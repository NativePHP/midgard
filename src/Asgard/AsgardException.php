<?php

namespace Native\Api\Client\Asgard;

use RuntimeException;

/** A handshake or call that could not complete. The code is the HTTP status, or 0. */
class AsgardException extends RuntimeException {}
