<?php

declare(strict_types=1);

use HalalPulse\Web\Page;
use HalalPulse\Web\Request;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);
if ($user === null) {
    Response::redirect('/login.php', 302);
}

if (Request::isPost()) {
    if (!$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
        http_response_code(400);
        exit('Invalid request token.');
    }
    try {
        $announcementId = (int) Request::postString('announcement_id');
        $impact = Request::postString('impact');
        $sector = Request::postString('sector');
        $rationale = Request::postString('rationale');
        $reviewId = $app->government->saveReview($announcementId, $sector, $impact, $rationale, $user->id);
        $app->logger->info('Government announcement review recorded.', [
            'announcement_id' => $announcementId,
            'government_review_id' => $reviewId,
            'impact' => $impact,
            'user_id' => $user->id,
        ]);
        $app->session->flash('success', 'Government evidence review saved as a new audit record.');
    } catch (InvalidArgumentException $exception) {
        $app->session->flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        $app->logger->error('Government announcement review failed.', ['exception' => $exception::class, 'user_id' => $user->id]);
        $app->session->flash('error', 'The review could not be saved. Run the health check and inspect the private application log.');
    }
    Response::redirect('/government.php', 303);
}

$status = Request::queryString('status');
$status = in_array($status, ['pending', 'reviewed', 'tailwind', 'all'], true) ? $status : 'pending';
$source = strtoupper(Request::queryString('source'));
$source = in_array($source, ['PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET'], true) ? $source : '';
$summary = $app->government->summary();
$announcements = $app->government->announcements($status, $source, 100);
$health = $app->government->sourceHealth();

Page::begin('Government tailwinds', (string) $config->get('app.name', 'HalalPulse'), $user, 'government', $app->session->csrfToken());
?>
<div class="page-heading">
    <div><p class="eyebrow">Official evidence only</p><h1>Government tailwinds</h1><p class="muted">PIB, SEBI, RBI, MCA, and Union Budget announcements. Machine tags are suggestions; only your reviewed records can feed a score.</p></div>
</div>
<?php Page::flash($app->session->consumeFlash()); ?>

<section class="metric-grid" aria-label="Government evidence summary">
    <article class="metric-card"><span>Announcements</span><strong><?= Page::escape($summary['announcements']) ?></strong><small>Immutable official-source records</small></article>
    <article class="metric-card"><span>Awaiting review</span><strong><?= Page::escape($summary['pending']) ?></strong><small>No current human decision</small></article>
    <article class="metric-card metric-accent"><span>Approved tailwinds</span><strong><?= Page::escape($summary['tailwinds']) ?></strong><small>Available as macro evidence</small></article>
    <article class="metric-card"><span>Source failures</span><strong><?= Page::escape($summary['source_failures']) ?></strong><small>Adapters remain isolated</small></article>
</section>

<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Source health</p><h2>Hourly contracts</h2></div><span class="status">Disabled until probed</span></div>
    <div class="source-list government-source-grid">
        <?php foreach ($health as $sourceHealth): $ok = (int) $sourceHealth['consecutive_failures'] === 0 && $sourceHealth['last_successful_poll_at'] !== null; ?>
            <article class="source-card"><strong><span class="source-dot <?= $ok ? 'source-dot-ok' : '' ?>"></span><?= Page::escape($sourceHealth['source']) ?></strong><span class="status <?= (int) $sourceHealth['consecutive_failures'] > 0 ? 'status-failed' : '' ?>"><?= Page::escape((int) $sourceHealth['consecutive_failures'] > 0 ? $sourceHealth['consecutive_failures'] . ' failure(s)' : ($ok ? 'Healthy' : 'Not polled')) ?></span><small>Last success: <?= Page::escape($sourceHealth['last_successful_poll_at'] ?? '—') ?></small></article>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Human approval gate</p><h2>Announcement review</h2></div><span class="status"><?= Page::escape(count($announcements)) ?> shown</span></div>
    <form class="filter-bar government-filter" method="get">
        <div><label for="status">Review state</label><select id="status" name="status"><option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Awaiting review</option><option value="reviewed" <?= $status === 'reviewed' ? 'selected' : '' ?>>Reviewed</option><option value="tailwind" <?= $status === 'tailwind' ? 'selected' : '' ?>>Approved tailwinds</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option></select></div>
        <div><label for="source">Official source</label><select id="source" name="source"><option value="">All five sources</option><?php foreach (['PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET'] as $sourceOption): ?><option value="<?= Page::escape($sourceOption) ?>" <?= $source === $sourceOption ? 'selected' : '' ?>><?= Page::escape($sourceOption) ?></option><?php endforeach; ?></select></div>
        <button class="button button-secondary" type="submit">Apply</button>
    </form>

    <?php if ($announcements === []): ?>
        <div class="empty-state"><h3>No announcements in this view</h3><p>Run the verified government poll or choose a different review state.</p></div>
    <?php else: ?>
        <div class="tailwind-list">
        <?php foreach ($announcements as $announcement): ?>
            <article class="tailwind-card">
                <div class="tailwind-card-main">
                    <div class="tailwind-meta"><span class="exchange-badge"><?= Page::escape($announcement['source']) ?></span><span><?= Page::escape($announcement['published_at']) ?></span><span><?= Page::escape($announcement['category']) ?></span></div>
                    <h3><a class="subject-link" href="<?= Page::escape($announcement['official_url']) ?>" target="_blank" rel="noopener noreferrer"><?= Page::escape($announcement['title']) ?></a></h3>
                    <?php if ((string) $announcement['summary'] !== ''): ?><p class="muted tailwind-summary"><?= Page::escape($announcement['summary']) ?></p><?php endif; ?>
                    <p class="classifier-note"><span class="status status-<?= Page::escape($announcement['classifier_impact']) ?>"><?= Page::escape(ucfirst((string) $announcement['classifier_impact'])) ?></span> Suggested sector: <strong><?= Page::escape($announcement['classifier_sector'] ?? 'Unclassified') ?></strong> · confidence <?= Page::escape($announcement['classifier_confidence']) ?>%</p>
                    <small class="muted"><?= Page::escape($announcement['classifier_reason']) ?></small>
                    <?php if ($announcement['review_id'] !== null): ?>
                        <div class="reviewed-evidence"><span class="status status-<?= Page::escape($announcement['reviewed_impact']) ?>"><?= Page::escape(ucwords(str_replace('_', ' ', (string) $announcement['reviewed_impact']))) ?></span><strong><?= Page::escape($announcement['reviewed_sector']) ?></strong><p><?= Page::escape($announcement['review_rationale']) ?></p><small><?= Page::escape($announcement['reviewer_name']) ?> · <?= Page::escape($announcement['reviewed_at']) ?></small></div>
                    <?php endif; ?>
                </div>
                <details class="review-disclosure">
                    <summary><?= $announcement['review_id'] === null ? 'Review evidence' : 'Replace review' ?></summary>
                    <form class="stacked-form tailwind-review-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
                        <input type="hidden" name="announcement_id" value="<?= Page::escape($announcement['id']) ?>">
                        <label>Sector<input name="sector" maxlength="150" value="<?= Page::escape($announcement['reviewed_sector'] ?? $announcement['classifier_sector'] ?? '') ?>"></label>
                        <label>Reviewed impact<select name="impact" required><option value="">Choose a decision</option><option value="strong_tailwind">Strong tailwind</option><option value="moderate_tailwind">Moderate tailwind</option><option value="neutral">Neutral</option><option value="headwind">Headwind</option><option value="not_relevant">Not relevant</option></select></label>
                        <label>Rationale<textarea name="rationale" minlength="10" maxlength="1000" required placeholder="Explain the sector link and why the official announcement is or is not a tailwind."></textarea></label>
                        <button class="button button-primary" type="submit">Save reviewed decision</button>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<section class="notice-card"><strong>Evidence rule</strong><p>Classification never approves an announcement. Only a current “strong tailwind” or “moderate tailwind” human review can be selected in a company macro-factor review. This remains research evidence, not an investment recommendation.</p></section>
<?php Page::end(); ?>
