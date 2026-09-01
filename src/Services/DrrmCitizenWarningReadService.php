<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmBarangayCatalogService.php';

/**
 * Public-safe, read-only projection of human-activated CIVENTRAL warnings.
 *
 * This is intentionally separate from the administrative read service. All
 * table fields, lifecycle filters, lookup values, and Caloocan area rules are
 * defined here; callers cannot supply PostgREST filters.
 */
final class DrrmCitizenWarningReadService
{
    public const CITY_NAME = 'Caloocan City';
    public const BARANGAY_DATASET_VERSION_ID = DrrmBarangayCatalogService::LEGACY_DRAFT_DATASET_VERSION_ID;

    private const MAX_ACTIVE_WARNINGS = 100;
    private const AREA_QUERY_WARNING_CHUNK_SIZE = 5;
    private const BARANGAY_QUERY_CHUNK_SIZE = 100;

    /** @var array<string, string> */
    private const HAZARD_LABELS = [
        'FLOOD' => 'Flood',
        'HEAVY_RAINFALL' => 'Heavy Rainfall',
        'TROPICAL_CYCLONE' => 'Tropical Cyclone',
        'LANDSLIDE' => 'Landslide',
        'EARTHQUAKE' => 'Earthquake',
        'VOLCANIC_ACTIVITY' => 'Volcanic Activity',
        'OTHER' => 'Other',
    ];

    /** @var list<string> */
    private const WARNING_LEVELS = ['LOW', 'MODERATE', 'HIGH', 'CRITICAL'];

    /** @var list<string> */
    private const SOURCE_CODES = ['PAGASA', 'PHIVOLCS', 'NDRRMC', 'CIVENTRAL'];

    public function __construct(private readonly SupabaseRestClient $client)
    {
    }

    /**
     * @return array{
     *     city: string,
     *     warning_level_scale: string,
     *     data_as_of: string,
     *     active_warning_count: int,
     *     warnings: list<array<string, mixed>>
     * }
     */
    public function activeWarnings(?DateTimeImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));

        $warningRows = $this->records($this->client->get('early_warnings', [
            'select' => 'id,source_id,title,hazard_type,warning_level_id,summary,status,issued_at,valid_until,source_reference,updated_at',
            'status' => 'eq.ACTIVE',
            'or' => '(valid_until.is.null,valid_until.gt.' . $asOf->format('Y-m-d\TH:i:sP') . ')',
            'order' => 'issued_at.desc',
            'limit' => self::MAX_ACTIVE_WARNINGS + 1,
        ]));

        if (count($warningRows) > self::MAX_ACTIVE_WARNINGS) {
            throw new RuntimeException('The active-warning result exceeds the supported public response size.');
        }

        if ($warningRows === []) {
            return $this->response($asOf, []);
        }

        $sources = $this->sourcesById();
        $riskLevels = $this->riskLevelsById();
        $warningIds = [];

        foreach ($warningRows as $row) {
            $id = (string) ($row['id'] ?? '');
            if (!$this->isUuid($id)) {
                continue;
            }
            $warningIds[] = $id;
        }

        $areasByWarning = $this->areasByWarningId($warningIds);
        $barangays = $this->validatedBarangaysById($areasByWarning);
        $warnings = [];

        foreach ($warningRows as $row) {
            $warning = $this->normalizeWarning($row, $sources, $riskLevels, $areasByWarning, $barangays, $asOf);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        return $this->response($asOf, $warnings);
    }

    /**
     * @param list<array<string, mixed>> $warnings
     * @return array<string, mixed>
     */
    private function response(DateTimeImmutable $asOf, array $warnings): array
    {
        return [
            'city' => self::CITY_NAME,
            'warning_level_scale' => 'CIVENTRAL Warning Level',
            'data_as_of' => $asOf->format('Y-m-d\TH:i:sP'),
            'active_warning_count' => count($warnings),
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function sourcesById(): array
    {
        $rows = $this->records($this->client->get('early_warning_sources', [
            'select' => 'id,source_code,source_name,is_active',
            'is_active' => 'eq.true',
            'order' => 'source_code.asc',
        ]));
        $sources = [];

        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $code = (string) ($row['source_code'] ?? '');
            $name = trim((string) ($row['source_name'] ?? ''));

            if ($this->isUuid($id) && in_array($code, self::SOURCE_CODES, true)
                && $name !== '' && ($row['is_active'] ?? null) === true) {
                $sources[$id] = ['code' => $code, 'name' => $name];
            }
        }

        return $sources;
    }

    /** @return array<int, array{code: string, label: string}> */
    private function riskLevelsById(): array
    {
        $rows = $this->records($this->client->get('risk_levels', [
            'select' => 'risk_level_id,code,name,is_active',
            'code' => 'in.(' . implode(',', self::WARNING_LEVELS) . ')',
            'is_active' => 'eq.true',
            'order' => 'severity_rank.asc',
        ]));
        $levels = [];

        foreach ($rows as $row) {
            $id = $row['risk_level_id'] ?? null;
            $code = (string) ($row['code'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));

            if ((is_int($id) || (is_string($id) && ctype_digit($id)))
                && in_array($code, self::WARNING_LEVELS, true)
                && $name !== '' && ($row['is_active'] ?? null) === true) {
                $levels[(int) $id] = ['code' => $code, 'label' => $name];
            }
        }

        return $levels;
    }

    /**
     * @param list<string> $warningIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function areasByWarningId(array $warningIds): array
    {
        $areas = [];

        foreach (array_chunk($warningIds, self::AREA_QUERY_WARNING_CHUNK_SIZE) as $chunk) {
            $rows = $this->records($this->client->get('early_warning_areas', [
                'select' => 'warning_id,scope_type,barangay_id,area_name,created_at',
                'warning_id' => 'in.(' . implode(',', $chunk) . ')',
                'order' => 'created_at.asc',
                'limit' => (187 * self::AREA_QUERY_WARNING_CHUNK_SIZE) + 1,
            ]));

            if (count($rows) > 187 * self::AREA_QUERY_WARNING_CHUNK_SIZE) {
                throw new RuntimeException('The affected-area result exceeds the supported public response size.');
            }

            foreach ($rows as $row) {
                $warningId = (string) ($row['warning_id'] ?? '');
                if ($this->isUuid($warningId) && in_array($warningId, $chunk, true)) {
                    $areas[$warningId][] = $row;
                }
            }
        }

        return $areas;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $areasByWarning
     * @return array<string, string>
     */
    private function validatedBarangaysById(array $areasByWarning): array
    {
        $ids = [];

        foreach ($areasByWarning as $areas) {
            foreach ($areas as $area) {
                if (($area['scope_type'] ?? null) === 'BARANGAY') {
                    $id = (string) ($area['barangay_id'] ?? '');
                    if ($this->isUuid($id)) {
                        $ids[$id] = true;
                    }
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $barangays = [];
        foreach (array_chunk(array_keys($ids), self::BARANGAY_QUERY_CHUNK_SIZE) as $chunk) {
            $rows = (new DrrmBarangayCatalogService($this->client))
                ->historicalBarangaysById($chunk);

            foreach ($rows as $row) {
                $id = (string) ($row['barangay_id'] ?? '');
                $name = trim((string) ($row['name'] ?? ''));

                if ($this->isUuid($id) && isset($ids[$id]) && $this->isValidatedBarangayName($name)) {
                    $barangays[$id] = $name;
                }
            }
        }

        return $barangays;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $sources
     * @param array<int, array{code: string, label: string}> $riskLevels
     * @param array<string, list<array<string, mixed>>> $areasByWarning
     * @param array<string, string> $barangays
     * @return array<string, mixed>|null
     */
    private function normalizeWarning(
        array $row,
        array $sources,
        array $riskLevels,
        array $areasByWarning,
        array $barangays,
        DateTimeImmutable $asOf
    ): ?array {
        $id = (string) ($row['id'] ?? '');
        $source = $sources[(string) ($row['source_id'] ?? '')] ?? null;
        $riskLevelId = $row['warning_level_id'] ?? null;
        $riskLevel = (is_int($riskLevelId) || (is_string($riskLevelId) && ctype_digit($riskLevelId)))
            ? ($riskLevels[(int) $riskLevelId] ?? null)
            : null;
        $title = trim((string) ($row['title'] ?? ''));
        $summary = trim((string) ($row['summary'] ?? ''));
        $hazardType = (string) ($row['hazard_type'] ?? '');

        if (!$this->isUuid($id) || ($row['status'] ?? null) !== 'ACTIVE'
            || !is_array($source) || !is_array($riskLevel)
            || $title === '' || $summary === '' || !isset(self::HAZARD_LABELS[$hazardType])) {
            return null;
        }

        $issuedAt = $this->timestamp($row['issued_at'] ?? null);
        $validUntil = ($row['valid_until'] ?? null) === null
            ? null
            : $this->timestamp($row['valid_until']);
        $updatedAt = $this->timestamp($row['updated_at'] ?? null);

        if ($issuedAt === null || $updatedAt === null
            || (($row['valid_until'] ?? null) !== null && $validUntil === null)
            || ($validUntil !== null && $validUntil <= $asOf)) {
            return null;
        }

        $areaProjection = $this->normalizeAreas($areasByWarning[$id] ?? [], $barangays);
        if ($areaProjection === null) {
            return null;
        }

        return [
            'id' => $id,
            'title' => $title,
            'hazard_type' => $hazardType,
            'hazard_label' => self::HAZARD_LABELS[$hazardType],
            'warning_level' => [
                'code' => $riskLevel['code'],
                'label' => $riskLevel['label'],
                'scale' => 'CIVENTRAL Warning Level',
            ],
            'summary' => $summary,
            'issued_at' => $issuedAt->format('Y-m-d\TH:i:sP'),
            'valid_until' => $validUntil?->format('Y-m-d\TH:i:sP'),
            'source' => [
                'code' => $source['code'],
                'name' => $source['name'],
            ],
            'source_reference' => $this->publicSourceReference(
                (string) $source['code'],
                $row['source_reference'] ?? null
            ),
            'scope' => $areaProjection['scope'],
            'affected_areas' => $areaProjection['areas'],
            'last_updated' => $updatedAt->format('Y-m-d\TH:i:sP'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $areas
     * @param array<string, string> $barangays
     * @return array{scope: string, areas: list<array{scope: string, name: string}>}|null
     */
    private function normalizeAreas(array $areas, array $barangays): ?array
    {
        if ($areas === []) {
            return null;
        }

        if (count($areas) === 1 && ($areas[0]['scope_type'] ?? null) === 'CITY'
            && ($areas[0]['barangay_id'] ?? null) === null
            && trim((string) ($areas[0]['area_name'] ?? '')) === self::CITY_NAME) {
            return [
                'scope' => 'CITY',
                'areas' => [['scope' => 'CITY', 'name' => self::CITY_NAME]],
            ];
        }

        $result = [];
        $seen = [];

        foreach ($areas as $area) {
            if (($area['scope_type'] ?? null) !== 'BARANGAY') {
                return null;
            }

            $barangayId = (string) ($area['barangay_id'] ?? '');
            $storedName = trim((string) ($area['area_name'] ?? ''));
            $validatedName = $barangays[$barangayId] ?? null;

            if ($validatedName === null || $storedName !== $validatedName || isset($seen[$barangayId])) {
                return null;
            }

            $seen[$barangayId] = true;
            $result[] = ['scope' => 'BARANGAY', 'name' => $validatedName];
        }

        usort($result, static function (array $left, array $right): int {
            return strnatcasecmp($left['name'], $right['name']);
        });

        return ['scope' => 'BARANGAY', 'areas' => $result];
    }

    private function publicSourceReference(string $sourceCode, mixed $value): ?string
    {
        if ($sourceCode === 'CIVENTRAL' || !is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || strlen($value) > 1000 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return null;
        }

        $allowedSuffixes = match ($sourceCode) {
            'PAGASA' => ['pagasa.dost.gov.ph'],
            'PHIVOLCS' => ['phivolcs.dost.gov.ph'],
            'NDRRMC' => ['ndrrmc.gov.ph'],
            default => [],
        };

        foreach ($allowedSuffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return $value;
            }
        }

        return null;
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

    private function isValidatedBarangayName(string $name): bool
    {
        return preg_match('/^Barangay (?:[1-9]|[1-9]\d|1\d\d)(?:-[A-F])?$/', $name) === 1
            && $name !== 'Barangay 176';
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    /**
     * @param array<mixed> $records
     * @return list<array<string, mixed>>
     */
    private function records(array $records): array
    {
        foreach ($records as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('The public warning data source returned an unexpected structure.');
            }
        }

        /** @var list<array<string, mixed>> $records */
        return array_values($records);
    }
}
