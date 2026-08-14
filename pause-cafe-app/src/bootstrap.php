<?php
/**
 * Wires everything together. Included by the front controller and by the tests.
 */

declare(strict_types=1);

// Kept in step with CHANGELOG.md.
defined( 'PAUSE_CAFE_VERSION' ) || define( 'PAUSE_CAFE_VERSION', '0.10.3' );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'PauseCafe\\';

		if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/View.php';

use PauseCafe\Database;
use PauseCafe\Mail\LogTransport;
use PauseCafe\Mailer;
use PauseCafe\Money;
use PauseCafe\Payments;
use PauseCafe\Schedule;
use PauseCafe\SignIn;
use PauseCafe\View;
use PauseCafe\Zeffy;

$root       = dirname( __DIR__ );
$configFile = $root . '/config.php';

$config = is_file( $configFile )
	? require $configFile
	: require $root . '/config.example.php';

// Tests point the database somewhere disposable.
if ( isset( $GLOBALS['PAUSE_CAFE_DB_OVERRIDE'] ) ) {
	$config['database'] = $GLOBALS['PAUSE_CAFE_DB_OVERRIDE'];
}

date_default_timezone_set( $config['timezone'] ?? 'UTC' );

Database::configure( $config['database'] );
Schedule::configure( $config['timezone'] ?? 'UTC' );
Money::configure( $config['currency'] ?? '$' );
View::configure( $root . '/views' );
Zeffy::configure(
	(string) ( $config['zeffy_secret'] ?? '' ),
	(string) ( $config['zeffy_api_key'] ?? '' ),
	dirname( (string) $config['database'] ) . '/zeffy.log'
);

Database::migrate();

// Registers the built-in payment methods. A site adding its own would call
// Payments::register() after this line.
Payments::boot();

LogTransport::configure( dirname( (string) $config['database'] ) . '/mail.log' );
Mailer::boot();

// Same again for the ways of signing in. A site with its own identity provider
// would subclass OidcMethod and SignIn::register() it here.
SignIn::boot();

if ( ! function_exists( 'e' ) ) {
	/**
	 * Escape for HTML. Global so templates stay readable.
	 */
	function e( $value ): string {
		return \PauseCafe\e( $value );
	}
}

return $config;
