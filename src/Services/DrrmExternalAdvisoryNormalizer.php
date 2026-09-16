<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

require_once __DIR__ . '/DrrmEarlyWarningLifecyclePolicy.php';

final class DrrmExternalAdvisoryValidationException extends RuntimeException
{
}

/**
 * Treats every provider field as untrusted and produces the only item shape
 * accepted by the Phase 4B.1 staging RPC.
 */
final class DrrmExternalAdvisoryNormalizer
{
    private const MAX_RAW_PAYLOAD_BYTES = 1048576;
    private const ALLOWED_PROVIDER_KEYS = [
        'external_reference_id',
        'source_reference',
        'title',
        'advisory_type',
        'hazard_type',
        'summary',
        'issued_at',
        'valid_until',
        'raw_payload',
    ];
    private const HAZARD_TYPES = [
        'FLOOD',
        'HEAVY_RAINFALL',
        'TROPICAL_CYCLONE',
        'LANDSLIDE',
        'EARTHQUAKE',
        'VOLCANIC_ACTIVITY',
        'OTHER',
    ];

    /** @param array<string, mixed> $providerItem @return array<string, mixed> */
    public function normalize(string $sourceCode, array $providerItem): array
    {
        $sourceCode = strtoupper(trim($sourceCode));
        if (preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $sourceCode) !== 1) {
            throw new DrrmExternalAdvisoryValidationException('The provider source code is invalid.');
        }
        if (array_is_list($providerItem)
            || array_diff(array_keys($providerItem), self::ALLOWED_PROVIDER_KEYS) !== []) {
            throw new DrrmExternalAdvisoryValidationException('The provider item shape is invalid.');
        }

        $externalReferenceId = $this->optionalText(
            $providerItem['external_reference_id'] ?? null,
            500,
            'The provider item identifier is invalid.'
        );
        $sourceReference = $this->optionalText(
            $providerItem['source_reference'] ?? null,
            2000,
            'The provider source reference is invalid.'
        );
        $title = $this->requiredText(
            $providerItem['title'] ?? null,
            500,
            'The provider advisory title is invalid.'
        );
        $advisoryType = $this->optionalCode(
            $providerItem['advisory_type'] ?? null,
            100,
            'The provider advisory type is invalid.'
        );
        $hazardType = $this->optionalControlledCode(
            $providerItem['hazard_type'] ?? null,
            self::HAZARD_TYPES,
            'The provider hazard type is invalid.'
        );
        $summary = $this->optionalText(
            $providerItem['summary'] ?? null,
            10000,
            'The provider advisory summary is invalid.'
        );
        $issuedAt = $this->optionalTimestamp(
            $providerItem['issued_at'] ?? null,
            'The provider issue timestamp is invalid.'
        );
        $validUntil = $this->optionalTimestamp(
            $providerItem['valid_until'] ?? null,
            'The provider validity timestamp is invalid.'
        );
        if ($validUntil !== null && ($issuedAt === null || $validUntil <= $issuedAt)) {
            throw new DrrmExternalAdvisoryValidationException(
                'The provider validity timestamp must be later than its issue timestamp.'
            );
        }

        $rawPayload = $providerItem['raw_payload'] ?? null;
        if (!is_array($rawPayload)) {
            throw new DrrmExternalAdvisoryValidationException('The provider raw payload is invalid.');
        }
        $rawJson = $this->canonicalJson($rawPayload);
        if (strlen($rawJson) > self::MAX_RAW_PAYLOAD_BYTES) {
            throw new DrrmExternalAdvisoryValidationException('The provider raw payload is too large.');
        }

        if ($externalReferenceId === null && ($sourceReference === null || $issuedAt === null)) {
            throw new DrrmExternalAdvisoryValidationException(
                'A provider item without a stable identifier needs a source reference and issue timestamp.'
            );
        }

        $normalizedPayload = [
            'external_reference_id' => $externalReferenceId,
            'source_reference' => $sourceReference,
            'title' => $title,
            'advisory_type' => $advisoryType,
            'hazard_type' => $hazardType,
            'summary' => $summary,
            'issued_at' => $issuedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            'valid_until' => $validUntil?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
        ];

        $identityMethod = $externalReferenceId === null
            ? 'DETERMINISTIC_FALLBACK'
            : 'PROVIDER_ID';
        $identityMaterial = $externalReferenceId === null
            ? [
                'version' => 1,
                'source_code' => $sourceCode,
                'source_reference' => $sourceReference,
                'title' => $title,
                'advisory_type' => $advisoryType,
                'issued_at' => $normalizedPayload['issued_at'],
            ]
            : [
                'version' => 1,
                'source_code' => $sourceCode,
                'external_reference_id' => $externalReferenceId,
            ];

        return $normalizedPayload + [
            'deduplication_key' => hash('sha256', $this->canonicalJson($identityMaterial)),
            'identity_method' => $identityMethod,
            'raw_payload' => $rawPayload,
            'normalized_payload' => $normalizedPayload,
            'payload_hash' => hash('sha256', $this->canonicalJson([
                'normalized_payload' => $normalizedPayload,
                'raw_payload' => $rawPayload,
            ])),
        ];
    }

    private function requiredText(mixed $value, int $maxLength, string $message): string
    {
        if (!is_string($value)) {
            throw new DrrmExternalAdvisoryValidationException($message);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new DrrmExternalAdvisoryValidationException($message);
        }
        return $value;
    }

    private function optionalText(mixed $value, int $maxLength, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new DrrmExternalAdvisoryValidationException($message);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new DrrmExternalAdvisoryValidationException($message);
        }
        return $value;
    }

    private function optionalCode(mixed $value, int $maxLength, string $message): ?string
    {
        $value = $this->optionalText($value, $maxLength, $message);
        if ($value === null) {
            return null;
        }
        $value = strtoupper($value);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $value) !== 1) {
            throw new DrrmExternalAdvisoryValidationException($message);
        }
        return $value;
    }

    /** @param list<string> $allowed */
    private function optionalControlledCode(mixed $value, array $allowed, string $message): ?string
    {
        $value = $this->optionalCode($value, 100, $message);
        if ($value !== null && !in_array($value, $allowed, true)) {
            throw new DrrmExternalAdvisoryValidationException($message);
        }
        return $value;
    }

    private function optionalTimestamp(mixed $value, string $message): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = DrrmEarlyWarningLifecyclePolicy::parseTimestamp($value);
        if ($timestamp === null) {
            throw new DrrmExternalAdvisoryValidationException($message);
        }
        return $timestamp;
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new DrrmExternalAdvisoryValidationException(
                'The provider payload could not be normalized.',
                0,
                $exception
            );
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
