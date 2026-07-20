# HalalPulse production-readiness checklist

Version: 0.9.0  
Target: private personal-use deployment on PHP 8.3, MySQL 8, and hourly shared-hosting cron

Do not enable live polling or Telegram delivery until every applicable gate below passes. Keep command output and screenshots private because they may reveal hosting paths, account names, or operational metadata.

## 1. Release gate

- [ ] The pull request into `main` has passed the `HalalPulse CI` workflow.
- [ ] The reviewed commit is the commit being deployed.
- [ ] `config/config.local.php`, local policy files, logs, downloaded documents, database exports, passwords, tokens, cookies, and Telegram chat IDs are absent from Git.
- [ ] The deployed host provides PHP 8.3 with `pdo_mysql`, `curl`, `json`, `mbstring`, `bcmath`, `dom`, and `openssl`.
- [ ] The deployed database is MySQL 8 using `utf8mb4`.

## 2. Safe filesystem layout

- [ ] The project root is outside the public web root.
- [ ] The domain document root points only to the supplied `public_html` directory.
- [ ] `storage/logs`, `storage/cache`, `storage/tmp`, `storage/documents`, and `storage/xbrl` are writable by the PHP/cron account and are not web-accessible.
- [ ] HTTPS is active before the first login and `app.force_https` remains `true`; confirm an `http://` request is refused and an `https://` response includes `Strict-Transport-Security`.
- [ ] Directory listing is disabled and the supplied `public_html/.htaccess` is effective.
- [ ] `robots.txt` disallows all crawling and authenticated/login responses include `X-Robots-Tag: noindex, nofollow, noarchive`.

## 3. Private configuration and database

1. Copy `config/config.example.php` to the ignored `config/config.local.php`.
2. Set the real database host, port, name, user, and password only in the local file. Keep `database.session_timezone` at `+05:30` unless the entire India-market timestamp model is deliberately changed.
3. Generate a stable application key:

   ```sh
   php cron/generate-app-key.php
   ```

4. Store the generated value in `security.app_key`; never paste it into chat or Git.
5. Import `database/schema.sql` on a fresh database. Existing installations apply missing numbered migrations in order.
6. Create the single administrator interactively:

   ```sh
   php cron/create-admin.php you@example.com "Your Name"
   ```

7. Run the local validation commands:

   ```sh
   php tests/run.php
   php cron/healthcheck.php
   ```

- [ ] Every test passes.
- [ ] Every health-check row reports `PASS`.
- [ ] The private configuration and database have been backed up through the hosting control panel.

## 4. Policy and methodology activation

- [ ] `config/sharia-policy.local.json` was prepared from the current official or licensed authority text, reviewed, approved, and activated. The intentionally incomplete example was not activated.
- [ ] The policy hash, reviewer, reference, thresholds, and effective date were recorded.
- [ ] `config/multibagger-methodology.local.json` was reviewed factor by factor, approved, and activated.
- [ ] A synthetic or non-investment test company was taken through the evidence, Sharia, valuation, risk, scoring, and audit-history screens before real research use.

## 5. Live source contract gate

Run probes from the actual shared-hosting account. These routes are public website contracts, not guaranteed exchange APIs.

```sh
php cron/probe-sources.php NSE BSE
php cron/probe-government-sources.php PIB SEBI RBI MCA BUDGET
```

- [ ] Each enabled source returned a plausible official record count and timestamp.
- [ ] A source returning blocked HTML, a consent page, a changed structure, an implausible timestamp, or zero records unexpectedly remains disabled.
- [ ] One manual `poll-filings.php` run stored duplicate-safe records and updated its checkpoint.
- [ ] One small `process-documents.php --limit=1` run either stored a valid private PDF or failed safely for manual review.
- [ ] One manual government poll stored announcements without automatically approving them as investment evidence.

For the official NSE Integrated Filing feed, separately complete `NSE_INTEGRATED_RSS.md`: apply migration 009, enable the private source block, run one CLI sync, verify RSS/XBRL checksums and database rows, then add its five-minute cron. Confirm the dashboard button only queues work and that repeated runs do not duplicate filings.

## 6. Telegram gate

- [ ] The bot was created with `@BotFather`, and its token exists only in `config/config.local.php`.
- [ ] The intended recipient opened the bot and sent `/start`.
- [ ] `cron/discover-telegram-chats.php` was run privately and no token or chat ID was copied into logs, Git, screenshots, or chat.
- [ ] The recipient explicitly consented and was registered through the authenticated Alerts page; the database contains only the encrypted address and keyed identity hash.
- [ ] A manual eligible test produced exactly one accepted Telegram message.
- [ ] Repeating the same alert command did not create a duplicate submission.
- [ ] `alerts.enabled` remains `false` until all preceding checks pass.

## 7. Schedule and observation

Configure the official NSE Integrated Filing worker every five minutes. Configure the other jobs hourly and stagger them within the hour:

1. filings poll;
2. government poll;
3. bounded document processing;
4. alert delivery only after the Telegram gate passes.

- [ ] Each command uses the hosting account's real absolute paths.
- [ ] Only the official NSE Integrated Filing RSS worker runs every five minutes; legacy exchange, government, document, and alert jobs remain hourly.
- [ ] The first 24 hours of NSE integrated sync runs, feed-item retries, private XBRL checksums, and normalized results were reviewed.
- [ ] The first 24 hours of private logs, source checkpoints, poll audits, document queue state, and alert delivery state were reviewed.
- [ ] Repeated failures disable the affected adapter until its contract is reviewed; they are not bypassed with scraping tricks or broad retries.

## 8. Launch acceptance

HalalPulse is ready for private research use only when:

- [ ] CI, host tests, and the host health check pass;
- [ ] the official-source probes pass for every enabled adapter;
- [ ] policy and methodology activation records are complete;
- [ ] authentication, session expiry, password reset, CSRF rejection, private PDF access, and logout have been tested over HTTPS;
- [ ] Telegram consent, encryption, idempotency, and manual delivery are confirmed if alerts are enabled;
- [ ] a database/configuration backup and rollback copy of the previously deployed code exist.

HalalPulse is a research aid. Its scores are not financial advice, and its Sharia result is only as valid as the reviewed policy and evidence entered by the administrator.
