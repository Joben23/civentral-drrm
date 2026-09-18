<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../src/Services/DrrmCitizenWarningNotificationService.php';
require_once __DIR__ . '/../src/Services/DrrmCitizenWarningNotificationRequest.php';

use App\Services\CitizenIdentity;
use App\Services\DrrmCitizenWarningNotificationNotEligibleException;
use App\Services\DrrmCitizenWarningNotificationRequest;
use App\Services\DrrmCitizenWarningNotificationService;
use App\Services\DrrmDataStoreInterface;

function expectNotification(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class NotificationTestStore implements DrrmDataStoreInterface
{
    public const SOURCE_ID = '10000000-0000-4000-8000-000000000004';
    /** @var array<string, array<string, mixed>> */
    public array $warnings = [];
    /** @var list<array<string, mixed>> */
    public array $events = [];
    /** @var array<string, string> */
    public array $reads = [];
    /** @var list<array<string, mixed>> */
    public array $rpcCalls = [];

    public function get(string $resource, array $query = []): array
    {
        if ($resource === 'early_warning_sources') return [[
            'id' => self::SOURCE_ID, 'source_code' => 'CIVENTRAL',
            'source_name' => 'CIVENTRAL DRRM', 'is_active' => true,
        ]];
        if ($resource === 'risk_levels') return [[
            'risk_level_id' => 1, 'code' => 'LOW', 'name' => 'Low',
            'severity_rank' => 1, 'is_active' => true,
        ]];
        if ($resource === 'early_warnings') {
            $rows = array_values($this->warnings);
            if (isset($query['id'])) {
                $ids = $this->ids((string) $query['id']);
                $rows = array_values(array_filter($rows,
                    static fn (array $row): bool => in_array($row['id'], $ids, true)));
            }
            if (($query['status'] ?? null) === 'eq.ACTIVE') {
                $rows = array_values(array_filter($rows,
                    static fn (array $row): bool => $row['status'] === 'ACTIVE'));
            }
            return $rows;
        }
        if ($resource === 'early_warning_areas') {
            $ids = $this->ids((string) ($query['warning_id'] ?? ''));
            return array_map(static fn (string $id): array => [
                'warning_id' => $id, 'scope_type' => 'CITY', 'barangay_id' => null,
                'area_name' => 'Caloocan City', 'created_at' => '2026-09-18T01:00:00+00:00',
            ], $ids);
        }
        if ($resource === 'early_warning_history') {
            $ids = $this->ids((string) ($query['warning_id'] ?? ''));
            $revision = (int) substr((string) ($query['resulting_revision'] ?? ''), 3);
            return array_values(array_filter($this->events, static fn (array $row): bool =>
                in_array($row['warning_id'], $ids, true)
                && $row['event_type'] === 'ACTIVATED'
                && $row['resulting_status'] === 'ACTIVE'
                && $row['resulting_revision'] === $revision));
        }
        if ($resource === 'module4_warning_notification_reads') {
            $citizen = substr((string) ($query['citizen_reference'] ?? ''), 3);
            $ids = $this->ids((string) ($query['activation_history_id'] ?? ''));
            $rows = [];
            foreach ($ids as $eventId) {
                $key = $citizen . '|' . $eventId;
                if (isset($this->reads[$key])) {
                    $rows[] = ['activation_history_id' => $eventId, 'read_at' => $this->reads[$key]];
                }
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected test resource: ' . $resource);
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('Direct table writes are forbidden.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        expectNotification($function === 'mark_module4_warning_notification_read', 'Unexpected RPC.');
        $this->rpcCalls[] = $payload;
        $eventId = (string) $payload['p_activation_history_id'];
        $citizen = (string) $payload['p_citizen_reference'];
        $event = current(array_values(array_filter($this->events, static fn (array $row): bool => $row['id'] === $eventId)));
        if (!is_array($event) || $event['event_type'] !== 'ACTIVATED'
            || $event['resulting_status'] !== 'ACTIVE'
            || ($this->warnings[$event['warning_id']]['status'] ?? null) !== 'ACTIVE'
            || ($this->warnings[$event['warning_id']]['revision'] ?? null) !== $event['resulting_revision']) {
            return ['outcome' => 'NOT_ELIGIBLE'];
        }
        $key = $citizen . '|' . $eventId;
        $alreadyRead = isset($this->reads[$key]);
        $this->reads[$key] ??= '2026-09-18T02:30:00+00:00';
        return [
            'outcome' => $alreadyRead ? 'ALREADY_READ' : 'MARKED_READ',
            'notification_event_id' => $eventId,
            'read_at' => $this->reads[$key],
        ];
    }

    /** @return list<string> */
    private function ids(string $filter): array
    {
        return str_starts_with($filter, 'in.(')
            ? explode(',', substr($filter, 4, -1)) : [];
    }
}

$store = new NotificationTestStore();
$asOf = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$past = $asOf->modify('-1 hour')->format(DATE_ATOM);
$expired = $asOf->modify('-30 minutes')->format(DATE_ATOM);
$future = $asOf->modify('+1 hour')->format(DATE_ATOM);
$later = $asOf->modify('+2 hours')->format(DATE_ATOM);
$conditions = [
    'active' => ['ACTIVE', $past, $future],
    'open_end' => ['ACTIVE', $past, null],
    'draft' => ['DRAFT', $past, null],
    'future' => ['ACTIVE', $future, $later],
    'expired' => ['ACTIVE', $past, $expired],
    'cancelled' => ['CANCELLED', $past, null],
    'archived' => ['ARCHIVED', $past, null],
    'wrong_revision' => ['ACTIVE', $past, $future],
    'created_only' => ['ACTIVE', $past, $future],
    'updated_only' => ['ACTIVE', $past, $future],
    'cancel_history_only' => ['ACTIVE', $past, $future],
];
$eventByName = [];
$sequence = 1;
foreach ($conditions as $name => [$status, $issuedAt, $validUntil]) {
    $warningId = sprintf('10000000-0000-4000-8000-%012d', $sequence);
    $eventId = sprintf('20000000-0000-4000-8000-%012d', $sequence++);
    $eventByName[$name] = $eventId;
    $store->warnings[$warningId] = [
        'id' => $warningId, 'source_id' => NotificationTestStore::SOURCE_ID,
        'title' => 'City flood warning ' . $name, 'hazard_type' => 'FLOOD',
        'warning_level_id' => 1, 'summary' => 'Seek higher ground.',
        'status' => $status, 'revision' => 2,
        'issued_at' => $issuedAt, 'valid_until' => $validUntil,
        'source_reference' => 'internal-only', 'updated_at' => $past,
        'raw_payload' => 'unsafe payload', 'actor_reference' => 'EMPLOYEE:42',
    ];
    $store->events[] = [
        'id' => $eventId, 'warning_id' => $warningId,
        'event_type' => match ($name) {
            'created_only' => 'CREATED',
            'updated_only' => 'DRAFT_UPDATED',
            'cancel_history_only' => 'CANCELLED',
            default => 'ACTIVATED',
        },
        'occurred_at' => $past,
        'resulting_status' => match ($name) {
            'created_only', 'updated_only' => 'DRAFT',
            'cancel_history_only' => 'CANCELLED',
            default => 'ACTIVE',
        },
        'resulting_revision' => $name === 'wrong_revision' ? 3 : 2,
        'actor_reference' => 'EMPLOYEE:42',
    ];
}
$olderActivation = $store->events[0];
$olderActivation['id'] = '30000000-0000-4000-8000-000000000001';
$olderActivation['resulting_revision'] = 3;
$store->events[] = $olderActivation;

$service = new DrrmCitizenWarningNotificationService($store);
$alice = new CitizenIdentity(123);
$bob = new CitizenIdentity(456);
$feed = $service->feed($alice, $asOf);
expectNotification($feed['count'] === 2 && $feed['unread_count'] === 2, 'Active event eligibility is wrong.');
$visibleIds = array_column($feed['notifications'], 'notification_event_id');
expectNotification(in_array($eventByName['active'], $visibleIds, true), 'Future-valid warning is missing.');
expectNotification(in_array($eventByName['open_end'], $visibleIds, true), 'Open-ended warning is missing.');
foreach (['draft', 'future', 'expired', 'cancelled', 'archived',
    'wrong_revision', 'created_only', 'updated_only', 'cancel_history_only'] as $name) {
    expectNotification(!in_array($eventByName[$name], $visibleIds, true), $name . ' warning leaked into feed.');
}
expectNotification(!in_array($olderActivation['id'], $visibleIds, true),
    'A wrong-revision activation duplicated an otherwise valid warning.');
$duplicateActivation = $store->events[0];
$duplicateActivation['id'] = '30000000-0000-4000-8000-000000000002';
$store->events[] = $duplicateActivation;
$duplicateRejected = false;
try {
    $service->feed($alice, $asOf);
} catch (RuntimeException) {
    $duplicateRejected = true;
}
array_pop($store->events);
expectNotification($duplicateRejected,
    'Duplicate matching activations did not fail closed.');
$encoded = json_encode($feed, JSON_THROW_ON_ERROR);
foreach (['actor_reference', 'raw_payload', 'reviewed_payload_hash', 'provider_config', 'EMPLOYEE:42'] as $forbidden) {
    expectNotification(!str_contains($encoded, $forbidden), 'Unsafe field leaked: ' . $forbidden);
}
expectNotification($feed['notifications'][0]['scope'] === 'CITY', 'Warning area was not projected.');

$first = $service->markRead($alice, $eventByName['active']);
expectNotification($first['already_read'] === false && $first['is_read'] === true, 'Initial mark-read failed.');
$second = $service->markRead($alice, $eventByName['active']);
expectNotification($second['already_read'] === true && $second['read_at'] === $first['read_at'], 'Repeat mark-read was not idempotent.');
expectNotification(count($store->reads) === 1, 'Duplicate read rows were created.');
expectNotification(($store->rpcCalls[0]['p_citizen_reference'] ?? null) === 'CITIZEN:123', 'Trusted citizen reference was not used.');
expectNotification($service->feed($bob, $asOf)['unread_count'] === 2, 'One citizen read another citizen state.');
expectNotification($service->feed($alice, $asOf)['unread_count'] === 1, 'Read state did not appear in feed.');
expectNotification(count($store->rpcCalls) === 2, 'Unexpected read RPC count.');

$invalidMarkRejected = false;
try {
    $service->markRead($alice, $eventByName['expired']);
} catch (DrrmCitizenWarningNotificationNotEligibleException) {
    $invalidMarkRejected = true;
}
expectNotification($invalidMarkRejected, 'An expired event was markable.');

$minimal = json_encode(['notification_event_id' => $eventByName['active']], JSON_THROW_ON_ERROR);
expectNotification(
    DrrmCitizenWarningNotificationRequest::eventId($minimal) === $eventByName['active'],
    'The minimal request was rejected.'
);
$uppercase = json_encode(['notification_event_id' => strtoupper($eventByName['active'])], JSON_THROW_ON_ERROR);
expectNotification(DrrmCitizenWarningNotificationRequest::eventId($uppercase) === $eventByName['active'],
    'A valid uppercase event ID was not normalized.');
foreach (['citizen_reference', 'actor_reference', 'url', 'payload', 'warning_id'] as $forbidden) {
    $rejected = false;
    try {
        $withExtra = json_encode([
            'notification_event_id' => $eventByName['active'], $forbidden => 'x',
        ], JSON_THROW_ON_ERROR);
        DrrmCitizenWarningNotificationRequest::eventId($withExtra);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    expectNotification($rejected, 'Request accepted client-selected ' . $forbidden . '.');
}
$readWarningId = $store->events[0]['warning_id'];
$store->warnings[$readWarningId]['status'] = 'CANCELLED';
expectNotification($service->feed($alice, $asOf)['count'] === 1,
    'A cancelled warning remained in the active notification feed.');
expectNotification(count($store->reads) === 1,
    'Cancellation erased the historical read receipt.');
echo 'Module 4 citizen warning notification feed/read state: OK' . PHP_EOL;
