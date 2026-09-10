<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Production default until an approved live 30-minute rainfall source exists.
 * It deliberately returns no observations and never fabricates a history.
 */
final class DrrmNoApprovedLiveRainfallHistoryProvider implements DrrmRainfallHistoryProviderInterface
{
    public function history(): array
    {
        return [
            'status' => 'UNAVAILABLE',
            'source_id' => 'NO_APPROVED_LIVE_SOURCE',
            'source_semantics' => null,
            'history' => null,
        ];
    }
}
