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

**Setup runs once and then closes for good.** Making the first organiser takes
the write lock before it checks whether one exists, and writes a marker whose
key is a primary key — so two requests arriving together cannot both make an
admin, and the database is what refuses the second rather than the code merely
trying not to. The marker also means the page stays shut if every organiser
account is later closed; the way back from that is `php tools/rescue.php`, which
needs the server rather than a browser.

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
| **Email a sign-in link** | They type their address, we email a link that signs them in once. No password to forget. | Email working, and `site_url` |
| **Auth0** | They sign in at your Auth0 tenant and come back. | Domain, client ID, client secret, and `site_url` |
| **Supabase** | They sign in through a Supabase project, which brokers Google, GitHub and the rest. | Project URL, anon key, provider, and `site_url` |

**Everything except passwords needs `site_url` set in `config.php`, and refuses
to run without it.** Both put an address in front of somebody — one in an email,
one handed to a provider — and with `site_url` blank that address comes from the
request's `Host` header, which the caller chooses. Ask for a sign-in link for
somebody else's address while claiming to be your own host, and the email that
lands in their inbox carries a working one-time token pointing at yours.

This used to be a warning on the settings screen while the links went out
anyway. A warning is not a control. The Signing in screen still explains it, but
the methods are held back until it is set — passwords are unaffected, so a site
works before then; it just cannot hand anybody a link.

**Signing in is not permission to order.** Whichever method somebody uses, a
first-time signer lands unapproved and an organiser still has to let them in
before they can buy lunch. Nothing an identity provider says can change that,
and nothing it says can make anybody an organiser.

**Accounts are matched on the provider's subject, not the email address.** Once
somebody is linked, the link holds even if they change their address at the
provider. The alternative, matching on the address every time, would mean
whoever inherits an address at work inherits the wallet that went with it.

**Making that link in the first place is the weak point**, because there is no
subject to go on yet and only the address is left. A confirmed address says the
provider believes this person can read that mailbox today — not that they are
whoever opened the account here. Addresses get reassigned inside an
organisation, recycled by a provider, and issued by tenants somebody else
administers.

So it depends on what the account is worth:

- **Nothing to take** — no balance, no orders, not an organiser: it links on the
  spot. That is the ordinary case, a member whose account an organiser typed in
  last month signing in with Google for the first time, and making them wait
  would cost far more than it protects.
- **Money, a history of orders, or an organiser's account**: the sign-in is
  refused and held as a claim under **Signing in → Waiting to be joined up**,
  for an organiser who knows the congregation to approve or throw away.
  Approving does not sign them in — they go back to the login page and come
  through the provider again, on the subject like anybody else.

An unconfirmed address still matches nothing at all and raises no claim, so
there is nothing for an organiser to approve by mistake. Repeated attempts
update the one claim rather than filling the screen.

### Connecting a provider yourself

**Your account → How you sign in → Connect.** Anyone already signed in can
attach a provider without waiting for an organiser, and disconnect it again.

This is not the address rule with the lock taken off, because the address is not
what authorises it. You have already proved who you are with a credential this
site issued, and the provider is only being written down as another way back —
so the address there does not have to match the one here, which is the ordinary
case for someone connecting a personal login to an account opened under a work
address. It need not even be confirmed, since nothing is being decided from it.

Somebody who takes over an address at a provider cannot use this: it needs a
sign-in here, which is the very thing they were trying to get.

Three things it will not do:

- **Take a provider account that is already somebody else's here.** That would
  remove their way in and hand it over. It refuses and points at an organiser.
- **Start from a link.** Connecting is a POST behind a CSRF token, so nobody can
  walk a signed-in member through attaching *the attacker's* provider account to
  their account — the same prize as the address hole, approached from the other
  side.
- **Finish for anybody but the person who started it.** If the session is gone
  or has become someone else by the time the provider sends them back, it stops.
  There is no fallback to working out who they are from the address.

**Disconnecting cannot lock you out.** The last remaining way in is refused, and
a link to a provider that has since been switched off does not count as one.

Organiser approval stays for the people this cannot help: somebody whose account
an organiser created, who has never had a password and has never signed in, has
nothing to authorise with.

Note what this means for the rescue below: an organiser's **own** first external
sign-in is held too, so proving the outside route works now means signing in,
being held, having the link approved, and coming back.

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

**Registering is throttled.** A script fetches the form for a CSRF token like
anybody else, so the token is not what stops one; without a limit it can write a
row, email the organisers and leave a name to decide about, all night. Three
limits apply — per address, per source address, and one for the whole site,
which is the only one that cannot be got round by varying something. They are
counted in their own table, so a burst of sign-ups cannot lock the congregation
out of signing in. `php tools/rescue.php` clears both.

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

### The side cart

Adding a meal opens a drawer down the side of the page listing what is in the
cart so far, and leaves the menu where it was. A parent ordering for three
children adds a name, adds the next, and only then presses **Checkout** — rather
than being sent to the cart page and having to walk back for each one.

Two of the same dish is usually two different children, and a line can only
carry one name. Where a line has more than one meal on it, both the drawer and
the cart page offer **Name each separately**, which breaks it into a line each,
carrying the original's answers over so nothing has to be retyped.

**The drawer is an enhancement, not a dependency.** It is the only JavaScript in
the app. Without it — blocked, failed to load, or erroring — **Add** posts and
redirects to the cart page, which is how the site worked before it existed. The
script hands anything unexpected back to the browser for the same reason: an
expired form token lands on the real error page rather than on a drawer
inventing an explanation.

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
a portion limit, a schedule, or a per-dish window override.

Clearing a cell in the grid moves that dish to draft rather than deleting it, so
anything ordered against it keeps its history. Days already served are
read-only.

### One-offs

Some things have no weekly rhythm at all: a box of chocolates, a Christmas menu
once a year, something that needs a fortnight's notice. Tick **Show whenever it
can be ordered** on the dish editor and set the from and until dates yourself.

A one-off is kept out of the weekly machinery entirely. It appears in its own
undated **Also available** section under the menu, and never adds a week to the
front page, occupies a cell in the grid, or gets touched by building a month.
It is still listed on `/admin/menu` like any other dish.

Without this the only way to sell one was to give it a service date, which
created a section for that day and pushed the real menu down behind it.

Everything in one order still has to be for the same day, so a one-off whose
window lands on a different date is ordered separately from that Sunday's lunch.

## Finding your way round

The organiser menu sits across the top, or down the side like WordPress —
whichever each organiser prefers. It is stored on the account, so moving it does
not move it for anyone else, and the link to flip it is in the menu itself. On a
narrow screen the sidebar becomes a slide-out drawer behind a **Menu** button,
with tap-sized rows — it used to fold back to the top, where it sat above every
screen and had to be scrolled past to reach anything.

Long screens carry a **Jump to** row at the top. Settings has one; anything else
that grows can add one by setting `$jump` and including
`partials/jump-links.php`.

## The overview

`/admin` opens on the next serving date: how many meals, how much is still to
collect, who is waiting for approval, how much sits in wallets, and the cook
list broken down by pickup location.

The **Serving** picker at the top switches it to any other date on the menu, so
last week can be checked and a fortnight ahead can be seen without leaving the
page. The date is in the URL (`/admin?date=2026-08-16`), so a particular cook
list can be bookmarked or sent on. A date that is not the next serving is
labelled as such, which keeps a printout from being mistaken for this week's.

## Orders

`/admin/orders` is one serving date at a time. Tick any number of orders and
mark them paid or unpaid, cancel them, download those as CSV, or re-send the
confirmation. Cancelling in bulk runs the single-order path once per order, so
the wallet refunds and the emails are identical either way.

**Cancelled orders stay visible.** The filter offers live, cancelled, or both —
a cancellation can move money back into a wallet, and a refund with no record
you can look at is not much of a record.

**A dish taken off the menu does not hide its orders.** Deleting a dish that has
already been sold turns it into a draft rather than removing it, so the orders
keep pointing somewhere. The date stays on every organiser screen even though
nothing published remains on it, and the Orders page names the dish so you are
not left hunting for something that is no longer on the menu.
## Closing accounts, and deleting things

**Closing an account is not deleting it, and that is the point.**
`wallet_entries` and `orders` both cascade from `users`, so removing the row
took the ledger and every order with it — money collected through Zeffy, refunds
given, amounts still owed, gone at once with nothing left to reconcile against.

**Organiser → People → Close account** stops them signing in and keeps
everything they did. It takes away their provider links and any sign-in link
already in their inbox, and it bites on the next request rather than whenever
the session happens to lapse. Reopening puts it back.

**Delete for good** is offered only where there is genuinely nothing to lose —
no orders, no wallet history. A spam registration or an account made while
testing can go outright; anything with money behind it cannot, and the model
refuses it rather than trusting the screen to hide the button.

### The order trash

**Move to trash** takes an order out of the cook list, out of what is still to
collect, and out of the portions it was holding. It is on the orders screen, in
bulk, and the trash is its own screen spanning every date.

**Trashing moves no money.** Cancelling is what gives money back. An organiser
who wants both should cancel first — trashing quietly refunding people would
make emptying the trash a financial act performed by tidying up.

**Delete for good**, from the trash only, is for orders put there while testing.
It is a different thing from cancelling:

| | What it says | What it leaves |
| --- | --- | --- |
| **Cancel** | It happened, and was undone | The order, the refund, both on the record |
| **Delete for good** | It never happened | Nothing — and whatever was charged returns to the balance |

The wallet entries go with the order. Leaving them would be worse than either
option: money movements pointing at an order nobody can open, turning up in a
statement months later with nothing to explain them. The running total printed
beside each remaining entry is rewritten afterwards, or the statement below the
gap would carry on from a figure that no longer follows.

Trashing is done by moving the order's **status**, not by a flag beside it, so
every query that already asks for confirmed orders drops it without being
changed. Restoring puts back whatever it was — a cancelled order comes back
cancelled.

An account whose only orders have been deleted for good becomes deletable again,
which is what makes clearing up after a run-through possible. A top-up on its
own still protects it: money arrived from somewhere and something has to say so.

## Editing an order

**Orders → Edit** changes an order that has already been placed, rather than
making you cancel the whole thing:

- **Change a quantity**, up or down. Zero removes the line. The difference is
  charged or refunded.
- **Add a dish** that is on the menu for that day, and charge for it.
- **Correct a name, group or note** without touching money.
- **Refund an amount** with a reason, for anything the lines cannot express.

Wallet orders are credited or debited as you go. Cash orders record the change
and simply owe more or less on the day — the system never held that money, so it
does not pretend to move it.

The screen carries four figures that are worth understanding, because two of
them sound alike:

| | |
| --- | --- |
| **Food now** | What the remaining lines are worth. Moves as you edit. |
| **Taken so far** | Money in. Only ever grows. |
| **Given back** | Money out. |
| **Can still refund** | Taken minus given back — never more than they paid. |

Keeping "taken" separate from "worth" is what stops an order being edited down to
nothing and then refunded again for money that was never collected.

Below that is the history: every change, why, who made it, and how much. Wallet
orders have a matching line on the member's statement.

Raising a quantity respects portion limits the same way ordering does; lowering
one puts the portions back. A cancelled order can be read but not changed.

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

**Guessing it is throttled**: five wrong tries from one machine and that machine
waits fifteen minutes. Counted per source rather than site-wide, so one cook
mistyping cannot shut the rest of the kitchen out on a Sunday morning, and
getting it right clears the tally. bcrypt used to be the only thing slowing this
down, which was never a limit on the number of guesses — a hundred slow ones in
parallel are a hundred fast ones. `php tools/rescue.php` clears a lockout.

## Notes on orders

Two kinds, both shown in the kitchen table and the CSV, and labelled there so a
cook can tell them apart:

- **Per meal** — "no onions", entered beside the name and group, travelling with
  the one dish it belongs to. Tagged **This meal**, and its own CSV column.
- **Per order** — a free-text box at checkout, the same idea as WooCommerce's
  order notes. Tagged **Whole order**, and its own CSV column.

Either can be typed on the menu card or changed on the cart page. The cart is a
single form, so every button on it — Update, Remove, Place order — carries
everything that has been typed. Nothing depends on pressing a particular button
first, which is what went wrong before 0.11.1.

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

Each entry also stores the running total as it stood, but only so a statement
reads back without re-adding the history. Nothing decides anything from it. That
distinction is what makes the ledger safe under concurrency: two writes racing
could at worst leave one of those figures stale, never the balance.

**`Wallet::post()` takes the write lock before it reads.** It reads the balance
and then writes a row derived from it, and where it opens its own transaction it
opens an immediate one rather than the plain `BEGIN` PDO would issue. Without
that, SQLite in WAL mode refuses the write outright when another connection has
committed since the read — safe, but it arrives as "database is locked" on a
webhook that did nothing wrong, and `busy_timeout` does not help because a stale
snapshot is a conflict rather than a wait. The rule lives in the primitive
because an invariant every caller has to remember is one a caller will
eventually forget.

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
php -d extension=php_pdo_sqlite tests/test-schedule.php          #  51 assertions
php -d extension=php_pdo_sqlite tests/test-app.php               # 365 assertions
php -d extension=php_pdo_sqlite tests/test-signin.php            # 200 assertions
php -d extension=php_pdo_sqlite tests/test-design.php            #  67 assertions
php -d extension=php_pdo_sqlite tests/test-orders-admin.php      #  28 assertions
php -d extension=php_pdo_sqlite tests/test-order-edits.php       #  76 assertions
php -d extension=php_pdo_sqlite tests/test-menu-flexibility.php  #  18 assertions
php -d extension=php_pdo_sqlite tests/test-deletion.php          #  74 assertions
```

All of them run against a throwaway database and need no server. 879 in total.

The HTTP layer — sessions, CSRF, approval gating, checkout, the side cart,
access control — has its own end-to-end run against a live server:

```bash
rm -f data/pause-cafe.sqlite*
php -d extension=php_pdo_sqlite -S 127.0.0.1:8321 -t public router.php &
bash tests/e2e.sh                                                # 280 assertions
```

It expects an empty database, since it drives first-run setup itself.

The one piece no suite covers is the side cart's JavaScript, which needs a
browser. Its server side is covered above, and the fallback is the point: with
the script gone, every form it touches posts and redirects exactly as it did
before the script existed.

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
- **Set `'site_url'` to the site's own address.** Every link the app emails is
  built from it. Left blank, the address comes from the request's `Host` header
  instead — which the caller chooses, so a sign-in link carrying a one-time
  token can be made to point somewhere else. The Sign-in screen warns if
  emailed links are on without it.
- Password sign-in is rate limited: five wrong guesses against one address, or
  forty from one machine, means a fifteen-minute wait. Both expire on their own,
  a successful sign-in clears them, and `php tools/rescue.php` clears the lot.
  The wait is applied to the address as typed, so it cannot be used to find out
  who has an account.
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
