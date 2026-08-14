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
- `curl` and `openssl` only if you sign people in through Auth0 or Supabase.
  Both are present on almost every host; without them those two methods report
  themselves as unavailable rather than failing at the moment somebody tries to
  sign in. Passwords and emailed links need neither.

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

## Schedules

A schedule decides when its dishes can be ordered. Most sites need one; add more
to run a second menu on its own rhythm.

Each one sets its **mode** (planned ahead, on publish, or manual), the **day food
is served**, when ordering **opens and closes**, the **closing time**, whether
upcoming weeks **preview**, which **pickup locations** it serves, and whether it
**shows on the front page**.

**There is always a default schedule, and its rules live in Settings.** Named
schedules are rows in their own table — one source of truth each, nothing
written to both. A site that only ever wants one menu never has to open the
Schedules screen.

A dish with no schedule follows the default. Deleting a schedule detaches its
dishes to the default rather than leaving them unresolvable, and a per-dish
from/until still overrides whichever schedule a dish belongs to.

The front page works out the current week **per schedule**, since two menus on
different rhythms are not on the same one. How many dishes sit across the grid
is an organiser setting, default 3, stepping down to two and then one on
narrower screens.

## The three ordering modes

**Each schedule picks its own mode**, so a planned Sunday lunch and an
on-publish midweek supper can run at the same time. The default schedule's mode
is set in Settings; a named one sets its own.

Whichever mode applies, a dish may carry its own from/until, which overrides it,
and a blackout date voids the window entirely.

| Mode | Ordering opens | Closes |
| --- | --- | --- |
| **Planned** | A set number of days before the service date | A set number of days before, at the cutoff time |
| **On publish** | The moment the dish is published | The next cutoff weekday at the cutoff time |
| **Manual** | Whenever the dish says | Whenever the dish says |

Defaults reproduce the current rhythm: served Sunday, opens Tuesday noon, closes
Saturday 1pm.

## How it looks

**Organiser → Design.** Colours, type, corner rounding, card style, the site
name, a logo and dark mode, for the whole site — the menu, the cart and the
organiser screens together. Three starting points (Plain, Bold, Warm paper) fill
everything in; adjust from there.

It works because [app.css](public/assets/app.css) puts every colour, corner and
typeface behind a CSS custom property and nothing below that block hardcodes
one. The Design screen writes a second `:root` inline in the page containing
**only what differs from the default**, so an untouched site ships no extra
bytes and a site that changed one colour ships one line. Add a hardcoded colour
to the stylesheet and you have opted that element out of both theming and dark
mode.

**Dark mode** has its own built-in palette rather than deriving one from the
chosen colours — a hand-picked dark scheme reads better than an inverted light
one. Set it to follow the visitor's device, or force it either way.

### Themes

A theme is a folder under `themes/`:

```
themes/list/
  theme.php     name and description — this is what makes it a theme
  style.css     loaded after app.css, so it only says what differs
  views/        optional; any template here replaces the built-in one
```

`themes/list/` ships as a working example: it turns the card grid into one dish
per row, using CSS alone. Choose it under Design.

**Templates fall through.** A theme provides what it wants to change and
inherits the rest. The intended place to start is
`views/partials/dish-card.php` — one dish, extracted specifically so a theme can
replace it without copying the ordering rules, the field resolution or the
cutoff handling.

Two things to know:

- **A theme's copy of a template stops tracking the original.** Replace
  `dish-card.php` and a later improvement to the built-in one will not reach
  your site. That is the trade-off for being able to change anything; it is why
  the example theme is CSS only.
- **`themes/` is not web-reachable, and must not be.** It is PHP that runs with
  full access to the app. The stylesheet gets out through a route that reads one
  known filename; the stored theme name is checked against the folders that
  actually exist rather than being used to build a path.

### Pictures

Each dish can have one, set on its edit screen, and a dish without one still
looks deliberate — the card falls back to a typographic layout. Uploads are
capped at 6 MB, re-encoded through GD (which discards anything hiding in the
file) and scaled to 1200px on the longest edge, so a photo straight off a phone
is fine. The type is read from the bytes rather than the filename, and the
stored name is random.

They live in `public/assets/uploads/`, which is gitignored — they are content,
not source, and a deploy must not overwrite them.

## Signing in

Several ways, each switched on or off under **Signing in**. More than one can
run at a time, so a congregation moving to a provider can leave passwords on for
whoever has not moved yet.

| Method | What it is | Setup |
| --- | --- | --- |
| **Password** | An address and a password kept here. What the site has always done. | None |
| **Email a sign-in link** | They type their address, we email a link that signs them in once. No password to forget. | Email has to be working |
| **Auth0** | They sign in at your Auth0 tenant and come back. | Domain, client ID, client secret |
| **Supabase** | They sign in through a Supabase project, which brokers Google, GitHub and the rest. | Project URL, anon key, provider |

**Signing in is not permission to order.** Whichever method somebody uses, a
first-time signer lands unapproved and an organiser still has to let them in
before they can buy lunch. Nothing an identity provider says can change that,
and nothing it says can make anybody an organiser.

**Accounts are matched on the provider's subject, not the email address.** The
first time somebody signs in with a provider, their address decides which
account they join — and only if the provider says it has confirmed that address.
After that the link holds even if they change their address at the provider. The
alternative, matching on the address every time, would mean whoever inherits an
address at work inherits the wallet that went with it.

**Organisers cannot lock themselves out.** Note the word: *organisers*. If you
switch every method off, members cannot sign in, and that is correct — you
turned it off. They see a note asking them to wait. What is protected is your
own way back:

1. **Organisers keep a password route at `/login?rescue=1`**, checked against an
   organiser account, so a member cannot use it. It is forced on whenever
   nothing else is available at all.

2. **It cannot be switched off until an organiser has actually signed in
   through a provider.** A provider whose settings are filled in has proved
   nothing — a client secret with a typo in it looks exactly like a working one
   right up until somebody tries it. So the old door stays until somebody has
   walked through the new one. The checkbox is held on and says why.

3. **If it all goes wrong anyway** — the tenant is deleted, the organiser who
   proved it works has left — there is a command-line way back that depends on
   nothing outside the server:

```bash
php tools/rescue.php
```

That turns password sign-in and the organiser route back on. `--list` shows the
organisers and whether each still has a password; `--reset you@example.org`
generates a new one and prints it, so it never goes near the shell history or an
inbox. It refuses to run over the web.

### Adding another provider

Anything speaking OpenID Connect — Google, Microsoft Entra, Keycloak, Authentik
— is a subclass of `OidcMethod` of about forty lines, saying only where its
endpoints are; `Auth0Method` is the worked example. Register it in
`src/bootstrap.php` next to the others and it appears on both the settings
screen and the login page, with its own fields, without either of those files
changing.

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

## Two ways to add a menu

Both write the same dishes, and each screen links to the other.

**The grid** at `/admin/menu/builder` — service dates down, pickup locations
across. Type the names, save a month in one sitting. Names autocomplete against
past dishes, and a repeat takes its price and description from the last one with
that name, so nothing is priced twice.

It follows the active mode: a month grid for **planned**, a single row for
**on-publish** that opens ordering the moment it is saved, and the month grid
plus a from/until per row for **manual**.

**The list** at `/admin/menu` — one dish at a time, for a price, a description,
a portion limit or a per-dish window override.

Clearing a cell in the grid moves that dish to draft rather than deleting it, so
anything ordered against it keeps its history. Days already served are
read-only.

## The overview

`/admin` opens on the next serving date: how many meals, how much is still to
collect, who is waiting for approval, how much sits in wallets, and the cook
list broken down by pickup location.

The **Serving** picker at the top switches it to any other date on the menu, so
last week can be checked and a fortnight ahead can be seen without leaving the
page. The date is in the URL (`/admin?date=2026-08-16`), so a particular cook
list can be bookmarked or sent on. A date that is not the next serving is
labelled as such, which keeps a printout from being mistaken for this week's.

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

Five emails go out, all plain text: an order confirmation listing every meal
with its name, group and note; a cancellation notice; a notice when a dish
somebody already ordered is corrected; a message when an account is approved;
and a heads-up to organisers when somebody signs up.

The cancellation notice says what became of the money, and says it three
different ways depending on what actually happened — refunded to the wallet
with the new balance, paid in cash so speak to an organiser, or never charged so
nothing to refund. Which one is chosen comes from the **wallet ledger**, not
from the payment method, so the email cannot claim a refund the ledger does not
show.

Adding another: implement `PauseCafe\Mail\Transport` and call
`Mailer::register()`. It declares its own configuration fields, so the settings
screen renders a form for it without changing.

## The questions asked when ordering

Managed under **Fields**, like a WooCommerce add-ons plugin. Each field has a
label, a type — single line, longer text, a list of choices, yes/no, or the
managed group list — and whether it is asked and required.

Visibility resolves in **three levels, each overriding the last**:

1. the field's own setting — the site default
2. the schedule the dish belongs to
3. the dish itself

A level that says nothing inherits. Each override is one four-way control:
inherit, do not ask, ask optional, ask required.

**Name, group and note cannot be deleted.** The kitchen list, the CSV export and
the order emails read them by name, so removing one would break those. Set them
to "do not ask" instead — that hides them without breaking anything, at whatever
level you like.

Answers to fields you add travel with the order and show on the kitchen list,
the CSV, the order page and the confirmation email. They are frozen on the line,
so deleting a field later does not erase what people already told you.

Only visible fields are read from a submitted form, and a list field only
accepts a value it offered — hand-posting a hidden field cannot put anything on
the cook list.

## Correcting a dish after people have ordered

Dishes get fixed — a typo, the wrong name, a price. By then somebody has usually
ordered, so **everyone who has is emailed**, with what it was, what it is now,
what they ordered and what they were charged. Every correction sends a fresh
notice; a dish fixed three times sends three.

Only changes a customer would care about count: name, description, price and
service date. Portion limits, drafting and no-op saves say nothing.

**A notify checkbox** lets you skip the email for a correction nobody would
notice. It shows on the per-dish editor only when the dish has orders, and on
the grid builder for the whole save, and is ticked by default. It silences the
email and nothing else — the rename below still happens.

**Renaming a dish renames it on confirmed orders too.** Order lines are
otherwise a frozen receipt, and this is the deliberate exception — leaving them
alone meant the cook list showed the old name for anyone who ordered before the
correction and the new one for everyone after, which is two dishes as far as the
kitchen can tell. **The price charged is never touched**, and the email says so
outright when the price is what changed.

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

Approve and edit accounts, choose how people sign in, unlink an external
account, reset passwords, credit and debit wallets, manage the
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
- Sign-in links are stored as a SHA-256 of the token, never the token. A leaked
  copy of that table cannot be pasted into a URL. They are single use and
  short-lived, and signing out kills any still outstanding.
- Identity tokens are verified against the provider's published keys: RSA
  signatures only — an unsigned token, or one switched to HMAC over the
  provider's public key, is refused on the algorithm before the signature is
  looked at — plus issuer, audience, nonce and expiry. The authorisation code
  flow uses state, nonce and PKCE, all one-shot.
- An external sign-in never sets a role or an approval, and an address the
  provider has not confirmed matches no existing account.
