<?php

declare(strict_types=1);

use HalalPulse\Nse\NseSyncRequestRepository;
use HalalPulse\Web\Request;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);

if ($user === null) {
    Response::redirect('/login.php', 302);
}

if (!Request::isPost() || !$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
    http_response_code(400);
    exit('Invalid request.');
}

if ($user->role !== 'admin') {
    http_response_code(403);
    exit('Administrator access is required.');
}

if ($config->get('sources.nse_integrated_rss.enabled', false) !== true) {
    $app->session->flash('warning', 'NSE Integrated Filing sync is not enabled in the private configuration.');
    Response::redirect('/dashboard.php');
}

try {
    $request = (new NseSyncRequestRepository($app->pdo))->request(
        userId: $user->id,
        cooldownSeconds: (int) $config->get('sources.nse_integrated_rss.manual_cooldown_seconds', 300),
    );
    $message = $request['created']
        ? 'NSE sync queued. The next five-minute cron run will process it.'
        : 'An NSE sync is already queued, running, or completed within the cooldown window.';
    $app->session->flash($request['created'] ? 'success' : 'warning', $message);
} catch (Throwable $exception) {
    $app->logger->error('NSE manual sync request failed.', [
        'user_id' => $user->id,
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ]);
    $app->session->flash('error', 'Unable to queue the NSE sync. Confirm migration 009 and cron configuration.');
}

Response::redirect('/dashboard.php');
