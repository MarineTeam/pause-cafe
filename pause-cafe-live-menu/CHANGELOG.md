# Changelog — Pause Cafe Live Menu

Notable changes to this plugin. Dates are the day the change landed on `main`.

> **Not yet deployed.** No version below has run on a live site. The scheduling
> rules are covered by tests; the admin screens and the WooCommerce filter
> integration have never run inside WordPress.

## 1.0.1 — 2026-08-11

### Fixed

- **CSV export was corrupted on PHP 8.4.** `fputcsv()` without an explicit
  `$escape` is deprecated, and the notice was written straight into the output
  stream — on any host with `display_errors` on, the downloaded kitchen report
  opened with PHP HTML in the first cell. Both call sites now pass `$escape`
  explicitly through a `csv_row()` helper.

## 1.0.0 — 2026-08-11

First version. Same feature set as `pause-cafe-menu`, with publishing as the
trigger instead of a date set ahead.

### Added

- **Publishing is the schedule.** A dish's window opens the instant it goes live
  and runs to the first cutoff after that. Nothing is dated by hand and nothing
  is set up in advance.
- **Cutoff is strictly after publishing**, so a menu published at or after the
  cutoff runs to the following week rather than closing in the past.
- **Publish menu** — one row of dish names and one button, showing the cutoff
  before you commit. Names autocomplete against the existing catalog.
- **Cycles** keyed on their cutoff date, so a whole published menu groups
  together on the kitchen report regardless of when each order landed. Everything
  published in one save shares an opening moment, so all campuses close together.
- **Kitchen report** grouped by service date, with print view and CSV.
- **Legacy items** — a reviewable one-time archive for published products that
  never went through the schedule. Nothing preselected, nothing deleted.
- **Settings** for the cutoff weekday and time, days between cutoff and service,
  default price, and the pickup locations. There is deliberately no "opens at"
  setting — publishing is what opens ordering.
- `[pause_cafe_live_menu]` shortcode with a `cycle` attribute.
- Product editor shows when ordering opened and its current state, plus a
  **Reopen ordering** checkbox.
- Cutoff enforced on the classic **and** block cart and checkout.
- 37 standalone assertions over the rules, runnable without WordPress.

### Notes

- Editing a dish that is already live does **not** restart its window. The stamp
  only fires on the transition into publish, so fixing a typo cannot silently
  move the cutoff. Reopening is explicit.
- Publishing at 09:00 on a Saturday gives a four-hour window — correct per the
  rule, but easy to do by accident.
- Refuses to load beside `pause-cafe-menu`; two sets of filters would disagree
  about whether a dish is buyable.
- Visibility is computed live, so deactivating restores the catalog unchanged.
