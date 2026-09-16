<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class ExternalAdvisoryProviderException extends \RuntimeException
{
}

/**
 * Bounded provider result before the shared normalizer and staging RPC.
 */
final class ExternalAdvisoryFetchResult
{
    public const RESULT_SUCCESS = 'SUCCESS';
    public const RESULT_PARTIAL = 'PARTIAL';
    public const RESULT_FAILED = 'FAILED';
    public const RESULT_SKIPPED = 'SKIPPED';
    public const MAX_ITEMS = 100;

    /** @var list<array<string, mixed>> */
    private array $items;

    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        private readonly string $result,
        array $items,
        private readonly int $errorCount = 0,
        private ?string $errorSummary = null
    ) {
        if (!in_array($result, [
            self::RESULT_SUCCESS,
            self::RESULT_PARTIAL,
            self::RESULT_FAILED,
            self::RESULT_SKIPPED,
        ], true)) {
            throw new InvalidArgumentException('The external advisory fetch result is invalid.');
        }
        if (!array_is_list($items) || count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('The external advisory fetch item count is invalid.');
        }
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) {
                throw new InvalidArgumentException('An external advisory provider item is invalid.');
            }
        }
        if ($errorCount < 0 || $errorCount > self::MAX_ITEMS) {
            throw new InvalidArgumentException('The external advisory error count is invalid.');
        }
        if (count($items) + $errorCount > self::MAX_ITEMS) {
            throw new InvalidArgumentException('The combined external advisory result count is invalid.');
        }

        $summary = self::safeSummary($errorSummary);
        if ($result === self::RESULT_SUCCESS && ($errorCount !== 0 || $summary !== null)) {
            throw new InvalidArgumentException('A successful fetch cannot contain provider errors.');
        }
        if ($result === self::RESULT_PARTIAL && ($items === [] || $errorCount < 1 || $summary === null)) {
            throw new InvalidArgumentException('A partial fetch must contain items and a safe error summary.');
        }
        if ($result === self::RESULT_FAILED && ($items !== [] || $errorCount < 1 || $summary === null)) {
            throw new InvalidArgumentException('A failed fetch result is inconsistent.');
        }
        if ($result === self::RESULT_SKIPPED && ($items !== [] || $errorCount !== 0 || $summary === null)) {
            throw new InvalidArgumentException('A skipped fetch result is inconsistent.');
        }

        $this->items = array_values($items);
        $this->errorSummary = $summary;
    }

    /** @param list<array<string, mixed>> $items */
    public static function success(array $items): self
    {
        return new self(self::RESULT_SUCCESS, $items);
    }

    /** @param list<array<string, mixed>> $items */
    public static function partial(array $items, int $errorCount, string $safeSummary): self
    {
        return new self(self::RESULT_PARTIAL, $items, $errorCount, $safeSummary);
    }

    public static function failed(string $safeSummary, int $errorCount = 1): self
    {
        return new self(self::RESULT_FAILED, [], $errorCount, $safeSummary);
    }

    public static function skipped(string $safeSummary): self
    {
        return new self(self::RESULT_SKIPPED, [], 0, $safeSummary);
    }

    public function result(): string
    {
        return $this->result;
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function fetchedItemCount(): int
    {
        return count($this->items);
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function errorSummary(): ?string
    {
        return $this->errorSummary;
    }

    private static function safeSummary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $summary = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($summary)) ?? '';
        $summary = preg_replace('/\s+/u', ' ', $summary) ?? '';
        if ($summary === '') {
            return null;
        }

        return mb_substr($summary, 0, 1000);
    }
}
