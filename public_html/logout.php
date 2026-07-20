<?php

declare(strict_types=1);

use HalalPulse\Web\Request;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);

if (!Request::isPost()) {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

if (!$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
    http_response_code(400);
    exit('Invalid request token.');
}

if ($user !== null) {
    $app->logger->info('User signed out.', ['user_id' => $user->id]);
}

$app->session->logout();
Response::redirect('/login.php');
