# HalalPulse activation workflows

Version: 1.0.0  
Date: 2026-07-25

## Purpose

The Operations dashboard intentionally distinguishes installed code from production inputs that must remain private, independently reviewed, or consent-based. The commands below reduce manual editing without weakening any readiness gate.

Run every command from the project root with the locked PHP 8.3 binary on Hostinger:

```sh
cd /home/u460884935/halalpulse
```

## Encrypted backups

```sh
/opt/alt/php83/usr/bin/php cron/configure-backups.php
```

The command:

- requires a private interactive terminal;
- reads and confirms the passphrase without terminal echo;
- updates only ignored `config/config.local.php` through an atomic mode-0600 replacement;
- keeps backup storage outside `public_html`;
- creates the first encrypted backup; and
- performs authenticated decryption verification before reporting success.

Store the passphrase separately in a password manager. Losing it makes the encrypted backups unrecoverable. After the first successful backup, configure the daily `cron/create-backup.php` job and periodically run `cron/check-backups.php --decrypt` plus isolated extraction.

## Sharia screening

```sh
/opt/alt/php83/usr/bin/php cron/prepare-sharia-policy.php
/opt/alt/php83/usr/bin/php cron/check-sharia-policy.php
```

The preparation command creates ignored `config/sharia-policy.local.json` with mode 0600 and never overwrites an existing working file. The template is not an approved policy. Replace placeholders only from the exact official/licensed AAOIFI edition, verify every clause and calculation definition, and obtain competent review. Install only after the checker reports `[READY]`:

```sh
/opt/alt/php83/usr/bin/php cron/install-sharia-policy.php config/sharia-policy.local.json
```

Activation unlocks the policy layer; individual companies still require primary-source business review and complete period-specific evidence.

## Multibagger ranking

```sh
/opt/alt/php83/usr/bin/php cron/prepare-multibagger-methodology.php
/opt/alt/php83/usr/bin/php cron/check-multibagger-methodology.php
```

The preparation command creates ignored `config/multibagger-methodology.local.json` with mode 0600 and never overwrites an existing working file. Review every factor, weight, evidence requirement, grade anchor, valuation rule, market-cap band, and microcap adjustment. Install only after independent review and a `[READY]` result:

```sh
/opt/alt/php83/usr/bin/php cron/install-multibagger-methodology.php config/multibagger-methodology.local.json
```

Methodology activation does not manufacture scores. A company must still have a same-period Sharia pass and complete reviewed company evidence.

## Telegram alerts

Telegram preparation is intentionally blocked while the application uses a temporary `hostingersite.com` address. After a permanent HTTPS domain is active:

```sh
/opt/alt/php83/usr/bin/php cron/configure-telegram.php
```

The command validates the origin before asking for the token, stores the token only in ignored private configuration, and leaves automatic alerts disabled. Then:

1. Have the intended recipient open the bot and send `/start`.
2. Run `cron/discover-telegram-chats.php` privately.
3. Register the consenting chat from the Alerts page.
4. Perform a controlled manual delivery test.
5. Enable automatic alerts only after the provider accepts the test and no unknown outcome remains.

## Readiness verification

```sh
/opt/alt/php83/usr/bin/php cron/release-readiness.php
/opt/alt/php83/usr/bin/php cron/verify-release.php
```

The Operations page shows the same categories and the exact next activation command. A blocked research gate is not a software failure when the required policy, methodology, evidence, domain, credential, consent, or backup is genuinely absent.
