# Official government tailwinds

Version: 0.7.0  
Last reviewed: 2026-07-19

## Evidence boundary

HalalPulse accepts automatic macro/regulatory announcements only from these primary-source publishers:

- PIB English press-release RSS: `https://pib.gov.in/RssMain.aspx?ModId=6&Lang=1&Regid=1`
- SEBI official press-release listing: `https://www.sebi.gov.in/sebiweb/home/HomeAction.do?doListing=yes&sid=6&smid=0&ssid=23`
- RBI official press-release RSS: `https://rbi.org.in/pressreleases_rss.xml`
- MCA official news updates: `https://www.mca.gov.in/content/mca/global/en/notifications-tender/news-updates.html`
- Union Budget official documents: `https://www.indiabudget.gov.in/`

Media, social posts, aggregators, search snippets, and third-party summaries are never ingested as evidence.

## Three separate decisions

1. The source adapter stores an immutable announcement with its source ID, official URL, publication time, raw normalized payload, and SHA-256 hash.
2. The conservative classifier suggests a sector and direction from explicit phrases. Confidence is capped at 85 and cannot approve anything.
3. A signed-in administrator records an append-only review: strong tailwind, moderate tailwind, neutral, headwind, or not relevant, with a sector and rationale.

Only a current strong/moderate human review appears in the company macro-evidence selector. The company factor record references the exact review, while the official URL is copied from the announcement rather than typed. If that government review is superseded, a future score becomes insufficient until the macro factor is reviewed again.

## Activation

All five adapters are disabled by default. On the shared host:

1. Apply `database/migrations/006_government_tailwinds.sql` to an existing installation.
2. Run `php tests/run.php` and `php cron/healthcheck.php`.
3. Probe one source at a time with `php cron/probe-government-sources.php PIB` (then SEBI, RBI, MCA, and BUDGET).
4. Confirm `succeeded`, a plausible non-zero count, official item URLs, and current timestamps.
5. Enable only each source that passed its live contract probe.
6. Run `php cron/poll-government-announcements.php` manually and review the private logs.
7. Add it as a once-per-hour control-panel cron.

HTML listing contracts require marker text, matching link paths, source-specific official hosts, and a usable date. The Union Budget configured fallback date must be reviewed annually against the new official budget page before enabling that year's adapter.

## Failure behavior

- HTTPS, explicit host allowlists, bounded response sizes, hard timeouts, and no redirects are mandatory.
- Invalid XML/HTML, missing markers, zero valid rows, wrong hosts, or missing dates fail the adapter without advancing its checkpoint.
- Source failures are isolated; one publisher cannot prevent the others from being attempted.
- MySQL advisory locks prevent overlap, and the one-hour database checkpoint prevents accidental over-polling.
- No retry loop is performed inside the scheduled request budget.
