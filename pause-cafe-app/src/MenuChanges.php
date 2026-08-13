<?php

namespace PauseCafe;

/**
 * Telling people when a dish they have already ordered is changed.
 *
 * Dishes get corrected — a typo, the wrong name entered, a price fixed — and by
 * then somebody has usually ordered. This works out who, updates what has to be
 * updated, and mails them.
 *
 * Every change notifies. A dish corrected three times sends three emails,
 * deliberately: the second correction matters as much as the first to the
 * person who has to eat it.
 */
class MenuChanges {

	/**
	 * What was announced during this request, so a route can tell the organiser
	 * who got mailed without having to thread a return value back through
	 * Menu::save().
	 *
	 * @var array<int,array{item:string,notified:int}>
	 */
	private static array $announcements = array();

	/**
	 * @return array<int,array{item:string,notified:int}>
	 */
	public static function announcements(): array {
		return self::$announcements;
	}

	public static function totalNotified(): int {
		$total = 0;

		foreach ( self::$announcements as $entry ) {
			$total += $entry['notified'];
		}

		return $total;
	}

	public static function forget(): void {
		self::$announcements = array();
	}

	/**
	 * Fields worth telling a customer about. Status, capacity and the schedule
	 * are all invisible to somebody who has already ordered.
	 *
	 * @return array<string,string> Column => label used in the email.
	 */
	public static function watched(): array {
		return array(
			'name'         => 'Dish',
			'description'  => 'Description',
			'price_cents'  => 'Price',
			'service_date' => 'Served on',
		);
	}

	/**
	 * The current values of the watched fields, or null when the dish is new.
	 */
	public static function snapshot( int $itemId ): ?array {
		$statement = Database::pdo()->prepare( 'SELECT * FROM menu_items WHERE id = ?' );
		$statement->execute( array( $itemId ) );

		$row = $statement->fetch();

		if ( ! $row ) {
			return null;
		}

		$snapshot = array();

		foreach ( array_keys( self::watched() ) as $field ) {
			$snapshot[ $field ] = (string) ( $row[ $field ] ?? '' );
		}

		return $snapshot;
	}

	/**
	 * @return array<string,array{from:string,to:string}>
	 */
	public static function diff( array $before, array $after ): array {
		$changes = array();

		foreach ( array_keys( self::watched() ) as $field ) {
			$from = (string) ( $before[ $field ] ?? '' );
			$to   = (string) ( $after[ $field ] ?? '' );

			if ( $from !== $to ) {
				$changes[ $field ] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
		}

		return $changes;
	}

	/**
	 * Everyone with a confirmed order for this dish, with their lines.
	 *
	 * @return array<int,array{user:array,lines:array[]}>
	 */
	public static function affected( int $itemId ): array {
		$statement = Database::pdo()->prepare(
			"SELECT o.id AS order_id, o.user_id, o.service_date,
					u.email, u.name AS account_name,
					ol.qty, ol.person_name, ol.group_name, ol.unit_price_cents
			 FROM order_lines ol
			 INNER JOIN orders o ON o.id = ol.order_id
			 INNER JOIN users u ON u.id = o.user_id
			 WHERE ol.menu_item_id = ? AND o.status = 'confirmed'
			 ORDER BY o.id"
		);

		$statement->execute( array( $itemId ) );

		$people = array();

		foreach ( $statement->fetchAll() as $row ) {
			$userId = (int) $row['user_id'];

			if ( ! isset( $people[ $userId ] ) ) {
				$people[ $userId ] = array(
					'user'  => array(
						'id'    => $userId,
						'email' => (string) $row['email'],
						'name'  => (string) $row['account_name'],
					),
					'lines' => array(),
				);
			}

			$people[ $userId ]['lines'][] = $row;
		}

		return $people;
	}

	/**
	 * Renames the dish on confirmed orders as well.
	 *
	 * Order lines are otherwise a receipt and left alone, but the name is the
	 * exception: leaving it means the cook list shows the old name for people
	 * who ordered before the correction and the new one for everybody after,
	 * which is two dishes as far as the kitchen can tell. The price they were
	 * charged is never touched.
	 *
	 * @return int Lines updated.
	 */
	public static function renameOrderLines( int $itemId, string $newName ): int {
		$statement = Database::pdo()->prepare(
			"UPDATE order_lines
			 SET item_name = ?
			 WHERE menu_item_id = ?
			   AND order_id IN ( SELECT id FROM orders WHERE status = 'confirmed' )"
		);

		$statement->execute( array( $newName, $itemId ) );

		return $statement->rowCount();
	}

	/**
	 * Applies the consequences of a change and mails everyone affected.
	 *
	 * @param array $before Snapshot taken before the save.
	 *
	 * @return array{changes:array,notified:int,lines_renamed:int}
	 */
	public static function announce( int $itemId, ?array $before ): array {
		$result = array(
			'changes'       => array(),
			'notified'      => 0,
			'lines_renamed' => 0,
		);

		if ( ! $before ) {
			return $result;
		}

		$after   = self::snapshot( $itemId );
		$changes = $after ? self::diff( $before, $after ) : array();

		if ( ! $changes ) {
			return $result;
		}

		$result['changes'] = $changes;
		$people            = self::affected( $itemId );

		if ( ! $people ) {
			return $result;
		}

		if ( isset( $changes['name'] ) ) {
			$result['lines_renamed'] = self::renameOrderLines( $itemId, $changes['name']['to'] );
		}

		$item = Menu::item( $itemId );

		foreach ( $people as $person ) {
			$sent = Notifications::orderedDishChanged( $person, $item, $changes );

			if ( $sent->ok ) {
				++$result['notified'];
			}
		}

		self::$announcements[] = array(
			'item'     => $item ? (string) $item['name'] : '',
			'notified' => $result['notified'],
		);

		return $result;
	}
}
