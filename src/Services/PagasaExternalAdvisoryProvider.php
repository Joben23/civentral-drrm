<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/ExternalAdvisoryProviderInterface.php';
require_once __DIR__ . '/ExternalAdvisoryFetchResult.php';

/**
 * PAGASA operational-advisory readiness adapter.
 *
 * The repository currently has a real Ten-Day forecast API connection, but
 * no verified official machine-readable operational advisory source. This
 * adapter therefore stages nothing and never converts forecast metadata into
 * a synthetic advisory.
 */
final class PagasaExternalAdvisoryProvider implements ExternalAdvisoryProviderInterface
{
    public function sourceCode(): string
    {
        return 'PAGASA';
    }

    public function classification(): array
    {
        return [
            'source_code' => $this->sourceCode(),
            'integration_status' => self::STATUS_PARTIAL,
            'source_kind' => 'FORECAST_METADATA_API',
            'operational_advisory_status' => self::STATUS_PENDING,
            'supports_operational_advisory_fetch' => false,
            'message' => 'PAGASA Ten-Day forecast metadata is connected; operational advisory ingestion awaits a verified official machine-readable source.',
        ];
    }

    public function fetch(): ExternalAdvisoryFetchResult
    {
        return ExternalAdvisoryFetchResult::skipped(
            'PAGASA operational advisory synchronization is pending a verified official machine-readable source.'
        );
    }
}
