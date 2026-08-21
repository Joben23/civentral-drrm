<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\PagasaConfig;

/**
 * Read-only Module 4 projection of official PAGASA information.
 *
 * The public TenDay issuance endpoint provides forecast issuance metadata,
 * not an operational advisory feed. Detailed Caloocan forecasts remain
 * separate and require the approved PAGASA token.
 */
final class DrrmPagasaAdvisoryService
{
    private const ISSUANCE_REFERENCE = 'https://tenday.pagasa.dost.gov.ph/api/v1/tenday/issuance';
    private const DETAILED_REFERENCE = 'https://tenday.pagasa.dost.gov.ph/api/v1/tenday/full';

    public function __construct(private readonly PagasaTenDayClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $publicStatus = 'TEMPORARILY_UNAVAILABLE';
        $publicInformation = null;

        try {
            $issuance = $this->client->issuance();
            $publicStatus = 'AVAILABLE';
            $publicInformation = [
                'information_type' => 'FORECAST_ISSUANCE',
                'title' => 'PAGASA Ten-Day Forecast Issuance',
                'summary' => 'Official forecast issuance metadata is available. This external information is not a CIVENTRAL local warning.',
                'latest_date' => $issuance['latest_date'],
                'latest_time' => $issuance['latest_time'],
                'forecast_period_start' => $issuance['start_date'],
                'forecast_period_end' => $issuance['end_date'],
                'reference' => self::ISSUANCE_REFERENCE,
                'status' => 'AVAILABLE',
                'coverage' => 'National TenDay forecast issuance metadata',
            ];
        } catch (PagasaAccessException|PagasaUnavailableException) {
            // Return a stable safe status while keeping the Module 4 dashboard usable.
        }

        $detailedApi = [
            'status' => 'ACCESS_PENDING',
            'token_required' => true,
            'forecast_entry_count' => 0,
            'coverage' => PagasaConfig::CALOOCAN_NAME . ', NCR',
            'reference' => self::DETAILED_REFERENCE,
        ];

        if ($this->client->isConfigured()) {
            try {
                $forecast = $this->client->fullForecastForCaloocan();
                $detailedApi['status'] = 'AVAILABLE';
                $detailedApi['forecast_entry_count'] = count($forecast['forecast']);
                $reportedLocation = $forecast['reported_location'];
                $reportedCoverage = array_filter([
                    $reportedLocation['name'] ?? null,
                    $reportedLocation['province'] ?? null,
                    $reportedLocation['region'] ?? null,
                ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
                if ($reportedCoverage !== []) {
                    $detailedApi['coverage'] = implode(', ', $reportedCoverage);
                }
            } catch (PagasaNotConfiguredException|PagasaAccessException) {
                $detailedApi['status'] = 'ACCESS_PENDING';
            } catch (PagasaUnavailableException) {
                $detailedApi['status'] = 'TEMPORARILY_UNAVAILABLE';
            }
        }

        $dataAvailability = match (true) {
            $publicStatus === 'AVAILABLE' && $detailedApi['status'] === 'AVAILABLE' => 'PUBLIC_AND_DETAILED_AVAILABLE',
            $publicStatus === 'AVAILABLE' => 'PUBLIC_ISSUANCE_ONLY',
            $detailedApi['status'] === 'AVAILABLE' => 'DETAILED_ONLY',
            default => 'TEMPORARILY_UNAVAILABLE',
        };

        return [
            'source' => [
                'agency' => 'DOST-PAGASA',
                'product' => 'TenDay Weather Forecast',
            ],
            'public_information_status' => $publicStatus,
            'public_information' => $publicInformation,
            'detailed_api' => $detailedApi,
            // The configured TenDay source is a forecast product, not an
            // advisory feed. Never synthesize an advisory from its metadata.
            'advisories' => [],
            'advisory_message' => 'No applicable PAGASA advisory available from the configured TenDay source.',
            'data_availability' => $dataAvailability,
            'external_information_only' => true,
        ];
    }
}
