<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

final readonly class AlertRecipient
{
    public function __construct(
        public int $id,
        public string $channel,
        public string $label,
        public string $address,
        public string $recipientHash,
    ) {
    }
}
