<?php

declare(strict_types=1);

use HalalPulse\Web\Page;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);

if ($user === null) {
    Response::redirect('/login.php', 302);
}

$summary = $app->documents->summary();
$documents = $app->documents->recent(50);
$formatBytes = static function (mixed $bytes): string {
    $bytes = (int) $bytes;
    if ($bytes < 1) {
        return '—';
    }

    return $bytes >= 1_048_576
        ? number_format($bytes / 1_048_576, 1) . ' MB'
        : number_format($bytes / 1024, 1) . ' KB';
};

Page::begin(
    'Documents',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'documents',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div><p class="eyebrow">Evidence operations</p><h1>Documents</h1><p class="muted">Private PDF acquisition, text-extraction status, and human metric review.</p></div>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<section class="metric-grid document-metrics" aria-label="Document pipeline summary">
    <article class="metric-card"><span>Total documents</span><strong><?= Page::escape($summary['total']) ?></strong><small>Quarterly-result candidates</small></article>
    <article class="metric-card"><span>Pending downloads</span><strong><?= Page::escape($summary['pending_download']) ?></strong><small>Small restart-safe queue</small></article>
    <article class="metric-card metric-accent"><span>Extracted</span><strong><?= Page::escape($summary['extracted']) ?></strong><small>Text ready for review</small></article>
    <article class="metric-card"><span>Metrics to review</span><strong><?= Page::escape($summary['pending_metrics']) ?></strong><small>Never scored automatically</small></article>
</section>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Latest work</p><h2>Document queue</h2></div><span class="status"><?= Page::escape($summary['manual_review']) ?> manual · <?= Page::escape($summary['failed']) ?> failed</span></div>
    <?php if ($documents === []): ?>
        <div class="empty-state"><h3>No documents queued</h3><p>Candidate filings with official attachments will appear after the hourly filing poll and document command run.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Company</th><th>Filing</th><th>Download</th><th>Extraction</th><th>Metrics</th><th>Updated</th></tr></thead>
                <tbody>
                <?php foreach ($documents as $document): ?>
                    <tr>
                        <td><span class="exchange-badge"><?= Page::escape($document['exchange']) ?></span><a class="table-title" href="/filing.php?id=<?= Page::escape($document['filing_id']) ?>"><strong><?= Page::escape($document['symbol']) ?></strong></a><small><?= Page::escape($document['company_name']) ?></small></td>
                        <td><a class="subject-link" href="/filing.php?id=<?= Page::escape($document['filing_id']) ?>"><?= Page::escape($document['subject']) ?></a><small><?= Page::escape($document['announced_at']) ?></small></td>
                        <td><span class="status status-<?= Page::escape($document['download_status']) ?>"><?= Page::escape(ucfirst((string) $document['download_status'])) ?></span><small><?= Page::escape($formatBytes($document['file_size_bytes'])) ?> · <?= Page::escape($document['download_attempts']) ?> attempts</small></td>
                        <td><span class="status status-<?= Page::escape($document['extraction_status']) ?>"><?= Page::escape(str_replace('_', ' ', ucfirst((string) $document['extraction_status']))) ?></span><?php if ($document['last_error'] !== null): ?><small class="error-note"><?= Page::escape($document['last_error']) ?></small><?php endif; ?></td>
                        <td><strong><?= Page::escape($document['metric_count']) ?></strong><small><?= Page::escape($document['pending_metric_count']) ?> pending</small></td>
                        <td class="nowrap"><?= Page::escape($document['downloaded_at'] ?? 'Queued') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php Page::end(); ?>
