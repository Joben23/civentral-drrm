<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Future TensorFlow integration boundary. No implementation is provided in
 * the development weather/susceptibility baseline.
 */
interface FloodRiskPredictorInterface
{
    public function isAvailable(): bool;

    /**
     * @param array<string, mixed> $weatherInputs
     * @param array{latitude: float, longitude: float, mapped_susceptibility: ?string} $locationContext
     * @param array<string, mixed> $historicalInputs
     * @return array<string, mixed>
     */
    public function predict(
        array $weatherInputs,
        array $locationContext,
        array $historicalInputs
    ): array;
}
