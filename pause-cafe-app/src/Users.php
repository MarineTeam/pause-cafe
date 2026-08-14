<?php

namespace PauseCafe;

/**
 * Accounts.
 *
 * Registering is not the same as being allowed to order: a new account sits
 * unapproved until an admin lets it in. That is what "only authorised users can
 * order" means here, and it is checked on the server at checkout, not just
 * hidden in the interface.
 */
class Users {

	public const ROLE_MEMBER = 'member';
	public const ROLE_ADMIN  = 'admin';

	public static function find( int $id ): ?array {
		$statement = Database::pdo()->prepare( 'SELECT * FROM users WHERE id = ?' );
		$statement->execute( array( $id ) );

		$row = $statement->fetch();

		return $row ?: null;
	}

	public static function findByEmail( string $email ): ?array {
		$statement = Database::pdo()->prepare( 'SELECT * FROM users WHERE email = ?' );
		$statement->execute( array( self::normaliseEmail( $email ) ) );

		$row = $statement->fetch();

		return $row ?: null;
	}

	public static function normaliseEmail( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * @return int New user ID.
	 * @throws \RuntimeException When the email is already taken.
	 */
	public static function create(
		string $email,
		string $password,
		string $name,
		string $group = '',
		string $role = self::ROLE_MEMBER,
		bool $approved = false
	): int {
		$email = self::normaliseEmail( $email );

		if ( self::findByEmail( $email ) ) {
			throw new \RuntimeException( 'An account with that email already exists.' );
		}

		if ( strlen( $password ) < 8 ) {
			throw new \RuntimeException( 'Passwords need to be at least 8 characters.' );
		}

		$statement = Database::pdo()->prepare(
			'INSERT INTO users (email, password_hash, name, group_name, role, is_approved, created_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?)'
		);

		$statement->execute(
			array(
				$email,
				password_hash( $password, PASSWORD_DEFAULT ),
				$name,
				$group,
				$role,
				$approved ? 1 : 0,
				gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) Database::pdo()->lastInsertId();
	}

	/**
	 * Makes an account for somebody an identity provider vouched for.
	 *
	 * No password is set, and none is needed: they have proved who they are
	 * somewhere else. The empty hash is what marks the account as passwordless,
	 * and authenticate() refuses to match against it.
	 *
	 * Unapproved, like every other new account. Signing in and being allowed to
	 * order are separate questions, and only an organiser answers the second.
	 *
	 * @return int New user ID.
	 * @throws \RuntimeException When the email is already taken.
	 */
	public static function createExternal( string $email, string $name ): int {
		$email = self::normaliseEmail( $email );

		if ( self::findByEmail( $email ) ) {
			throw new \RuntimeException( 'An account with that email already exists.' );
		}

		$statement = Database::pdo()->prepare(
			'INSERT INTO users (email, password_hash, name, group_name, role, is_approved, created_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?)'
		);

		$statement->execute(
			array(
				$email,
				'',
				'' !== $name ? $name : $email,
				'',
				self::ROLE_MEMBER,
				0,
				gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return (int) Database::pdo()->lastInsertId();
	}

	/** Whether this account can be signed into with a password at all. */
	public static function hasPassword( ?array $user ): bool {
		return $user && '' !== (string) ( $user['password_hash'] ?? '' );
	}

	/**
	 * @return array|null The user row on success.
	 */
	public static function authenticate( string $email, string $password ): ?array {
		$user = self::findByEmail( $email );

		/*
		 * A passwordless account must never be matched. password_verify()
		 * against an empty hash is false anyway, but relying on that would put
		 * the whole guarantee in someone else's function.
		 */
		if ( $user && ! self::hasPassword( $user ) ) {
			return null;
		}

		if ( ! $user ) {
			/*
			 * Hash anyway. Returning immediately for an unknown address makes the
			 * response measurably faster than for a known one, which is enough to
			 * enumerate who has an account.
			 */
			password_verify( $password, '$2y$10$usesomesillystringforeseeableuseinvalidhashvaluehere00' );

			return null;
		}

		if ( ! password_verify( $password, $user['password_hash'] ) ) {
			return null;
		}

		if ( password_needs_rehash( $user['password_hash'], PASSWORD_DEFAULT ) ) {
			self::setPassword( (int) $user['id'], $password );
		}

		return $user;
	}

	public static function setPassword( int $id, string $password ): void {
		$statement = Database::pdo()->prepare( 'UPDATE users SET password_hash = ? WHERE id = ?' );
		$statement->execute( array( password_hash( $password, PASSWORD_DEFAULT ), $id ) );
	}

	public static function update( int $id, array $fields ): void {
		$allowed = array( 'name', 'group_name', 'role', 'is_approved', 'email', 'admin_nav' );
		$sets    = array();
		$values  = array();

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			$sets[]   = $key . ' = ?';
			$values[] = 'email' === $key ? self::normaliseEmail( (string) $value ) : $value;
		}

		if ( ! $sets ) {
			return;
		}

		$values[] = $id;

		$statement = Database::pdo()->prepare( 'UPDATE users SET ' . implode( ', ', $sets ) . ' WHERE id = ?' );
		$statement->execute( $values );
	}

	public static function isAdmin( ?array $user ): bool {
		return $user && self::ROLE_ADMIN === $user['role'];
	}

	public static function canOrder( ?array $user ): bool {
		return $user && (int) $user['is_approved'] === 1;
	}

	/**
	 * @return array[] Every account, with its wallet balance attached.
	 */
	public static function all( string $search = '' ): array {
		$sql = 'SELECT u.*, COALESCE(SUM(w.delta_cents), 0) AS balance_cents
				FROM users u
				LEFT JOIN wallet_entries w ON w.user_id = u.id';

		$params = array();

		if ( '' !== $search ) {
			$sql     .= ' WHERE u.email LIKE ? OR u.name LIKE ? OR u.group_name LIKE ?';
			$like     = '%' . $search . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql .= ' GROUP BY u.id ORDER BY u.is_approved ASC, u.name COLLATE NOCASE ASC';

		$statement = Database::pdo()->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchAll();
	}

	public static function pendingCount(): int {
		return (int) Database::pdo()
			->query( 'SELECT COUNT(*) FROM users WHERE is_approved = 0' )
			->fetchColumn();
	}

	public static function delete( int $id ): void {
		$pdo = Database::pdo();

		// Nothing that could sign the account back in may outlive it.
		foreach ( array( 'user_identities', 'login_tokens' ) as $table ) {
			$statement = $pdo->prepare( 'DELETE FROM ' . $table . ' WHERE user_id = ?' );
			$statement->execute( array( $id ) );
		}

		$statement = $pdo->prepare( 'DELETE FROM users WHERE id = ?' );
		$statement->execute( array( $id ) );
	}
}
