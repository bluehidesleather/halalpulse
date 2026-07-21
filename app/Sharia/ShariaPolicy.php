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
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromDatabase(array $row): self
    {
        $ratios = json_decode((string) ($row['ratios_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($ratios) || !array_is_list($ratios)) {
            throw new InvalidArgumentException('Stored Sharia policy ratios are invalid.');
        }

        $payload = [
            'version' => (string) ($row['version'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'authority_name' => (string) ($row['authority_name'] ?? ''),
            'authority_standard' => (string) ($row['authority_standard'] ?? ''),
            'authority_reference_url' => (string) ($row['authority_reference_url'] ?? ''),
            'effective_date' => (string) ($row['effective_date'] ?? ''),
            'verified_by' => (string) ($row['verified_by'] ?? ''),
            'verification_note' => (string) ($row['verification_note'] ?? ''),
            'approved_for_use' => true,
            'ratios' => $ratios,
        ];
        $validator = new ShariaPolicyValidator();
        $validated = $validator->validate($payload);
        $storedHash = (string) ($row['policy_hash'] ?? '');
        if (strlen($storedHash) !== 64 || !hash_equals($storedHash, $validator->hash($validated))) {
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
        );
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
