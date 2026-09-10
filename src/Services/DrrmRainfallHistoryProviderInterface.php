<?php

declare(strict_types=1);

namespace App\Services;

interface DrrmRainfallHistoryProviderInterface
{
    /**
     * @return array{
     *     status: 'AVAILABLE'|'UNAVAILABLE'|'INCOMPATIBLE',
     *     source_id: string,
     *     source_semantics: array<string, mixed>|null,
     *     history: list<array<string, mixed>>|null
     * }
     */
    public function history(): array;
}
