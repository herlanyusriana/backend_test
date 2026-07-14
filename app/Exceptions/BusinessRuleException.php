<?php

namespace App\Exceptions;

use RuntimeException;

class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
