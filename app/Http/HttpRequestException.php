<?php

declare(strict_types=1);

namespace HalalPulse\Http;

use RuntimeException;

final class HttpRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $statusCode = null)
    {
        parent::__construct($message);
    }
}
