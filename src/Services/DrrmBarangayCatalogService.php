<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

require_once __DIR__ . '/DrrmDataStoreInterface.php';

/**
 * Separates the catalog used for new Module 3/4 writes from historical lookup.
 *
 * The checked-in PSA PSGC evidence defines the current catalog as exactly 193
 * rows: Barangays 1-175, 177-188, and 176-A through 176-F with their official
 * ten-digit codes. The controlled 187-row draft remains the compatibility
 * write catalog until one governed published version contains that exact set.
 */
final class DrrmBarangayCatalogService
{
    public const LEGACY_DRAFT_DATASET_VERSION_ID = 'b386cd54-2288-423f-9b92-2092333333c1';
    public const LEGACY_DRAFT_COUNT = 187;
    public const CURRENT_OPERATIONAL_COUNT = 193;

    public function __construct(private readonly DrrmDataStoreInterface $store)
    {
    }

    /** @return list<array{barangay_id: string, barangay_code: string, name: string}> */
    public function availableBarangays(): array
    {
        $published = $this->completePublishedOperationalCatalog();
        if ($published !== null) {
            return $published['barangays'];
        }
        return $this->legacyCompatibilityCatalog();
    }

    public function currentPublishedOperationalVersionId(): ?string
    {
        $published = $this->completePublishedOperationalCatalog();
        return $published['dataset_version_id'] ?? null;
    }

    /**
     * Resolve only against the one catalog currently eligible for NEW writes.
     * A call can therefore never mix legacy IDs with successor IDs.
     *
     * @param list<string> $barangayIds
     * @return list<array{barangay_id: string, barangay_code: string, name: string}>
     */
    public function writeEligibleBarangaysById(array $barangayIds): array
    {
        $uniqueIds = $this->validatedUniqueIds($barangayIds);
        if ($uniqueIds === null || $uniqueIds === []) {
            return [];
        }

        $byId = [];
        foreach ($this->availableBarangays() as $barangay) {
            $byId[$barangay['barangay_id']] = $barangay;
        }
        $result = [];
        foreach ($barangayIds as $barangayId) {
            if (!isset($byId[$barangayId])) {
                return [];
            }
            $result[] = $byId[$barangayId];
        }
        return $result;
    }

    /**
     * Resolve existing references without making them eligible for a new write.
     * Legacy compatibility rows and governed PUBLISHED/ARCHIVED catalog rows may
     * coexist in historical results.
     *
     * @param list<string> $barangayIds
     * @return list<array{barangay_id: string, barangay_code: string, name: string}>
     */
    public function historicalBarangaysById(array $barangayIds): array
    {
        $uniqueIds = $this->validatedUniqueIds($barangayIds);
        if ($uniqueIds === null || $uniqueIds === []) {
            return [];
        }

        $records = $this->records($this->store->get('barangays', [
            'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status',
            'barangay_id' => count($uniqueIds) === 1
                ? 'eq.' . array_key_first($uniqueIds)
                : 'in.(' . implode(',', array_keys($uniqueIds)) . ')',
            'limit' => count($uniqueIds) + 1,
        ]));
        if (count($records) !== count($uniqueIds)) {
            return [];
        }

        $eligible = [];
        $versionIds = [];
        foreach ($records as $record) {
            $id = (string) ($record['barangay_id'] ?? '');
            $versionId = (string) ($record['boundary_dataset_version_id'] ?? '');
            $status = (string) ($record['record_status'] ?? '');
            if (!isset($uniqueIds[$id]) || !$this->isUuid($versionId)) {
                return [];
            }
            if ($versionId === self::LEGACY_DRAFT_DATASET_VERSION_ID
                && $status === 'INACTIVE') {
                $eligible[$id] = $record;
                continue;
            }
            if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                return [];
            }
            $versionIds[$versionId] = true;
        }

        if ($versionIds !== []) {
            $versions = $this->historicalVersionsById(array_keys($versionIds));
            if (count($versions) !== count($versionIds)) {
                return [];
            }
            foreach ($records as $record) {
                $id = (string) ($record['barangay_id'] ?? '');
                if (!isset($eligible[$id])) {
                    $versionId = (string) ($record['boundary_dataset_version_id'] ?? '');
                    if (!isset($versions[$versionId])) {
                        return [];
                    }
                    $eligible[$id] = $record;
                }
            }
        }

        $result = [];
        foreach ($barangayIds as $barangayId) {
            if (!isset($eligible[$barangayId])) {
                return [];
            }
            $result[] = $this->normalizeRecord($eligible[$barangayId]);
        }
        return $result;
    }

    /**
     * @return array{
     *   dataset_version_id: string,
     *   barangays: list<array{barangay_id: string, barangay_code: string, name: string}>
     * }|null
     */
    private function completePublishedOperationalCatalog(): ?array
    {
        $versions = $this->records($this->store->get('dataset_versions', [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_reference,publication_date,effective_from,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
            'dataset_category' => 'eq.BARANGAY_BOUNDARY',
            'review_status' => 'eq.PUBLISHED',
            'order' => 'published_at.desc',
            'limit' => 2,
        ]));
        if ($versions === []) {
            return null;
        }
        if (count($versions) !== 1) {
            throw new RuntimeException('The published barangay catalog is ambiguous.');
        }
        $version = $versions[0];
        if (!$this->isGovernedBoundaryVersion($version, ['PUBLISHED'])) {
            return null;
        }
        $sourceId = (string) $version['dataset_source_id'];
        if (!isset($this->activeSources([$sourceId])[$sourceId])) {
            return null;
        }

        $versionId = (string) $version['dataset_version_id'];
        $records = $this->records($this->store->get('barangays', [
            'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status',
            'boundary_dataset_version_id' => 'eq.' . $versionId,
            'record_status' => 'eq.ACTIVE',
            'boundary_geometry' => 'not.is.null',
            'order' => 'barangay_code.asc',
            'limit' => self::CURRENT_OPERATIONAL_COUNT + 1,
        ]));
        $normalized = $this->normalizeCatalogOrNull(
            $records,
            $versionId,
            'ACTIVE',
            $this->expectedCurrentCatalog()
        );
        if ($normalized === null) {
            // A partial PUBLISHED successor does not displace compatibility.
            return null;
        }
        return ['dataset_version_id' => $versionId, 'barangays' => $normalized];
    }

    /** @return list<array{barangay_id: string, barangay_code: string, name: string}> */
    private function legacyCompatibilityCatalog(): array
    {
        $records = $this->records($this->store->get('barangays', [
            'select' => 'barangay_id,barangay_code,name,boundary_dataset_version_id,record_status',
            'boundary_dataset_version_id' => 'eq.' . self::LEGACY_DRAFT_DATASET_VERSION_ID,
            'record_status' => 'eq.INACTIVE',
            'boundary_geometry' => 'not.is.null',
            'order' => 'barangay_code.asc',
            'limit' => self::LEGACY_DRAFT_COUNT + 1,
        ]));
        $normalized = $this->normalizeCatalogOrNull(
            $records,
            self::LEGACY_DRAFT_DATASET_VERSION_ID,
            'INACTIVE',
            $this->expectedLegacyCatalog()
        );
        if ($normalized === null) {
            throw new RuntimeException('The validated barangay compatibility catalog is unavailable.');
        }
        return $normalized;
    }

    /** @param list<string> $versionIds @return array<string, true> */
    private function historicalVersionsById(array $versionIds): array
    {
        $versions = $this->records($this->store->get('dataset_versions', [
            'select' => 'dataset_version_id,dataset_source_id,dataset_category,hazard_type_id,source_reference,publication_date,effective_from,license,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
            'dataset_version_id' => 'in.(' . implode(',', $versionIds) . ')',
            'dataset_category' => 'eq.BARANGAY_BOUNDARY',
            'limit' => count($versionIds) + 1,
        ]));
        $eligible = [];
        $publishedSources = [];
        foreach ($versions as $version) {
            if (!$this->isGovernedBoundaryVersion($version, ['PUBLISHED', 'ARCHIVED'])) {
                continue;
            }
            $versionId = (string) $version['dataset_version_id'];
            $eligible[$versionId] = true;
            if (($version['review_status'] ?? null) === 'PUBLISHED') {
                $publishedSources[(string) $version['dataset_source_id']] = $versionId;
            }
        }
        if ($publishedSources !== []) {
            $activeSources = $this->activeSources(array_keys($publishedSources));
            foreach ($publishedSources as $sourceId => $versionId) {
                if (!isset($activeSources[$sourceId])) {
                    unset($eligible[$versionId]);
                }
            }
        }
        return $eligible;
    }

    /** @param array<string, mixed> $version @param list<string> $statuses */
    private function isGovernedBoundaryVersion(array $version, array $statuses): bool
    {
        return $this->isUuid((string) ($version['dataset_version_id'] ?? ''))
            && $this->isUuid((string) ($version['dataset_source_id'] ?? ''))
            && ($version['dataset_category'] ?? null) === 'BARANGAY_BOUNDARY'
            && ($version['hazard_type_id'] ?? null) === null
            && in_array($version['review_status'] ?? null, $statuses, true)
            && $this->nonEmptyString($version['source_reference'] ?? null)
            && $this->nonEmptyString($version['license'] ?? null)
            && $this->nonEmptyString($version['reviewed_by_civentral_user_id'] ?? null)
            && $this->nonEmptyString($version['publication_date'] ?? null)
            && $this->nonEmptyString($version['effective_from'] ?? null)
            && $this->nonEmptyString($version['reviewed_at'] ?? null)
            && $this->nonEmptyString($version['published_at'] ?? null);
    }

    /** @param list<string> $sourceIds @return array<string, true> */
    private function activeSources(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }
        $sources = $this->records($this->store->get('dataset_sources', [
            'select' => 'dataset_source_id,record_status',
            'dataset_source_id' => 'in.(' . implode(',', $sourceIds) . ')',
            'record_status' => 'eq.ACTIVE',
            'limit' => count($sourceIds) + 1,
        ]));
        $result = [];
        foreach ($sources as $source) {
            $sourceId = (string) ($source['dataset_source_id'] ?? '');
            if ($this->isUuid($sourceId) && ($source['record_status'] ?? null) === 'ACTIVE') {
                $result[$sourceId] = true;
            }
        }
        return $result;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, string> $expected code to name
     * @return list<array{barangay_id: string, barangay_code: string, name: string}>|null
     */
    private function normalizeCatalogOrNull(
        array $records,
        string $versionId,
        string $status,
        array $expected
    ): ?array {
        if (count($records) !== count($expected)) {
            return null;
        }
        $result = [];
        $seenIds = [];
        $seenCodes = [];
        $seenNames = [];
        try {
            foreach ($records as $record) {
                if (($record['boundary_dataset_version_id'] ?? null) !== $versionId
                    || ($record['record_status'] ?? null) !== $status) {
                    return null;
                }
                $normalized = $this->normalizeRecord($record);
                if (($expected[$normalized['barangay_code']] ?? null) !== $normalized['name']
                    || isset($seenIds[$normalized['barangay_id']])
                    || isset($seenCodes[$normalized['barangay_code']])
                    || isset($seenNames[$normalized['name']])) {
                    return null;
                }
                $seenIds[$normalized['barangay_id']] = true;
                $seenCodes[$normalized['barangay_code']] = true;
                $seenNames[$normalized['name']] = true;
                $result[] = $normalized;
            }
        } catch (RuntimeException) {
            return null;
        }
        return count($seenCodes) === count($expected) ? $result : null;
    }

    /** @return array<string, string> */
    private function expectedLegacyCatalog(): array
    {
        $expected = [];
        for ($number = 1; $number <= 188; $number++) {
            if ($number !== 176) {
                $expected[sprintf('13801%05d', $number)] = 'Barangay ' . $number;
            }
        }
        return $expected;
    }

    /** @return array<string, string> */
    private function expectedCurrentCatalog(): array
    {
        $expected = $this->expectedLegacyCatalog();
        for ($offset = 0; $offset < 6; $offset++) {
            $expected[sprintf('13801%05d', 189 + $offset)] =
                'Barangay 176-' . chr(ord('A') + $offset);
        }
        return $expected;
    }

    /** @param list<string> $barangayIds @return array<string, true>|null */
    private function validatedUniqueIds(array $barangayIds): ?array
    {
        $uniqueIds = [];
        foreach ($barangayIds as $barangayId) {
            if (!$this->isUuid($barangayId) || isset($uniqueIds[$barangayId])) {
                return null;
            }
            $uniqueIds[$barangayId] = true;
        }
        return $uniqueIds;
    }

    /** @param array<string, mixed> $record @return array{barangay_id: string, barangay_code: string, name: string} */
    private function normalizeRecord(array $record): array
    {
        $id = (string) ($record['barangay_id'] ?? '');
        $code = (string) ($record['barangay_code'] ?? '');
        $name = trim((string) ($record['name'] ?? ''));
        if (!$this->isUuid($id) || preg_match('/^13801\d{5}$/', $code) !== 1
            || preg_match('/^Barangay (?:[1-9]|[1-9]\d|1\d\d)(?:-[A-F])?$/', $name) !== 1
            || $name === 'Barangay 176') {
            throw new RuntimeException('The barangay catalog contains a malformed record.');
        }
        return ['barangay_id' => $id, 'barangay_code' => $code, 'name' => $name];
    }

    /** @param array<mixed> $records @return list<array<string, mixed>> */
    private function records(array $records): array
    {
        foreach ($records as $record) {
            if (!is_array($record) || array_is_list($record)) {
                throw new RuntimeException('The barangay data source returned malformed records.');
            }
        }
        /** @var list<array<string, mixed>> $records */
        return array_values($records);
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
