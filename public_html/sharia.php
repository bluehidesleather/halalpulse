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
$statusLabel = static function (string $status) use ($policy): string {
    return $policy === null ? ucfirst(str_replace('_', ' ', $status)) : $policy->statusLabel($status);
};

usort($companies, static function (array $left, array $right): int {
    $priority = static function (array $company): array {
        $status = (string) ($company['screening_status'] ?? 'not_screened');
        $rank = isset($company['compliance_rank']) ? (int) $company['compliance_rank'] : 99;
        $statusOrder = match ($status) {
            'passed' => 0,
            'insufficient' => 1,
            'failed' => 2,
            default => 3,
        };

        return [
            $statusOrder,
            $status === 'passed' ? $rank : 99,
            mb_strtolower((string) ($company['company_name'] ?? '')),
            (string) ($company['exchange'] ?? ''),
            (string) ($company['symbol'] ?? ''),
        ];
    };

    return $priority($left) <=> $priority($right);
});

Page::begin(
    'Sharia screening',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'sharia',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div><p class="eyebrow">Evidence-first review</p><h1>Sharia research screening</h1><p class="muted">Versioned calculations separated from investment scoring and gated by human primary-evidence review.</p></div>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<?php if ($policy === null): ?>
    <section class="notice-card notice-error policy-gate">
        <strong>Screening is locked</strong>
        <p>No policy is active. Activate the versioned HalalPulse research policy with explicit research-only acknowledgement, or install an independently reviewed policy through the separate verified-policy workflow.</p>
    </section>
<?php else: ?>
    <section class="policy-banner">
        <div><p class="eyebrow"><?= $policy->isResearch() ? 'Active research policy' : 'Active independently reviewed policy' ?></p><h2><?= Page::escape($policy->name) ?> · <?= Page::escape($policy->version) ?></h2><p><?= Page::escape($policy->authorityName) ?> · <?= Page::escape($policy->authorityStandard) ?> · effective <?= Page::escape($policy->effectiveDate) ?></p></div>
        <div class="policy-meta"><span class="status status-<?= $policy->isResearch() ? 'manual_review' : 'passed' ?>"><?= $policy->isResearch() ? 'Research active' : 'Reviewed active' ?></span><a href="<?= Page::escape($policy->authorityReferenceUrl) ?>" target="_blank" rel="noopener noreferrer">Authority reference</a><span class="mono">SHA <?= Page::escape(substr($policy->policyHash, 0, 12)) ?>…</span></div>
    </section>
    <section class="notice-card">
        <strong><?= $policy->isResearch() ? 'Research assurance boundary' : 'Policy assurance' ?></strong>
        <p><?= Page::escape($policy->disclaimer) ?></p>
        <?php if ($policy->isResearch()): ?><p><strong>Additional strict rule:</strong> HalalPulse requires eligible real operating assets to be at least 30% of consolidated total assets. This is an owner-defined investor-protection overlay, not an AAOIFI certification claim.</p><?php endif; ?>
    </section>
<?php endif; ?>

<section class="metric-grid" aria-label="Sharia review summary">
    <article class="metric-card"><span>Companies</span><strong><?= Page::escape($summary['companies']) ?></strong><small>Active observed issuers</small></article>
    <article class="metric-card"><span>Activity reviewed</span><strong><?= Page::escape($summary['reviewed']) ?></strong><small>Latest human classification</small></article>
    <article class="metric-card metric-accent"><span><?= $policy?->isResearch() ? 'Latest research pass' : 'Latest pass' ?></span><strong><?= Page::escape($summary['passed']) ?></strong><small>Under a recorded policy version</small></article>
    <article class="metric-card"><span>Needs attention</span><strong><?= Page::escape($summary['failed'] + $summary['insufficient']) ?></strong><small><?= Page::escape($summary['failed']) ?> failed · <?= Page::escape($summary['insufficient']) ?> insufficient</small></article>
</section>

<section class="notice-card">
    <strong>Rank direction</strong>
    <p>Rank 1 is the strongest passing buffer. Rank 5 still passes, but is closest to one or more active-policy maximums or minimums. The rank is a HalalPulse research indicator, not an AAOIFI rating.</p>
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
                        <td><span class="exchange-badge"><?= Page::escape($company['exchange']) ?></span><a class="table-title" href="/sharia-company.php?id=<?= Page::escape($company['id']) ?>"><strong><?= Page::escape($company['symbol']) ?></strong></a><small><?= Page::escape($company['company_name']) ?></small><small><a href="/sharia-candidates.php?id=<?= Page::escape($company['id']) ?>">Review structured XBRL evidence</a></small></td>
                        <td><span class="status status-<?= Page::escape($company['activity_status'] ?? 'pending') ?>"><?= Page::escape(ucfirst((string) ($company['activity_status'] ?? 'pending'))) ?></span></td>
                        <td><span class="status status-<?= Page::escape($status) ?>"><?= Page::escape($statusLabel($status)) ?></span></td>
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
