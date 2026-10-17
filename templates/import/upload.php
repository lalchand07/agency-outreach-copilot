<?php /** @var list<array<string, mixed>> $campaigns */ ?>
<h1 class="text-2xl font-semibold mb-2">Import leads from CSV</h1>
<p class="text-sm text-slate-600 mb-6">For exhibitor lists, association directories or your own spreadsheets. Only import data you have the right to use for outreach. Next you choose which column is which.</p>
<?php if ($error !== ''): ?><div class="mb-4 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e($base) ?>/import" enctype="multipart/form-data" class="bg-white border border-slate-200 rounded-lg p-6 max-w-xl space-y-4 text-sm">
  <?= $csrf->field() ?>
  <input type="hidden" name="MAX_FILE_SIZE" value="<?= e(App\Leads\CsvImporter::MAX_BYTES) ?>">
  <label class="block">CSV file (max 5 MB, 5,000 rows)<input type="file" name="csv" accept=".csv,text/csv" required class="block mt-1"></label>
  <label class="block">Campaign<select name="campaign_id" class="block mt-1 w-full border rounded px-2 py-1.5"><option value="">None</option><?php foreach ($campaigns as $c): ?><option value="<?= e($c['id']) ?>" <?= $campaignId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
  <button class="bg-indigo-600 text-white rounded px-4 py-2">Upload</button>
</form>
