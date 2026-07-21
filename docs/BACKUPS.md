# Encrypted backup and recovery

## Boundary

HalalPulse backups contain sensitive database records, the private application configuration, accepted evidence documents, and archived NSE XBRL source files. They must remain outside `public_html` and must never be committed to Git.

The backup command creates a local encrypted recovery bundle. It does not copy the bundle off the hosting account automatically. A second independent storage location is still required to survive hosting-account loss.

## Format

`cron/create-backup.php` creates one file named like:

```text
halalpulse-YYYYMMDD-HHMMSSZ-COMMIT.hpbak
```

The envelope uses:

- a unique random salt;
- PBKDF2-HMAC-SHA-256 with 200,000 iterations;
- a 256-bit key;
- independently authenticated AES-256-GCM chunks;
- unique random nonces per chunk;
- a SHA-256 identity for the encrypted file;
- authenticated decryption verification before the backup is published.

The decrypted bundle contains exactly:

- `database.sql.gz` — a transactional MySQL dump;
- `private-files.tar.gz` — configured private paths;
- `manifest.json` — creation time, application commit, included paths, byte counts, and SHA-256 values.

The encryption passphrase is never written into the bundle, database, log, status file, Git repository, or command line.

## Private configuration

Copy the safe example fields into ignored `config/config.local.php` and set a unique passphrase:

```php
'backups' => [
    'enabled' => true,
    'storage_path' => dirname(__DIR__) . '/storage/backups',
    'retention_days' => 14,
    'maximum_age_hours' => 30,
    'encryption_passphrase' => 'A UNIQUE PRIVATE PASSPHRASE STORED OFFLINE TOO',
    'mysqldump_binary' => '/usr/bin/mysqldump',
    'tar_binary' => '/usr/bin/tar',
    'include_paths' => [
        'config/config.local.php',
        'storage/documents',
        'storage/xbrl',
    ],
],
```

The passphrase must contain at least 20 bytes. Use a unique high-entropy passphrase and store it in a separate password manager or offline recovery record. Losing both the live configuration and the passphrase makes the encrypted backup unrecoverable.

## Create and verify

Run:

```sh
php cron/create-backup.php
php cron/check-backups.php --decrypt
```

The first command:

1. obtains a non-blocking process lock;
2. writes into a mode-0700 temporary directory;
3. streams `mysqldump` into gzip without putting the database password in the process arguments;
4. archives only configured private paths;
5. creates a manifest and compressed recovery bundle;
6. encrypts the bundle with authenticated chunks;
7. decrypts and verifies it before publishing;
8. writes mode-0600 `latest.json` metadata; and
9. removes expired `.hpbak` files according to the retention period.

The second command verifies file existence, age, encrypted SHA-256, authenticated decryption, and the expected plaintext SHA-256.

## Cron schedule

A daily backup should run at a quiet time, separate from source workers. Example:

```cron
17 2 * * * /opt/alt/php83/usr/bin/php /home/USER/halalpulse/cron/create-backup.php >> /home/USER/halalpulse/storage/logs/backup-cron.log 2>&1
```

The operational dashboard treats a backup older than `backups.maximum_age_hours` as blocked.

## Isolated extraction

Never test a restore inside the live application directory. Extract into a new empty directory outside the project:

```sh
php cron/extract-backup.php \
  /private/backups/halalpulse-YYYYMMDD-HHMMSSZ-COMMIT.hpbak \
  /home/USER/halalpulse-restore-test \
  --confirm-isolated-extraction
```

The command:

- refuses paths outside the configured backup directory;
- refuses a non-empty destination;
- refuses a destination inside the live project;
- authenticates every encrypted chunk;
- permits only the three expected bundle entries;
- rejects absolute paths and `..` traversal;
- verifies the manifest hashes; and
- never imports the SQL dump automatically.

After extraction, inspect `manifest.json`, run `gzip -t database.sql.gz`, list `private-files.tar.gz`, and restore into a disposable database first. A production database replacement must be a separately approved maintenance action with a fresh pre-restore backup.

## Off-host copy

A backup stored only on the same Hostinger account is not a complete disaster-recovery strategy. Copy successful `.hpbak` files to a second controlled location using a mechanism that preserves confidentiality and integrity. Keep the off-host copy private, verify its encrypted SHA-256 after transfer, and periodically perform an isolated extraction test.
