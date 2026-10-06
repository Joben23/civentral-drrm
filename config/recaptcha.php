<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class RecaptchaConfig
{
    public const DEFAULT_CONNECT_TIMEOUT_MS = 2000;
    public const DEFAULT_REQUEST_TIMEOUT_MS = 5000;

    private const SITE_KEY_VARIABLE = 'RECAPTCHA_SITE_KEY';
    private const SECRET_KEY_VARIABLE = 'RECAPTCHA_SECRET_KEY';
    private const ALLOWED_HOSTNAMES_VARIABLE = 'RECAPTCHA_ALLOWED_HOSTNAMES';
    private const CONNECT_TIMEOUT_VARIABLE = 'RECAPTCHA_CONNECT_TIMEOUT_MS';
    private const REQUEST_TIMEOUT_VARIABLE = 'RECAPTCHA_REQUEST_TIMEOUT_MS';

    /** @param list<string> $allowedHostnames */
    private function __construct(
        private readonly string $siteKey,
        private readonly string $secretKey,
        private readonly array $allowedHostnames,
        private readonly int $connectTimeoutMs,
        private readonly int $requestTimeoutMs
    ) {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $fileValues = $envFile !== null && is_file($envFile)
            ? self::readEnvironmentFile($envFile)
            : [];

        $siteKey = self::requiredValue(self::SITE_KEY_VARIABLE, $fileValues);
        $secretKey = self::requiredValue(self::SECRET_KEY_VARIABLE, $fileValues);
        self::assertSafeKey($siteKey);
        self::assertSafeKey($secretKey);

        $hostnameValue = self::requiredValue(self::ALLOWED_HOSTNAMES_VARIABLE, $fileValues);
        $allowedHostnames = array_values(array_unique(array_filter(array_map(
            static fn (string $hostname): string => strtolower(rtrim(trim($hostname), '.')),
            explode(',', $hostnameValue)
        ))));
        if ($allowedHostnames === []) {
            throw new RuntimeException('The reCAPTCHA hostname allowlist is not configured.');
        }
        foreach ($allowedHostnames as $hostname) {
            if (strlen($hostname) > 253
                || preg_match('/^(?:localhost|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\d{1,3}(?:\.\d{1,3}){3})$/', $hostname) !== 1) {
                throw new RuntimeException('The reCAPTCHA hostname allowlist is invalid.');
            }
        }

        $connectTimeout = self::timeoutValue(
            self::CONNECT_TIMEOUT_VARIABLE,
            $fileValues,
            self::DEFAULT_CONNECT_TIMEOUT_MS,
            10000
        );
        $requestTimeout = self::timeoutValue(
            self::REQUEST_TIMEOUT_VARIABLE,
            $fileValues,
            self::DEFAULT_REQUEST_TIMEOUT_MS,
            30000
        );
        if ($requestTimeout < $connectTimeout) {
            throw new RuntimeException('The reCAPTCHA request timeout cannot be shorter than its connection timeout.');
        }

        return new self($siteKey, $secretKey, $allowedHostnames, $connectTimeout, $requestTimeout);
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    /** Server-side verification use only. */
    public function secretKey(): string
    {
        return $this->secretKey;
    }

    /** @return list<string> */
    public function allowedHostnames(): array
    {
        return $this->allowedHostnames;
    }

    public function connectTimeoutMs(): int
    {
        return $this->connectTimeoutMs;
    }

    public function requestTimeoutMs(): int
    {
        return $this->requestTimeoutMs;
    }

    /** @param array<string, string> $fileValues */
    private static function requiredValue(string $name, array $fileValues): string
    {
        $value = self::environmentValue($name, $fileValues);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            throw new RuntimeException($name . ' is not configured.');
        }

        return $value;
    }

    private static function assertSafeKey(string $key): void
    {
        if (strlen($key) > 2048 || preg_match('/[\x00-\x20\x7F]/', $key) === 1) {
            throw new RuntimeException('A reCAPTCHA key configuration is invalid.');
        }
    }

    /** @param array<string, string> $fileValues */
    private static function timeoutValue(string $name, array $fileValues, int $default, int $maximum): int
    {
        $value = self::environmentValue($name, $fileValues);
        if ($value === false) {
            return $default;
        }
        $value = trim($value);
        if (preg_match('/^[1-9]\d*$/', $value) !== 1 || (int) $value > $maximum) {
            throw new RuntimeException('A reCAPTCHA timeout configuration is invalid.');
        }

        return (int) $value;
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

    /** @return array<string, string> */
    private static function readEnvironmentFile(string $envFile): array
    {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('The environment file could not be read.');
        }
        $allowed = [
            self::SITE_KEY_VARIABLE,
            self::SECRET_KEY_VARIABLE,
            self::ALLOWED_HOSTNAMES_VARIABLE,
            self::CONNECT_TIMEOUT_VARIABLE,
            self::REQUEST_TIMEOUT_VARIABLE,
        ];
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!in_array($name, $allowed, true) || isset($values[$name])) {
                continue;
            }
            $length = strlen($value);
            if ($length >= 2 && (
                ($value[0] === chr(34) && $value[$length - 1] === chr(34))
                || ($value[0] === chr(39) && $value[$length - 1] === chr(39))
            )) {
                $value = substr($value, 1, -1);
            }
            $values[$name] = $value;
        }
        return $values;
    }
}
