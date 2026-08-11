<?php
/**
 * Plugin settings, stored as a single option array.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Settings {

	const OPTION = 'pcm_settings';

	/**
	 * Defaults match Pause Cafe's actual rhythm: menu opens Tuesday lunchtime,
	 * orders close Saturday 1pm, food is handed over on Sunday.
	 */
	public static function defaults() {
		return array(
			'service_weekday'   => 0,   // 0 = Sunday, matching PHP's 'w' format.
			'open_days_before'  => 5,   // Tuesday.
			'open_time'         => '12:00',
			'close_days_before' => 1,   // Saturday.
			'close_time'        => '13:00',
			'default_price'     => '10.00',
			'preview_upcoming'  => 'no',
			'menu_page_id'      => 0,
			'locations'         => array(),
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$current = self::all();

		update_option( self::OPTION, array_merge( $current, $values ) );
	}

	/**
	 * Configured pickup locations as a list of [ 'label' => string, 'term_id' => int ].
	 * Entries pointing at deleted categories are dropped rather than returned
	 * broken, because every caller uses term_id to build a query.
	 */
	public static function locations() {
		$locations = self::get( 'locations' );
		$clean     = array();

		if ( ! is_array( $locations ) ) {
			return $clean;
		}

		foreach ( $locations as $location ) {
			if ( empty( $location['term_id'] ) ) {
				continue;
			}

			$term = get_term( (int) $location['term_id'], 'product_cat' );

			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}

			$clean[] = array(
				'label'   => ! empty( $location['label'] ) ? $location['label'] : $term->name,
				'term_id' => (int) $location['term_id'],
			);
		}

		return $clean;
	}

	/**
	 * Term IDs of every category the plugin considers a menu category. Used to
	 * decide which products the schedule governs.
	 */
	public static function managed_term_ids() {
		return wp_list_pluck( self::locations(), 'term_id' );
	}

	public static function preview_upcoming() {
		return 'yes' === self::get( 'preview_upcoming' );
	}
}
