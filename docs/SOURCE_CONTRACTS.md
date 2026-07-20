# Official source contracts

Version: 0.2.0  
Last reviewed: 2026-07-19

## Important status

NSE and BSE expose official corporate-announcement pages. Their browser applications call public JSON routes, but those routes are not documented as stable public APIs. The adapters are therefore disabled in the example configuration and must remain disabled until `cron/probe-sources.php` succeeds from the production shared-hosting account.

The probe performs one GET per selected exchange, maps the response in memory, prints only counts/timestamps, and makes no database changes.

Government adapters use a separate disabled-by-default contract and probe. See `GOVERNMENT_TAILWINDS.md` for the five primary-source routes, RSS/HTML marker rules, source-specific URL allowlists, and human approval gate.

NSE also publishes a distinct official Integrated Filing Financials RSS/XBRL contract. It is not the browser JSON route described below and does not require rotating headers or session-cookie emulation. See `NSE_INTEGRATED_RSS.md` for its strict archive URL, XML, storage, retry, and five-minute scheduling rules.

## NSE

- Official page: `https://www.nseindia.com/companies-listing/corporate-filings-announcements`
- Configured website route: `https://www.nseindia.com/api/corporate-announcements?index=equities`
- Expected envelope: a JSON list, or a list in `data`, `announcements`, or `records`.
- Identity aliases: `seq_id`, `sequenceId`, `broadcastId`, `id`.
- Required aliases: symbol, company name, announcement timestamp.
- Subject aliases: `attchmntText`, `subject`, `headline`, `description`; category is the fallback.
- Attachment hosts: `nsearchives.nseindia.com`, `archives.nseindia.com`, `www.nseindia.com`.

## BSE

- Official page: `https://www.bseindia.com/corporates/ann.html`
- Configured website route: `https://api.bseindia.com/BseIndiaAPI/api/AnnSubCategoryGetData/w`
- Expected envelope: a JSON list, or a list in `Table`, `table`, `data`, or `announcements`. A JSON-encoded `Table` string is also accepted.
- Identity aliases: `NEWSID`, `NEWS_ID`, `newsId`, `id`.
- Required aliases: scrip code, company name, subject, announcement timestamp.
- Attachment aliases: `NSURL`, `ATTACHMENTNAME`, `attachmentUrl`, `attachment`.
- Attachment hosts: `www.bseindia.com`, `api.bseindia.com`.
- Poll window: today plus at most the configured recent lookback; database deduplication handles overlaps.

## Fail-closed rules

- Only HTTPS request URLs on the configured host allowlist are permitted.
- Redirects are rejected rather than followed.
- A response over the configured byte limit is aborted.
- Non-2xx, invalid JSON, or an unknown response envelope fails the poll.
- Individual malformed rows are skipped with schema-only warnings; raw row contents are not logged.
- If a non-empty response maps zero filings, the entire poll fails and its checkpoint does not advance.
- Attachment URLs outside known official exchange hosts are discarded.
- Missing native IDs receive a deterministic SHA-256-based fallback ID.

## Activation checklist

1. Copy the example configuration to the ignored local configuration file.
2. Leave both `enabled` flags false.
3. Run `php tests/run.php` and `php cron/healthcheck.php`.
4. Run `php cron/probe-sources.php NSE`, then `php cron/probe-sources.php BSE`.
5. Confirm both results say `succeeded` and show plausible recent timestamps.
6. Enable only the sources that passed.
7. Run `php cron/poll-filings.php` once manually and inspect its JSON result and the latest log records.
8. Add the hourly control-panel cron only after the manual database-writing run succeeds.
