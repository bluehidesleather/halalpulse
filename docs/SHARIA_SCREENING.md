# Sharia screening policy and evidence workflow

## Safety boundary

HalalPulse does not ship numerical Sharia thresholds and does not claim that a remembered or internet-copied formula is current. AAOIFI lists **Sharia Standard No. 21 — Financial Paper (Shares and Bonds)** on its official standards catalogue and provides an official e-standards access route. AAOIFI also states that stakeholders should refer to its website regularly and that it does not approve copies circulated through other channels.

The 2026 AAOIFI announcement for the draft English translation of Standards 1–61 explicitly labels that translation unofficial and directs users to the official website version or the latest printed official edition. Therefore an exposure draft, announcement page, third-party summary, screener methodology, social-media post, or remembered threshold must never become the governing HalalPulse policy source.

The software applies a supplied policy deterministically. It is a research aid, not a fatwa, religious ruling, investment recommendation, or substitute for a qualified Sharia adviser.

## Fail-closed rules

A screening can be `passed`, `failed`, or `insufficient`.

- No active policy: screening is unavailable.
- Unapproved policy or missing threshold: installation is refused.
- An AAOIFI policy citing a third-party, draft, consultation, or announcement URL: installation is refused.
- A ratio without an exact governing clause, numerator definition, or denominator definition: installation is refused.
- Prohibited business-activity review: `failed` before ratio calculation.
- Pending or mixed business-activity review: `insufficient`.
- Missing required numerator or denominator: `insufficient`.
- Mismatched currencies: `insufficient`.
- Zero denominator: `insufficient`.
- Missing PHP `bcmath`: calculation is refused.
- Any complete required ratio above its maximum: `failed`.
- Only a permissible activity review with every required ratio complete and within its maximum can be `passed`.

There is no floating-point fallback. Values are kept as decimal strings, normalized to base units with `bcmath`, and compared at an eight-decimal calculation scale.

## Policy verification fields

Every ratio in the local policy must record all of the following:

- exact machine keys for the numerator and denominator;
- exact decimal maximum;
- exact official clause reference;
- the reviewed definition and inclusions for the numerator;
- the reviewed denominator and measurement basis;
- whether the ratio is required.

These fields are retained inside the canonical `ratios_json` snapshot and are included in the policy SHA-256 identity. Changing a threshold, definition, or clause therefore changes the policy content and requires a new version.

## Policy lifecycle

1. Apply `database/migrations/004_sharia_screening.sql` to an existing installation.
2. Copy `config/sharia-policy.example.json` to the ignored `config/sharia-policy.local.json`.
3. Access the current official AAOIFI e-standard or latest printed official edition and verify Standard No. 21.
4. Record the exact edition, language, access date, governing clauses, ratio definitions, denominator basis, maxima, effective date, and applicability.
5. Have an independent qualified reviewer confirm the mapping from the official text to every JSON field.
6. Replace every placeholder but keep `approved_for_use` as `false` while the file is still under review.
7. Run the non-mutating readiness check:

```sh
php cron/check-sharia-policy.php config/sharia-policy.local.json
```

8. Resolve every `[BLOCKED]` item. The command makes no database changes.
9. After final approval, set `approved_for_use` to `true` and run the readiness check again.
10. Activate only a `[READY]` file:

```sh
php cron/install-sharia-policy.php config/sharia-policy.local.json
```

11. Confirm the printed version and SHA-256, then run `php cron/healthcheck.php`.

The local policy file is ignored by Git. Activation stores a canonical policy hash and deactivates the previous policy inside a transaction. Old policies and old screening snapshots are not deleted. Reusing a version with different content is refused; changed policy content requires a new version.

## Evidence workflow

The private `Sharia` page lists companies already observed in official exchange filings. For each company, the administrator:

1. records a business-activity classification, description, source URL, and rationale;
2. selects a reporting period and reviews each input required by the active policy;
3. records currency, unit scale, evidence note, and an official source reference;
4. runs the screening only after reviewing the current evidence set.

Activity reviews are append-only. Replacing a financial input marks the previous record `superseded` rather than deleting it. Every screening stores the policy ID, activity snapshot, ratios, reasons, normalized input snapshot, user, and timestamp.

## Structured NSE XBRL candidates

Migration `011_sharia_xbrl_candidates.sql` adds a review queue between official NSE XBRL evidence and accepted Sharia inputs. The five-minute NSE worker may create a `pending` candidate only when all of the following are true:

- the filing was processed successfully and retained in the private archive;
- the reporting period and ISO currency are present;
- the source fact uses the matching monetary unit;
- the value fits exactly inside the configured decimal precision without rounding;
- the fact mapping is explicitly supported.

The first supported mapping is deliberately narrow:

- `Income` or `TotalIncome` → `total_revenue`, confidence 90%;
- `RevenueFromOperations` → `total_revenue` fallback, confidence 75%, with mandatory other-income review.

The mapper does **not** reinterpret `OtherIncome` as impermissible income, `DebtEquityRatio` as interest-bearing debt, or any unrelated balance-sheet fact as an AAOIFI input. Missing evidence remains missing.

An administrator may accept a pending candidate only when its metric key is required by the active policy. Acceptance supersedes the previous current input for the same company, period and metric, stores the XBRL item and fact provenance, and records the reviewer and time. Rejection also remains in the audit trail. The interface labels acceptance as `Policy required` while the policy gate is closed.

For an existing installation, apply migration 011 before deploying the worker code. Then create candidates for already processed filings with:

```sh
php cron/backfill-sharia-candidates.php --limit=500
```

The backfill is idempotent. Running it again does not duplicate the same item, metric, fact and context. A partial result exits non-zero and must be reviewed before treating the queue as complete.

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

`tests/fixtures/sharia_policy.json` deliberately contains made-up thresholds and clause references and is labeled as a test-only policy. It verifies approval gating, official-source gating, clause provenance, missing-threshold rejection, exact boundary behavior, rank boundaries, unit normalization, missing evidence, currency mismatch, and activity gating. Its values are not religious guidance and must never be activated in production.
