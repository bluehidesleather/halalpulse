# Security policy

HalalPulse is a private, personal-use research system whose source repository is publicly visible. Treat every credential, policy file, filing archive, recipient record, database export, encrypted backup, and production path as private operational material.

## Reporting a vulnerability

Do not include credentials, private filings, personal data, database contents, or exploitable production details in a public issue.

Use GitHub private vulnerability reporting from the repository Security tab when available. Otherwise, contact the repository owner through an already established private channel and provide only the minimum information needed to reproduce the problem safely.

## Material that must never be committed

- `config/config.local.php` and local policy or methodology files
- database passwords, application keys, bot tokens, recipient identifiers, cookies, or backup passphrases
- private-key material or credential containers
- runtime documents, XBRL archives, logs, database dumps, or encrypted backups
- Hostinger account paths, temporary-domain references, or production-only infrastructure details

The command below audits the tracked Git inventory for these high-confidence risks:

```sh
php cron/audit-repository.php
```

The same audit runs in continuous integration and as part of `cron/verify-release.php`.

## Secret exposure response

When a secret may have entered Git history:

1. Revoke or rotate the credential immediately; deleting the file is not sufficient.
2. Disable affected integrations until the replacement credential is installed privately.
3. Remove the value from the current tree and assess whether history rewriting is required.
4. Review access logs, delivery records, and administrative activity for misuse.
5. Re-run the repository audit and the complete release verification.

## Production boundary

Security checks do not activate Sharia policy, investment methodology, Telegram recipients, source adapters, backups, or a permanent domain. Those remain fail-closed until their independent evidence, credentials, and production-host checks are complete.
