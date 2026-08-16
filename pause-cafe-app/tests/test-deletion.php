<?php
/**
 * Closing accounts, and what may be deleted outright.
 *
 * wallet_entries.user_id and orders.user_id both cascade from users, and
 * foreign keys are on. So `DELETE FROM users` was never removing a person: it
 * was removing the person, every order they ever placed, and the whole of their
 * ledger -- money taken through Zeffy, refunds given, amounts still owed. The
 * screen even said so, as though it were a feature.
 *
 * An account is a way in. The money is a record. Closing the first must not
 * erase the second, and the first test here holds the cascade up to the light
 * so nobody removes the guard thinking it is belt and braces.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-deletion.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Database;
use PauseCafe\Identities;
use PauseCafe\LoginTokens;
use PauseCafe\Menu;
use PauseCafe\Orders;
use PauseCafe\Schedule;
use PauseCafe\SignIn\Profile;
use PauseCafe\Users;
use PauseCafe\Wallet;

// LoginTokens has no reader, and one test needs to watch the rows go, so it
// counts them where they live.
$tokensFor = static function ( int $userId ): int {
	$statement = Database::pdo()->prepare( 'SELECT COUNT(*) FROM login_tokens WHERE user_id = ?' );
	$statement->execute( array( $userId ) );

	return (int) $statement->fetchColumn();
};

Schedule::freeze( new DateTimeImmutable( '2026-09-01 09:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

$dish = Menu::save(
	array(
		'location_id'  => 1,
		'name'         => 'Pie',
		'price_cents'  => 1000,
		'service_date' => '2026-09-06',
		'open_from'    => '2026-01-01 00:00:00',
		'close_at'     => '2027-01-01 00:00:00',
		'status'       => 'published',
	)
);

$spender = static function ( string $email ) use ( $dish ): int {
	$id = Users::create( $email, 'a-good-password', 'Spender', '', Users::ROLE_MEMBER, true );

	Wallet::credit( $id, 5000, Wallet::KIND_TOPUP, 'float' );
	Orders::place( $id, array( array( 'item_id' => $dish, 'qty' => 1 ) ) );

	return $id;
};

/* =========================================================================
 * The cascade is real
 * ====================================================================== */

echo "Removing the row really does take the money with it\n";

$doomed = $spender( 'doomed@example.org' );

check( 'they have a ledger', count( Wallet::entries( $doomed ) ), 2 );
check( 'and an order', count( Orders::forUser( $doomed ) ), 1 );

/*
 * Deliberately going round Users::delete() to show what it is protecting
 * against. If this ever stops cascading, the guard becomes optional -- and
 * whoever notices should find out here rather than by losing a year of
 * accounts.
 */
Database::pdo()->prepare( 'DELETE FROM users WHERE id = ?' )->execute( array( $doomed ) );

check( 'a raw delete takes the ledger with it', count( Wallet::entries( $doomed ) ), 0 );
check( 'and every order', count( Orders::forUser( $doomed ) ), 0 );

/* =========================================================================
 * Which is why closing is what the organiser gets
 * ====================================================================== */

echo "\nClosing an account keeps everything it did\n";

$member = $spender( 'sam@example.org' );

Identities::attach( $member, 'auth0', new Profile( 'auth0|sam', 'sam@example.org', true, 'Sam' ) );
LoginTokens::issue( $member, 30 );

$before = Wallet::balance( $member );

Users::disable( $member );

$closed = Users::find( $member );

check( 'the account is still there', null !== $closed, true );
check( 'and marked closed', Users::isDisabled( $closed ), true );
check( 'the ledger is untouched', count( Wallet::entries( $member ) ), 2 );
check( 'the balance with it', Wallet::balance( $member ), $before );
check( 'and the order is still on the books', count( Orders::forUser( $member ) ), 1 );

echo "\nBut they cannot get back in\n";

check( 'their password no longer works', Users::authenticate( 'sam@example.org', 'a-good-password' ), null );
check( 'their provider link is gone', Identities::find( 'auth0', 'auth0|sam' ), null );
check( 'so is any link already in their inbox', $tokensFor( $member ), 0 );

$viaProvider = Identities::resolve( 'auth0', new Profile( 'auth0|sam', 'sam@example.org', true, 'Sam' ) );

check( 'and signing in afresh does not admit them', $viaProvider->isAuthenticated(), false );

echo "\nReopening puts it back\n";

Users::enable( $member );

check( 'no longer closed', Users::isDisabled( Users::find( $member ) ), false );
check( 'the password works again', (int) Users::authenticate( 'sam@example.org', 'a-good-password' )['id'], $member );
check( 'and the money never went anywhere', Wallet::balance( $member ), $before );

/* =========================================================================
 * Deleting outright, only where there is nothing to lose
 * ====================================================================== */

echo "\nAn account with history cannot be deleted\n";

check( 'it says so before you try', Users::isDeletable( $member ), false );

check_throws(
	'and refuses if you do',
	static fn() => Users::delete( $member ),
	'destroy'
);

check( 'the account is still there', null !== Users::find( $member ), true );
check( 'with its ledger', count( Wallet::entries( $member ) ), 2 );
check( 'and its order', count( Orders::forUser( $member ) ), 1 );

echo "\nA balance on its own is enough to protect one\n";

$creditOnly = Users::create( 'credit@example.org', 'a-good-password', 'Credit Only', '', Users::ROLE_MEMBER, true );

Wallet::credit( $creditOnly, 2000, Wallet::KIND_TOPUP, 'Zeffy' );

check( 'money without orders still counts', Users::isDeletable( $creditOnly ), false );

check_throws(
	'so it is refused too',
	static fn() => Users::delete( $creditOnly ),
	'destroy'
);

echo "\nAn account that never did anything can go\n";

$spam = Users::create( 'spam@example.org', 'a-good-password', 'Spam Bot', '', Users::ROLE_MEMBER, false );

Identities::attach( $spam, 'auth0', new Profile( 'auth0|spam', 'spam@example.org', true, 'Spam' ) );
LoginTokens::issue( $spam, 30 );

check( 'nothing behind it', Users::isDeletable( $spam ), true );

Users::delete( $spam );

check( 'so it is gone', Users::find( $spam ), null );
check( 'along with its provider link', Identities::find( 'auth0', 'auth0|spam' ), null );
check( 'and any sign-in link', $tokensFor( $spam ), 0 );

// Deleting cannot touch anybody else's history on the way past.
check( 'the member beside it is untouched', count( Wallet::entries( $member ) ), 2 );

/* =========================================================================
 * Orders: trash, restore, and deleting for good
 *
 * Two different things that both sound like removing an order. Cancelling says
 * it happened and was undone, gives the money back, and leaves both halves on
 * the record. Deleting says it never happened, which is only ever true of
 * something put there while testing -- so it is reachable only from the trash,
 * and it takes the wallet entries with it rather than leaving movements
 * pointing at an order nobody can open.
 * ====================================================================== */

echo "\nTrashing takes an order out of everything, and moves no money\n";

$scarce = Menu::save(
	array(
		'location_id'  => 1,
		'name'         => 'Scarce stew',
		'price_cents'  => 800,
		'service_date' => '2026-09-13',
		'open_from'    => '2026-01-01 00:00:00',
		'close_at'     => '2027-01-01 00:00:00',
		'status'       => 'published',
		'capacity'     => 2,
	)
);

$diner = Users::create( 'diner@example.org', 'a-good-password', 'Dee Diner', '', Users::ROLE_MEMBER, true );

Wallet::credit( $diner, 5000, Wallet::KIND_TOPUP, 'float' );

$order = Orders::place( $diner, array( array( 'item_id' => $scarce, 'qty' => 2 ) ) );

check( 'the portions are taken', Menu::item( $scarce )['remaining'], 0 );
check( 'and the money with them', Wallet::balance( $diner ), 3400 );

$spent = Wallet::balance( $diner );

Orders::trash( $order );

check( 'the order is in the trash', Orders::find( $order )['status'], Orders::STATUS_TRASHED );
check( 'the portions come back', Menu::item( $scarce )['remaining'], 2 );
check( 'it leaves the cook list', count( Orders::summaryForServiceDate( '2026-09-13' ) ), 0 );
check( 'and the date picker', in_array( '2026-09-13', Orders::serviceDates(), true ), false );
check( 'the member no longer sees it', count( Orders::forUser( $diner ) ), 0 );
check( 'nor does "both"', count( Orders::forServiceDate( '2026-09-13', '' ) ), 0 );
check( 'but it is in the trash screen', count( Orders::trashed() ), 1 );

// The point of keeping it separate from cancelling.
check( 'and no money moved', Wallet::balance( $diner ), $spent );

check_throws(
	'a trashed order cannot be edited',
	static fn() => Orders::refundAmount( $order, 100, 'Nope', null ),
	'trash'
);

echo "\nRestoring puts it back as it was\n";

Orders::restore( $order );

check( 'confirmed again', Orders::find( $order )['status'], Orders::STATUS_CONFIRMED );
check( 'holding its portions', Menu::item( $scarce )['remaining'], 0 );
check( 'back on the cook list', count( Orders::summaryForServiceDate( '2026-09-13' ) ) > 0, true );
check( 'and the member sees it again', count( Orders::forUser( $diner ) ), 1 );

// A cancelled order that goes to the trash comes back cancelled, not alive.
$cancelled = Orders::place( $diner, array( array( 'item_id' => $dish, 'qty' => 1 ) ) );

Orders::cancel( $cancelled, null );
Orders::trash( $cancelled );
Orders::restore( $cancelled );

check( 'a cancelled order restores as cancelled', Orders::find( $cancelled )['status'], Orders::STATUS_CANCELLED );

echo "\nDeleting for good only works from the trash\n";

check_throws(
	'a live order cannot be deleted',
	static fn() => Orders::purge( $order ),
	'trash'
);

check( 'and it is still there', null !== Orders::find( $order ), true );

echo "\nAnd it takes the money movements with it\n";

$before  = Wallet::balance( $diner );
$entries = count( Wallet::entries( $diner ) );

// Give this one a history worth removing: part of it refunded first.
Orders::refundAmount( $order, 300, 'Cold', null );

check( 'the refund lands', Wallet::balance( $diner ), $before + 300 );

Orders::trash( $order );
Orders::purge( $order );

check( 'the order is gone', Orders::find( $order ), null );
check( 'its charge and its refund went with it', count( Wallet::entries( $diner ) ), $entries - 1 );

/*
 * The charge was 1600 and 300 of it had already come back, so unwinding the
 * whole thing leaves the member 1300 better off than they were a moment ago --
 * and exactly where they stood before the order existed.
 */
check( 'and the balance is as if it never happened', Wallet::balance( $diner ), $before + 1600 );

// Nothing may be left pointing at an order that cannot be opened.
$orphans = Database::pdo()->prepare(
	"SELECT COUNT(*) FROM wallet_entries WHERE reference LIKE ? OR reference = ? OR reference = ?"
);

$orphans->execute( array( 'adjust:' . $order . ':%', 'order:' . $order, 'refund:' . $order ) );

check( 'with no stray entries left behind', (int) $orphans->fetchColumn(), 0 );

check(
	'the lines went too',
	(int) Database::pdo()->query( 'SELECT COUNT(*) FROM order_lines WHERE order_id = ' . (int) $order )->fetchColumn(),
	0
);

echo "\nThe running total beside each entry is rewritten\n";

/*
 * The balance is always the sum of the deltas, so it cannot be wrong. What can
 * be wrong is the figure printed next to each line of a statement, worked out
 * when the entry was written -- everything below a removed entry would carry on
 * from a number that no longer follows.
 */
$running = 0;
$wrong   = 0;

foreach ( array_reverse( Wallet::entries( $diner ) ) as $entry ) {
	$running += (int) $entry['delta_cents'];

	if ( $running !== (int) $entry['balance_after_cents'] ) {
		++$wrong;
	}
}

check( 'every line still adds up', $wrong, 0 );
check( 'ending on the real balance', $running, Wallet::balance( $diner ) );

echo "\nAnd an account emptied of orders becomes deletable again\n";

$tester = Users::create( 'tester@example.org', 'a-good-password', 'Tess Tester', '', Users::ROLE_MEMBER, true );

// Cash on pickup, so the account has an order and no ledger at all -- the shape
// a run-through leaves behind.
$junk = Orders::place( $tester, array( array( 'item_id' => $dish, 'qty' => 1 ) ), null, '', false, 'cod' );

check( 'while the test order stands, the account is protected', Users::isDeletable( $tester ), false );

Orders::trash( $junk );

check( 'trashing alone is not enough', Users::isDeletable( $tester ), false );

Orders::purge( $junk );

check( 'but deleting it for good is', Users::isDeletable( $tester ), true );

Users::delete( $tester );

check( 'so the account can go as well', Users::find( $tester ), null );

/*
 * The other way round: a top-up on its own still protects an account, even with
 * no orders left. Money arrived from somewhere and something has to say so.
 */
$topped = Users::create( 'topped@example.org', 'a-good-password', 'Tom Topped', '', Users::ROLE_MEMBER, true );

Wallet::credit( $topped, 2000, Wallet::KIND_TOPUP, 'Zeffy' );

$paid = Orders::place( $topped, array( array( 'item_id' => $dish, 'qty' => 1 ) ) );

Orders::trash( $paid );
Orders::purge( $paid );

check( 'the order is gone', Orders::find( $paid ), null );
check( 'the top-up is not', count( Wallet::entries( $topped ) ), 1 );
check( 'so the account still cannot be deleted', Users::isDeletable( $topped ), false );

finish();
