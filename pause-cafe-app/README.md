# Pause Cafe — standalone app

The lunch ordering system as its own small website. No WordPress, no
WooCommerce, no build step: PHP and a single SQLite file.

It does what the three plugins do, plus the parts WooCommerce was providing —
accounts, a prepaid wallet, checkout — sized for a few dozen orders a week
rather than a shop.

Version history is in [CHANGELOG.md](CHANGELOG.md).

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

Groups are a list organisers manage in Settings, not a text box. Free text
drifts — "Youth", "youth" and a typo become three separate rows on the cook
list. Every place a group is chosen offers the list, and the server checks the
submitted value against it regardless of what the form sent. Renaming a group
moves everyone in it; past orders keep the name they were placed under, which is
what a receipt should do. Removing a group leaves the people in it alone and
flags them in Settings rather than silently clearing their group.

Until at least one group exists the field does not appear at all.

## Paying

Payment methods are a register, not a hardcoded wallet. Two ship with the app,
and organisers switch them on and off in Settings:

| Method | | |
| --- | --- | --- |
| **Wallet balance** | Taken from what has already been topped up | Settles at once |
| **Pay on pickup** | Cash on the day | Order stays **owing** until an organiser marks it paid |

If only one is enabled it is used silently with no choice shown. At least one
has to stay on — the settings screen refuses to turn them all off, since that
would leave nobody able to order and no way to undo it from the storefront.

**The wallet is entirely optional.** Switch it off and the balance, the wallet
statement and the top-up wording disappear from member accounts — unless an
account still holds money, which stays visible until it is settled rather than
quietly vanishing.

Unpaid orders are listed on the orders screen with a running total still to
collect, so nothing is handed over unrecorded.

An order and its payment are written in one transaction: neither can happen
without the other. Cancelling asks the method to reverse itself — a wallet order
gets a refund entry; a cash order that was never collected has nothing to
return, and one that was is settled in person, which the cancellation notice
says rather than inventing a ledger entry.

### Adding another method

Implement `PauseCafe\Payments\Method` and call `Payments::register()` after
`Payments::boot()` in `src/bootstrap.php`. Checkout, settings and the orders
screen pick it up on their own — none of them name a method.

## The kitchen list

`/kitchen` is the page the cooks and servers actually use — every ordered meal
as one sortable table: date, pickup, dish, quantity, name, group, payment and
notes. A to-cook summary sits above it, so the answer is "cook 12 pork" rather
than twelve rows to count.

**Sorting** is by clicking a heading. Pickup location then group stays the
tiebreak whatever column is chosen, because that is the order food is handed out
in — sorting by dish still keeps each campus's groups together underneath.

**Filters:** next 7 days, this week, this month, last 30 days, a custom from/to,
and by dish, pickup location or group. The CSV export honours whatever is
currently filtered and sorted.

**Access.** Organisers always see it. Everyone else needs a shared password, set
in Settings — which lets the kitchen team open it on a phone without an account.
Leave it unset and the page stays organiser-only. It shows member names and what
they ordered, so treat the password the way you would a door key.

## Notes on orders

Two kinds, both shown in the kitchen table and the CSV:

- **Per meal** — "no onions", entered beside the name and group, so it travels
  with the one dish it belongs to
- **Per order** — a free-text box at checkout, the same idea as WooCommerce's
  order notes

## Email

A register of transports, chosen in Settings:

| | |
| --- | --- |
| **Resend** | Over HTTPS with an API key. Works where outbound SMTP ports are blocked, which on shared hosting they often are. Needs a verified sending domain. |
| **SMTP** | An existing mailbox — the church account, Gmail, Fastmail. Gmail needs an app password. |
| **Write to a file** | Nothing is sent; messages append to `data/mail.log`. For staging, and what the tests use. |
| **PHP mail()** | No setup, poor deliverability. |

**PHP mail() is the last resort, not the default.** If the chosen transport
fails, the message is retried through it — a confirmation that lands in a spam
folder beats one silently dropped because an API key expired. Both failures are
logged, and **Send a test to myself** in Settings names the transport that
actually carried it.

Four emails go out, all plain text: an order confirmation listing every meal
with its name, group and note; a cancellation notice; a message when an account
is approved; and a heads-up to organisers when somebody signs up.

The cancellation notice says what became of the money, and says it three
different ways depending on what actually happened — refunded to the wallet
with the new balance, paid in cash so speak to an organiser, or never charged so
nothing to refund. Which one is chosen comes from the **wallet ledger**, not
from the payment method, so the email cannot claim a refund the ledger does not
show.

Adding another: implement `PauseCafe\Mail\Transport` and call
`Mailer::register()`. It declares its own configuration fields, so the settings
screen renders a form for it without changing.

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
