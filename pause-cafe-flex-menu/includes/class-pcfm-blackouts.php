<?php
/**
 * Dates on which no menu runs -- Christmas, a long weekend, a week the kitchen
 * is closed.
 *
 * A blackout voids the window for any dish serving that day, so the dishes go
 * away rather than sitting there orderable. The label is shown on the menu so
 * people know why, instead of finding an empty page.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Blackouts {

	const OPTION = 'pcfm_blackouts';

	/**
	 * @return array Y-m-d => label.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		ksort( $stored );

		return $stored;
	}

	public static function update( array $dates ) {
		$clean = array();

		foreach ( $dates as $date => $label ) {
			$date = trim( (string) $date );

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}

			$clean[ $date ] = sanitize_text_field( $label );
		}

		ksort( $clean );

		update_option( self::OPTION, $clean );

		if ( class_exists( 'PCFM_Window' ) ) {
			PCFM_Window::flush();
		}
	}

	public static function is_blackout( $date ) {
		if ( ! $date ) {
			return false;
		}

		return array_key_exists( $date, self::all() );
	}

	public static function label( $date ) {
		$all = self::all();

		if ( ! isset( $all[ $date ] ) || '' === $all[ $date ] ) {
			return __( 'No menu this week', 'pause-cafe-flex-menu' );
		}

		return $all[ $date ];
	}

	/**
	 * Blackouts from today onwards, for the admin screen and for skipping dates
	 * when a month is generated.
	 *
	 * @return array Y-m-d => label.
	 */
	public static function upcoming() {
		$today = PCFM_Window::now()->format( 'Y-m-d' );

		return array_filter(
			self::all(),
			function ( $label, $date ) use ( $today ) {
				return $date >= $today;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}
}
