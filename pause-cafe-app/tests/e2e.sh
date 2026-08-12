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
has "and the group field too" "$MENU" 'value="Youth"'

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
  -d "_token=$T" -d "item_id=$ITEM" -d "qty=2" -d "person_name=Sam" -d "group_name=Youth"

CART=$(curl -s -b $M -c $M "$BASE/cart")
has "the cart shows the dish" "$CART" "BBQ pork on rice"
has "with the line total" "$CART" '\$20\.00'

T=$(echo "$CART" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
curl -s -o /dev/null -b $M -c $M -X POST "$BASE/checkout" -d "_token=$T"

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
echo "Access control"
want "a member cannot reach the admin area" "$(code $M /admin)" "403"
want "a signed-out visitor is redirected from the cart" "$(code /dev/null /cart)" "302"

echo ""
echo "$pass passed, $fail failed"
rm -f "$A" "$M"
[ "$fail" -eq 0 ] || exit 1
