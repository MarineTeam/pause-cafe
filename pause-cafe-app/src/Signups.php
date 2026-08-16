<?php

namespace PauseCafe;

/**
 * Slowing down somebody registering accounts in bulk.
 *
 * Registration is cheap to ask for and not cheap to serve: every attempt writes
 * a row, emails the organisers, and leaves somebody a name to look at and
 * decide about. A script can do it all night. Nothing is gained by the attacker
 * except noise, which is the point -- the accounts land unapproved and cannot
 * order, so this is an abuse and delivery problem rather than a way in.
 *
 * Its own table rather than sharing login_attempts, deliberately. Counting both
 * in one place would mean a burst of sign-ups locking the congregation out of
 * signing in, which hands an attacker something far better than noise.
 *
 * Three limits, and only the last is really the defence:
 *
 *   - **Per address**, so the same form submitted repeatedly stops mattering.
 *     Weak on its own: an attacker can change the address every time.
 *   - **Per source address**, loose. A church hall full of people joining after
 *     a service all arrive from one router, and locking them out to stop a
 *     script would be a poor trade.
 *   - **For the whole site.** The one that actually bounds the damage, because
 *     it is the only one an attacker cannot get around by varying something.
 *     Set far above what this congregation could ever do by hand.
 *
 * A window and no permanence: everything here expires on its own.
 */
class Signups {

	/** Attempts from one address before it waits. */
	private const PER_EMAIL = 3;

	/**
	 * Attempts from one source address before it waits.
	 *
	 * Generous on purpose. Behind one router, a welcome table signing people up
	 * one after another looks exactly like a script.
	 */
	private const PER_IP = 10;

	/**
	 * Attempts across the whole site before everybody waits.
	 *
	 * The blunt one. A congregation of this size will never approach it; a
	 * script will pass it in seconds, whatever addresses it comes from.
	 */
	private const GLOBAL_MAX = 40;

	/** How far back an attempt counts, and how long a wait lasts. */
	private const WINDOW_MINUTES = 60;

	/**
	 * How long this attempt has to wait, in seconds. Zero means go ahead.
	 *
	 * The address is taken as typed and never checked against the accounts
	 * table, for the same reason the sign-in throttle does not: telling
	 * somebody they are being throttled on a real address answers "does this
	 * person have an account here?"
	 */
	public static function retryAfter( string $email, string $ip ): int {
		$since = self::since();
		$email = Users::normaliseEmail( $email );
		$worst = 0;

		$limits = array(
			array( 'email = ?', $email, self::PER_EMAIL ),
			array( 'ip = ?', $ip, self::PER_IP ),
			// No clause: everything in the window, whoever it came from.
			array( '', '', self::GLOBAL_MAX ),
		);

		foreach ( $limits as $limit ) {
			list( $clause, $value, $allowed ) = $limit;

			if ( '' !== $clause && '' === $value ) {
				continue;
			}

			$sql    = 'SELECT COUNT(*) AS n, MAX(created_at) AS last FROM signup_attempts WHERE ';
			$params = array();

			if ( '' !== $clause ) {
				$sql     .= $clause . ' AND ';
				$params[] = $value;
			}

			$sql     .= 'created_at >= ?';
			$params[] = $since;

			$statement = Database::pdo()->prepare( $sql );
			$statement->execute( $params );

			$row = $statement->fetch();

			if ( ! $row || (int) $row['n'] < $allowed ) {
				continue;
			}

			/*
			 * The wait runs from the most recent attempt, so a script that
			 * keeps hammering keeps itself locked out rather than running the
			 * clock down while it waits.
			 *
			 * Read back in the zone it was written in. Parsing a local stamp as
			 * UTC is what once made the sign-in throttle inert, with every test
			 * passing because the clock was frozen to UTC and the two agreed.
			 */
			$last = new \DateTimeImmutable( (string) $row['last'], self::now()->getTimezone() );
			$free = $last->getTimestamp() + ( self::WINDOW_MINUTES * 60 );

			$worst = max( $worst, $free - self::now()->getTimestamp() );
		}

		return max( 0, $worst );
	}

	/**
	 * Counted whether or not the account was actually created.
	 *
	 * An attempt that failed still cost a request, a hash and a write, and a
	 * script that only ever fails would otherwise be unlimited.
	 */
	public static function record( string $email, string $ip ): void {
		$statement = Database::pdo()->prepare(
			'INSERT INTO signup_attempts (email, ip, created_at) VALUES (?, ?, ?)'
		);

		$statement->execute(
			array( Users::normaliseEmail( $email ), $ip, self::now()->format( 'Y-m-d H:i:s' ) )
		);

		// Cheap enough here rather than needing a cron nobody will set up.
		if ( 0 === random_int( 0, 20 ) ) {
			self::purge();
		}
	}

	public static function purge(): void {
		$statement = Database::pdo()->prepare( 'DELETE FROM signup_attempts WHERE created_at < ?' );
		$statement->execute( array( self::since() ) );
	}

	/** Used by tools/rescue.php and the tests. Returns how many were cleared. */
	public static function clearAll(): int {
		$statement = Database::pdo()->prepare( 'DELETE FROM signup_attempts' );
		$statement->execute();

		return $statement->rowCount();
	}

	/**
	 * What the person waiting is told.
	 *
	 * Says nothing about which limit was hit. "That address has tried too often"
	 * and "the site is busy" are different facts, and the first is one an
	 * attacker can use.
	 */
	public static function message( int $seconds ): string {
		$minutes = (int) ceil( $seconds / 60 );

		return 'Too many sign-ups from here just now. Please wait '
			. ( $minutes > 1 ? $minutes . ' minutes' : 'a minute' )
			. ' and try again, or ask an organiser to make your account.';
	}

	private static function since(): string {
		return self::now()->modify( '-' . self::WINDOW_MINUTES . ' minutes' )->format( 'Y-m-d H:i:s' );
	}

	private static function now(): \DateTimeImmutable {
		return Schedule::now();
	}
}
