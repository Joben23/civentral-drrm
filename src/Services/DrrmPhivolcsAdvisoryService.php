<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Read-only Module 4 availability projection for official DOST-PHIVOLCS
 * information.
 *
 * Current earthquake, volcano, and tsunami products are published through
 * official human-readable channels. The confirmed PHIVOLCS ArcGIS services
 * expose static hazard/reference data, not a documented operational event or
 * advisory feed. This service therefore performs no scraping or upstream
 * request and must not synthesize event records from those pages or layers.
 */
final class DrrmPhivolcsAdvisoryService
{
    public const EARTHQUAKE_INFORMATION_REFERENCE =
        'https://earthquake.phivolcs.dost.gov.ph/EQLatest.html';
    public const VOLCANO_BULLETIN_REFERENCE =
        'https://wovodat.phivolcs.dost.gov.ph/bulletin/list-of-bulletin';
    public const TSUNAMI_INFORMATION_REFERENCE =
        'https://tsunami.phivolcs.dost.gov.ph/';
    public const ACTIVE_FAULT_REFERENCE =
        'https://gisweb.phivolcs.dost.gov.ph/arcgis/rest/services/PHIVOLCS/ActiveFault/MapServer';

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return [
            'source' => [
                'agency' => 'DOST-PHIVOLCS',
                'organization' => 'Philippine Institute of Volcanology and Seismology',
            ],
            'machine_readable_source_status' => 'NOT_CONFIRMED',
            'runtime_status' => 'INTEGRATION_PENDING',
            'official_publication_channels' => [
                [
                    'product' => 'EARTHQUAKE_INFORMATION',
                    'type' => 'HUMAN_READABLE_WEB_PAGE',
                    'reference' => self::EARTHQUAKE_INFORMATION_REFERENCE,
                ],
                [
                    'product' => 'VOLCANO_BULLETIN',
                    'type' => 'HUMAN_READABLE_WEB_PAGE',
                    'reference' => self::VOLCANO_BULLETIN_REFERENCE,
                ],
                [
                    'product' => 'TSUNAMI_INFORMATION',
                    'type' => 'HUMAN_READABLE_WEB_PAGE',
                    'reference' => self::TSUNAMI_INFORMATION_REFERENCE,
                ],
            ],
            'static_hazard_reference' => [
                'type' => 'ARCGIS_STATIC_HAZARD_SERVICE',
                'reference' => self::ACTIVE_FAULT_REFERENCE,
                'operational_event_feed' => false,
                'note' => 'Static active-fault geometry is not an earthquake event or advisory feed.',
            ],
            'events' => [],
            'relevance' => [
                'status' => 'NOT_APPLIED_NO_FEED',
                'supported_classifications' => [
                    'CALOOCAN',
                    'NCR',
                    'NEARBY',
                    'NATIONWIDE',
                    'OTHER',
                    'UNKNOWN',
                ],
            ],
            'message' => 'No applicable PHIVOLCS information is available through a confirmed official machine-readable source.',
            'upstream_request_attempted' => false,
            'external_information_only' => true,
        ];
    }
}
