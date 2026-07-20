<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use DateTimeImmutable;
use InvalidArgumentException;

final class ShariaPolicyValidator
{
    /**
     * @param array<string, mixed> $input
     * @return array{
     *   version: string,
     *   name: string,
     *   authority_name: string,
     *   authority_standard: string,
     *   authority_reference_url: string,
     *   effective_date: string,
     *   verified_by: string,
     *   verification_note: string,
     *   approved_for_use: bool,
     *   ratios: list<array{key: string, label: string, numerator_key: string, denominator_key: string, max_percent: string, required: bool}>
     * }
     */
    public function validate(array $input, bool $requireApproval = true): array
    {
        $version = $this->requiredText($input, 'version', 64);
        $name = $this->requiredText($input, 'name', 191);
        $authorityName = $this->requiredText($input, 'authority_name', 191);
        $authorityStandard = $this->requiredText($input, 'authority_standard', 100);
        $referenceUrl = $this->requiredText($input, 'authority_reference_url', 500);
        $effectiveDate = $this->requiredText($input, 'effective_date', 10);
        $verifiedBy = $this->requiredText($input, 'verified_by', 191);
        $verificationNote = $this->requiredText($input, 'verification_note', 1000);
        $approved = ($input['approved_for_use'] ?? null) === true;

        foreach ([$version, $name, $authorityName, $authorityStandard, $verifiedBy, $verificationNote] as $text) {
            if (stripos($text, 'REPLACE') !== false) {
                throw new InvalidArgumentException('Policy placeholders must be replaced before installation.');
            }
        }
        if (mb_strlen($verifiedBy) < 3 || mb_strlen($verificationNote) < 20) {
            throw new InvalidArgumentException('Policy reviewer and verification note are not sufficiently specific.');
        }

        if ($requireApproval && !$approved) {
            throw new InvalidArgumentException('The policy must set approved_for_use to true before installation.');
        }

        if (filter_var($referenceUrl, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($referenceUrl), 'https://')) {
            throw new InvalidArgumentException('authority_reference_url must be a valid HTTPS URL.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
        if ($date === false || $date->format('Y-m-d') !== $effectiveDate) {
            throw new InvalidArgumentException('effective_date must use the YYYY-MM-DD format.');
        }

        $ratioInput = $input['ratios'] ?? null;
        if (!is_array($ratioInput) || !array_is_list($ratioInput) || $ratioInput === []) {
            throw new InvalidArgumentException('ratios must be a non-empty JSON list.');
        }

        $ratios = [];
        $seen = [];

        foreach ($ratioInput as $index => $ratio) {
            if (!is_array($ratio)) {
                throw new InvalidArgumentException("Ratio {$index} must be an object.");
            }

            $key = $this->ratioKey($ratio, 'key', $index);
            $label = $this->ratioText($ratio, 'label', $index, 191);
            $numerator = $this->ratioKey($ratio, 'numerator_key', $index);
            $denominator = $this->ratioKey($ratio, 'denominator_key', $index);
            $maximum = $ratio['max_percent'] ?? null;
            $required = $ratio['required'] ?? null;

            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Ratio key {$key} is duplicated.");
            }
            if ($numerator === $denominator) {
                throw new InvalidArgumentException("Ratio {$key} must use different numerator and denominator inputs.");
            }
            if (!is_string($maximum) && !is_int($maximum)) {
                throw new InvalidArgumentException("Ratio {$key} max_percent must be a decimal string.");
            }

            $maximum = (string) $maximum;
            if (!DecimalMath::isDecimal($maximum) || !$this->percentageIsInRange($maximum)) {
                throw new InvalidArgumentException("Ratio {$key} max_percent must be greater than 0 and no more than 100.");
            }
            if (!is_bool($required)) {
                throw new InvalidArgumentException("Ratio {$key} required must be true or false.");
            }

            $seen[$key] = true;
            $ratios[] = [
                'key' => $key,
                'label' => $label,
                'numerator_key' => $numerator,
                'denominator_key' => $denominator,
                'max_percent' => $maximum,
                'required' => $required,
            ];
        }

        if (count(array_filter($ratios, static fn (array $ratio): bool => $ratio['required'])) === 0) {
            throw new InvalidArgumentException('At least one ratio must be required.');
        }

        return [
            'version' => $version,
            'name' => $name,
            'authority_name' => $authorityName,
            'authority_standard' => $authorityStandard,
            'authority_reference_url' => $referenceUrl,
            'effective_date' => $effectiveDate,
            'verified_by' => $verifiedBy,
            'verification_note' => $verificationNote,
            'approved_for_use' => $approved,
            'ratios' => $ratios,
        ];
    }

    /** @param array<string, mixed> $input */
    public function hash(array $input): string
    {
        $validated = $this->validate($input);
        $json = json_encode($validated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }

    /** @param array<string, mixed> $input */
    private function requiredText(array $input, string $key, int $maximumLength): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("{$key} must contain 1 to {$maximumLength} characters.");
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("{$key} must not contain control characters.");
        }

        return $value;
    }

    /** @param array<string, mixed> $ratio */
    private function ratioKey(array $ratio, string $field, int $index): string
    {
        $value = $ratio[$field] ?? null;
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException("Ratio {$index} {$field} must be a lowercase machine key.");
        }

        return $value;
    }

    /** @param array<string, mixed> $ratio */
    private function ratioText(array $ratio, string $field, int $index, int $maximumLength): string
    {
        $value = $ratio[$field] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("Ratio {$index} {$field} must be a string.");
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException("Ratio {$index} {$field} has an invalid length.");
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("Ratio {$index} {$field} must not contain control characters.");
        }

        return $value;
    }

    private function percentageIsInRange(string $value): bool
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $wholeNumber = (int) $whole;
        $hasNonZeroFraction = trim($fraction, '0') !== '';

        if ($wholeNumber === 0 && !$hasNonZeroFraction) {
            return false;
        }

        return $wholeNumber < 100 || $wholeNumber === 100 && !$hasNonZeroFraction;
    }
}
