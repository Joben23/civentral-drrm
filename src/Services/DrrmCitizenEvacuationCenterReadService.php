<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppEnvironment;
use JsonException;
use RuntimeException;

require_once __DIR__ . '/../../config/app_environment.php';
require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/DrrmCitizenHazardMapReadService.php';
require_once __DIR__ . '/DrrmMapReadService.php';
require_once __DIR__ . '/DrrmDraftEvacuationCenterPreviewService.php';
require_once __DIR__ . '/DrrmDraftBarangayPreviewService.php';

/**
 * Public, read-only evacuation-center projection for the citizen map.
 */
final class DrrmCitizenEvacuationCenterReadService
{
    public function __construct(
        private readonly DrrmDataStoreInterface $client,
        private readonly ?string $envFile = null
    ) {
    }

    /** @return array<string, mixed> */
    public function response(): array
    {
        $operational = (new DrrmMapReadService($this->client))->evacuationCenters();
        if ($operational !== []) {
            return [
                'city' => DrrmCitizenHazardMapReadService::CITY_NAME,
                'layer' => 'evacuation-centers',
                'source_status' => 'OPERATIONAL',
                'verification_status' => 'VERIFIED',
                'count' => count($operational),
                'items' => array_map(fn (array $record): array => $this->operationalItem($record), $operational),
            ];
        }

        if (!AppEnvironment::isStaging($this->envFile)
            || !AppEnvironment::isPublicDrrmPreviewEnabled($this->envFile)) {
            return $this->emptyResponse();
        }

        $preview = (new DrrmDraftEvacuationCenterPreviewService($this->client, true))->featureCollection();
        $items = [];
        foreach ($preview['features'] as $feature) {
            $properties = $feature['properties'] ?? null;
            $geometry = $feature['geometry'] ?? null;
            if (!is_array($properties) || !is_array($geometry)
                || ($geometry['type'] ?? null) !== 'Point'
                || !is_array($geometry['coordinates'] ?? null)) {
                throw new RuntimeException('The controlled evacuation-center preview is malformed.');
            }
            $items[] = [
                'reference_id' => $properties['evacuation_center_id'],
                'name' => $properties['name'],
                'barangay_name' => $properties['barangay_name'],
                'latitude' => (float) $geometry['coordinates'][1],
                'longitude' => (float) $geometry['coordinates'][0],
                'source_status' => 'DEVELOPMENT_PREVIEW',
                'verification_status' => 'UNVERIFIED_REFERENCE',
            ];
        }

        return [
            'city' => DrrmCitizenHazardMapReadService::CITY_NAME,
            'layer' => 'evacuation-centers',
            'source_status' => 'DEVELOPMENT_PREVIEW',
            'verification_status' => 'UNVERIFIED_REFERENCE',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyResponse(): array
    {
        return [
            'city' => DrrmCitizenHazardMapReadService::CITY_NAME,
            'layer' => 'evacuation-centers',
            'source_status' => 'NONE',
            'verification_status' => 'NONE',
            'count' => 0,
            'items' => [],
        ];
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function operationalItem(array $record): array
    {
        $location = $record['location'] ?? null;
        if (is_string($location)) {
            try {
                $location = json_decode($location, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('An operational evacuation-center location is invalid.', 0, $exception);
            }
        }
        if (!is_array($location) || ($location['type'] ?? null) !== 'Point'
            || !is_array($location['coordinates'] ?? null) || count($location['coordinates']) !== 2) {
            throw new RuntimeException('An operational evacuation-center location is invalid.');
        }

        return [
            'reference_id' => $record['evacuation_center_id'],
            'name' => $record['name'],
            'address' => $record['address'] ?? null,
            'capacity' => $record['capacity'] ?? null,
            'managing_office' => $record['managing_office_name'] ?? null,
            'latitude' => (float) $location['coordinates'][1],
            'longitude' => (float) $location['coordinates'][0],
            'source_status' => 'OPERATIONAL',
            'verification_status' => 'VERIFIED',
        ];
    }
}
