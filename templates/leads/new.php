<?php /** @var list<array<string, mixed>> $campaigns */ /** @var array<string, mixed> $old */ ?>
<h1 class="text-2xl font-semibold mb-4">Add a lead</h1>
<?php if ($error !== ''): ?><div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e($base) ?>/leads" class="bg-white border border-slate-200 rounded-lg p-6 max-w-3xl space-y-4">
  <?= $csrf->field() ?>
  <?= $view->partial('_lead_fields', ['values' => $old + ['campaign_id' => $campaignId], 'campaigns' => $campaigns]) ?>
  <label class="block text-sm">Notes<textarea name="notes" rows="3" class="mt-1 w-full border rounded px-3 py-1.5"><?= e($old['notes'] ?? '') ?></textarea></label>
  <p class="text-xs text-slate-500">Only add businesses and business contact details you are allowed to use. Duplicates (same website domain or phone) are refused.</p>
  <button class="bg-indigo-600 text-white rounded px-4 py-2 text-sm">Add lead</button>
</form>
