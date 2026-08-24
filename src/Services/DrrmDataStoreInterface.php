<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Narrow data boundary used by DRRM services and isolated service tests.
 */
interface DrrmDataStoreInterface
{
    /** @param array<string, scalar> $query @return array<mixed> */
    public function get(string $resource, array $query = []): array;

    /** @param array<mixed> $payload @param array<string, scalar> $query @return array<mixed> */
    public function post(string $resource, array $payload, array $query = []): array;

    /** @param array<string, mixed> $payload @return array<mixed> */
    public function rpc(string $function, array $payload = []): array;
}
