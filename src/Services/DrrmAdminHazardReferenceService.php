<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Staging-admin-only projection of the controlled flood and landslide draft
 * records used for Module 1 planning review.
 *
 * The service intentionally exposes only the minimal hazard metadata needed by
 * the admin map and does not activate or publish these records.
 */
final class DrrmAdminHazardReferenceService
{
    public const DISPLAY_STATUS = 'DRAFT ADMIN REFERENCE';
    public const DISCLOSURE = 'Controlled draft hazard geometry is shown to authenticated administrators for planning and validation only. It is not published operational data.';

    public function __construct(
        private readonly SupabaseRestClient $client,
        bool $stagingPreviewAllowed
    ) {
        if (!$stagingPreviewAllowed) {
            throw new RuntimeException('The admin hazard reference is unavailable.');
        }
    }

    /** @return array{type: string, features: list<array<string, mixed>>} */
    public function featureCollection(): array
    {
        $flood = (new DrrmDraftFloodPreviewService($this->client, true))->featureCollection();
        $landslide = (new DrrmDraftLandslidePreviewService($this->client, true))->featureCollection();

        $features = [];
        foreach (array_merge($flood['features'], $landslide['features']) as $feature) {
            if (!is_array($feature)) {
                throw new RuntimeException('The controlled hazard projection is invalid.');
            }

            $properties = $feature['properties'] ?? [];
            if (!is_array($properties)) {
                throw new RuntimeException('The controlled hazard properties are invalid.');
            }

            $features[] = [
                'type' => 'Feature',
                'geometry' => $feature['geometry'],
                'properties' => [
                    'hazard' => $properties['hazard'] ?? null,
                    'mgb_code' => $properties['mgb_code'] ?? null,
                    'mgb_label' => $properties['mgb_label'] ?? null,
                    'display_risk_label' => $properties['display_risk_label'] ?? null,
                    'source_agency' => $properties['source_agency'] ?? 'DENR-MGB',
                    'reference_status' => self::DISPLAY_STATUS,
                ],
            ];
        }

        if (count($features) !== 28) {
            throw new RuntimeException('The admin hazard reference does not contain the expected feature count.');
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
