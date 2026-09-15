<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Canonical timestamp rules for the effective Module 4 warning lifecycle.
 *
 * Stored lifecycle state remains authoritative and is never changed here.
 * Effective activity additionally depends on the warning's UTC issue and
 * validity window.
 */
final class DrrmEarlyWarningLifecyclePolicy
{
    public const ACTIVATION_VALIDITY_ORDER_INVALID = 'VALIDITY_ORDER_INVALID';
    public const ACTIVATION_FUTURE_ISSUED_AT = 'FUTURE_ISSUED_AT';
    public const ACTIVATION_ALREADY_EXPIRED = 'ALREADY_EXPIRED';

    private const TIMESTAMP_PATTERN =
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/';

    public static function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function asUtc(?DateTimeImmutable $timestamp = null): DateTimeImmutable
    {
        return ($timestamp ?? self::utcNow())->setTimezone(new DateTimeZone('UTC'));
    }

    public static function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::TIMESTAMP_PATTERN, $value) !== 1) {
            return null;
        }

        try {
            $timestamp = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors)
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                return null;
            }

            return $timestamp->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $warning */
    public static function isEffectivelyActive(
        array $warning,
        ?DateTimeImmutable $asOf = null
    ): bool {
        if (($warning['status'] ?? null) !== 'ACTIVE') {
            return false;
        }

        $asOf = self::asUtc($asOf);
        $issuedAt = self::parseTimestamp($warning['issued_at'] ?? null);
        if ($issuedAt === null || $issuedAt > $asOf) {
            return false;
        }

        if (($warning['valid_until'] ?? null) === null) {
            return true;
        }

        $validUntil = self::parseTimestamp($warning['valid_until']);

        return $validUntil !== null && $validUntil > $asOf;
    }

    /** @param array<string, mixed> $warning */
    public static function effectiveStatus(
        array $warning,
        ?DateTimeImmutable $asOf = null
    ): string {
        $storedStatus = (string) ($warning['status'] ?? '');
        if ($storedStatus !== 'ACTIVE' || ($warning['valid_until'] ?? null) === null) {
            return $storedStatus;
        }

        $validUntil = self::parseTimestamp($warning['valid_until']);

        return $validUntil !== null && $validUntil <= self::asUtc($asOf)
            ? 'EXPIRED'
            : $storedStatus;
    }

    public static function activationTimestampViolation(
        DateTimeImmutable $issuedAt,
        ?DateTimeImmutable $validUntil,
        ?DateTimeImmutable $asOf = null
    ): ?string {
        $issuedAt = self::asUtc($issuedAt);
        $validUntil = $validUntil === null ? null : self::asUtc($validUntil);
        $asOf = self::asUtc($asOf);

        if ($validUntil !== null && $validUntil <= $issuedAt) {
            return self::ACTIVATION_VALIDITY_ORDER_INVALID;
        }
        if ($issuedAt > $asOf) {
            return self::ACTIVATION_FUTURE_ISSUED_AT;
        }
        if ($validUntil !== null && $validUntil <= $asOf) {
            return self::ACTIVATION_ALREADY_EXPIRED;
        }

        return null;
    }
}
