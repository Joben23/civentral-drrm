<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class DrrmIncidentAuthorizationException extends RuntimeException
{
}

/**
 * Module 3 authorization based only on the trusted, server-built permission map.
 */
final class DrrmIncidentAuthorizationService
{
    public const RESOURCE = 'incident reporting & response log';
    public const ACTION_VIEW = 'VIEW';
    public const ACTION_REVIEW = 'REVIEW_INCIDENT';
    public const ACTION_VERIFY = 'VERIFY_INCIDENT';
    public const ACTION_ASSIGN = 'ASSIGN_INCIDENT';
    public const ACTION_UPDATE_RESPONSE = 'UPDATE_RESPONSE';
    public const ACTION_RESOLVE = 'RESOLVE_INCIDENT';
    public const ACTION_CLOSE = 'CLOSE_INCIDENT';
    public const ACTION_REJECT = 'REJECT_INCIDENT';

    /** @var list<string> */
    private const ACTIONS = [
        self::ACTION_VIEW,
        self::ACTION_REVIEW,
        self::ACTION_VERIFY,
        self::ACTION_ASSIGN,
        self::ACTION_UPDATE_RESPONSE,
        self::ACTION_RESOLVE,
        self::ACTION_CLOSE,
        self::ACTION_REJECT,
    ];

    /** @var array<string, string> */
    private const LIFECYCLE_ACTION_PERMISSIONS = [
        'REVIEW' => self::ACTION_REVIEW,
        'VERIFY' => self::ACTION_VERIFY,
        'ASSIGN' => self::ACTION_ASSIGN,
        'RESOLVE' => self::ACTION_RESOLVE,
        'CLOSE' => self::ACTION_CLOSE,
        'REJECT' => self::ACTION_REJECT,
    ];

    /** @var list<string> */
    private array $resourceActions;

    /** @param list<string> $resourceActions */
    public function __construct(array $resourceActions, private readonly bool $isSuperadmin)
    {
        $normalized = [];
        foreach ($resourceActions as $action) {
            if (!is_string($action)) {
                continue;
            }
            $action = strtoupper(trim($action));
            if (in_array($action, self::ACTIONS, true)) {
                $normalized[] = $action;
            }
        }
        $this->resourceActions = array_values(array_unique($normalized));
    }

    /** @param array<string, mixed>|null $trustedHeaderUser */
    public static function fromTrustedSession(?array $trustedHeaderUser = null): self
    {
        $permissionMap = $_SESSION['user_permissions_map'] ?? [];
        $resourceActions = [];

        if (is_array($permissionMap)) {
            foreach ($permissionMap as $resource => $actions) {
                if (!is_string($resource) || self::normalizeResource($resource) !== self::RESOURCE) {
                    continue;
                }
                if (is_array($actions)) {
                    $resourceActions = array_values($actions);
                }
                break;
            }
        }

        $currentUser = $_SESSION['current_user_details'] ?? [];
        $currentUser = is_array($currentUser) ? $currentUser : [];
        $trustedSuperadmin = $trustedHeaderUser['is_superadmin']
            ?? $currentUser['is_superadmin']
            ?? false;

        return new self(
            $resourceActions,
            filter_var($trustedSuperadmin, FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function canView(): bool
    {
        return $this->allows(self::ACTION_VIEW);
    }

    public function canUpdateResponse(): bool
    {
        return $this->allows(self::ACTION_UPDATE_RESPONSE);
    }

    public function allowsLifecycleAction(string $action): bool
    {
        $action = strtoupper(trim($action));
        if (!isset(self::LIFECYCLE_ACTION_PERMISSIONS[$action])) {
            throw new InvalidArgumentException('Unknown incident lifecycle action.');
        }
        return $this->allows(self::LIFECYCLE_ACTION_PERMISSIONS[$action]);
    }

    public function allows(string $action): bool
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unknown Incident Reporting & Response Log action.');
        }
        return $this->isSuperadmin || in_array($action, $this->resourceActions, true);
    }

    public function requireAction(string $action): void
    {
        if (!$this->allows($action)) {
            throw new DrrmIncidentAuthorizationException('Module 3 permission denied.');
        }
    }

    public function isSuperadmin(): bool
    {
        return $this->isSuperadmin;
    }

    public function hasModuleResource(): bool
    {
        return $this->resourceActions !== [];
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return [
            'canView' => $this->canView(),
            'canReview' => $this->allows(self::ACTION_REVIEW),
            'canVerify' => $this->allows(self::ACTION_VERIFY),
            'canAssign' => $this->allows(self::ACTION_ASSIGN),
            'canUpdateResponse' => $this->canUpdateResponse(),
            'canResolve' => $this->allows(self::ACTION_RESOLVE),
            'canClose' => $this->allows(self::ACTION_CLOSE),
            'canReject' => $this->allows(self::ACTION_REJECT),
        ];
    }

    private static function normalizeResource(string $resource): string
    {
        return strtolower(trim($resource));
    }
}
