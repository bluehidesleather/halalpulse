# Production and operations readiness

## Purpose

HalalPulse separates successful code deployment from a fully operational research system. A green test suite proves that the software behaves as designed; it does not prove that external sources are fresh, religious policy evidence is verified, backups are recent, or a consenting Telegram recipient exists.

The authenticated **Operations** page and `cron/release-readiness.php` provide one fail-closed report across those boundaries.

## Gates

### Runtime

Requires:

- `app.environment` set to `production`;
- HTTPS enforcement and secure session cookies;
- at least one active administrator; and
- a real HTTPS application URL rather than the example domain.

A Hostinger temporary domain is technically runnable but remains a warning until replaced with the permanent domain.

### Ingestion

Requires:

- official NSE Integrated Filing RSS enabled;
- a successful or cooldown-skipped integrated run within 20 minutes;
- zero failed integrated feed items; and
- healthy recent runs for every optional legacy or government source that has been enabled.

Disabled legacy NSE/BSE browser adapters are shown as coverage warnings, not silently treated as active. At least one successfully probed government source is required for the macro-tailwind layer.

### Screening

Requires an active clause-level Sharia policy that passed the non-mutating policy readiness check and was independently reviewed before installation.

### Ranking

Requires:

- the screening gate;
- an active reviewed multibagger methodology;
- at least one enabled healthy official government source; and
- company-and-period evidence passing the multibagger readiness gate before an immutable score is recorded.

### Backups

Requires:

- encrypted backups enabled;
- a private passphrase of adequate length;
- a recent backup within `backups.maximum_age_hours`;
- the encrypted file still present and readable; and
- its SHA-256 matching `latest.json`.

Run `cron/check-backups.php --decrypt` for authenticated decryption verification.

### Alerts

Requires:

- alerts enabled;
- a private Telegram bot token;
- a permanent HTTPS application URL;
- at least one consenting active recipient; and
- no unresolved delivery with an `unknown` provider outcome.

Unknown outcomes are blockers because an automatic retry could duplicate a message that Telegram accepted before a network interruption.

## Commands

Human-readable report:

```sh
php cron/release-readiness.php
```

Machine-readable report:

```sh
php cron/release-readiness.php --json
```

The command exits:

- `0` when every gate is operational;
- `1` when one or more operational gates remain incomplete; and
- `2` when the inspection itself cannot run.

An incomplete result does not necessarily mean the code deployment failed. It identifies external evidence, credentials, source freshness, backup, or operational work still required.

## Web dashboard

Sign in and open `/operations.php`. It shows:

- each gate as Ready or Blocked;
- exact blocking checks;
- non-blocking operational warnings;
- current policy and methodology status;
- source freshness;
- research inventory counts;
- alert-recipient and unknown-delivery state; and
- latest encrypted backup metadata without exposing secrets.

## Release discipline

For a production release:

1. merge only a CI-green pull request;
2. create a pre-deployment database and project backup;
3. fast-forward the live checkout to the reviewed commit;
4. run all dependency-free suites and `cron/healthcheck.php`;
5. verify the authenticated web pages;
6. run the official-source workers once;
7. create and decrypt-verify a new encrypted backup; and
8. run `cron/release-readiness.php`.

Do not convert warnings into passing states by hardcoding data, inventing thresholds, weakening evidence requirements, or marking credentials and recipients as present when they are not.
