<?php
require_once __DIR__ . '/../../src/Services/AdminSessionManager.php';
\App\Services\AdminSessionManager::start();

header('Content-Type: application/json; charset=utf-8');

// CORS Headers
$allowedOrigins = [
    'http://localhost',
    'http://localhost:80',
    'http://localhost:3000',
    'http://127.0.0.1',
    'http://127.0.0.1:80'
];

if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (in_array($origin, $allowedOrigins) || preg_match('/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/_admin-session.php';
require_once __DIR__ . '/../../config/proxy.php';

function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$apiBaseUrl = getenv('EXPO_PUBLIC_API_BASE_URL') ?: 'https://civentral.tech/api/employee';

$fileName = basename(__FILE__);
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$remoteUrl = rtrim($apiBaseUrl, '/') . '/' . $fileName . ($queryString !== '' ? '?' . $queryString : '');

$body = null;
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $body = file_get_contents('php://input');
}

$result = proxyRequest($remoteUrl, $method, $body);

// AUTO-GRANT PERMISSIONS ON NEW RESOURCE CREATION
if ($method === 'POST' && ($result['code'] >= 200 && $result['code'] < 300)) {
    try {
        $bodyData = json_decode($body, true) ?? [];
        $resName = $bodyData['resource_name'] ?? null;
        
        $resData = $result['body']['data'] ?? $result['body'] ?? null;
        $newResourceId = null;
        if (is_array($resData)) {
            $newResourceId = $resData['resource_id'] ?? $resData['id'] ?? null;
        }
        if (!$newResourceId && is_array($result['body'])) {
            $newResourceId = $result['body']['resource_id'] ?? $result['body']['id'] ?? null;
        }
        
        // If ID not directly in creation response, fetch latest resources list to resolve ID by name
        if (!$newResourceId && !empty($resName)) {
            $listRes = proxyRequest($remoteUrl, 'GET');
            if (($listRes['code'] >= 200 && $listRes['code'] < 300) && isset($listRes['body']['data']) && is_array($listRes['body']['data'])) {
                foreach ($listRes['body']['data'] as $r) {
                    if (strcasecmp(trim($r['resource_name'] ?? ''), trim($resName)) === 0) {
                        $newResourceId = intval($r['resource_id']);
                        break;
                    }
                }
            }
        }
        
        if ($newResourceId) {
            $permissionsUrl = rtrim($apiBaseUrl, '/') . '/permissions.php';
            $permRes = proxyRequest($permissionsUrl, 'GET');
            
            if (($permRes['code'] >= 200 && $permRes['code'] < 300) && isset($permRes['body']['status']) && $permRes['body']['status'] === 'success') {
                $permData = $permRes['body'];
                $roles = $permData['roles'] ?? [];
                $actions = $permData['actions'] ?? [];
                $dbPermissions = $permData['permissions'] ?? [];
                $dbRolePermissions = $permData['role_permissions'] ?? [];
                
                $creatorRoleId = $_SESSION['role_id'] ?? ($_SESSION['current_user_details']['role_id'] ?? null);
                $targetRoleIds = [];
                if ($creatorRoleId) {
                    $targetRoleIds[] = intval($creatorRoleId);
                }
                
                foreach ($roles as $r) {
                    if (!empty($r['is_superadmin']) || !empty($r['is_global_access']) || 
                        strcasecmp(trim($r['role_name'] ?? ''), 'super admin') === 0 || 
                        strcasecmp(trim($r['role_name'] ?? ''), 'administrator') === 0) {
                        $targetRoleIds[] = intval($r['role_id']);
                    }
                }
                $targetRoleIds = array_unique($targetRoleIds);
                
                $permIdMap = [];
                foreach ($dbPermissions as $p) {
                    $permIdMap[$p['permission_id']] = [
                        'resource_id' => intval($p['resource_id']),
                        'action_id' => intval($p['action_id'])
                    ];
                }
                
                $roleGrantedPairs = [];
                foreach ($roles as $r) {
                    $roleGrantedPairs[intval($r['role_id'])] = [];
                }
                
                foreach ($dbRolePermissions as $rp) {
                    $rId = intval($rp['role_id']);
                    $pInfo = $permIdMap[$rp['permission_id']] ?? null;
                    if ($pInfo && isset($roleGrantedPairs[$rId])) {
                        $roleGrantedPairs[$rId][] = $pInfo;
                    }
                }
                
                $actionIdsToGrant = [];
                foreach ($actions as $act) {
                    $actionIdsToGrant[] = intval($act['action_id']);
                }
                if (empty($actionIdsToGrant)) {
                    $actionIdsToGrant = [1, 2, 3, 4];
                }
                
                foreach ($targetRoleIds as $tRoleId) {
                    if (!isset($roleGrantedPairs[$tRoleId])) {
                        $roleGrantedPairs[$tRoleId] = [];
                    }
                    
                    $existingPairs = $roleGrantedPairs[$tRoleId];
                    
                    foreach ($actionIdsToGrant as $actId) {
                        $alreadyHas = false;
                        foreach ($existingPairs as $ep) {
                            if (intval($ep['resource_id']) === intval($newResourceId) && intval($ep['action_id']) === intval($actId)) {
                                $alreadyHas = true;
                                break;
                            }
                        }
                        if (!$alreadyHas) {
                            $existingPairs[] = [
                                'resource_id' => intval($newResourceId),
                                'action_id' => intval($actId)
                            ];
                        }
                    }
                    
                    proxyRequest($permissionsUrl, 'POST', [
                        'role_id' => $tRoleId,
                        'granted_permissions' => $existingPairs
                    ]);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Error auto-granting permissions on resource creation: ' . $e->getMessage());
    }
}

respond($result['body'], $result['code']);
