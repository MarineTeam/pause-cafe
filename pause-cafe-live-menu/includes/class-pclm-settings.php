<?php
/**
 * Plugin settings, stored as a single option array.
 *
 * There is deliberately no "opens at" setting. Publishing is what opens
 * ordering, so the only thing to configure is when it shuts.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Settings {

	const OPTION = 'pclm_settings';

	public static function defaults() {
		return array(
			'close_weekday'            => 6,   // Saturday, matching PHP's 'w' format.
			'close_time'               => '13:00',
			'service_days_after_close' => 1,   // Food is handed over the day after cutoff.
			'default_price'            => '10.00',
			'menu_page_id'             => 0,
			'locations'                => array(),
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
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * Configured pickup locations. Entries pointing at deleted categories are
	 * dropped, because every caller uses term_id to build a query.
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

	public static function managed_term_ids() {
		return wp_list_pluck( self::locations(), 'term_id' );
	}
}
