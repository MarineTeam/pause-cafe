# Pause Cafe

Weekly lunch menu ordering for the church, running on WordPress + WooCommerce at
[pause-cafe.in](https://www.pause-cafe.in/).

This repository holds `pause-cafe-menu`, a plugin that owns the weekly menu
schedule. It does not replace the storefront, the accounts, or the wallet.

## What the plugin does

Each dish carries one field: a **service date**, the Sunday it is served.
Everything else is derived from it.

| When | Dish state |
| --- | --- |
| Before Tuesday 12:00 | Hidden from listings |
| Tuesday 12:00 → Saturday 13:00 | Visible and orderable |
| Saturday 13:00 → Sunday | Visible, "ordering closed" |
| After Sunday | Hidden, direct links redirect to the menu |

The window is set once in Settings, relative to the service day, so no dish is
ever scheduled by hand and two dishes in the same week cannot disagree about when
ordering closes.

### Screens

- **Build menu** — a grid of service dates down, pickup locations across. Type
  dish names, save. Products are created or updated with the right category,
  service date and SKU. Names autocomplete against the existing catalog, so a
  repeat dish brings its photo, description and price across.
- **Kitchen report** — what to cook for a given service date, grouped by
  location, with quantities and who ordered. Print view and CSV.
- **Legacy items** — a one-time cleanup listing published products with no
  service date. Nothing is preselected; selected items move to draft, never
  deleted.
- **Settings** — the ordering window, the service weekday, default price,
  whether upcoming weeks preview, and which product categories are pickup
  locations.

### Storefront

`[pause_cafe_menu]` renders the week's dishes grouped by pickup location, using
the theme's own product template. It replaces the per-campus product grids that
had to be re-pointed every week.

Attributes:

- `weeks` — how many service dates to show. `0` shows every upcoming week.
  Defaults to `1`, or all weeks when previews are on.
- `date` — render one specific `YYYY-MM-DD` instead.

The nearest week always shows, whether or not ordering has opened, with a line
explaining when it opens. That replaces the current behaviour where the
add-to-cart button silently disappears with no explanation.

## What it deliberately does not touch

Wallet System for WooCommerce, the Zeffy integration, accounts, login, orders,
emails, checkout and payment are all left alone. Visibility is computed live
rather than written into the `product_visibility` taxonomy, so **deactivating the
plugin restores the catalog exactly as it was**.

## Scope of the schedule

A product is governed only if it carries a service date, or sits in one of the
configured pickup categories. Drinks, desserts and special orders are untouched
and stay on sale.

## Install

1. Zip the `pause-cafe-menu` directory.
2. WordPress admin → Plugins → Add New → Upload Plugin.
3. Activate. Pickup locations are auto-detected from any product category whose
   name starts with "Pick up".
4. Open **Pause Cafe → Settings** and confirm the window and the location
   mapping.
5. Open **Pause Cafe → Legacy items** and archive the old undated dishes.
6. Replace the per-campus grids on the menu page with `[pause_cafe_menu]`.

## Retires

- WPC Product Timer — the schedule is derived, not configured per product.
- Advanced Order Export, for the weekly kitchen list.
- The three per-campus Essential Addons product grids.
- The weekly category shuffle, and the Tuesday deadline.

Check the kitchen report against a known week before turning Advanced Order
Export off.
