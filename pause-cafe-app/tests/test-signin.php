<?php
/**
 * Signing in: token verification, one-time links, and what an outside provider
 * is and is not allowed to decide.
 *
 * The JWT section signs real tokens with a real key and verifies them through
 * the same code the live path uses, so the crypto is actually exercised rather
 * than assumed. Everything a provider would be reached over the network for is
 * faked; what is under test is what this site does with the answer.
 *
 * Run:  php -d extension=php_pdo_sqlite tests/test-signin.php
 */

declare(strict_types=1);

require __DIR__ . '/harness.php';

fresh_database();

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Identities;
use PauseCafe\LoginAttempts;
use PauseCafe\LoginTokens;
use PauseCafe\Notifications;
use PauseCafe\Mail\LogTransport;
use PauseCafe\Mailer;
use PauseCafe\Schedule;
use PauseCafe\Settings;
use PauseCafe\SignIn;
use PauseCafe\SignIn\Jwt;
use PauseCafe\SignIn\MagicLinkMethod;
use PauseCafe\SignIn\Method;
use PauseCafe\SignIn\Outcome;
use PauseCafe\SignIn\PasswordMethod;
use PauseCafe\SignIn\Profile;
use PauseCafe\Users;
use PauseCafe\Wallet;

$logPath = dirname( __DIR__ ) . '/data/test-mail.log';

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

LogTransport::configure( $logPath );
Settings::set( 'mail_transport', 'log' );

// A fixed clock, so "expired" means something specific.
Schedule::freeze( new DateTimeImmutable( '2026-08-13 09:00:00', new DateTimeZone( 'UTC' ) ) );

/*
 * A pinned address, as a properly set-up install has. Sign-in links and
 * providers both refuse to run without one, so everything below assumes it --
 * and the last section here takes it away again to prove the refusal.
 */
Notifications::configure( 'https://lunch.example', true );

/* =========================================================================
 * Identity tokens
 * ====================================================================== */

echo "Verifying a signed identity token\n";

$fixtures = require __DIR__ . '/fixtures-keys.php';

$keyPair = openssl_pkey_get_private( $fixtures['k1'] );
$details = openssl_pkey_get_details( $keyPair );

/** Builds the key set a provider would publish, from the key we just made. */
$jwksFor = static function ( array $details, string $kid ): array {
	return array(
		'keys' => array(
			array(
				'kty' => 'RSA',
				'use' => 'sig',
				'kid' => $kid,
				'n'   => Jwt::encode( $details['rsa']['n'] ),
				'e'   => Jwt::encode( $details['rsa']['e'] ),
			),
		),
	);
};

/** Signs a token the way a provider would. */
$sign = static function ( array $claims, $key, string $kid = 'k1', string $alg = 'RS256' ): string {
	$header  = Jwt::encode( (string) json_encode( array( 'alg' => $alg, 'typ' => 'JWT', 'kid' => $kid ) ) );
	$payload = Jwt::encode( (string) json_encode( $claims ) );

	openssl_sign( $header . '.' . $payload, $signature, $key, OPENSSL_ALGO_SHA256 );

	return $header . '.' . $payload . '.' . Jwt::encode( $signature );
};

$jwks = $jwksFor( $details, 'k1' );
$now  = 1786000000;

$goodClaims = array(
	'iss'            => 'https://church.eu.auth0.com/',
	'aud'            => 'client-abc',
	'sub'            => 'auth0|12345',
	'email'          => 'ruth@example.org',
	'email_verified' => true,
	'name'           => 'Ruth Okafor',
	'nonce'          => 'nonce-xyz',
	'exp'            => $now + 300,
	'iat'            => $now,
);

$expect = array(
	'issuer'   => 'https://church.eu.auth0.com/',
	'audience' => 'client-abc',
	'nonce'    => 'nonce-xyz',
);

$verified = Jwt::verify( $sign( $goodClaims, $keyPair ), $jwks, $expect, $now );

check( 'a properly signed token is accepted', $verified['sub'], 'auth0|12345' );
check( 'and its claims come back', $verified['email'], 'ruth@example.org' );

echo "\nA token that is wrong in any way is refused\n";

// Tamper with the payload, leaving the signature alone.
$tampered = $sign( array_merge( $goodClaims, array( 'email' => 'ruth@example.org' ) ), $keyPair );
$parts    = explode( '.', $tampered );
$parts[1] = Jwt::encode(
	(string) json_encode( array_merge( $goodClaims, array( 'email' => 'treasurer@example.org' ) ) )
);

check_throws(
	'an edited payload breaks the signature',
	static fn() => Jwt::verify( implode( '.', $parts ), $jwks, $expect, $now ),
	'signature'
);

$otherKey = openssl_pkey_get_private( $fixtures['k2'] );

check_throws(
	'a token signed with somebody else’s key is refused',
	static fn() => Jwt::verify( $sign( $goodClaims, $otherKey ), $jwks, $expect, $now ),
	'signature'
);

/*
 * The two classic ways of getting a verifier to accept a forgery. Both have to
 * fail on the algorithm, before any signature is even looked at.
 */
$unsignedHeader  = Jwt::encode( (string) json_encode( array( 'alg' => 'none', 'typ' => 'JWT' ) ) );
$unsignedPayload = Jwt::encode( (string) json_encode( $goodClaims ) );

check_throws(
	'an unsigned token is refused',
	static fn() => Jwt::verify( $unsignedHeader . '.' . $unsignedPayload . '.', $jwks, $expect, $now ),
	'not accepted'
);

$publicKey = $details['key'];
$hsHeader  = Jwt::encode( (string) json_encode( array( 'alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'k1' ) ) );
$hsPayload = Jwt::encode( (string) json_encode( $goodClaims ) );
$hsToken   = $hsHeader . '.' . $hsPayload . '.'
	. Jwt::encode( hash_hmac( 'sha256', $hsHeader . '.' . $hsPayload, $publicKey, true ) );

check_throws(
	'a token switched to HMAC over the public key is refused',
	static fn() => Jwt::verify( $hsToken, $jwks, $expect, $now ),
	'not accepted'
);

check_throws(
	'a token for another application is refused',
	static fn() => Jwt::verify(
		$sign( array_merge( $goodClaims, array( 'aud' => 'someone-elses-client' ) ), $keyPair ),
		$jwks,
		$expect,
		$now
	),
	'different application'
);

check_throws(
	'a token from another issuer is refused',
	static fn() => Jwt::verify(
		$sign( array_merge( $goodClaims, array( 'iss' => 'https://evil.example/' ) ), $keyPair ),
		$jwks,
		$expect,
		$now
	),
	'wrong place'
);

check_throws(
	'a token replayed from an earlier sign-in is refused',
	static fn() => Jwt::verify(
		$sign( array_merge( $goodClaims, array( 'nonce' => 'a-previous-nonce' ) ), $keyPair ),
		$jwks,
		$expect,
		$now
	),
	'does not match'
);

check_throws(
	'an expired token is refused',
	static fn() => Jwt::verify( $sign( $goodClaims, $keyPair ), $jwks, $expect, $now + 3600 ),
	'expired'
);

check_throws(
	'a token signed with an unpublished key is refused',
	static fn() => Jwt::verify( $sign( $goodClaims, $keyPair, 'rotated-away' ), $jwks, $expect, $now ),
	'does not know'
);

check_throws(
	'nonsense is refused',
	static fn() => Jwt::verify( 'not.a.token', $jwks, $expect, $now ),
	''
);

echo "\nSmall clock differences are tolerated\n";

check(
	'a token that expired one second ago still works',
	Jwt::verify( $sign( $goodClaims, $keyPair ), $jwks, $expect, $now + 301 )['sub'],
	'auth0|12345'
);

check_throws(
	'one that expired ten minutes ago does not',
	static fn() => Jwt::verify( $sign( $goodClaims, $keyPair ), $jwks, $expect, $now + 900 ),
	'expired'
);

echo "\nKey rotation\n";

$secondKey     = openssl_pkey_get_private( $fixtures['k3'] );
$secondDetails = openssl_pkey_get_details( $secondKey );

$rotated = array(
	'keys' => array_merge(
		$jwksFor( $details, 'k1' )['keys'],
		$jwksFor( $secondDetails, 'k2' )['keys']
	),
);

check(
	'the old key still verifies while both are published',
	Jwt::verify( $sign( $goodClaims, $keyPair, 'k1' ), $rotated, $expect, $now )['sub'],
	'auth0|12345'
);

check(
	'and so does the new one',
	Jwt::verify( $sign( $goodClaims, $secondKey, 'k2' ), $rotated, $expect, $now )['sub'],
	'auth0|12345'
);

check_throws(
	'but a token claiming the wrong key id is refused',
	static fn() => Jwt::verify( $sign( $goodClaims, $keyPair, 'k2' ), $rotated, $expect, $now ),
	'signature'
);

/* =========================================================================
 * One-time sign-in links
 * ====================================================================== */

echo "\nA sign-in link works once\n";

$member = Users::create( 'ruth@example.org', 'a-good-password', 'Ruth Okafor', 'Choir', Users::ROLE_MEMBER, true );

$token = LoginTokens::issue( $member, 15 );

check( 'the plaintext is never stored', LoginTokens::consume( 'not-the-token' ), null );

$signedIn = LoginTokens::consume( $token );

check( 'the link signs the right person in', (int) $signedIn['id'], $member );
check( 'and cannot be used again', LoginTokens::consume( $token ), null );

echo "\nAnd only for as long as it says\n";

$shortLived = LoginTokens::issue( $member, 15 );

Schedule::freeze( new DateTimeImmutable( '2026-08-13 09:14:30', new DateTimeZone( 'UTC' ) ) );
check( 'still good at fourteen minutes', is_array( LoginTokens::consume( $shortLived ) ), true );

$expiring = LoginTokens::issue( $member, 15 );

Schedule::freeze( new DateTimeImmutable( '2026-08-13 09:30:00', new DateTimeZone( 'UTC' ) ) );
check( 'expired at sixteen', LoginTokens::consume( $expiring ), null );

Schedule::freeze( new DateTimeImmutable( '2026-08-13 09:00:00', new DateTimeZone( 'UTC' ) ) );

echo "\nOne inbox cannot be used to flood another\n";

$fresh = Users::create( 'flood@example.org', 'a-good-password', 'Flo Odgers', '', Users::ROLE_MEMBER, true );

for ( $i = 0; $i < 5; $i++ ) {
	LoginTokens::issue( $fresh, 15 );
}

check( 'the sixth request in a quarter hour is throttled', LoginTokens::isThrottled( $fresh ), true );

Schedule::freeze( new DateTimeImmutable( '2026-08-13 09:20:00', new DateTimeZone( 'UTC' ) ) );
check( 'and allowed again once the window passes', LoginTokens::isThrottled( $fresh ), false );

Schedule::freeze( new DateTimeImmutable( '2026-08-13 09:00:00', new DateTimeZone( 'UTC' ) ) );

echo "\nSigning out kills any link still in the inbox\n";

$outstanding = LoginTokens::issue( $member, 15 );
LoginTokens::revokeFor( $member );

check( 'a link left in an old email stops working', LoginTokens::consume( $outstanding ), null );

/* =========================================================================
 * The magic link method
 * ====================================================================== */

echo "\nThe link method gives nothing away\n";

$magic = new MagicLinkMethod();

$_SERVER['HTTP_HOST'] = 'lunch.example.org';
$_SERVER['HTTPS']     = 'on';

$known   = $magic->start( array( 'email' => 'ruth@example.org' ) );
$unknown = $magic->start( array( 'email' => 'nobody@example.org' ) );

check( 'a known address gets a notice, not a sign-in', $known->kind(), Outcome::NOTICE );
check( 'an unknown address gets the very same words', $unknown->message(), $known->message() );
check( 'a malformed address too', $magic->start( array( 'email' => 'not-an-address' ) )->message(), $known->message() );

$log = is_file( $logPath ) ? (string) file_get_contents( $logPath ) : '';

check( 'only the real member was written to', substr_count( $log, 'ruth@example.org' ) > 0, true );
check( 'the unknown address was not emailed', str_contains( $log, 'nobody@example.org' ), false );

echo "\nAnd the link in the email signs that person in\n";

if ( preg_match( '#/auth/magic/callback\?token=([A-Za-z0-9_\-]+)#', $log, $found ) ) {
	$outcome = $magic->finish( array( 'token' => $found[1] ) );

	check( 'the emailed link works', $outcome->isAuthenticated(), true );
	check( 'as the right person', $outcome->user()['email'], 'ruth@example.org' );
	check( 'and not twice', $magic->finish( array( 'token' => $found[1] ) )->isAuthenticated(), false );
} else {
	check( 'the email contained a sign-in link', false, true );
}

check(
	'with email switched off it is not offered at all',
	( static function () use ( $magic ) {
		Settings::set( 'mail_enabled', 'no' );
		$configured = $magic->isConfigured();
		Settings::set( 'mail_enabled', 'yes' );

		return $configured;
	} )(),
	false
);

/* =========================================================================
 * What a provider may and may not decide
 * ====================================================================== */

echo "\nAn outside provider cannot let anybody order\n";

Settings::set( 'signin_external_create', 'yes' );

$newcomer = Identities::resolve(
	'auth0',
	new Profile( 'auth0|new-person', 'grace@example.org', true, 'Grace Mensah' )
);

check( 'a first-time signer is signed in', $newcomer->isAuthenticated(), true );
check( 'but not approved', Users::canOrder( $newcomer->user() ), false );
check( 'and only ever a member', $newcomer->user()['role'], Users::ROLE_MEMBER );
check( 'with no password to guess', Users::hasPassword( $newcomer->user() ), false );

echo "\nAn unconfirmed address matches nothing\n";

$imposter = Identities::resolve(
	'auth0',
	new Profile( 'auth0|imposter', 'ruth@example.org', false, 'Not Ruth' )
);

check( 'it is refused', $imposter->isAuthenticated(), false );
check( 'and says why', str_contains( $imposter->message(), 'not confirmed' ), true );
check( 'nothing was linked', Identities::find( 'auth0', 'auth0|imposter' ), null );

echo "\nA confirmed address joins the account it belongs to\n";

$linked = Identities::resolve(
	'auth0',
	new Profile( 'auth0|ruth', 'ruth@example.org', true, 'Ruth Okafor' )
);

check( 'the existing member is recognised', (int) $linked->user()['id'], $member );
check( 'and keeps their approval', Users::canOrder( $linked->user() ), true );
check( 'the link is recorded', (int) Identities::find( 'auth0', 'auth0|ruth' )['user_id'], $member );

echo "\nAfterwards the subject is what counts, not the address\n";

$renamed = Identities::resolve(
	'auth0',
	new Profile( 'auth0|ruth', 'ruth.okafor@newjob.example', true, 'Ruth Okafor' )
);

check( 'a changed address at the provider still signs them in', (int) $renamed->user()['id'], $member );
check( 'their address here is left alone', $renamed->user()['email'], 'ruth@example.org' );
check(
	'though the new one is noted against the link',
	Identities::find( 'auth0', 'auth0|ruth' )['email'],
	'ruth.okafor@newjob.example'
);

// The point of keying on the subject: somebody who later takes over an address
// at the provider gets their own account, not the one that address used to
// belong to.
$successor = Identities::resolve(
	'auth0',
	new Profile( 'auth0|somebody-else', 'ruth.okafor@newjob.example', true, 'Someone Else' )
);

check( 'and whoever inherits that address gets their own account', $successor->isAuthenticated(), true );
check( 'not Ruth’s', (int) $successor->user()['id'] === $member, false );

echo "\nOrganisers can refuse strangers outright\n";

Settings::set( 'signin_external_create', 'no' );

$stranger = Identities::resolve(
	'auth0',
	new Profile( 'auth0|stranger', 'stranger@example.org', true, 'A Stranger' )
);

check( 'no account is made', $stranger->isAuthenticated(), false );
check( 'and they are told to ask', str_contains( $stranger->message(), 'organiser' ), true );
check( 'nothing was written', Users::findByEmail( 'stranger@example.org' ), null );

Settings::set( 'signin_external_create', 'yes' );

echo "\nA link that outlives its account is cleaned up\n";

$doomed = Users::create( 'leaving@example.org', 'a-good-password', 'Lee Aving', '', Users::ROLE_MEMBER, true );

Identities::link( $doomed, 'auth0', 'auth0|leaving', 'leaving@example.org' );

// Deleting through Users::delete takes the link with it, so the orphan has to
// be made by hand to test the guard.
$statement = \PauseCafe\Database::pdo()->prepare( 'DELETE FROM users WHERE id = ?' );
$statement->execute( array( $doomed ) );

$orphan = Identities::resolve( 'auth0', new Profile( 'auth0|leaving', 'leaving@example.org', true, '' ) );

check( 'the sign-in is refused', $orphan->isAuthenticated(), false );
check( 'and the dangling link is removed', Identities::find( 'auth0', 'auth0|leaving' ), null );

echo "\nDeleting somebody takes their way back in with them\n";

$removed = Users::create( 'gone@example.org', 'a-good-password', 'Gus Gone', '', Users::ROLE_MEMBER, true );

Identities::link( $removed, 'auth0', 'auth0|gone', 'gone@example.org' );
$leftover = LoginTokens::issue( $removed, 15 );

Users::delete( $removed );

check( 'the link is gone', Identities::find( 'auth0', 'auth0|gone' ), null );
check( 'and so is any sign-in link they were sent', LoginTokens::consume( $leftover ), null );

/* =========================================================================
 * Passwordless accounts
 * ====================================================================== */

echo "\nAn account with no password cannot be signed into with one\n";

$external = Users::createExternal( 'passwordless@example.org', 'Pat Wordless' );

check( 'it has no password', Users::hasPassword( Users::find( $external ) ), false );
check( 'an empty password does not work', Users::authenticate( 'passwordless@example.org', '' ), null );
check( 'nor does any other', Users::authenticate( 'passwordless@example.org', 'password' ), null );

$password = new PasswordMethod();

check(
	'and the password method says the same thing it says to everyone',
	$password->start( array( 'email' => 'passwordless@example.org', 'password' => 'guess' ) )->message(),
	$password->start( array( 'email' => 'nobody-at-all@example.org', 'password' => 'guess' ) )->message()
);

/* =========================================================================
 * The register
 * ====================================================================== */

echo "\nThe register decides what the login page offers\n";

check( 'passwords are on to begin with', SignIn::isEnabled( 'password' ), true );
check( 'and nothing else is', SignIn::isEnabled( 'auth0' ), false );
check( 'four methods are registered', count( SignIn::all() ), 4 );

Settings::set( 'signin_auth0_enabled', 'yes' );

check( 'switching one on is not enough to offer it', SignIn::isAvailable( 'auth0' ), false );

Settings::setMany(
	array(
		'signin_auth0_domain'        => 'church.eu.auth0.com',
		'signin_auth0_client_id'     => 'client-abc',
		'signin_auth0_client_secret' => 'shhh',
	)
);

check( 'filled in, it is offered', SignIn::isAvailable( 'auth0' ), true );
check( 'alongside passwords', count( SignIn::available() ), 2 );

echo "\nAnd it will not let an organiser lock themselves out\n";

Settings::setMany(
	array(
		'signin_password_enabled' => 'no',
		'signin_auth0_enabled'    => 'no',
	)
);

/*
 * With everything off the public login page offers nothing at all. There is no
 * fallback that puts passwords back for members: two earlier versions had one,
 * and it was wrong both times -- first it drew a form that then refused to
 * work, and once that was fixed it quietly re-admitted every member to a site
 * whose organisers had deliberately switched passwords off.
 *
 * What survives is the organiser rescue, which is checked against an admin
 * account, and which cannot be off while it is the only way in.
 */
check( 'with everything off, members are offered nothing', SignIn::available(), array() );

check_throws(
	'and a member cannot sign in with a password anyway',
	static fn() => SignIn::resolve( 'password' ),
	'switched off'
);

check( 'while the organiser rescue is forced on', SignIn::rescueAllowed(), true );
check( 'and offered', SignIn::rescueOffered(), true );
check( 'as the only way in', SignIn::organiserRoutes(), array( 'Organiser password' ) );

// Even having been told to switch it off, since there is nothing to replace it.
Settings::set( 'signin_admin_rescue', 'no' );

check( 'which cannot be given away when it is all there is', SignIn::rescueAllowed(), true );

Settings::set( 'signin_admin_rescue', 'yes' );

Settings::set( 'signin_auth0_enabled', 'yes' );

check( 'with a provider on, passwords step aside', array_keys( SignIn::available() ), array( 'auth0' ) );
check( 'and the organiser way in is offered', SignIn::rescueOffered(), true );
check( 'listed as such', in_array( 'Organiser password', SignIn::organiserRoutes(), true ), true );

Settings::set( 'signin_admin_rescue', 'no' );

check( 'turning it off leaves only the provider', SignIn::organiserRoutes(), array( 'Auth0' ) );

Settings::setMany(
	array(
		'signin_password_enabled' => 'yes',
		'signin_admin_rescue'     => 'yes',
	)
);

check( 'with passwords back, the rescue is redundant', SignIn::rescueOffered(), false );

echo "\nThe rescue cannot be given up before it has been replaced\n";

/*
 * The lockout this guards against: switch passwords off, point at a provider
 * nobody has ever signed in through, switch the rescue off, and there is no
 * way in at all. A configured provider is not a working one.
 */
check( 'a configured provider does not count as proven', SignIn::rescueMayBeDisabled(), false );

$organiser = Users::create( 'ada@example.org', 'a-good-password', 'Ada Organiser', '', Users::ROLE_ADMIN, true );

check( 'nor does an organiser with no linked account', SignIn::rescueMayBeDisabled(), false );

// A member signing in externally proves nothing about the organisers.
Identities::resolve( 'auth0', new Profile( 'auth0|a-member', 'member@example.org', true, 'A Member' ) );

check( 'nor a member who has signed in that way', SignIn::rescueMayBeDisabled(), false );

/*
 * An organiser's first external sign-in is parked rather than linked, so
 * proving the outside route now takes the full path: sign in, be held, have the
 * link approved, come back. That is the bootstrap an organiser has to walk
 * before giving up the password rescue, and it is worth walking here -- the
 * rescue is what stops the site locking everybody out, and it must not become
 * disableable on the strength of an attempt that was refused.
 */
$held = Identities::resolve( 'auth0', new Profile( 'auth0|ada', 'ada@example.org', true, 'Ada Organiser' ) );

check( 'an organiser is not linked on the strength of an address', $held->isAuthenticated(), false );
check( 'and a refused attempt proves nothing', SignIn::rescueMayBeDisabled(), false );

$waiting = Identities::pendingLinks();

check( 'the attempt is waiting for a human', count( $waiting ), 1 );
check( 'against the right account', (int) $waiting[0]['user_id'], $organiser );

Identities::approveLink( (int) $waiting[0]['id'] );

/*
 * Approving does not sign anybody in; they come back through the provider. It
 * is that second pass, on the subject rather than the address, that proves the
 * route works.
 */
$backAgain = Identities::resolve( 'auth0', new Profile( 'auth0|ada', 'ada@example.org', true, 'Ada Organiser' ) );

check( 'once approved they get in', $backAgain->isAuthenticated(), true );
check( 'as themselves', (int) $backAgain->user()['id'], $organiser );
check( 'and an organiser who has is enough', SignIn::rescueMayBeDisabled(), true );

// And if that organiser is removed, the proof goes with them.
Users::delete( $organiser );

check( 'and it is withdrawn if they are removed', SignIn::rescueMayBeDisabled(), false );

echo "\nMethods describe themselves to the settings screen\n";

foreach ( SignIn::all() as $id => $method ) {
	check( $id . ' has a label', '' !== $method->label(), true );
	check( $id . ' explains itself', '' !== $method->describe(), true );
	check(
		$id . ' asks for a prompt the login page knows',
		in_array(
			$method->prompt(),
			array( Method::PROMPT_PASSWORD, Method::PROMPT_EMAIL, Method::PROMPT_BUTTON ),
			true
		),
		true
	);
}

check_throws(
	'resolving something that does not exist is refused',
	static fn() => SignIn::resolve( 'facebook' ),
	'does not exist'
);

Settings::set( 'signin_supabase_enabled', 'yes' );

check_throws(
	'as is resolving one that is not set up',
	static fn() => SignIn::resolve( 'supabase' ),
	'not set up'
);

/* =========================================================================
 * Guessing passwords
 * ====================================================================== */

echo "\nGuessing at a password gets slower\n";

/*
 * Deliberately not UTC.
 *
 * The first version of this stored the stamp in local time and read it back as
 * UTC, so the deadline landed hours in the past and the wait came out negative
 * -- it never locked anything. Every one of these assertions passed anyway,
 * because the clock was frozen to UTC and the two readings agreed. A clock
 * frozen to a zone with an offset is what makes the difference visible.
 */
Schedule::freeze( new DateTimeImmutable( '2026-08-13 12:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

LoginAttempts::clearAll();

$guess = static fn( string $email = 'ruth@example.org', string $ip = '198.51.100.7' )
	=> LoginAttempts::retryAfter( $email, $ip );

check( 'a first attempt is free', $guess(), 0 );

for ( $i = 0; $i < 4; $i++ ) {
	LoginAttempts::record( 'ruth@example.org', '198.51.100.7' );
}

check( 'and so is the fifth', $guess(), 0 );

LoginAttempts::record( 'ruth@example.org', '198.51.100.7' );

check( 'the sixth has to wait', $guess() > 0, true );
check( 'for about a quarter of an hour', $guess() > 800 && $guess() <= 900, true );

echo "\nThe wait says nothing about who has an account\n";

/*
 * The address is throttled as typed. Were only real accounts counted, the
 * difference between "wrong password" and "too many attempts" would answer
 * whether an address is a member here.
 */
LoginAttempts::clearAll();

for ( $i = 0; $i < 5; $i++ ) {
	LoginAttempts::record( 'nobody-at-all@example.org', '198.51.100.7' );
}

check(
	'an address with no account is throttled too',
	LoginAttempts::retryAfter( 'nobody-at-all@example.org', '203.0.113.9' ) > 0,
	true
);

echo "\nOne address being locked does not lock another\n";

LoginAttempts::clearAll();

for ( $i = 0; $i < 5; $i++ ) {
	LoginAttempts::record( 'ruth@example.org', '198.51.100.7' );
}

check( 'the guessed-at address waits', $guess() > 0, true );
check( 'somebody else on the same network does not', $guess( 'flood@example.org' ), 0 );

echo "\nBut one machine working through many accounts is caught\n";

LoginAttempts::clearAll();

for ( $i = 0; $i < 40; $i++ ) {
	LoginAttempts::record( 'victim' . $i . '@example.org', '198.51.100.7' );
}

check(
	'an address never guessed at is held back by the machine',
	LoginAttempts::retryAfter( 'someone-new@example.org', '198.51.100.7' ) > 0,
	true
);

check(
	'while the same address from elsewhere is fine',
	LoginAttempts::retryAfter( 'someone-new@example.org', '203.0.113.9' ),
	0
);

echo "\nNothing stays locked\n";

LoginAttempts::clearAll();

for ( $i = 0; $i < 5; $i++ ) {
	LoginAttempts::record( 'ruth@example.org', '198.51.100.7' );
}

check( 'locked now', $guess() > 0, true );

Schedule::freeze( new DateTimeImmutable( '2026-08-13 12:16:00', new DateTimeZone( 'America/Vancouver' ) ) );
check( 'and free again a quarter of an hour later', $guess(), 0 );

Schedule::freeze( new DateTimeImmutable( '2026-08-13 12:00:00', new DateTimeZone( 'America/Vancouver' ) ) );

echo "\nAnd signing in clears the slate\n";

LoginAttempts::clearAll();

for ( $i = 0; $i < 5; $i++ ) {
	LoginAttempts::record( 'ruth@example.org', '198.51.100.7' );
}

check( 'locked after five', $guess() > 0, true );

LoginAttempts::forgive( 'ruth@example.org' );

check( 'getting in forgives them', $guess(), 0 );

// The command-line way back, for somebody who cannot wait.
for ( $i = 0; $i < 5; $i++ ) {
	LoginAttempts::record( 'ruth@example.org', '198.51.100.7' );
}

LoginAttempts::clearAll();

check( 'and the rescue tool clears everything', $guess(), 0 );

/* =========================================================================
 * The address the site uses for itself
 * ====================================================================== */

echo "\nLinks in email do not trust the browser\n";

$_SERVER['HTTP_HOST'] = 'lunch.example.org';
unset( $_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'] );

Notifications::configure( '', false );
check( 'unpinned, it falls back to the request', Notifications::baseUrl(), 'http://lunch.example.org' );
check( 'and says it is not pinned', Notifications::urlIsPinned(), false );

/*
 * The reason this matters: the sign-in link carries a one-time token, and an
 * unpinned address is whatever the caller claimed to be asking.
 */
$_SERVER['HTTP_HOST'] = 'elsewhere.example';
check( 'which a caller can choose', Notifications::baseUrl(), 'http://elsewhere.example' );

Notifications::configure( 'https://lunch.example.org', false );
check( 'pinned, the header is ignored', Notifications::baseUrl(), 'https://lunch.example.org' );
check( 'and it says so', Notifications::urlIsPinned(), true );

Notifications::configure( 'https://lunch.example.org/', false );
check( 'a trailing slash is trimmed, so links do not double up', Notifications::baseUrl(), 'https://lunch.example.org' );

echo "\nAnd it works out HTTPS behind a proxy\n";

$_SERVER['HTTP_HOST'] = 'lunch.example.org';

Notifications::configure( '', true );
check( 'the config flag is enough', Notifications::baseUrl(), 'https://lunch.example.org' );

Notifications::configure( '', false );
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
check( 'so is the proxy header', Notifications::baseUrl(), 'https://lunch.example.org' );

unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
$_SERVER['HTTPS'] = 'on';
check( 'so is the usual one', Notifications::baseUrl(), 'https://lunch.example.org' );

$_SERVER['HTTPS'] = 'off';
check( 'and "off" is not mistaken for yes', Notifications::baseUrl(), 'http://lunch.example.org' );

unset( $_SERVER['HTTPS'] );

echo "\nAnd an unpinned address is refused, not worked around\n";

/*
 * Knowing the address can be chosen by the caller is only worth anything if
 * something acts on it. It used to be a warning on the settings screen while
 * the links went out regardless, which is not a control -- so the two methods
 * that put an address in front of somebody refuse to run at all.
 *
 * The attack it closes: ask for a sign-in link for another member's address
 * while claiming to be your own host. The email lands in their inbox carrying a
 * working one-time token pointing at yours.
 */
Settings::setMany(
	array(
		'mail_enabled'         => 'yes',
		'signin_magic_enabled' => 'yes',
		'signin_auth0_enabled' => 'yes',
	)
);

Notifications::configure( '', false );

check( 'unpinned, sign-in links are not offered', SignIn::isAvailable( 'magic' ), false );
check( 'and the reason names the setting', str_contains( SignIn::get( 'magic' )->requirement(), 'site_url' ), true );
check( 'nor is a provider', SignIn::isAvailable( 'auth0' ), false );
check( 'for the same reason', str_contains( SignIn::get( 'auth0' )->requirement(), 'site_url' ), true );

// Asking for a link while claiming to be somewhere else sends nothing at all.
$_SERVER['HTTP_HOST'] = 'attacker.example';

$mailBefore = is_file( $logPath ) ? file_get_contents( $logPath ) : '';

$forged = SignIn::get( 'magic' )->start( array( 'email' => 'ruth@example.org' ) );

$mailAfter = is_file( $logPath ) ? file_get_contents( $logPath ) : '';

check( 'a forged host gets no link', $mailBefore, $mailAfter );
check( 'and nothing leaks in the reply', str_contains( $forged->message(), 'attacker.example' ), false );

// Nothing anywhere in the mail log has ever pointed at them.
check(
	'no email ever carried that host',
	str_contains( is_file( $logPath ) ? (string) file_get_contents( $logPath ) : '', 'attacker.example' ),
	false
);

$_SERVER['HTTP_HOST'] = 'lunch.example.org';

// Pinned again, and they come back -- the refusal is about the setting, not
// about the methods being broken.
Notifications::configure( 'https://lunch.example', true );

check( 'pinned, sign-in links work again', SignIn::isAvailable( 'magic' ), true );
check( 'and so does the provider', SignIn::isAvailable( 'auth0' ), true );

/* =========================================================================
 * An address is not enough to inherit an account worth taking
 *
 * The hole these exist for: a first external sign-in has no subject to match
 * on, so only the address is left, and a match handed over the account on the
 * spot. A confirmed address says the provider believes this person can read
 * that mailbox today -- not that they are whoever opened the account here.
 * Addresses get reassigned inside an organisation, recycled by a provider, and
 * issued by tenants somebody else administers.
 *
 * The line drawn is worth-taking: money, a history of orders, or the admin
 * area. Accounts with none of those still link straight away, because making
 * their owners wait would cost far more than it protects.
 * ====================================================================== */

echo "\nAn account with money is not handed over on an address alone\n";

$saver = Users::create( 'saver@example.org', 'a-good-password', 'Sam Saver', '', Users::ROLE_MEMBER, true );

Wallet::credit( $saver, 4000, Wallet::KIND_TOPUP, 'float' );

check( 'a balance makes the account worth protecting', Identities::needsApproval( $saver ), true );

$grab = Identities::resolve(
	'auth0',
	new Profile( 'auth0|maybe-sam', 'saver@example.org', true, 'Sam Saver' )
);

check( 'so a confirmed address does not get in', $grab->isAuthenticated(), false );
check( 'nothing is linked', Identities::find( 'auth0', 'auth0|maybe-sam' ), null );
check( 'and the money is untouched', Wallet::balance( $saver ), 4000 );
check( 'the person is told what is happening', str_contains( $grab->message(), 'organiser' ), true );

$queued = Identities::pendingLinks();

check( 'the claim is waiting for a human', count( $queued ), 1 );
check( 'naming the account it wants', (int) $queued[0]['user_id'], $saver );
check( 'and the address that asked', $queued[0]['email'], 'saver@example.org' );

// Trying repeatedly must not bury the screen the organiser reads.
Identities::resolve( 'auth0', new Profile( 'auth0|maybe-sam', 'saver@example.org', true, 'Sam Saver' ) );
Identities::resolve( 'auth0', new Profile( 'auth0|maybe-sam', 'saver@example.org', true, 'Sam Saver' ) );

check( 'trying again leaves one claim, not three', count( Identities::pendingLinks() ), 1 );

echo "\nA second provider gets no free pass from the first\n";

$other = Identities::resolve(
	'supabase',
	new Profile( 'supabase|maybe-sam', 'saver@example.org', true, 'Sam Saver' )
);

check( 'the same address at another provider is held too', $other->isAuthenticated(), false );
check( 'as a claim of its own', count( Identities::pendingLinks() ), 2 );

echo "\nDeclining a claim leaves nothing behind\n";

$supabaseClaim = 0;

foreach ( Identities::pendingLinks() as $candidate ) {
	if ( 'supabase' === $candidate['provider'] ) {
		$supabaseClaim = (int) $candidate['id'];
	}
}

Identities::declineLink( $supabaseClaim );

check( 'it is gone from the list', count( Identities::pendingLinks() ), 1 );
check( 'and still nothing is linked', Identities::find( 'supabase', 'supabase|maybe-sam' ), null );

$again = Identities::resolve(
	'supabase',
	new Profile( 'supabase|maybe-sam', 'saver@example.org', true, 'Sam Saver' )
);

check( 'a declined signer is refused, not admitted', $again->isAuthenticated(), false );

/*
 * Being turned down is not a ban -- they can ask again, and asking again parks
 * a fresh claim. That is deliberate: a decline is usually "I do not know who
 * this is yet", not "never". Cleared here so the counts below are about what
 * each step does rather than what this one left lying around.
 */
check( 'though asking again does park a fresh claim', count( Identities::pendingLinks() ), 2 );

foreach ( Identities::pendingLinks() as $candidate ) {
	if ( 'supabase' === $candidate['provider'] ) {
		Identities::declineLink( (int) $candidate['id'] );
	}
}

check( 'and clearing it leaves only the first', count( Identities::pendingLinks() ), 1 );

echo "\nApproving is what joins them up\n";

$samClaim = 0;

foreach ( Identities::pendingLinks() as $candidate ) {
	if ( 'auth0' === $candidate['provider'] ) {
		$samClaim = (int) $candidate['id'];
	}
}

check( 'the organiser can approve it', Identities::approveLink( $samClaim ), true );
check( 'which spends the claim', Identities::pendingLink( $samClaim ), null );

/*
 * Approving does not sign anybody in -- the organiser is at their own screen,
 * not the member's. The member comes back through the provider, and this time
 * matches on the subject like anybody else.
 */
$admitted = Identities::resolve(
	'auth0',
	new Profile( 'auth0|maybe-sam', 'saver@example.org', true, 'Sam Saver' )
);

check( 'and now they get in', $admitted->isAuthenticated(), true );
check( 'as the account they asked for', (int) $admitted->user()['id'], $saver );
check( 'with the balance still there', Wallet::balance( $saver ), 4000 );

echo "\nAn empty account still links on the spot\n";

$fresh = Users::create( 'fresh@example.org', 'a-good-password', 'Fred Fresh', '', Users::ROLE_MEMBER, true );

check( 'nothing to take means nothing to guard', Identities::needsApproval( $fresh ), false );

$straight = Identities::resolve(
	'auth0',
	new Profile( 'auth0|fred', 'fresh@example.org', true, 'Fred Fresh' )
);

check( 'so they are signed straight in', $straight->isAuthenticated(), true );
check( 'as themselves', (int) $straight->user()['id'], $fresh );
check( 'and no organiser is troubled', count( Identities::pendingLinks() ), 0 );

echo "\nAn organiser account is guarded whether or not it holds money\n";

$empty = Users::create( 'chair@example.org', 'a-good-password', 'Chris Chair', '', Users::ROLE_ADMIN, true );

check( 'the role alone is enough', Identities::needsApproval( $empty ), true );

$reach = Identities::resolve(
	'auth0',
	new Profile( 'auth0|maybe-chris', 'chair@example.org', true, 'Chris Chair' )
);

check( 'so it is held as well', $reach->isAuthenticated(), false );

Identities::declineLink( (int) Identities::pendingLinks()[0]['id'] );

echo "\nAnd an unconfirmed address never even gets that far\n";

$sneak = Identities::resolve(
	'auth0',
	new Profile( 'auth0|sneak', 'saver@example.org', false, 'Not Sam' )
);

check( 'it is refused outright', $sneak->isAuthenticated(), false );
check( 'with no claim raised for an organiser to mis-approve', count( Identities::pendingLinks() ), 0 );

echo "\nA recycled address cannot walk into the account that had it\n";

/*
 * The scenario the audit named: somebody leaves, their address is reassigned,
 * and the new holder signs in. The provider confirms the address honestly --
 * it is theirs now. What they must not get is the leaver's account.
 */
$successorClaim = Identities::resolve(
	'auth0',
	new Profile( 'auth0|new-holder', 'saver@example.org', true, 'New Holder' )
);

check( 'the new holder is not signed in as the old one', $successorClaim->isAuthenticated(), false );
check( 'the original link is untouched', (int) Identities::find( 'auth0', 'auth0|maybe-sam' )['user_id'], $saver );
check( 'and it is an organiser who decides', count( Identities::pendingLinks() ), 1 );

Identities::declineLink( (int) Identities::pendingLinks()[0]['id'] );

/* =========================================================================
 * Connecting a provider to an account you are already signed in to
 *
 * The other half of the same problem, and the reason it is not the old hole
 * wearing a hat: what authorises the link is a credential this site issued, not
 * the provider's word about an address. Somebody who takes over an address at
 * the provider still cannot sign in here, so this door is shut to them --
 * signing in here is the very thing they were trying to achieve.
 *
 * The address plays no part at all, and the tests say so by using one that does
 * not match.
 * ====================================================================== */

echo "\nLinking is authorised by the session, not by an address\n";

$connector = Users::create( 'work@example.org', 'a-good-password', 'Cora Connector', '', Users::ROLE_MEMBER, true );

Wallet::credit( $connector, 2500, Wallet::KIND_TOPUP, 'float' );

check(
	'this account would be held on a sign-in',
	Identities::needsApproval( $connector ),
	true
);

// A personal login, nothing like the address on the account.
$joined = Identities::attach(
	$connector,
	'auth0',
	new Profile( 'auth0|cora-personal', 'cora@personal.example', true, 'Cora Connector' )
);

check( 'connecting it works anyway', $joined->isAuthenticated(), true );
check( 'because the address is not what decided it', (int) Identities::find( 'auth0', 'auth0|cora-personal' )['user_id'], $connector );
check( 'the account keeps its own address', Users::find( $connector )['email'], 'work@example.org' );
check( 'and nothing was parked for an organiser', count( Identities::pendingLinks() ), 0 );

/*
 * An unconfirmed address is fine here, where it is refused on the login page.
 * That is not a relaxation: the address is not being used as evidence of
 * anything, so there is nothing for the provider to confirm.
 */
$unconfirmed = Identities::attach(
	$connector,
	'supabase',
	new Profile( 'supabase|cora', 'whatever@example.invalid', false, 'Cora' )
);

check( 'an unconfirmed address is no obstacle to linking', $unconfirmed->isAuthenticated(), true );

echo "\nBut it cannot be used to take something\n";

$victim = Users::create( 'victim@example.org', 'a-good-password', 'Vic Tim', '', Users::ROLE_MEMBER, true );

Wallet::credit( $victim, 9900, Wallet::KIND_TOPUP, 'float' );

// Cora tries to attach a provider account that is already somebody else's.
Identities::attach( $victim, 'auth0', new Profile( 'auth0|vic', 'victim@example.org', true, 'Vic Tim' ) );

$theft = Identities::attach( $connector, 'auth0', new Profile( 'auth0|vic', 'victim@example.org', true, 'Vic Tim' ) );

check( 'a provider account already in use is refused', $theft->isAuthenticated(), false );
check( 'and stays with whoever had it', (int) Identities::find( 'auth0', 'auth0|vic' )['user_id'], $victim );
check( 'their money is untouched', Wallet::balance( $victim ), 9900 );

// Connecting the same one twice is a no-op, not a second row.
$twice = Identities::attach(
	$connector,
	'auth0',
	new Profile( 'auth0|cora-personal', 'cora@personal.example', true, 'Cora Connector' )
);

check( 'connecting the same one again says so', Outcome::NOTICE, $twice->kind() );
check( 'and does not add a second link', count( Identities::forUser( $connector ) ), 2 );

check(
	'linking cannot invent an account',
	Identities::attach( 99999, 'auth0', new Profile( 'auth0|ghost', 'ghost@example.org', true, 'Ghost' ) )->isAuthenticated(),
	false
);

echo "\nAnd self-service settles a claim that was waiting\n";

$waiter = Users::create( 'waiter@example.org', 'a-good-password', 'Will Waiter', '', Users::ROLE_MEMBER, true );

Wallet::credit( $waiter, 1000, Wallet::KIND_TOPUP, 'float' );

Identities::resolve( 'auth0', new Profile( 'auth0|will', 'waiter@example.org', true, 'Will Waiter' ) );

check( 'the sign-in was held', count( Identities::pendingLinks() ), 1 );

// Will gives up waiting, signs in with his password, and connects it himself.
Identities::attach( $waiter, 'auth0', new Profile( 'auth0|will', 'waiter@example.org', true, 'Will Waiter' ) );

check( 'doing it himself clears the claim', count( Identities::pendingLinks() ), 0 );
check( 'and he is linked', (int) Identities::find( 'auth0', 'auth0|will' )['user_id'], $waiter );

echo "\nDisconnecting cannot leave somebody with no way in\n";

Settings::setMany(
	array(
		'signin_password_enabled' => 'no',
		'signin_magic_enabled'    => 'no',
	)
);

$stranded = Users::createExternal( 'external@example.org', 'Only External' );

Identities::attach( $stranded, 'auth0', new Profile( 'auth0|only', 'external@example.org', true, 'Only External' ) );

$onlyLink = (int) Identities::forUser( $stranded )[0]['id'];

check( 'with no password and nothing else on, that link is everything', Identities::waysInWithout( $stranded, $onlyLink ), 0 );

// A second provider, but one that is switched on without being set up. A link
// to a provider nobody can actually reach is not a way in, and counting it
// would let somebody drop their real one and find out afterwards.
Identities::attach( $stranded, 'supabase', new Profile( 'supabase|only', 'external@example.org', true, 'Only External' ) );

check( 'a link to a provider that is not set up counts for nothing', Identities::waysInWithout( $stranded, $onlyLink ), 0 );

Settings::setMany(
	array(
		'signin_supabase_url'      => 'https://abcdefgh.supabase.co',
		'signin_supabase_anon_key' => 'anon-key',
		'signin_supabase_provider' => 'google',
	)
);

check( 'once it is set up, it does', Identities::waysInWithout( $stranded, $onlyLink ), 1 );

// So does switching an emailed link back on, which needs nothing but the
// address already on the account.
Identities::unlink( (int) Identities::forUser( $stranded )[1]['id'] );

check( 'back to one', Identities::waysInWithout( $stranded, $onlyLink ), 0 );

Settings::set( 'signin_magic_enabled', 'yes' );

check( 'and sign-in links count for everybody', Identities::waysInWithout( $stranded, $onlyLink ), 1 );

Settings::setMany(
	array(
		'signin_password_enabled' => 'yes',
		'signin_magic_enabled'    => 'no',
	)
);

check( 'a password only counts if they have one', Identities::waysInWithout( $stranded, $onlyLink ), 0 );

Users::setPassword( $stranded, 'a-good-password' );

check( 'once set, it does', Identities::waysInWithout( $stranded, $onlyLink ), 1 );

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

finish();
