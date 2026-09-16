<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/DrrmEarlyWarningLifecyclePolicy.php';

/**
 * Read-only projection of DRRM Module 4 data for the early-warning dashboard.
 *
 * Browser input is never forwarded to PostgREST. All selected fields, filters,
 * ordering, and limits are fixed by this service.
 */
final class DrrmEarlyWarningReadService
{
    /** @var list<array<string, mixed>>|null */
    private ?array $sourceRows = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $riskLevelRows = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $activeWarningRows = null;

    private readonly DateTimeImmutable $asOf;

    public function __construct(
        private readonly DrrmDataStoreInterface $client,
        ?DateTimeImmutable $asOf = null
    ) {
        $this->asOf = DrrmEarlyWarningLifecyclePolicy::asUtc($asOf);
    }

    /**
     * @return array{
     *     metrics: array{
     *         active_warnings: int,
     *         high_risk_areas: int,
     *         weather_advisories: int,
     *         alerts_sent_today: int
     *     },
     *     metric_metadata: array<string, array{implemented: bool, definition: string}>,
     *     current_warning: array<string, mixed>|null,
     *     recent_warnings: list<array<string, mixed>>,
     *     sources: list<array<string, mixed>>
     * }
     */
    public function dashboardSummary(): array
    {
        return [
            'metrics' => [
                'active_warnings' => $this->activeWarningCount(),
                'high_risk_areas' => $this->highRiskActiveAreaCount(),
                'weather_advisories' => $this->weatherAdvisoryCount(),
                'alerts_sent_today' => $this->alertsSentToday(),
            ],
            'metric_metadata' => [
                'active_warnings' => [
                    'implemented' => true,
                    'definition' => 'Stored ACTIVE warnings already issued and not expired as of the server UTC time.',
                ],
                'high_risk_areas' => [
                    'implemented' => true,
                    'definition' => 'Affected-area records attached to effectively active HIGH or CRITICAL warnings.',
                ],
                'weather_advisories' => [
                    'implemented' => true,
                    'definition' => 'Effectively active warning records attributed to the PAGASA source.',
                ],
                'alerts_sent_today' => [
                    'implemented' => false,
                    'definition' => 'Alert delivery tracking is not implemented yet.',
                ],
            ],
            'current_warning' => $this->currentActiveWarning(),
            'recent_warnings' => $this->recentWarnings(),
            'sources' => $this->sourceStatuses(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sourceStatuses(): array
    {
        $sources = [];

        foreach ($this->sources() as $source) {
            $sources[] = [
                'source_code' => (string) $source['source_code'],
                'source_name' => (string) $source['source_name'],
                'source_type' => (string) $source['source_type'],
                'integration_status' => (string) $source['integration_status'],
                'is_active' => (bool) $source['is_active'],
            ];
        }

        return $sources;
    }

    public function activeWarningCount(): int
    {
        return count($this->activeWarnings());
    }

    public function highRiskActiveAreaCount(): int
    {
        $highRiskLevelIds = [];

        foreach ($this->riskLevels() as $riskLevel) {
            if (in_array($riskLevel['code'], ['HIGH', 'CRITICAL'], true)) {
                $highRiskLevelIds[] = (int) $riskLevel['risk_level_id'];
            }
        }

        if (count($highRiskLevelIds) !== 2) {
            throw new RuntimeException('The required HIGH and CRITICAL risk levels are unavailable.');
        }

        $warningIds = [];

        foreach ($this->activeWarnings() as $warning) {
            if (in_array((int) $warning['warning_level_id'], $highRiskLevelIds, true)) {
                $warningIds[] = (string) $warning['id'];
            }
        }

        return count($this->areasForWarningIds($warningIds));
    }

    public function weatherAdvisoryCount(): int
    {
        $pagasaSourceId = null;

        foreach ($this->sources() as $source) {
            if ($source['source_code'] === 'PAGASA') {
                $pagasaSourceId = (string) $source['id'];
                break;
            }
        }

        if ($pagasaSourceId === null) {
            throw new RuntimeException('The PAGASA early-warning source is unavailable.');
        }

        return count(array_filter(
            $this->activeWarnings(),
            static fn (array $warning): bool => $warning['source_id'] === $pagasaSourceId
        ));
    }

    public function alertsSentToday(): int
    {
        // Delivery tracking is intentionally absent in Phase 3.
        return 0;
    }

    /** @return array<string, mixed>|null */
    public function currentActiveWarning(): ?array
    {
        $warnings = $this->activeWarnings();

        if ($warnings === []) {
            return null;
        }

        return $this->normalizeWarnings([$warnings[0]])[0];
    }

    /** @return list<array<string, mixed>> */
    public function recentWarnings(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new RuntimeException('The recent warning limit is outside the supported range.');
        }

        $rows = $this->assertRecordList($this->client->get('early_warnings', [
            'select' => 'id,source_id,title,hazard_type,warning_level_id,summary,status,issued_at,valid_until,source_reference,revision,updated_at',
            'order' => 'issued_at.desc',
            'limit' => $limit,
        ]));

        return $this->normalizeWarnings($rows);
    }

    /** @return list<array<string, mixed>> */
    public function warningHistory(string $warningId, int $limit = 100): array
    {
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $warningId
        ) !== 1) {
            throw new RuntimeException('Invalid warning identifier.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('The warning-history limit is outside the supported range.');
        }

        $rows = $this->assertRecordList($this->client->get('early_warning_history', [
            'select' => 'id,warning_id,event_type,actor_reference,occurred_at,resulting_status,resulting_revision,details',
            'warning_id' => 'eq.' . $warningId,
            'order' => 'occurred_at.desc,resulting_revision.desc',
            'limit' => $limit,
        ]));

        foreach ($rows as $row) {
            if (($row['warning_id'] ?? null) !== $warningId
                || !isset($row['id'], $row['event_type'], $row['actor_reference'], $row['occurred_at'], $row['resulting_status'], $row['resulting_revision'])
                || !in_array($row['event_type'], ['CREATED', 'DRAFT_UPDATED', 'ACTIVATED', 'CANCELLED'], true)
                || !is_array($row['details'] ?? null)) {
                throw new RuntimeException('A warning-history record has an unexpected structure.');
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function sources(): array
    {
        if ($this->sourceRows !== null) {
            return $this->sourceRows;
        }

        $rows = $this->assertRecordList($this->client->get('early_warning_sources', [
            'select' => 'id,source_code,source_name,source_type,integration_status,is_active',
            'order' => 'source_code.asc',
        ]));

        foreach ($rows as $row) {
            if (
                !isset($row['id'], $row['source_code'], $row['source_name'], $row['source_type'], $row['integration_status'])
                || !is_bool($row['is_active'] ?? null)
                || !in_array($row['integration_status'], [
                    'CONNECTED', 'PARTIAL', 'PENDING', 'UNAVAILABLE', 'DISABLED',
                ], true)
            ) {
                throw new RuntimeException('An early-warning source record has an unexpected structure.');
            }
        }

        return $this->sourceRows = $rows;
    }

    /** @return list<array<string, mixed>> */
    private function riskLevels(): array
    {
        if ($this->riskLevelRows !== null) {
            return $this->riskLevelRows;
        }

        $rows = $this->assertRecordList($this->client->get('risk_levels', [
            'select' => 'risk_level_id,code,name,severity_rank',
            'is_active' => 'eq.true',
            'order' => 'severity_rank.asc',
        ]));

        foreach ($rows as $row) {
            if (!isset($row['risk_level_id'], $row['code'], $row['name'], $row['severity_rank'])) {
                throw new RuntimeException('A risk-level record has an unexpected structure.');
            }
        }

        return $this->riskLevelRows = $rows;
    }

    /** @return list<array<string, mixed>> */
    private function activeWarnings(): array
    {
        if ($this->activeWarningRows !== null) {
            return $this->activeWarningRows;
        }

        $rows = $this->assertRecordList($this->client->get('early_warnings', [
            'select' => 'id,source_id,title,hazard_type,warning_level_id,summary,status,issued_at,valid_until,source_reference,revision,updated_at',
            'status' => 'eq.ACTIVE',
            'issued_at' => 'lte.' . $this->asOf->format('Y-m-d\TH:i:sP'),
            'or' => '(valid_until.is.null,valid_until.gt.' . $this->asOf->format('Y-m-d\TH:i:sP') . ')',
            'order' => 'issued_at.desc',
        ]));

        $activeRows = [];
        foreach ($rows as $row) {
            if (
                !isset($row['id'], $row['source_id'], $row['title'], $row['hazard_type'], $row['warning_level_id'], $row['summary'], $row['issued_at'])
                || ($row['status'] ?? null) !== 'ACTIVE'
            ) {
                throw new RuntimeException('An active early-warning record has an unexpected structure.');
            }

            // Enforce the fixed query rule again at the projection boundary.
            if (DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive($row, $this->asOf)) {
                $activeRows[] = $row;
            }
        }

        return $this->activeWarningRows = $activeRows;
    }

    /**
     * @param list<array<string, mixed>> $warnings
     * @return list<array<string, mixed>>
     */
    private function normalizeWarnings(array $warnings): array
    {
        if ($warnings === []) {
            return [];
        }

        $sourcesById = [];
        foreach ($this->sources() as $source) {
            $sourcesById[(string) $source['id']] = $source;
        }

        $riskLevelsById = [];
        foreach ($this->riskLevels() as $riskLevel) {
            $riskLevelsById[(int) $riskLevel['risk_level_id']] = $riskLevel;
        }

        $warningIds = array_values(array_map(
            static fn (array $warning): string => (string) ($warning['id'] ?? ''),
            $warnings
        ));
        $areasByWarningId = [];

        foreach ($this->areasForWarningIds($warningIds) as $area) {
            $warningId = (string) $area['warning_id'];
            $areasByWarningId[$warningId][] = [
                'scope_type' => (string) $area['scope_type'],
                'barangay_id' => $area['barangay_id'] === null ? null : (string) $area['barangay_id'],
                'area_name' => (string) $area['area_name'],
            ];
        }

        $normalized = [];

        foreach ($warnings as $warning) {
            $warningId = (string) ($warning['id'] ?? '');
            $source = $sourcesById[(string) ($warning['source_id'] ?? '')] ?? null;
            $riskLevel = $riskLevelsById[(int) ($warning['warning_level_id'] ?? 0)] ?? null;

            if (
                $warningId === ''
                || !is_array($source)
                || !is_array($riskLevel)
                || !isset(
                    $warning['title'],
                    $warning['hazard_type'],
                    $warning['summary'],
                    $warning['status'],
                    $warning['issued_at'],
                    $warning['revision'],
                    $warning['updated_at']
                )
                || (int) $warning['revision'] < 1
            ) {
                throw new RuntimeException('An early-warning record has an unexpected structure.');
            }

            $storedStatus = (string) $warning['status'];
            $normalized[] = [
                'id' => $warningId,
                'title' => (string) $warning['title'],
                'hazard_type' => (string) $warning['hazard_type'],
                'warning_level' => [
                    'code' => (string) $riskLevel['code'],
                    'name' => (string) $riskLevel['name'],
                ],
                'summary' => (string) $warning['summary'],
                // Preserve the persisted lifecycle value while making display
                // semantics explicit for expired stored-ACTIVE rows.
                'status' => $storedStatus,
                'stored_status' => $storedStatus,
                'effective_status' => DrrmEarlyWarningLifecyclePolicy::effectiveStatus(
                    $warning,
                    $this->asOf
                ),
                'is_effectively_active' => DrrmEarlyWarningLifecyclePolicy::isEffectivelyActive(
                    $warning,
                    $this->asOf
                ),
                'issued_at' => (string) $warning['issued_at'],
                'valid_until' => $warning['valid_until'] === null ? null : (string) $warning['valid_until'],
                'revision' => (int) $warning['revision'],
                'updated_at' => (string) $warning['updated_at'],
                'source_reference' => $warning['source_reference'] === null
                    ? null
                    : (string) $warning['source_reference'],
                'source' => [
                    'code' => (string) $source['source_code'],
                    'name' => (string) $source['source_name'],
                ],
                'affected_areas' => $areasByWarningId[$warningId] ?? [],
            ];
        }

        return $normalized;
    }

    /**
     * @param list<string> $warningIds
     * @return list<array<string, mixed>>
     */
    private function areasForWarningIds(array $warningIds): array
    {
        $warningIds = array_values(array_unique(array_filter(
            $warningIds,
            static fn (string $warningId): bool => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $warningId
            ) === 1
        )));

        if ($warningIds === []) {
            return [];
        }

        $rows = $this->assertRecordList($this->client->get('early_warning_areas', [
            'select' => 'warning_id,scope_type,barangay_id,area_name',
            'warning_id' => 'in.(' . implode(',', $warningIds) . ')',
            'order' => 'created_at.asc',
        ]));

        foreach ($rows as $row) {
            if (!isset($row['warning_id'], $row['scope_type'], $row['area_name'])
                || !array_key_exists('barangay_id', $row)) {
                throw new RuntimeException('An early-warning affected-area record has an unexpected structure.');
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed> $records
     * @return list<array<string, mixed>>
     */
    private function assertRecordList(array $records): array
    {
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('The early-warning data source returned an unexpected record structure.');
            }
        }

        /** @var list<array<string, mixed>> $records */
        return array_values($records);
    }
}
