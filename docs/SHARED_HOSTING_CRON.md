# Shared-hosting cron setup

HalalPulse uses a five-minute command for the official NSE Integrated Filing RSS plus hourly commands for the legacy exchange, government, document, and alert workflows. It also uses daily bounded maintenance for encrypted backups and login-attempt retention. MySQL advisory locks prevent overlapping source and backup runs.

## Before scheduling

1. Select PHP 8.3 in the hosting control panel and enable `pdo_mysql`, `curl`, `json`, `mbstring`, `bcmath`, `dom`, and `openssl`.
2. Keep the project outside `public_html`, except for the supplied `public_html` directory.
3. Create `config/config.local.php` from the example and add the real database credentials there.
4. Generate and configure the private application key, then create the administrator as described in `AUTHENTICATION.md`.
5. Import `database/schema.sql`, then apply every missing numbered migration through `012_user_auth_version.sql` to an existing database.
6. Verify and activate the ignored local Sharia policy as described in `SHARIA_SCREENING.md`; the example intentionally has no usable thresholds.
7. Review and activate the ignored local multibagger methodology as described in `MULTIBAGGER_SCORING.md`.
8. Run the tests, health check, source probes, and one manual poll in that order.
9. Probe all five government adapters separately and enable only the contracts that pass, as described in `GOVERNMENT_TAILWINDS.md`.

## NSE Integrated Filing schedule

Choose “every five minutes” and use the host's displayed PHP 8.3 executable and absolute project paths:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/sync-nse-integrated.php
```

The worker makes one feed request per run and downloads only newly discovered or retry-due official XBRL documents. It also claims the authenticated dashboard’s queued manual request. Scheduled and manual work share one advisory lock and cannot overlap.

On Hostinger CloudLinux, the PHP 8.3 CLI is commonly `/opt/alt/php83/usr/bin/php`; verify it with `-v` rather than copying the path blindly.

## Hourly schedule

Choose “once per hour” in the control panel. Use the host's displayed PHP 8.3 executable path and the absolute project path. A typical command shape is:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/poll-filings.php
```

Add a second command 10–15 minutes later for a small document batch:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/process-documents.php --limit=3
```

Add the government poll at a different minute in the same hourly window:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/poll-government-announcements.php
```

After private Telegram Bot setup, explicit recipient consent, and a successful manual alert run, add alert delivery later in the hourly window:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/send-alerts.php
```

## Daily maintenance

Schedule login-attempt retention once per day at a quiet time:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/prune-login-attempts.php
```

The command removes at most the configured number of oldest rows that are beyond the retention cutoff. It preserves recent rows used by throttling and never performs an unbounded cleanup inside a login request.

After private backup configuration and a successful manual encrypted backup/decryption test, schedule backup creation at a different daily time:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/create-backup.php
```

Keep these jobs at different minutes. Do not copy placeholder paths literally. The hosting control panel normally displays both the account home path and the PHP executable path.

## Expected output

Success returns JSON with top-level status `succeeded` and one result per enabled exchange. A failure returns exit code 1 and leaves the failed exchange checkpoint unchanged. One exchange failure does not prevent the other exchange from being attempted in the same hourly run.

If the command says `not_configured`, both source flags are still false. This is safe and expected before the source probes pass.

The document command makes requests only for newly discovered quarterly-result candidates with an official attachment. It does not poll every company. If the host lacks `pdftotext` or disables `proc_open`, downloads still complete and the dashboard marks those PDFs for manual review.

The government command makes at most one request per enabled publisher each hour. It performs no automatic approval: announcements remain in the authenticated Tailwinds queue until a human recordss a reviewed decision.

The NSE Integrated worker returns `succeeded`, `partial`, `skipped`, or `not_configured`. A partial result means the feed was safely recorded but at least one XBRL remains in the retry queue. See `NSE_INTEGRATED_RSS.md` before enabling it.

The alert command defaults to one message per active recipient per run. It sends nothing unless every current Sharia, methodology, score, factor, valuation, risk, government-evidence, and recipient-consent gate passes. See `ALERT_DELIVERY.md`; the recipient must start the Telegram bot before registration.
