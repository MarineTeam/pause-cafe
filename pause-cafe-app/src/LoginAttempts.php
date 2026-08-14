<?php

namespace PauseCafe;

/**
 * Slowing down somebody guessing passwords.
 *
 * bcrypt costs about a tenth of a second, which sounds like protection until
 * you work out that it allows roughly ten guesses a second, all day, against a
 * password a person chose. The organiser rescue is the attractive target: it
 * only ever accepts an organiser, so every success is an account that can move
 * money.
 *
 * Two limits, and the shapes are different on purpose:
 *
 *   - **Per address, tight.** Five wrong guesses and that address waits. This
 *     is the one that stops the attack, because an attacker picks a victim.
 *   - **Per source address, loose.** Catches one machine trying many accounts.
 *     Deliberately generous: on shared hosting every visitor can arrive from
 *     the same proxy address, and a tight limit there would lock out the whole
 *     congregation because one person mistyped.
 *
 * Nothing here is ever permanent. A lock expires on its own, succeeding clears
 * it, and tools/rescue.php can wipe the lot from the command line — the point
 * of this session's other work was that an organiser always has a way back in,
 * and a lockout that needs a database editor to undo would give that away.
 */
class LoginAttempts {

	/** Wrong guesses against one address before it has to wait. */
	private const PER_EMAIL = 5;

	/** Wrong guesses from one source address before it has to wait. */
	private const PER_IP = 40;

	/** How far back a guess counts, and how long a wait lasts. */
	private const WINDOW_MINUTES = 15;

	/**
	 * How long this attempt has to wait, in seconds. Zero means go ahead.
	 *
	 * The address is taken as typed and never checked against the accounts
	 * table. Throttling only real addresses would answer "does this person have
	 * an account here?" for anyone patient enough to ask.
	 */
	public static function retryAfter( string $email, string $ip ): int {
		$since = self::since();
		$email = Users::normaliseEmail( $email );

		$worst = 0;

		foreach (
			array(
				array( 'email = ?', $email, self::PER_EMAIL ),
				array( 'ip = ?', $ip, self::PER_IP ),
			) as $limit
		) {
			list( $clause, $value, $allowed ) = $limit;

			if ( '' === $value ) {
				continue;
			}

			$statement = Database::pdo()->prepare(
				'SELECT COUNT(*) AS n, MAX(created_at) AS last
				 FROM login_attempts WHERE ' . $clause . ' AND created_at >= ?'
			);

			$statement->execute( array( $value, $since ) );

			$row = $statement->fetch();

			if ( ! $row || (int) $row['n'] < $allowed ) {
				continue;
			}

			/*
			 * The wait runs from the most recent guess, so hammering it while
			 * locked keeps it locked rather than running the clock down.
			 *
			 * The stored stamp is read back in the same zone it was written in.
			 * It used to be parsed as UTC while being written in local time,
			 * which put the deadline seven hours in the past here and made the
			 * wait negative -- so it never locked anything. Freezing the clock
			 * to UTC in the tests hid it exactly, because then the two agree.
			 */
			$last = new \DateTimeImmutable( (string) $row['last'], self::now()->getTimezone() );
			$free = $last->getTimestamp() + ( self::WINDOW_MINUTES * 60 );
			$wait = $free - self::now()->getTimestamp();

			$worst = max( $worst, $wait );
		}

		return max( 0, $worst );
	}

	public static function record( string $email, string $ip ): void {
		$statement = Database::pdo()->prepare(
			'INSERT INTO login_attempts (email, ip, created_at) VALUES (?, ?, ?)'
		);

		$statement->execute(
			array(
				Users::normaliseEmail( $email ),
				$ip,
				self::now()->format( 'Y-m-d H:i:s' ),
			)
		);

		// Cheap enough to do here rather than needing a cron nobody will set up.
		if ( 0 === random_int( 0, 20 ) ) {
			self::purge();
		}
	}

	/** Signing in successfully forgives everything against that address. */
	public static function forgive( string $email ): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM login_attempts WHERE email = ?' );
		$statement->execute( array( Users::normaliseEmail( $email ) ) );
	}

	/** Used by tools/rescue.php. Returns how many were cleared. */
	public static function clearAll(): int {
		$statement = Database::pdo()->prepare( 'DELETE FROM login_attempts' );
		$statement->execute();

		return $statement->rowCount();
	}

	public static function purge(): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM login_attempts WHERE created_at < ?' );
		$statement->execute( array( self::since() ) );
	}

	/**
	 * A phrase for the person waiting, which says nothing about whether the
	 * address exists.
	 */
	public static function message( int $seconds ): string {
		$minutes = (int) ceil( $seconds / 60 );

		return 'Too many sign-in attempts. Please wait '
			. ( $minutes > 1 ? $minutes . ' minutes' : 'a minute' )
			. ' and try again.';
	}

	/**
	 * Where the request came from.
	 *
	 * REMOTE_ADDR only. X-Forwarded-For is set by the client on the way in and
	 * an attacker can put a different value on every request, which would turn
	 * a per-source limit into no limit at all -- worse than not having one,
	 * because it would look like protection.
	 */
	public static function ip(): string {
		return (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
	}

	private static function since(): string {
		return self::now()->modify( '-' . self::WINDOW_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );
	}

	private static function now(): \DateTimeImmutable {
		return Schedule::now();
	}
}
