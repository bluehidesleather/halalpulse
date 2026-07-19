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

$policy = $app->sharia->activePolicy();
$summary = $app->sharia->summary();
$companies = $app->sharia->companies();

Page::begin(
    'Sharia screening',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'sharia',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div><p class="eyebrow">Evidence-first review</p><h1>Sharia screening</h1><p class="muted">Versioned policy calculations, separated from investment scoring and gated by human evidence review.</p></div>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<?php if ($policy === null): ?>
    <section class="notice-card notice-error policy-gate">
        <strong>Screening is locked</strong>
        <p>No policy is active. Copy <span class="mono">config/sharia-policy.example.json</span> to the ignored local policy file, verify every threshold against the current official or licensed standard, approve it, and run the policy installer.</p>
    </section>
<?php else: ?>
    <section class="policy-banner">
        <div><p class="eyebrow">Active policy</p><h2><?= Page::escape($policy->name) ?> · <?= Page::escape($policy->version) ?></h2><p><?= Page::escape($policy->authorityName) ?> <?= Page::escape($policy->authorityStandard) ?> · effective <?= Page::escape($policy->effectiveDate) ?></p></div>
        <div class="policy-meta"><span class="status status-passed">Active</span><a href="<?= Page::escape($policy->authorityReferenceUrl) ?>" target="_blank" rel="noopener noreferrer">Authority reference</a><span class="mono">SHA <?= Page::escape(substr($policy->policyHash, 0, 12)) ?>…</span></div>
    </section>
<?php endif; ?>

<section class="metric-grid" aria-label="Sharia review summary">
    <article class="metric-card"><span>Companies</span><strong><?= Page::escape($summary['companies']) ?></strong><small>Active observed issuers</small></article>
    <article class="metric-card"><span>Activity reviewed</span><strong><?= Page::escape($summary['reviewed']) ?></strong><small>Latest human classification</small></article>
    <article class="metric-card metric-accent"><span>Latest pass</span><strong><?= Page::escape($summary['passed']) ?></strong><small>Under a recorded policy version</small></article>
    <article class="metric-card"><span>Needs attention</span><strong><?= Page::escape($summary['failed'] + $summary['insufficient']) ?></strong><small><?= Page::escape($summary['failed']) ?> failed · <?= Page::escape($summary['insufficient']) ?> insufficient</small></article>
</section>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Company workbench</p><h2>Review queue</h2></div><span class="status"><?= Page::escape(count($companies)) ?> shown</span></div>
    <?php if ($companies === []): ?>
        <div class="empty-state"><h3>No companies stored yet</h3><p>Companies appear after a verified NSE or BSE filing poll stores official announcements.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Company</th><th>Activity review</th><th>Latest screening</th><th>Rank</th><th>Period</th></tr></thead>
                <tbody>
                <?php foreach ($companies as $company): ?>
                    <?php $status = (string) ($company['screening_status'] ?? 'not_screened'); ?>
                    <tr>
                        <td><span class="exchange-badge"><?= Page::escape($company['exchange']) ?></span><a class="table-title" href="/sharia-company.php?id=<?= Page::escape($company['id']) ?>"><strong><?= Page::escape($company['symbol']) ?></strong></a><small><?= Page::escape($company['company_name']) ?></small></td>
                        <td><span class="status status-<?= Page::escape($company['activity_status'] ?? 'pending') ?>"><?= Page::escape(ucfirst((string) ($company['activity_status'] ?? 'pending'))) ?></span></td>
                        <td><span class="status status-<?= Page::escape($status) ?>"><?= Page::escape(ucfirst(str_replace('_', ' ', $status))) ?></span></td>
                        <td><?= $company['compliance_rank'] === null ? '—' : Page::escape($company['compliance_rank']) . ' / 5' ?></td>
                        <td><span class="nowrap"><?= Page::escape($company['screening_period'] ?? '—') ?></span><small><?= Page::escape($company['screened_at'] ?? '') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php Page::end(); ?>
