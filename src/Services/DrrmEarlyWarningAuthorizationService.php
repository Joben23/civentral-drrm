<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class DrrmEarlyWarningAuthorizationException extends RuntimeException
{
}

/**
 * Module 4 authorization based only on trusted, server-loaded session data.
 */
final class DrrmEarlyWarningAuthorizationService
{
    public const RESOURCE = 'disaster early warning system';
    public const ACTION_VIEW = 'VIEW';
    public const ACTION_CREATE_WARNING = 'CREATE_WARNING';
    public const ACTION_ACTIVATE_WARNING = 'ACTIVATE_WARNING';
    public const ACTION_CANCEL_WARNING = 'CANCEL_WARNING';

    private const ACTIONS = [
        self::ACTION_VIEW,
        self::ACTION_CREATE_WARNING,
        self::ACTION_ACTIVATE_WARNING,
        self::ACTION_CANCEL_WARNING,
    ];

    /** @var list<string> */
    private array $resourceActions;

    /**
     * @param list<string> $resourceActions
     */
    public function __construct(array $resourceActions, private readonly bool $isSuperadmin)
    {
        $normalizedActions = [];

        foreach ($resourceActions as $action) {
            if (!is_string($action)) {
                continue;
            }

            $normalizedAction = strtoupper(trim($action));
            if (in_array($normalizedAction, self::ACTIONS, true)) {
                $normalizedActions[] = $normalizedAction;
            }
        }

        $this->resourceActions = array_values(array_unique($normalizedActions));
    }

    /**
     * Build authorization from the trusted permission map populated by
     * HeaderService. The optional header context is also server-resolved.
     * No browser data, department membership, flat granted-action list, or
     * global-access flag participates in this decision.
     *
     * @param array<string, mixed>|null $trustedHeaderUser
     */
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

        $currentUserDetails = $_SESSION['current_user_details'] ?? [];
        $currentUserDetails = is_array($currentUserDetails) ? $currentUserDetails : [];
        $trustedSuperadminValue = $trustedHeaderUser['is_superadmin']
            ?? $currentUserDetails['is_superadmin']
            ?? false;

        return new self(
            $resourceActions,
            filter_var($trustedSuperadminValue, FILTER_VALIDATE_BOOLEAN)
        );
    }

    public function canView(): bool
    {
        return $this->allows(self::ACTION_VIEW);
    }

    public function canCreateWarning(): bool
    {
        return $this->allows(self::ACTION_CREATE_WARNING);
    }

    /**
     * The central permission catalog has no separate edit action. Maintaining
     * a warning definition while it remains DRAFT belongs to CREATE_WARNING.
     */
    public function canEditDraft(): bool
    {
        return $this->canCreateWarning();
    }

    /**
     * The central catalog has no external-sync action. A synchronization run
     * writes provider staging/audit records, so CREATE_WARNING is the least
     * surprising existing owner; VIEW remains strictly read-only.
     */
    public function canSynchronizeExternalAdvisories(): bool
    {
        return $this->canCreateWarning();
    }

    /** VIEW owns the administrative list/detail projection only. */
    public function canReviewExternalAdvisories(): bool
    {
        return $this->canView();
    }

    /** Terminal review writes use the least-surprising existing permission. */
    public function canDismissExternalAdvisory(): bool
    {
        return $this->canCreateWarning();
    }

    public function canConvertExternalAdvisoryToDraft(): bool
    {
        return $this->canCreateWarning();
    }

    public function canActivateWarning(): bool
    {
        return $this->allows(self::ACTION_ACTIVATE_WARNING);
    }

    public function canCancelWarning(): bool
    {
        return $this->allows(self::ACTION_CANCEL_WARNING);
    }

    public function isSuperadmin(): bool
    {
        return $this->isSuperadmin;
    }

    public function hasModuleResource(): bool
    {
        return $this->resourceActions !== [];
    }

    public function allows(string $action): bool
    {
        $normalizedAction = strtoupper(trim($action));

        if (!in_array($normalizedAction, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Unknown Disaster Early Warning System action.');
        }

        return $this->isSuperadmin || in_array($normalizedAction, $this->resourceActions, true);
    }

    public function requireAction(string $action): void
    {
        if (!$this->allows($action)) {
            throw new DrrmEarlyWarningAuthorizationException('Module 4 permission denied.');
        }
    }

    /** @return array{canView: bool, canCreateWarning: bool, canEditDraft: bool, canSynchronizeExternalAdvisories: bool, canReviewExternalAdvisories: bool, canDismissExternalAdvisory: bool, canConvertExternalAdvisoryToDraft: bool, canActivateWarning: bool, canCancelWarning: bool} */
    public function capabilities(): array
    {
        return [
            'canView' => $this->canView(),
            'canCreateWarning' => $this->canCreateWarning(),
            'canEditDraft' => $this->canEditDraft(),
            'canSynchronizeExternalAdvisories' => $this->canSynchronizeExternalAdvisories(),
            'canReviewExternalAdvisories' => $this->canReviewExternalAdvisories(),
            'canDismissExternalAdvisory' => $this->canDismissExternalAdvisory(),
            'canConvertExternalAdvisoryToDraft' => $this->canConvertExternalAdvisoryToDraft(),
            'canActivateWarning' => $this->canActivateWarning(),
            'canCancelWarning' => $this->canCancelWarning(),
        ];
    }

    private static function normalizeResource(string $resource): string
    {
        return strtolower(trim($resource));
    }
}
