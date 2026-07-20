# HalalPulse

Private, personal-use intelligence platform for detecting new NSE/BSE quarterly-result filings, screening companies for Sharia compliance, and ranking long-term multibagger potential.

## Locked product rules

- PHP 8.3 and MySQL 8 on the existing shared hosting; no VPS is required.
- Plain PHP with PDO, no framework, no Composer, and no `.env` dependency.
- One lightweight latest-filings request per hour for the legacy NSE/BSE browser adapters; the official NSE Integrated Filing RSS is a separate five-minute feed-level request matching its published TTL. Never poll one URL per company.
- Official exchange and government sources only. Media/news sites are excluded.
- Layer 1: AAOIFI Sharia pass/fail plus compliance rank 1–5.
- Layer 2: separate multibagger score 1–10; scores 1–4 may trigger an alert.
- Telegram Bot alerts are free under Telegram's standard limits and remain disabled until a consenting recipient is registered.
- No secret, password, cookie, bot token, or recipient address belongs in Git.

## Current milestone

Milestone 10 adds a disabled-by-default, integrity-preserving NSE Integrated Filing RSS/XBRL pipeline. It archives source XML privately, stores normalized financial results plus all XBRL facts, retries individual failures, runs automatically every five minutes, and exposes an administrator-only queue button on the dashboard.

## Repository layout

```text
app/                 Application code
cron/                Shared-hosting cron entry points
config/              Safe example configuration
database/            Versioned MySQL schema
docs/                Architecture and operating decisions
public_html/         Web document root
storage/             Runtime logs and temporary files (not committed)
tests/               Dependency-free test harness
```

## Local/shared-hosting setup

1. Install PHP 8.3 with `pdo_mysql`, `curl`, `json`, `mbstring`, `bcmath`, `dom`, `openssl`, and MySQL 8.
2. Create a MySQL database and import `database/schema.sql`. Existing installations apply the missing numbered files in `database/migrations` in order.
3. Copy `config/config.example.php` to `config/config.local.php`.
4. Put real database credentials only in `config/config.local.php`.
5. Run `php cron/generate-app-key.php` and copy its output into `security.app_key` in the local configuration. Never commit that value.
6. Run `php cron/create-admin.php you@example.com "Your Name"` and enter a unique password interactively.
7. For the Sharia module, copy `config/sharia-policy.example.json` to the ignored `config/sharia-policy.local.json`. Verify and fill it from the current official or licensed standard text; do not invent missing values.
8. Activate the reviewed policy with `php cron/install-sharia-policy.php config/sharia-policy.local.json`.
9. Copy `config/multibagger-methodology.example.json` to the ignored `config/multibagger-methodology.local.json`, review every factor and assumption, approve it, then run `php cron/install-multibagger-methodology.php config/multibagger-methodology.local.json`.
10. Apply migrations `006_government_tailwinds.sql`, `007_alert_delivery.sql`, `008_telegram_alerts.sql`, and `009_nse_integrated_rss.sql` in order on an existing installation (fresh installs import the full schema).
11. Run `php tests/run.php` and `php cron/healthcheck.php`.
12. Point the domain document root at this project's `public_html` directory and sign in over HTTPS.
13. Run `php cron/probe-sources.php NSE BSE` from the hosting account. This makes one request to each exchange and does not write to the database.
14. Run `php cron/probe-government-sources.php PIB SEBI RBI MCA BUDGET`; enable only each source that succeeds with plausible official records.
15. Follow `docs/NSE_INTEGRATED_RSS.md`, validate one CLI sync, then configure `sync-nse-integrated.php` every five minutes. Configure the legacy filings, government, and bounded document jobs hourly at different minutes.
16. Follow `docs/ALERT_DELIVERY.md`; keep alerts disabled until the private bot token, `/start` consent, encrypted database recipient, and manual smoke-test gates are complete.

`config/config.local.php` is ignored by Git. Application code, configuration, SQL, logs, and cron files remain outside `public_html`.

## Adapter activation gate

The source endpoints in `config/config.example.php` are observed public website routes, not documented exchange APIs. They remain off by default. See `docs/SOURCE_CONTRACTS.md` for the mapped fields and failure rules and `docs/SHARED_HOSTING_CRON.md` for deployment steps.

See `docs/AUTHENTICATION.md` for the login threat model, first-admin procedure, password reset command, and session behaviour.

See `docs/DOCUMENT_PIPELINE.md` for the PDF allowlist, storage/integrity model, optional text extractor, and mandatory human-review gate.

See `docs/SHARIA_SCREENING.md` for the policy activation gate, exact-decimal formulas, immutable evidence trail, screening behavior, and the distinction between policy compliance and the custom rank.

See `docs/MULTIBAGGER_SCORING.md` for factor weights, score direction, dual valuation, microcap adjustments, official-source rules, Sharia eligibility, and the alert gate.

See `docs/GOVERNMENT_TAILWINDS.md` for the five official contracts, production-host probes, classifier boundary, and human approval workflow.

See `docs/ALERT_DELIVERY.md` for Telegram consent, encrypted recipients, the freshness/idempotency model, private configuration, and safe manual recovery procedure.

See `docs/NSE_INTEGRATED_RSS.md` for the official RSS/XBRL contract, private evidence archive, five-minute worker, dashboard queue button, recovery model, and legal/coverage boundaries.

Personal use only. HalalPulse is a research aid, not financial or religious advice.
