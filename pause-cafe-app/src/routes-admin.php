<?php
/**
 * Admin routes.
 *
 * Included from the front controller, so it shares $router and the guards.
 * `use` is per-file in PHP, hence the imports below.
 */

declare(strict_types=1);

use PauseCafe\AdminNav;
use PauseCafe\Auth;
use PauseCafe\Blackouts;
use PauseCafe\Csrf;
use PauseCafe\Design;
use PauseCafe\Groups;
use PauseCafe\Identities;
use PauseCafe\Images;
use PauseCafe\Kitchen;
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
use PauseCafe\Schedules;
use PauseCafe\Settings;
use PauseCafe\SignIn;
use PauseCafe\Themes;
use PauseCafe\Users;
use PauseCafe\View;
use PauseCafe\Wallet;
use PauseCafe\Zeffy;

/* ---------------------------------------------------------------- Dashboard */

$router->get(
	'/admin',
	static function () use ( $requireAdmin, $query ): void {
		$requireAdmin();

		$dates       = organiser_service_dates();
		$requested   = $query( 'date' );
		$serviceDate = in_array( $requested, $dates, true )
			? $requested
			: ( Menu::currentServiceDate() ?? ( $dates ? (string) end( $dates ) : '' ) );

		echo View::render(
			'admin/dashboard',
			array(
				'title'       => 'Organiser',
				'pending'     => Users::pendingCount(),
				'serviceDate' => $serviceDate,
				'dates'       => $dates,
				'summary'     => '' !== $serviceDate ? Orders::summaryForServiceDate( $serviceDate ) : array(),
				'unpaid'      => '' !== $serviceDate ? Orders::unpaidForServiceDate( $serviceDate ) : array(),
				'outstanding' => Wallet::totalOutstanding(),
				'mode'        => Schedule::activeMode(),
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

		$wasApproved = 1 === (int) $target['is_approved'];
		$nowApproved = isset( $_POST['is_approved'] );

		Users::update(
			$userId,
			array(
				'name'        => $post( 'name' ),
				'group_name'  => Groups::sanitise( $post( 'group_name' ) ),
				'role'        => $role,
				'is_approved' => $nowApproved ? 1 : 0,
			)
		);

		// Only on the transition, so re-saving an approved account does not tell
		// them again every time.
		if ( ! $wasApproved && $nowApproved ) {
			Notifications::accountApproved( $userId );
		}

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
				'schedules' => Schedules::all(),
				'mode'      => Schedule::activeMode(),
				'affected'  => 0,
			)
		);
	}
);

/*
 * Registered before /admin/menu/{id}: the router takes the first matching
 * pattern, and {id} would otherwise swallow "builder".
 */
$router->get(
	'/admin/menu/builder',
	static function () use ( $requireAdmin, $query ): void {
		$requireAdmin();

		$scheduleId = (int) $query( 'schedule', (string) Schedules::DEFAULT_ID );

		if ( ! Schedules::exists( $scheduleId ) ) {
			$scheduleId = Schedules::DEFAULT_ID;
		}

		$rules     = Schedules::rulesFor( $scheduleId );
		$mode      = (string) $rules['mode'];
		$locations = Schedules::locationsFor( $scheduleId );
		$month     = $query( 'month' );

		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', $month, $matches ) ) {
			$now     = Schedule::now();
			$matches = array( '', $now->format( 'Y' ), $now->format( 'm' ) );
			$month   = $now->format( 'Y-m' );
		}

		$year  = (int) $matches[1];
		$index = (int) $matches[2];

		$cursor   = ( new DateTimeImmutable( $year . '-' . $index . '-01', Schedule::timezone() ) );
		$previous = $cursor->modify( '-1 month' )->format( 'Y-m' );
		$next     = $cursor->modify( '+1 month' )->format( 'Y-m' );

		// On-publish has no calendar to fill in: there is only the menu you are
		// about to put live, so the grid collapses to a single row.
		$dates = Schedule::MODE_ON_PUBLISH === $mode
			? array()
			: Schedule::serviceDatesInMonth( $year, $index, (int) $rules['service_weekday'] );

		$current = Menu::currentServiceDate( $scheduleId );
		$rows    = array();

		foreach ( $dates as $date ) {
			$cells = array();

			foreach ( $locations as $location ) {
				$cells[ (int) $location['id'] ] = Menu::itemBySlot( $date, (int) $location['id'], $scheduleId );
			}

			$rows[ $date ] = $cells;
		}

		$live = array();

		if ( Schedule::MODE_ON_PUBLISH === $mode && $current ) {
			foreach ( $locations as $location ) {
				$found = Menu::itemsForServiceDate( $current, (int) $location['id'], false, $scheduleId );

				$live[ (int) $location['id'] ] = $found ? $found[0] : null;
			}
		}

		echo View::render(
			'admin/menu-builder',
			array(
				'title'      => 'Build menu',
				'mode'       => $mode,
				'month'      => $month,
				'monthName'  => $cursor->format( 'F Y' ),
				'previous'   => $previous,
				'next'       => $next,
				'locations'  => $locations,
				'rows'       => $rows,
				'live'       => $live,
				'names'      => Menu::distinctNames(),
				'today'      => Schedule::now()->format( 'Y-m-d' ),
				'schedules'  => Schedules::all(),
				'scheduleId' => $scheduleId,
				'rules'      => $rules,
			)
		);
	}
);

$router->post(
	'/admin/menu/builder',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$month      = $post( 'month' );
		$scheduleId = (int) $post( 'schedule', (string) Schedules::DEFAULT_ID );

		if ( ! Schedules::exists( $scheduleId ) ) {
			$scheduleId = Schedules::DEFAULT_ID;
		}

		MenuChanges::forget();
		MenuChanges::setNotify( wants_change_emails() );

		$tally = MenuBuilder::save(
			(array) ( $_POST['dish'] ?? array() ),
			(array) ( $_POST['from'] ?? array() ),
			(array) ( $_POST['until'] ?? array() ),
			$scheduleId
		);

		View::flash(
			'success',
			trim(
				sprintf(
					'Menu saved. %d added, %d updated, %d moved to draft. ',
					$tally['created'],
					$tally['updated'],
					$tally['drafted']
				) . menu_change_note()
			)
		);

		View::redirect(
			'/admin/menu/builder?schedule=' . $scheduleId .
			( '' !== $month ? '&month=' . urlencode( $month ) : '' )
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
				'schedules' => Schedules::all(),
				'mode'      => Schedule::activeMode(),
				'affected'  => count( MenuChanges::affected( (int) $item['id'] ) ),
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
			'field_rules'  => MenuFields::rulesFromPost( $_POST ),
			'schedule_id'  => (int) ( $_POST['schedule_id'] ?? Schedules::DEFAULT_ID ),
			'standalone'   => isset( $_POST['standalone'] ) ? 1 : 0,
		);

		// A schedule deleted between opening the form and saving it would
		// otherwise leave the dish pointing at rules that no longer exist.
		if ( ! Schedules::exists( $data['schedule_id'] ) ) {
			$data['schedule_id'] = Schedules::DEFAULT_ID;
		}

		$existing = $id ? Menu::item( $id ) : null;

		if ( $id ) {
			$data['opened_at'] = $existing ? (string) $existing['opened_at'] : '';
		}

		/*
		 * The picture. Absent from the form means "leave it alone", which is
		 * what a save that only changed the price should do -- so the existing
		 * value is carried forward unless something explicitly replaces or
		 * removes it.
		 */
		$data['image_path'] = $existing ? (string) ( $existing['image_path'] ?? '' ) : '';

		if ( isset( $_POST['remove_image'] ) ) {
			Images::forget( $data['image_path'] );
			$data['image_path'] = '';
		} elseif ( isset( $_FILES['image'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			try {
				$uploaded = Images::accept( $_FILES['image'] );

				// The old one is only removed once the new one is safely on
				// disk, so a failed upload does not leave the dish with none.
				Images::forget( $data['image_path'] );
				$data['image_path'] = $uploaded;
			} catch ( \RuntimeException $e ) {
				View::flash( 'error', $e->getMessage() . ' Nothing else was saved.' );
				View::redirect( $id ? '/admin/menu/' . $id : '/admin/menu/new' );
			}
		}

		// In on-publish mode a brand new published dish opens ordering there and
		// then -- that is the whole point of the mode.
		$openNow = Schedule::MODE_ON_PUBLISH === Schedule::activeMode()
			&& 'published' === $data['status']
			&& '' === $data['opened_at'];

		if ( $openNow || isset( $_POST['reopen'] ) ) {
			$data['opened_at'] = Schedule::now()->format( 'Y-m-d H:i:s' );
		}

		MenuChanges::forget();
		MenuChanges::setNotify( wants_change_emails() );

		$savedId = Menu::save( $data, $id ?: null );

		View::flash( 'success', trim( 'Saved. ' . menu_change_note() ) );
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

		$dates       = organiser_service_dates();
		$requested   = $query( 'date' );
		$serviceDate = in_array( $requested, $dates, true )
			? $requested
			: ( Menu::currentServiceDate() ?? ( $dates ? (string) end( $dates ) : '' ) );

		$status = $query( 'status' );

		if ( ! in_array( $status, array( Orders::STATUS_CONFIRMED, Orders::STATUS_CANCELLED, 'all' ), true ) ) {
			$status = Orders::STATUS_CONFIRMED;
		}

		$orders = $serviceDate
			? Orders::forServiceDate( $serviceDate, 'all' === $status ? '' : $status )
			: array();

		echo View::render(
			'admin/orders',
			array(
				'title'       => 'Orders',
				'serviceDate' => $serviceDate,
				'dates'       => $dates,
				'status'      => $status,
				'orders'      => $orders,
				// Which dishes on this date are no longer on the menu, so the
				// page can say so rather than leaving an organiser to wonder
				// why a dish they cannot find still has orders against it.
				'retired'     => $serviceDate ? Orders::retiredDishes( $serviceDate ) : array(),
			)
		);
	}
);

/**
 * One action applied to whichever orders were ticked.
 *
 * Every branch works from the same validated set, and an id that is not on the
 * date being shown is dropped rather than acted on — the form is the only thing
 * that says which orders these are, and it arrives from a browser.
 */
$router->post(
	'/admin/orders/bulk',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$serviceDate = $post( 'date' );
		$status      = $post( 'status', Orders::STATUS_CONFIRMED );
		$back        = '/admin/orders?date=' . urlencode( $serviceDate ) . '&status=' . urlencode( $status );

		$wanted = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );

		if ( ! $wanted ) {
			View::flash( 'notice', 'Nothing was ticked, so nothing happened.' );
			View::redirect( $back );
		}

		// Only orders actually on this date, so a posted id cannot reach an
		// order the organiser was not looking at.
		$onThisDate = array();

		foreach ( Orders::forServiceDate( $serviceDate, '' ) as $candidate ) {
			$onThisDate[ (int) $candidate['id'] ] = $candidate;
		}

		$chosen = array();

		foreach ( $wanted as $id ) {
			if ( isset( $onThisDate[ $id ] ) ) {
				$chosen[] = $onThisDate[ $id ];
			}
		}

		if ( ! $chosen ) {
			View::flash( 'error', 'Those orders are not on this date.' );
			View::redirect( $back );
		}

		switch ( $post( 'action' ) ) {
			case 'paid':
			case 'unpaid':
				$paid = 'paid' === $post( 'action' );
				$done = 0;

				foreach ( $chosen as $order ) {
					if ( Orders::STATUS_CANCELLED === $order['status'] ) {
						continue;
					}

					Orders::markPaid( (int) $order['id'], $paid );
					++$done;
				}

				View::flash( 'success', $done . ' order(s) marked ' . ( $paid ? 'paid' : 'unpaid' ) . '.' );
				break;

			case 'cancel':
				$done    = 0;
				$skipped = 0;

				foreach ( $chosen as $order ) {
					if ( Orders::STATUS_CANCELLED === $order['status'] ) {
						++$skipped;

						continue;
					}

					// The single-order path, once per order: same refund, same
					// email. Cancelling in bulk must not mean cancelling by a
					// different set of rules.
					Orders::cancel( (int) $order['id'], Auth::id() );
					Notifications::orderCancelled( (int) $order['id'] );
					++$done;
				}

				View::flash(
					'success',
					$done . ' order(s) cancelled, refunded where they were paid from a wallet, and everyone told.'
					. ( $skipped > 0 ? ' ' . $skipped . ' were already cancelled.' : '' )
				);
				break;

			case 'resend':
				$done = 0;

				foreach ( $chosen as $order ) {
					if ( Orders::STATUS_CANCELLED === $order['status'] ) {
						continue;
					}

					Notifications::orderPlaced( (int) $order['id'] );
					++$done;
				}

				View::flash( 'success', 'Confirmation re-sent for ' . $done . ' order(s).' );
				break;

			case 'export':
				orders_csv( $chosen, $serviceDate );
				exit;

			default:
				View::flash( 'error', 'Pick something to do with them.' );
		}

		View::redirect( $back );
	}
);

/* ------------------------------------------------------- Editing one order */

$router->get(
	'/admin/orders/{id}/edit',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();

		$orderId = (int) $id;
		$order   = Orders::find( $orderId );

		if ( ! $order ) {
			http_response_code( 404 );
			echo View::render( 'error', array( 'title' => 'Not found', 'message' => 'No such order.' ) );

			return;
		}

		echo View::render(
			'admin/order-edit',
			array(
				'title'       => 'Order #' . $orderId,
				'order'       => $order,
				'lines'       => Orders::lines( $orderId ),
				'adjustments' => Orders::adjustments( $orderId ),
				'refundable'  => Orders::refundableCents( $orderId ),
				'balance'     => Wallet::balance( (int) $order['user_id'] ),
				// Anything else being served that day, to add to the order.
				'available'   => Menu::itemsForServiceDate( (string) $order['service_date'] ),
			)
		);
	}
);

/**
 * Every edit to one order arrives here.
 *
 * One route rather than four, because they share the guard, the redirect and
 * the "tell them what changed" step, and because an organiser pressing two
 * buttons on one screen should not have to think about which endpoint each one
 * goes to.
 */
$router->post(
	'/admin/orders/{id}/edit',
	static function ( string $id ) use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$orderId = (int) $id;
		$back    = '/admin/orders/' . $orderId . '/edit';
		$before  = Orders::find( $orderId );

		if ( ! $before ) {
			View::redirect( '/admin/orders' );
		}

		try {
			switch ( $post( 'action' ) ) {
				case 'qty':
					Orders::setLineQty(
						$orderId,
						(int) ( $_POST['line_id'] ?? 0 ),
						(int) ( $_POST['qty'] ?? 0 ),
						Auth::id()
					);

					$note = 'Quantity changed.';
					break;

				case 'details':
					Orders::setLineDetails( $orderId, (int) ( $_POST['line_id'] ?? 0 ), $_POST );

					$note = 'Details corrected. Nothing was charged or refunded.';
					break;

				case 'add':
					Orders::addLine(
						$orderId,
						(int) ( $_POST['menu_item_id'] ?? 0 ),
						(int) ( $_POST['qty'] ?? 1 ),
						$_POST,
						Auth::id()
					);

					$note = 'Dish added.';
					break;

				case 'refund':
					Orders::refundAmount( $orderId, Money::parse( $post( 'amount' ) ), $post( 'reason' ), Auth::id() );

					$note = 'Refunded.';
					break;

				default:
					View::flash( 'error', 'Pick something to change.' );
					View::redirect( $back );
			}
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
			View::redirect( $back );
		}

		$after = Orders::find( $orderId );

		// Only worth an email when the order actually moved. Correcting a
		// spelling is not news, and a message for every keystroke trains people
		// to ignore the ones that matter.
		if ( (int) $before['total_cents'] !== (int) $after['total_cents'] && ! isset( $_POST['quiet'] ) ) {
			Notifications::orderChanged( $orderId, (int) $before['total_cents'] );
			$note .= ' They have been emailed.';
		}

		View::flash( 'success', $note );
		View::redirect( $back );
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
				'dates'       => organiser_service_dates(),
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

		$orderId = (int) $id;
		$order   = Orders::find( $orderId );
		$paid    = $order && Orders::isPaid( $order );

		try {
			Orders::cancel( $orderId, Auth::id() );

			$refund = Orders::refundEntryFor( $orderId );

			if ( $refund ) {
				$message = 'Order cancelled. ' . Money::format( (int) $refund['delta_cents'] ) .
					' went back to their wallet, leaving ' .
					Money::format( Wallet::balance( (int) $order['user_id'] ) ) . '.';
			} elseif ( Orders::refundedCents( $orderId ) > 0 ) {
				/*
				 * Charged, and every penny of it already returned before the
				 * cancellation -- so there was nothing left to send back. Saying
				 * "nothing had been charged" here would be plainly untrue to an
				 * organiser looking at the order's refund history.
				 */
				$message = 'Order cancelled. ' . Money::format( Orders::refundedCents( $orderId ) ) .
					' had already been refunded, so there was nothing left to give back.';
			} elseif ( $paid ) {
				// Money collected outside the wallet is not something the system
				// can hand back.
				$message = 'Order cancelled. It was already paid outside the wallet, so return the money in person.';
			} else {
				$message = 'Order cancelled. Nothing had been charged, so there is nothing to refund.';
			}

			Notifications::orderCancelled( $orderId );

			View::flash( 'success', $message . ' They have been emailed.' );
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

		$dates       = organiser_service_dates();
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

/* ------------------------------------------------------------------- Fields */

$router->get(
	'/admin/fields',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		echo View::render(
			'admin/fields',
			array(
				'title'  => 'Order fields',
				'fields' => MenuFields::definitions(),
			)
		);
	}
);

$router->post(
	'/admin/fields/save',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		try {
			MenuFields::save(
				array(
					'field_key'   => $post( 'field_key' ),
					'label'       => $post( 'label' ),
					'type'        => $post( 'type' ),
					'options'     => (string) ( $_POST['options'] ?? '' ),
					'placeholder' => $post( 'placeholder' ),
					'is_shown'    => isset( $_POST['is_shown'] ),
					'is_required' => isset( $_POST['is_required'] ),
					'sort_order'  => (int) ( $_POST['sort_order'] ?? 0 ),
				),
				( (int) ( $_POST['id'] ?? 0 ) ) ?: null
			);

			View::flash( 'success', 'Field saved.' );
		} catch ( \RuntimeException $e ) {
			View::flash( 'error', $e->getMessage() );
		}

		View::redirect( '/admin/fields' );
	}
);

$router->post(
	'/admin/fields/{id}/delete',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		// Called once and held: delete() removes the row, so asking twice would
		// report failure on the second look.
		$removed = MenuFields::delete( (int) $id );

		View::flash(
			$removed ? 'success' : 'error',
			$removed
				? 'Field removed. Answers already given to it are kept on those orders.'
				: 'That field is built in and cannot be removed. Set it to "do not ask" instead.'
		);

		View::redirect( '/admin/fields' );
	}
);

/* ---------------------------------------------------------------- Schedules */

$router->get(
	'/admin/schedules',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		$assigned = array();

		foreach ( Schedules::named() as $id => $rules ) {
			$assigned[ $id ] = array_map( 'intval', array_column( Schedules::locationsFor( $id ), 'id' ) );
		}

		echo View::render(
			'admin/schedules',
			array(
				'title'     => 'Schedules',
				'schedules' => Schedules::all(),
				'locations' => Menu::locations(),
				'assigned'  => $assigned,
			)
		);
	}
);

$router->post(
	'/admin/schedules/save',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$id = (int) ( $_POST['id'] ?? 0 );

		// The default schedule's rules live in settings, so this screen only ever
		// writes named ones.
		if ( Schedules::DEFAULT_ID === $id && '' === $post( 'name' ) ) {
			View::flash( 'error', 'Give the schedule a name.' );
			View::redirect( '/admin/schedules' );
		}

		$savedId = Schedules::save(
			array(
				'name'                     => $post( 'name' ),
				'mode'                     => $post( 'mode' ),
				'service_weekday'          => (int) ( $_POST['service_weekday'] ?? 0 ),
				'open_days_before'         => (int) ( $_POST['open_days_before'] ?? 5 ),
				'open_time'                => $post( 'open_time' ),
				'close_days_before'        => (int) ( $_POST['close_days_before'] ?? 1 ),
				'close_time'               => $post( 'close_time' ),
				'close_weekday'            => (int) ( $_POST['close_weekday'] ?? 6 ),
				'service_days_after_close' => (int) ( $_POST['service_days_after_close'] ?? 1 ),
				'preview_upcoming'         => isset( $_POST['preview_upcoming'] ),
				'show_on_front'            => isset( $_POST['show_on_front'] ),
				'field_rules'              => MenuFields::rulesFromPost( $_POST ),
				'sort_order'               => (int) ( $_POST['sort_order'] ?? 0 ),
			),
			$id ?: null
		);

		Schedules::setLocations( $savedId, array_map( 'intval', (array) ( $_POST['locations'] ?? array() ) ) );

		View::flash( 'success', 'Schedule saved.' );
		View::redirect( '/admin/schedules' );
	}
);

$router->post(
	'/admin/schedules/{id}/delete',
	static function ( string $id ) use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		Schedules::delete( (int) $id );

		View::flash( 'success', 'Schedule removed. Its dishes now follow the default schedule.' );
		View::redirect( '/admin/schedules' );
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
				'mailers'   => Mailer::all(),
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
				'front_grid_columns'       => (string) max( 1, min( 6, (int) ( $_POST['front_grid_columns'] ?? 3 ) ) ),
				'default_show_on_front'    => isset( $_POST['default_show_on_front'] ) ? 'yes' : 'no',
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

/**
 * Whether the organiser left the "email anyone who already ordered" box ticked.
 *
 * An unticked checkbox is not submitted at all, so the form carries a companion
 * hidden field. Without it there would be no way to tell "unticked" from "this
 * form has no such control", and the safe reading of those two differs.
 */
function wants_change_emails(): bool {
	if ( ! isset( $_POST['notify_present'] ) ) {
		return true;
	}

	return isset( $_POST['notify_orders'] );
}

/**
 * The sentence about who was, or was not, emailed. Empty when nothing that
 * anyone had ordered changed.
 */
function menu_change_note(): string {
	$notified   = MenuChanges::totalNotified();
	$suppressed = MenuChanges::totalSuppressed();

	if ( $notified > 0 ) {
		return sprintf(
			'%d %s already ordered a changed dish and %s been emailed.',
			$notified,
			1 === $notified ? 'person had' : 'people had',
			1 === $notified ? 'has' : 'have'
		);
	}

	if ( $suppressed > 0 ) {
		return sprintf(
			'%d %s already ordered a changed dish and %s not emailed, as you asked.',
			$suppressed,
			1 === $suppressed ? 'person had' : 'people had',
			1 === $suppressed ? 'was' : 'were'
		);
	}

	return '';
}

/**
 * Every date an organiser might need to open, newest handling first.
 *
 * The union of what is on the menu and what has been ordered, and the union is
 * the point. Menu::serviceDates() lists dates with a *published* dish, and
 * deleting a dish that has been sold drafts it rather than removing it -- so
 * the date it was served on could disappear from every picker while its orders,
 * and the money taken for them, were still sitting in the database with no way
 * to reach them.
 *
 * @return string[] Ascending.
 */
function organiser_service_dates(): array {
	$dates = array_unique( array_merge( Menu::serviceDates(), Orders::serviceDates() ) );

	sort( $dates );

	return array_values( $dates );
}

/**
 * Sends the ticked orders as a CSV and stops.
 *
 * One line per meal rather than per order, because that is the shape anyone
 * opening it wants — the same shape the kitchen export uses.
 *
 * @param array[] $orders Rows from Orders::forServiceDate().
 */
function orders_csv( array $orders, string $serviceDate ): void {
	nocache_headers_compat();

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=orders-' . ( $serviceDate ?: 'selected' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );

	// Excel needs the BOM to read the Chinese dish names correctly.
	fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

	csv_row(
		$out,
		array( 'Order', 'Status', 'Date', 'Account', 'Email', 'Group', 'Dish', 'Qty', 'For', 'Meal note', 'Payment', 'Paid', 'Line total' )
	);

	foreach ( $orders as $order ) {
		foreach ( Orders::lines( (int) $order['id'] ) as $line ) {
			csv_row(
				$out,
				array(
					$order['id'],
					$order['status'],
					$order['service_date'],
					$order['user_name'],
					$order['user_email'],
					$order['user_group'],
					$line['item_name'],
					$line['qty'],
					$line['person_name'],
					$line['note'] ?? '',
					Payments::label( (string) $order['payment_method'] ),
					Orders::isPaid( $order ) ? 'yes' : 'no',
					Money::format( (int) $line['unit_price_cents'] * (int) $line['qty'] ),
				)
			);
		}
	}

	fclose( $out );
}

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
	'/admin/mail',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$transport = $post( 'mail_transport' );

		Settings::setMany(
			array(
				'mail_enabled'    => isset( $_POST['mail_enabled'] ) ? 'yes' : 'no',
				'mail_transport'  => Mailer::get( $transport ) ? $transport : 'php',
				'mail_from_name'  => $post( 'mail_from_name' ),
				'mail_from_email' => $post( 'mail_from_email' ),
			)
		);

		/*
		 * Fields come from each transport rather than a fixed list, so adding a
		 * transport does not mean editing this handler.
		 */
		foreach ( Mailer::all() as $candidate ) {
			foreach ( $candidate->configFields() as $key => $field ) {
				if ( ! array_key_exists( $key, $_POST ) ) {
					continue;
				}

				$value = trim( (string) $_POST[ $key ] );

				// A blank password box means "leave it alone", not "erase it" --
				// the form never renders the stored value back.
				if ( 'password' === $field['type'] && '' === $value ) {
					continue;
				}

				Settings::set( $key, $value );
			}
		}

		View::flash( 'success', 'Email settings saved.' );
		View::redirect( '/admin/settings' );
	}
);

/**
 * Which way this organiser wants the menu. Theirs alone — it is on the account,
 * not in settings, so it cannot rearrange anybody else's screen.
 */
$router->post(
	'/admin/nav',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		AdminNav::setStyle( Auth::id(), $post( 'style' ) );
		Auth::refresh();

		// Straight back where they were, so flipping it does not also navigate.
		$back = (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
		$path = '/' . trim( (string) parse_url( $back, PHP_URL_PATH ), '/' );

		View::redirect( AdminNav::appliesTo( $path ) ? $path : '/admin' );
	}
);

/* -------------------------------------------------------------------------
 * Design
 * ---------------------------------------------------------------------- */

$router->get(
	'/admin/design',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		echo View::render(
			'admin/design',
			array(
				'title'   => 'Design',
				'groups'  => Design::grouped(),
				'presets' => Design::presets(),
				'themes'  => Themes::all(),
				'active'  => Themes::slug(),
			)
		);
	}
);

$router->post(
	'/admin/design',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		// A preset is a shortcut for filling the form in, so it is applied
		// first and whatever else was submitted lands on top of it.
		$preset = $post( 'preset' );

		if ( '' !== $preset && Design::applyPreset( $preset ) ) {
			View::flash( 'success', 'Applied the ' . Design::presets()[ $preset ]['label'] . ' look. Adjust anything you like.' );
			View::redirect( '/admin/design' );
		}

		if ( '' !== $post( 'reset' ) ) {
			Design::reset();
			View::flash( 'success', 'Put everything back to the defaults.' );
			View::redirect( '/admin/design' );
		}

		/*
		 * Driven by the token list rather than a fixed set of fields, so a new
		 * token needs no change here. Design::set() validates each one and
		 * ignores anything that does not fit, which is what keeps a colour
		 * field from becoming a way to write arbitrary CSS.
		 */
		foreach ( Design::tokens() as $key => $token ) {
			if ( ! array_key_exists( $key, $_POST ) ) {
				continue;
			}

			Design::set( $key, (string) $_POST[ $key ] );
		}

		$theme = $post( 'design_theme' );

		if ( ! Themes::isValid( $theme ) ) {
			/*
			 * Said rather than swallowed. Themes::slug() would refuse this
			 * anyway, but silently keeping the old theme after being told to
			 * change it is the kind of thing an organiser spends an afternoon
			 * on before working out the name was wrong.
			 */
			View::flash( 'error', 'Everything else was saved, but there is no theme called “' . $theme . '”.' );
			View::redirect( '/admin/design' );
		}

		Settings::set( 'design_theme', $theme );

		View::flash( 'success', 'Design saved.' );
		View::redirect( '/admin/design' );
	}
);

/* -------------------------------------------------------------------------
 * Signing in
 * ---------------------------------------------------------------------- */

$router->get(
	'/admin/signin',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();

		echo View::render(
			'admin/sign-in',
			array(
				'title'   => 'Signing in',
				'methods' => SignIn::all(),
				'links'   => Identities::all(),
				'pending' => Identities::pendingLinks(),
			)
		);
	}
);

$router->post(
	'/admin/signin',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		$wanted = (array) ( $_POST['enabled'] ?? array() );

		foreach ( SignIn::all() as $id => $method ) {
			Settings::set( SignIn::settingKey( $id ), isset( $wanted[ $id ] ) ? 'yes' : 'no' );

			/*
			 * Fields come from the method rather than a fixed list, so a new
			 * one needs no change here -- the same arrangement as the mail
			 * transports above.
			 */
			foreach ( $method->fields() as $key => $field ) {
				if ( ! array_key_exists( $key, $_POST ) ) {
					continue;
				}

				$value = trim( (string) $_POST[ $key ] );

				// A blank secret box means "leave it alone", not "erase it".
				if ( 'password' === $field['type'] && '' === $value ) {
					continue;
				}

				Settings::set( $key, $value );
			}
		}

		Settings::set(
			'signin_external_create',
			isset( $_POST['signin_external_create'] ) ? 'yes' : 'no'
		);

		/*
		 * The one setting on this screen that can lock everybody out, so it is
		 * the one that does not simply do as it is told.
		 *
		 * Switching the rescue off is only allowed once an organiser has
		 * actually signed in through a provider. Until then it is the only way
		 * in that is known to work -- a provider whose settings are filled in
		 * has proved nothing, and a typo in a client secret looks exactly like
		 * a working configuration until somebody tries it.
		 */
		$keepRescue = isset( $_POST['signin_admin_rescue'] );
		$refused    = ! $keepRescue && ! SignIn::rescueMayBeDisabled();

		Settings::set( 'signin_admin_rescue', $keepRescue || $refused ? 'yes' : 'no' );

		if ( $refused ) {
			View::flash(
				'error',
				'Everything else was saved, but the organiser password has been left on. '
				. SignIn::rescueLockReason()
			);
			View::redirect( '/admin/signin' );
		}

		View::flash( 'success', 'Sign-in settings saved.' );
		View::redirect( '/admin/signin' );
	}
);

$router->post(
	'/admin/signin/link',
	static function () use ( $requireAdmin, $post ): void {
		$requireAdmin();
		Csrf::verify();

		$id = (int) ( $_POST['id'] ?? 0 );

		if ( 'approve' !== $post( 'decision' ) ) {
			Identities::declineLink( $id );

			View::flash( 'success', 'That request was turned down. Nothing was joined up.' );
			View::redirect( '/admin/signin' );
		}

		$request = Identities::pendingLink( $id );

		if ( ! $request || ! Identities::approveLink( $id ) ) {
			View::flash( 'error', 'That request is no longer there.' );
			View::redirect( '/admin/signin' );
		}

		View::flash(
			'success',
			'Joined up. ' . $request['email'] . ' can now sign in through '
			. SignIn::label( (string) $request['provider'] ) . ' — they will need to go back and try again.'
		);

		View::redirect( '/admin/signin' );
	}
);

$router->post(
	'/admin/signin/unlink',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		Identities::unlink( (int) ( $_POST['id'] ?? 0 ) );

		View::flash( 'success', 'That external account has been unlinked.' );
		View::redirect( '/admin/signin' );
	}
);

$router->post(
	'/admin/mail/test',
	static function () use ( $requireAdmin ): void {
		$requireAdmin();
		Csrf::verify();

		$admin  = Auth::user();
		$result = Notifications::test( (string) $admin['email'], (string) $admin['name'] );

		if ( $result->ok ) {
			View::flash(
				'success',
				'Test sent to ' . $admin['email'] . ' via ' . Mailer::label( $result->transport ) . '. ' .
				( $result->viaFallback ? 'The chosen transport failed, so PHP mail() was used instead.' : $result->message )
			);
		} else {
			View::flash( 'error', 'Could not send: ' . $result->message );
		}

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
