<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use Throwable;

final readonly class ShariaEvidenceReadiness
{
    public function __construct(private DecimalMath $math)
    {
    }

    /**
     * @param array<string, mixed>|null $activityReview
     * @param array<string, array<string, mixed>|ShariaInputValue> $inputRows
     * @param list<array<string, mixed>> $pendingCandidates
     * @return array{
     *   ready: bool,
     *   activity_status: string,
     *   required_input_keys: list<string>,
     *   accepted_input_keys: list<string>,
     *   missing_input_keys: list<string>,
     *   pending_candidate_keys: list<string>,
     *   blockers: list<string>,
     *   warnings: list<string>
     * }
     */
    public function assess(
        ?ShariaPolicy $policy,
        ?array $activityReview,
        array $inputRows,
        array $pendingCandidates = [],
    ): array {
        $activityStatus = (string) ($activityReview['activity_status'] ?? 'not_reviewed');
        $acceptedKeys = array_values(array_unique(array_map('strval', array_keys($inputRows))));
        sort($acceptedKeys);

        $candidateKeys = [];
        foreach ($pendingCandidates as $candidate) {
            if (($candidate['review_status'] ?? 'pending') !== 'pending') {
                continue;
            }
            $key = trim((string) ($candidate['metric_key'] ?? ''));
            if ($key !== '') {
                $candidateKeys[$key] = true;
            }
        }
        $pendingCandidateKeys = array_keys($candidateKeys);
        sort($pendingCandidateKeys);

        $result = [
            'ready' => false,
            'activity_status' => $activityStatus,
            'required_input_keys' => [],
            'accepted_input_keys' => $acceptedKeys,
            'missing_input_keys' => [],
            'pending_candidate_keys' => $pendingCandidateKeys,
            'blockers' => [],
            'warnings' => [],
        ];

        if ($policy === null) {
            $result['blockers'][] = 'Activate a clause-verified Sharia policy.';
        }

        if ($activityReview === null) {
            $result['blockers'][] = 'Record a business-activity review from primary evidence.';
        } elseif ($activityStatus === 'pending') {
            $result['blockers'][] = 'Complete the pending business-activity review.';
        } elseif ($activityStatus === 'mixed') {
            $result['blockers'][] = 'Resolve the mixed business-activity classification before screening.';
        } elseif (!in_array($activityStatus, ['permissible', 'prohibited'], true)) {
            $result['blockers'][] = 'The business-activity review has an unsupported status.';
        }

        if ($policy === null || !in_array($activityStatus, ['permissible', 'prohibited'], true)) {
            return $this->finish($result);
        }

        if ($activityStatus === 'prohibited') {
            $result['warnings'][] = 'Financial ratios are not required because a prohibited activity review fails before ratio calculation.';

            return $this->finish($result);
        }

        $requiredKeys = [];
        foreach ($policy->ratios as $ratio) {
            if (($ratio['required'] ?? false) !== true) {
                continue;
            }
            $requiredKeys[(string) $ratio['numerator_key']] = true;
            $requiredKeys[(string) $ratio['denominator_key']] = true;
        }
        $result['required_input_keys'] = array_keys($requiredKeys);
        sort($result['required_input_keys']);

        $inputs = [];
        foreach ($policy->inputKeys() as $key) {
            $row = $inputRows[$key] ?? null;
            if ($row === null) {
                if (isset($requiredKeys[$key])) {
                    $result['missing_input_keys'][] = $key;
                }
                continue;
            }

            try {
                $inputs[$key] = $row instanceof ShariaInputValue ? $row : ShariaInputValue::fromArray($row);
            } catch (Throwable) {
                $result['blockers'][] = "Accepted input {$key} is invalid and must be replaced.";
            }
        }
        sort($result['missing_input_keys']);

        foreach ($result['missing_input_keys'] as $key) {
            $result['blockers'][] = "Required input {$key} is missing for the selected period.";
            if (isset($candidateKeys[$key])) {
                $result['warnings'][] = "A pending structured candidate may help complete {$key}, but it must be reviewed and accepted first.";
            }
        }

        foreach ($policy->ratios as $ratio) {
            $numerator = $inputs[$ratio['numerator_key']] ?? null;
            $denominator = $inputs[$ratio['denominator_key']] ?? null;
            if ($numerator === null || $denominator === null) {
                continue;
            }

            if ($numerator->currency !== $denominator->currency) {
                $result['blockers'][] = "Currencies do not match for {$ratio['label']}.";
                continue;
            }

            try {
                if ($this->math->compare($denominator->baseValue($this->math), '0') <= 0) {
                    $result['blockers'][] = "The denominator is not greater than zero for {$ratio['label']}.";
                }
            } catch (Throwable) {
                $result['blockers'][] = "Accepted evidence cannot be normalized for {$ratio['label']}.";
            }
        }

        return $this->finish($result);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function finish(array $result): array
    {
        $result['blockers'] = array_values(array_unique($result['blockers']));
        $result['warnings'] = array_values(array_unique($result['warnings']));
        $result['ready'] = $result['blockers'] === [];

        return $result;
    }
}
