<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use RuntimeException;

final class TelegramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerCode = null,
        public readonly bool $outcomeKnown = true,
    ) {
        parent::__construct($message);
    }
}
