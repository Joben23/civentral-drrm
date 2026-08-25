<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/** Server-only configuration for the citizen authentication bridge and write API. */
final class CitizenApiConfig
{
    private const DEFAULT_PROFILE_URL = 'https://civentral.tech/api/citizen/get-profile.php';

    /** @param list<string> $allowedOrigins */
    private function __construct(
        private readonly string $profileUrl,
        private readonly array $allowedOrigins,
        private readonly bool $development
    ) {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $fileValues = $envFile !== null && is_file($envFile)
            ? self::readEnvironmentFile($envFile)
            : [];

        $profileUrl = self::environmentValue(
            'CITIZEN_AUTH_PROFILE_URL',
            $fileValues,
            self::DEFAULT_PROFILE_URL
        );
        self::validateProfileUrl($profileUrl);

        $originsValue = self::environmentValue('CITIZEN_CORS_ALLOWED_ORIGINS', $fileValues, '');
        $allowedOrigins = [];
        foreach (array_filter(array_map('trim', explode(',', $originsValue))) as $origin) {
            $allowedOrigins[] = self::validatedOrigin($origin);
        }

        $appEnvironment = strtolower(self::environmentValue('APP_ENV', $fileValues, 'production'));

        return new self($profileUrl, array_values(array_unique($allowedOrigins)), $appEnvironment === 'development');
    }

    public function profileUrl(): string
    {
        return $this->profileUrl;
    }

    public function isAllowedOrigin(string $origin): bool
    {
        if (in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }

        return $this->development
            && preg_match('/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin) === 1;
    }

    private static function validateProfileUrl(string $url): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('CITIZEN_AUTH_PROFILE_URL is invalid.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $isLoopback))
            || !str_ends_with($path, '/api/citizen/get-profile.php')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('CITIZEN_AUTH_PROFILE_URL must target the HTTPS citizen profile endpoint.');
        }
    }

    private static function validatedOrigin(string $origin): string
    {
        if (filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('CITIZEN_CORS_ALLOWED_ORIGINS contains an invalid origin.');
        }

        $parts = parse_url($origin);
        if (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            throw new RuntimeException('CITIZEN_CORS_ALLOWED_ORIGINS must contain origins without paths.');
        }

        return rtrim($origin, '/');
    }

    /** @param array<string, string> $fileValues */
    private static function environmentValue(string $name, array $fileValues, string $default): string
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

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /** @return array<string, string> */
    private static function readEnvironmentFile(string $envFile): array
    {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('The environment file could not be read.');
        }

        $allowed = ['CITIZEN_AUTH_PROFILE_URL', 'CITIZEN_CORS_ALLOWED_ORIGINS', 'APP_ENV'];
        $values = [];
        foreach ($lines as $line) {
            $line = trim(ltrim($line, "\xEF\xBB\xBF"));
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!in_array($name, $allowed, true) || array_key_exists($name, $values)) {
                continue;
            }
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $values[$name] = $value;
        }

        return $values;
    }
}
