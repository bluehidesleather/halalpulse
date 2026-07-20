<?php

declare(strict_types=1);

use HalalPulse\Web\Page;
use HalalPulse\Web\Request;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);

if ($user !== null) {
    Response::redirect('/dashboard.php', 302);
}

$error = null;
$email = '';

if (Request::isPost()) {
    $email = Request::postString('email');
    $password = Request::postString('password', false);
    $csrf = Request::postString('csrf_token', false);

    if (!$app->session->verifyCsrf($csrf)) {
        http_response_code(400);
        $error = 'The form expired. Refresh the page and try again.';
    } elseif (mb_strlen($email) > 191 || strlen($password) > 4096) {
        $error = 'Email or password is incorrect.';
    } else {
        $result = $app->authentication->attempt($email, $password, Request::clientIp());

        if ($result['status'] === 'success' && $result['user'] !== null) {
            $app->session->login($result['user']);
            Response::redirect('/dashboard.php');
        }

        usleep(250_000);
        $error = $result['status'] === 'throttled'
            ? 'Too many unsuccessful attempts. Wait 15 minutes before trying again.'
            : 'Email or password is incorrect.';
    }
}

Page::begin('Sign in', (string) $config->get('app.name', 'HalalPulse'), null, bodyClass: 'login-body');
?>
<section class="login-shell">
    <div class="login-intro">
        <span class="brand-mark brand-mark-large">HP</span>
        <p class="eyebrow">Private research workspace</p>
        <h1>Evidence first.<br>Noise last.</h1>
        <p>Monitor official exchange filings, preserve the audit trail, and review long-term research signals in one private dashboard.</p>
        <ul class="login-points">
            <li>Official NSE and BSE disclosures</li>
            <li>Restart-safe hourly ingestion</li>
            <li>Versioned Sharia and long-term potential scoring</li>
        </ul>
    </div>
    <div class="login-card">
        <p class="eyebrow">HalalPulse access</p>
        <h2>Welcome back</h2>
        <p class="muted">Sign in with the administrator account created from the secure command line.</p>

        <?php Page::flash($app->session->consumeFlash()); ?>
        <?php if ($error !== null): ?>
            <div class="flash flash-error" role="alert"><?= Page::escape($error) ?></div>
        <?php endif; ?>

        <form class="stacked-form" method="post" action="/login.php" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="<?= Page::escape($email) ?>" maxlength="191" autocomplete="username" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" maxlength="128" autocomplete="current-password" required>

            <button class="button button-primary button-full" type="submit">Sign in securely</button>
        </form>
        <p class="form-footnote">Personal-use system · Sessions expire automatically after inactivity.</p>
    </div>
</section>
<?php Page::end(); ?>
