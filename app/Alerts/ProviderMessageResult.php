<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

final readonly class ProviderMessageResult
{
    public function __construct(
        public string $messageId,
        public string $status,
    ) {
    }
}
