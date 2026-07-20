<?php

declare(strict_types=1);

namespace HalalPulse\Http;

interface HttpClient
{
    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): HttpResponse;
}
