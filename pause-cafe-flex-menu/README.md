# Pause Cafe Flex Menu

WordPress plugin. Menu scheduling for WooCommerce with several menus at once,
either open mode per menu, per-dish overrides, portion limits, blackout dates
and per-location cutoffs.

The most capable of the three, and the largest. If a single weekly menu on a
fixed rhythm is all you need, `pause-cafe-menu` or `pause-cafe-live-menu` will
be less to look after.

## The one abstraction

Everything resolves through one function:

```php
PCFM_Window::for_product( $id )   // → open_from, close_at, service_date, source
```

Visibility, the cart guard, the menu and the kitchen report consume that and
nothing else. Adding a mode means teaching one class rather than four
subsystems.

### Resolution order

1. **Per-dish override** — `from` and `until` both set on the dish, wins outright
2. **The schedule's mode** — `planned`, `on_publish`, or `manual`
3. **The location's cutoff offset** — pulls the close *earlier*, never later
4. **Blackout dates** — void the window entirely

Every mode produces a `service_date`, which is what lets one kitchen report
cover all three without knowing which is in force.

An offset large enough to land before the window opens leaves a zero-length
window, so a misconfigured offset fails closed rather than quietly reopening
something.

## Schedules

A schedule is a term in the `pcfm_schedule` taxonomy with its rules in term
meta. That means dishes are assigned through WordPress's own UI and found with
an ordinary `tax_query`, rather than through a bespoke registry.

Each schedule sets its own mode, cutoff, which locations it serves, per-location
offsets, default portions and default price. Two can run side by side — a Sunday
lunch closing Saturday 1pm and a Wednesday supper closing Tuesday 6pm resolve
completely independently.

| Mode | Ordering opens | Closes |
| --- | --- | --- |
| `planned` | A set number of days before the service date | A set number of days before, at the cutoff time |
| `on_publish` | The moment the dish is published | The next cutoff weekday at the cutoff time |
| `manual` | Whenever the dish says | Whenever the dish says |

## Portion limits ride on WooCommerce stock

Setting a portion count sets `manage_stock`, `stock_quantity` and
`backorders=no`. Sold-out handling, cart validation and the race between two
people buying the last one are WooCommerce's own, already-tested behaviour — not
a parallel counter.

## Screens

Under **Pause Cafe** in the admin menu:

- **Build menu** — pick a schedule; the grid renders per its mode. A month grid
  for planned, a single row for on-publish, from/until pickers for manual. One
  screen, three renderings.
- **Schedules** — create and configure, including locations and their offsets
- **Blackout dates** — a date plus a label, shown on the storefront
- **Kitchen report** — schedule, then service date. Print view and CSV.
- **Legacy items** — one-time cleanup for products on no schedule
- **Settings** — site-wide locations, default price, menu page

The product editor gains the schedule, the resolved window, the service date,
the per-dish override fields and a portions field.

## Storefront

```
[pause_cafe_flex_menu]
```

| Attribute | |
| --- | --- |
| `schedule` | Slug or ID. Omit to render every schedule that currently has a window. |
| `weeks` | Service dates to show. `0` shows all upcoming. |
| `date` | Render one specific `YYYY-MM-DD`. |

## A note on service dates

Two of the three modes *derive* the service date rather than storing it, which
makes it unqueryable in SQL. It is therefore also written to a denormalised key,
`_pcfm_resolved_service_date`, whenever a dish changes. Resolution always uses
the real logic; only listings and the report read the copy.

Changing a schedule's rules resyncs every dish on it, because that can move all
of their dates.

## Install

1. Zip this directory.
2. WordPress admin → Plugins → Add New → Upload Plugin → Activate.
3. **Settings** — confirm the auto-detected pickup locations.
4. **Schedules** — create at least one and set its rules. Nothing works until a
   schedule exists.
5. **Legacy items** — archive old dishes.
6. Put the shortcode on the menu page.

## Scope

Only products on a schedule, carrying scheduling dates, or in a configured
pickup category are governed. Drinks, desserts and special orders are untouched.

Visibility is computed live rather than written into the `product_visibility`
taxonomy, so **deactivating restores the catalog exactly as it was**.

WooCommerce, the wallet, Zeffy, accounts, orders, emails and payment are not
modified.

## Tests

```bash
php tests/test-window.php
```

74 assertions covering each mode in isolation, the precedence chain, an offset
large enough to invert a window clamping shut instead, blackouts, two schedules
resolving independently, bad data failing closed, and the November DST change.
Runs without a WordPress install.

Six admin screens and the WooCommerce filter integration are **not** covered —
this plugin has the largest untested admin surface of the three. Staging first.

## Conflicts

Mutually exclusive with the other two. This plugin detects both and refuses to
load beside either.
