# `tests/` — how to run them

No PHPUnit, no Composer. Each file is a script that prints results and exits
non-zero on failure.

`-d extension=php_pdo_sqlite` is only needed where SQLite is not already enabled
in `php.ini`. On most hosts it is, and you can drop the flag.

## Without a server

Both use a throwaway database in the system temp directory, created fresh on
each run and never touching `data/`.

```bash
php -d extension=php_pdo_sqlite tests/test-schedule.php   # 51 assertions
php -d extension=php_pdo_sqlite tests/test-app.php        # 160 assertions
```

**`test-schedule.php`** — window resolution. Each of the three modes on its own,
a per-dish override beating the active mode, blackouts voiding a window, preview
behaviour, bad data failing closed, both boundary minutes around every cutoff,
and the November DST change where the clocks go back mid-window.

**`test-app.php`** — everything above the schedule. Accounts and the approval
gate, the wallet ledger, placing orders, portion limits, the cutoff, an
organiser ordering past it, balance checks, cancel-and-refund, the kitchen
report, the Zeffy webhook including a redelivery being ignored, the managed
group list — including that a forged group never reaches the database, and that
renaming one moves its members while leaving past orders alone — and the payment
register, covering a cash order staying owing until marked paid, the wallet
being switched off entirely, and an unknown method being refused.

It also covers the kitchen table: filtering by date range, dish, location and
group; sorting, including that an injected sort key falls back to the default
and leaves the table standing; and the shared-password access rules.

## With a server

```bash
rm -f data/pause-cafe.sqlite*
php -d extension=php_pdo_sqlite -S 127.0.0.1:8321 -t public router.php &
bash tests/e2e.sh                                          # 68 assertions
```

**`e2e.sh`** drives real HTTP with cookie jars: first-run setup, a bad CSRF token
getting 419, creating groups, registration, the approval gate, the group field
rendering as a dropdown with no text box anywhere, a forged group being
discarded, a wallet top-up, add-to-cart, checkout debiting the balance, a dish
selling out at its portion limit, the kitchen report, the CSV export, a cash
order placed and later marked paid by an organiser, and a member getting 403
from the admin area.

It expects an **empty** database, since it drives setup itself. Override the
address with `BASE=http://... bash tests/e2e.sh`.

`-t public` matters: without it the built-in server looks for static files in
the project root and every stylesheet 404s.

## Writing more

`harness.php` provides `fresh_database()`, `check()`, `check_throws()` and
`finish()`. `Schedule::freeze( $moment )` pins the clock so window behaviour is
deterministic.

Two habits worth keeping:

- **Assert that a refused action changed nothing.** Several tests check the
  balance is untouched after a rejected order. A guard that throws but has
  already moved money is worse than no guard.
- **When a test fails, work out which side is wrong.** Two assertions here were
  wrong rather than the code: a next-week dish is rejected on its window before
  the mixed-date check runs, and a dish name filtered out of the kitchen table
  still appears in the filter dropdown, which is what a dropdown is for.
- **Assert the status code of anything that redirects.** A fatal in checkout
  returned 500 and every later assertion just saw an empty database — the cause
  was ten steps upstream of where the failures appeared.

## Not covered

Concurrency, load, and any real Zeffy account. The webhook is tested against
synthetic payloads whose shape is an assumption — see the note in the main
README.
