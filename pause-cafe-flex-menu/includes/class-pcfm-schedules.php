<?php
/**
 * Schedules -- the thing that makes several menus possible at once.
 *
 * A schedule is a term in the pcfm_schedule taxonomy with its rules in term
 * meta. Using a real taxonomy means dishes are assigned through WordPress's own
 * UI and found with an ordinary tax_query, rather than through a bespoke
 * registry that would need its own storage, caching and admin screen.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Schedules {

	const TAXONOMY = 'pcfm_schedule';
	const META     = 'pcfm_rules';

	const MODE_PLANNED    = 'planned';
	const MODE_ON_PUBLISH = 'on_publish';
	const MODE_MANUAL     = 'manual';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
	}

	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			array( 'product' ),
			array(
				'label'             => __( 'Menu schedule', 'pause-cafe-flex-menu' ),
				'labels'            => array(
					'name'          => __( 'Menu schedules', 'pause-cafe-flex-menu' ),
					'singular_name' => __( 'Menu schedule', 'pause-cafe-flex-menu' ),
					'add_new_item'  => __( 'Add menu schedule', 'pause-cafe-flex-menu' ),
					'edit_item'     => __( 'Edit menu schedule', 'pause-cafe-flex-menu' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_woocommerce',
					'edit_terms'   => 'manage_woocommerce',
					'delete_terms' => 'manage_woocommerce',
					'assign_terms' => 'edit_products',
				),
			)
		);
	}

	public static function modes() {
		return array(
			self::MODE_PLANNED    => __( 'Planned ahead — dishes carry a service date', 'pause-cafe-flex-menu' ),
			self::MODE_ON_PUBLISH => __( 'On publish — ordering opens the moment a dish goes live', 'pause-cafe-flex-menu' ),
			self::MODE_MANUAL     => __( 'Manual — each dish carries its own from and until', 'pause-cafe-flex-menu' ),
		);
	}

	/**
	 * `close_time` is deliberately shared by the planned and on-publish modes:
	 * in both it means the time of day ordering shuts.
	 */
	public static function default_rules() {
		return array(
			'mode'                     => self::MODE_PLANNED,

			// Planned.
			'service_weekday'          => 0,
			'open_days_before'         => 5,
			'open_time'                => '12:00',
			'close_days_before'        => 1,

			// On publish.
			'close_weekday'            => 6,
			'service_days_after_close' => 1,

			// Shared.
			'close_time'               => '13:00',
			'preview_upcoming'         => 'no',
			'locations'                => array(),
			'location_offsets'         => array(),
			'default_capacity'         => 0,
			'default_price'            => '',
		);
	}

	public static function rules( $term_id ) {
		$stored = $term_id ? get_term_meta( (int) $term_id, self::META, true ) : array();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::default_rules() );
	}

	public static function save_rules( $term_id, array $rules ) {
		$clean = wp_parse_args( $rules, self::rules( $term_id ) );

		update_term_meta( (int) $term_id, self::META, $clean );

		if ( class_exists( 'PCFM_Window' ) ) {
			PCFM_Window::flush();
		}
	}

	/**
	 * @return WP_Term[]
	 */
	public static function all() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	public static function exists( $term_id ) {
		$term = get_term( (int) $term_id, self::TAXONOMY );

		return $term && ! is_wp_error( $term );
	}

	/**
	 * The schedule a dish belongs to, or 0. A dish is only ever in one.
	 */
	public static function for_product( $product_id ) {
		$ids = wp_get_object_terms( (int) $product_id, self::TAXONOMY, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $ids ) || ! $ids ) {
			return 0;
		}

		return (int) $ids[0];
	}

	public static function assign( $product_id, $term_id ) {
		wp_set_object_terms( (int) $product_id, array( (int) $term_id ), self::TAXONOMY, false );
	}

	/**
	 * Locations this schedule serves. An empty list on the schedule means every
	 * location configured for the site.
	 *
	 * @return array[] Same shape as PCFM_Settings::locations().
	 */
	public static function locations( $term_id ) {
		$rules = self::rules( $term_id );
		$all   = PCFM_Settings::locations();

		if ( empty( $rules['locations'] ) ) {
			return $all;
		}

		$wanted = array_map( 'intval', (array) $rules['locations'] );

		return array_values(
			array_filter(
				$all,
				function ( $location ) use ( $wanted ) {
					return in_array( (int) $location['term_id'], $wanted, true );
				}
			)
		);
	}

	/**
	 * Minutes this location closes earlier than the schedule's own cutoff.
	 */
	public static function location_offset( $term_id, $location_term_id ) {
		$rules   = self::rules( $term_id );
		$offsets = is_array( $rules['location_offsets'] ) ? $rules['location_offsets'] : array();

		return isset( $offsets[ $location_term_id ] ) ? absint( $offsets[ $location_term_id ] ) : 0;
	}
}
