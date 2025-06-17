<?php

namespace Native\Api\Client\Support\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiValidationException extends HttpException
{
    public function __construct($previous = null, $message = 'There were validation errors')
    {
        parent::__construct(422, $message, $previous);
    }
}
