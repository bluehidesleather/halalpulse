<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use HalalPulse\Sharia\DecimalMath;
use InvalidArgumentException;

final class MultibaggerMethodologyValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(array $input, bool $requireApproval = true): array
    {
        $readiness = (new MultibaggerMethodologyReadinessInspector())->inspect($input, $requireApproval);
        if ($readiness['errors'] !== []) {
            throw new InvalidArgumentException($readiness['errors'][0]);
        }

        $version = $this->text($input, 'version', 64);
        $name = $this->text($input, 'name', 191);
        $effectiveDate = $this->text($input, 'effective_date', 10);
        $verifiedBy = $this->text($input, 'verified_by', 191);
        $verificationNote = $this->text($input, 'verification_note', 1000);
        $approved = ($input['approved_for_use'] ?? null) === true;

        $coefficient = (string) $input['graham_coefficient'];
        $reviewScopeInput = $this->object($input['review_scope'] ?? null, 'review_scope');
        $reviewScope = [
            'minimum_history_years' => (int) $reviewScopeInput['minimum_history_years'],
            'factor_evidence_note_min_chars' => (int) $reviewScopeInput['factor_evidence_note_min_chars'],
            'valuation_assumptions_note_min_chars' => (int) $reviewScopeInput['valuation_assumptions_note_min_chars'],
            'official_sources_only' => true,
            'same_period_sharia_pass_required' => true,
            'media_sources_allowed' => false,
        ];

        $factorsInput = $input['factors'];
        if (!is_array($factorsInput) || !array_is_list($factorsInput)) {
            throw new InvalidArgumentException('factors must be a list.');
        }

        $factors = [];
        $weightTotal = 0;
        $seenFactors = [];
        foreach ($factorsInput as $index => $factor) {
            if (!is_array($factor)) {
                throw new InvalidArgumentException("Factor {$index} must be an object.");
            }
            $key = $this->machineKey($factor['key'] ?? null, "Factor {$index} key");
            if (isset($seenFactors[$key])) {
                throw new InvalidArgumentException("Factor key {$key} is duplicated.");
            }
            $seenFactors[$key] = true;

            $weight = $factor['weight_percent'] ?? null;
            if (!is_int($weight) || $weight < 1 || $weight > 100) {
                throw new InvalidArgumentException("Factor {$key} weight_percent must be an integer from 1 to 100.");
            }
            $weightTotal += $weight;

            $requirements = $this->textList(
                $factor['evidence_requirements'] ?? null,
                "Factor {$key} evidence_requirements",
                20,
                1000,
            );
            $anchorsInput = $this->object($factor['grade_anchors'] ?? null, "Factor {$key} grade_anchors");
            $anchors = [];
            foreach (['1', '4', '7', '10'] as $anchor) {
                $anchors[$anchor] = $this->valueText(
                    $anchorsInput[$anchor] ?? null,
                    "Factor {$key} grade anchor {$anchor}",
                    1000,
                    20,
                );
            }

            $factors[] = [
                'key' => $key,
                'label' => $this->valueText($factor['label'] ?? null, "Factor {$index} label", 191, 10),
                'description' => $this->valueText($factor['description'] ?? null, "Factor {$index} description", 1000, 10),
                'weight_percent' => $weight,
                'required' => true,
                'evidence_requirements' => $requirements,
                'grade_anchors' => $anchors,
            ];
        }
        if ($weightTotal !== 100) {
            throw new InvalidArgumentException('Factor weights must total exactly 100 percent.');
        }

        $bands = $this->decimalMap($input['market_cap_bands_crore'] ?? null, ['large_min', 'mid_min', 'small_min', 'micro_min']);
        if (!$this->descending([$bands['large_min'], $bands['mid_min'], $bands['small_min'], $bands['micro_min']])) {
            throw new InvalidArgumentException('Market-cap band minima must descend from large to micro.');
        }

        $risk = $this->object($input['microcap_adjustments'] ?? null, 'microcap_adjustments');
        $appliesBelow = $risk['applies_below_crore'] ?? null;
        $redPoints = $risk['red_flag_points'] ?? null;
        $greenPoints = $risk['green_flag_points'] ?? null;
        if (!is_string($appliesBelow)
            || !DecimalMath::isDecimal($appliesBelow)
            || preg_match('/[1-9]/', $appliesBelow) !== 1
            || !is_int($redPoints)
            || !is_int($greenPoints)
            || $redPoints < 1
            || $greenPoints < 1
        ) {
            throw new InvalidArgumentException('Microcap adjustment values are invalid.');
        }

        $valuationPolicyInput = $this->object($input['valuation_policy'] ?? null, 'valuation_policy');
        $valuationPolicy = [
            'graham_basis_note' => $this->valueText($valuationPolicyInput['graham_basis_note'] ?? null, 'valuation_policy.graham_basis_note', 1000, 40),
            'dcf_review_note' => $this->valueText($valuationPolicyInput['dcf_review_note'] ?? null, 'valuation_policy.dcf_review_note', 1000, 40),
            'dcf_required_assumptions' => $this->machineKeyList(
                $valuationPolicyInput['dcf_required_assumptions'] ?? null,
                'valuation_policy.dcf_required_assumptions',
            ),
        ];

        return [
            'version' => $version,
            'name' => $name,
            'effective_date' => $effectiveDate,
            'verified_by' => $verifiedBy,
            'verification_note' => $verificationNote,
            'approved_for_use' => $approved,
            'grade_direction' => '1_best_10_weakest',
            'alert_max_score' => 4,
            'graham_coefficient' => $coefficient,
            'valuation_required' => true,
            'review_scope' => $reviewScope,
            'factors' => $factors,
            'valuation_policy' => $valuationPolicy,
            'market_cap_bands_crore' => $bands,
            'market_cap_band_note' => $this->valueText($input['market_cap_band_note'] ?? null, 'market_cap_band_note', 1000, 40),
            'microcap_adjustment_note' => $this->valueText($input['microcap_adjustment_note'] ?? null, 'microcap_adjustment_note', 1000, 40),
            'microcap_adjustments' => [
                'applies_below_crore' => $appliesBelow,
                'red_flag_points' => $redPoints,
                'green_flag_points' => $greenPoints,
                'red_flags' => $this->flagDefinitions($risk['red_flags'] ?? null, 'red_flags'),
                'green_flags' => $this->flagDefinitions($risk['green_flags'] ?? null, 'green_flags'),
            ],
        ];
    }

    /** @param array<string, mixed> $input */
    public function hash(array $input): string
    {
        $json = json_encode($this->validate($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key, int $maximum): string
    {
        return $this->valueText($input[$key] ?? null, $key, $maximum);
    }

    private function valueText(mixed $input, string $label, int $maximum, int $minimum = 1): string
    {
        if (!is_string($input)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }
        $value = trim($input);
        if (mb_strlen($value) < $minimum || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("{$label} has invalid content or length.");
        }

        return $value;
    }

    private function machineKey(mixed $input, string $label): string
    {
        if (!is_string($input) || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $input) !== 1) {
            throw new InvalidArgumentException("{$label} must be a lowercase machine key.");
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function object(mixed $input, string $label): array
    {
        if (!is_array($input) || array_is_list($input)) {
            throw new InvalidArgumentException("{$label} must be an object.");
        }

        return $input;
    }

    /** @return list<string> */
    private function textList(mixed $input, string $label, int $minimumLength, int $maximumLength): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === []) {
            throw new InvalidArgumentException("{$label} must be a non-empty list.");
        }

        $output = [];
        foreach ($input as $index => $value) {
            $output[] = $this->valueText($value, "{$label} item {$index}", $maximumLength, $minimumLength);
        }

        return $output;
    }

    /** @return list<string> */
    private function machineKeyList(mixed $input, string $label): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === []) {
            throw new InvalidArgumentException("{$label} must be a non-empty list.");
        }

        $output = [];
        foreach ($input as $index => $value) {
            $key = $this->machineKey($value, "{$label} item {$index}");
            $output[$key] = true;
        }

        return array_keys($output);
    }

    /** @param mixed $input @param list<string> $keys @return array<string, string> */
    private function decimalMap(mixed $input, array $keys): array
    {
        if (!is_array($input)) {
            throw new InvalidArgumentException('Market-cap bands must be an object.');
        }
        $output = [];
        foreach ($keys as $key) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || !DecimalMath::isDecimal($value)) {
                throw new InvalidArgumentException("Market-cap band {$key} must be a decimal string.");
            }
            $output[$key] = $value;
        }

        return $output;
    }

    /** @param list<string> $values */
    private function descending(array $values): bool
    {
        $previous = null;
        foreach ($values as $value) {
            $numeric = (int) $value;
            if ((string) $numeric !== $value || $numeric <= 0 || $previous !== null && $previous <= $numeric) {
                return false;
            }
            $previous = $numeric;
        }

        return true;
    }

    /** @return list<array{key: string, label: string}> */
    private function flagDefinitions(mixed $input, string $label): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            throw new InvalidArgumentException("{$label} must be a list.");
        }
        $output = [];
        $seen = [];
        foreach ($input as $index => $flag) {
            if (!is_array($flag)) {
                throw new InvalidArgumentException("{$label} item {$index} must be an object.");
            }
            $key = $this->machineKey($flag['key'] ?? null, "{$label} item {$index} key");
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("{$label} key {$key} is duplicated.");
            }
            $seen[$key] = true;
            $output[] = [
                'key' => $key,
                'label' => $this->valueText($flag['label'] ?? null, "{$label} item {$index} label", 191, 5),
            ];
        }

        return $output;
    }
}
