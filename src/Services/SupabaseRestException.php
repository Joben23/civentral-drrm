<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Safe transport exception for PostgREST failures.
 *
 * The response body is deliberately not retained so database messages,
 * relation names, SQL text, and other implementation details cannot leak
 * through an application exception chain.
 */
final class SupabaseRestException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly ?string $sqlState = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function sqlState(): ?string
    {
        return $this->sqlState;
    }
}
