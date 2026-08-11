<?php
/**
 * Reading and writing a product's service date, and working out which products
 * the schedule is allowed to govern.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Product {

	const META = '_pcm_service_date';

	public static function get_service_date( $product_id ) {
		$value = get_post_meta( (int) $product_id, self::META, true );

		return $value ? (string) $value : '';
	}

	public static function set_service_date( $product_id, $service_date ) {
		if ( ! $service_date ) {
			delete_post_meta( (int) $product_id, self::META );
		} else {
			update_post_meta( (int) $product_id, self::META, sanitize_text_field( $service_date ) );
		}

		wp_cache_delete( 'pcm_service_dates' );
	}

	/**
	 * A product is governed if it carries a service date, or if it sits in one of
	 * the configured pickup categories.
	 *
	 * The category half matters: it means a menu item that somehow lost its date
	 * is treated as unavailable rather than silently becoming buyable forever.
	 * Products outside those categories -- drinks, desserts, special orders --
	 * are left completely alone.
	 */
	public static function is_managed( $product_id ) {
		if ( self::get_service_date( $product_id ) ) {
			return true;
		}

		$managed = PCM_Settings::managed_term_ids();

		if ( ! $managed ) {
			return false;
		}

		return has_term( $managed, 'product_cat', (int) $product_id );
	}

	/**
	 * Published products for a service date, optionally limited to one pickup
	 * category.
	 *
	 * @return int[] Product IDs.
	 */
	public static function ids_for_date( $service_date, $term_id = 0 ) {
		if ( ! $service_date ) {
			return array();
		}

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'menu_order title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'pcm_bypass_visibility'  => true,
			'meta_query'             => array(
				array(
					'key'   => self::META,
					'value' => $service_date,
				),
			),
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
	 * The single product for a date and location, or 0. The builder keeps one
	 * dish per cell, so anything beyond the first is a duplicate the admin
	 * created by hand.
	 */
	public static function id_for_slot( $service_date, $term_id ) {
		$ids = self::ids_for_date( $service_date, $term_id );

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Every product the plugin governs, dated or not.
	 *
	 * @return int[]
	 */
	public static function all_managed_ids() {
		$base = array(
			'post_type'              => 'product',
			'post_status'            => array( 'publish', 'private' ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'pcm_bypass_visibility'  => true,
		);

		/*
		 * The two reasons to be managed are independent, so they are queried
		 * separately and merged. Combining them into one query would apply the
		 * category restriction to the dated products as well, which would let a
		 * dated dish outside the pickup categories escape the schedule.
		 */
		$by_meta = new WP_Query(
			array_merge(
				$base,
				array(
					'meta_query' => array(
						array(
							'key'     => self::META,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);

		$ids     = array_map( 'intval', $by_meta->posts );
		$managed = PCM_Settings::managed_term_ids();

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
	 * Published products that look like leftover menu items: no service date and
	 * not in any pickup category. These are the ones the archive tool offers up.
	 *
	 * @return int[]
	 */
	public static function undated_legacy_ids() {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'pcm_bypass_visibility'  => true,
			'meta_query'             => array(
				array(
					'key'     => self::META,
					'compare' => 'NOT EXISTS',
				),
			),
		);

		$managed = PCM_Settings::managed_term_ids();

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
