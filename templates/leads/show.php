<?php
/** @var array<string, mixed> $lead */
/** @var array<string, mixed>|null $campaign */
/** @var array<string, mixed>|null $research */
/** @var list<array<string, mixed>> $drafts */
/** @var list<array<string, mixed>> $messages */
/** @var list<array<string, mixed>> $activity */
/** @var string|null $suppressed */
use App\Leads\CampaignRepository;
use App\Pipeline\Status;

$id = (int) $lead['id'];
$result = $research['result'] ?? [];
$facts = $research['facts'] ?? [];
$languages = $campaign['languages'] ?? ['en'];
$channels = $campaign['channels'] ?? ['email'];
$defaultLanguage = $lead['language'] !== '' ? $lead['language'] : ($languages[0] ?? 'en');
?>
<div class="flex flex-wrap items-start justify-between gap-3 mb-6">
  <div>
    <h1 class="text-2xl font-semibold"><?= e($lead['name'] !== '' ? $lead['name'] : $lead['website']) ?></h1>
    <p class="text-sm text-slate-600 mt-1">
      <?php if ($lead['website'] !== ''): ?><a class="text-indigo-700 hover:underline" href="<?= e($lead['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($lead['domain'] ?? $lead['website']) ?></a> · <?php endif; ?>
      <?= e(Status::label((string) $lead['status'])) ?> · source: <?= e($lead['source']) ?><?= $campaign !== null ? ' · ' : '' ?><?php if ($campaign !== null): ?><a class="text-indigo-700" href="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>"><?= e($campaign['name']) ?></a><?php endif; ?>
    </p>
  </div>
  <div class="text-right">
    <div class="text-3xl font-semibold"><?= $lead['score'] !== null ? e($lead['score']) : '–' ?><span class="text-base text-slate-400">/100</span></div>
    <p class="text-xs text-slate-500 max-w-sm"><?= e($lead['score_reason']) ?></p>
  </div>
</div>

<?php if ($suppressed !== null): ?>
  <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">On the suppression list (<?= e($suppressed) ?>). Emails to this lead will not be sent.</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2 space-y-6">
    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-medium">Research</h2>
        <div class="flex gap-2">
          <form method="post" action="<?= e($base) ?>/leads/<?= e($id) ?>/research"><?= $csrf->field() ?><button class="text-sm bg-indigo-600 text-white rounded px-3 py-1.5" data-busy="Reading the website…"><?= $research === null ? 'Research website' : 'Research again' ?></button></form>
          <?php if ($research !== null): ?><form method="post" action="<?= e($base) ?>/leads/<?= e($id) ?>/score"><?= $csrf->field() ?><button class="text-sm border border-slate-300 rounded px-3 py-1.5">Re-score</button></form><?php endif; ?>
        </div>
      </div>
      <?php if ($research === null): ?>
        <p class="text-sm text-slate-500">Not researched yet. Research reads up to 5 public pages of the business's own website (home, about, contact, services), respecting robots.txt.</p>
      <?php else: ?>
        <?= $view->partial('_research', ['research' => $research]) ?>
      <?php endif; ?>
    </section>

    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <h2 class="font-medium mb-3">Drafts</h2>
      <form method="post" action="<?= e($base) ?>/leads/<?= e($id) ?>/draft" class="flex flex-wrap items-end gap-3 text-sm mb-4">
        <?= $csrf->field() ?>
        <label>Language<select name="language" class="block mt-1 border rounded px-2 py-1.5"><?php foreach (CampaignRepository::LANGUAGES as $code => $label): ?><option value="<?= e($code) ?>" <?= $defaultLanguage === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <fieldset><legend>Channels</legend><?php foreach (CampaignRepository::CHANNELS as $code => $label): ?><label class="mr-2"><input type="checkbox" name="channels[]" value="<?= e($code) ?>" <?= in_array($code, $channels, true) ? 'checked' : '' ?>> <?= e(explode(' ', $label)[0]) ?></label><?php endforeach; ?></fieldset>
        <label class="flex-1 min-w-48">Extra instruction (optional)<input name="instruction" maxlength="300" placeholder="e.g. mention our Urdu-speaking support" class="block mt-1 w-full border rounded px-2 py-1.5"></label>
        <input type="hidden" name="fresh" value="1">
        <button class="bg-indigo-600 text-white rounded px-3 py-1.5 disabled:opacity-50" <?= $aiEnabled ? '' : 'disabled title="AI is not configured"' ?> data-busy="Drafting…">Generate drafts</button>
      </form>
      <?php if ($drafts === []): ?><p class="text-sm text-slate-500">No drafts yet.</p><?php endif; ?>
      <div class="space-y-3">
        <?php foreach ($drafts as $draft): ?>
          <?php if (in_array($draft['status'], ['superseded'], true)) { continue; } ?>
          <?= $view->partial('review/_draft', ['draft' => $draft, 'lead' => $lead, 'error' => null, 'notice' => '']) ?>
        <?php endforeach; ?>
      </div>
      <details class="mt-4 text-sm">
        <summary class="cursor-pointer text-indigo-700">Write a draft by hand</summary>
        <form method="post" action="<?= e($base) ?>/leads/<?= e($id) ?>/manual-draft" class="mt-3 space-y-2">
          <?= $csrf->field() ?>
          <div class="flex gap-3">
            <select name="channel" class="border rounded px-2 py-1.5"><option value="email">Email</option><option value="linkedin">LinkedIn</option><option value="whatsapp">WhatsApp</option></select>
            <select name="language" class="border rounded px-2 py-1.5"><?php foreach (CampaignRepository::LANGUAGES as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
            <label class="flex items-center gap-1"><input type="checkbox" name="followup" value="1"> Follow-up</label>
          </div>
          <input name="subject" placeholder="Subject (email only)" class="w-full border rounded px-3 py-1.5">
          <textarea name="body" rows="5" class="w-full border rounded px-3 py-1.5" placeholder="Email ≤ 120 words, LinkedIn ≤ 300 characters, WhatsApp ≤ 60 words. The email footer (identity, address, unsubscribe) is added automatically."></textarea>
          <button class="border border-slate-300 rounded px-3 py-1.5">Save for review</button>
        </form>
      </details>
    </section>

    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <h2 class="font-medium mb-3">Messages sent</h2>
      <?php if ($messages === []): ?><p class="text-sm text-slate-500">Nothing sent yet.</p><?php endif; ?>
      <?php foreach ($messages as $m): ?>
        <details class="border-t border-slate-100 py-2 text-sm">
          <summary class="cursor-pointer"><span class="font-medium"><?= e(ucfirst((string) $m['channel'])) ?></span> · <?= e($m['status']) ?> · <?= e($m['sent_at'] ?? $m['queued_at']) ?> UTC · <?= e($m['subject']) ?><?= (int) $m['is_followup'] === 1 ? ' (follow-up)' : '' ?></summary>
          <pre class="whitespace-pre-wrap text-xs bg-slate-50 rounded p-3 mt-2"><?= e($m['body']) ?></pre>
          <?php if ((string) $m['error'] !== ''): ?><p class="text-xs text-red-700 mt-1"><?= e($m['error']) ?></p><?php endif; ?>
        </details>
      <?php endforeach; ?>
    </section>
  </div>

  <div class="space-y-6">
    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <h2 class="font-medium mb-3">Pipeline</h2>
      <form method="post" action="<?= e($base) ?>/leads/<?= e($id) ?>/status" class="space-y-2 text-sm">
        <?= $csrf->field() ?>
        <select name="status" class="w-full border rounded px-2 py-1.5"><?php foreach (Status::ALL as $k => $label): ?><option value="<?= e($k) ?>" <?= $lead['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <label class="block">Next action date<input type="date" name="next_action_at" value="<?= e(substr((string) ($lead['next_action_at'] ?? ''), 0, 10)) ?>" class="mt-1 w-full border rounded px-2 py-1.5"></label>
        <button class="border border-slate-300 rounded px-3 py-1.5">Update</button>
      </form>
      <p class="text-xs text-slate-500 mt-2">"Do not contact" adds the email, website domain and phone to the suppression list and withdraws queued messages.</p>
    </section>

    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <h2 class="font-medium mb-3">Details</h2>
      <form method="post" action="<?= e($base) ?>/leads/<?= e($id) ?>" class="space-y-3">
        <?= $csrf->field() ?>
        <?= $view->partial('_lead_fields', ['values' => $lead, 'campaigns' => $campaigns]) ?>
        <label class="block text-sm">Notes<textarea name="notes" rows="4" class="mt-1 w-full border rounded px-3 py-1.5"><?= e($lead['notes']) ?></textarea></label>
        <button class="border border-slate-300 rounded px-3 py-1.5 text-sm">Save</button>
      </form>
      <?php if ($lead['place_id'] !== null): ?><p class="text-xs text-slate-400 mt-2">Google place ID: <?= e($lead['place_id']) ?></p><?php endif; ?>
    </section>

    <section class="bg-white border border-slate-200 rounded-lg p-4">
      <h2 class="font-medium mb-3">Activity</h2>
      <ul class="text-xs space-y-1 text-slate-600">
        <?php foreach ($activity as $a): ?><li><span class="text-slate-400"><?= e(substr((string) $a['created_at'], 0, 16)) ?></span> <?= e($a['detail']) ?></li><?php endforeach; ?>
      </ul>
    </section>

    <form method="post" action="<?= e($base) ?>/leads/<?= e($id) ?>/delete" data-confirm="Delete this lead and its research, drafts and notes? (Suppression entries are kept.)"><?= $csrf->field() ?><button class="text-sm text-red-700 hover:underline">Delete lead</button></form>
  </div>
</div>
