<?php

namespace PauseCafe;

/**
 * Named schedules.
 *
 * There is always a **default schedule** whose rules live in settings — every
 * install has one, and a dish with no `schedule_id` uses it. Named schedules
 * are rows in the `schedules` table, for sites running more than one menu.
 *
 * One source of truth per schedule: settings for the default, a row for each
 * named one. Nothing is written to both.
 */
class Schedules {

	/** The id used for the settings-backed default. */
	public const DEFAULT_ID = 0;

	private static ?array $cache = null;

	/**
	 * The rules every schedule exposes, whatever backs it.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaultRules(): array {
		return array(
			'id'                       => self::DEFAULT_ID,
			'name'                     => Settings::get( 'default_schedule_name', 'Sunday lunch' ),
			'mode'                     => Schedule::activeMode(),
			'service_weekday'          => Settings::int( 'service_weekday', 0 ),
			'open_days_before'         => Settings::int( 'open_days_before', 5 ),
			'open_time'                => Settings::get( 'open_time', '12:00' ),
			'close_days_before'        => Settings::int( 'close_days_before', 1 ),
			'close_time'               => Settings::get( 'close_time', '13:00' ),
			'close_weekday'            => Settings::int( 'close_weekday', 6 ),
			'service_days_after_close' => Settings::int( 'service_days_after_close', 1 ),
			'preview_upcoming'         => Settings::bool( 'preview_upcoming' ),
			'show_on_front'            => 'no' !== Settings::get( 'default_show_on_front', 'yes' ),
			'field_rules'              => Settings::get( 'default_field_rules' ),
			'sort_order'               => -1,
		);
	}

	private static function fromRow( array $row ): array {
		return array(
			'id'                       => (int) $row['id'],
			'name'                     => (string) $row['name'],
			'mode'                     => array_key_exists( $row['mode'], Schedule::modes() )
				? (string) $row['mode']
				: Schedule::MODE_PLANNED,
			'service_weekday'          => (int) $row['service_weekday'],
			'open_days_before'         => (int) $row['open_days_before'],
			'open_time'                => (string) $row['open_time'],
			'close_days_before'        => (int) $row['close_days_before'],
			'close_time'               => (string) $row['close_time'],
			'close_weekday'            => (int) $row['close_weekday'],
			'service_days_after_close' => (int) $row['service_days_after_close'],
			'preview_upcoming'         => 'yes' === $row['preview_upcoming'],
			'show_on_front'            => 'yes' === $row['show_on_front'],
			'field_rules'              => (string) ( $row['field_rules'] ?? '' ),
			'sort_order'               => (int) $row['sort_order'],
		);
	}

	/**
	 * Named schedules only.
	 *
	 * @return array<int,array>
	 */
	public static function named(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$rows  = Database::pdo()->query( 'SELECT * FROM schedules ORDER BY sort_order, id' )->fetchAll();
		$named = array();

		foreach ( $rows as $row ) {
			$named[ (int) $row['id'] ] = self::fromRow( $row );
		}

		self::$cache = $named;

		return $named;
	}

	/**
	 * The default plus every named schedule, keyed by id.
	 *
	 * @return array<int,array>
	 */
	public static function all(): array {
		return array( self::DEFAULT_ID => self::defaultRules() ) + self::named();
	}

	/**
	 * The rules governing a dish.
	 *
	 * An id that no longer exists falls back to the default rather than leaving
	 * the dish unresolvable — deleting a schedule should not strand its history.
	 */
	public static function rulesFor( $scheduleId ): array {
		$id = (int) $scheduleId;

		if ( self::DEFAULT_ID === $id ) {
			return self::defaultRules();
		}

		return self::named()[ $id ] ?? self::defaultRules();
	}

	public static function exists( int $id ): bool {
		return self::DEFAULT_ID === $id || isset( self::named()[ $id ] );
	}

	/**
	 * Schedules the front page should show, in order.
	 *
	 * @return array<int,array>
	 */
	public static function onFront(): array {
		return array_filter( self::all(), static fn( $rules ) => $rules['show_on_front'] );
	}

	/**
	 * The pickup locations a schedule serves. None assigned means all of them,
	 * so a fresh schedule works before anyone has thought about locations.
	 *
	 * @return array[]
	 */
	public static function locationsFor( $scheduleId ): array {
		$id  = (int) $scheduleId;
		$all = Menu::locations();

		if ( self::DEFAULT_ID === $id ) {
			return $all;
		}

		$statement = Database::pdo()->prepare( 'SELECT location_id FROM schedule_locations WHERE schedule_id = ?' );
		$statement->execute( array( $id ) );

		$allowed = array_map( 'intval', $statement->fetchAll( \PDO::FETCH_COLUMN ) );

		if ( ! $allowed ) {
			return $all;
		}

		return array_values(
			array_filter( $all, static fn( $location ) => in_array( (int) $location['id'], $allowed, true ) )
		);
	}

	/**
	 * @param int[] $locationIds Empty means every location.
	 */
	public static function setLocations( int $scheduleId, array $locationIds ): void {
		if ( self::DEFAULT_ID === $scheduleId ) {
			return;
		}

		$pdo = Database::pdo();

		$delete = $pdo->prepare( 'DELETE FROM schedule_locations WHERE schedule_id = ?' );
		$delete->execute( array( $scheduleId ) );

		$insert = $pdo->prepare( 'INSERT OR IGNORE INTO schedule_locations (schedule_id, location_id) VALUES (?, ?)' );

		foreach ( $locationIds as $locationId ) {
			$insert->execute( array( $scheduleId, (int) $locationId ) );
		}

		self::flush();
	}

	/**
	 * @return int The schedule id.
	 */
	public static function save( array $data, ?int $id = null ): int {
		$fields = array(
			'name'                     => trim( (string) ( $data['name'] ?? '' ) ),
			'mode'                     => array_key_exists( (string) ( $data['mode'] ?? '' ), Schedule::modes() )
				? (string) $data['mode']
				: Schedule::MODE_PLANNED,
			'service_weekday'          => max( 0, min( 6, (int) ( $data['service_weekday'] ?? 0 ) ) ),
			'open_days_before'         => max( 0, min( 30, (int) ( $data['open_days_before'] ?? 5 ) ) ),
			'open_time'                => self::time( (string) ( $data['open_time'] ?? '' ), '12:00' ),
			'close_days_before'        => max( 0, min( 30, (int) ( $data['close_days_before'] ?? 1 ) ) ),
			'close_time'               => self::time( (string) ( $data['close_time'] ?? '' ), '13:00' ),
			'close_weekday'            => max( 0, min( 6, (int) ( $data['close_weekday'] ?? 6 ) ) ),
			'service_days_after_close' => max( 0, min( 14, (int) ( $data['service_days_after_close'] ?? 1 ) ) ),
			'preview_upcoming'         => ! empty( $data['preview_upcoming'] ) ? 'yes' : 'no',
			'show_on_front'            => ! empty( $data['show_on_front'] ) ? 'yes' : 'no',
			'field_rules'              => (string) ( $data['field_rules'] ?? '' ),
			'sort_order'               => (int) ( $data['sort_order'] ?? 0 ),
		);

		if ( '' === $fields['name'] ) {
			$fields['name'] = 'Untitled schedule';
		}

		$pdo = Database::pdo();

		if ( $id ) {
			$sets = array();

			foreach ( array_keys( $fields ) as $key ) {
				$sets[] = $key . ' = :' . $key;
			}

			$statement = $pdo->prepare( 'UPDATE schedules SET ' . implode( ', ', $sets ) . ' WHERE id = :id' );
			$statement->execute( array_merge( $fields, array( 'id' => $id ) ) );

			self::flush();

			return $id;
		}

		$columns      = array_keys( $fields );
		$placeholders = array_map( static fn( $c ) => ':' . $c, $columns );

		$statement = $pdo->prepare(
			'INSERT INTO schedules (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ')'
		);

		$statement->execute( $fields );

		self::flush();

		return (int) $pdo->lastInsertId();
	}

	/**
	 * Removes a schedule. Its dishes fall back to the default rather than
	 * becoming unresolvable.
	 */
	public static function delete( int $id ): void {
		if ( self::DEFAULT_ID === $id ) {
			return;
		}

		$pdo = Database::pdo();

		$detach = $pdo->prepare( 'UPDATE menu_items SET schedule_id = NULL WHERE schedule_id = ?' );
		$detach->execute( array( $id ) );

		$remove = $pdo->prepare( 'DELETE FROM schedules WHERE id = ?' );
		$remove->execute( array( $id ) );

		self::flush();
	}

	private static function time( string $value, string $fallback ): string {
		$value = trim( $value );

		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ? $value : $fallback;
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
