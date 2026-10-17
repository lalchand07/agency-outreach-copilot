<?php
/** @var array<string, mixed> $values */
/** @var list<array<string, mixed>> $campaigns */
use App\Leads\CampaignRepository;

$v = static fn (string $k): string => (string) ($values[$k] ?? '');
?>
<div class="grid md:grid-cols-2 gap-3 text-sm">
  <label class="block">Business name<input name="name" value="<?= e($v('name')) ?>" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">Website<input name="website" value="<?= e($v('website')) ?>" placeholder="https://example.co.uk" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">Email<input name="email" type="email" value="<?= e($v('email')) ?>" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">Phone / WhatsApp<input name="phone" value="<?= e($v('phone')) ?>" placeholder="+44 20 7946 0958" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">Contact person<input name="contact_name" value="<?= e($v('contact_name')) ?>" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">LinkedIn URL<input name="linkedin_url" value="<?= e($v('linkedin_url')) ?>" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">City<input name="city" value="<?= e($v('city')) ?>" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">Country<input name="country" value="<?= e($v('country')) ?>" class="mt-1 w-full border rounded px-3 py-1.5"></label>
  <label class="block">Preferred language<select name="language" class="mt-1 w-full border rounded px-2 py-1.5"><option value="">Campaign default</option><?php foreach (CampaignRepository::LANGUAGES as $code => $label): ?><option value="<?= e($code) ?>" <?= $v('language') === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label class="block">Campaign<select name="campaign_id" class="mt-1 w-full border rounded px-2 py-1.5"><option value="">None</option><?php foreach ($campaigns as $c): ?><option value="<?= e($c['id']) ?>" <?= (int) $v('campaign_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
</div>
