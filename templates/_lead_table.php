<?php /** @var list<array<string, mixed>> $leads */ use App\Pipeline\Status; ?>
<div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
<table class="w-full text-sm">
  <thead class="bg-slate-50 text-left text-slate-500"><tr><th class="px-3 py-2">Business</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Score</th><th class="px-3 py-2">Email</th><th class="px-3 py-2">City</th><th class="px-3 py-2">Next action</th></tr></thead>
  <tbody>
  <?php foreach ($leads as $lead): ?>
    <tr class="border-t border-slate-100 hover:bg-slate-50">
      <td class="px-3 py-2"><a class="text-indigo-700 hover:underline font-medium" href="<?= e($base) ?>/leads/<?= e($lead['id']) ?>"><?= e($lead['name'] !== '' ? $lead['name'] : ($lead['domain'] ?? $lead['website'])) ?></a><div class="text-xs text-slate-500"><?= e($lead['domain'] ?? '') ?></div></td>
      <td class="px-3 py-2"><span class="inline-block rounded-full bg-slate-100 px-2 py-0.5 text-xs"><?= e(Status::label((string) $lead['status'])) ?></span></td>
      <td class="px-3 py-2" title="<?= e($lead['score_reason'] ?? '') ?>"><?= $lead['score'] !== null ? e($lead['score']) : '<span class="text-slate-400">–</span>' ?></td>
      <td class="px-3 py-2"><?= e($lead['email']) ?></td>
      <td class="px-3 py-2"><?= e($lead['city']) ?></td>
      <td class="px-3 py-2"><?= e(substr((string) ($lead['next_action_at'] ?? ''), 0, 10)) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($leads === []): ?><tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">No leads.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
