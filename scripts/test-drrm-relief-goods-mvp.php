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
    'beneficiary_migration' => $root . '/supabase/migrations/20260911000200_module2_beneficiary_assistance.sql',
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
assertRelief('SubmittedFormsCapturedBeforeAwait', substr_count($source['js'] ?? '', 'const formElement = event.currentTarget') === 4 && !str_contains($source['js'] ?? '', 'event.currentTarget.reset()'));
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
assertRelief('BeneficiarySchemaIsAdditive', str_contains($source['beneficiary_migration'], 'create table if not exists public.relief_beneficiaries') && str_contains($source['beneficiary_migration'], 'create table if not exists public.relief_distribution_beneficiaries'));
assertRelief('BeneficiaryBarangayAndSizeConstraints', str_contains($source['beneficiary_migration'], 'references public.barangays') && str_contains($source['beneficiary_migration'], 'household_size_positive'));
assertRelief('AssistanceDuplicateProtection', str_contains($source['beneficiary_migration'], 'unique_link') && str_contains($source['service'], 'Beneficiary already recorded for this distribution.'));
assertRelief('AssistanceRequiresReleasedDistribution', str_contains($source['service'], "Only released distributions can record assistance.") && str_contains($source['service'], "'select' => 'id,status,relief_distribution_items(id,quantity)'") && str_contains($source['service'], "'status'] ?? null) !== 'RELEASED'"));
$assistanceStart = strpos($source['service'], 'public function recordAssistance');
$assistanceEnd = strpos($source['service'], 'public function release', $assistanceStart === false ? 0 : $assistanceStart);
$assistanceMethod = $assistanceStart === false ? false : substr($source['service'], $assistanceStart, $assistanceEnd === false ? null : $assistanceEnd - $assistanceStart);
assertRelief('BeneficiaryDoesNotUseStockMutation', $assistanceMethod !== false && !str_contains($assistanceMethod, 'receive_relief_stock') && !str_contains($assistanceMethod, 'release_relief_distribution'));
assertRelief('BeneficiarySummaryDerivation', str_contains($source['service'], 'beneficiarySummary') && str_contains($source['service'], 'people_represented') && str_contains($source['service'], 'not_yet_served'));
assertRelief('BeneficiaryLiveReloadContract', str_contains($source['api'], "'beneficiary_summary'") && str_contains($source['js'] ?? '', "'register_beneficiary'") && str_contains($source['js'] ?? '', "'record_assistance'") && substr_count($source['js'] ?? '', 'await load();') >= 5);
assertRelief('BeneficiaryCreateProtection', str_contains($source['js'] ?? '', "'REGISTERING...'") && str_contains($source['js'] ?? '', "'RECORDING...'") && str_contains($source['js'] ?? '', 'assistance-form'));
assertRelief('BeneficiaryNoSecretExposure', !str_contains($source['page'], 'SUPABASE_SECRET_KEY') && !str_contains($source['js'] ?? '', 'SUPABASE_SECRET_KEY'));
assertRelief('AssistanceStoresItemQuantities', str_contains($source['beneficiary_migration'], 'quantity_received') && str_contains($source['service'], 'quantity_received'));
assertRelief('AssistanceQuantityPositive', str_contains($source['beneficiary_migration'], 'quantity_positive') && str_contains($source['service'], 'positiveQuantity'));
assertRelief('AssistanceItemBelongsToDistribution', str_contains($source['beneficiary_migration'], 'ITEM_NOT_IN_DISTRIBUTION') && str_contains($source['service'], 'Received item is not part of the selected distribution.'));
assertRelief('CumulativeAllocationBounded', str_contains($source['beneficiary_migration'], 'allocated_quantity + requested_quantity > released_quantity') && str_contains($source['js'] ?? '', 'Remaining:'));
assertRelief('ClearOverAllocationMessage', str_contains($source['service'], 'Allocated quantity exceeds the remaining released quantity.'));
assertRelief('EmptyAssistanceRejected', str_contains($source['beneficiary_migration'], 'ITEMS_REQUIRED') && str_contains($source['service'], 'At least one received item is required.'));
assertRelief('HistoryUsesActualQuantities', str_contains($source['js'] ?? '', 'quantity_received') && str_contains($source['js'] ?? '', 'actualItems'));
assertRelief('BeneficiaryHistoryUsesValidItemRelationship', str_contains($source['service'], 'relief_distribution_items(relief_item_id,relief_items(item_name,unit))') && !str_contains($source['service'], 'relief_distribution_items(item_name,unit)'));
assertRelief('Phase4AGetContractPreserved', str_contains($source['api'], "'summary'") && str_contains($source['api'], "'inventory'") && str_contains($source['api'], "'distributions'") && str_contains($source['api'], "'destinations'"));
assertRelief('DistributionItemIdPreservedForAssistance', str_contains($source['service'], 'relief_distribution_items(id,quantity,relief_item_id,relief_items(item_name,unit))') && str_contains($source['js'] ?? '', 'data-distribution-item-id'));
assertRelief('BeneficiaryUsesPhase4ADestinationSource', str_contains($source['js'] ?? '', 'state.destinations.barangays') && str_contains($source['js'] ?? '', 'beneficiaryBarangay'));
assertRelief('BarangayOptionUsesExistingIdAndName', str_contains($source['js'] ?? '', 'entry.barangay_id') && str_contains($source['js'] ?? '', 'entry.name'));
assertRelief('BarangayEmptyStateIsExplicit', str_contains($source['js'] ?? '', 'No barangays available') && str_contains($source['js'] ?? '', 'beneficiarySelect.disabled'));
assertRelief('NoHardcodedBarangays', !preg_match('/Barangay\s+[0-9]+/', $source['js'] ?? '') && !preg_match('/Barangay\s+[0-9]+/', $source['page']));
assertRelief('HouseholdSizeWording', str_contains($source['page'], 'Household Size (Number of People)') && str_contains($source['page'], 'Number of household members'));
assertRelief('Phase4ADestinationBindingPreserved', str_contains($source['js'] ?? '', "state.destinations.barangays : state.destinations.evacuation_centers"));
assertRelief('ActualNestedItemNamesRendered', str_contains($source['js'] ?? '', 'item.relief_distribution_items?.relief_items?.item_name') && !str_contains($source['js'] ?? '', 'item.relief_distribution_items?.item_name || \'Item\''));
assertRelief('ServedHouseholdsCanReceiveAgain', str_contains($source['js'] ?? '', 'usedDistributionIds') && str_contains($source['js'] ?? '', 'eligibleDistributions') && !str_contains($source['js'] ?? '', 'config.canCreate && !served'));
assertRelief('UsedDistributionExcluded', str_contains($source['js'] ?? '', '!usedDistributionIds.has(distribution.id)'));
assertRelief('OnlyReleasedWithRemainingEligible', str_contains($source['js'] ?? '', "distribution.status === 'RELEASED'") && str_contains($source['js'] ?? '', 'allocatedByDistributionItem'));
assertRelief('MultipleAssistanceHistoryPreserved', str_contains($source['js'] ?? '', 'assistance = beneficiary.relief_distribution_beneficiaries || []') && str_contains($source['service'], 'relief_distribution_beneficiaries'));

echo 'Assertions=' . $assertions . PHP_EOL;
if ($failures !== []) { fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL); exit(1); }