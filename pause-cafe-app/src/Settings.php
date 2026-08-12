<?php

namespace PauseCafe;

/**
 * Key/value settings, cached for the request.
 *
 * `active_mode` is the important one: it decides which of the three scheduling
 * behaviours is in force, and only one is ever active at a time.
 */
class Settings {

	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			// planned | on_publish | manual
			'active_mode'              => 'planned',

			// Planned mode.
			'service_weekday'          => '0',   // 0 = Sunday
			'open_days_before'         => '5',   // Tuesday
			'open_time'                => '12:00',
			'close_days_before'        => '1',   // Saturday

			// On-publish mode.
			'close_weekday'            => '6',   // Saturday
			'service_days_after_close' => '1',

			// Shared.
			'close_time'               => '13:00',
			'preview_upcoming'         => 'no',
			'default_price'            => '10.00',

			// Storefront copy.
			'menu_heading'             => 'Sunday Menu',
			'menu_note'                => 'All our meats are either Non-Medicated and or Organic.',

			// People must be approved by an admin before they can order.
			'allow_registration'       => 'yes',

			// Let members go negative? Off by default -- the wallet is prepaid.
			'allow_negative_balance'   => 'no',
		);
	}

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$rows   = Database::pdo()->query( 'SELECT key, value FROM settings' )->fetchAll();
		$stored = array();

		foreach ( $rows as $row ) {
			$stored[ $row['key'] ] = $row['value'];
		}

		self::$cache = array_merge( self::defaults(), $stored );

		return self::$cache;
	}

	public static function get( string $key, string $fallback = '' ): string {
		$all = self::all();

		return $all[ $key ] ?? $fallback;
	}

	public static function int( string $key, int $fallback = 0 ): int {
		$value = self::get( $key );

		return '' === $value ? $fallback : (int) $value;
	}

	public static function bool( string $key ): bool {
		return 'yes' === self::get( $key );
	}

	public static function set( string $key, string $value ): void {
		$statement = Database::pdo()->prepare(
			'INSERT INTO settings (key, value) VALUES (:k, :v)
			 ON CONFLICT(key) DO UPDATE SET value = :v'
		);

		$statement->execute(
			array(
				':k' => $key,
				':v' => $value,
			)
		);

		self::$cache = null;
	}

	public static function setMany( array $values ): void {
		foreach ( $values as $key => $value ) {
			self::set( (string) $key, (string) $value );
		}
	}

	public static function seedDefaults(): void {
		$pdo    = Database::pdo();
		$insert = $pdo->prepare( 'INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)' );

		foreach ( self::defaults() as $key => $value ) {
			$insert->execute( array( $key, $value ) );
		}

		self::$cache = null;
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
