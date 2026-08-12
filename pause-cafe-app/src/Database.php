<?php

namespace PauseCafe;

use PDO;
use PDOException;

/**
 * SQLite connection and schema.
 *
 * One file on disk holds everything. At this volume that is not a compromise --
 * it removes database credentials, a second service to keep running, and makes
 * a backup a matter of copying one file.
 */
class Database {

	private static ?PDO $pdo = null;

	private static string $path = '';

	public static function configure( string $path ): void {
		self::$path = $path;
		self::$pdo  = null;
	}

	public static function pdo(): PDO {
		if ( self::$pdo instanceof PDO ) {
			return self::$pdo;
		}

		$directory = dirname( self::$path );

		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, 0775, true );
		}

		try {
			$pdo = new PDO( 'sqlite:' . self::$path );
		} catch ( PDOException $e ) {
			throw new \RuntimeException(
				'Could not open the database. Check that the pdo_sqlite extension is enabled and that ' .
				$directory . ' is writable. (' . $e->getMessage() . ')'
			);
		}

		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );

		// Foreign keys are off by default in SQLite and must be asked for.
		$pdo->exec( 'PRAGMA foreign_keys = ON' );

		// Lets a reader carry on while a write is in progress. With a handful of
		// concurrent orders this is the difference between a brief wait and a
		// "database is locked" error.
		$pdo->exec( 'PRAGMA journal_mode = WAL' );
		$pdo->exec( 'PRAGMA busy_timeout = 5000' );

		self::$pdo = $pdo;

		return $pdo;
	}

	public static function migrate(): void {
		$pdo = self::pdo();

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS users (
				id            INTEGER PRIMARY KEY AUTOINCREMENT,
				email         TEXT    NOT NULL UNIQUE,
				password_hash TEXT    NOT NULL,
				name          TEXT    NOT NULL DEFAULT '',
				group_name    TEXT    NOT NULL DEFAULT '',
				role          TEXT    NOT NULL DEFAULT 'member',
				is_approved   INTEGER NOT NULL DEFAULT 0,
				created_at    TEXT    NOT NULL
			)"
		);

		/*
		 * The wallet is an append-only ledger, never a single balance column. The
		 * running balance is stored alongside each entry so a statement can be
		 * read back without re-adding history, but the entries are the truth --
		 * money that moved can always be explained.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS wallet_entries (
				id                  INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id             INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
				delta_cents         INTEGER NOT NULL,
				balance_after_cents INTEGER NOT NULL,
				kind                TEXT    NOT NULL,
				note                TEXT    NOT NULL DEFAULT '',
				reference           TEXT    NOT NULL DEFAULT '',
				created_by          INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
				created_at          TEXT    NOT NULL
			)"
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_wallet_user ON wallet_entries(user_id, id)' );

		/*
		 * Stops a Zeffy webhook delivered twice from crediting twice. SQLite
		 * treats NULLs as distinct in a unique index, so entries without a
		 * reference are excluded rather than colliding with each other.
		 */
		$pdo->exec(
			"CREATE UNIQUE INDEX IF NOT EXISTS idx_wallet_reference
			 ON wallet_entries(kind, reference) WHERE reference != ''"
		);

		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS locations (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				name       TEXT    NOT NULL,
				sort_order INTEGER NOT NULL DEFAULT 0
			)'
		);

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS menu_items (
				id           INTEGER PRIMARY KEY AUTOINCREMENT,
				location_id  INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
				name         TEXT    NOT NULL,
				description  TEXT    NOT NULL DEFAULT '',
				price_cents  INTEGER NOT NULL DEFAULT 1000,
				service_date TEXT    NOT NULL DEFAULT '',
				opened_at    TEXT    NOT NULL DEFAULT '',
				open_from    TEXT    NOT NULL DEFAULT '',
				close_at     TEXT    NOT NULL DEFAULT '',
				capacity     INTEGER NOT NULL DEFAULT 0,
				status       TEXT    NOT NULL DEFAULT 'published',
				created_at   TEXT    NOT NULL
			)"
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_items_date ON menu_items(service_date)' );

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS orders (
				id           INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
				service_date TEXT    NOT NULL,
				total_cents  INTEGER NOT NULL,
				status       TEXT    NOT NULL DEFAULT 'confirmed',
				placed_by    INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
				note         TEXT    NOT NULL DEFAULT '',
				created_at   TEXT    NOT NULL
			)"
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_orders_date ON orders(service_date)' );

		/*
		 * Line items keep their own copy of the dish name, location and price. An
		 * order is a receipt: editing the menu afterwards must not rewrite what
		 * somebody was charged for.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS order_lines (
				id                INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id          INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				menu_item_id      INTEGER NULL REFERENCES menu_items(id) ON DELETE SET NULL,
				item_name         TEXT    NOT NULL,
				location_name     TEXT    NOT NULL DEFAULT '',
				qty               INTEGER NOT NULL DEFAULT 1,
				unit_price_cents  INTEGER NOT NULL DEFAULT 0,
				person_name       TEXT    NOT NULL DEFAULT '',
				group_name        TEXT    NOT NULL DEFAULT ''
			)"
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_lines_order ON order_lines(order_id)' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_lines_item ON order_lines(menu_item_id)' );

		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS blackouts (
				service_date TEXT PRIMARY KEY,
				label        TEXT NOT NULL DEFAULT ''
			)"
		);

		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS settings (
				key   TEXT PRIMARY KEY,
				value TEXT NOT NULL
			)'
		);

		self::seed();
	}

	private static function seed(): void {
		$pdo = self::pdo();

		$count = (int) $pdo->query( 'SELECT COUNT(*) FROM locations' )->fetchColumn();

		if ( 0 === $count ) {
			$insert = $pdo->prepare( 'INSERT INTO locations (name, sort_order) VALUES (?, ?)' );

			foreach ( array( 'Marine', 'RCC', 'Fraser' ) as $index => $name ) {
				$insert->execute( array( $name, $index ) );
			}
		}

		Settings::seedDefaults();
	}

	/**
	 * True when no admin exists yet, which is what puts the app into first-run
	 * setup instead of showing a login nobody can pass.
	 */
	public static function needsSetup(): bool {
		$pdo = self::pdo();

		return 0 === (int) $pdo->query( "SELECT COUNT(*) FROM users WHERE role = 'admin'" )->fetchColumn();
	}
}
