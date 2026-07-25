<?php

declare(strict_types=1);

use HalalPulse\Operations\BackupEncryptor;
use HalalPulse\Operations\BackupService;
use HalalPulse\Operations\OperationsReadiness;
use HalalPulse\Web\Page;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);
if ($user === null) {
    Response::redirect('/login.php', 302);
}

$snapshot = $app->operations->snapshot();
$backupStatus = (new BackupService($config, new BackupEncryptor(), HALALPULSE_ROOT))->latestStatus();
$report = (new OperationsReadiness())->assess($config, $snapshot, $backupStatus);
$checksByCategory = [];
foreach ($report['checks'] as $check) {
    $checksByCategory[$check['category']][] = $check;
}

Page::begin(
    'Operations readiness',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'operations',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Production control plane</p>
        <h1>Operations readiness</h1>
        <p class="muted">One fail-closed view of runtime, ingestion, research policies, backups, and alert delivery. No secret values are displayed.</p>
    </div>
    <span class="status status-<?= $report['fully_operational'] ? 'passed' : 'manual_review' ?>"><?= $report['fully_operational'] ? 'Fully operational' : 'Blocked items remain' ?></span>
</div>

<section class="metric-grid operations-gates">
    <?php foreach ($report['gates'] as $gate => $passed): ?>
        <article class="metric-card <?= $passed ? 'metric-accent' : '' ?>">
            <span><?= Page::escape(ucfirst($gate)) ?></span>
            <strong><?= $passed ? 'Ready' : 'Blocked' ?></strong>
            <small><?= $passed ? 'All current gate checks pass' : 'Review the detailed checks below' ?></small>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($report['blockers'] !== []): ?>
    <section class="notice-card notice-error">
        <strong>Blocking items</strong>
        <ul class="reason-list"><?php foreach ($report['blockers'] as $blocker): ?><li><?= Page::escape($blocker) ?></li><?php endforeach; ?></ul>
    </section>
<?php endif; ?>

<?php if ($report['warnings'] !== []): ?>
    <section class="notice-card">
        <strong>Operational warnings</strong>
        <ul class="reason-list"><?php foreach ($report['warnings'] as $warning): ?><li><?= Page::escape($warning) ?></li><?php endforeach; ?></ul>
    </section>
<?php endif; ?>

<section class="panel panel-results">
    <div class="panel-heading">
        <div><p class="eyebrow">Safe activation path</p><h2>Exact next commands</h2></div>
        <span class="status">Run privately over SSH</span>
    </div>
    <div class="readiness-check-list">
        <article class="readiness-check">
            <span class="status status-manual_review">Screening</span>
            <div><strong>Prepare the ignored AAOIFI policy working file</strong><p><span class="mono">/opt/alt/php83/usr/bin/php cron/prepare-sharia-policy.php</span><br>Complete it only from the exact official/licensed edition, run the readiness checker, obtain competent approval, and install only after a <span class="mono">[READY]</span> result.</p></div>
        </article>
        <article class="readiness-check">
            <span class="status status-manual_review">Ranking</span>
            <div><strong>Prepare the ignored methodology working file</strong><p><span class="mono">/opt/alt/php83/usr/bin/php cron/prepare-multibagger-methodology.php</span><br>Review every factor, grade anchor, evidence requirement, valuation rule, market-cap band, and microcap adjustment before activation.</p></div>
        </article>
        <article class="readiness-check">
            <span class="status status-manual_review">Backups</span>
            <div><strong>Enable, create, and authenticate the first encrypted backup</strong><p><span class="mono">/opt/alt/php83/usr/bin/php cron/configure-backups.php</span><br>The passphrase is entered without echo and is written only to ignored private configuration.</p></div>
        </article>
        <article class="readiness-check">
            <span class="status status-manual_review">Alerts</span>
            <div><strong>Prepare Telegram only after a permanent HTTPS domain is active</strong><p><span class="mono">/opt/alt/php83/usr/bin/php cron/configure-telegram.php</span><br>The command refuses the temporary Hostinger domain and leaves automatic delivery disabled until recipient consent and a manual test are complete.</p></div>
        </article>
    </div>
</section>

<?php foreach ($checksByCategory as $category => $checks): ?>
    <section class="panel panel-results">
        <div class="panel-heading"><div><p class="eyebrow">Gate detail</p><h2><?= Page::escape(ucfirst($category)) ?></h2></div><span class="status"><?= Page::escape(count($checks)) ?> checks</span></div>
        <div class="readiness-check-list">
            <?php foreach ($checks as $check): ?>
                <article class="readiness-check">
                    <span class="status status-<?= $check['status'] === 'passed' ? 'accepted' : ($check['status'] === 'warning' ? 'manual_review' : 'rejected') ?>"><?= Page::escape(ucfirst($check['status'])) ?></span>
                    <div><strong><?= Page::escape($check['label']) ?></strong><p><?= Page::escape($check['detail']) ?></p></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<section class="review-grid operations-summary">
    <article class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Research inventory</p><h2>Current evidence state</h2></div></div>
        <dl class="detail-list">
            <div><dt>Activity-reviewed companies</dt><dd><?= Page::escape($snapshot['activity_reviewed_companies']) ?></dd></div>
            <div><dt>Pending XBRL candidates</dt><dd><?= Page::escape($snapshot['pending_sharia_candidates']) ?></dd></div>
            <div><dt>Recorded Sharia passes</dt><dd><?= Page::escape($snapshot['sharia_passes']) ?></dd></div>
            <div><dt>Completed potential scores</dt><dd><?= Page::escape($snapshot['completed_scores']) ?></dd></div>
        </dl>
    </article>
    <article class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Continuity</p><h2>Latest encrypted backup</h2></div></div>
        <?php if ($backupStatus === null): ?>
            <div class="empty-state compact"><p>No successful backup status has been recorded.</p></div>
        <?php else: ?>
            <dl class="detail-list">
                <div><dt>File</dt><dd class="mono"><?= Page::escape($backupStatus['filename'] ?? '') ?></dd></div>
                <div><dt>Created</dt><dd><?= Page::escape($backupStatus['created_at'] ?? '') ?></dd></div>
                <div><dt>Bytes</dt><dd><?= Page::escape($backupStatus['bytes'] ?? 0) ?></dd></div>
                <div><dt>Commit</dt><dd class="mono"><?= Page::escape(substr((string) ($backupStatus['commit'] ?? 'unknown'), 0, 12)) ?></dd></div>
                <div><dt>SHA-256</dt><dd class="mono hash-value"><?= Page::escape($backupStatus['encrypted_sha256'] ?? '') ?></dd></div>
            </dl>
        <?php endif; ?>
    </article>
</section>

<section class="notice-card"><strong>Command-line equivalent</strong><p>Run <span class="mono">php cron/release-readiness.php</span> for the same fail-closed report. Run <span class="mono">php cron/check-backups.php --decrypt</span> for authenticated backup verification.</p></section>
<?php Page::end(); ?>
