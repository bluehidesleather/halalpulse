<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use InvalidArgumentException;

final readonly class ShariaPolicy
{
    /**
     * @param list<array{
     *   key: string,
     *   label: string,
     *   numerator_key: string,
     *   denominator_key: string,
     *   comparison: string,
     *   max_percent: string,
     *   required: bool,
     *   source_clause: string,
     *   numerator_definition: string,
     *   denominator_definition: string
     * }> $ratios
     */
    public function __construct(
        public int $id,
        public string $version,
        public string $name,
        public string $authorityName,
        public string $authorityStandard,
        public string $authorityReferenceUrl,
        public string $effectiveDate,
        public string $verifiedBy,
        public string $verificationNote,
        public string $policyHash,
        public bool $isActive,
        public array $ratios,
        public string $assuranceLevel = 'independently_reviewed',
        public string $disclaimer = 'This policy is a research aid, not a fatwa, and not financial advice.',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromDatabase(array $row): self
    {
        $storedDefinition = json_decode((string) ($row['ratios_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($storedDefinition)) {
            throw new InvalidArgumentException('Stored Sharia policy definition is invalid.');
        }

        $legacy = array_is_list($storedDefinition);
        if ($legacy) {
            $ratios = $storedDefinition;
            $assuranceLevel = 'independently_reviewed';
            $disclaimer = 'This policy is a research aid, not a fatwa, and not financial advice.';
        } else {
            $ratios = $storedDefinition['ratios'] ?? null;
            $assuranceLevel = (string) ($storedDefinition['assurance_level'] ?? '');
            $disclaimer = (string) ($storedDefinition['disclaimer'] ?? '');
        }
        if (!is_array($ratios) || !array_is_list($ratios)) {
            throw new InvalidArgumentException('Stored Sharia policy ratios are invalid.');
        }

        foreach ($ratios as $index => $ratio) {
            if (!is_array($ratio)) {
                throw new InvalidArgumentException('Stored Sharia policy ratio is invalid.');
            }
            if (!isset($ratio['comparison'])) {
                $ratio['comparison'] = 'maximum';
            }
            $ratios[$index] = $ratio;
        }

        $payload = [
            'version' => (string) ($row['version'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'authority_name' => (string) ($row['authority_name'] ?? ''),
            'authority_standard' => (string) ($row['authority_standard'] ?? ''),
            'authority_reference_url' => (string) ($row['authority_reference_url'] ?? ''),
            'effective_date' => (string) ($row['effective_date'] ?? ''),
            'assurance_level' => $assuranceLevel,
            'verified_by' => (string) ($row['verified_by'] ?? ''),
            'verification_note' => (string) ($row['verification_note'] ?? ''),
            'disclaimer' => $disclaimer,
            'approved_for_use' => true,
            'ratios' => $ratios,
        ];
        $validator = new ShariaPolicyValidator();
        $validated = $validator->validate($payload);
        $storedHash = (string) ($row['policy_hash'] ?? '');
        $computedHash = $legacy ? $validator->legacyHash($validated) : $validator->hash($validated);
        if (strlen($storedHash) !== 64 || !hash_equals($storedHash, $computedHash)) {
            throw new InvalidArgumentException('Stored Sharia policy content does not match its SHA-256 identity.');
        }

        return new self(
            id: (int) $row['id'],
            version: $validated['version'],
            name: $validated['name'],
            authorityName: $validated['authority_name'],
            authorityStandard: $validated['authority_standard'],
            authorityReferenceUrl: $validated['authority_reference_url'],
            effectiveDate: $validated['effective_date'],
            verifiedBy: $validated['verified_by'],
            verificationNote: $validated['verification_note'],
            policyHash: $storedHash,
            isActive: (int) $row['is_active'] === 1,
            ratios: $validated['ratios'],
            assuranceLevel: $validated['assurance_level'],
            disclaimer: $validated['disclaimer'],
        );
    }

    public function isResearch(): bool
    {
        return $this->assuranceLevel === 'research';
    }

    public function statusLabel(string $status): string
    {
        if (!$this->isResearch()) {
            return ucfirst($status);
        }

        return match ($status) {
            'passed' => 'Research pass',
            'failed' => 'Research fail',
            default => 'Insufficient evidence',
        };
    }

    /** @return list<string> */
    public function inputKeys(): array
    {
        $keys = [];
        foreach ($this->ratios as $ratio) {
            $keys[$ratio['numerator_key']] = true;
            $keys[$ratio['denominator_key']] = true;
        }

        return array_keys($keys);
    }
}
