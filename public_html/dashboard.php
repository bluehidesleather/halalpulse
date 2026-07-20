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

$summary = $app->dashboard->summary();
$latest = $app->dashboard->latestFilings(10);
$sources = $app->dashboard->sourceStatuses();
$nseIntegrated = $app->dashboard->nseIntegratedStatus();
$nseIntegratedEnabled = $config->get('sources.nse_integrated_rss.enabled', false) === true;

Page::begin(
    'Overview',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'dashboard',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Private intelligence dashboard</p>
        <h1>Good to see you, <?= Page::escape($user->displayName) ?>.</h1>
        <p class="muted">A quiet view of what the official filing pipeline has actually observed.</p>
    </div>
    <a class="button button-secondary" href="/filings.php">Review all filings</a>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<section class="metric-grid" aria-label="Pipeline summary">
    <article class="metric-card"><span>Tracked companies</span><strong><?= Page::escape($summary['companies']) ?></strong><small>Seen in official filings</small></article>
    <article class="metric-card"><span>Total filings</span><strong><?= Page::escape($summary['filings']) ?></strong><small>Duplicate-safe records</small></article>
    <article class="metric-card metric-accent"><span>Result candidates</span><strong><?= Page::escape($summary['candidates']) ?></strong><small>Awaiting evidence review</small></article>
    <article class="metric-card"><span>Pending pipeline</span><strong><?= Page::escape($summary['pending']) ?></strong><small>Detected or queued</small></article>
</section>

<section class="panel sync-panel" aria-labelledby="nse-sync-title">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">Official structured source</p>
            <h2 id="nse-sync-title">NSE Integrated Filing Financials</h2>
        </div>
        <?php
        $nseState = !$nseIntegrated['available']
            ? 'Migration required'
            : ($nseIntegrated['last_run_status'] === null ? 'Not run' : ucfirst($nseIntegrated['last_run_status']));
        ?>
        <span class="status status-<?= Page::escape($nseIntegrated['last_run_status'] ?? 'pending') ?>"><?= Page::escape($nseState) ?></span>
    </div>
    <div class="sync-grid">
        <div><span>Archived XBRL</span><strong><?= Page::escape($nseIntegrated['processed']) ?></strong></div>
        <div><span>Pending</span><strong><?= Page::escape($nseIntegrated['pending']) ?></strong></div>
        <div><span>Retrying</span><strong><?= Page::escape($nseIntegrated['failed']) ?></strong></div>
        <div><span>Feed build</span><strong class="sync-time"><?= Page::escape($nseIntegrated['feed_last_build_at'] ?? 'Never') ?></strong></div>
    </div>
    <div class="sync-actions">
        <p>
            Automatic sync runs every five minutes. The button safely queues the same duplicate-proof pipeline;
            it does not perform a long download inside the browser request.
        </p>
        <?php if ($user->role === 'admin'): ?>
            <form method="post" action="/sync-nse.php">
                <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
                <button class="button button-primary" type="submit" <?= (!$nseIntegratedEnabled || !$nseIntegrated['available']) ? 'disabled' : '' ?>>Sync data now</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (!$nseIntegratedEnabled): ?>
        <small class="sync-note">Disabled until migration 009, private archive storage, and the five-minute Hostinger cron are configured.</small>
    <?php elseif ($nseIntegrated['manual_request_status'] !== null): ?>
        <small class="sync-note">Latest manual request: <?= Page::escape($nseIntegrated['manual_request_status']) ?> · Last run: <?= Page::escape($nseIntegrated['last_run_at'] ?? 'Never') ?></small>
    <?php endif; ?>
</section>

<section class="dashboard-grid">
    <div class="panel panel-wide">
        <div class="panel-heading">
            <div><p class="eyebrow">Latest evidence</p><h2>Recent filings</h2></div>
            <a href="/filings.php">View all</a>
        </div>
        <?php if ($latest === []): ?>
            <div class="empty-state"><h3>No filings stored yet</h3><p>Run the source probes and enable the verified hourly pollers to begin collecting disclosures.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Company</th><th>Announcement</th><th>Time</th><th>Signal</th></tr></thead>
                    <tbody>
                    <?php foreach ($latest as $filing): ?>
                        <tr>
                            <td><span class="exchange-badge"><?= Page::escape($filing['exchange']) ?></span><a class="table-title" href="/filing.php?id=<?= Page::escape($filing['id']) ?>"><strong><?= Page::escape($filing['symbol']) ?></strong></a><small><?= Page::escape($filing['company_name']) ?></small></td>
                            <td><a class="subject-link" href="/filing.php?id=<?= Page::escape($filing['id']) ?>"><?= Page::escape($filing['subject']) ?></a></td>
                            <td class="nowrap"><?= Page::escape($filing['announced_at']) ?></td>
                            <td>
                                <?php if ((int) $filing['is_quarterly_result_candidate'] === 1): ?>
                                    <span class="status status-candidate">Candidate <?= Page::escape($filing['classifier_confidence']) ?>%</span>
                                <?php else: ?>
                                    <span class="status">Observed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <aside class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Operations</p><h2>Source health</h2></div></div>
        <div class="source-list">
            <?php foreach ($sources as $source): ?>
                <?php
                $healthy = ($source['last_run_status'] ?? null) === 'succeeded' && (int) $source['consecutive_failures'] === 0;
                $state = $source['last_run_status'] === null ? 'Not run' : ($healthy ? 'Healthy' : ucfirst((string) $source['last_run_status']));
                ?>
                <article class="source-card">
                    <div><span class="source-dot <?= $healthy ? 'source-dot-ok' : '' ?>"></span><strong><?= Page::escape($source['exchange']) ?></strong></div>
                    <span class="status"><?= Page::escape($state) ?></span>
                    <small>Last poll: <?= Page::escape($source['last_successful_poll_at'] ?? 'Never') ?></small>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="notice-card">
            <strong>Analysis gate</strong>
            <p>Sharia screening and potential scoring each require an active reviewed methodology and complete evidence. Potential scoring also requires a same-period Sharia pass.</p>
        </div>
    </aside>
</section>
<?php Page::end(); ?>
