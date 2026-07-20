# Filing-document evidence pipeline

Version: 0.4.0

## Purpose

This module acquires the official attachment for a quarterly-result candidate and prepares evidence for human review. It does not decide Sharia compliance, calculate a multibagger score, or treat a text match as an audited financial fact.

## Processing flow

1. `cron/process-documents.php` seeds candidate filings that have an official attachment URL.
2. A MySQL advisory lock prevents overlapping document runs. Interrupted `downloading` records become retryable after 15 minutes.
3. Each run downloads at most the configured small batch, three by default.
4. The HTTPS client permits only configured NSE/BSE archive hosts, refuses redirects, applies a timeout, and aborts over the byte limit.
5. The response must begin with the PDF signature and contain a plausible number of bytes.
6. The file is written to a random temporary name, permissioned, atomically renamed, and stored outside `public_html` under an exchange/year/month path containing its SHA-256 prefix.
7. If the configured `pdftotext` binary and `proc_open` are available, extraction runs with a time limit and bounded output size.
8. Revenue, total income, EBITDA, profit before tax, net profit, and EPS lines may become low-confidence candidates with the exact evidence line retained.
9. The administrator opens the filing page and explicitly accepts or rejects each candidate.

## Storage and viewing

- `storage/documents` is private and ignored except for its directory placeholder.
- The database stores a relative path, byte count, MIME type, and full SHA-256.
- `/document.php` requires an active administrator session, resolves the path beneath the configured root, recomputes the hash, and streams only a matching PDF.
- Raw PDF files are never placed in or linked directly from `public_html`.

## Optional extractor

Shared hosts frequently omit `pdftotext` or disable `proc_open`. This is a supported state. The pipeline records `manual_review`; the administrator can still open the verified private PDF. Do not enable an arbitrary web PDF-to-text service, because that would transmit unpublished workflow data to a third party and violate the official-source/privacy boundary.

To test availability, run:

```sh
php cron/process-documents.php --limit=1
```

Inspect the JSON result and the Documents page. `manual_review` with an unavailable-extractor note means downloading worked but automated text extraction is not available on that host.

## Human-review rule

The parser cannot reliably infer every table header, period column, consolidated/standalone boundary, currency, or unit from arbitrary PDFs. Candidate confidence is therefore capped at 65. Acceptance records the administrator and review time, but downstream engines must additionally require explicit period/unit completion before using a value in a formula.
