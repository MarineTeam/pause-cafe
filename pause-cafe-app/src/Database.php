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

		/*
		 * The groups people can be in. Named member_groups because GROUPS is a
		 * keyword in SQLite's window-function grammar.
		 *
		 * Groups are referenced by name rather than by ID. Names are what appear
		 * on the cook list and what get frozen into order lines, and keeping one
		 * representation avoids a join every time a line is displayed. Renaming a
		 * group updates the accounts carrying it; past orders keep the name they
		 * were placed under, which is correct for a receipt.
		 */
		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS member_groups (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				name       TEXT    NOT NULL UNIQUE,
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

		/*
		 * Named schedules, for sites running more than one menu -- a Sunday lunch
		 * closing Saturday 1pm and a Wednesday supper closing Tuesday evening,
		 * side by side.
		 *
		 * There is always an unnamed default schedule whose rules live in
		 * settings; a dish with no schedule_id uses it. That keeps every existing
		 * install working untouched and means a site that only ever wants one
		 * menu never has to know this table exists.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS schedules (
				id                       INTEGER PRIMARY KEY AUTOINCREMENT,
				name                     TEXT    NOT NULL,
				mode                     TEXT    NOT NULL DEFAULT 'planned',
				service_weekday          INTEGER NOT NULL DEFAULT 0,
				open_days_before         INTEGER NOT NULL DEFAULT 5,
				open_time                TEXT    NOT NULL DEFAULT '12:00',
				close_days_before        INTEGER NOT NULL DEFAULT 1,
				close_time               TEXT    NOT NULL DEFAULT '13:00',
				close_weekday            INTEGER NOT NULL DEFAULT 6,
				service_days_after_close INTEGER NOT NULL DEFAULT 1,
				preview_upcoming         TEXT    NOT NULL DEFAULT 'no',
				show_on_front            TEXT    NOT NULL DEFAULT 'yes',
				sort_order               INTEGER NOT NULL DEFAULT 0
			)"
		);

		// Which pickup locations a schedule serves. No rows for a schedule means
		// it serves all of them.
		$pdo->exec(
			'CREATE TABLE IF NOT EXISTS schedule_locations (
				schedule_id INTEGER NOT NULL REFERENCES schedules(id) ON DELETE CASCADE,
				location_id INTEGER NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
				PRIMARY KEY ( schedule_id, location_id )
			)'
		);

		/*
		 * The fields asked for on each meal. Three are built in -- who it is for,
		 * their group, and a note -- because the kitchen list and the CSV read
		 * them by name. Those three can be hidden but never deleted.
		 *
		 * The row itself is the global setting. Schedules and dishes override it
		 * through their own field_rules, so the resolution order is
		 * definition -> schedule -> dish.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS custom_fields (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				field_key   TEXT    NOT NULL UNIQUE,
				label       TEXT    NOT NULL,
				type        TEXT    NOT NULL DEFAULT 'text',
				options     TEXT    NOT NULL DEFAULT '',
				placeholder TEXT    NOT NULL DEFAULT '',
				is_builtin  INTEGER NOT NULL DEFAULT 0,
				is_shown    INTEGER NOT NULL DEFAULT 1,
				is_required INTEGER NOT NULL DEFAULT 0,
				sort_order  INTEGER NOT NULL DEFAULT 0
			)"
		);

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

		/*
		 * Which external accounts belong to which local one.
		 *
		 * The link is on the provider's subject claim, never on the email
		 * address. Subjects are permanent; an address can be changed at the
		 * provider, and keying on it would mean whoever holds an address today
		 * inherits the wallet of whoever held it yesterday.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS user_identities (
				id           INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id      INTEGER NOT NULL,
				provider     TEXT    NOT NULL,
				subject      TEXT    NOT NULL,
				email        TEXT    NOT NULL DEFAULT '',
				created_at   TEXT    NOT NULL,
				last_seen_at TEXT    NOT NULL DEFAULT ''
			)"
		);

		$pdo->exec(
			'CREATE UNIQUE INDEX IF NOT EXISTS idx_identity_subject
				ON user_identities (provider, subject)'
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_identity_user ON user_identities (user_id)' );

		/*
		 * When somebody stopped being able to sign in, blank while they still
		 * can.
		 *
		 * A date rather than a flag because "since when" is the question asked
		 * of a closed account months later, and a flag cannot answer it.
		 *
		 * This exists because deleting the row does not just remove a person: it
		 * cascades through wallet_entries and orders and takes the money with
		 * them. What was collected through Zeffy, what was refunded, what is
		 * still owed -- all of it gone, with nothing left to reconcile against.
		 */
		self::addColumnIfMissing( 'users', 'disabled_at', "TEXT NOT NULL DEFAULT ''" );

		/*
		 * External sign-ins waiting for an organiser to say they are the same
		 * person as an account that already exists here.
		 *
		 * A verified address is good evidence and poor proof. Addresses get
		 * reassigned inside an organisation, recycled by a provider, or come
		 * from a tenant somebody else administers -- so matching one against an
		 * account holding money is a claim, not an identity. The claim is parked
		 * here for a human who knows the congregation to accept or throw away.
		 *
		 * Unique on (provider, subject) so a signer who keeps trying leaves one
		 * row rather than a queue of them.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS identity_link_requests (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id    INTEGER NOT NULL,
				provider   TEXT    NOT NULL,
				subject    TEXT    NOT NULL,
				email      TEXT    NOT NULL DEFAULT '',
				name       TEXT    NOT NULL DEFAULT '',
				created_at TEXT    NOT NULL,
				last_try_at TEXT   NOT NULL DEFAULT ''
			)"
		);

		$pdo->exec(
			'CREATE UNIQUE INDEX IF NOT EXISTS idx_link_request_subject
				ON identity_link_requests (provider, subject)'
		);

		/*
		 * Single-use sign-in links.
		 *
		 * Only the hash of the token is kept. A leaked backup of this table is
		 * then worth nothing: the column cannot be pasted into a URL, for the
		 * same reason password_hash cannot be typed into a password box.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS login_tokens (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id    INTEGER NOT NULL,
				token_hash TEXT    NOT NULL UNIQUE,
				purpose    TEXT    NOT NULL DEFAULT 'magic',
				created_at TEXT    NOT NULL,
				expires_at TEXT    NOT NULL,
				used_at    TEXT    NOT NULL DEFAULT ''
			)"
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_token_user ON login_tokens (user_id, created_at)' );

		/*
		 * Wrong password guesses, so they can be slowed down.
		 *
		 * Only failures are written, and only for long enough to matter -- rows
		 * older than the window are deleted, so this never becomes a record of
		 * who tried to sign in and when.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS login_attempts (
				id         INTEGER PRIMARY KEY AUTOINCREMENT,
				email      TEXT NOT NULL DEFAULT '',
				ip         TEXT NOT NULL DEFAULT '',
				created_at TEXT NOT NULL
			)"
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_attempt_email ON login_attempts (email, created_at)' );
		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_attempt_ip ON login_attempts (ip, created_at)' );

		/*
		 * Added after the first release, so they arrive by ALTER rather than in
		 * the CREATE above -- an existing install would never see a changed
		 * CREATE TABLE IF NOT EXISTS.
		 */
		self::addColumnIfMissing( 'orders', 'payment_method', "TEXT NOT NULL DEFAULT 'wallet'" );
		self::addColumnIfMissing( 'orders', 'paid_at', "TEXT NOT NULL DEFAULT ''" );

		// A note against one meal -- "no onions" -- as opposed to orders.note,
		// which is about the order as a whole.
		self::addColumnIfMissing( 'order_lines', 'note', "TEXT NOT NULL DEFAULT ''" );

		// NULL means the default schedule, whose rules are in settings.
		self::addColumnIfMissing( 'menu_items', 'schedule_id', 'INTEGER NULL' );

		// Per-field show/require overrides, as JSON. Empty means inherit.
		self::addColumnIfMissing( 'schedules', 'field_rules', "TEXT NOT NULL DEFAULT ''" );
		self::addColumnIfMissing( 'menu_items', 'field_rules', "TEXT NOT NULL DEFAULT ''" );

		// Answers to any field beyond the three built-in ones, as JSON.
		self::addColumnIfMissing( 'order_lines', 'extra_fields', "TEXT NOT NULL DEFAULT ''" );

		/*
		 * What an order has actually taken from somebody, as opposed to what it
		 * is currently worth.
		 *
		 * orders.total_cents is the value of the food and moves when the lines
		 * are edited. This is money, and only ever goes up: it is what was
		 * charged at checkout plus anything charged since. Refunds are capped
		 * against it, so nobody can be given back more than they put in.
		 */
		if ( self::addColumnIfMissing( 'orders', 'charged_cents', 'INTEGER NOT NULL DEFAULT 0' ) ) {
			// Orders that predate the column were charged their total and
			// never adjusted. Runs on the one migration that adds the column.
			$pdo->exec( 'UPDATE orders SET charged_cents = total_cents' );
		}

		/*
		 * Every movement of money against an order after checkout, in the
		 * organiser's words.
		 *
		 * The wallet ledger already records the money for wallet orders, but it
		 * says nothing for a cash one, and it is organised by person rather than
		 * by order. This is the per-order story: what changed, why, and who did
		 * it -- which is what somebody looking at a refund six weeks later
		 * actually needs.
		 */
		$pdo->exec(
			"CREATE TABLE IF NOT EXISTS order_adjustments (
				id          INTEGER PRIMARY KEY AUTOINCREMENT,
				order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
				delta_cents INTEGER NOT NULL,
				reason      TEXT    NOT NULL DEFAULT '',
				by_user_id  INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
				created_at  TEXT    NOT NULL
			)"
		);

		$pdo->exec( 'CREATE INDEX IF NOT EXISTS idx_adjustment_order ON order_adjustments (order_id, id)' );

		// Public URL of an uploaded photo. Empty means the card falls back to
		// its typographic layout, which is the normal case.
		self::addColumnIfMissing( 'menu_items', 'image_path', "TEXT NOT NULL DEFAULT ''" );

		/*
		 * A dish that is not part of any weekly rhythm.
		 *
		 * A Christmas menu, a box of chocolates, something needing a fortnight's
		 * notice. It shows whenever its own from/until allow, in its own section,
		 * rather than only during whichever week its schedule currently points
		 * at. Without this the only way to give one dish odd timing was to
		 * change the schedule every other dish shares.
		 */
		self::addColumnIfMissing( 'menu_items', 'standalone', 'INTEGER NOT NULL DEFAULT 0' );

		// Where this organiser wants the admin navigation: top or side. On the
		// account rather than in settings, so one organiser's preference is not
		// imposed on the others.
		self::addColumnIfMissing( 'users', 'admin_nav', "TEXT NOT NULL DEFAULT 'top'" );

		self::seed();
	}

	/**
	 * Adds a column when it is not already there.
	 *
	 * Table and column names come from this file only, never from a request, so
	 * interpolating them is safe -- SQLite cannot bind identifiers anyway.
	 */
	/**
	 * @return bool True only on the run that actually added it, so a caller can
	 *              backfill exactly once without needing a flag of its own.
	 */
	private static function addColumnIfMissing( string $table, string $column, string $definition ): bool {
		$pdo = self::pdo();

		foreach ( $pdo->query( 'PRAGMA table_info(' . $table . ')' )->fetchAll() as $existing ) {
			if ( $existing['name'] === $column ) {
				return false;
			}
		}

		$pdo->exec( 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition );

		return true;
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
		MenuFields::seedBuiltins();
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
