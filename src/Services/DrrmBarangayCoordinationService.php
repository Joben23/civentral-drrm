<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class DrrmBarangayCoordinationValidationException extends RuntimeException {}

final class DrrmBarangayCoordinationService
{
    public const SITUATION_LEVELS = ['NORMAL', 'MONITORING', 'ELEVATED', 'CRITICAL'];
    public const ACCESS_CONDITIONS = ['ACCESSIBLE', 'PARTIALLY_BLOCKED', 'BLOCKED', 'UNKNOWN'];
    public const REQUEST_CATEGORIES = ['RELIEF_GOODS', 'RESCUE', 'MEDICAL', 'EVACUATION', 'EQUIPMENT', 'ROAD_ACCESS', 'INFORMATION', 'OTHER'];
    public const PRIORITIES = ['NORMAL', 'HIGH', 'URGENT'];
    public const REQUEST_STATUS_PENDING = 'PENDING';

    public function __construct(private readonly DrrmDataStoreInterface $client) {}

    public function availableBarangays(): array
    {
        return $this->client->get('barangays', ['select' => 'barangay_id,name,barangay_code', 'order' => 'name.asc']);
    }

    public function summary(): array
    {
        $reports = $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,evacuees,reported_at,created_at', 'order' => 'reported_at.desc,created_at.desc,id.desc']);
        $requests = $this->client->get('drrm_barangay_assistance_requests', ['select' => 'id,status,priority,barangay_id,requested_at,created_at,updated_at', 'order' => 'requested_at.desc,created_at.desc,id.desc']);
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

        return ['barangays_reporting' => count($latestByBarangay), 'active_requests' => $activeRequests, 'urgent_requests' => $urgent, 'total_evacuees' => $totalEvacuees, 'capabilities' => ['canView' => true, 'canCreate' => true]];
    }

    public function currentSituations(): array
    {
        $reports = $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,situation_level,affected_households,evacuees,access_condition,situation_summary,notes,reported_at,reported_by_reference,created_at', 'order' => 'reported_at.desc,created_at.desc,id.desc']);
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
        return $this->client->get('drrm_barangay_status_reports', ['select' => 'id,barangay_id,situation_level,affected_households,evacuees,access_condition,situation_summary,notes,reported_at,reported_by_reference', 'order' => 'reported_at.desc']);
    }

    public function assistanceRequests(): array
    {
        return $this->client->get('drrm_barangay_assistance_requests', ['select' => 'id,barangay_id,request_category,priority,description,status,requested_at,requested_by_reference,created_at,updated_at', 'order' => 'requested_at.desc']);
    }

    public function createStatusReport(array $input, string $actor): array
    {
        $actor = $this->requiredActor($actor, 'Authenticated user is required.');
        $barangayId = $this->requiredUuid($input['barangay_id'] ?? null, 'Barangay is required.');
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
        $barangayId = $this->requiredUuid($input['barangay_id'] ?? null, 'Barangay is required.');
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
