<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Staging-admin-only presentation projection of the exact controlled center set.
 *
 * This service never changes publication state and deliberately omits operational
 * center IDs, capacity, contacts, availability, reviewer data, and route fields.
 */
final class DrrmAdminEvacuationCenterReferenceService
{
    public const DISPLAY_STATUS = 'UNVERIFIED CENTER REFERENCE';
    public const DISCLOSURE = 'Reference locations are shown for administrative planning only and remain pending LGU verification.';

    public function __construct(
        private readonly SupabaseRestClient $client,
        bool $stagingPreviewAllowed
    ) {
        if (!$stagingPreviewAllowed) {
            throw new RuntimeException('The admin center reference is unavailable.');
        }
    }

    /** @return array{type: string, features: list<array<string, mixed>>} */
    public function featureCollection(): array
    {
        $controlled = (new DrrmDraftEvacuationCenterPreviewService(
            $this->client,
            true
        ))->featureCollection();

        $features = array_map(
            static function (array $feature): array {
                $properties = $feature['properties'] ?? [];
                if (!is_array($properties)) {
                    throw new RuntimeException('The controlled center projection is invalid.');
                }

                return [
                    'type' => 'Feature',
                    'geometry' => $feature['geometry'],
                    'properties' => [
                        'reference_id' => $properties['evacuation_center_id'],
                        'name' => $properties['name'],
                        'location_status' => 'APPROXIMATE_REFERENCE_LOCATION',
                        'barangay_display_location' => $properties['barangay_name'],
                        'managing_office' => 'City Government of Caloocan',
                        'verification_status' => 'PENDING_LGU_VERIFICATION',
                        'display_status' => self::DISPLAY_STATUS,
                    ],
                ];
            },
            $controlled['features']
        );

        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
