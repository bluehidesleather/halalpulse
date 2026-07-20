# Multibagger potential scoring

## Purpose and risk boundary

This layer prioritizes companies for private manual research. It does not predict returns, recommend a trade, or label any investment safe. The [SEBI Investor shares guide](https://investor.sebi.gov.in/understandings_shares.html) states that stock-market returns are not guaranteed, and SEBI's [securities-market do's and don'ts](https://investor.sebi.gov.in/securities-dos_and_donts.html) advises investors to consider their objectives and risk appetite.

The score uses 1 for the strongest reviewed evidence and 10 for the weakest. It is deliberately separate from Sharia screening. A company cannot receive a score unless the same period has a pass under the currently active Sharia policy.

## Methodology lifecycle

The repository ships a reviewed-structure template at `config/multibagger-methodology.example.json`, but it is not active and cannot be installed unchanged. To activate it:

1. Apply `database/migrations/005_multibagger_scoring.sql` to an existing database.
2. Copy the example to the ignored `config/multibagger-methodology.local.json`.
3. Review every factor definition, weight, market-cap band, adjustment, and valuation assumption.
4. Replace the reviewer placeholders, write a specific verification note, set `approved_for_use` to `true`, and run `php cron/install-multibagger-methodology.php config/multibagger-methodology.local.json`.
5. Record the printed version and SHA-256.

Changed content requires a new version. Previous methodologies and score snapshots remain stored. The installer locks score direction to `1_best_10_weakest`, weights to exactly 100%, alert maximum to 4, and dual valuation to required.

## Twelve-factor scorecard

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

Each factor receives a human-reviewed grade from 1 to 10 plus an evidence note and either a stored exchange filing PDF or an allowed official URL. Macro evidence is stricter: it must reference a current human-approved strong/moderate review of a stored PIB, SEBI, RBI, MCA, or Union Budget announcement. The official URL is copied from that immutable announcement.

The original [Piotroski research publication](https://www.gsb.stanford.edu/faculty-research/publications/value-investing-use-historical-financial-statement-information) studied a historical financial-statement heuristic in a high book-to-market setting. HalalPulse therefore treats the F-Score as one weighted factor, not a universal predictor or stand-alone buy signal.

## Exact score calculation

For completed required factors:

`weighted score = sum(factor grade × integer weight) / 100`

The displayed final score uses exact integer half-up rounding. No binary floating-point calculation is used. Micro/nano adjustments are applied only when reviewed market capitalization is below ₹500 crore:

- each selected red flag adds 2;
- each selected green flag subtracts 1;
- final score is capped to the 1–10 range.

The stored risk snapshot identifies whether adjustments were applied and preserves selected flags. Market-cap categories are: large ≥₹20,000 crore, mid ≥₹5,000 crore, small ≥₹500 crore, micro ≥₹50 crore, and nano below ₹50 crore.

## Dual valuation

The engine calculates the configured Graham Number using exact `bcmath` square-root arithmetic and stores the administrator-supplied DCF value with its assumptions. It sets `undervalued_by_both` only when current price is no higher than both calculated Graham value and reviewed DCF value. Disagreement does not receive an undervalued label.

DCF is assumption-sensitive. The assumptions note is mandatory, as are a currency, positive EPS, positive book value per share, positive DCF value, current price, evidence note, and allowed official source URL.

## Alert gate

`alert_eligible` is true only when:

- the same-period Sharia screening passed under the active Sharia policy;
- all required multibagger evidence is complete;
- a final score was calculated; and
- final score is 4 or lower.

This records eligibility. The separate delivery command rechecks active policies, latest evidence, current macro review, score freshness, and active recipient consent before a Telegram Bot submission. Insufficient or stale evidence cannot be delivered.

## Current automation boundary

Milestone 9 retains human factor/government decisions and adds a separate idempotent Telegram delivery boundary. Provider acceptance is audited separately and never changes the immutable score.
