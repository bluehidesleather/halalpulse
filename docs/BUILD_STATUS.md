# HalalPulse build status

Version: 0.9.0 Telegram-alerts checkpoint  
Date: 2026-07-19  
Repository target: `bluehidesleather/halalpulse`

## Locked deployment decision

- Existing shared hosting; no VPS purchase.
- PHP 8.3 and MySQL 8.
- Plain PHP and PDO; no framework, Composer, `.env`, Redis, or resident worker.
- Hosting control-panel cron runs once every hour.
- Each hourly run makes at most one latest-filings request to NSE and one to BSE.
- Secrets stay in ignored `config/config.local.php` and never enter Git.

## Completed through this checkpoint

- Secure application bootstrap and configuration reader.
- Fail-closed web transport: plain HTTP is refused before session startup, HTTPS emits HSTS, and unhandled setup errors are replaced by a generic browser reference.
- PDO database connection factory.
- Normalized NSE/BSE filing value object and source-adapter contract.
- Duplicate-safe filing storage with raw payload hashing.
- Company upsert, source checkpoints, poll audit history, failure tracking, and MySQL advisory locking.
- Conservative quarterly-result candidate classifier with exclusion rules.
- MySQL schema for companies, filings, checkpoints, and poll runs.
- Shared-hosting health-check command.
- Dependency-free classifier test harness.
- `public_html` boundary and structured runtime directories.
- Architecture, source, security, and deployment documentation.
- HTTPS-only cURL client with an explicit NSE/BSE host allowlist, no redirects, bounded response size, and hard timeouts.
- Defensive NSE and BSE announcement mappers with normalized IDs, official attachment-host validation, multiple timestamp formats, and row-level warnings.
- Disabled-by-default NSE and BSE filing sources using one request per poll.
- Database-free `cron/probe-sources.php` command for hosting-account contract checks.
- Hourly `cron/poll-filings.php` command that isolates exchange failures and uses the existing advisory locks/checkpoints.
- Sanitized synthetic NSE/BSE fixtures and mapper tests; no copied live filings are stored in the repository.
- Single-administrator account schema and safe interactive create/reset commands.
- Login/logout with generic credential errors, HMAC-keyed throttling, password rehashing, and audit-safe security events.
- Secure host-only sessions with strict cookies, ID regeneration, idle/absolute expiration, and CSRF protection.
- Responsive private dashboard with ingestion metrics, latest filings, exchange-source health, and honest analysis gating.
- Searchable/filterable filings ledger with paginated results and official attachment links.
- Account-security page for verified password changes.
- Apache/LiteSpeed web-root hardening and a no-inline-script content security policy.
- Restart-safe filing-document queue with stale-run recovery and a MySQL advisory lock.
- Official-host-only PDF client with hard time/byte limits, signature validation, atomic private storage, and SHA-256 metadata.
- Private downloaded document bytes are explicitly ignored by Git; only the empty directory marker is packaged.
- Optional bounded `pdftotext` adapter; unavailable extraction cleanly routes documents to manual review.
- Conservative revenue/income/EBITDA/PBT/net-profit/EPS candidate detection with currency, scale, scope, confidence, and exact evidence snippets.
- Human accept/reject review with CSRF protection and administrator audit attribution.
- Private document operations page, filing evidence page, and authenticated SHA-verified PDF streaming.
- Synthetic financial-text and PDF download tests; no real company statement is embedded.
- Immutable, versioned Sharia policies with canonical SHA-256 identity and transactional activation.
- Deliberately incomplete example policy: no production threshold is bundled, remembered, or guessed.
- Append-only company activity reviews with reviewer, source, rationale, and timestamp.
- Administrator-accepted financial policy inputs with period, currency, unit scale, source PDF, notes, and superseded history.
- Exact-decimal ratio engine using mandatory `bcmath`, including unit normalization and currency checks.
- Fail-closed `passed`, `failed`, and `insufficient` decisions with immutable input, policy, ratio, reason, and user snapshots.
- Separate HalalPulse compliance rank 1–5 for passing results only; it is not presented as an AAOIFI rating.
- Authenticated Sharia queue and company evidence workbench with CSRF-protected review and screening actions.
- Synthetic policy and engine tests covering activation gates, exact boundaries, missing evidence, mismatched currency, activity gates, and custom ranking.
- Versioned multibagger methodology with a canonical hash, exact 100% factor-weight validation, locked 1-best score direction, and locked score-≤4 alert ceiling.
- Twelve-factor review structure covering Piotroski, dual valuation, returns, growth, margins, debt, cash flow, working capital, promoters, institutions, official macro evidence, and relative valuation.
- Same-period active-policy Sharia pass gate; insufficient or stale Sharia evidence cannot receive a multibagger score.
- Exact Graham calculation plus administrator-reviewed DCF assumptions; `undervalued` is true only when both methods agree.
- Large/mid/small/micro/nano classification and explicit micro/nano red/green flag adjustments below ₹500 crore.
- Strict official evidence-host allowlists, with macro sources limited to PIB, SEBI, RBI, MCA, and Union Budget hosts.
- Append-only factor, valuation, risk, and score snapshots with superseded review history and administrator attribution.
- Protected potential queue and company scorecard workbench; alert eligibility remains separate from provider submission.
- Source-specific PIB and RBI RSS adapters plus marker-checked SEBI, MCA, and Union Budget listing adapters.
- Dedicated government-source HTTPS allowlist, no redirects, bounded response sizes, one-hour checkpoint, and per-source MySQL advisory locks.
- Disabled-by-default production-host probe and hourly polling commands; one source failure cannot block the others.
- Immutable announcement records with official URL, normalized raw payload, SHA-256 identity, classifier suggestion, and poll audit history.
- Conservative sector/direction phrase classifier with confidence capped at 85; it cannot approve investment evidence.
- Authenticated Tailwinds queue with CSRF-protected append-only strong/moderate/neutral/headwind/not-relevant reviews.
- Macro factor now requires a current strong/moderate government review; its official URL is copied from the announcement and a superseded review fails closed for future scores.
- Disabled-by-default Telegram Bot client using the official `sendMessage` method with bounded TLS JSON requests and the private bot token kept out of logs and MySQL.
- Alert configuration validates bot-token shape, HTTPS application URL, response limits, per-recipient batch size, and recipient cap.
- Recipient discovery command reads only chat identity metadata after the recipient sends `/start`; it does not print message bodies or the token.
- Authenticated recipient registration requires explicit consent confirmation and encrypts each numeric chat ID with AES-256-GCM under a key derived from the stable private application key.
- Recipient label, activation, consent/verification timestamps, keyed identity hash, and administrator attribution are stored in MySQL; decrypted chat IDs are never displayed or logged.
- Dispatch rechecks the latest score, active methodology/policy, latest same-period Sharia screening, unchanged Sharia/factor/valuation/risk evidence, and current approved macro evidence.
- Unique score/channel/recipient-HMAC reservations prevent scheduled duplicate submissions without repeating the encrypted recipient address or storing the message body.
- Provider-accepted, known-failed, and unknown outcomes are distinct; interrupted reservations recover to unknown and no failure is automatically retried.
- Explicit CLI recovery requires inspection of the Telegram chat, unchanged content hash, current eligibility and recipient identity, duplicate-risk confirmation, and a five-attempt ceiling.
- Authenticated Alerts page manages consented recipients and exposes delivery audit state without credentials or decrypted recipient PII.

## Validation completed

- All 121 PHP files passed grammar parsing.
- All six JSON policy, methodology, and exchange-adapter fixtures passed strict decoding; synthetic RSS/HTML government fixtures were also added.
- CSS delimiter and static internal-link checks passed.
- The full schema contains 24 non-duplicate tables; Telegram recipient/delivery fields and migration `008_telegram_alerts.sql` passed structural checks.
- Every application import and static internal PHP link resolves, and CSS delimiters are balanced.
- No real database password, token, API key, cookie, Telegram chat ID, or bot credential is included.
- No VPS-only dependency or per-minute polling schedule is included.
- GitHub CI now provisions PHP 8.3 and MySQL 8, lints the full PHP tree, runs the dependency-free test suite, imports the canonical schema, and executes the deployment health check.
- Live endpoint tests remain intentionally pending because NSE/BSE and government website contracts must be probed from the actual shared-hosting account before activation.
- NSE/BSE website JSON and all five government source contracts must be probed from the shared-hosting account before activation. Cloud-browser access can differ from production-host access and anti-bot policy.

## Activation gates

Run `php cron/probe-sources.php NSE BSE` on the shared host. Do not enable a source unless it returns `succeeded` with a plausible mapped filing count and timestamp. If either exchange changes its website contract, update only that exchange mapper and fixture before probing again.

Separately, verify a local Sharia policy against the current official or licensed authority text, fill every threshold, approve it, and activate it with `cron/install-sharia-policy.php`. The repository intentionally cannot produce a Sharia pass before that step.

Run the exchange and government probes, activate the reviewed policy/methodology, and complete real evidence workflows before enabling alerts. Telegram delivery must remain disabled until the private bot token, recipient `/start`, explicit consent record, encrypted database registration, HTTPS URL, and successful manual smoke test are all confirmed.

## GitHub publication state

The GitHub App is installed for `bluehidesleather/halalpulse`. Version 0.9.0 is published on `agent/telegram-alerts-v0.9` and is under review in draft pull request #1. The release tree contains the expected source paths and remains isolated from `main`; do not replace or force-push `main`.
