<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Trusted server-side configuration for the official PAGASA TenDay API.
 */
final class PagasaConfig
{
    public const CALOOCAN_NAME = 'City of Caloocan';
    public const CALOOCAN_PSGC_10_DIGIT = '1380100000';
    public const OFFICIAL_HOST = 'tenday.pagasa.dost.gov.ph';
    private const DEFAULT_BASE_URL = 'https://' . self::OFFICIAL_HOST;

    private function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiToken
    ) {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $baseUrl = trim((string) self::environmentValue(
            'PAGASA_TENDAY_BASE_URL',
            $envFile,
            self::DEFAULT_BASE_URL
        ));
        $token = trim((string) self::environmentValue('PAGASA_API_TOKEN', $envFile, ''));

        self::assertOfficialBaseUrl($baseUrl);

        return new self(rtrim($baseUrl, '/'), $token === '' ? null : $token);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiToken(): ?string
    {
        return $this->apiToken;
    }

    public function hasApiToken(): bool
    {
        return $this->apiToken !== null;
    }

    private static function assertOfficialBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if (
            $baseUrl === '' || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::OFFICIAL_HOST
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new RuntimeException('The PAGASA TenDay base URL must use the official HTTPS service.');
        }
    }

    private static function environmentValue(
        string $name,
        ?string $envFile,
        string $default
    ): string|false {
        $value = getenv($name);
        if ($value === false && array_key_exists($name, $_ENV)) {
            $value = (string) $_ENV[$name];
        }
        if ($value === false && array_key_exists($name, $_SERVER)) {
            $value = (string) $_SERVER[$name];
        }
        if ($value !== false) {
            return $value;
        }
        if ($envFile === null || !is_file($envFile)) {
            return $default;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return $default;
        }

        foreach ($lines as $line) {
            $line = trim(ltrim($line, "\xEF\xBB\xBF"));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$candidateName, $candidateValue] = array_map('trim', explode('=', $line, 2));
            if ($candidateName !== $name) {
                continue;
            }
            if (strlen($candidateValue) >= 2) {
                $first = $candidateValue[0];
                $last = $candidateValue[strlen($candidateValue) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $candidateValue = substr($candidateValue, 1, -1);
                }
            }

            return $candidateValue;
        }

        return $default;
    }
}
