<?php

namespace Native\Api\Client\Support\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ApiValidationException extends HttpException
{
    public function __construct(?Throwable $previous = null, string $message = 'There were validation errors')
    {
        parent::__construct(422, $message, $previous);
    }
}
