# Telegram Bot alert delivery

Version: 1.0.0  
Date: 2026-07-21

## Boundary

HalalPulse submits personal research alerts through the official Telegram Bot API. Telegram states that bots can message their users at no cost under the standard broadcast limits. This integration does not send SMS, does not require Twilio, and does not make a score into investment or religious advice.

A bot cannot start a private conversation by itself. Every intended recipient must open the bot and send `/start` before registration. HalalPulse also requires the administrator to record that consent explicitly. Removing or blocking the bot stops delivery at Telegram; deactivating a recipient in HalalPulse stops future attempts while preserving history.

Official references:

- Bot API: <https://core.telegram.org/bots/api>
- Bot FAQ and free broadcast limits: <https://core.telegram.org/bots/faq>

## Private configuration

Create a bot with `@BotFather`. Store the real token only in ignored `config/config.local.php`:

```php
'alerts' => [
    'enabled' => false,
    'channel' => 'telegram',
    'batch_size' => 1,
    'recipient_limit' => 25,
    'app_base_url' => 'https://your-real-domain.example',
    'telegram' => [
        'bot_token' => 'PRIVATE_BOT_TOKEN',
        'request_timeout_seconds' => 20,
        'max_request_bytes' => 16384,
        'max_response_bytes' => 1048576,
        'max_header_bytes' => 65536,
    ],
],
```

`app_base_url` must be a public DNS-style HTTPS origin only. Credentials, IP literals, custom or explicit ports, paths, queries, fragments, whitespace, and control bytes are rejected. This prevents alert links from silently pointing at a different path or internal address.

Never paste the token into a browser URL, screenshot, log, database row, issue, commit, or support conversation. Rotate it with `@BotFather` if it is exposed.

## Provider transport safeguards

- Only `sendMessage` and `getUpdates` are allowed API methods.
- The Telegram API hostname is fixed in code; redirects are disabled and only HTTPS is permitted.
- TLS peer and hostname verification remain enabled.
- Connect and total request timeouts are bounded.
- Encoded JSON request bodies, response bodies, and response headers have independent byte limits.
- Responses must be JSON when Telegram supplies a content type.
- Provider-supplied error descriptions are stripped of control bytes, length-bounded, and have the private bot token redacted before an exception can reach logs or delivery records.
- A network interruption after `sendMessage` remains an unknown outcome, never an automatic retry.

These controls do not activate alerts or prove recipient consent. They only constrain the optional provider transport.

## Recipient registration

1. Keep alerts disabled.
2. Ask the intended recipient to open the bot and send `/start`.
3. Run `php cron/discover-telegram-chats.php` privately on the host.
4. Sign in to HalalPulse and open **Alerts**.
5. Enter a private label and the discovered numeric chat ID.
6. Confirm that the recipient started the bot and requested alerts.

The chat ID is encrypted with AES-256-GCM using a key derived from the private application key. MySQL stores ciphertext, nonce, authentication tag, a keyed identity hash, label, consent timestamp, activation state, and administrator attribution. Delivery rows do not repeat the encrypted address. The bot token is never stored in MySQL.

Changing `security.app_key` without migrating encrypted recipients makes their addresses undecryptable. Treat the application key as a stable production secret and back it up securely.

## Delivery and duplicate boundary

The hourly command selects only the latest candidate that still passes all current gates:

- latest immutable multibagger score is 1–4 and alert-eligible;
- its methodology is still active;
- its same-period Sharia screening passed under the active policy;
- no accepted Sharia, factor, valuation, or risk evidence changed after calculation;
- the macro factor still references a current strong/moderate government review; and
- the company and recipient are active.

A unique score/channel/recipient-HMAC reservation is committed before contacting Telegram. The message body and raw chat ID are not stored in delivery records. Telegram's successful `sendMessage` result supplies a numeric message ID, which is retained for audit and provider-level duplicate checks.

Known failure and unknown outcome remain distinct. Network interruption after submission can leave acceptance ambiguous. Those records are never retried automatically.

## Manual testing and activation

1. Apply `database/migrations/008_telegram_alerts.sql` to an existing v0.8 database, or use the full schema for a fresh installation.
2. Configure the private token and permanent HTTPS application origin while leaving alerts disabled.
3. Register one consenting recipient.
4. Run `php tests/telegram-security.php`, `php tests/run.php`, and `php cron/healthcheck.php`.
5. Set `alerts.enabled` to `true` temporarily and run `php cron/send-alerts.php` manually with a deliberately eligible synthetic or reviewed candidate.
6. Confirm the exact message in Telegram and inspect the authenticated Alerts page.
7. Only after that test succeeds, schedule `cron/send-alerts.php` once per hour.

For a failed or unknown delivery, inspect the destination Telegram chat first. Only if no matching message exists may you run:

```sh
php cron/retry-alert-delivery.php DELIVERY_ID --confirm-no-message-sent
```

The command revalidates recipient identity, current score eligibility, unchanged message hash, and the five-attempt ceiling before sending.

## Privacy and operational notes

- Telegram receives the message content and chat identity as required to deliver it.
- HalalPulse never logs the bot token or decrypted chat ID.
- The Alerts page displays only the administrator label, channel, state, timestamps, and truncated provider message ID.
- Provider acceptance does not prove that a person read the notification.
- Telegram is an optional convenience channel; the authenticated database record remains the source of truth.
