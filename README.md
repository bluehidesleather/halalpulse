# HalalPulse

Private, personal-use intelligence platform for detecting new NSE/BSE quarterly-result filings, screening companies through an evidence-first Sharia research policy, and ranking long-term multibagger potential.

## Locked product rules

- PHP 8.3 and MySQL 8 on the existing shared hosting; no VPS is required.
- Plain PHP with PDO, no framework, no Composer, and no `.env` dependency.
- One lightweight latest-filings request per hour for the legacy NSE/BSE browser adapters; the official NSE Integrated Filing RSS is a separate five-minute feed-level request matching its published TTL. Never poll one URL per company.
- Official exchange and government sources only. Media/news sites are excluded.
- Layer 1: a clearly labelled Sharia **research** pass/fail plus HalalPulse compliance rank 1–5, with rank 1 the strongest passing buffer. Research results are not a fatwa or independent Sharia certification.
- The versioned research policy applies 30% debt, 30% interest-bearing deposits/investments, 5% impermissible-income, and the owner-defined HalalPulse minimum 30% eligible real operating-assets rule.
- The separate independently reviewed policy workflow remains available for future competent clause-level review.
- Layer 2: separate multibagger score 1–10, with score 1 strongest; scores 1–4 may trigger an alert.
- Telegram Bot alerts remain disabled until a private token and consenting recipient are configured and a manual smoke test succeeds.
- Conventional banking-taxonomy filings are retained as excluded source evidence and never enter financial scoring or alerts.
- Structured XBRL values are review candidates, never automatic religious or investment conclusions.
- A screening cannot be recorded until an active policy, primary-source activity review, and period-specific financial evidence pass the readiness gate.
- A multibagger methodology must retain official evidence requirements and grade anchors for every factor, plus explicit valuation and microcap rules.
- An immutable potential score is recorded only after a company-and-period readiness gate confirms the Sharia research pass, every factor, valuation, and risk evidence.
- Database and private evidence backups must be encrypted, authenticated, verified, stored outside `public_html`, and tested through isolated extraction.
- No secret, password, cookie, bot token, recipient address, or backup passphrase belongs in Git.
- The interface is a light premium research product; Sharia compliance does not make green the brand color. Green is reserved for genuine semantic success states.

## Current milestone

Milestone 22 adds an operational research-screening path without weakening the independent-review boundary:

- a tracked, versioned research policy with explicit assurance and disclaimer metadata;
- maximum and minimum threshold directions using exact decimal arithmetic;
- the HalalPulse minimum 30% real operating-asset substance rule;
- explicit command-line acknowledgement before research activation;
- research-only result labels and operational warnings;
- backward compatibility for previously stored policy hashes;
- NSE total-income evidence mapped to the exact `total_income` denominator;
- dedicated policy, asset-substance, compatibility, MySQL integration, and release checks;
- encrypted streaming backups covering MySQL, private configuration, filing documents, and XBRL archives;
- authenticated backup verification and isolated extraction;
- an authenticated Operations page reporting runtime, source, policy, methodology, backup, and alert readiness;
- tracked-repository auditing for private configuration, backup material, credentials, production paths, and temporary-domain leakage;
- rotating authenticated sessions and account-wide revocation after password changes;
- bounded failed-login retention;
- strict official-source and Telegram transport boundaries; and
- a light ivory, stone, charcoal, bronze, and blue-grey design system.

The structured mapper suggests only `total_income` from a direct NSE total-income fact, or a lower-confidence provisional revenue-from-operations candidate requiring other-income review. It does not infer interest-bearing debt, deposits, impermissible income, market capitalization, eligible operating assets, total assets, business permissibility, DCF assumptions, governance quality, factor grades, or investment suitability.

Research activation does not remove external evidence work. Company-level results still require primary-source business review and accepted period-specific financial inputs. Ranking still requires an independently reviewed multibagger methodology, official government-source coverage, and complete company evidence.

## Repository layout

```text
app/                 Application code
cron/                Shared-hosting cron and operational commands
config/              Safe example and tracked research configuration
database/            Versioned MySQL schema
public_html/         Web document root
docs/                Architecture and operating decisions
storage/             Private runtime evidence, logs, and backups
tests/               Dependency-free test harness
```

## Local/shared-hosting setup

1. Install PHP 8.3 with `pdo_mysql`, `curl`, `json`, `mbstring`, `bcmath`, `dom`, and `openssl`, plus MySQL 8 or compatible MariaDB.
2. Create a database and import `database/schema.sql`. Existing installations apply missing numbered migrations in order.
3. Copy `config/config.example.php` to ignored `config/config.local.php`.
4. Put real database credentials only in `config/config.local.php`.
5. Run `php cron/generate-app-key.php` and copy its output into `security.app_key`. Never commit that value.
6. Run `php cron/create-admin.php you@example.com "Your Name"` and enter a unique password interactively.
7. Activate the tracked research-only Sharia policy only after reading its disclaimer:

```sh
php cron/activate-research-sharia-policy.php --acknowledge-research-only
```

8. For a future independently reviewed policy, copy `config/sharia-policy.example.json` to ignored `config/sharia-policy.local.json`, complete it from the exact official/licensed edition, obtain competent review, run `php cron/check-sharia-policy.php`, and install only after `[READY]`.
9. Copy `config/multibagger-methodology.example.json` to ignored `config/multibagger-methodology.local.json` and independently review every factor, evidence requirement, grade anchor, weight, valuation assumption, market-cap band, and microcap adjustment.
10. Run `php cron/check-multibagger-methodology.php config/multibagger-methodology.local.json`. It makes no database changes and must report `[READY]` before activation.
11. Activate the approved methodology with `php cron/install-multibagger-methodology.php config/multibagger-methodology.local.json`.
12. Apply migrations `006_government_tailwinds.sql`, `007_alert_delivery.sql`, `008_telegram_alerts.sql`, `009_nse_integrated_rss.sql`, `010_nse_activity_exclusions.sql`, `011_sharia_xbrl_candidates.sql`, and `012_user_auth_version.sql` in order on an existing installation. No new migration is required for the research-policy assurance metadata.
13. Configure `backups` privately, then run `php cron/create-backup.php` and `php cron/check-backups.php --decrypt`.
14. Run `php cron/verify-release.php` for all dependency-free suites and deployment health checks.
15. Point the permanent HTTPS domain document root at this project's `public_html` directory and sign in.
16. Run `php cron/probe-sources.php NSE BSE`; enable only adapters that succeed with plausible official records.
17. Run `php cron/probe-government-sources.php PIB SEBI RBI MCA BUDGET`; enable only sources that succeed with plausible official records.
18. Follow `docs/NSE_INTEGRATED_RSS.md`, validate one CLI sync, and configure `sync-nse-integrated.php` every five minutes. Configure other enabled jobs at separate minutes.
19. Follow `docs/ALERT_DELIVERY.md`; keep alerts disabled until the private bot token, `/start` consent, encrypted recipient, and manual smoke-test gates are complete.
20. Run `php cron/release-readiness.php` and use `/operations.php` to distinguish completed code from external operational blockers.

Applying migration 012 intentionally signs out sessions issued by older code because those sessions do not carry an authentication version. Sign in again after deployment; subsequent password changes or resets revoke all older sessions automatically.

`config/config.local.php` is ignored by Git. Application code, configuration, SQL, logs, source evidence, and encrypted backups remain outside `public_html`.

## Verification commands

Complete deployment verification:

```sh
php cron/verify-release.php
```

Research policy checks:

```sh
php tests/sharia-policy-readiness.php
php tests/sharia-research-policy.php
php tests/sharia-evidence-readiness.php
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

An incomplete readiness report is not automatically a code failure. It identifies missing company evidence, methodology review, source activation, credentials, recipient consent, permanent-domain configuration, or backup freshness.

## Documentation

- `docs/AUTHENTICATION.md` — login threat model, first-admin procedure, password reset, sessions, and bounded login-history retention.
- `docs/DESIGN_SYSTEM.md` — light luxury palette, semantic color rules, typography, component direction, and accessibility guard.
- `docs/DOCUMENT_PIPELINE.md` — official-document allowlist, private storage, integrity, extraction, and human review.
- `docs/SHARIA_SCREENING.md` — research versus independently reviewed assurance, strict asset-substance policy, exact-decimal screening, XBRL candidates, and evidence readiness.
- `docs/MULTIBAGGER_SCORING.md` — methodology readiness, factor anchors, company evidence readiness, valuation, risks, and alerts.
- `docs/GOVERNMENT_TAILWINDS.md` — official government contracts, probes, classifiers, and human review.
- `docs/ALERT_DELIVERY.md` — Telegram consent, encryption, freshness, idempotency, and manual recovery.
- `docs/NSE_INTEGRATED_RSS.md` — official RSS/XBRL archive, five-minute worker, exclusions, and recovery.
- `docs/BACKUPS.md` — authenticated encryption, scheduling, verification, isolated extraction, and off-host copies.
- `docs/OPERATIONS_READINESS.md` — runtime, ingestion, research, backup, and alert release gates.
- `docs/SOURCE_CONTRACTS.md` and `docs/SHARED_HOSTING_CRON.md` — browser-adapter contracts and shared-host scheduling.

Personal use only. HalalPulse is a research aid, not financial or religious advice.
