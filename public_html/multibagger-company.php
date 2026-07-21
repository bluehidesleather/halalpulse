<?php

declare(strict_types=1);

use HalalPulse\Multibagger\MultibaggerEvidenceReadiness;
use HalalPulse\Multibagger\MultibaggerScoringEngine;
use HalalPulse\Multibagger\ResearchEvidenceUrl;
use HalalPulse\Sharia\DecimalMath;
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
$company = $companyId > 0 ? $app->multibagger->company($companyId) : null;
if ($company === null) {
    http_response_code(404);
    exit('Company not found.');
}

$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
};
$returnPath = static function (int $id, string $period = ''): string {
    $path = '/multibagger-company.php?id=' . $id;

    return $period === '' ? $path : $path . '&period=' . rawurlencode($period);
};
$postList = static function (string $key): array {
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter($values, 'is_string'));
};
$decodeFlags = static function (mixed $value): array {
    if (is_array($value)) {
        return array_values(array_filter($value, 'is_string'));
    }
    if (!is_string($value) || $value === '') {
        return [];
    }
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }

    return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
};

$readinessService = new MultibaggerEvidenceReadiness(new DecimalMath());

if (Request::isPost()) {
    if (!$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
        http_response_code(400);
        exit('Invalid request token.');
    }

    $action = Request::postString('action');
    $period = Request::postString('period_end');

    try {
        if (!$validDate($period)) {
            throw new InvalidArgumentException('Choose a valid reporting period end date.');
        }

        $methodology = $app->multibagger->activeMethodology();
        if ($methodology === null) {
            throw new InvalidArgumentException('Activate the reviewed multibagger methodology first.');
        }

        $factorNoteMinimum = max(20, (int) ($methodology->definition['review_scope']['factor_evidence_note_min_chars'] ?? 20));
        $valuationNoteMinimum = max(20, (int) ($methodology->definition['review_scope']['valuation_assumptions_note_min_chars'] ?? 40));

        if ($action === 'save_factor') {
            $factorKey = Request::postString('factor_key');
            $grade = (int) Request::postString('grade');
            $note = trim(Request::postString('evidence_note'));
            $sourceUrl = trim(Request::postString('evidence_source_url'));
            $documentText = Request::postString('source_document_id');
            $documentId = $documentText === '' ? null : (int) $documentText;
            $governmentText = Request::postString('government_tailwind_review_id');
            $governmentReviewId = $governmentText === '' ? null : (int) $governmentText;

            if (!in_array($factorKey, $methodology->factorKeys(), true) || $grade < 1 || $grade > 10) {
                throw new InvalidArgumentException('Choose a valid factor and grade from 1 to 10.');
            }
            if (mb_strlen($note) < $factorNoteMinimum || mb_strlen($note) > 1000) {
                throw new InvalidArgumentException("Factor evidence note must contain {$factorNoteMinimum} to 1,000 characters.");
            }
            if ($documentId !== null && ($documentId < 1 || !$app->multibagger->documentBelongsToCompany($documentId, $companyId))) {
                throw new InvalidArgumentException('The selected PDF does not belong to this company.');
            }

            if ($factorKey === 'macro_tailwind') {
                $governmentEvidence = $governmentReviewId === null
                    ? null
                    : $app->government->approvedTailwind($governmentReviewId);
                if ($governmentEvidence === null) {
                    throw new InvalidArgumentException('Choose a current, human-approved government tailwind review for the macro factor.');
                }
                $sourceUrl = (string) $governmentEvidence['official_url'];
                $documentId = null;
            } else {
                $governmentReviewId = null;
                if ($sourceUrl === '' && $documentId === null) {
                    throw new InvalidArgumentException('Link an official source URL or a stored filing PDF.');
                }
                if ($sourceUrl !== '' && !ResearchEvidenceUrl::isAllowed($sourceUrl)) {
                    throw new InvalidArgumentException('Evidence URL must use an allowed official exchange, regulator, or government HTTPS host.');
                }
            }

            $app->multibagger->saveFactorReview(
                $companyId,
                $period,
                $factorKey,
                $grade,
                $note,
                $sourceUrl === '' ? null : $sourceUrl,
                $documentId,
                $governmentReviewId,
                $user->id,
            );
            $app->logger->info('Multibagger factor reviewed.', [
                'company_id' => $companyId,
                'period_end' => $period,
                'factor_key' => $factorKey,
                'grade' => $grade,
                'government_review_id' => $governmentReviewId,
                'user_id' => $user->id,
            ]);
            $app->session->flash('success', 'Factor review saved as a new audit record.');
        } elseif ($action === 'save_valuation') {
            $values = [];
            foreach (['eps', 'book_value_per_share', 'dcf_value_per_share', 'current_price'] as $field) {
                $value = Request::postString($field);
                if (preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,6})?$/D', $value) !== 1 || $value === '0') {
                    throw new InvalidArgumentException('Every valuation number must be a positive decimal with at most six fractional digits.');
                }
                $values[$field] = $value;
            }

            $currency = strtoupper(Request::postString('currency'));
            $assumptions = trim(Request::postString('dcf_assumptions_note'));
            $note = trim(Request::postString('valuation_evidence_note'));
            $sourceUrl = trim(Request::postString('valuation_evidence_source_url'));
            $documentText = Request::postString('valuation_document_id');
            $documentId = $documentText === '' ? null : (int) $documentText;

            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new InvalidArgumentException('Currency must be a three-letter code such as INR.');
            }
            if (mb_strlen($assumptions) < $valuationNoteMinimum || mb_strlen($assumptions) > 1000) {
                throw new InvalidArgumentException("DCF assumptions must contain {$valuationNoteMinimum} to 1,000 characters.");
            }
            if (mb_strlen($note) < $factorNoteMinimum || mb_strlen($note) > 1000) {
                throw new InvalidArgumentException("Valuation evidence note must contain {$factorNoteMinimum} to 1,000 characters.");
            }
            if (!ResearchEvidenceUrl::isAllowed($sourceUrl)) {
                throw new InvalidArgumentException('Valuation evidence URL must use an allowed official HTTPS host.');
            }
            if ($documentId !== null && !$app->multibagger->documentBelongsToCompany($documentId, $companyId)) {
                throw new InvalidArgumentException('The selected valuation PDF does not belong to this company.');
            }

            $values += [
                'currency' => $currency,
                'dcf_assumptions_note' => $assumptions,
                'evidence_note' => $note,
                'evidence_source_url' => $sourceUrl,
                'source_document_id' => $documentId,
            ];
            $app->multibagger->saveValuationReview($companyId, $period, $values, $user->id);
            $app->logger->info('Multibagger valuation reviewed.', [
                'company_id' => $companyId,
                'period_end' => $period,
                'user_id' => $user->id,
            ]);
            $app->session->flash('success', 'Graham and DCF valuation evidence saved.');
        } elseif ($action === 'save_risk') {
            $marketCap = Request::postString('market_cap_crore');
            $note = trim(Request::postString('risk_evidence_note'));
            $sourceUrl = trim(Request::postString('risk_evidence_source_url'));
            if (preg_match('/^(?:0|[1-9]\d{0,17})(?:\.\d{1,6})?$/D', $marketCap) !== 1 || $marketCap === '0') {
                throw new InvalidArgumentException('Market capitalization must be a positive crore value.');
            }
            if (mb_strlen($note) < $factorNoteMinimum || mb_strlen($note) > 1000) {
                throw new InvalidArgumentException("Risk evidence note must contain {$factorNoteMinimum} to 1,000 characters.");
            }
            if (!ResearchEvidenceUrl::isAllowed($sourceUrl)) {
                throw new InvalidArgumentException('Risk evidence URL must use an allowed official HTTPS host.');
            }

            $riskConfig = $methodology->definition['microcap_adjustments'];
            $allowedRed = array_column($riskConfig['red_flags'], 'key');
            $allowedGreen = array_column($riskConfig['green_flags'], 'key');
            $red = array_values(array_unique(array_intersect($postList('red_flags'), $allowedRed)));
            $green = array_values(array_unique(array_intersect($postList('green_flags'), $allowedGreen)));

            $app->multibagger->saveRiskReview(
                $companyId,
                $period,
                $marketCap,
                $red,
                $green,
                $note,
                $sourceUrl,
                $user->id,
            );
            $app->logger->info('Multibagger risk review saved.', [
                'company_id' => $companyId,
                'period_end' => $period,
                'red_flag_count' => count($red),
                'green_flag_count' => count($green),
                'user_id' => $user->id,
            ]);
            $app->session->flash('success', 'Market-cap and microcap risk review saved.');
        } elseif ($action === 'run_score') {
            $shariaPass = $app->multibagger->currentShariaPass($companyId, $period);
            $factorRows = $app->multibagger->factorReviews($companyId, $period);
            $valuationRow = $app->multibagger->valuationReview($companyId, $period);
            $riskRow = $app->multibagger->riskReview($companyId, $period);
            $readiness = $readinessService->assess($methodology, $shariaPass, $factorRows, $valuationRow, $riskRow);
            if (!$readiness['ready']) {
                throw new InvalidArgumentException('Scoring is blocked: ' . (string) ($readiness['blockers'][0] ?? 'evidence is incomplete.'));
            }

            $result = (new MultibaggerScoringEngine(new DecimalMath()))->score(
                $methodology,
                $shariaPass,
                $factorRows,
                $valuationRow,
                $riskRow,
            );
            if ($result->status !== 'scored' || $result->finalScore === null || $shariaPass === null) {
                throw new InvalidArgumentException('The evidence set did not produce a complete score. No immutable score was recorded.');
            }

            $scoreId = $app->multibagger->recordScore(
                $companyId,
                $methodology,
                (int) $shariaPass['id'],
                $period,
                $result,
                $user->id,
            );
            $app->logger->info('Multibagger score recorded.', [
                'score_id' => $scoreId,
                'company_id' => $companyId,
                'status' => $result->status,
                'final_score' => $result->finalScore,
                'alert_eligible' => $result->alertEligible,
                'user_id' => $user->id,
            ]);
            $app->session->flash('success', 'Score ' . $result->finalScore . ' / 10 recorded.');
        } else {
            throw new InvalidArgumentException('Invalid multibagger review action.');
        }
    } catch (InvalidArgumentException $exception) {
        $app->session->flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        $app->logger->error('Multibagger action failed.', [
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

$methodology = $app->multibagger->activeMethodology();
$periods = $app->multibagger->periods($companyId);
$selectedPeriod = Request::queryString('period');
if (!$validDate($selectedPeriod)) {
    $selectedPeriod = $periods[0] ?? (new DateTimeImmutable('now'))->format('Y-m-d');
}
$shariaPass = $app->multibagger->currentShariaPass($companyId, $selectedPeriod);
$factors = $app->multibagger->factorReviews($companyId, $selectedPeriod);
$valuation = $app->multibagger->valuationReview($companyId, $selectedPeriod);
$risk = $app->multibagger->riskReview($companyId, $selectedPeriod);
$readiness = $readinessService->assess($methodology, $shariaPass, $factors, $valuation, $risk);
$documents = $app->multibagger->documentsForCompany($companyId);
$governmentTailwinds = $app->government->approvedTailwinds();
$history = $app->multibagger->scoreHistory($companyId);
$selectedRed = $decodeFlags($risk['red_flags'] ?? []);
$selectedGreen = $decodeFlags($risk['green_flags'] ?? []);
$factorNoteMinimum = $methodology === null ? 20 : max(20, (int) ($methodology->definition['review_scope']['factor_evidence_note_min_chars'] ?? 20));
$valuationNoteMinimum = $methodology === null ? 40 : max(20, (int) ($methodology->definition['review_scope']['valuation_assumptions_note_min_chars'] ?? 40));

Page::begin(
    (string) $company['symbol'] . ' potential review',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'multibagger',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div>
        <p class="eyebrow"><?= Page::escape($company['exchange']) ?> · <?= Page::escape($company['symbol']) ?></p>
        <h1><?= Page::escape($company['company_name']) ?></h1>
        <p class="muted">Factor-level evidence, conservative dual valuation, microcap risks, and immutable score history.</p>
    </div>
    <a class="button button-secondary" href="/multibagger.php">Back to queue</a>
</div>

<?php Page::flash($app->session->consumeFlash()); ?>

<section class="policy-banner">
    <div>
        <p class="eyebrow">Reporting period</p>
        <form class="period-picker" method="get">
            <input type="hidden" name="id" value="<?= Page::escape($companyId) ?>">
            <select name="period" aria-label="Reporting period">
                <?php if ($periods === []): ?><option value="<?= Page::escape($selectedPeriod) ?>"><?= Page::escape($selectedPeriod) ?></option><?php endif; ?>
                <?php foreach ($periods as $period): ?><option value="<?= Page::escape($period) ?>" <?= $selectedPeriod === $period ? 'selected' : '' ?>><?= Page::escape($period) ?></option><?php endforeach; ?>
            </select>
            <button class="button button-secondary button-small" type="submit">Load</button>
        </form>
    </div>
    <div class="policy-meta">
        <?php if ($shariaPass !== null): ?><span class="status status-passed">Current Sharia pass</span><?php else: ?><span class="status status-insufficient">No same-period Sharia pass</span><?php endif; ?>
        <?php if ($methodology !== null): ?><span class="mono"><?= Page::escape($methodology->version) ?> · <?= Page::escape(count($factors)) ?>/<?= Page::escape(count($methodology->factors())) ?> factors</span><?php endif; ?>
    </div>
</section>

<section class="panel readiness-panel">
    <div class="panel-heading">
        <div><p class="eyebrow">Immutable-score gate</p><h2>Evidence readiness · <?= Page::escape($selectedPeriod) ?></h2></div>
        <span class="status status-<?= $readiness['ready'] ? 'accepted' : 'manual_review' ?>"><?= $readiness['ready'] ? 'Ready' : 'Blocked' ?></span>
    </div>
    <div class="sync-grid">
        <div><span>Sharia pass</span><strong><?= $readiness['sharia_ready'] ? 'Ready' : 'Missing' ?></strong></div>
        <div><span>Factors</span><strong><?= Page::escape(count($readiness['completed_factor_keys'])) ?> / <?= Page::escape(count($readiness['required_factor_keys'])) ?></strong></div>
        <div><span>Valuation</span><strong><?= $readiness['valuation_ready'] ? 'Ready' : 'Missing' ?></strong></div>
        <div><span>Risk set</span><strong><?= $readiness['risk_ready'] ? 'Ready' : 'Missing' ?></strong></div>
    </div>
    <?php if ($readiness['blockers'] !== []): ?>
        <div class="notice-card notice-error"><strong>Blocking items</strong><ul class="reason-list"><?php foreach ($readiness['blockers'] as $blocker): ?><li><?= Page::escape($blocker) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($readiness['warnings'] !== []): ?>
        <div class="notice-card"><strong>Research warnings</strong><ul class="reason-list"><?php foreach ($readiness['warnings'] as $warning): ?><li><?= Page::escape($warning) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
</section>

<?php if ($methodology === null): ?>
    <section class="notice-card notice-error policy-gate"><strong>Methodology gate closed</strong><p>Activate an independently reviewed local methodology before entering evidence or calculating scores.</p></section>
<?php else: ?>
    <section class="panel methodology-guide">
        <div class="panel-heading"><div><p class="eyebrow">Active methodology</p><h2>Evidence and grade guide</h2></div><span class="mono">SHA <?= Page::escape(substr($methodology->methodologyHash, 0, 12)) ?>…</span></div>
        <div class="factor-grid">
            <?php foreach ($methodology->factors() as $factor): ?>
                <article class="factor-card">
                    <div><strong><?= Page::escape($factor['label']) ?></strong><small><?= Page::escape($factor['weight_percent']) ?>% · <?= Page::escape($factor['key']) ?></small></div>
                    <p><?= Page::escape($factor['description']) ?></p>
                    <small class="muted">Anchor 1: <?= Page::escape($factor['grade_anchors']['1']) ?></small>
                    <small class="muted">Anchor 4: <?= Page::escape($factor['grade_anchors']['4']) ?></small>
                    <small class="muted">Anchor 7: <?= Page::escape($factor['grade_anchors']['7']) ?></small>
                    <small class="muted">Anchor 10: <?= Page::escape($factor['grade_anchors']['10']) ?></small>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="review-grid">
        <article class="panel">
            <div class="panel-heading"><div><p class="eyebrow">100% weighted scorecard</p><h2>Factor review</h2></div><span class="status"><?= Page::escape(count($factors)) ?> / <?= Page::escape(count($methodology->factors())) ?></span></div>
            <form class="stacked-form form-section" method="post">
                <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
                <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
                <input type="hidden" name="period_end" value="<?= Page::escape($selectedPeriod) ?>">
                <input type="hidden" name="action" value="save_factor">
                <label for="factor_key">Factor</label>
                <select id="factor_key" name="factor_key" required><?php foreach ($methodology->factors() as $factor): ?><option value="<?= Page::escape($factor['key']) ?>"><?= Page::escape($factor['label']) ?> · <?= Page::escape($factor['weight_percent']) ?>%</option><?php endforeach; ?></select>
                <label for="grade">Grade (1 strongest, 10 weakest)</label>
                <select id="grade" name="grade" required><?php for ($grade = 1; $grade <= 10; $grade++): ?><option value="<?= $grade ?>"><?= $grade ?></option><?php endfor; ?></select>
                <label for="evidence_note">Evidence and calculation note</label>
                <textarea id="evidence_note" name="evidence_note" minlength="<?= Page::escape($factorNoteMinimum) ?>" maxlength="1000" required></textarea>
                <small>Minimum <?= Page::escape($factorNoteMinimum) ?> characters. Explain the data, period, calculation, comparison, and why the selected grade matches the methodology anchor.</small>
                <label for="evidence_source_url">Official source URL (non-macro factors)</label>
                <input id="evidence_source_url" name="evidence_source_url" type="url" maxlength="1000">
                <label for="source_document_id">Stored filing PDF (non-macro factors)</label>
                <select id="source_document_id" name="source_document_id"><option value="">No linked PDF</option><?php foreach ($documents as $document): ?><option value="<?= Page::escape($document['id']) ?>">#<?= Page::escape($document['id']) ?> · <?= Page::escape($document['announced_at']) ?> · <?= Page::escape($document['subject']) ?></option><?php endforeach; ?></select>
                <label for="government_tailwind_review_id">Approved government evidence (required for macro tailwind)</label>
                <select id="government_tailwind_review_id" name="government_tailwind_review_id"><option value="">No government review selected</option><?php foreach ($governmentTailwinds as $tailwind): ?><option value="<?= Page::escape($tailwind['id']) ?>"><?= Page::escape($tailwind['source']) ?> · <?= Page::escape($tailwind['sector']) ?> · <?= Page::escape($tailwind['published_at']) ?> · <?= Page::escape($tailwind['title']) ?></option><?php endforeach; ?></select>
                <small>Approve evidence first in <a href="/government.php?status=pending">Tailwinds</a>. The official URL is copied from the reviewed record.</small>
                <button class="button button-primary" type="submit">Save factor review</button>
            </form>
        </article>

        <article class="panel">
            <div class="panel-heading"><div><p class="eyebrow">Dual-method gate</p><h2>Valuation evidence</h2></div><?php if ($valuation !== null): ?><span class="status status-passed">Reviewed</span><?php endif; ?></div>
            <form class="stacked-form form-section" method="post">
                <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
                <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
                <input type="hidden" name="period_end" value="<?= Page::escape($selectedPeriod) ?>">
                <input type="hidden" name="action" value="save_valuation">
                <div class="form-row">
                    <div><label for="eps">EPS</label><input id="eps" name="eps" value="<?= Page::escape($valuation['eps'] ?? '') ?>" required></div>
                    <div><label for="book_value_per_share">Book value/share</label><input id="book_value_per_share" name="book_value_per_share" value="<?= Page::escape($valuation['book_value_per_share'] ?? '') ?>" required></div>
                    <div><label for="currency">Currency</label><input id="currency" name="currency" value="<?= Page::escape($valuation['currency'] ?? 'INR') ?>" maxlength="3" required></div>
                </div>
                <div class="form-row">
                    <div><label for="dcf_value_per_share">DCF value/share</label><input id="dcf_value_per_share" name="dcf_value_per_share" value="<?= Page::escape($valuation['dcf_value_per_share'] ?? '') ?>" required></div>
                    <div><label for="current_price">Current price</label><input id="current_price" name="current_price" value="<?= Page::escape($valuation['current_price'] ?? '') ?>" required></div>
                </div>
                <label for="dcf_assumptions_note">DCF assumptions</label>
                <textarea id="dcf_assumptions_note" name="dcf_assumptions_note" minlength="<?= Page::escape($valuationNoteMinimum) ?>" maxlength="1000" required><?= Page::escape($valuation['dcf_assumptions_note'] ?? '') ?></textarea>
                <small>Minimum <?= Page::escape($valuationNoteMinimum) ?> characters. Include forecast period, base free cash flow, growth, discount rate, terminal growth, net debt, diluted shares, and margin of safety.</small>
                <label for="valuation_evidence_note">Valuation evidence note</label>
                <textarea id="valuation_evidence_note" name="valuation_evidence_note" minlength="<?= Page::escape($factorNoteMinimum) ?>" maxlength="1000" required><?= Page::escape($valuation['evidence_note'] ?? '') ?></textarea>
                <label for="valuation_evidence_source_url">Official price/evidence URL</label>
                <input id="valuation_evidence_source_url" name="valuation_evidence_source_url" type="url" maxlength="1000" value="<?= Page::escape($valuation['evidence_source_url'] ?? '') ?>" required>
                <label for="valuation_document_id">Financial statement PDF (optional)</label>
                <select id="valuation_document_id" name="valuation_document_id"><option value="">No linked PDF</option><?php foreach ($documents as $document): ?><option value="<?= Page::escape($document['id']) ?>" <?= (int) ($valuation['source_document_id'] ?? 0) === (int) $document['id'] ? 'selected' : '' ?>>#<?= Page::escape($document['id']) ?> · <?= Page::escape($document['subject']) ?></option><?php endforeach; ?></select>
                <button class="button button-primary" type="submit">Save valuation review</button>
            </form>
        </article>
    </section>

    <section class="panel panel-results">
        <div class="panel-heading"><div><p class="eyebrow">Microcap-aware</p><h2>Market cap and risk flags</h2></div><?php if ($risk !== null): ?><span class="status status-passed">Reviewed</span><?php endif; ?></div>
        <form class="stacked-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
            <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
            <input type="hidden" name="period_end" value="<?= Page::escape($selectedPeriod) ?>">
            <input type="hidden" name="action" value="save_risk">
            <div class="form-row">
                <div><label for="market_cap_crore">Market cap (₹ crore)</label><input id="market_cap_crore" name="market_cap_crore" value="<?= Page::escape($risk['market_cap_crore'] ?? '') ?>" required></div>
                <div><label for="risk_evidence_source_url">Official source URL</label><input id="risk_evidence_source_url" name="risk_evidence_source_url" type="url" maxlength="1000" value="<?= Page::escape($risk['evidence_source_url'] ?? '') ?>" required></div>
            </div>
            <div class="flag-grid">
                <fieldset><legend>Red flags (+<?= Page::escape($methodology->definition['microcap_adjustments']['red_flag_points']) ?> each below ₹<?= Page::escape($methodology->definition['microcap_adjustments']['applies_below_crore']) ?> crore)</legend><?php foreach ($methodology->definition['microcap_adjustments']['red_flags'] as $flag): ?><label class="check-row"><input type="checkbox" name="red_flags[]" value="<?= Page::escape($flag['key']) ?>" <?= in_array($flag['key'], $selectedRed, true) ? 'checked' : '' ?>> <span><?= Page::escape($flag['label']) ?></span></label><?php endforeach; ?></fieldset>
                <fieldset><legend>Green flags (−<?= Page::escape($methodology->definition['microcap_adjustments']['green_flag_points']) ?> each below ₹<?= Page::escape($methodology->definition['microcap_adjustments']['applies_below_crore']) ?> crore)</legend><?php foreach ($methodology->definition['microcap_adjustments']['green_flags'] as $flag): ?><label class="check-row"><input type="checkbox" name="green_flags[]" value="<?= Page::escape($flag['key']) ?>" <?= in_array($flag['key'], $selectedGreen, true) ? 'checked' : '' ?>> <span><?= Page::escape($flag['label']) ?></span></label><?php endforeach; ?></fieldset>
            </div>
            <label for="risk_evidence_note">Risk review evidence</label>
            <textarea id="risk_evidence_note" name="risk_evidence_note" minlength="<?= Page::escape($factorNoteMinimum) ?>" maxlength="1000" required><?= Page::escape($risk['evidence_note'] ?? '') ?></textarea>
            <button class="button button-primary" type="submit">Save risk review</button>
        </form>
    </section>

    <section class="panel panel-results">
        <div class="panel-heading"><div><p class="eyebrow">Current factor set</p><h2>Reviewed factors</h2></div><span class="status"><?= Page::escape(count($factors)) ?> / <?= Page::escape(count($methodology->factors())) ?></span></div>
        <div class="factor-grid">
            <?php foreach ($methodology->factors() as $factor): $review = $factors[$factor['key']] ?? null; ?>
                <article class="factor-card">
                    <div><strong><?= Page::escape($factor['label']) ?></strong><small><?= Page::escape($factor['weight_percent']) ?>% weight</small></div>
                    <?php if ($review === null): ?>
                        <span class="status status-insufficient">Missing</span>
                    <?php else: ?>
                        <span class="score-pill"><?= Page::escape($review['grade']) ?></span>
                        <p><?= Page::escape($review['evidence_note']) ?></p>
                        <?php if ($factor['key'] === 'macro_tailwind'): ?><small><?= Page::escape($review['government_source'] ?? 'No current source') ?> · <?= Page::escape($review['government_review_sector'] ?? 'review required') ?> · <?= Page::escape($review['government_review_status'] ?? 'invalid') ?></small><?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <form class="screening-action" method="post">
            <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
            <input type="hidden" name="company_id" value="<?= Page::escape($companyId) ?>">
            <input type="hidden" name="period_end" value="<?= Page::escape($selectedPeriod) ?>">
            <input type="hidden" name="action" value="run_score">
            <p>Only a complete ready evidence set can create an immutable score.</p>
            <button class="button button-primary" type="submit" <?= $readiness['ready'] ? '' : 'disabled aria-disabled="true"' ?>><?= $readiness['ready'] ? 'Calculate immutable score' : 'Complete blocking items' ?></button>
        </form>
    </section>
<?php endif; ?>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Audit trail</p><h2>Score history</h2></div><span class="status"><?= Page::escape(count($history)) ?> records</span></div>
    <?php if ($history === []): ?>
        <div class="empty-state compact"><p>No scores recorded yet.</p></div>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Score</th><th>Period</th><th>Methodology</th><th>Valuation</th><th>Alert gate</th><th>Recorded</th></tr></thead><tbody>
        <?php foreach ($history as $score): ?><tr><td><?= Page::escape($score['final_score'] === null ? 'Insufficient' : $score['final_score'] . ' / 10') ?><small><?= Page::escape($score['market_cap_category']) ?></small></td><td><?= Page::escape($score['period_end']) ?></td><td><?= Page::escape($score['methodology_version']) ?></td><td><?= (int) $score['undervalued_by_both'] === 1 ? '<span class="status status-passed">Both agree</span>' : 'No dual agreement' ?></td><td><?= (int) $score['alert_eligible'] === 1 ? '<span class="status status-passed">Eligible</span>' : '—' ?></td><td><?= Page::escape($score['computed_by_name']) ?><small><?= Page::escape($score['computed_at']) ?></small></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>

<section class="notice-card"><strong>Personal research only</strong><p>A low score is not a forecast, recommendation, or promise of return. Verify filings, assumptions, liquidity, valuation, governance, and personal risk suitability independently.</p></section>
<?php Page::end(); ?>
