# Authentication and first administrator

Version: 0.5.0

HalalPulse is a private personal-use installation. It supports administrator accounts created from the command line and does not expose public registration, invitation, or email-password-reset endpoints.

## First-time setup

1. Serve the site over HTTPS and keep `app.force_https` set to `true`.
2. Copy `config/config.example.php` to the ignored `config/config.local.php` file.
3. Generate a private application key:

   ```sh
   php cron/generate-app-key.php
   ```

4. Copy the single output line to `security.app_key` in `config/config.local.php`. Do not paste it into chat, commit it, or put it in a screenshot.
5. Import `database/schema.sql`, then apply the missing numbered migrations in order. Migration `012_user_auth_version.sql` is required by the current authentication code.
6. Create the administrator from a hosting terminal:

   ```sh
   php cron/create-admin.php you@example.com "Your Name"
   ```

7. Enter and confirm the password at the prompts. The password is never accepted as a command-line argument, so it does not enter shell history.
8. Run `php cron/healthcheck.php`, then open the domain's `/login.php` page.

## Password recovery

There is deliberately no public “forgot password” route. Reset the password from the hosting terminal:

```sh
php cron/reset-admin-password.php you@example.com
```

A successful command-line reset increments the account authentication version. Every browser session issued under an older version is rejected on its next request. An attacker who only reaches the website cannot trigger a reset email, enumerate users through reset forms, or create an account.

## Controls implemented

- Email addresses are normalized before lookup, while login errors remain generic.
- Invalid-user checks still execute a password hash verification to reduce identity-timing differences.
- Five failures across the keyed identity or client-IP bucket block new attempts for 15 minutes by default.
- Only HMAC hashes of the normalized email and remote address are stored for throttling; logs contain a short hash prefix or user ID, never credentials.
- Passwords require 12 to 128 characters and use Argon2id where available.
- Session IDs regenerate on login and rotate every 15 minutes by default without extending the idle or absolute lifetime.
- URL-based PHP session propagation is disabled explicitly.
- Invalid, future, or contradictory authentication timestamps fail closed.
- A password change or command-line reset increments `users.auth_version`, invalidating every previously issued browser session. Transparent password-hash upgrades during a valid login do not increment the version.
- The browser performing a valid password change is immediately reauthenticated under the new version; all other sessions are signed out.
- When `app.force_https` is enabled, PHP refuses every non-TLS web request before a session starts or credentials can be submitted. Successful HTTPS responses emit a one-year HSTS policy. The check deliberately does not trust a client-supplied `X-Forwarded-Proto` header.
- Unhandled setup and database exceptions render a generic reference code in the browser; class and file location are written only to the private server error log.
- Cookies are session-only, host-only, `Secure`, `HttpOnly`, and `SameSite=Strict`.
- Sessions expire after 30 minutes of inactivity or 12 hours total by default.
- Every state-changing form verifies a 256-bit CSRF token.
- Authenticated pages send no-store caching and restrictive browser security headers.

## Migration behavior

Migration `012_user_auth_version.sql` adds a non-null authentication version with a default of 1. Sessions created by older code do not contain this version and are intentionally rejected after deployment. This is a one-time sign-in requirement, not data loss.

Apply the migration before pulling code that queries `users.auth_version`:

```sh
mysql -h localhost -u YOUR_DATABASE_USER -p YOUR_DATABASE_NAME < database/migrations/012_user_auth_version.sql
```

Do not put the database password in the command line. The MySQL client prompts for it without echoing.

## Operational notes

- The application uses `REMOTE_ADDR` and does not trust arbitrary forwarded-IP headers. If the hosting provider later places the site behind a known proxy, add an explicit trusted-proxy design rather than trusting all forwarded values.
- Keep PHP `display_errors` off in production and direct errors to a non-public log.
- The application key must remain stable. Changing it resets login-throttling identity buckets and makes encrypted Telegram recipient addresses unreadable until they are re-registered; it does not invalidate password hashes.
- Deactivating an administrator in the database causes the next authenticated request to lose access.
- The health check verifies both the session rotation interval and the `users.auth_version` column.
