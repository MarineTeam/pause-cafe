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

finish();
