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
	 * @param array $lines  Each: item_id, qty, person_name, group_name.
	 * @param bool  $force  Admin override -- allows ordering outside the window
	 *                      and past the wallet balance, for a phone order taken
	 *                      after the cutoff. Capacity is still respected.
	 *
	 * @return int New order ID.
	 * @throws \RuntimeException With a message safe to show the customer.
	 */
	public static function place(
		int $userId,
		array $lines,
		?int $placedBy = null,
		string $note = '',
		bool $force = false
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
				);
			}

			$balance = Wallet::balance( $userId );

			if ( ! $force && ! Settings::bool( 'allow_negative_balance' ) && $balance < $total ) {
				throw new \RuntimeException(
					'Your balance is ' . Money::format( $balance ) . ' and this order comes to ' .
					Money::format( $total ) . '. Please top up first.'
				);
			}

			$statement = $pdo->prepare(
				'INSERT INTO orders (user_id, service_date, total_cents, status, placed_by, note, created_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?)'
			);

			$statement->execute(
				array( $userId, $serviceDate, $total, 'confirmed', $placedBy, $note, gmdate( 'Y-m-d H:i:s' ) )
			);

			$orderId = (int) $pdo->lastInsertId();

			$insertLine = $pdo->prepare(
				'INSERT INTO order_lines
					(order_id, menu_item_id, item_name, location_name, qty, unit_price_cents, person_name, group_name)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
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
					)
				);
			}

			Wallet::post(
				$userId,
				-$total,
				Wallet::KIND_ORDER,
				'Order #' . $orderId . ' for ' . Schedule::formatDate( $serviceDate, 'j M Y' ),
				'order:' . $orderId,
				$placedBy,
				false
			);

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

			Wallet::post(
				(int) $order['user_id'],
				(int) $order['total_cents'],
				Wallet::KIND_REFUND,
				'Refund for cancelled order #' . $orderId,
				'refund:' . $orderId,
				$byUserId,
				false
			);

			self::commit( $pdo );
		} catch ( \Throwable $e ) {
			self::rollback( $pdo );

			throw $e;
		}
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
	public static function forServiceDate( string $serviceDate ): array {
		$statement = Database::pdo()->prepare(
			"SELECT o.*, u.name AS user_name, u.email AS user_email, u.group_name AS user_group
			 FROM orders o
			 INNER JOIN users u ON u.id = o.user_id
			 WHERE o.service_date = ? AND o.status = 'confirmed'
			 ORDER BY o.id"
		);

		$statement->execute( array( $serviceDate ) );

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
	 * Flat rows for CSV export.
	 *
	 * @return array[]
	 */
	public static function exportRows( string $serviceDate ): array {
		$statement = Database::pdo()->prepare(
			"SELECT o.id AS order_id, o.created_at, u.name AS account_name, u.email,
					ol.location_name, ol.item_name, ol.qty, ol.unit_price_cents,
					ol.person_name, ol.group_name
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
