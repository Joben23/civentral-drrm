<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only staging-admin GIS check against the controlled 15-feature flood draft.
 *
 * The server-owned loader is lazy so invalid and outside-city points are
 * rejected before any controlled reference data is read.
 */
final class DrrmAdminFloodReferenceCheckService
{
    public const SOURCE_STATUS = 'DRAFT_ADMIN_REFERENCE';
    public const NO_INTERSECTION_STATUS = 'NO_MAPPED_REFERENCE_INTERSECTION';

    /** @var array<string, array{classification: string, display_label: string, source_label: string, rank: int}> */
    private const CLASSIFICATIONS = [
        'LF' => [
            'classification' => 'LOW',
            'display_label' => 'Low',
            'source_label' => 'Low Susceptibility to Flooding',
            'rank' => 1,
        ],
        'MF' => [
            'classification' => 'MODERATE',
            'display_label' => 'Moderate',
            'source_label' => 'Moderate Susceptibility to Flooding',
            'rank' => 2,
        ],
        'HF' => [
            'classification' => 'HIGH',
            'display_label' => 'High',
            'source_label' => 'High Susceptibility to Flooding',
            'rank' => 3,
        ],
        'VHF' => [
            'classification' => 'VERY HIGH',
            'display_label' => 'Very High',
            'source_label' => 'Very High Susceptibility to Flooding',
            'rank' => 4,
        ],
    ];

    /** @var Closure(): array{type: string, features: list<array<string, mixed>>} */
    private readonly Closure $referenceLoader;

    /**
     * @param callable(): array{type: string, features: list<array<string, mixed>>} $referenceLoader
     */
    public function __construct(
        callable $referenceLoader,
        private readonly DrrmCaloocanBoundaryService $cityBoundary,
        bool $stagingAdminCheckAllowed
    ) {
        if (!$stagingAdminCheckAllowed) {
            throw new RuntimeException('The admin flood reference check is unavailable.');
        }
        $this->referenceLoader = Closure::fromCallable($referenceLoader);
    }

    /** @return array<string, mixed> */
    public function check(float $latitude, float $longitude): array
    {
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180
            || !$this->cityBoundary->contains($latitude, $longitude)) {
            throw new InvalidArgumentException('Please select a location inside Caloocan City.');
        }

        $features = $this->controlledFeatures(($this->referenceLoader)());
        $intersections = [];
        foreach ($features as $feature) {
            if (!$this->geometryCoversPoint($feature['geometry'], $longitude, $latitude)) {
                continue;
            }
            $code = (string) $feature['properties']['mgb_code'];
            $intersections[] = self::CLASSIFICATIONS[$code];
        }

        $location = ['latitude' => $latitude, 'longitude' => $longitude];
        $reference = [
            'hazard_type' => 'FLOOD',
            'source_status' => self::SOURCE_STATUS,
            'source_organization' => 'DENR-MGB',
        ];
        if ($intersections === []) {
            return [
                'status' => self::NO_INTERSECTION_STATUS,
                'intersection' => false,
                'location' => $location,
                'reference' => $reference,
            ];
        }

        usort(
            $intersections,
            static fn (array $left, array $right): int => $right['rank'] <=> $left['rank']
        );
        $highest = $intersections[0];
        return [
            'status' => self::SOURCE_STATUS,
            'intersection' => true,
            'classification' => $highest['classification'],
            'risk_rank' => $highest['rank'],
            'overlap_count' => count($intersections),
            'multiple_reference_polygons' => count($intersections) > 1,
            'location' => $location,
            'reference' => $reference,
        ];
    }

    /**
     * @param array<mixed> $collection
     * @return list<array{type: string, geometry: array<string, mixed>, properties: array<string, mixed>}>
     */
    private function controlledFeatures(array $collection): array
    {
        $features = ($collection['type'] ?? null) === 'FeatureCollection'
            && is_array($collection['features'] ?? null)
            && array_is_list($collection['features'])
            ? $collection['features']
            : null;
        if ($features === null || count($features) !== DrrmDraftFloodPreviewService::EXPECTED_FEATURE_COUNT) {
            throw new RuntimeException('The controlled flood reference is unavailable.');
        }

        $counts = array_fill_keys(array_keys(self::CLASSIFICATIONS), 0);
        $validated = [];
        foreach ($features as $feature) {
            $properties = is_array($feature) && is_array($feature['properties'] ?? null)
                ? $feature['properties']
                : null;
            $geometry = is_array($feature) && is_array($feature['geometry'] ?? null)
                ? $feature['geometry']
                : null;
            $code = is_array($properties) ? strtoupper(trim((string) ($properties['mgb_code'] ?? ''))) : '';
            $expected = self::CLASSIFICATIONS[$code] ?? null;
            if (($feature['type'] ?? null) !== 'Feature'
                || $properties === null || $geometry === null || $expected === null
                || ($properties['hazard'] ?? null) !== 'Flood'
                || ($properties['mgb_label'] ?? null) !== $expected['source_label']
                || ($properties['display_risk_label'] ?? null) !== $expected['display_label']
                || ($properties['source_agency'] ?? null) !== 'DENR-MGB'
                || ($geometry['type'] ?? null) !== 'MultiPolygon'
                || !is_array($geometry['coordinates'] ?? null)
                || !array_is_list($geometry['coordinates'])
                || $geometry['coordinates'] === []) {
                throw new RuntimeException('The controlled flood reference contains an invalid feature.');
            }

            $counts[$code]++;
            $validated[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => $properties,
            ];
        }

        if ($counts !== ['LF' => 5, 'MF' => 3, 'HF' => 4, 'VHF' => 3]) {
            throw new RuntimeException('The controlled flood reference classification counts are invalid.');
        }

        return $validated;
    }
    /** @param array<string, mixed> $geometry */
    private function geometryCoversPoint(array $geometry, float $longitude, float $latitude): bool
    {
        foreach ($geometry['coordinates'] as $polygon) {
            if (!is_array($polygon) || !array_is_list($polygon) || $polygon === []) {
                throw new RuntimeException('The controlled flood reference contains an invalid polygon.');
            }

            $exterior = $this->pointInRing($longitude, $latitude, $polygon[0] ?? null);
            if ($exterior === 0) {
                continue;
            }
            if ($exterior === 2) {
                return true;
            }

            $insideHole = false;
            for ($index = 1, $count = count($polygon); $index < $count; $index++) {
                $hole = $this->pointInRing($longitude, $latitude, $polygon[$index]);
                if ($hole === 2) {
                    return true;
                }
                if ($hole === 1) {
                    $insideHole = true;
                    break;
                }
            }
            if (!$insideHole) {
                return true;
            }
        }

        return false;
    }

    /** @return int 0=outside, 1=inside, 2=boundary */
    private function pointInRing(float $longitude, float $latitude, mixed $ring): int
    {
        if (!is_array($ring) || !array_is_list($ring) || count($ring) < 4) {
            throw new RuntimeException('The controlled flood reference contains an invalid ring.');
        }

        $inside = false;
        for ($index = 0, $previous = count($ring) - 1; $index < count($ring); $previous = $index++) {
            $first = $ring[$previous] ?? null;
            $second = $ring[$index] ?? null;
            if (!is_array($first) || !is_array($second)
                || !is_numeric($first[0] ?? null) || !is_numeric($first[1] ?? null)
                || !is_numeric($second[0] ?? null) || !is_numeric($second[1] ?? null)) {
                throw new RuntimeException('The controlled flood reference contains an invalid position.');
            }

            $x1 = (float) $first[0];
            $y1 = (float) $first[1];
            $x2 = (float) $second[0];
            $y2 = (float) $second[1];
            if (!is_finite($x1) || !is_finite($y1) || !is_finite($x2) || !is_finite($y2)
                || $x1 < -180 || $x1 > 180 || $x2 < -180 || $x2 > 180
                || $y1 < -90 || $y1 > 90 || $y2 < -90 || $y2 > 90) {
                throw new RuntimeException('The controlled flood reference contains an out-of-range position.');
            }

            $cross = (($x2 - $x1) * ($latitude - $y1))
                - (($y2 - $y1) * ($longitude - $x1));
            if (abs($cross) <= 1.0E-12
                && $longitude >= min($x1, $x2) - 1.0E-12
                && $longitude <= max($x1, $x2) + 1.0E-12
                && $latitude >= min($y1, $y2) - 1.0E-12
                && $latitude <= max($y1, $y2) + 1.0E-12) {
                return 2;
            }

            if (($y1 > $latitude) !== ($y2 > $latitude)) {
                $intersection = (($x2 - $x1) * ($latitude - $y1) / ($y2 - $y1)) + $x1;
                if ($longitude < $intersection) {
                    $inside = !$inside;
                }
            }
        }

        return $inside ? 1 : 0;
    }
}
