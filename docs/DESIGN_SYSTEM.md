# HalalPulse design system

Version: 1.0.0  
Date: 2026-07-21

## Product direction

HalalPulse is a financial-research and evidence-review product. Its visual identity must communicate precision, restraint, trust, and long-term value. It must not look like a generic religious template.

Sharia compliance does not imply a green brand. Green is reserved for a genuine positive semantic state such as a completed, accepted, permissible, passed, delivered, or healthy result. It is not used for the page background, navigation, brand mark, policy banner, score emphasis, or ordinary calls to action.

## Core palette

- Warm ivory `#f6f3ed` — application background.
- Porcelain `#fffdf9` — primary cards and inputs.
- Soft stone `#f2eee7` — secondary surfaces.
- Charcoal `#24211d` — primary text.
- Muted stone `#6f6960` — supporting text.
- Muted bronze `#7d623d` — brand, links, focus, and refined emphasis.
- Blue-grey `#52687f` — informational and neutral workflow states.
- Subdued green `#3f6f5e` — success-only semantic state.
- Burnished amber `#8b5c24` — warning and incomplete state.
- Muted red `#9a4747` — failure, rejection, and prohibited state.

The design tokens live in `public_html/assets/app.css`. Components must consume those tokens instead of introducing unrelated brand colors.

## Typography

Body copy uses the system sans-serif stack for dense research screens and predictable shared-host rendering. Major headings and the brand use a restrained system serif stack to add editorial and investment-research character without requiring an external font service.

## Component principles

- Pages remain predominantly light, with no large dark panels.
- Cards use porcelain or soft-stone surfaces, subtle borders, and low-opacity warm shadows.
- Primary actions use a deep neutral ink color, not green.
- Navigation uses charcoal text and a bronze active indicator.
- Policy gates use a light blue-grey informational treatment.
- Scores use bronze emphasis; they are not represented as religious approval.
- Success, warning, danger, and information colors are never interchangeable.
- Decorative Islamic motifs, crescents, mosque silhouettes, geometric ornaments, and automatic green branding are excluded.

## Accessibility guard

`tests/design-system.php` verifies:

- the browser color scheme remains light;
- primary and muted text retain WCAG contrast on the main surface;
- the accent remains distinct from semantic success green;
- legacy dark-green colors cannot silently return;
- brand and Sharia policy components do not use the success token; and
- the stylesheets retain balanced declaration blocks.

Run it with:

```sh
php tests/design-system.php
```

This automated guard checks the locked design boundary. Final browser review at desktop and mobile widths is still required before deployment.
