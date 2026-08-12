# Changelog — Pause Cafe app

Notable changes to the standalone app. Dates are the day the change landed on
`main`. The current version is in `PAUSE_CAFE_VERSION` in `src/bootstrap.php`.

> **Pre-1.0, and never run in production.** The schedule, model and HTTP layers
> are covered by tests, but the app has not run on the production host and the
> Zeffy webhook has never seen a real payment. It stays below 1.0 until both
> have happened.

## 0.6.0 — 2026-08-12

### Added

- **A grid builder at `/admin/menu/builder`**, the same shape as the flex
  plugin's: service dates down, pickup locations across, type the dish names and
  save a month in one sitting.
- It renders **three ways, following the active mode** — a month grid for
  planned, a single row for on-publish that opens ordering on save, and the
  month grid plus a from/until per row for manual.
- **Autocomplete from past dishes**, using a native `<datalist>` so it needs no
  JavaScript. A new dish takes its price and description from the last one with
  the same name, so a repeat is never priced twice.

### Notes

- **The per-dish editor stays.** The grid is for filling a month; the editor is
  for a price, a description or a portion limit on one dish. Both write the same
  rows, and each screen links to the other.
- Clearing a cell moves that dish to draft rather than deleting it, so anything
  ordered against it keeps pointing somewhere. Retyping the name republishes it.
- Days already served are read-only and are never rewritten by a save.
- Saving an unchanged grid reports no changes rather than touching every row.
- The save logic lives in `MenuBuilder` rather than in the route, which is both
  the convention the rest of the app follows and what makes it testable — the
  route now just hands over the submitted arrays.

## 0.5.0 — 2026-08-12

### Added

- **An email when an order is cancelled**, saying plainly what happened to the
  money. Three outcomes, three different messages:
  - paid from the wallet — names the amount returned and the new balance
  - paid in cash — says the money was not taken from the wallet and to speak to
    an organiser
  - never paid — says there is nothing to refund
- **The order page shows the same thing** once an order is cancelled, so it is
  answerable without going back to the email.
- The organiser's confirmation after cancelling now names the refunded amount
  and the member's resulting balance, rather than saying "anything paid has
  been put back".

### Notes

- Whether a refund happened is read from the wallet ledger — the entry
  `refund:{order}` — rather than inferred from the payment method. That is what
  actually moved, and it is the same row the member sees on their statement, so
  the email cannot claim a refund the ledger does not show.
- Cancelling a cash order that was already collected still writes nothing to the
  wallet. Inventing a credit for money the system never held would put the
  ledger out of step with reality.

## 0.4.0 — 2026-08-12

### Added

- **Email, as a register of transports** chosen in Settings: **Resend** over
  HTTPS, **SMTP** for an existing mailbox, **write to a file** for staging, and
  **PHP mail()**. Each declares its own configuration fields, so the settings
  screen grows a form for a new transport without that screen changing.
- **PHP mail() is the last resort.** If the chosen transport fails, the message
  is retried through it. A confirmation that lands in a spam folder beats one
  silently dropped because an API key expired. Both failures are logged and the
  admin is told which happened.
- **The emails themselves**, all plain text: an order confirmation listing every
  meal with its name, group and note; a notice when an account is approved; and
  a heads-up to organisers when somebody signs up.
- **Send a test to myself** in Settings, which names the transport that carried
  it and says so when the fallback was used.

### Notes

- Every field that reaches a header is stripped of newlines. A newline in a
  subject or address is how a header injection becomes a Bcc to somebody else's
  list, and there is a test asserting a crafted message grows no extra headers.
- Non-ASCII subjects are base64-encoded, so a Chinese dish name in a subject
  line arrives intact rather than as mojibake.
- The SMTP client is written against sockets rather than a library, keeping the
  app free of Composer. Reply parsing and DATA dot-stuffing are covered by
  tests; the socket conversation itself is not, since it needs a real server.
- Notifications never throw. An email that cannot be sent must not undo an order
  that already took payment.
- Saving email settings leaves a blank password box alone rather than erasing
  the stored secret, and the form never renders a stored password back.

## 0.3.0 — 2026-08-12

### Added

- **The kitchen list at `/kitchen`.** A sortable, filterable table of every
  ordered meal: date, pickup, dish, quantity, name, group, payment and notes.
  Sorting is by clicking a column heading; pickup location then group stays the
  tiebreak whatever is chosen, because that is the order food is handed out in.
- **Filters** by date — next 7 days, this week, this month, last 30 days, or a
  custom from/to — and by dish, pickup location and group.
- **Shared-password access.** Cooks and servers open the list on a phone without
  an account; organisers never see the prompt. Leaving the password unset keeps
  it organiser-only. Set it in Settings.
- **A to-cook summary** above the table, so the answer is "cook 12 pork" rather
  than twelve rows to count.
- **Notes on orders**, in two places: a note per meal ("no onions") entered
  beside the name and group, and an order-level note at checkout like
  WooCommerce's. Both show in the kitchen table and the CSV.
- CSV export honouring the current filters and sort.

### Changed

- The admin **Kitchen report** tab now points at `/kitchen`. The old grouped
  summary at `/admin/report` still works.
- Uncaught throwables render an apology and are logged, rather than returning a
  bare 500. Only `RuntimeException` messages — the deliberate, safe ones — are
  still shown to the person.

### Fixed

- **Checkout was fatal**, introduced while adding the order note: the route
  closure did not import `$post`. Caught by the end-to-end run, which now
  asserts checkout's status code — the previous version only checked what
  happened afterwards, so a 500 looked like an empty database rather than the
  real cause.

### Notes

- The sort key is interpolated into `ORDER BY`, which cannot be a bound
  parameter, so it is whitelisted. There is a test that passes
  `qty; DROP TABLE orders --` and asserts the table survives.
- The shared password is bcrypt-verified, which is the only throttle. Enough for
  a page whose worst case is a list of who ordered lunch; anything more valuable
  would want real rate limiting.

## 0.2.0 — 2026-08-12

### Added

- **Payment methods are a register, not a hardcoded wallet.** `Payments\Method`
  is an interface and `Payments` the register; ordering asks what is enabled,
  hands the chosen method the order, and never names one itself. Adding a third
  is implementing the interface and calling `Payments::register()`.
- **Pay on pickup.** Cash orders are created *owing*, appear on an unpaid list
  with a running total still to collect, and an organiser marks them paid when
  the money is in hand. Orders gained `payment_method` and `paid_at`.
- **Groups are a managed list.** Organisers maintain the options in Settings and
  every group field is a dropdown. Free text drifts — "Youth", "youth" and a
  typo become three rows on the cook list.
- Group renaming moves the accounts in it; past orders keep the name they were
  placed under. Removing a group leaves the people in it alone and flags them,
  rather than clearing a field nobody asked to lose.

### Changed

- **The wallet is now optional.** Switched off, the balance and statement
  disappear from member accounts — unless the account still holds money, which
  stays visible until it is settled.
- Cancelling an order asks the payment method to reverse itself. A wallet order
  gets a refund entry; a collected cash order says to return the money in
  person rather than inventing a ledger entry for money never held.
- Settings refuses to disable every payment method, which would leave nobody
  able to order and no way back from the storefront.
- Every route accepting a group runs it through `Groups::sanitise()`. A dropdown
  is a convenience on the form, not a promise about what arrives.

### Notes

- New columns arrive by `ALTER` behind a `PRAGMA table_info` check, since an
  existing install never sees a changed `CREATE TABLE IF NOT EXISTS`.
- Tests grew to 118 model assertions and 46 over real HTTP.

## 0.1.0 — 2026-08-11

First version. The whole system as its own site — no WordPress, no WooCommerce,
no build step. PHP 8.1 and one SQLite file.

### Added

- **Accounts** with admin approval. Registering is not the same as being allowed
  to order, and the gate is checked at checkout on the server rather than only
  hidden in the interface.
- **Prepaid wallet** as an append-only ledger. No balance column anywhere — a
  balance is the sum of its entries, and every entry records who moved the
  money, when, why and what it refers to.
- **Zeffy top-ups.** Their public API is read-only, so payments arrive by
  webhook, matched on email and made idempotent by payment ID. Plus a reconcile
  button against the read-only API, and manual credit/debit for cash.
- **All three scheduling modes** — planned, on publish, and manual — with one
  active at a time, per-dish overrides and blackout dates on top.
- **Per-line name and group**, so one account can order for several people and
  the cook list reads the way the servers need it.
- **Organiser screens** — approve and edit accounts, credit and debit wallets,
  manage the menu and portion limits, place orders on someone's behalf past the
  cutoff and past their balance, cancel and refund, and the kitchen report with
  print and CSV.
- Storefront matching the current pause-cafe.in: Inter, white, square charcoal
  buttons, centred menu headings.
- 51 schedule assertions, 71 model assertions, and 28 driven over real HTTP.

### Notes

- An order and its payment are written in one transaction: a debit without an
  order is money taken for nothing, and an order without a debit is food given
  away.
- Money is integer cents everywhere; floats appear only at the edges.
- Passwords hashed and rehashed on sign-in, session ID regenerated on login,
  CSRF on every state-changing request, every value escaped on output, all SQL
  through prepared statements.
