# NSE Integrated Filing Financials sync

HalalPulse can ingest the official NSE `INTEGRATED_FILING_FINANCIALS` RSS feed and the XBRL documents linked by that feed. This path is separate from the older browser JSON adapter.

## Source contract

- NSE RSS directory: `https://www.nseindia.com/static/rss-feed`
- Feed: `https://nsearchives.nseindia.com/content/RSS/Integrated_Filing_Financials.xml`
- Expected channel title: `INTEGRATED_FILING_FINANCIALS`
- Expected feed TTL: five minutes
- Accepted document host: `nsearchives.nseindia.com`
- Accepted document path: `/corporate/xbrl/INTEGRATED_FILING_*_WEB.xml`

The client uses plain HTTPS GET requests with a descriptive user agent. It does not rotate identities, forge NSE cookies, solve challenges, bypass access controls, or scrape one page per company. Redirects, third-party links, query-string substitutions, DTDs, external entities, oversized responses, and malformed XML fail closed.

NSE can change or withdraw this publication contract. Use is therefore limited to the private research installation unless a separate redistribution licence and legal review permit broader publication. The software cannot promise “no legal issue”; the operator remains responsible for NSE terms, copyright/database rights, SEBI obligations, and what is exposed to end users.

## Integrity pipeline

Each run follows this order:

1. Acquire a MySQL advisory lock so scheduled and manual runs cannot overlap.
2. Download, validate, hash, and privately archive the RSS XML.
3. Persist every valid feed item before downloading its XBRL. This preserves retry work even after an item rotates out of the short feed.
4. Download only strict official archive URLs.
5. Reject unsafe XML and parse the XBRL without network entity resolution.
6. Archive the original XBRL atomically with a SHA-256 checksum outside `public_html`.
7. Store the company, filing, normalized financial result, and every reportable XBRL fact in one database transaction.
8. Retry an individual failed XBRL with bounded exponential delay without losing successful items.

`financial_results` contains normalized fields used by the application. `xbrl_facts` retains the complete fact set with context, unit, decimals, and occurrence, so unmapped taxonomy fields are not discarded. The original RSS and XBRL bytes are also retained. This is a strong integrity design, but “absolute zero loss” still requires database and private-storage backups plus reconciliation against the exchange.

## Automatic and manual sync

The authoritative worker is:

```sh
/path/to/php83 /home/ACCOUNT/halalpulse/cron/sync-nse-integrated.php
```

Run it every five minutes, matching the published feed TTL. The authenticated dashboard’s **Sync data now** button inserts a database request. It never runs the network pipeline in the web request. The next cron invocation claims the request and labels the audited run `manual`; absent a request, the same worker runs as `scheduled`.

The button has a five-minute cooldown, requires an administrator session and CSRF token, and is disabled if the feature or migration is unavailable.

## Activation on an existing installation

1. Back up MySQL and the project directory.
2. Apply `database/migrations/009_nse_integrated_rss.sql` in phpMyAdmin or the MySQL client.
3. Pull/deploy the reviewed code.
4. Add the `sources.nse_integrated_rss` block from `config/config.example.php` to the ignored `config/config.local.php`.
5. Confirm `nsearchives.nseindia.com` is in `polling.allowed_hosts`.
6. Confirm `storage/xbrl` exists, is writable by PHP/cron, and is outside `public_html`.
7. Run `php tests/run.php` and `php cron/healthcheck.php`.
8. Run the worker once with `enabled => true` and inspect its JSON, private archive, database rows, and dashboard status.
9. Add the five-minute cron only after the manual CLI run succeeds.

## Reconciliation and recovery

- Run database and private archive backups at least daily.
- Review partial/failed runs and rows in `nse_integrated_feed_items` whose status is `failed`.
- Periodically reconcile stored source URLs/counts against NSE’s official financial-results export, because the RSS feed is a recent-update stream rather than a guaranteed historical catalogue.
- If the feed identity, host, path pattern, or XML contract changes, leave the source disabled until code, fixtures, and a production-host probe are reviewed.
- Do not delete a failed row to force a retry. Its retry timestamp and error provide the audit trail.

This feature covers NSE Integrated Filing Financials. It does not by itself provide complete BSE coverage, PDF-only notes, historical backfill for all listed companies, or licensed redistribution rights.
