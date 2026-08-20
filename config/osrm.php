<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Server-side OSRM configuration for the development route preview.
 *
 * The browser never selects or receives this base URL. The public project
 * server is a development default only and can later be replaced by trusted
 * server configuration without changing the route API or frontend.
 */
final class OsrmConfig
{
    private const VARIABLE = 'OSRM_BASE_URL';
    private const DEVELOPMENT_DEFAULT = 'https://router.project-osrm.org';

    private function __construct(private readonly string $baseUrl)
    {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $value = getenv(self::VARIABLE);

        if ($value === false && array_key_exists(self::VARIABLE, $_ENV)) {
            $value = (string) $_ENV[self::VARIABLE];
        }

        if ($value === false && array_key_exists(self::VARIABLE, $_SERVER)) {
            $value = (string) $_SERVER[self::VARIABLE];
        }

        if ($value === false && $envFile !== null && is_file($envFile)) {
            $value = self::readValueFromFile($envFile);
        }

        $baseUrl = trim($value === false ? self::DEVELOPMENT_DEFAULT : (string) $value);
        self::assertValidBaseUrl($baseUrl);

        return new self(rtrim($baseUrl, '/'));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private static function assertValidBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);

        if (
            $baseUrl === '' || !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
        ) {
            throw new RuntimeException('The server-side OSRM base URL is invalid.');
        }
    }

    private static function readValueFromFile(string $envFile): string|false
    {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
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

            [$name, $candidate] = array_map('trim', explode('=', $line, 2));
            if ($name !== self::VARIABLE) {
                continue;
            }

            if (strlen($candidate) >= 2) {
                $first = $candidate[0];
                $last = $candidate[strlen($candidate) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $candidate = substr($candidate, 1, -1);
                }
            }

            return $candidate;
        }

        return false;
    }
}
