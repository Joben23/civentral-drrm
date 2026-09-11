<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/src/Services/DrrmReliefGoodsAuthorizationService.php';
$failures = [];
$assertions = 0;
function assertRelief(string $name, bool $condition): void
{
    global $failures, $assertions;
    $assertions++;
    echo $name . '=' . ($condition ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$condition) $failures[] = $name;
}

$files = [
    'migration' => $root . '/supabase/migrations/20260911000100_module2_relief_goods_foundation.sql',
    'authorization' => $root . '/src/Services/DrrmReliefGoodsAuthorizationService.php',
    'csrf' => $root . '/src/Services/DrrmReliefGoodsCsrfService.php',
    'service' => $root . '/src/Services/DrrmReliefGoodsService.php',
    'api' => $root . '/api/drrm/relief-goods.php',
    'page' => $root . '/pages/drrm/relief-goods-distribution.php',
    'js' => $root . '/assets/js/drrm/relief-goods.js',
    'css' => $root . '/assets/css/drrm/relief-goods.css',
    'sidebar' => $root . '/includes/sidebar.php',
];
$source = [];
foreach ($files as $key => $file) {
    $source[$key] = file_get_contents($file);
    assertRelief($key . 'Exists', is_string($source[$key]));
}
assertRelief('AtomicReleaseRpc', str_contains($source['migration'], 'release_relief_distribution') && str_contains($source['migration'], 'for update'));
assertRelief('NegativeStockRejected', str_contains($source['migration'], 'INSUFFICIENT_STOCK') && str_contains($source['migration'], 'current_stock - item_quantity'));
assertRelief('MovementHistoryPresent', str_contains($source['migration'], 'relief_stock_movements') && str_contains($source['migration'], "DISTRIBUTION_RELEASE"));
assertRelief('IdempotentReleaseReference', str_contains($source['migration'], 'client_reference text not null unique') && str_contains($source['service'], 'client_reference'));
assertRelief('ServerAuthorizationAndCsrf', str_contains($source['api'], 'requireAction') && str_contains($source['api'], 'requireValidHeader'));
assertRelief('AdministratorItemMaintenance', str_contains($source['service'], 'createItem') && str_contains($source['api'], "'create_item'") && str_contains($source['page'], 'relief-goods.js'));
assertRelief('NoBrowserSecret', !str_contains($source['page'], 'SUPABASE_SECRET_KEY') && !str_contains($source['source'] ?? '', 'SUPABASE_SECRET_KEY'));
assertRelief('DashboardNavigationEnabled', str_contains($source['sidebar'], 'relief-goods-distribution.php') && !str_contains($source['sidebar'], 'Relief Goods Distribution Tracker</span>\n              <span'));
assertRelief('DomainValidation', str_contains($source['service'], 'positiveQuantity') && str_contains($source['service'], 'At least one relief item is required.'));
assertRelief('ClearInsufficientStockMessage', str_contains($source['service'], "'Insufficient stock.'"));
assertRelief('InventoryIncludesReorderLevel', str_contains($source['service'], 'current_stock,reorder_level,updated_at'));
assertRelief('ReorderLevelDoesNotSeedStock', str_contains($source['service'], "'reorder_level' => number_format") && !str_contains($source['service'], "'current_stock' =>"));
assertRelief('CreateRefreshesAuthoritativeData', substr_count($source['js'] ?? '', "'create_item'") === 1 && str_contains($source['js'] ?? '', "await load();"));
assertRelief('ReceiveAndReleaseRefreshData', substr_count($source['js'] ?? '', "await load();") >= 3);
assertRelief('MinimumStockLevelShownInInventory', str_contains($source['page'], 'Minimum Stock Level') && str_contains($source['js'] ?? '', 'reorderLevel'));
assertRelief('ModuleScriptVersioned', str_contains($source['page'], 'relief-goods.js?v=') && str_contains($source['page'], 'filemtime'));
assertRelief('StockStatusRules', str_contains($source['js'] ?? '', "const out = stock === 0") && str_contains($source['js'] ?? '', "stock > 0 && stock <= reorderLevel") && str_contains($source['js'] ?? '', "status = out ? 'OUT OF STOCK'"));
assertRelief('SummaryExcludesOutOfStock', str_contains($source['service'], 'if ($stock > 0)') && str_contains($source['service'], 'reorder_level'));
assertRelief('InteractiveButtonContract', str_contains($source['page'], 'relief-action-button') && str_contains($source['page'], 'relief-secondary-button') && str_contains($source['js'] ?? '', 'setBusy'));
assertRelief('LoadingStatesPreventDoubleSubmit', str_contains($source['js'] ?? '', "'ADDING...'" ) && str_contains($source['js'] ?? '', "'RECEIVING...'" ) && str_contains($source['js'] ?? '', "'RELEASING...'" ) && str_contains($source['js'] ?? '', 'button.disabled = true'));
assertRelief('DisabledButtonSemantics', str_contains($source['css'] ?? '', 'cursor: not-allowed') && str_contains($source['css'] ?? '', 'transform: none'));
assertRelief('SubmittedFormsCapturedBeforeAwait', substr_count($source['js'] ?? '', 'const formElement = event.currentTarget') === 3 && !str_contains($source['js'] ?? '', 'event.currentTarget.reset()'));
$releaseResetPosition = strpos($source['js'] ?? '', "formElement.reset(); $('#distributionItems').innerHTML = '';");
$releaseReloadPosition = strpos($source['js'] ?? '', "addItem(); await load(); message('Goods released.')");
assertRelief('ReleaseResetsThenReloads', $releaseResetPosition !== false && $releaseReloadPosition !== false && $releaseResetPosition < $releaseReloadPosition);
assertRelief('SummaryAndHistoryRefreshOnRelease', str_contains($source['js'] ?? '', 'summary') && str_contains($source['js'] ?? '', 'distributionBody') && str_contains($source['js'] ?? '', 'await load(); message(\'Goods released.\''));
assertRelief('ReleaseErrorsStayOperational', str_contains($source['js'] ?? '', "message(error.message, true)") && str_contains($source['js'] ?? '', "message('Goods released.')"));

$superadmin = new App\Services\DrrmReliefGoodsAuthorizationService([], true);
$viewOnly = new App\Services\DrrmReliefGoodsAuthorizationService(['VIEW'], false);
$unauthorized = new App\Services\DrrmReliefGoodsAuthorizationService([], false);
assertRelief('SuperadminCanView', $superadmin->canView());
assertRelief('SuperadminCanCreate', $superadmin->canCreate());
assertRelief('AuthorizedViewCanView', $viewOnly->canView());
assertRelief('ViewDoesNotGrantCreate', !$viewOnly->canCreate());
assertRelief('CreateActionAllowsMutations', (new App\Services\DrrmReliefGoodsAuthorizationService(['CREATE'], false))->canCreate());
assertRelief('UnauthorizedFailsClosed', !$unauthorized->canView() && !$unauthorized->canCreate());
$_SESSION = ['current_user_details' => ['is_superadmin' => true]];
assertRelief('TrustedSuperadminSessionCanView', App\Services\DrrmReliefGoodsAuthorizationService::fromTrustedSession()->canView());
$_SESSION = ['user_permissions_map' => ['  Relief   Goods Distribution Tracker  ' => [' VIEW ']]];
assertRelief('PermissionResourceNormalization', App\Services\DrrmReliefGoodsAuthorizationService::fromTrustedSession()->canView());
assertRelief('PageRedirectIsSingleFailClosedBranch', substr_count($source['page'], "header('Location: ../dashboard.php')") === 1 && str_contains($source['page'], 'if (!$authorization->canView())'));
assertRelief('SidebarIsPermissionAware', str_contains($source['sidebar'], '$canAccessReliefGoods') && str_contains($source['sidebar'], 'if ($canAccessReliefGoods)'));

echo 'Assertions=' . $assertions . PHP_EOL;
if ($failures !== []) { fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL); exit(1); }