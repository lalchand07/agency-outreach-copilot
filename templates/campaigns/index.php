<?php /** @var list<array<string, mixed>> $campaigns */ ?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold">Campaigns</h1>
  <a href="<?= e($base) ?>/campaigns/new" class="bg-indigo-600 text-white rounded px-3 py-2 text-sm hover:bg-indigo-700">New campaign</a>
</div>
<?php if ($campaigns === []): ?>
  <div class="bg-white border border-slate-200 rounded-lg p-6 text-sm text-slate-600">No campaigns yet. A campaign holds your target criteria (country, cities, business type), languages and channels.</div>
<?php endif; ?>
<div class="grid md:grid-cols-2 gap-4">
<?php foreach ($campaigns as $c): ?>
  <a href="<?= e($base) ?>/campaigns/<?= e($c['id']) ?>" class="block bg-white border border-slate-200 rounded-lg p-4 hover:border-indigo-300">
    <div class="flex justify-between"><h2 class="font-medium"><?= e($c['name']) ?></h2><span class="text-sm text-slate-500"><?= e($c['lead_count']) ?> leads</span></div>
    <p class="text-sm text-slate-600 mt-1"><?= e($c['country']) ?><?= $c['cities'] !== [] ? ' · ' . e(implode(', ', $c['cities'])) : '' ?></p>
    <p class="text-xs text-slate-500 mt-1"><?= e(implode(' · ', $c['keywords'])) ?></p>
  </a>
<?php endforeach; ?>
</div>
