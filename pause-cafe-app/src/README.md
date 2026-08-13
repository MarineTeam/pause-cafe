# `src/` — application code

Plain PHP classes under the `PauseCafe\` namespace, autoloaded by class name
from `bootstrap.php`. No framework, no Composer.

Nothing here echoes HTML and nothing here reads `$_POST`. Request handling lives
in `public/index.php` and `routes-admin.php`; rendering lives in `views/`.

## Wiring

| File | |
| --- | --- |
| `bootstrap.php` | Autoloader, loads config, configures every class, runs migrations. Included by the front controller *and* by the tests. |
| `Database.php` | The SQLite connection and the whole schema. `migrate()` is idempotent and runs on every request. |
| `Router.php` | Method + path to callable. `{name}` placeholders arrive as handler arguments. |
| `View.php` | Renders a template into a layout. Also flash messages and redirects. Defines the `e()` escape helper. |

## Domain

| File | |
| --- | --- |
| `Schedule.php` | The rules engine. Three modes, plus `freeze()` so tests can pin the clock. |
| `Schedules.php` | Named schedules, and the settings-backed default every install has. |
| `Window.php` | The answer for one dish: open time, close time, service date, state. |
| `Blackouts.php` | Days no menu runs. A blackout voids a window. |
| `Settings.php` | Key/value store, cached per request. `active_mode` chooses the schedule mode. |
| `Menu.php` | Dishes and pickup locations. Every read comes back with its `Window` attached. |
| `MenuBuilder.php` | The grid way of entering a menu. Alongside the per-dish editor, not instead of it. |
| `MenuChanges.php` | Who to tell when a dish they already ordered is corrected, and what to update. |
| `MenuFields.php` | The questions asked when ordering, and the three-level visibility resolution. |
| `Cart.php` | The session cart. Lines are a list, not keyed by dish — the same dish for two people is two lines. |
| `Orders.php` | Placing, cancelling and reporting. Owns the transaction that ties an order to its wallet debit. |
| `Wallet.php` | The append-only ledger. |
| `Payments.php` | The register of payment methods. Ordering asks it what is enabled and never names one. |
| `Payments/Method.php` | The interface a payment method implements. |
| `Payments/WalletMethod.php` | Debits the ledger. Settles at once. |
| `Payments/CodMethod.php` | Cash on the day. Records nothing; the order carries its own owing state. |
| `Users.php` | Accounts, authentication, the approval gate. |
| `Groups.php` | The managed list of groups. `sanitise()` is the gate every route runs submitted values through. |
| `Kitchen.php` | Who may see the kitchen list, and turning date presets into a from/to pair. |
| `Money.php` | Integer cents in, formatted strings out. |
| `Auth.php` | Session handling and the current user. |
| `Csrf.php` | Tokens for every state-changing request. |
| `Zeffy.php` | Webhook authorisation, payload extraction, wallet crediting, API reconciliation. |
| `Mailer.php` | The register of email transports, and the one entry point for sending. Falls back to mail(). |
| `Mail/Transport.php` | The interface an email transport implements. |
| `Mail/Message.php` | One email and the MIME it becomes. Strips newlines from every header field. |
| `Mail/{Php,Smtp,Resend,Log}Transport.php` | The four built-in ways to send. |
| `Notifications.php` | The app's actual emails. Every method fails quietly. |

## Three things to know before changing anything

**Money is integer cents, everywhere.** In the database, in the cart, in the
ledger. Floats appear only at the edges, in `Money::format()` and
`Money::parse()`.

**The wallet has no balance column.** A balance is `SUM(delta_cents)`. Each entry
also stores the running balance so a statement reads back without re-adding
history, but the entries are the truth. Write through `Wallet::post()` and
nothing else.

**The default schedule is backed by settings, named ones by rows.** One source
of truth each — never write a schedule's rules to both. `Schedules::rulesFor()`
is the only thing that needs to know which is which, and an id it does not
recognise falls back to the default rather than leaving a dish unresolvable.

**A sort key cannot be bound.** `ORDER BY` takes no parameters, so the kitchen
table's sort column is looked up in `Orders::sortableColumns()` and anything
unrecognised falls back. Never interpolate a request value there directly.

**A dropdown is not validation.** Groups are offered as a `<select>`, but every
route that accepts one passes it through `Groups::sanitise()`, which returns the
canonical spelling or an empty string. Nothing trusts the form.

**`Orders::place()` owns its transaction.** SQLite only takes a write lock up
front with `BEGIN IMMEDIATE`, which PDO cannot issue through
`beginTransaction()`. Driving it with `exec()` means PDO never registers a
transaction, so `commit()` and `rollBack()` would throw — hence the private
`begin`/`commit`/`rollback` trio, which must be used as a set. `Wallet::post()`
takes `$ownTransaction = false` when called from inside it.

**Order lines are a frozen receipt, with exactly one exception.** Correcting a
dish's name rewrites it on confirmed lines, because otherwise the cook list
carries two names for one pot. The price charged is never rewritten, and
`MenuChanges` is the only thing allowed to touch a line after checkout.

**Sending email must never break the thing that triggered it.** `Notifications`
returns a failed `Result` rather than throwing; an order that already took
payment cannot be undone by a mail server being down.

## Adding an email transport

1. Implement `PauseCafe\Mail\Transport` in `src/Mail/`
2. `Mailer::register( new YourTransport() )` after `Mailer::boot()` in `bootstrap.php`

`configFields()` is what the settings screen renders, so declaring a field is
all it takes to get a form control and have the value saved. `send()` must not
throw — return a failed `Result` so the caller can fall back.

## Adding a payment method

1. Implement `PauseCafe\Payments\Method` in `src/Payments/`
2. `Payments::register( new YourMethod() )` after `Payments::boot()` in `bootstrap.php`

`charge()` and `refund()` run **inside** the order's transaction, so they must
not open one of their own — anything they write is rolled back with the order if
a later step fails. Return `false` from `settlesImmediately()` if payment lands
later; the order is then created owing and appears on the unpaid list.

Checkout, the settings toggles and the orders screen all read the register, so
none of them need changing.

## Adding a scheduling mode

1. Add the constant and label to `Schedule::modes()`
2. Add a branch to `Schedule::forItem()` that sets `openFrom`, `closeAt` and
   `source`
3. Make sure it yields a `serviceDate` — the kitchen report groups on it
4. Add a rendering branch to the builder in `views/admin/menu-edit.php`

Visibility, the guard, the cart and the report need no changes: they only ever
read a `Window`.
