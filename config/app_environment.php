<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Minimal environment gate for local-only development features.
 *
 * APP_ENV must explicitly be development/local and the HTTP request itself
 * must originate from and target a loopback host. Missing configuration is
 * deliberately treated as disabled.
 */
final class AppEnvironment
{
    private const VARIABLE = 'APP_ENV';

    public static function allowsLocalDevelopmentRequest(
        ?string $envFile = null,
        ?array $server = null
    ): bool {
        $environment = self::value($envFile);

        if (!in_array($environment, ['development', 'local'], true)) {
            return false;
        }

        $server ??= $_SERVER;
        $remoteAddress = strtolower(trim((string) ($server['REMOTE_ADDR'] ?? '')));

        if (!in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
            return false;
        }

        $hostHeader = trim((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        $host = parse_url('http://' . $hostHeader, PHP_URL_HOST);
        $host = is_string($host) ? strtolower(trim($host, '[]')) : '';

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function value(?string $envFile): string
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

        return is_string($value) ? strtolower(trim($value)) : '';
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

            [$name, $value] = array_map('trim', explode('=', $line, 2));

            if ($name !== self::VARIABLE) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            return $value;
        }

        return false;
    }
}
