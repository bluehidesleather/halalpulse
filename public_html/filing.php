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

$filingId = Request::queryInt('id', 0);
$filing = $filingId > 0 ? $app->documents->filingDetail($filingId) : null;

if ($filing === null) {
    http_response_code(404);
    Page::begin('Filing not found', (string) $config->get('app.name', 'HalalPulse'), $user, 'filings', $app->session->csrfToken());
    echo '<div class="empty-state"><h1>Filing not found</h1><p>The requested filing does not exist.</p><a class="button button-secondary" href="/filings.php">Return to filings</a></div>';
    Page::end();
    exit;
}

$documentId = (int) ($filing['document_id'] ?? 0);
$metrics = $documentId > 0 ? $app->documents->metricsForDocument($documentId) : [];
$structuredResult = $app->documents->financialResultForFiling($filingId);
$attachmentUrl = is_string($filing['attachment_url']) && str_starts_with($filing['attachment_url'], 'https://')
    ? $filing['attachment_url']
    : null;
$metricLabels = [
    'revenue' => 'Revenue',
    'total_income' => 'Total income',
    'ebitda' => 'EBITDA',
    'profit_before_tax' => 'Profit before tax',
    'net_profit' => 'Net profit',
    'eps' => 'Earnings per share',
];
$structuredMetricLabels = [
    'revenue_from_operations' => 'Revenue from operations',
    'other_income' => 'Other income',
    'total_income' => 'Total income',
    'finance_costs' => 'Finance costs',
    'total_expenses' => 'Total expenses',
    'profit_before_tax' => 'Profit before tax',
    'tax_expense' => 'Tax expense',
    'profit_for_period' => 'Profit for period',
    'profit_attributable_to_owners' => 'Profit attributable to owners',
    'basic_eps' => 'Basic EPS',
    'diluted_eps' => 'Diluted EPS',
    'debt_equity_ratio' => 'Debt/equity ratio',
];

Page::begin(
    (string) $filing['symbol'] . ' filing',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'filings',
    $app->session->csrfToken(),
);
?>
<div class="page-heading filing-heading">
    <div>
        <p class="eyebrow"><?= Page::escape($filing['exchange']) ?> · <?= Page::escape($filing['category']) ?></p>
        <h1><?= Page::escape($filing['symbol']) ?></h1>
        <p class="company-name"><?= Page::escape($filing['company_name']) ?></p>
    </div>
    <div class="heading-actions">
        <?php if ($attachmentUrl !== null): ?><a class="button button-secondary" href="<?= Page::escape($attachmentUrl) ?>" target="_blank" rel="noopener noreferrer">Official source</a><?php endif; ?>
        <?php if (($filing['download_status'] ?? null) === 'downloaded'): ?><a class="button button-primary" href="/document.php?id=<?= Page::escape($documentId) ?>" target="_blank" rel="noopener">Open private PDF</a><?php endif; ?>
    </div>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<section class="detail-grid">
    <article class="panel detail-main">
        <p class="eyebrow">Announcement</p>
        <h2><?= Page::escape($filing['subject']) ?></h2>
        <dl class="detail-list detail-list-wide">
            <div><dt>Published</dt><dd><?= Page::escape($filing['announced_at']) ?></dd></div>
            <div><dt>Source ID</dt><dd class="mono"><?= Page::escape($filing['source_id']) ?></dd></div>
            <div><dt>Pipeline</dt><dd><span class="status"><?= Page::escape(ucfirst((string) $filing['processing_status'])) ?></span></dd></div>
            <div><dt>Classifier</dt><dd><?php if ((int) $filing['is_quarterly_result_candidate'] === 1): ?><span class="status status-candidate">Candidate <?= Page::escape($filing['classifier_confidence']) ?>%</span><?php else: ?><span class="status">Not selected</span><?php endif; ?><small><?= Page::escape($filing['classifier_reason']) ?></small></dd></div>
        </dl>
    </article>

    <aside class="panel">
        <p class="eyebrow">Document evidence</p>
        <?php if ($documentId < 1): ?>
            <div class="empty-state compact"><h3>Not queued</h3><p>The document command has not seeded this filing.</p></div>
        <?php else: ?>
            <dl class="detail-list">
                <div><dt>Download</dt><dd><span class="status status-<?= Page::escape($filing['download_status']) ?>"><?= Page::escape(ucfirst((string) $filing['download_status'])) ?></span></dd></div>
                <div><dt>Extraction</dt><dd><span class="status status-<?= Page::escape($filing['extraction_status']) ?>"><?= Page::escape(str_replace('_', ' ', ucfirst((string) $filing['extraction_status']))) ?></span></dd></div>
                <div><dt>Size</dt><dd><?= $filing['file_size_bytes'] ? Page::escape(number_format((int) $filing['file_size_bytes'] / 1024, 1) . ' KB') : '—' ?></dd></div>
                <div><dt>SHA-256</dt><dd class="mono hash-value"><?= Page::escape($filing['sha256'] ?? '—') ?></dd></div>
            </dl>
            <?php if ($filing['last_error'] !== null): ?><div class="notice-card notice-error"><strong>Pipeline note</strong><p><?= Page::escape($filing['last_error']) ?></p></div><?php endif; ?>
        <?php endif; ?>
    </aside>
</section>

<?php if ($structuredResult !== null): ?>
<section class="panel structured-result-panel">
    <div class="panel-heading">
        <div><p class="eyebrow">Exchange-submitted XBRL</p><h2>Structured financial result</h2></div>
        <span class="status status-processed"><?= Page::escape(ucfirst((string) $structuredResult['filing_type'])) ?></span>
    </div>
    <div class="result-meta">
        <span><?= Page::escape(ucfirst((string) $structuredResult['statement_scope'])) ?></span>
        <span><?= Page::escape($structuredResult['reporting_quarter'] ?? $structuredResult['reporting_period_type'] ?? 'Period not stated') ?></span>
        <span>Ended <?= Page::escape($structuredResult['period_end']) ?></span>
        <span><?= Page::escape($structuredResult['audit_status'] ?? 'Audit status not stated') ?></span>
        <span><?= Page::escape($structuredResult['currency']) ?> · <?= Page::escape($structuredResult['rounding_level'] ?? 'source units') ?></span>
    </div>
    <div class="structured-metric-grid">
        <?php foreach ($structuredMetricLabels as $key => $label): ?>
            <?php if ($structuredResult[$key] !== null): ?>
                <article><span><?= Page::escape($label) ?></span><strong><?= Page::escape($structuredResult[$key]) ?></strong></article>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <dl class="detail-list detail-list-wide result-integrity">
        <div><dt>Board approval</dt><dd><?= Page::escape($structuredResult['board_approval_date'] ?? '—') ?></dd></div>
        <div><dt>XBRL SHA-256</dt><dd class="mono hash-value"><?= Page::escape($structuredResult['xbrl_sha256']) ?></dd></div>
        <div><dt>Taxonomy</dt><dd class="mono hash-value"><?= Page::escape($structuredResult['taxonomy_uri'] ?? '—') ?></dd></div>
    </dl>
    <div class="notice-card"><strong>Primary-source data, not an investment conclusion</strong><p>These values are parsed from the archived official XBRL and preserve its submitted units. They do not create a Sharia decision or potential score without the separate reviewed evidence gates.</p></div>
</section>
<?php endif; ?>

<section class="panel metric-review-panel">
    <div class="panel-heading"><div><p class="eyebrow">Human validation gate</p><h2>Financial metric candidates</h2></div><span class="status"><?= Page::escape(count($metrics)) ?> detected</span></div>
    <div class="notice-card"><strong>Not investment data yet</strong><p>Values below come from conservative text matching. Confirm the statement scope, period, currency, scale, and evidence line before accepting them. Accepted values still do not create a Sharia or multibagger score.</p></div>
    <?php if ($metrics === []): ?>
        <div class="empty-state"><h3>No metric candidates</h3><p>Text extraction may be unavailable, pending, or unable to locate reliable statement lines.</p></div>
    <?php else: ?>
        <div class="metric-candidate-list">
            <?php foreach ($metrics as $metric): ?>
                <article class="metric-candidate">
                    <div class="metric-candidate-value">
                        <span class="eyebrow"><?= Page::escape($metricLabels[$metric['metric_key']] ?? $metric['metric_key']) ?></span>
                        <strong><?= Page::escape($metric['current_value']) ?></strong>
                        <small><?= Page::escape($metric['currency'] ?? 'Currency unknown') ?> · <?= Page::escape($metric['scale_label']) ?> · <?= Page::escape($metric['statement_scope']) ?></small>
                    </div>
                    <div class="metric-evidence"><p><?= Page::escape($metric['evidence_snippet']) ?></p><span class="status">Parser <?= Page::escape($metric['confidence']) ?>%</span><?php if ($metric['comparison_value'] !== null): ?><span class="status">Comparison <?= Page::escape($metric['comparison_value']) ?></span><?php endif; ?></div>
                    <div class="metric-review-actions">
                        <span class="status status-<?= Page::escape($metric['review_status']) ?>"><?= Page::escape(ucfirst((string) $metric['review_status'])) ?></span>
                        <?php if ($metric['review_status'] === 'pending'): ?>
                            <form method="post" action="/metric-review.php"><input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>"><input type="hidden" name="candidate_id" value="<?= Page::escape($metric['id']) ?>"><button class="button button-primary button-small" type="submit" name="action" value="accepted">Accept</button><button class="button button-quiet button-small" type="submit" name="action" value="rejected">Reject</button></form>
                        <?php else: ?>
                            <small><?= Page::escape($metric['reviewer_name'] ?? 'Administrator') ?> · <?= Page::escape($metric['reviewed_at']) ?></small>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php Page::end(); ?>
