<?php

declare(strict_types=1);

$basePath = '../../';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Services/DrrmReliefGoodsAuthorizationService.php';
require_once __DIR__ . '/../../src/Services/DrrmReliefGoodsCsrfService.php';

$authorization = \App\Services\DrrmReliefGoodsAuthorizationService::fromTrustedSession($headerUser);
if (!$authorization->canView()) {
    header('Location: ../dashboard.php');
    exit;
}
$canCreate = $authorization->canCreate();
$csrfToken = $canCreate ? (new \App\Services\DrrmReliefGoodsCsrfService())->token() : null;
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/drrm/relief-goods.css?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/../../assets/css/drrm/relief-goods.css')); ?>">
<main class="flex-1 min-w-0 w-full overflow-y-auto p-4 sm:p-6 md:p-8">
  <section class="mx-auto w-full max-w-[1600px] space-y-6" aria-labelledby="reliefTitle">
    <header class="flex flex-col gap-4 border-b border-slate-200/70 pb-5 lg:flex-row lg:items-start lg:justify-between">
      <div>
        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-brand-dark">Disaster Risk Reduction &amp; Management</p>
        <h1 id="reliefTitle" class="mt-1 text-xl font-black tracking-tight text-slate-900 sm:text-2xl">Relief Goods Distribution Tracker</h1>
        <p class="mt-1 max-w-3xl text-xs font-medium leading-relaxed text-slate-500">Track relief inventory, receive goods, and release supplies to verified response destinations.</p>
      </div>
      <?php if ($canCreate): ?><span class="inline-flex h-fit items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-emerald-700"><i class="fa-solid fa-lock-open" aria-hidden="true"></i>Operations enabled</span><?php endif; ?>
    </header>
    <p id="reliefStatus" class="text-xs font-bold text-slate-500" role="status" aria-live="polite">Loading relief records...</p>
    <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Relief inventory summary">
      <?php foreach (['total_items' => 'Relief Items', 'available_items' => 'Items In Stock', 'low_stock_items' => 'Low Stock Items', 'total_distributions' => 'Distributions'] as $key => $label): ?>
        <article class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs"><p class="text-[9px] font-black uppercase tracking-wider text-slate-400"><?php echo $label; ?></p><p id="summary-<?php echo $key; ?>" class="mt-2 text-2xl font-black text-slate-800">0</p></article>
      <?php endforeach; ?>
    </section>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(340px,1fr)]">
      <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs" aria-labelledby="inventoryTitle">
        <div class="flex items-center justify-between gap-3"><h2 id="inventoryTitle" class="text-sm font-black text-slate-800">Current Inventory</h2><button type="button" id="refreshRelief" class="relief-secondary-button rounded-lg border border-slate-200 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-slate-600"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Refresh</button></div>
        <div class="mt-4 overflow-x-auto"><table class="w-full min-w-[720px] text-left text-[11px]"><thead class="border-b border-slate-100 text-[9px] font-black uppercase tracking-wider text-slate-400"><tr><th class="px-2 py-3">Item</th><th class="px-2 py-3">Category</th><th class="px-2 py-3">Unit</th><th class="px-2 py-3 text-right">Available</th><th class="px-2 py-3 text-right">Minimum Stock Level</th><th class="px-2 py-3">Status</th></tr></thead><tbody id="inventoryBody" class="divide-y divide-slate-100"></tbody></table></div>
      </section>
      <?php if ($canCreate): ?>
        <div class="space-y-4">
          <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs"><h2 class="text-sm font-black text-slate-800">Receive Goods</h2><form id="receiveForm" class="mt-4 space-y-3"><select name="item_id" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"><option value="">Select relief item</option></select><input name="quantity" type="number" min="0.001" step="0.001" required placeholder="Quantity received" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"><input name="source" maxlength="180" placeholder="Source / donor (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"><input name="reference_note" maxlength="500" placeholder="Reference or notes (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"><button class="relief-action-button w-full rounded-lg bg-slate-900 px-3 py-2.5 text-[10px] font-black uppercase tracking-wider text-white" type="submit"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Receive stock</button></form></section>
          <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs"><h2 class="text-sm font-black text-slate-800">Release Distribution</h2><form id="distributionForm" class="mt-4 space-y-3"><select name="destination_type" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"><option value="">Destination type</option><option value="BARANGAY">Barangay</option><option value="EVACUATION_CENTER">Evacuation Center</option></select><select name="destination_id" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"><option value="">Destination</option></select><div id="distributionItems" class="space-y-2"></div><button type="button" id="addDistributionItem" class="relief-secondary-button w-full rounded-lg border border-dashed border-slate-300 px-3 py-2 text-[10px] font-black uppercase tracking-wider text-slate-600"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add item</button><textarea name="notes" maxlength="2000" rows="2" placeholder="Notes (optional)" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-xs"></textarea><button class="relief-action-button w-full rounded-lg bg-brand-dark px-3 py-2.5 text-[10px] font-black uppercase tracking-wider text-white" type="submit"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> Release goods</button></form></section>
        </div>
      <?php endif; ?>
    </div>
    <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs"><h2 class="text-sm font-black text-slate-800">Distribution History</h2><div class="mt-4 overflow-x-auto"><table class="w-full min-w-[700px] text-left text-[11px]"><thead class="border-b border-slate-100 text-[9px] font-black uppercase tracking-wider text-slate-400"><tr><th class="px-2 py-3">Date</th><th class="px-2 py-3">Destination</th><th class="px-2 py-3">Items</th><th class="px-2 py-3">Status</th><th class="px-2 py-3">Responsible user</th></tr></thead><tbody id="distributionBody" class="divide-y divide-slate-100"></tbody></table></div></section>
  </section>
</main>
<script>window.CiventralReliefGoodsConfig = <?php echo json_encode(['endpoint' => $basePath . 'api/drrm/relief-goods.php', 'csrfToken' => $csrfToken, 'canCreate' => $canCreate], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo $basePath; ?>assets/js/drrm/relief-goods.js?v=<?php echo rawurlencode((string) filemtime(__DIR__ . '/../../assets/js/drrm/relief-goods.js')); ?>"></script>
