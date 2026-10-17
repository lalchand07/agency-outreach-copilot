<?php
/** @var list<string> $headers */
/** @var list<list<string>> $preview */
/** @var array<string, int> $mapping */
/** @var list<array<string, mixed>> $campaigns */
use App\Leads\CsvImporter;
?>
<h1 class="text-2xl font-semibold mb-2">Map columns</h1>
<p class="text-sm text-slate-600 mb-6"><?= e($rowCount) ?> row(s) found. Pick the column for each field; duplicates by website domain or phone are skipped.</p>
<form method="post" action="<?= e($base) ?>/import/confirm" class="space-y-6">
  <?= $csrf->field() ?>
  <div class="bg-white border border-slate-200 rounded-lg p-4 grid md:grid-cols-3 gap-3 text-sm">
    <?php foreach (CsvImporter::FIELDS as $field => $label): ?>
      <label><?= e($label) ?><select name="map_<?= e($field) ?>" class="block mt-1 w-full border rounded px-2 py-1.5"><option value="-1">— not imported —</option><?php foreach ($headers as $i => $h): ?><option value="<?= e($i) ?>" <?= ($mapping[$field] ?? -1) === $i ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?></select></label>
    <?php endforeach; ?>
    <label>Campaign<select name="campaign_id" class="block mt-1 w-full border rounded px-2 py-1.5"><option value="">None</option><?php foreach ($campaigns as $c): ?><option value="<?= e($c['id']) ?>" <?= $campaignId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
    <table class="w-full text-xs"><thead class="bg-slate-50"><tr><?php foreach ($headers as $h): ?><th class="px-2 py-1 text-left"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($preview as $row): ?><tr class="border-t border-slate-100"><?php foreach ($headers as $i => $_): ?><td class="px-2 py-1"><?= e($row[$i] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table>
  </div>
  <button class="bg-indigo-600 text-white rounded px-4 py-2 text-sm">Import</button>
</form>
