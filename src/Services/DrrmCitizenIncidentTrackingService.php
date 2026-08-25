<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/CitizenSessionIdentityVerifier.php';

/**
 * Fixed-field citizen projection for owned Module 3 incidents.
 *
 * The verified identity is the only source of reporter_reference. Internal
 * incident identifiers are used only for server-side joins and are never
 * returned to the caller.
 */
final class DrrmCitizenIncidentTrackingService
{
    private const MY_INCIDENT_LIMIT = 100;
    private const NOTIFICATION_LIMIT = 100;
    private const MAX_NOTIFICATION_INCIDENTS = 1000;
    private const NOTIFICATION_INCIDENT_CHUNK_SIZE = 50;

    /** @var list<string> */
    public const STATUSES = [
        'SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'ASSIGNED',
        'RESPONDING', 'RESOLVED', 'CLOSED', 'REJECTED',
    ];

    /** @var list<string> */
    public const NOTIFICATION_STATUSES = [
        'UNDER_REVIEW', 'VERIFIED', 'ASSIGNED', 'RESPONDING',
        'RESOLVED', 'CLOSED', 'REJECTED',
    ];

    /** @var array<string, array{label: string, message: string}> */
    private const STATUS_SEMANTICS = [
        'SUBMITTED' => [
            'label' => 'Submitted',
            'message' => 'Your report was received and is waiting for DRRM review.',
        ],
        'UNDER_REVIEW' => [
            'label' => 'Under Review',
            'message' => 'DRRM is currently reviewing your report.',
        ],
        'VERIFIED' => [
            'label' => 'Verified',
            'message' => 'DRRM verified the reported incident.',
        ],
        'ASSIGNED' => [
            'label' => 'Assigned',
            'message' => 'A response team has been assigned.',
        ],
        'RESPONDING' => [
            'label' => 'Responding',
            'message' => 'Response operations are currently in progress.',
        ],
        'RESOLVED' => [
            'label' => 'Resolved',
            'message' => 'The incident has been marked as resolved.',
        ],
        'CLOSED' => [
            'label' => 'Closed',
            'message' => 'The incident case has been closed.',
        ],
        'REJECTED' => [
            'label' => 'Rejected',
            'message' => 'DRRM was unable to verify this report.',
        ],
    ];

    /** @var list<string> */
    private const INCIDENT_TYPES = [
        'FLOOD', 'FIRE', 'LANDSLIDE', 'EARTHQUAKE', 'ROAD_BLOCKAGE',
        'FALLEN_TREE', 'STRUCTURAL_DAMAGE', 'MEDICAL_EMERGENCY',
        'UTILITY_HAZARD', 'OTHER',
    ];

    /** @var list<string> */
    private const SEVERITIES = ['UNASSESSED', 'LOW', 'MODERATE', 'HIGH', 'CRITICAL'];

    /** @var list<string> */
    private const VERIFICATION_STATUSES = ['PENDING', 'IN_REVIEW', 'VERIFIED', 'REJECTED'];

    public function __construct(private readonly DrrmDataStoreInterface $store)
    {
    }

    /**
     * @return array{count: int, has_more: bool, incidents: list<array<string, mixed>>}
     */
    public function myIncidents(CitizenIdentity $identity): array
    {
        $records = $this->records($this->store->get('drrm_incidents', [
            'select' => $this->incidentSelect(false),
            'reporter_type' => 'eq.CITIZEN',
            'reporter_reference' => 'eq.' . $identity->reporterReference(),
            'order' => 'reported_at.desc,incident_number.desc',
            'limit' => self::MY_INCIDENT_LIMIT + 1,
        ]));

        $hasMore = count($records) > self::MY_INCIDENT_LIMIT;
        $records = array_slice($records, 0, self::MY_INCIDENT_LIMIT);
        $incidents = array_map(
            fn (array $record): array => $this->normalizeIncident($record, false),
            $records
        );

        return [
            'count' => count($incidents),
            'has_more' => $hasMore,
            'incidents' => array_values($incidents),
        ];
    }

    /** @return array<string, mixed> */
    public function incidentDetails(string $incidentNumber, CitizenIdentity $identity): array
    {
        $incidentNumber = strtoupper(trim($incidentNumber));
        if (preg_match('/^INC-[0-9]{4}-[0-9]{6,}$/', $incidentNumber) !== 1
            || strlen($incidentNumber) > 40) {
            throw new DrrmCitizenIncidentInvalidRequestException('The incident number is invalid.');
        }

        $records = $this->records($this->store->get('drrm_incidents', [
            'select' => $this->incidentSelect(true),
            'incident_number' => 'eq.' . $incidentNumber,
            'reporter_type' => 'eq.CITIZEN',
            'reporter_reference' => 'eq.' . $identity->reporterReference(),
            'limit' => 2,
        ]));

        if (count($records) !== 1) {
            throw new DrrmCitizenIncidentNotFoundException('The incident could not be found.');
        }

        $incidentId = (string) ($records[0]['id'] ?? '');
        if (!$this->isUuid($incidentId)) {
            throw new RuntimeException('The incident data source returned an unexpected identifier.');
        }

        $timelineRows = $this->records($this->store->get('drrm_incident_status_history', [
            'select' => 'from_status,to_status,changed_at',
            'incident_id' => 'eq.' . $incidentId,
            'order' => 'changed_at.asc,id.asc',
            'limit' => count(self::STATUSES) + 1,
        ]));

        if (count($timelineRows) > count(self::STATUSES)) {
            throw new RuntimeException('The incident timeline exceeds the supported lifecycle size.');
        }

        usort($timelineRows, function (array $left, array $right): int {
            $leftAt = $this->timestamp($left['changed_at'] ?? null);
            $rightAt = $this->timestamp($right['changed_at'] ?? null);
            if ($leftAt === null || $rightAt === null) {
                return 0;
            }
            return strcmp(
                $leftAt->format('Y-m-d\TH:i:s.uP'),
                $rightAt->format('Y-m-d\TH:i:s.uP')
            );
        });
        $timeline = array_map(
            fn (array $record): array => $this->normalizeTimelineEvent($record),
            $timelineRows
        );

        $incident = $this->normalizeIncident($records[0], true);
        $incident['timeline'] = array_values($timeline);

        return $incident;
    }

    /**
     * SUBMITTED is intentionally excluded because submission already returns a
     * receipt. Every returned event is derived from append-only status history.
     *
     * @return array{unread_count: int, count: int, has_more: bool, notifications: list<array<string, mixed>>}
     */
    public function notifications(CitizenIdentity $identity): array
    {
        $reporterReference = $identity->reporterReference();
        $lastSeenAt = $this->lastSeenAt($reporterReference);
        $incidentRows = $this->records($this->store->get('drrm_incidents', [
            'select' => 'id,incident_number,title',
            'reporter_type' => 'eq.CITIZEN',
            'reporter_reference' => 'eq.' . $reporterReference,
            'order' => 'reported_at.desc',
            'limit' => self::MAX_NOTIFICATION_INCIDENTS + 1,
        ]));

        if (count($incidentRows) > self::MAX_NOTIFICATION_INCIDENTS) {
            throw new RuntimeException('The citizen incident history exceeds the supported notification size.');
        }

        $incidentsById = [];
        foreach ($incidentRows as $row) {
            $id = (string) ($row['id'] ?? '');
            $incidentNumber = (string) ($row['incident_number'] ?? '');
            $title = trim((string) ($row['title'] ?? ''));
            if (!$this->isUuid($id)
                || preg_match('/^INC-[0-9]{4}-[0-9]{6,}$/', $incidentNumber) !== 1
                || $title === '') {
                throw new RuntimeException('The citizen notification source has an unexpected structure.');
            }
            $incidentsById[$id] = [
                'incident_number' => $incidentNumber,
                'title' => $title,
            ];
        }

        $notifications = [];
        foreach (array_chunk(array_keys($incidentsById), self::NOTIFICATION_INCIDENT_CHUNK_SIZE) as $ids) {
            $maximumRows = count($ids) * count(self::NOTIFICATION_STATUSES);
            $historyRows = $this->records($this->store->get('drrm_incident_status_history', [
                'select' => 'id,incident_id,to_status,changed_at',
                'incident_id' => 'in.(' . implode(',', $ids) . ')',
                'to_status' => 'in.(' . implode(',', self::NOTIFICATION_STATUSES) . ')',
                'order' => 'changed_at.desc,id.desc',
                'limit' => $maximumRows + 1,
            ]));

            if (count($historyRows) > $maximumRows) {
                throw new RuntimeException('The citizen notification history has an unexpected lifecycle size.');
            }

            foreach ($historyRows as $row) {
                $historyId = (string) ($row['id'] ?? '');
                $incidentId = (string) ($row['incident_id'] ?? '');
                $status = (string) ($row['to_status'] ?? '');
                $occurredAt = $this->timestamp($row['changed_at'] ?? null);
                $incident = $incidentsById[$incidentId] ?? null;

                if (!$this->isUuid($historyId) || !is_array($incident)
                    || !in_array($status, self::NOTIFICATION_STATUSES, true)
                    || $occurredAt === null) {
                    throw new RuntimeException('The citizen notification history has an unexpected structure.');
                }

                $semantics = self::STATUS_SEMANTICS[$status];
                $notifications[] = [
                    'event_id' => 'inc_evt_' . substr(hash('sha256', 'drrm-status-history:' . $historyId), 0, 32),
                    'incident_number' => $incident['incident_number'],
                    'title' => $incident['title'],
                    'status' => $status,
                    'status_label' => $semantics['label'],
                    'occurred_at' => $this->formatTimestamp($occurredAt),
                    '_sort_at' => $occurredAt->format('Y-m-d\TH:i:s.uP'),
                    'message' => $semantics['message'],
                    'is_read' => $lastSeenAt !== null && $occurredAt <= $lastSeenAt,
                ];
            }
        }

        usort($notifications, static function (array $left, array $right): int {
            $timestampOrder = strcmp($right['_sort_at'], $left['_sort_at']);
            return $timestampOrder !== 0
                ? $timestampOrder
                : strcmp($right['event_id'], $left['event_id']);
        });

        $unreadCount = count(array_filter(
            $notifications,
            static fn (array $notification): bool => $notification['is_read'] === false
        ));
        $hasMore = count($notifications) > self::NOTIFICATION_LIMIT;
        $notifications = array_slice($notifications, 0, self::NOTIFICATION_LIMIT);
        foreach ($notifications as &$notification) {
            unset($notification['_sort_at']);
        }
        unset($notification);

        return [
            'unread_count' => $unreadCount,
            'count' => count($notifications),
            'has_more' => $hasMore,
            'notifications' => array_values($notifications),
        ];
    }

    /** @return array{last_seen_at: string|null} */
    public function markNotificationsRead(CitizenIdentity $identity): array
    {
        $result = $this->store->rpc('mark_drrm_citizen_incident_notifications_read', [
            'p_reporter_reference' => $identity->reporterReference(),
        ]);
        if (array_is_list($result) && count($result) === 1 && is_array($result[0])) {
            $result = $result[0];
        }
        if (!is_array($result) || array_is_list($result)
            || !array_key_exists('last_seen_at', $result)) {
            throw new RuntimeException('The notification read state returned an unexpected structure.');
        }

        $lastSeenAt = $result['last_seen_at'] === null
            ? null
            : $this->timestamp($result['last_seen_at']);
        if ($result['last_seen_at'] !== null && $lastSeenAt === null) {
            throw new RuntimeException('The notification read state returned an invalid timestamp.');
        }

        return ['last_seen_at' => $lastSeenAt === null ? null : $this->formatTimestamp($lastSeenAt)];
    }

    private function incidentSelect(bool $includeDescription): string
    {
        $fields = [
            'id', 'incident_number', 'title', 'status', 'reported_at',
            'location_description', 'verification_status',
            'incident_type:drrm_incident_types!drrm_incidents_incident_type_id_fkey(code,label)',
            'severity:drrm_incident_severities!drrm_incidents_severity_id_fkey(code,label)',
            'barangay:barangays!drrm_incidents_barangay_id_fkey(name)',
        ];
        if ($includeDescription) {
            array_splice($fields, 3, 0, ['description']);
        }
        return implode(',', $fields);
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function normalizeIncident(array $record, bool $includeDescription): array
    {
        $incidentId = (string) ($record['id'] ?? '');
        $incidentNumber = (string) ($record['incident_number'] ?? '');
        $title = trim((string) ($record['title'] ?? ''));
        $status = (string) ($record['status'] ?? '');
        $location = trim((string) ($record['location_description'] ?? ''));
        $verificationStatus = (string) ($record['verification_status'] ?? '');
        $reportedAt = $this->timestamp($record['reported_at'] ?? null);
        $type = $this->relatedRecord($record['incident_type'] ?? null, false);
        $severity = $this->relatedRecord($record['severity'] ?? null, false);
        $barangay = $this->relatedRecord($record['barangay'] ?? null, true);

        if (!$this->isUuid($incidentId)
            || preg_match('/^INC-[0-9]{4}-[0-9]{6,}$/', $incidentNumber) !== 1
            || $title === '' || $location === '' || $reportedAt === null
            || !isset(self::STATUS_SEMANTICS[$status])
            || !in_array($verificationStatus, self::VERIFICATION_STATUSES, true)
            || !in_array($type['code'] ?? null, self::INCIDENT_TYPES, true)
            || !in_array($severity['code'] ?? null, self::SEVERITIES, true)) {
            throw new RuntimeException('The citizen incident record has an unexpected structure.');
        }

        $barangayName = $barangay === null ? null : trim((string) ($barangay['name'] ?? ''));
        if ($barangay !== null && $barangayName === '') {
            throw new RuntimeException('The citizen incident barangay has an unexpected structure.');
        }

        $semantics = self::STATUS_SEMANTICS[$status];
        $incident = [
            'incident_number' => $incidentNumber,
            'incident_type' => [
                'code' => (string) $type['code'],
                'label' => (string) ($type['label'] ?? $type['code']),
            ],
            'title' => $title,
            'barangay_name' => $barangayName,
            'location_description' => $location,
            'reported_at' => $this->formatTimestamp($reportedAt),
            'status' => $status,
            'status_label' => $semantics['label'],
            'status_message' => $semantics['message'],
            'severity' => [
                'code' => (string) $severity['code'],
                'label' => (string) ($severity['label'] ?? $severity['code']),
            ],
            'verification_status' => $verificationStatus,
        ];

        if ($includeDescription) {
            $description = trim((string) ($record['description'] ?? ''));
            if ($description === '') {
                throw new RuntimeException('The citizen incident description has an unexpected structure.');
            }
            $incident['description'] = $description;
        }

        return $incident;
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function normalizeTimelineEvent(array $record): array
    {
        $fromStatus = $record['from_status'] ?? null;
        $status = (string) ($record['to_status'] ?? '');
        $occurredAt = $this->timestamp($record['changed_at'] ?? null);
        if (($fromStatus !== null && !in_array($fromStatus, self::STATUSES, true))
            || !isset(self::STATUS_SEMANTICS[$status]) || $occurredAt === null) {
            throw new RuntimeException('The citizen incident timeline has an unexpected structure.');
        }

        $semantics = self::STATUS_SEMANTICS[$status];
        return [
            'from_status' => $fromStatus,
            'status' => $status,
            'status_label' => $semantics['label'],
            'message' => $semantics['message'],
            'occurred_at' => $this->formatTimestamp($occurredAt),
        ];
    }

    private function lastSeenAt(string $reporterReference): ?DateTimeImmutable
    {
        $records = $this->records($this->store->get(
            'drrm_citizen_incident_notification_state',
            [
                'select' => 'last_seen_at',
                'reporter_reference' => 'eq.' . $reporterReference,
                'limit' => 2,
            ]
        ));
        if (count($records) > 1) {
            throw new RuntimeException('The citizen notification state is not unique.');
        }
        if ($records === [] || ($records[0]['last_seen_at'] ?? null) === null) {
            return null;
        }

        $timestamp = $this->timestamp($records[0]['last_seen_at']);
        if ($timestamp === null) {
            throw new RuntimeException('The citizen notification state has an invalid timestamp.');
        }
        return $timestamp;
    }

    /** @return array<string, mixed>|null */
    private function relatedRecord(mixed $value, bool $nullable): ?array
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (is_array($value) && array_is_list($value)) {
            if ($value === [] && $nullable) {
                return null;
            }
            if (count($value) === 1 && is_array($value[0])) {
                $value = $value[0];
            }
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('The citizen incident relationship has an unexpected structure.');
        }
        return $value;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    /** @param array<mixed> $records @return list<array<string, mixed>> */
    private function records(array $records): array
    {
        if (!array_is_list($records)) {
            throw new RuntimeException('The citizen incident data source returned an unexpected collection.');
        }
        foreach ($records as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new RuntimeException('The citizen incident data source returned an unexpected record.');
            }
        }
        /** @var list<array<string, mixed>> $records */
        return array_values($records);
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}

final class DrrmCitizenIncidentNotFoundException extends RuntimeException
{
}

final class DrrmCitizenIncidentInvalidRequestException extends RuntimeException
{
}
