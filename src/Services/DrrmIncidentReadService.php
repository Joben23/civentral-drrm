<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/DrrmDataStoreInterface.php';

/**
 * Fixed-field, server-side read projection for Module 3 administration.
 */
final class DrrmIncidentReadService
{
    /** @var list<string> */
    public const STATUSES = [
        'SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'ASSIGNED',
        'RESPONDING', 'RESOLVED', 'CLOSED', 'REJECTED',
    ];

    /** @var list<string> */
    public const INCIDENT_TYPES = [
        'FLOOD', 'FIRE', 'LANDSLIDE', 'EARTHQUAKE', 'ROAD_BLOCKAGE',
        'FALLEN_TREE', 'STRUCTURAL_DAMAGE', 'MEDICAL_EMERGENCY',
        'UTILITY_HAZARD', 'OTHER',
    ];

    /** @var list<string> */
    public const SEVERITIES = ['LOW', 'MODERATE', 'HIGH', 'CRITICAL'];

    public function __construct(private readonly DrrmDataStoreInterface $store)
    {
    }

    /** @return array{submitted: int, under_review: int, active_response: int, resolved_today: int, total: int} */
    public function summary(): array
    {
        $result = $this->store->rpc('drrm_incident_summary');
        if (array_is_list($result) && count($result) === 1 && is_array($result[0])) {
            $result = $result[0];
        }

        $summary = [];
        foreach (['submitted', 'under_review', 'active_response', 'resolved_today', 'total'] as $key) {
            $value = $result[$key] ?? null;
            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw new RuntimeException('The incident summary returned an unexpected structure.');
            }
            $summary[$key] = (int) $value;
        }

        /** @var array{submitted: int, under_review: int, active_response: int, resolved_today: int, total: int} $summary */
        return $summary;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{incidents: list<array<string, mixed>>, count: int, lookups: array<string, mixed>}
     */
    public function incidents(array $filters = []): array
    {
        $allowedKeys = ['search', 'status', 'type', 'severity'];
        if (array_diff(array_keys($filters), $allowedKeys) !== []) {
            throw new InvalidArgumentException('Unsupported incident filters were supplied.');
        }

        $search = $this->searchTerm($filters['search'] ?? null);
        $status = $this->optionalCode($filters['status'] ?? null, self::STATUSES, 'Invalid incident status filter.');
        $type = $this->optionalCode($filters['type'] ?? null, self::INCIDENT_TYPES, 'Invalid incident type filter.');
        $severity = $this->optionalCode($filters['severity'] ?? null, self::SEVERITIES, 'Invalid incident severity filter.');
        $lookups = $this->lookups();

        $query = [
            'select' => implode(',', [
                'id', 'incident_number', 'title', 'status', 'reported_at',
                'location_description', 'assigned_department_reference', 'assigned_user_reference',
                'incident_type:drrm_incident_types!drrm_incidents_incident_type_id_fkey(code,label)',
                'severity:drrm_incident_severities!drrm_incidents_severity_id_fkey(code,label,severity_rank)',
                'barangay:barangays!drrm_incidents_barangay_id_fkey(barangay_id,barangay_code,name)',
            ]),
            'order' => 'reported_at.desc',
            'limit' => 200,
        ];

        if ($status !== null) {
            $query['status'] = 'eq.' . $status;
        }
        if ($type !== null) {
            $query['incident_type_id'] = 'eq.' . $this->lookupId($lookups['types'], $type, 'incident_type_id');
        }
        if ($severity !== null) {
            $query['severity_id'] = 'eq.' . $this->lookupId($lookups['severities'], $severity, 'severity_id');
        }
        if ($search !== null) {
            $pattern = '*' . $search . '*';
            $query['or'] = '(incident_number.ilike.' . $pattern
                . ',title.ilike.' . $pattern
                . ',location_description.ilike.' . $pattern . ')';
        }

        $records = $this->recordList($this->store->get('drrm_incidents', $query));
        $incidents = array_map(fn (array $record): array => $this->normalizeIncident($record, false), $records);

        return [
            'incidents' => array_values($incidents),
            'count' => count($incidents),
            'lookups' => [
                'statuses' => array_map(static fn (string $code): array => [
                    'code' => $code,
                    'label' => ucwords(strtolower(str_replace('_', ' ', $code))),
                ], self::STATUSES),
                'types' => $lookups['types'],
                'severities' => $lookups['severities'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function details(string $incidentId): array
    {
        if (!$this->isUuid($incidentId)) {
            throw new InvalidArgumentException('Invalid incident identifier.');
        }

        $records = $this->recordList($this->store->get('drrm_incidents', [
            'select' => implode(',', [
                'id', 'incident_number', 'title', 'description', 'status', 'reported_at',
                'reporter_type', 'barangay_id', 'location_description', 'latitude', 'longitude',
                'source', 'verification_status', 'verified_at', 'verified_by_reference',
                'assigned_department_reference', 'assigned_user_reference', 'resolved_at',
                'closed_at', 'created_at', 'updated_at',
                'incident_type:drrm_incident_types!drrm_incidents_incident_type_id_fkey(code,label)',
                'severity:drrm_incident_severities!drrm_incidents_severity_id_fkey(code,label,severity_rank)',
                'barangay:barangays!drrm_incidents_barangay_id_fkey(barangay_id,barangay_code,name)',
            ]),
            'id' => 'eq.' . $incidentId,
            'limit' => 2,
        ]));

        if (count($records) !== 1) {
            throw new DrrmIncidentNotFoundException('The incident could not be found.');
        }

        $incident = $this->normalizeIncident($records[0], true);
        $incident['status_history'] = $this->recordList($this->store->get('drrm_incident_status_history', [
            'select' => 'id,from_status,to_status,changed_at,changed_by_reference,notes',
            'incident_id' => 'eq.' . $incidentId,
            'order' => 'changed_at.asc',
        ]));
        $incident['assignments'] = $this->recordList($this->store->get('drrm_incident_assignments', [
            'select' => 'id,department_reference,user_reference,assigned_at,assigned_by_reference,notes',
            'incident_id' => 'eq.' . $incidentId,
            'order' => 'assigned_at.desc',
        ]));
        $incident['response_logs'] = $this->recordList($this->store->get('drrm_incident_response_logs', [
            'select' => 'id,action_type,message,created_at,created_by_reference',
            'incident_id' => 'eq.' . $incidentId,
            'order' => 'created_at.asc',
        ]));

        return $incident;
    }

    /** @return array{types: list<array<string, mixed>>, severities: list<array<string, mixed>>} */
    private function lookups(): array
    {
        $types = $this->recordList($this->store->get('drrm_incident_types', [
            'select' => 'incident_type_id,code,label,sort_order',
            'is_active' => 'eq.true',
            'order' => 'sort_order.asc',
        ]));
        $severities = $this->recordList($this->store->get('drrm_incident_severities', [
            'select' => 'severity_id,code,label,severity_rank',
            'is_active' => 'eq.true',
            'order' => 'severity_rank.asc',
        ]));

        $typeCodes = array_column($types, 'code');
        $severityCodes = array_column($severities, 'code');
        if ($typeCodes !== self::INCIDENT_TYPES || $severityCodes !== self::SEVERITIES) {
            throw new RuntimeException('The controlled incident lookup catalog is unavailable.');
        }

        return ['types' => $types, 'severities' => $severities];
    }

    /** @param list<array<string, mixed>> $records */
    private function lookupId(array $records, string $code, string $idField): int
    {
        foreach ($records as $record) {
            if (($record['code'] ?? null) === $code && is_numeric($record[$idField] ?? null)) {
                return (int) $record[$idField];
            }
        }
        throw new RuntimeException('The selected incident lookup is unavailable.');
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function normalizeIncident(array $record, bool $includeDetails): array
    {
        $type = $this->relatedRecord($record['incident_type'] ?? null);
        $severity = $this->relatedRecord($record['severity'] ?? null);
        $barangayValue = $record['barangay'] ?? null;
        $barangay = $barangayValue === null
            ? null
            : $this->relatedRecord($barangayValue);

        foreach (['id', 'incident_number', 'title', 'status', 'reported_at', 'location_description'] as $field) {
            if (!is_string($record[$field] ?? null) || trim((string) $record[$field]) === '') {
                throw new RuntimeException('An incident record has an unexpected structure.');
            }
        }
        if (!in_array($record['status'], self::STATUSES, true)
            || !in_array($type['code'] ?? null, self::INCIDENT_TYPES, true)
            || !in_array($severity['code'] ?? null, self::SEVERITIES, true)) {
            throw new RuntimeException('An incident record contains an unsupported controlled value.');
        }

        $normalized = [
            'id' => (string) $record['id'],
            'incident_number' => (string) $record['incident_number'],
            'incident_type' => ['code' => (string) $type['code'], 'label' => (string) ($type['label'] ?? $type['code'])],
            'title' => (string) $record['title'],
            'severity' => ['code' => (string) $severity['code'], 'label' => (string) ($severity['label'] ?? $severity['code'])],
            'status' => (string) $record['status'],
            'reported_at' => (string) $record['reported_at'],
            'location_description' => (string) $record['location_description'],
            'barangay' => $barangay,
            'assigned_department_reference' => $this->nullableString($record['assigned_department_reference'] ?? null),
            'assigned_user_reference' => $this->nullableString($record['assigned_user_reference'] ?? null),
        ];

        if (!$includeDetails) {
            return $normalized;
        }

        foreach (['description', 'reporter_type', 'source', 'verification_status', 'created_at', 'updated_at'] as $field) {
            if (!is_string($record[$field] ?? null) || trim((string) $record[$field]) === '') {
                throw new RuntimeException('An incident detail record has an unexpected structure.');
            }
        }
        $normalized += [
            'description' => (string) $record['description'],
            'reporter_type' => (string) $record['reporter_type'],
            'source' => (string) $record['source'],
            'verification_status' => (string) $record['verification_status'],
            'latitude' => $record['latitude'] === null ? null : (float) $record['latitude'],
            'longitude' => $record['longitude'] === null ? null : (float) $record['longitude'],
            'verified_at' => $this->nullableString($record['verified_at'] ?? null),
            'verified_by_reference' => $this->nullableString($record['verified_by_reference'] ?? null),
            'resolved_at' => $this->nullableString($record['resolved_at'] ?? null),
            'closed_at' => $this->nullableString($record['closed_at'] ?? null),
            'created_at' => (string) $record['created_at'],
            'updated_at' => (string) $record['updated_at'],
        ];

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function relatedRecord(mixed $value): array
    {
        if (is_array($value) && array_is_list($value) && count($value) === 1 && is_array($value[0])) {
            $value = $value[0];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('An incident relationship has an unexpected structure.');
        }
        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function searchTerm(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid incident search filter.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 80
            || preg_match("/^[\\p{L}\\p{N} .'-]+$/u", $value) !== 1) {
            throw new InvalidArgumentException('Invalid incident search filter.');
        }
        return $value;
    }

    /** @param list<string> $allowed */
    private function optionalCode(mixed $value, array $allowed, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($message);
        }
        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    /** @param array<mixed> $records @return list<array<string, mixed>> */
    private function recordList(array $records): array
    {
        if (!array_is_list($records)) {
            throw new RuntimeException('The incident data source returned an unexpected collection.');
        }
        foreach ($records as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new RuntimeException('The incident data source returned an unexpected record.');
            }
        }
        /** @var list<array<string, mixed>> $records */
        return array_values($records);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}

final class DrrmIncidentNotFoundException extends RuntimeException
{
}
