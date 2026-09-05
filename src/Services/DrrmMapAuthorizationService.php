<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Module 1 authorization based only on the trusted, server-built permission map.
 */
final class DrrmMapAuthorizationService
{
    /** Current RBAC resource name returned by the employee permissions API. */
    public const RESOURCE = 'hazard & evacuation map';
    /** Older deployments may still retain the original Module 1 resource name. */
    public const LEGACY_RESOURCE = 'hazard & evacuation map system';
    private const RESOURCES = [self::RESOURCE, self::LEGACY_RESOURCE];
    public const ACTION_VIEW = 'VIEW';

    /** @var list<string> */
    private array $resourceActions;

    /** @param list<string> $resourceActions */
    public function __construct(array $resourceActions)
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

    /**
     * @param array<string, mixed>|null $_trustedHeaderUser Retained for caller compatibility;
     *        Module 1 VIEW must still be present in the trusted permission map.
     */
    public static function fromTrustedSession(?array $_trustedHeaderUser = null): self
    {
        $permissionMap = $_SESSION['user_permissions_map'] ?? [];
        $resourceActions = [];

        if (is_array($permissionMap)) {
            foreach ($permissionMap as $resource => $actions) {
                if (!is_string($resource)
                    || !in_array(strtolower(trim($resource)), self::RESOURCES, true)) {
                    continue;
                }
                if (is_array($actions)) {
                    $resourceActions = array_values($actions);
                }
                break;
            }
        }

        return new self($resourceActions);
    }

    public function canView(): bool
    {
        return in_array(self::ACTION_VIEW, $this->resourceActions, true);
    }
}
