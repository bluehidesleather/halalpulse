<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use InvalidArgumentException;

final class ShariaActivityReviewValidator
{
    /**
     * @return array{status: string, description: string, source_url: ?string, evidence_note: string}
     */
    public function validate(string $status, string $description, string $sourceUrl, string $evidenceNote): array
    {
        $status = trim($status);
        $description = trim($description);
        $sourceUrl = trim($sourceUrl);
        $evidenceNote = trim($evidenceNote);

        if (!in_array($status, ['pending', 'permissible', 'prohibited', 'mixed'], true)) {
            throw new InvalidArgumentException('Choose a valid activity status.');
        }

        $this->meaningfulText($description, 'Activity description', 20, 1000);
        $this->meaningfulText($evidenceNote, 'Review rationale', 30, 1000);

        $decisive = in_array($status, ['permissible', 'prohibited', 'mixed'], true);
        if ($decisive && $sourceUrl === '') {
            throw new InvalidArgumentException('A primary evidence URL is required for a permissible, prohibited, or mixed classification.');
        }

        if ($sourceUrl !== '') {
            if (mb_strlen($sourceUrl) > 1000 || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException('Primary evidence must be a valid HTTPS URL.');
            }
            $scheme = strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME));
            $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
            $user = parse_url($sourceUrl, PHP_URL_USER);
            $password = parse_url($sourceUrl, PHP_URL_PASS);
            if ($scheme !== 'https' || $host === '' || $user !== null || $password !== null) {
                throw new InvalidArgumentException('Primary evidence must be a public HTTPS URL without embedded credentials.');
            }
            if ($this->isLocalHost($host)) {
                throw new InvalidArgumentException('Primary evidence must not point to a local or private host.');
            }
        }

        return [
            'status' => $status,
            'description' => $description,
            'source_url' => $sourceUrl === '' ? null : $sourceUrl,
            'evidence_note' => $evidenceNote,
        ];
    }

    private function meaningfulText(string $value, string $label, int $minimumLength, int $maximumLength): void
    {
        $length = mb_strlen($value);
        if ($length < $minimumLength || $length > $maximumLength) {
            throw new InvalidArgumentException("{$label} must contain {$minimumLength} to {$maximumLength} characters.");
        }

        $alphanumeric = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        if (!is_string($alphanumeric) || mb_strlen($alphanumeric) < 10) {
            throw new InvalidArgumentException("{$label} must contain meaningful evidence, not a placeholder.");
        }

        if (preg_match('/^(?:n\/?a|none|unknown|pending|not available|test)+$/iu', trim($value)) === 1) {
            throw new InvalidArgumentException("{$label} must contain meaningful evidence, not a placeholder.");
        }
    }

    private function isLocalHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
