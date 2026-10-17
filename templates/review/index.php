<?php
/** @var list<array{lead: array<string, mixed>, drafts: list<array<string, mixed>>}> $groups */
/** @var array<int, array<string, mixed>|null> $research */
/** @var list<array<string, mixed>> $campaigns */
?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-2">
  <h1 class="text-2xl font-semibold">Review queue</h1>
  <form method="get" class="text-sm"><select name="campaign_id" class="border rounded px-2 py-1.5" data-autosubmit><option value="">All campaigns</option><?php foreach ($campaigns as $c): ?><option value="<?= e($c['id']) ?>" <?= $campaignId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></form>
</div>
<p class="text-sm text-slate-600 mb-4">Approve each message you are happy to send. Approved emails go to the outbox queue (<?= e($remaining) ?> left in today's cap); LinkedIn and WhatsApp drafts wait in the outbox for you to send by hand.
  <span class="text-slate-500">Keys: <kbd>j</kbd>/<kbd>k</kbd> next/previous draft, <kbd>a</kbd> approve, <kbd>r</kbd> reject, <kbd>e</kbd> edit, <kbd>s</kbd> save edit, <kbd>g</kbd> regenerate, <kbd>Esc</kbd> leave the text box.</span></p>

<?php if ($groups === []): ?>
  <div class="bg-white border border-slate-200 rounded-lg p-6 text-sm text-slate-600">Nothing to review. Research and draft leads from a campaign page.</div>
<?php endif; ?>

<div class="space-y-6" id="review">
<?php foreach ($groups as $group): $lead = $group['lead']; $r = $research[(int) $lead['id']] ?? null; ?>
  <section class="grid lg:grid-cols-5 gap-4 bg-slate-100/60 border border-slate-200 rounded-xl p-4">
    <aside class="lg:col-span-2 text-sm">
      <h2 class="font-semibold text-base"><a class="hover:underline" href="<?= e($base) ?>/leads/<?= e($lead['id']) ?>"><?= e($lead['name'] !== '' ? $lead['name'] : $lead['website']) ?></a></h2>
      <p class="text-slate-600 text-xs mb-2"><?= e($lead['domain'] ?? '') ?> · <?= e($lead['email'] !== '' ? $lead['email'] : 'no email') ?> · <?= e(trim($lead['city'] . ', ' . $lead['country'], ', ')) ?> · score <?= e($lead['score'] ?? '–') ?></p>
      <?php if ((string) $lead['score_reason'] !== ''): ?><p class="text-xs text-slate-500 mb-3"><?= e($lead['score_reason']) ?></p><?php endif; ?>
      <?php if ($r !== null): ?><?= $view->partial('_research', ['research' => $r]) ?><?php else: ?><p class="text-slate-500">No research.</p><?php endif; ?>
    </aside>
    <div class="lg:col-span-3 space-y-3">
      <?php foreach ($group['drafts'] as $draft): ?>
        <?= $view->partial('review/_draft', ['draft' => $draft, 'lead' => $lead, 'error' => null, 'notice' => '']) ?>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
