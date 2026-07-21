# Multibagger potential scoring

## Purpose and risk boundary

This layer prioritizes companies for private manual research. It does not predict returns, recommend a trade, or label any investment safe. The [SEBI Investor shares guide](https://investor.sebi.gov.in/understandings_shares.html) states that stock-market returns are not guaranteed, and SEBI's [securities-market do's and don'ts](https://investor.sebi.gov.in/securities-dos_and_donts.html) advises investors to consider their objectives and risk appetite.

The score uses 1 for the strongest reviewed evidence and 10 for the weakest. It is deliberately separate from Sharia screening. A company cannot receive a score unless the same period has a pass under the currently active Sharia policy.

## Methodology lifecycle

The repository ships a structured template at `config/multibagger-methodology.example.json`, but it is deliberately unready and cannot be installed unchanged.

1. Apply `database/migrations/005_multibagger_scoring.sql` to an existing database.
2. Copy the example to the ignored `config/multibagger-methodology.local.json`.
3. Review every factor definition, evidence requirement, grade anchor, weight, market-cap band, adjustment, and valuation assumption.
4. Replace every placeholder but keep `approved_for_use` as `false` while review is incomplete.
5. Run the non-mutating readiness check:

```sh
php cron/check-multibagger-methodology.php config/multibagger-methodology.local.json
```

6. Resolve every `[BLOCKED]` item. Warnings require an explicit review decision but do not alter the database.
7. After independent approval, set `approved_for_use` to `true` and run the readiness check again.
8. Activate only a file reported as `[READY]`:

```sh
php cron/install-multibagger-methodology.php config/multibagger-methodology.local.json
```

9. Record the printed version and SHA-256, then run the full tests and health check.

Changed content requires a new version. Previous methodologies and score snapshots remain stored. The installer locks score direction to `1_best_10_weakest`, weights to exactly 100%, alert maximum to 4, dual valuation to required, official sources to required, media sources to prohibited, and same-period Sharia passage to required.

## Auditable factor rubric

Every factor must retain:

- a precise description;
- at least two explicit evidence requirements;
- a required integer weight;
- grade anchors for 1, 4, 7, and 10;
- a human evidence note meeting the active methodology's minimum length;
- either a stored exchange filing or an allowed official URL.

The grade anchors reduce arbitrary scoring. Grades between anchors remain reviewer judgments, but the evidence note must explain the interpolation. The factor definitions, evidence requirements, anchors, weights, and review-scope rules are included in the methodology SHA-256 identity.

The production template contains twelve factors:

| Factor | Weight |
|---|---:|
| Piotroski F-Score | 12% |
| Graham and DCF valuation | 12% |
| ROE and ROCE | 10% |
| Revenue and profit growth consistency | 10% |
| Operating margin trend | 8% |
| Debt quality | 8% |
| Cash-flow quality | 8% |
| Working-capital efficiency | 6% |
| Promoter quality and pledge | 8% |
| Institutional ownership trend | 6% |
| Official macro tailwind | 6% |
| Relative valuation ratios | 6% |

The original [Piotroski research publication](https://www.gsb.stanford.edu/faculty-research/publications/value-investing-use-historical-financial-statement-information) studied a historical financial-statement heuristic in a high book-to-market setting. HalalPulse therefore treats the F-Score as one weighted factor, not a universal predictor or stand-alone buy signal.

## Official evidence boundary

Each factor receives a human-reviewed grade plus an evidence note and either a stored exchange filing PDF or an allowed official URL. Macro evidence is stricter: it must reference a current human-approved strong/moderate review of a stored PIB, SEBI, RBI, MCA, or Union Budget announcement. The official URL is copied from that immutable announcement.

The methodology must keep `official_sources_only=true`, `media_sources_allowed=false`, and `same_period_sharia_pass_required=true`. These are activation gates, not optional preferences.

## Exact score calculation

For completed required factors:

`weighted score = sum(factor grade × integer weight) / 100`

The displayed final score uses exact integer half-up rounding. No binary floating-point calculation is used. Micro/nano adjustments are applied only when reviewed market capitalization is below the configured threshold:

- each selected red flag adds the configured red-flag points;
- each selected green flag subtracts the configured green-flag points;
- final score is capped to the 1–10 range.

The stored risk snapshot identifies whether adjustments were applied and preserves selected flags. Market-cap categories, their rationale, and the microcap adjustment rationale are part of the methodology definition and hash. This prevents silent category or penalty changes.

## Dual valuation

The engine calculates the configured Graham Number using exact `bcmath` square-root arithmetic and stores the administrator-supplied DCF value with its assumptions. It sets `undervalued_by_both` only when current price is no higher than both calculated Graham value and reviewed DCF value. Disagreement does not receive an undervalued label.

The methodology must document why its Graham coefficient is used and retain a DCF review policy. At minimum, the DCF policy requires:

- forecast period;
- base free cash flow;
- growth rates;
- discount rate;
- terminal growth rate;
- net debt;
- diluted shares;
- margin of safety.

DCF is assumption-sensitive. The active methodology controls the minimum DCF assumptions-note length. Missing, brief, non-positive, or unsupported valuation evidence produces `insufficient`, not a score.

## Alert gate

`alert_eligible` is true only when:

- the same-period Sharia screening passed under the active Sharia policy;
- all required multibagger evidence is complete;
- a final score was calculated; and
- final score is 4 or lower.

This records eligibility. The separate delivery command rechecks active policies, latest evidence, current macro review, score freshness, and active recipient consent before a Telegram Bot submission. Insufficient or stale evidence cannot be delivered.

## Current automation boundary

HalalPulse automates exact validation, weighted arithmetic, methodology hashing, allowed-source checks, and alert eligibility. It does not invent grades, DCF assumptions, governance conclusions, macro transmission, or future returns. These remain evidence-backed human review decisions.
