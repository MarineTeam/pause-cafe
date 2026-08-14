<?php
/**
 * Editing an order after it was placed, which is all money.
 *
 * The invariant every assertion here is really about: what the wallet says,
 * what the order says it took, and what the food is worth must agree after
 * every operation and after any sequence of them. Each test states the arithmetic
 * it expects rather than just checking a balance, because a balance that happens
 * to be right for the wrong reason is the failure mode that reaches production.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-order-edits.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Mail\LogTransport;
use PauseCafe\Menu;
use PauseCafe\Orders;
use PauseCafe\Payments;
use PauseCafe\Schedule;
use PauseCafe\Settings;
use PauseCafe\Users;
use PauseCafe\Wallet;

$logPath = dirname( __DIR__ ) . '/data/test-edit-mail.log';

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

LogTransport::configure( $logPath );
Settings::set( 'mail_transport', 'log' );

Schedule::freeze( new DateTimeImmutable( '2026-09-01 09:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

$organiser = Users::create( 'ada@example.org', 'a-good-password', 'Ada', '', Users::ROLE_ADMIN, true );
$member    = Users::create( 'sam@example.org', 'a-good-password', 'Sam', 'Youth', Users::ROLE_MEMBER, true );

Wallet::credit( $member, 5000, Wallet::KIND_TOPUP, 'float' );

$dish = static function ( string $name, int $cents, int $capacity = 0 ) {
	return Menu::save(
		array(
			'location_id'  => 1,
			'name'         => $name,
			'price_cents'  => $cents,
			'service_date' => '2026-09-06',
			'open_from'    => '2026-01-01 00:00:00',
			'close_at'     => '2027-01-01 00:00:00',
			'status'       => 'published',
			'capacity'     => $capacity,
		)
	);
};

$pie  = $dish( 'Pie', 1000 );
$soup = $dish( 'Soup', 400 );

$order = Orders::place( $member, array( array( 'item_id' => $pie, 'qty' => 3 ) ) );
$line  = (int) Orders::lines( $order )[0]['id'];

/* =========================================================================
 * The starting position
 * ====================================================================== */

echo "Placing an order records what it took\n";

check( 'the food is worth $30', Orders::find( $order )['total_cents'], 3000 );
check( 'and $30 was taken', (int) Orders::find( $order )['charged_cents'], 3000 );
check( 'leaving $20 in the wallet', Wallet::balance( $member ), 2000 );
check( 'nothing refunded yet', Orders::refundedCents( $order ), 0 );
check( 'and $30 could be', Orders::refundableCents( $order ), 3000 );

/* =========================================================================
 * Reducing
 * ====================================================================== */

echo "\nReducing a line gives the difference back\n";

Orders::setLineQty( $order, $line, 1, $organiser );

check( 'the food is now $10', Orders::find( $order )['total_cents'], 1000 );
check( 'the wallet has $40 — 20 plus the 20 returned', Wallet::balance( $member ), 4000 );
check( '$20 is recorded as refunded', Orders::refundedCents( $order ), 2000 );
check( 'what it took is unchanged, being a record of money in', (int) Orders::find( $order )['charged_cents'], 3000 );
check( 'so only $10 more could ever come back', Orders::refundableCents( $order ), 1000 );

echo "\nAnd the portions come back with it\n";

check( 'two of the three are free again', Menu::sold( $pie ), 1 );

/* =========================================================================
 * Adding
 * ====================================================================== */

echo "\nAdding a dish charges for it\n";

Orders::addLine( $order, $soup, 2, array(), $organiser );

check( 'the food is $18', Orders::find( $order )['total_cents'], 1800 );
check( 'the wallet drops to $32', Wallet::balance( $member ), 3200 );
check( 'and it has now taken $38', (int) Orders::find( $order )['charged_cents'], 3800 );
check( 'so more can be refunded than before', Orders::refundableCents( $order ), 1800 );
check( 'the line is on the order', count( Orders::lines( $order ) ), 2 );

echo "\nA dish for another day cannot be added\n";

$otherDay = Menu::save(
	array(
		'location_id'  => 1,
		'name'         => 'Next week pie',
		'price_cents'  => 1000,
		'service_date' => '2026-09-13',
		'open_from'    => '2026-01-01 00:00:00',
		'close_at'     => '2027-01-01 00:00:00',
		'status'       => 'published',
	)
);

check_throws(
	'it is refused',
	static fn() => Orders::addLine( $order, $otherDay, 1, array(), $organiser ),
	'different date'
);

check( 'and nothing was charged for it', Wallet::balance( $member ), 3200 );

/* =========================================================================
 * Portions
 * ====================================================================== */

echo "\nPortion limits hold when going up\n";

$scarce = $dish( 'Scarce stew', 500, 2 );
$small  = Orders::place( $member, array( array( 'item_id' => $scarce, 'qty' => 1 ) ) );
$scarceLine = (int) Orders::lines( $small )[0]['id'];

check( 'one is taken', Menu::sold( $scarce ), 1 );

check_throws(
	'asking for more than are left is refused',
	static fn() => Orders::setLineQty( $small, $scarceLine, 5, $organiser ),
	'left'
);

check( 'the line is untouched', (int) Orders::lines( $small )[0]['qty'], 1 );
check( 'and so is the wallet', Wallet::balance( $member ), 2700 );

check(
	'going up to exactly the limit is allowed',
	( static function () use ( $small, $scarceLine, $organiser ) {
		Orders::setLineQty( $small, $scarceLine, 2, $organiser );

		return (int) Orders::lines( $small )[0]['qty'];
	} )(),
	2
);

/* =========================================================================
 * Refunding an amount
 * ====================================================================== */

echo "\nA goodwill refund is capped at what was paid\n";

$before = Wallet::balance( $member );

Orders::refundAmount( $order, 250, 'Collected late', $organiser );

check( 'the money goes back', Wallet::balance( $member ), $before + 250 );
check( 'the food is unchanged, since no line moved', Orders::find( $order )['total_cents'], 1800 );

check_throws(
	'more than remains is refused',
	static fn() => Orders::refundAmount( $order, 999999, 'greedy', $organiser ),
	'more than was paid'
);

check_throws( 'so is nothing', static fn() => Orders::refundAmount( $order, 0, 'nothing', $organiser ), 'an amount' );
check_throws( 'and so is no reason', static fn() => Orders::refundAmount( $order, 100, '  ', $organiser ), 'what the refund is for' );

check( 'none of those moved money', Wallet::balance( $member ), $before + 250 );

echo "\nRefunds can never add up to more than was taken\n";

$left = Orders::refundableCents( $order );

Orders::refundAmount( $order, $left, 'Everything back', $organiser );

check( 'the last of it comes back', Orders::refundableCents( $order ), 0 );

check_throws(
	'and then nothing more',
	static fn() => Orders::refundAmount( $order, 1, 'one more penny', $organiser ),
	'more than was paid'
);

/* =========================================================================
 * Cash orders
 * ====================================================================== */

echo "\nA cash order moves no money, only what is owed\n";

Settings::set( 'payment_cod_enabled', 'yes' );

$cashDish = $dish( 'Cash pie', 700 );
$cash     = Orders::place( $member, array( array( 'item_id' => $cashDish, 'qty' => 2 ) ), null, '', false, 'cod' );
$cashLine = (int) Orders::lines( $cash )[0]['id'];

$walletBefore = Wallet::balance( $member );
$entriesBefore = count( Wallet::entries( $member ) );

Orders::setLineQty( $cash, $cashLine, 1, $organiser );

check( 'what is owed halves', Orders::find( $cash )['total_cents'], 700 );
check( 'the wallet is untouched', Wallet::balance( $member ), $walletBefore );
check( 'and no ledger entry was invented', count( Wallet::entries( $member ) ), $entriesBefore );
check( 'but the order still records it', count( Orders::adjustments( $cash ) ), 1 );

/* =========================================================================
 * Details, and what is off limits
 * ====================================================================== */

echo "\nCorrecting details never touches money\n";

$walletBefore = Wallet::balance( $member );

Orders::setLineDetails( $cash, $cashLine, array( 'person_name' => 'Samuel', 'group_name' => 'Youth', 'note' => 'no onions' ) );

$fixed = Orders::lines( $cash )[0];

check( 'the name changes', $fixed['person_name'], 'Samuel' );
check( 'the note lands', $fixed['note'], 'no onions' );
check( 'the wallet does not move', Wallet::balance( $member ), $walletBefore );
check( 'and nothing is added to the history', count( Orders::adjustments( $cash ) ), 1 );

echo "\nA cancelled order is closed to further changes\n";

Orders::cancel( $cash, $organiser );

check_throws(
	'the quantity cannot be changed',
	static fn() => Orders::setLineQty( $cash, $cashLine, 5, $organiser ),
	'cancelled'
);

check_throws(
	'nor can it be refunded again',
	static fn() => Orders::refundAmount( $cash, 100, 'after the fact', $organiser ),
	'cancelled'
);

echo "\nAnd a line from another order is not reachable\n";

check_throws(
	'by id alone',
	static fn() => Orders::setLineQty( $order, $cashLine, 1, $organiser ),
	'not on this order'
);

/* =========================================================================
 * The history
 * ====================================================================== */

echo "\nEvery movement is written down\n";

$history = Orders::adjustments( $order );

check( 'the order has a history', count( $history ) >= 4, true );
check( 'each one says who did it', $history[0]['by_name'], 'Ada' );
check( 'and why', '' !== $history[0]['reason'], true );

echo "\nAnd the ledger agrees with the order\n";

/*
 * The cross-check worth having. Everything above reads the order's own figures,
 * which would all stay self-consistent even if the money went the wrong way --
 * and it did, first time: reducing an order debited the member instead of
 * refunding them, and every order-side number still looked right.
 *
 * This compares the two sides. The wallet entries belonging to this order must
 * sum to the negative of what the order says it is holding, because a charge
 * lowers a balance and a refund raises it.
 */
$statement = \PauseCafe\Database::pdo()->prepare(
	"SELECT COALESCE(SUM(delta_cents), 0) FROM wallet_entries
	 WHERE reference = ? OR reference LIKE ?"
);

$statement->execute( array( 'order:' . $order, 'adjust:' . $order . ':%' ) );

$ledger = (int) $statement->fetchColumn();
$held   = (int) Orders::find( $order )['charged_cents'] - Orders::refundedCents( $order );

check( 'the wallet moved by exactly what the order took, and no more', $ledger, -$held );
check( 'which after refunding everything is nothing at all', $held, 0 );

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

finish();
