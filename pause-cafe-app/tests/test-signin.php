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
use PauseCafe\LoginTokens;
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

$logPath = dirname( __DIR__ ) . '/data/test-mail.log';

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

LogTransport::configure( $logPath );
Settings::set( 'mail_transport', 'log' );

// A fixed clock, so "expired" means something specific.
Schedule::freeze( new DateTimeImmutable( '2026-08-13 09:00:00', new DateTimeZone( 'UTC' ) ) );

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

check( 'with everything off, passwords come back', array_keys( SignIn::available() ), array( 'password' ) );

/*
 * And they have to actually work. The fallback used to be offered by the login
 * page and then refused by resolve(), which checked the enabled setting rather
 * than availability -- a safety net that drew a door which would not open. It
 * only showed up when every method was off at once, which is exactly the state
 * somebody is in when they need it.
 */
$fallback = SignIn::resolve( 'password' );

check( 'and can be resolved, not just displayed', $fallback->id(), 'password' );
check(
	'and will really sign an organiser in',
	$fallback->start(
		array( 'email' => 'ruth@example.org', 'password' => 'a-good-password' )
	)->isAuthenticated(),
	true
);

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

Identities::resolve( 'auth0', new Profile( 'auth0|ada', 'ada@example.org', true, 'Ada Organiser' ) );

check( 'but an organiser who has is enough', SignIn::rescueMayBeDisabled(), true );

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

if ( is_file( $logPath ) ) {
	unlink( $logPath );
}

finish();
