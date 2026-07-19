<?php

declare(strict_types=1);

use HalalPulse\Web\Request;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);

if ($user === null) {
    Response::redirect('/login.php', 302);
}

if (!Request::isPost()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

if (!$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
    http_response_code(400);
    exit('Invalid request token.');
}

$candidateId = (int) Request::postString('candidate_id');
$action = Request::postString('action');

if (!in_array($action, ['accepted', 'rejected'], true)) {
    http_response_code(400);
    exit('Invalid review action.');
}

$filingId = $candidateId > 0 ? $app->documents->reviewMetric($candidateId, $action, $user->id) : null;

if ($filingId === null) {
    http_response_code(404);
    exit('Metric candidate not found.');
}

$app->logger->info('Metric candidate reviewed.', [
    'candidate_id' => $candidateId,
    'review_status' => $action,
    'user_id' => $user->id,
]);
$app->session->rotateCsrfToken();
$app->session->flash('success', 'Metric candidate marked ' . $action . '.');
Response::redirect('/filing.php?id=' . $filingId);
