<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Filing
{
    public function __construct(
        public string $exchange,
        public string $sourceId,
        public string $symbol,
        public string $companyName,
        public string $category,
        public string $subject,
        public DateTimeImmutable $announcedAt,
        public ?string $attachmentUrl,
        public array $rawPayload,
    ) {
        if (!in_array($exchange, ['NSE', 'BSE'], true)) {
            throw new InvalidArgumentException('Exchange must be NSE or BSE.');
        }

        foreach (['sourceId' => $sourceId, 'symbol' => $symbol, 'companyName' => $companyName, 'subject' => $subject] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Filing {$field} cannot be empty.");
            }
        }

        if ($attachmentUrl !== null && filter_var($attachmentUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Attachment URL is invalid.');
        }
    }

    public function payloadHash(): string
    {
        $json = json_encode($this->rawPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }
}
