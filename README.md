# Pause Cafe

Weekly lunch menu ordering for the church, running on WordPress + WooCommerce at
[pause-cafe.in](https://www.pause-cafe.in/).

This repository holds two plugins that solve the same problem in different ways.
**Install one or the other, never both** — they each filter whether a dish is
buyable, and running both would have them fighting. `pause-cafe-live-menu`
refuses to load if `pause-cafe-menu` is active.

Neither replaces the storefront, the accounts, or the wallet.

## Choosing between them

| | `pause-cafe-menu` | `pause-cafe-live-menu` |
| --- | --- | --- |
| Ordering opens | Tuesday noon, from a service date set ahead | The moment you publish |
| Planning | A month at a time | None |
| Weekly effort | None for three weeks in four | One screen, one button |
| Closes | Saturday 1pm | Saturday 1pm |
| Reopens | Automatically, next week | When you publish again |
| Admin screen | Month grid, dates down, campuses across | One row of dish names |
| Late menu | Week opens without dishes in it | Nothing shows until you publish |

Take the first if you want the Tuesday deadline gone and are happy to plan a
month ahead. Take the second if planning ahead is the part that never happens.

## Shared behaviour

Both plugins:

- Hide and unsell dishes outside their window, computed live rather than written
  into the `product_visibility` taxonomy, so **deactivating restores the catalog
  exactly as it was**
- Enforce the cutoff on the classic *and* block cart and checkout, covering the
  add-at-12:58, check-out-at-13:05 case that would otherwise debit a wallet for
  food the kitchen was never told to cook
- Redirect direct links to dishes from a finished week
- Explain themselves — "ordering opens Tuesday", "ordering is closed" — instead
  of silently dropping the add-to-cart button
- Group the kitchen report by the day food is served, not the day the order was
  placed, with print and CSV
- Offer a reviewable one-time archive for old undated products, which are
  currently still orderable through a direct link
- Autocomplete dish names against the existing catalog, carrying the photo,
  description and price across for a repeat
- Leave WooCommerce, the wallet, Zeffy, accounts, orders, emails and payment
  completely alone

Only products in a configured pickup category, or products the plugin itself has
scheduled, are governed. Drinks, desserts and special orders stay on sale.

## `pause-cafe-menu` — plan ahead

Each dish carries a **service date**, the Sunday it is served. Everything is
derived from it.

| When | Dish state |
| --- | --- |
| Before Tuesday 12:00 | Hidden |
| Tuesday 12:00 → Saturday 13:00 | Visible and orderable |
| Saturday 13:00 → Sunday | Visible, "ordering closed" |
| After Sunday | Hidden |

Screens: **Build menu** (month grid), **Kitchen report**, **Legacy items**,
**Settings**.

Shortcode `[pause_cafe_menu]`, attributes `weeks` and `date`. The nearest week
always shows, whether or not ordering has opened; `weeks="0"` shows every
upcoming week.

## `pause-cafe-live-menu` — publish and go

Publishing is the schedule. A dish's window opens when it goes live and runs to
the first cutoff after that.

| When | Dish state |
| --- | --- |
| Published → Saturday 13:00 | Visible and orderable |
| Saturday 13:00 → Sunday | Visible, "ordering closed" |
| After Sunday | Hidden |

Publishing again reopens ordering for a fresh window. A menu published *after*
Saturday 1pm runs to the following week rather than closing in the past.

Screens: **Publish menu** (one row), **Kitchen report**, **Legacy items**,
**Settings**.

Shortcode `[pause_cafe_live_menu]`, attribute `cycle`.

## Install

1. Zip the plugin directory you picked.
2. WordPress admin → Plugins → Add New → Upload Plugin.
3. Activate. Pickup locations are auto-detected from any product category whose
   name starts with "Pick up".
4. **Settings** — confirm the cutoff and the location mapping.
5. **Legacy items** — archive the old undated dishes.
6. Replace the per-campus grids on the menu page with the shortcode.

```bash
git archive --format=zip --prefix=pause-cafe-menu/ -o pause-cafe-menu.zip HEAD:pause-cafe-menu
```

## Tests

Each plugin ships a standalone check of its rules engine, runnable without a
WordPress install:

```bash
php pause-cafe-menu/tests/test-schedule.php
```

23 assertions for `pause-cafe-menu`, 37 for `pause-cafe-live-menu`. The admin
screens and WooCommerce filter integration are **not** covered — install on
staging first.

## Retires

WPC Product Timer, the three per-campus Essential Addons product grids, the
weekly category shuffle, and the Tuesday deadline. Advanced Order Export too,
once the kitchen report has been checked against a week you already know the
answer to.
