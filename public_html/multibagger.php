<?php

declare(strict_types=1);

use HalalPulse\Web\Page;
use HalalPulse\Web\Response;
use HalalPulse\Web\WebApplication;

$config=require dirname(__DIR__).'/app/bootstrap.php';$app=WebApplication::boot($config);$user=$app->session->currentUser($app->users);if($user===null)Response::redirect('/login.php',302);
$methodology=$app->multibagger->activeMethodology();$summary=$app->multibagger->summary();$companies=$app->multibagger->companies();
Page::begin('Multibagger potential',(string)$config->get('app.name','HalalPulse'),$user,'multibagger',$app->session->csrfToken());
?>
<div class="page-heading"><div><p class="eyebrow">Separate research layer</p><h1>Multibagger potential</h1><p class="muted">A 1-best to 10-weakest evidence score. It never overrides Sharia eligibility and never predicts a guaranteed return.</p></div></div>
<?php Page::flash($app->session->consumeFlash()); ?>
<?php if($methodology===null): ?>
<section class="notice-card notice-error policy-gate"><strong>Scoring is locked</strong><p>No reviewed methodology is active. Complete the ignored local methodology file and activate it from the command line.</p></section>
<?php else: ?>
<section class="policy-banner"><div><p class="eyebrow">Active methodology</p><h2><?=Page::escape($methodology->name)?> · <?=Page::escape($methodology->version)?></h2><p><?=Page::escape(count($methodology->factors()))?> weighted factors · score 1 strongest · alert candidate at ≤ <?=Page::escape($methodology->definition['alert_max_score'])?></p></div><div class="policy-meta"><span class="status status-passed">Active</span><span class="mono">SHA <?=Page::escape(substr($methodology->methodologyHash,0,12))?>…</span></div></section>
<?php endif; ?>
<section class="metric-grid"><article class="metric-card"><span>Companies</span><strong><?=Page::escape($summary['companies'])?></strong><small>Observed issuers</small></article><article class="metric-card"><span>Latest scored</span><strong><?=Page::escape($summary['scored'])?></strong><small>Complete reviewed scorecards</small></article><article class="metric-card metric-accent"><span>Alert candidates</span><strong><?=Page::escape($summary['alert_eligible'])?></strong><small>Current Sharia pass + score ≤4</small></article><article class="metric-card"><span>Insufficient</span><strong><?=Page::escape($summary['insufficient'])?></strong><small>Never converted into a score</small></article></section>
<section class="panel panel-results"><div class="panel-heading"><div><p class="eyebrow">Company workbench</p><h2>Research queue</h2></div><span class="status"><?=Page::escape(count($companies))?> shown</span></div>
<?php if($companies===[]): ?><div class="empty-state"><h3>No companies stored yet</h3><p>Companies appear after verified exchange ingestion.</p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Company</th><th>Sharia gate</th><th>Potential score</th><th>Cap</th><th>Alert gate</th><th>Period</th></tr></thead><tbody>
<?php foreach($companies as $company): $scoreStatus=(string)($company['score_status']??'not_scored'); ?>
<tr><td><span class="exchange-badge"><?=Page::escape($company['exchange'])?></span><a class="table-title" href="/multibagger-company.php?id=<?=Page::escape($company['id'])?>"><strong><?=Page::escape($company['symbol'])?></strong></a><small><?=Page::escape($company['company_name'])?></small></td><td><span class="status status-<?=Page::escape($company['sharia_status']??'pending')?>"><?=Page::escape(ucfirst((string)($company['sharia_status']??'no pass')))?></span></td><td><span class="status status-<?=Page::escape($scoreStatus)?>"><?=Page::escape($company['final_score']===null?ucfirst(str_replace('_',' ',$scoreStatus)):$company['final_score'].' / 10')?></span></td><td><?=Page::escape($company['market_cap_category']??'—')?></td><td><?=((int)($company['alert_eligible']??0)===1)?'<span class="status status-passed">Eligible</span>':'—'?></td><td><?=Page::escape($company['score_period']??'—')?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="notice-card"><strong>Risk notice</strong><p>A low score is a research-prioritization signal, not a recommendation or promised return. Verify filings, assumptions, liquidity, valuation, and personal risk suitability before any investment decision.</p></section>
<?php Page::end(); ?>
