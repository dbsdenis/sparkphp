<?php

/**
 * Thrown when validation fails.
 * Provides structured access to field-level error messages
 * without coupling validation to HTTP response or exit.
 */
class ValidationException extends \RuntimeException
{
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
