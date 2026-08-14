<?php
/**
 * The way back in when nobody can sign in.
 *
 * Every guard in the settings screen can be outlived by events: an Auth0 tenant
 * that worked in March is deleted in June, and the organiser who proved it
 * works is the one who cannot get in. This does not depend on anything outside
 * the server, and it cannot be reached over the web.
 *
 * Run it on the host, from the application directory:
 *
 *   php tools/rescue.php                      turn password sign-in back on
 *   php tools/rescue.php --list               show the organisers
 *   php tools/rescue.php --reset you@here     also set a new password
 *
 * The new password is generated and printed rather than taken as an argument,
 * so it never lands in the shell history.
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 404 );
	exit( "This is a command line tool.\n" );
}

require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Identities;
use PauseCafe\Settings;
use PauseCafe\SignIn;
use PauseCafe\Users;

$arguments = array_slice( $argv, 1 );
$command   = $arguments[0] ?? '';

/** @return array[] Just the organisers. */
$organisers = static function (): array {
	return array_values(
		array_filter( Users::all(), static fn( array $u ): bool => Users::isAdmin( $u ) )
	);
};

if ( '--list' === $command ) {
	echo "Organisers:\n";

	foreach ( $organisers() as $one ) {
		$links = Identities::forUser( (int) $one['id'] );
		$how   = $links
			? implode( ', ', array_column( $links, 'provider' ) )
			: 'no linked provider';

		printf(
			"  %-34s %-28s %s\n",
			$one['email'],
			$one['name'],
			Users::hasPassword( $one ) ? 'password + ' . $how : 'NO PASSWORD, ' . $how
		);
	}

	exit( 0 );
}

/*
 * Both switches, together. Turning the rescue on without the password method
 * would leave a way in that only works if you know the URL for it, which is
 * not what somebody running this needs.
 */
Settings::setMany(
	array(
		SignIn::settingKey( 'password' ) => 'yes',
		'signin_admin_rescue'            => 'yes',
	)
);

echo "Password sign-in is back on, and so is the organiser password route.\n";

if ( '--reset' === $command ) {
	$email = $arguments[1] ?? '';

	if ( '' === $email ) {
		exit( "Give the address to reset: php tools/rescue.php --reset you@example.org\n" );
	}

	$user = Users::findByEmail( $email );

	if ( ! $user ) {
		exit( 'No account for ' . $email . ". Try --list.\n" );
	}

	if ( ! Users::isAdmin( $user ) ) {
		exit( $email . " is not an organiser. This only resets organisers.\n" );
	}

	// Readable over the phone, and long enough that it does not matter.
	$password = strtolower( bin2hex( random_bytes( 3 ) ) )
		. '-' . strtolower( bin2hex( random_bytes( 3 ) ) )
		. '-' . strtolower( bin2hex( random_bytes( 3 ) ) );

	Users::setPassword( (int) $user['id'], $password );

	echo "\nNew password for " . $email . ":\n\n    " . $password . "\n\n";
	echo "Sign in with it and change it. It is on this screen and in no email.\n";
}

echo "\nOrganisers who can now sign in with a password:\n";

foreach ( $organisers() as $one ) {
	if ( Users::hasPassword( $one ) ) {
		echo '  ' . $one['email'] . "\n";
	}
}

echo "\nSign in at /login. Nothing else was changed.\n";
