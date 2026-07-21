# HalalPulse

Private, personal-use intelligence platform for detecting new NSE/BSE quarterly-result filings, screening companies for Sharia compliance, and ranking long-term multibagger potential.

## Locked product rules

- PHP 8.3 and MySQL 8 on the existing shared hosting; no VPS is required.
- Plain PHP with PDO, no framework, no Composer, and no `.env` dependency.
- One lightweight latest-filings request per hour for the legacy NSE/BSE browser adapters; the official NSE Integrated Filing RSS is a separate five-minute feed-level request matching its published TTL. Never poll one URL per company.
- Official exchange and government sources only. Media/news sites are excluded.
- Layer 1: AAOIFI Sharia pass/fail plus compliance rank 1–5, with rank 1 the cleanest passing result.
- Layer 2: separate multibagger score 1–10, with score 1 strongest; scores 1–4 may trigger an alert.
- Telegram Bot alerts remain disabled until a private token and consenting recipient are configured and a manual smoke test succeeds.
- Conventional banking-taxonomy filings are retained as excluded source evidence and never enter financial scoring or alerts.
- Structured XBRL values are review candidates, never automatic religious or investment conclusions.
- An AAOIFI policy must cite official AAOIFI material and retain exact clause, numerator, and denominator definitions for every ratio.
- A Sharia screening cannot be recorded until policy, primary-source activity review, and period-specific financial evidence pass the readiness gate.
- A multibagger methodology must retain official evidence requirements and grade anchors for every factor, plus explicit valuation and microcap rules.
- An immutable potential score is recorded only after a company-and-period readiness gate confirms the Sharia pass, every factor, valuation, and risk evidence.
- Database and private evidence backups must be encrypted, authenticated, verified, stored outside `public_html`, and tested through isolated extraction.
- No secret, password, cookie, bot token, recipient address, or backup passphrase belongs in Git.

## Current milestone

Milestone 17 completes the code-level release control plane:

- the multibagger company page now discovers real reporting periods and reports exact score blockers;
- incomplete or stale factor, valuation, risk, macro, or Sharia evidence cannot create an immutable score;
- encrypted streaming backups cover MySQL, private configuration, filing documents, and XBRL archives;
- authenticated backup verification and isolated extraction are available from the command line;
- an authenticated Operations page reports runtime, source, policy, methodology, backup, and alert readiness; and
- `cron/verify-release.php` runs the complete deployment test and health-check suite in one command.

The current structured mapper suggests only `total_revenue` from an NSE total-income fact, or a lower-confidence revenue-from-operations fallback. It does not infer interest-bearing debt, deposits, impermissible income, business permissibility, DCF assumptions, governance quality, factor grades, or investment suitability. A pending candidate remains missing evidence until an administrator reviews and accepts it under an active verified policy.

Code completion does not substitute for external production inputs. Full operational status still requires independently verified Sharia policy text, an independently reviewed multibagger methodology, successfully probed official government sources, a recent encrypted backup, a permanent HTTPS domain, and Telegram credentials/recipient consent when alerts are enabled.

## Repository layout

```text
app/                 Application code
cron/                Shared-hosting cron and operational commands
config/              Safe example configuration
database/            Versioned MySQL schema
docs/                Architecture and operating decisions
public_html/         Web document root
storage/             Private runtime evidence, logs, and backups
tests/               Dependency-free test harness
```

## Local/shared-hosting setup

1. Install PHP 8.3 with `pdo_mysql`, `curl`, `json`, `mbstring`, `bcmath`, `dom`, `openssl`, and MySQL 8.
2. Create a MySQL database and import `database/schema.sql`. Existing installations apply the missing numbered files in `database/migrations` in order.
3. Copy `config/config.example.php` to `config/config.local.php`.
4. Put real database credentials only in `config/config.local.php`.
5. Run `php cron/generate-app-key.php` and copy its output into `security.app_key`. Never commit that value.
6. Run `php cron/create-admin.php you@example.com "Your Name"` and enter a unique password interactively.
7. For the Sharia module, copy `config/sharia-policy.example.json` to the ignored `config/sharia-policy.local.json`. Verify the exact official Standard No. 21 edition, language, clauses, maxima, numerator definitions, and denominator basis; do not invent missing values.
8. Run `php cron/check-sharia-policy.php config/sharia-policy.local.json`. It makes no database changes and must report `[READY]` before activation.
9. Activate the independently reviewed policy with `php cron/install-sharia-policy.php config/sharia-policy.local.json`.
10. Copy `config/multibagger-methodology.example.json` to the ignored `config/multibagger-methodology.local.json` and independently review every factor, evidence requirement, grade anchor, weight, valuation assumption, market-cap band, and microcap adjustment.
11. Run `php cron/check-multibagger-methodology.php config/multibagger-methodology.local.json`. It makes no database changes and must report `[READY]` before activation.
12. Activate the approved methodology with `php cron/install-multibagger-methodology.php config/multibagger-methodology.local.json`.
13. Apply migrations `006_government_tailwinds.sql`, `007_alert_delivery.sql`, `008_telegram_alerts.sql`, `009_nse_integrated_rss.sql`, `010_nse_activity_exclusions.sql`, and `011_sharia_xbrl_candidates.sql` in order on an existing installation. Until migrations 010 and 011 are consolidated into the canonical schema, fresh installations apply both immediately after importing `database/schema.sql`.
14. Configure `backups` in private configuration with a unique passphrase and paths outside `public_html`; then run `php cron/create-backup.php` and `php cron/check-backups.php --decrypt`.
15. Run `php cron/verify-release.php` for all dependency-free suites and the deployment health check.
16. Point the permanent HTTPS domain document root at this project's `public_html` directory and sign in.
17. Run `php cron/probe-sources.php NSE BSE`; enable only adapters that succeed with plausible official records.
18. Run `php cron/probe-government-sources.php PIB SEBI RBI MCA BUDGET`; enable only each source that succeeds with plausible official records.
19. Follow `docs/NSE_INTEGRATED_RSS.md`, validate one CLI sync, then configure `sync-nse-integrated.php` every five minutes. Configure enabled legacy filings, government, bounded document, backup, and alert jobs at separate minutes.
20. Follow `docs/ALERT_DELIVERY.md`; keep alerts disabled until the private bot token, `/start` consent, encrypted database recipient, and manual smoke-test gates are complete.
21. Run `php cron/release-readiness.php` and use the authenticated `/operations.php` dashboard to distinguish completed code from external operational blockers.

`config/config.local.php` is ignored by Git. Application code, configuration, SQL, logs, source evidence, and encrypted backups remain outside `public_html`.

## Verification commands

Complete deployment verification:

```sh
php cron/verify-release.php
```

Encrypted backup lifecycle:

```sh
php cron/create-backup.php
php cron/check-backups.php --decrypt
```

Production readiness report:

```sh
php cron/release-readiness.php
php cron/release-readiness.php --json
```

An incomplete readiness report is not automatically a code failure. It identifies missing policy evidence, source activation, credentials, recipient consent, permanent-domain configuration, or backup freshness.

## Documentation

- `docs/AUTHENTICATION.md` — login threat model, first-admin procedure, password reset, and sessions.
- `docs/DOCUMENT_PIPELINE.md` — official-document allowlist, private storage, integrity, extraction, and human review.
- `docs/SHARIA_SCREENING.md` — policy provenance, exact-decimal screening, XBRL candidates, and evidence readiness.
- `docs/MULTIBAGGER_SCORING.md` — methodology readiness, factor anchors, company evidence readiness, valuation, risks, and alerts.
- `docs/GOVERNMENT_TAILWINDS.md` — official government contracts, probes, classifiers, and human review.
- `docs/ALERT_DELIVERY.md` — Telegram consent, encryption, freshness, idempotency, and manual recovery.
- `docs/NSE_INTEGRATED_RSS.md` — official RSS/XBRL archive, five-minute worker, exclusions, and recovery.
- `docs/BACKUPS.md` — authenticated encryption, scheduling, verification, isolated extraction, and off-host copies.
- `docs/OPERATIONS_READINESS.md` — runtime, ingestion, research, backup, and alert release gates.
- `docs/SOURCE_CONTRACTS.md` and `docs/SHARED_HOSTING_CRON.md` — browser-adapter contracts and shared-host scheduling.

Personal use only. HalalPulse is a research aid, not financial or religious advice.
