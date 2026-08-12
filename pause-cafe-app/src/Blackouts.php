<?php

namespace PauseCafe;

/**
 * Days no menu runs -- Christmas, a long weekend, a week the kitchen is closed.
 *
 * A blackout voids the window for anything serving that day, so dishes go away
 * rather than sitting there orderable, and the menu shows the label instead of
 * an empty page.
 */
class Blackouts {

	private static ?array $cache = null;

	/**
	 * @return array<string,string> Y-m-d => label.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$rows  = Database::pdo()->query( 'SELECT service_date, label FROM blackouts ORDER BY service_date' )->fetchAll();
		$dates = array();

		foreach ( $rows as $row ) {
			$dates[ $row['service_date'] ] = $row['label'];
		}

		self::$cache = $dates;

		return $dates;
	}

	public static function isBlackout( string $date ): bool {
		return '' !== $date && array_key_exists( $date, self::all() );
	}

	public static function label( string $date ): string {
		$all = self::all();

		if ( ! isset( $all[ $date ] ) || '' === $all[ $date ] ) {
			return 'No menu this week';
		}

		return $all[ $date ];
	}

	public static function add( string $date, string $label ): void {
		if ( ! Schedule::parseDate( $date ) ) {
			return;
		}

		$statement = Database::pdo()->prepare(
			'INSERT INTO blackouts (service_date, label) VALUES (:d, :l)
			 ON CONFLICT(service_date) DO UPDATE SET label = :l'
		);

		$statement->execute(
			array(
				':d' => $date,
				':l' => $label,
			)
		);

		self::$cache = null;
	}

	public static function remove( string $date ): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM blackouts WHERE service_date = ?' );
		$statement->execute( array( $date ) );

		self::$cache = null;
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
