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
