<?php /** @var list<array<string, mixed>> $messages */ /** @var bool $actions */ ?>
<div class="overflow-x-auto">
<table class="w-full text-sm">
  <thead class="text-left text-slate-500"><tr><th class="py-1 pr-3">When (UTC)</th><th class="pr-3">Channel</th><th class="pr-3">To</th><th class="pr-3">Subject</th><th class="pr-3">Status</th><?php if ($actions): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($messages as $m): ?>
    <tr class="border-t border-slate-100 align-top">
      <td class="py-1 pr-3 whitespace-nowrap"><?= e($m['sent_at'] ?? $m['queued_at']) ?></td>
      <td class="pr-3"><?= e($m['channel']) ?><?= (int) $m['is_followup'] === 1 ? ' (follow-up)' : '' ?></td>
      <td class="pr-3"><?php if ($m['lead_id'] !== null): ?><a class="text-indigo-700 hover:underline" href="<?= e($base) ?>/leads/<?= e($m['lead_id']) ?>"><?= e($m['recipient']) ?></a><?php else: ?><span class="text-slate-400"><?= e(substr((string) $m['recipient'], 0, 20)) ?>…</span><?php endif; ?></td>
      <td class="pr-3"><?= e($m['subject']) ?></td>
      <td class="pr-3"><?= e($m['status']) ?><?php if ((string) $m['error'] !== ''): ?><div class="text-xs text-red-700"><?= e($m['error']) ?></div><?php endif; ?></td>
      <?php if ($actions): ?>
        <td class="whitespace-nowrap">
          <?php if ($m['status'] === 'failed'): ?><form method="post" action="<?= e($base) ?>/messages/<?= e($m['id']) ?>/retry" class="inline"><?= $csrf->field() ?><button class="text-xs text-indigo-700">Retry</button></form><?php endif; ?>
          <form method="post" action="<?= e($base) ?>/messages/<?= e($m['id']) ?>/cancel" class="inline ml-2"><?= $csrf->field() ?><button class="text-xs text-red-700">Remove</button></form>
        </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($messages === []): ?><tr><td colspan="6" class="py-4 text-center text-slate-500">Empty.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
