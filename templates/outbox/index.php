<?php
/** @var list<array<string, mixed>> $queued */
/** @var list<array<string, mixed>> $failed */
/** @var list<array<string, mixed>> $log */
/** @var list<array<string, mixed>> $manual */
/** @var list<string> $missingSender */
?>
<h1 class="text-2xl font-semibold mb-4">Outbox</h1>
<?php if ($missingSender !== []): ?><div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">Sending is blocked: set the sender <?= e(implode(', ', $missingSender)) ?> in <code>config/product.php</code>.</div><?php endif; ?>
<?php if (!$smtpConfigured): ?><div class="mb-4 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm">SMTP is not configured (<code>SMTP_HOST</code> in <code>.env</code>). Queued emails will fail until it is.</div><?php endif; ?>

<section class="bg-white border border-slate-200 rounded-lg p-4 mb-6">
  <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <h2 class="font-medium">Email queue <span class="text-slate-400">(<?= e(count($queued)) ?>)</span></h2>
    <form method="post" action="<?= e($base) ?>/outbox/process" class="flex items-center gap-3 text-sm">
      <?= $csrf->field() ?>
      <span class="text-slate-600"><?= e($sentToday) ?> of <?= e($cap) ?> sent today</span>
      <button class="bg-indigo-600 text-white rounded px-3 py-1.5 disabled:opacity-50" <?= $queued === [] || $missingSender !== [] ? 'disabled' : '' ?> data-busy="Sending…">Send queued now</button>
    </form>
  </div>
  <p class="text-xs text-slate-500 mb-3">Or let cron send them: <code>php bin/console send</code> every 15 minutes. The daily cap and the suppression list are checked again right before each email goes out.</p>
  <?= $view->partial('outbox/_table', ['messages' => array_merge($failed, $queued), 'actions' => true]) ?>
</section>

<section class="bg-white border border-slate-200 rounded-lg p-4 mb-6">
  <h2 class="font-medium mb-1">Send by hand: LinkedIn and WhatsApp <span class="text-slate-400">(<?= e(count($manual)) ?>)</span></h2>
  <p class="text-xs text-slate-500 mb-3">This tool never automates LinkedIn or WhatsApp. Copy the text or open the wa.me link, send it yourself, then mark it as sent.</p>
  <div class="grid md:grid-cols-2 gap-3">
    <?php foreach ($manual as $draft): ?>
      <div>
        <p class="text-sm font-medium mb-1"><a class="hover:underline" href="<?= e($base) ?>/leads/<?= e($draft['lead_id']) ?>"><?= e($draft['lead_name']) ?></a></p>
        <?= $view->partial('review/_draft', ['draft' => $draft, 'lead' => $draft, 'error' => null, 'notice' => '']) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($manual === []): ?><p class="text-sm text-slate-500">Nothing waiting.</p><?php endif; ?>
</section>

<section class="bg-white border border-slate-200 rounded-lg p-4">
  <h2 class="font-medium mb-3">Send log (last 200)</h2>
  <?= $view->partial('outbox/_table', ['messages' => $log, 'actions' => false]) ?>
</section>
