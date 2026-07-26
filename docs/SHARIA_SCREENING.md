# Sharia screening policy and evidence workflow

## Safety boundary

HalalPulse supports two explicitly different assurance modes:

1. **Research** — deterministic owner-approved screening for personal research. Results must be labelled `Research pass`, `Research fail`, or `Insufficient evidence`. This mode is not a fatwa, not independent Sharia certification, and not financial advice.
2. **Independently reviewed** — the existing workflow for a policy mapped from an exact official or licensed standard and confirmed by a competent independent reviewer.

The two modes share the same fail-closed evidence engine but never share the same assurance claim. Activating a research policy must not imply scholar review or certification.

AAOIFI lists **Sharia Standard No. 21 — Financial Paper (Shares and Bonds)** on its official standards catalogue. The versioned HalalPulse research policy cites the official standard page to identify the referenced standard, but its ratio mapping is expressly research-only because HalalPulse does not possess a licensed clause-level edition or independent scholar approval.

The software is a research aid. It does not issue religious rulings or investment recommendations.

## Versioned HalalPulse strict research policy

`config/sharia-research-policy.json` is tracked and reviewable. It contains:

- interest-bearing debt ÷ market capitalization, maximum **30%**;
- interest-bearing deposits and investments ÷ market capitalization, maximum **30%**;
- impermissible income ÷ consolidated total income, maximum **5%**; and
- eligible real operating assets ÷ consolidated total assets, minimum **30%**.

The first three are recorded as an AAOIFI SS 21 research mapping pending independent certification. The fourth is an owner-defined **HalalPulse asset-substance rule** for investor protection and must never be represented as an AAOIFI certification rule.

Eligible real operating assets include evidenced net property, plant and equipment, capital work in progress, inventories, operating right-of-use assets, permissible investment property, biological operating assets, and other clearly evidenced physical operating assets. Cash, deposits, loans, receivables, securities, financial investments, goodwill, deferred-tax assets, unsupported miscellaneous assets, and intangible assets are excluded.

The policy uses consolidated evidence. Market-cap evidence must record the official exchange closing price, outstanding ordinary shares, calculation date, and alignment with the reviewed reporting period.

## Research-policy activation

Research activation requires an explicit command-line acknowledgement:

```sh
php cron/activate-research-sharia-policy.php --acknowledge-research-only
```

The command:

- loads only the tracked versioned research policy;
- sets approval in memory after the explicit acknowledgement;
- validates assurance metadata, disclaimer, threshold directions, clauses, definitions, and exact decimals;
- activates the policy transactionally;
- prints its version, SHA-256, assurance level, and disclaimer; and
- keeps all previous policy records stored but inactive.

The tracked JSON remains `approved_for_use: false`; it cannot be installed accidentally through the generic installer without an explicit activation decision.

## Independently reviewed policy workflow

The original verified-policy path remains available:

1. Copy `config/sharia-policy.example.json` to ignored `config/sharia-policy.local.json`.
2. Access the exact official or licensed standard edition.
3. Record the edition, language, access date, clauses, ratio definitions, denominator basis, maxima or minima, effective date, and applicability.
4. Obtain competent independent review.
5. Keep `assurance_level` as `independently_reviewed` and `approved_for_use` as `false` until review is complete.
6. Run:

```sh
php cron/check-sharia-policy.php config/sharia-policy.local.json
```

7. Resolve every blocker, set `approved_for_use` to `true` only after approval, and activate with:

```sh
php cron/install-sharia-policy.php config/sharia-policy.local.json
```

A third-party summary, exposure draft, consultation page, social-media post, remembered formula, or uncontrolled copy cannot serve as the governing independently reviewed policy source.

## Fail-closed rules

A screening can be stored as `passed`, `failed`, or `insufficient`. Presentation depends on policy assurance.

- No active policy: screening is unavailable.
- Invalid policy assurance or missing disclaimer: activation is refused.
- Research activation without explicit acknowledgement: refused.
- Independently reviewed policy without approval: refused.
- Missing threshold direction, exact threshold, clause, numerator definition, or denominator definition: refused.
- Prohibited business activity: failed before ratio calculation.
- Pending or mixed activity review: blocked.
- Decisive activity review without meaningful primary evidence: refused.
- Missing required numerator or denominator: insufficient.
- Mismatched currencies: insufficient.
- Zero denominator: insufficient.
- Missing PHP `bcmath`: refused.
- Maximum ratio above its limit: failed.
- Minimum ratio below its floor: failed.
- Only permissible activity with every required ratio complete and within its directional threshold can pass.

There is no floating-point fallback. Decimal strings are normalized to base units with `bcmath` and compared at an eight-decimal calculation scale.

## Policy identity and backward compatibility

Every policy retains:

- assurance level and disclaimer;
- exact machine keys;
- threshold direction (`maximum` or `minimum`);
- exact decimal threshold;
- source or methodology clause;
- numerator and denominator definitions; and
- required/optional state.

These values are included in the canonical SHA-256 identity. Changing a threshold, direction, definition, disclaimer, or assurance level requires a new policy version.

Policies stored before assurance metadata was introduced remain readable and hash-verifiable as legacy independently reviewed records. New records store assurance metadata, disclaimer, and ratios inside the existing `ratios_json` column, so no database migration is required.

## Evidence workflow

For each observed company, the administrator:

1. records business activity from primary evidence;
2. selects a real reporting period;
3. reviews every required input under the active policy;
4. records currency, unit scale, evidence note, and official source reference;
5. calculates market capitalization from documented exchange evidence where required; and
6. resolves every readiness blocker before storing an immutable screening.

Activity reviews are append-only. Replacing a financial input preserves the previous record as `superseded`. Every screening stores the policy ID, activity status, directional ratio results, reasons, normalized input snapshot, user, and timestamp.

### Research-policy input keys

The strict research policy requires:

- `interest_bearing_debt`;
- `market_capitalization`;
- `interest_bearing_deposits`;
- `impermissible_income`;
- `total_income`;
- `eligible_real_operating_assets`; and
- `total_assets`.

A repeated denominator is entered once and reused by the relevant ratios.

## Structured NSE XBRL candidates

Structured values are review candidates, never automatic religious conclusions.

The conservative mapper supports only:

- `Income` or `TotalIncome` → `total_income`, confidence 90%.

`RevenueFromOperations` is not re-labelled as consolidated total income because other income may be missing. Where no direct total-income fact exists, `total_income` remains missing until the administrator establishes the complete value from primary financial statements.

The mapper does not reinterpret `OtherIncome` as impermissible income, `DebtEquityRatio` as interest-bearing debt, or unrelated balance-sheet facts as asset-substance inputs. Debt, deposits, impermissible income, eligible operating assets, total assets, and market capitalization remain missing until primary evidence is reviewed and accepted.

For existing processed filings, the idempotent backfill remains:

```sh
php cron/backfill-sharia-candidates.php --limit=500
```

## Compliance rank

Rank 1–5 is a HalalPulse research indicator, not an AAOIFI rating.

For a maximum threshold, utilization is `actual percentage ÷ maximum`. For a minimum threshold, risk utilization is `minimum ÷ actual percentage`. The worst passing utilization determines rank:

| Worst threshold utilization | HalalPulse rank |
|---:|---:|
| up to 50% | 1 |
| over 50%, up to 70% | 2 |
| over 70%, up to 85% | 3 |
| over 85%, up to 95% | 4 |
| over 95%, up to 100% | 5 |

Failed and insufficient results receive no rank. Multibagger scoring remains a separate downstream layer and cannot bypass the latest passing screening result.

## Verification

Run all policy and release checks with:

```sh
php tests/sharia-policy-readiness.php
php tests/sharia-research-policy.php
php tests/sharia-evidence-readiness.php
php tests/sharia-evidence-readiness-db.php
php cron/verify-release.php
```

Synthetic fixtures contain made-up values solely to verify software boundaries. They are not religious guidance and must never be activated in production.
