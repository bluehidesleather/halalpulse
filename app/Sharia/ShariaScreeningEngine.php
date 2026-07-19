<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use Throwable;

final readonly class ShariaScreeningEngine
{
    public function __construct(private DecimalMath $math)
    {
    }

    /**
     * @param array<string, array<string, mixed>|ShariaInputValue> $inputRows
     */
    public function screen(ShariaPolicy $policy, string $activityStatus, array $inputRows): ShariaScreeningResult
    {
        if (!$policy->isActive) {
            return $this->insufficient($activityStatus, ['No active Sharia policy is available.']);
        }

        if ($activityStatus === 'prohibited') {
            return new ShariaScreeningResult('failed', null, $activityStatus, [], ['The latest administrator activity review is prohibited.'], []);
        }

        if ($activityStatus !== 'permissible') {
            return $this->insufficient($activityStatus, ['A permissible administrator activity review is required.']);
        }

        $inputs = [];
        $inputSnapshot = [];
        $reasons = [];

        foreach ($policy->inputKeys() as $key) {
            $row = $inputRows[$key] ?? null;
            if ($row === null) {
                continue;
            }

            try {
                $input = $row instanceof ShariaInputValue ? $row : ShariaInputValue::fromArray($row);
                $inputs[$key] = $input;
                $inputSnapshot[$key] = $input->snapshot($this->math);
            } catch (Throwable) {
                $reasons[] = "Input {$key} is invalid.";
            }
        }

        $ratioResults = [];
        $failed = false;
        $worstUtilization = '0';

        foreach ($policy->ratios as $ratio) {
            $numerator = $inputs[$ratio['numerator_key']] ?? null;
            $denominator = $inputs[$ratio['denominator_key']] ?? null;

            if ($numerator === null || $denominator === null) {
                if ($ratio['required']) {
                    $reasons[] = "Required evidence is missing for {$ratio['label']}.";
                }
                continue;
            }

            if ($numerator->currency !== $denominator->currency) {
                $reasons[] = "Currencies do not match for {$ratio['label']}.";
                continue;
            }

            $numeratorBase = $numerator->baseValue($this->math);
            $denominatorBase = $denominator->baseValue($this->math);
            if ($this->math->compare($denominatorBase, '0') <= 0) {
                $reasons[] = "The denominator is not greater than zero for {$ratio['label']}.";
                continue;
            }

            $percentage = $this->math->percent($numeratorBase, $denominatorBase);
            $passed = $this->math->compare($percentage, $ratio['max_percent']) <= 0;
            $utilization = $this->math->utilization($percentage, $ratio['max_percent']);
            if ($this->math->compare($utilization, $worstUtilization) > 0) {
                $worstUtilization = $utilization;
            }

            $ratioResults[] = [
                'key' => $ratio['key'],
                'label' => $ratio['label'],
                'percentage' => $this->math->normalize($percentage),
                'max_percent' => $ratio['max_percent'],
                'currency' => $numerator->currency,
                'passed' => $passed,
            ];

            if (!$passed) {
                $failed = true;
                $reasons[] = "{$ratio['label']} exceeds the active policy maximum.";
            }
        }

        $requiredCount = count(array_filter($policy->ratios, static fn (array $ratio): bool => $ratio['required']));
        $calculatedRequired = count(array_filter(
            $ratioResults,
            static function (array $result) use ($policy): bool {
                foreach ($policy->ratios as $ratio) {
                    if ($ratio['key'] === $result['key']) {
                        return $ratio['required'];
                    }
                }

                return false;
            }
        ));

        if ($calculatedRequired !== $requiredCount || $reasons !== [] && !$failed) {
            return new ShariaScreeningResult('insufficient', null, $activityStatus, $ratioResults, $reasons, $inputSnapshot);
        }

        if ($failed) {
            return new ShariaScreeningResult('failed', null, $activityStatus, $ratioResults, $reasons, $inputSnapshot);
        }

        return new ShariaScreeningResult(
            status: 'passed',
            complianceRank: $this->rank($worstUtilization),
            activityStatus: $activityStatus,
            ratioResults: $ratioResults,
            reasons: ['All required ratios are within the active policy maxima.'],
            inputSnapshot: $inputSnapshot,
        );
    }

    /** @param list<string> $reasons */
    private function insufficient(string $activityStatus, array $reasons): ShariaScreeningResult
    {
        return new ShariaScreeningResult('insufficient', null, $activityStatus, [], $reasons, []);
    }

    private function rank(string $worstUtilization): int
    {
        if ($this->math->compare($worstUtilization, '50') <= 0) {
            return 5;
        }
        if ($this->math->compare($worstUtilization, '70') <= 0) {
            return 4;
        }
        if ($this->math->compare($worstUtilization, '85') <= 0) {
            return 3;
        }
        if ($this->math->compare($worstUtilization, '95') <= 0) {
            return 2;
        }

        return 1;
    }
}
