<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by domain services when a business rule prevents an operation.
 * Rendered as: { "success": false, "message": "...", "code": "..." }.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BUSINESS_RULE_VIOLATION',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
