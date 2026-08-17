<?php

namespace PauseCafe;

/**
 * The kitchen list, and who is allowed to see it.
 *
 * Cooks and servers need this on a phone on Sunday morning without an account.
 * A shared password gets them in; organisers never see the prompt.
 *
 * The page shows member names and what they ordered, so it is not left open.
 * Setting no password keeps it organiser-only.
 */
class Kitchen {

	private const SESSION_KEY = 'kitchen_ok';

	public static function isProtected(): bool {
		return '' !== Settings::get( 'kitchen_password_hash' );
	}

	/**
	 * Organisers are always in. Everyone else needs the shared password, and
	 * only when one has been set.
	 */
	public static function hasAccess(): bool {
		if ( Auth::isAdmin() ) {
			return true;
		}

		if ( ! self::isProtected() ) {
			return false;
		}

		return ! empty( $_SESSION[ self::SESSION_KEY ] );
	}

	/**
	 * How long this machine has to wait before guessing again, in seconds.
	 *
	 * Counted per source address rather than site-wide. One shared password
	 * means every wrong guess is against the same secret, so a single tally
	 * would let one person with a typo shut the kitchen out on a Sunday
	 * morning -- which on this page is a worse outcome than the guessing.
	 */
	public static function lockedFor(): int {
		$ip = LoginAttempts::ip();

		return LoginAttempts::retryAfter( self::scope( $ip ), $ip );
	}

	public static function unlock( string $password ): bool {
		/*
		 * bcrypt's ~100ms was doing this job, and it was never the right tool.
		 * A cost per guess is not a limit on guesses: nothing stops a hundred
		 * requests at once, and a hundred slow guesses in parallel are a
		 * hundred fast ones. What is behind the page is the congregation's
		 * names, their groups and what each of them is eating.
		 *
		 * Checked here rather than only in the route, for the same reason the
		 * sign-in link refuses in its own method: this is the code that decides
		 * whether a password was right, and a guard living in the caller is one
		 * the next caller will not have.
		 */
		if ( self::lockedFor() > 0 ) {
			return false;
		}

		$ip   = LoginAttempts::ip();
		$hash = Settings::get( 'kitchen_password_hash' );

		if ( '' === $hash || ! password_verify( $password, $hash ) ) {
			LoginAttempts::record( self::scope( $ip ), $ip );

			return false;
		}

		// Getting in clears the tally, so a cook who mistyped twice and then
		// got it right is not still being counted against on their next visit.
		LoginAttempts::forgive( self::scope( $ip ) );

		$_SESSION[ self::SESSION_KEY ] = true;

		return true;
	}

	/**
	 * The name this machine's kitchen guesses are counted under.
	 *
	 * The throttle's tight per-identity limit is keyed on an address, and there
	 * is no address here -- one password serves everybody. Putting the source
	 * in the key borrows that limit and makes it mean "five guesses from this
	 * machine", which is what the kitchen wants, while the throttle's own
	 * per-source limit still catches somebody working through many at once.
	 */
	private static function scope( string $ip ): string {
		return 'kitchen:' . ( '' !== $ip ? $ip : 'unknown' );
	}

	public static function lock(): void {
		unset( $_SESSION[ self::SESSION_KEY ] );
	}

	/**
	 * @param string $password Empty clears it, putting the page back to
	 *                         organisers only.
	 */
	public static function setPassword( string $password ): void {
		Settings::set(
			'kitchen_password_hash',
			'' === $password ? '' : password_hash( $password, PASSWORD_DEFAULT )
		);
	}

	/**
	 * @return array<string,string> Preset key => label.
	 */
	public static function ranges(): array {
		return array(
			'7days' => 'Next 7 days',
			'week'  => 'This week',
			'month' => 'This month',
			'past'  => 'Last 30 days',
			'all'   => 'Everything',
		);
	}

	/**
	 * Turns a preset into a pair of service dates.
	 *
	 * Presets look forward, because the usual question is what still has to be
	 * cooked. "Last 30 days" is there for the times it is not.
	 *
	 * @return array{from:string,to:string}
	 */
	public static function resolveRange( string $preset ): array {
		$today = Schedule::now()->setTime( 0, 0, 0 );

		switch ( $preset ) {
			case 'week':
				// Monday to Sunday around today.
				$start = $today->modify( 'monday this week' );

				return array(
					'from' => $start->format( 'Y-m-d' ),
					'to'   => $start->modify( '+6 days' )->format( 'Y-m-d' ),
				);

			case 'month':
				return array(
					'from' => $today->modify( 'first day of this month' )->format( 'Y-m-d' ),
					'to'   => $today->modify( 'last day of this month' )->format( 'Y-m-d' ),
				);

			case 'past':
				return array(
					'from' => $today->modify( '-30 days' )->format( 'Y-m-d' ),
					'to'   => $today->format( 'Y-m-d' ),
				);

			case 'all':
				return array(
					'from' => '',
					'to'   => '',
				);

			case '7days':
			default:
				return array(
					'from' => $today->format( 'Y-m-d' ),
					'to'   => $today->modify( '+7 days' )->format( 'Y-m-d' ),
				);
		}
	}

	/**
	 * Reads filters off the query string, with everything normalised.
	 *
	 * @return array{range:string,from:string,to:string,dish:string,location:string,group:string}
	 */
	public static function filtersFromQuery( array $query ): array {
		$range = (string) ( $query['range'] ?? '7days' );

		if ( ! array_key_exists( $range, self::ranges() ) ) {
			$range = '7days';
		}

		$dates = self::resolveRange( $range );

		// An explicit from/to wins, so a preset is only ever a shortcut.
		$from = trim( (string) ( $query['from'] ?? '' ) );
		$to   = trim( (string) ( $query['to'] ?? '' ) );

		if ( Schedule::parseDate( $from ) ) {
			$dates['from'] = $from;
			$range         = 'custom';
		}

		if ( Schedule::parseDate( $to ) ) {
			$dates['to'] = $to;
			$range       = 'custom';
		}

		return array(
			'range'    => $range,
			'from'     => $dates['from'],
			'to'       => $dates['to'],
			'dish'     => trim( (string) ( $query['dish'] ?? '' ) ),
			'location' => trim( (string) ( $query['location'] ?? '' ) ),
			'group'    => trim( (string) ( $query['group'] ?? '' ) ),
		);
	}

	/**
	 * Rebuilds the current query string with one value changed, so sort links
	 * and filter forms keep everything else in place.
	 */
	public static function url( array $query, array $changes = array(), string $base = '/kitchen' ): string {
		$merged = array_merge( $query, $changes );

		foreach ( $merged as $key => $value ) {
			if ( '' === $value || null === $value ) {
				unset( $merged[ $key ] );
			}
		}

		return $base . ( $merged ? '?' . http_build_query( $merged ) : '' );
	}
}
