<?php

namespace Native\Api\Client\Asgard\Security;

use RuntimeException;

/**
 * A message failed a check: digest, signature, freshness, decryption or
 * shape. The message says which, for logs. Never show it to the other side.
 */
final class SecurityException extends RuntimeException {}
