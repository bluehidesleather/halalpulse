<?php

declare(strict_types=1);

use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);

Response::redirect($user === null ? '/login.php' : '/dashboard.php', 302);
