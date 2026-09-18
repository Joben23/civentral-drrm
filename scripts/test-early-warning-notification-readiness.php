<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/Services/DrrmWarningNotificationReadinessService.php';

use App\Services\DrrmDataStoreInterface;
use App\Services\DrrmWarningNotificationReadinessService;

final class ReadinessTestStore implements DrrmDataStoreInterface
{
    public array $result = [];
    public bool $unavailable = false;
    public int $rpcCount = 0;

    public function get(string $resource, array $query = []): array
    {
        throw new RuntimeException('Readiness must not read a table directly.');
    }

    public function post(string $resource, array $payload, array $query = []): array
    {
        throw new RuntimeException('Readiness must not write a table.');
    }

    public function rpc(string $function, array $payload = []): array
    {
        if ($function !== 'verify_module4_warning_notification_reads_schema' || $payload !== []) {
            throw new RuntimeException('Unexpected readiness RPC.');
        }
        $this->rpcCount++;
        if ($this->unavailable) throw new RuntimeException('Isolated unavailable verifier.');
        return $this->result;
    }
}

function expectReadiness(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$store = new ReadinessTestStore();
$service = new DrrmWarningNotificationReadinessService($store);
expectReadiness(!$service->isAvailable(), 'Missing schema was reported available.');
$store->unavailable = true;
expectReadiness(!$service->isAvailable(), 'Unavailable verifier was reported available.');
$store->unavailable = false;
$checks = [
    'table_exists', 'columns_valid', 'id_default_valid', 'read_at_default_valid',
    'activation_fk_restrict_valid', 'one_receipt_unique_valid',
    'citizen_reference_check_valid', 'rls_enabled', 'service_role_select_only',
    'browser_table_privileges_absent', 'public_table_privileges_absent',
    'functions_hardened', 'service_role_execute', 'browser_execute_absent',
    'public_execute_absent',
];
$allTrue = array_fill_keys($checks, true);
$store->result = $allTrue;
expectReadiness($service->isAvailable(), 'Complete valid schema was reported unavailable.');
foreach ($checks as $check) {
    $store->result = $allTrue;
    $store->result[$check] = false;
    expectReadiness(!$service->isAvailable(), 'Invalid catalog check passed: ' . $check);
}
$store->result = [$allTrue];
expectReadiness($service->isAvailable(), 'Single-row verifier response was not accepted.');
echo 'Module 4 in-app readiness evaluator: OK' . PHP_EOL;
