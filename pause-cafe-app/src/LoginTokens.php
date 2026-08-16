<?php

namespace PauseCafe;

/**
 * Single-use sign-in links.
 *
 * The plaintext token exists for exactly as long as it takes to build the email
 * — it is returned by issue(), used, and never stored. What goes in the table
 * is its SHA-256, which is enough to recognise the token coming back and no use
 * at all to anyone reading the database.
 *
 * SHA-256 rather than a password hash on purpose. Tokens are 256 bits of
 * randomness, so there is nothing to guess and no need to make guessing slow;
 * and a slow hash could not be looked up by value, only compared row by row.
 */
class LoginTokens {

	public const PURPOSE_MAGIC = 'magic';

	/** How many links one account may ask for inside the window below. */
	private const BURST_LIMIT = 5;

	private const BURST_WINDOW_MINUTES = 15;

	/**
	 * Makes a token and records its hash.
	 *
	 * @return string The plaintext, for the link. Not recoverable afterwards.
	 */
	public static function issue( int $userId, int $minutes, string $purpose = self::PURPOSE_MAGIC ): string {
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$now   = Schedule::now();

		$statement = Database::pdo()->prepare(
			'INSERT INTO login_tokens (user_id, token_hash, purpose, created_at, expires_at)
			 VALUES (?, ?, ?, ?, ?)'
		);

		$statement->execute(
			array(
				$userId,
				self::hash( $token ),
				$purpose,
				$now->format( 'Y-m-d H:i:s' ),
				$now->modify( '+' . max( 1, $minutes ) . ' minutes' )->format( 'Y-m-d H:i:s' ),
			)
		);

		return $token;
	}

	/**
	 * Spends a token.
	 *
	 * Marks it used before returning, so a link forwarded to somebody else, or
	 * fetched twice by an email client that follows links, is dead the second
	 * time.
	 *
	 * @return array|null The user row, or null for anything not exactly right.
	 */
	public static function consume( string $token, string $purpose = self::PURPOSE_MAGIC ): ?array {
		if ( '' === $token ) {
			return null;
		}

		$statement = Database::pdo()->prepare(
			'SELECT * FROM login_tokens WHERE token_hash = ? AND purpose = ?'
		);

		$statement->execute( array( self::hash( $token ), $purpose ) );

		$row = $statement->fetch();

		if ( ! $row ) {
			return null;
		}

		if ( '' !== (string) $row['used_at'] ) {
			return null;
		}

		if ( Schedule::now()->format( 'Y-m-d H:i:s' ) > (string) $row['expires_at'] ) {
			return null;
		}

		$user = Users::find( (int) $row['user_id'] );

		// A link already in an inbox must not outlive the account it was for.
		if ( ! $user || Users::isDisabled( $user ) ) {
			return null;
		}

		$update = Database::pdo()->prepare( 'UPDATE login_tokens SET used_at = ? WHERE id = ? AND used_at = \'\'' );
		$update->execute( array( Schedule::now()->format( 'Y-m-d H:i:s' ), (int) $row['id'] ) );

		/*
		 * If the row was already spent between the read above and here, the
		 * update changes nothing and this was the losing half of a double
		 * click. Refuse it rather than sign the same link in twice.
		 */
		if ( 0 === $update->rowCount() ) {
			return null;
		}

		return $user;
	}

	/**
	 * Whether this account has asked for too many links too quickly.
	 *
	 * The limit is per account rather than per browser, because the cost being
	 * contained is somebody else's inbox filling up.
	 */
	public static function isThrottled( int $userId, string $purpose = self::PURPOSE_MAGIC ): bool {
		$since = Schedule::now()
			->modify( '-' . self::BURST_WINDOW_MINUTES . ' minutes' )
			->format( 'Y-m-d H:i:s' );

		$statement = Database::pdo()->prepare(
			'SELECT COUNT(*) FROM login_tokens WHERE user_id = ? AND purpose = ? AND created_at >= ?'
		);

		$statement->execute( array( $userId, $purpose, $since ) );

		return (int) $statement->fetchColumn() >= self::BURST_LIMIT;
	}

	/** Kills every outstanding link for somebody — used when they sign out. */
	public static function revokeFor( int $userId, string $purpose = self::PURPOSE_MAGIC ): void {
		$statement = Database::pdo()->prepare(
			'UPDATE login_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at = \'\''
		);

		$statement->execute( array( Schedule::now()->format( 'Y-m-d H:i:s' ), $userId, $purpose ) );
	}

	/**
	 * Clears out tokens that can no longer do anything.
	 *
	 * Spent and expired rows are only clutter, but they are also a record of
	 * when somebody signed in, which is not worth keeping for a year.
	 */
	public static function purge( int $olderThanDays = 7 ): int {
		$cutoff = Schedule::now()
			->modify( '-' . max( 1, $olderThanDays ) . ' days' )
			->format( 'Y-m-d H:i:s' );

		$statement = Database::pdo()->prepare( 'DELETE FROM login_tokens WHERE created_at < ?' );
		$statement->execute( array( $cutoff ) );

		return $statement->rowCount();
	}

	private static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}
}
