<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmDataStoreInterface.php';
require_once __DIR__ . '/DrrmExternalAdvisoryNormalizer.php';
require_once __DIR__ . '/ExternalAdvisoryProviderInterface.php';
require_once __DIR__ . '/ExternalAdvisoryFetchResult.php';
require_once __DIR__ . '/SupabaseRestException.php';

final class DrrmExternalAdvisoryIngestionException extends RuntimeException
{
}

/**
 * Provider-neutral, server-only staging coordinator.
 *
 * It records one auditable fetch run through a transactionally restricted RPC.
 * It has no dependency on the warning write service and cannot activate or
 * create a CIVENTRAL warning.
 */
final class DrrmExternalAdvisoryIngestionService
{
    public const MAX_STAGING_ITEMS_JSON_BYTES = 4194304;

    public function __construct(
        private readonly DrrmDataStoreInterface $client,
        private readonly DrrmExternalAdvisoryNormalizer $normalizer = new DrrmExternalAdvisoryNormalizer()
    ) {
    }

    /** @return array<string, mixed> */
    public function synchronize(
        ExternalAdvisoryProviderInterface $provider,
        string $actorReference
    ): array {
        $actorReference = trim($actorReference);
        if (preg_match('/^(?:USER|EMPLOYEE):[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,149}$/', $actorReference) !== 1) {
            throw new DrrmExternalAdvisoryIngestionException(
                'A trusted external-advisory synchronization actor is required.'
            );
        }

        $sourceCode = strtoupper(trim($provider->sourceCode()));
        if (preg_match('/^[A-Z][A-Z0-9_]{0,49}$/', $sourceCode) !== 1) {
            throw new DrrmExternalAdvisoryIngestionException('The external advisory provider is invalid.');
        }

        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $providerResult = $provider->fetch();
        } catch (ExternalAdvisoryProviderException) {
            $providerResult = ExternalAdvisoryFetchResult::failed(
                'The external advisory provider request failed safely.'
            );
        } catch (Throwable) {
            $providerResult = ExternalAdvisoryFetchResult::failed(
                'The external advisory provider returned an unexpected error.'
            );
        }

        $normalizedItems = [];
        $seenIdentities = [];
        $normalizationErrors = 0;
        foreach ($providerResult->items() as $providerItem) {
            try {
                $normalized = $this->normalizer->normalize($sourceCode, $providerItem);
                $identity = (string) $normalized['deduplication_key'];
                if (isset($seenIdentities[$identity])) {
                    $normalizationErrors++;
                    continue;
                }
                $seenIdentities[$identity] = true;
                $normalizedItems[] = $normalized;
            } catch (DrrmExternalAdvisoryValidationException) {
                $normalizationErrors++;
            }
        }

        try {
            $encodedItems = json_encode(
                $normalizedItems,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new DrrmExternalAdvisoryIngestionException(
                'The external advisory batch could not be encoded safely.',
                0,
                $exception
            );
        }
        if (strlen($encodedItems) > self::MAX_STAGING_ITEMS_JSON_BYTES) {
            throw new DrrmExternalAdvisoryIngestionException(
                'The external advisory batch exceeds the safe aggregate size limit.'
            );
        }

        $errorCount = $providerResult->errorCount() + $normalizationErrors;
        $result = $providerResult->result();
        $errorSummary = $providerResult->errorSummary();
        if ($normalizationErrors > 0) {
            $result = $normalizedItems === []
                ? ExternalAdvisoryFetchResult::RESULT_FAILED
                : ExternalAdvisoryFetchResult::RESULT_PARTIAL;
            $errorSummary = 'One or more external provider items failed safe normalization.';
        }

        $payload = [
            'p_source_code' => $sourceCode,
            'p_started_at' => $startedAt->format('Y-m-d\TH:i:sP'),
            'p_result' => $result,
            'p_fetched_item_count' => $providerResult->fetchedItemCount(),
            'p_items' => $normalizedItems,
            'p_error_count' => $errorCount,
            'p_error_summary' => $errorSummary,
            'p_actor_reference' => $actorReference,
        ];

        try {
            $rpcResult = $this->client->rpc('stage_module4_external_advisory_fetch', $payload);
        } catch (SupabaseRestException $exception) {
            if (in_array($exception->sqlState(), ['22023', '23502', '23503', '23505', '23514'], true)) {
                throw new DrrmExternalAdvisoryIngestionException(
                    'The external advisory fetch failed database validation.',
                    0,
                    $exception
                );
            }
            throw new DrrmExternalAdvisoryIngestionException(
                'The external advisory fetch could not be recorded.',
                0,
                $exception
            );
        } catch (Throwable $exception) {
            throw new DrrmExternalAdvisoryIngestionException(
                'The external advisory fetch could not be recorded.',
                0,
                $exception
            );
        }

        if (array_is_list($rpcResult)) {
            if (count($rpcResult) !== 1 || !is_array($rpcResult[0])) {
                throw new DrrmExternalAdvisoryIngestionException(
                    'The external advisory staging response was invalid.'
                );
            }
            $rpcResult = $rpcResult[0];
        }
        if (($rpcResult['outcome'] ?? null) !== 'RECORDED'
            || !$this->isUuid((string) ($rpcResult['fetch_run_id'] ?? ''))
            || ($rpcResult['source_code'] ?? null) !== $sourceCode
            || ($rpcResult['result'] ?? null) !== $result) {
            throw new DrrmExternalAdvisoryIngestionException(
                'The external advisory staging response was invalid.'
            );
        }

        return [
            'fetch_run_id' => (string) $rpcResult['fetch_run_id'],
            'source_code' => $sourceCode,
            'result' => $result,
            'fetched_item_count' => (int) ($rpcResult['fetched_item_count'] ?? 0),
            'staged_item_count' => (int) ($rpcResult['staged_item_count'] ?? 0),
            'new_item_count' => (int) ($rpcResult['new_item_count'] ?? 0),
            'updated_item_count' => (int) ($rpcResult['updated_item_count'] ?? 0),
            'unchanged_item_count' => (int) ($rpcResult['unchanged_item_count'] ?? 0),
            'error_count' => (int) ($rpcResult['error_count'] ?? 0),
            'warning_created' => false,
            'warning_activated' => false,
        ];
    }

    private function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
