<?php /** @var string $content */ /** @var string $base */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title ?? 'Outreach Copilot') ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/assets/app.css">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-md bg-white border border-slate-200 rounded-xl shadow-sm p-6"><?= $content ?></div>
</body>
</html>
