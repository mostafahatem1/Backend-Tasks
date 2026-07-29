<?php

namespace App\Exceptions;

use Exception;

class IdempotencyKeyConflictException extends Exception
{
    public function __construct(
        public readonly string $idempotencyKey,
        string $message = 'The idempotency key has already been used with a different order request.'
    ) {
        parent::__construct($message);
    }
}
