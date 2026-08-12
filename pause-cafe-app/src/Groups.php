<?php

namespace PauseCafe;

/**
 * The groups people can belong to, managed by organisers.
 *
 * Free text drifts -- "Youth", "youth", "YTH" and a typo all become separate
 * rows on the cook list. A fixed list keeps the report readable, so every entry
 * point offers a choice rather than a text box, and the server checks the value
 * against this list regardless of what the form sent.
 */
class Groups {

	private static ?array $cache = null;

	/**
	 * @return array[] Rows of id, name, sort_order.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::$cache = Database::pdo()
			->query( 'SELECT * FROM member_groups ORDER BY sort_order, name COLLATE NOCASE' )
			->fetchAll();

		return self::$cache;
	}

	/**
	 * @return string[]
	 */
	public static function names(): array {
		return array_column( self::all(), 'name' );
	}

	public static function any(): bool {
		return array() !== self::all();
	}

	public static function find( int $id ): ?array {
		foreach ( self::all() as $group ) {
			if ( (int) $group['id'] === $id ) {
				return $group;
			}
		}

		return null;
	}

	public static function has( string $name ): bool {
		foreach ( self::names() as $known ) {
			if ( 0 === strcasecmp( $known, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The canonical spelling of a submitted group, or '' when it is not one of
	 * ours.
	 *
	 * Every route that accepts a group runs input through this. A dropdown is a
	 * convenience for the person filling the form, not a guarantee about what
	 * arrives.
	 */
	public static function sanitise( string $name ): string {
		$name = trim( $name );

		if ( '' === $name ) {
			return '';
		}

		foreach ( self::names() as $known ) {
			if ( 0 === strcasecmp( $known, $name ) ) {
				return $known;
			}
		}

		return '';
	}

	/**
	 * @return int The new group's ID, or 0 when the name was blank or a duplicate.
	 */
	public static function add( string $name ): int {
		$name = trim( $name );

		if ( '' === $name || self::has( $name ) ) {
			return 0;
		}

		$next = (int) Database::pdo()
			->query( 'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM member_groups' )
			->fetchColumn();

		$statement = Database::pdo()->prepare( 'INSERT INTO member_groups (name, sort_order) VALUES (?, ?)' );
		$statement->execute( array( $name, $next ) );

		self::flush();

		return (int) Database::pdo()->lastInsertId();
	}

	/**
	 * Renames a group and moves every account that was in it.
	 *
	 * Order lines are deliberately left alone: they record what was true when
	 * the order was placed.
	 *
	 * @return bool False when the name was blank or already taken.
	 */
	public static function rename( int $id, string $name ): bool {
		$name    = trim( $name );
		$current = self::find( $id );

		if ( ! $current || '' === $name ) {
			return false;
		}

		if ( 0 !== strcasecmp( $current['name'], $name ) && self::has( $name ) ) {
			return false;
		}

		$pdo = Database::pdo();

		$statement = $pdo->prepare( 'UPDATE member_groups SET name = ? WHERE id = ?' );
		$statement->execute( array( $name, $id ) );

		$statement = $pdo->prepare( 'UPDATE users SET group_name = ? WHERE group_name = ?' );
		$statement->execute( array( $name, $current['name'] ) );

		self::flush();

		return true;
	}

	/**
	 * Removes a group from the list.
	 *
	 * Accounts already in it keep the name. Silently clearing someone's group
	 * because an organiser tidied the list would lose information nobody asked
	 * to throw away; the dropdown surfaces the stale value instead.
	 */
	public static function delete( int $id ): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM member_groups WHERE id = ?' );
		$statement->execute( array( $id ) );

		self::flush();
	}

	/**
	 * Group names held by accounts but no longer on the list.
	 *
	 * @return string[]
	 */
	public static function orphaned(): array {
		$held = Database::pdo()
			->query( "SELECT DISTINCT group_name FROM users WHERE group_name != '' ORDER BY group_name" )
			->fetchAll( \PDO::FETCH_COLUMN );

		return array_values( array_filter( $held, static fn( $name ) => ! self::has( (string) $name ) ) );
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
