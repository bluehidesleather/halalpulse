<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

final class MetricCandidateExtractor
{
    /** @var array<string, string> */
    private const PATTERNS = [
        'revenue' => '\b(?:revenue from operations|operating revenue|revenue)\b',
        'total_income' => '\btotal income\b',
        'ebitda' => '\b(?:ebitda|earnings before interest[, ]+tax[, ]+depreciation)\b',
        'profit_before_tax' => '\b(?:profit before tax|profit /?\s*\(loss\) before tax|pbt)\b',
        'net_profit' => '\b(?:net profit|profit /?\s*\(loss\) for the period|profit for the period)\b',
        'eps' => '\b(?:earnings per share|basic eps|diluted eps)\b',
    ];

    /** @return list<MetricCandidate> */
    public function extract(string $text): array
    {
        $scale = $this->detectScale($text);
        $currency = $this->detectCurrency($text);
        $scope = $this->detectScope($text);
        $candidates = [];
        $seen = [];
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '' || mb_strlen($line) > 1000) {
                continue;
            }

            foreach (self::PATTERNS as $metricKey => $pattern) {
                if (isset($seen[$metricKey]) || preg_match('/' . $pattern . '/iu', $line) !== 1) {
                    continue;
                }

                $values = $this->numericValues($line);
                if ($values === []) {
                    continue;
                }

                $confidence = 35;
                if (count($values) === 2) {
                    $confidence += 15;
                }
                if ($currency !== null) {
                    $confidence += 5;
                }
                if ($scale !== 'one') {
                    $confidence += 5;
                }

                $candidates[] = new MetricCandidate(
                    metricKey: $metricKey,
                    statementScope: $scope,
                    periodLabel: '',
                    currentValue: $values[0],
                    comparisonValue: $values[1] ?? null,
                    currency: $currency,
                    scaleLabel: $scale,
                    confidence: min(65, $confidence),
                    evidenceSnippet: mb_substr($line, 0, 1000),
                );
                $seen[$metricKey] = true;
            }
        }

        return $candidates;
    }

    /** @return list<string> */
    private function numericValues(string $line): array
    {
        preg_match_all('/(?<![A-Za-z])\(?-?\d[\d,]*(?:\.\d+)?\)?/', $line, $matches);
        $values = [];

        foreach ($matches[0] ?? [] as $token) {
            $normalized = str_replace([',', '(', ')'], ['', '-', ''], (string) $token);
            $normalized = preg_replace('/^--/', '-', $normalized) ?? $normalized;

            if (preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) !== 1 || strlen($normalized) > 30) {
                continue;
            }

            $values[] = $normalized;
        }

        if (count($values) > 2 && preg_match('/^-?\d{1,3}$/', $values[0]) === 1) {
            array_shift($values);
        }

        return array_slice($values, 0, 4);
    }

    private function detectScale(string $text): string
    {
        $sample = mb_substr($text, 0, 12000);

        return match (true) {
            preg_match('/\b(?:crore|crores|cr\.)\b/iu', $sample) === 1 => 'crore',
            preg_match('/\b(?:lakh|lakhs|lac|lacs)\b/iu', $sample) === 1 => 'lakh',
            preg_match('/\bmillions?\b/iu', $sample) === 1 => 'million',
            preg_match('/\bthousands?\b/iu', $sample) === 1 => 'thousand',
            default => 'one',
        };
    }

    private function detectCurrency(string $text): ?string
    {
        $sample = mb_substr($text, 0, 12000);

        return preg_match('/(?:₹|\bINR\b|\bRs\.?\s)/iu', $sample) === 1 ? 'INR' : null;
    }

    private function detectScope(string $text): string
    {
        $sample = mb_substr($text, 0, 20000);
        $standalone = preg_match('/\bstandalone\b/iu', $sample) === 1;
        $consolidated = preg_match('/\bconsolidated\b/iu', $sample) === 1;

        if ($standalone === $consolidated) {
            return 'unknown';
        }

        return $consolidated ? 'consolidated' : 'standalone';
    }
}
