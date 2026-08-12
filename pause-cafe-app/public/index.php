<?php
/**
 * Front controller and route table.
 *
 * Handlers stay thin: they check permission, validate input, call into the
 * model classes and render. Anything that decides business behaviour lives in
 * src/, not here.
 */

declare(strict_types=1);

$config = require dirname( __DIR__ ) . '/src/bootstrap.php';

use PauseCafe\Auth;
use PauseCafe\Blackouts;
use PauseCafe\Cart;
use PauseCafe\Csrf;
use PauseCafe\Database;
use PauseCafe\Menu;
use PauseCafe\Money;
use PauseCafe\Orders;
use PauseCafe\Router;
use PauseCafe\Schedule;
use PauseCafe\Settings;
use PauseCafe\Users;
use PauseCafe\View;
use PauseCafe\Wallet;
use PauseCafe\Zeffy;

Auth::start( (bool) ( $config['https'] ?? false ) );

$router = new Router();

/* -------------------------------------------------------------------------
 * Guards
 * ---------------------------------------------------------------------- */

$requireLogin = static function (): void {
	if ( ! Auth::check() ) {
		View::flash( 'error', 'Please sign in first.' );
		View::redirect( '/login' );
	}
};

$requireAdmin = static function () use ( $requireLogin ): void {
	$requireLogin();

	if ( ! Auth::isAdmin() ) {
		http_response_code( 403 );
		echo View::render( 'error', array( 'title' => 'Not allowed', 'message' => 'That area is for organisers only.' ) );
		exit;
	}
};

$post = static fn( string $key, string $default = '' ): string => trim( (string) ( $_POST[ $key ] ?? $default ) );
$query = static fn( string $key, string $default = '' ): string => trim( (string) ( $_GET[ $key ] ?? $default ) );

/* -------------------------------------------------------------------------
 * First run
 * ---------------------------------------------------------------------- */

$router->get(
	'/setup',
	static function (): void {
		if ( ! Database::needsSetup() ) {
			View::redirect( '/login' );
		}

		echo View::render( 'setup', array( 'title' => 'Set up' ) );
	}
);

$router->post(
	'/setup',
	static function () use ( $post ): void {
		if ( ! Database::needsSetup() ) {
			View::redirect( '/login' );
		}

		Csrf::verify();

		try {
			$id = Users::create(
				$post( 'email' ),
				$post( 'password' ),
				$post( 'name' ),
				'',
				Users::ROLE_ADMIN,
				true
			);

			Auth::login( Users::find( $id ) );
			View::flash( 'success', 'Welcome. Add this week\'s menu to get going.' );
			View::redirect( '/admin' );
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
			View::redirect( '/setup' );
		}
	}
);

/* -------------------------------------------------------------------------
 * Storefront
 * ---------------------------------------------------------------------- */

$router->get(
	'/',
	static function (): void {
		$serviceDate = Menu::currentServiceDate();
		$locations   = Menu::locations();
		$blocks      = array();

		if ( $serviceDate ) {
			foreach ( $locations as $location ) {
				$items = array_values(
					array_filter(
						Menu::itemsForServiceDate( $serviceDate, (int) $location['id'] ),
						static fn( $item ) => $item['window']->isListed()
					)
				);

				if ( $items ) {
					$blocks[] = array(
						'location' => $location,
						'items'    => $items,
					);
				}
			}
		}

		// A blacked-out week still resolves a date, so the label can be shown
		// rather than an empty page.
		$blackout = $serviceDate && Blackouts::isBlackout( $serviceDate )
			? Blackouts::label( $serviceDate )
			: '';

		echo View::render(
			'menu',
			array(
				'title'       => Settings::get( 'menu_heading' ),
				'serviceDate' => $serviceDate,
				'blocks'      => $blocks,
				'blackout'    => $blackout,
			)
		);
	}
);

$router->get(
	'/login',
	static function (): void {
		if ( Database::needsSetup() ) {
			View::redirect( '/setup' );
		}

		if ( Auth::check() ) {
			View::redirect( '/' );
		}

		echo View::render( 'login', array( 'title' => 'Sign in' ) );
	}
);

$router->post(
	'/login',
	static function () use ( $post ): void {
		Csrf::verify();

		$user = Users::authenticate( $post( 'email' ), (string) ( $_POST['password'] ?? '' ) );

		if ( ! $user ) {
			View::flash( 'error', 'That email and password did not match.' );
			View::redirect( '/login' );
		}

		Auth::login( $user );

		View::redirect( Users::isAdmin( $user ) ? '/admin' : '/' );
	}
);

$router->post(
	'/logout',
	static function (): void {
		Csrf::verify();
		Auth::logout();
		View::redirect( '/' );
	}
);

$router->get(
	'/register',
	static function (): void {
		if ( ! Settings::bool( 'allow_registration' ) ) {
			View::flash( 'error', 'New accounts are created by the organisers. Please get in touch with them.' );
			View::redirect( '/login' );
		}

		echo View::render( 'register', array( 'title' => 'Create an account' ) );
	}
);

$router->post(
	'/register',
	static function () use ( $post ): void {
		Csrf::verify();

		if ( ! Settings::bool( 'allow_registration' ) ) {
			View::redirect( '/login' );
		}

		try {
			Users::create(
				$post( 'email' ),
				(string) ( $_POST['password'] ?? '' ),
				$post( 'name' ),
				$post( 'group_name' )
			);

			View::flash( 'success', 'Thanks. An organiser will approve your account, and then you can order.' );
			View::redirect( '/login' );
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
			View::redirect( '/register' );
		}
	}
);

/* -------------------------------------------------------------------------
 * Cart and checkout
 * ---------------------------------------------------------------------- */

$router->post(
	'/cart/add',
	static function () use ( $requireLogin, $post ): void {
		$requireLogin();
		Csrf::verify();

		$item = Menu::item( (int) ( $_POST['item_id'] ?? 0 ) );

		if ( ! $item ) {
			View::flash( 'error', 'That dish is not on the menu.' );
			View::redirect( '/' );
		}

		if ( ! $item['window']->isOrderable() ) {
			View::flash( 'error', $item['name'] . ' cannot be ordered right now. ' . $item['window']->message() );
			View::redirect( '/' );
		}

		if ( Menu::isSoldOut( $item ) ) {
			View::flash( 'error', $item['name'] . ' is sold out.' );
			View::redirect( '/' );
		}

		$personName = $post( 'person_name' );

		// Falling back to the account holder keeps the cook list readable when
		// somebody just orders for themselves and skips the field.
		if ( '' === $personName ) {
			$user       = Auth::user();
			$personName = (string) ( $user['name'] ?? '' );
		}

		$groupName = $post( 'group_name' );

		if ( '' === $groupName ) {
			$user      = Auth::user();
			$groupName = (string) ( $user['group_name'] ?? '' );
		}

		Cart::add( (int) $item['id'], max( 1, (int) ( $_POST['qty'] ?? 1 ) ), $personName, $groupName );

		View::flash( 'success', $item['name'] . ' added for ' . $personName . '.' );
		View::redirect( '/cart' );
	}
);

$router->get(
	'/cart',
	static function () use ( $requireLogin ): void {
		$requireLogin();

		$cart = Cart::detailed();

		echo View::render(
			'cart',
			array(
				'title'   => 'Your cart',
				'cart'    => $cart,
				'balance' => Wallet::balance( Auth::id() ),
			)
		);
	}
);

$router->post(
	'/cart/update',
	static function () use ( $requireLogin, $post ): void {
		$requireLogin();
		Csrf::verify();

		Cart::update(
			(int) ( $_POST['index'] ?? -1 ),
			(int) ( $_POST['qty'] ?? 1 ),
			$post( 'person_name' ),
			$post( 'group_name' )
		);

		View::redirect( '/cart' );
	}
);

$router->post(
	'/cart/remove',
	static function () use ( $requireLogin ): void {
		$requireLogin();
		Csrf::verify();

		Cart::remove( (int) ( $_POST['index'] ?? -1 ) );

		View::redirect( '/cart' );
	}
);

$router->post(
	'/checkout',
	static function () use ( $requireLogin ): void {
		$requireLogin();
		Csrf::verify();

		if ( ! Auth::canOrder() ) {
			View::flash( 'error', 'Your account is waiting to be approved, so it cannot order yet.' );
			View::redirect( '/cart' );
		}

		$cart = Cart::detailed();

		if ( ! $cart['lines'] ) {
			View::redirect( '/cart' );
		}

		try {
			$orderId = Orders::place(
				Auth::id(),
				array_map(
					static fn( $line ) => array(
						'item_id'     => $line['item']['id'],
						'qty'         => $line['qty'],
						'person_name' => $line['person_name'],
						'group_name'  => $line['group_name'],
					),
					$cart['lines']
				)
			);

			Cart::clear();

			View::flash( 'success', 'Order placed. See you Sunday.' );
			View::redirect( '/orders/' . $orderId );
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
			View::redirect( '/cart' );
		}
	}
);

/* -------------------------------------------------------------------------
 * Account
 * ---------------------------------------------------------------------- */

$router->get(
	'/account',
	static function () use ( $requireLogin ): void {
		$requireLogin();

		echo View::render(
			'account',
			array(
				'title'   => 'Your account',
				'balance' => Wallet::balance( Auth::id() ),
				'entries' => Wallet::entries( Auth::id() ),
				'orders'  => Orders::forUser( Auth::id() ),
			)
		);
	}
);

$router->post(
	'/account/password',
	static function () use ( $requireLogin ): void {
		$requireLogin();
		Csrf::verify();

		$current = (string) ( $_POST['current_password'] ?? '' );
		$next    = (string) ( $_POST['new_password'] ?? '' );
		$user    = Auth::user();

		if ( ! Users::authenticate( $user['email'], $current ) ) {
			View::flash( 'error', 'That current password is not right.' );
			View::redirect( '/account' );
		}

		if ( strlen( $next ) < 8 ) {
			View::flash( 'error', 'The new password needs to be at least 8 characters.' );
			View::redirect( '/account' );
		}

		Users::setPassword( (int) $user['id'], $next );

		View::flash( 'success', 'Password changed.' );
		View::redirect( '/account' );
	}
);

$router->get(
	'/orders/{id}',
	static function ( string $id ) use ( $requireLogin ): void {
		$requireLogin();

		$order = Orders::find( (int) $id );

		if ( ! $order || ( (int) $order['user_id'] !== Auth::id() && ! Auth::isAdmin() ) ) {
			http_response_code( 404 );
			echo View::render( 'error', array( 'title' => 'Not found', 'message' => 'No such order.' ) );

			return;
		}

		echo View::render(
			'order',
			array(
				'title' => 'Order #' . $order['id'],
				'order' => $order,
				'lines' => Orders::lines( (int) $order['id'] ),
			)
		);
	}
);

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

require dirname( __DIR__ ) . '/src/routes-admin.php';

/* -------------------------------------------------------------------------
 * Zeffy webhook -- no session, authorised by shared secret
 * ---------------------------------------------------------------------- */

$router->post(
	'/webhook/zeffy',
	static function (): void {
		header( 'Content-Type: application/json' );

		$headers = function_exists( 'getallheaders' ) ? (array) getallheaders() : array();

		if ( ! Zeffy::authorise( $headers, $_GET ) ) {
			http_response_code( 401 );
			echo json_encode( array( 'error' => 'unauthorised' ) );

			return;
		}

		$raw     = file_get_contents( 'php://input' );
		$payload = json_decode( (string) $raw, true );

		if ( ! is_array( $payload ) ) {
			Zeffy::log( 'bad-json', $raw );
			http_response_code( 400 );
			echo json_encode( array( 'error' => 'expected a JSON object' ) );

			return;
		}

		$result = Zeffy::applyPayment( $payload );

		// Anything other than 2xx invites the sender to retry forever, and an
		// unmatched email will never start matching on its own.
		echo json_encode( $result );
	}
);

/* -------------------------------------------------------------------------
 * Dispatch
 * ---------------------------------------------------------------------- */

try {
	$matched = $router->dispatch(
		$_SERVER['REQUEST_METHOD'] ?? 'GET',
		$_SERVER['REQUEST_URI'] ?? '/'
	);

	if ( ! $matched ) {
		http_response_code( 404 );
		echo View::render( 'error', array( 'title' => 'Not found', 'message' => 'That page does not exist.' ) );
	}
} catch ( \RuntimeException $e ) {
	$code = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500;

	http_response_code( $code );
	echo View::render( 'error', array( 'title' => 'Something went wrong', 'message' => $e->getMessage() ) );
}
