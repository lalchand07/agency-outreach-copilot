<?php
/** @var array<string, int> $byStatus */
/** @var array<string, array{email: int, manual: int}> $perDay */
/** @var array{contacted: int, replied: int, rate: float} $reply */
/** @var array{enabled: bool, total: float, remaining: float|null, by_feature: list<array<string, mixed>>, error: string|null} $ai */
/** @var App\Config\ProductProfile $product */
use App\Pipeline\Status;

$maxDay = max(1, ...array_map(static fn (array $d): int => $d['email'] + $d['manual'], array_values($perDay)));
$totalLeads = array_sum($byStatus);
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-semibold">Dashboard</h1>
  <a href="<?= e($base) ?>/export.csv" class="text-sm border border-slate-300 bg-white rounded px-3 py-1.5 hover:bg-slate-50">Export pipeline CSV</a>
</div>

<?php if ($product->isExample || $product->missingSenderFields() !== []): ?>
  <div class="mb-6 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm">
    <strong>Finish setup:</strong> copy <code>config/product.example.php</code> to <code>config/product.php</code> and fill in your product and sender details (name, company, email and postal address). Sending is blocked until the sender details are complete.
  </div>
<?php endif; ?>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
  <a href="<?= e($base) ?>/review" class="bg-white border border-slate-200 rounded-lg p-4 hover:border-indigo-300">
    <div class="text-sm text-slate-500">Drafts to review</div><div class="text-3xl font-semibold"><?= e($pendingDrafts) ?></div>
  </a>
  <a href="<?= e($base) ?>/outbox" class="bg-white border border-slate-200 rounded-lg p-4 hover:border-indigo-300">
    <div class="text-sm text-slate-500">Emails sent today</div><div class="text-3xl font-semibold"><?= e($sentToday) ?><span class="text-base text-slate-400"> / <?= e($cap) ?></span></div>
    <div class="text-xs text-slate-500"><?= e($queued) ?> queued</div>
  </a>
  <div class="bg-white border border-slate-200 rounded-lg p-4">
    <div class="text-sm text-slate-500">Reply rate</div><div class="text-3xl font-semibold"><?= e($reply['rate']) ?>%</div>
    <div class="text-xs text-slate-500"><?= e($reply['replied']) ?> of <?= e($reply['contacted']) ?> contacted</div>
  </div>
  <div class="bg-white border border-slate-200 rounded-lg p-4">
    <div class="text-sm text-slate-500">AI spend this month</div>
    <?php if (!$ai['enabled']): ?>
      <div class="text-base text-slate-400 mt-2">AI not configured</div>
    <?php else: ?>
      <div class="text-3xl font-semibold">$<?= e(number_format($ai['total'], 2)) ?></div>
      <div class="text-xs text-slate-500"><?= $ai['remaining'] !== null ? '$' . e(number_format($ai['remaining'], 2)) . ' left in the monthly budget' : 'no monthly limit' ?></div>
    <?php endif; ?>
  </div>
</div>

<div class="grid md:grid-cols-3 gap-6">
  <section class="bg-white border border-slate-200 rounded-lg p-4 md:col-span-1">
    <h2 class="font-medium mb-3">Leads by status <span class="text-slate-400 text-sm">(<?= e($totalLeads) ?>)</span></h2>
    <ul class="space-y-1.5 text-sm">
      <?php foreach ($byStatus as $status => $n): ?>
        <li><a class="flex justify-between hover:text-indigo-700" href="<?= e($base) ?>/leads?status=<?= e($status) ?>"><span><?= e(Status::label($status)) ?></span><span class="font-mono"><?= e($n) ?></span></a>
          <div class="h-1 bg-slate-100 rounded"><div class="h-1 bg-indigo-400 rounded" style="width: <?= e($totalLeads > 0 ? round($n / $totalLeads * 100) : 0) ?>%"></div></div></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="bg-white border border-slate-200 rounded-lg p-4 md:col-span-2">
    <h2 class="font-medium mb-3">Messages sent per day (last 14 days)</h2>
    <div class="flex items-end gap-1 h-40" role="img" aria-label="Messages sent per day">
      <?php foreach ($perDay as $day => $d): $total = $d['email'] + $d['manual']; ?>
        <div class="flex-1 flex flex-col items-center justify-end h-full" title="<?= e($day) ?>: <?= e($d['email']) ?> email, <?= e($d['manual']) ?> manual">
          <span class="text-[10px] text-slate-500"><?= $total > 0 ? e($total) : '' ?></span>
          <div class="w-full bg-indigo-200 rounded-t" style="height: <?= e(round($d['manual'] / $maxDay * 100)) ?>%"></div>
          <div class="w-full bg-indigo-500" style="height: <?= e(round($d['email'] / $maxDay * 100)) ?>%"></div>
          <span class="text-[10px] text-slate-400 mt-1"><?= e(substr($day, 8, 2)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-slate-500 mt-2"><span class="inline-block w-2 h-2 bg-indigo-500"></span> email &nbsp; <span class="inline-block w-2 h-2 bg-indigo-200"></span> LinkedIn/WhatsApp (sent by hand)</p>

    <?php if ($ai['enabled'] && $ai['by_feature'] !== []): ?>
      <h3 class="font-medium mt-6 mb-2 text-sm">AI usage by feature (php-ai-guard)</h3>
      <table class="w-full text-sm">
        <thead class="text-slate-500 text-left"><tr><th class="py-1">Feature</th><th>Requests</th><th>Tokens in/out</th><th class="text-right">Cost</th></tr></thead>
        <tbody>
          <?php foreach ($ai['by_feature'] as $row): ?>
            <tr class="border-t border-slate-100"><td class="py-1"><?= e($row['group'] !== '' ? $row['group'] : '(none)') ?></td><td><?= e($row['requests']) ?></td><td><?= e(number_format((int) $row['input_tokens'])) ?> / <?= e(number_format((int) $row['output_tokens'])) ?></td><td class="text-right">$<?= e(number_format((float) $row['cost_usd'], 4)) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php if ($ai['error'] !== null): ?><p class="text-xs text-red-600 mt-2">AI usage could not be read: <?= e($ai['error']) ?></p><?php endif; ?>
  </section>
</div>

<div class="grid md:grid-cols-2 gap-6 mt-6">
  <section class="bg-white border border-slate-200 rounded-lg p-4">
    <h2 class="font-medium mb-3">Next actions due</h2>
    <?php if ($dueLeads === []): ?><p class="text-sm text-slate-500">Nothing due.</p><?php endif; ?>
    <ul class="text-sm space-y-1">
      <?php foreach ($dueLeads as $lead): ?>
        <li><a class="text-indigo-700 hover:underline" href="<?= e($base) ?>/leads/<?= e($lead['id']) ?>"><?= e($lead['name'] !== '' ? $lead['name'] : $lead['website']) ?></a> <span class="text-slate-500">· <?= e(substr((string) $lead['next_action_at'], 0, 10)) ?> · <?= e(Status::label((string) $lead['status'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="bg-white border border-slate-200 rounded-lg p-4">
    <h2 class="font-medium mb-3">Follow-ups</h2>
    <p class="text-sm text-slate-600 mb-3"><?= e($followupsDue) ?> contacted lead(s) have not replied and are due their one follow-up. Follow-ups are drafts: you still approve each one.</p>
    <form method="post" action="<?= e($base) ?>/settings/followups"><?= $csrf->field() ?><button class="text-sm bg-indigo-600 text-white rounded px-3 py-1.5 disabled:opacity-50" <?= $followupsDue === 0 ? 'disabled' : '' ?>>Draft follow-ups</button></form>
  </section>
</div>
