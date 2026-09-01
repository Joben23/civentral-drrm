<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmBarangayCatalogService.php';

final class DrrmEarlyWarningValidationException extends RuntimeException
{
}

final class DrrmEarlyWarningLifecycleException extends RuntimeException
{
}

final class DrrmEarlyWarningWriteException extends RuntimeException
{
}

/**
 * Server-only write boundary for human-reviewed Module 4 warnings.
 */
final class DrrmEarlyWarningWriteService
{
    public const BARANGAY_DATASET_VERSION_ID = DrrmBarangayCatalogService::LEGACY_DRAFT_DATASET_VERSION_ID;
    public const EXPECTED_BARANGAY_COUNT = DrrmBarangayCatalogService::LEGACY_DRAFT_COUNT;

    /** @var list<string> */
    public const HAZARD_TYPES = [
        'FLOOD',
        'HEAVY_RAINFALL',
        'TROPICAL_CYCLONE',
        'LANDSLIDE',
        'EARTHQUAKE',
        'VOLCANIC_ACTIVITY',
        'OTHER',
    ];

    /** @var list<string> */
    public const SOURCE_CODES = ['PAGASA', 'PHIVOLCS', 'NDRRMC', 'CIVENTRAL'];

    /** @var list<string> */
    public const WARNING_LEVELS = ['LOW', 'MODERATE', 'HIGH', 'CRITICAL'];

    private const EXTERNAL_SOURCE_CODES = ['PAGASA', 'PHIVOLCS', 'NDRRMC'];

    public function __construct(private readonly SupabaseRestClient $client)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createDraft(array $input): array
    {
        $draft = $this->validateDraftInput($input);
        $warningId = null;

        try {
            $created = $this->client->post('early_warnings', [
                'source_id' => $draft['source']['id'],
                'external_reference_id' => null,
                'title' => $draft['title'],
                'hazard_type' => $draft['hazard_type'],
                'warning_level_id' => $draft['risk_level']['risk_level_id'],
                'summary' => $draft['summary'],
                'status' => 'DRAFT',
                'issued_at' => $draft['issued_at'],
                'valid_until' => $draft['valid_until'],
                'source_reference' => $draft['source_reference'],
            ], [
                'select' => 'id,source_id,title,hazard_type,warning_level_id,summary,status,issued_at,valid_until,source_reference',
            ]);

            if (count($created) !== 1 || !is_array($created[0])) {
                throw new DrrmEarlyWarningWriteException('The warning draft was not created uniquely.');
            }

            $warningId = (string) ($created[0]['id'] ?? '');
            if (!$this->isUuid($warningId) || ($created[0]['status'] ?? null) !== 'DRAFT') {
                throw new DrrmEarlyWarningWriteException('The warning draft response was invalid.');
            }

            $areaPayload = [];
            foreach ($draft['areas'] as $area) {
                $areaPayload[] = [
                    'warning_id' => $warningId,
                    'scope_type' => $area['scope_type'],
                    'barangay_id' => $area['barangay_id'],
                    'area_name' => $area['area_name'],
                ];
            }

            $createdAreas = $this->client->post('early_warning_areas', $areaPayload, [
                'select' => 'id,warning_id,scope_type,barangay_id,area_name',
            ]);

            if (count($createdAreas) !== count($areaPayload)) {
                throw new DrrmEarlyWarningWriteException('Not all warning areas were created.');
            }

            foreach ($createdAreas as $area) {
                if (!is_array($area) || ($area['warning_id'] ?? null) !== $warningId) {
                    throw new DrrmEarlyWarningWriteException('A warning area response was invalid.');
                }
            }

            return [
                'id' => $warningId,
                'title' => $draft['title'],
                'status' => 'DRAFT',
                'hazard_type' => $draft['hazard_type'],
                'warning_level' => $draft['risk_level']['code'],
                'source_code' => $draft['source']['source_code'],
                'affected_area_count' => count($createdAreas),
            ];
        } catch (DrrmEarlyWarningValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($warningId !== null) {
                $this->rollbackDraft($warningId);
            }

            if ($exception instanceof DrrmEarlyWarningWriteException) {
                throw $exception;
            }

            throw new DrrmEarlyWarningWriteException('Unable to save warning.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    public function activate(string $warningId): array
    {
        return $this->changeStatus($warningId, 'ACTIVATE');
    }

    /** @return array<string, mixed> */
    public function cancel(string $warningId): array
    {
        return $this->changeStatus($warningId, 'CANCEL');
    }

    /** @return list<array{barangay_id: string, barangay_code: string, name: string}> */
    public function availableBarangays(): array
    {
        try {
            return (new DrrmBarangayCatalogService($this->client))->availableBarangays();
        } catch (Throwable $exception) {
            throw new DrrmEarlyWarningWriteException(
                'The validated barangay catalog is unavailable.',
                0,
                $exception
            );
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validateDraftInput(array $input): array
    {
        $allowedKeys = [
            'title', 'hazard_type', 'warning_level', 'source_code', 'summary',
            'issued_at', 'valid_until', 'source_reference', 'scope_type', 'barangay_ids',
        ];

        if (array_diff(array_keys($input), $allowedKeys) !== []) {
            throw new DrrmEarlyWarningValidationException('Unsupported warning fields were supplied.');
        }

        $title = $this->requiredText($input['title'] ?? null, 'Warning title is required.', 180);
        $summary = $this->requiredText($input['summary'] ?? null, 'Warning summary is required.', 5000);
        $hazardType = $this->controlledCode($input['hazard_type'] ?? null, self::HAZARD_TYPES, 'Invalid hazard type.');
        $warningLevel = $this->controlledCode($input['warning_level'] ?? null, self::WARNING_LEVELS, 'Invalid warning level.');
        $sourceCode = $this->controlledCode($input['source_code'] ?? null, self::SOURCE_CODES, 'Invalid warning source.');
        $scopeType = $this->controlledCode($input['scope_type'] ?? null, ['CITY', 'BARANGAY'], 'Invalid affected-area scope.');
        $sourceReference = $this->optionalText($input['source_reference'] ?? null, 1000);

        if (in_array($sourceCode, self::EXTERNAL_SOURCE_CODES, true) && $sourceReference === null) {
            throw new DrrmEarlyWarningValidationException('A source reference is required for official external advisories.');
        }

        $issuedAt = $this->timestamp($input['issued_at'] ?? null, 'Issued At is invalid.', false);
        $validUntil = $this->timestamp($input['valid_until'] ?? null, 'Valid Until is invalid.', true);

        if ($validUntil !== null && $validUntil < $issuedAt) {
            throw new DrrmEarlyWarningValidationException('Valid Until must not be before Issued At.');
        }

        $source = $this->resolveSource($sourceCode);
        $riskLevel = $this->resolveRiskLevel($warningLevel);
        $areas = $scopeType === 'CITY'
            ? $this->cityArea($input['barangay_ids'] ?? null)
            : $this->barangayAreas($input['barangay_ids'] ?? null);

        return [
            'title' => $title,
            'summary' => $summary,
            'hazard_type' => $hazardType,
            'source_reference' => $sourceReference,
            'issued_at' => $issuedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            'valid_until' => $validUntil?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            'source' => $source,
            'risk_level' => $riskLevel,
            'areas' => $areas,
        ];
    }

    /** @return array<string, mixed> */
    private function changeStatus(string $warningId, string $action): array
    {
        if (!$this->isUuid($warningId)) {
            throw new DrrmEarlyWarningValidationException('Invalid warning identifier.');
        }

        if (!in_array($action, ['ACTIVATE', 'CANCEL'], true)) {
            throw new DrrmEarlyWarningValidationException('Invalid warning lifecycle action.');
        }

        $warnings = $this->client->get('early_warnings', [
            'select' => 'id,source_id,title,hazard_type,warning_level_id,summary,status,issued_at,valid_until,source_reference',
            'id' => 'eq.' . $warningId,
            'limit' => 2,
        ]);

        if (count($warnings) !== 1 || !is_array($warnings[0])) {
            throw new DrrmEarlyWarningLifecycleException('The warning could not be found.');
        }

        $warning = $warnings[0];
        $currentStatus = (string) ($warning['status'] ?? '');

        if ($action === 'ACTIVATE') {
            if ($currentStatus !== 'DRAFT') {
                throw new DrrmEarlyWarningLifecycleException('This warning can no longer be activated.');
            }
            $this->validateActivation($warning);
            $targetStatus = 'ACTIVE';
        } else {
            if (!in_array($currentStatus, ['DRAFT', 'ACTIVE'], true)) {
                throw new DrrmEarlyWarningLifecycleException('This warning can no longer be cancelled.');
            }
            $targetStatus = 'CANCELLED';
        }

        try {
            $updated = $this->client->patch('early_warnings', [
                'status' => $targetStatus,
            ], [
                'id' => 'eq.' . $warningId,
                'status' => 'eq.' . $currentStatus,
                'select' => 'id,title,status,updated_at',
            ]);
        } catch (Throwable $exception) {
            throw new DrrmEarlyWarningWriteException('Unable to update warning status.', 0, $exception);
        }

        if (count($updated) !== 1 || ($updated[0]['id'] ?? null) !== $warningId
            || ($updated[0]['status'] ?? null) !== $targetStatus) {
            throw new DrrmEarlyWarningLifecycleException('The warning status changed before this action completed.');
        }

        return [
            'id' => $warningId,
            'title' => (string) ($updated[0]['title'] ?? $warning['title']),
            'previous_status' => $currentStatus,
            'status' => $targetStatus,
        ];
    }

    /** @param array<string, mixed> $warning */
    private function validateActivation(array $warning): void
    {
        $title = trim((string) ($warning['title'] ?? ''));
        $summary = trim((string) ($warning['summary'] ?? ''));
        $hazardType = (string) ($warning['hazard_type'] ?? '');
        $sourceReference = $this->optionalText($warning['source_reference'] ?? null, 1000);

        if ($title === '' || $summary === '' || !in_array($hazardType, self::HAZARD_TYPES, true)) {
            throw new DrrmEarlyWarningLifecycleException('The warning is incomplete and cannot be activated.');
        }

        $issuedAt = $this->timestamp($warning['issued_at'] ?? null, 'The warning issue time is invalid.', false);
        $validUntil = $this->timestamp($warning['valid_until'] ?? null, 'The warning validity period is invalid.', true);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($validUntil !== null && $validUntil <= $now) {
            throw new DrrmEarlyWarningLifecycleException('The warning validity period has already expired.');
        }
        if ($validUntil !== null && $validUntil < $issuedAt) {
            throw new DrrmEarlyWarningLifecycleException('The warning validity period is invalid.');
        }

        $areas = $this->client->get('early_warning_areas', [
            'select' => 'id,scope_type,barangay_id,area_name',
            'warning_id' => 'eq.' . (string) $warning['id'],
            'limit' => self::EXPECTED_BARANGAY_COUNT + 1,
        ]);
        if ($areas === []) {
            throw new DrrmEarlyWarningLifecycleException('The warning has no affected area.');
        }

        $source = $this->resolveSourceById((string) ($warning['source_id'] ?? ''));
        if (in_array($source['source_code'], self::EXTERNAL_SOURCE_CODES, true) && $sourceReference === null) {
            throw new DrrmEarlyWarningLifecycleException('The external warning source reference is missing.');
        }

        $this->resolveRiskLevelById($warning['warning_level_id'] ?? null);
    }

    /** @return array{id: string, source_code: string} */
    private function resolveSource(string $sourceCode): array
    {
        $rows = $this->client->get('early_warning_sources', [
            'select' => 'id,source_code,is_active',
            'source_code' => 'eq.' . $sourceCode,
            'is_active' => 'eq.true',
            'limit' => 2,
        ]);

        if (count($rows) !== 1 || !is_array($rows[0]) || !$this->isUuid((string) ($rows[0]['id'] ?? ''))
            || ($rows[0]['source_code'] ?? null) !== $sourceCode || ($rows[0]['is_active'] ?? null) !== true) {
            throw new DrrmEarlyWarningValidationException('Invalid warning source.');
        }

        return ['id' => (string) $rows[0]['id'], 'source_code' => $sourceCode];
    }

    /** @return array{id: string, source_code: string} */
    private function resolveSourceById(string $sourceId): array
    {
        if (!$this->isUuid($sourceId)) {
            throw new DrrmEarlyWarningLifecycleException('The warning source is invalid.');
        }

        $rows = $this->client->get('early_warning_sources', [
            'select' => 'id,source_code,is_active',
            'id' => 'eq.' . $sourceId,
            'is_active' => 'eq.true',
            'limit' => 2,
        ]);

        $sourceCode = count($rows) === 1 && is_array($rows[0])
            ? (string) ($rows[0]['source_code'] ?? '')
            : '';

        if (!in_array($sourceCode, self::SOURCE_CODES, true)) {
            throw new DrrmEarlyWarningLifecycleException('The warning source is no longer valid.');
        }

        return ['id' => $sourceId, 'source_code' => $sourceCode];
    }

    /** @return array{risk_level_id: int, code: string} */
    private function resolveRiskLevel(string $code): array
    {
        $rows = $this->client->get('risk_levels', [
            'select' => 'risk_level_id,code,is_active',
            'code' => 'eq.' . $code,
            'is_active' => 'eq.true',
            'limit' => 2,
        ]);

        if (count($rows) !== 1 || !is_array($rows[0]) || !is_int($rows[0]['risk_level_id'] ?? null)
            || ($rows[0]['code'] ?? null) !== $code || ($rows[0]['is_active'] ?? null) !== true) {
            throw new DrrmEarlyWarningValidationException('Invalid warning level.');
        }

        return ['risk_level_id' => $rows[0]['risk_level_id'], 'code' => $code];
    }

    private function resolveRiskLevelById(mixed $riskLevelId): void
    {
        if (!is_int($riskLevelId) && !(is_string($riskLevelId) && ctype_digit($riskLevelId))) {
            throw new DrrmEarlyWarningLifecycleException('The warning level is invalid.');
        }

        $rows = $this->client->get('risk_levels', [
            'select' => 'risk_level_id,code,is_active',
            'risk_level_id' => 'eq.' . (int) $riskLevelId,
            'is_active' => 'eq.true',
            'limit' => 2,
        ]);

        if (count($rows) !== 1 || !is_array($rows[0])
            || !in_array($rows[0]['code'] ?? null, self::WARNING_LEVELS, true)) {
            throw new DrrmEarlyWarningLifecycleException('The warning level is no longer valid.');
        }
    }

    /** @return list<array{scope_type: string, barangay_id: null, area_name: string}> */
    private function cityArea(mixed $barangayIds): array
    {
        if ($barangayIds !== null && $barangayIds !== []) {
            throw new DrrmEarlyWarningValidationException('CITY scope must not include barangay identifiers.');
        }

        return [[
            'scope_type' => 'CITY',
            'barangay_id' => null,
            'area_name' => 'Caloocan City',
        ]];
    }

    /** @return list<array{scope_type: string, barangay_id: string, area_name: string}> */
    private function barangayAreas(mixed $barangayIds): array
    {
        if (!is_array($barangayIds) || !array_is_list($barangayIds) || $barangayIds === []) {
            throw new DrrmEarlyWarningValidationException('Select at least one validated barangay.');
        }

        if (count($barangayIds) > DrrmBarangayCatalogService::CURRENT_OPERATIONAL_COUNT) {
            throw new DrrmEarlyWarningValidationException('Too many barangays were selected.');
        }

        $uniqueIds = [];
        foreach ($barangayIds as $barangayId) {
            if (!is_string($barangayId) || !$this->isUuid($barangayId)) {
                throw new DrrmEarlyWarningValidationException('One or more selected barangays are invalid.');
            }
            $uniqueIds[$barangayId] = true;
        }

        if (count($uniqueIds) !== count($barangayIds)) {
            throw new DrrmEarlyWarningValidationException('Duplicate barangay selections are not allowed.');
        }

        try {
            $records = (new DrrmBarangayCatalogService($this->client))
                ->writeEligibleBarangaysById(array_keys($uniqueIds));
        } catch (Throwable $exception) {
            throw new DrrmEarlyWarningWriteException(
                'The validated barangay catalog is unavailable.',
                0,
                $exception
            );
        }

        if (count($records) !== count($uniqueIds)) {
            throw new DrrmEarlyWarningValidationException('One or more selected barangays are invalid.');
        }

        $areas = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new DrrmEarlyWarningValidationException('One or more selected barangays are invalid.');
            }
            $id = (string) ($record['barangay_id'] ?? '');
            $name = trim((string) ($record['name'] ?? ''));
            if (!isset($uniqueIds[$id]) || $name === 'Barangay 176'
                || preg_match('/^Barangay (?:[1-9]|[1-9]\d|1\d\d)(?:-[A-F])?$/', $name) !== 1) {
                throw new DrrmEarlyWarningValidationException('One or more selected barangays are invalid.');
            }
            $areas[] = [
                'scope_type' => 'BARANGAY',
                'barangay_id' => $id,
                'area_name' => $name,
            ];
        }

        return $areas;
    }

    private function rollbackDraft(string $warningId): void
    {
        try {
            $this->client->delete('early_warning_areas', [
                'warning_id' => 'eq.' . $warningId,
                'select' => 'id',
            ]);

            $deletedWarnings = $this->client->delete('early_warnings', [
                'id' => 'eq.' . $warningId,
                'status' => 'eq.DRAFT',
                'select' => 'id',
            ]);

            $remainingWarnings = $this->client->get('early_warnings', [
                'select' => 'id',
                'id' => 'eq.' . $warningId,
                'limit' => 1,
            ]);
            $remainingAreas = $this->client->get('early_warning_areas', [
                'select' => 'id',
                'warning_id' => 'eq.' . $warningId,
                'limit' => 1,
            ]);

            if (count($deletedWarnings) !== 1 || $remainingWarnings !== [] || $remainingAreas !== []) {
                throw new RuntimeException('Rollback verification failed.');
            }
        } catch (Throwable $exception) {
            throw new DrrmEarlyWarningWriteException(
                'Unable to save warning and automatic rollback could not be verified.',
                0,
                $exception
            );
        }
    }

    private function requiredText(mixed $value, string $message, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new DrrmEarlyWarningValidationException('Source Reference is invalid.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            return null;
        }
        return $value;
    }

    /** @param list<string> $allowed */
    private function controlledCode(mixed $value, array $allowed, string $message): string
    {
        if (!is_string($value)) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        return $value;
    }

    private function timestamp(mixed $value, string $message, bool $nullable): ?DateTimeImmutable
    {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        if (!is_string($value) || preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/',
            $value
        ) !== 1) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        try {
            $timestamp = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                throw new DrrmEarlyWarningValidationException($message);
            }
            return $timestamp;
        } catch (DrrmEarlyWarningValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DrrmEarlyWarningValidationException($message);
        }
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
