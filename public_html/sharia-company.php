<?php

declare(strict_types=1);

use HalalPulse\Sharia\DecimalMath;
use HalalPulse\Sharia\ShariaScreeningEngine;
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

$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
};
$returnPath = static function (int $id, string $period = ''): string {
    $path = '/sharia-company.php?id=' . $id;

    return $period === '' ? $path : $path . '&period=' . rawurlencode($period);
};

if (Request::isPost()) {
    if (!$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    $action = Request::postString('action');
    $period = Request::postString('period_end');

    try {
        if ($action === 'save_activity') {
            $status = Request::postString('activity_status');
            $description = Request::postString('activity_description');
            $sourceUrl = Request::postString('evidence_source_url');
            $note = Request::postString('evidence_note');

            if (!in_array($status, ['pending', 'permissible', 'prohibited', 'mixed'], true)) {
                throw new InvalidArgumentException('Choose a valid activity status.');
            }
            if (mb_strlen($description) < 3 || mb_strlen($description) > 1000) {
                throw new InvalidArgumentException('Activity description must contain 3 to 1,000 characters.');
            }
            if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
                throw new InvalidArgumentException('Evidence note must contain 3 to 1,000 characters.');
            }
            if ($sourceUrl !== '' && (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($sourceUrl), 'https://'))) {
                throw new InvalidArgumentException('Evidence source must be a valid HTTPS URL.');
            }

            $app->sharia->saveActivityReview($companyId, $status, $description, $sourceUrl === '' ? null : $sourceUrl, $note, $user->id);
            $app->logger->info('Company Sharia activity reviewed.', ['company_id' => $companyId, 'activity_status' => $status, 'user_id' => $user->id]);
            $app->session->flash('success', 'Activity review saved as a new audit record.');
        } elseif ($action === 'save_input') {
            $policy = $app->sharia->activePolicy();
            if ($policy === null) {
                throw new InvalidArgumentException('Activate a verified policy before entering policy inputs.');
            }

            $metricKey = Request::postString('metric_key');
            $value = Request::postString('value');
            $currency = strtoupper(Request::postString('currency'));
            $scale = Request::postString('scale_label');
            $documentText = Request::postString('source_document_id');
            $documentId = $documentText === '' ? null : (int) $documentText;
            $note = Request::postString('input_evidence_note');

            if (!$validDate($period)) {
                throw new InvalidArgumentException('Choose a valid evidence period end date.');
            }
            if (!in_array($metricKey, $policy->inputKeys(), true)) {
                throw new InvalidArgumentException('The financial input is not used by the active policy.');
            }
            if (preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,6})?$/D', $value) !== 1) {
                throw new InvalidArgumentException('Value must be a non-negative decimal with up to 18 whole and 6 fractional digits.');
            }
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new InvalidArgumentException('Currency must be a three-letter code such as INR.');
            }
            if (!in_array($scale, ['one', 'thousand', 'lakh', 'million', 'crore'], true)) {
                throw new InvalidArgumentException('Choose a valid unit scale.');
            }
            if (mb_strlen($note) < 3 || mb_strlen($note) > 1000) {
                throw new InvalidArgumentException('Input evidence note must contain 3 to 1,000 characters.');
            }
            if ($documentId !== null && ($documentId < 1 || !$app->sharia->documentBelongsToCompany($documentId, $companyId))) {
                throw new InvalidArgumentException('The selected source document does not belong to this company.');
            }

            $app->sharia->saveFinancialInput($companyId, $period, $metricKey, $value, $currency, $scale, $documentId, $note, $user->id);
            $app->logger->info('Sharia financial evidence accepted.', ['company_id' => $companyId, 'period_end' => $period, 'metric_key' => $metricKey, 'user_id' => $user->id]);
            $app->session->flash('success', 'Financial evidence saved. Any previous current value was preserved as superseded.');
        } elseif ($action === 'run_screening') {
            if (!$validDate($period)) {
                throw new InvalidArgumentException('Choose a valid screening period end date.');
            }

            $policy = $app->sharia->activePolicy();
            if ($policy === null) {
                throw new InvalidArgumentException('No verified Sharia policy is active.');
            }

            $activity = $app->sharia->latestActivityReview($companyId);
            $result = (new ShariaScreeningEngine(new DecimalMath()))->screen(
                $policy,
                (string) ($activity['activity_status'] ?? 'pending'),
                $app->sharia->inputsForPeriod($companyId, $period),
            );
            $screeningId = $app->sharia->recordScreening($companyId, $policy, $period, $result, $user->id);
            $app->logger->info('Sharia screening recorded.', ['screening_id' => $screeningId, 'company_id' => $companyId, 'status' => $result->status, 'policy_id' => $policy->id, 'user_id' => $user->id]);
            $app->session->flash($result->status === 'passed' ? 'success' : 'warning', 'Screening recorded as ' . $result->status . '.');
        } else {
            throw new InvalidArgumentException('Invalid Sharia review action.');
        }
    } catch (InvalidArgumentException $exception) {
        $app->session->flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        $app->logger->error('Sharia review action failed.', [
            'company_id' => $companyId,
            'action' => $action,
            'exception_class' => $exception::class,
            'user_id' => $user->id,
        ]);
        $app->session->flash('error', 'The action could not be completed. Run the health check and inspect the private application log.');
    }

    $app->session->rotateCsrfToken();
    Response::redirect($returnPath($companyId, $period));
}

$policy = $app->sharia->activePolicy();
$activity = $app->sharia->latestActivityReview($companyId);
$documents = $app->sharia->documentsForCompany($companyId);
$periods = $app->sharia->periods($companyId);
$selectedPeriod = Request::queryString('period');
if (!$validDate($selectedPeriod)) {
    $selectedPeriod = $periods[0] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
}
$inputs = $app->sharia->inputsForPeriod($companyId, $selectedPeriod);
$history = $app->sharia->screeningHistory($companyId);

Page::begin(
    (string) $company['symbol'] . ' Sharia review',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'sharia',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div><p class="eyebrow"><?= Page::escape($company['exchange']) ?> · <?= Page::escape($company['symbol']) ?></p><h1><?= Page::escape($company['company_name']) ?></h1><p class="muted">Every review, evidence value, policy version, and screening result is retained for audit.</p></div>
    <a class="button button-secondary" href="/sharia.php">Back to queue</a>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<?php if ($policy === null): ?>
    <section class="notice-card notice-error policy-gate"><strong>Policy gate closed</strong><p>You may record an activity review, but financial policy inputs and screening remain unavailable until a verified policy is installed.</p></section>
<?php else: ?>
    <section class="policy-banner compact-policy"><div><p class="eyebrow">Active policy</p><h2><?= Page::escape($policy->name) ?></h2><p><?= Page::escape($policy->version) ?> · <?= Page::escape($policy->authorityStandard) ?> · <?= Page::escape(count($policy->ratios)) ?> ratios</p></div><span class="mono">SHA <?= Page::escape(substr($policy->policyHash, 0, 12)) ?>…</span></section>
<?php endif; ?>

<section class="review-grid">
    <article class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Human gate</p><h2>Business activity</h2></div><?php if ($activity !== null): ?><span class="status status-<?= Page::escape($activity['activity_status']) ?>"><?= Page::escape(ucfirst((string) $activity['activity_status'])) ?></span><?php endif; ?></div>
        <?php if ($activity !== null): ?><p class="review-note"><?= Page::escape($activity['activity_description']) ?></p><small class="muted">Reviewed by <?= Page::escape($activity['reviewer_name']) ?> at <?= Page::escape($activity['reviewed_at']) ?></small><?php endif; ?>
        <form class="stacked-form form-section" method="post">
            <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
            <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
            <input type="hidden" name="action" value="save_activity">
            <label for="activity_status">Classification</label>
            <select id="activity_status" name="activity_status" required>
                <?php foreach (['pending', 'permissible', 'prohibited', 'mixed'] as $status): ?><option value="<?= Page::escape($status) ?>" <?= ($activity['activity_status'] ?? 'pending') === $status ? 'selected' : '' ?>><?= Page::escape(ucfirst($status)) ?></option><?php endforeach; ?>
            </select>
            <label for="activity_description">What the company does</label>
            <textarea id="activity_description" name="activity_description" maxlength="1000" required><?= Page::escape($activity['activity_description'] ?? '') ?></textarea>
            <label for="evidence_source_url">Evidence URL (HTTPS, optional)</label>
            <input id="evidence_source_url" name="evidence_source_url" type="url" maxlength="1000" value="<?= Page::escape($activity['evidence_source_url'] ?? '') ?>">
            <label for="evidence_note">Review rationale</label>
            <textarea id="evidence_note" name="evidence_note" maxlength="1000" required><?= Page::escape($activity['evidence_note'] ?? '') ?></textarea>
            <button class="button button-primary" type="submit">Save new activity review</button>
        </form>
    </article>

    <article class="panel">
        <div class="panel-heading"><div><p class="eyebrow">Accepted evidence</p><h2>Financial policy input</h2></div><span class="status"><?= Page::escape($selectedPeriod) ?></span></div>
        <?php if ($policy === null): ?>
            <div class="empty-state compact"><p>Activate a verified policy to reveal its required input keys.</p></div>
        <?php else: ?>
            <form class="stacked-form form-section" method="post">
                <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
                <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
                <input type="hidden" name="action" value="save_input">
                <label for="period_end">Evidence period end</label><input id="period_end" name="period_end" type="date" value="<?= Page::escape($selectedPeriod) ?>" required>
                <label for="metric_key">Policy input</label><select id="metric_key" name="metric_key" required><?php foreach ($policy->inputKeys() as $key): ?><option value="<?= Page::escape($key) ?>"><?= Page::escape(str_replace('_', ' ', ucfirst($key))) ?></option><?php endforeach; ?></select>
                <div class="form-row"><div><label for="value">Value</label><input id="value" name="value" inputmode="decimal" placeholder="0.000000" required></div><div><label for="currency">Currency</label><input id="currency" name="currency" value="INR" maxlength="3" required></div><div><label for="scale_label">Scale</label><select id="scale_label" name="scale_label"><?php foreach (['one', 'thousand', 'lakh', 'million', 'crore'] as $scale): ?><option value="<?= Page::escape($scale) ?>"><?= Page::escape(ucfirst($scale)) ?></option><?php endforeach; ?></select></div></div>
                <label for="source_document_id">Stored filing document (optional)</label><select id="source_document_id" name="source_document_id"><option value="">No linked PDF</option><?php foreach ($documents as $document): ?><option value="<?= Page::escape($document['id']) ?>">#<?= Page::escape($document['id']) ?> · <?= Page::escape($document['announced_at']) ?> · <?= Page::escape($document['subject']) ?></option><?php endforeach; ?></select>
                <label for="input_evidence_note">Where this value appears</label><textarea id="input_evidence_note" name="input_evidence_note" maxlength="1000" required></textarea>
                <button class="button button-primary" type="submit">Accept evidence value</button>
            </form>
        <?php endif; ?>
    </article>
</section>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Current evidence set</p><h2>Inputs for period</h2></div><form class="period-picker" method="get"><input type="hidden" name="id" value="<?= Page::escape($companyId) ?>"><label for="period">Period</label><input id="period" name="period" type="date" value="<?= Page::escape($selectedPeriod) ?>"><button class="button button-secondary button-small" type="submit">Load</button></form></div>
    <?php if ($inputs === []): ?>
        <div class="empty-state compact"><p>No accepted policy inputs for this period.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Input</th><th>Value</th><th>Evidence</th><th>Accepted</th></tr></thead><tbody><?php foreach ($inputs as $key => $input): ?><tr><td><strong><?= Page::escape(str_replace('_', ' ', ucfirst($key))) ?></strong></td><td><?= Page::escape($input['currency']) ?> <?= Page::escape($input['value']) ?><small><?= Page::escape($input['scale_label']) ?></small></td><td><?= Page::escape($input['evidence_note']) ?><?php if ($input['source_document_id'] !== null): ?><small><a href="/document.php?id=<?= Page::escape($input['source_document_id']) ?>">Stored PDF #<?= Page::escape($input['source_document_id']) ?></a></small><?php endif; ?></td><td><?= Page::escape($input['accepted_by_name']) ?><small><?= Page::escape($input['accepted_at']) ?></small></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($policy !== null): ?><form class="screening-action" method="post"><input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>"><input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>"><input type="hidden" name="period_end" value="<?= Page::escape($selectedPeriod) ?>"><input type="hidden" name="action" value="run_screening"><p>Uses the current evidence set and stores an immutable result snapshot under policy <?= Page::escape($policy->version) ?>.</p><button class="button button-primary" type="submit">Run screening</button></form><?php endif; ?>
</section>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Immutable decisions</p><h2>Screening history</h2></div><span class="status"><?= Page::escape(count($history)) ?> records</span></div>
    <?php if ($history === []): ?>
        <div class="empty-state compact"><p>No screenings recorded yet.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Result</th><th>Period</th><th>Policy</th><th>Reasons</th><th>Recorded</th></tr></thead><tbody>
        <?php foreach ($history as $screening): ?>
            <?php $reasons = json_decode((string) $screening['reasons'], true); $reasons = is_array($reasons) ? $reasons : []; ?>
            <tr><td><span class="status status-<?= Page::escape($screening['status']) ?>"><?= Page::escape(ucfirst((string) $screening['status'])) ?></span><small><?= $screening['compliance_rank'] === null ? 'No rank' : 'HalalPulse rank ' . Page::escape($screening['compliance_rank']) . ' / 5' ?></small></td><td><?= Page::escape($screening['period_end']) ?></td><td><?= Page::escape($screening['policy_version']) ?><small><?= Page::escape($screening['policy_name']) ?></small></td><td><ul class="reason-list"><?php foreach ($reasons as $reason): ?><li><?= Page::escape((string) $reason) ?></li><?php endforeach; ?></ul></td><td><?= Page::escape($screening['computed_by_name']) ?><small><?= Page::escape($screening['computed_at']) ?></small></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php Page::end(); ?>
