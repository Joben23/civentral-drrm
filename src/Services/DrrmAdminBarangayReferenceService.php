<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Staging-admin-only presentation projection of the controlled incomplete set.
 *
 * This service never changes publication state and omits internal identifiers
 * and governance metadata from the map response.
 */
final class DrrmAdminBarangayReferenceService
{
    public const DISPLAY_STATUS = 'INCOMPLETE ADMIN REFERENCE';
    public const DISCLOSURE = '187 validated barangay boundaries are shown for administrative planning. Barangays 176-A through 176-F remain pending authoritative GIS boundaries. These records are not yet published as the complete operational 193-barangay catalog.';

    public function __construct(
        private readonly SupabaseRestClient $client,
        bool $stagingPreviewAllowed
    ) {
        if (!$stagingPreviewAllowed) {
            throw new RuntimeException('The admin barangay reference is unavailable.');
        }
    }

    /** @return array{type: string, features: list<array<string, mixed>>} */
    public function featureCollection(): array
    {
        $controlled = (new DrrmDraftBarangayPreviewService(
            $this->client,
            true
        ))->featureCollection();

        $features = array_map(
            static function (array $feature): array {
                $properties = $feature['properties'] ?? [];
                if (!is_array($properties)) {
                    throw new RuntimeException('The controlled barangay projection is invalid.');
                }

                return [
                    'type' => 'Feature',
                    'geometry' => $feature['geometry'],
                    'properties' => [
                        'barangay_code' => $properties['barangay_code'],
                        'name' => $properties['name'],
                        'reference_status' => self::DISPLAY_STATUS,
                    ],
                ];
            },
            $controlled['features']
        );

        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
