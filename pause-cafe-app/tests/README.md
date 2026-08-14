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
php -d extension=php_pdo_sqlite tests/test-app.php        # 332 assertions
php -d extension=php_pdo_sqlite tests/test-signin.php     # 96 assertions
php -d extension=php_pdo_sqlite tests/test-design.php     # 67 assertions
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

And email: header-injection stripping, subject encoding, MIME shape, SMTP reply
parsing and dot-stuffing, and the fallback to mail() when the chosen transport
fails — driven by a stub transport that always does. The log transport is
selected throughout, so a run never reaches for a mail server.

The grid builder is covered in all three modes: dates already served left
untouched, a cleared cell drafted rather than deleted, a repeat inheriting its
price, and saving an unchanged grid reporting no changes.

Correcting a dish is covered end to end: both customers mailed, the rename
reaching confirmed order lines while the price does not, a second and third
correction each notifying again, and portion limits and drafting staying
silent.

Cancellation gets all three of its outcomes checked — refunded to the wallet,
paid in cash, never charged — including that the two non-wallet cases write no
ledger entry and the email does not claim one.

**`test-signin.php`** — the ways in. Its first third signs real tokens with a
real RSA key and puts them through the same verifier the live path uses, so the
crypto is exercised rather than assumed: a good token passes, and one that has
been edited, signed with the wrong key, left unsigned, switched to HMAC over the
provider's public key, issued for another application, issued by another issuer,
replayed with an old nonce, expired, or signed with a key that was rotated away
is refused. Key rotation is covered both ways — either published key verifies
its own token, neither verifies the other's.

The rest is what this site does with the answer. One-time links: single use,
expiry either side of the minute, a burst limit per account, and signing out
killing anything still sitting in an inbox. Identity linking: the approval gate
surviving an external sign-in, an unconfirmed address matching nothing, the link
being keyed on the provider's subject rather than the address — so somebody who
later inherits an address at the provider gets their own account and not the
first person's — passwordless accounts refusing every password, and deleting
somebody taking their links and tokens with them. Finally the register: the
fallback that puts passwords back when everything else is off or misconfigured,
and the organiser rescue — including that it cannot be given up until an
organiser has actually signed in through a provider, that a configured provider
does not count, that a *member* signing in that way does not count either, and
that deleting the organiser who proved it withdraws the proof.

`fixtures-keys.php` holds three throwaway RSA keys. They are fixed rather than
generated because `openssl_pkey_new()` needs an `openssl.cnf` that many PHP
builds cannot find — including the one this was written on — and a test that
only runs on a tidy OpenSSL installation is a test that stops running. Signing
and verifying need no config. The keys are public and protect nothing.

**`test-design.php`** — how the site looks. That `Design::css()` emits nothing
at all until something is changed, and then exactly one line per change; that a
colour box cannot be used to write CSS, since its value lands inside a `<style>`
block where a stray brace would turn the rest into arbitrary rules; that numbers
are clamped and unknown fonts refused; and that the site name has newlines
stripped, because it reaches an email header.

One assertion in there is worth knowing about: it reads `app.css` and checks
every token default matches the `:root` value. The two have to agree — a
default that disagrees is suppressed by `css()` and the page quietly shows the
stylesheet's value instead, with nothing on screen to explain why the organiser's
choice did not take. That is a coupling with no other way of noticing.

Themes are exercised against a throwaway theme built in the temp directory, so
the tests still mean something if the shipped one changes: a directory without a
manifest is not a theme; the stored slug is matched against what exists rather
than pasted into a path (`../../etc`, `/etc/passwd`, `tester/../..` and a
capitalised name all resolve to no theme); a theme deleted while selected falls
back to core instead of fataling; and a template the theme provides comes from
the theme while one it does not comes from core.

Pictures cover the checks that run before `is_uploaded_file()`, which cannot be
true outside a real request — a script wearing a `.jpg` is refused, as is a path
that was never uploaded — plus that deleting one only ever touches files in the
uploads directory whose names match what was written there. The happy path is
covered over HTTP in `e2e.sh`.

## With a server

```bash
rm -f data/pause-cafe.sqlite*
php -d extension=php_pdo_sqlite -S 127.0.0.1:8321 -t public router.php &
bash tests/e2e.sh                                          # 201 assertions
```

**`e2e.sh`** drives real HTTP with cookie jars: first-run setup, a bad CSRF token
getting 419, creating groups, registration, the approval gate, the group field
rendering as a dropdown with no text box anywhere, a forged group being
discarded, a wallet top-up, add-to-cart, checkout debiting the balance, a dish
selling out at its portion limit, the kitchen report, the CSV export, a cash
order placed and later marked paid by an organiser, and a member getting 403
from the admin area.

It also drives the sign-in methods over real HTTP: the login page rendering
whatever is switched on, an emailed link arriving in `data/mail.log` and signing
that person in exactly once, an unknown address getting the identical answer and
no email, and — with passwords switched off for members — an organiser still
getting in through the rescue while a member cannot.

The lockout is driven end to end: saving passwords off, an unproven provider on,
and the rescue off must leave the organiser route standing and working, while
still saving the rest of the form. That combination was a real lockout before
0.10.1, and the assertion exists because reading the code was not enough to
catch it — reproducing it was.

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
- **Flatten HTML before matching two adjacent attributes.** `flat()` exists
  because templates wrap, and because on Windows they are checked out with CRLF
  — so the rendered page carries carriage returns that are invisible in a
  terminal and sit right where you expected a space.

## Not covered

Concurrency, load, and any real Zeffy account. The webhook is tested against
synthetic payloads whose shape is an assumption — see the note in the main
README.

**No real identity provider.** The token verification is tested properly,
because that can be done offline. Everything either side of it — the redirect
Auth0 actually sends, the shape of a Supabase token response, whether a callback
URL was allowed — has only ever been exercised against what the specifications
say, never against an account. Expect the first live attempt to fail on
something small, most likely the callback URL.
