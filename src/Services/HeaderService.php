<?php

namespace App\Services;

class HeaderService
{
    private $userService;
    private $permissionService;
    private $authService;

    public function __construct($userService, $permissionService, $authService)
    {
        $this->userService = $userService;
        $this->permissionService = $permissionService;
        $this->authService = $authService;
    }

    public function buildHeaderUser()
    {
        // Never retain a permission map from an earlier or incomplete identity
        // resolution. The remote server must rebuild it for this session.
        $_SESSION['user_granted_actions'] = [];
        $_SESSION['user_granted_resources'] = [];
        $_SESSION['user_permissions_map'] = [];

        $headerUser = [
            'full_name' => 'System User',
            'initials' => 'SU',
            'role' => 'Staff',
            'role_prefix' => 'STF',
            'profile_picture' => 'default-avatar.png',
            'is_superadmin' => false,
            'is_global_access' => false,
            'granted_actions' => [],
            'granted_resources' => []
        ];

        if (!$this->authService->isLoggedIn()) {
            return $headerUser;
        }

        $userId = $_SESSION['user_id'] ?? null;
        $employeeId = $_SESSION['employee_id'] ?? null;

        $user = $this->userService->getCurrentUserDetails($userId, $employeeId);

        if ($user) {
            $mid = !empty($user['middle_name']) ? $user['middle_name'] . ' ' : '';
            $headerUser['full_name'] = trim(($user['first_name'] ?? '') . ' ' . $mid . ($user['last_name'] ?? ''));
            $headerUser['initials'] = strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? 'S', 0, 1));
            $headerUser['profile_picture'] = $user['profile_picture'] ?? 'default-avatar.png';
            
            $headerUser['position_id'] = $user['position_id'] ?? null;
            $headerUser['position_name'] = $user['position_name'] ?? '';
            $headerUser['department_id'] = $user['department_id'] ?? ($user['role_dept_id'] ?? null);
            $headerUser['department_name'] = $user['department_name'] ?? '';
            $headerUser['department_code'] = $user['department_code'] ?? '';

            $headerUser['role'] = $user['role_name'] ?? 'Staff';
            $headerUser['role_prefix'] = $user['role_prefix'] ?? 'STF';
            $headerUser['is_global_access'] = filter_var($user['is_global_access'] ?? false, FILTER_VALIDATE_BOOLEAN);

            // Only the explicit server-derived profile field can confer this
            // status. Role names and prefixes are display metadata, not proof.
            $headerUser['is_superadmin'] = filter_var(
                $user['is_superadmin'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            // Permissions: Query LGU REST API on every load for instant panel changes
            if (!empty($user['role_id'])) {
                require_once __DIR__ . '/../../config/proxy.php';
                $apiBaseUrl = getenv('EXPO_PUBLIC_API_BASE_URL') ?: 'https://civentral.tech/api/employee';
                $remoteUrl = rtrim($apiBaseUrl, '/') . '/permissions.php';
                $res = proxyRequest($remoteUrl, 'GET', null);
                
                $grantedActions = [];
                $grantedResources = [];
                $userPermsMap = [];
                
                if (!empty($res['body']) && $res['code'] === 200) {
                    $body = $res['body'];
                    $rolesPerms = $body['role_permissions'] ?? [];
                    $perms = $body['permissions'] ?? [];
                    $resources = $body['resources'] ?? [];
                    $actions = $body['actions'] ?? [];
                    
                    // Map action names & resource names
                    $actionsMap = [];
                    foreach ($actions as $a) {
                        $actionsMap[$a['action_id']] = strtoupper($a['action_name']);
                    }
                    
                    $resourcesMap = [];
                    foreach ($resources as $r) {
                        $resourcesMap[$r['resource_id']] = strtolower(trim($r['resource_name']));
                    }
                    
                    $permsMap = [];
                    foreach ($perms as $p) {
                        $permsMap[$p['permission_id']] = [
                            'action_id' => $p['action_id'],
                            'resource_id' => $p['resource_id']
                        ];
                    }
                    
                    $targetRoleId = intval($user['role_id']);
                    foreach ($rolesPerms as $rp) {
                        if (intval($rp['role_id']) === $targetRoleId) {
                            $pId = $rp['permission_id'];
                            if (isset($permsMap[$pId])) {
                                $actId = $permsMap[$pId]['action_id'];
                                $resId = $permsMap[$pId]['resource_id'];
                                
                                if (isset($actionsMap[$actId])) {
                                    $grantedActions[] = $actionsMap[$actId];
                                }
                                if (isset($resourcesMap[$resId])) {
                                    $grantedResources[] = $resourcesMap[$resId];
                                }
                                if (isset($actionsMap[$actId]) && isset($resourcesMap[$resId])) {
                                    $actName = $actionsMap[$actId];
                                    $resName = $resourcesMap[$resId];
                                    if (!isset($userPermsMap[$resName])) {
                                        $userPermsMap[$resName] = [];
                                    }
                                    if (!in_array($actName, $userPermsMap[$resName])) {
                                        $userPermsMap[$resName][] = $actName;
                                    }
                                }
                            }
                        }
                    }
                }
                
                $headerUser['granted_actions'] = array_values(array_unique($grantedActions));
                $headerUser['granted_resources'] = array_values(array_unique($grantedResources));
                
                // Cache inside local session
                $_SESSION['user_granted_actions'] = $headerUser['granted_actions'];
                $_SESSION['user_granted_resources'] = $headerUser['granted_resources'];
                $_SESSION['user_permissions_map'] = $userPermsMap;
            }
        }

        return $headerUser;
    }
}
