#!/usr/bin/env bash
#
# End-to-end run against a live server: sessions, CSRF, approval gating, the
# wallet, checkout, portion limits, the kitchen report and access control.
#
# Start a server on a throwaway database first:
#
#   rm -f data/pause-cafe.sqlite*
#   php -d extension=php_pdo_sqlite -S 127.0.0.1:8321 -t public router.php &
#   bash tests/e2e.sh
#
# It expects an empty database -- it drives first-run setup itself.

set -u

BASE=${BASE:-http://127.0.0.1:8321}
A=$(mktemp); M=$(mktemp)   # cookie jars: organiser, member
pass=0; fail=0

ok()   { pass=$((pass+1)); echo "  ok    $1"; }
bad()  { fail=$((fail+1)); echo "  FAIL  $1"; echo "        $2"; }
want() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected '$3', got '$2'"; fi; }
has()  { if echo "$2" | grep -q "$3"; then ok "$1"; else bad "$1" "missing: $3"; fi; }
hasnt(){ if echo "$2" | grep -q "$3"; then bad "$1" "unexpectedly present: $3"; else ok "$1"; fi; }

# Grabs a CSRF token from any page. Every form on a page shares one token.
tok()  { curl -s -b "$1" -c "$1" "$BASE$2" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }

# Collapses newlines and runs of whitespace, so an assertion about two adjacent
# HTML attributes does not depend on where the template happens to wrap.
# \r is in the set deliberately: on Windows the templates are checked out with
# CRLF, so the rendered HTML carries carriage returns that are invisible in a
# terminal and would otherwise sit between the attributes.
flat() { printf '%s' "$1" | tr '\r\n\t' '   ' | tr -s ' '; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$1" -c "$1" "$BASE$2"; }

echo ""
echo "First run"
want "setup page is reachable" "$(code $A /setup)" "200"

T=$(tok $A /setup)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/setup" \
  -d "_token=$T" -d "name=Ada Organiser" -d "email=ada@example.org" -d "password=correct-horse"
want "organiser lands in the admin area" "$(code $A /admin)" "200"
want "setup redirects once an admin exists" "$(code $A /setup)" "302"

echo ""
echo "CSRF"
BADPOST=$(curl -s -o /dev/null -w '%{http_code}' -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=obviously-wrong" -d "name=Sneaky" -d "location_id=1")
want "a bad token is rejected" "$BADPOST" "419"

echo ""
echo "Email settings"
# Switched to the log transport before anything that sends, so the run never
# reaches for a real mail server and every message can be read back.
T=$(tok $A /admin/settings)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/mail" \
  -d "_token=$T" -d "mail_enabled=1" -d "mail_transport=log" \
  -d "mail_from_name=Pause Cafe" -d "mail_from_email=lunch@example.org"

SETTINGS=$(curl -s -b $A -c $A "$BASE/admin/settings")
has "every transport is offered" "$SETTINGS" 'value="resend"'
has "including SMTP" "$SETTINGS" 'value="smtp"'
has "whose fields are rendered from the transport" "$SETTINGS" 'name="smtp_host"'
has "and the log transport is now selected" "$(flat "$SETTINGS")" 'value="log" checked'

T=$(tok $A /admin/settings)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/mail/test" -d "_token=$T"
has "a test message is written" "$(cat data/mail.log 2>/dev/null)" "test email"

echo ""
echo "Groups"
# Groups have to exist before anything can be assigned one -- with an empty list
# the field does not render and any submitted value is discarded.
T=$(tok $A /admin/settings)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/groups/add" -d "_token=$T" -d "name=Youth"
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/groups/add" -d "_token=$T" -d "name=Seniors"

SETTINGS=$(curl -s -b $A -c $A "$BASE/admin/settings")
has "a group appears in settings" "$SETTINGS" 'value="Youth"'
has "and so does the second" "$SETTINGS" 'value="Seniors"'

DUP=$(tok $A /admin/settings)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/groups/add" -d "_token=$DUP" -d "name=youth"
COUNT=$(curl -s -b $A -c $A "$BASE/admin/settings" | grep -c 'name="name" value="' || true)
want "a case-variant duplicate is refused" "$COUNT" "2"

echo ""
echo "Menu"
# An explicit from/until override keeps this test independent of the real clock.
T=$(tok $A /admin/menu/new)
SAVE=$(curl -s -o /dev/null -w '%{http_code}' -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=$T" -d "name=BBQ pork on rice" -d "location_id=1" -d "price=10.00" \
  -d "capacity=2" -d "status=published" -d "service_date=2026-08-16" \
  -d "open_from=2026-01-01T00:00" -d "close_at=2027-01-01T00:00")
want "saving a dish redirects" "$SAVE" "302"

HOME_HTML=$(curl -s "$BASE/")
has "the dish shows on the public menu" "$HOME_HTML" "BBQ pork on rice"
has "with its price" "$HOME_HTML" '\$10\.00'
has "under its pickup location" "$HOME_HTML" "Marine"
has "signed-out visitors are asked to sign in" "$HOME_HTML" "Sign in to order"

echo ""
echo "Registration and approval"
T=$(tok $M /register)
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/register" \
  -d "_token=$T" -d "name=Sam Member" -d "group_name=Youth" \
  -d "email=sam@example.org" -d "password=battery-staple"

T=$(tok $M /login)
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/login" \
  -d "_token=$T" -d "email=sam@example.org" -d "password=battery-staple"

MENU=$(curl -s -b $M -c $M "$BASE/")
has "an unapproved member is told they are waiting" "$MENU" "waiting for an organiser"
hasnt "and gets no add-to-cart form" "$MENU" "Add to cart"

MEMBER_ID=$(curl -s -b $A -c $A "$BASE/admin/users" | grep -o '/admin/users/[0-9]*/wallet' | head -1 | grep -o '[0-9]\+')
T=$(tok $A /admin/users)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/users/$MEMBER_ID" \
  -d "_token=$T" -d "name=Sam Member" -d "group_name=Youth" -d "role=member" -d "is_approved=1"

MENU=$(curl -s -b $M -c $M "$BASE/")
has "an approved member gets the order form" "$MENU" "Add to cart"
has "the name field is prefilled" "$MENU" 'value="Sam Member"'
has "the group is a dropdown" "$MENU" '<select id="group-'
has "with their group preselected" "$MENU" '<option value="Youth" selected'
has "and the other group offered" "$MENU" '<option value="Seniors"'
hasnt "and no free-text group box anywhere" "$MENU" 'type="text" name="group_name"'

# The dropdown is only a convenience on the form; the server has to reject a
# value that was never on the list.
T=$(tok $A /admin/users)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/users/$MEMBER_ID" \
  -d "_token=$T" -d "name=Sam Member" -d "group_name=Totally Invented" -d "role=member" -d "is_approved=1"
hasnt "a forged group is discarded" "$(curl -s -b $A -c $A "$BASE/admin/users")" "Totally Invented"

T=$(tok $A /admin/users)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/users/$MEMBER_ID" \
  -d "_token=$T" -d "name=Sam Member" -d "group_name=Youth" -d "role=member" -d "is_approved=1"

echo ""
echo "Wallet top-up by an organiser"
T=$(tok $A /admin/users)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/users/$MEMBER_ID/wallet" \
  -d "_token=$T" -d "direction=credit" -d "amount=25.00" -d "note=Cash at the desk"
has "the balance appears on their account" "$(curl -s -b $M -c $M "$BASE/account")" '\$25\.00'

echo ""
echo "Ordering"
ITEM=$(curl -s -b $M -c $M "$BASE/" | grep -o 'name="item_id" value="[0-9]*"' | head -1 | grep -o '[0-9]\+')
T=$(tok $M /)
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/cart/add" \
  -d "_token=$T" -d "item_id=$ITEM" -d "qty=2" -d "person_name=Sam" -d "group_name=Youth" \
  -d "note=no onions"

CART=$(curl -s -b $M -c $M "$BASE/cart")
has "the cart shows the dish" "$CART" "BBQ pork on rice"
has "with the line total" "$CART" '\$20\.00'
has "and keeps the note" "$CART" 'value="no onions"'

T=$(echo "$CART" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
# Asserting the status matters: a fatal here returns 500 and every later
# assertion just sees an empty database rather than the real cause.
CO=$(curl -s -o /dev/null -w '%{http_code}' -b $M -c $M -X POST "$BASE/checkout" \
  -d "_token=$T" -d "order_note=Side door please")
want "checkout redirects rather than erroring" "$CO" "302"

ACCOUNT=$(curl -s -b $M -c $M "$BASE/account")
has "the balance is debited to \$5" "$ACCOUNT" '\$5\.00'
has "the order is listed" "$ACCOUNT" "Order"

echo ""
echo "Capacity and the cook list"
has "the dish reads sold out once its portions are gone" "$(curl -s -b $M -c $M "$BASE/")" "Sold out"

REPORT=$(curl -s -b $A -c $A "$BASE/admin/report?date=2026-08-16")
has "the kitchen report lists the dish" "$REPORT" "BBQ pork on rice"
has "with the eater and group" "$REPORT" "Sam (Youth)"
has "and a total" "$REPORT" "2 meals in total"

# fputcsv quotes any field containing a space, so the header is partly quoted.
CSV=$(curl -s -b $A -c $A "$BASE/admin/report/export?date=2026-08-16")
has "the CSV export has a header row" "$CSV" '"Service date",Location,Dish'
hasnt "and carries no PHP notices" "$CSV" "Deprecated"
has "with the order line" "$CSV" "Sam Member"

echo ""
echo "Payment methods"
has "settings offers a toggle per method" "$(curl -s -b $A -c $A "$BASE/admin/settings")" 'name="payment\[cod\]"'
has "and one for the wallet" "$(curl -s -b $A -c $A "$BASE/admin/settings")" 'name="payment\[wallet\]"'

# A second dish, unlimited, so the cash order is not blocked by the sold-out one.
T=$(tok $A /admin/menu/new)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=$T" -d "name=Pay later pasta" -d "location_id=2" -d "price=12.00" \
  -d "capacity=0" -d "status=published" -d "service_date=2026-08-16" \
  -d "open_from=2026-01-01T00:00" -d "close_at=2027-01-01T00:00"

ITEM2=$(curl -s -b $M -c $M "$BASE/" | grep -B2 'Pay later pasta' -A40 | grep -o 'name="item_id" value="[0-9]*"' | head -1 | grep -o '[0-9]\+')
T=$(tok $M /)
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/cart/add" \
  -d "_token=$T" -d "item_id=$ITEM2" -d "qty=1" -d "person_name=Sam" -d "group_name=Seniors"

CART=$(curl -s -b $M -c $M "$BASE/cart")
has "the cart asks how to pay" "$CART" "How would you like to pay"
has "offering the wallet" "$CART" "Wallet balance"
has "and cash on pickup" "$CART" "Pay on pickup"

T=$(echo "$CART" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
CO=$(curl -s -o /dev/null -w '%{http_code}' -b $M -c $M -X POST "$BASE/checkout" \
  -d "_token=$T" -d "payment_method=cod")
want "the cash checkout redirects too" "$CO" "302"

ACCOUNT=$(curl -s -b $M -c $M "$BASE/account")
has "a cash order is flagged as still to pay" "$ACCOUNT" "To pay"
has "and the balance is untouched at \$5" "$ACCOUNT" '\$5\.00'

ORDERS=$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-08-16")
has "the organiser sees it as owing" "$ORDERS" "Owing"
has "with a total left to collect" "$ORDERS" '\$12\.00 still to collect'

CODID=$(echo "$ORDERS" | grep -o '/admin/orders/[0-9]*/paid' | tail -1 | grep -o '[0-9]\+')
T=$(tok $A /admin/orders)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/orders/$CODID/paid" -d "_token=$T" -d "state=paid"

ORDERS=$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-08-16")
has "marking it paid shows up" "$ORDERS" "Paid"
hasnt "and the collect banner is gone" "$ORDERS" "still to collect"

echo ""
echo "Kitchen list"
K=$(mktemp)   # a third jar: nobody signed in

LOCKED=$(curl -s -b $K -c $K "$BASE/kitchen")
has "with no password set it is organisers only" "$LOCKED" "for organisers only"
hasnt "and shows no orders" "$LOCKED" "BBQ pork on rice"

KITCHEN=$(curl -s -b $A -c $A "$BASE/kitchen?range=all")
has "an organiser sees the table" "$KITCHEN" "BBQ pork on rice"
has "with the line note" "$KITCHEN" "no onions"
has "and the order note" "$KITCHEN" "Side door please"
has "the name on the meal" "$KITCHEN" "Sam"
has "the group" "$KITCHEN" "Youth"
has "a to-cook summary" "$KITCHEN" "To cook"
has "and sortable headings" "$KITCHEN" 'href="/kitchen?range=all&amp;sort=dish'

# Dish names are matched inside <strong>, which only the table renders. The
# filter dropdown lists every dish whatever the current filter, so a plain
# substring check would find it there and pass for the wrong reason.
has "filtering by location narrows it" \
  "$(curl -s -b $A -c $A "$BASE/kitchen?range=all&location=RCC")" '<strong>Pay later pasta</strong>'
hasnt "excluding the other campus" \
  "$(curl -s -b $A -c $A "$BASE/kitchen?range=all&location=RCC")" '<strong>BBQ pork on rice</strong>'

has "filtering by group works" \
  "$(curl -s -b $A -c $A "$BASE/kitchen?range=all&group=Seniors")" "Pay later pasta"

CSV=$(curl -s -b $A -c $A "$BASE/kitchen/export?range=all")
has "the CSV carries the columns asked for" "$CSV" "Date,Location,Dish,Qty,Name,Group,Payment,Paid,Notes"
has "and the note" "$CSV" "no onions"
hasnt "with no PHP notices" "$CSV" "Deprecated"

T=$(tok $A /admin/settings)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/kitchen-password" -d "_token=$T" -d "password=kitchen-door"

LOCKED=$(curl -s -b $K -c $K "$BASE/kitchen")
has "once set, visitors get a password prompt" "$LOCKED" "Enter the shared password"
hasnt "and still no orders" "$LOCKED" "BBQ pork on rice"

T=$(echo "$LOCKED" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -b $K -c $K -X POST "$BASE/kitchen/unlock" -d "_token=$T" -d "password=wrong-one"
hasnt "a wrong password does not get in" "$(curl -s -b $K -c $K "$BASE/kitchen")" "BBQ pork on rice"

T=$(curl -s -b $K -c $K "$BASE/kitchen" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -b $K -c $K -X POST "$BASE/kitchen/unlock" -d "_token=$T" -d "password=kitchen-door"
has "the right one does" "$(curl -s -b $K -c $K "$BASE/kitchen?range=all")" "BBQ pork on rice"

rm -f "$K"

echo ""
echo "Email actually sent"
MAILLOG=$(cat data/mail.log 2>/dev/null)
has "organisers were told about the sign-up" "$MAILLOG" "New sign-up: Sam Member"
has "sent to the organiser" "$MAILLOG" "ada@example.org"
has "the member was told once approved" "$MAILLOG" "account is ready"
has "and got an order confirmation" "$MAILLOG" "Order confirmed"
has "naming the dish" "$MAILLOG" "BBQ pork on rice"
has "and the note on the meal" "$MAILLOG" "no onions"
has "from the configured address" "$MAILLOG" "lunch@example.org"

echo ""
echo "Access control"
want "a member cannot reach the admin area" "$(code $M /admin)" "403"
want "a signed-out visitor is redirected from the cart" "$(code /dev/null /cart)" "302"

echo ""
echo "$pass passed, $fail failed"
rm -f "$A" "$M"
[ "$fail" -eq 0 ] || exit 1
