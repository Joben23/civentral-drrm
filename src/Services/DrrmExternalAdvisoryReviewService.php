<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/DrrmEarlyWarningWriteService.php';
require_once __DIR__ . '/SupabaseRestException.php';

/**
 * Administrative projection and terminal review boundary for staged external
 * advisories. Raw and normalized provider payload documents are never read.
 */
final class DrrmExternalAdvisoryReviewService
{
    public const REVIEW_STATUSES = ['PENDING_REVIEW', 'DISMISSED', 'DRAFT_CREATED'];

    private readonly DrrmEarlyWarningWriteService $warningWriter;

    public function __construct(
        private readonly DrrmDataStoreInterface $client,
        ?DrrmEarlyWarningWriteService $warningWriter = null
    ) {
        $this->warningWriter = $warningWriter ?? new DrrmEarlyWarningWriteService($client);
    }

    /** @return list<array<string, mixed>> */
    public function listAdvisories(string $status = 'PENDING_REVIEW', int $limit = 100): array
    {
        $status = strtoupper(trim($status));
        if (!in_array($status, self::REVIEW_STATUSES, true)) {
            throw new DrrmEarlyWarningValidationException('Invalid external advisory review status.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new DrrmEarlyWarningValidationException('Invalid external advisory list limit.');
        }

        $rows = $this->recordList($this->client->get('external_advisories', [
            'select' => implode(',', [
                'id', 'source_id', 'title', 'advisory_type', 'hazard_type', 'issued_at',
                'valid_until', 'first_fetched_at', 'fetched_at', 'payload_version',
                'review_status', 'linked_warning_id', 'reviewed_at',
                'reviewed_payload_version', 'created_at', 'updated_at',
            ]),
            'review_status' => 'eq.' . $status,
            'order' => 'fetched_at.desc,created_at.desc',
            'limit' => $limit,
        ]));

        return $this->normalizeAdvisories($rows);
    }

    /** @return array<string, mixed> */
    public function advisoryDetail(string $advisoryId): array
    {
        $advisoryId = $this->uuid($advisoryId, 'Invalid external advisory identifier.');
        $rows = $this->recordList($this->client->get('external_advisories', [
            'select' => implode(',', [
                'id', 'source_id', 'external_reference_id', 'source_reference', 'title',
                'advisory_type', 'hazard_type', 'summary', 'issued_at', 'valid_until',
                'first_fetched_at', 'fetched_at', 'first_fetch_run_id', 'last_fetch_run_id',
                'payload_version', 'review_status', 'linked_warning_id', 'reviewed_at',
                'reviewed_payload_version', 'created_at', 'updated_at',
            ]),
            'id' => 'eq.' . $advisoryId,
            'limit' => 2,
        ]));
        if (count($rows) !== 1) {
            throw new DrrmEarlyWarningLifecycleException('The staged external advisory could not be found.');
        }

        $normalized = $this->normalizeAdvisories($rows, true)[0];
        $firstRunId = $this->uuid(
            (string) ($rows[0]['first_fetch_run_id'] ?? ''),
            'Invalid first fetch provenance.'
        );
        $latestRunId = $this->uuid(
            (string) ($rows[0]['last_fetch_run_id'] ?? ''),
            'Invalid latest fetch provenance.'
        );
        $runIds = array_values(array_unique([$firstRunId, $latestRunId]));
        $runs = $this->recordList($this->client->get('external_advisory_fetch_runs', [
            'select' => implode(',', [
                'id', 'result', 'fetched_item_count', 'staged_item_count', 'new_item_count',
                'updated_item_count', 'unchanged_item_count', 'error_count', 'started_at', 'finished_at',
            ]),
            'id' => 'in.(' . implode(',', $runIds) . ')',
            'order' => 'started_at.asc',
            'limit' => 2,
        ]));
        $runsById = [];
        foreach ($runs as $run) {
            $runId = $this->uuid((string) ($run['id'] ?? ''), 'Invalid fetch provenance record.');
            $runsById[$runId] = $this->safeRun($run);
        }
        if (!isset($runsById[$firstRunId], $runsById[$latestRunId])) {
            throw new RuntimeException('External advisory fetch provenance is incomplete.');
        }

        $history = $this->recordList($this->client->get('external_advisory_review_history', [
            'select' => 'id,external_advisory_id,event_type,actor_reference,occurred_at,resulting_review_status,payload_version,linked_warning_id',
            'external_advisory_id' => 'eq.' . $advisoryId,
            'order' => 'occurred_at.desc',
            'limit' => 10,
        ]));
        $events = [];
        foreach ($history as $event) {
            if (($event['external_advisory_id'] ?? null) !== $advisoryId
                || !in_array($event['event_type'] ?? null, ['DISMISSED', 'DRAFT_CREATED'], true)
                || !in_array($event['resulting_review_status'] ?? null, ['DISMISSED', 'DRAFT_CREATED'], true)
                || !is_int($event['payload_version'] ?? null)
                || $event['payload_version'] < 1) {
                throw new RuntimeException('External advisory review history is malformed.');
            }
            $events[] = [
                'id' => (string) $event['id'],
                'event_type' => (string) $event['event_type'],
                'actor_reference' => (string) $event['actor_reference'],
                'occurred_at' => (string) $event['occurred_at'],
                'resulting_review_status' => (string) $event['resulting_review_status'],
                'payload_version' => (int) $event['payload_version'],
                'linked_warning_id' => $event['linked_warning_id'] === null
                    ? null
                    : (string) $event['linked_warning_id'],
            ];
        }

        $normalized['provenance'] = [
            'first_fetch' => $runsById[$firstRunId],
            'latest_fetch' => $runsById[$latestRunId],
        ];
        $normalized['review_history'] = $events;
        return $normalized;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function dismiss(array $input, string $actorReference): array
    {
        if (array_diff(array_keys($input), ['external_advisory_id', 'expected_payload_version']) !== []) {
            throw new DrrmEarlyWarningValidationException(
                'Unsupported external advisory review fields were supplied.'
            );
        }

        $result = $this->callRpc('dismiss_module4_external_advisory', [
            'p_external_advisory_id' => $this->uuid(
                $input['external_advisory_id'] ?? null,
                'Invalid external advisory identifier.'
            ),
            'p_expected_payload_version' => $this->positiveInteger(
                $input['expected_payload_version'] ?? null,
                'Invalid external advisory payload version.'
            ),
            'p_actor_reference' => $this->actorReference($actorReference),
        ]);

        return $this->reviewMutationResult($result, 'DISMISSED');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function convertToDraft(array $input, string $actorReference): array
    {
        return $this->warningWriter->createDraftFromExternalAdvisory($input, $actorReference);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function normalizeAdvisories(array $rows, bool $detail = false): array
    {
        if ($rows === []) {
            return [];
        }

        $sourceIds = [];
        foreach ($rows as $row) {
            $sourceIds[] = $this->uuid(
                (string) ($row['source_id'] ?? ''),
                'Invalid external advisory source.'
            );
        }
        $sourceIds = array_values(array_unique($sourceIds));
        $sources = $this->recordList($this->client->get('early_warning_sources', [
            'select' => 'id,source_code,source_name,source_type',
            'id' => 'in.(' . implode(',', $sourceIds) . ')',
            'order' => 'source_code.asc',
        ]));
        $sourcesById = [];
        foreach ($sources as $source) {
            $sourceId = $this->uuid(
                (string) ($source['id'] ?? ''),
                'Invalid external advisory source.'
            );
            $sourcesById[$sourceId] = [
                'code' => (string) ($source['source_code'] ?? ''),
                'name' => (string) ($source['source_name'] ?? ''),
                'type' => (string) ($source['source_type'] ?? ''),
            ];
        }

        $normalized = [];
        foreach ($rows as $row) {
            $id = $this->uuid((string) ($row['id'] ?? ''), 'Invalid external advisory record.');
            $sourceId = (string) $row['source_id'];
            $status = (string) ($row['review_status'] ?? '');
            $version = $row['payload_version'] ?? null;
            if (!isset($sourcesById[$sourceId])
                || !in_array($status, self::REVIEW_STATUSES, true)
                || !is_int($version)
                || $version < 1
                || trim((string) ($row['title'] ?? '')) === '') {
                throw new RuntimeException('An external advisory record is malformed.');
            }

            $item = [
                'id' => $id,
                'source' => $sourcesById[$sourceId],
                'title' => (string) $row['title'],
                'advisory_type' => $row['advisory_type'] === null ? null : (string) $row['advisory_type'],
                'hazard_type' => $row['hazard_type'] === null ? null : (string) $row['hazard_type'],
                'issued_at' => $row['issued_at'] === null ? null : (string) $row['issued_at'],
                'valid_until' => $row['valid_until'] === null ? null : (string) $row['valid_until'],
                'first_fetched_at' => (string) $row['first_fetched_at'],
                'fetched_at' => (string) $row['fetched_at'],
                'payload_version' => (int) $version,
                'review_status' => $status,
                'reviewed_at' => $row['reviewed_at'] === null ? null : (string) $row['reviewed_at'],
                'reviewed_payload_version' => $row['reviewed_payload_version'] === null
                    ? null
                    : (int) $row['reviewed_payload_version'],
                'linked_warning_id' => $row['linked_warning_id'] === null
                    ? null
                    : (string) $row['linked_warning_id'],
            ];
            if ($detail) {
                $item += [
                    'external_reference_id' => $row['external_reference_id'] === null
                        ? null
                        : (string) $row['external_reference_id'],
                    'source_reference' => $row['source_reference'] === null
                        ? null
                        : (string) $row['source_reference'],
                    'summary' => $row['summary'] === null ? null : (string) $row['summary'],
                ];
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private function safeRun(array $run): array
    {
        $result = (string) ($run['result'] ?? '');
        if (!in_array($result, ['SUCCESS', 'PARTIAL', 'FAILED', 'SKIPPED'], true)) {
            throw new RuntimeException('External advisory fetch provenance is malformed.');
        }
        return [
            'result' => $result,
            'fetched_item_count' => (int) ($run['fetched_item_count'] ?? 0),
            'staged_item_count' => (int) ($run['staged_item_count'] ?? 0),
            'new_item_count' => (int) ($run['new_item_count'] ?? 0),
            'updated_item_count' => (int) ($run['updated_item_count'] ?? 0),
            'unchanged_item_count' => (int) ($run['unchanged_item_count'] ?? 0),
            'error_count' => (int) ($run['error_count'] ?? 0),
            'started_at' => (string) ($run['started_at'] ?? ''),
            'finished_at' => (string) ($run['finished_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function callRpc(string $function, array $payload): array
    {
        try {
            $result = $this->client->rpc($function, $payload);
        } catch (SupabaseRestException $exception) {
            if (in_array($exception->sqlState(), ['22023', '23502', '23503', '23505', '23514'], true)) {
                throw new DrrmEarlyWarningValidationException(
                    'The external advisory review failed server-side validation.',
                    0,
                    $exception
                );
            }
            throw new DrrmEarlyWarningWriteException(
                'Unable to save external advisory review.',
                0,
                $exception
            );
        } catch (Throwable $exception) {
            throw new DrrmEarlyWarningWriteException(
                'Unable to save external advisory review.',
                0,
                $exception
            );
        }

        if (array_is_list($result)) {
            if (count($result) !== 1 || !is_array($result[0])) {
                throw new DrrmEarlyWarningWriteException(
                    'The external advisory review response was invalid.'
                );
            }
            $result = $result[0];
        }
        if (!is_array($result) || $result === []) {
            throw new DrrmEarlyWarningWriteException(
                'The external advisory review response was empty.'
            );
        }
        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function reviewMutationResult(array $result, string $expectedStatus): array
    {
        $outcome = (string) ($result['outcome'] ?? '');
        if (in_array($outcome, ['REVIEW_CONFLICT', 'PAYLOAD_VERSION_CONFLICT'], true)) {
            throw new DrrmEarlyWarningConflictException(
                'This advisory changed or was already reviewed. Refresh and review the latest record before trying again.'
            );
        }
        if ($outcome === 'NOT_FOUND') {
            throw new DrrmEarlyWarningLifecycleException(
                'The staged external advisory could not be found.'
            );
        }
        if ($outcome !== $expectedStatus || ($result['review_status'] ?? null) !== $expectedStatus) {
            throw new DrrmEarlyWarningWriteException(
                'The external advisory review was not completed transactionally.'
            );
        }
        return [
            'external_advisory_id' => $this->uuid(
                (string) ($result['external_advisory_id'] ?? ''),
                'Invalid external advisory review response.'
            ),
            'review_status' => $expectedStatus,
            'payload_version' => $this->positiveInteger(
                $result['payload_version'] ?? null,
                'Invalid external advisory review response.'
            ),
            'reviewed_at' => (string) ($result['reviewed_at'] ?? ''),
            'linked_warning_id' => null,
            'warning_created' => false,
            'warning_activated' => false,
        ];
    }

    private function uuid(mixed $value, string $message): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        return strtolower($value);
    }

    private function positiveInteger(mixed $value, string $message): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1) {
            throw new DrrmEarlyWarningValidationException($message);
        }
        return $value;
    }

    private function actorReference(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(?:USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,149}$/', $value) !== 1) {
            throw new DrrmEarlyWarningValidationException(
                'A trusted advisory reviewer could not be resolved.'
            );
        }
        return $value;
    }

    /** @param array<mixed> $rows @return list<array<string, mixed>> */
    private function recordList(array $rows): array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException(
                    'The external advisory data source returned malformed records.'
                );
            }
        }
        return array_values($rows);
    }
}
