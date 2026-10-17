<?php
/** @var array<string, mixed> $draft */
/** @var array<string, mixed> $lead */
/** @var string|null $error */
/** @var string $notice */
use App\Drafting\DraftLimits;

$id = (int) $draft['id'];
$channel = (string) $draft['channel'];
$editable = in_array($draft['status'], ['pending', 'approved'], true);
$pending = $draft['status'] === 'pending';
$rtl = in_array($draft['language'], ['ar', 'ur'], true);
$statusColours = ['pending' => 'bg-amber-100 text-amber-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'queued' => 'bg-sky-100 text-sky-800', 'sent' => 'bg-slate-200 text-slate-700', 'rejected' => 'bg-red-100 text-red-700', 'superseded' => 'bg-slate-100 text-slate-500'];
$limit = match ($channel) {
    'email' => ((int) $draft['is_followup'] === 1 ? DraftLimits::FOLLOWUP_MAX_WORDS : DraftLimits::EMAIL_MAX_WORDS) . ' words',
    'linkedin' => DraftLimits::LINKEDIN_MAX_CHARS . ' characters',
    default => DraftLimits::WHATSAPP_MAX_WORDS . ' words',
};
$waNumber = (string) App\Leads\Normalizer::phone((string) ($lead['phone'] ?? ''), (string) ($lead['country'] ?? ''));
?>
<article id="draft-<?= e($id) ?>" class="draft border border-slate-200 rounded-lg p-3 bg-white <?= $pending ? 'draft-pending' : '' ?>" data-draft-id="<?= e($id) ?>" data-channel="<?= e($channel) ?>" tabindex="-1">
  <div class="flex flex-wrap items-center gap-2 text-xs mb-2">
    <span class="font-semibold uppercase tracking-wide text-slate-700"><?= e($channel) ?><?= (int) $draft['is_followup'] === 1 ? ' follow-up' : '' ?></span>
    <span class="rounded-full px-2 py-0.5 <?= e($statusColours[$draft['status']] ?? '') ?>"><?= e($draft['status']) ?></span>
    <span class="text-slate-500"><?= e(strtoupper((string) $draft['language'])) ?> · limit <?= e($limit) ?></span>
    <?php if ((int) $draft['edited'] === 1): ?><span class="text-slate-500">edited</span><?php endif; ?>
    <span class="ml-auto text-slate-400" data-counter>…</span>
  </div>
  <?php if ($error !== null && $error !== ''): ?><p class="text-xs text-red-700 bg-red-50 rounded px-2 py-1 mb-2"><?= e($error) ?></p><?php endif; ?>
  <?php if ($notice !== ''): ?><p class="text-xs text-emerald-700 bg-emerald-50 rounded px-2 py-1 mb-2"><?= e($notice) ?></p><?php endif; ?>
  <?php if ((string) $draft['warnings'] !== ''): ?>
    <ul class="text-xs text-amber-800 bg-amber-50 rounded px-2 py-1 mb-2 list-disc pl-5"><?php foreach (explode("\n", (string) $draft['warnings']) as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ((string) $draft['observation'] !== ''): ?><p class="text-xs text-slate-500 mb-2">Based on: <?= e($draft['observation']) ?></p><?php endif; ?>

  <form hx-post="<?= e($base) ?>/drafts/<?= e($id) ?>/edit" hx-target="#draft-<?= e($id) ?>" hx-swap="outerHTML" method="post" action="<?= e($base) ?>/drafts/<?= e($id) ?>/edit" class="space-y-2" data-draft-form>
    <?= $csrf->field() ?>
    <?php if ($channel === 'email'): ?>
      <input name="subject" value="<?= e($draft['subject']) ?>" <?= $editable ? '' : 'readonly' ?> class="w-full border rounded px-2 py-1 text-sm font-medium" aria-label="Subject" dir="auto">
    <?php endif; ?>
    <textarea name="body" rows="<?= $channel === 'email' ? 8 : 4 ?>" <?= $editable ? '' : 'readonly' ?> class="w-full border rounded px-2 py-1 text-sm" aria-label="Message" dir="<?= $rtl ? 'rtl' : 'auto' ?>" data-limit-channel="<?= e($channel) ?>" data-followup="<?= e($draft['is_followup']) ?>"><?= e($draft['body']) ?></textarea>
    <?php if ($channel === 'email'): ?><p class="text-xs text-slate-400">Your signature, postal address and an unsubscribe link are added below the body when queued.</p><?php endif; ?>
    <div class="flex flex-wrap gap-2 text-sm">
      <?php if ($pending): ?>
        <button type="button" class="bg-emerald-600 text-white rounded px-3 py-1 disabled:opacity-40" data-shortcut="approve" hx-post="<?= e($base) ?>/drafts/<?= e($id) ?>/approve" hx-target="#draft-<?= e($id) ?>" hx-swap="outerHTML" hx-include="closest form">Approve <kbd class="text-xs opacity-75">a</kbd></button>
      <?php endif; ?>
      <?php if ($editable): ?>
        <button class="border border-slate-300 rounded px-3 py-1" data-shortcut="save">Save edit <kbd class="text-xs text-slate-400">s</kbd></button>
        <button type="button" class="border border-red-200 text-red-700 rounded px-3 py-1" data-shortcut="reject" hx-post="<?= e($base) ?>/drafts/<?= e($id) ?>/reject" hx-target="#draft-<?= e($id) ?>" hx-swap="outerHTML">Reject <kbd class="text-xs opacity-75">r</kbd></button>
      <?php endif; ?>
      <?php if ($pending && (int) $draft['is_followup'] === 0): ?>
        <button type="button" class="border border-slate-300 rounded px-3 py-1" data-shortcut="regenerate" hx-post="<?= e($base) ?>/drafts/<?= e($id) ?>/regenerate" hx-confirm="Replace this lead's pending drafts with new ones? (This uses AI budget.)">Regenerate <kbd class="text-xs text-slate-400">g</kbd></button>
      <?php endif; ?>
      <?php if ($channel !== 'email'): ?>
        <button type="button" class="border border-slate-300 rounded px-3 py-1" data-copy>Copy</button>
        <?php if ($channel === 'whatsapp' && $waNumber !== ''): ?>
          <a class="border border-slate-300 rounded px-3 py-1" href="https://wa.me/<?= e($waNumber) ?>?text=<?= e(rawurlencode((string) $draft['body'])) ?>" target="_blank" rel="noopener noreferrer">Open in WhatsApp</a>
        <?php endif; ?>
        <?php if ($channel === 'linkedin' && (string) ($lead['linkedin_url'] ?? '') !== ''): ?>
          <a class="border border-slate-300 rounded px-3 py-1" href="<?= e($lead['linkedin_url']) ?>" target="_blank" rel="noopener noreferrer">Open LinkedIn profile</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </form>
  <?php if ($draft['status'] === 'approved' && $channel !== 'email'): ?>
    <form method="post" action="<?= e($base) ?>/drafts/<?= e($id) ?>/sent" class="mt-2"><?= $csrf->field() ?><button class="text-sm bg-slate-800 text-white rounded px-3 py-1">I sent this by hand</button></form>
  <?php endif; ?>
</article>
