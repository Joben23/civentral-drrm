<?php

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/AuthService.php';
require_once __DIR__ . '/DrrmBarangayAssignmentResolver.php';
require_once __DIR__ . '/DrrmBarangayCoordinationAuthorizationService.php';
use InvalidArgumentException;
use RuntimeException;

final class DrrmBarangayCoordinationValidationException extends RuntimeException {}

final class DrrmBarangayCoordinationService
{
    public const SITUATION_LEVELS = ['NORMAL', 'MONITORING', 'ELEVATED', 'CRITICAL'];
    public const ACCESS_CONDITIONS = ['ACCESSIBLE', 'PARTIALLY_BLOCKED', 'BLOCKED', 'UNKNOWN'];
    public const REQUEST_CATEGORIES = ['RELIEF_GOODS', 'RESCUE', 'MEDICAL', 'EVACUATION', 'EQUIPMENT', 'ROAD_ACCESS', 'INFORMATION', 'OTHER'];
    public const PRIORITIES = ['NORMAL', 'HIGH', 'URGENT'];
    public const REQUEST_STATUSES = ['PENDING', 'ACKNOWLEDGED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'];
    public const REQUEST_STATUS_PENDING = 'PENDING';
    public const REQUEST_STATUS_ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const REQUEST_STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const REQUEST_STATUS_COMPLETED = 'COMPLETED';
    public const REQUEST_STATUS_CANCELLED = 'CANCELLED';

    private readonly DrrmDataStoreInterface $client;
    private readonly DrrmBarangayAssignmentResolver $resolver;
    private readonly ?AuthService $auth;

    public function __construct(DrrmDataStoreInterface $client, ?AuthService $auth = null, ?DrrmBarangayAssignmentResolver $resolver = null)
    {
        $this->client = $client;
        $this->auth = $auth;
        $this->resolver = $resolver ?? new DrrmBarangayAssignmentResolver($client);
    }

    private function currentUserDetails(): array
    {
        return is_array($_SESSION['current_user_details'] ?? null) ? $_SESSION['current_user_details'] : [];
    }

    private function globalActorByProfile(): bool
    {
        $details = $this->currentUserDetails();
        return filter_var($details['is_superadmin'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || filter_var($details['is_global_access'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function module5AllBarangaysActor(): bool
    {
        if ($this->globalActorByProfile()) {
            return true;
        }

        $authorization = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
        return $authorization->canEdit();
    }

    private function scopedBarangayId(): ?string
    {
        $userReference = '';
        if ($this->auth instanceof AuthService) {
            $source = $this->auth->currentUserId();
            if (is_string($source)) {
                $userReference = trim($source);
            }
        }

        if ($userReference === '') {
            return null;
        }

        return $this->resolver->activeBarangayIdForUser($userReference);
    }

    private function filterRowsByBarangay(array $rows): array
    {
        if ($this->module5AllBarangaysActor()) {
            return $rows;
        }
        $scope = $this->scopedBarangayId();
        if ($scope === null) {
            return [];
        }
        return array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['barangay_id'] ?? '') === $scope));
    }

    private function readScopeForCreate(array $input): string
    {
        if ($this->module5AllBarangaysActor()) {
            return $this->requiredUuid($input['barangay_id'] ?? null, 'Barangay is required.');
        }

        $scope = $this->scopedBarangayId();
        if ($scope !== null) {
            return $scope;
        }

        throw new DrrmBarangayCoordinationValidationException('Barangay assignment is required.');
    }

    public function availableBarangays(): array
    {
        if ($this->module5AllBarangaysActor()) {
            return $this->client->get('barangays', ['select' => 'barangay_id,name,barangay_code', 'order' => 'name.asc']);
        }

        $scope = $this->scopedBarangayId();
        if ($scope === null) {
            return [];
        }

        return $this->client->get('barangays', [
            'select' => 'barangay_id,name,barangay_code',
            'order' => 'name.asc',
            'barangay_id' => 'eq.' . $scope,
        ]);
    }

    public function assignmentBarangays(): array
    {
        $this->requireAssignmentAdminEdit();
        return $this->client->get('barangays', [
            'select' => 'barangay_id,name,barangay_code',
            'order' => 'name.asc',
        ]);
    }

    public function summary(): array
    {
        if ($this->module5AllBarangaysActor()) {
            $reports = $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,evacuees,reported_at,created_at', 'order' => 'reported_at.desc,created_at.desc,id.desc']);
            $requests = $this->client->get('drrm_barangay_assistance_requests', ['select' => 'id,status,priority,barangay_id,requested_at,created_at,updated_at', 'order' => 'requested_at.desc,created_at.desc,id.desc']);
        } else {
            $scope = $this->scopedBarangayId();
            if ($scope === null) {
                return ['barangays_reporting' => 0, 'active_requests' => 0, 'urgent_requests' => 0, 'total_evacuees' => 0];
            }
            $reports = $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,evacuees,reported_at,created_at', 'order' => 'reported_at.desc,created_at.desc,id.desc', 'barangay_id' => 'eq.' . $scope]);
            $requests = $this->client->get('drrm_barangay_assistance_requests', ['select' => 'id,status,priority,barangay_id,requested_at,created_at,updated_at', 'order' => 'requested_at.desc,created_at.desc,id.desc', 'barangay_id' => 'eq.' . $scope]);
        }
        $activeRequests = 0;
        $urgent = 0;
        foreach ($requests as $row) {
            $status = strtoupper((string) ($row['status'] ?? ''));
            if (!in_array($status, ['COMPLETED', 'CANCELLED'], true)) {
                $activeRequests++;
                if (strtoupper((string) ($row['priority'] ?? '')) === 'URGENT') {
                    $urgent++;
                }
            }
        }

        $latestByBarangay = [];
        foreach ($reports as $row) {
            $key = (string) ($row['barangay_id'] ?? '');
            if ($key === '') {
                continue;
            }
            $candidate = $row;
            if (!isset($latestByBarangay[$key])) {
                $latestByBarangay[$key] = $candidate;
                continue;
            }
            if ($this->laterReport($candidate, $latestByBarangay[$key])) {
                $latestByBarangay[$key] = $candidate;
            }
        }

        $totalEvacuees = 0;
        foreach ($latestByBarangay as $row) {
            $totalEvacuees += (int) ($row['evacuees'] ?? 0);
        }

        return ['barangays_reporting' => count($latestByBarangay), 'active_requests' => $activeRequests, 'urgent_requests' => $urgent, 'total_evacuees' => $totalEvacuees];
    }

    public function currentSituations(): array
    {
        if ($this->module5AllBarangaysActor()) {
            $reports = $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,situation_level,affected_households,evacuees,access_condition,situation_summary,notes,reported_at,reported_by_reference,created_at', 'order' => 'reported_at.desc,created_at.desc,id.desc']);
        } else {
            $scope = $this->scopedBarangayId();
            if ($scope === null) {
                return [];
            }
            $reports = $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,situation_level,affected_households,evacuees,access_condition,situation_summary,notes,reported_at,reported_by_reference,created_at', 'order' => 'reported_at.desc,created_at.desc,id.desc', 'barangay_id' => 'eq.' . $scope]);
        }
        $latest = [];
        foreach ($reports as $row) {
            $barangayId = (string) ($row['barangay_id'] ?? '');
            if ($barangayId === '') {
                continue;
            }
            if (!isset($latest[$barangayId])) {
                $latest[$barangayId] = $row;
                continue;
            }
            if ($this->laterReport($row, $latest[$barangayId])) {
                $latest[$barangayId] = $row;
            }
        }
        $barangays = $this->availableBarangays();
        $names = [];
        foreach ($barangays as $barangay) {
            $names[(string) ($barangay['barangay_id'] ?? '')] = $barangay['name'] ?? '';
        }
        $rows = [];
        foreach ($latest as $report) {
            $rows[] = [
                'barangay_id' => $report['barangay_id'],
                'barangay' => $names[(string) ($report['barangay_id'] ?? '')] ?? 'Unknown Barangay',
                'situation_level' => $report['situation_level'] ?? '',
                'affected_households' => (int) ($report['affected_households'] ?? 0),
                'evacuees' => (int) ($report['evacuees'] ?? 0),
                'access_condition' => $report['access_condition'] ?? '',
                'reported_at' => $report['reported_at'] ?? null,
                'reported_by_reference' => $report['reported_by_reference'] ?? '',
                'situation_summary' => $report['situation_summary'] ?? '',
                'notes' => $report['notes'] ?? '',
            ];
        }
        return $rows;
    }

    private function laterReport(array $candidate, array $current): bool
    {
        $candidateAt = (string) ($candidate['reported_at'] ?? '');
        $currentAt = (string) ($current['reported_at'] ?? '');
        if ($candidateAt !== $currentAt) {
            return strtotime($candidateAt) > strtotime($currentAt);
        }
        $candidateCreated = (string) ($candidate['created_at'] ?? '');
        $currentCreated = (string) ($current['created_at'] ?? '');
        if ($candidateCreated !== $currentCreated) {
            return strtotime($candidateCreated) > strtotime($currentCreated);
        }
        return (string) ($candidate['id'] ?? '') > (string) ($current['id'] ?? '');
    }

    public function situationHistory(): array
    {
        if ($this->module5AllBarangaysActor()) {
            return $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,situation_level,affected_households,evacuees,access_condition,situation_summary,notes,reported_at,reported_by_reference', 'order' => 'reported_at.desc']);
        }
        $scope = $this->scopedBarangayId();
        if ($scope === null) {
            return [];
        }
        return $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,situation_level,affected_households,evacuees,access_condition,situation_summary,notes,reported_at,reported_by_reference', 'order' => 'reported_at.desc', 'barangay_id' => 'eq.' . $scope]);
    }

    public function assistanceRequests(): array
    {
        if ($this->module5AllBarangaysActor()) {
            return $this->client->get('drrm_barangay_assistance_requests', ['select' => 'id,barangay_id,request_category,priority,description,status,requested_at,requested_by_reference,created_at,updated_at', 'order' => 'requested_at.desc']);
        }
        $scope = $this->scopedBarangayId();
        if ($scope === null) {
            return [];
        }
        return $this->client->get('drrm_barangay_assistance_requests', ['select' => 'id,barangay_id,request_category,priority,description,status,requested_at,requested_by_reference,created_at,updated_at', 'order' => 'requested_at.desc', 'barangay_id' => 'eq.' . $scope]);
    }

    public function assistanceRequestHistory(): array
    {
        if ($this->module5AllBarangaysActor()) {
            return $this->client->get('drrm_barangay_assistance_request_updates', ['select' => 'id,request_id,from_status,to_status,response_note,handled_by_reference,created_at', 'order' => 'created_at.desc,id.desc']);
        }

        $scope = $this->scopedBarangayId();
        if ($scope === null) {
            return [];
        }

        $updates = $this->client->get('drrm_barangay_assistance_request_updates', ['select' => 'id,request_id,from_status,to_status,response_note,handled_by_reference,created_at', 'order' => 'created_at.desc,id.desc']);
        $requests = $this->client->get('drrm_barangay_assistance_requests', [
            'select' => 'id,barangay_id',
            'barangay_id' => 'eq.' . $scope,
            'order' => 'requested_at.desc',
        ]);

        $allowedRequestIds = [];
        foreach ($requests as $row) {
            $allowedRequestIds[(string) ($row['id'] ?? '')] = true;
        }

        return array_values(array_filter($updates, static fn(array $row): bool => isset($allowedRequestIds[(string) ($row['request_id'] ?? '')])));
    }

    private function requireAssignmentAdminEdit(): void
    {
        $auth = DrrmBarangayCoordinationAuthorizationService::fromTrustedSession();
        if (!$auth->canEdit()) {
            throw new DrrmBarangayCoordinationAuthorizationException('Module 5 permission denied.');
        }
    }

    private function requireUserReference(string $userReference, string $message = 'User reference is required.'): string
    {
        $userReference = trim((string) $userReference);
        if ($userReference === '') {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        if (mb_strlen($userReference) > 180) {
            throw new DrrmBarangayCoordinationValidationException('Input is too long.');
        }
        return $userReference;
    }

    public function readAssignment(string $userReference): array
    {
        $this->requireAssignmentAdminEdit();
        $userReference = $this->requireUserReference($userReference);
        return $this->client->get('drrm_barangay_user_assignments', [
            'select' => 'id,user_reference,barangay_id,is_active,created_at,updated_at',
            'user_reference' => 'eq.' . $userReference,
            'order' => 'created_at.desc,id.desc',
        ]);
    }

    public function assignUserBarangay(string $userReference, string $barangayId): array
    {
        $this->requireAssignmentAdminEdit();
        $userReference = $this->requireUserReference($userReference);
        $barangayId = $this->requiredUuid($barangayId, 'Barangay is required.');
        return $this->client->rpc('set_drrm_barangay_user_assignment', [
            'p_user_reference' => $userReference,
            'p_barangay_id' => $barangayId,
        ]);
    }

    public function changeUserBarangay(string $userReference, string $barangayId): array
    {
        return $this->assignUserBarangay($userReference, $barangayId);
    }

    public function deactivateUserBarangay(string $userReference): array
    {
        $this->requireAssignmentAdminEdit();
        $userReference = $this->requireUserReference($userReference);
        return $this->client->rpc('deactivate_drrm_barangay_user_assignment', [
            'p_user_reference' => $userReference,
        ]);
    }

    public function createStatusReport(array $input, string $actor): array
    {
        $actor = $this->requiredActor($actor, 'Authenticated user is required.');
        $barangayId = $this->readScopeForCreate($input);
        $level = $this->requiredEnum($input['situation_level'] ?? null, self::SITUATION_LEVELS, 'Situation level is required.');
        $access = $this->requiredEnum($input['access_condition'] ?? null, self::ACCESS_CONDITIONS, 'Access condition is required.');
        $affected = $this->nonNegativeInt($input['affected_households'] ?? null, 'Affected households must be zero or greater.');
        $evacuees = $this->nonNegativeInt($input['evacuees'] ?? null, 'Evacuees must be zero or greater.');
        $summary = $this->requiredText($input['situation_summary'] ?? null, 250, 'Situation summary is required.');
        $notes = $this->optionalText($input['notes'] ?? null, 2000);
        $reportedAt = isset($input['reported_at']) && is_string($input['reported_at']) && trim($input['reported_at']) !== '' ? trim($input['reported_at']) : gmdate('c');

        return $this->client->post('drrm_barangay_status_reports', [
            'barangay_id' => $barangayId,
            'situation_level' => $level,
            'affected_households' => $affected,
            'evacuees' => $evacuees,
            'access_condition' => $access,
            'situation_summary' => $summary,
            'notes' => $notes,
            'reported_at' => $reportedAt,
            'reported_by_reference' => $actor,
        ], ['select' => 'id,barangay_id,situation_level,affected_households,evacuees,access_condition,situation_summary,notes,reported_at,reported_by_reference']);
    }

    public function createAssistanceRequest(array $input, string $actor): array
    {
        $actor = $this->requiredActor($actor, 'Authenticated user is required.');
        $barangayId = $this->readScopeForCreate($input);
        $category = $this->requiredEnum($input['request_category'] ?? null, self::REQUEST_CATEGORIES, 'Request category is required.');
        $priority = $this->requiredEnum($input['priority'] ?? null, self::PRIORITIES, 'Priority is required.');
        $description = $this->requiredText($input['description'] ?? null, 1000, 'Request description is required.');
        $requestedAt = isset($input['requested_at']) && is_string($input['requested_at']) && trim($input['requested_at']) !== '' ? trim($input['requested_at']) : gmdate('c');

        return $this->client->post('drrm_barangay_assistance_requests', [
            'barangay_id' => $barangayId,
            'request_category' => $category,
            'priority' => $priority,
            'description' => $description,
            'status' => self::REQUEST_STATUS_PENDING,
            'requested_at' => $requestedAt,
            'requested_by_reference' => $actor,
        ], ['select' => 'id,barangay_id,request_category,priority,description,status,requested_at,requested_by_reference']);
    }

    public function transitionAssistanceRequest(array $input, string $actor): array
    {
        $actor = $this->requiredActor($actor, 'Authenticated user is required.');
        $requestId = $this->requiredUuid($input['request_id'] ?? null, 'Assistance request is required.');
        $expectedStatus = $this->requiredEnum($input['expected_status'] ?? null, self::REQUEST_STATUSES, 'Expected status is required.');
        $targetStatus = $this->requiredEnum($input['target_status'] ?? null, self::REQUEST_STATUSES, 'Target status is required.');
        $responseNote = $this->optionalText($input['response_note'] ?? null, 1000);

        if (!self::transitionIsAllowed($expectedStatus, $targetStatus)) {
            throw new DrrmBarangayCoordinationValidationException('Illegal assistance request transition.');
        }

        if (in_array($targetStatus, [self::REQUEST_STATUS_COMPLETED, self::REQUEST_STATUS_CANCELLED], true) && trim($responseNote) === '') {
            throw new DrrmBarangayCoordinationValidationException('Response note is required for completed or cancelled requests.');
        }

        $result = $this->client->rpc('transition_assistance_request', [
            'p_request_id' => $requestId,
            'p_expected_status' => $expectedStatus,
            'p_target_status' => $targetStatus,
            'p_response_note' => $responseNote,
            'p_handled_by_reference' => $actor,
        ]);

        if (isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }
        if (isset($result['data']) && is_array($result['data'])) {
            return $result['data'];
        }
        return $result;
    }

    public static function transitionIsAllowed(string $fromStatus, string $toStatus): bool
    {
        return match (true) {
            $fromStatus === self::REQUEST_STATUS_PENDING && $toStatus === self::REQUEST_STATUS_ACKNOWLEDGED => true,
            $fromStatus === self::REQUEST_STATUS_PENDING && $toStatus === self::REQUEST_STATUS_CANCELLED => true,
            $fromStatus === self::REQUEST_STATUS_ACKNOWLEDGED && $toStatus === self::REQUEST_STATUS_IN_PROGRESS => true,
            $fromStatus === self::REQUEST_STATUS_ACKNOWLEDGED && $toStatus === self::REQUEST_STATUS_CANCELLED => true,
            $fromStatus === self::REQUEST_STATUS_IN_PROGRESS && $toStatus === self::REQUEST_STATUS_COMPLETED => true,
            $fromStatus === self::REQUEST_STATUS_IN_PROGRESS && $toStatus === self::REQUEST_STATUS_CANCELLED => true,
            default => false,
        };
    }

    private function requiredActor(string $actor, string $message): string
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        if (mb_strlen($actor) > 180) {
            throw new DrrmBarangayCoordinationValidationException('Input is too long.');
        }
        return $actor;
    }

    private function requiredUuid(mixed $value, string $message): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', trim($value))) {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        return trim($value);
    }

    private function requiredEnum(mixed $value, array $allowed, string $message): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        return $value;
    }

    private function requiredText(mixed $value, int $maxLength, string $message): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        $text = trim($value);
        if (mb_strlen($text) > $maxLength) {
            throw new DrrmBarangayCoordinationValidationException('Input is too long.');
        }
        return $text;
    }

    private function optionalText(mixed $value, int $maxLength): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            return '';
        }
        $text = trim($value);
        if (mb_strlen($text) > $maxLength) {
            throw new DrrmBarangayCoordinationValidationException('Input is too long.');
        }
        return $text;
    }

    private function nonNegativeInt(mixed $value, string $message): int
    {
        if (!is_numeric($value)) {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        $int = (int) $value;
        if ($int < 0) {
            throw new DrrmBarangayCoordinationValidationException($message);
        }
        return $int;
    }
}
