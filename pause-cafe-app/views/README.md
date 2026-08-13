# `views/` — templates

Plain PHP templates. No template engine.

`View::render( 'menu', $data )` extracts `$data` into the template's scope,
captures the output, then renders `layout.php` with that output as `$content`.

## Rules

**Escape everything.** `e()` is a global helper defined in `bootstrap.php`.
Every value that reaches the page goes through it:

```php
<h3><?= e( $item['name'] ) ?></h3>
```

The only exception is `<?= $content ?>` in the layout, which is already-rendered
HTML.

**Every form that changes something carries a token:**

```php
<form method="post" action="/cart/add">
    <?= \PauseCafe\Csrf::field() ?>
```

A missing or wrong token returns 419.

**Templates may read, not write.** They call into `Auth`, `Settings`, `Menu` and
so on to display things. Anything that changes state belongs in a route handler.

## Files

| | |
| --- | --- |
| `layout.php` | Page shell: header, nav, flash messages, the unapproved-account banner, footer |
| `menu.php` | The storefront. Dishes grouped by pickup location, with the name and group fields on each add-to-cart form. |
| `cart.php` | Editable lines, the wallet sum, and the checkout button |
| `account.php` | Balance, order history, wallet statement, change password |
| `order.php` | One order as a receipt |
| `kitchen.php` | The kitchen list — filters, to-cook summary, sortable table |
| `kitchen-locked.php` | The shared-password prompt shown to anyone not signed in |
| `login.php` `register.php` `setup.php` | Sign in, sign up, first-run organiser |
| `error.php` | 403, 404, 419 and anything else thrown |
| `partials/group-select.php` | The group dropdown, used in five places — see below |
| `admin/` | Organiser screens — see below |

## `partials/group-select.php`

Set `$gs` then include it:

```php
<?php
$gs = array( 'id' => 'group-42', 'value' => $user['group_name'] );
include __DIR__ . '/partials/group-select.php';
?>
```

Keys: `name` (default `group_name`), `value`, `id`, `label` (`''` renders the
select alone), `required`.

It renders **nothing** when no groups are configured, so callers wrapping it in
a `<div class="field">` should guard with `Groups::any()` to avoid an empty box.
A value no longer on the list is kept as a final option marked "(no longer
listed)" rather than being silently dropped.

## `admin/`

`_tabs.php` is included at the top of every admin template and marks the current
tab from the request path.

| | |
| --- | --- |
| `dashboard.php` | Counts, the active mode, this week's cook list |
| `menu.php` `menu-edit.php` | Dish list and per-dish editor. The editor renders different fields per active mode. |
| `menu-builder.php` | The grid: dates down, locations across. Three renderings, picked by mode. |
| `schedules.php` | Named schedules and their rules. The default points at Settings. |
| `fields.php` | The order-field registry. Built-ins show no remove control. |
| `partials/order-fields.php` | Renders the configured questions on a dish or a cart line. |
| `partials/field-rules.php` | The per-level override control, used on schedules and dishes. |
| `orders.php` `order-new.php` | Orders for a date; placing one on someone's behalf |
| `report.php` | The kitchen report, with print styling |
| `users.php` | People, approval, wallet credit/debit, password reset |
| `settings.php` | Mode, rules, locations, blackouts, Zeffy |

## Styling

One stylesheet, `public/assets/app.css`. It deliberately matches the current
pause-cafe.in: Inter, white, near-black text, square charcoal buttons, centred
menu headings. No framework and no build step — edit the file.
