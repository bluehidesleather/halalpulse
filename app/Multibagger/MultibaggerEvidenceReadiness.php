<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use HalalPulse\Sharia\DecimalMath;
use Throwable;

final readonly class MultibaggerEvidenceReadiness
{
    public function __construct(private DecimalMath $math)
    {
    }

    /**
     * @param array<string, mixed>|null $shariaPass
     * @param array<string, array<string, mixed>> $factorRows
     * @param array<string, mixed>|null $valuationRow
     * @param array<string, mixed>|null $riskRow
     * @return array{
     *   ready: bool,
     *   required_factor_keys: list<string>,
     *   completed_factor_keys: list<string>,
     *   missing_factor_keys: list<string>,
     *   invalid_factor_keys: list<string>,
     *   sharia_ready: bool,
     *   valuation_ready: bool,
     *   risk_ready: bool,
     *   blockers: list<string>,
     *   warnings: list<string>
     * }
     */
    public function assess(
        ?MultibaggerMethodology $methodology,
        ?array $shariaPass,
        array $factorRows,
        ?array $valuationRow,
        ?array $riskRow,
    ): array {
        $result = [
            'ready' => false,
            'required_factor_keys' => [],
            'completed_factor_keys' => [],
            'missing_factor_keys' => [],
            'invalid_factor_keys' => [],
            'sharia_ready' => false,
            'valuation_ready' => false,
            'risk_ready' => false,
            'blockers' => [],
            'warnings' => [],
        ];

        if ($methodology === null || !$methodology->isActive) {
            $result['blockers'][] = 'Activate an independently reviewed multibagger methodology.';

            return $this->finish($result);
        }

        $result['required_factor_keys'] = $methodology->factorKeys();
        sort($result['required_factor_keys']);

        if ($shariaPass === null || ($shariaPass['status'] ?? null) !== 'passed') {
            $result['blockers'][] = 'A same-period Sharia pass under the active policy is required.';
        } else {
            $result['sharia_ready'] = true;
        }

        $factorNoteMinimum = max(20, (int) ($methodology->definition['review_scope']['factor_evidence_note_min_chars'] ?? 20));
        foreach ($methodology->factors() as $factor) {
            $key = (string) $factor['key'];
            $row = $factorRows[$key] ?? null;
            if (!is_array($row)) {
                $result['missing_factor_keys'][] = $key;
                $result['blockers'][] = "Required factor review is missing for {$factor['label']}.";
                continue;
            }

            $reason = $this->factorProblem($key, $row, $factorNoteMinimum);
            if ($reason !== null) {
                $result['invalid_factor_keys'][] = $key;
                $result['blockers'][] = "{$factor['label']}: {$reason}";
                continue;
            }

            $result['completed_factor_keys'][] = $key;
        }

        sort($result['completed_factor_keys']);
        sort($result['missing_factor_keys']);
        sort($result['invalid_factor_keys']);

        $valuationProblem = $this->valuationProblem($methodology, $valuationRow, $factorNoteMinimum);
        if ($valuationProblem === null) {
            $result['valuation_ready'] = true;
        } else {
            $result['blockers'][] = $valuationProblem;
        }

        $riskProblem = $this->riskProblem($methodology, $riskRow, $factorNoteMinimum);
        if ($riskProblem === null) {
            $result['risk_ready'] = true;
        } else {
            $result['blockers'][] = $riskProblem;
        }

        if ($result['valuation_ready'] && $valuationRow !== null) {
            try {
                $graham = $this->math->squareRoot($this->math->multiply(
                    (string) $methodology->definition['graham_coefficient'],
                    $this->math->multiply((string) $valuationRow['eps'], (string) $valuationRow['book_value_per_share']),
                ));
                $currentPrice = (string) $valuationRow['current_price'];
                $dcfValue = (string) $valuationRow['dcf_value_per_share'];
                if ($this->math->compare($currentPrice, $graham) > 0 || $this->math->compare($currentPrice, $dcfValue) > 0) {
                    $result['warnings'][] = 'Graham and DCF do not both support the current price; this does not block scoring but prevents an undervalued-by-both label.';
                }
            } catch (Throwable) {
                $result['blockers'][] = 'Valuation evidence cannot be normalized with exact decimal arithmetic.';
                $result['valuation_ready'] = false;
            }
        }

        return $this->finish($result);
    }

    /** @param array<string, mixed> $row */
    private function factorProblem(string $key, array $row, int $noteMinimum): ?string
    {
        $grade = $row['grade'] ?? null;
        if (!is_int($grade) && !(is_string($grade) && ctype_digit($grade))) {
            return 'grade is missing or invalid.';
        }
        if ((int) $grade < 1 || (int) $grade > 10) {
            return 'grade must be from 1 to 10.';
        }

        if (mb_strlen(trim((string) ($row['evidence_note'] ?? ''))) < $noteMinimum) {
            return "evidence note must contain at least {$noteMinimum} characters.";
        }

        $url = trim((string) ($row['evidence_source_url'] ?? ''));
        $documentId = isset($row['source_document_id']) && (int) $row['source_document_id'] > 0
            ? (int) $row['source_document_id']
            : null;

        if ($key === 'macro_tailwind') {
            if ($url === '' || !ResearchEvidenceUrl::isAllowed($url, true)) {
                return 'a current official government-source URL is required.';
            }
            if ((int) ($row['government_tailwind_review_id'] ?? 0) < 1) {
                return 'a human-approved government tailwind review is required.';
            }
            if (($row['government_review_status'] ?? null) !== 'current') {
                return 'the government tailwind review is no longer current.';
            }
            if (!in_array((string) ($row['government_review_impact'] ?? ''), ['strong_tailwind', 'moderate_tailwind'], true)) {
                return 'the government review must record a strong or moderate tailwind.';
            }

            return null;
        }

        if ($documentId === null && $url === '') {
            return 'link an official source URL or a stored company filing.';
        }
        if ($url !== '' && !ResearchEvidenceUrl::isAllowed($url)) {
            return 'the evidence URL is not an allowed official source.';
        }

        return null;
    }

    /** @param array<string, mixed>|null $row */
    private function valuationProblem(MultibaggerMethodology $methodology, ?array $row, int $noteMinimum): ?string
    {
        if ($row === null) {
            return 'A reviewed Graham and DCF valuation set is required.';
        }

        foreach (['eps', 'book_value_per_share', 'dcf_value_per_share', 'current_price'] as $field) {
            $value = (string) ($row[$field] ?? '');
            if (!DecimalMath::isDecimal($value) || $this->math->compare($value, '0') <= 0) {
                return "Valuation field {$field} must be a positive exact decimal.";
            }
        }

        if (preg_match('/^[A-Z]{3}$/D', (string) ($row['currency'] ?? '')) !== 1) {
            return 'Valuation currency must be a three-letter uppercase code.';
        }

        $assumptionMinimum = max(20, (int) ($methodology->definition['review_scope']['valuation_assumptions_note_min_chars'] ?? 40));
        if (mb_strlen(trim((string) ($row['dcf_assumptions_note'] ?? ''))) < $assumptionMinimum) {
            return "DCF assumptions must contain at least {$assumptionMinimum} characters.";
        }
        if (mb_strlen(trim((string) ($row['evidence_note'] ?? ''))) < $noteMinimum) {
            return "Valuation evidence note must contain at least {$noteMinimum} characters.";
        }
        if (!ResearchEvidenceUrl::isAllowed(trim((string) ($row['evidence_source_url'] ?? '')))) {
            return 'Valuation evidence must use an allowed official HTTPS source.';
        }

        return null;
    }

    /** @param array<string, mixed>|null $row */
    private function riskProblem(MultibaggerMethodology $methodology, ?array $row, int $noteMinimum): ?string
    {
        if ($row === null) {
            return 'A reviewed market-cap and microcap risk set is required.';
        }

        $marketCap = (string) ($row['market_cap_crore'] ?? '');
        if (!DecimalMath::isDecimal($marketCap) || $this->math->compare($marketCap, '0') <= 0) {
            return 'Market capitalization must be a positive exact crore value.';
        }
        if (mb_strlen(trim((string) ($row['evidence_note'] ?? ''))) < $noteMinimum) {
            return "Risk evidence note must contain at least {$noteMinimum} characters.";
        }
        if (!ResearchEvidenceUrl::isAllowed(trim((string) ($row['evidence_source_url'] ?? '')))) {
            return 'Risk evidence must use an allowed official HTTPS source.';
        }

        $allowedRed = array_column($methodology->definition['microcap_adjustments']['red_flags'], 'key');
        $allowedGreen = array_column($methodology->definition['microcap_adjustments']['green_flags'], 'key');
        if (!$this->flagsAreAllowed($row['red_flags'] ?? [], $allowedRed)) {
            return 'Risk review contains an unknown red flag.';
        }
        if (!$this->flagsAreAllowed($row['green_flags'] ?? [], $allowedGreen)) {
            return 'Risk review contains an unknown green flag.';
        }

        return null;
    }

    /** @param mixed $value @param list<string> $allowed */
    private function flagsAreAllowed(mixed $value, array $allowed): bool
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return false;
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function finish(array $result): array
    {
        $result['blockers'] = array_values(array_unique($result['blockers']));
        $result['warnings'] = array_values(array_unique($result['warnings']));
        $result['ready'] = $result['blockers'] === [];

        return $result;
    }
}
