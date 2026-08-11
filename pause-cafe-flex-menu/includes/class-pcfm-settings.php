<?php
/**
 * Site-wide settings.
 *
 * Anything that varies per menu lives on the schedule instead -- see
 * PCFM_Schedules. Only things genuinely shared by every menu are here.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Settings {

	const OPTION = 'pcfm_settings';

	public static function defaults() {
		return array(
			'default_price' => '10.00',
			'menu_page_id'  => 0,
			'locations'     => array(),
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
	 * Every pickup location on the site, as [ 'label' => string, 'term_id' => int ].
	 * Entries pointing at deleted categories are dropped, because every caller
	 * uses term_id to build a query.
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

	public static function location_label( $term_id ) {
		foreach ( self::locations() as $location ) {
			if ( (int) $location['term_id'] === (int) $term_id ) {
				return $location['label'];
			}
		}

		return '';
	}

	public static function location_term_ids() {
		return wp_list_pluck( self::locations(), 'term_id' );
	}

	/**
	 * The pickup category a dish belongs to, or 0.
	 */
	public static function location_for_product( $product_id ) {
		foreach ( self::location_term_ids() as $term_id ) {
			if ( has_term( $term_id, 'product_cat', (int) $product_id ) ) {
				return (int) $term_id;
			}
		}

		return 0;
	}
}
