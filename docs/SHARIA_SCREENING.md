# Sharia screening policy and evidence workflow

## Safety boundary

HalalPulse does not ship numerical Sharia thresholds and does not claim that a remembered or internet-copied formula is current. AAOIFI publishes an official page for [Sharia Standard No. 21](https://aaoifi.com/ss-21-financial-paper-shares-and-bonds/?lang=en), and its [Sharia standards catalogue](https://aaoifi.com/shariah-standards-3/?lang=en) states that standards are updated. The administrator must verify the current official or properly licensed standard text before activating a local policy.

The software applies a supplied policy deterministically. It is a research aid, not a fatwa, religious ruling, investment recommendation, or substitute for a qualified Sharia adviser.

## Fail-closed rules

A screening can be `passed`, `failed`, or `insufficient`.

- No active policy: screening is unavailable.
- Unapproved policy or missing threshold: installation is refused.
- Prohibited business-activity review: `failed` before ratio calculation.
- Pending or mixed business-activity review: `insufficient`.
- Missing required numerator or denominator: `insufficient`.
- Mismatched currencies: `insufficient`.
- Zero denominator: `insufficient`.
- Missing PHP `bcmath`: calculation is refused.
- Any complete required ratio above its maximum: `failed`.
- Only a permissible activity review with every required ratio complete and within its maximum can be `passed`.

There is no floating-point fallback. Values are kept as decimal strings, normalized to base units with `bcmath`, and compared at an eight-decimal calculation scale.

## Policy lifecycle

1. Apply `database/migrations/004_sharia_screening.sql` to an existing installation.
2. Copy `config/sharia-policy.example.json` to `config/sharia-policy.local.json`.
3. Use the current official or licensed standard text to verify the policy's ratio definitions, denominators, maxima, effective date, and applicability.
4. Replace every placeholder, document the reviewer and verification note, set `approved_for_use` to `true`, and retain the supporting source outside Git if licensing requires it.
5. Run `php cron/install-sharia-policy.php config/sharia-policy.local.json`.
6. Confirm the printed version and SHA-256, then run `php cron/healthcheck.php`.

The local policy file is ignored by Git. Activation stores a canonical policy hash and deactivates the previous policy inside a transaction. Old policies and old screening snapshots are not deleted. Reusing a version with different content is refused; changed policy content requires a new version.

## Evidence workflow

The private `Sharia` page lists companies already observed in official exchange filings. For each company, the administrator:

1. records a business-activity classification, description, source URL, and rationale;
2. selects a reporting period and enters each input required by the active policy;
3. records currency, unit scale, evidence note, and an optional stored filing PDF;
4. runs the screening only after reviewing the current evidence set.

Activity reviews are append-only. Replacing a financial input marks the previous record `superseded` rather than deleting it. Every screening stores the policy ID, activity snapshot, ratios, reasons, normalized input snapshot, user, and timestamp.

## Compliance rank

Rank 1–5 is a HalalPulse product indicator, not an AAOIFI rating. It is assigned only to a passing result. **Rank 1 is the cleanest passing result** and rank 5 is a passing result closest to one or more active-policy maxima. The rank reflects the worst utilization of any active-policy maximum:

| Worst maximum utilization | HalalPulse rank |
|---:|---:|
| up to 50% | 1 |
| over 50%, up to 70% | 2 |
| over 70%, up to 85% | 3 |
| over 85%, up to 95% | 4 |
| over 95%, up to 100% | 5 |

Failed and insufficient results have no rank. Investment/multibagger scoring remains a separate downstream layer and must not reinterpret a failed or insufficient Sharia result as eligible.

## Synthetic tests

`tests/fixtures/sharia_policy.json` deliberately contains made-up thresholds and is labeled as a test-only policy. It verifies approval gating, missing-threshold rejection, exact boundary behavior, rank boundaries, unit normalization, missing evidence, currency mismatch, and activity gating. Its values are not religious guidance and must never be activated in production.
