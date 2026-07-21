<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use DateTimeImmutable;

final class ShariaPolicyReadinessInspector
{
    /**
     * @param array<string, mixed> $input
     * @return array{ready: bool, errors: list<string>, warnings: list<string>}
     */
    public function inspect(array $input, bool $requireApproval = true): array
    {
        $errors = [];
        $warnings = [];

        $requiredText = [
            'version' => 64,
            'name' => 191,
            'authority_name' => 191,
            'authority_standard' => 100,
            'authority_reference_url' => 500,
            'effective_date' => 10,
            'verified_by' => 191,
            'verification_note' => 1000,
        ];

        foreach ($requiredText as $key => $maximumLength) {
            $value = $input[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $errors[] = "{$key} is required.";
                continue;
            }

            $value = trim($value);
            if (mb_strlen($value) > $maximumLength) {
                $errors[] = "{$key} exceeds {$maximumLength} characters.";
            }
            if ($this->containsPlaceholder($value)) {
                $errors[] = "{$key} still contains a placeholder.";
            }
        }

        $referenceUrl = is_string($input['authority_reference_url'] ?? null)
            ? trim((string) $input['authority_reference_url'])
            : '';
        if ($referenceUrl !== '') {
            if (filter_var($referenceUrl, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($referenceUrl), 'https://')) {
                $errors[] = 'authority_reference_url must be a valid HTTPS URL.';
            } elseif (strcasecmp(trim((string) ($input['authority_name'] ?? '')), 'AAOIFI') === 0) {
                $host = strtolower((string) parse_url($referenceUrl, PHP_URL_HOST));
                $path = strtolower((string) parse_url($referenceUrl, PHP_URL_PATH));
                if (!in_array($host, ['aaoifi.com', 'www.aaoifi.com'], true)) {
                    $errors[] = 'An AAOIFI policy must cite an official aaoifi.com source.';
                }
                if (str_contains($path, 'draft') || str_contains(strtolower($referenceUrl), '/announcement/')) {
                    $errors[] = 'An AAOIFI policy cannot cite a draft, announcement, or consultation page as the governing standard text.';
                }
                if (!str_contains(strtolower((string) ($input['authority_standard'] ?? '')), '21')) {
                    $warnings[] = 'Confirm that the authority_standard identifies Sharia Standard No. 21 for listed shares.';
                }
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
        if ($verificationNote !== '' && mb_strlen($verificationNote) < 40) {
            $errors[] = 'verification_note must document the edition, language, clauses, and review basis.';
        }

        if ($requireApproval && ($input['approved_for_use'] ?? null) !== true) {
            $errors[] = 'approved_for_use must be true only after independent review is complete.';
        }

        $ratios = $input['ratios'] ?? null;
        if (!is_array($ratios) || !array_is_list($ratios) || $ratios === []) {
            $errors[] = 'ratios must be a non-empty list.';
        } else {
            $seen = [];
            foreach ($ratios as $index => $ratio) {
                if (!is_array($ratio)) {
                    $errors[] = "Ratio {$index} must be an object.";
                    continue;
                }

                $key = is_string($ratio['key'] ?? null) ? trim((string) $ratio['key']) : '';
                if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $key) !== 1) {
                    $errors[] = "Ratio {$index} key must be a lowercase machine key.";
                } elseif (isset($seen[$key])) {
                    $errors[] = "Ratio key {$key} is duplicated.";
                } else {
                    $seen[$key] = true;
                }

                foreach (['label', 'numerator_key', 'denominator_key', 'source_clause', 'numerator_definition', 'denominator_definition'] as $field) {
                    $value = $ratio[$field] ?? null;
                    if (!is_string($value) || trim($value) === '') {
                        $errors[] = "Ratio {$index} {$field} is required.";
                    } elseif ($this->containsPlaceholder(trim($value))) {
                        $errors[] = "Ratio {$index} {$field} still contains a placeholder.";
                    }
                }

                $maximum = $ratio['max_percent'] ?? null;
                if ((!is_string($maximum) && !is_int($maximum)) || !DecimalMath::isDecimal((string) $maximum)) {
                    $errors[] = "Ratio {$index} max_percent must be an exact decimal string.";
                } elseif (!$this->percentageIsInRange((string) $maximum)) {
                    $errors[] = "Ratio {$index} max_percent must be greater than 0 and no more than 100.";
                }

                if (!is_bool($ratio['required'] ?? null)) {
                    $errors[] = "Ratio {$index} required must be true or false.";
                }
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
