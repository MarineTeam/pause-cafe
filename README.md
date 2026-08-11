# Pause Cafe

Weekly lunch menu ordering for the church, running on WordPress + WooCommerce at
[pause-cafe.in](https://www.pause-cafe.in/).

This repository holds three plugins that solve the same problem at different
levels of ambition. **Install exactly one.** Each decides whether a dish is
buyable, so two running at once would have competing filters disagreeing.
`pause-cafe-flex-menu` refuses to load if either of the others is active, and
`pause-cafe-live-menu` refuses to load beside `pause-cafe-menu`.

None of them replaces the storefront, the accounts, or the wallet.

## Choosing one

| | `pause-cafe-menu` | `pause-cafe-live-menu` | `pause-cafe-flex-menu` |
| --- | --- | --- | --- |
| Ordering opens | Tuesday noon, from a service date set ahead | The moment you publish | Either, per schedule, plus per-dish overrides |
| Planning | A month at a time | None | Your choice |
| Menus at once | One | One | As many as you like |
| Portion limits | — | — | Yes, on WooCommerce stock |
| Holidays | — | — | Blackout dates with labels |
| Per-location cutoffs | — | — | Yes |
| Admin screen | Month grid | One row | Month grid, one row, or from/until — by mode |
| Size | ~2,800 lines | ~3,100 lines | ~5,200 lines |

Start with the simplest one that covers what you need. `pause-cafe-menu` if
planning a month ahead suits you, `pause-cafe-live-menu` if it never happens,
`pause-cafe-flex-menu` if you need more than one menu or the operational rules.

## Shared behaviour

All three:

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

Only products in a configured pickup category, on a schedule, or carrying
scheduling dates are governed. Drinks, desserts and special orders stay on sale.

## `pause-cafe-menu` — plan ahead

Each dish carries a service date. Hidden before Tuesday noon, orderable until
Saturday 1pm, visible but closed through Sunday, gone after.

Shortcode `[pause_cafe_menu]`, attributes `weeks` and `date`.

## `pause-cafe-live-menu` — publish and go

Publishing is the schedule. The window opens when a dish goes live and runs to
the first Saturday 1pm after that. Publishing again reopens it.

Shortcode `[pause_cafe_live_menu]`, attribute `cycle`.

## `pause-cafe-flex-menu` — everything, configurable

Everything resolves through one function, `PCFM_Window::for_product()`, which
returns an open time, a close time and a service date. Visibility, the guard,
the menu and the report consume only that.

Precedence, highest first:

1. **Per-dish override** — `from` and `until` set on the dish
2. **Schedule mode** — planned, on publish, or manual
3. **Per-location offset** — pulls the close earlier, never later
4. **Blackout date** — voids the window entirely

A schedule is a term in the `pcfm_schedule` taxonomy with its rules in term
meta, so dishes are assigned through the normal product UI. Every mode produces
a service date, which is what lets one kitchen report cover all of them.

Portion limits run on WooCommerce stock rather than a parallel counter, so
sold-out handling, cart validation and the race between two people buying the
last one are WooCommerce's own, already-tested behaviour.

Screens: **Build menu**, **Schedules**, **Kitchen report**, **Blackout dates**,
**Legacy items**, **Settings**.

Shortcode `[pause_cafe_flex_menu]`, attributes `schedule`, `weeks`, `date`.
Omitting `schedule` renders every schedule that currently has a window.

## Install

1. Zip the plugin directory you picked.
2. WordPress admin → Plugins → Add New → Upload Plugin.
3. Activate. Pickup locations are auto-detected from any product category whose
   name starts with "Pick up".
4. **Settings** — confirm the locations. For the flex plugin, create a schedule
   next and set its rules.
5. **Legacy items** — archive the old undated dishes.
6. Replace the per-campus grids on the menu page with the shortcode.

```bash
git archive --format=zip --prefix=pause-cafe-flex-menu/ -o pause-cafe-flex-menu.zip HEAD:pause-cafe-flex-menu
```

## Tests

Each plugin ships a standalone check of its rules engine, runnable without a
WordPress install:

```bash
php pause-cafe-flex-menu/tests/test-window.php
```

23 assertions for `pause-cafe-menu`, 37 for `pause-cafe-live-menu`, 74 for
`pause-cafe-flex-menu` (whose file is `tests/test-window.php`; the other two use
`tests/test-schedule.php`).

The admin screens and WooCommerce filter integration are **not** covered —
install on staging first, and keep Advanced Order Export running until the
kitchen report matches a week whose answer you already know.

## Retires

WPC Product Timer, the three per-campus Essential Addons product grids, the
weekly category shuffle, and the Tuesday deadline. Advanced Order Export too,
once the kitchen report has been checked against a known week.
