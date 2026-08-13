# Pause Cafe

Weekly lunch menu ordering for the church, currently running on WordPress +
WooCommerce at [pause-cafe.in](https://www.pause-cafe.in/).

Four implementations of the same thing, in increasing order of how much they
replace. **Run exactly one.**

| | What it is | Docs |
| --- | --- | --- |
| **`pause-cafe-menu`** | WordPress plugin. Plan a month ahead; each dish carries a service date. | [README](pause-cafe-menu/README.md) |
| **`pause-cafe-live-menu`** | WordPress plugin. Publishing opens ordering; no planning at all. | [README](pause-cafe-live-menu/README.md) |
| **`pause-cafe-flex-menu`** | WordPress plugin. Several menus at once, portion limits, blackouts, per-location cutoffs. | [README](pause-cafe-flex-menu/README.md) |
| **`pause-cafe-app`** | The whole system as its own site. No WordPress, no WooCommerce. | [README](pause-cafe-app/README.md) |

The three plugins **extend** the existing WooCommerce site. The app **replaces**
it, and brings its own accounts, wallet and checkout.

## Choosing

| | menu | live-menu | flex-menu | app |
| --- | --- | --- | --- | --- |
| Ordering opens | Tue noon, from a service date | On publish | Either, per menu | Either, per menu |
| Planning | A month at a time | None | Your choice | Your choice |
| Menus at once | One | One | Many | Many |
| Portion limits | — | — | Yes | Yes |
| Blackout dates | — | — | Yes | Yes |
| Per-location cutoffs | — | — | Yes | — |
| Bulk entry | Month grid | Single row | Month grid | Month grid |
| Accounts, wallet, checkout | WooCommerce | WooCommerce | WooCommerce | Built in |
| Needs WordPress | Yes | Yes | Yes | **No** |
| Lines | ~3,000 | ~2,900 | ~4,700 | ~10,000 |

**At 20–30 orders a week, start with the simplest one that covers what you
need.** The app is the better-tested code and the cleaner system, but adopting
it means migrating accounts and wallet balances — the one migration where a
mistake means owing people money you cannot reconstruct.

Worth noticing in that table: **`pause-cafe-flex-menu` is now only ahead of the
app on per-location cutoffs.** It earns its place if you are staying on
WordPress and need several menus; if you were going to leave WordPress anyway,
the app does everything it does and brings its own accounts and payment.

## What they all do

- Hide and unsell dishes outside their window, computed live rather than
  written into stored state, so **turning one off restores things exactly**
- Enforce the cutoff at the moment of ordering, covering the add-at-12:58,
  check-out-at-13:05 case that would otherwise take money for food the kitchen
  was never told to cook
- Redirect direct links to dishes from a finished week
- Explain themselves — "ordering opens Tuesday", "ordering is closed" — rather
  than silently dropping the button
- Group the kitchen report by the day food is served, not the day the order was
  placed, with print and CSV
- Autocomplete dish names against past dishes, so a repeat carries its
  description and price across — and, in the plugins, its photo

## Tests

```bash
php pause-cafe-menu/tests/test-schedule.php               # 23
php pause-cafe-live-menu/tests/test-schedule.php          # 37
php pause-cafe-flex-menu/tests/test-window.php            # 74
php -d extension=php_pdo_sqlite pause-cafe-app/tests/test-schedule.php   # 51
php -d extension=php_pdo_sqlite pause-cafe-app/tests/test-app.php        # 302
```

Plus 123 assertions over real HTTP for the app — see
[its tests README](pause-cafe-app/tests/README.md).

610 assertions in total. **Scope of that number matters:** the rules engines are
well covered everywhere, and the app additionally has its model and HTTP layers
covered. The plugins' admin screens and WooCommerce filter integration are
**not** tested — they have never run inside WordPress. Install on staging first.

## Deploying

- **Plugins** — zip the directory, upload through Plugins → Add New.
- **App** — point the domain's document root at `pause-cafe-app/public/`, keeping
  everything else above it. See [the app README](pause-cafe-app/README.md).

```bash
git archive --format=zip --prefix=pause-cafe-flex-menu/ -o pause-cafe-flex-menu.zip HEAD:pause-cafe-flex-menu
```

## Open question

**How Zeffy credits the wallet.** Their public API is read-only and cannot take
a payment for us; the app relies on their completed-payment webhook, matched by
email. The payload shape is the one assumption in the money path that has not
been verified against a live account — the app logs every raw payload to
`data/zeffy.log` so it can be tightened after the first real payment.
