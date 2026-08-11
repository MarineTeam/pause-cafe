<?php
/**
 * Stamping dishes when they go live, and finding them again afterwards.
 *
 * The stamp happens on the publish transition, so the everyday act of
 * publishing a dish is the whole scheduling step. Nothing has to be filled in.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Product {

	const META_OPENED = '_pclm_opened_at';
	const META_CYCLE  = '_pclm_cycle';

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_stamp_on_publish' ), 10, 3 );
	}

	/**
	 * Opens ordering the moment a dish is published.
	 *
	 * Only fires on the transition into publish, so editing a dish that is
	 * already live does not silently restart its window -- use open_now() for
	 * that, which the publish screen calls explicitly.
	 */
	public static function maybe_stamp_on_publish( $new_status, $old_status, $post ) {
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! self::is_managed( $post->ID ) ) {
			return;
		}

		self::open_now( $post->ID );
	}

	/**
	 * Starts a fresh ordering window for this dish, running to the next cutoff.
	 */
	public static function open_now( $product_id, ?DateTimeImmutable $moment = null ) {
		$moment = $moment ? $moment : PCLM_Schedule::now();

		update_post_meta( (int) $product_id, self::META_OPENED, $moment->format( 'Y-m-d H:i:s' ) );
		update_post_meta( (int) $product_id, self::META_CYCLE, PCLM_Schedule::cycle_for( $moment ) );

		self::flush();
	}

	public static function flush() {
		wp_cache_delete( 'pclm_cycles' );

		if ( class_exists( 'PCLM_Visibility' ) ) {
			PCLM_Visibility::flush();
		}
	}

	public static function get_opened_at( $product_id ) {
		$value = get_post_meta( (int) $product_id, self::META_OPENED, true );

		return $value ? (string) $value : '';
	}

	public static function get_cycle( $product_id ) {
		$value = get_post_meta( (int) $product_id, self::META_CYCLE, true );

		return $value ? (string) $value : '';
	}

	/**
	 * A product is governed if it has been stamped, or if it sits in one of the
	 * configured pickup categories. Drinks, desserts and special orders are left
	 * completely alone.
	 */
	public static function is_managed( $product_id ) {
		if ( get_post_meta( (int) $product_id, self::META_OPENED, true ) ) {
			return true;
		}

		$managed = PCLM_Settings::managed_term_ids();

		if ( ! $managed ) {
			return false;
		}

		return has_term( $managed, 'product_cat', (int) $product_id );
	}

	private static function base_query_args() {
		return array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'menu_order title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'pclm_bypass_visibility' => true,
		);
	}

	/**
	 * @return int[]
	 */
	public static function ids_for_cycle( $cycle, $term_id = 0 ) {
		if ( ! $cycle ) {
			return array();
		}

		$args = array_merge(
			self::base_query_args(),
			array(
				'meta_query' => array(
					array(
						'key'   => self::META_CYCLE,
						'value' => $cycle,
					),
				),
			)
		);

		if ( $term_id ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => (int) $term_id,
				),
			);
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * The dish currently taking orders at a location, or 0. Used by the publish
	 * screen to decide between updating the live menu and starting a new week.
	 */
	public static function open_slot_id( $term_id ) {
		$cycle = PCLM_Schedule::current_cycle();

		if ( ! $cycle ) {
			return 0;
		}

		foreach ( self::ids_for_cycle( $cycle, $term_id ) as $product_id ) {
			if ( PCLM_Schedule::is_listed( self::get_opened_at( $product_id ) ) ) {
				return (int) $product_id;
			}
		}

		return 0;
	}

	/**
	 * @return int[]
	 */
	public static function all_managed_ids() {
		$base = array_merge(
			self::base_query_args(),
			array( 'post_status' => array( 'publish', 'private' ) )
		);

		/*
		 * Stamped and categorised are independent reasons to be managed, so they
		 * are queried separately. Combining them would apply the category filter
		 * to the stamped products too, letting a stamped dish outside the pickup
		 * categories escape the schedule.
		 */
		$by_meta = new WP_Query(
			array_merge(
				$base,
				array(
					'meta_query' => array(
						array(
							'key'     => self::META_OPENED,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);

		$ids     = array_map( 'intval', $by_meta->posts );
		$managed = PCLM_Settings::managed_term_ids();

		if ( $managed ) {
			$by_cat = new WP_Query(
				array_merge(
					$base,
					array(
						'tax_query' => array(
							array(
								'taxonomy' => 'product_cat',
								'field'    => 'term_id',
								'terms'    => $managed,
							),
						),
					)
				)
			);

			$ids = array_merge( $ids, array_map( 'intval', $by_cat->posts ) );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Published products that look like leftover menu items: never stamped, and
	 * outside every pickup category.
	 *
	 * @return int[]
	 */
	public static function unstamped_legacy_ids() {
		$args = array_merge(
			self::base_query_args(),
			array(
				'meta_query' => array(
					array(
						'key'     => self::META_OPENED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$managed = PCLM_Settings::managed_term_ids();

		if ( $managed ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $managed,
					'operator' => 'NOT IN',
				),
			);
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}
}
