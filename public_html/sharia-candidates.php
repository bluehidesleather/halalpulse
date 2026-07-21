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

$companyId = Request::isPost() ? (int) Request::postString('company_id') : Request::queryInt('id', 0);
$company = $companyId > 0 ? $app->sharia->company($companyId) : null;
if ($company === null) {
    http_response_code(404);
    exit('Company not found.');
}

$returnPath = '/sharia-candidates.php?id=' . $companyId;
if (Request::isPost()) {
    if (!$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    $candidateId = (int) Request::postString('candidate_id');
    $action = Request::postString('action');

    try {
        if ($action === 'accept_candidate') {
            $policy = $app->sharia->activePolicy();
            if ($policy === null) {
                throw new InvalidArgumentException('Activate a verified Sharia policy before accepting policy evidence.');
            }

            $inputId = $app->shariaCandidates->accept(
                candidateId: $candidateId,
                companyId: $companyId,
                userId: $user->id,
                allowedMetricKeys: $policy->inputKeys(),
            );
            $app->logger->info('NSE XBRL Sharia evidence candidate accepted.', [
                'candidate_id' => $candidateId,
                'input_id' => $inputId,
                'company_id' => $companyId,
                'user_id' => $user->id,
            ]);
            $app->session->flash('success', 'Candidate accepted into the current Sharia evidence set.');
        } elseif ($action === 'reject_candidate') {
            $app->shariaCandidates->reject($candidateId, $companyId, $user->id);
            $app->logger->info('NSE XBRL Sharia evidence candidate rejected.', [
                'candidate_id' => $candidateId,
                'company_id' => $companyId,
                'user_id' => $user->id,
            ]);
            $app->session->flash('success', 'Candidate rejected and preserved in the audit trail.');
        } else {
            throw new InvalidArgumentException('Invalid evidence-candidate action.');
        }
    } catch (InvalidArgumentException $exception) {
        $app->session->flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        $app->logger->error('NSE XBRL Sharia evidence candidate action failed.', [
            'candidate_id' => $candidateId,
            'company_id' => $companyId,
            'action' => $action,
            'exception_class' => $exception::class,
            'user_id' => $user->id,
        ]);
        $app->session->flash('error', 'The candidate action could not be completed. Inspect the private application log.');
    }

    $app->session->rotateCsrfToken();
    Response::redirect($returnPath);
}

$policy = $app->sharia->activePolicy();
$candidates = $app->shariaCandidates->forCompany($companyId);
$pendingCount = count(array_filter(
    $candidates,
    static fn (array $candidate): bool => ($candidate['review_status'] ?? null) === 'pending',
));

Page::begin(
    (string) $company['symbol'] . ' XBRL evidence',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'sharia',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div>
        <p class="eyebrow"><?= Page::escape($company['exchange']) ?> · <?= Page::escape($company['symbol']) ?></p>
        <h1>Structured evidence candidates</h1>
        <p class="muted">Official NSE XBRL values are suggested for review. Nothing is accepted automatically into a Sharia screening.</p>
    </div>
    <a class="button button-secondary" href="/sharia-company.php?id=<?= Page::escape($companyId) ?>">Back to company review</a>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<?php if ($policy === null): ?>
    <section class="notice-card notice-error policy-gate">
        <strong>Acceptance is locked</strong>
        <p>The green action is unavailable until a clause-verified policy is activated. Pending candidates remain unchanged. Reject only when the XBRL-to-input mapping itself is demonstrably wrong.</p>
    </section>
<?php endif; ?>

<section class="metric-grid">
    <article class="metric-card"><span>Company</span><strong><?= Page::escape($company['symbol']) ?></strong><small><?= Page::escape($company['company_name']) ?></small></article>
    <article class="metric-card metric-accent"><span>Pending review</span><strong><?= Page::escape($pendingCount) ?></strong><small>Require administrator decision</small></article>
    <article class="metric-card"><span>Total candidates</span><strong><?= Page::escape(count($candidates)) ?></strong><small>Audit records retained</small></article>
    <article class="metric-card"><span>Policy</span><strong><?= $policy === null ? 'Locked' : 'Active' ?></strong><small><?= Page::escape($policy?->version ?? 'No verified policy') ?></small></article>
</section>

<section class="notice-card">
    <strong>Conservative automation boundary</strong>
    <p>The mapper currently suggests only a total-revenue candidate from a structured total-income fact, or a lower-confidence revenue-from-operations fallback. Debt, interest-bearing deposits and impermissible income are not inferred from unrelated facts.</p>
</section>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Official structured evidence</p><h2>Candidate queue</h2></div><span class="status"><?= Page::escape(count($candidates)) ?> records</span></div>
    <?php if ($candidates === []): ?>
        <div class="empty-state"><h3>No candidates yet</h3><p>The next eligible NSE XBRL ingestion will create conservative review candidates when a supported fact is present.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Period</th><th>Suggested input</th><th>Value</th><th>Source fact</th><th>Confidence</th><th>Status</th><th>Decision</th></tr></thead>
                <tbody>
                <?php foreach ($candidates as $candidate): ?>
                    <tr>
                        <td><?= Page::escape($candidate['period_end']) ?><small><?= Page::escape($candidate['statement_scope']) ?> · <?= Page::escape($candidate['reporting_period_type'] ?? '') ?></small></td>
                        <td><strong><?= Page::escape(str_replace('_', ' ', ucfirst((string) $candidate['metric_key']))) ?></strong><small><?= Page::escape($candidate['mapping_reason']) ?></small></td>
                        <td><?= Page::escape($candidate['currency']) ?> <?= Page::escape($candidate['candidate_value']) ?><small><?= Page::escape($candidate['scale_label']) ?></small></td>
                        <td><span class="mono"><?= Page::escape($candidate['source_fact_name']) ?></span><small>Context <?= Page::escape($candidate['source_context_ref']) ?> · <?= Page::escape($candidate['source_filename']) ?></small></td>
                        <td><?= Page::escape($candidate['mapping_confidence']) ?>%</td>
                        <td><span class="status status-<?= Page::escape($candidate['review_status']) ?>"><?= Page::escape(ucfirst((string) $candidate['review_status'])) ?></span><?php if ($candidate['reviewed_by_name'] !== null): ?><small><?= Page::escape($candidate['reviewed_by_name']) ?> · <?= Page::escape($candidate['reviewed_at']) ?></small><?php endif; ?></td>
                        <td>
                            <?php if ($candidate['review_status'] === 'pending'): ?>
                                <div class="button-row">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
                                        <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
                                        <input type="hidden" name="candidate_id" value="<?= Page::escape($candidate['id']) ?>">
                                        <input type="hidden" name="action" value="accept_candidate">
                                        <button class="button button-primary button-small" type="submit" <?= $policy === null ? 'disabled title="A verified policy is required before acceptance."' : '' ?>><?= $policy === null ? 'Policy required' : 'Accept' ?></button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
                                        <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
                                        <input type="hidden" name="candidate_id" value="<?= Page::escape($candidate['id']) ?>">
                                        <input type="hidden" name="action" value="reject_candidate">
                                        <button class="button button-secondary button-small" type="submit" title="Reject only when the structured mapping is demonstrably incorrect.">Reject</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php Page::end(); ?>
