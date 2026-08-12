<?php
/**
 * End to end: accounts, wallet, ordering, capacity, cutoff, admin overrides,
 * refunds, the kitchen report and the Zeffy webhook.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-app.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Menu;
use PauseCafe\Money;
use PauseCafe\Orders;
use PauseCafe\Schedule;
use PauseCafe\Settings;
use PauseCafe\Users;
use PauseCafe\Wallet;
use PauseCafe\Zeffy;

function freeze( string $when ): void {
	Schedule::freeze( new DateTimeImmutable( $when, Schedule::timezone() ) );
}

Settings::setMany(
	array(
		'active_mode'       => Schedule::MODE_PLANNED,
		'service_weekday'   => '0',
		'open_days_before'  => '5',
		'open_time'         => '12:00',
		'close_days_before' => '1',
		'close_time'        => '13:00',
	)
);

$locations = Menu::locations();

check( 'three pickup locations are seeded', count( $locations ), 3 );
check( 'first is Marine', $locations[0]['name'], 'Marine' );

/* ------------------------------------------------------------------ */

echo "\nAccounts\n";

check( 'a fresh install needs setup', \PauseCafe\Database::needsSetup(), true );

$adminId = Users::create( 'admin@example.org', 'correct-horse', 'Ada Organiser', '', Users::ROLE_ADMIN, true );

check( 'no longer needs setup', \PauseCafe\Database::needsSetup(), false );

$memberId = Users::create( 'sam@example.org', 'battery-staple', 'Sam Member', 'Youth' );

check( 'new members start unapproved', (int) Users::find( $memberId )['is_approved'], 0 );
check( 'unapproved members cannot order', Users::canOrder( Users::find( $memberId ) ), false );

check_throws(
	'a duplicate email is refused',
	static fn() => Users::create( 'SAM@example.org', 'another-one', 'Impostor' ),
	'already exists'
);

check_throws(
	'a short password is refused',
	static fn() => Users::create( 'short@example.org', 'abc', 'Too Short' ),
	'8 characters'
);

check( 'the right password authenticates', Users::authenticate( 'sam@example.org', 'battery-staple' )['id'], $memberId );
check( 'a wrong password does not', Users::authenticate( 'sam@example.org', 'nope' ), null );
check( 'an unknown email does not', Users::authenticate( 'ghost@example.org', 'whatever' ), null );

Users::update( $memberId, array( 'is_approved' => 1 ) );
check( 'approved members can order', Users::canOrder( Users::find( $memberId ) ), true );

/* ------------------------------------------------------------------ */

echo "\nWallet ledger\n";

check( 'a new wallet is empty', Wallet::balance( $memberId ), 0 );

Wallet::credit( $memberId, 5000, Wallet::KIND_TOPUP, 'Cash at the desk', '', $adminId );
check( 'a credit shows up', Wallet::balance( $memberId ), 5000 );

Wallet::debit( $memberId, 500, Wallet::KIND_ADJUSTMENT, 'Correcting an overpayment', '', $adminId );
check( 'a manual debit shows up', Wallet::balance( $memberId ), 4500 );
check( 'the ledger has both entries', count( Wallet::entries( $memberId ) ), 2 );
check( 'money is formatted', Money::format( 4500 ), '$45.00' );
check( 'a negative reads correctly', Money::format( -1250 ), '-$12.50' );
check( 'typed amounts parse', Money::parse( '$1,234.56' ), 123456 );

/* ------------------------------------------------------------------ */

echo "\nMenu\n";

$marine = (int) $locations[0]['id'];
$rcc    = (int) $locations[1]['id'];

$porkId = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'BBQ pork on rice 叉燒飯',
		'price_cents'  => 1000,
		'service_date' => '2026-08-16',
		'capacity'     => 3,
		'status'       => 'published',
	)
);

$pastaId = Menu::save(
	array(
		'location_id'  => $rcc,
		'name'         => 'Tuscan chicken pasta',
		'price_cents'  => 1200,
		'service_date' => '2026-08-16',
		'capacity'     => 0,
		'status'       => 'published',
	)
);

freeze( '2026-08-12 10:00' ); // Wednesday, inside the window.

check( 'the service date is discoverable', Menu::serviceDates(), array( '2026-08-16' ) );
check( 'the current week resolves', Menu::currentServiceDate(), '2026-08-16' );
check( 'both dishes are on that date', count( Menu::itemsForServiceDate( '2026-08-16' ) ), 2 );
check( 'filtering by location works', count( Menu::itemsForServiceDate( '2026-08-16', $marine ) ), 1 );
check( 'the dish is orderable midweek', Menu::item( $porkId )['window']->isOrderable(), true );

/* ------------------------------------------------------------------ */

echo "\nOrdering\n";

$orderId = Orders::place(
	$memberId,
	array(
		array( 'item_id' => $porkId, 'qty' => 2, 'person_name' => 'Sam', 'group_name' => 'Youth' ),
	)
);

check( 'the order exists', (int) Orders::find( $orderId )['user_id'], $memberId );
check( 'it is for the right day', Orders::find( $orderId )['service_date'], '2026-08-16' );
check( 'the total is right', (int) Orders::find( $orderId )['total_cents'], 2000 );
check( 'the wallet was debited', Wallet::balance( $memberId ), 2500 );
check( 'the line carries the name', Orders::lines( $orderId )[0]['person_name'], 'Sam' );
check( 'the line carries the group', Orders::lines( $orderId )[0]['group_name'], 'Youth' );
check( 'the line snapshots the dish name', Orders::lines( $orderId )[0]['item_name'], 'BBQ pork on rice 叉燒飯' );
check( 'two portions are sold', Menu::sold( $porkId ), 2 );
check( 'one is left', Menu::item( $porkId )['remaining'], 1 );

check_throws(
	'ordering past the portion limit is refused',
	static fn() => Orders::place( $memberId, array( array( 'item_id' => $porkId, 'qty' => 2 ) ) ),
	'only has 1 left'
);

check( 'the refused order did not move money', Wallet::balance( $memberId ), 2500 );

// Forced, so the window check does not fire first and mask the guard being
// tested here -- next week's dish is legitimately not open yet.
check_throws(
	'mixing service dates in one order is refused',
	static function () use ( $memberId, $porkId, $marine, $adminId ) {
		$other = Menu::save(
			array(
				'location_id'  => $marine,
				'name'         => 'Next week special',
				'price_cents'  => 1000,
				'service_date' => '2026-08-23',
				'status'       => 'published',
			)
		);

		Orders::place(
			$memberId,
			array(
				array( 'item_id' => $porkId, 'qty' => 1 ),
				array( 'item_id' => $other, 'qty' => 1 ),
			),
			$adminId,
			'',
			true
		);
	},
	'same day'
);

/* ------------------------------------------------------------------ */

echo "\nThe cutoff is enforced at the moment of ordering\n";

freeze( '2026-08-15 13:05' ); // Saturday, five minutes past the cutoff.

check( 'the dish is no longer orderable', Menu::item( $pastaId )['window']->isOrderable(), false );

check_throws(
	'a member cannot order after the cutoff',
	static fn() => Orders::place( $memberId, array( array( 'item_id' => $pastaId, 'qty' => 1 ) ) ),
	'Ordering closed'
);

check( 'nothing was charged', Wallet::balance( $memberId ), 2500 );

$behalfId = Orders::place(
	$memberId,
	array( array( 'item_id' => $pastaId, 'qty' => 1, 'person_name' => 'Jo', 'group_name' => 'Seniors' ) ),
	$adminId,
	'Phoned in Saturday evening',
	true
);

check( 'an organiser can order past the cutoff', (int) Orders::find( $behalfId )['total_cents'], 1200 );
check( 'it records who placed it', (int) Orders::find( $behalfId )['placed_by'], $adminId );
check( 'the wallet is still debited', Wallet::balance( $memberId ), 1300 );
check( 'the note is kept', Orders::find( $behalfId )['note'], 'Phoned in Saturday evening' );

check_throws(
	'even an organiser cannot exceed the portion limit',
	static fn() => Orders::place( $memberId, array( array( 'item_id' => $porkId, 'qty' => 5 ) ), $adminId, '', true ),
	'only has 1 left'
);

/* ------------------------------------------------------------------ */

echo "\nBalance is checked before an order goes through\n";

$brokeId = Users::create( 'broke@example.org', 'no-money-here', 'Skint Person', 'Youth', Users::ROLE_MEMBER, true );

freeze( '2026-08-12 10:00' );

check_throws(
	'ordering with an empty wallet is refused',
	static fn() => Orders::place( $brokeId, array( array( 'item_id' => $pastaId, 'qty' => 1 ) ) ),
	'balance'
);

check( 'their balance is untouched', Wallet::balance( $brokeId ), 0 );

Settings::set( 'allow_negative_balance', 'yes' );
$negativeId = Orders::place( $brokeId, array( array( 'item_id' => $pastaId, 'qty' => 1 ) ) );
check( 'allowing negatives lets it through', Wallet::balance( $brokeId ), -1200 );
Settings::set( 'allow_negative_balance', 'no' );

/* ------------------------------------------------------------------ */

echo "\nCancelling refunds the wallet\n";

$before = Wallet::balance( $brokeId );
Orders::cancel( $negativeId, $adminId );

check( 'the order is cancelled', Orders::find( $negativeId )['status'], 'cancelled' );
check( 'the money went back', Wallet::balance( $brokeId ), $before + 1200 );

check_throws(
	'cancelling twice is refused',
	static fn() => Orders::cancel( $negativeId, $adminId ),
	'already cancelled'
);

/* ------------------------------------------------------------------ */

echo "\nKitchen report\n";

$summary = Orders::summaryForServiceDate( '2026-08-16' );

check( 'two locations have orders', count( $summary ), 2 );
check( 'Marine has the pork', array_key_first( $summary['Marine'] ), 'BBQ pork on rice 叉燒飯' );
check( 'with two portions', $summary['Marine']['BBQ pork on rice 叉燒飯']['qty'], 2 );
check( 'and names the eater', $summary['Marine']['BBQ pork on rice 叉燒飯']['people'][0], 'Sam (Youth) ×2' );
check( 'RCC shows the phone order', $summary['RCC']['Tuscan chicken pasta']['people'][0], 'Jo (Seniors)' );
check( 'the cancelled order is excluded', count( $summary['RCC']['Tuscan chicken pasta']['people'] ), 1 );
check( 'export rows are flat', count( Orders::exportRows( '2026-08-16' ) ), 2 );

/* ------------------------------------------------------------------ */

echo "\nZeffy webhook\n";

$payload = array(
	'id'         => 'pay_abc123',
	'email'      => 'sam@example.org',
	'amount'     => 40.00,
	'donorName'  => 'Sam Member',
);

$before = Wallet::balance( $memberId );
$result = Zeffy::applyPayment( $payload );

check( 'a payment is credited', $result['status'], 'credited' );
check( 'the balance went up by the amount', Wallet::balance( $memberId ), $before + 4000 );

$again = Zeffy::applyPayment( $payload );

check( 'a redelivery is ignored', $again['status'], 'duplicate' );
check( 'and does not credit twice', Wallet::balance( $memberId ), $before + 4000 );

check(
	'an unknown email is reported, not auto-created',
	Zeffy::applyPayment( array( 'id' => 'pay_x', 'email' => 'nobody@example.org', 'amount' => 10 ) )['status'],
	'unmatched'
);

check(
	'amounts already in cents are trusted',
	Zeffy::extract( array( 'id' => 'p1', 'email' => 'a@b.co', 'amountInCents' => 2500 ) )['cents'],
	2500
);

check(
	'a wrapped payload is unwrapped',
	Zeffy::extract( array( 'data' => array( 'id' => 'p2', 'payerEmail' => 'a@b.co', 'total' => 15.5 ) ) )['cents'],
	1550
);

check(
	'a payload with no email is ignored',
	Zeffy::extract( array( 'id' => 'p3', 'amount' => 10 ) ),
	null
);

check(
	'a payload with no amount is ignored',
	Zeffy::extract( array( 'id' => 'p4', 'email' => 'a@b.co' ) ),
	null
);

/* ------------------------------------------------------------------ */

echo "\nDeleting a dish that has been ordered keeps history\n";

check( 'it is drafted, not deleted', Menu::delete( $porkId ), false );
check( 'the item still exists', Menu::item( $porkId )['status'], 'draft' );
check( 'the order line is intact', Orders::lines( $orderId )[0]['item_name'], 'BBQ pork on rice 叉燒飯' );

$unusedId = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'Never ordered',
		'price_cents'  => 900,
		'service_date' => '2026-09-06',
		'status'       => 'published',
	)
);

check( 'an unused dish deletes outright', Menu::delete( $unusedId ), true );
check( 'and is gone', Menu::item( $unusedId ), null );

finish();
