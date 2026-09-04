<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Module 1 authorization based only on the trusted, server-built permission map.
 */
final class DrrmMapAuthorizationService
{
    public const RESOURCE = 'hazard & evacuation map system';
    public const ACTION_VIEW = 'VIEW';

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
            if ($action === self::ACTION_VIEW) {
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
                if (!is_string($resource) || strtolower(trim($resource)) !== self::RESOURCE) {
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
        return $this->isSuperadmin || in_array(self::ACTION_VIEW, $this->resourceActions, true);
    }
}
