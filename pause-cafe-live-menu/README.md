# Pause Cafe Live Menu

WordPress plugin. Weekly lunch menu scheduling for WooCommerce, with no planning
ahead.

Version history is in [CHANGELOG.md](CHANGELOG.md).

**Publishing is the schedule.** A dish's ordering window opens the instant it
goes live and runs to the first cutoff after that. Publishing again opens a
fresh window. Nothing is dated by hand and nothing is set up in advance.

## The model

| When | Dish state |
| --- | --- |
| Published → Saturday 13:00 | Visible and orderable |
| Saturday 13:00 → Sunday | Visible, "ordering is closed" |
| After Sunday | Hidden; direct links redirect to the menu |

The cutoff is the first Saturday 13:00 **strictly after** publishing. A menu
published at or after the cutoff therefore runs to the following week rather
than closing in the past.

One consequence worth knowing: publishing at 09:00 on a Saturday gives a
four-hour window. Correct per the rule, but easy to do by accident.

## Screens

Under **Pause Cafe** in the admin menu:

- **Publish menu** — one row of dish names, one button. It shows the cutoff
  before you commit, and says whether ordering is currently open. Names
  autocomplete against the existing catalog, so a repeat dish brings its photo,
  description and price across.
- **Kitchen report** — what to cook, grouped by location, with quantities and who
  ordered. Print view and CSV.
- **Legacy items** — one-time cleanup for published products that never went
  through the schedule. Nothing preselected; selected items move to draft.
- **Settings** — the cutoff weekday and time, how many days after it the food is
  served, default price, and the pickup locations.

The product editor gains a read-out of when ordering opened and its current
state, plus a **Reopen ordering** checkbox.

## Storefront

```
[pause_cafe_live_menu]
```

Renders whatever is currently published, grouped by pickup location, using the
theme's own product template.

| Attribute | |
| --- | --- |
| `cycle` | Render a specific cutoff date (`YYYY-MM-DD`) instead of the current one. |

## How cycles work

Everything published in one save shares a single opening moment, so all campuses
close together even if the save takes a few seconds. A **cycle** is identified by
its cutoff date, so every dish in one published menu shares a key — which is what
lets the kitchen report group a whole menu regardless of when each order landed.

Editing a dish that is already live does **not** restart its window. The stamp
only fires on the transition into publish, so fixing a typo cannot silently move
the cutoff. Reopening is explicit: the publish screen, or the checkbox on the
product editor.

## Install

1. Zip this directory.
2. WordPress admin → Plugins → Add New → Upload Plugin → Activate.
3. Pickup locations are auto-detected from any product category whose name starts
   with "Pick up". Confirm them in **Settings**.
4. Run **Legacy items** to archive old dishes.
5. Replace the per-campus grids on the menu page with the shortcode.

## Scope

Only products in a configured pickup category, or that this plugin has
published, are governed. Drinks, desserts and special orders are untouched.

Visibility is computed live rather than written into the `product_visibility`
taxonomy, so **deactivating restores the catalog exactly as it was**.

WooCommerce, the wallet, Zeffy, accounts, orders, emails and payment are not
modified.

## Tests

```bash
php tests/test-schedule.php
```

37 assertions covering the cycle, the publish-at-cutoff rollover, the Sunday
reopen path, bad data failing closed, a non-default cutoff day, and the November
DST change. Runs without a WordPress install.

The admin screens and the WooCommerce filter integration are **not** covered.

## Conflicts

Mutually exclusive with `pause-cafe-menu` and `pause-cafe-flex-menu`. This plugin
detects `pause-cafe-menu` and refuses to load beside it.
