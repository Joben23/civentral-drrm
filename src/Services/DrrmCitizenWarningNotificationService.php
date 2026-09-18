<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

require_once __DIR__ . '/CitizenSessionIdentityVerifier.php';
require_once __DIR__ . '/DrrmCitizenWarningReadService.php';
require_once __DIR__ . '/DrrmEarlyWarningLifecyclePolicy.php';

final class DrrmCitizenWarningNotificationNotEligibleException extends RuntimeException
{
}

/**
 * Authenticated pull-only in-app feed. ACTIVATED history supplies event identity;
 * the existing public warning projection supplies every visibility decision.
 */
final class DrrmCitizenWarningNotificationService
{
    private const HISTORY_CHUNK_SIZE = 10;
    private const MAX_EVENTS = 100;

    public function __construct(private readonly DrrmDataStoreInterface $store)
    {
    }

    /** @return array<string, mixed> */
    public function feed(CitizenIdentity $identity, ?DateTimeImmutable $asOf = null): array
    {
        $public = (new DrrmCitizenWarningReadService($this->store))->activeWarnings($asOf);
        $warnings = $public['warnings'];
        $byWarning = [];
        foreach ($warnings as $warning) {
            $id = (string) ($warning['id'] ?? '');
            if (!self::isUuid($id) || isset($byWarning[$id])) {
                throw new RuntimeException('The public warning projection is inconsistent.');
            }
            $byWarning[$id] = $warning;
        }

        // Revision is fetched privately; the public warning API never exposes it.
        // ACTIVE definitions are immutable under Phase 4A, but this second read
        // also closes a cancellation/expiry window before selecting events.
        $projectionTime = DrrmEarlyWarningLifecyclePolicy::parseTimestamp($public['data_as_of'] ?? null);
        if ($projectionTime === null) {
            throw new RuntimeException('The public warning projection has no valid timestamp.');
        }
        $currentRevisions = [];
        foreach (array_chunk(array_keys($byWarning), self::HISTORY_CHUNK_SIZE) as $chunk) {
            $rows = $this->records($this->store->get('early_warnings', [
                'select' => 'id,revision,status,issued_at,valid_until',
                'id' => 'in.(' . implode(',', $chunk) . ')',
                'status' => 'eq.ACTIVE',
                'issued_at' => 'lte.' . $public['data_as_of'],
                'or' => '(valid_until.is.null,valid_until.gt.' . $public['data_as_of'] . ')',
                'limit' => count($chunk) + 1,
            ]));
            if (count($rows) > count($chunk)) {
                throw new RuntimeException('Too many current warning revisions were returned.');
            }
            foreach ($rows as $row) {
                $warningId = (string) ($row['id'] ?? '');
                if (!isset($byWarning[$warningId]) || isset($currentRevisions[$warningId])) {
                    throw new RuntimeException('A current warning revision has an unexpected structure.');
                }
                $revision = $row['revision'] ?? null;
                if (is_int($revision) && $revision > 0
                    && DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive($row, $projectionTime)) {
                    $currentRevisions[$warningId] = $revision;
                }
            }
        }

        $warningIdsByRevision = [];
        foreach ($currentRevisions as $warningId => $revision) {
            $warningIdsByRevision[$revision][] = $warningId;
        }

        $events = [];
        $eventByWarning = [];
        foreach ($warningIdsByRevision as $revision => $warningIds) {
            foreach (array_chunk($warningIds, self::HISTORY_CHUNK_SIZE) as $chunk) {
                $rows = $this->records($this->store->get('early_warning_history', [
                    'select' => 'id,warning_id,event_type,occurred_at,resulting_status,resulting_revision',
                    'warning_id' => 'in.(' . implode(',', $chunk) . ')',
                    'event_type' => 'eq.ACTIVATED',
                    'resulting_status' => 'eq.ACTIVE',
                    'resulting_revision' => 'eq.' . $revision,
                    'order' => 'occurred_at.desc,id.desc',
                    'limit' => count($chunk) + 1,
                ]));
                if (count($rows) > count($chunk)) {
                    throw new RuntimeException('Too many activation events were returned.');
                }
                foreach ($rows as $row) {
                    $eventId = (string) ($row['id'] ?? '');
                    $warningId = (string) ($row['warning_id'] ?? '');
                    $occurredAt = DrrmEarlyWarningLifecyclePolicy::parseTimestamp($row['occurred_at'] ?? null);
                    if (!self::isUuid($eventId) || !in_array($warningId, $chunk, true)
                        || isset($events[$eventId])
                        || isset($eventByWarning[$warningId])
                        || ($row['event_type'] ?? null) !== 'ACTIVATED'
                        || ($row['resulting_status'] ?? null) !== 'ACTIVE'
                        || !is_int($row['resulting_revision'] ?? null)
                        || $row['resulting_revision'] !== $currentRevisions[$warningId]
                        || $occurredAt === null) {
                        throw new RuntimeException('An activation event has an unexpected structure.');
                    }
                    $eventByWarning[$warningId] = $eventId;
                    $events[$eventId] = [
                        'warning' => $byWarning[$warningId],
                        'occurred_at' => $occurredAt,
                    ];
                }
            }
        }
        if (count($events) > self::MAX_EVENTS) {
            throw new RuntimeException('The active warning feed exceeds its safe event limit.');
        }

        $reads = [];
        foreach (array_chunk(array_keys($events), self::HISTORY_CHUNK_SIZE) as $chunk) {
            $rows = $this->records($this->store->get('module4_warning_notification_reads', [
                'select' => 'activation_history_id,read_at',
                'citizen_reference' => 'eq.' . $identity->reporterReference(),
                'activation_history_id' => 'in.(' . implode(',', $chunk) . ')',
                'limit' => count($chunk) + 1,
            ]));
            if (count($rows) > count($chunk)) {
                throw new RuntimeException('Too many warning read receipts were returned.');
            }
            foreach ($rows as $row) {
                $eventId = (string) ($row['activation_history_id'] ?? '');
                $readAt = DrrmEarlyWarningLifecyclePolicy::parseTimestamp($row['read_at'] ?? null);
                if (!isset($events[$eventId]) || isset($reads[$eventId]) || $readAt === null) {
                    throw new RuntimeException('A warning read receipt has an unexpected structure.');
                }
                $reads[$eventId] = $readAt->format('Y-m-d\TH:i:sP');
            }
        }

        $notifications = [];
        foreach ($events as $eventId => $event) {
            $warning = $event['warning'];
            $notifications[] = [
                'notification_event_id' => $eventId,
                'warning_id' => $warning['id'],
                'title' => $warning['title'],
                'hazard_type' => $warning['hazard_type'],
                'hazard_label' => $warning['hazard_label'],
                'warning_level' => $warning['warning_level'],
                'summary' => $warning['summary'],
                'issued_at' => $warning['issued_at'],
                'valid_until' => $warning['valid_until'],
                'activated_at' => $event['occurred_at']->format('Y-m-d\TH:i:sP'),
                'source' => $warning['source'],
                'source_reference' => $warning['source_reference'],
                'scope' => $warning['scope'],
                'affected_areas' => $warning['affected_areas'],
                'is_read' => isset($reads[$eventId]),
                'read_at' => $reads[$eventId] ?? null,
            ];
        }
        usort($notifications, static function (array $left, array $right): int {
            $byTime = strcmp($right['activated_at'], $left['activated_at']);
            return $byTime !== 0
                ? $byTime
                : strcmp($right['notification_event_id'], $left['notification_event_id']);
        });

        return [
            'city' => $public['city'],
            'data_as_of' => $public['data_as_of'],
            'count' => count($notifications),
            'unread_count' => count(array_filter(
                $notifications,
                static fn (array $item): bool => !$item['is_read']
            )),
            'notifications' => $notifications,
        ];
    }

    /** @return array{notification_event_id: string, is_read: true, read_at: string, already_read: bool} */
    public function markRead(CitizenIdentity $identity, string $eventId): array
    {
        if (!self::isUuid($eventId)) {
            throw new DrrmCitizenWarningNotificationNotEligibleException('The warning notification is unavailable.');
        }
        $eventId = strtolower($eventId);
        $eligible = false;
        foreach ($this->feed($identity)['notifications'] as $item) {
            if ($item['notification_event_id'] === $eventId) {
                $eligible = true;
                break;
            }
        }
        if (!$eligible) {
            throw new DrrmCitizenWarningNotificationNotEligibleException('The warning notification is unavailable.');
        }

        $result = $this->store->rpc('mark_module4_warning_notification_read', [
            'p_activation_history_id' => $eventId,
            'p_citizen_reference' => $identity->reporterReference(),
        ]);
        if (array_is_list($result) && count($result) === 1 && is_array($result[0])) {
            $result = $result[0];
        }
        $outcome = is_array($result) ? ($result['outcome'] ?? null) : null;
        if ($outcome === 'NOT_ELIGIBLE') {
            throw new DrrmCitizenWarningNotificationNotEligibleException('The warning notification is unavailable.');
        }
        $readAt = is_array($result)
            ? DrrmEarlyWarningLifecyclePolicy::parseTimestamp($result['read_at'] ?? null)
            : null;
        if (!in_array($outcome, ['MARKED_READ', 'ALREADY_READ'], true)
            || ($result['notification_event_id'] ?? null) !== $eventId
            || $readAt === null) {
            throw new RuntimeException('The warning read receipt response is invalid.');
        }
        return [
            'notification_event_id' => $eventId,
            'is_read' => true,
            'read_at' => $readAt->format('Y-m-d\TH:i:sP'),
            'already_read' => $outcome === 'ALREADY_READ',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function records(array $rows): array
    {
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new RuntimeException('The warning notification data source is invalid.');
            }
        }
        return array_values($rows);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
