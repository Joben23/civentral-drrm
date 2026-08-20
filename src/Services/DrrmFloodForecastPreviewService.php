<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\PagasaConfig;
use RuntimeException;

/**
 * Development-only safe projection of official PAGASA weather information.
 */
final class DrrmFloodForecastPreviewService
{
    public function __construct(
        private readonly PagasaTenDayClient $client,
        bool $localDevelopmentPreviewAllowed
    ) {
        if (!$localDevelopmentPreviewAllowed) {
            throw new RuntimeException('The flood forecast preview is unavailable.');
        }
    }

    /** @return array<string, mixed> */
    public function preview(): array
    {
        $issuance = null;
        try {
            $issuance = $this->client->issuance();
        } catch (PagasaUnavailableException|PagasaAccessException) {
            // The detailed configuration state remains useful even when the
            // public issuance helper is temporarily unavailable.
        }

        $base = [
            'weather_source' => [
                'agency' => 'DOST-PAGASA',
                'product' => 'TenDay Weather Forecast',
            ],
            'forecast_location' => [
                'requested_name' => PagasaConfig::CALOOCAN_NAME,
                'requested_psgc_10_digit' => PagasaConfig::CALOOCAN_PSGC_10_DIGIT,
                'reported_name' => null,
                'reported_province' => null,
                'reported_region' => null,
            ],
            'issuance' => $issuance,
            'forecast' => [],
            'source_metadata' => [],
            'response_metadata' => [],
        ];

        if (!$this->client->isConfigured()) {
            return $base + [
                'api_status' => 'NOT_CONFIGURED',
                'message' => 'PAGASA API access is not configured for this development environment.',
            ];
        }

        try {
            $forecast = $this->client->fullForecastForCaloocan();
            $base['forecast'] = $forecast['forecast'];
            $base['source_metadata'] = $forecast['source_metadata'];
            $base['response_metadata'] = $forecast['response_metadata'];
            $base['forecast_location']['reported_name'] = $forecast['reported_location']['name'];
            $base['forecast_location']['reported_province'] = $forecast['reported_location']['province'];
            $base['forecast_location']['reported_region'] = $forecast['reported_location']['region'];

            return $base + [
                'api_status' => 'AVAILABLE',
                'message' => 'Official PAGASA TenDay forecast loaded.',
            ];
        } catch (PagasaNotConfiguredException|PagasaAccessException) {
            return $base + [
                'api_status' => 'ACCESS_REQUIRED',
                'message' => 'PAGASA API access is not configured for this development environment.',
            ];
        } catch (PagasaUnavailableException) {
            return $base + [
                'api_status' => 'TEMPORARILY_UNAVAILABLE',
                'message' => 'PAGASA weather forecast is temporarily unavailable.',
            ];
        }
    }
}
