<?php
/**
 * The organiser's side of orders: reaching them, and the navigation.
 *
 * The reachability tests exist because of a real hole. Deleting a dish that has
 * been sold drafts it rather than removing it — which is what preserves the
 * orders — and the date pickers were built from published dishes only. So the
 * act of protecting the orders was the same act that hid them: the date fell
 * out of every screen and the orders, and the money taken for them, could not
 * be reached at all.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-orders-admin.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\AdminNav;
use PauseCafe\Menu;
use PauseCafe\Orders;
use PauseCafe\Schedule;
use PauseCafe\Users;
use PauseCafe\Wallet;

Schedule::freeze( new DateTimeImmutable( '2026-09-01 09:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

$organiser = Users::create( 'ada@example.org', 'a-good-password', 'Ada', '', Users::ROLE_ADMIN, true );
$member    = Users::create( 'sam@example.org', 'a-good-password', 'Sam', 'Youth', Users::ROLE_MEMBER, true );

Wallet::credit( $member, 5000, Wallet::KIND_TOPUP, 'float' );

$dishId = Menu::save(
	array(
		'location_id'  => 1,
		'name'         => 'Doomed pie',
		'price_cents'  => 1000,
		'service_date' => '2026-09-06',
		'open_from'    => '2026-01-01 00:00:00',
		'close_at'     => '2027-01-01 00:00:00',
		'status'       => 'published',
	)
);

$orderId = Orders::place( $member, array( array( 'item_id' => $dishId, 'qty' => 1 ) ) );

/* =========================================================================
 * Reaching orders whose dish is gone
 * ====================================================================== */

echo "While the dish is published, everything can see the date\n";

check( 'the menu offers it', in_array( '2026-09-06', Menu::serviceDates(), true ), true );
check( 'and so do the orders', in_array( '2026-09-06', Orders::serviceDates(), true ), true );
check( 'nothing is retired', Orders::retiredDishes( '2026-09-06' ), array() );

echo "\nDeleting a sold dish drafts it, and that used to hide the orders\n";

Menu::delete( $dishId );

check( 'the dish is drafted, not removed', Menu::item( $dishId )['status'], 'draft' );
check( 'the order is untouched', count( Orders::forServiceDate( '2026-09-06' ) ), 1 );

// The trap: the menu no longer knows about this date at all.
check( 'the menu has forgotten the date', in_array( '2026-09-06', Menu::serviceDates(), true ), false );

// And the reason the orders are still reachable.
check( 'but the orders remember it', in_array( '2026-09-06', Orders::serviceDates(), true ), true );

check(
	'and the dish is reported as no longer on the menu',
	Orders::retiredDishes( '2026-09-06' ),
	array( 'Doomed pie' )
);

echo "\nA hard-deleted dish is reported the same way\n";

$spare = Menu::save(
	array(
		'location_id'  => 1,
		'name'         => 'Never ordered',
		'price_cents'  => 1000,
		'service_date' => '2026-09-06',
		'open_from'    => '2026-01-01 00:00:00',
		'close_at'     => '2027-01-01 00:00:00',
		'status'       => 'published',
	)
);

// Nothing sold, so this one really is deleted.
Menu::delete( $spare );

check( 'it is gone', Menu::item( $spare ), null );
check( 'and having no orders, it is not reported', Orders::retiredDishes( '2026-09-06' ), array( 'Doomed pie' ) );

/* =========================================================================
 * Cancelled orders stay visible
 * ====================================================================== */

echo "\nCancelling does not make an order disappear\n";

Orders::cancel( $orderId, $organiser );

check( 'it is not in the live list', count( Orders::forServiceDate( '2026-09-06' ) ), 0 );
check(
	'but it is in the cancelled one',
	count( Orders::forServiceDate( '2026-09-06', Orders::STATUS_CANCELLED ) ),
	1
);
check( 'and in both', count( Orders::forServiceDate( '2026-09-06', '' ) ), 1 );

check( 'the money came back', Wallet::balance( $member ), 5000 );

// The date has to stay reachable or there is no way to look at what was
// refunded and why.
check( 'and the date is still offered', in_array( '2026-09-06', Orders::serviceDates(), true ), true );

check(
	'a cancelled order no longer counts as an unfindable dish',
	Orders::retiredDishes( '2026-09-06' ),
	array()
);

/* =========================================================================
 * The navigation
 * ====================================================================== */

echo "\nThe organiser menu is a personal choice\n";

check( 'top to begin with', AdminNav::style( Users::find( $organiser ) ), AdminNav::TOP );

AdminNav::setStyle( $organiser, AdminNav::SIDE );

check( 'switched for them', AdminNav::style( Users::find( $organiser ) ), AdminNav::SIDE );
check( 'and nobody else', AdminNav::style( Users::find( $member ) ), AdminNav::TOP );

check( 'nonsense falls back to the top', ( static function () use ( $organiser ) {
	AdminNav::setStyle( $organiser, 'diagonal' );

	return AdminNav::style( Users::find( $organiser ) );
} )(), AdminNav::TOP );

echo "\nIt knows which screen it is on\n";

check( 'the overview only matches itself', AdminNav::currentFor( '/admin' ), '/admin' );
check( 'a sub-page lights up its section', AdminNav::currentFor( '/admin/menu/builder' ), '/admin/menu' );
check( 'not the overview', AdminNav::currentFor( '/admin/menu/12' ), '/admin/menu' );
check( 'query strings are ignored', AdminNav::currentFor( '/admin/orders?date=2026-09-06' ), '/admin/orders' );
check( 'the kitchen counts as organiser chrome', AdminNav::appliesTo( '/kitchen' ), true );
check( 'the storefront does not', AdminNav::appliesTo( '/' ), false );
check( 'nor does the cart', AdminNav::appliesTo( '/cart' ), false );

check( 'every item has a label', count( array_filter( AdminNav::items() ) ), count( AdminNav::items() ) );

finish();
