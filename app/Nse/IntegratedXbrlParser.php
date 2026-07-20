<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DOMElement;

final class IntegratedXbrlParser
{
    /** @var list<string> */
    private const NON_FACT_NAMES = ['schemaRef', 'context', 'unit', 'roleRef', 'arcroleRef'];

    public function parse(string $xml): IntegratedFinancialResult
    {
        $document = SafeXml::load($xml, 'NSE Integrated Filing XBRL');
        $root = $document->documentElement;
        if ($root === null || strtolower($root->localName) !== 'xbrl') {
            throw new NseSourceException('NSE Integrated Filing document root must be XBRL.');
        }

        $taxonomyUri = '';
        $facts = [];
        $byName = [];
        $occurrences = [];

        foreach ($root->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'schemaRef') {
                $taxonomyUri = trim($child->getAttributeNS('http://www.w3.org/1999/xlink', 'href'));
                continue;
            }

            if (in_array($child->localName, self::NON_FACT_NAMES, true) || !$child->hasAttribute('contextRef')) {
                continue;
            }

            $name = $child->localName;
            $contextRef = trim($child->getAttribute('contextRef'));
            $value = trim($child->textContent);
            if ($name === '' || $contextRef === '' || $value === '') {
                continue;
            }

            $key = $name . "\0" . $contextRef;
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            $fact = [
                'name' => $name,
                'context_ref' => $contextRef,
                'unit_ref' => $this->attribute($child, 'unitRef'),
                'decimals' => $this->attribute($child, 'decimals'),
                'value' => $value,
                'occurrence' => $occurrences[$key],
            ];
            $facts[] = $fact;
            $byName[$name] ??= [];
            $byName[$name][] = $fact;
        }

        if ($facts === []) {
            throw new NseSourceException('NSE Integrated Filing XBRL contains no reportable facts.');
        }

        $metadata = [
            'scrip_code' => $this->first($byName, ['ScripCode']),
            'symbol' => strtoupper((string) $this->first($byName, ['Symbol'])),
            'isin' => strtoupper((string) $this->first($byName, ['ISIN'])),
            'company_name' => $this->first($byName, ['NameOfTheCompany']),
            'company_type' => $this->first($byName, ['TypeOfCompany']),
            'security_class' => $this->first($byName, ['ClassOfSecurity']),
            'financial_year_start' => $this->date($this->first($byName, ['DateOfStartOfFinancialYear'])),
            'financial_year_end' => $this->date($this->first($byName, ['DateOfEndOfFinancialYear'])),
            'period_start' => $this->date($this->first($byName, ['DateOfStartOfReportingPeriod'])),
            'period_end' => $this->date($this->first($byName, ['DateOfEndOfReportingPeriod'])),
            'reporting_period_type' => $this->first($byName, ['TypeOfReportingPeriod']),
            'reporting_quarter' => $this->first($byName, ['ReportingQuarter']),
            'audit_status' => $this->first($byName, ['WhetherResultsAreAuditedOrUnaudited']),
            'statement_scope' => $this->scope($this->first($byName, ['NatureOfReportStandaloneConsolidated'])),
            'currency' => strtoupper((string) ($this->first($byName, ['DescriptionOfPresentationCurrency']) ?? 'INR')),
            'rounding_level' => $this->first($byName, ['LevelOfRounding']),
            'board_approval_date' => $this->date($this->first($byName, ['DateOfBoardMeetingWhenFinancialResultsWereApproved'])),
        ];

        $metrics = [
            'revenue_from_operations' => $this->number($this->first($byName, ['RevenueFromOperations'])),
            'other_income' => $this->number($this->first($byName, ['OtherIncome'])),
            'total_income' => $this->number($this->first($byName, ['Income', 'TotalIncome'])),
            'finance_costs' => $this->number($this->first($byName, ['FinanceCosts', 'FinanceCost'])),
            'total_expenses' => $this->number($this->first($byName, ['Expenses', 'TotalExpenses'])),
            'profit_before_tax' => $this->number($this->first($byName, ['ProfitBeforeTax'])),
            'tax_expense' => $this->number($this->first($byName, ['TaxExpense', 'TotalTaxExpense'])),
            'profit_for_period' => $this->number($this->first($byName, ['ProfitLossForPeriod', 'ProfitForPeriod'])),
            'profit_attributable_to_owners' => $this->number($this->first($byName, ['ProfitOrLossAttributableToOwnersOfParent'])),
            'basic_eps' => $this->number($this->first($byName, [
                'BasicEarningsLossPerShareFromContinuingAndDiscontinuedOperations',
                'BasicEarningsLossPerShareFromContinuingOperations',
                'BasicEarningsPerShare',
            ])),
            'diluted_eps' => $this->number($this->first($byName, [
                'DilutedEarningsLossPerShareFromContinuingAndDiscontinuedOperations',
                'DilutedEarningsLossPerShareFromContinuingOperations',
                'DilutedEarningsPerShare',
            ])),
            'debt_equity_ratio' => $this->number($this->first($byName, ['DebtEquityRatio'])),
        ];

        return new IntegratedFinancialResult($taxonomyUri, $metadata, $metrics, $facts);
    }

    private function attribute(DOMElement $element, string $name): ?string
    {
        if (!$element->hasAttribute($name)) {
            return null;
        }

        $value = trim($element->getAttribute($name));

        return $value === '' ? null : $value;
    }

    /** @param array<string, list<array<string, mixed>>> $byName */
    private function first(array $byName, array $names): ?string
    {
        foreach ($names as $name) {
            foreach ($byName[$name] ?? [] as $fact) {
                if (($fact['context_ref'] ?? '') === 'OneD') {
                    return (string) $fact['value'];
                }
            }

            if (isset($byName[$name][0]['value'])) {
                return (string) $byName[$name][0]['value'];
            }
        }

        return null;
    }

    private function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function number(?string $value): ?string
    {
        if ($value === null || preg_match('/^[+-]?(?:\d+)(?:\.\d+)?$/D', $value) !== 1) {
            return null;
        }

        $unsigned = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $integer . ($fraction === '' ? '' : '.' . $fraction);

        return str_starts_with($value, '-') && $normalized !== '0' ? '-' . $normalized : $normalized;
    }

    private function scope(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if (str_contains($normalized, 'consolidated')) {
            return 'consolidated';
        }

        if (str_contains($normalized, 'standalone')) {
            return 'standalone';
        }

        return 'unknown';
    }
}
