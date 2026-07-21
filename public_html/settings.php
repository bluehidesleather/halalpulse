<?php

declare(strict_types=1);

use HalalPulse\Auth\PasswordPolicy;
use HalalPulse\Web\Page;
use HalalPulse\Web\Request;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);

if ($user === null) {
    Response::redirect('/login.php', 302);
}

$errors = [];

if (Request::isPost()) {
    $csrf = Request::postString('csrf_token', false);
    $currentPassword = Request::postString('current_password', false);
    $newPassword = Request::postString('new_password', false);
    $confirmation = Request::postString('new_password_confirmation', false);

    if (!$app->session->verifyCsrf($csrf)) {
        http_response_code(400);
        $errors[] = 'The form expired. Refresh the page and try again.';
    }

    $policy = new PasswordPolicy();
    $errors = array_merge($errors, $policy->violations($newPassword));

    if (!hash_equals($newPassword, $confirmation)) {
        $errors[] = 'New password confirmation does not match.';
    }

    if ($errors === [] && !$app->authentication->changePassword($user, $currentPassword, $newPassword)) {
        $errors[] = 'Current password is incorrect.';
    }

    if ($errors === []) {
        $updatedUser = $app->users->findActiveById($user->id);
        if ($updatedUser === null) {
            throw new RuntimeException('The administrator account became unavailable after its credential changed.');
        }
        $app->session->login($updatedUser);
        $app->session->flash('success', 'Your password was changed and all other sessions were signed out.');
        Response::redirect('/settings.php');
    }
}

Page::begin(
    'Security',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'settings',
    $app->session->csrfToken(),
);
?>
<div class="page-heading"><div><p class="eyebrow">Account protection</p><h1>Security</h1><p class="muted">Manage the only web credential that unlocks this private installation.</p></div></div>

<?php Page::flash($app->session->consumeFlash()); ?>

<div class="settings-grid">
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Administrator</p><h2>Account details</h2></div></div>
        <dl class="detail-list"><div><dt>Name</dt><dd><?= Page::escape($user->displayName) ?></dd></div><div><dt>Email</dt><dd><?= Page::escape($user->email) ?></dd></div><div><dt>Role</dt><dd><?= Page::escape(ucfirst($user->role)) ?></dd></div></dl>
    </section>

    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Credential</p><h2>Change password</h2></div></div>
        <?php if ($errors !== []): ?><div class="flash flash-error" role="alert"><strong>Password was not changed.</strong><ul><?php foreach ($errors as $error): ?><li><?= Page::escape($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form class="stacked-form" method="post" action="/settings.php">
            <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
            <label for="current_password">Current password</label>
            <input id="current_password" name="current_password" type="password" maxlength="128" autocomplete="current-password" required>
            <label for="new_password">New password</label>
            <input id="new_password" name="new_password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required>
            <small>Use at least 12 characters. A unique passphrase is recommended.</small>
            <label for="new_password_confirmation">Confirm new password</label>
            <input id="new_password_confirmation" name="new_password_confirmation" type="password" minlength="12" maxlength="128" autocomplete="new-password" required>
            <button class="button button-primary" type="submit">Update password</button>
        </form>
    </section>
</div>
<?php Page::end(); ?>
