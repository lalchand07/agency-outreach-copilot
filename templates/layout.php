<?php
/** @var string $content */
/** @var App\Http\Csrf $csrf */
/** @var string $base */
$title = $title ?? 'Outreach Copilot';
$nav = [
    '/' => 'Dashboard',
    '/campaigns' => 'Campaigns',
    '/leads' => 'Leads',
    '/review' => 'Review',
    '/outbox' => 'Outbox',
    '/suppression' => 'Suppression',
    '/settings' => 'Settings',
];
$current = '/' . explode('/', trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/'))[0];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="htmx-config" content='{"allowEval":false,"includeIndicatorStyles":false}'>
<title><?= e($title) ?> · Outreach Copilot</title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<script src="<?= e($base) ?>/assets/htmx-1.9.12.min.js"></script>
<script src="<?= e($base) ?>/assets/app.js" defer></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen" hx-headers='{"X-CSRF-Token": "<?= e($csrf->token()) ?>"}'>
<header class="bg-white border-b border-slate-200">
  <div class="max-w-7xl mx-auto px-4 flex items-center gap-6 h-14">
    <a href="<?= e($base) ?>/" class="font-semibold text-indigo-700 whitespace-nowrap">Outreach Copilot</a>
    <nav class="flex gap-1 overflow-x-auto text-sm">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= e($base . $href) ?>" class="px-3 py-1.5 rounded-md <?= $current === $href ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-600 hover:bg-slate-100' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <form method="post" action="<?= e($base) ?>/logout" class="ml-auto"><?= $csrf->field() ?><button class="text-sm text-slate-500 hover:text-slate-800">Log out</button></form>
  </div>
</header>
<main class="max-w-7xl mx-auto px-4 py-6">
  <?php foreach ($flashes ?? [] as $flash): ?>
    <div class="mb-4 rounded-md px-4 py-3 text-sm <?= $flash['type'] === 'error' ? 'bg-red-50 text-red-800 border border-red-200' : ($flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-sky-50 text-sky-800 border border-sky-200') ?>"><?= e($flash['message']) ?></div>
  <?php endforeach; ?>
  <?php if (!empty($report) && (($report['duplicates'] ?? []) !== [] || ($report['skipped'] ?? []) !== [])): ?>
    <details class="mb-4 rounded-md bg-white border border-slate-200 px-4 py-3 text-sm" open>
      <summary class="font-medium cursor-pointer"><?= e($report['title'] ?? 'Report') ?>: details</summary>
      <ul class="mt-2 list-disc pl-5 space-y-1 text-slate-600">
        <?php foreach (array_merge($report['duplicates'] ?? [], $report['skipped'] ?? []) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
  <?= $content ?>
</main>
<footer class="max-w-7xl mx-auto px-4 py-8 text-xs text-slate-400">
  Nothing is sent without your approval. You are responsible for complying with the outreach laws that apply to you (e.g. UK PECR/GDPR, EU ePrivacy, UAE).
</footer>
</body>
</html>
