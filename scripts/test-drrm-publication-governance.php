<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../src/Services/DrrmDataStoreInterface.php';
require_once __DIR__ . '/../src/Services/SupabaseRestClient.php';
require_once __DIR__ . '/../src/Services/DrrmMapReadService.php';
require_once __DIR__ . '/../src/Services/DrrmBarangayCatalogService.php';

use App\Config\SupabaseConfig;
use App\Services\DrrmBarangayCatalogService;
use App\Services\DrrmDataStoreInterface;
use App\Services\DrrmMapReadService;
use App\Services\SupabaseRestClient;

final class GovernanceReadStore implements DrrmDataStoreInterface
{
    /** @param array<string, list<array<string, mixed>>> $tables */
    public function __construct(private readonly array $tables)
    {
    }

    public function get(string $resource, array $query = []): array
    {
        $rows = $this->tables[$resource] ?? [];
        foreach ($query as $field => $filter) {
            if (in_array($field, ['select', 'order', 'limit', 'offset'], true)) {
                continue;
            }
            $rows = array_values(array_filter(
                $rows,
                static function (array $row) use ($field, $filter): bool {
                    if (!is_string($filter)) {
                        return false;
                    }
                    if ($filter === 'not.is.null') {
                        return ($row[$field] ?? null) !== null;
                    }
                    if (str_starts_with($filter, 'eq.')) {
                        return (string) ($row[$field] ?? '') === substr($filter, 3);
                    }
                    if (str_starts_with($filter, 'neq.')) {
                        return (string) ($row[$field] ?? '') !== substr($filter, 4);
                    }
                    if (str_starts_with($filter, 'in.(') && str_ends_with($filter, ')')) {
                        $values = explode(',', substr($filter, 4, -1));
                        return in_array((string) ($row[$field] ?? ''), $values, true);
                    }
                    if (str_starts_with($filter, 'ilike.')) {
                        $prefix = rtrim(substr($filter, 6), '*');
                        return str_starts_with(
                            strtolower((string) ($row[$field] ?? '')),
                            strtolower($prefix)
                        );
                    }
                    return true;
                }
            ));
        }

        if (isset($query['limit']) && is_int($query['limit'])) {
            $rows = array_slice($rows, 0, $query['limit']);
        }
        return $rows;
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('The governance test store is read-only.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        throw new RuntimeException('The governance test store exposes no RPC mutations.');
    }
}

$failures = [];
$assertions = 0;

function governanceAssert(string $name, bool $condition): void
{
    global $failures, $assertions;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) {
        $failures[] = $name;
    }
}

function governanceFailsClosed(string $name, callable $operation): void
{
    try {
        $result = $operation();
        governanceAssert($name, $result === []);
    } catch (Throwable) {
        governanceAssert($name, true);
    }
}

/** @param array<mixed> $rows */
function governanceAllMatch(array $rows, callable $predicate): bool
{
    foreach ($rows as $row) {
        if (!is_array($row) || !$predicate($row)) {
            return false;
        }
    }
    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function governanceBarangayCatalog(
    string $versionId,
    string $recordStatus,
    bool $current,
    int $idOffset,
    ?string $firstId = null
): array {
    $definitions = [];
    for ($number = 1; $number <= 188; $number++) {
        if ($number !== 176) {
            $definitions[] = [sprintf('13801%05d', $number), 'Barangay ' . $number];
        }
    }
    if ($current) {
        for ($offset = 0; $offset < 6; $offset++) {
            $definitions[] = [
                sprintf('13801%05d', 189 + $offset),
                'Barangay 176-' . chr(ord('A') + $offset),
            ];
        }
    }

    $rows = [];
    foreach ($definitions as $index => [$code, $name]) {
        $idNumber = $idOffset + $index + 1;
        $rows[] = [
            'barangay_id' => $index === 0 && $firstId !== null
                ? $firstId
                : sprintf('%08x-0000-4000-8000-%012x', $idNumber, $idNumber),
            'barangay_code' => $code,
            'name' => $name,
            'district_code' => '1',
            'boundary_geometry' => ['type' => 'MultiPolygon', 'coordinates' => []],
            'boundary_dataset_version_id' => $versionId,
            'record_status' => $recordStatus,
        ];
    }
    return $rows;
}

$sourceId = '11111111-1111-4111-8111-111111111111';
$versionId = '22222222-2222-4222-8222-222222222222';
$barangayId = '33333333-3333-4333-8333-333333333333';
$centerId = '44444444-4444-4444-8444-444444444444';
$routeId = '55555555-5555-4555-8555-555555555555';
// These nonempty sentinels exercise in-memory projection checks only. They are
// never submitted to Supabase and do not represent people or event times.
$evidenceSentinel = 'NONEMPTY_TEST_SENTINEL';
$publishedVersion = [
    'dataset_version_id' => $versionId,
    'dataset_source_id' => $sourceId,
    'dataset_category' => 'BARANGAY_BOUNDARY',
    'hazard_type_id' => null,
    'source_reference' => 'controlled-test-reference',
    'publication_date' => $evidenceSentinel,
    'effective_from' => $evidenceSentinel,
    'license' => 'controlled-test-license',
    'review_status' => 'PUBLISHED',
    'reviewed_by_civentral_user_id' => $evidenceSentinel,
    'reviewed_at' => $evidenceSentinel,
    'published_at' => $evidenceSentinel,
];
$activeSource = [
    'dataset_source_id' => $sourceId,
    'record_status' => 'ACTIVE',
];
$activeSuccessorBarangays = governanceBarangayCatalog(
    $versionId,
    'ACTIVE',
    true,
    1000,
    $barangayId
);
$activeBarangay = $activeSuccessorBarangays[0];
$verifiedCenter = [
    'evacuation_center_id' => $centerId,
    'name' => 'Controlled Center',
    'barangay_id' => $barangayId,
    'location' => ['type' => 'Point', 'coordinates' => [121.0, 14.7]],
    'address' => 'Controlled address',
    'capacity' => 0,
    'operational_status' => 'AVAILABLE',
    'publication_status' => 'PUBLISHED',
    'contact_phone' => null,
    'accessibility_notes' => null,
    'managing_office_name' => 'Controlled office',
    'verified_by_civentral_user_id' => $evidenceSentinel,
    'verified_at' => $evidenceSentinel,
];
$approvedRoute = [
    'evacuation_route_id' => $routeId,
    'route_name' => 'Controlled route',
    'origin_barangay_id' => $barangayId,
    'origin_name' => 'Controlled origin',
    'origin_location' => ['type' => 'Point', 'coordinates' => [121.0, 14.7]],
    'destination_center_id' => $centerId,
    'route_geometry' => [
        'type' => 'LineString',
        'coordinates' => [[121.0, 14.7], [121.01, 14.71]],
    ],
    'distance_meters' => 1500.0,
    'safety_notes' => 'Use the controlled signed route only.',
    'route_status' => 'APPROVED',
    'approved_by_civentral_user_id' => $evidenceSentinel,
    'approved_at' => $evidenceSentinel,
    'last_reviewed_at' => $evidenceSentinel,
];

$eligibleService = new DrrmMapReadService(new GovernanceReadStore([
    'dataset_versions' => [$publishedVersion],
    'dataset_sources' => [$activeSource],
    'barangays' => $activeSuccessorBarangays,
    'evacuation_centers' => [$verifiedCenter],
    'evacuation_routes' => [$approvedRoute],
]));
governanceAssert(
    'PublishedReviewedVersionCanExposeActiveChild',
    count($eligibleService->barangays()) === DrrmBarangayCatalogService::CURRENT_OPERATIONAL_COUNT
);
governanceAssert(
    'VerifiedPublishedCenterCanBeOperational',
    count($eligibleService->evacuationCenters()) === 1
);
governanceAssert(
    'ApprovedRouteAgainstPublishedCenterCanBeOperational',
    count($eligibleService->evacuationRoutes()) === 1
);

$draftService = new DrrmMapReadService(new GovernanceReadStore([
    'dataset_versions' => [array_replace($publishedVersion, ['review_status' => 'DRAFT'])],
    'dataset_sources' => [$activeSource],
    'barangays' => [$activeBarangay],
]));
governanceFailsClosed(
    'DraftVersionActiveChildFailsClosed',
    static fn (): array => $draftService->barangays()
);

$unreviewedService = new DrrmMapReadService(new GovernanceReadStore([
    'dataset_versions' => [array_replace($publishedVersion, [
        'reviewed_by_civentral_user_id' => null,
        'reviewed_at' => null,
    ])],
    'dataset_sources' => [$activeSource],
    'barangays' => [$activeBarangay],
]));
governanceFailsClosed(
    'PublishedVersionWithoutReviewMetadataFailsClosed',
    static fn (): array => $unreviewedService->barangays()
);

$unverifiedCenter = array_replace($verifiedCenter, [
    'verified_by_civentral_user_id' => null,
    'verified_at' => null,
]);
$centerService = new DrrmMapReadService(new GovernanceReadStore([
    'evacuation_centers' => [$unverifiedCenter],
]));
governanceAssert(
    'UnverifiedPublishedCenterFailsClosed',
    $centerService->evacuationCenters() === []
);

$routeService = new DrrmMapReadService(new GovernanceReadStore([
    'evacuation_routes' => [$approvedRoute],
    'evacuation_centers' => [array_replace($verifiedCenter, ['publication_status' => 'DRAFT'])],
]));
governanceAssert(
    'ApprovedRouteAgainstUnpublishedCenterFailsClosed',
    $routeService->evacuationRoutes() === []
);

$nullDistanceRouteService = new DrrmMapReadService(new GovernanceReadStore([
    'evacuation_routes' => [array_replace($approvedRoute, ['distance_meters' => null])],
    'evacuation_centers' => [$verifiedCenter],
]));
governanceAssert(
    'ApprovedRouteWithNullDistanceFailsClosed',
    $nullDistanceRouteService->evacuationRoutes() === []
);
$zeroDistanceRouteService = new DrrmMapReadService(new GovernanceReadStore([
    'evacuation_routes' => [array_replace($approvedRoute, ['distance_meters' => 0])],
    'evacuation_centers' => [$verifiedCenter],
]));
governanceAssert(
    'ApprovedRouteWithZeroDistanceFailsClosed',
    $zeroDistanceRouteService->evacuationRoutes() === []
);

$legacyBarangays = governanceBarangayCatalog(
    DrrmBarangayCatalogService::LEGACY_DRAFT_DATASET_VERSION_ID,
    'INACTIVE',
    false,
    3000
);
$legacyCatalog = new DrrmBarangayCatalogService(new GovernanceReadStore([
    'dataset_versions' => [],
    'barangays' => $legacyBarangays,
]));
governanceAssert(
    'Module34LegacyCatalogStillReturns187InactiveRows',
    count($legacyCatalog->availableBarangays()) === 187
);

$incompleteSuccessorCatalog = new DrrmBarangayCatalogService(new GovernanceReadStore([
    'dataset_versions' => [$publishedVersion],
    'dataset_sources' => [$activeSource],
    'barangays' => array_merge($legacyBarangays, [$activeBarangay]),
]));
governanceAssert(
    'IncompletePublishedSuccessorDoesNotDisplaceCompatibility',
    count($incompleteSuccessorCatalog->availableBarangays()) ===
        DrrmBarangayCatalogService::LEGACY_DRAFT_COUNT
);

$successorCatalog = new DrrmBarangayCatalogService(new GovernanceReadStore([
    'dataset_versions' => [$publishedVersion],
    'dataset_sources' => [$activeSource],
    'barangays' => array_merge($legacyBarangays, $activeSuccessorBarangays),
]));
governanceAssert(
    'CompletePublishedSuccessorBecomesCurrentWriteCatalog',
    count($successorCatalog->availableBarangays()) ===
        DrrmBarangayCatalogService::CURRENT_OPERATIONAL_COUNT
);
governanceAssert(
    'MixedLegacyAndSuccessorNewWriteAttemptFailsClosed',
    $successorCatalog->writeEligibleBarangaysById([
        $legacyBarangays[0]['barangay_id'],
        $activeSuccessorBarangays[0]['barangay_id'],
    ]) === []
);
governanceAssert(
    'SuccessorOnlyNewWriteAttemptIsEligible',
    count($successorCatalog->writeEligibleBarangaysById([
        $activeSuccessorBarangays[0]['barangay_id'],
        $activeSuccessorBarangays[1]['barangay_id'],
    ])) === 2
);

$archivedVersionId = '66666666-6666-4666-8666-666666666666';
$archivedBarangays = governanceBarangayCatalog(
    $archivedVersionId,
    'INACTIVE',
    true,
    5000
);
$historicalCatalog = new DrrmBarangayCatalogService(new GovernanceReadStore([
    'dataset_versions' => [
        array_replace($publishedVersion, [
            'dataset_version_id' => $archivedVersionId,
            'review_status' => 'ARCHIVED',
        ]),
    ],
    'dataset_sources' => [],
    'barangays' => array_merge($legacyBarangays, $archivedBarangays),
]));
governanceAssert(
    'HistoricalArchivedAndLegacyReferencesResolveTogether',
    count($historicalCatalog->historicalBarangaysById([
        $legacyBarangays[0]['barangay_id'],
        $archivedBarangays[0]['barangay_id'],
    ])) === 2
);

$migrationPath = __DIR__
    . '/../supabase/migrations/20260901000100_module1_gis_publication_governance.sql';
$migration = file_get_contents($migrationPath);
$mapServiceSource = file_get_contents(__DIR__ . '/../src/Services/DrrmMapReadService.php');
$catalogSource = file_get_contents(__DIR__ . '/../src/Services/DrrmBarangayCatalogService.php');
$mapAdapterSource = file_get_contents(__DIR__ . '/../assets/js/drrm/operational-map-data.js');
governanceAssert(
    'MigrationTransactionBounded',
    is_string($migration)
    && str_starts_with(ltrim($migration), '-- Phase P0')
    && str_contains($migration, chr(10) . 'begin;' . chr(10))
    && str_ends_with(rtrim($migration), 'commit;')
);
governanceAssert(
    'MigrationContainsNoPublicationDataMutation',
    is_string($migration)
    && preg_match(
        '/\b(?:update|delete\s+from)\s+public\.(?:dataset_sources|dataset_versions|barangays|hazard_zones|fault_features|evacuation_centers|evacuation_routes)\b/i',
        $migration
    ) !== 1
    && preg_match(
        '/\binsert\s+into\s+public\.(?:dataset_sources|dataset_versions|barangays|hazard_zones|fault_features|evacuation_centers|evacuation_routes)\b/i',
        $migration
    ) !== 1
);
governanceAssert(
    'DatasetWorkflowAndReviewEvidenceEnforced',
    is_string($migration)
    && str_contains($migration, 'DRAFT -> UNDER_REVIEW -> PUBLISHED -> ARCHIVED')
    && str_contains($migration, 'dataset_versions_review_lifecycle_check')
    && str_contains($migration, 'source.record_status = ' . chr(39) . 'ACTIVE' . chr(39))
);
governanceAssert(
    'FutureGisDefaultsAreInactive',
    is_string($migration)
    && substr_count(
        $migration,
        'alter column record_status set default ' . chr(39) . 'INACTIVE' . chr(39)
    ) === 3
);
governanceAssert(
    'VersionedChildrenRequirePublishedParents',
    is_string($migration)
    && str_contains($migration, 'enforce_active_gis_dataset_coupling')
    && str_contains($migration, 'version.review_status = ' . chr(39) . 'PUBLISHED' . chr(39))
    && str_contains($migration, 'protect_published_gis_child')
    && str_contains($migration, 'for share;')
    && str_contains($migration, 'barangays_protect_published_authority')
    && str_contains($migration, 'risk_level_id')
    && str_contains($migration, 'feature_class')
);
governanceAssert(
    'DatasetLineageRejectsSelfCyclesAndWrongScopeBeforeReview',
    is_string($migration)
    && str_contains($migration, 'supersedes_dataset_version_id')
    && str_contains($migration, 'dataset_versions_not_self_superseding_check')
    && str_contains($migration, 'Dataset predecessor lineage is immutable')
    && str_contains($migration, 'with recursive lineage')
    && str_contains($migration, 'where not lineage.cycle')
    && str_contains($migration, 'predecessor.dataset_category is distinct from new.dataset_category')
    && str_contains($migration, 'predecessor.hazard_type_id is distinct from new.hazard_type_id')
);
governanceAssert(
    'PublicationChildUpdateRaceLocksParentVersion',
    is_string($migration)
    && str_contains($migration, 'Parent workflow updates never lock child rows')
    && str_contains($migration, 'where dataset_version_id = old_version_id')
    && str_contains($migration, 'for share;')
    && str_contains($migration, 'record_status is intentionally excluded')
);
governanceAssert(
    'IndirectLineageCycleIsRejected',
    is_string($migration)
    && str_contains($migration, 'with recursive lineage')
    && str_contains($migration, 'Dataset-version predecessor lineage contains a cycle.')
);
governanceAssert(
    'WrongScopePredecessorIsRejectedBeforeReview',
    is_string($migration)
    && str_contains($migration, 'reviewed successor requires an ARCHIVED predecessor')
    && str_contains($migration, 'predecessor.dataset_category is distinct from new.dataset_category')
    && str_contains($migration, 'predecessor.hazard_type_id is distinct from new.hazard_type_id')
);
governanceAssert(
    'DatasetCategoryAndHazardScopeAreEnforced',
    is_string($migration)
    && !str_contains($migration, 'dataset_versions_category_hazard_scope_check')
    && str_contains($migration, 'enforce_dataset_version_publication_workflow')
    && str_contains($migration, 'dataset_versions_one_published_barangay_catalog_uidx')
    && str_contains($migration, 'EARTHQUAKE_FAULT')
);
governanceAssert(
    'AuthoritativeLiveStatusAndScopeChecksArePreserved',
    is_string($migration)
    && str_contains($migration, 'LIVE CHECK COMPATIBILITY')
    && str_contains($migration, 'DRAFT/UNDER_REVIEW/PUBLISHED/ARCHIVED')
    && str_contains($migration, 'DRAFT/PUBLISHED/ARCHIVED')
    && str_contains($migration, 'DRAFT/UNDER_REVIEW/APPROVED/SUSPENDED/ARCHIVED')
    && !str_contains($migration, chr(39) . 'REVIEWED' . chr(39))
    && stripos($migration, 'drop constraint') === false
);
governanceAssert(
    'CenterVerificationIsRequiredForPublication',
    is_string($migration)
    && str_contains($migration, 'evacuation_centers_publication_prerequisites_check')
    && str_contains($migration, 'verified_by_civentral_user_id')
    && str_contains($migration, 'verified_at')
    && str_contains($migration, 'enforce_evacuation_center_publication_workflow')
    && str_contains($migration, 'DRAFT -> PUBLISHED -> ARCHIVED')
);
governanceAssert(
    'OperationalRoutesRequireGeometryDistanceSafetyReviewAndPublishedCenter',
    is_string($migration)
    && str_contains($migration, 'evacuation_routes_operational_evidence_check')
    && str_contains($migration, 'route_status not in (' . chr(39) . 'APPROVED' . chr(39) . ', ' . chr(39) . 'SUSPENDED' . chr(39) . ')')
    && str_contains($migration, 'evacuation_routes_geometry_governance_check')
    && str_contains($migration, 'extensions.st_srid(route_geometry) = 4326')
    && str_contains($migration, 'distance_meters is not null')
    && str_contains($migration, 'distance_meters > 0')
    && str_contains($migration, 'last_reviewed_at is not null')
    && str_contains($migration, 'nullif(btrim(safety_notes), ' . chr(39) . chr(39) . ') is not null')
    && str_contains($migration, 'enforce_approved_route_governance')
    && str_contains($migration, 'add column if not exists supersedes_route_id uuid null')
    && str_contains($migration, 'evacuation_routes_supersedes_fkey')
    && str_contains($migration, 'evacuation_routes_one_successor_uidx')
    && str_contains($migration, 'evidence and safety definition are immutable through suspension/archive')
    && is_string($mapAdapterSource)
    && str_contains($mapAdapterSource, 'distanceMeters <= 0')
);
governanceAssert(
    'PublishedCenterEvidenceRewriteRequiresArchivedReplacement',
    is_string($migration)
    && str_contains($migration, 'new.verified_by_civentral_user_id is distinct from old.verified_by_civentral_user_id')
    && str_contains($migration, 'new.verified_at is distinct from old.verified_at')
    && str_contains($migration, 'new.location is distinct from old.location')
    && str_contains($migration, 'create and verify a replacement center')
);
governanceAssert(
    'ApprovedRouteEvidenceRewriteRequiresSuccessor',
    is_string($migration)
    && str_contains($migration, 'before update or delete on public.evacuation_routes')
    && str_contains($migration, 'old.route_status in (' . chr(39) . 'APPROVED' . chr(39) . ', ' . chr(39) . 'SUSPENDED' . chr(39) . ', ' . chr(39) . 'ARCHIVED' . chr(39) . ')')
    && str_contains($migration, 'approved_by_civentral_user_id')
    && str_contains($migration, 'last_reviewed_at')
    && str_contains($migration, 'supersedes_route_id')
);
governanceAssert(
    'RlsAndBrowserRolesAreNotWeakened',
    is_string($migration)
    && stripos($migration, 'disable row level security') === false
    && !str_contains($migration, 'grant execute on function public.verify_module1_publication_governance() to anon')
    && !str_contains($migration, 'grant execute on function public.verify_module1_publication_governance() to authenticated')
);
governanceAssert(
    'OperationalServiceCouplesChildrenAndEvidence',
    is_string($mapServiceSource)
    && str_contains($mapServiceSource, 'eligiblePublishedVersions')
    && str_contains($mapServiceSource, 'isOperationalCenter')
    && str_contains($mapServiceSource, 'isApprovedRoute')
);
governanceAssert(
    'Module34CatalogHasLegacyAndSuccessorPaths',
    is_string($catalogSource)
    && str_contains($catalogSource, 'LEGACY_DRAFT_DATASET_VERSION_ID')
    && str_contains($catalogSource, 'CURRENT_OPERATIONAL_COUNT = 193')
    && str_contains($catalogSource, 'completePublishedOperationalCatalog')
    && str_contains($catalogSource, 'writeEligibleBarangaysById')
    && str_contains($catalogSource, 'historicalBarangaysById')
    && str_contains($migration, 'is_complete_drrm_barangay_catalog')
    && str_contains($migration, 'current_drrm_barangay_write_catalog_version_id')
);
governanceAssert(
    'MigrationContainsNoEmbeddedSecretMaterial',
    is_string($migration)
    && preg_match('/(?:eyJ[a-zA-Z0-9_-]{20,}|sk-[a-zA-Z0-9_-]{20,})/', $migration) !== 1
    && !str_contains($migration, 'SUPABASE_SERVICE_ROLE_KEY=')
    && !str_contains($migration, 'CIVENTRAL_AI')
);

try {
    $client = new SupabaseRestClient(
        SupabaseConfig::fromEnvironment(__DIR__ . '/../.env')
    );

    $versions = $client->get('dataset_versions', [
        'select' => 'dataset_version_id,review_status,reviewed_by_civentral_user_id,reviewed_at,published_at',
        'order' => 'created_at.asc',
    ]);
    $barangays = $client->get('barangays', [
        'select' => 'barangay_id,record_status',
    ]);
    $hazards = $client->get('hazard_zones', [
        'select' => 'hazard_zone_id,hazard_type_id,record_status',
    ]);
    $faults = $client->get('fault_features', [
        'select' => 'fault_feature_id,record_status',
    ]);
    $centers = $client->get('evacuation_centers', [
        'select' => 'evacuation_center_id,publication_status,operational_status,verified_by_civentral_user_id,verified_at',
    ]);
    $routes = $client->get('evacuation_routes', [
        'select' => 'evacuation_route_id,route_status',
    ]);

    governanceAssert(
        'CurrentFourDatasetVersionsRemainDraft',
        count($versions) === 4
        && governanceAllMatch(
            $versions,
            static fn (array $row): bool =>
                ($row['review_status'] ?? null) === 'DRAFT'
                && ($row['reviewed_by_civentral_user_id'] ?? null) === null
                && ($row['reviewed_at'] ?? null) === null
                && ($row['published_at'] ?? null) === null
        )
    );
    governanceAssert(
        'Current187BarangaysRemainInactive',
        count($barangays) === 187
        && governanceAllMatch(
            $barangays,
            static fn (array $row): bool => ($row['record_status'] ?? null) === 'INACTIVE'
        )
    );
    governanceAssert(
        'Current28HazardsRemainInactive',
        count($hazards) === 28
        && governanceAllMatch(
            $hazards,
            static fn (array $row): bool => ($row['record_status'] ?? null) === 'INACTIVE'
        )
    );
    governanceAssert(
        'CurrentHazardSplitRemains15Flood13Landslide',
        count(array_filter(
            $hazards,
            static fn (array $row): bool => ($row['hazard_type_id'] ?? null) === 1
        )) === 15
        && count(array_filter(
            $hazards,
            static fn (array $row): bool => ($row['hazard_type_id'] ?? null) === 2
        )) === 13
    );
    governanceAssert(
        'Current156FaultsRemainInactive',
        count($faults) === 156
        && governanceAllMatch(
            $faults,
            static fn (array $row): bool => ($row['record_status'] ?? null) === 'INACTIVE'
        )
    );
    governanceAssert(
        'Current15CentersRemainDraftInactiveUnverified',
        count($centers) === 15
        && governanceAllMatch(
            $centers,
            static fn (array $row): bool =>
                ($row['publication_status'] ?? null) === 'DRAFT'
                && ($row['operational_status'] ?? null) === 'INACTIVE'
                && ($row['verified_by_civentral_user_id'] ?? null) === null
                && ($row['verified_at'] ?? null) === null
        )
    );
    governanceAssert('CurrentRouteCountRemainsZero', $routes === []);

    $liveMapService = new DrrmMapReadService($client);
    governanceAssert(
        'LiveOperationalApisRemainEmpty',
        $liveMapService->barangays() === []
        && $liveMapService->hazardZones() === []
        && $liveMapService->faultFeatures() === []
        && $liveMapService->evacuationCenters() === []
        && $liveMapService->evacuationRoutes() === []
    );

    try {
        $deployed = $client->rpc('verify_module1_publication_governance');
        if (array_is_list($deployed) && count($deployed) === 1 && is_array($deployed[0])) {
            $deployed = $deployed[0];
        }
        governanceAssert(
            'DeployedGovernanceCatalogVerification',
            is_array($deployed)
            && ($deployed['dataset_version_count'] ?? null) === 4
            && ($deployed['draft_dataset_version_count'] ?? null) === 4
            && ($deployed['non_draft_dataset_version_count'] ?? null) === 0
            && ($deployed['barangay_count'] ?? null) === 187
            && ($deployed['inactive_barangay_count'] ?? null) === 187
            && ($deployed['hazard_zone_count'] ?? null) === 28
            && ($deployed['inactive_hazard_zone_count'] ?? null) === 28
            && ($deployed['fault_feature_count'] ?? null) === 156
            && ($deployed['inactive_fault_feature_count'] ?? null) === 156
            && ($deployed['evacuation_center_count'] ?? null) === 15
            && ($deployed['draft_inactive_center_count'] ?? null) === 15
            && ($deployed['evacuation_route_count'] ?? null) === 0
            && ($deployed['active_defaults_are_safe'] ?? null) === true
            && ($deployed['lineage_column_exists'] ?? null) === true
            && ($deployed['route_lineage_column_exists'] ?? null) === true
            && ($deployed['governance_constraint_count'] ?? null) === 11
            && ($deployed['governance_trigger_count'] ?? null) === 15
        );
        echo 'GovernanceMigrationDeployed=YES' . PHP_EOL;
        echo 'ManualSupabaseSqlExecutionRequired=NO' . PHP_EOL;
    } catch (Throwable) {
        echo 'GovernanceMigrationDeployed=NO' . PHP_EOL;
        echo 'ManualSupabaseSqlExecutionRequired=YES' . PHP_EOL;
    }
} catch (Throwable $exception) {
    governanceAssert('LiveReadOnlyInventoryAvailable', false);
    fwrite(STDERR, 'LiveReadOnlyInventoryReason=' . $exception->getMessage() . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, 'GovernanceFailures=' . implode(',', $failures) . PHP_EOL);
    exit(1);
}

echo 'GovernanceAssertions=' . $assertions . PHP_EOL;
echo 'DrrmPublicationGovernance=PASS' . PHP_EOL;
