<?php
/**
 * The rules engine.
 *
 * Every ordering decision in this plugin comes from one value: a dish's service
 * date. Opening time, cutoff and expiry are derived from it, so there is no
 * per-product schedule to configure and no way for two dishes in the same week
 * to disagree about when ordering closes.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Schedule {

	const UPCOMING = 'upcoming';
	const OPEN     = 'open';
	const CLOSED   = 'closed';
	const PAST     = 'past';

	public static function timezone() {
		return wp_timezone();
	}

	public static function now() {
		return new DateTimeImmutable( 'now', self::timezone() );
	}

	/**
	 * A Y-m-d service date as a DateTimeImmutable at local midnight.
	 *
	 * @return DateTimeImmutable|null Null when the string is not a valid date.
	 */
	public static function date_obj( $service_date ) {
		if ( ! $service_date ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $service_date . ' 00:00:00', self::timezone() );

		if ( ! $date || $date->format( 'Y-m-d' ) !== $service_date ) {
			return null;
		}

		return $date;
	}

	/**
	 * Applies an offset of "N days before the service date, at HH:MM".
	 */
	private static function offset_from( $service_date, $days_before, $time ) {
		$date = self::date_obj( $service_date );

		if ( ! $date ) {
			return null;
		}

		$parts   = explode( ':', (string) $time );
		$hours   = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$minutes = isset( $parts[1] ) ? (int) $parts[1] : 0;

		return $date
			->modify( '-' . absint( $days_before ) . ' days' )
			->setTime( $hours, $minutes, 0 );
	}

	public static function opens_at( $service_date ) {
		return self::offset_from(
			$service_date,
			PCM_Settings::get( 'open_days_before' ),
			PCM_Settings::get( 'open_time' )
		);
	}

	public static function closes_at( $service_date ) {
		return self::offset_from(
			$service_date,
			PCM_Settings::get( 'close_days_before' ),
			PCM_Settings::get( 'close_time' )
		);
	}

	/**
	 * A dish stays listed through the end of the day it is served, then drops off.
	 */
	public static function expires_at( $service_date ) {
		$date = self::date_obj( $service_date );

		return $date ? $date->setTime( 23, 59, 59 ) : null;
	}

	/**
	 * @return string One of the state constants. Invalid dates read as PAST so
	 *                that broken data fails closed rather than becoming buyable.
	 */
	public static function state_for( $service_date, ?DateTimeImmutable $now = null ) {
		$opens   = self::opens_at( $service_date );
		$closes  = self::closes_at( $service_date );
		$expires = self::expires_at( $service_date );

		if ( ! $opens || ! $closes || ! $expires ) {
			return self::PAST;
		}

		$now = $now ? $now : self::now();

		if ( $now > $expires ) {
			return self::PAST;
		}

		if ( $now < $opens ) {
			return self::UPCOMING;
		}

		if ( $now >= $closes ) {
			return self::CLOSED;
		}

		return self::OPEN;
	}

	public static function is_orderable( $service_date, ?DateTimeImmutable $now = null ) {
		return self::OPEN === self::state_for( $service_date, $now );
	}

	/**
	 * Whether a dish for this date should appear in listings at all. Upcoming
	 * weeks are hidden unless the site has opted into previewing them.
	 */
	public static function is_listed( $service_date, ?DateTimeImmutable $now = null ) {
		$state = self::state_for( $service_date, $now );

		if ( self::PAST === $state ) {
			return false;
		}

		if ( self::OPEN === $state || self::CLOSED === $state ) {
			return true;
		}

		if ( PCM_Settings::preview_upcoming() ) {
			return true;
		}

		/*
		 * The nearest week is always listed, even before ordering opens. Between
		 * Sunday service and Tuesday noon there would otherwise be nothing on the
		 * menu at all; showing the dishes with "ordering opens Tuesday" reads far
		 * better than an empty page. Weeks beyond that stay hidden unless the
		 * site has turned previews on.
		 */
		return $service_date === self::current_service_date();
	}

	/**
	 * Every distinct service date that has at least one product, ascending.
	 *
	 * @return string[]
	 */
	public static function all_service_dates() {
		global $wpdb;

		$dates = wp_cache_get( 'pcm_service_dates' );

		if ( false === $dates ) {
			$dates = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_value
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = %s
					   AND pm.meta_value != ''
					   AND p.post_type = 'product'
					   AND p.post_status = 'publish'
					 ORDER BY pm.meta_value ASC",
					PCM_Product::META
				)
			);

			wp_cache_set( 'pcm_service_dates', $dates, '', 300 );
		}

		return is_array( $dates ) ? $dates : array();
	}

	/**
	 * The week the storefront should be showing: the earliest service date that
	 * has not yet expired. Returns null when nothing is scheduled.
	 */
	public static function current_service_date() {
		$today = self::now()->format( 'Y-m-d' );

		foreach ( self::all_service_dates() as $date ) {
			if ( $date >= $today ) {
				return $date;
			}
		}

		return null;
	}

	/**
	 * Service weekdays falling inside a given month, for the builder grid.
	 *
	 * @return string[] Y-m-d dates.
	 */
	public static function service_dates_in_month( $year, $month ) {
		$weekday = (int) PCM_Settings::get( 'service_weekday' );
		$dates   = array();

		$cursor = DateTimeImmutable::createFromFormat(
			'Y-n-j H:i:s',
			$year . '-' . $month . '-1 00:00:00',
			self::timezone()
		);

		if ( ! $cursor ) {
			return $dates;
		}

		$days = (int) $cursor->format( 't' );

		for ( $day = 1; $day <= $days; $day++ ) {
			$candidate = $cursor->setDate( (int) $year, (int) $month, $day );

			if ( (int) $candidate->format( 'w' ) === $weekday ) {
				$dates[] = $candidate->format( 'Y-m-d' );
			}
		}

		return $dates;
	}

	public static function format_date( $service_date, $format = null ) {
		$date = self::date_obj( $service_date );

		if ( ! $date ) {
			return '';
		}

		return wp_date( $format ? $format : get_option( 'date_format' ), $date->getTimestamp() );
	}

	private static function format_moment( ?DateTimeImmutable $moment = null ) {
		if ( ! $moment ) {
			return '';
		}

		return wp_date(
			get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ),
			$moment->getTimestamp()
		);
	}

	/**
	 * Customer-facing explanation of why a dish can or cannot be ordered. The
	 * silent missing add-to-cart button is the thing this replaces.
	 */
	public static function state_message( $service_date ) {
		switch ( self::state_for( $service_date ) ) {
			case self::OPEN:
				return sprintf(
					/* translators: %s: date and time ordering closes. */
					__( 'Ordering closes %s.', 'pause-cafe-menu' ),
					self::format_moment( self::closes_at( $service_date ) )
				);

			case self::UPCOMING:
				return sprintf(
					/* translators: %s: date and time ordering opens. */
					__( 'Ordering opens %s.', 'pause-cafe-menu' ),
					self::format_moment( self::opens_at( $service_date ) )
				);

			case self::CLOSED:
				return sprintf(
					/* translators: %s: date and time ordering closed. */
					__( 'Ordering closed %s.', 'pause-cafe-menu' ),
					self::format_moment( self::closes_at( $service_date ) )
				);
		}

		return __( 'This dish is no longer available.', 'pause-cafe-menu' );
	}
}
