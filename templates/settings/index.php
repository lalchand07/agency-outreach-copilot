<?php
/** @var array<string, int> $settings */
/** @var array<string, bool> $checks */
/** @var App\Config\ProductProfile $product */
use App\Config\Settings;

$labels = [
    'daily_send_cap' => ['Daily email cap', 'Emails sent per day at most (default 20, hard maximum ' . Settings::MAX['daily_send_cap'] . ').'],
    'followup_after_days' => ['Follow-up after (days)', 'Days without a reply before a follow-up draft is suggested.'],
    'max_followups' => ['Follow-ups per lead', 'Default 1. Each follow-up still needs your approval. 0 turns them off.'],
    'retention_days' => ['Retention (days)', 'Delete details of "not interested" and "do not contact" leads after this many days. Suppression entries are kept.'],
    'research_max_pages' => ['Pages read per website', 'Up to 5 (home, about, contact, services…).'],
];
?>
<h1 class="text-2xl font-semibold mb-6">Settings</h1>
<div class="grid lg:grid-cols-2 gap-6">
  <form method="post" action="<?= e($base) ?>/settings" class="bg-white border border-slate-200 rounded-lg p-4 space-y-4">
    <?= $csrf->field() ?>
    <h2 class="font-medium">Limits</h2>
    <?php foreach ($labels as $key => [$label, $help]): ?>
      <label class="block text-sm"><span class="font-medium"><?= e($label) ?></span>
        <input type="number" name="<?= e($key) ?>" value="<?= e($settings[$key]) ?>" min="<?= $key === 'max_followups' ? 0 : 1 ?>" max="<?= e(Settings::MAX[$key]) ?>" class="mt-1 block w-32 border rounded px-2 py-1.5">
        <span class="text-xs text-slate-500"><?= e($help) ?></span></label>
    <?php endforeach; ?>
    <button class="bg-indigo-600 text-white rounded px-4 py-2 text-sm">Save</button>
  </form>

  <div class="space-y-6">
    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <h2 class="font-medium mb-3">Setup checklist</h2>
      <ul class="text-sm space-y-1">
        <?php foreach ($checks as $label => $ok): ?><li><?= $ok ? '<span class="text-emerald-600">✓</span>' : '<span class="text-red-600">✗</span>' ?> <?= e($label) ?></li><?php endforeach; ?>
      </ul>
      <p class="text-xs text-slate-500 mt-3">Secrets (API keys, SMTP password) are read from <code>.env</code> only. They are never stored in the database or shown here.</p>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <h2 class="font-medium mb-2">Sender identity (from config/product.php)</h2>
      <pre class="text-xs bg-slate-50 rounded p-3 whitespace-pre-wrap"><?= e(trim($product->sender('name') . ', ' . $product->sender('title') . "\n" . $product->sender('company') . "\n" . $product->sender('address') . "\n" . $product->sender('email'))) ?></pre>
    </section>
    <section class="bg-white border border-slate-200 rounded-lg p-4 text-sm space-y-3">
      <h2 class="font-medium">Maintenance</h2>
      <form method="post" action="<?= e($base) ?>/settings/followups" class="flex items-center justify-between gap-3"><?= $csrf->field() ?><span><?= e($followupsDue) ?> follow-up(s) due</span><button class="border border-slate-300 rounded px-3 py-1.5" <?= $followupsDue === 0 ? 'disabled' : '' ?>>Draft follow-ups</button></form>
      <form method="post" action="<?= e($base) ?>/settings/retention" class="flex items-center justify-between gap-3" data-confirm="Permanently delete the details of these leads?"><?= $csrf->field() ?><span><?= e($retentionCandidates) ?> lead(s) past the retention period</span><button class="border border-red-200 text-red-700 rounded px-3 py-1.5" <?= $retentionCandidates === 0 ? 'disabled' : '' ?>>Apply retention now</button></form>
      <p class="text-xs text-slate-500">Both also run from cron: <code>php bin/console followups</code> and <code>php bin/console retention</code>.</p>
    </section>
  </div>
</div>
