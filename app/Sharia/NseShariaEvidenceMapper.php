<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use HalalPulse\Nse\IntegratedFinancialResult;

final class NseShariaEvidenceMapper
{
    /**
     * @return list<array{
     *   metric_key: string,
     *   value: string,
     *   currency: string,
     *   scale_label: string,
     *   source_fact_name: string,
     *   source_context_ref: string,
     *   confidence: int,
     *   mapping_reason: string
     * }>
     */
    public function map(IntegratedFinancialResult $result): array
    {
        $periodEnd = (string) ($result->metadata['period_end'] ?? '');
        $currency = strtoupper(trim((string) ($result->metadata['currency'] ?? '')));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $periodEnd) !== 1 || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            return [];
        }

        $totalIncome = $this->candidate(
            facts: $result->facts,
            names: ['Income', 'TotalIncome'],
            metricKey: 'total_revenue',
            currency: $currency,
            confidence: 90,
            reason: 'Structured NSE XBRL total-income fact suggested as total revenue. Administrator acceptance is required.',
        );
        if ($totalIncome !== null) {
            return [$totalIncome];
        }

        $revenue = $this->candidate(
            facts: $result->facts,
            names: ['RevenueFromOperations'],
            metricKey: 'total_revenue',
            currency: $currency,
            confidence: 75,
            reason: 'Structured NSE XBRL revenue-from-operations fact suggested as a total-revenue fallback. Review other income before acceptance.',
        );

        return $revenue === null ? [] : [$revenue];
    }

    /**
     * @param list<array{name: string, context_ref: string, unit_ref: ?string, decimals: ?string, value: string, occurrence: int}> $facts
     * @param list<string> $names
     * @return array{
     *   metric_key: string,
     *   value: string,
     *   currency: string,
     *   scale_label: string,
     *   source_fact_name: string,
     *   source_context_ref: string,
     *   confidence: int,
     *   mapping_reason: string
     * }|null
     */
    private function candidate(
        array $facts,
        array $names,
        string $metricKey,
        string $currency,
        int $confidence,
        string $reason,
    ): ?array {
        $fact = $this->preferredFact($facts, $names);
        if ($fact === null) {
            return null;
        }

        $unitRef = strtoupper(trim((string) ($fact['unit_ref'] ?? '')));
        if ($unitRef !== $currency) {
            return null;
        }

        $value = $this->positiveDecimal((string) $fact['value']);
        if ($value === null) {
            return null;
        }

        return [
            'metric_key' => $metricKey,
            'value' => $value,
            'currency' => $currency,
            'scale_label' => 'one',
            'source_fact_name' => (string) $fact['name'],
            'source_context_ref' => (string) $fact['context_ref'],
            'confidence' => $confidence,
            'mapping_reason' => $reason,
        ];
    }

    /**
     * @param list<array{name: string, context_ref: string, unit_ref: ?string, decimals: ?string, value: string, occurrence: int}> $facts
     * @param list<string> $names
     * @return array{name: string, context_ref: string, unit_ref: ?string, decimals: ?string, value: string, occurrence: int}|null
     */
    private function preferredFact(array $facts, array $names): ?array
    {
        foreach ($names as $name) {
            foreach ($facts as $fact) {
                if ($fact['name'] === $name && $fact['context_ref'] === 'OneD') {
                    return $fact;
                }
            }
        }

        foreach ($names as $name) {
            foreach ($facts as $fact) {
                if ($fact['name'] === $name) {
                    return $fact;
                }
            }
        }

        return null;
    }

    private function positiveDecimal(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\+?\d+(?:\.\d+)?$/D', $value) !== 1) {
            return null;
        }

        $value = ltrim($value, '+');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        if (strlen($whole) > 30 || strlen($fraction) > 6) {
            return null;
        }

        $normalized = $whole . ($fraction === '' ? '' : '.' . $fraction);

        return $normalized === '0' ? null : $normalized;
    }
}
