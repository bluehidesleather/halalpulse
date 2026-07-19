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

$documentId = Request::queryInt('id', 0);
$document = $documentId > 0 ? $app->documents->downloadable($documentId) : null;

if ($document === null) {
    http_response_code(404);
    exit('Document not found.');
}

$relativePath = $document['storage_path'];
$storageRoot = $config->requireString('documents.storage_path');

if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
    http_response_code(404);
    exit('Document not found.');
}

$root = realpath($storageRoot);
$path = realpath(rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath);

if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Document not found.');
}

$sha256 = hash_file('sha256', $path);
if (!is_string($sha256) || !hash_equals($document['sha256'], $sha256)) {
    $app->logger->error('Private document integrity check failed.', ['document_id' => $documentId]);
    http_response_code(409);
    exit('Document integrity check failed.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="halalpulse-document-' . $documentId . '.pdf"');
header('Content-Length: ' . (int) filesize($path));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-Robots-Tag: noindex, nofollow, noarchive');
readfile($path);
exit;
