<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/DrrmDataStoreInterface.php';

final class DrrmIncidentValidationException extends RuntimeException
{
}

final class DrrmIncidentLifecycleException extends RuntimeException
{
}

final class DrrmIncidentWriteException extends RuntimeException
{
}

/**
 * Server-only boundary for controlled Module 3 operational actions.
 */
final class DrrmIncidentWriteService
{
    /** @var list<string> */
    private const STATUSES = [
        'SUBMITTED', 'UNDER_REVIEW', 'VERIFIED', 'ASSIGNED',
        'RESPONDING', 'RESOLVED', 'CLOSED', 'REJECTED',
    ];

    /** @var array<string, list<string>> */
    public const ACTION_FROM_STATUSES = [
        'REVIEW' => ['SUBMITTED'],
        'VERIFY' => ['UNDER_REVIEW'],
        'ASSIGN' => ['VERIFIED'],
        'RESOLVE' => ['RESPONDING'],
        'CLOSE' => ['RESOLVED'],
        'REJECT' => ['SUBMITTED', 'UNDER_REVIEW'],
    ];

    /** @var list<string> */
    public const RESPONSE_ACTION_TYPES = ['DISPATCH_NOTE', 'RESPONSE_UPDATE'];

    public function __construct(private readonly DrrmDataStoreInterface $store)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function transition(array $input, string $actorReference): array
    {
        $allowedKeys = [
            'incident_id', 'action', 'notes',
            'assigned_department_reference', 'assigned_user_reference',
        ];
        if (array_diff(array_keys($input), $allowedKeys) !== []) {
            throw new DrrmIncidentValidationException('Unsupported incident action fields were supplied.');
        }

        $incidentId = $this->uuid($input['incident_id'] ?? null);
        $action = $this->controlledCode(
            $input['action'] ?? null,
            array_keys(self::ACTION_FROM_STATUSES),
            'Invalid incident lifecycle action.'
        );
        $actorReference = $this->reference($actorReference, 'Invalid incident actor reference.', false) ?? '';
        $notes = $this->plainText($input['notes'] ?? null, 2000, true, 'Invalid incident action note.');
        $departmentReference = $this->reference(
            $input['assigned_department_reference'] ?? null,
            'Invalid department reference.',
            true
        );
        $userReference = $this->reference(
            $input['assigned_user_reference'] ?? null,
            'Invalid user reference.',
            true
        );

        if ($action === 'ASSIGN') {
            if ($departmentReference === null && $userReference === null) {
                throw new DrrmIncidentValidationException('A department or user assignment reference is required.');
            }
        } elseif ($departmentReference !== null || $userReference !== null) {
            throw new DrrmIncidentValidationException('Assignment references are only accepted for assignment actions.');
        }

        if (in_array($action, ['RESOLVE', 'REJECT'], true) && $notes === null) {
            throw new DrrmIncidentValidationException(
                $action === 'RESOLVE' ? 'A resolution note is required.' : 'A rejection reason is required.'
            );
        }

        $currentStatus = $this->currentStatus($incidentId);
        if (!in_array($currentStatus, self::ACTION_FROM_STATUSES[$action], true)) {
            throw new DrrmIncidentLifecycleException($this->lifecycleMessage($action));
        }

        try {
            $result = $this->store->rpc('transition_drrm_incident', [
                'p_incident_id' => $incidentId,
                'p_action' => $action,
                'p_actor_reference' => $actorReference,
                'p_notes' => $notes,
                'p_assigned_department_reference' => $departmentReference,
                'p_assigned_user_reference' => $userReference,
            ]);
        } catch (Throwable $exception) {
            throw new DrrmIncidentWriteException('Unable to update the incident lifecycle.', 0, $exception);
        }

        return $this->validatedRpcResult($result, $incidentId, $currentStatus);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addResponse(array $input, string $actorReference): array
    {
        $allowedKeys = ['incident_id', 'action_type', 'message'];
        if (array_diff(array_keys($input), $allowedKeys) !== []) {
            throw new DrrmIncidentValidationException('Unsupported response-log fields were supplied.');
        }

        $incidentId = $this->uuid($input['incident_id'] ?? null);
        $actionType = $this->controlledCode(
            $input['action_type'] ?? null,
            self::RESPONSE_ACTION_TYPES,
            'Invalid response action type.'
        );
        $message = $this->plainText($input['message'] ?? null, 5000, false, 'A valid response message is required.');
        $actorReference = $this->reference($actorReference, 'Invalid incident actor reference.', false) ?? '';
        $currentStatus = $this->currentStatus($incidentId);

        if (!in_array($currentStatus, ['ASSIGNED', 'RESPONDING'], true)) {
            throw new DrrmIncidentLifecycleException(
                'Response updates require an assigned or responding incident.'
            );
        }
        if ($currentStatus === 'ASSIGNED' && $actionType !== 'DISPATCH_NOTE') {
            throw new DrrmIncidentLifecycleException('The first response entry must be a dispatch note.');
        }

        try {
            $result = $this->store->rpc('add_drrm_incident_response', [
                'p_incident_id' => $incidentId,
                'p_action_type' => $actionType,
                'p_message' => $message,
                'p_actor_reference' => $actorReference,
            ]);
        } catch (Throwable $exception) {
            throw new DrrmIncidentWriteException('Unable to record the incident response.', 0, $exception);
        }

        return $this->validatedRpcResult($result, $incidentId, $currentStatus);
    }

    public static function actorReferenceFromSession(): string
    {
        $prefix = null;
        $value = null;
        if (isset($_SESSION['user_id']) && is_scalar($_SESSION['user_id'])) {
            $prefix = 'USER';
            $value = trim((string) $_SESSION['user_id']);
        } elseif (isset($_SESSION['employee_id']) && is_scalar($_SESSION['employee_id'])) {
            $prefix = 'EMPLOYEE';
            $value = trim((string) $_SESSION['employee_id']);
        }

        if ($prefix === null || $value === ''
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,149}$/', $value) !== 1) {
            throw new DrrmIncidentValidationException('The authenticated user reference is invalid.');
        }

        return $prefix . ':' . $value;
    }

    private function currentStatus(string $incidentId): string
    {
        try {
            $records = $this->store->get('drrm_incidents', [
                'select' => 'id,status',
                'id' => 'eq.' . $incidentId,
                'limit' => 2,
            ]);
        } catch (Throwable $exception) {
            throw new DrrmIncidentWriteException('Unable to load the incident lifecycle.', 0, $exception);
        }

        if (count($records) !== 1 || !is_array($records[0]) || array_is_list($records[0])) {
            throw new DrrmIncidentLifecycleException('The incident could not be found.');
        }
        $status = $records[0]['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new DrrmIncidentWriteException('The incident lifecycle is invalid.');
        }
        return $status;
    }

    /** @param array<mixed> $result @return array<string, mixed> */
    private function validatedRpcResult(array $result, string $incidentId, string $previousStatus): array
    {
        if (array_is_list($result) && count($result) === 1 && is_array($result[0])) {
            $result = $result[0];
        }
        if (array_is_list($result)
            || ($result['id'] ?? null) !== $incidentId
            || ($result['previous_status'] ?? null) !== $previousStatus
            || !is_string($result['status'] ?? null)
            || !in_array($result['status'], self::STATUSES, true)) {
            throw new DrrmIncidentWriteException('The incident action returned an unexpected result.');
        }
        return $result;
    }

    private function lifecycleMessage(string $action): string
    {
        return match ($action) {
            'REVIEW' => 'Only a submitted incident can enter review.',
            'VERIFY' => 'Only an incident under review can be verified.',
            'ASSIGN' => 'Only a verified incident can be assigned.',
            'RESOLVE' => 'Only an incident with an active response can be resolved.',
            'CLOSE' => 'Only a resolved incident can be closed.',
            'REJECT' => 'This incident can no longer be rejected.',
            default => 'The incident lifecycle action is invalid.',
        };
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) !== 1) {
            throw new DrrmIncidentValidationException('Invalid incident identifier.');
        }
        return strtolower($value);
    }

    /** @param list<string> $allowed */
    private function controlledCode(mixed $value, array $allowed, string $message): string
    {
        if (!is_string($value)) {
            throw new DrrmIncidentValidationException($message);
        }
        $value = strtoupper(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new DrrmIncidentValidationException($message);
        }
        return $value;
    }

    private function reference(mixed $value, string $message, bool $nullable): ?string
    {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        if (!is_string($value)) {
            throw new DrrmIncidentValidationException($message);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 200
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,199}$/', $value) !== 1) {
            throw new DrrmIncidentValidationException($message);
        }
        return $value;
    }

    private function plainText(
        mixed $value,
        int $maxLength,
        bool $nullable,
        string $message
    ): ?string {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        if (!is_string($value)) {
            throw new DrrmIncidentValidationException($message);
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength
            || str_contains($value, '<') || str_contains($value, '>')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new DrrmIncidentValidationException($message);
        }
        return $value;
    }
}
