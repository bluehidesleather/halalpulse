<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use DateTimeImmutable;
use HalalPulse\Sharia\DecimalMath;
use InvalidArgumentException;

final class MultibaggerMethodologyValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(array $input, bool $requireApproval = true): array
    {
        $version = $this->text($input, 'version', 64);
        $name = $this->text($input, 'name', 191);
        $effectiveDate = $this->text($input, 'effective_date', 10);
        $verifiedBy = $this->text($input, 'verified_by', 191);
        $verificationNote = $this->text($input, 'verification_note', 1000);
        $approved = ($input['approved_for_use'] ?? null) === true;

        foreach ([$version, $name, $verifiedBy, $verificationNote] as $text) {
            if (stripos($text, 'REPLACE') !== false) {
                throw new InvalidArgumentException('Methodology placeholders must be replaced before installation.');
            }
        }
        if ($requireApproval && !$approved) {
            throw new InvalidArgumentException('The methodology must set approved_for_use to true before installation.');
        }
        if (mb_strlen($verifiedBy) < 3 || mb_strlen($verificationNote) < 20) {
            throw new InvalidArgumentException('Methodology reviewer and verification note are not sufficiently specific.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
            throw new InvalidArgumentException('effective_date must use the YYYY-MM-DD format.');
        }
        if (($input['grade_direction'] ?? null) !== '1_best_10_weakest') {
            throw new InvalidArgumentException('grade_direction must be 1_best_10_weakest.');
        }

        $alertMaximum = $input['alert_max_score'] ?? null;
        if ($alertMaximum !== 4) {
            throw new InvalidArgumentException('alert_max_score must be 4 for the locked HalalPulse workflow.');
        }

        $coefficient = $input['graham_coefficient'] ?? null;
        if (!is_string($coefficient) || !DecimalMath::isDecimal($coefficient) || preg_match('/[1-9]/', $coefficient) !== 1) {
            throw new InvalidArgumentException('graham_coefficient must be a positive decimal string.');
        }

        $factorsInput = $input['factors'] ?? null;
        if (!is_array($factorsInput) || !array_is_list($factorsInput) || $factorsInput === []) {
            throw new InvalidArgumentException('factors must be a non-empty list.');
        }

        $factors = [];
        $weightTotal = 0;
        $seenFactors = [];
        foreach ($factorsInput as $index => $factor) {
            if (!is_array($factor)) {
                throw new InvalidArgumentException("Factor {$index} must be an object.");
            }
            $key = $this->machineKey($factor['key'] ?? null, "Factor {$index} key");
            $label = $this->valueText($factor['label'] ?? null, "Factor {$index} label", 191);
            $description = $this->valueText($factor['description'] ?? null, "Factor {$index} description", 1000);
            $weight = $factor['weight_percent'] ?? null;
            $required = $factor['required'] ?? null;
            if (isset($seenFactors[$key])) {
                throw new InvalidArgumentException("Factor key {$key} is duplicated.");
            }
            if (!is_int($weight) || $weight < 1 || $weight > 100) {
                throw new InvalidArgumentException("Factor {$key} weight_percent must be an integer from 1 to 100.");
            }
            if ($required !== true) {
                throw new InvalidArgumentException("Factor {$key} must be required so missing weights cannot improve a score.");
            }
            $seenFactors[$key] = true;
            $weightTotal += $weight;
            $factors[] = ['key' => $key, 'label' => $label, 'description' => $description, 'weight_percent' => $weight, 'required' => $required];
        }
        if ($weightTotal !== 100) {
            throw new InvalidArgumentException('Factor weights must total exactly 100 percent.');
        }

        $bands = $this->decimalMap($input['market_cap_bands_crore'] ?? null, ['large_min', 'mid_min', 'small_min', 'micro_min']);
        if (!$this->descending([$bands['large_min'], $bands['mid_min'], $bands['small_min'], $bands['micro_min']])) {
            throw new InvalidArgumentException('Market-cap band minima must descend from large to micro.');
        }

        $risk = $input['microcap_adjustments'] ?? null;
        if (!is_array($risk)) {
            throw new InvalidArgumentException('microcap_adjustments must be an object.');
        }
        $appliesBelow = $risk['applies_below_crore'] ?? null;
        $redPoints = $risk['red_flag_points'] ?? null;
        $greenPoints = $risk['green_flag_points'] ?? null;
        if (!is_string($appliesBelow) || !DecimalMath::isDecimal($appliesBelow) || preg_match('/[1-9]/', $appliesBelow) !== 1 || !is_int($redPoints) || !is_int($greenPoints) || $redPoints < 1 || $greenPoints < 1) {
            throw new InvalidArgumentException('Microcap adjustment values are invalid.');
        }

        $redFlags = $this->flagDefinitions($risk['red_flags'] ?? null, 'red_flags');
        $greenFlags = $this->flagDefinitions($risk['green_flags'] ?? null, 'green_flags');

        $valuationRequired = $input['valuation_required'] ?? null;
        if ($valuationRequired !== true) {
            throw new InvalidArgumentException('valuation_required must be true for the locked Graham-plus-DCF workflow.');
        }

        return [
            'version' => $version,
            'name' => $name,
            'effective_date' => $effectiveDate,
            'verified_by' => $verifiedBy,
            'verification_note' => $verificationNote,
            'approved_for_use' => $approved,
            'grade_direction' => '1_best_10_weakest',
            'alert_max_score' => $alertMaximum,
            'graham_coefficient' => $coefficient,
            'valuation_required' => true,
            'factors' => $factors,
            'market_cap_bands_crore' => $bands,
            'microcap_adjustments' => [
                'applies_below_crore' => $appliesBelow,
                'red_flag_points' => $redPoints,
                'green_flag_points' => $greenPoints,
                'red_flags' => $redFlags,
                'green_flags' => $greenFlags,
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

    private function valueText(mixed $input, string $label, int $maximum): string
    {
        if (!is_string($input)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }
        $value = trim($input);
        if ($value === '' || mb_strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
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
            $output[] = ['key' => $key, 'label' => $this->valueText($flag['label'] ?? null, "{$label} item {$index} label", 191)];
        }

        return $output;
    }
}
