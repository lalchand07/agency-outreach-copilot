<?php /** @var array<string, mixed> $campaign */ /** @var list<array<string, mixed>> $leads */ /** @var int $newCount */ /** @var bool $aiEnabled */ ?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-2">
  <h1 class="text-2xl font-semibold"><?= e($campaign['name']) ?></h1>
  <div class="flex gap-2 text-sm">
    <a href="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>/edit" class="border border-slate-300 bg-white rounded px-3 py-1.5">Edit</a>
    <a href="<?= e($base) ?>/export.csv?campaign_id=<?= e($campaign['id']) ?>" class="border border-slate-300 bg-white rounded px-3 py-1.5">Export CSV</a>
  </div>
</div>
<p class="text-sm text-slate-600 mb-6"><?= e($campaign['country']) ?><?= $campaign['cities'] !== [] ? ' · ' . e(implode(', ', $campaign['cities'])) : '' ?> · <?= e(implode(', ', $campaign['keywords'])) ?> · languages: <?= e(implode(', ', $campaign['languages'])) ?> · channels: <?= e(implode(', ', $campaign['channels'])) ?></p>

<div class="grid md:grid-cols-4 gap-4 mb-6">
  <a href="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>/places" class="bg-white border border-slate-200 rounded-lg p-4 hover:border-indigo-300"><div class="font-medium">Find on Google Places</div><p class="text-xs text-slate-500 mt-1">Text Search with your own API key.</p></a>
  <a href="<?= e($base) ?>/import?campaign_id=<?= e($campaign['id']) ?>" class="bg-white border border-slate-200 rounded-lg p-4 hover:border-indigo-300"><div class="font-medium">Import CSV</div><p class="text-xs text-slate-500 mt-1">Exhibitor lists, directories you may use.</p></a>
  <a href="<?= e($base) ?>/leads/new?campaign_id=<?= e($campaign['id']) ?>" class="bg-white border border-slate-200 rounded-lg p-4 hover:border-indigo-300"><div class="font-medium">Add manually</div><p class="text-xs text-slate-500 mt-1">One business at a time.</p></a>
  <form method="post" action="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>/process" class="bg-white border border-slate-200 rounded-lg p-4">
    <?= $csrf->field() ?>
    <div class="font-medium">Research &amp; draft</div>
    <p class="text-xs text-slate-500 mt-1"><?= e($newCount) ?> new lead(s). Processes up to 3 per click<?= $aiEnabled ? '' : ' (AI not configured: research only)' ?>.</p>
    <input type="hidden" name="draft" value="<?= $aiEnabled ? '1' : '0' ?>">
    <button class="mt-2 text-sm bg-indigo-600 text-white rounded px-3 py-1.5 disabled:opacity-50" <?= $newCount === 0 ? 'disabled' : '' ?> data-busy="Working… this reads each website politely, so it can take a minute.">Process next 3</button>
  </form>
</div>
<?= $view->partial('_lead_table', ['leads' => $leads]) ?>
