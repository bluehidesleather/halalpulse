<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use DateTimeImmutable;
use HalalPulse\Sharia\DecimalMath;

final class MultibaggerMethodologyReadinessInspector
{
    /**
     * @param array<string, mixed> $input
     * @return array{ready: bool, errors: list<string>, warnings: list<string>}
     */
    public function inspect(array $input, bool $requireApproval = true): array
    {
        $errors = [];
        $warnings = [];

        foreach (['version', 'name', 'effective_date', 'verified_by', 'verification_note'] as $key) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[] = "{$key} is required.";
                continue;
            }
            if ($this->containsPlaceholder($value)) {
                $errors[] = "{$key} still contains a placeholder.";
            }
        }

        $effectiveDate = is_string($input['effective_date'] ?? null) ? trim((string) $input['effective_date']) : '';
        if ($effectiveDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
            if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
                $errors[] = 'effective_date must use the YYYY-MM-DD format.';
            }
        }

        $verifiedBy = is_string($input['verified_by'] ?? null) ? trim((string) $input['verified_by']) : '';
        $verificationNote = is_string($input['verification_note'] ?? null) ? trim((string) $input['verification_note']) : '';
        if ($verifiedBy !== '' && mb_strlen($verifiedBy) < 3) {
            $errors[] = 'verified_by must identify the reviewer.';
        }
        if ($verificationNote !== '' && mb_strlen($verificationNote) < 80) {
            $errors[] = 'verification_note must document factor definitions, grade anchors, weights, valuation assumptions, market-cap bands, and risk adjustments.';
        }

        if ($requireApproval && ($input['approved_for_use'] ?? null) !== true) {
            $errors[] = 'approved_for_use must be true only after the methodology review is complete.';
        }
        if (($input['grade_direction'] ?? null) !== '1_best_10_weakest') {
            $errors[] = 'grade_direction must remain 1_best_10_weakest.';
        }
        if (($input['alert_max_score'] ?? null) !== 4) {
            $errors[] = 'alert_max_score must remain 4.';
        }
        if (($input['valuation_required'] ?? null) !== true) {
            $errors[] = 'valuation_required must remain true.';
        }

        $coefficient = $input['graham_coefficient'] ?? null;
        if (!is_string($coefficient) || !DecimalMath::isDecimal($coefficient) || preg_match('/[1-9]/', $coefficient) !== 1) {
            $errors[] = 'graham_coefficient must be a positive exact decimal string.';
        }

        $reviewScope = $input['review_scope'] ?? null;
        if (!is_array($reviewScope)) {
            $errors[] = 'review_scope must be an object.';
        } else {
            $historyYears = $reviewScope['minimum_history_years'] ?? null;
            $factorNoteMinimum = $reviewScope['factor_evidence_note_min_chars'] ?? null;
            $valuationNoteMinimum = $reviewScope['valuation_assumptions_note_min_chars'] ?? null;
            if (!is_int($historyYears) || $historyYears < 1 || $historyYears > 10) {
                $errors[] = 'review_scope.minimum_history_years must be an integer from 1 to 10.';
            }
            if (!is_int($factorNoteMinimum) || $factorNoteMinimum < 20 || $factorNoteMinimum > 500) {
                $errors[] = 'review_scope.factor_evidence_note_min_chars must be an integer from 20 to 500.';
            }
            if (!is_int($valuationNoteMinimum) || $valuationNoteMinimum < 40 || $valuationNoteMinimum > 1000) {
                $errors[] = 'review_scope.valuation_assumptions_note_min_chars must be an integer from 40 to 1000.';
            }
            foreach (['official_sources_only', 'same_period_sharia_pass_required'] as $key) {
                if (($reviewScope[$key] ?? null) !== true) {
                    $errors[] = "review_scope.{$key} must be true.";
                }
            }
            if (($reviewScope['media_sources_allowed'] ?? null) !== false) {
                $errors[] = 'review_scope.media_sources_allowed must be false.';
            }
        }

        $factors = $input['factors'] ?? null;
        if (!is_array($factors) || !array_is_list($factors) || $factors === []) {
            $errors[] = 'factors must be a non-empty list.';
        } else {
            $seen = [];
            $weightTotal = 0;
            foreach ($factors as $index => $factor) {
                if (!is_array($factor)) {
                    $errors[] = "Factor {$index} must be an object.";
                    continue;
                }
                $key = is_string($factor['key'] ?? null) ? trim((string) $factor['key']) : '';
                if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $key) !== 1) {
                    $errors[] = "Factor {$index} key must be a lowercase machine key.";
                } elseif (isset($seen[$key])) {
                    $errors[] = "Factor key {$key} is duplicated.";
                } else {
                    $seen[$key] = true;
                }

                foreach (['label', 'description'] as $field) {
                    $value = $factor[$field] ?? null;
                    if (!is_string($value) || mb_strlen(trim($value)) < 10 || $this->containsPlaceholder((string) $value)) {
                        $errors[] = "Factor {$index} {$field} must be specific and placeholder-free.";
                    }
                }

                $weight = $factor['weight_percent'] ?? null;
                if (!is_int($weight) || $weight < 1 || $weight > 100) {
                    $errors[] = "Factor {$key} weight_percent must be an integer from 1 to 100.";
                } else {
                    $weightTotal += $weight;
                }
                if (($factor['required'] ?? null) !== true) {
                    $errors[] = "Factor {$key} must be required.";
                }

                $requirements = $factor['evidence_requirements'] ?? null;
                if (!is_array($requirements) || !array_is_list($requirements) || count($requirements) < 2) {
                    $errors[] = "Factor {$key} must define at least two evidence_requirements.";
                } else {
                    foreach ($requirements as $requirementIndex => $requirement) {
                        if (!is_string($requirement) || mb_strlen(trim($requirement)) < 20 || $this->containsPlaceholder($requirement)) {
                            $errors[] = "Factor {$key} evidence requirement {$requirementIndex} must be specific and placeholder-free.";
                        }
                    }
                }

                $anchors = $factor['grade_anchors'] ?? null;
                if (!is_array($anchors)) {
                    $errors[] = "Factor {$key} grade_anchors must be an object.";
                } else {
                    foreach (['1', '4', '7', '10'] as $anchor) {
                        $text = $anchors[$anchor] ?? null;
                        if (!is_string($text) || mb_strlen(trim($text)) < 20 || $this->containsPlaceholder($text)) {
                            $errors[] = "Factor {$key} grade anchor {$anchor} must be specific and placeholder-free.";
                        }
                    }
                }
            }
            if ($weightTotal !== 100) {
                $errors[] = 'Factor weights must total exactly 100 percent.';
            }
            if (count($factors) !== 12) {
                $warnings[] = 'The locked production scorecard currently expects twelve factors; confirm any deliberate factor-count change.';
            }
        }

        $valuationPolicy = $input['valuation_policy'] ?? null;
        if (!is_array($valuationPolicy)) {
            $errors[] = 'valuation_policy must be an object.';
        } else {
            foreach (['graham_basis_note', 'dcf_review_note'] as $field) {
                $value = $valuationPolicy[$field] ?? null;
                if (!is_string($value) || mb_strlen(trim($value)) < 40 || $this->containsPlaceholder($value)) {
                    $errors[] = "valuation_policy.{$field} must be specific and placeholder-free.";
                }
            }
            $assumptions = $valuationPolicy['dcf_required_assumptions'] ?? null;
            if (!is_array($assumptions) || !array_is_list($assumptions)) {
                $errors[] = 'valuation_policy.dcf_required_assumptions must be a list.';
            } else {
                $requiredAssumptions = [
                    'forecast_period_years',
                    'base_free_cash_flow',
                    'growth_rates',
                    'discount_rate',
                    'terminal_growth_rate',
                    'net_debt',
                    'diluted_shares',
                    'margin_of_safety',
                ];
                $normalized = [];
                foreach ($assumptions as $assumption) {
                    if (!is_string($assumption) || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $assumption) !== 1) {
                        $errors[] = 'Every DCF required assumption must be a lowercase machine key.';
                        continue;
                    }
                    $normalized[$assumption] = true;
                }
                foreach ($requiredAssumptions as $assumption) {
                    if (!isset($normalized[$assumption])) {
                        $errors[] = "DCF required assumption {$assumption} is missing.";
                    }
                }
            }
        }

        foreach (['market_cap_band_note', 'microcap_adjustment_note'] as $field) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || mb_strlen(trim($value)) < 40 || $this->containsPlaceholder($value)) {
                $errors[] = "{$field} must document the reviewed rationale and be placeholder-free.";
            }
        }

        return [
            'ready' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function containsPlaceholder(string $value): bool
    {
        return stripos($value, 'REPLACE') !== false
            || stripos($value, 'TODO') !== false
            || stripos($value, 'TBD') !== false;
    }
}
