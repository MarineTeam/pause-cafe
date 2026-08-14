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

use PauseCafe\Groups;
use PauseCafe\Kitchen;
use PauseCafe\Mail\LogTransport;
use PauseCafe\Mail\Message;
use PauseCafe\Mail\ResendTransport;
use PauseCafe\Mail\Result;
use PauseCafe\Mail\SmtpTransport;
use PauseCafe\Mail\Transport;
use PauseCafe\Mailer;
use PauseCafe\Menu;
use PauseCafe\MenuBuilder;
use PauseCafe\MenuChanges;
use PauseCafe\MenuFields;
use PauseCafe\Money;
use PauseCafe\Notifications;
use PauseCafe\Orders;
use PauseCafe\Payments;
use PauseCafe\Schedule;

/**
 * A transport that never works, so the fallback path can be exercised.
 */
class AlwaysFailsTransport implements Transport {

	public function id(): string {
		return 'always-fails';
	}

	public function label(): string {
		return 'Always fails';
	}

	public function description(): string {
		return 'For tests only.';
	}

	public function isConfigured(): bool {
		return true;
	}

	public function configFields(): array {
		return array();
	}

	public function send( Message $message, string $fromName, string $fromEmail ): Result {
		return Result::failed( $this->id(), 'Nope.' );
	}
}
use PauseCafe\Schedules;
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

/* ------------------------------------------------------------------ */

echo "\nPayment methods are a register, not a hardcoded wallet\n";

check( 'both built-ins are registered', array_keys( Payments::all() ), array( 'wallet', 'cod' ) );
check( 'both are on by default', array_keys( Payments::enabled() ), array( 'wallet', 'cod' ) );
check( 'the wallet is the default choice', Payments::defaultId(), 'wallet' );
check( 'the wallet settles at once', Payments::get( 'wallet' )->settlesImmediately(), true );
check( 'cash on pickup does not', Payments::get( 'cod' )->settlesImmediately(), false );

freeze( '2026-09-09 10:00' ); // Wednesday, inside the window for the 13th.

$cashDish = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'Cash dish',
		'price_cents'  => 1500,
		'service_date' => '2026-09-13',
		'status'       => 'published',
	)
);

$before  = Wallet::balance( $memberId );
$cashRef = Orders::place(
	$memberId,
	array( array( 'item_id' => $cashDish, 'qty' => 1, 'person_name' => 'Sam' ) ),
	null,
	'',
	false,
	'cod'
);

check( 'the order records how it is being paid', Orders::find( $cashRef )['payment_method'], 'cod' );
check( 'it starts owing', Orders::isPaid( Orders::find( $cashRef ) ), false );
check( 'and takes nothing from the wallet', Wallet::balance( $memberId ), $before );
check( 'it appears on the owing list', count( Orders::unpaidForServiceDate( '2026-09-13' ) ), 1 );

Orders::markPaid( $cashRef );

check( 'marking it paid sticks', Orders::isPaid( Orders::find( $cashRef ) ), true );
check( 'and clears the owing list', Orders::unpaidForServiceDate( '2026-09-13' ), array() );
check( 'without inventing a ledger entry', Wallet::balance( $memberId ), $before );

Orders::cancel( $cashRef, $adminId );

// Cash the system never held is not cash it can hand back.
check( 'cancelling a cash order credits nothing', Wallet::balance( $memberId ), $before );

echo "\nThe wallet can be switched off entirely\n";

Settings::set( Payments::settingKey( 'wallet' ), 'no' );

check( 'it drops out of the enabled list', array_keys( Payments::enabled() ), array( 'cod' ) );
check( 'and cash becomes the default', Payments::defaultId(), 'cod' );
check_throws( 'choosing it is refused', static fn() => Payments::resolve( 'wallet' ), 'not available' );

$fallbackDish = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'Fallback dish',
		'price_cents'  => 800,
		'service_date' => '2026-09-13',
		'status'       => 'published',
	)
);

$fallbackRef = Orders::place( $memberId, array( array( 'item_id' => $fallbackDish, 'qty' => 1 ) ) );

check( 'an order with no method chosen falls back to cash', Orders::find( $fallbackRef )['payment_method'], 'cod' );
check( 'and still moves no money', Wallet::balance( $memberId ), $before );

Settings::set( Payments::settingKey( 'wallet' ), 'yes' );

check_throws( 'an invented method is refused', static fn() => Payments::resolve( 'bitcoin' ), 'does not exist' );

echo "\nA short balance blocks the wallet but never cash\n";

$skint = Users::create( 'skint@example.org', 'nothing-here-yet', 'Skint Two', '', Users::ROLE_MEMBER, true );

check( 'the wallet objects', '' !== Payments::get( 'wallet' )->unavailableReason( $skint, 5000 ), true );
check( 'cash does not', Payments::get( 'cod' )->unavailableReason( $skint, 5000 ), '' );

check_throws(
	'so a wallet order is refused',
	static fn() => Orders::place( $skint, array( array( 'item_id' => $fallbackDish, 'qty' => 1 ) ), null, '', false, 'wallet' ),
	'balance'
);

$skintRef = Orders::place( $skint, array( array( 'item_id' => $fallbackDish, 'qty' => 1 ) ), null, '', false, 'cod' );

check( 'while a cash order goes through', Orders::find( $skintRef )['payment_method'], 'cod' );
check( 'owing what it is worth', (int) Orders::find( $skintRef )['total_cents'], 800 );

/* ------------------------------------------------------------------ */

echo "\nGroups are a managed list, not free text\n";

check( 'none to begin with', Groups::any(), false );
check( 'so nothing validates', Groups::sanitise( 'Youth' ), '' );

$youthId = Groups::add( 'Youth' );
Groups::add( 'Seniors' );

check( 'a group can be added', $youthId > 0, true );
check( 'and is listed', Groups::names(), array( 'Youth', 'Seniors' ) );
check( 'a duplicate is refused', Groups::add( 'Youth' ), 0 );
check( 'so is a case-variant duplicate', Groups::add( 'youth' ), 0 );
check( 'so is a blank name', Groups::add( '   ' ), 0 );

check( 'a known group passes through', Groups::sanitise( 'Youth' ), 'Youth' );
check( 'matching ignores case but returns the canonical spelling', Groups::sanitise( 'YOUTH' ), 'Youth' );
check( 'surrounding space is trimmed', Groups::sanitise( '  Seniors ' ), 'Seniors' );
check( 'an unknown group is discarded', Groups::sanitise( 'Made Up' ), '' );
check( 'and so is empty input', Groups::sanitise( '' ), '' );

// This is the guard that matters: a dropdown is only a convenience on the form,
// so a hand-crafted POST must not be able to invent a group.
check(
	'a forged group never reaches the database',
	( static function () {
		$id = Users::create( 'forged@example.org', 'a-password-here', 'Forger', Groups::sanitise( 'Definitely Not A Group' ) );

		return Users::find( $id )['group_name'];
	} )(),
	''
);

echo "\nRenaming a group moves everyone in it\n";

Users::update( $memberId, array( 'group_name' => 'Youth' ) );

check( 'renaming succeeds', Groups::rename( $youthId, 'Young Adults' ), true );
check( 'the list is updated', Groups::sanitise( 'Young Adults' ), 'Young Adults' );
check( 'the old name no longer validates', Groups::sanitise( 'Youth' ), '' );
check( 'the account moved across', Users::find( $memberId )['group_name'], 'Young Adults' );

check(
	'past orders keep the name they were placed under',
	Orders::summaryForServiceDate( '2026-08-16' )['Marine']['BBQ pork on rice 叉燒飯']['people'][0],
	'Sam (Youth) ×2'
);

check( 'renaming onto an existing name is refused', Groups::rename( $youthId, 'Seniors' ), false );
check( 'renaming to blank is refused', Groups::rename( $youthId, '  ' ), false );

echo "\nRemoving a group leaves the people in it alone\n";

Groups::delete( $youthId );

check( 'it is off the list', Groups::names(), array( 'Seniors' ) );
check( 'but the account keeps its group', Users::find( $memberId )['group_name'], 'Young Adults' );
check( 'and it is reported as orphaned', Groups::orphaned(), array( 'Young Adults' ) );

/* ------------------------------------------------------------------ */

echo "\nNotes ride along with each meal\n";

freeze( '2026-10-07 10:00' ); // Wednesday, inside the window for the 11th.

$kMarine = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'Roast dinner',
		'price_cents'  => 1100,
		'service_date' => '2026-10-11',
		'status'       => 'published',
	)
);

$kRcc = Menu::save(
	array(
		'location_id'  => $rcc,
		'name'         => 'Veggie curry',
		'price_cents'  => 1000,
		'service_date' => '2026-10-11',
		'status'       => 'published',
	)
);

Wallet::credit( $memberId, 20000, Wallet::KIND_TOPUP, 'Test float' );

$notedRef = Orders::place(
	$memberId,
	array(
		array( 'item_id' => $kMarine, 'qty' => 2, 'person_name' => 'Ada', 'group_name' => 'Youth', 'note' => 'no gravy' ),
		array( 'item_id' => $kRcc, 'qty' => 1, 'person_name' => 'Bo', 'group_name' => 'Seniors' ),
	),
	null,
	'Leave at the side door'
);

$notedLines = Orders::lines( $notedRef );

check( 'a line note is stored', $notedLines[0]['note'], 'no gravy' );
check( 'a line without one is empty', $notedLines[1]['note'], '' );
check( 'the order note is stored too', Orders::find( $notedRef )['note'], 'Leave at the side door' );

/* ------------------------------------------------------------------ */

echo "\nThe kitchen table filters and sorts\n";

$everything = Orders::lineItemsFiltered( array( 'from' => '2026-10-11', 'to' => '2026-10-11' ) );

check( 'the date range narrows to that week', count( $everything ), 2 );
check( 'rows carry the line note', $everything[0]['note'], 'no gravy' );
check( 'and the order note alongside it', $everything[0]['order_note'], 'Leave at the side door' );
check( 'and who actually ordered', $everything[0]['account_name'], 'Sam Member' );

check(
	'filtering by dish',
	count( Orders::lineItemsFiltered( array( 'from' => '2026-10-11', 'to' => '2026-10-11', 'dish' => 'Veggie curry' ) ) ),
	1
);

check(
	'filtering by pickup location',
	count( Orders::lineItemsFiltered( array( 'from' => '2026-10-11', 'to' => '2026-10-11', 'location' => 'Marine' ) ) ),
	1
);

check(
	'filtering by group',
	Orders::lineItemsFiltered( array( 'from' => '2026-10-11', 'to' => '2026-10-11', 'group' => 'Seniors' ) )[0]['person_name'],
	'Bo'
);

check(
	'a range that excludes everything returns nothing',
	Orders::lineItemsFiltered( array( 'from' => '2027-01-01', 'to' => '2027-01-31' ) ),
	array()
);

check( 'totals fold the lines into dishes', Orders::totalsByDish( $everything ), array( 'Roast dinner' => 2, 'Veggie curry' => 1 ) );

echo "\nSorting is whitelisted, and pickup stays the tiebreak\n";

$byDate = array( 'from' => '2026-10-11', 'to' => '2026-10-11' );

check(
	'default order is by pickup location',
	array_column( Orders::lineItemsFiltered( $byDate ), 'location_name' ),
	array( 'Marine', 'RCC' )
);

check(
	'sorting by dish descending',
	array_column( Orders::lineItemsFiltered( $byDate, 'dish', 'desc' ), 'item_name' ),
	array( 'Veggie curry', 'Roast dinner' )
);

check(
	'sorting by quantity descending',
	array_column( Orders::lineItemsFiltered( $byDate, 'qty', 'desc' ), 'qty' ),
	array( 2, 1 )
);

// The sort key is interpolated into ORDER BY, so anything unrecognised has to
// fall back rather than reach the query.
check(
	'an injected sort key falls back to the default',
	array_column( Orders::lineItemsFiltered( $byDate, "qty; DROP TABLE orders --", 'asc' ), 'location_name' ),
	array( 'Marine', 'RCC' )
);

check( 'and the table is still there', count( Orders::lineItemsFiltered( $byDate ) ), 2 );
check( 'an injected direction is ignored', count( Orders::lineItemsFiltered( $byDate, 'qty', 'asc; DELETE FROM orders' ) ), 2 );

$options = Orders::filterOptions();

check( 'filter options list the dishes', in_array( 'Veggie curry', $options['dishes'], true ), true );
check( 'and the locations', in_array( 'Marine', $options['locations'], true ), true );
check( 'and the groups', in_array( 'Seniors', $options['groups'], true ), true );

/* ------------------------------------------------------------------ */

echo "\nKitchen access is organisers, or a shared password\n";

$_SESSION = array();

check( 'no password means organisers only', Kitchen::isProtected(), false );
check( 'so a visitor is out', Kitchen::hasAccess(), false );
check( 'and unlocking cannot work', Kitchen::unlock( 'anything' ), false );

Kitchen::setPassword( 'kitchen-door-2026' );

check( 'setting one flips it on', Kitchen::isProtected(), true );
check( 'the wrong password is refused', Kitchen::unlock( 'wrong' ), false );
check( 'and still leaves them out', Kitchen::hasAccess(), false );
check( 'the right one gets in', Kitchen::unlock( 'kitchen-door-2026' ), true );
check( 'which grants access', Kitchen::hasAccess(), true );

Kitchen::lock();
check( 'signing out revokes it', Kitchen::hasAccess(), false );

Kitchen::setPassword( '' );
check( 'clearing it goes back to organisers only', Kitchen::isProtected(), false );

echo "\nDate range presets\n";

freeze( '2026-08-12 09:00' ); // A Wednesday.

check( 'seven days looks forward', Kitchen::resolveRange( '7days' ), array( 'from' => '2026-08-12', 'to' => '2026-08-19' ) );
check( 'this week is Monday to Sunday', Kitchen::resolveRange( 'week' ), array( 'from' => '2026-08-10', 'to' => '2026-08-16' ) );
check( 'this month is the calendar month', Kitchen::resolveRange( 'month' ), array( 'from' => '2026-08-01', 'to' => '2026-08-31' ) );
check( 'last 30 days looks back', Kitchen::resolveRange( 'past' ), array( 'from' => '2026-07-13', 'to' => '2026-08-12' ) );
check( 'everything is unbounded', Kitchen::resolveRange( 'all' ), array( 'from' => '', 'to' => '' ) );
check( 'an unknown preset falls back to seven days', Kitchen::resolveRange( 'nonsense' ), Kitchen::resolveRange( '7days' ) );

check(
	'an explicit from overrides the preset',
	Kitchen::filtersFromQuery( array( 'range' => 'month', 'from' => '2026-09-01' ) )['from'],
	'2026-09-01'
);

check(
	'and marks the range custom',
	Kitchen::filtersFromQuery( array( 'range' => 'month', 'from' => '2026-09-01' ) )['range'],
	'custom'
);

check(
	'a garbage date is ignored',
	Kitchen::filtersFromQuery( array( 'range' => 'week', 'from' => 'whenever' ) )['from'],
	'2026-08-10'
);

check(
	'links keep the other filters',
	Kitchen::url( array( 'group' => 'Youth', 'range' => 'week' ), array( 'sort' => 'dish' ) ),
	'/kitchen?group=Youth&range=week&sort=dish'
);

check(
	'and drop the empty ones',
	Kitchen::url( array( 'group' => '', 'dish' => 'Curry' ), array( 'sort' => 'qty' ), '/kitchen/export' ),
	'/kitchen/export?dish=Curry&sort=qty'
);

/* ------------------------------------------------------------------ */

echo "\nBuilding an email\n";

$msg = Message::make( 'sam@example.org', 'Sam Member', 'Lunch is ready', "Line one\nLine two" );

check( 'the address survives', $msg->toEmail, 'sam@example.org' );
check( 'the display name is quoted', $msg->formattedTo(), '"Sam Member" <sam@example.org>' );

// A newline in any header field is how an injected Bcc gets added.
$injected = Message::make(
	"sam@example.org\r\nBcc: everyone@example.org",
	"Sam\nX-Evil: yes",
	"Subject\r\nBcc: someone@example.org",
	'body'
);

check( 'newlines are stripped from the address', $injected->toEmail, 'sam@example.orgBcc: everyone@example.org' );
check( 'and from the name', $injected->toName, 'SamX-Evil: yes' );
check( 'and from the subject', $injected->subject, 'SubjectBcc: someone@example.org' );
check( 'so the raw message grows no extra headers', substr_count( $injected->raw( 'Cafe', 'a@b.co' ), "\r\nBcc:" ), 0 );

check( 'an ASCII subject is left alone', $msg->encodeSubject( 'Lunch' ), 'Lunch' );
check( 'a Chinese subject is encoded', $msg->encodeSubject( '叉燒飯' ), '=?UTF-8?B?' . base64_encode( '叉燒飯' ) . '?=' );

$raw = $msg->raw( 'Pause Cafe', 'lunch@example.org' );

check( 'the raw message carries From', false !== strpos( $raw, 'From: "Pause Cafe" <lunch@example.org>' ), true );
check( 'and To', false !== strpos( $raw, 'To: "Sam Member" <sam@example.org>' ), true );
check( 'and is plain text without an HTML part', false !== strpos( $raw, 'text/plain' ), true );
check( 'with CRLF line endings', false !== strpos( $raw, "Line one\r\nLine two" ), true );

$both = Message::make( 'a@b.co', 'A', 'Subject', 'plain', '<p>rich</p>' );

check( 'adding HTML makes it multipart', false !== strpos( $both->raw( 'C', 'c@d.co' ), 'multipart/alternative' ), true );

echo "\nSMTP protocol handling\n";

check( 'a simple reply parses', SmtpTransport::parseReply( "250 OK\r\n" ), array( 'code' => 250, 'text' => 'OK' ) );
check(
	'a multi-line reply uses the last line',
	SmtpTransport::parseReply( "250-mail.example.org\r\n250-SIZE 35882577\r\n250 HELP\r\n" ),
	array( 'code' => 250, 'text' => 'HELP' )
);
check( 'an error code comes through', SmtpTransport::parseReply( "535 auth failed\r\n" )['code'], 535 );
check( 'nonsense reads as code zero', SmtpTransport::parseReply( "who knows\r\n" )['code'], 0 );

// A line of a single dot would otherwise end the DATA block early.
check( 'a leading dot is doubled', SmtpTransport::stuffDots( "hello\r\n.\r\nworld" ), "hello\r\n..\r\nworld" );
check( 'including at the very start', SmtpTransport::stuffDots( '.hidden' ), '..hidden' );
check( 'and ordinary text is untouched', SmtpTransport::stuffDots( "a\r\nb" ), "a\r\nb" );

echo "\nResend error messages say what to do\n";

check(
	'a bad key is named as such',
	false !== strpos( ResendTransport::explain( 401, '{"message":"Invalid API key"}' ), 'rejected the API key' ),
	true
);
check(
	'an unverified domain is named as such',
	false !== strpos( ResendTransport::explain( 422, '{}' ), 'not verified' ),
	true
);

echo "\nSending goes through the chosen transport\n";

Settings::setMany( array( 'mail_enabled' => 'yes', 'mail_transport' => 'log' ) );

$logPath = LogTransport::path();

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

check( 'four transports are registered', array_keys( Mailer::all() ), array( 'php', 'smtp', 'resend', 'log' ) );
check( 'the selected one is used', Mailer::selectedId(), 'log' );

$result = Mailer::send( Message::make( 'sam@example.org', 'Sam', 'Hello', 'Body text' ) );

check( 'it reports success', $result->ok, true );
check( 'naming the transport', $result->transport, 'log' );
check( 'and the message reached the file', false !== strpos( (string) file_get_contents( $logPath ), 'Body text' ), true );

check(
	'an invalid address is refused before any transport runs',
	Mailer::send( Message::make( 'not-an-address', 'X', 'Hi', 'Body' ) )->ok,
	false
);

Settings::set( 'mail_enabled', 'no' );
check( 'switching email off stops sends', Mailer::send( Message::make( 'sam@example.org', 'S', 'Hi', 'B' ) )->ok, false );
Settings::set( 'mail_enabled', 'yes' );

check( 'an unknown transport falls back to php', ( static function () {
	Settings::set( 'mail_transport', 'carrier-pigeon' );
	$id = Mailer::selectedId();
	Settings::set( 'mail_transport', 'log' );

	return $id;
} )(), 'php' );

echo "\nA failing transport falls back to mail()\n";

Mailer::register( new AlwaysFailsTransport() );
Settings::set( 'mail_transport', 'always-fails' );

check( 'the broken one is selected', Mailer::selectedId(), 'always-fails' );

$fallbackResult = Mailer::send( Message::make( 'sam@example.org', 'Sam', 'Hello', 'Body' ) );

// mail() is not available in this environment, so the fallback is expected to
// fail too -- what matters is that it was attempted and both reasons surface.
check( 'the failure names the original transport', $fallbackResult->transport, 'always-fails' );
check( 'and explains the first failure', false !== strpos( $fallbackResult->message, 'Nope' ), true );
check( 'and says the fallback was tried', false !== strpos( $fallbackResult->message, 'fallback' ), true );

Settings::set( 'mail_transport', 'log' );

echo "\nThe app's own emails\n";

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

// The date-preset checks above left the clock in August; this dish is served in
// October, so wind forward into its ordering window.
freeze( '2026-10-07 10:00' );

$mailOrder = Orders::place(
	$memberId,
	array( array( 'item_id' => $kMarine, 'qty' => 1, 'person_name' => 'Ada', 'group_name' => 'Youth', 'note' => 'extra gravy' ) ),
	null,
	'Ring the bell'
);

Notifications::orderPlaced( $mailOrder );

$log = (string) file_get_contents( $logPath );

check( 'the confirmation goes to the account holder', false !== strpos( $log, 'sam@example.org' ), true );
check( 'names the dish', false !== strpos( $log, 'Roast dinner' ), true );
check( 'names who it is for', false !== strpos( $log, 'Ada (Youth)' ), true );
check( 'carries the line note', false !== strpos( $log, 'extra gravy' ), true );
check( 'carries the order note', false !== strpos( $log, 'Ring the bell' ), true );
check( 'and states the total', false !== strpos( $log, Money::format( 1100 ) ), true );

unlink( $logPath );
Notifications::accountApproved( $memberId );
check( 'approval tells them they can order', false !== strpos( (string) file_get_contents( $logPath ), 'approved' ), true );

unlink( $logPath );
$registered = Users::create( 'newcomer@example.org', 'a-fresh-password', 'New Comer', '' );
Notifications::newRegistration( $registered );

$log = (string) file_get_contents( $logPath );

check( 'organisers hear about a sign-up', false !== strpos( $log, 'admin@example.org' ), true );
check( 'and are told who', false !== strpos( $log, 'New Comer' ), true );

/* ------------------------------------------------------------------ */

echo "\nCancelling says what happened to the money\n";

check( 'an uncancelled order has no refund entry', Orders::refundEntryFor( $mailOrder ), null );

$balanceBeforeCancel = Wallet::balance( $memberId );

unlink( $logPath );
Orders::cancel( $mailOrder, $adminId );
Notifications::orderCancelled( $mailOrder );

$refundEntry = Orders::refundEntryFor( $mailOrder );

check( 'a wallet order leaves a refund entry', null !== $refundEntry, true );
check( 'for the order total', (int) $refundEntry['delta_cents'], 1100 );
check( 'and the balance goes back up', Wallet::balance( $memberId ), $balanceBeforeCancel + 1100 );

$log = (string) file_get_contents( $logPath );

check( 'the email says it was cancelled', false !== strpos( $log, 'has been cancelled' ), true );
check( 'names the refunded amount', false !== strpos( $log, Money::format( 1100 ) . ' has gone back into your wallet' ), true );
check( 'and states the new balance', false !== strpos( $log, 'Your balance is now ' . Money::format( Wallet::balance( $memberId ) ) ), true );

echo "\nA cash order that was never collected refunds nothing\n";

$cashUnpaid = Orders::place(
	$memberId,
	array( array( 'item_id' => $kRcc, 'qty' => 1, 'person_name' => 'Bo' ) ),
	null,
	'',
	false,
	'cod'
);

$balanceBeforeCancel = Wallet::balance( $memberId );

unlink( $logPath );
Orders::cancel( $cashUnpaid, $adminId );
Notifications::orderCancelled( $cashUnpaid );

check( 'no refund entry is written', Orders::refundEntryFor( $cashUnpaid ), null );
check( 'and the wallet is untouched', Wallet::balance( $memberId ), $balanceBeforeCancel );

$log = (string) file_get_contents( $logPath );

check( 'the email says nothing was charged', false !== strpos( $log, 'nothing to refund' ), true );
check( 'and does not claim a wallet refund', false !== strpos( $log, 'gone back into your wallet' ), false );

echo "\nA cash order already collected sends them to an organiser\n";

$cashPaid = Orders::place(
	$memberId,
	array( array( 'item_id' => $kRcc, 'qty' => 1, 'person_name' => 'Cass' ) ),
	null,
	'',
	false,
	'cod'
);

Orders::markPaid( $cashPaid );

$balanceBeforeCancel = Wallet::balance( $memberId );

unlink( $logPath );
Orders::cancel( $cashPaid, $adminId );
Notifications::orderCancelled( $cashPaid );

check( 'still no refund entry', Orders::refundEntryFor( $cashPaid ), null );

// The system never held this money, so inventing a wallet credit would be
// making up a payment that never went through it.
check( 'and no wallet credit is invented', Wallet::balance( $memberId ), $balanceBeforeCancel );

$log = (string) file_get_contents( $logPath );

check( 'the email points them at an organiser', false !== strpos( $log, 'speak to an organiser' ), true );
check( 'naming what they paid', false !== strpos( $log, Money::format( 1000 ) ), true );

/* ------------------------------------------------------------------ */

echo "\nThe grid builder fills a month\n";

Settings::setMany(
	array(
		'active_mode'     => Schedule::MODE_PLANNED,
		'service_weekday' => '0',
		'default_price'   => '9.50',
	)
);

freeze( '2026-11-03 09:00' ); // Tuesday. The 1st is behind us, the rest ahead.

check(
	'the month offers its Sundays',
	Schedule::serviceDatesInMonth( 2026, 11 ),
	array( '2026-11-01', '2026-11-08', '2026-11-15', '2026-11-22', '2026-11-29' )
);

$tally = MenuBuilder::save(
	array(
		'2026-11-01' => array( $marine => 'Should be ignored' ),
		'2026-11-08' => array( $marine => 'Cottage pie', $rcc => 'Dal and rice' ),
		'2026-11-15' => array( $marine => 'Leek soup' ),
	)
);

check( 'three dishes are created', $tally['created'], 3 );
check( 'a day already served is left alone', Menu::itemBySlot( '2026-11-01', $marine ), null );
check( 'the dish lands in its cell', Menu::itemBySlot( '2026-11-08', $marine )['name'], 'Cottage pie' );
check( 'the other campus too', Menu::itemBySlot( '2026-11-08', $rcc )['name'], 'Dal and rice' );
check( 'priced from the default', Menu::itemBySlot( '2026-11-08', $marine )['price_cents'], 950 );
check( 'and published', Menu::itemBySlot( '2026-11-08', $marine )['status'], 'published' );

echo "\nA repeat inherits what was set last time\n";

$pie = Menu::itemBySlot( '2026-11-08', $marine );

Menu::save(
	array_merge( $pie, array( 'price_cents' => 1275, 'description' => 'With mash' ) ),
	(int) $pie['id']
);

MenuBuilder::save( array( '2026-11-22' => array( $marine => 'Cottage pie' ) ) );

$repeat = Menu::itemBySlot( '2026-11-22', $marine );

check( 'the price carries across', $repeat['price_cents'], 1275 );
check( 'and the description', $repeat['description'], 'With mash' );
check( 'without touching the original', (int) Menu::itemBySlot( '2026-11-08', $marine )['id'], (int) $pie['id'] );

echo "\nSaving is idempotent, and edits stay in place\n";

check(
	'saving the same grid changes nothing',
	MenuBuilder::save( array( '2026-11-08' => array( $marine => 'Cottage pie' ) ) ),
	array( 'created' => 0, 'updated' => 0, 'drafted' => 0 )
);

check(
	'renaming counts as an update',
	MenuBuilder::save( array( '2026-11-08' => array( $marine => 'Shepherd pie' ) ) )['updated'],
	1
);

check( 'and edits the same row', (int) Menu::itemBySlot( '2026-11-08', $marine )['id'], (int) $pie['id'] );

echo "\nClearing a cell drafts rather than deletes\n";

$soup = Menu::itemBySlot( '2026-11-15', $marine );

check( 'clearing drafts it', MenuBuilder::save( array( '2026-11-15' => array( $marine => '' ) ) )['drafted'], 1 );
check( 'the dish still exists', (int) Menu::itemBySlot( '2026-11-15', $marine )['id'], (int) $soup['id'] );
check( 'as a draft', Menu::itemBySlot( '2026-11-15', $marine )['status'], 'draft' );
check( 'and clearing again does nothing', MenuBuilder::save( array( '2026-11-15' => array( $marine => '' ) ) )['drafted'], 0 );
check( 'while retyping it republishes', MenuBuilder::save( array( '2026-11-15' => array( $marine => 'Leek soup' ) ) )['updated'], 1 );

echo "\nA dish unpublished on its own screen stays unpublished\n";

/*
 * The trap: saving the grid forces every named cell to published, so a dish
 * somebody deliberately unpublished would come back the next time anyone edited
 * a different week of the same month. What stops it is the builder rendering an
 * unpublished dish as a placeholder rather than a value, so its cell submits
 * empty -- these assertions are about the empty cell leaving it alone.
 */
$soup = Menu::item( (int) Menu::itemBySlot( '2026-11-15', $marine )['id'] );

Menu::save( array_merge( $soup, array( 'status' => 'draft' ) ), (int) $soup['id'] );

check( 'it starts unpublished', Menu::itemBySlot( '2026-11-15', $marine )['status'], 'draft' );

// A month save that touches a different week. The unpublished cell arrives
// empty, exactly as the grid renders it.
$tally = MenuBuilder::save(
	array(
		'2026-11-15' => array( $marine => '' ),
		'2026-11-08' => array( $marine => 'Shepherd pie' ),
	)
);

check( 'editing elsewhere in the month changes nothing else', $tally['updated'], 0 );
check( 'and it is still unpublished', Menu::itemBySlot( '2026-11-15', $marine )['status'], 'draft' );
check( 'nothing was drafted twice', $tally['drafted'], 0 );

// And the deliberate way back still works.
check(
	'typing the name back republishes it',
	MenuBuilder::save( array( '2026-11-15' => array( $marine => 'Leek soup' ) ) )['updated'],
	1
);

check( 'published again', Menu::itemBySlot( '2026-11-15', $marine )['status'], 'published' );

echo "\nManual mode takes a window per row\n";

Settings::set( 'active_mode', Schedule::MODE_MANUAL );

MenuBuilder::save(
	array( '2026-11-29' => array( $marine => 'Winter stew' ) ),
	array( '2026-11-29' => '2026-11-24T09:00' ),
	array( '2026-11-29' => '2026-11-28T17:00' )
);

$stew = Menu::itemBySlot( '2026-11-29', $marine );

check( 'the from is stored', $stew['open_from'], '2026-11-24T09:00' );
check( 'the until is stored', $stew['close_at'], '2026-11-28T17:00' );
check( 'and it resolves as a manual window', $stew['window']->source, Schedule::MODE_MANUAL );
check( 'open inside it', $stew['window']->isOrderable( new DateTimeImmutable( '2026-11-26 12:00', Schedule::timezone() ) ), true );
check( 'shut before it', $stew['window']->isOrderable( new DateTimeImmutable( '2026-11-23 12:00', Schedule::timezone() ) ), false );

echo "\nOn-publish mode collapses to one row\n";

Settings::set( 'active_mode', Schedule::MODE_ON_PUBLISH );
freeze( '2026-12-01 14:00' ); // Tuesday.

check( 'publishing the row creates a dish', MenuBuilder::save( array( $marine => 'Festive roast' ) )['created'], 1 );

$festive = array_values( array_filter( Menu::allItems(), static fn( $i ) => 'Festive roast' === $i['name'] ) );

check( 'stamped as opened', '' !== $festive[0]['opened_at'], true );
check( 'and orderable straight away', $festive[0]['window']->isOrderable(), true );
check( 'running to the next cutoff', $festive[0]['window']->closeAt->format( 'D Y-m-d H:i' ), 'Sat 2026-12-05 13:00' );

Settings::set( 'active_mode', Schedule::MODE_PLANNED );

/* ------------------------------------------------------------------ */

echo "\nA second schedule runs on its own rhythm\n";

check( 'there is a default schedule to begin with', array_keys( Schedules::all() ), array( Schedules::DEFAULT_ID ) );
check( 'whose rules come from settings', Schedules::rulesFor( Schedules::DEFAULT_ID )['open_days_before'], 5 );

$supperId = Schedules::save(
	array(
		'name'              => 'Wednesday supper',
		'mode'              => Schedule::MODE_PLANNED,
		'service_weekday'   => 3,
		'open_days_before'  => 2,
		'open_time'         => '08:00',
		'close_days_before' => 1,
		'close_time'        => '18:00',
		'show_on_front'     => true,
	)
);

check( 'the named schedule is listed', Schedules::named()[ $supperId ]['name'], 'Wednesday supper' );
check( 'and joins the default', count( Schedules::all() ), 2 );
check( 'an unknown id falls back to the default', Schedules::rulesFor( 9999 )['id'], Schedules::DEFAULT_ID );

$wednesdays = Schedule::serviceDatesInMonth( 2027, 1, 3 );

check( 'the weekday argument picks Wednesdays', count( $wednesdays ) >= 4, true );

$supperDate = $wednesdays[1];

check( 'and they really are Wednesdays', ( new DateTimeImmutable( $supperDate ) )->format( 'l' ), 'Wednesday' );

MenuBuilder::save( array( $supperDate => array( $rcc => 'Wednesday hotpot' ) ), array(), array(), $supperId );

$hotpot = Menu::itemBySlot( $supperDate, $rcc, $supperId );

check( 'the dish is filed under its schedule', (int) $hotpot['schedule_id'], $supperId );
check(
	'and opens by that schedule, not the default',
	$hotpot['window']->openFrom->format( 'D H:i' ),
	'Mon 08:00'
);
check( 'closing by it too', $hotpot['window']->closeAt->format( 'D H:i' ), 'Tue 18:00' );

echo "\nTwo schedules can share a date and a location without colliding\n";

MenuBuilder::save( array( $supperDate => array( $rcc => 'Default day dish' ) ), array(), array(), Schedules::DEFAULT_ID );

$onDefault = Menu::itemBySlot( $supperDate, $rcc, Schedules::DEFAULT_ID );

check( 'the default cell holds its own dish', $onDefault['name'], 'Default day dish' );
check( 'the named cell is untouched', Menu::itemBySlot( $supperDate, $rcc, $supperId )['name'], 'Wednesday hotpot' );
check( 'and they are different rows', (int) $onDefault['id'] !== (int) $hotpot['id'], true );

// The default is planned with a Sunday service weekday, so the same calendar
// day resolves to a different window under each schedule.
check(
	'each resolves by its own rules',
	$onDefault['window']->openFrom->format( 'H:i' ) !== $hotpot['window']->openFrom->format( 'H:i' ),
	true
);

echo "\nLocations can be limited per schedule\n";

check( 'with none chosen a schedule serves them all', count( Schedules::locationsFor( $supperId ) ), 3 );

Schedules::setLocations( $supperId, array( $rcc ) );

check( 'choosing some narrows it', count( Schedules::locationsFor( $supperId ) ), 1 );
check( 'to the right one', Schedules::locationsFor( $supperId )[0]['name'], 'RCC' );
check( 'while the default still serves all', count( Schedules::locationsFor( Schedules::DEFAULT_ID ) ), 3 );

echo "\nThe front page honours the show-on-front switch\n";

check( 'both are on the front by default', count( Schedules::onFront() ), 2 );

Schedules::save( array_merge( Schedules::named()[ $supperId ], array( 'show_on_front' => false ) ), $supperId );

check( 'turning one off drops it', array_keys( Schedules::onFront() ), array( Schedules::DEFAULT_ID ) );

Settings::set( 'default_show_on_front', 'no' );
check( 'and the default can be hidden too', Schedules::onFront(), array() );
Settings::set( 'default_show_on_front', 'yes' );

echo "\nRemoving a schedule leaves its dishes resolvable\n";

Schedules::delete( $supperId );

check( 'it is gone from the list', count( Schedules::all() ), 1 );

$orphaned = Menu::item( (int) $hotpot['id'] );

check( 'its dish survives', $orphaned['name'], 'Wednesday hotpot' );
check( 'detached to the default', null === $orphaned['schedule_id'], true );
check( 'and still resolves a window', $orphaned['window']->source, Schedule::MODE_PLANNED );

/* ------------------------------------------------------------------ */

echo "\nCorrecting a dish tells whoever already ordered it\n";

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

$sundays    = Schedule::serviceDatesInMonth( 2027, 2, 0 );
$changeDate = $sundays[1];

// The Wednesday before, so the window is open and orders can be placed.
freeze( ( new DateTimeImmutable( $changeDate, Schedule::timezone() ) )->modify( '-4 days' )->format( 'Y-m-d' ) . ' 10:00' );

$correctedId = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'Chicken curry',
		'description'  => 'Mild',
		'price_cents'  => 1000,
		'service_date' => $changeDate,
		'status'       => 'published',
	)
);

$eater = Users::create( 'eater@example.org', 'a-good-password', 'Eve Eater', '', Users::ROLE_MEMBER, true );
$other = Users::create( 'other@example.org', 'a-good-password', 'Otto Other', '', Users::ROLE_MEMBER, true );

Wallet::credit( $eater, 10000, Wallet::KIND_TOPUP, 'float' );
Wallet::credit( $other, 10000, Wallet::KIND_TOPUP, 'float' );

Orders::place( $eater, array( array( 'item_id' => $correctedId, 'qty' => 2, 'person_name' => 'Eve', 'group_name' => 'Seniors' ) ) );
Orders::place( $other, array( array( 'item_id' => $correctedId, 'qty' => 1, 'person_name' => 'Otto' ) ) );

check( 'two people are on the hook', count( MenuChanges::affected( $correctedId ) ), 2 );

$current = Menu::item( $correctedId );

unlink( $logPath );
MenuChanges::forget();

Menu::save( array_merge( $current, array( 'name' => 'Chicken korma' ) ), $correctedId );

$log = (string) file_get_contents( $logPath );

check( 'both are emailed', MenuChanges::totalNotified(), 2 );
check( 'the first of them', false !== strpos( $log, 'eater@example.org' ), true );
check( 'and the second', false !== strpos( $log, 'other@example.org' ), true );
check( 'the mail says what it was', false !== strpos( $log, 'was: Chicken curry' ), true );
check( 'and what it is now', false !== strpos( $log, 'now: Chicken korma' ), true );
check( 'and repeats what they ordered', false !== strpos( $log, '2 x Eve (Seniors)' ), true );
check( 'and what they were charged', false !== strpos( $log, 'You were charged ' . Money::format( 2000 ) ), true );

echo "\nThe rename reaches the cook list, the price never does\n";

$lines = Orders::lineItemsFiltered( array( 'from' => $changeDate, 'to' => $changeDate ) );

// Otherwise the kitchen sees the old name for anyone who ordered before the
// correction and the new one for everyone after -- two dishes, one pot.
check( 'existing orders show the corrected name', array_unique( array_column( $lines, 'item_name' ) ), array( 'Chicken korma' ) );
check( 'and the price they paid is untouched', (int) $lines[0]['unit_price_cents'], 1000 );

echo "\nEvery correction notifies, not just the first\n";

unlink( $logPath );
MenuChanges::forget();

Menu::save( array_merge( Menu::item( $correctedId ), array( 'name' => 'Chicken madras' ) ), $correctedId );

check( 'a second correction mails them again', MenuChanges::totalNotified(), 2 );

unlink( $logPath );
MenuChanges::forget();

Menu::save( array_merge( Menu::item( $correctedId ), array( 'name' => 'Chicken jalfrezi' ) ), $correctedId );

check( 'and so does a third', MenuChanges::totalNotified(), 2 );
check( 'the cook list keeps up', Orders::lineItemsFiltered( array( 'from' => $changeDate, 'to' => $changeDate ) )[0]['item_name'], 'Chicken jalfrezi' );

echo "\nA price change is announced but never re-charged\n";

$before = Wallet::balance( $eater );

unlink( $logPath );
MenuChanges::forget();

Menu::save( array_merge( Menu::item( $correctedId ), array( 'price_cents' => 1400 ) ), $correctedId );

$log = (string) file_get_contents( $logPath );

check( 'they are told', MenuChanges::totalNotified(), 2 );
check( 'with the old price', false !== strpos( $log, 'was: ' . Money::format( 1000 ) ), true );
check( 'and the new one', false !== strpos( $log, 'now: ' . Money::format( 1400 ) ), true );
check( 'reassured nothing more is taken', false !== strpos( $log, 'nothing further will be taken' ), true );
check( 'and nothing is', Wallet::balance( $eater ), $before );

echo "\nSilent changes stay silent\n";

MenuChanges::forget();
Menu::save( array_merge( Menu::item( $correctedId ), array( 'capacity' => 40 ) ), $correctedId );
check( 'changing the portion limit tells nobody', MenuChanges::totalNotified(), 0 );

MenuChanges::forget();
Menu::save( array_merge( Menu::item( $correctedId ), array( 'status' => 'draft' ) ), $correctedId );
check( 'nor does drafting it', MenuChanges::totalNotified(), 0 );

MenuChanges::forget();
Menu::save( array_merge( Menu::item( $correctedId ), array( 'status' => 'published' ) ), $correctedId );
check( 'nor saving with nothing altered', MenuChanges::totalNotified(), 0 );

$untouched = Menu::save(
	array(
		'location_id'  => $rcc,
		'name'         => 'Nobody ordered this',
		'price_cents'  => 900,
		'service_date' => $changeDate,
		'status'       => 'published',
	)
);

MenuChanges::forget();
Menu::save( array_merge( Menu::item( $untouched ), array( 'name' => 'Still nobody' ) ), $untouched );
check( 'a dish with no orders tells nobody', MenuChanges::totalNotified(), 0 );

echo "\nCancelled orders are left out of it\n";

$cancelledBy = Users::create( 'gone@example.org', 'a-good-password', 'Gus Gone', '', Users::ROLE_MEMBER, true );
Wallet::credit( $cancelledBy, 5000, Wallet::KIND_TOPUP, 'float' );

$goneOrder = Orders::place( $cancelledBy, array( array( 'item_id' => $correctedId, 'qty' => 1 ) ) );
Orders::cancel( $goneOrder, $adminId );

check( 'they drop off the affected list', count( MenuChanges::affected( $correctedId ) ), 2 );

MenuChanges::forget();
Menu::save( array_merge( Menu::item( $correctedId ), array( 'name' => 'Chicken pasanda' ) ), $correctedId );

check( 'so only the live orders are mailed', MenuChanges::totalNotified(), 2 );
check( 'and their line keeps its own name', Orders::lines( $goneOrder )[0]['item_name'], 'Chicken jalfrezi' );

echo "\nAn organiser can correct something quietly\n";

unlink( $logPath );
MenuChanges::forget();
MenuChanges::setNotify( false );

Menu::save( array_merge( Menu::item( $correctedId ), array( 'name' => 'Chicken rogan josh' ) ), $correctedId );

check( 'nobody is emailed', MenuChanges::totalNotified(), 0 );
check( 'but they are still counted', MenuChanges::totalSuppressed(), 2 );
check( 'and no message is written at all', is_file( $logPath ), false );

// Silencing the email must not silence the data fix, or the kitchen ends up
// with two names for one pot.
check(
	'the rename still reaches the cook list',
	Orders::lineItemsFiltered( array( 'from' => $changeDate, 'to' => $changeDate ) )[0]['item_name'],
	'Chicken rogan josh'
);

check( 'notification is off while it is off', MenuChanges::willNotify(), false );
MenuChanges::forget();
check( 'and forgetting turns it back on', MenuChanges::willNotify(), true );

// The opt-out is per save, so the next one speaks up again unless told not to.
Menu::save( array_merge( Menu::item( $correctedId ), array( 'name' => 'Chicken dhansak' ) ), $correctedId );
check( 'so the next correction notifies again', MenuChanges::totalNotified(), 2 );

/* ------------------------------------------------------------------ */

echo "\nThe three built-in fields cannot be removed\n";

check( 'they are seeded', array_keys( MenuFields::definitions() ), array( 'person_name', 'group_name', 'note' ) );
check( 'and marked built in', MenuFields::definitions()['person_name']['builtin'], true );

$personId = MenuFields::definitions()['person_name']['id'];

check( 'deleting one is refused', MenuFields::delete( $personId ), false );
check( 'and it survives', isset( MenuFields::definitions()['person_name'] ), true );

// Hiding is the supported way to get them off the form.
MenuFields::save( array( 'label' => 'Name on this meal', 'is_shown' => false ), $personId );

check( 'but it can be hidden', MenuFields::definitions()['person_name']['shown'], false );
check( 'without disappearing', isset( MenuFields::definitions()['person_name'] ), true );

MenuFields::save( array( 'label' => 'Name on this meal', 'is_shown' => true, 'is_required' => true ), $personId );

check( 'a built-in keeps its type when saved', MenuFields::definitions()['person_name']['type'], 'text' );
check( 'and a new field cannot shadow one', MenuFields::makeKey( 'Note' ), 'note_custom' );

echo "\nOrganisers can add their own\n";

// field_key is posted as an empty string by the form rather than omitted, so
// the blank case has to derive from the label too -- ?? alone does not.
$allergyId = MenuFields::save(
	array(
		'field_key'   => '',
		'label'       => 'Allergies',
		'type'        => 'text',
		'placeholder' => 'e.g. nuts',
		'is_shown'    => true,
	)
);

check( 'the key comes from the label', isset( MenuFields::definitions()['allergies'] ), true );
check( 'and it is not built in', MenuFields::definitions()['allergies']['builtin'], false );

check_throws(
	'a clashing label is refused',
	static fn() => MenuFields::save( array( 'label' => 'Allergies', 'type' => 'text' ) ),
	'already a field'
);

$spiceId = MenuFields::save(
	array(
		'label'    => 'Spice level',
		'type'     => 'select',
		'options'  => "Mild\nMedium\nHot",
		'is_shown' => true,
	)
);

check( 'a list field keeps its choices', MenuFields::definitions()['spice_level']['options'], array( 'Mild', 'Medium', 'Hot' ) );

echo "\nAnswers are cleaned by the field's own type\n";

$spice = MenuFields::definitions()['spice_level'];

check( 'a listed choice passes', MenuFields::sanitiseValue( $spice, 'Medium' ), 'Medium' );
check( 'case is forgiven', MenuFields::sanitiseValue( $spice, 'medium' ), 'Medium' );

// A select is a convenience on the form, not a promise about what arrives.
check( 'an invented choice is dropped', MenuFields::sanitiseValue( $spice, 'Nuclear' ), '' );

$groupField = MenuFields::definitions()['group_name'];

check( 'a group is checked against the managed list', MenuFields::sanitiseValue( $groupField, 'Made up' ), '' );
check( 'and a real one passes', MenuFields::sanitiseValue( $groupField, 'Seniors' ), 'Seniors' );

echo "\nVisibility resolves site, then schedule, then dish\n";

$fieldSchedule = Schedules::save(
	array(
		'name'        => 'Field test schedule',
		'mode'        => Schedule::MODE_PLANNED,
		'field_rules' => MenuFields::encodeRules( array( 'allergies' => array( 'shown' => false ) ) ),
	)
);

$plainDish = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'Field test dish',
		'price_cents'  => 1000,
		'service_date' => $changeDate,
		'status'       => 'published',
	)
);

check( 'the site default shows it', isset( MenuFields::visibleFor( Menu::item( $plainDish ) )['allergies'] ), true );

$onSchedule = Menu::save(
	array(
		'location_id'  => $rcc,
		'name'         => 'Schedule test dish',
		'price_cents'  => 1000,
		'service_date' => $changeDate,
		'status'       => 'published',
		'schedule_id'  => $fieldSchedule,
	)
);

check( 'the schedule can hide it', isset( MenuFields::visibleFor( Menu::item( $onSchedule ) )['allergies'] ), false );

Menu::save(
	array_merge(
		Menu::item( $onSchedule ),
		array( 'field_rules' => MenuFields::encodeRules( array( 'allergies' => array( 'shown' => true, 'required' => true ) ) ) )
	),
	$onSchedule
);

$resolved = MenuFields::visibleFor( Menu::item( $onSchedule ) );

check( 'and the dish can bring it back', isset( $resolved['allergies'] ), true );
check( 'as required', $resolved['allergies']['required'], true );
check( 'while its schedule sibling stays hidden', isset( MenuFields::visibleFor( Menu::item( $plainDish ) )['spice_level'] ), true );

echo "\nOnly visible fields are read from a form\n";

$hiddenOnDish = Menu::save(
	array(
		'location_id'  => $marine,
		'name'         => 'No spice here',
		'price_cents'  => 1000,
		'service_date' => $changeDate,
		'status'       => 'published',
		'field_rules'  => MenuFields::encodeRules( array( 'spice_level' => array( 'shown' => false ) ) ),
	)
);

$posted = array(
	'person_name' => 'Wanda',
	'spice_level' => 'Hot',
	'allergies'   => 'Peanuts',
);

$collected = MenuFields::collect( Menu::item( $hiddenOnDish ), $posted );

check( 'a visible field is taken', $collected['allergies'], 'Peanuts' );
// Hand-posting a hidden field must not smuggle a value into the kitchen list.
check( 'a hidden one is ignored entirely', isset( $collected['spice_level'] ), false );

check(
	'a blank required field is reported',
	MenuFields::missingRequired( Menu::item( $onSchedule ), array( 'person_name' => 'X' ) ),
	array( 'Allergies' )
);

check(
	'name falls back to the account',
	MenuFields::collect( Menu::item( $plainDish ), array(), array( 'name' => 'Fallback Person', 'group_name' => '' ) )['person_name'],
	'Fallback Person'
);

echo "\nAnswers travel with the order and reach the kitchen\n";

$fieldEater = Users::create( 'fields@example.org', 'a-good-password', 'Fay Fields', '', Users::ROLE_MEMBER, true );
Wallet::credit( $fieldEater, 5000, Wallet::KIND_TOPUP, 'float' );

$fieldOrder = Orders::place(
	$fieldEater,
	array(
		array(
			'item_id'     => $plainDish,
			'qty'         => 1,
			'person_name' => 'Fay',
			'extra'       => array( 'allergies' => 'Peanuts', 'spice_level' => 'Mild' ),
		),
	)
);

$storedLine = Orders::lines( $fieldOrder )[0];

check( 'the answers are stored', MenuFields::describeExtras( $storedLine['extra_fields'] ), 'Allergies: Peanuts · Spice level: Mild' );

$kitchenRows = array_values(
	array_filter(
		Orders::lineItemsFiltered( array( 'from' => $changeDate, 'to' => $changeDate ) ),
		static fn( $r ) => 'Field test dish' === $r['item_name']
	)
);

check( 'and reach the kitchen table', MenuFields::describeExtras( $kitchenRows[0]['extra_fields'] ), 'Allergies: Peanuts · Spice level: Mild' );

// A deleted field must not take its answers with it.
MenuFields::delete( $spiceId );

check( 'removing a field is allowed', isset( MenuFields::definitions()['spice_level'] ), false );
check(
	'and past answers still read, by key',
	false !== strpos( MenuFields::describeExtras( $storedLine['extra_fields'] ), 'Spice level: Mild' ),
	true
);

finish();
