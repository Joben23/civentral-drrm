<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Server-side Supabase configuration for the DRRM service layer.
 *
 * This class intentionally does not depend on config/database.php so a
 * Supabase request does not initialize or alter the existing MySQL layer.
 */
final class SupabaseConfig
{
    private const URL_VARIABLE = 'SUPABASE_URL';
    private const SECRET_KEY_VARIABLE = 'SUPABASE_SECRET_KEY';

    private function __construct(
        private readonly string $baseUrl,
        private readonly string $secretKey
    ) {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $fileValues = [];

        if ($envFile !== null && is_file($envFile)) {
            $fileValues = self::readEnvironmentFile($envFile);
        }

        $baseUrl = self::requiredEnvironmentValue(self::URL_VARIABLE, $fileValues);
        $secretKey = self::requiredEnvironmentValue(self::SECRET_KEY_VARIABLE, $fileValues);

        $baseUrl = self::validatedProjectBaseUrl($baseUrl);

        if (!str_starts_with($secretKey, 'sb_secret_')) {
            throw new RuntimeException('SUPABASE_SECRET_KEY is not in the expected server secret-key format.');
        }

        return new self($baseUrl, $secretKey);
    }

    public function restBaseUrl(): string
    {
        return $this->baseUrl . '/rest/v1';
    }

    /**
     * Only server-side infrastructure should call this method.
     */
    public function serverApiKey(): string
    {
        return $this->secretKey;
    }

    /**
     * @param array<string, string> $fileValues
     */
    private static function requiredEnvironmentValue(string $name, array $fileValues): string
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

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            throw new RuntimeException($name . ' is not configured.');
        }

        return $value;
    }

    private static function validatedProjectBaseUrl(string $baseUrl): string
    {
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('SUPABASE_URL is not a valid URL.');
        }

        $parts = parse_url($baseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $isLocalHost))) {
            throw new RuntimeException('SUPABASE_URL must use HTTPS unless it targets a local development host.');
        }

        if (!in_array($path, ['', '/rest/v1'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('SUPABASE_URL must be a project URL without credentials, an unsupported path, query, or fragment.');
        }

        $normalizedUrl = rtrim($baseUrl, '/');

        // Accept an existing REST suffix without modifying .env, but retain one
        // canonical project-root URL internally so /rest/v1 is never duplicated.
        if ($path === '/rest/v1') {
            $normalizedUrl = substr($normalizedUrl, 0, -strlen('/rest/v1'));
        }

        return $normalizedUrl;
    }

    /**
     * @return array<string, string>
     */
    private static function readEnvironmentFile(string $envFile): array
    {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('The environment file could not be read.');
        }

        $values = [];
        $allowedVariables = [self::URL_VARIABLE, self::SECRET_KEY_VARIABLE];

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

            if (!in_array($name, $allowedVariables, true) || array_key_exists($name, $values)) {
                continue;
            }

            if (strlen($value) >= 2) {
                $firstCharacter = $value[0];
                $lastCharacter = $value[strlen($value) - 1];

                if (($firstCharacter === '"' && $lastCharacter === '"') || ($firstCharacter === "'" && $lastCharacter === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$name] = $value;
        }

        return $values;
    }
}
