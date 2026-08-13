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
use PauseCafe\Groups;
use PauseCafe\Kitchen;
use PauseCafe\Menu;
use PauseCafe\MenuFields;
use PauseCafe\Money;
use PauseCafe\Notifications;
use PauseCafe\Orders;
use PauseCafe\Payments;
use PauseCafe\Router;
use PauseCafe\Schedule;
use PauseCafe\Schedules;
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
		/*
		 * One section per schedule that asks to be on the front page. Each works
		 * out its own current week, since two menus on different rhythms are not
		 * on the same one.
		 */
		$sections = array();

		foreach ( Schedules::onFront() as $scheduleId => $rules ) {
			$serviceDate = Menu::currentServiceDate( $scheduleId );
			$blocks      = array();

			if ( $serviceDate ) {
				foreach ( Schedules::locationsFor( $scheduleId ) as $location ) {
					$items = array_values(
						array_filter(
							Menu::itemsForServiceDate( $serviceDate, (int) $location['id'], true, $scheduleId ),
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
			$sections[] = array(
				'rules'       => $rules,
				'serviceDate' => $serviceDate,
				'blocks'      => $blocks,
				'blackout'    => $serviceDate && Blackouts::isBlackout( $serviceDate )
					? Blackouts::label( $serviceDate )
					: '',
			);
		}

		echo View::render(
			'menu',
			array(
				'title'    => Settings::get( 'menu_heading' ),
				'sections' => $sections,
				'columns'  => max( 1, min( 6, Settings::int( 'front_grid_columns', 3 ) ) ),
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
			$newUserId = Users::create(
				$post( 'email' ),
				(string) ( $_POST['password'] ?? '' ),
				$post( 'name' ),
				Groups::sanitise( $post( 'group_name' ) )
			);

			// Nobody is waiting on this, and a mail failure must not turn a
			// successful sign-up into an error page.
			Notifications::newRegistration( $newUserId );

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

		/*
		 * Only fields visible on this dish are read, and each is cleaned by its
		 * own type. A hidden field cannot be filled in by hand-posting it, and a
		 * select cannot be given a value it never offered.
		 */
		$values  = MenuFields::collect( $item, $_POST, Auth::user() );
		$missing = MenuFields::missingRequired( $item, $values );

		if ( $missing ) {
			View::flash( 'error', 'Please fill in: ' . implode( ', ', $missing ) . '.' );
			View::redirect( '/' );
		}

		Cart::add( (int) $item['id'], max( 1, (int) ( $_POST['qty'] ?? 1 ) ), $values );

		$who = (string) ( $values[ MenuFields::PERSON ] ?? '' );

		View::flash( 'success', $item['name'] . ( '' !== $who ? ' added for ' . $who . '.' : ' added.' ) );
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
				'methods' => Payments::enabled(),
			)
		);
	}
);

$router->post(
	'/cart/update',
	static function () use ( $requireLogin, $post ): void {
		$requireLogin();
		Csrf::verify();

		$index = (int) ( $_POST['index'] ?? -1 );
		$lines = Cart::lines();
		$qty   = (int) ( $_POST['qty'] ?? 1 );

		if ( ! isset( $lines[ $index ] ) ) {
			View::redirect( '/cart' );
		}

		if ( $qty < 1 ) {
			Cart::remove( $index );
			View::redirect( '/cart' );
		}

		$item = Menu::item( (int) $lines[ $index ]['item_id'] );

		if ( $item ) {
			Cart::update( $index, $qty, MenuFields::collect( $item, $_POST, Auth::user() ) );
		}

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
	static function () use ( $requireLogin, $post ): void {
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
						'note'        => $line['note'],
						'extra'       => $line['extra'],
					),
					$cart['lines']
				),
				null,
				mb_substr( $post( 'order_note' ), 0, 500 ),
				false,
				(string) ( $_POST['payment_method'] ?? '' )
			);

			Cart::clear();

			// After the order is safely committed: a mail failure must not undo
			// an order that already took payment.
			Notifications::orderPlaced( $orderId );

			$order = Orders::find( $orderId );

			View::flash(
				'success',
				Orders::isPaid( $order )
					? 'Order placed. See you Sunday.'
					: 'Order placed. Please bring payment on the day.'
			);

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
 * Kitchen list -- organisers, or anyone with the shared password
 * ---------------------------------------------------------------------- */

$router->get(
	'/kitchen',
	static function (): void {
		if ( ! Kitchen::hasAccess() ) {
			echo View::render(
				'kitchen-locked',
				array(
					'title'     => 'Kitchen list',
					'protected' => Kitchen::isProtected(),
				)
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$query   = $_GET;
		$filters = Kitchen::filtersFromQuery( $query );
		$sort    = (string) ( $query['sort'] ?? 'location' );
		$dir     = (string) ( $query['dir'] ?? 'asc' );
		$rows    = Orders::lineItemsFiltered( $filters, $sort, $dir );

		echo View::render(
			'kitchen',
			array(
				'title'   => 'Kitchen list',
				'rows'    => $rows,
				'totals'  => Orders::totalsByDish( $rows ),
				'filters' => $filters,
				'options' => Orders::filterOptions(),
				'sort'    => array_key_exists( $sort, Orders::sortableColumns() ) ? $sort : 'location',
				'dir'     => 'desc' === strtolower( $dir ) ? 'desc' : 'asc',
				'query'   => $query,
			)
		);
	}
);

$router->post(
	'/kitchen/unlock',
	static function () use ( $post ): void {
		Csrf::verify();

		if ( Kitchen::unlock( (string) ( $_POST['password'] ?? '' ) ) ) {
			View::redirect( '/kitchen' );
		}

		View::flash( 'error', 'That password did not work.' );
		View::redirect( '/kitchen' );
	}
);

$router->post(
	'/kitchen/lock',
	static function (): void {
		Csrf::verify();
		Kitchen::lock();

		View::flash( 'success', 'Signed out of the kitchen list.' );
		View::redirect( '/kitchen' );
	}
);

$router->get(
	'/kitchen/export',
	static function (): void {
		if ( ! Kitchen::hasAccess() ) {
			http_response_code( 403 );
			echo 'Not allowed.';

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$query   = $_GET;
		$filters = Kitchen::filtersFromQuery( $query );
		$rows    = Orders::lineItemsFiltered(
			$filters,
			(string) ( $query['sort'] ?? 'location' ),
			(string) ( $query['dir'] ?? 'asc' )
		);

		$name = 'pause-cafe-kitchen';

		if ( '' !== $filters['from'] ) {
			$name .= '-' . $filters['from'];
		}

		nocache_headers_compat();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $name . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// Excel needs the BOM to read the Chinese dish names correctly.
		fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$row = static function ( array $fields ) use ( $out ): void {
			// $escape must be explicit: PHP 8.4 deprecates its default and writes
			// the notice into this very stream.
			fputcsv( $out, $fields, ',', '"', '' );
		};

		$row( array( 'Date', 'Location', 'Dish', 'Qty', 'Name', 'Group', 'Payment', 'Paid', 'Notes', 'Extras', 'Account', 'Order' ) );

		foreach ( $rows as $line ) {
			$row(
				array(
					$line['service_date'],
					$line['location_name'],
					$line['item_name'],
					$line['qty'],
					$line['person_name'],
					$line['group_name'],
					Payments::label( (string) $line['payment_method'] ),
					'' !== $line['paid_at'] ? 'yes' : 'no',
					trim( $line['note'] . ( '' !== $line['order_note'] ? ' / ' . $line['order_note'] : '' ) ),
					// Answers to fields the organiser added, as one readable cell
					// rather than a column per field that changes shape over time.
					MenuFields::describeExtras( $line['extra_fields'] ?? '' ),
					$line['account_name'],
					$line['order_id'],
				)
			);
		}

		fclose( $out );
		exit;
	}
);

function nocache_headers_compat(): void {
	header( 'Cache-Control: no-store, no-cache, must-revalidate' );
	header( 'Pragma: no-cache' );
}

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
} catch ( \Throwable $e ) {
	/*
	 * A bug, not a refusal. Anything thrown here is logged and shown as a blank
	 * apology -- a TypeError's message can name internals, and a bare 500 gives
	 * the person in front of it nothing at all.
	 */
	error_log( 'pause-cafe: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

	http_response_code( 500 );
	echo View::render(
		'error',
		array(
			'title'   => 'Something went wrong',
			'message' => 'Sorry — that did not work. An organiser has been sent the details.',
		)
	);
}
