<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Base exception for all Go API communication failures.
 * Subclasses let callers react differently to different failure modes
 * instead of checking a null return value.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        string          $message,
        private int     $statusCode = 0,
        ?\Throwable     $previous   = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

class ApiNotFoundException extends ApiException {}

class ApiUnauthorizedException extends ApiException {}

class ApiValidationException extends ApiException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        private array $errors,
        ?\Throwable   $previous = null,
    ) {
        parent::__construct('API validation failed', 422, $previous);
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}

class ApiNetworkException extends ApiException {}