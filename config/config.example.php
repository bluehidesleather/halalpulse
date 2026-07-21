<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'HalalPulse',
        'environment' => 'development',
        'timezone' => 'Asia/Kolkata',
        'force_https' => true,
        'log_path' => dirname(__DIR__) . '/storage/logs/halalpulse.jsonl',
    ],
    'security' => [
        // Generate a private value with: php cron/generate-app-key.php
        'app_key' => '',
        'session_name' => 'halalpulse_session',
        'session_idle_seconds' => 1800,
        'session_absolute_seconds' => 43200,
        'session_rotation_seconds' => 900,
        'login_max_attempts' => 5,
        'login_window_seconds' => 900,
        'login_attempt_retention_seconds' => 604800,
        'login_attempt_prune_max_rows' => 5000,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'halalpulse',
        'user' => 'halalpulse',
        'password' => '',
        'charset' => 'utf8mb4',
        // Named zones are not always installed on shared MySQL; use the India market offset.
        'session_timezone' => '+05:30',
    ],
    'polling' => [
        'nse_interval_seconds' => 3600,
        'bse_interval_seconds' => 3600,
        'request_timeout_seconds' => 20,
        'max_response_bytes' => 8388608,
        'max_header_bytes' => 65536,
        'user_agent' => 'HalalPulse/0.9 (personal research; contact configured privately)',
        'allowed_hosts' => [
            'www.nseindia.com',
            'nsearchives.nseindia.com',
            'api.bseindia.com',
        ],
    ],
    'government_polling' => [
        'interval_seconds' => 3600,
        'request_timeout_seconds' => 20,
        'max_response_bytes' => 8388608,
        'max_header_bytes' => 65536,
        'user_agent' => 'HalalPulse/0.9 (personal research; contact configured privately)',
        'allowed_hosts' => [
            'pib.gov.in',
            'www.pib.gov.in',
            'www.sebi.gov.in',
            'rbi.org.in',
            'www.rbi.org.in',
            'www.mca.gov.in',
            'www.indiabudget.gov.in',
        ],
    ],
    'sources' => [
        'nse_integrated_rss' => [
            // Official RSS/XBRL publication path. Enable only after migration 009 and cron setup.
            'enabled' => false,
            'official_page' => 'https://www.nseindia.com/static/rss-feed',
            'endpoint' => 'https://nsearchives.nseindia.com/content/RSS/Integrated_Filing_Financials.xml',
            // Keep raw source evidence outside public_html.
            'storage_path' => dirname(__DIR__) . '/storage/xbrl',
            'interval_seconds' => 300,
            'manual_cooldown_seconds' => 300,
            'batch_size' => 20,
        ],
        'nse' => [
            'enabled' => false,
            'official_page' => 'https://www.nseindia.com/companies-listing/corporate-filings-announcements',
            // Public website route, not a documented/stable API. Probe before enabling.
            'endpoint' => 'https://www.nseindia.com/api/corporate-announcements?index=equities',
        ],
        'bse' => [
            'enabled' => false,
            'official_page' => 'https://www.bseindia.com/corporates/ann.html',
            // Public website route, not a documented/stable API. Probe before enabling.
            'endpoint' => 'https://api.bseindia.com/BseIndiaAPI/api/AnnSubCategoryGetData/w',
            'lookback_days' => 2,
        ],
    ],
    'government_sources' => [
        // Every adapter is disabled until cron/probe-government-sources.php succeeds on the shared host.
        'pib' => [
            'enabled' => false,
            'format' => 'rss',
            'official_page' => 'https://www.pib.gov.in/ViewRss.aspx?lang=1&reg=1',
            'endpoint' => 'https://pib.gov.in/RssMain.aspx?ModId=6&Lang=1&Regid=1',
            'required_markers' => ['item'],
        ],
        'sebi' => [
            'enabled' => false,
            'format' => 'html',
            'official_page' => 'https://www.sebi.gov.in/sebiweb/home/HomeAction.do?doListing=yes&sid=6&smid=0&ssid=23',
            'endpoint' => 'https://www.sebi.gov.in/sebiweb/home/HomeAction.do?doListing=yes&sid=6&smid=0&ssid=23',
            'category' => 'Press release',
            'required_markers' => ['SEBI', 'Press Releases'],
            'link_path_contains' => ['/media/press-releases/'],
        ],
        'rbi' => [
            'enabled' => false,
            'format' => 'rss',
            'official_page' => 'https://rbi.org.in/Scripts/rss.aspx',
            'endpoint' => 'https://rbi.org.in/pressreleases_rss.xml',
            'required_markers' => ['item'],
        ],
        'mca' => [
            'enabled' => false,
            'format' => 'html',
            'official_page' => 'https://www.mca.gov.in/content/mca/global/en/notifications-tender/news-updates.html',
            'endpoint' => 'https://www.mca.gov.in/content/mca/global/en/notifications-tender/news-updates.html',
            'category' => 'News update',
            'required_markers' => ['Ministry of Corporate Affairs'],
            'link_path_contains' => ['/content/dam/mca/', '/notifications-tender/news-updates/'],
        ],
        'budget' => [
            'enabled' => false,
            'format' => 'html',
            'official_page' => 'https://www.indiabudget.gov.in/',
            'endpoint' => 'https://www.indiabudget.gov.in/',
            'category' => 'Union Budget document',
            'required_markers' => ['Union Budget Documents', 'Budget Highlights'],
            'link_path_contains' => ['/doc/'],
            // Review annually against the official page before enabling the new budget-year contract.
            'default_publication_date' => '2026-02-01',
        ],
    ],
    'alerts' => [
        'enabled' => false,
        'channel' => 'telegram',
        'batch_size' => 1,
        'recipient_limit' => 25,
        'app_base_url' => 'https://halalpulse.example',
        'telegram' => [
            // Create with @BotFather. Keep the real token only in ignored config/config.local.php.
            'bot_token' => '',
            'request_timeout_seconds' => 20,
            'max_request_bytes' => 16384,
            'max_response_bytes' => 1048576,
            'max_header_bytes' => 65536,
        ],
    ],
    'backups' => [
        // Keep false until a unique private encryption passphrase is stored in config.local.php.
        'enabled' => false,
        'storage_path' => dirname(__DIR__) . '/storage/backups',
        'retention_days' => 14,
        'maximum_age_hours' => 30,
        'encryption_passphrase' => '',
        // This wrapper adds --no-tablespaces so a least-privilege database account can create a logical backup.
        'mysqldump_binary' => dirname(__DIR__) . '/bin/mysqldump-no-tablespaces',
        'tar_binary' => '/usr/bin/tar',
        // Paths are relative to the project root and are encrypted before the final backup is published.
        'include_paths' => [
            'config/config.local.php',
            'storage/documents',
            'storage/xbrl',
        ],
    ],
    'documents' => [
        'storage_path' => dirname(__DIR__) . '/storage/documents',
        'batch_size' => 3,
        'request_timeout_seconds' => 30,
        'max_response_bytes' => 15728640,
        'allowed_hosts' => [
            'nsearchives.nseindia.com',
            'archives.nseindia.com',
            'www.nseindia.com',
            'www.bseindia.com',
            'api.bseindia.com',
        ],
        // Optional. Unavailable binaries leave documents ready for manual review.
        'pdftotext_binary' => '/usr/bin/pdftotext',
        'extraction_timeout_seconds' => 30,
    ],
];
