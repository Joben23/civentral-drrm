<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Authenticated staging-admin projection of the shared flood evaluator.
 *
 * Authorization and CSRF remain endpoint responsibilities; this wrapper keeps
 * the established admin response contract and source-status terminology.
 */
final class DrrmAdminFloodReferenceCheckService
{
    public const SOURCE_STATUS = 'DRAFT_ADMIN_REFERENCE';
    public const NO_INTERSECTION_STATUS = DrrmFloodReferenceEvaluatorService::NO_INTERSECTION_STATUS;

    private readonly DrrmFloodReferenceEvaluatorService $evaluator;

    /**
     * @param callable(): array{type: string, features: list<array<string, mixed>>} $referenceLoader
     */
    public function __construct(
        callable $referenceLoader,
        DrrmCaloocanBoundaryService $cityBoundary,
        bool $stagingAdminCheckAllowed
    ) {
        if (!$stagingAdminCheckAllowed) {
            throw new RuntimeException('The admin flood reference check is unavailable.');
        }

        $this->evaluator = new DrrmFloodReferenceEvaluatorService($referenceLoader, $cityBoundary);
    }

    /** @return array<string, mixed> */
    public function check(float $latitude, float $longitude): array
    {
        $evaluated = $this->evaluator->evaluate($latitude, $longitude);
        $reference = [
            'hazard_type' => $evaluated['hazard_type'],
            'source_status' => self::SOURCE_STATUS,
            'source_organization' => $evaluated['reference_source'],
        ];

        if (!$evaluated['intersection']) {
            return [
                'status' => self::NO_INTERSECTION_STATUS,
                'intersection' => false,
                'location' => $evaluated['location'],
                'reference' => $reference,
            ];
        }

        return [
            'status' => self::SOURCE_STATUS,
            'intersection' => true,
            'classification' => $evaluated['classification'],
            'risk_rank' => $evaluated['risk_rank'],
            'overlap_count' => $evaluated['overlap_count'],
            'multiple_reference_polygons' => $evaluated['multiple_reference_polygons'],
            'location' => $evaluated['location'],
            'reference' => $reference,
        ];
    }
}
