<?php
/** @var array<string, mixed> $campaign */
/** @var array{places: list<array<string, string>>, next_page_token: string|null, error: string|null}|null $results */
/** @var bool $configured */
?>
<h1 class="text-2xl font-semibold mb-1">Find businesses on Google Places</h1>
<p class="text-sm text-slate-600 mb-6">Campaign: <a class="text-indigo-700" href="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>"><?= e($campaign['name']) ?></a>. Uses the official Places API (New) Text Search with your own key. Each search is billed by Google beyond the free usage.</p>

<?php if (!$configured): ?>
  <div class="mb-6 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm">Set <code>GOOGLE_PLACES_API_KEY</code> in <code>.env</code> to search. See the README for how to create a restricted key. You can still import a CSV or add leads by hand.</div>
<?php endif; ?>

<form method="post" action="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>/places" class="bg-white border border-slate-200 rounded-lg p-4 flex flex-wrap gap-3 items-end mb-6">
  <?= $csrf->field() ?>
  <label class="text-sm font-medium">Business type<input name="query" value="<?= e($query) ?>" list="keywords" class="mt-1 block border rounded px-3 py-2 font-normal w-72"></label>
  <datalist id="keywords"><?php foreach ($campaign['keywords'] as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
  <label class="text-sm font-medium">City<input name="city" value="<?= e($city) ?>" list="cities" class="mt-1 block border rounded px-3 py-2 font-normal w-56"></label>
  <datalist id="cities"><?php foreach ($campaign['cities'] as $k): ?><option value="<?= e($k) ?>"><?php endforeach; ?></datalist>
  <button class="bg-indigo-600 text-white rounded px-4 py-2 text-sm disabled:opacity-50" <?= $configured ? '' : 'disabled' ?>>Search</button>
</form>

<?php if ($results !== null): ?>
  <?php if ($results['error'] !== null): ?>
    <div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800"><?= e($results['error']) ?></div>
  <?php else: ?>
    <form method="post" action="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>/places/add">
      <?= $csrf->field() ?>
      <input type="hidden" name="city" value="<?= e($city) ?>">
      <div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-left text-slate-500"><tr><th class="px-3 py-2"><input type="checkbox" data-check-all="place_id[]" aria-label="Select all"></th><th class="px-3 py-2">Business</th><th class="px-3 py-2">Website</th><th class="px-3 py-2">Address</th><th class="px-3 py-2">Status</th></tr></thead>
          <tbody>
          <?php foreach ($results['places'] as $p): ?>
            <tr class="border-t border-slate-100">
              <td class="px-3 py-2"><input type="checkbox" name="place_id[]" value="<?= e($p['id']) ?>" <?= $p['website'] === '' || $p['status'] === 'CLOSED_PERMANENTLY' ? 'disabled' : '' ?>><input type="hidden" name="website[<?= e($p['id']) ?>]" value="<?= e($p['website']) ?>"></td>
              <td class="px-3 py-2"><a class="text-indigo-700 hover:underline" href="<?= e($p['maps_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($p['name']) ?></a><div class="text-xs text-slate-500"><?= e($p['type']) ?></div></td>
              <td class="px-3 py-2"><?= $p['website'] !== '' ? e(App\Leads\Normalizer::domain($p['website'])) : '<span class="text-slate-400">no website</span>' ?></td>
              <td class="px-3 py-2 text-slate-600"><?= e($p['address']) ?></td>
              <td class="px-3 py-2 text-xs"><?= e(str_replace('_', ' ', strtolower($p['status']))) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($results['places'] === []): ?><tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">No results.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="text-xs text-slate-500 mt-2">Results are shown from Google Maps and are not saved. Only the Google place ID is stored for leads you add; the lead's name and website are confirmed from the business's own home page. Listings without a website cannot be researched and are disabled.</p>
      <p class="text-xs text-slate-500 mt-1">Powered by Google</p>
      <div class="mt-4 flex gap-3">
        <button class="bg-indigo-600 text-white rounded px-4 py-2 text-sm" data-busy="Adding… each selected website's home page is fetched once.">Add selected as leads</button>
      </div>
    </form>
    <?php if ($results['next_page_token'] !== null): ?>
      <form method="post" action="<?= e($base) ?>/campaigns/<?= e($campaign['id']) ?>/places" class="mt-3">
        <?= $csrf->field() ?><input type="hidden" name="query" value="<?= e($query) ?>"><input type="hidden" name="city" value="<?= e($city) ?>"><input type="hidden" name="page_token" value="<?= e($results['next_page_token']) ?>">
        <button class="text-sm border border-slate-300 bg-white rounded px-3 py-1.5">Next page of results</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
