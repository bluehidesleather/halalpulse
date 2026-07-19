# HalalPulse architecture — Milestone 9

## Why this foundation exists

HalalPulse is event-driven. It must detect a newly published result first and only then perform deeper company analysis. Polling thousands of companies independently would be wasteful, more likely to be blocked, and contrary to the low-cost design.

```text
NSE latest filings (1 request/hour) ─┐
                                   ├─ normalize ─ deduplicate ─ classify ─ queue
BSE latest filings (1 request/hour) ─┘
```

The queue feeds document extraction and human evidence review. The Sharia module consumes only explicitly accepted inputs under an activated policy, and the separate potential score consumes only a current Sharia pass. Government announcements use their own ingest/classify/review boundary. Alert delivery consumes only the latest fully current score and reserves an idempotency record before contacting Telegram.

## Source policy

- Exchange disclosures: official NSE and BSE filing pages/routes only.
- Macro/regulatory sources: PIB, MCA, SEBI, RBI, and Union Budget publications only.
- Media articles, tip services, social posts, and third-party stock applications are not source-of-truth inputs.
- Public website routes may change or apply anti-bot controls. Each exchange therefore receives its own adapter behind `FilingSource`.
- Adapter endpoint, headers, cookies, retry behaviour, and field mappings must be verified and documented before activation.
- The system must identify itself, use timeouts and bounded retries, respect rate limits, and stop on repeated failures.

The current client performs one attempt per scheduled exchange request. It allows HTTPS only, permits only configured exchange hosts, refuses redirects, limits response bytes, and applies a hard timeout. This preserves the one-request-per-exchange hourly budget and prevents an exchange redirect from escaping the official-source allowlist.

## Ingestion invariants

1. `source_id + exchange` is the filing identity and is unique.
2. Raw source payloads are retained for auditability.
3. A SHA-256 payload hash supports change detection.
4. Checkpoints advance only after a successful database transaction.
5. Classifier output is a candidate flag, not proof that the attachment contains usable financial statements.
6. No Sharia or investment score is calculated until source data and units are validated.

## Security boundary

- Web root is `public_html/`; application, configuration, storage, and SQL remain outside it.
- Real configuration is `config/config.local.php`, excluded from Git.
- Secrets must never appear in code, logs, database dumps, pull requests, or screenshots.
- Production logs are JSON lines with restricted filesystem permissions and must not contain credentials or full raw HTTP headers.
- The website is private by default. Every application page except the login form requires an active administrator session.
- Session cookies are host-only, `Secure`, `HttpOnly`, and `SameSite=Strict`; authenticated sessions have both idle and absolute expiration.
- All state-changing web forms require a per-session CSRF token.
- Login throttling keys are HMACs of the normalized email and client IP; raw login identities and IP addresses are not stored in the throttle table or logs.
- Passwords use Argon2id when the PHP build provides it and otherwise use PHP's current `PASSWORD_DEFAULT` algorithm.

## Planned modules

1. Foundation and filing ingestion boundary. Completed.
2. Disabled-by-default NSE/BSE adapters, fixture contracts, probe command, and hourly cron. Completed; hosting smoke test required before activation.
3. Secure single-admin login, private dashboard, filings ledger, and password management. Completed.
4. Private filing-document acquisition, optional text extraction, and human metric review. Completed; hosting extractor availability must be probed.
5. Policy-driven Sharia engine with explicit formula versioning and source evidence. Completed; a verified local policy is deliberately not bundled.
6. Multibagger engine with factor-level scores and complete audit trail. Completed; factor evidence remains human-reviewed.
7. Official-government announcement ingestion and reviewed sector tagging. Completed; every live adapter still requires a shared-host probe.
8. Provider-isolated alert delivery with idempotency and freshness gates. Completed.
9. Free Telegram Bot transport with encrypted database recipients, explicit consent, discovery command, and provider-neutral audit identifiers. Completed; private token, recipient `/start`, and hosting smoke test are still required.

## Shared-hosting deployment model

The hosting control-panel cron calls the polling command once every hour. Each run checks the latest NSE stream once and the latest BSE stream once. A MySQL advisory lock prevents overlapping runs.

There are no resident workers, process supervisors, Redis queues, framework schedulers, or VPS-only services. New candidate filings are recorded in MySQL and later processing commands will work through them in small, restart-safe batches that respect shared-hosting execution limits.

Only `public_html` is web-accessible. Application code, configuration, SQL, cron commands, tests, and logs remain outside the web root. The same domain code can later move to a VPS without changing the database model or source-adapter contracts.

## Document evidence model

Only quarterly-result candidate filings with an official attachment are queued. A second advisory lock prevents overlapping document runs. Downloads are limited to approved exchange archive hosts, refuse redirects, enforce byte/time limits, require a PDF signature, and are written atomically outside the web root. A stored document is opened only through an authenticated controller that recomputes its SHA-256 before streaming.

Text extraction is an optional capability. If a safe local `pdftotext` binary and `proc_open` are unavailable, the PDF remains usable for manual review. Detected financial values are low-confidence candidates with evidence snippets; explicit administrator acceptance is required, and acceptance alone still does not authorize downstream scoring.

## Sharia policy and screening model

The software boundary is policy-driven because standards and interpretations can change. An administrator verifies a local JSON policy against the current official or licensed text and activates it with a CLI command. The installer validates every field, refuses blank thresholds or an unapproved policy, hashes the canonical content, deactivates the previous policy transactionally, and retains historical policy rows.

Business-activity reviews and screenings are append-only. Financial inputs retain superseded versions. A screening snapshot contains the exact policy, activity classification, evidence values, normalized base-unit values, ratio results, decision reasons, and administrator attribution used at calculation time.

`bcmath` is mandatory. There is no binary floating-point comparison or permissive fallback. Missing policy, incomplete evidence, mixed activity, currency mismatch, and invalid denominators produce `insufficient`; they never silently pass. The HalalPulse 1–5 rank is calculated only after a pass and is explicitly separate from both the authority standard and the potential score.

## Multibagger research model

The second layer consumes only a same-period Sharia pass under the active policy. An active, hash-verified methodology supplies 12 integer weights totaling 100%, fixes score direction to 1-best/10-weakest, requires dual valuation, and fixes the downstream alert ceiling at 4.

Factor, valuation, and risk reviews are append-only with superseded history. Every score stores its methodology, Sharia screening, weighted factor evidence, Graham/DCF snapshot, market-cap classification, microcap adjustments, reasons, user, and timestamp. Missing evidence produces no numeric score. `alert_eligible` is only an intermediate gate; the separate delivery module revalidates freshness before any submission.

Only approved exchange, regulator, and government HTTPS hosts may be stored as research evidence. The macro factor accepts only a current strong/moderate human review of a stored PIB, SEBI, RBI, MCA, or Union Budget announcement. Its official URL is copied from the immutable source record. Machine classification is capped and never approves a factor.

## Government evidence model

PIB and RBI use official RSS feeds. SEBI, MCA, and Union Budget use marker-checked HTML listing contracts with matching link paths and source-specific official-host allowlists. Each source remains disabled until its production-host probe maps a plausible non-zero result. MySQL advisory locks and a one-hour checkpoint enforce the shared-host request budget.

Announcements, payload hashes, classifier suggestions, append-only human decisions, and poll history are stored separately. A superseded human decision invalidates that macro factor for future scoring. Historical score snapshots remain immutable.

## Alert delivery model

Alert selection revalidates the latest score against the active methodology and policy, latest same-period Sharia screening, unchanged evidence reviews, and current approved government evidence. A unique score/channel/recipient-HMAC key prevents the scheduled job from resubmitting the same candidate.

The Telegram bot token lives only in ignored local configuration. Recipient chat IDs are encrypted in MySQL with AES-256-GCM under a key derived from the stable private application key. Consent, activation, recipient HMAC, message hash, provider message ID/status, attempts, and sanitized errors are database-managed. Known rejection and unknown outcomes are distinct. Neither is automatically retried; ambiguous outcomes require inspection of the destination chat and explicit CLI confirmation.
