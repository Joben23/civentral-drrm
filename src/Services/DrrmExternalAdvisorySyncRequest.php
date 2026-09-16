<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Browser request boundary for server-owned external advisory adapters.
 */
final class DrrmExternalAdvisorySyncRequest
{
    /** @param mixed $input */
    public static function sourceCodeFromInput(mixed $input): string
    {
        if (!is_array($input)
            || array_is_list($input)
            || array_diff(array_keys($input), ['source_code']) !== []
            || !is_string($input['source_code'] ?? null)) {
            throw new InvalidArgumentException('Unsupported external advisory provider.');
        }

        $sourceCode = strtoupper(trim($input['source_code']));
        if ($sourceCode !== 'PAGASA') {
            throw new InvalidArgumentException('Unsupported external advisory provider.');
        }

        return $sourceCode;
    }
}
