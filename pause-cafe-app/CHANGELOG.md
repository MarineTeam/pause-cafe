# Changelog — Pause Cafe app

Notable changes to the standalone app. Dates are the day the change landed on
`main`. The current version is in `PAUSE_CAFE_VERSION` in `src/bootstrap.php`.

> **Pre-1.0, and never run in production.** The schedule, model and HTTP layers
> are covered by tests, but the app has not run on the production host and the
> Zeffy webhook has never seen a real payment. It stays below 1.0 until both
> have happened.

## Unreleased

### Changed

- **`Wallet::post()` opens an immediate transaction when it opens its own.** It
  reads the balance and then writes a row derived from it, which is the shape
  that goes wrong under concurrency, and PDO's `beginTransaction()` can only
  issue a plain `BEGIN` — which takes no write lock until the first write. The
  balance itself was never at risk, because it is always `SUM(delta_cents)` and
  WAL refuses a stale read-then-write outright rather than losing an update; the
  symptom was "database is locked" on a webhook that had done nothing wrong,
  which `busy_timeout` cannot help with because a stale snapshot is a conflict
  and not a wait. Taking the lock up front makes it the wait it should have
  been. Raised by a security audit as a financial invariant; landing as
  reliability, since no money could have gone astray.

### Security

- **Registration had no throttle at all**, unlike signing in. A script fetches
  the form for a CSRF token like anybody else, so nothing stopped it writing a
  row, emailing the organisers and leaving a name to decide about, over and
  over. Three limits now: per address, per source address (loose, because a
  welcome table all arrives from one router), and one for the whole site — the
  last being the only one an attacker cannot get around by varying something.
  Counted in their own table, so a burst of sign-ups cannot lock the
  congregation out of signing in. Reported by a security audit.

- **A forged `Host` header could send somebody's sign-in link to an attacker.**
  With `site_url` unset, every address the app builds came from the request's
  `Host` header — so asking for a sign-in link for another member's address
  while claiming to be your own host produced an email, in their inbox, carrying
  a working one-time token pointing at yours. There was a warning about this on
  the settings screen while the links went out regardless; a warning is not a
  control. Sign-in links and identity providers are now unavailable until
  `site_url` is set, refusing in the method as well as at the route. Passwords
  are unaffected, and ordinary emails — order confirmations and the like, which
  carry no token — still fall back to the request. Reported by a security audit.

- **Deleting a member destroyed the money as well as the account.**
  `wallet_entries.user_id` and `orders.user_id` both cascade from `users` with
  foreign keys on, so `DELETE FROM users` removed the person, every order they
  had placed, and the whole of their ledger — including money collected through
  Zeffy. The screen said so out loud: "Account deleted, along with its orders
  and ledger." Accounts are now closed instead, keeping everything they did, and
  can only be deleted outright where there is nothing to lose. Reported by a
  security audit.

- **An external provider could hand over an existing account on a confirmed
  address alone.** The first sign-in through a provider has no subject to match
  on, so only the address was left, and a match linked and signed in on the
  spot. A confirmed address says the provider believes somebody can read that
  mailbox today — not that they are whoever opened the account here, which is a
  real gap when addresses get reassigned, recycled, or issued by a tenant
  somebody else administers. Accounts holding money, carrying orders, or
  belonging to an organiser now hold the sign-in as a claim for an organiser to
  approve; accounts with nothing to take still link immediately. Reported by a
  security audit.

### Added

- **An order trash.** Move to trash takes an order out of the cook list, out of
  what is still to collect, and out of the portions it held, without destroying
  it or moving any money — cancelling is still what gives money back. From the
  trash it can be restored, coming back as whatever it was, or deleted for good.
- **Deleting an order for good**, which is not cancelling: cancelling says it
  happened and was undone, this says it never happened. Meant for orders put
  there while testing. The wallet entries go with the order rather than being
  left pointing at something nobody can open, whatever was charged returns to
  the balance, and the running total beside each remaining ledger entry is
  rewritten so the statement still adds up.
- **Accounts with no orders and no wallet history can still be deleted
  outright** — a spam registration, or something made while testing. Clearing an
  account's test orders from the trash makes it deletable again.
- **Connect a provider to your own account**, from Your account → How you sign
  in, without waiting for an organiser — and disconnect it again. What
  authorises the link is being signed in already, not the provider's word about
  an address, so the address there need not match the one here or even be
  confirmed. It refuses a provider account already linked to somebody else,
  needs a POST behind a CSRF token so nobody can walk a signed-in member through
  connecting *their* provider account, and stops rather than guessing if the
  session has changed by the time the provider sends them back. Disconnecting
  the last remaining way in is refused.

### Changed

- An organiser's own first external sign-in is held for approval like anyone
  else's, so proving the outside route before switching off the password rescue
  now takes the full path: sign in, be held, have the link approved, come back.

- **Cancelling an order could refund more than was ever charged.** `cancel()`
  gave back `total_cents` — what the food is worth — rather than what was still
  owed. The two are the same number until something has already been refunded,
  so a $20 order given a $5 goodwill refund and then cancelled paid out $25
  against a $20 charge. Cancellation now goes through `refundableCents()`, the
  same cap every other refund path uses, and records the refund in the order's
  history so `refundedCents()` counts it and the invariant still holds
  afterwards. Reported by a security audit; found by review, not in the wild.

## 0.14.0 — 2026-08-14

### Added

- **A side cart.** Adding a meal now opens a drawer listing the cart so far and
  leaves the menu where it was, so a parent ordering for three children adds a
  name, adds the next, and presses **Checkout** once — instead of being sent to
  the cart page and walking back for each one.
- **Name each separately**, on a line holding more than one meal. Two of a dish
  is usually two children, and a line can only carry one name; this breaks it
  into a line each, carrying the original's answers over. Offered in the drawer
  and on the cart page.
- **A schedule picker on the single-dish editor.** Dishes added there always
  landed on the default schedule, so the only way to give one its own timing was
  to change the schedule every other dish was on.
- **One-off dishes** — a box of chocolates, a Christmas menu, something needing
  a fortnight's notice. Tick **Show whenever it can be ordered**, set the from
  and until dates, and it appears in its own undated **Also available** section.
- The organiser sidebar is a **slide-out drawer** on a phone, with tap-sized
  rows, rather than folding to the top of every screen.

### Fixed

- Faking a one-off by giving it a midweek service date created a section for
  that day on the front page and hid the Sunday menu behind it. One-offs are now
  kept out of the weekly sections, the front page's list of dates, and the month
  builder's grid, while staying listed on the admin menu screen.
- `menu_items.open_from` and `close_at` kept whatever string they were handed.
  That was fine while the only caller was a `datetime-local` input, but a value
  a hair off that format — a space where the `T` should be — parsed as nothing
  and left the dish quietly unorderable with no error anywhere to explain why.
  Both are canonicalised on save and the parser accepts either separator.

### Notes

- **`cart.js` is the only JavaScript in the app, and nothing depends on it.**
  Every form it touches is an ordinary form; without the script, **Add** posts
  and redirects to the cart page exactly as before. The script hands anything
  unexpected — an expired token, a dropped connection — straight back to the
  browser, so the real reason is shown on the real page rather than guessed at
  in a drawer.
- The drawer is closed with `visibility: hidden`, not only a transform. An
  off-screen panel that is still visible is still in the tab order.
- Everything in one order still has to be for the same day, so a one-off whose
  window lands on another date is ordered separately from that Sunday's lunch.

## 0.13.0 — 2026-08-14

### Added

- **Orders can be edited instead of only cancelled**, the way WooCommerce lets
  you. From **Orders → Edit**: change a quantity up or down, remove a line, add
  another dish, correct a name or note, or refund an amount with a reason. The
  difference moves either way — wallet orders are credited or debited, cash
  orders simply owe more or less.
- **A per-order history of the money**, saying what changed, why, who did it and
  how much, alongside the running totals: what the food is worth, what has been
  taken, what has been given back, and what could still be refunded.
- Members are emailed when an edit changes what they owe, with the new lines,
  the old and new totals, and where the money went. Correcting a spelling sends
  nothing.

### Notes

- **`orders.total_cents` and `orders.charged_cents` mean different things.** The
  first is what the food is worth and moves as the lines are edited; the second
  is money in, only ever grows, and is what refunds are capped against. Without
  the second, an order edited down to nothing could be refunded repeatedly for
  money that was never collected.
- Repeated movements need repeated wallet references, and `charge()`/`refund()`
  have fixed ones — `order:12`, `refund:12` — behind a unique index that stops a
  redelivered Zeffy webhook crediting twice. Rather than loosen that, there is a
  new `Payments\Method::adjust()` whose reference carries the adjustment's own
  row id. **Anything implementing `Method` needs the new method.**
- Raising a quantity respects portion limits exactly as ordering does. Reducing
  one releases the portions, since capacity is counted live from the lines.
- Cash orders record the adjustment but write no ledger entry, consistent with
  how cancelling has always treated them: the system never held that money.
- A cancelled order is closed to further edits.
### Fixed

- **Orders could become unreachable when their dish was taken off the menu.**
  Deleting a dish that has already been sold drafts it rather than removing it,
  which is what keeps the orders pointing somewhere — but every date picker was
  built from *published* dishes only. So the same act that protected the orders
  also hid them: the serving date dropped off the Orders, Overview, Report and
  order-for-someone screens, and there was no way left to view, settle or cancel
  those orders, or to refund what had been paid.

  The organiser screens now offer the union of dates on the menu and dates with
  orders, and the Orders page names any dish that is no longer published rather
  than leaving you to wonder why it cannot be found.

### Added

- **Bulk actions on the Orders page.** Tick any number and mark them paid or
  unpaid, cancel them, download just those as CSV, or re-send the confirmation.
  Cancelling in bulk goes through the single-order path once per order, so the
  wallet refunds and the emails are exactly the same.
- **A status filter on Orders** — live, cancelled, or both. Cancelled orders
  were previously invisible everywhere, which is a poor way to treat a record of
  money being refunded.
- **The organiser menu can run down the side**, WordPress style, or stay across
  the top. It is a per-account choice: one organiser moving it does not move it
  for anybody else. On a narrow screen the sidebar folds back to the top.
- **Jump links on Settings**, which is long enough that reaching the blackout
  dates meant scrolling past everything else.

### Changed

- The organiser navigation is rendered by the layout rather than included at the
  top of each screen. The side arrangement has to sit *beside* the content, and
  a template included at the top of that content cannot do that. `_tabs.php` is
  gone; `AdminNav` owns the list and `admin/_nav.php` draws it.

### Notes

- Bulk actions only ever touch orders on the date being shown. An id posted for
  anything else is dropped rather than acted on.
- `Orders::retiredDishes()` matches on the name stored against the line, because
  that is what an organiser recognises and because a line whose dish row was
  hard-deleted has nothing else left to match on.
## 0.12.1 — 2026-08-14

### Fixed

- **Unpublishing a dish did not stick if you then edited that month's menu.**
  Saving the grid forces every named cell to published — deliberately, because
  typing a name back into a cell you cleared is how you put a dish back. But the
  builder was also putting an unpublished dish's name *into* the box, so the
  form resubmitted it on every save and the builder could not tell "somebody
  typed this" from "the form carried it". Editing any other week of the same
  month quietly put every unpublished dish back on the menu.

  An unpublished dish now shows as a placeholder — `Roast chicken
  (unpublished)` — with a matching pill, so it is still visible but is not
  submitted. Its cell arrives empty, the existing "already a draft, nothing to
  do" path leaves it alone, and typing the name in still republishes it.

### Notes

- The two halves are only correct together, so both are tested: a unit test that
  an empty cell leaves a draft alone, and an end-to-end assertion that the grid
  renders a placeholder rather than a value. `MenuBuilder::unchanged()` now
  carries a comment saying its status check is safe *only* because of how the
  grid renders — putting the name back in the box brings the bug straight back.

## 0.12.0 — 2026-08-14

Two security fixes from an audit of the whole app, plus one thing to set when
you deploy.

### Fixed

- **Passwords could be guessed at as fast as the server would answer.** Nothing
  counted failed sign-ins, so bcrypt's tenth of a second was the only cost —
  about ten guesses a second, indefinitely. The organiser rescue was the worst
  of it: it only ever admits an organiser, so every success there is an account
  that can move money.

  Five wrong guesses against one address now means a fifteen-minute wait, and
  forty from one machine does the same. Both expire on their own, signing in
  clears them, and `tools/rescue.php` wipes them — a lockout that needed a
  database editor to undo would undermine the whole point of that tool.

- **Links in email were built from the browser's `Host` header.** Ask for a
  sign-in link while claiming to be another host, and the link emailed to the
  account holder points there, carrying a working one-time token. Click it and
  somebody else has the session. The address now comes from `site_url` in
  `config.php` when set, and the Sign-in screen warns when emailed links are
  switched on without it.

- **`baseUrl()` ignored `'https' => true`**, so behind a TLS-terminating proxy
  every link the app emailed said `http://`. It now takes HTTPS from the config
  flag, `$_SERVER['HTTPS']`, or `X-Forwarded-Proto` — any of them can say yes,
  none can say no.

### To do when you deploy

Set **`site_url`** in `config.php` to the site's own address, and **`https`** to
true once you are behind TLS. The first protects emailed sign-in tokens; the
second also marks the session cookie secure.

### Notes

- Throttling counts the address as typed, whether or not it has an account.
  Counting only real ones would turn the wait into an answer to "is this person
  a member here?"
- The per-machine limit is deliberately loose. On shared hosting every visitor
  can arrive from one proxy address, and a tight limit there would lock out the
  congregation because one person mistyped.
- `X-Forwarded-For` is ignored for throttling. A caller sets it, so an attacker
  could put a fresh value on every request — a per-machine limit built on it
  would be no limit at all, while looking like one.

### Audited and found clean

For the record, since these were checked rather than assumed: all 70 routes
(every `/admin` route guarded, every POST carrying CSRF except the Zeffy
webhook, which uses a shared secret instead); no user input reaching a SQL
string; the three places that build HTML in PHP all escaping; `/orders/{id}`
checking ownership and answering 404 rather than 403; and every secret
comparison either constant-time or bcrypt.

## 0.11.1 — 2026-08-14

### Fixed

- **A note typed against a meal on the cart page was silently thrown away.**
  The cart was a form per line, each with its own Update button, and the
  checkout button lived in a form of its own — so "no onions" only survived if
  the shopper happened to press that line's Update before checking out, which
  nobody does. The note reached nothing: not the kitchen list, not the CSV, not
  the confirmation email.

  It looked intermittent because there were two ways in. A note typed on the
  **menu card** travelled with the add-to-cart request and always worked; the
  same note typed on the **cart page** was lost. Same field, same wording, and
  whether it survived depended on which screen it was typed on.

  The cart is now one form. Update, Remove and Place order all carry every
  line's answers, so nothing depends on pressing a particular button first.

### Changed

- **The two kinds of note are now labelled.** The kitchen list tags each one
  "This meal" or "Whole order" rather than distinguishing them by being slightly
  greyer, which is no distinction at all on a printout. The tag keeps an outline
  when printed, since the colour will not survive a monochrome printer.
- **The CSV gives them a column each** — "Meal note" and "Order note" — instead
  of joining them with a slash into one cell that could be neither sorted nor
  filtered.
- The order-note box on the cart says what it is for, and points at the per-dish
  box for anything about a single meal.

### Notes

- `views/partials/order-fields.php` takes an optional `group`, so one form can
  carry several sets of answers as `line[0][note]`, `line[1][note]` and so on.
  That is what lets the whole cart submit at once.
- Quantity of zero no longer empties a line. Removing is the Remove button's
  job; a zero in the quantity box is a typo, and clearing somebody's order
  because they mistyped is a poor trade.

## 0.11.0 — 2026-08-14

### Added

- **A Design screen.** Colours, type, corner rounding, card style, site name,
  logo and dark mode, for the whole site at once — including the organiser
  screens, which pick it up without having been touched. Three starting points:
  Plain, Bold and Warm paper.
- **Themes.** A folder under `themes/` with a manifest, an optional stylesheet
  and optional templates. Anything a theme does not provide falls through to the
  built-in version. `themes/list/` ships as a working example, turning the card
  grid into one dish per row.
- **Pictures on dishes**, optional, with the card falling back to a typographic
  layout when there is none. Uploads are re-encoded and scaled, so a photo
  straight off a phone is fine.
- **A new default look**, the Bold one: tinted cards with no outline, rounded
  corners and a green accent. The old look is one click away under Plain.
- **The front page says whether ordering is open**, once above the week rather
  than implied by each card. Sold out and closed are pills now, not warning
  boxes.

### Changed

- `views/partials/dish-card.php` is new — one dish, lifted out of `menu.php`.
  This is the piece a theme is meant to override, and extracting it means a
  theme can change how a dish looks without copying the ordering rules, the
  field resolution or the cutoff handling.
- Templates locate their partials through `View::locate()` rather than
  `__DIR__`, which is what lets an overridden template still find the built-in
  pieces around it.
- The site name is a Design setting. It used to be the mail-from name, which is
  a strange place for it; that is still the fallback, so nothing changes for an
  install that set it there.

### Notes

- **A theme's copy of a template stops tracking the original.** The standard
  child-theme trade-off, and the reason the shipped example is CSS only.
- `themes/` is not web-reachable and must not be: it is PHP with full access to
  the app. Its stylesheet is served by a route that reads one known filename,
  and the stored theme name is matched against the folders that exist rather
  than used to build a path.
- Design values are validated before they reach the page. A colour box lands
  inside a `<style>` block, where a stray brace would let the rest of the value
  become arbitrary CSS, so anything that is not a hex colour is dropped.
- `Design::css()` emits only what differs from the default, which couples the
  defaults to the `:root` block in `app.css`. A test asserts the two agree —
  without it, a disagreement would silently show the stylesheet's value.
- Uploads go in `public/assets/uploads/`, now gitignored. They are content, and
  a deploy must not overwrite them.

## 0.10.3 — 2026-08-14

### Fixed

- **With every sign-in method switched off, members could still sign in with a
  password.** 0.10.2 made the fallback work; the trouble was the fallback
  itself. Putting passwords back on the public login page re-admitted the whole
  congregation to a site whose organisers had deliberately switched passwords
  off — the opposite of what was asked for.

  There is now no public fallback. With nothing switched on, the login page
  offers nothing and says so. What survives is the organiser rescue, which is
  checked against an organiser account and which a member cannot use.

### Notes

- The rescue is now **forced on whenever nothing else is available**, rather
  than only being defended when the setting is saved. That holds even if a
  provider is switched off, or stops being configured, long afterwards.
- The guarantee is deliberately about organisers, not everybody. If the
  organisers switch everything off, members cannot sign in, and that is the
  correct outcome.

## 0.10.2 — 2026-08-14

### Fixed

- **The fallback that puts passwords back drew a form that then refused to
  work.** With every sign-in method switched off, the login page correctly
  offered a password box — and submitting it produced "Something went wrong",
  because the login route checked the enabled setting rather than what the page
  had actually offered. The two disagreed, and the fallback lost.

  This was the worst possible place for it: the fallback exists for people who
  are locked out, and it failed precisely for them. `SignIn::resolve()` now gates
  on the same list the login page renders from, so anything shown can be used.

### Notes

- `tools/rescue.php` was unaffected and remained a way back in throughout — it
  sets the settings directly rather than going through the login route.

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
