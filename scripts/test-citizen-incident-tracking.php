<?php

declare(strict_types=1);

use App\Services\CitizenIdentity;
use App\Services\DrrmCitizenIncidentNotFoundException;
use App\Services\DrrmCitizenIncidentTrackingService;
use App\Services\DrrmDataStoreInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/CitizenSessionIdentityVerifier.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenIncidentTrackingService.php';

final class CitizenTrackingTestStore implements DrrmDataStoreInterface
{
    /** @var list<array{resource: string, query: array<string, scalar>}> */
    public array $getCalls = [];
    /** @var list<array{function: string, payload: array<string, mixed>}> */
    public array $rpcCalls = [];
    public int $postCalls = 0;

    private const OWNED_ID = '11111111-1111-4111-8111-111111111111';
    private const OTHER_ID = '22222222-2222-4222-8222-222222222222';

    /** @return array<string, mixed> */
    private function incident(bool $details): array
    {
        $record = [
            'id' => self::OWNED_ID,
            'incident_number' => 'INC-2026-000100',
            'incident_type' => ['code' => 'FLOOD', 'label' => 'Flood'],
            'title' => 'Controlled resolved citizen incident',
            'status' => 'RESOLVED',
            'reported_at' => '2026-08-25T10:00:00+00:00',
            'location_description' => 'Controlled test location',
            'verification_status' => 'VERIFIED',
            'severity' => ['code' => 'HIGH', 'label' => 'High'],
            'barangay' => ['name' => 'Barangay 1'],
            'assigned_department_reference' => 'DEPARTMENT:9',
            'assigned_user_reference' => 'USER:77',
            'admin_notes' => 'Must never be projected',
            'response_logs' => [['message' => 'Must never be projected']],
        ];
        if ($details) {
            $record['description'] = 'A controlled description safe for the owning citizen.';
        }
        return $record;
    }

    public function get(string $resource, array $query = []): array
    {
        $this->getCalls[] = ['resource' => $resource, 'query' => $query];
        if ($resource === 'drrm_citizen_incident_notification_state') {
            return ($query['reporter_reference'] ?? null) === 'eq.CITIZEN:42'
                ? [['last_seen_at' => '2026-08-25T10:03:00+00:00']]
                : [];
        }
        if ($resource === 'drrm_incidents') {
            $reporter = $query['reporter_reference'] ?? null;
            if ($reporter !== 'eq.CITIZEN:42'
                || ($query['reporter_type'] ?? null) !== 'eq.CITIZEN') {
                return [];
            }
            if (isset($query['incident_number'])) {
                return $query['incident_number'] === 'eq.INC-2026-000100'
                    ? [$this->incident(true)]
                    : [];
            }
            if (($query['select'] ?? null) === 'id,incident_number,title') {
                return [[
                    'id' => self::OWNED_ID,
                    'incident_number' => 'INC-2026-000100',
                    'title' => 'Controlled resolved citizen incident',
                ]];
            }
            return [$this->incident(false)];
        }
        if ($resource === 'drrm_incident_status_history') {
            if (($query['select'] ?? null) === 'from_status,to_status,changed_at') {
                if (($query['incident_id'] ?? null) !== 'eq.' . self::OWNED_ID) {
                    return [];
                }
                return [
                    [
                        'from_status' => 'UNDER_REVIEW',
                        'to_status' => 'VERIFIED',
                        'changed_at' => '2026-08-25T10:04:00+00:00',
                        'changed_by_reference' => 'USER:77',
                        'notes' => 'Internal verification note',
                    ],
                    [
                        'from_status' => null,
                        'to_status' => 'SUBMITTED',
                        'changed_at' => '2026-08-25T10:00:00+00:00',
                        'changed_by_reference' => 'CITIZEN:42',
                    ],
                    [
                        'from_status' => 'SUBMITTED',
                        'to_status' => 'UNDER_REVIEW',
                        'changed_at' => '2026-08-25T10:02:00+00:00',
                        'changed_by_reference' => 'USER:77',
                    ],
                    [
                        'from_status' => 'VERIFIED',
                        'to_status' => 'ASSIGNED',
                        'changed_at' => '2026-08-25T10:05:00+00:00',
                    ],
                    [
                        'from_status' => 'ASSIGNED',
                        'to_status' => 'RESPONDING',
                        'changed_at' => '2026-08-25T10:06:00+00:00',
                    ],
                    [
                        'from_status' => 'RESPONDING',
                        'to_status' => 'RESOLVED',
                        'changed_at' => '2026-08-25T10:07:00+00:00',
                    ],
                ];
            }
            if (!str_contains((string) ($query['to_status'] ?? ''), 'UNDER_REVIEW')
                || str_contains((string) ($query['to_status'] ?? ''), 'SUBMITTED')
                || !str_contains((string) ($query['incident_id'] ?? ''), self::OWNED_ID)) {
                throw new RuntimeException('Unsafe notification history query.');
            }
            $statuses = [
                ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', 'UNDER_REVIEW', '2026-08-25T10:02:00+00:00'],
                ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'VERIFIED', '2026-08-25T10:04:00+00:00'],
                ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa3', 'ASSIGNED', '2026-08-25T10:05:00+00:00'],
                ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa4', 'RESPONDING', '2026-08-25T10:06:00+00:00'],
                ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa5', 'RESOLVED', '2026-08-25T10:07:00+00:00'],
            ];
            return array_map(static fn (array $event): array => [
                'id' => $event[0],
                'incident_id' => self::OWNED_ID,
                'to_status' => $event[1],
                'changed_at' => $event[2],
                'changed_by_reference' => 'USER:77',
                'notes' => 'Internal note',
            ], $statuses);
        }
        throw new RuntimeException('Unexpected resource: ' . $resource);
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        $this->postCalls++;
        throw new RuntimeException('Citizen tracking tests cannot create records.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        $this->rpcCalls[] = ['function' => $function, 'payload' => $payload];
        if ($function !== 'mark_drrm_citizen_incident_notifications_read') {
            throw new RuntimeException('Unexpected RPC: ' . $function);
        }
        return ['last_seen_at' => '2026-08-25T10:07:00+00:00'];
    }
}

$failures = [];

function citizenTrackingAssert(string $name, bool $condition): void
{
    global $failures;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

/** @return list<string> */
function citizenTrackingKeys(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        $keys = array_merge($keys, citizenTrackingKeys($child));
    }
    return array_values(array_unique($keys));
}

$store = new CitizenTrackingTestStore();
$service = new DrrmCitizenIncidentTrackingService($store);
$identity = new CitizenIdentity(42);

$list = $service->myIncidents($identity);
citizenTrackingAssert(
    'AuthenticatedCitizenSeesOwnedIncidents',
    $list['count'] === 1
        && $list['incidents'][0]['incident_number'] === 'INC-2026-000100'
);
citizenTrackingAssert(
    'CitizenDoesNotSeeAnotherReporterIncidents',
    $service->myIncidents(new CitizenIdentity(99))['incidents'] === []
);

$details = $service->incidentDetails('INC-2026-000100', $identity);
citizenTrackingAssert(
    'OwnedIncidentDetailsReturned',
    $details['incident_number'] === 'INC-2026-000100'
        && $details['description'] === 'A controlled description safe for the owning citizen.'
);

$otherNotFound = false;
try {
    $service->incidentDetails('INC-2026-000101', $identity);
} catch (DrrmCitizenIncidentNotFoundException) {
    $otherNotFound = true;
}
citizenTrackingAssert('DetailOwnershipEnforced', $otherNotFound);
citizenTrackingAssert('SequentialIncidentNumberCannotBypassOwnership', $otherNotFound);

$timelineStatuses = array_column($details['timeline'], 'status');
citizenTrackingAssert('StatusTimelineOrderedOldestFirst', $timelineStatuses === [
    'SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'ASSIGNED', 'RESPONDING', 'RESOLVED',
]);
citizenTrackingAssert(
    'SubmittedIncludedInCitizenTimeline',
    ($details['timeline'][0]['message'] ?? null)
        === 'Your report was received and is waiting for DRRM review.'
);

$forbiddenKeys = [
    'id', 'incident_id', 'reporter_reference', 'assigned_user_reference',
    'assigned_department_reference', 'changed_by_reference', 'admin_notes',
    'notes', 'response_logs', 'verified_by_reference', 'created_by_reference',
];
$detailKeys = citizenTrackingKeys($details);
citizenTrackingAssert(
    'InternalAssignmentReferencesAbsent',
    !in_array('assigned_user_reference', $detailKeys, true)
        && !in_array('assigned_department_reference', $detailKeys, true)
);
citizenTrackingAssert(
    'ActorReferencesAbsent',
    !in_array('changed_by_reference', $detailKeys, true)
        && !in_array('verified_by_reference', $detailKeys, true)
        && !in_array('created_by_reference', $detailKeys, true)
);
citizenTrackingAssert('AdminNotesAbsent', !in_array('admin_notes', $detailKeys, true));
citizenTrackingAssert('ResponseLogsAbsent', !in_array('response_logs', $detailKeys, true));
citizenTrackingAssert(
    'NoInternalUuidProjected',
    !in_array('id', $detailKeys, true) && !in_array('incident_id', $detailKeys, true)
);
citizenTrackingAssert(
    'CitizenProjectionContainsOnlyAllowedKeys',
    array_intersect($forbiddenKeys, $detailKeys) === []
);

$feed = $service->notifications($identity);
citizenTrackingAssert(
    'NotificationFeedDerivedFromStatusHistory',
    $feed['count'] === 5
        && array_column($feed['notifications'], 'status') === [
            'RESOLVED', 'RESPONDING', 'ASSIGNED', 'VERIFIED', 'UNDER_REVIEW',
        ]
);
citizenTrackingAssert(
    'InitialSubmittedExcludedFromNotificationFeed',
    !in_array('SUBMITTED', array_column($feed['notifications'], 'status'), true)
);
citizenTrackingAssert('UnreadCountUsesLastSeenWatermark', $feed['unread_count'] === 4);
citizenTrackingAssert(
    'PerEventReadStateIsReal',
    $feed['notifications'][4]['is_read'] === true
        && count(array_filter(
            $feed['notifications'],
            static fn (array $event): bool => $event['is_read'] === false
        )) === 4
);
citizenTrackingAssert(
    'CitizenFriendlyNotificationMessageReturned',
    $feed['notifications'][0]['message']
        === 'The incident has been marked as resolved.'
);
citizenTrackingAssert(
    'NotificationEventIdentifierIsOpaque',
    str_starts_with($feed['notifications'][0]['event_id'], 'inc_evt_')
        && preg_match(
            '/^[0-9a-f-]{36}$/',
            $feed['notifications'][0]['event_id']
        ) !== 1
);

$feedKeys = citizenTrackingKeys($feed);
citizenTrackingAssert(
    'NotificationFeedOmitsInternalFields',
    array_intersect($forbiddenKeys, $feedKeys) === []
);

$marked = $service->markNotificationsRead($identity);
$rpc = $store->rpcCalls[0] ?? [];
citizenTrackingAssert(
    'MarkReadUsesAtomicServerRpc',
    ($rpc['function'] ?? null)
        === 'mark_drrm_citizen_incident_notifications_read'
);
citizenTrackingAssert(
    'ClientCannotChooseReporterReference',
    array_keys($rpc['payload'] ?? []) === ['p_reporter_reference']
        && ($rpc['payload']['p_reporter_reference'] ?? null) === 'CITIZEN:42'
);
citizenTrackingAssert(
    'MarkReadReturnsDatabaseWatermark',
    $marked['last_seen_at'] === '2026-08-25T10:07:00+00:00'
);
citizenTrackingAssert('NoOperationalIncidentCreated', $store->postCalls === 0);

$unsafeSelectUsed = false;
foreach ($store->getCalls as $call) {
    $select = (string) ($call['query']['select'] ?? '');
    foreach ([
        'assigned_user_reference', 'assigned_department_reference',
        'changed_by_reference', 'notes', 'response_logs',
    ] as $forbiddenSelect) {
        if (str_contains($select, $forbiddenSelect)) {
            $unsafeSelectUsed = true;
        }
    }
}
citizenTrackingAssert('FixedFieldQueriesExcludeInternalColumns', !$unsafeSelectUsed);

if ($failures !== []) {
    fwrite(
        STDERR,
        'Citizen incident tracking failures: ' . implode(', ', $failures) . PHP_EOL
    );
    exit(1);
}

echo 'CitizenIncidentTrackingService=PASS' . PHP_EOL;
