<?php
/**
 * Admin routes.
 *
 * Included from the front controller, so it shares $router and the guards.
 * `use` is per-file in PHP, hence the imports below.
 */

declare(strict_types=1);

use PauseCafe\Auth;
use PauseCafe\Blackouts;
use PauseCafe\Csrf;
use PauseCafe\Groups;
use PauseCafe\Kitchen;
use PauseCafe\Menu;
use PauseCafe\Money;
use PauseCafe\Orders;
use PauseCafe\Payments;
use PauseCafe\Schedule;
use PauseCafe\Settings;
use PauseCafe\Users;
use PauseCafe\View;
use PauseCafe\Wallet;
use PauseCafe\Zeffy;

/* ---------------------------------------------------------------- Dashboard */

$router->get(
	'/admin',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		$serviceDate = Menu::currentServiceDate();

		echo View::render(
			'admin/dashboard',
			array(
				'title'        => 'Organiser',
				'pending'      => Users::pendingCount(),
				'serviceDate'  => $serviceDate,
				'summary'      => $serviceDate ? Orders::summaryForServiceDate( $serviceDate ) : array(),
				'outstanding'  => Wallet::totalOutstanding(),
				'mode'         => Schedule::activeMode(),
			)
		);
	}
);

/* ------------------------------------------------------------------- People */

$router->get(
	'/admin/users',
	static function () use ( $requireAdmin, $query ): void {
		$requireAdmin();

		echo View::render(
			'admin/users',
			array(
				'title'  => 'People',
				'users'  => Users::all( $query( 'q' ) ),
				'search' => $query( 'q' ),
			)
		);
	}
);

$router->post(
	'/admin/users/create',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		try {
			Users::create(
				$post( 'email' ),
				(string) ( $_POST['password'] ?? '' ),
				$post( 'name' ),
				Groups::sanitise( $post( 'group_name' ) ),
				'admin' === $post( 'role' ) ? Users::ROLE_ADMIN : Users::ROLE_MEMBER,
				true
			);

			View::flash( 'success', 'Account created and approved.' );
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
		}

		View::redirect( '/admin/users' );
	}
);

$router->post(
	'/admin/users/{id}',
	static function ( string $id ) use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$userId = (int) $id;
		$target = Users::find( $userId );

		if ( ! $target ) {
			View::redirect( '/admin/users' );
		}

		$role = 'admin' === $post( 'role' ) ? Users::ROLE_ADMIN : Users::ROLE_MEMBER;

		/*
		 * Refuse to remove the last admin, or to demote yourself into a site with
		 * no organiser. Locking everyone out is not recoverable from the UI.
		 */
		if ( Users::ROLE_ADMIN === $target['role'] && Users::ROLE_MEMBER === $role && self_is_last_admin( $userId ) ) {
			View::flash( 'error', 'That is the only organiser account. Make someone else an organiser first.' );
			View::redirect( '/admin/users' );
		}

		Users::update(
			$userId,
			array(
				'name'        => $post( 'name' ),
				'group_name'  => Groups::sanitise( $post( 'group_name' ) ),
				'role'        => $role,
				'is_approved' => isset( $_POST['is_approved'] ) ? 1 : 0,
			)
		);

		if ( '' !== ( $_POST['password'] ?? '' ) ) {
			if ( strlen( (string) $_POST['password'] ) < 8 ) {
				View::flash( 'error', 'Password not changed: it needs to be at least 8 characters.' );
			} else {
				Users::setPassword( $userId, (string) $_POST['password'] );
				View::flash( 'success', 'Password reset.' );
			}
		}

		View::flash( 'success', 'Saved.' );
		View::redirect( '/admin/users' );
	}
);

/**
 * True when this account is the only remaining organiser.
 */
function self_is_last_admin( int $userId ): bool {
	$admins = array_filter( Users::all(), static fn( $u ) => Users::ROLE_ADMIN === $u['role'] );

	return 1 === count( $admins ) && (int) reset( $admins )['id'] === $userId;
}

$router->post(
	'/admin/users/{id}/wallet',
	static function ( string $id ) use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$userId = (int) $id;
		$target = Users::find( $userId );

		if ( ! $target ) {
			View::redirect( '/admin/users' );
		}

		$cents = Money::parse( $post( 'amount' ) );
		$note  = $post( 'note' );

		if ( 0 === $cents ) {
			View::flash( 'error', 'Enter an amount.' );
			View::redirect( '/admin/users' );
		}

		// A debit is entered as a positive number with the direction chosen
		// separately, so a stray minus sign cannot flip a debit into a credit.
		$isDebit = 'debit' === $post( 'direction' );
		$delta   = $isDebit ? -abs( $cents ) : abs( $cents );

		if ( '' === $note ) {
			$note = $isDebit ? 'Manual debit' : 'Manual top-up';
		}

		$balance = Wallet::post(
			$userId,
			$delta,
			Wallet::KIND_ADJUSTMENT,
			$note,
			'',
			Auth::id()
		);

		View::flash(
			'success',
			Money::format( abs( $delta ) ) . ( $isDebit ? ' debited from ' : ' credited to ' ) .
			$target['name'] . '. New balance ' . Money::format( $balance ) . '.'
		);

		View::redirect( '/admin/users' );
	}
);

$router->post(
	'/admin/users/{id}/delete',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		$userId = (int) $id;

		if ( $userId === Auth::id() ) {
			View::flash( 'error', 'You cannot delete the account you are signed in with.' );
			View::redirect( '/admin/users' );
		}

		if ( self_is_last_admin( $userId ) ) {
			View::flash( 'error', 'That is the only organiser account.' );
			View::redirect( '/admin/users' );
		}

		Users::delete( $userId );

		View::flash( 'success', 'Account deleted, along with its orders and ledger.' );
		View::redirect( '/admin/users' );
	}
);

/* --------------------------------------------------------------------- Menu */

$router->get(
	'/admin/menu',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		echo View::render(
			'admin/menu',
			array(
				'title' => 'Menu',
				'items' => Menu::allItems(),
				'mode'  => Schedule::activeMode(),
			)
		);
	}
);

$router->get(
	'/admin/menu/new',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		echo View::render(
			'admin/menu-edit',
			array(
				'title'     => 'Add a dish',
				'item'      => null,
				'locations' => Menu::locations(),
				'mode'      => Schedule::activeMode(),
			)
		);
	}
);

$router->get(
	'/admin/menu/{id}',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();

		$item = Menu::item( (int) $id );

		if ( ! $item ) {
			View::redirect( '/admin/menu' );
		}

		echo View::render(
			'admin/menu-edit',
			array(
				'title'     => 'Edit ' . $item['name'],
				'item'      => $item,
				'locations' => Menu::locations(),
				'mode'      => Schedule::activeMode(),
			)
		);
	}
);

$router->post(
	'/admin/menu/save',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$id   = (int) ( $_POST['id'] ?? 0 );
		$name = $post( 'name' );

		if ( '' === $name ) {
			View::flash( 'error', 'A dish needs a name.' );
			View::redirect( $id ? '/admin/menu/' . $id : '/admin/menu/new' );
		}

		$data = array(
			'location_id'  => (int) ( $_POST['location_id'] ?? 0 ),
			'name'         => $name,
			'description'  => $post( 'description' ),
			'price_cents'  => Money::parse( $post( 'price' ) ),
			'service_date' => $post( 'service_date' ),
			'open_from'    => $post( 'open_from' ),
			'close_at'     => $post( 'close_at' ),
			'capacity'     => (int) ( $_POST['capacity'] ?? 0 ),
			'status'       => $post( 'status', 'published' ),
			'opened_at'    => '',
		);

		if ( $id ) {
			$existing          = Menu::item( $id );
			$data['opened_at'] = $existing ? (string) $existing['opened_at'] : '';
		}

		// In on-publish mode a brand new published dish opens ordering there and
		// then -- that is the whole point of the mode.
		$openNow = Schedule::MODE_ON_PUBLISH === Schedule::activeMode()
			&& 'published' === $data['status']
			&& '' === $data['opened_at'];

		if ( $openNow || isset( $_POST['reopen'] ) ) {
			$data['opened_at'] = Schedule::now()->format( 'Y-m-d H:i:s' );
		}

		$savedId = Menu::save( $data, $id ?: null );

		View::flash( 'success', 'Saved.' );
		View::redirect( '/admin/menu/' . $savedId );
	}
);

$router->post(
	'/admin/menu/{id}/delete',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		$deleted = Menu::delete( (int) $id );

		View::flash(
			'success',
			$deleted
				? 'Dish deleted.'
				: 'That dish has orders against it, so it was moved to draft instead of deleted.'
		);

		View::redirect( '/admin/menu' );
	}
);

$router->post(
	'/admin/menu/{id}/publish',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		Menu::publishNow( (int) $id );

		View::flash( 'success', 'Ordering is open.' );
		View::redirect( '/admin/menu' );
	}
);

/* ------------------------------------------------------------------- Orders */

$router->get(
	'/admin/orders',
	static function () use ( $requireAdmin, $query ): void {
		$requireAdmin();

		$serviceDate = $query( 'date' ) ?: ( Menu::currentServiceDate() ?? '' );

		echo View::render(
			'admin/orders',
			array(
				'title'       => 'Orders',
				'serviceDate' => $serviceDate,
				'dates'       => Menu::serviceDates(),
				'orders'      => $serviceDate ? Orders::forServiceDate( $serviceDate ) : array(),
			)
		);
	}
);

$router->get(
	'/admin/orders/new',
	static function () use ( $requireAdmin, $query ): void {
		$requireAdmin();

		$serviceDate = $query( 'date' ) ?: ( Menu::currentServiceDate() ?? '' );

		echo View::render(
			'admin/order-new',
			array(
				'title'       => 'Order for someone',
				'users'       => Users::all(),
				'dates'       => Menu::serviceDates(),
				'serviceDate' => $serviceDate,
				'items'       => $serviceDate ? Menu::itemsForServiceDate( $serviceDate ) : array(),
				'methods'     => Payments::enabled(),
			)
		);
	}
);

$router->post(
	'/admin/orders/new',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$userId      = (int) ( $_POST['user_id'] ?? 0 );
		$serviceDate = $post( 'service_date' );
		$rows        = (array) ( $_POST['line'] ?? array() );
		$lines       = array();

		foreach ( $rows as $itemId => $row ) {
			$qty = (int) ( $row['qty'] ?? 0 );

			if ( $qty < 1 ) {
				continue;
			}

			$lines[] = array(
				'item_id'     => (int) $itemId,
				'qty'         => $qty,
				'person_name' => trim( (string) ( $row['person_name'] ?? '' ) ),
				'group_name'  => Groups::sanitise( (string) ( $row['group_name'] ?? '' ) ),
			);
		}

		if ( ! $userId || ! $lines ) {
			View::flash( 'error', 'Pick a person and at least one dish.' );
			View::redirect( '/admin/orders/new?date=' . urlencode( $serviceDate ) );
		}

		try {
			// force: organisers take orders after the cutoff, and sometimes for
			// someone whose balance is short. What is owed is still recorded.
			$orderId = Orders::place(
				$userId,
				$lines,
				Auth::id(),
				$post( 'note' ),
				true,
				$post( 'payment_method' )
			);

			View::flash( 'success', 'Order #' . $orderId . ' placed on their behalf.' );
			View::redirect( '/orders/' . $orderId );
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
			View::redirect( '/admin/orders/new?date=' . urlencode( $serviceDate ) );
		}
	}
);

$router->post(
	'/admin/orders/{id}/paid',
	static function ( string $id ) use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$order = Orders::find( (int) $id );

		if ( ! $order ) {
			View::redirect( '/admin/orders' );
		}

		$paid = 'unpaid' !== $post( 'state' );

		Orders::markPaid( (int) $id, $paid );

		View::flash(
			'success',
			'Order #' . (int) $id . ( $paid ? ' marked paid.' : ' marked unpaid again.' )
		);

		View::redirect( '/admin/orders?date=' . urlencode( (string) $order['service_date'] ) );
	}
);

$router->post(
	'/admin/orders/{id}/cancel',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		$order = Orders::find( (int) $id );
		$paid  = $order && Orders::isPaid( $order );
		$byCod = $order && 'cod' === $order['payment_method'];

		try {
			Orders::cancel( (int) $id, Auth::id() );

			// Cash already collected is not something the system can hand back.
			View::flash(
				'success',
				$byCod && $paid
					? 'Order cancelled. It was paid in cash, so return the money in person.'
					: 'Order cancelled and anything paid has been put back.'
			);
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
		}

		View::redirect( '/admin/orders' );
	}
);

/* ------------------------------------------------------------------- Report */

$router->get(
	'/admin/report',
	static function () use ( $requireAdmin, $query ): void {
		$requireAdmin();

		$dates       = Menu::serviceDates();
		$serviceDate = $query( 'date' ) ?: ( Menu::currentServiceDate() ?? ( $dates ? end( $dates ) : '' ) );

		echo View::render(
			'admin/report',
			array(
				'title'       => 'Kitchen report',
				'dates'       => $dates,
				'serviceDate' => $serviceDate,
				'summary'     => $serviceDate ? Orders::summaryForServiceDate( $serviceDate ) : array(),
			)
		);
	}
);

$router->get(
	'/admin/report/export',
	static function () use ( $requireAdmin, $query ): void {
		$requireAdmin();

		$serviceDate = $query( 'date' );

		if ( ! Schedule::parseDate( $serviceDate ) ) {
			View::redirect( '/admin/report' );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pause-cafe-' . $serviceDate . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// Excel needs the BOM to read the Chinese dish names correctly.
		fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		csv_row( $out, array( 'Service date', 'Location', 'Dish', 'Qty', 'For', 'Group', 'Account', 'Email', 'Order', 'Unit price' ) );

		foreach ( Orders::exportRows( $serviceDate ) as $row ) {
			csv_row(
				$out,
				array(
					$serviceDate,
					$row['location_name'],
					$row['item_name'],
					$row['qty'],
					$row['person_name'],
					$row['group_name'],
					$row['account_name'],
					$row['email'],
					$row['order_id'],
					Money::format( (int) $row['unit_price_cents'] ),
				)
			);
		}

		fclose( $out );
		exit;
	}
);

/* ----------------------------------------------------------------- Settings */

$router->get(
	'/admin/settings',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		echo View::render(
			'admin/settings',
			array(
				'title'     => 'Settings',
				'settings'  => Settings::all(),
				'locations' => Menu::locations(),
				'methods'   => Payments::all(),
				'groups'    => Groups::all(),
				'orphaned'  => Groups::orphaned(),
				'blackouts' => Blackouts::all(),
				'zeffyOn'   => Zeffy::isConfigured(),
				'kitchenOn' => Kitchen::isProtected(),
			)
		);
	}
);

$router->post(
	'/admin/settings',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$mode = $post( 'active_mode' );

		Settings::setMany(
			array(
				'active_mode'              => array_key_exists( $mode, Schedule::modes() ) ? $mode : Schedule::MODE_PLANNED,
				'service_weekday'          => (string) max( 0, min( 6, (int) ( $_POST['service_weekday'] ?? 0 ) ) ),
				'open_days_before'         => (string) max( 0, min( 30, (int) ( $_POST['open_days_before'] ?? 5 ) ) ),
				'open_time'                => sanitise_time( $post( 'open_time' ), '12:00' ),
				'close_days_before'        => (string) max( 0, min( 30, (int) ( $_POST['close_days_before'] ?? 1 ) ) ),
				'close_weekday'            => (string) max( 0, min( 6, (int) ( $_POST['close_weekday'] ?? 6 ) ) ),
				'close_time'               => sanitise_time( $post( 'close_time' ), '13:00' ),
				'service_days_after_close' => (string) max( 0, min( 14, (int) ( $_POST['service_days_after_close'] ?? 1 ) ) ),
				'preview_upcoming'         => isset( $_POST['preview_upcoming'] ) ? 'yes' : 'no',
				'allow_registration'       => isset( $_POST['allow_registration'] ) ? 'yes' : 'no',
				'allow_negative_balance'   => isset( $_POST['allow_negative_balance'] ) ? 'yes' : 'no',
				'default_price'            => $post( 'default_price', '10.00' ),
				'menu_heading'             => $post( 'menu_heading' ),
				'menu_note'                => $post( 'menu_note' ),
			)
		);

		/*
		 * Payment toggles come from the register rather than a fixed list, so a
		 * method added later gets its switch here without this code changing.
		 */
		$wanted = (array) ( $_POST['payment'] ?? array() );
		$keep   = array();

		foreach ( Payments::all() as $id => $method ) {
			if ( isset( $wanted[ $id ] ) ) {
				$keep[] = $id;
			}
		}

		// Turning everything off would leave nobody able to order, and no way to
		// undo it from the storefront. Refuse rather than strand the site.
		if ( ! $keep ) {
			View::flash( 'error', 'At least one payment method has to stay switched on. Settings saved, payment methods unchanged.' );
			View::redirect( '/admin/settings' );
		}

		foreach ( Payments::all() as $id => $method ) {
			Settings::set( Payments::settingKey( $id ), in_array( $id, $keep, true ) ? 'yes' : 'no' );
		}

		View::flash( 'success', 'Settings saved.' );
		View::redirect( '/admin/settings' );
	}
);

function sanitise_time( string $value, string $fallback ): string {
	return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ? $value : $fallback;
}

/**
 * Writes one CSV row.
 *
 * $escape must be passed explicitly: PHP 8.4 deprecates relying on its default,
 * and the deprecation notice is emitted straight into the output stream, which
 * corrupts the downloaded file. Empty string is both what PHP 9 will default to
 * and the RFC 4180 behaviour.
 *
 * @param resource $handle
 */
function csv_row( $handle, array $fields ): void {
	fputcsv( $handle, $fields, ',', '"', '' );
}

$router->post(
	'/admin/locations/add',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$name = $post( 'name' );

		if ( '' !== $name ) {
			Menu::addLocation( $name );
			View::flash( 'success', $name . ' added.' );
		}

		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/locations/{id}/delete',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		Menu::deleteLocation( (int) $id );

		View::flash( 'success', 'Location removed, along with its dishes.' );
		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/kitchen-password',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$password = (string) ( $_POST['password'] ?? '' );

		if ( isset( $_POST['clear'] ) ) {
			Kitchen::setPassword( '' );
			View::flash( 'success', 'Shared password cleared. The kitchen list is organisers only again.' );
			View::redirect( '/admin/settings' );
		}

		if ( strlen( $password ) < 6 ) {
			View::flash( 'error', 'The shared password needs to be at least 6 characters.' );
			View::redirect( '/admin/settings' );
		}

		Kitchen::setPassword( $password );

		View::flash( 'success', 'Shared password set. Anyone with it can now see the kitchen list.' );
		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/groups/add',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$name = $post( 'name' );

		if ( '' === $name ) {
			View::flash( 'error', 'Give the group a name.' );
		} elseif ( 0 === Groups::add( $name ) ) {
			View::flash( 'error', 'There is already a group called ' . $name . '.' );
		} else {
			View::flash( 'success', $name . ' added.' );
		}

		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/groups/{id}/rename',
	static function ( string $id ) use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		if ( Groups::rename( (int) $id, $post( 'name' ) ) ) {
			View::flash( 'success', 'Group renamed, and everyone in it moved across.' );
		} else {
			View::flash( 'error', 'That name is blank or already taken.' );
		}

		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/groups/{id}/delete',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		$group = Groups::find( (int) $id );

		if ( $group ) {
			Groups::delete( (int) $id );

			View::flash(
				'success',
				$group['name'] . ' removed from the list. Anyone already in it keeps it until you change them.'
			);
		}

		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/blackouts/add',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		Blackouts::add( $post( 'service_date' ), $post( 'label' ) );

		View::flash( 'success', 'Blackout saved.' );
		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/blackouts/remove',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		Blackouts::remove( $post( 'service_date' ) );

		View::flash( 'success', 'Blackout removed.' );
		View::redirect( '/admin/settings' );
	}
);

$router->post(
	'/admin/zeffy/reconcile',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		$result = Zeffy::reconcile();

		View::flash( $result['ok'] ? 'success' : 'error', $result['message'] );
		View::redirect( '/admin/settings' );
	}
);
