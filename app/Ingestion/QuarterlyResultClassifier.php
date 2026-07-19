<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

final class QuarterlyResultClassifier
{
    /** @return array{is_candidate: bool, confidence: int, reason: string} */
    public function classify(Filing $filing): array
    {
        $text = $this->normalize($filing->category . ' ' . $filing->subject);

        $exclusions = [
            'trading window',
            'analyst meeting',
            'investor meeting',
            'board meeting intimation',
            'newspaper publication',
        ];

        foreach ($exclusions as $phrase) {
            if (str_contains($text, $phrase)) {
                return [
                    'is_candidate' => false,
                    'confidence' => 0,
                    'reason' => "Excluded announcement type: {$phrase}",
                ];
            }
        }

        $hasFinancialResults = str_contains($text, 'financial result')
            || str_contains($text, 'quarterly result');
        $hasPeriodSignal = str_contains($text, 'quarter')
            || str_contains($text, 'period ended')
            || str_contains($text, 'year ended');
        $hasResultQualifier = str_contains($text, 'unaudited')
            || str_contains($text, 'audited')
            || str_contains($text, 'standalone')
            || str_contains($text, 'consolidated');

        if ($hasFinancialResults && $hasPeriodSignal && $hasResultQualifier) {
            return [
                'is_candidate' => true,
                'confidence' => 100,
                'reason' => 'Financial-results, reporting-period, and result-qualifier signals found.',
            ];
        }

        if ($hasFinancialResults && $hasPeriodSignal) {
            return [
                'is_candidate' => true,
                'confidence' => 90,
                'reason' => 'Financial-results and reporting-period signals found.',
            ];
        }

        if ($hasFinancialResults) {
            return [
                'is_candidate' => true,
                'confidence' => 70,
                'reason' => 'Financial-results signal found; manual or document verification required.',
            ];
        }

        return [
            'is_candidate' => false,
            'confidence' => 0,
            'reason' => 'No quarterly financial-results signal found.',
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
