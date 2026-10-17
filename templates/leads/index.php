<?php
/** @var list<array<string, mixed>> $leads */
/** @var array<string, mixed> $filters */
/** @var list<array<string, mixed>> $campaigns */
use App\Pipeline\Status;

$pages = max(1, (int) ceil($total / 50));
$qs = static fn (int $p): string => http_build_query(['page' => $p] + $filters);
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <h1 class="text-2xl font-semibold">Leads <span class="text-slate-400 text-lg">(<?= e($total) ?>)</span></h1>
  <div class="flex gap-2 text-sm">
    <a href="<?= e($base) ?>/import" class="border border-slate-300 bg-white rounded px-3 py-1.5">Import CSV</a>
    <a href="<?= e($base) ?>/leads/new" class="bg-indigo-600 text-white rounded px-3 py-1.5">Add lead</a>
  </div>
</div>
<form method="get" class="flex flex-wrap gap-2 mb-4 text-sm">
  <input name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Search name, domain, email, city" class="border rounded px-3 py-1.5 w-64">
  <select name="status" class="border rounded px-2 py-1.5"><option value="">All statuses</option><?php foreach (Status::ALL as $k => $label): ?><option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  <select name="campaign_id" class="border rounded px-2 py-1.5"><option value="">All campaigns</option><?php foreach ($campaigns as $c): ?><option value="<?= e($c['id']) ?>" <?= (int) ($filters['campaign_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  <label class="flex items-center gap-1"><input type="checkbox" name="due" value="1" <?= !empty($filters['due']) ? 'checked' : '' ?>> Next action due</label>
  <button class="border border-slate-300 bg-white rounded px-3 py-1.5">Filter</button>
</form>
<?= $view->partial('_lead_table', ['leads' => $leads]) ?>
<?php if ($pages > 1): ?>
  <nav class="flex gap-2 mt-4 text-sm">
    <?php if ($page > 1): ?><a class="border bg-white rounded px-3 py-1" href="?<?= e($qs($page - 1)) ?>">Previous</a><?php endif; ?>
    <span class="px-2 py-1 text-slate-500">Page <?= e($page) ?> of <?= e($pages) ?></span>
    <?php if ($page < $pages): ?><a class="border bg-white rounded px-3 py-1" href="?<?= e($qs($page + 1)) ?>">Next</a><?php endif; ?>
  </nav>
<?php endif; ?>
