<?php

namespace PauseCafe;

/**
 * Menu items and pickup locations.
 *
 * Every read that matters comes back with its resolved Window attached, so no
 * caller has to remember which mode is active or how to work out a cutoff.
 */
class Menu {

	/**
	 * @return array[]
	 */
	public static function locations(): array {
		return Database::pdo()
			->query( 'SELECT * FROM locations ORDER BY sort_order, id' )
			->fetchAll();
	}

	public static function location( int $id ): ?array {
		$statement = Database::pdo()->prepare( 'SELECT * FROM locations WHERE id = ?' );
		$statement->execute( array( $id ) );

		return $statement->fetch() ?: null;
	}

	public static function addLocation( string $name ): int {
		$next = (int) Database::pdo()->query( 'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM locations' )->fetchColumn();

		$statement = Database::pdo()->prepare( 'INSERT INTO locations (name, sort_order) VALUES (?, ?)' );
		$statement->execute( array( $name, $next ) );

		return (int) Database::pdo()->lastInsertId();
	}

	public static function renameLocation( int $id, string $name ): void {
		$statement = Database::pdo()->prepare( 'UPDATE locations SET name = ? WHERE id = ?' );
		$statement->execute( array( $name, $id ) );
	}

	public static function deleteLocation( int $id ): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM locations WHERE id = ?' );
		$statement->execute( array( $id ) );
	}

	public static function item( int $id ): ?array {
		$statement = Database::pdo()->prepare(
			'SELECT i.*, l.name AS location_name
			 FROM menu_items i
			 LEFT JOIN locations l ON l.id = i.location_id
			 WHERE i.id = ?'
		);

		$statement->execute( array( $id ) );

		$row = $statement->fetch();

		return $row ? self::decorate( $row ) : null;
	}

	private static function decorate( array $row ): array {
		$row['window']    = Schedule::forItem( $row );
		$row['sold']      = self::sold( (int) $row['id'] );
		$row['capacity']  = (int) $row['capacity'];
		$row['remaining'] = $row['capacity'] > 0 ? max( 0, $row['capacity'] - $row['sold'] ) : null;

		return $row;
	}

	/**
	 * @return array[]
	 */
	public static function itemsForServiceDate(
		string $serviceDate,
		int $locationId = 0,
		bool $publishedOnly = true,
		$scheduleId = null
	): array {
		$sql    = 'SELECT i.*, l.name AS location_name
				   FROM menu_items i
				   LEFT JOIN locations l ON l.id = i.location_id
				   WHERE 1 = 1';
		$params = array();

		if ( $publishedOnly ) {
			$sql .= " AND i.status = 'published'";
		}

		if ( $locationId ) {
			$sql     .= ' AND i.location_id = ?';
			$params[] = $locationId;
		}

		if ( null !== $scheduleId ) {
			if ( Schedules::DEFAULT_ID === (int) $scheduleId ) {
				$sql .= ' AND i.schedule_id IS NULL';
			} else {
				$sql     .= ' AND i.schedule_id = ?';
				$params[] = (int) $scheduleId;
			}
		}

		$sql .= ' ORDER BY l.sort_order, i.name';

		$statement = Database::pdo()->prepare( $sql );
		$statement->execute( $params );

		$items = array();

		/*
		 * Service dates are filtered in PHP rather than SQL because two of the
		 * three modes derive the date instead of storing it -- on-publish works
		 * it out from the cutoff. Filtering in SQL would only ever see the
		 * planned-mode rows.
		 */
		foreach ( $statement->fetchAll() as $row ) {
			$decorated = self::decorate( $row );

			if ( $decorated['window']->serviceDate === $serviceDate ) {
				$items[] = $decorated;
			}
		}

		return $items;
	}

	/**
	 * @return array[] Every published item, decorated.
	 */
	public static function allItems( bool $publishedOnly = false ): array {
		$sql = 'SELECT i.*, l.name AS location_name
				FROM menu_items i
				LEFT JOIN locations l ON l.id = i.location_id';

		if ( $publishedOnly ) {
			$sql .= " WHERE i.status = 'published'";
		}

		$sql .= ' ORDER BY i.id DESC';

		return array_map(
			array( self::class, 'decorate' ),
			Database::pdo()->query( $sql )->fetchAll()
		);
	}

	/**
	 * Distinct service dates that have at least one published item, ascending.
	 *
	 * @return string[]
	 */
	public static function serviceDates( $scheduleId = null ): array {
		$dates = array();

		foreach ( self::allItems( true ) as $item ) {
			if ( null !== $scheduleId && (int) ( $item['schedule_id'] ?? 0 ) !== (int) $scheduleId ) {
				continue;
			}

			$date = $item['window']->serviceDate;

			if ( '' !== $date ) {
				$dates[ $date ] = true;
			}
		}

		$dates = array_keys( $dates );
		sort( $dates );

		return $dates;
	}

	/**
	 * The week the storefront should show: the earliest date not yet past.
	 *
	 * Worked out per schedule, since two menus on different rhythms are on
	 * different weeks.
	 */
	public static function currentServiceDate( $scheduleId = null ): ?string {
		$today = Schedule::now()->format( 'Y-m-d' );

		foreach ( self::serviceDates( $scheduleId ) as $date ) {
			if ( $date >= $today ) {
				return $date;
			}
		}

		return null;
	}

	/**
	 * The dish sitting in one cell of the builder grid.
	 *
	 * Matched on the stored service_date column rather than the resolved window:
	 * the grid is what wrote that column, and a lookup that went through the
	 * schedule would not find a draft or a dish whose mode derives its date.
	 */
	public static function itemBySlot( string $serviceDate, int $locationId, $scheduleId = Schedules::DEFAULT_ID ): ?array {
		if ( '' === $serviceDate || ! $locationId ) {
			return null;
		}

		$sql    = 'SELECT i.*, l.name AS location_name
				   FROM menu_items i
				   LEFT JOIN locations l ON l.id = i.location_id
				   WHERE i.service_date = ? AND i.location_id = ?';
		$params = array( $serviceDate, $locationId );

		// Two schedules can serve the same location on the same day, so the cell
		// is only unique once the schedule is part of the lookup.
		if ( Schedules::DEFAULT_ID === (int) $scheduleId ) {
			$sql .= ' AND i.schedule_id IS NULL';
		} else {
			$sql     .= ' AND i.schedule_id = ?';
			$params[] = (int) $scheduleId;
		}

		$sql .= ' ORDER BY i.id LIMIT 1';

		$statement = Database::pdo()->prepare( $sql );

		$statement->execute( $params );

		$row = $statement->fetch();

		return $row ? self::decorate( $row ) : null;
	}

	/**
	 * Every dish name that has been used, for the builder's autocomplete.
	 *
	 * @return string[]
	 */
	public static function distinctNames(): array {
		$rows = Database::pdo()
			->query( "SELECT DISTINCT name FROM menu_items WHERE name != '' ORDER BY name COLLATE NOCASE" )
			->fetchAll( \PDO::FETCH_COLUMN );

		return array_map( 'strval', $rows );
	}

	/**
	 * The most recent dish with this name, used to carry a repeat's price and
	 * description across instead of retyping them.
	 */
	public static function templateFor( string $name ): ?array {
		$name = trim( $name );

		if ( '' === $name ) {
			return null;
		}

		$statement = Database::pdo()->prepare(
			'SELECT * FROM menu_items WHERE name = ? ORDER BY id DESC LIMIT 1'
		);

		$statement->execute( array( $name ) );

		return $statement->fetch() ?: null;
	}

	/**
	 * Quantity already sold, counting confirmed orders only.
	 */
	public static function sold( int $itemId ): int {
		$statement = Database::pdo()->prepare(
			"SELECT COALESCE(SUM(ol.qty), 0)
			 FROM order_lines ol
			 INNER JOIN orders o ON o.id = ol.order_id
			 WHERE ol.menu_item_id = ? AND o.status = 'confirmed'"
		);

		$statement->execute( array( $itemId ) );

		return (int) $statement->fetchColumn();
	}

	public static function isSoldOut( array $item ): bool {
		return null !== $item['remaining'] && $item['remaining'] <= 0;
	}

	/**
	 * @return int The item ID.
	 */
	public static function save( array $data, ?int $id = null ): int {
		$fields = array(
			'location_id'  => (int) ( $data['location_id'] ?? 0 ),
			'name'         => trim( (string) ( $data['name'] ?? '' ) ),
			'description'  => trim( (string) ( $data['description'] ?? '' ) ),
			'price_cents'  => (int) ( $data['price_cents'] ?? 0 ),
			'service_date' => (string) ( $data['service_date'] ?? '' ),
			'opened_at'    => (string) ( $data['opened_at'] ?? '' ),
			'open_from'    => (string) ( $data['open_from'] ?? '' ),
			'close_at'     => (string) ( $data['close_at'] ?? '' ),
			'capacity'     => max( 0, (int) ( $data['capacity'] ?? 0 ) ),
			'status'       => 'draft' === ( $data['status'] ?? 'published' ) ? 'draft' : 'published',
			// NULL is the default schedule, whose rules live in settings.
			'schedule_id'  => ( (int) ( $data['schedule_id'] ?? 0 ) ) > 0 ? (int) $data['schedule_id'] : null,
		);

		$pdo = Database::pdo();

		if ( $id ) {
			/*
			 * Taken before the write so anyone who has already ordered can be told
			 * what changed. This lives here rather than in the callers because
			 * every route that edits a dish -- the per-dish editor and the grid
			 * builder -- comes through this method, and one that forgot to
			 * announce would silently change somebody's lunch.
			 */
			$before = MenuChanges::snapshot( $id );

			$sets = array();

			foreach ( array_keys( $fields ) as $key ) {
				$sets[] = $key . ' = :' . $key;
			}

			$statement = $pdo->prepare( 'UPDATE menu_items SET ' . implode( ', ', $sets ) . ' WHERE id = :id' );
			$statement->execute( array_merge( $fields, array( 'id' => $id ) ) );

			MenuChanges::announce( $id, $before );

			return $id;
		}

		$fields['created_at'] = gmdate( 'Y-m-d H:i:s' );

		$columns      = array_keys( $fields );
		$placeholders = array_map( static fn( $c ) => ':' . $c, $columns );

		$statement = $pdo->prepare(
			'INSERT INTO menu_items (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')'
		);

		$statement->execute( $fields );

		return (int) $pdo->lastInsertId();
	}

	/**
	 * Opens ordering now. Only meaningful in on-publish mode, where the stamp is
	 * what starts the window.
	 */
	public static function publishNow( int $id, ?string $moment = null ): void {
		$moment = $moment ?: Schedule::now()->format( 'Y-m-d H:i:s' );

		$statement = Database::pdo()->prepare(
			"UPDATE menu_items SET opened_at = ?, status = 'published' WHERE id = ?"
		);

		$statement->execute( array( $moment, $id ) );
	}

	/**
	 * Items are only really deletable while nothing has been ordered against
	 * them; otherwise they are drafted so order history keeps its reference.
	 */
	public static function delete( int $id ): bool {
		if ( self::sold( $id ) > 0 ) {
			$statement = Database::pdo()->prepare( "UPDATE menu_items SET status = 'draft' WHERE id = ?" );
			$statement->execute( array( $id ) );

			return false;
		}

		$statement = Database::pdo()->prepare( 'DELETE FROM menu_items WHERE id = ?' );
		$statement->execute( array( $id ) );

		return true;
	}
}
