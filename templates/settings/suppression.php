<?php /** @var list<array<string, mixed>> $entries */ ?>
<h1 class="text-2xl font-semibold mb-2">Suppression list</h1>
<p class="text-sm text-slate-600 mb-6">Nobody on this list is emailed. It is checked when a draft is approved, when an email is queued and right before it is sent. Unsubscribes and "do not contact" leads are added automatically and kept after data retention.</p>
<form method="post" action="<?= e($base) ?>/suppression" class="bg-white border border-slate-200 rounded-lg p-4 mb-6 grid md:grid-cols-4 gap-3 text-sm items-end">
  <?= $csrf->field() ?>
  <label>Type<select name="type" class="block mt-1 w-full border rounded px-2 py-1.5"><option value="email">Email address</option><option value="domain">Whole domain</option><option value="phone">Phone (international format)</option></select></label>
  <label class="md:col-span-2">Values (one per line or comma separated)<textarea name="values" rows="2" class="block mt-1 w-full border rounded px-2 py-1.5" required></textarea></label>
  <label>Reason<input name="reason" placeholder="Asked by phone" class="block mt-1 w-full border rounded px-2 py-1.5"></label>
  <button class="bg-indigo-600 text-white rounded px-4 py-2 md:col-start-4">Add</button>
</form>
<div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
  <table class="w-full text-sm">
    <thead class="bg-slate-50 text-left text-slate-500"><tr><th class="px-3 py-2">Type</th><th class="px-3 py-2">Value</th><th class="px-3 py-2">Reason</th><th class="px-3 py-2">Added (UTC)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($entries as $s): ?>
      <tr class="border-t border-slate-100"><td class="px-3 py-2"><?= e($s['type']) ?></td><td class="px-3 py-2 font-mono"><?= e($s['value']) ?></td><td class="px-3 py-2"><?= e($s['reason']) ?></td><td class="px-3 py-2"><?= e($s['created_at']) ?></td>
        <td class="px-3 py-2"><form method="post" action="<?= e($base) ?>/suppression/<?= e($s['id']) ?>/delete" data-confirm="Remove this entry? Only do this if they asked to hear from you again."><?= $csrf->field() ?><button class="text-xs text-red-700">Remove</button></form></td></tr>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">Empty.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
