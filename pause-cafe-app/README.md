# Pause Cafe — standalone app

The lunch ordering system as its own small website. No WordPress, no
WooCommerce, no build step: PHP and a single SQLite file.

It does what the three plugins do, plus the parts WooCommerce was providing —
accounts, a prepaid wallet, checkout — sized for a few dozen orders a week
rather than a shop.

## Requirements

- PHP 8.1 or newer with `pdo_sqlite`
- Anywhere that runs WordPress will run this

## First run

```bash
cp config.example.php config.php     # then edit it
php -d extension=php_pdo_sqlite -S 127.0.0.1:8321 -t public router.php
```

Open <http://127.0.0.1:8321>. With no accounts yet it goes straight to setup and
creates the first organiser. The database and the three pickup locations
(Marine, RCC, Fraser) are created on first request.

The `-d extension=php_pdo_sqlite` is only needed where SQLite is not enabled in
`php.ini`. On most hosts it already is.

## Deploying

Point the domain's document root at **`public/`**. Nothing else should be
reachable over the web — `config.php`, the database and `src/` all sit above it.
`public/.htaccess` handles the rewrite on Apache, and denies database files as a
second line of defence in case the docroot is misconfigured.

Back up by copying `data/pause-cafe.sqlite`. That one file is everything.

## The three ordering modes

One is active at a time, chosen in Settings. Whichever is active, a dish may
carry its own from/until, which overrides the mode, and a blackout date voids
the window entirely.

| Mode | Ordering opens | Closes |
| --- | --- | --- |
| **Planned** | A set number of days before the service date | A set number of days before, at the cutoff time |
| **On publish** | The moment the dish is published | The next cutoff weekday at the cutoff time |
| **Manual** | Whenever the dish says | Whenever the dish says |

Defaults reproduce the current rhythm: served Sunday, opens Tuesday noon, closes
Saturday 1pm.

## Ordering

Only approved accounts can order. Anyone can register, but an organiser has to
approve them, and that is checked on the server at checkout — not just hidden in
the interface.

Each cart line carries a **name** and a **group**, so one account can order for
several people and the cook list reads the way the servers need it. Both default
to the account holder's own name and group.

Checkout debits the wallet. The order and the debit are written in one
transaction: neither can happen without the other.

## Wallet

An append-only ledger. There is no balance column anywhere — a balance is the
sum of the entries, and every entry records who moved the money, when, why, and
what it refers to.

Three ways money arrives:

- **Zeffy webhook.** Zeffy POSTs to `/webhook/zeffy` when a payment completes;
  the matching account is credited by email address. A payment ID makes it
  idempotent, so a redelivered webhook cannot credit twice.
- **Zeffy reconciliation.** A button in Settings pulls payments from the
  read-only API and credits anything not seen yet.
- **By hand.** Organisers credit or debit any account from the People screen,
  with a note. This is the path for cash.

Zeffy's public API is read-only, so it cannot take a payment on our behalf —
members pay through a Zeffy form and the webhook tells us about it.

> The exact webhook payload shape could not be verified without a live Zeffy
> account. Extraction is deliberately tolerant of several plausible field names,
> and every raw payload is written to `data/zeffy.log`. **Check that log after
> the first real payment** and tighten `Zeffy::extract()` to the fields actually
> sent.

## What organisers can do

Approve and edit accounts, reset passwords, credit and debit wallets, manage the
menu and portion limits, set blackout dates, switch ordering mode, place orders
on someone's behalf (which ignores the cutoff and the balance, but still records
the debit and still respects portion limits), cancel and refund, and read the
kitchen report with print and CSV.

## Tests

```bash
php -d extension=php_pdo_sqlite tests/test-schedule.php   # 51 assertions
php -d extension=php_pdo_sqlite tests/test-app.php        # 71 assertions
```

Both run against a throwaway database and need no server.

The HTTP layer — sessions, CSRF, approval gating, checkout, access control — has
its own end-to-end run against a live server:

```bash
rm -f data/pause-cafe.sqlite*
php -d extension=php_pdo_sqlite -S 127.0.0.1:8321 -t public router.php &
bash tests/e2e.sh                                          # 28 assertions
```

It expects an empty database, since it drives first-run setup itself.

## Security notes

- Passwords hashed with `password_hash()`, rehashed on sign-in when the cost
  changes. Sign-in on an unknown address still runs a hash, so response timing
  does not reveal who has an account.
- Session ID regenerated on sign-in.
- CSRF token on every state-changing request.
- Every value escaped on output.
- All SQL goes through prepared statements.
- Set `'https' => true` in `config.php` once you are behind TLS; that marks the
  session cookie secure.
