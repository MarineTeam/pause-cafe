# Pause Cafe Menu

WordPress plugin. Weekly lunch menu scheduling for WooCommerce, planned a month
ahead.

Each dish carries one field — a **service date**, the Sunday it is served — and
everything else is derived from it. There is no per-dish timer to configure, and
two dishes in the same week cannot disagree about when ordering closes.

## The model

| When | Dish state |
| --- | --- |
| Before Tuesday 12:00 | Hidden from listings |
| Tuesday 12:00 → Saturday 13:00 | Visible and orderable |
| Saturday 13:00 → Sunday | Visible, "ordering closed" |
| After Sunday | Hidden; direct links redirect to the menu |

The window is set once in Settings, relative to the service day. Defaults match
the existing rhythm: served Sunday, opens Tuesday noon, closes Saturday 1pm.

## Screens

Under **Pause Cafe** in the admin menu:

- **Build menu** — a grid, service dates down, pickup locations across. Type
  dish names and save; products are created or updated with the right category,
  service date and SKU. Names autocomplete against the existing catalog, so a
  repeat dish brings its photo, description and price across.
- **Kitchen report** — what to cook for a service date, grouped by location, with
  quantities and who ordered. Print view and CSV.
- **Legacy items** — one-time cleanup listing published products with no service
  date. Nothing is preselected; selected items move to draft, never deleted.
- **Settings** — the ordering window, service weekday, default price, whether
  upcoming weeks preview, and which product categories are pickup locations.

A **Service date** field is also added to the normal product editor, and a
column to the products list.

## Storefront

```
[pause_cafe_menu]
```

Renders the week's dishes grouped by pickup location, using the theme's own
product template. Replaces per-campus product grids that had to be re-pointed
every week.

| Attribute | |
| --- | --- |
| `weeks` | How many service dates to show. `0` shows every upcoming week. Defaults to `1`, or all weeks when previews are on. |
| `date` | Render one specific `YYYY-MM-DD` instead. |

The nearest week always shows, whether or not ordering has opened, with a line
explaining when it opens — rather than the add-to-cart button silently vanishing.

## Install

1. Zip this directory.
2. WordPress admin → Plugins → Add New → Upload Plugin → Activate.
3. Pickup locations are auto-detected from any product category whose name starts
   with "Pick up". Confirm them in **Settings**.
4. Run **Legacy items** to archive old undated dishes.
5. Replace the per-campus grids on the menu page with the shortcode.

## Scope

Only products in a configured pickup category, or carrying a service date, are
governed. Drinks, desserts and special orders are untouched.

Visibility is computed live rather than written into the `product_visibility`
taxonomy, so **deactivating restores the catalog exactly as it was**.

WooCommerce, the wallet, Zeffy, accounts, orders, emails and payment are not
modified.

## Tests

```bash
php tests/test-schedule.php
```

23 assertions covering the full window, both boundary minutes, bad data failing
closed, service days per month, and the November DST change. Runs without a
WordPress install.

The admin screens and the WooCommerce filter integration are **not** covered.

## Retires

WPC Product Timer, the per-campus product grids, the weekly category shuffle,
and the Tuesday deadline. Advanced Order Export too, once the kitchen report has
been checked against a week whose answer you already know.

## Conflicts

Mutually exclusive with `pause-cafe-live-menu` and `pause-cafe-flex-menu` — each
decides whether a dish is buyable, and two at once would disagree. Those two
detect this plugin and refuse to load.
