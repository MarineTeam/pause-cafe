# Changelog — Pause Cafe Flex Menu

Notable changes to this plugin. Dates are the day the change landed on `main`.

> **Not yet deployed.** No version below has run on a live site. The window
> resolver is covered by tests, but this plugin has the largest untested admin
> surface of the three — six screens and the WooCommerce filter integration have
> never run inside WordPress.

## 1.0.1 — 2026-08-11

### Fixed

- **CSV export was corrupted on PHP 8.4.** `fputcsv()` without an explicit
  `$escape` is deprecated, and the notice was written straight into the output
  stream — on any host with `display_errors` on, the downloaded kitchen report
  opened with PHP HTML in the first cell. Both call sites now pass `$escape`
  explicitly through a `csv_row()` helper.

## 1.0.0 — 2026-08-11

First version. Covers what the other two plugins cannot: several menus at once,
either open mode per menu, and the operational rules a real week needs.

### Added

- **One resolver.** `PCFM_Window::for_product()` returns an open time, a close
  time and a service date; visibility, the cart guard, the menu and the report
  consume that and nothing else.
- **Precedence chain** — a per-dish override beats the schedule's mode, which is
  adjusted by the location's cutoff offset, and a blackout date voids the window
  outright. An offset large enough to land before the window opens clamps shut
  rather than quietly reopening something.
- **Schedules as a taxonomy** (`pcfm_schedule`) with rules in term meta, so
  dishes are assigned through WordPress's own UI and found with an ordinary
  `tax_query`. Two schedules with different rhythms resolve independently.
- **Three modes per schedule** — `planned`, `on_publish` and `manual`.
- **Portion limits ride on WooCommerce stock** rather than a parallel counter,
  so sold-out handling and the race between two people buying the last one are
  behaviour WooCommerce already tested.
- **Blackout dates** with a label, shown on the storefront in place of the week.
- **Per-location cutoffs**, so one campus can close earlier than the others.
- **Build menu** — one screen, three renderings picked by the schedule's mode.
- **Kitchen report**, **Legacy items**, **Blackout dates** and **Settings**
  screens.
- `[pause_cafe_flex_menu]` shortcode with `schedule`, `weeks` and `date`.
- Cutoff enforced on the classic **and** block cart and checkout.
- 74 standalone assertions, including the full precedence chain and an offset
  large enough to invert a window.

### Notes

- Every mode produces a service date, which is what lets one kitchen report
  cover all three without knowing which is in force.
- Service dates are also written to `_pcfm_resolved_service_date` because two of
  the three modes derive them, which would otherwise make them unqueryable in
  SQL. Resolution always uses the real logic; only listings and the report read
  the copy. Changing a schedule's rules resyncs its dishes.
- Refuses to load beside either of the other two plugins.
- Visibility is computed live, so deactivating restores the catalog unchanged.
