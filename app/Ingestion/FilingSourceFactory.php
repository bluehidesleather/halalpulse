<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use HalalPulse\Config;
use HalalPulse\Http\HttpClient;
use HalalPulse\Support\JsonLogger;
use InvalidArgumentException;

final class FilingSourceFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly JsonLogger $logger,
    ) {
    }

    public function create(string $exchange): FilingSource
    {
        return match (strtoupper($exchange)) {
            'NSE' => new NseFilingSource(
                http: $this->http,
                mapper: new NseAnnouncementMapper(),
                logger: $this->logger,
                endpoint: $this->config->requireString('sources.nse.endpoint'),
                officialPage: $this->config->requireString('sources.nse.official_page'),
            ),
            'BSE' => new BseFilingSource(
                http: $this->http,
                mapper: new BseAnnouncementMapper(),
                logger: $this->logger,
                endpoint: $this->config->requireString('sources.bse.endpoint'),
                officialPage: $this->config->requireString('sources.bse.official_page'),
                lookbackDays: (int) $this->config->get('sources.bse.lookback_days', 2),
            ),
            default => throw new InvalidArgumentException('Exchange must be NSE or BSE.'),
        };
    }
}
