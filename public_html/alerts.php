<?php

declare(strict_types=1);

use HalalPulse\Alerts\AlertConfiguration;
use HalalPulse\Web\Page;
use HalalPulse\Web\Request;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = WebApplication::boot($config);
$user = $app->session->currentUser($app->users);
if ($user === null) {
    Response::redirect('/login.php', 302);
}
$alertConfig = AlertConfiguration::fromConfig($config);

if (Request::isPost()) {
    if (!$app->session->verifyCsrf(Request::postString('csrf_token', false))) {
        http_response_code(400);
        $app->session->flash('error', 'The form expired. Refresh the page and try again.');
        Response::redirect('/alerts.php');
    }
    $action = Request::postString('action');
    try {
        if ($action === 'register_recipient') {
            if (Request::postString('consent_confirmed') !== 'yes') {
                throw new InvalidArgumentException('Confirm that this recipient started the bot and requested alerts.');
            }
            $app->alertRecipients->registerTelegram(
                Request::postString('chat_id'),
                Request::postString('label'),
                $user->id,
            );
            $app->session->flash('success', 'Telegram recipient registered. Its chat ID is encrypted in the database.');
        } elseif ($action === 'deactivate_recipient') {
            $recipientId = filter_var(Request::postString('recipient_id'), FILTER_VALIDATE_INT);
            $app->alertRecipients->deactivate(is_int($recipientId) ? $recipientId : 0);
            $app->session->flash('success', 'Telegram recipient deactivated. Historical delivery records were preserved.');
        } else {
            throw new InvalidArgumentException('Unknown recipient action.');
        }
    } catch (InvalidArgumentException $exception) {
        $app->session->flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        $app->logger->error('Alert recipient action failed.', ['action' => $action, 'user_id' => $user->id, 'exception' => $exception::class]);
        $app->session->flash('error', 'The recipient could not be updated. Run the health check and inspect the private log.');
    }
    $app->session->rotateCsrfToken();
    Response::redirect('/alerts.php');
}

$summary = $app->alerts->summary();
$deliveries = $app->alerts->recentDeliveries();
$recipients = $app->alertRecipients->listTelegram();
$activeRecipientCount = count(array_filter($recipients, static fn (array $recipient): bool => (int) $recipient['is_active'] === 1));

Page::begin('Alert delivery', (string) $config->get('app.name', 'HalalPulse'), $user, 'alerts', $app->session->csrfToken());
?>
<div class="page-heading"><div><p class="eyebrow">Delivery audit</p><h1>Telegram alerts</h1><p class="muted">Free Telegram Bot notifications for the latest current score only. Bot tokens are never stored in MySQL, and recipient chat IDs are encrypted at rest.</p></div></div>
<?php Page::flash($app->session->consumeFlash()); ?>
<?php if (!$alertConfig->enabled): ?>
<section class="notice-card notice-error policy-gate"><strong>Delivery is locked</strong><p>Configure the private Telegram bot token, register a consenting recipient below, complete a manual test, and only then set <span class="mono">alerts.enabled</span> to true.</p></section>
<?php else: ?>
<section class="policy-banner"><div><p class="eyebrow">Private configuration</p><h2>Telegram Bot delivery enabled</h2><p>Batch size <?= Page::escape($alertConfig->batchSize) ?> per recipient · <?= Page::escape($activeRecipientCount) ?> active recipients · scheduled command only</p></div><div class="policy-meta"><span class="status status-passed">Enabled</span><span class="status">No SMS fee</span></div></section>
<?php endif; ?>

<section class="metric-grid">
    <article class="metric-card"><span>Latest eligible scores</span><strong><?= Page::escape($summary['eligible']) ?></strong><small>Freshness is rechecked before every send</small></article>
    <article class="metric-card metric-accent"><span>Provider accepted</span><strong><?= Page::escape($summary['accepted']) ?></strong><small>Telegram returned a message ID</small></article>
    <article class="metric-card"><span>Failed</span><strong><?= Page::escape($summary['failed']) ?></strong><small>Known non-acceptance; no auto-retry</small></article>
    <article class="metric-card"><span>Unknown outcome</span><strong><?= Page::escape($summary['unknown']) ?></strong><small>Inspect the chat before a manual retry</small></article>
</section>

<div class="settings-grid">
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Consent required</p><h2>Register Telegram recipient</h2></div></div>
    <ol><li>Create the bot with <span class="mono">@BotFather</span> and keep its token only in private configuration.</li><li>Ask the intended recipient to open the bot and send <span class="mono">/start</span>.</li><li>Run <span class="mono">php cron/discover-telegram-chats.php</span> privately to obtain the numeric chat ID.</li></ol>
    <form class="stacked-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>">
        <input type="hidden" name="action" value="register_recipient">
        <label for="label">Private recipient label</label>
        <input id="label" name="label" maxlength="100" placeholder="My Telegram" required>
        <label for="chat_id">Numeric Telegram chat ID</label>
        <input id="chat_id" name="chat_id" inputmode="numeric" pattern="-?[1-9][0-9]{0,18}" autocomplete="off" required>
        <label class="check-row"><input type="checkbox" name="consent_confirmed" value="yes" required> <span>I confirm this recipient started the bot and requested HalalPulse alerts.</span></label>
        <button class="button button-primary" type="submit">Encrypt and register recipient</button>
    </form>
</section>

<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Database-managed</p><h2>Telegram recipients</h2></div><span class="status"><?= Page::escape($activeRecipientCount) ?> active</span></div>
    <?php if ($recipients === []): ?><div class="empty-state"><h3>No recipients registered</h3><p>Alerts cannot leave the server until a consenting Telegram chat is registered.</p></div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Label</th><th>Status</th><th>Confirmed</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($recipients as $recipient): ?><tr><td><strong><?= Page::escape($recipient['label']) ?></strong><small>Chat ID encrypted · <?= Page::escape($recipient['channel']) ?></small></td><td><span class="status status-<?= (int) $recipient['is_active'] === 1 ? 'passed' : 'insufficient' ?>"><?= (int) $recipient['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td><td><?= Page::escape($recipient['confirmed_by_name'] ?? 'Administrator') ?><small><?= Page::escape($recipient['last_verified_at']) ?></small></td><td><?php if ((int) $recipient['is_active'] === 1): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= Page::escape($app->session->csrfToken()) ?>"><input type="hidden" name="action" value="deactivate_recipient"><input type="hidden" name="recipient_id" value="<?= Page::escape($recipient['id']) ?>"><button class="button button-quiet button-small" type="submit">Deactivate</button></form><?php else: ?><small>Re-register the same chat ID to reactivate.</small><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
</div>

<section class="panel panel-results"><div class="panel-heading"><div><p class="eyebrow">No recipient address displayed</p><h2>Delivery history</h2></div><span class="status"><?= Page::escape(count($deliveries)) ?> records</span></div>
<?php if ($deliveries === []): ?><div class="empty-state"><h3>No alert submissions yet</h3><p>An alert is considered only when the latest score is ≤4 and its active methodology, same-period Sharia pass, and reviewed government tailwind are all still current.</p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Company</th><th>Recipient</th><th>Score</th><th>Status</th><th>Provider</th><th>Attempts</th><th>Last attempt</th></tr></thead><tbody>
<?php foreach ($deliveries as $delivery): ?><tr><td><span class="exchange-badge"><?= Page::escape($delivery['exchange']) ?></span><strong class="table-title"><?= Page::escape($delivery['symbol']) ?></strong><small><?= Page::escape($delivery['company_name']) ?></small></td><td><?= Page::escape($delivery['recipient_label'] ?? 'Historical recipient') ?><small><?= Page::escape($delivery['channel']) ?></small></td><td><?= Page::escape($delivery['final_score']) ?> / 10<small><?= Page::escape($delivery['period_end']) ?></small></td><td><span class="status status-<?= Page::escape($delivery['status']) ?>"><?= Page::escape(ucfirst((string) $delivery['status'])) ?></span><?php if ($delivery['error_message'] !== null): ?><small class="error-note"><?= Page::escape($delivery['error_message']) ?></small><?php endif; ?></td><td><?= Page::escape($delivery['provider_status'] ?? '—') ?><small class="mono"><?= Page::escape($delivery['provider_message_id'] === null ? '' : substr((string) $delivery['provider_message_id'], 0, 12)) ?></small></td><td><?= Page::escape($delivery['attempt_count']) ?></td><td><?= Page::escape($delivery['last_attempt_at'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="notice-card"><strong>Telegram limitation</strong><p>The recipient must start the bot before it can message them. A successful Bot API response confirms Telegram accepted and created the message; it does not prove that the recipient read it. Failed or ambiguous submissions are never retried automatically.</p></section>
<?php Page::end(); ?>
