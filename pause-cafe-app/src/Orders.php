<?php

namespace PauseCafe;

/**
 * Placing and reading orders.
 *
 * An order and its wallet debit are written in one transaction. They must
 * either both happen or neither: a debit without an order is money taken for
 * nothing, and an order without a debit is food given away.
 */
class Orders {

	/** The two states an order can be in. Cancelled rows are kept, never deleted. */
	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_CANCELLED = 'cancelled';

	/*
	 * SQLite only takes a write lock up front with BEGIN IMMEDIATE, which PDO
	 * cannot issue through beginTransaction(). Driving it with exec() means PDO
	 * never registers a transaction, so commit() and rollBack() would throw --
	 * hence these three, which must be used as a set.
	 */

	private static function begin( \PDO $pdo ): void {
		$pdo->exec( 'BEGIN IMMEDIATE' );
	}

	private static function commit( \PDO $pdo ): void {
		$pdo->exec( 'COMMIT' );
	}

	private static function rollback( \PDO $pdo ): void {
		try {
			$pdo->exec( 'ROLLBACK' );
		} catch ( \Throwable $ignored ) {
			// Already unwound; nothing useful to do here.
		}
	}

	/**
	 * @param array  $lines         Each: item_id, qty, person_name, group_name.
	 * @param bool   $force         Admin override -- allows ordering outside the
	 *                              window and past whatever the payment method
	 *                              would otherwise insist on, for a phone order
	 *                              taken after the cutoff. Capacity is still
	 *                              respected.
	 * @param string $paymentMethod Method id. Empty picks the first enabled one.
	 *
	 * @return int New order ID.
	 * @throws \RuntimeException With a message safe to show the customer.
	 */
	public static function place(
		int $userId,
		array $lines,
		?int $placedBy = null,
		string $note = '',
		bool $force = false,
		string $paymentMethod = ''
	): int {
		$user = Users::find( $userId );

		if ( ! $user ) {
			throw new \RuntimeException( 'That account no longer exists.' );
		}

		if ( ! $force && ! Users::canOrder( $user ) ) {
			throw new \RuntimeException( 'This account has not been approved for ordering yet.' );
		}

		$lines = array_values( array_filter( $lines, static fn( $l ) => (int) ( $l['qty'] ?? 0 ) > 0 ) );

		if ( ! $lines ) {
			throw new \RuntimeException( 'There is nothing in this order.' );
		}

		// Resolved before the transaction opens: a disabled or unknown method is
		// a bad request, not a rollback.
		$method = Payments::resolve( $paymentMethod );

		$pdo = Database::pdo();

		// IMMEDIATE takes the write lock up front, so two people buying the last
		// portion at the same moment cannot both pass the capacity check.
		self::begin( $pdo );

		try {
			$resolved    = array();
			$total       = 0;
			$serviceDate = '';

			foreach ( $lines as $line ) {
				$item = Menu::item( (int) ( $line['item_id'] ?? 0 ) );

				if ( ! $item || 'published' !== $item['status'] ) {
					throw new \RuntimeException( 'One of the dishes is no longer on the menu.' );
				}

				$qty = max( 1, (int) $line['qty'] );

				if ( ! $force && ! $item['window']->isOrderable() ) {
					throw new \RuntimeException(
						$item['name'] . ' could not be ordered. ' . $item['window']->message()
					);
				}

				if ( null !== $item['remaining'] && $qty > $item['remaining'] ) {
					throw new \RuntimeException(
						$item['name'] . ' only has ' . $item['remaining'] . ' left.'
					);
				}

				// One order covers one week. Mixing service dates would put the
				// same order on two different cook lists.
				if ( '' === $serviceDate ) {
					$serviceDate = $item['window']->serviceDate;
				} elseif ( $serviceDate !== $item['window']->serviceDate ) {
					throw new \RuntimeException( 'Everything in one order has to be for the same day.' );
				}

				$total += $item['price_cents'] * $qty;

				$resolved[] = array(
					'item'        => $item,
					'qty'         => $qty,
					'person_name' => trim( (string) ( $line['person_name'] ?? '' ) ),
					'group_name'  => trim( (string) ( $line['group_name'] ?? '' ) ),
					'note'        => trim( (string) ( $line['note'] ?? '' ) ),
					'extra'       => (array) ( $line['extra'] ?? array() ),
				);
			}

			// An organiser taking a phone order overrides this the same way they
			// override the cutoff: the order still records what is owed.
			if ( ! $force ) {
				$reason = $method->unavailableReason( $userId, $total );

				if ( '' !== $reason ) {
					throw new \RuntimeException( $reason );
				}
			}

			$now = gmdate( 'Y-m-d H:i:s' );

			$statement = $pdo->prepare(
				'INSERT INTO orders
					(user_id, service_date, total_cents, charged_cents, status, placed_by, note,
					 created_at, payment_method, paid_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
			);

			$statement->execute(
				array(
					$userId,
					$serviceDate,
					$total,
					/*
					 * What this order has taken, as opposed to what it is worth.
					 * They start equal and then diverge: editing the lines moves
					 * total_cents, while this only ever grows, and refunds are
					 * capped against it so nobody is given back more than they
					 * put in.
					 */
					$total,
					'confirmed',
					$placedBy,
					$note,
					$now,
					$method->id(),
					$method->settlesImmediately() ? $now : '',
				)
			);

			$orderId = (int) $pdo->lastInsertId();

			$insertLine = $pdo->prepare(
				'INSERT INTO order_lines
					(order_id, menu_item_id, item_name, location_name, qty, unit_price_cents,
					 person_name, group_name, note, extra_fields)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
			);

			foreach ( $resolved as $line ) {
				$insertLine->execute(
					array(
						$orderId,
						$line['item']['id'],
						$line['item']['name'],
						$line['item']['location_name'],
						$line['qty'],
						$line['item']['price_cents'],
						$line['person_name'],
						$line['group_name'],
						$line['note'],
						// Answers to fields the organiser added, frozen with the
						// rest of the line so a later field rename cannot rewrite
						// what somebody actually asked for.
						$line['extra'] ? (string) json_encode( $line['extra'] ) : '',
					)
				);
			}

			$method->charge( $userId, $orderId, $total, $placedBy );

			self::commit( $pdo );

			return $orderId;
		} catch ( \Throwable $e ) {
			self::rollback( $pdo );

			throw $e;
		}
	}

	/**
	 * Cancels an order and puts the money back, in one transaction.
	 */
	public static function cancel( int $orderId, ?int $byUserId = null ): void {
		$order = self::find( $orderId );

		if ( ! $order ) {
			throw new \RuntimeException( 'That order does not exist.' );
		}

		if ( 'cancelled' === $order['status'] ) {
			throw new \RuntimeException( 'That order is already cancelled.' );
		}

		$pdo = Database::pdo();
		self::begin( $pdo );

		try {
			$statement = $pdo->prepare( "UPDATE orders SET status = 'cancelled' WHERE id = ?" );
			$statement->execute( array( $orderId ) );

			/*
			 * Giving the money back is the method's business. A wallet order gets
			 * a ledger entry; a cash order that was never collected has nothing to
			 * return, and one that was is settled in person.
			 */
			$method = Payments::get( (string) $order['payment_method'] );

			if ( $method ) {
				$method->refund(
					(int) $order['user_id'],
					$orderId,
					(int) $order['total_cents'],
					$byUserId
				);
			}

			self::commit( $pdo );
		} catch ( \Throwable $e ) {
			self::rollback( $pdo );

			throw $e;
		}
	}

	/**
	 * The wallet entry written when this order was cancelled, if there was one.
	 *
	 * Read from the ledger rather than inferred from the payment method: the
	 * ledger is what actually moved, and it is the same thing the member sees on
	 * their statement.
	 */
	/* ---------------------------------------------------------------------
	 * Editing an order after it was placed
	 * ------------------------------------------------------------------ */

	/**
	 * Every money movement recorded against an order, oldest first.
	 *
	 * @return array[]
	 */
	public static function adjustments( int $orderId ): array {
		$statement = Database::pdo()->prepare(
			'SELECT a.*, u.name AS by_name
			 FROM order_adjustments a
			 LEFT JOIN users u ON u.id = a.by_user_id
			 WHERE a.order_id = ? ORDER BY a.id'
		);

		$statement->execute( array( $orderId ) );

		return $statement->fetchAll();
	}

	/** How much has been given back so far. */
	public static function refundedCents( int $orderId ): int {
		$statement = Database::pdo()->prepare(
			'SELECT COALESCE(-SUM(delta_cents), 0) FROM order_adjustments
			 WHERE order_id = ? AND delta_cents < 0'
		);

		$statement->execute( array( $orderId ) );

		return (int) $statement->fetchColumn();
	}

	/**
	 * The most that can still be given back.
	 *
	 * Capped at what was actually taken, never at what the food is worth. An
	 * order edited down to nothing and then refunded twice would otherwise hand
	 * out money that was never collected.
	 */
	public static function refundableCents( int $orderId ): int {
		$order = self::find( $orderId );

		if ( ! $order ) {
			return 0;
		}

		return max( 0, (int) $order['charged_cents'] - self::refundedCents( $orderId ) );
	}

	/**
	 * Records a movement and, if the payment method holds the money, makes it.
	 *
	 * The one place either happens, so the record and the money cannot drift
	 * apart. Callers are already inside a transaction.
	 *
	 * @param int $deltaCents Positive takes more; negative gives money back.
	 *
	 * @throws \RuntimeException When a refund would exceed what was charged.
	 */
	private static function adjust( array $order, int $deltaCents, string $reason, ?int $byUserId ): void {
		if ( 0 === $deltaCents ) {
			return;
		}

		$orderId = (int) $order['id'];

		if ( $deltaCents < 0 && -$deltaCents > self::refundableCents( $orderId ) ) {
			throw new \RuntimeException(
				'That would refund more than was paid for this order. '
				. Money::format( self::refundableCents( $orderId ) ) . ' is left.'
			);
		}

		$pdo = Database::pdo();

		$statement = $pdo->prepare(
			'INSERT INTO order_adjustments (order_id, delta_cents, reason, by_user_id, created_at)
			 VALUES (?, ?, ?, ?, ?)'
		);

		$statement->execute(
			array( $orderId, $deltaCents, $reason, $byUserId, gmdate( 'Y-m-d H:i:s' ) )
		);

		// Taking more raises what this order has collected; giving back does
		// not lower it, because it is a record of money in, and the refunds are
		// counted separately.
		if ( $deltaCents > 0 ) {
			$pdo->prepare( 'UPDATE orders SET charged_cents = charged_cents + ? WHERE id = ?' )
				->execute( array( $deltaCents, $orderId ) );
		}

		$method = Payments::get( (string) $order['payment_method'] );

		if ( $method ) {
			$method->adjust(
				(int) $order['user_id'],
				$orderId,
				$deltaCents,
				$reason,
				// The row id makes it unique, so repeated movements never
				// collide on the wallet's idempotency index.
				'adjust:' . $orderId . ':' . $pdo->lastInsertId(),
				$byUserId
			);
		}
	}

	/**
	 * Changes how many of one line, moving the difference.
	 *
	 * Setting it to zero removes the line. Everything happens in one
	 * transaction: the line, the total and the money either all move or none
	 * of them do.
	 *
	 * @throws \RuntimeException
	 */
	public static function setLineQty( int $orderId, int $lineId, int $qty, ?int $byUserId = null ): void {
		$order = self::editableOrder( $orderId );
		$line  = self::line( $orderId, $lineId );

		$qty = max( 0, $qty );
		$was = (int) $line['qty'];

		if ( $qty === $was ) {
			return;
		}

		// Going up has to fit in whatever portions are left, the same rule an
		// order placed on the storefront lives by.
		if ( $qty > $was ) {
			self::assertPortionsFor( $line, $qty - $was );
		}

		$unit  = (int) $line['unit_price_cents'];
		$delta = ( $qty - $was ) * $unit;

		$pdo = Database::pdo();
		self::begin( $pdo );

		try {
			if ( 0 === $qty ) {
				$pdo->prepare( 'DELETE FROM order_lines WHERE id = ?' )->execute( array( $lineId ) );
			} else {
				$pdo->prepare( 'UPDATE order_lines SET qty = ? WHERE id = ?' )->execute( array( $qty, $lineId ) );
			}

			self::retotal( $orderId );

			self::adjust(
				$order,
				$delta,
				0 === $qty
					? 'Removed ' . $line['item_name']
					: $line['item_name'] . ' changed from ' . $was . ' to ' . $qty,
				$byUserId
			);

			self::commit( $pdo );
		} catch ( \Throwable $e ) {
			self::rollback( $pdo );

			throw $e;
		}
	}

	/**
	 * Adds a dish to an existing order, charging for it.
	 *
	 * @throws \RuntimeException
	 */
	public static function addLine( int $orderId, int $menuItemId, int $qty, array $answers = array(), ?int $byUserId = null ): void {
		$order = self::editableOrder( $orderId );
		$qty   = max( 1, $qty );
		$item  = Menu::item( $menuItemId );

		if ( ! $item ) {
			throw new \RuntimeException( 'That dish does not exist.' );
		}

		if ( (string) $item['service_date'] !== (string) $order['service_date'] ) {
			throw new \RuntimeException( 'That dish is for a different date.' );
		}

		self::assertPortionsFor( array( 'menu_item_id' => $menuItemId ), $qty );

		$unit  = (int) $item['price_cents'];
		$delta = $unit * $qty;

		$fields = MenuFields::collect( $item, $answers, Users::find( (int) $order['user_id'] ) );

		$pdo = Database::pdo();
		self::begin( $pdo );

		try {
			$statement = $pdo->prepare(
				'INSERT INTO order_lines
					(order_id, menu_item_id, item_name, location_name, qty, unit_price_cents,
					 person_name, group_name, note, extra_fields)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
			);

			$statement->execute(
				array(
					$orderId,
					$menuItemId,
					(string) $item['name'],
					(string) $item['location_name'],
					$qty,
					$unit,
					(string) ( $fields[ MenuFields::PERSON ] ?? '' ),
					(string) ( $fields[ MenuFields::GROUP ] ?? '' ),
					(string) ( $fields[ MenuFields::NOTE ] ?? '' ),
					self::encodeExtras( $fields ),
				)
			);

			self::retotal( $orderId );
			self::adjust( $order, $delta, 'Added ' . $qty . ' × ' . $item['name'], $byUserId );

			self::commit( $pdo );
		} catch ( \Throwable $e ) {
			self::rollback( $pdo );

			throw $e;
		}
	}

	/**
	 * Corrects who a meal is for, without touching money.
	 *
	 * Kept apart from the quantity so a typo in a name can never move a penny,
	 * and so the kitchen list can be fixed after the cutoff without anything
	 * being refunded.
	 */
	public static function setLineDetails( int $orderId, int $lineId, array $answers ): void {
		$line = self::line( $orderId, $lineId );
		$item = $line['menu_item_id'] ? Menu::item( (int) $line['menu_item_id'] ) : null;

		// A dish that has since been deleted still has a line to correct, so
		// fall back to the built-in fields rather than refusing.
		$fields = $item
			? MenuFields::collect( $item, $answers, null )
			: array(
				MenuFields::PERSON => trim( (string) ( $answers[ MenuFields::PERSON ] ?? '' ) ),
				MenuFields::GROUP  => Groups::sanitise( (string) ( $answers[ MenuFields::GROUP ] ?? '' ) ),
				MenuFields::NOTE   => trim( (string) ( $answers[ MenuFields::NOTE ] ?? '' ) ),
			);

		$statement = Database::pdo()->prepare(
			'UPDATE order_lines SET person_name = ?, group_name = ?, note = ?, extra_fields = ? WHERE id = ?'
		);

		$statement->execute(
			array(
				(string) ( $fields[ MenuFields::PERSON ] ?? '' ),
				(string) ( $fields[ MenuFields::GROUP ] ?? '' ),
				(string) ( $fields[ MenuFields::NOTE ] ?? '' ),
				$item ? self::encodeExtras( $fields ) : (string) $line['extra_fields'],
				$lineId,
			)
		);
	}

	/**
	 * Gives back an amount that is not tied to a line — a goodwill gesture, or
	 * putting right something the lines cannot express.
	 *
	 * @throws \RuntimeException
	 */
	public static function refundAmount( int $orderId, int $cents, string $reason, ?int $byUserId = null ): void {
		$order  = self::editableOrder( $orderId );
		$cents  = abs( $cents );
		$reason = trim( $reason );

		if ( $cents <= 0 ) {
			throw new \RuntimeException( 'Enter an amount to refund.' );
		}

		if ( '' === $reason ) {
			// Required, because a bare number in the ledger months later
			// explains nothing to whoever is reconciling it.
			throw new \RuntimeException( 'Say what the refund is for.' );
		}

		$pdo = Database::pdo();
		self::begin( $pdo );

		try {
			self::adjust( $order, -$cents, $reason, $byUserId );

			self::commit( $pdo );
		} catch ( \Throwable $e ) {
			self::rollback( $pdo );

			throw $e;
		}
	}

	/**
	 * Answers to fields the organiser added, as stored on a line.
	 *
	 * The three built-ins have columns of their own; everything else is frozen
	 * as JSON so a later field rename cannot rewrite what somebody asked for.
	 * Mirrors what place() does at checkout — an edited line has to be shaped
	 * exactly like one that was never edited.
	 */
	private static function encodeExtras( array $fields ): string {
		$extra = $fields;

		unset( $extra[ MenuFields::PERSON ], $extra[ MenuFields::GROUP ], $extra[ MenuFields::NOTE ] );

		$extra = array_filter( $extra, static fn( $value ): bool => '' !== (string) $value );

		return $extra ? (string) json_encode( $extra ) : '';
	}

	/**
	 * @throws \RuntimeException When the order cannot be edited.
	 */
	private static function editableOrder( int $orderId ): array {
		$order = self::find( $orderId );

		if ( ! $order ) {
			throw new \RuntimeException( 'That order does not exist.' );
		}

		if ( 'cancelled' === $order['status'] ) {
			throw new \RuntimeException( 'That order is cancelled. Nothing more can be changed on it.' );
		}

		return $order;
	}

	/**
	 * @throws \RuntimeException When the line is not on that order.
	 */
	private static function line( int $orderId, int $lineId ): array {
		$statement = Database::pdo()->prepare( 'SELECT * FROM order_lines WHERE id = ? AND order_id = ?' );
		$statement->execute( array( $lineId, $orderId ) );

		$line = $statement->fetch();

		if ( ! $line ) {
			throw new \RuntimeException( 'That line is not on this order.' );
		}

		return $line;
	}

	/**
	 * @throws \RuntimeException When there are not enough portions left.
	 */
	private static function assertPortionsFor( array $line, int $extra ): void {
		$itemId = (int) ( $line['menu_item_id'] ?? 0 );

		if ( ! $itemId ) {
			return;
		}

		$item = Menu::item( $itemId );

		if ( ! $item || null === $item['remaining'] ) {
			return;
		}

		if ( $extra > (int) $item['remaining'] ) {
			throw new \RuntimeException(
				'Only ' . (int) $item['remaining'] . ' portion(s) of ' . $item['name'] . ' left.'
			);
		}
	}

	/** Recalculates the stored total from whatever lines remain. */
	private static function retotal( int $orderId ): int {
		$pdo = Database::pdo();

		$statement = $pdo->prepare(
			'SELECT COALESCE(SUM(qty * unit_price_cents), 0) FROM order_lines WHERE order_id = ?'
		);

		$statement->execute( array( $orderId ) );

		$total = (int) $statement->fetchColumn();

		$pdo->prepare( 'UPDATE orders SET total_cents = ? WHERE id = ?' )->execute( array( $total, $orderId ) );

		return $total;
	}

	public static function refundEntryFor( int $orderId ): ?array {
		$statement = Database::pdo()->prepare(
			'SELECT * FROM wallet_entries WHERE kind = ? AND reference = ? LIMIT 1'
		);

		$statement->execute( array( Wallet::KIND_REFUND, 'refund:' . $orderId ) );

		return $statement->fetch() ?: null;
	}

	public static function isPaid( array $order ): bool {
		return '' !== (string) ( $order['paid_at'] ?? '' );
	}

	/**
	 * Records that the money for a pay-on-pickup order has been handed over.
	 */
	public static function markPaid( int $orderId, bool $paid = true ): void {
		$statement = Database::pdo()->prepare( 'UPDATE orders SET paid_at = ? WHERE id = ?' );
		$statement->execute( array( $paid ? gmdate( 'Y-m-d H:i:s' ) : '', $orderId ) );
	}

	/**
	 * Confirmed orders for a service date that are still owing.
	 *
	 * @return array[]
	 */
	public static function unpaidForServiceDate( string $serviceDate ): array {
		return array_values(
			array_filter(
				self::forServiceDate( $serviceDate ),
				static fn( $order ) => ! self::isPaid( $order )
			)
		);
	}

	public static function find( int $id ): ?array {
		$statement = Database::pdo()->prepare(
			'SELECT o.*, u.name AS user_name, u.email AS user_email, u.group_name AS user_group,
					p.name AS placed_by_name
			 FROM orders o
			 INNER JOIN users u ON u.id = o.user_id
			 LEFT JOIN users p ON p.id = o.placed_by
			 WHERE o.id = ?'
		);

		$statement->execute( array( $id ) );

		return $statement->fetch() ?: null;
	}

	/**
	 * @return array[]
	 */
	public static function lines( int $orderId ): array {
		$statement = Database::pdo()->prepare(
			'SELECT * FROM order_lines WHERE order_id = ? ORDER BY id'
		);

		$statement->execute( array( $orderId ) );

		return $statement->fetchAll();
	}

	/**
	 * @return array[]
	 */
	public static function forUser( int $userId, int $limit = 50 ): array {
		$statement = Database::pdo()->prepare(
			'SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT ?'
		);

		$statement->execute( array( $userId, $limit ) );

		return $statement->fetchAll();
	}

	/**
	 * @return array[]
	 */
	/**
	 * Every date somebody has ordered for.
	 *
	 * Kept separate from Menu::serviceDates(), which answers a different
	 * question -- what is on the menu -- and answers it from published dishes
	 * only. An organiser needs both, and needs this one especially: deleting a
	 * dish that has been sold drafts it instead, which is what preserves the
	 * orders, and until this existed that was also what hid them. The date fell
	 * out of every picker and the orders became unreachable, money and all.
	 *
	 * Cancelled orders count. Their date has to stay reachable or there is no
	 * way to look at what was refunded.
	 *
	 * @return string[] Ascending.
	 */
	public static function serviceDates(): array {
		$rows = Database::pdo()->query(
			"SELECT DISTINCT service_date FROM orders
			 WHERE service_date != '' ORDER BY service_date ASC"
		)->fetchAll();

		return array_map( static fn( array $row ): string => (string) $row['service_date'], $rows );
	}

	/**
	 * Dishes ordered on a date that are no longer published.
	 *
	 * Deleting a dish that has been sold drafts it instead, so this is the
	 * normal end state for a dish an organiser tried to remove — and without
	 * saying so, the orders page shows orders for a dish that cannot be found
	 * anywhere on the menu.
	 *
	 * Matched on the name recorded against the line, because that is what a
	 * cook and an organiser both recognise, and because a line whose dish row
	 * was hard-deleted has nothing else left to match on.
	 *
	 * @return string[] Dish names.
	 */
	public static function retiredDishes( string $serviceDate ): array {
		$statement = Database::pdo()->prepare(
			"SELECT DISTINCT ol.item_name
			 FROM order_lines ol
			 INNER JOIN orders o ON o.id = ol.order_id
			 WHERE o.service_date = ? AND o.status = ?
			   AND NOT EXISTS (
				   SELECT 1 FROM menu_items m
				   WHERE m.name = ol.item_name
					 AND m.service_date = o.service_date
					 AND m.status = 'published'
			   )
			 ORDER BY ol.item_name COLLATE NOCASE"
		);

		$statement->execute( array( $serviceDate, self::STATUS_CONFIRMED ) );

		return array_map( static fn( array $row ): string => (string) $row['item_name'], $statement->fetchAll() );
	}

	/**
	 * @param string $status confirmed, cancelled, or '' for both.
	 */
	public static function forServiceDate( string $serviceDate, string $status = self::STATUS_CONFIRMED ): array {
		$sql = "SELECT o.*, u.name AS user_name, u.email AS user_email, u.group_name AS user_group
				FROM orders o
				INNER JOIN users u ON u.id = o.user_id
				WHERE o.service_date = ?";

		$params = array( $serviceDate );

		if ( '' !== $status ) {
			$sql     .= ' AND o.status = ?';
			$params[] = $status;
		}

		$statement = Database::pdo()->prepare( $sql . ' ORDER BY o.id' );
		$statement->execute( $params );

		return $statement->fetchAll();
	}

	/**
	 * The cook list: location -> dish -> quantity, with who it is for.
	 */
	public static function summaryForServiceDate( string $serviceDate ): array {
		$statement = Database::pdo()->prepare(
			"SELECT ol.*, o.user_id, u.name AS account_name
			 FROM order_lines ol
			 INNER JOIN orders o ON o.id = ol.order_id
			 INNER JOIN users u ON u.id = o.user_id
			 WHERE o.service_date = ? AND o.status = 'confirmed'
			 ORDER BY ol.location_name, ol.item_name, ol.person_name"
		);

		$statement->execute( array( $serviceDate ) );

		$summary = array();

		foreach ( $statement->fetchAll() as $line ) {
			$location = '' !== $line['location_name'] ? $line['location_name'] : 'Unassigned';
			$dish     = $line['item_name'];

			if ( ! isset( $summary[ $location ] ) ) {
				$summary[ $location ] = array();
			}

			if ( ! isset( $summary[ $location ][ $dish ] ) ) {
				$summary[ $location ][ $dish ] = array(
					'qty'    => 0,
					'people' => array(),
				);
			}

			$summary[ $location ][ $dish ]['qty'] += (int) $line['qty'];

			$who = '' !== $line['person_name'] ? $line['person_name'] : $line['account_name'];

			if ( '' !== $line['group_name'] ) {
				$who .= ' (' . $line['group_name'] . ')';
			}

			if ( (int) $line['qty'] > 1 ) {
				$who .= ' ×' . (int) $line['qty'];
			}

			$summary[ $location ][ $dish ]['people'][] = $who;
		}

		ksort( $summary );

		return $summary;
	}

	/**
	 * Columns the kitchen table may be sorted by.
	 *
	 * A whitelist, because the sort key arrives on the query string and is
	 * interpolated into ORDER BY, which cannot be a bound parameter.
	 *
	 * @return array<string,string>
	 */
	public static function sortableColumns(): array {
		return array(
			'date'     => 'o.service_date',
			'location' => 'ol.location_name COLLATE NOCASE',
			'dish'     => 'ol.item_name COLLATE NOCASE',
			'qty'      => 'ol.qty',
			'name'     => 'ol.person_name COLLATE NOCASE',
			'group'    => 'ol.group_name COLLATE NOCASE',
			'payment'  => 'o.payment_method',
			'notes'    => 'ol.note COLLATE NOCASE',
		);
	}

	/**
	 * Line items for the kitchen table.
	 *
	 * @param array  $filters from, to, dish, location, group -- any may be ''.
	 * @param string $sort    A key of sortableColumns(). Anything else falls back
	 *                        to location.
	 *
	 * @return array[]
	 */
	public static function lineItemsFiltered( array $filters, string $sort = 'location', string $dir = 'asc' ): array {
		$sql = "SELECT ol.id, ol.order_id, ol.item_name, ol.location_name, ol.qty,
					   ol.unit_price_cents, ol.person_name, ol.group_name, ol.note, ol.extra_fields,
					   o.service_date, o.payment_method, o.paid_at, o.created_at,
					   o.note AS order_note,
					   u.name AS account_name, u.email
				FROM order_lines ol
				INNER JOIN orders o ON o.id = ol.order_id
				INNER JOIN users u ON u.id = o.user_id
				WHERE o.status = 'confirmed'";

		$params = array();

		foreach (
			array(
				'from'     => array( 'o.service_date >= ?', 'from' ),
				'to'       => array( 'o.service_date <= ?', 'to' ),
				'dish'     => array( 'ol.item_name = ?', 'dish' ),
				'location' => array( 'ol.location_name = ?', 'location' ),
				'group'    => array( 'ol.group_name = ?', 'group' ),
			) as $key => $clause
		) {
			if ( '' !== (string) ( $filters[ $key ] ?? '' ) ) {
				$sql     .= ' AND ' . $clause[0];
				$params[] = $filters[ $key ];
			}
		}

		$columns = self::sortableColumns();
		$primary = $columns[ $sort ] ?? $columns['location'];
		$dir     = 'desc' === strtolower( $dir ) ? 'DESC' : 'ASC';

		/*
		 * Pickup location then group is the order the servers hand food out in,
		 * so it stays the tiebreak whatever the chosen column -- sorting by dish
		 * still keeps each campus's groups together underneath.
		 */
		$sql .= ' ORDER BY ' . $primary . ' ' . $dir .
			', ol.location_name COLLATE NOCASE ASC, ol.group_name COLLATE NOCASE ASC,
			  ol.person_name COLLATE NOCASE ASC, ol.id ASC';

		$statement = Database::pdo()->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchAll();
	}

	/**
	 * Distinct values available to filter on, from confirmed orders.
	 *
	 * @return array{dishes:string[],locations:string[],groups:string[]}
	 */
	public static function filterOptions(): array {
		$pdo   = Database::pdo();
		$pull  = static function ( string $column ) use ( $pdo ): array {
			$rows = $pdo->query(
				"SELECT DISTINCT ol.{$column}
				 FROM order_lines ol
				 INNER JOIN orders o ON o.id = ol.order_id
				 WHERE o.status = 'confirmed' AND ol.{$column} != ''
				 ORDER BY ol.{$column} COLLATE NOCASE"
			)->fetchAll( \PDO::FETCH_COLUMN );

			return array_map( 'strval', $rows );
		};

		return array(
			'dishes'    => $pull( 'item_name' ),
			'locations' => $pull( 'location_name' ),
			'groups'    => $pull( 'group_name' ),
		);
	}

	/**
	 * Totals per dish for a set of rows, so the kitchen sees "cook 12 pork"
	 * rather than counting twelve lines.
	 *
	 * @return array<string,int>
	 */
	public static function totalsByDish( array $rows ): array {
		$totals = array();

		foreach ( $rows as $row ) {
			$dish = (string) $row['item_name'];

			$totals[ $dish ] = ( $totals[ $dish ] ?? 0 ) + (int) $row['qty'];
		}

		ksort( $totals, SORT_NATURAL | SORT_FLAG_CASE );

		return $totals;
	}

	/**
	 * Flat rows for CSV export.
	 *
	 * @return array[]
	 */
	public static function exportRows( string $serviceDate ): array {
		$statement = Database::pdo()->prepare(
			"SELECT o.id AS order_id, o.created_at, u.name AS account_name, u.email,
					ol.location_name, ol.item_name, ol.qty, ol.unit_price_cents,
					ol.person_name, ol.group_name, ol.note, ol.extra_fields
			 FROM order_lines ol
			 INNER JOIN orders o ON o.id = ol.order_id
			 INNER JOIN users u ON u.id = o.user_id
			 WHERE o.service_date = ? AND o.status = 'confirmed'
			 ORDER BY ol.location_name, ol.item_name, ol.person_name"
		);

		$statement->execute( array( $serviceDate ) );

		return $statement->fetchAll();
	}
}
