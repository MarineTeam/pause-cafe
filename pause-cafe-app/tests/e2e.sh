#!/usr/bin/env bash
#
# End-to-end run against a live server: sessions, CSRF, approval gating, the
# wallet, checkout, portion limits, the kitchen report and access control.
#
# Start a server on a throwaway database first:
#
#   cp config.example.php config.php     # then set site_url in it
#   rm -f data/pause-cafe.sqlite*
#   php -d extension=php_pdo_sqlite -S 127.0.0.1:8321 -t public router.php &
#   bash tests/e2e.sh
#
# It expects an empty database -- it drives first-run setup itself.
#
# config.php must set site_url to the address above. Sign-in links and identity
# providers refuse to run without one, because the address in an emailed link
# would otherwise come from the request's Host header -- so with it unset the
# sign-in-link section here fails, correctly.

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
has "signed-out visitors are asked to sign in" "$HOME_HTML" 'class="button" href="/login"'

echo ""
echo "Grid builder"
BUILDER=$(curl -s -b $A -c $A "$BASE/admin/menu/builder?month=2026-08")
has "the builder renders a month" "$BUILDER" "August 2026"
has "with a column per pickup location" "$BUILDER" 'dish\[2026-08-23\]\[1\]'
has "an autocomplete list of past dishes" "$BUILDER" '<datalist id="dish-names">'
has "including one already entered" "$BUILDER" 'value="BBQ pork on rice"'
has "and the menu list links to it" "$(curl -s -b $A -c $A "$BASE/admin/menu")" 'href="/admin/menu/builder"'

T=$(tok $A "/admin/menu/builder?month=2026-08")
SAVE=$(curl -s -o /dev/null -w '%{http_code}' -b $A -c $A -X POST "$BASE/admin/menu/builder" \
  -d "_token=$T" -d "month=2026-08" \
  -d "dish[2026-08-23][1]=Lentil shepherd pie" \
  -d "dish[2026-08-23][2]=Chicken katsu" \
  -d "dish[2026-08-30][1]=Lentil shepherd pie")
want "saving the grid redirects" "$SAVE" "302"

BUILDER=$(curl -s -b $A -c $A "$BASE/admin/menu/builder?month=2026-08")
has "the new dish is in its cell" "$BUILDER" 'value="Lentil shepherd pie"'
has "and so is the other campus" "$BUILDER" 'value="Chicken katsu"'
has "the menu list shows them too" "$(curl -s -b $A -c $A "$BASE/admin/menu")" "Chicken katsu"

# Clearing a cell drafts rather than deletes, so history survives.
T=$(tok $A "/admin/menu/builder?month=2026-08")
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/builder" \
  -d "_token=$T" -d "month=2026-08" \
  -d "dish[2026-08-23][1]=Lentil shepherd pie" \
  -d "dish[2026-08-23][2]=" \
  -d "dish[2026-08-30][1]=Lentil shepherd pie"

has "a cleared dish becomes a draft" "$(curl -s -b $A -c $A "$BASE/admin/menu")" "Draft"

# An unpublished dish must not be submitted back by the grid. Saving the month
# forces every named cell to published, so if the builder put the name in the
# box, editing any other week would quietly put it back on the menu.
BUILDER=$(curl -s -b $A -c $A "$BASE/admin/menu/builder?month=2026-08")
hasnt "an unpublished dish is not in its box" "$(flat "$BUILDER")" 'name="dish\[2026-08-23\]\[2\]" value="Chicken katsu"'
has "it is shown as a placeholder instead" "$(flat "$BUILDER")" 'Chicken katsu (unpublished)'
has "and marked as such" "$BUILDER" "Unpublished"

# Save the month again, editing a different week entirely.
T=$(tok $A "/admin/menu/builder?month=2026-08")
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/builder" \
  -d "_token=$T" -d "month=2026-08" \
  -d "dish[2026-08-23][1]=Lentil shepherd pie" \
  -d "dish[2026-08-23][2]=" \
  -d "dish[2026-08-30][1]=Lentil cottage pie"

hasnt "and it is still not on the public menu" "$(curl -s "$BASE/")" "Chicken katsu"
has "while the week that was edited did change" \
  "$(curl -s -b $A -c $A "$BASE/admin/menu")" "Lentil cottage pie"

echo ""
echo "Schedules"
SCHED=$(curl -s -b $A -c $A "$BASE/admin/schedules")
has "the default schedule is listed" "$SCHED" "Sunday lunch"
has "marked as the default" "$SCHED" "Default"
has "pointing at settings for its rules" "$SCHED" 'href="/admin/settings"'
has "and there is a form to add another" "$SCHED" "Add a schedule"

T=$(tok $A /admin/schedules)
NEW=$(curl -s -o /dev/null -w '%{http_code}' -b $A -c $A -X POST "$BASE/admin/schedules/save" \
  -d "_token=$T" -d "name=Wednesday supper" -d "mode=planned" \
  -d "service_weekday=3" -d "open_days_before=2" -d "open_time=08:00" \
  -d "close_days_before=1" -d "close_time=18:00" -d "close_weekday=6" \
  -d "service_days_after_close=1" -d "show_on_front=1" -d "locations[]=2")
want "creating one redirects" "$NEW" "302"

SCHED=$(curl -s -b $A -c $A "$BASE/admin/schedules")
has "the new schedule appears" "$SCHED" "Wednesday supper"
has "with a link to build its menu" "$SCHED" 'builder?schedule='

has "the builder now offers a schedule picker" \
  "$(curl -s -b $A -c $A "$BASE/admin/menu/builder")" 'name="schedule"'

echo ""
echo "Order fields"
FIELDS=$(curl -s -b $A -c $A "$BASE/admin/fields")
has "the built-ins are listed" "$FIELDS" "Name on this meal"
has "and marked as built in" "$FIELDS" "Built in"
has "with their storage key shown" "$FIELDS" "<code>person_name</code>"
has "and a form to add more" "$FIELDS" "Add a field"

# A built-in offers no remove control at all, so it cannot be deleted by
# clicking around.
BUILTIN_REMOVES=$(echo "$FIELDS" | grep -c 'Remove field' || true)
want "built-ins offer no remove button" "$BUILTIN_REMOVES" "0"

T=$(tok $A /admin/fields)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/fields/save" \
  -d "_token=$T" -d "label=Allergies" -d "type=text" -d "placeholder=e.g. nuts" -d "is_shown=1"

FIELDS=$(curl -s -b $A -c $A "$BASE/admin/fields")
has "saving reports success" "$FIELDS" "Field saved"
has "a new field appears" "$FIELDS" "Allergies"
has "keyed off its label" "$FIELDS" "<code>allergies</code>"
has "and this one can be removed" "$FIELDS" "Remove field"

has "the dish editor offers per-dish overrides" \
  "$(curl -s -b $A -c $A "$BASE/admin/menu/builder")" "Build menu"
has "the schedules screen offers per-schedule ones" \
  "$(curl -s -b $A -c $A "$BASE/admin/schedules")" 'name="rule\[allergies\]"'

echo ""
echo "Front page grid"
has "the dish grid carries the column count" "$(curl -s "$BASE/")" 'style="--cols: 3"'
has "and settings can change it" "$(curl -s -b $A -c $A "$BASE/admin/settings")" 'name="front_grid_columns"'

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
hasnt "and gets no add-to-cart form" "$MENU" 'action="/cart/add"'

MEMBER_ID=$(curl -s -b $A -c $A "$BASE/admin/users" | grep -o '/admin/users/[0-9]*/wallet' | head -1 | grep -o '[0-9]\+')
T=$(tok $A /admin/users)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/users/$MEMBER_ID" \
  -d "_token=$T" -d "name=Sam Member" -d "group_name=Youth" -d "role=member" -d "is_approved=1"

MENU=$(curl -s -b $M -c $M "$BASE/")
has "an approved member gets the order form" "$MENU" 'action="/cart/add"'
has "the name field is prefilled" "$MENU" 'value="Sam Member"'
# Ids are prefixed per form now that fields are rendered from configuration, so
# this matches the stable part: the id ends with the key, and the name is it.
has "the group is a dropdown" "$(flat "$MENU")" '_name" name="group_name"'
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
has "the custom field is on the order form" "$(curl -s -b $M -c $M "$BASE/")" 'name="allergies"'

curl -s -o /dev/null -b $M -c $M -X POST "$BASE/cart/add" \
  -d "_token=$T" -d "item_id=$ITEM" -d "qty=2" -d "person_name=Sam" -d "group_name=Youth" \
  -d "note=no onions" -d "allergies=Peanuts"

CART=$(curl -s -b $M -c $M "$BASE/cart")
has "the cart shows the dish" "$CART" "BBQ pork on rice"
has "with the line total" "$CART" '\$20\.00'
has "and keeps the note" "$CART" 'value="no onions"'

has "the line fields are nested under the line" "$CART" 'name="line\[0\]\[note\]"'
has "and the whole cart is one form" "$CART" 'action="/checkout"'

T=$(echo "$CART" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')

# The bug this guards: a note edited on the cart page and never separately
# saved. The cart is one form, so Place order carries every line's answers --
# it used to carry none of them, and whatever had been typed was dropped
# without a word. Note the changed value: it has to beat the one from add.
#
# Asserting the status matters too: a fatal here returns 500 and every later
# assertion just sees an empty database rather than the real cause.
CO=$(curl -s -o /dev/null -w '%{http_code}' -b $M -c $M -X POST "$BASE/checkout" \
  -d "_token=$T" -d "order_note=Side door please" \
  -d "line[0][qty]=2" \
  --data-urlencode "line[0][person_name]=Sam" \
  --data-urlencode "line[0][group_name]=Youth" \
  --data-urlencode "line[0][note]=no onions and extra sauce" \
  --data-urlencode "line[0][allergies]=Peanuts")
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
# Both notes, and labelled, so a cook can tell which is which on a printout.
has "with the meal note" "$KITCHEN" "no onions and extra sauce"
has "and the order note" "$KITCHEN" "Side door please"
has "the meal one is tagged" "$(flat "$KITCHEN")" 'note--meal"> <span class="note__tag">This meal</span>'
has "and the order one too" "$(flat "$KITCHEN")" 'note--order"> <span class="note__tag">Whole order</span>'
has "the name on the meal" "$KITCHEN" "Sam"
has "the group" "$KITCHEN" "Youth"
has "the custom field reaches the cook list" "$KITCHEN" "Allergies: Peanuts"
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
has "the CSV carries the columns asked for" "$CSV" "Date,Location,Dish,Qty,Name,Group,Payment,Paid"
# One column each, so a spreadsheet can tell a note about a meal from a note
# about the order -- and sort or filter on either. Quoted because fputcsv
# quotes any field containing a space.
has "with the two notes kept apart" "$CSV" '"Meal note","Order note"'
has "and the meal note as edited at checkout" "$CSV" "no onions and extra sauce"
has "alongside the order note" "$CSV" "Side door please"
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
echo "Correcting a dish somebody already ordered"
DISH_ID=$(curl -s -b $A -c $A "$BASE/admin/menu" | grep -o '/admin/menu/[0-9]*"><strong>BBQ pork on rice' | grep -o '[0-9]\+' | head -1)

# Every field is posted, not just the name: the editor writes what it is given,
# so omitting the override window would silently close the dish.
T=$(tok $A "/admin/menu/$DISH_ID")
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=$T" -d "id=$DISH_ID" -d "name=BBQ pork with broccoli on rice" \
  -d "location_id=1" -d "price=10.00" -d "capacity=2" -d "status=published" \
  -d "service_date=2026-08-16" -d "open_from=2026-01-01T00:00" -d "close_at=2027-01-01T00:00"

EDITED=$(curl -s -b $A -c $A "$BASE/admin/menu/$DISH_ID")
has "the organiser is told somebody was emailed" "$EDITED" "already ordered this"

CHANGEMAIL=$(cat data/mail.log 2>/dev/null)
has "the customer gets the notice" "$CHANGEMAIL" "A dish you ordered has changed"
has "naming what it was" "$CHANGEMAIL" "was: BBQ pork on rice"
has "and what it is now" "$CHANGEMAIL" "now: BBQ pork with broccoli on rice"
has "sent to whoever ordered it" "$CHANGEMAIL" "sam@example.org"

# The kitchen must not end up with two names for one pot.
KITCHEN=$(curl -s -b $A -c $A "$BASE/kitchen?range=all")
has "the cook list carries the correction" "$KITCHEN" "BBQ pork with broccoli on rice"
hasnt "and drops the old name" "$(flat "$KITCHEN")" "<strong>BBQ pork on rice</strong>"

# Unticking the box suppresses the email but must not suppress the rename. The
# log is counted rather than cleared, since later checks read earlier messages.
NOTICES_BEFORE=$(grep -c "A dish you ordered has changed" data/mail.log 2>/dev/null || true)

T=$(tok $A "/admin/menu/$DISH_ID")
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=$T" -d "id=$DISH_ID" -d "name=BBQ pork and broccoli" \
  -d "location_id=1" -d "price=10.00" -d "capacity=2" -d "status=published" \
  -d "service_date=2026-08-16" -d "open_from=2026-01-01T00:00" -d "close_at=2027-01-01T00:00" \
  -d "notify_present=1"

QUIET=$(curl -s -b $A -c $A "$BASE/admin/menu/$DISH_ID")
has "unticking the box says so" "$QUIET" "not emailed, as you asked"

NOTICES_AFTER=$(grep -c "A dish you ordered has changed" data/mail.log 2>/dev/null || true)
want "and sends nothing" "$NOTICES_AFTER" "$NOTICES_BEFORE"
has "while the cook list still follows the rename" \
  "$(curl -s -b $A -c $A "$BASE/kitchen?range=all")" "BBQ pork and broccoli"
has "and the editor offers the box when orders exist" "$QUIET" 'name="notify_orders"'

echo ""
echo "Cancelling an order"
# The first order on this date is the wallet one, so this exercises the refund
# path rather than the cash one.
ORDERS=$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-08-16")
WALLET_ORDER=$(echo "$ORDERS" | grep -o '/admin/orders/[0-9]*/cancel' | head -1 | grep -o '[0-9]\+')

T=$(tok $A /admin/orders)
CANCEL=$(curl -s -o /dev/null -w '%{http_code}' -b $A -c $A \
  -X POST "$BASE/admin/orders/$WALLET_ORDER/cancel" -d "_token=$T")
want "cancelling redirects" "$CANCEL" "302"

ACCOUNT=$(curl -s -b $M -c $M "$BASE/account")
has "the order now reads cancelled" "$ACCOUNT" "Cancelled"
has "and a refund appears in their wallet history" "$ACCOUNT" "Refund"

ORDERPAGE=$(curl -s -b $M -c $M "$BASE/orders/$WALLET_ORDER")
has "the order page says the wallet was refunded" "$(flat "$ORDERPAGE")" "went back into the wallet"
has "naming the amount" "$ORDERPAGE" '\$20\.00'

CANCELMAIL=$(cat data/mail.log 2>/dev/null)
has "they were emailed about it" "$CANCELMAIL" "Order cancelled for"
has "telling them the money is back" "$CANCELMAIL" "has gone back into your wallet"
has "and what the balance is now" "$CANCELMAIL" "Your balance is now"

echo ""
echo "Front page layout"
# The regression this guards: a grid per pickup location meant every card sat
# alone in column one and nothing was ever side by side.
HOME_HTML=$(curl -s "$BASE/")
GRIDS=$(echo "$HOME_HTML" | grep -c '<ul class="dishes"')
want "the whole week is a single grid" "$GRIDS" "1"
has "holding the Marine dish" "$(flat "$HOME_HTML")" 'dish__where"><span class="pill pill--past">Marine'
has "and the RCC one" "$(flat "$HOME_HTML")" 'dish__where"><span class="pill pill--past">RCC'
has "with the column count on it" "$HOME_HTML" 'style="--cols: 3"'

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
echo "Overview date picker"
OVERVIEW=$(curl -s -b $A -c $A "$BASE/admin")
has "the overview offers a date" "$OVERVIEW" 'name="date"'
has "defaulting to the next serving" "$OVERVIEW" "Cook list for Sunday 16 August"
has "which is marked as such" "$OVERVIEW" "next up"
has "and shows what is owed on it" "$OVERVIEW" "Still to collect"

LATER=$(curl -s -b $A -c $A "$BASE/admin?date=2026-08-23")
has "a later date can be picked" "$LATER" "Cook list for Sunday 23 August"
has "flagged as not the next one" "$LATER" "Not the next serving"
has "with nothing ordered yet" "$LATER" "No orders for this date"

# A date that is not on the menu must not be trusted into the query.
BOGUS=$(curl -s -b $A -c $A "$BASE/admin?date=not-a-date")
has "a bogus date falls back to the next serving" "$BOGUS" "Cook list for Sunday 16 August"

echo ""
echo "Orders whose dish was taken off the menu"
# The hole this closes: deleting a sold dish drafts it, and the date pickers
# were built from published dishes only -- so the orders, and the money, fell
# off every screen at once.
T=$(tok $A /admin/menu/new)
# Saving redirects to the new dish, so the id comes from the Location header
# rather than from guessing which row of the menu list is the new one -- that
# list is newest-first, and picking the wrong end drafts somebody else's dish.
DOOMED=$(curl -s -o /dev/null -w '%{redirect_url}' -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=$T" -d "name=Doomed pie" -d "location_id=1" -d "price=4.00" \
  -d "status=published" -d "service_date=2026-09-06" \
  -d "open_from=2026-01-01T00:00" -d "close_at=2027-01-01T00:00" | grep -o '[0-9]*$')

T=$(tok $M /)
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/cart/add" -d "_token=$T" -d "item_id=$DOOMED" -d "qty=1"
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/checkout" \
  -d "_token=$(tok $M /cart)" -d "payment_method=wallet" -d "line[0][qty]=1"

# Now take it off the menu. It has been ordered, so it drafts.
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/$DOOMED/delete" -d "_token=$(tok $A "/admin/menu/$DOOMED")"

ORD=$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06")
has "the date is still offered" "$ORD" "2026-09-06"
has "the orders are still listed" "$ORD" "sam@example.org"
has "and it says the dish has gone" "$ORD" "No longer on the menu"
has "naming it" "$ORD" "Doomed pie"

echo ""
echo "Bulk actions on orders"
has "there are tick boxes" "$ORD" 'name="ids\[\]"'
has "and a status filter" "$ORD" 'name="status"'

# Flashes are read on a following request rather than through curl -L: a
# redirect chain and a separate GET both work, but fetching the CSRF token for
# the next step is itself a request, and it eats whatever is waiting.
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/orders/bulk" \
  -d "_token=$(tok $A "/admin/orders?date=2026-09-06")" \
  -d "date=2026-09-06" -d "status=confirmed" -d "action=paid"
has "ticking nothing does nothing" \
  "$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06")" "Nothing was ticked"

# An id from a date the organiser is not looking at must not be acted on.
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/orders/bulk" \
  -d "_token=$(tok $A "/admin/orders?date=2026-09-06")" \
  -d "date=2026-09-06" -d "status=confirmed" -d "action=cancel" -d "ids[]=99999"
has "an order from elsewhere is refused" \
  "$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06")" "not on this date"

# The ids of everything currently listed. sed, not a trailing-digits grep --
# the attribute ends in a quote, so anchoring on the end of the line matches
# nothing at all and silently ticks no boxes.
IDS=$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06" \
  | sed -n 's/.*name="ids\[\]" value="\([0-9]*\)".*/\1/p')
ARGS=""
for id in $IDS; do ARGS="$ARGS -d ids[]=$id"; done
want "there are orders to act on" "$([ -n "$IDS" ] && echo yes || echo no)" "yes"

curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/orders/bulk" \
  -d "_token=$(tok $A "/admin/orders?date=2026-09-06")" \
  -d "date=2026-09-06" -d "status=confirmed" -d "action=paid" $ARGS
has "several can be marked paid at once" \
  "$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06")" "marked paid"

# Cancelling in bulk refunds and keeps the record.
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/orders/bulk" \
  -d "_token=$(tok $A "/admin/orders?date=2026-09-06")" \
  -d "date=2026-09-06" -d "status=confirmed" -d "action=cancel" $ARGS
has "and cancelled at once" \
  "$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06")" "order(s) cancelled"

CANCELLED=$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06&status=cancelled")
has "cancelled orders remain visible" "$CANCELLED" "Cancelled"
hasnt "and are out of the live list" \
  "$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-09-06&status=confirmed")" 'name="ids\[\]"'

echo ""
echo "Organiser navigation"
has "the menu is across the top by default" "$(curl -s -b $A -c $A "$BASE/admin")" "admin-nav--top"

T=$(tok $A /admin/settings)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/nav" -d "_token=$T" -d "style=side"
has "it can be moved to the side" "$(curl -s -b $A -c $A "$BASE/admin")" "admin-nav--side"
has "and stays there across screens" "$(curl -s -b $A -c $A "$BASE/admin/menu")" "admin-nav--side"

# The choice belongs to the person, not the site.
has "while another organiser is unaffected" "$(curl -s -b $M -c $M "$BASE/")" "Sunday Menu"

T=$(tok $A /admin/settings)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/nav" -d "_token=$T" -d "style=top"
has "and back again" "$(curl -s -b $A -c $A "$BASE/admin")" "admin-nav--top"

SET=$(curl -s -b $A -c $A "$BASE/admin/settings")
has "long screens offer jump links" "$SET" 'href="#blackouts"'
has "with the section to match" "$SET" 'id="blackouts"'

echo ""
echo "Design"
want "the design screen is there" "$(code $A /admin/design)" "200"
DESIGN=$(curl -s -b $A -c $A "$BASE/admin/design")
has "offering a starting look" "$DESIGN" 'name="preset" value="bold"'
has "and a theme picker" "$DESIGN" 'name="design_theme"'
has "listing the shipped theme" "$DESIGN" 'value="list"'

# Defaults must emit nothing: the stylesheet already is the default look.
hasnt "an untouched site adds no inline CSS" "$(curl -s "$BASE/")" "<style>"

T=$(tok $A /admin/design)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/design" \
  -d "_token=$T" -d "design_button=#aa3311" -d "design_brand_name=St Aidans Lunch"

HOME_HTML=$(curl -s "$BASE/")
has "a changed colour reaches the page" "$HOME_HTML" '\-\-button: #aa3311;'
has "and only that colour" "$(flat "$HOME_HTML")" '<style>:root { --button: #aa3311; }'
has "the site name reaches the header" "$HOME_HTML" "St Aidans Lunch"
has "and the browser tab" "$HOME_HTML" "<title>.*St Aidans Lunch"

# A colour box must not become a way to write arbitrary CSS.
T=$(tok $A /admin/design)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/design" \
  -d "_token=$T" -d "design_ink=#000; } body { display:none } :root { --x:1"
hasnt "an injected rule is refused" "$(curl -s "$BASE/")" "display:none"

echo ""
echo "Themes"
T=$(tok $A /admin/design)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/design" -d "_token=$T" -d "design_theme=list"
HOME_HTML=$(curl -s "$BASE/")
has "the theme stylesheet is linked" "$HOME_HTML" 'href="/theme.css'
want "and served" "$(code $A /theme.css)" "200"
has "with the theme's own rules" "$(curl -s "$BASE/theme.css")" "Compact list"
has "while the chosen colour still applies" "$HOME_HTML" '\-\-button: #aa3311;'

# A slug that is not an installed theme is refused outright, and the theme
# already in use is left alone rather than being half-replaced.
T=$(tok $A /admin/design)
REFUSED=$(curl -s -L -b $A -c $A -X POST "$BASE/admin/design" -d "_token=$T" -d "design_theme=../../etc")
has "a traversing slug is refused" "$REFUSED" "no theme called"
has "and the working theme is untouched" "$(curl -s "$BASE/")" 'href="/theme.css'

# Back to the built-in look for whatever runs after this.
T=$(tok $A /admin/design)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/design" -d "_token=$T" -d "reset=1"
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/design" -d "_token=$(tok $A /admin/design)" -d "design_theme="

echo ""
echo "Dish pictures"
# A real PNG, made here so the test carries no binary fixture. It goes in
# data/ -- which is gitignored, and a relative path both PHP and curl can
# resolve whichever platform they were built for. An absolute /tmp path is not:
# on Windows the curl and PHP in use are native builds that cannot read one.
IMG=data/e2e-dish.png
EVIL=data/e2e-evil.jpg

php -r '$i=imagecreatetruecolor(900,600); imagefill($i,0,0,imagecolorallocate($i,190,110,50)); imagepng($i,$argv[1]);' "$IMG" 2>/dev/null

if [ -f "$IMG" ]; then
  T=$(tok $A /admin/menu/1)
  curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/save" \
    -F "_token=$T" -F "id=1" -F "name=BBQ pork on rice" -F "location_id=1" -F "price=10.00" \
    -F "status=published" -F "service_date=2026-08-16" \
    -F "open_from=2026-01-01T00:00" -F "close_at=2027-01-01T00:00" \
    -F "image=@$IMG;type=image/png"

  has "an uploaded picture appears on the card" "$(curl -s "$BASE/")" 'class="dish__photo"'
  has "under a name it was not given" "$(curl -s "$BASE/")" 'src="/assets/uploads/[0-9a-f]\{24\}\.png"'

  # The same request shape, carrying a script instead.
  printf '<?php echo "pwned"; ?>' > "$EVIL"
  curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/save" \
    -F "_token=$(tok $A /admin/menu/1)" -F "id=1" -F "name=BBQ pork on rice" -F "location_id=1" \
    -F "price=10.00" -F "status=published" -F "service_date=2026-08-16" \
    -F "open_from=2026-01-01T00:00" -F "close_at=2027-01-01T00:00" \
    -F "image=@$EVIL;type=image/jpeg"

  has "a script wearing a .jpg is refused" \
    "$(curl -s -b $A -c $A "$BASE/admin/menu/1")" "not a picture"
  has "and the real picture is still there" "$(curl -s "$BASE/")" 'class="dish__photo"'

  # Removing it must leave the dish standing.
  curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/save" \
    -F "_token=$(tok $A /admin/menu/1)" -F "id=1" -F "name=BBQ pork on rice" -F "location_id=1" \
    -F "price=10.00" -F "status=published" -F "service_date=2026-08-16" \
    -F "open_from=2026-01-01T00:00" -F "close_at=2027-01-01T00:00" -F "remove_image=1"

  HOME_HTML=$(curl -s "$BASE/")
  hasnt "removing the picture takes it off the card" "$HOME_HTML" 'class="dish__photo"'
  has "and the dish is still on the menu" "$HOME_HTML" "BBQ pork on rice"

  rm -f "$IMG" "$EVIL"
else
  echo "  --    skipped: no GD to make a test image with"
fi

echo ""
echo "Ways of signing in"
LOGIN=$(curl -s "$BASE/login")
has "the sign-in page offers a password" "$LOGIN" 'name="password"'
has "naming which method it is" "$LOGIN" 'name="method" value="password"'
hasnt "and no organiser back door while passwords are on" "$LOGIN" "rescue=1"

want "the organiser screen is there" "$(code $A /admin/signin)" "200"
SETTINGS=$(curl -s -b $A -c $A "$BASE/admin/signin")
has "listing every method" "$SETTINGS" "Email a sign-in link"
has "including the ones not set up" "$SETTINGS" "Supabase"
has "and the callback URL to copy" "$SETTINGS" "/auth/auth0/callback"

# Turn on the emailed link alongside passwords.
T=$(tok $A /admin/signin)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/signin" \
  -d "_token=$T" -d "enabled[password]=1" -d "enabled[magic]=1" \
  -d "signin_magic_minutes=15" -d "signin_admin_rescue=1" -d "signin_external_create=1"

LOGIN=$(curl -s "$BASE/login")
has "the link method appears once switched on" "$LOGIN" 'name="method" value="magic"'
has "alongside the password form" "$LOGIN" 'name="method" value="password"'

echo ""
echo "Signing in with an emailed link"
: > data/mail.log
# A jar of its own: $M is already signed in, and /login redirects away from
# anyone who is, which would leave nothing to read a CSRF token out of.
L=$(mktemp)
T=$(tok $L /login)
LINKREQ=$(curl -s -b $L -c $L -X POST "$BASE/login" -L \
  -d "_token=$T" -d "method=magic" -d "email=sam@example.org")
has "asking for a link says something noncommittal" "$LINKREQ" "on its way"

UNKNOWN=$(curl -s -b $L -c $L -X POST "$BASE/login" -L \
  -d "_token=$(tok $L /login)" -d "method=magic" -d "email=nobody@example.org")
has "and says exactly the same for an address with no account" "$UNKNOWN" "on its way"
hasnt "which was not emailed" "$(cat data/mail.log 2>/dev/null)" "nobody@example.org"

# Pull the link out of the log the way the member would out of their inbox.
LINK=$(grep -o '/auth/magic/callback?token=[A-Za-z0-9_-]*' data/mail.log | head -1)
if [ -n "$LINK" ]; then
  ok "a link was emailed"
  J=$(mktemp)
  want "following it signs them in" "$(code $J "$LINK")" "302"
  want "and they are really signed in" "$(code $J /account)" "200"
  want "following it again does not" "$(code $J "$LINK")" "302"
  SPENT=$(curl -s -b $J -c $J -L "$BASE$LINK")
  has "saying the link is spent" "$SPENT" "used already"
else
  bad "a link was emailed" "no sign-in link in data/mail.log"
fi

echo ""
echo "Turning passwords off leaves organisers a way in"
T=$(tok $A /admin/signin)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/signin" \
  -d "_token=$T" -d "enabled[magic]=1" -d "signin_magic_minutes=15" \
  -d "signin_admin_rescue=1" -d "signin_external_create=1"

LOGIN=$(curl -s "$BASE/login")
hasnt "members are no longer shown a password box" "$LOGIN" 'name="method" value="password"'
has "but the organiser sign-in is offered" "$LOGIN" "rescue=1"

R=$(mktemp)
T=$(tok $R "/login?rescue=1")
want "an organiser can still get in with a password" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b $R -c $R -X POST "$BASE/login/rescue" \
    -d "_token=$T" -d "email=ada@example.org" -d "password=correct-horse")" "302"
want "and lands in the admin area" "$(code $R /admin)" "200"

# The rescue is for organisers. A member reaching it must not get to bypass
# the method the organisers chose for everybody.
N=$(mktemp)
T=$(tok $N "/login?rescue=1")
curl -s -o /dev/null -b $N -c $N -X POST "$BASE/login/rescue" \
  -d "_token=$T" -d "email=sam@example.org" -d "password=battery-staple"
want "a member cannot use it" "$(code $N /account)" "302"

# Every method off at once. Members get nothing -- no password box smuggled
# back in -- while organisers keep the rescue. Both halves matter: an earlier
# version put a password form back for everybody, which re-admitted the whole
# congregation to a site whose organisers had switched passwords off.
T=$(tok $A /admin/signin)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/signin" \
  -d "_token=$T" -d "signin_admin_rescue=1"

LOGIN=$(curl -s "$BASE/login")
hasnt "with nothing switched on, no password form is offered" "$LOGIN" 'name="method" value="password"'
has "members are told to wait" "$LOGIN" "not set up at the moment"
has "and the organiser route is still there" "$LOGIN" "rescue=1"

# A member must not get in, by either door.
F=$(mktemp)
T=$(tok $F /login)
curl -s -o /dev/null -b $F -c $F -X POST "$BASE/login" \
  -d "_token=$T" -d "method=password" -d "email=sam@example.org" -d "password=battery-staple"
want "a member cannot sign in with a password" "$(code $F /account)" "302"

T=$(tok $F "/login?rescue=1")
curl -s -o /dev/null -b $F -c $F -X POST "$BASE/login/rescue" \
  -d "_token=$T" -d "email=sam@example.org" -d "password=battery-staple"
want "nor through the organiser door" "$(code $F /account)" "302"

G=$(mktemp)
T=$(tok $G "/login?rescue=1")
want "but an organiser can" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b $G -c $G -X POST "$BASE/login/rescue" \
    -d "_token=$T" -d "email=ada@example.org" -d "password=correct-horse")" "302"
want "and reaches the admin area" "$(code $G /admin)" "200"

# The lockout this all exists to prevent: passwords off, a provider that is
# filled in but has never signed anybody in, and the rescue switched off too.
# Saving that combination must not leave the site with no way in.
T=$(tok $A /admin/signin)
LOCKOUT=$(curl -s -b $A -c $A -L -X POST "$BASE/admin/signin" \
  -d "_token=$T" -d "enabled[auth0]=1" \
  -d "signin_auth0_domain=church.eu.auth0.com" \
  -d "signin_auth0_client_id=abc" -d "signin_auth0_client_secret=shh")
has "turning the rescue off is refused while unproven" "$LOCKOUT" "left on"
has "explaining what would have to happen first" "$LOCKOUT" "signed in through a provider"

LOGIN=$(curl -s "$BASE/login")
has "so the organiser sign-in is still offered" "$LOGIN" "rescue=1"

X=$(mktemp)
T=$(tok $X "/login?rescue=1")
want "and still works" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b $X -c $X -X POST "$BASE/login/rescue" \
    -d "_token=$T" -d "email=ada@example.org" -d "password=correct-horse")" "302"
want "letting the organiser back in" "$(code $X /admin)" "200"

# The rest of the form still saved -- only the dangerous switch was overridden.
has "while the provider it was saved with is on" \
  "$(curl -s -b $A -c $A "$BASE/admin/signin")" 'name="enabled\[auth0\]" value="1" checked'

# Put passwords back for whatever runs after this.
T=$(tok $A /admin/signin)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/signin" \
  -d "_token=$T" -d "enabled[password]=1" -d "enabled[magic]=1" \
  -d "signin_magic_minutes=15" -d "signin_admin_rescue=1" -d "signin_external_create=1"

echo ""
echo "Guessing passwords over HTTP"
# Six wrong guesses at the organiser account from one browser. The sixth must
# be refused before the password is even checked.
G=$(mktemp)
for i in 1 2 3 4 5; do
  curl -s -o /dev/null -b $G -c $G -X POST "$BASE/login" \
    -d "_token=$(tok $G /login)" -d "method=password" \
    -d "email=ada@example.org" -d "password=wrong-$i"
done

LOCKED=$(curl -s -b $G -c $G -L -X POST "$BASE/login" \
  -d "_token=$(tok $G /login)" -d "method=password" \
  -d "email=ada@example.org" -d "password=wrong-6")
has "the sixth wrong guess is refused" "$LOCKED" "Too many sign-in attempts"

# And the lock holds even when the password is finally right, which is the
# whole point -- otherwise guessing until you land is still free.
RIGHT=$(curl -s -b $G -c $G -L -X POST "$BASE/login" \
  -d "_token=$(tok $G /login)" -d "method=password" \
  -d "email=ada@example.org" -d "password=correct-horse")
has "and so is the right password while locked" "$RIGHT" "Too many sign-in attempts"
want "so nobody is signed in" "$(code $G /admin)" "302"

# A different account from the same browser still works: the tight limit is
# per address, so one person being guessed at cannot lock out the rest.
O=$(mktemp)
want "another account is unaffected" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b $O -c $O -X POST "$BASE/login" \
    -d "_token=$(tok $O /login)" -d "method=password" \
    -d "email=sam@example.org" -d "password=battery-staple")" "302"
want "and really is signed in" "$(code $O /account)" "200"

# Clear it so later sections can still sign in as the organiser.
php -d extension=php_pdo_sqlite -r 'require "src/bootstrap.php"; PauseCafe\LoginAttempts::clearAll();' > /dev/null 2>&1
want "the rescue tool unlocks it again" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b $G -c $G -X POST "$BASE/login" \
    -d "_token=$(tok $G /login)" -d "method=password" \
    -d "email=ada@example.org" -d "password=correct-horse")" "302"

echo ""
echo "Editing an order"
# A wallet order of its own, on its own date, so cancelling and refunding here
# cannot disturb the fixtures the rest of the run depends on.
T=$(tok $A /admin/menu/new)
EDITABLE=$(curl -s -o /dev/null -w '%{redirect_url}' -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=$T" -d "name=Editable pie" -d "location_id=1" -d "price=10.00" \
  -d "status=published" -d "service_date=2026-10-04" \
  -d "open_from=2026-01-01T00:00" -d "close_at=2027-01-01T00:00" | grep -o '[0-9]*$')

T=$(tok $A /admin/users)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/users/2/wallet" \
  -d "_token=$T" -d "amount=60.00" -d "direction=credit" -d "note=edit float"

T=$(tok $M /)
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/cart/add" -d "_token=$T" -d "item_id=$EDITABLE" -d "qty=3"
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/checkout" \
  -d "_token=$(tok $M /cart)" -d "payment_method=wallet" -d "line[0][qty]=3"

EDIT_ID=$(curl -s -b $A -c $A "$BASE/admin/orders?date=2026-10-04" \
  | sed -n 's|.*/admin/orders/\([0-9]*\)/edit.*|\1|p' | head -1)
want "the orders list links to the editor" "$([ -n "$EDIT_ID" ] && echo yes || echo no)" "yes"
want "which opens" "$(code $A "/admin/orders/$EDIT_ID/edit")" "200"

SCREEN=$(curl -s -b $A -c $A "$BASE/admin/orders/$EDIT_ID/edit")
has "showing what the food is worth" "$SCREEN" "Food now"
has "and what was actually taken" "$SCREEN" "Taken so far"
has "and what can still come back" "$SCREEN" "Can still refund"

LINE_ID=$(echo "$SCREEN" | sed -n 's/.*name="line_id" value="\([0-9]*\)".*/\1/p' | head -1)
want "the line is addressable" "$([ -n "$LINE_ID" ] && echo yes || echo no)" "yes"

# Three at $10 from a $60-ish wallet, then down to one: $20 comes back.
BEFORE=$(curl -s -b $M -c $M "$BASE/account" | grep -oE '\$[0-9]+\.[0-9]{2}' | head -1)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/orders/$EDIT_ID/edit" \
  -d "_token=$(tok $A "/admin/orders/$EDIT_ID/edit")" \
  -d "action=qty" -d "line_id=$LINE_ID" -d "qty=1"

SCREEN=$(curl -s -b $A -c $A "$BASE/admin/orders/$EDIT_ID/edit")
has "reducing it refunds the difference" "$SCREEN" "Quantity changed"
has "and the history records it" "$SCREEN" "changed from 3 to 1"
has "telling the member" "$(cat data/mail.log 2>/dev/null)" "order has changed"

# Refunding more than was paid must be refused outright.
REFUSED=$(curl -s -b $A -c $A -L -X POST "$BASE/admin/orders/$EDIT_ID/edit" \
  -d "_token=$(tok $A "/admin/orders/$EDIT_ID/edit")" \
  -d "action=refund" -d "amount=9999" -d "reason=far too much")
has "an over-refund is refused" "$REFUSED" "more than was paid"

# And a refund with no reason, since the ledger has to stay readable.
NOREASON=$(curl -s -b $A -c $A -L -X POST "$BASE/admin/orders/$EDIT_ID/edit" \
  -d "_token=$(tok $A "/admin/orders/$EDIT_ID/edit")" \
  -d "action=refund" -d "amount=1.00" -d "reason=")
has "so is one with no reason" "$NOREASON" "what the refund is for"

# A details-only edit changes the line and sends nothing.
MAILS=$(grep -c "order has changed" data/mail.log 2>/dev/null || echo 0)
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/orders/$EDIT_ID/edit" \
  -d "_token=$(tok $A "/admin/orders/$EDIT_ID/edit")" \
  -d "action=details" -d "line_id=$LINE_ID" -d "person_name=Samuel"

has "a details edit lands" "$(curl -s -b $A -c $A "$BASE/admin/orders/$EDIT_ID/edit")" "Samuel"
want "and emails nobody" "$(grep -c 'order has changed' data/mail.log 2>/dev/null || echo 0)" "$MAILS"

echo ""
echo "One-off dishes"
# The single-dish editor had no schedule field at all, so this first assertion
# is the whole of that fix: the control has to be on the form.
NEWDISH=$(curl -s -b $A -c $A "$BASE/admin/menu/new")
has "the dish editor offers a schedule" "$NEWDISH" 'name="schedule_id"'
has "and a standalone switch" "$NEWDISH" 'name="standalone"'

# Counted before the one-off exists, so the assertion below is about what the
# one-off changed rather than about whatever the run happens to have published.
WEEKS_BEFORE=$(curl -s -b $M -c $M "$BASE/" | grep -c 'class="week__date"')

# A box of chocolates: no weekly rhythm, its own long window.
curl -s -o /dev/null -b $A -c $A -X POST "$BASE/admin/menu/save" \
  -d "_token=$(tok $A /admin/menu/new)" \
  -d "name=Box of chocolates" -d "location_id=1" -d "price=15.00" \
  -d "status=published" -d "standalone=1" -d "schedule_id=0" \
  -d "open_from=2026-01-01T00:00" -d "close_at=2036-12-24T12:00"

FRONT=$(curl -s -b $M -c $M "$BASE/")
has "a one-off appears in its own section" "$FRONT" "Also available"
has "and is on the page" "$FRONT" "Box of chocolates"

# The reported bug: faking a one-off with an off-day service date pushed a
# dated section onto the front page and hid the real menu behind it. Its own
# section carries no date, so the count of dated weeks must not have moved.
want "without adding a week to the front page" "$(printf '%s' "$FRONT" | grep -c 'class="week__date"')" "$WEEKS_BEFORE"

# And it is a real dish, not just a listing.
ONEOFF_ID=$(printf '%s' "$FRONT" | tr -d '\r\n' | grep -o 'Box of chocolates.*' \
  | grep -o 'name="item_id" value="[0-9]*"' | head -1 | grep -o '[0-9][0-9]*')
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/cart/add" \
  -d "_token=$(tok $M /)" -d "item_id=$ONEOFF_ID" -d "person_name=Sam Member" -d "quantity=1"
has "a one-off can be put in the cart" "$(curl -s -b $M -c $M "$BASE/cart")" "Box of chocolates"

echo ""
echo "The side cart"
# Only the delivery differs between the two paths, so both are checked against
# the same route. A plain post still redirects to the cart page, which is the
# whole no-JavaScript guarantee.
PLAIN=$(curl -s -o /dev/null -w '%{http_code}' -b $M -c $M -X POST "$BASE/cart/add" \
  -d "_token=$(tok $M /)" -d "item_id=$ONEOFF_ID" -d "person_name=Child A" -d "qty=1")
want "a plain add still redirects" "$PLAIN" "302"

# The same post, announced as a background request, comes back as the drawer.
AJAX=$(curl -s -b $M -c $M -H "X-Requested-With: XMLHttpRequest" -X POST "$BASE/cart/add" \
  -d "_token=$(tok $M /)" -d "item_id=$ONEOFF_ID" -d "person_name=Child B" -d "qty=1")
has "an add from the drawer answers in JSON" "$AJAX" '"ok":true'
has "carrying the markup to show" "$AJAX" 'side-cart__lines'
has "with both names on it" "$AJAX" "Child A"
has "including the one just added" "$AJAX" "Child B"

# A refusal has to reach the drawer too, or it would silently do nothing.
NOPE=$(curl -s -b $M -c $M -H "X-Requested-With: XMLHttpRequest" -X POST "$BASE/cart/add" \
  -d "_token=$(tok $M /)" -d "item_id=999999" -d "qty=1")
has "a refusal comes back as one" "$NOPE" '"ok":false'
has "saying why" "$NOPE" "not on the menu"

# Two of a dish is usually two children, and one line can hold only one name.
TWO=$(curl -s -b $M -c $M -H "X-Requested-With: XMLHttpRequest" -X POST "$BASE/cart/add" \
  -d "_token=$(tok $M /)" -d "item_id=$ONEOFF_ID" -d "person_name=Twins" -d "qty=2")
has "a quantity of two offers to be split" "$TWO" "Name each one separately"

# The cart at this point: the one-off, Child A, Child B, then Twins at two.
SPLIT=$(curl -s -b $M -c $M -H "X-Requested-With: XMLHttpRequest" -X POST "$BASE/cart/split" \
  -d "_token=$(tok $M /)" -d "index=3")
hasnt "and after splitting it does not" "$SPLIT" "Name each one separately"
want "the pair became a line each" \
  "$(printf '%s' "$SPLIT" | grep -o 'side-cart__line-main' | wc -l | tr -d ' ')" "5"

# The drawer is only rendered for somebody who could use it.
has "a member's page carries the drawer" "$(curl -s -b $M -c $M "$BASE/")" 'id="side-cart"'
hasnt "a signed-out visitor's does not" "$(curl -s "$BASE/")" 'id="side-cart"'

# Left as it was found, so nothing downstream inherits a full cart.
for i in 4 3 2 1 0; do
  curl -s -o /dev/null -b $M -c $M -X POST "$BASE/cart/remove" -d "_token=$(tok $M /cart)" -d "index=$i"
done
want "and it can be emptied again" "$(curl -s -b $M -c $M "$BASE/cart" | grep -c 'Nothing in the cart yet')" "1"

echo ""
echo "Registering in bulk"
# A script fetches the form for a CSRF token like anybody else, so the token is
# not what stops this. Ten from one address is the loose per-source limit --
# loose because a welcome table signing people up one after another all arrives
# from the same router.
R=$(mktemp)
BULK=0

for i in 1 2 3 4 5 6 7 8 9 10 11 12; do
  OUT=$(curl -s -b $R -c $R -X POST "$BASE/register" \
    -d "_token=$(tok $R /register)" \
    -d "name=Bot $i" -d "email=bot$i@example.org" -d "password=a-good-password")

  if echo "$OUT" | grep -q "Too many sign-ups"; then BULK=1; fi
done

# The refusal arrives on the redirect target rather than the POST, so check
# there too before deciding.
if curl -s -b $R -c $R "$BASE/register" | grep -q "Too many sign-ups"; then BULK=1; fi

want "a run of sign-ups is eventually refused" "$BULK" "1"

ACCOUNTS=$(curl -s -b $A -c $A "$BASE/admin/users" | grep -c "bot[0-9]*@example.org")
if [ "$ACCOUNTS" -lt 12 ]; then ok "and not all of them became accounts"; else bad "and not all of them became accounts" "all 12 got through"; fi

# Signing in must be untouched by that, or filling in the registration form
# would be a way to lock the congregation out. A fresh jar, because the member's
# own is already signed in and /login just redirects it -- with no form on the
# other end there is no token, and the post fails for the wrong reason.
FRESH=$(mktemp)
want "signing in still works after all that" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b $FRESH -c $FRESH -X POST "$BASE/login" \
     -d "_token=$(tok $FRESH /login)" -d "email=sam@example.org" -d "password=battery-staple")" "302"
want "and really is signed in" "$(code $FRESH /account)" "200"

rm -f "$R" "$FRESH"

echo ""
echo "Access control"
want "a member cannot reach the admin area" "$(code $M /admin)" "403"
want "a signed-out visitor is redirected from the cart" "$(code /dev/null /cart)" "302"

echo ""
echo "$pass passed, $fail failed"
rm -f "$A" "$M"
[ "$fail" -eq 0 ] || exit 1
