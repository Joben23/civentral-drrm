<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Canonical fail-closed resolver for DRRM barangay assignment scope.
 *
 * Trust the server-side CIVentral application identity only. The assignment
 * table stores user_reference TEXT and links assigned barangay_id to
 * public.barangays. No auth.users FK, no user_id/email/employee matching.
 */
final class DrrmBarangayAssignmentResolver
{
    public function __construct(private readonly DrrmDataStoreInterface $client) {}

    public function activeBarangayIdForUser(string $userReference): ?string
    {
        $userReference = trim($userReference);
        if ($userReference === '') {
            return null;
        }

        $rows = $this->client->get('drrm_barangay_user_assignments', [
            'select' => 'barangay_id',
            'user_reference' => 'eq.' . $userReference,
            'is_active' => 'eq.true',
            'limit' => '1',
        ]);

        if (!isset($rows[0]['barangay_id'])) {
            return null;
        }

        $barangayId = trim((string) $rows[0]['barangay_id']);
        return $barangayId !== '' ? $barangayId : null;
    }
}
