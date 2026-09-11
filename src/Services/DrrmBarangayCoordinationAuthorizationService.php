<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class DrrmBarangayCoordinationAuthorizationException extends RuntimeException {}

final class DrrmBarangayCoordinationAuthorizationService
{
    public const RESOURCE = 'barangay drrm coordination tool';
    public const ACTION_VIEW = 'VIEW';
    public const ACTION_CREATE = 'CREATE';

    public function __construct(private readonly array $resourceActions, private readonly bool $isSuperadmin) {}

    public static function fromTrustedSession(?array $trustedHeaderUser = null): self
    {
        $actions = [];
        $permissionMap = $_SESSION['user_permissions_map'] ?? [];
        if (is_array($permissionMap)) {
            foreach ($permissionMap as $resource => $resourceActions) {
                if (is_string($resource) && self::normalizeResource($resource) === self::RESOURCE && is_array($resourceActions)) {
                    $actions = array_map(
                        static fn (string $action): string => strtoupper(trim($action)),
                        array_filter($resourceActions, 'is_string')
                    );
                    break;
                }
            }
        }
        $currentUser = is_array($_SESSION['current_user_details'] ?? null) ? $_SESSION['current_user_details'] : [];
        $superadmin = $trustedHeaderUser['is_superadmin'] ?? $currentUser['is_superadmin'] ?? false;
        return new self(array_values(array_unique($actions)), filter_var($superadmin, FILTER_VALIDATE_BOOLEAN));
    }

    public function canView(): bool { return $this->allows(self::ACTION_VIEW); }
    public function canCreate(): bool { return $this->allows(self::ACTION_CREATE); }

    public function allows(string $action): bool
    {
        if (!in_array($action, [self::ACTION_VIEW, self::ACTION_CREATE], true)) {
            throw new InvalidArgumentException('Unknown Module 5 action.');
        }
        return $this->isSuperadmin || in_array($action, $this->resourceActions, true);
    }

    public function requireAction(string $action): void
    {
        if (!$this->allows($action)) {
            throw new DrrmBarangayCoordinationAuthorizationException('Module 5 permission denied.');
        }
    }

    public function capabilities(): array
    {
        return ['canView' => $this->canView(), 'canCreate' => $this->canCreate()];
    }

    private static function normalizeResource(string $resource): string
    {
        return (string) preg_replace('/\s+/', ' ', strtolower(trim($resource)));
    }
}
