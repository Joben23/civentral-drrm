<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Server-only configuration for the private CIVENTRAL AI service.
 *
 * The base URL and shared key must never be rendered into HTML or JavaScript.
 */
final class AiServiceConfig
{
    public const DEFAULT_BASE_URL = 'http://127.0.0.1:8098';
    public const DEFAULT_CONNECT_TIMEOUT_MS = 1000;
    public const DEFAULT_REQUEST_TIMEOUT_MS = 5000;

    private const BASE_URL_VARIABLE = 'CIVENTRAL_AI_BASE_URL';
    private const INTERNAL_KEY_VARIABLE = 'CIVENTRAL_AI_INTERNAL_KEY';
    private const CONNECT_TIMEOUT_VARIABLE = 'CIVENTRAL_AI_CONNECT_TIMEOUT_MS';
    private const REQUEST_TIMEOUT_VARIABLE = 'CIVENTRAL_AI_REQUEST_TIMEOUT_MS';
    private const TRUSTED_DOCKER_HTTP_HOST = 'flood-risk-ai';
    private const TRUSTED_DOCKER_HTTP_PORT = 8098;

    private function __construct(
        private readonly string $baseUrl,
        private readonly ?string $internalKey,
        private readonly int $connectTimeoutMs,
        private readonly int $requestTimeoutMs
    ) {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $fileValues = $envFile !== null && is_file($envFile)
            ? self::readEnvironmentFile($envFile)
            : [];

        $baseValue = self::environmentValue(self::BASE_URL_VARIABLE, $fileValues);
        $baseUrl = trim($baseValue === false ? self::DEFAULT_BASE_URL : $baseValue);
        self::assertValidBaseUrl($baseUrl);

        $keyValue = self::environmentValue(self::INTERNAL_KEY_VARIABLE, $fileValues);
        $internalKey = trim($keyValue === false ? '' : $keyValue);
        if ($internalKey !== '' && (
            strlen($internalKey) < 32
            || strlen($internalKey) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $internalKey) === 1
        )) {
            throw new RuntimeException('The internal AI service key configuration is invalid.');
        }

        $connectTimeoutMs = self::timeoutValue(
            self::CONNECT_TIMEOUT_VARIABLE,
            $fileValues,
            self::DEFAULT_CONNECT_TIMEOUT_MS,
            10000
        );
        $requestTimeoutMs = self::timeoutValue(
            self::REQUEST_TIMEOUT_VARIABLE,
            $fileValues,
            self::DEFAULT_REQUEST_TIMEOUT_MS,
            30000
        );
        if ($requestTimeoutMs < $connectTimeoutMs) {
            throw new RuntimeException('The AI request timeout cannot be shorter than its connection timeout.');
        }

        return new self(
            rtrim($baseUrl, '/'),
            $internalKey === '' ? null : $internalKey,
            $connectTimeoutMs,
            $requestTimeoutMs
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** Server-side transport use only. */
    public function internalKey(): ?string
    {
        return $this->internalKey;
    }

    public function hasInternalKey(): bool
    {
        return $this->internalKey !== null;
    }

    public function connectTimeoutMs(): int
    {
        return $this->connectTimeoutMs;
    }

    public function requestTimeoutMs(): int
    {
        return $this->requestTimeoutMs;
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
            || (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535))
        ) {
            throw new RuntimeException('The private AI service base URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($scheme === 'http'
            && !self::isLocalOrPrivateHost($host)
            && !self::isTrustedDockerHttpTarget($host, $port)) {
            throw new RuntimeException('An HTTP AI service URL must target a local or private host.');
        }
    }

    private static function isTrustedDockerHttpTarget(string $host, ?int $port): bool
    {
        return $host === self::TRUSTED_DOCKER_HTTP_HOST
            && $port === self::TRUSTED_DOCKER_HTTP_PORT;
    }

    private static function isLocalOrPrivateHost(string $host): bool
    {
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false || in_array($host, ['0.0.0.0', '::'], true)) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /** @param array<string, string> $fileValues */
    private static function environmentValue(string $name, array $fileValues): string|false
    {
        $value = getenv($name);
        if ($value === false && array_key_exists($name, $_ENV)) {
            $value = (string) $_ENV[$name];
        }
        if ($value === false && array_key_exists($name, $_SERVER)) {
            $value = (string) $_SERVER[$name];
        }
        if ($value === false && array_key_exists($name, $fileValues)) {
            $value = $fileValues[$name];
        }
        return is_string($value) ? $value : false;
    }

    /** @param array<string, string> $fileValues */
    private static function timeoutValue(
        string $name,
        array $fileValues,
        int $default,
        int $maximum
    ): int {
        $value = self::environmentValue($name, $fileValues);
        if ($value === false) {
            return $default;
        }
        $value = trim($value);
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new RuntimeException('An AI service timeout configuration is invalid.');
        }
        $timeout = (int) $value;
        if ($timeout > $maximum) {
            throw new RuntimeException('An AI service timeout configuration is invalid.');
        }
        return $timeout;
    }

    /** @return array<string, string> */
    private static function readEnvironmentFile(string $envFile): array
    {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('The environment file could not be read.');
        }

        $allowed = [
            self::BASE_URL_VARIABLE,
            self::INTERNAL_KEY_VARIABLE,
            self::CONNECT_TIMEOUT_VARIABLE,
            self::REQUEST_TIMEOUT_VARIABLE,
        ];
        $values = [];
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
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!in_array($name, $allowed, true) || array_key_exists($name, $values)) {
                continue;
            }
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            $values[$name] = $value;
        }
        return $values;
    }
}
