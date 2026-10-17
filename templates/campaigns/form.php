<?php
/** @var array<string, mixed>|null $campaign */
/** @var App\Config\ProductProfile $product */
use App\Leads\CampaignRepository;

$c = $campaign ?? ['name' => '', 'country' => '', 'cities' => [], 'keywords' => [], 'languages' => ['en'], 'channels' => ['email'], 'ideal_customer' => '', 'notes' => ''];
$action = $campaign === null ? '/campaigns' : '/campaigns/' . $campaign['id'];
?>
<h1 class="text-2xl font-semibold mb-6"><?= e($title) ?></h1>
<form method="post" action="<?= e($base . $action) ?>" class="bg-white border border-slate-200 rounded-lg p-6 space-y-4 max-w-3xl">
  <?= $csrf->field() ?>
  <label class="block text-sm font-medium">Name<input name="name" required value="<?= e($c['name']) ?>" placeholder="UK Umrah agencies, spring" class="mt-1 w-full border rounded px-3 py-2 font-normal"></label>
  <div class="grid md:grid-cols-2 gap-4">
    <label class="block text-sm font-medium">Country<input name="country" value="<?= e($c['country']) ?>" placeholder="United Kingdom" class="mt-1 w-full border rounded px-3 py-2 font-normal"></label>
    <label class="block text-sm font-medium">Cities <span class="font-normal text-slate-500">(comma or one per line)</span><textarea name="cities" rows="2" class="mt-1 w-full border rounded px-3 py-2 font-normal" placeholder="London, Birmingham, Manchester"><?= e(implode("\n", $c['cities'])) ?></textarea></label>
  </div>
  <label class="block text-sm font-medium">Business type keywords <span class="font-normal text-slate-500">(used for Google Places searches)</span><textarea name="keywords" rows="2" class="mt-1 w-full border rounded px-3 py-2 font-normal" placeholder="Umrah travel agency, ATOL travel agency"><?= e(implode("\n", $c['keywords'])) ?></textarea></label>
  <label class="block text-sm font-medium">Ideal customer <span class="font-normal text-slate-500">(leave empty to use the product profile)</span><textarea name="ideal_customer" rows="3" class="mt-1 w-full border rounded px-3 py-2 font-normal" placeholder="<?= e($product->idealCustomer) ?>"><?= e($c['ideal_customer']) ?></textarea></label>
  <div class="grid md:grid-cols-2 gap-4">
    <fieldset class="text-sm"><legend class="font-medium mb-1">Draft languages</legend>
      <?php foreach (CampaignRepository::LANGUAGES as $code => $label): ?>
        <label class="mr-4"><input type="checkbox" name="languages[]" value="<?= e($code) ?>" <?= in_array($code, $c['languages'], true) ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
      <p class="text-xs text-slate-500 mt-1">The first one is the default; you can pick per lead.</p>
    </fieldset>
    <fieldset class="text-sm"><legend class="font-medium mb-1">Channels</legend>
      <?php foreach (CampaignRepository::CHANNELS as $code => $label): ?>
        <label class="mr-4"><input type="checkbox" name="channels[]" value="<?= e($code) ?>" <?= in_array($code, $c['channels'], true) ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach; ?>
      <p class="text-xs text-slate-500 mt-1">LinkedIn and WhatsApp are drafts you copy and send yourself.</p>
    </fieldset>
  </div>
  <label class="block text-sm font-medium">Notes<textarea name="notes" rows="2" class="mt-1 w-full border rounded px-3 py-2 font-normal"><?= e($c['notes']) ?></textarea></label>
  <div class="flex gap-3"><button class="bg-indigo-600 text-white rounded px-4 py-2 text-sm hover:bg-indigo-700">Save</button><a href="<?= e($base) ?>/campaigns" class="px-4 py-2 text-sm text-slate-600">Cancel</a></div>
</form>
<?php if ($campaign !== null): ?>
  <form method="post" action="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>/delete" class="mt-6" data-confirm="Delete this campaign? Its leads are kept."><?= $csrf->field() ?><button class="text-sm text-red-700 hover:underline">Delete campaign</button></form>
<?php endif; ?>
