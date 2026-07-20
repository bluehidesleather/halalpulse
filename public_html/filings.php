<?php

declare(strict_types=1);

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

$query = Request::queryString('q');
$exchange = strtoupper(Request::queryString('exchange'));
$candidate = strtolower(Request::queryString('candidate'));
$status = strtolower(Request::queryString('status'));
$page = Request::queryInt('page', 1);
$results = $app->dashboard->searchFilings($query, $exchange, $candidate, $status, $page);

$selected = static fn (string $value, string $current): string => $value === $current ? ' selected' : '';
$pageUrl = static function (int $target) use ($query, $exchange, $candidate, $status): string {
    return '/filings.php?' . http_build_query([
        'q' => $query,
        'exchange' => $exchange,
        'candidate' => $candidate,
        'status' => $status,
        'page' => $target,
    ]);
};

Page::begin(
    'Filings',
    (string) $config->get('app.name', 'HalalPulse'),
    $user,
    'filings',
    $app->session->csrfToken(),
);
?>
<div class="page-heading">
    <div><p class="eyebrow">Official disclosure ledger</p><h1>Filings</h1><p class="muted">Search normalized NSE/BSE announcements without losing the original audit payload.</p></div>
</div>

<section class="panel">
    <form class="filter-bar" method="get" action="/filings.php">
        <div class="filter-search"><label for="q">Company or announcement</label><input id="q" type="search" name="q" value="<?= Page::escape($query) ?>" maxlength="100" placeholder="Symbol, company, subject"></div>
        <div><label for="exchange">Exchange</label><select id="exchange" name="exchange"><option value="">All</option><option value="NSE"<?= $selected('NSE', $exchange) ?>>NSE</option><option value="BSE"<?= $selected('BSE', $exchange) ?>>BSE</option></select></div>
        <div><label for="candidate">Result signal</label><select id="candidate" name="candidate"><option value="">All</option><option value="yes"<?= $selected('yes', $candidate) ?>>Candidate</option><option value="no"<?= $selected('no', $candidate) ?>>Not candidate</option></select></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All</option><?php foreach (['detected', 'queued', 'processed', 'rejected', 'failed'] as $option): ?><option value="<?= $option ?>"<?= $selected($option, $status) ?>><?= Page::escape(ucfirst($option)) ?></option><?php endforeach; ?></select></div>
        <button class="button button-primary" type="submit">Apply filters</button>
    </form>
</section>

<section class="panel panel-results">
    <div class="panel-heading"><div><p class="eyebrow">Results</p><h2><?= Page::escape($results['total']) ?> filings</h2></div></div>
    <?php if ($results['items'] === []): ?>
        <div class="empty-state"><h3>No matching filings</h3><p>Try removing filters, or run the verified hourly source poll.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Company</th><th>Category and subject</th><th>Published</th><th>Classification</th><th>Document</th></tr></thead>
                <tbody>
                <?php foreach ($results['items'] as $filing): ?>
                    <?php
                    $attachmentUrl = is_string($filing['attachment_url'])
                        && str_starts_with($filing['attachment_url'], 'https://')
                        ? $filing['attachment_url']
                        : null;
                    ?>
                    <tr>
                        <td><span class="exchange-badge"><?= Page::escape($filing['exchange']) ?></span><a class="table-title" href="/filing.php?id=<?= Page::escape($filing['id']) ?>"><strong><?= Page::escape($filing['symbol']) ?></strong></a><small><?= Page::escape($filing['company_name']) ?></small></td>
                        <td><small><?= Page::escape($filing['category']) ?></small><div><a class="subject-link" href="/filing.php?id=<?= Page::escape($filing['id']) ?>"><?= Page::escape($filing['subject']) ?></a></div></td>
                        <td class="nowrap"><?= Page::escape($filing['announced_at']) ?></td>
                        <td>
                            <?php if ((int) $filing['is_quarterly_result_candidate'] === 1): ?>
                                <span class="status status-candidate">Candidate <?= Page::escape($filing['classifier_confidence']) ?>%</span>
                            <?php else: ?>
                                <span class="status"><?= Page::escape(ucfirst((string) $filing['processing_status'])) ?></span>
                            <?php endif; ?>
                            <small class="reason"><?= Page::escape($filing['classifier_reason']) ?></small>
                        </td>
                        <td><?php if ($attachmentUrl !== null): ?><a class="document-link" href="<?= Page::escape($attachmentUrl) ?>" target="_blank" rel="noopener noreferrer">Official file</a><?php else: ?><span class="muted">None</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($results['pages'] > 1): ?>
            <nav class="pagination" aria-label="Filings pages">
                <?php if ($results['page'] > 1): ?><a class="button button-quiet" href="<?= Page::escape($pageUrl($results['page'] - 1)) ?>">Previous</a><?php endif; ?>
                <span>Page <?= Page::escape($results['page']) ?> of <?= Page::escape($results['pages']) ?></span>
                <?php if ($results['page'] < $results['pages']): ?><a class="button button-quiet" href="<?= Page::escape($pageUrl($results['page'] + 1)) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php Page::end(); ?>
