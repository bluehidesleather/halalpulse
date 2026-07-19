# Authentication and first administrator

Version: 0.3.0

HalalPulse is a private personal-use installation. It supports administrator accounts created from the command line and does not expose public registration, invitation, or email-password-reset endpoints.

## First-time setup

1. Serve the site over HTTPS and keep `app.force_https` set to `true`.
2. Copy `config/config.example.php` to the ignored `config/config.local.php` file.
3. Generate a private application key:

   ```sh
   php cron/generate-app-key.php
   ```

4. Copy the single output line to `security.app_key` in `config/config.local.php`. Do not paste it into chat, commit it, or put it in a screenshot.
5. Import `database/schema.sql`, or apply `database/migrations/002_auth_dashboard.sql` to an existing v0.2 database.
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

An attacker who only reaches the website cannot trigger a reset email, enumerate users through reset forms, or create an account.

## Controls implemented

- Email addresses are normalized before lookup, while login errors remain generic.
- Invalid-user checks still execute a password hash verification to reduce identity-timing differences.
- Five failures across the keyed identity or client-IP bucket block new attempts for 15 minutes by default.
- Only HMAC hashes of the normalized email and remote address are stored for throttling; logs contain a short hash prefix or user ID, never credentials.
- Passwords require 12 to 128 characters and use Argon2id where available.
- Session IDs regenerate on login and password change.
- Cookies are session-only, host-only, `Secure`, `HttpOnly`, and `SameSite=Strict`.
- Sessions expire after 30 minutes of inactivity or 12 hours total by default.
- Every state-changing form verifies a 256-bit CSRF token.
- Authenticated pages send no-store caching and restrictive browser security headers.

## Operational notes

- The application uses `REMOTE_ADDR` and does not trust arbitrary forwarded-IP headers. If the hosting provider later places the site behind a known proxy, add an explicit trusted-proxy design rather than trusting all forwarded values.
- Keep PHP `display_errors` off in production and direct errors to a non-public log.
- The application key must remain stable. Changing it resets login-throttling identity buckets and makes encrypted Telegram recipient addresses unreadable until they are re-registered; it does not invalidate password hashes.
- Deactivating an administrator in the database causes the next authenticated request to lose access.
