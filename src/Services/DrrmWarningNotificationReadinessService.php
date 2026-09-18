<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

require_once __DIR__ . '/DrrmDataStoreInterface.php';

/** Fail-closed interpretation of the service-role-only schema verifier. */
final class DrrmWarningNotificationReadinessService
{
    private const REQUIRED_CHECKS = [
        'table_exists', 'columns_valid', 'id_default_valid', 'read_at_default_valid',
        'activation_fk_restrict_valid', 'one_receipt_unique_valid',
        'citizen_reference_check_valid', 'rls_enabled', 'service_role_select_only',
        'browser_table_privileges_absent', 'public_table_privileges_absent',
        'functions_hardened', 'service_role_execute', 'browser_execute_absent',
        'public_execute_absent',
    ];

    public function __construct(private readonly DrrmDataStoreInterface $store)
    {
    }

    public function isAvailable(): bool
    {
        try {
            $catalog = $this->store->rpc('verify_module4_warning_notification_reads_schema');
        } catch (Throwable) {
            return false;
        }
        if (array_is_list($catalog) && count($catalog) === 1 && is_array($catalog[0])) {
            $catalog = $catalog[0];
        }
        if (array_is_list($catalog)) {
            return false;
        }
        foreach (self::REQUIRED_CHECKS as $check) {
            if (($catalog[$check] ?? null) !== true) {
                return false;
            }
        }
        return true;
    }
}
