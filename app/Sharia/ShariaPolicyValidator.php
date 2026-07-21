<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

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
     *   ratios: list<array{
     *     key: string,
     *     label: string,
     *     numerator_key: string,
     *     denominator_key: string,
     *     max_percent: string,
     *     required: bool,
     *     source_clause: string,
     *     numerator_definition: string,
     *     denominator_definition: string
     *   }>
     * }
     */
    public function validate(array $input, bool $requireApproval = true): array
    {
        $readiness = (new ShariaPolicyReadinessInspector())->inspect($input, $requireApproval);
        if ($readiness['errors'] !== []) {
            throw new InvalidArgumentException($readiness['errors'][0]);
        }

        $version = $this->requiredText($input, 'version', 64);
        $name = $this->requiredText($input, 'name', 191);
        $authorityName = $this->requiredText($input, 'authority_name', 191);
        $authorityStandard = $this->requiredText($input, 'authority_standard', 100);
        $referenceUrl = $this->requiredText($input, 'authority_reference_url', 500);
        $effectiveDate = $this->requiredText($input, 'effective_date', 10);
        $verifiedBy = $this->requiredText($input, 'verified_by', 191);
        $verificationNote = $this->requiredText($input, 'verification_note', 1000);
        $approved = ($input['approved_for_use'] ?? null) === true;

        /** @var list<array<string, mixed>> $ratioInput */
        $ratioInput = $input['ratios'];
        $ratios = [];

        foreach ($ratioInput as $index => $ratio) {
            $ratios[] = [
                'key' => $this->ratioKey($ratio, 'key', $index),
                'label' => $this->ratioText($ratio, 'label', $index, 191),
                'numerator_key' => $this->ratioKey($ratio, 'numerator_key', $index),
                'denominator_key' => $this->ratioKey($ratio, 'denominator_key', $index),
                'max_percent' => (string) $ratio['max_percent'],
                'required' => (bool) $ratio['required'],
                'source_clause' => $this->ratioText($ratio, 'source_clause', $index, 191),
                'numerator_definition' => $this->ratioText($ratio, 'numerator_definition', $index, 1000),
                'denominator_definition' => $this->ratioText($ratio, 'denominator_definition', $index, 1000),
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
}
