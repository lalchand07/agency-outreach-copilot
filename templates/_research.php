<?php
/** @var array<string, mixed> $research */
$result = $research['result'];
$facts = $research['facts'];
$labels = ['solo' => 'one person', 'small' => 'small', 'medium' => 'medium', 'large' => 'large', 'unknown' => 'unknown'];
?>
<div class="text-sm space-y-3">
  <?php if ($research['error'] !== ''): ?><p class="text-amber-800 bg-amber-50 rounded px-3 py-2"><?= e($research['error']) ?></p><?php endif; ?>
  <?php if ($result !== []): ?>
    <?php if (($result['summary'] ?? '') !== ''): ?><p class="text-slate-700"><?= e($result['summary']) ?></p><?php endif; ?>
    <dl class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-1">
      <div><dt class="text-xs text-slate-500">Services</dt><dd><?= e(implode(', ', (array) $result['services']) ?: 'none found') ?></dd></div>
      <div><dt class="text-xs text-slate-500">Size</dt><dd title="<?= e($result['size']['signals'] ?? '') ?>"><?= e($labels[$result['size']['estimate'] ?? 'unknown'] ?? 'unknown') ?></dd></div>
      <div><dt class="text-xs text-slate-500">Prices</dt><dd><?= e(str_replace('_', ' ', (string) ($result['pricing'] ?? 'unknown'))) ?></dd></div>
      <div><dt class="text-xs text-slate-500">Online quote form</dt><dd><?= !empty($result['has_quote_form']) ? 'yes' : 'not found' ?></dd></div>
      <div><dt class="text-xs text-slate-500">WhatsApp</dt><dd><?= !empty($result['whatsapp']) ? 'yes' : 'not found' ?></dd></div>
      <div><dt class="text-xs text-slate-500">Languages</dt><dd><?= e(implode(', ', (array) $result['languages']) ?: 'unknown') ?></dd></div>
      <div class="col-span-2"><dt class="text-xs text-slate-500">ATOL / IATA / other</dt><dd><?= e(implode(', ', (array) $result['accreditations']) ?: 'none mentioned') ?></dd></div>
    </dl>
    <div>
      <h3 class="text-xs font-medium text-slate-500 uppercase tracking-wide mb-1">Verified observations</h3>
      <?php if (($result['observations'] ?? []) === []): ?><p class="text-slate-500">None could be verified.</p><?php endif; ?>
      <ol class="list-decimal pl-5 space-y-1">
        <?php foreach ((array) $result['observations'] as $obs): ?>
          <li><?= e($obs['text']) ?> <a class="text-xs text-indigo-700 hover:underline" href="<?= e($obs['source_url']) ?>" target="_blank" rel="noopener noreferrer">source</a>
            <div class="text-xs text-slate-500 italic">“<?= e($obs['evidence']) ?>”<?= ($obs['origin'] ?? '') === 'facts' ? ' · from the HTML' : '' ?></div></li>
        <?php endforeach; ?>
      </ol>
    </div>
  <?php endif; ?>
  <details class="text-xs text-slate-600">
    <summary class="cursor-pointer">Pages read (<?= e(count($research['pages']['fetched'] ?? [])) ?>), skipped (<?= e(count($research['pages']['skipped'] ?? [])) ?>), dropped claims (<?= e(count($research['dropped'])) ?>), contacts found</summary>
    <ul class="list-disc pl-5 mt-2 space-y-0.5">
      <?php foreach ($research['pages']['fetched'] ?? [] as $p): ?><li><a class="text-indigo-700" href="<?= e($p['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['url']) ?></a> — <?= e($p['title']) ?></li><?php endforeach; ?>
      <?php foreach ($research['pages']['skipped'] ?? [] as $p): ?><li class="text-slate-500">Skipped <?= e($p['url']) ?>: <?= e($p['reason']) ?></li><?php endforeach; ?>
      <?php foreach ($research['dropped'] as $d): ?><li class="text-amber-700">Dropped: <?= e($d) ?></li><?php endforeach; ?>
    </ul>
    <p class="mt-2">Emails on site: <?= e(implode(', ', (array) ($facts['emails'] ?? [])) ?: 'none') ?> · Phones: <?= e(implode(', ', (array) ($facts['phones'] ?? [])) ?: 'none') ?></p>
    <p class="text-slate-400">Researched <?= e($research['created_at']) ?> UTC</p>
  </details>
</div>
