<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Server-only boundary for official external advisory providers.
 *
 * Implementations must use fixed, trusted server configuration. Browser
 * URLs, credentials, and request options must never cross this boundary.
 */
interface ExternalAdvisoryProviderInterface
{
    public const STATUS_CONNECTED = 'CONNECTED';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';

    public function sourceCode(): string;

    /**
     * @return array{
     *   source_code: string,
     *   integration_status: string,
     *   source_kind: string,
     *   operational_advisory_status: string,
     *   supports_operational_advisory_fetch: bool,
     *   message: string
     * }
     */
    public function classification(): array;

    public function fetch(): ExternalAdvisoryFetchResult;
}
