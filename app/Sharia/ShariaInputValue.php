<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use InvalidArgumentException;

final readonly class ShariaInputValue
{
    private const SCALE_FACTORS = [
        'one' => '1',
        'thousand' => '1000',
        'lakh' => '100000',
        'million' => '1000000',
        'crore' => '10000000',
    ];

    public function __construct(
        public string $value,
        public string $currency,
        public string $scaleLabel,
        public ?int $sourceDocumentId,
        public string $evidenceNote,
    ) {
        if (!DecimalMath::isDecimal($value)) {
            throw new InvalidArgumentException('Financial input must be a non-negative decimal string.');
        }
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Financial input currency must be a three-letter ISO code.');
        }
        if (!isset(self::SCALE_FACTORS[$scaleLabel])) {
            throw new InvalidArgumentException('Financial input scale is unsupported.');
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            value: (string) ($row['value'] ?? ''),
            currency: strtoupper((string) ($row['currency'] ?? '')),
            scaleLabel: (string) ($row['scale_label'] ?? ''),
            sourceDocumentId: isset($row['source_document_id']) ? (int) $row['source_document_id'] : null,
            evidenceNote: (string) ($row['evidence_note'] ?? ''),
        );
    }

    public function baseValue(DecimalMath $math): string
    {
        return $math->multiply($this->value, self::SCALE_FACTORS[$this->scaleLabel]);
    }

    /** @return array<string, int|string|null> */
    public function snapshot(DecimalMath $math): array
    {
        return [
            'value' => $this->value,
            'currency' => $this->currency,
            'scale_label' => $this->scaleLabel,
            'base_value' => $math->normalize($this->baseValue($math)),
            'source_document_id' => $this->sourceDocumentId,
            'evidence_note' => $this->evidenceNote,
        ];
    }
}
