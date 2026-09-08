<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Public staging-preview projection of the shared controlled flood evaluator. */
final class DrrmCitizenFloodReferenceCheckService
{
    public const SOURCE_STATUS = 'DEVELOPMENT_PREVIEW';
    public const INTERSECTION_STATUS = 'DEVELOPMENT_REFERENCE';
    public const WARNING = 'This does not mean the location is flood-safe. The current reference dataset is incomplete/draft and does not replace official advisories.';

    public function __construct(
        private readonly DrrmFloodReferenceEvaluatorService $evaluator,
        bool $publicPreviewAllowed
    ) {
        if (!$publicPreviewAllowed) {
            throw new RuntimeException('The citizen flood reference preview is unavailable.');
        }
    }

    /** @return array<string, mixed> */
    public function check(float $latitude, float $longitude): array
    {
        $evaluated = $this->evaluator->evaluate($latitude, $longitude);
        $response = [
            'status' => $evaluated['intersection']
                ? self::INTERSECTION_STATUS
                : DrrmFloodReferenceEvaluatorService::NO_INTERSECTION_STATUS,
            'intersection' => $evaluated['intersection'],
            'source_status' => self::SOURCE_STATUS,
            'reference_source' => $evaluated['reference_source'],
            'location' => $evaluated['location'],
            'warning' => self::WARNING,
        ];

        if (!$evaluated['intersection']) {
            return $response;
        }

        return $response + [
            'classification' => $evaluated['classification'],
            'risk_rank' => $evaluated['risk_rank'],
            'overlap_count' => $evaluated['overlap_count'],
            'multiple_reference_polygons' => $evaluated['multiple_reference_polygons'],
        ];
    }
}
