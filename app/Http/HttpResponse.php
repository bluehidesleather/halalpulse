<?php

declare(strict_types=1);

namespace HalalPulse\Http;

final readonly class HttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? null;

        return is_array($values) && $values !== [] ? $values[0] : null;
    }
}
