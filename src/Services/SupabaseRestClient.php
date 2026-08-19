<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\SupabaseConfig;
use JsonException;
use RuntimeException;

/**
 * Minimal server-side client for Supabase Data REST API requests.
 *
 * Credentials remain confined to the server-side apikey header. Mutation
 * methods return representations so controlled CLI jobs can verify exactly
 * what PostgreSQL accepted without exposing request headers.
 */
final class SupabaseRestClient
{
    public function __construct(
        private readonly SupabaseConfig $config,
        private readonly int $connectionTimeoutSeconds = 5,
        private readonly int $requestTimeoutSeconds = 15
    ) {
        if ($connectionTimeoutSeconds < 1 || $requestTimeoutSeconds < 1) {
            throw new RuntimeException('Supabase request timeouts must be positive.');
        }
    }

    /**
     * @param array<string, scalar> $query
     * @return array<mixed>
     */
    public function get(string $resource, array $query = []): array
    {
        return $this->request('GET', $resource, $query);
    }

    /**
     * Insert one record or a homogeneous list of records.
     *
     * @param array<mixed> $payload
     * @param array<string, scalar> $query
     * @return array<mixed>
     */
    public function post(string $resource, array $payload, array $query = []): array
    {
        if ($payload === []) {
            throw new RuntimeException('A Supabase REST insert payload cannot be empty.');
        }

        return $this->request('POST', $resource, $query, $payload, true);
    }

    /**
     * Delete only records selected by explicit PostgREST filters.
     *
     * @param array<string, scalar> $query
     * @return array<mixed>
     */
    public function delete(string $resource, array $query): array
    {
        if ($query === []) {
            throw new RuntimeException('A Supabase REST delete requires at least one explicit filter.');
        }

        $filterCount = count(array_filter(
            array_keys($query),
            static fn (string $key): bool => $key !== 'select'
        ));

        if ($filterCount === 0) {
            throw new RuntimeException('A Supabase REST delete requires a record-selection filter.');
        }

        return $this->request('DELETE', $resource, $query, null, true);
    }

    /**
     * @param array<string, scalar> $query
     * @param array<mixed>|null $payload
     * @return array<mixed>
     */
    private function request(
        string $method,
        string $resource,
        array $query = [],
        ?array $payload = null,
        bool $returnRepresentation = false
    ): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension is required for Supabase requests.');
        }

        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            throw new RuntimeException('The Supabase REST method is not supported.');
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $resource)) {
            throw new RuntimeException('The Supabase REST resource name is invalid.');
        }

        foreach ($query as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new RuntimeException('Supabase REST query parameters must contain scalar values.');
            }
        }

        $url = $this->config->restBaseUrl() . '/' . rawurlencode($resource);

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new RuntimeException('The Supabase REST request could not be initialized.');
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'apikey: ' . $this->config->serverApiKey(),
        ];

        if ($returnRepresentation) {
            $headers[] = 'Prefer: return=representation';
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
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'POST') {
            $options[CURLOPT_POST] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                );
            } catch (JsonException) {
                throw new RuntimeException('The Supabase REST request payload could not be encoded.');
            }
        }

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $curlErrorNumber = curl_errno($handle);
            curl_close($handle);

            throw new RuntimeException('The Supabase REST request failed at the network layer (cURL code ' . $curlErrorNumber . ').');
        }

        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('The Supabase REST request failed with HTTP status ' . $httpStatus . '.');
        }

        if ($responseBody === '') {
            return [];
        }

        try {
            $decodedResponse = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The Supabase REST response was not valid JSON.');
        }

        if (!is_array($decodedResponse)) {
            throw new RuntimeException('The Supabase REST response did not contain the expected JSON structure.');
        }

        return $decodedResponse;
    }
}
