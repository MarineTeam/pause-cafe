# Changelog — Pause Cafe Menu

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

First version. Replaces per-product timer configuration with a single service
date per dish.

### Added

- **Schedule derived from one field.** A dish carries a service date; opening
  time, cutoff and expiry are worked back from it, so nothing is scheduled by
  hand and two dishes in the same week cannot disagree about when ordering
  closes. Defaults to served Sunday, opens Tuesday noon, closes Saturday 1pm.
- **Build menu** — a month grid, service dates down and pickup locations across.
  Dish names autocomplete against the existing catalog, carrying a repeat's
  photo, description and price across.
- **Kitchen report** grouped by service date rather than order date, with print
  view and CSV. An order placed early for a later week lands on the right cook
  list, which order-date grouping gets wrong.
- **Legacy items** — a reviewable one-time archive for published products with
  no service date. Nothing is preselected and nothing is deleted; selected items
  move to draft so past orders keep their history.
- **Settings** for the ordering window, service weekday, default price, upcoming
  week previews, and which product categories are pickup locations.
- `[pause_cafe_menu]` shortcode with `weeks` and `date` attributes, rendering
  through the theme's own product template.
- Service date field on the product editor and a column on the products list.
- Cutoff enforced on the classic **and** block cart and checkout, covering the
  add-at-12:58, check-out-at-13:05 case that would otherwise take money for food
  the kitchen was never told to cook.
- Direct links to dishes from a finished week redirect to the menu.
- 23 standalone assertions over the rules, runnable without WordPress.

### Notes

- Visibility is computed live rather than written into the `product_visibility`
  taxonomy, so deactivating restores the catalog exactly as it was.
- Only products in a configured pickup category, or carrying a service date, are
  governed. Drinks, desserts and special orders are untouched.
- WooCommerce, the wallet, Zeffy, accounts, orders, emails and payment are not
  modified.
