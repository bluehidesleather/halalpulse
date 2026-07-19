<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

use InvalidArgumentException;

final readonly class MultibaggerMethodology
{
    /** @param array<string, mixed> $definition */
    public function __construct(
        public int $id,
        public string $version,
        public string $name,
        public string $effectiveDate,
        public string $verifiedBy,
        public string $verificationNote,
        public string $methodologyHash,
        public bool $isActive,
        public array $definition,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromDatabase(array $row): self
    {
        $definition = json_decode((string) ($row['definition_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($definition)) {
            throw new InvalidArgumentException('Stored multibagger methodology is invalid.');
        }
        $validator = new MultibaggerMethodologyValidator();
        $validated = $validator->validate($definition);
        $storedHash = (string) ($row['methodology_hash'] ?? '');
        if (strlen($storedHash) !== 64 || !hash_equals($storedHash, $validator->hash($validated))) {
            throw new InvalidArgumentException('Stored multibagger methodology does not match its SHA-256 identity.');
        }

        return new self(
            id: (int) $row['id'],
            version: $validated['version'],
            name: $validated['name'],
            effectiveDate: $validated['effective_date'],
            verifiedBy: $validated['verified_by'],
            verificationNote: $validated['verification_note'],
            methodologyHash: $storedHash,
            isActive: (int) $row['is_active'] === 1,
            definition: $validated,
        );
    }

    /** @return list<array<string, mixed>> */
    public function factors(): array
    {
        return $this->definition['factors'];
    }

    /** @return list<string> */
    public function factorKeys(): array
    {
        return array_map(static fn (array $factor): string => $factor['key'], $this->factors());
    }
}
