<?php
/**
 * The rules engine.
 *
 * Publishing is what opens ordering, so a dish's window starts the moment it
 * goes live and ends at the first cutoff after that -- Saturday 1pm by default.
 * It stays shut through the service day, and the next published menu starts a
 * fresh window. Nothing is scheduled in advance and nothing has to be dated by
 * hand.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Schedule {

	const OPEN   = 'open';
	const CLOSED = 'closed';
	const PAST   = 'past';

	public static function timezone() {
		return wp_timezone();
	}

	public static function now() {
		return new DateTimeImmutable( 'now', self::timezone() );
	}

	/**
	 * @return DateTimeImmutable|null Null when the stored value is unusable.
	 */
	public static function parse( $datetime ) {
		if ( ! $datetime ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $datetime, self::timezone() );

		return $parsed ? $parsed : null;
	}

	/**
	 * The first cutoff strictly after the given moment.
	 *
	 * Strictly matters: a menu published on Saturday at 14:00 has missed that
	 * day's 13:00 cutoff, so its window runs to the following Saturday rather
	 * than closing in the past.
	 */
	public static function cutoff_after( DateTimeImmutable $moment ) {
		$weekday = (int) PCLM_Settings::get( 'close_weekday' );
		$parts   = explode( ':', (string) PCLM_Settings::get( 'close_time' ) );
		$hours   = isset( $parts[0] ) ? (int) $parts[0] : 13;
		$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;

		$days_ahead = ( $weekday - (int) $moment->format( 'w' ) + 7 ) % 7;
		$candidate  = $moment->modify( '+' . $days_ahead . ' days' )->setTime( $hours, $minutes, 0 );

		if ( $candidate <= $moment ) {
			$candidate = $candidate->modify( '+7 days' );
		}

		return $candidate;
	}

	/**
	 * The day the food is handed over: the day after the cutoff by default.
	 */
	public static function service_date_for_cutoff( DateTimeImmutable $cutoff ) {
		$days = absint( PCLM_Settings::get( 'service_days_after_close' ) );

		return $cutoff->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	public static function expires_after( DateTimeImmutable $cutoff ) {
		$days = absint( PCLM_Settings::get( 'service_days_after_close' ) );

		return $cutoff->modify( '+' . $days . ' days' )->setTime( 23, 59, 59 );
	}

	/**
	 * A cycle is identified by its cutoff date, so every dish published in the
	 * same window shares one key. That is what the kitchen report groups on --
	 * no service date has to be entered anywhere.
	 */
	public static function cycle_for( DateTimeImmutable $opened_at ) {
		return self::cutoff_after( $opened_at )->format( 'Y-m-d' );
	}

	/**
	 * @param string $opened_at Y-m-d H:i:s.
	 * @return string One of the state constants. Unusable values read as PAST so
	 *                broken data fails closed rather than becoming buyable.
	 */
	public static function state_for( $opened_at, ?DateTimeImmutable $now = null ) {
		$opened = self::parse( $opened_at );

		if ( ! $opened ) {
			return self::PAST;
		}

		$cutoff  = self::cutoff_after( $opened );
		$expires = self::expires_after( $cutoff );
		$now     = $now ? $now : self::now();

		if ( $now > $expires ) {
			return self::PAST;
		}

		if ( $now >= $cutoff ) {
			return self::CLOSED;
		}

		// A window that has not started yet is not orderable either.
		if ( $now < $opened ) {
			return self::CLOSED;
		}

		return self::OPEN;
	}

	public static function is_orderable( $opened_at, ?DateTimeImmutable $now = null ) {
		return self::OPEN === self::state_for( $opened_at, $now );
	}

	/**
	 * Whether a dish should still appear in listings. Closed dishes stay visible
	 * through the service day so people can check what they ordered.
	 */
	public static function is_listed( $opened_at, ?DateTimeImmutable $now = null ) {
		return self::PAST !== self::state_for( $opened_at, $now );
	}

	/**
	 * Every cycle that has at least one published dish, ascending.
	 *
	 * @return string[] Cutoff dates as Y-m-d.
	 */
	public static function all_cycles() {
		global $wpdb;

		$cycles = wp_cache_get( 'pclm_cycles' );

		if ( false === $cycles ) {
			$cycles = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = %s
					   AND pm.meta_value != ''
					   AND p.post_type = 'product'
					   AND p.post_status = 'publish'
					 ORDER BY pm.meta_value ASC",
					PCLM_Product::META_CYCLE
				)
			);

			wp_cache_set( 'pclm_cycles', $cycles, '', 300 );
		}

		return is_array( $cycles ) ? $cycles : array();
	}

	/**
	 * The cycle the storefront should be showing: the earliest that has not yet
	 * expired, which in practice is whatever was published most recently.
	 */
	public static function current_cycle() {
		$today = self::now()->format( 'Y-m-d' );

		foreach ( self::all_cycles() as $cycle ) {
			if ( $cycle >= $today ) {
				return $cycle;
			}
		}

		return null;
	}

	/**
	 * A cycle key is the cutoff date, so the cutoff moment is that date at the
	 * configured closing time.
	 */
	public static function cutoff_for_cycle( $cycle ) {
		$date = self::parse( $cycle . ' 00:00:00' );

		if ( ! $date ) {
			return null;
		}

		$parts   = explode( ':', (string) PCLM_Settings::get( 'close_time' ) );
		$hours   = isset( $parts[0] ) ? (int) $parts[0] : 13;
		$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return $date->setTime( $hours, $minutes, 0 );
	}

	/**
	 * The day the food for a cycle is handed over.
	 */
	public static function service_date_for_cycle( $cycle ) {
		$cutoff = self::cutoff_for_cycle( $cycle );

		return $cutoff ? self::service_date_for_cutoff( $cutoff ) : '';
	}

	/**
	 * State of a whole cycle, for the menu header and the report. Every dish in
	 * a cycle shares one cutoff, so they cannot disagree.
	 */
	public static function cycle_state( $cycle, ?DateTimeImmutable $now = null ) {
		$cutoff = self::cutoff_for_cycle( $cycle );

		if ( ! $cutoff ) {
			return self::PAST;
		}

		$now = $now ? $now : self::now();

		if ( $now > self::expires_after( $cutoff ) ) {
			return self::PAST;
		}

		if ( $now >= $cutoff ) {
			return self::CLOSED;
		}

		return self::OPEN;
	}

	public static function cycle_state_message( $cycle ) {
		if ( self::OPEN === self::cycle_state( $cycle ) ) {
			return sprintf(
				/* translators: %s: date and time ordering closes. */
				__( 'Ordering closes %s.', 'pause-cafe-live-menu' ),
				self::format_moment( self::cutoff_for_cycle( $cycle ) )
			);
		}

		return __( 'Ordering is closed. It reopens when the next menu is published.', 'pause-cafe-live-menu' );
	}

	public static function format_moment( ?DateTimeImmutable $moment = null ) {
		if ( ! $moment ) {
			return '';
		}

		return wp_date(
			get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ),
			$moment->getTimestamp()
		);
	}

	public static function format_date( $date, $format = null ) {
		$parsed = self::parse( $date . ' 00:00:00' );

		if ( ! $parsed ) {
			return '';
		}

		return wp_date( $format ? $format : get_option( 'date_format' ), $parsed->getTimestamp() );
	}

	/**
	 * Customer-facing explanation of why a dish can or cannot be ordered.
	 */
	public static function state_message( $opened_at ) {
		$opened = self::parse( $opened_at );

		if ( ! $opened ) {
			return __( 'This dish is no longer available.', 'pause-cafe-live-menu' );
		}

		switch ( self::state_for( $opened_at ) ) {
			case self::OPEN:
				return sprintf(
					/* translators: %s: date and time ordering closes. */
					__( 'Ordering closes %s.', 'pause-cafe-live-menu' ),
					self::format_moment( self::cutoff_after( $opened ) )
				);

			case self::CLOSED:
				return __( 'Ordering is closed. It reopens when the next menu is published.', 'pause-cafe-live-menu' );
		}

		return __( 'This dish is no longer available.', 'pause-cafe-live-menu' );
	}
}
