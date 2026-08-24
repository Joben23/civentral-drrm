<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Server-side TensorFlow integration boundary. Implementations must fail
 * closed and must not treat model unavailability as low risk.
 */
interface FloodRiskPredictorInterface
{
    public function isAvailable(): bool;

    /**
     * @param array<string, mixed> $weatherInputs
     * @param array{barangay_id: string, latitude: float, longitude: float, mapped_susceptibility: ?string} $locationContext
     * @param array<string, mixed> $historicalInputs
     * @return array<string, mixed>
     */
    public function predict(
        array $weatherInputs,
        array $locationContext,
        array $historicalInputs
    ): array;
}
