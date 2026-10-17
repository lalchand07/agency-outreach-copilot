<?php /** @var bool $found */ /** @var bool $done */ /** @var string $token */ /** @var string $company */ ?>
<?php if (!$found): ?>
  <h1 class="text-xl font-semibold mb-2">Link not recognised</h1>
  <p class="text-slate-600">This unsubscribe link is not valid. Reply to the email with "unsubscribe" and we will remove you by hand.</p>
<?php elseif ($done): ?>
  <h1 class="text-xl font-semibold mb-2">You are unsubscribed</h1>
  <p class="text-slate-600"><?= e($company !== '' ? $company : 'We') ?> will not contact this address again. Sorry for the interruption.</p>
<?php else: ?>
  <h1 class="text-xl font-semibold mb-2">Unsubscribe</h1>
  <p class="text-slate-600 mb-4">Confirm and <?= e($company !== '' ? $company : 'we') ?> will not contact you again.</p>
  <form method="post" action="<?= e($base) ?>/u/<?= e($token) ?>">
    <button class="w-full bg-slate-800 text-white rounded py-2 font-medium hover:bg-slate-900">Unsubscribe me</button>
  </form>
<?php endif; ?>
