<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use HalalPulse\Sharia\DecimalMath;
use Throwable;

final readonly class MultibaggerScoringEngine
{
    public function __construct(private DecimalMath $math)
    {
    }

    /** @param array<string, array<string, mixed>> $factorRows @param array<string, mixed>|null $valuationRow @param array<string, mixed>|null $riskRow */
    public function score(MultibaggerMethodology $methodology, ?array $shariaPass, array $factorRows, ?array $valuationRow, ?array $riskRow): MultibaggerScoringResult
    {
        if (!$methodology->isActive) {
            return $this->insufficient(['No active multibagger methodology is available.']);
        }
        if ($shariaPass === null || ($shariaPass['status'] ?? null) !== 'passed') {
            return $this->insufficient(['A current Sharia pass under the active Sharia policy is required.']);
        }

        $factorEvidenceMinimum = (int) ($methodology->definition['review_scope']['factor_evidence_note_min_chars'] ?? 20);
        $factorResults = [];
        $reasons = [];
        $weightedSum = 0;
        foreach ($methodology->factors() as $factor) {
            $row = $factorRows[$factor['key']] ?? null;
            $grade = is_array($row) ? ($row['grade'] ?? null) : null;
            if (!is_int($grade) && !(is_string($grade) && ctype_digit($grade))) {
                if ($factor['required']) {
                    $reasons[] = "Required factor review is missing for {$factor['label']}.";
                }
                continue;
            }
            $grade = (int) $grade;
            if ($grade < 1 || $grade > 10) {
                $reasons[] = "Factor grade is invalid for {$factor['label']}.";
                continue;
            }
            $evidenceNote = trim((string) ($row['evidence_note'] ?? ''));
            $evidenceUrl = (string) ($row['evidence_source_url'] ?? '');
            $documentId = isset($row['source_document_id']) ? (int) $row['source_document_id'] : null;
            $governmentReviewId = isset($row['government_tailwind_review_id']) ? (int) $row['government_tailwind_review_id'] : null;
            $governmentReviewStatus = (string) ($row['government_review_status'] ?? '');
            $governmentReviewImpact = (string) ($row['government_review_impact'] ?? '');
            $macroEvidenceInvalid = $factor['key'] === 'macro_tailwind' && (
                $evidenceUrl === ''
                || !ResearchEvidenceUrl::isAllowed($evidenceUrl, true)
                || $governmentReviewId === null
                || $governmentReviewStatus !== 'current'
                || !in_array($governmentReviewImpact, ['strong_tailwind', 'moderate_tailwind'], true)
            );
            $generalEvidenceInvalid = $factor['key'] !== 'macro_tailwind' && (
                $documentId === null && $evidenceUrl === ''
                || $evidenceUrl !== '' && !ResearchEvidenceUrl::isAllowed($evidenceUrl)
            );
            if (mb_strlen($evidenceNote) < $factorEvidenceMinimum || $macroEvidenceInvalid || $generalEvidenceInvalid) {
                $reasons[] = "Official evidence is invalid or missing for {$factor['label']}.";
                continue;
            }
            $weightedSum += $grade * (int) $factor['weight_percent'];
            $factorResults[] = [
                'key' => $factor['key'],
                'label' => $factor['label'],
                'grade' => $grade,
                'weight_percent' => (int) $factor['weight_percent'],
                'evidence_note' => $evidenceNote,
                'evidence_source_url' => $evidenceUrl === '' ? null : $evidenceUrl,
                'source_document_id' => $documentId,
                'government_tailwind_review_id' => $governmentReviewId,
            ];
        }

        $requiredCount = count(array_filter($methodology->factors(), static fn (array $factor): bool => $factor['required']));
        $completedRequired = count(array_filter($factorResults, static function (array $result) use ($methodology): bool {
            foreach ($methodology->factors() as $factor) {
                if ($factor['key'] === $result['key']) {
                    return $factor['required'];
                }
            }

            return false;
        }));
        if ($completedRequired !== $requiredCount) {
            return new MultibaggerScoringResult('insufficient', null, null, false, false, 'unknown', $factorResults, $reasons, [], []);
        }

        $valuation = $this->valuation($methodology, $valuationRow, $reasons);
        if ($valuation === null && $methodology->definition['valuation_required']) {
            return new MultibaggerScoringResult('insufficient', null, null, false, false, 'unknown', $factorResults, $reasons, [], []);
        }

        $risk = $this->risk($methodology, $riskRow, $reasons);
        if ($risk === null) {
            return new MultibaggerScoringResult('insufficient', null, null, (bool) ($valuation['undervalued_by_both'] ?? false), false, 'unknown', $factorResults, $reasons, $valuation ?? [], []);
        }

        $weightedScore = $this->math->divide((string) $weightedSum, '100');
        $rounded = intdiv($weightedSum + 50, 100);
        $adjustment = (int) $risk['score_adjustment'];
        $finalScore = max(1, min(10, $rounded + $adjustment));
        $alertMaximum = (int) $methodology->definition['alert_max_score'];

        return new MultibaggerScoringResult(
            status: 'scored',
            finalScore: $finalScore,
            weightedScore: $this->math->normalize($weightedScore),
            undervaluedByBoth: (bool) ($valuation['undervalued_by_both'] ?? false),
            alertEligible: $finalScore <= $alertMaximum,
            marketCapCategory: (string) $risk['market_cap_category'],
            factorResults: $factorResults,
            reasons: ["Score {$finalScore} recorded on a 1-best to 10-weakest scale; it is research output, not a return forecast."],
            valuationSnapshot: $valuation ?? [],
            riskSnapshot: $risk,
        );
    }

    /** @param list<string> $reasons @param array<string, mixed>|null $row @return array<string, mixed>|null */
    private function valuation(MultibaggerMethodology $methodology, ?array $row, array &$reasons): ?array
    {
        if ($row === null) {
            $reasons[] = 'A reviewed Graham and DCF valuation set is required.';
            return null;
        }
        try {
            $eps = (string) ($row['eps'] ?? '');
            $bookValue = (string) ($row['book_value_per_share'] ?? '');
            $dcfValue = (string) ($row['dcf_value_per_share'] ?? '');
            $currentPrice = (string) ($row['current_price'] ?? '');
            $currency = (string) ($row['currency'] ?? '');
            $assumptions = trim((string) ($row['dcf_assumptions_note'] ?? ''));
            $evidenceNote = trim((string) ($row['evidence_note'] ?? ''));
            $evidenceUrl = (string) ($row['evidence_source_url'] ?? '');
            foreach ([$eps, $bookValue, $dcfValue, $currentPrice] as $value) {
                if (!DecimalMath::isDecimal($value)) {
                    throw new \InvalidArgumentException();
                }
            }
            if ($this->math->compare($eps, '0') <= 0 || $this->math->compare($bookValue, '0') <= 0 || $this->math->compare($dcfValue, '0') <= 0 || $this->math->compare($currentPrice, '0') <= 0) {
                throw new \InvalidArgumentException();
            }
            $factorEvidenceMinimum = (int) ($methodology->definition['review_scope']['factor_evidence_note_min_chars'] ?? 20);
            $valuationAssumptionsMinimum = (int) ($methodology->definition['review_scope']['valuation_assumptions_note_min_chars'] ?? 40);
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1
                || mb_strlen($assumptions) < $valuationAssumptionsMinimum
                || mb_strlen($evidenceNote) < $factorEvidenceMinimum
                || !ResearchEvidenceUrl::isAllowed($evidenceUrl)
            ) {
                throw new \InvalidArgumentException();
            }
            $graham = $this->math->squareRoot($this->math->multiply($methodology->definition['graham_coefficient'], $this->math->multiply($eps, $bookValue)));
            $undervalued = $this->math->compare($currentPrice, $graham) <= 0 && $this->math->compare($currentPrice, $dcfValue) <= 0;

            return [
                'currency' => $currency,
                'eps' => $eps,
                'book_value_per_share' => $bookValue,
                'graham_number' => $this->math->normalize($graham),
                'dcf_value_per_share' => $dcfValue,
                'current_price' => $currentPrice,
                'undervalued_by_both' => $undervalued,
                'dcf_assumptions_note' => $assumptions,
                'evidence_note' => $evidenceNote,
                'evidence_source_url' => $evidenceUrl,
            ];
        } catch (Throwable) {
            $reasons[] = 'Valuation inputs are incomplete, non-positive, or invalid.';
            return null;
        }
    }

    /** @param list<string> $reasons @param array<string, mixed>|null $row @return array<string, mixed>|null */
    private function risk(MultibaggerMethodology $methodology, ?array $row, array &$reasons): ?array
    {
        if ($row === null) {
            $reasons[] = 'A reviewed market-cap and microcap risk set is required.';
            return null;
        }
        $marketCap = (string) ($row['market_cap_crore'] ?? '');
        if (!DecimalMath::isDecimal($marketCap) || $this->math->compare($marketCap, '0') <= 0) {
            $reasons[] = 'Market capitalization must be a positive crore value.';
            return null;
        }
        $evidenceNote = trim((string) ($row['evidence_note'] ?? ''));
        $evidenceUrl = (string) ($row['evidence_source_url'] ?? '');
        $factorEvidenceMinimum = (int) ($methodology->definition['review_scope']['factor_evidence_note_min_chars'] ?? 20);
        if (mb_strlen($evidenceNote) < $factorEvidenceMinimum || !ResearchEvidenceUrl::isAllowed($evidenceUrl)) {
            $reasons[] = 'Market-cap risk evidence is invalid or missing.';
            return null;
        }
        $redFlags = $this->selectedFlags($row['red_flags'] ?? [], $methodology->definition['microcap_adjustments']['red_flags']);
        $greenFlags = $this->selectedFlags($row['green_flags'] ?? [], $methodology->definition['microcap_adjustments']['green_flags']);
        $category = $this->marketCapCategory($marketCap, $methodology->definition['market_cap_bands_crore']);
        $microConfig = $methodology->definition['microcap_adjustments'];
        $isMicrocap = $this->math->compare($marketCap, $microConfig['applies_below_crore']) < 0;
        $adjustment = $isMicrocap
            ? count($redFlags) * (int) $microConfig['red_flag_points'] - count($greenFlags) * (int) $microConfig['green_flag_points']
            : 0;

        return [
            'market_cap_crore' => $marketCap,
            'market_cap_category' => $category,
            'microcap_adjustments_applied' => $isMicrocap,
            'red_flags' => $redFlags,
            'green_flags' => $greenFlags,
            'score_adjustment' => $adjustment,
            'evidence_note' => $evidenceNote,
            'evidence_source_url' => $evidenceUrl,
        ];
    }

    /** @param mixed $selected @param list<array{key: string, label: string}> $allowed @return list<string> */
    private function selectedFlags(mixed $selected, array $allowed): array
    {
        if (is_string($selected)) {
            $selected = json_decode($selected, true);
        }
        if (!is_array($selected)) {
            return [];
        }
        $allowedKeys = array_column($allowed, 'key');

        return array_values(array_unique(array_filter($selected, static fn (mixed $key): bool => is_string($key) && in_array($key, $allowedKeys, true))));
    }

    /** @param array<string, string> $bands */
    private function marketCapCategory(string $marketCap, array $bands): string
    {
        if ($this->math->compare($marketCap, $bands['large_min']) >= 0) return 'large';
        if ($this->math->compare($marketCap, $bands['mid_min']) >= 0) return 'mid';
        if ($this->math->compare($marketCap, $bands['small_min']) >= 0) return 'small';
        if ($this->math->compare($marketCap, $bands['micro_min']) >= 0) return 'micro';
        return 'nano';
    }

    /** @param list<string> $reasons */
    private function insufficient(array $reasons): MultibaggerScoringResult
    {
        return new MultibaggerScoringResult('insufficient', null, null, false, false, 'unknown', [], $reasons, [], []);
    }
}
