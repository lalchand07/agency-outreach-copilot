<?php /** @var bool $configured */ /** @var string $error */ /** @var App\Http\Csrf $csrf */ ?>
<h1 class="text-xl font-semibold mb-4">Sign in</h1>
<?php if ($error !== ''): ?><p class="mb-4 text-sm text-red-700 bg-red-50 rounded px-3 py-2"><?= e($error) ?></p><?php endif; ?>
<?php if (!$configured): ?>
  <div class="mb-4 text-sm bg-amber-50 border border-amber-200 rounded p-3">
    <p class="font-medium">Set an admin password first</p>
    <p class="mt-1">Run <code class="bg-white px-1">php bin/console hash-password</code>, then put the result in <code class="bg-white px-1">ADMIN_PASSWORD_HASH</code> in your <code>.env</code> file (keep the single quotes).</p>
  </div>
<?php endif; ?>
<form method="post" action="<?= e($base) ?>/login" class="space-y-3">
  <?= $csrf->field() ?>
  <label class="block text-sm">Username<input name="username" autocomplete="username" required class="mt-1 w-full border rounded px-3 py-2"></label>
  <label class="block text-sm">Password<input type="password" name="password" autocomplete="current-password" required class="mt-1 w-full border rounded px-3 py-2"></label>
  <button class="w-full bg-indigo-600 text-white rounded py-2 font-medium hover:bg-indigo-700">Sign in</button>
</form>
