<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\PagasaConfig;
use JsonException;
use RuntimeException;

final class PagasaNotConfiguredException extends RuntimeException
{
}

final class PagasaAccessException extends RuntimeException
{
}

final class PagasaUnavailableException extends RuntimeException
{
}

/**
 * Reusable client for the official DOST-PAGASA TenDay forecast service.
 *
 * The current official documentation requires the approved token in a
 * `token` request header. No token, URL, or upstream response details are
 * returned to the browser.
 */
final class PagasaTenDayClient
{
    public function __construct(
        private readonly PagasaConfig $config,
        private readonly int $connectionTimeoutSeconds = 5,
        private readonly int $requestTimeoutSeconds = 20
    ) {
        if ($connectionTimeoutSeconds < 1 || $requestTimeoutSeconds < 1) {
            throw new PagasaUnavailableException('PAGASA request timeouts must be positive.');
        }
    }

    public function isConfigured(): bool
    {
        return $this->config->hasApiToken();
    }

    /** @return array{latest_date: string, latest_time: string, start_date: string, end_date: string} */
    public function issuance(): array
    {
        $payload = $this->request('/api/v1/tenday/issuance', [], false);
        $keys = ['latest_date', 'latest_time', 'start_date', 'end_date'];
        $issuance = [];
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value === '') {
                throw new PagasaUnavailableException('The PAGASA issuance response is incomplete.');
            }
            $issuance[$key] = $value;
        }

        return $issuance;
    }

    /**
     * @return array{
     *   forecast: list<array<string, mixed>>,
     *   source_metadata: array<string, mixed>,
     *   response_metadata: array<string, mixed>,
     *   reported_location: array{name: string, province: ?string, region: ?string}
     * }
     */
    public function fullForecastForCaloocan(): array
    {
        if (!$this->config->hasApiToken()) {
            throw new PagasaNotConfiguredException('PAGASA API access is not configured.');
        }

        $payload = $this->request('/api/v1/tenday/full', [
            // The official API documents name or PSGC filtering. Using the
            // current PSA city PSGC avoids ambiguous city-name matching.
            'municity' => PagasaConfig::CALOOCAN_PSGC_10_DIGIT,
            'page' => 'none',
        ], true);

        return $this->normalizeFullForecast($payload);
    }

    /** @param array<string, scalar> $query @return array<string, mixed> */
    private function request(string $path, array $query, bool $requiresToken): array
    {
        if (!extension_loaded('curl')) {
            throw new PagasaUnavailableException('The PHP cURL extension is required for PAGASA requests.');
        }
        if (!preg_match('#^/api/v1/[a-z/]+$#', $path)) {
            throw new PagasaUnavailableException('The PAGASA API path is invalid.');
        }
        if ($requiresToken && !$this->config->hasApiToken()) {
            throw new PagasaNotConfiguredException('PAGASA API access is not configured.');
        }

        $url = $this->config->baseUrl() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new PagasaUnavailableException('The PAGASA request could not be initialized.');
        }

        $headers = ['Accept: application/json'];
        if ($requiresToken) {
            $headers[] = 'token: ' . $this->config->apiToken();
        }
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectionTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->requestTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'CIVENTRAL-DRRM/1.0',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HTTPGET => true,
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        if ($body === false) {
            $code = curl_errno($handle);
            curl_close($handle);
            throw new PagasaUnavailableException('The PAGASA request failed at the network layer (cURL code ' . $code . ').');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PagasaUnavailableException('The PAGASA response was not valid JSON.');
        }
        if (!is_array($payload)) {
            throw new PagasaUnavailableException('The PAGASA response structure was invalid.');
        }
        if (in_array($status, [401, 403, 498], true)) {
            throw new PagasaAccessException('PAGASA API access could not be authorized.');
        }
        if ($status === 429 || $status < 200 || $status >= 300) {
            throw new PagasaUnavailableException('The PAGASA service request was unsuccessful.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeFullForecast(array $payload): array
    {
        $records = $payload['forecast'] ?? $payload['data'] ?? null;
        if (!is_array($records) || !array_is_list($records) || $records === [] || count($records) > 20) {
            throw new PagasaUnavailableException('The PAGASA full-forecast response did not contain forecast records.');
        }

        $normalized = [];
        $reportedNames = [];
        $reportedProvinces = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new PagasaUnavailableException('A PAGASA forecast record was malformed.');
            }

            $date = trim((string) ($record['date'] ?? ''));
            $municity = trim((string) ($record['municity'] ?? ''));
            if ($date === '' || !$this->isCaloocanName($municity)) {
                throw new PagasaUnavailableException('The PAGASA forecast response did not match Caloocan City.');
            }

            $province = $this->nullableString($record['province'] ?? null);
            $reportedNames[$municity] = true;
            if ($province !== null) {
                $reportedProvinces[$province] = true;
            }
            $normalized[] = [
                'date' => $date,
                'province' => $province,
                'municity' => $municity,
                'rainfall_desc' => $this->nullableString($record['rainfall_desc'] ?? null),
                'rainfall_total' => $this->nullableNumber($record['rainfall_total'] ?? null),
                'cloud_cover' => $this->nullableString($record['cloud_cover'] ?? null),
                'tmean' => $this->nullableNumber($record['tmean'] ?? null),
                'tmin' => $this->nullableNumber($record['tmin'] ?? null),
                'tmax' => $this->nullableNumber($record['tmax'] ?? null),
                'humidity' => $this->nullableNumber($record['humidity'] ?? null),
                'wind_speed' => $this->nullableNumber($record['wind_speed'] ?? null),
                'wind_direction' => $this->nullableString($record['wind_direction'] ?? null),
            ];
        }

        if (count($reportedNames) !== 1 || count($reportedProvinces) > 1) {
            throw new PagasaUnavailableException('The PAGASA forecast response covered an unexpected geographic scope.');
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $misc = is_array($payload['misc'] ?? null) ? $payload['misc'] : [];

        return [
            'forecast' => $normalized,
            'source_metadata' => $this->allowMetadata($metadata, [
                'request_no', 'api', 'forecast', 'issuance_date', 'region', 'province', 'municity',
            ]),
            'response_metadata' => $this->allowMetadata($misc, [
                'version', 'timestamp', 'status_code', 'description', 'total_count', 'total_pages',
            ]),
            'reported_location' => [
                'name' => (string) array_key_first($reportedNames),
                'province' => $reportedProvinces === [] ? null : (string) array_key_first($reportedProvinces),
                'region' => $this->nullableString($metadata['region'] ?? null),
            ],
        ];
    }

    private function isCaloocanName(string $name): bool
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
        return in_array($normalized, ['city of caloocan', 'caloocan city', 'caloocan'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new PagasaUnavailableException('A PAGASA forecast field had an invalid type.');
        }
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function nullableNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new PagasaUnavailableException('A PAGASA numeric forecast field was invalid.');
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new PagasaUnavailableException('A PAGASA numeric forecast field was invalid.');
        }
        return floor($number) === $number ? (int) $number : $number;
    }

    /** @param array<string, mixed> $metadata @param list<string> $allowed */
    private function allowMetadata(array $metadata, array $allowed): array
    {
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $metadata) && is_scalar($metadata[$key])) {
                $safe[$key] = $metadata[$key];
            }
        }
        return $safe;
    }
}
