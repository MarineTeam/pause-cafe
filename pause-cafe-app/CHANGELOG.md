# Changelog — Pause Cafe app

Notable changes to the standalone app. Dates are the day the change landed on
`main`. The current version is in `PAUSE_CAFE_VERSION` in `src/bootstrap.php`.

> **Pre-1.0, and never run in production.** The schedule, model and HTTP layers
> are covered by tests, but the app has not run on the production host and the
> Zeffy webhook has never seen a real payment. It stays below 1.0 until both
> have happened.

## 0.10.1 — 2026-08-14

### Fixed

- **The organiser password rescue could be switched off into a lockout.** With
  passwords off for members, a provider configured but never actually used, and
  the rescue turned off, there was no way into the site at all. The check meant
  to catch this counted a *configured* provider as a way in, so it never fired.

  The rescue can now only be turned off once an organiser has genuinely signed
  in through a provider. Until then the checkbox is held on and explains what
  would have to happen first. Everything else on the form still saves; only that
  switch is overridden.

### Added

- **`tools/rescue.php`**, a command-line way back in for when it goes wrong
  anyway — the tenant is deleted, or the organiser who proved it works has left.
  It turns password sign-in and the organiser route back on, lists organisers
  and whether they still have a password, and can generate a new one. The
  password is printed rather than taken as an argument, so it stays out of the
  shell history. It refuses to run over the web.

### Notes

- The guard is about proof, not configuration. A client secret with a typo in it
  is configured, and looks exactly like a working one until somebody tries it.
  Deleting the organiser who proved a provider works withdraws the proof.

## 0.10.0 — 2026-08-13

### Added

- **How people sign in is now a choice**, and more than one way can run at
  once — the same arrangement as the payment methods. Organisers manage it
  under **Signing in**.
- **Email a sign-in link.** No password: they type their address and get a link
  that works once and expires. Suits a congregation that orders lunch weekly and
  forgets its password in between.
- **Auth0**, configured with a domain, client ID and client secret. Endpoints
  and signing keys come from the tenant's discovery document, so a rotated key
  needs nothing changed here.
- **Supabase**, configured with a project URL and anon key, brokering whichever
  social provider the project has enabled.
- **Linked accounts** on the same screen, with an unlink button, so a wrong link
  can be undone without deleting anybody.

### Notes

- **Signing in still is not permission to order.** However somebody arrives,
  a first-time signer lands unapproved and an organiser has to let them in.
  Nothing a provider says sets a role or an approval.
- **Accounts are matched on the provider's subject, not the email address.** The
  address decides which account somebody joins the first time, and only if the
  provider has confirmed it; after that the link holds. Matching on the address
  every time would hand a wallet to whoever inherits an address.
- **You cannot lock yourself out.** If every method is off or misconfigured the
  password comes back on by itself, and organisers keep a password route at
  `/login?rescue=1` unless that is deliberately turned off.
- Sign-in links are stored as a SHA-256 of the token, never the token itself.
  Single use, short-lived, throttled per account, and revoked on sign-out.
- Identity tokens are verified against the provider's published keys — RSA only,
  so an unsigned token or one switched to HMAC over the public key is refused on
  the algorithm — plus issuer, audience, nonce and expiry. The code flow uses
  state, nonce and PKCE.
- Adding a fifth method is a class and one `SignIn::register()` call. Anything
  speaking OpenID Connect is a subclass of `OidcMethod` of about forty lines.

### Not verified

**No real identity provider has ever been contacted.** Token verification is
tested properly, because that can be done offline against a generated key.
Everything either side of it — the redirect Auth0 actually sends, the shape of a
Supabase token response, whether a callback URL was allowed — follows the
specifications and nothing more. Expect the first live attempt to fail on
something small, most likely the callback URL.

## 0.9.1 — 2026-08-13

### Added

- **The overview can show any serving date, not only the next one.** A picker
  at the top of **Organiser** lists every date on the menu, newest first, with
  the next serving marked. Choosing one reloads the cook list for that date.
- **Still to collect** joins the stat row: what is owed on the chosen date by
  people who have not paid.
- A button under the cook list opens the full kitchen list already filtered to
  the chosen date.

### Notes

- The chosen date lives in the URL (`/admin?date=2026-08-16`), so a particular
  cook list can be bookmarked or sent to whoever is cooking.
- A date that is not on the menu is ignored rather than queried, and the page
  falls back to the next serving.
- Picking a date other than the next serving marks the heading, so a printed
  cook list cannot be mistaken for this week's.

## 0.9.0 — 2026-08-12

### Added

- **The questions asked when ordering are now configurable**, the way a
  WooCommerce add-ons plugin does it. Organisers manage them under **Fields**.
- **Three levels, each overriding the last** — the site default, then the
  schedule, then the individual dish. A level that says nothing inherits.
- **New field types**: single line, longer text, a list of choices, yes/no, and
  the managed group list.
- Answers to added fields travel with the order and appear on the kitchen list,
  the CSV export, the order page and the confirmation email.

### Notes

- **Name, group and note cannot be deleted.** The kitchen list, the CSV and the
  order emails read them by name, so removing one would break those. They can be
  set to "do not ask" at any level, which hides them without breaking anything,
  and the Fields screen offers no remove control for them at all.
- Each override is one four-way control — inherit, do not ask, ask optional, ask
  required — because the fourth combination, hidden but required, is not
  something anyone means.
- **Only visible fields are read from a form.** Hand-posting a hidden field
  cannot smuggle a value onto the cook list, and a list field only accepts what
  it offered.
- Answers are stored as JSON on the order line, frozen like the rest of it.
  Deleting a field later does not erase what people already told you.
- A required field with nothing to prefill stays visible on the dish card;
  everything else folds away, so ordering for yourself is still one click.

### Fixed

- **A template that threw left its output buffer open**, so the half-rendered
  page was flushed at shutdown alongside the error page. That reads as a mangled
  success rather than a failure, and is much harder to diagnose than a clean
  500. Found while building this — a view was missing an import.

## 0.8.1 — 2026-08-12

### Added

- **A notify checkbox**, so a correction nobody would notice does not have to
  wake anyone up. It appears on the per-dish editor only when the dish actually
  has orders, and on the grid builder for the whole save. Ticked by default —
  telling people is the safer miss.
- The organiser is told either way: how many were emailed, or how many were not
  "as you asked".

### Notes

- **The checkbox silences the email, never the rename.** Renaming a dish still
  updates confirmed order lines whatever the box says, because that is data
  consistency rather than courtesy — silencing it would leave the cook list with
  two names for one pot.
- The opt-out is per save. `MenuChanges::forget()` puts notification back on, so
  a request that opted out cannot leave the next one silent.
- An unticked checkbox is not submitted at all, so the form carries a companion
  hidden field. Without it there is no way to tell "unticked" from "this form
  has no such control", and the safe default differs between the two.

## 0.8.0 — 2026-08-12

### Added

- **Correcting a dish emails everyone who has already ordered it.** The message
  names what it was, what it is now, what they ordered, and what they were
  charged.
- **Every correction notifies.** A dish fixed three times sends three emails —
  the third correction matters as much as the first to whoever has to eat it.
- Watched fields are the ones a customer would care about: name, description,
  price and service date. Changing the portion limit, drafting a dish, or saving
  with nothing altered tells nobody.
- The organiser is told how many people were emailed, on both the per-dish
  editor and the grid builder.

### Changed

- **Renaming a dish renames it on confirmed orders too.** Order lines are
  otherwise a frozen receipt, and this is the deliberate exception: leaving them
  meant the cook list showed the old name for anyone who ordered before the
  correction and the new one for everyone after — two dishes as far as the
  kitchen could tell, one pot in reality. **The price charged is never touched.**

### Notes

- A price change is announced but never re-charged. The email says outright that
  nothing further will be taken, because that is the first thing anyone reading
  it will want to know.
- Cancelled orders are excluded, and keep whatever name they were placed under.
- The hook lives in `Menu::save()` rather than in the routes. Both the editor
  and the grid builder go through it, and a route that forgot to announce would
  silently change somebody's lunch.

## 0.7.1 — 2026-08-12

### Fixed

- **The front page was never a grid.** Each pickup location rendered its own
  grid, so a location with one dish put that card alone in column one with two
  empty columns beside it, three times down the page. A week's dishes now share
  one grid and sit side by side, with the pickup shown as a label on each card.
- **"Sign in to order" stretched the full width of its card.** The card is a
  flex column, and flex items are blockified — an `inline-block` button became
  `block`. It is wrapped now, so it takes its natural width.
- **The order form made every card enormous.** Four stacked inputs per dish.
  Name, group and note now sit in a closed disclosure — still submitted, since a
  closed `<details>` posts its fields — leaving quantity and the button on one
  row. Ordering for yourself is one click; the fields unfold only when the meal
  is for somebody else.

### Changed

- The stylesheet's cache-buster is the app version rather than a hardcoded `v=1`,
  so a release cannot ship CSS that browsers keep a stale copy of.

## 0.7.0 — 2026-08-12

### Added

- **Multiple schedules.** A Sunday lunch and a Wednesday supper can run side by
  side, each with its own rules, and the builder has a picker to switch between
  them.
- Each schedule sets **when ordering opens** (planned ahead, on publish, or
  manual), **the day food is served**, how many days before it opens and closes,
  **the closing time**, whether upcoming weeks preview, and **which pickup
  locations it serves**.
- **Show on front page** per schedule, so a staff-only menu can exist without
  appearing publicly.
- **Dishes across on the front page**, an organiser setting, default 3. It steps
  down to two and then one on narrower screens whatever is chosen.
- Per-dish from/until still overrides whichever schedule a dish belongs to.

### Notes

- **There is always a default schedule, and its rules live in Settings.** Named
  schedules are rows in a new table. One source of truth each; nothing is
  written to both. A site that only ever wants one menu never has to know the
  table exists, and every existing install keeps working untouched — which is
  why the 296 assertions written before this change still pass unaltered.
- A dish with no schedule follows the default, and deleting a schedule detaches
  its dishes to the default rather than leaving them unresolvable.
- Cells are keyed on schedule as well as date and location, so two schedules
  serving the same campus on the same day do not overwrite each other.
- The front page works out the current week **per schedule**, since two menus on
  different rhythms are not on the same one.

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
