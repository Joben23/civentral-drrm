<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/DrrmBarangayCatalogService.php';
require_once __DIR__ . '/DrrmEarlyWarningLifecyclePolicy.php';
require_once __DIR__ . '/SupabaseRestException.php';

final class DrrmEarlyWarningValidationException extends RuntimeException
{
}

final class DrrmEarlyWarningLifecycleException extends RuntimeException
{
}

final class DrrmEarlyWarningConflictException extends RuntimeException
{
}

final class DrrmEarlyWarningWriteException extends RuntimeException
{
}

/**
 * Server-only transactional write boundary for human-reviewed Module 4 warnings.
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

    public function __construct(private readonly DrrmDataStoreInterface $client)
    {
    }

    /**
     * Resolve the actor only from trusted server-side session data. Module 4's
     * Supabase database does not own CIVENTRAL identities, so this deliberately
     * follows the established USER:/EMPLOYEE: external-reference convention.
     *
     * @param array<string, mixed>|null $trustedSession
     */
    public static function actorReferenceFromSession(?array $trustedSession = null): string
    {
        $session = $trustedSession ?? $_SESSION;
        $userId = $session['user_id'] ?? null;
        $employeeId = $session['employee_id'] ?? null;

        $prefix = is_scalar($userId) && trim((string) $userId) !== '' ? 'USER:' : 'EMPLOYEE:';
        $value = $prefix === 'USER:' ? $userId : $employeeId;
        $value = is_scalar($value) ? trim((string) $value) : '';

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,149}$/', $value) !== 1) {
            throw new DrrmEarlyWarningValidationException('A trusted warning actor could not be resolved.');
        }

        return $prefix . $value;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function createDraft(array $input, string $actorReference): array
    {
        $draft = $this->validateDraftInput($input);
        $actorReference = $this->trustedActorReference($actorReference);

        $result = $this->callRpc('create_module4_warning_draft', $this->draftRpcPayload(
            $draft,
            $actorReference
        ));

        if (($result['outcome'] ?? null) !== 'CREATED') {
            throw new DrrmEarlyWarningWriteException('The warning draft was not created transactionally.');
        }

        return $this->mutationResult($result, 'DRAFT');
    }

    /**
     * Create one Phase 4A draft from a locked, still-pending staged advisory.
     * PHP validates the human-confirmed definition; the database RPC remains
     * authoritative for source identity, payload version, and atomic linking.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createDraftFromExternalAdvisory(
        array $input,
        string $actorReference
    ): array {
        $allowedKeys = [
            'external_advisory_id', 'expected_payload_version',
            'title', 'hazard_type', 'warning_level', 'source_code', 'summary',
            'issued_at', 'valid_until', 'source_reference', 'scope_type', 'barangay_ids',
        ];
        if (array_diff(array_keys($input), $allowedKeys) !== []) {
            throw new DrrmEarlyWarningValidationException(
                'Unsupported external advisory conversion fields were supplied.'
            );
        }

        $externalAdvisoryId = $this->uuidIdentifier(
            $input['external_advisory_id'] ?? null,
            'Invalid external advisory identifier.'
        );
        $expectedPayloadVersion = $this->expectedPayloadVersion(
            $input['expected_payload_version'] ?? null
        );
        $definitionInput = array_diff_key(
            $input,
            array_flip(['external_advisory_id', 'expected_payload_version'])
        );
        $draft = $this->validateDraftInput($definitionInput);
        $actorReference = $this->trustedActorReference($actorReference);

        $result = $this->callRpc('convert_module4_external_advisory_to_draft', [
            'p_external_advisory_id' => $externalAdvisoryId,
            'p_expected_payload_version' => $expectedPayloadVersion,
            'p_expected_source_id' => $draft['source']['id'],
            'p_title' => $draft['title'],
            'p_hazard_type' => $draft['hazard_type'],
            'p_warning_level_id' => $draft['risk_level']['risk_level_id'],
            'p_summary' => $draft['summary'],
            'p_issued_at' => $draft['issued_at'],
            'p_valid_until' => $draft['valid_until'],
            'p_source_reference' => $draft['source_reference'],
            'p_areas' => $draft['areas'],
            'p_actor_reference' => $actorReference,
        ]);
        $outcome = (string) ($result['outcome'] ?? '');

        if (in_array($outcome, [
            'REVIEW_CONFLICT', 'PAYLOAD_VERSION_CONFLICT', 'SOURCE_CONFLICT',
        ], true)) {
            throw new DrrmEarlyWarningConflictException(
                'This advisory changed or was already reviewed. Refresh and review the latest record before trying again.'
            );
        }
        if ($outcome === 'NOT_FOUND') {
            throw new DrrmEarlyWarningLifecycleException(
                'The staged external advisory could not be found.'
            );
        }
        if ($outcome !== 'DRAFT_CREATED'
            || ($result['review_status'] ?? null) !== 'DRAFT_CREATED'
            || ($result['external_advisory_id'] ?? null) !== $externalAdvisoryId
            || (int) ($result['payload_version'] ?? 0) !== $expectedPayloadVersion) {
            throw new DrrmEarlyWarningWriteException(
                'The external advisory was not converted transactionally.'
            );
        }

        $warning = $this->mutationResult($result, 'DRAFT');
        if ($warning['revision'] !== 1
            || ($result['linked_warning_id'] ?? null) !== $warning['id']) {
            throw new DrrmEarlyWarningWriteException(
                'The converted warning draft response was invalid.'
            );
        }

        return [
            'external_advisory_id' => $externalAdvisoryId,
            'review_status' => 'DRAFT_CREATED',
            'payload_version' => $expectedPayloadVersion,
            'reviewed_at' => (string) ($result['reviewed_at'] ?? ''),
            'linked_warning_id' => $warning['id'],
            'warning' => $warning,
            'warning_created' => true,
            'warning_activated' => false,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function updateDraft(array $input, string $actorReference): array
    {
        $allowedKeys = [
            'warning_id', 'expected_revision', 'title', 'hazard_type', 'warning_level',
            'source_code', 'summary', 'issued_at', 'valid_until', 'source_reference',
            'scope_type', 'barangay_ids',
        ];
        if (array_diff(array_keys($input), $allowedKeys) !== []) {
            throw new DrrmEarlyWarningValidationException('Unsupported warning fields were supplied.');
        }

        $warningId = $this->warningId($input['warning_id'] ?? null);
        $expectedRevision = $this->expectedRevision($input['expected_revision'] ?? null);
        $definitionInput = array_diff_key($input, array_flip(['warning_id', 'expected_revision']));
        $draft = $this->validateDraftInput($definitionInput);
        $actorReference = $this->trustedActorReference($actorReference);

        $payload = $this->draftRpcPayload($draft, $actorReference);
        $payload = ['p_warning_id' => $warningId, 'p_expected_revision' => $expectedRevision] + $payload;
        $result = $this->callRpc('update_module4_warning_draft', $payload);
        $outcome = (string) ($result['outcome'] ?? '');

        if ($outcome === 'REVISION_CONFLICT') {
            throw new DrrmEarlyWarningConflictException(
                'This warning changed after it was loaded. Refresh and review the latest version before trying again.'
            );
        }
        if ($outcome === 'NOT_DRAFT') {
            throw new DrrmEarlyWarningLifecycleException('Only a persisted DRAFT warning can be edited.');
        }
        if ($outcome === 'NOT_FOUND') {
            throw new DrrmEarlyWarningLifecycleException('The warning could not be found.');
        }
        if ($outcome !== 'UPDATED') {
            throw new DrrmEarlyWarningWriteException('The warning draft was not updated transactionally.');
        }

        return $this->mutationResult($result, 'DRAFT');
    }

    /** @return array<string, mixed> */
    public function activate(string $warningId, int $expectedRevision, string $actorReference): array
    {
        return $this->changeStatus($warningId, $expectedRevision, 'ACTIVATE', $actorReference);
    }

    /** @return array<string, mixed> */
    public function cancel(string $warningId, int $expectedRevision, string $actorReference): array
    {
        return $this->changeStatus($warningId, $expectedRevision, 'CANCEL', $actorReference);
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
        if ($validUntil !== null && $validUntil <= $issuedAt) {
            throw new DrrmEarlyWarningValidationException('Valid Until must be later than Issued At.');
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
    private function changeStatus(
        string $warningId,
        int $expectedRevision,
        string $action,
        string $actorReference
    ): array {
        $warningId = $this->warningId($warningId);
        $expectedRevision = $this->expectedRevision($expectedRevision);
        $actorReference = $this->trustedActorReference($actorReference);

        if (!in_array($action, ['ACTIVATE', 'CANCEL'], true)) {
            throw new DrrmEarlyWarningValidationException('Invalid warning lifecycle action.');
        }

        $warnings = $this->client->get('early_warnings', [
            'select' => 'id,source_id,title,hazard_type,warning_level_id,summary,status,issued_at,valid_until,source_reference,revision',
            'id' => 'eq.' . $warningId,
            'limit' => 2,
        ]);
        if (count($warnings) !== 1 || !is_array($warnings[0])) {
            throw new DrrmEarlyWarningLifecycleException('The warning could not be found.');
        }

        $warning = $warnings[0];
        $currentStatus = (string) ($warning['status'] ?? '');
        $currentRevision = $this->recordRevision($warning['revision'] ?? null);
        if ($currentRevision !== $expectedRevision) {
            throw new DrrmEarlyWarningConflictException(
                'This warning changed after it was loaded. Refresh and review the latest version before trying again.'
            );
        }

        if ($action === 'ACTIVATE') {
            if ($currentStatus !== 'DRAFT') {
                throw new DrrmEarlyWarningLifecycleException('This warning can no longer be activated.');
            }
            $this->validateActivation($warning);
        } elseif (!in_array($currentStatus, ['DRAFT', 'ACTIVE'], true)) {
            throw new DrrmEarlyWarningLifecycleException('This warning can no longer be cancelled.');
        }

        $result = $this->callRpc('change_module4_warning_status', [
            'p_warning_id' => $warningId,
            'p_expected_revision' => $expectedRevision,
            'p_action' => $action,
            'p_actor_reference' => $actorReference,
        ]);
        $outcome = (string) ($result['outcome'] ?? '');

        if ($outcome === 'REVISION_CONFLICT') {
            throw new DrrmEarlyWarningConflictException(
                'This warning changed after it was loaded. Refresh and review the latest version before trying again.'
            );
        }
        if ($outcome === 'NOT_FOUND') {
            throw new DrrmEarlyWarningLifecycleException('The warning could not be found.');
        }
        if ($outcome === 'INVALID_STATUS') {
            throw new DrrmEarlyWarningLifecycleException('The warning status changed before this action completed.');
        }
        if ($outcome !== 'CHANGED') {
            $messages = [
                'FUTURE_ISSUED_AT' => 'The warning issue time is in the future and cannot be activated.',
                'INVALID_VALIDITY_ORDER' => 'The warning validity period must be later than the issue time.',
                'ALREADY_EXPIRED' => 'The warning validity period has already expired.',
                'INCOMPLETE' => 'The warning is incomplete and cannot be activated.',
                'INVALID_SOURCE' => 'The warning source is no longer valid.',
                'MISSING_SOURCE_REFERENCE' => 'The external warning source reference is missing.',
                'INVALID_WARNING_LEVEL' => 'The warning level is no longer valid.',
                'NO_AFFECTED_AREA' => 'The warning has no affected area.',
            ];
            if (isset($messages[$outcome])) {
                throw new DrrmEarlyWarningLifecycleException($messages[$outcome]);
            }
            throw new DrrmEarlyWarningWriteException('The warning lifecycle action was not completed transactionally.');
        }

        return $this->mutationResult($result, $action === 'ACTIVATE' ? 'ACTIVE' : 'CANCELLED');
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
        $violation = DrrmEarlyWarningLifecyclePolicy::activationTimestampViolation($issuedAt, $validUntil);
        if ($violation === DrrmEarlyWarningLifecyclePolicy::ACTIVATION_VALIDITY_ORDER_INVALID) {
            throw new DrrmEarlyWarningLifecycleException('The warning validity period must be later than the issue time.');
        }
        if ($violation === DrrmEarlyWarningLifecyclePolicy::ACTIVATION_FUTURE_ISSUED_AT) {
            throw new DrrmEarlyWarningLifecycleException('The warning issue time is in the future and cannot be activated.');
        }
        if ($violation === DrrmEarlyWarningLifecyclePolicy::ACTIVATION_ALREADY_EXPIRED) {
            throw new DrrmEarlyWarningLifecycleException('The warning validity period has already expired.');
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

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    private function draftRpcPayload(array $draft, string $actorReference): array
    {
        return [
            'p_source_id' => $draft['source']['id'],
            'p_title' => $draft['title'],
            'p_hazard_type' => $draft['hazard_type'],
            'p_warning_level_id' => $draft['risk_level']['risk_level_id'],
            'p_summary' => $draft['summary'],
            'p_issued_at' => $draft['issued_at'],
            'p_valid_until' => $draft['valid_until'],
            'p_source_reference' => $draft['source_reference'],
            'p_areas' => $draft['areas'],
            'p_actor_reference' => $actorReference,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function callRpc(string $function, array $payload): array
    {
        try {
            $result = $this->client->rpc($function, $payload);
        } catch (SupabaseRestException $exception) {
            if ($exception->sqlState() === '23505') {
                throw new DrrmEarlyWarningValidationException(
                    'The warning contains duplicate or conflicting constrained values.',
                    0,
                    $exception
                );
            }
            if (in_array($exception->sqlState(), ['22023', '23502', '23503', '23514'], true)) {
                throw new DrrmEarlyWarningValidationException(
                    'The warning failed server-side validation.',
                    0,
                    $exception
                );
            }
            throw new DrrmEarlyWarningWriteException('Unable to save warning.', 0, $exception);
        } catch (Throwable $exception) {
            throw new DrrmEarlyWarningWriteException('Unable to save warning.', 0, $exception);
        }

        if (array_is_list($result)) {
            if (count($result) !== 1 || !is_array($result[0])) {
                throw new DrrmEarlyWarningWriteException('The warning mutation response was invalid.');
            }
            $result = $result[0];
        }
        if ($result === []) {
            throw new DrrmEarlyWarningWriteException('The warning mutation response was empty.');
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function mutationResult(array $result, string $expectedStatus): array
    {
        $id = (string) ($result['id'] ?? '');
        $status = (string) ($result['status'] ?? '');
        $revision = $this->recordRevision($result['revision'] ?? null);
        if (!$this->isUuid($id) || $status !== $expectedStatus) {
            throw new DrrmEarlyWarningWriteException('The warning mutation response was invalid.');
        }

        return [
            'id' => $id,
            'title' => (string) ($result['title'] ?? ''),
            'previous_status' => $result['previous_status'] ?? null,
            'status' => $status,
            'revision' => $revision,
            'updated_at' => isset($result['updated_at']) ? (string) $result['updated_at'] : null,
            'affected_area_count' => isset($result['affected_area_count'])
                ? (int) $result['affected_area_count']
                : null,
        ];
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
        if (count($rows) !== 1 || !is_array($rows[0])
            || !is_int($rows[0]['risk_level_id'] ?? null)
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
        return [['scope_type' => 'CITY', 'barangay_id' => null, 'area_name' => 'Caloocan City']];
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
            throw new DrrmEarlyWarningWriteException('The validated barangay catalog is unavailable.', 0, $exception);
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
            $areas[] = ['scope_type' => 'BARANGAY', 'barangay_id' => $id, 'area_name' => $name];
        }
        return $areas;
    }

    private function warningId(mixed $value): string
    {
        if (!is_string($value) || !$this->isUuid($value)) {
            throw new DrrmEarlyWarningValidationException('Invalid warning identifier.');
        }
        return strtolower($value);
    }

    private function expectedRevision(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new DrrmEarlyWarningValidationException('A valid expected warning revision is required.');
        }
        return $value;
    }

    private function expectedPayloadVersion(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw new DrrmEarlyWarningValidationException(
                'A valid expected advisory payload version is required.'
            );
        }
        return $value;
    }

    private function uuidIdentifier(mixed $value, string $message): string
    {
        if (!is_string($value) || !$this->isUuid($value)) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        return strtolower($value);
    }

    private function recordRevision(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new DrrmEarlyWarningWriteException('The warning revision response was invalid.');
        }
        return $value;
    }

    private function trustedActorReference(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(?:USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,149}$/', $value) !== 1) {
            throw new DrrmEarlyWarningValidationException('A trusted warning actor could not be resolved.');
        }
        return $value;
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
        $timestamp = DrrmEarlyWarningLifecyclePolicy::parseTimestamp($value);
        if ($timestamp === null) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        return $timestamp;
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
