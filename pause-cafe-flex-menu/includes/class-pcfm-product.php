<?php
/**
 * Product-side plumbing: stamping, capacity, and finding dishes again.
 *
 * The service date is derived for two of the three modes, which makes it
 * unqueryable in SQL. So it is also written to a denormalised key whenever a
 * dish changes -- resolution always uses PCFM_Window, but listings and the
 * kitchen report can still find dishes with an ordinary meta query.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Product {

	/** Denormalised copy of the resolved service date. Never read for decisions. */
	const META_RESOLVED = '_pcfm_resolved_service_date';

	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_stamp_on_publish' ), 10, 3 );
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'sync_after_save' ) );
	}

	/**
	 * Opens ordering when a dish on an on-publish schedule goes live.
	 *
	 * Only fires on the transition into publish, so editing a dish that is
	 * already live does not silently restart its window.
	 */
	public static function maybe_stamp_on_publish( $new_status, $old_status, $post ) {
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$schedule = PCFM_Schedules::for_product( $post->ID );

		if ( ! $schedule ) {
			return;
		}

		$rules = PCFM_Schedules::rules( $schedule );

		if ( PCFM_Schedules::MODE_ON_PUBLISH !== $rules['mode'] ) {
			self::sync_resolved( $post->ID );
			return;
		}

		self::open_now( $post->ID );
	}

	/**
	 * Starts a fresh ordering window for a dish on an on-publish schedule.
	 */
	public static function open_now( $product_id, ?DateTimeImmutable $moment = null ) {
		$moment = $moment ? $moment : PCFM_Window::now();

		update_post_meta( (int) $product_id, PCFM_Window::META_OPENED_AT, $moment->format( 'Y-m-d H:i:s' ) );

		PCFM_Window::flush();
		self::sync_resolved( $product_id );
	}

	public static function sync_after_save( $product ) {
		if ( $product instanceof WC_Product ) {
			PCFM_Window::flush();
			self::sync_resolved( $product->get_id() );
		}
	}

	/**
	 * Recomputes and stores the denormalised service date.
	 */
	public static function sync_resolved( $product_id ) {
		$window = PCFM_Window::for_product( $product_id );

		if ( $window->service_date ) {
			update_post_meta( (int) $product_id, self::META_RESOLVED, $window->service_date );
		} else {
			delete_post_meta( (int) $product_id, self::META_RESOLVED );
		}

		wp_cache_delete( 'pcfm_service_dates' );
	}

	/**
	 * Resyncs every dish on a schedule. Called when a schedule's rules change,
	 * because that can move the service date of everything on it.
	 */
	public static function resync_schedule( $schedule_id ) {
		PCFM_Window::flush();

		foreach ( self::ids_for_schedule( $schedule_id ) as $product_id ) {
			self::sync_resolved( $product_id );
		}
	}

	/**
	 * A dish is governed if it is on a schedule, carries any scheduling meta, or
	 * sits in a pickup category. Drinks, desserts and special orders are left
	 * completely alone.
	 */
	public static function is_managed( $product_id ) {
		$product_id = (int) $product_id;

		if ( PCFM_Schedules::for_product( $product_id ) ) {
			return true;
		}

		foreach ( array( PCFM_Window::META_SERVICE_DATE, PCFM_Window::META_OPENED_AT, PCFM_Window::META_OPEN_FROM ) as $key ) {
			if ( get_post_meta( $product_id, $key, true ) ) {
				return true;
			}
		}

		$locations = PCFM_Settings::location_term_ids();

		return $locations ? has_term( $locations, 'product_cat', $product_id ) : false;
	}

	private static function base_args() {
		return array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'menu_order title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'pcfm_bypass_visibility' => true,
		);
	}

	/**
	 * @return int[]
	 */
	public static function ids_for_schedule( $schedule_id ) {
		if ( ! $schedule_id ) {
			return array();
		}

		$query = new WP_Query(
			array_merge(
				self::base_args(),
				array(
					'post_status' => array( 'publish', 'draft', 'private' ),
					'tax_query'   => array(
						array(
							'taxonomy' => PCFM_Schedules::TAXONOMY,
							'field'    => 'term_id',
							'terms'    => (int) $schedule_id,
						),
					),
				)
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Published dishes serving on a date, optionally narrowed to one schedule
	 * and one pickup location.
	 *
	 * @return int[]
	 */
	public static function ids_for_service_date( $service_date, $schedule_id = 0, $location_term_id = 0 ) {
		if ( ! $service_date ) {
			return array();
		}

		$args = array_merge(
			self::base_args(),
			array(
				'meta_query' => array(
					array(
						'key'   => self::META_RESOLVED,
						'value' => $service_date,
					),
				),
			)
		);

		$tax = array();

		if ( $schedule_id ) {
			$tax[] = array(
				'taxonomy' => PCFM_Schedules::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => (int) $schedule_id,
			);
		}

		if ( $location_term_id ) {
			$tax[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => (int) $location_term_id,
			);
		}

		if ( $tax ) {
			$args['tax_query'] = $tax;
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	public static function id_for_slot( $service_date, $schedule_id, $location_term_id ) {
		$ids = self::ids_for_service_date( $service_date, $schedule_id, $location_term_id );

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * The dish currently taking orders at a location on an on-publish schedule.
	 */
	public static function open_slot_id( $schedule_id, $location_term_id ) {
		foreach ( self::ids_for_schedule( $schedule_id ) as $product_id ) {
			if ( 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}

			if ( ! has_term( (int) $location_term_id, 'product_cat', $product_id ) ) {
				continue;
			}

			if ( PCFM_Window::for_product( $product_id )->is_listed() ) {
				return (int) $product_id;
			}
		}

		return 0;
	}

	/**
	 * Every distinct service date with a published dish, ascending.
	 *
	 * @return string[]
	 */
	public static function all_service_dates( $schedule_id = 0 ) {
		global $wpdb;

		$key = 'pcfm_service_dates';

		if ( ! $schedule_id ) {
			$cached = wp_cache_get( $key );

			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : array();
			}
		}

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
				self::META_RESOLVED
			)
		);

		$dates = is_array( $dates ) ? $dates : array();

		if ( $schedule_id ) {
			$dates = array_values(
				array_filter(
					$dates,
					function ( $date ) use ( $schedule_id ) {
						return (bool) self::ids_for_service_date( $date, $schedule_id );
					}
				)
			);
		} else {
			wp_cache_set( $key, $dates, '', 300 );
		}

		return $dates;
	}

	/**
	 * The next service date not yet past, for a schedule or across all of them.
	 */
	public static function current_service_date( $schedule_id = 0 ) {
		$today = PCFM_Window::now()->format( 'Y-m-d' );

		foreach ( self::all_service_dates( $schedule_id ) as $date ) {
			if ( $date >= $today ) {
				return $date;
			}
		}

		return null;
	}

	/**
	 * @return int[]
	 */
	public static function all_managed_ids() {
		$base = array_merge(
			self::base_args(),
			array( 'post_status' => array( 'publish', 'private' ) )
		);

		$ids = array();

		// On a schedule.
		$by_schedule = new WP_Query(
			array_merge(
				$base,
				array(
					'tax_query' => array(
						array(
							'taxonomy' => PCFM_Schedules::TAXONOMY,
							'operator' => 'EXISTS',
						),
					),
				)
			)
		);

		$ids = array_merge( $ids, array_map( 'intval', $by_schedule->posts ) );

		// Carrying scheduling meta.
		foreach ( array( PCFM_Window::META_SERVICE_DATE, PCFM_Window::META_OPENED_AT, PCFM_Window::META_OPEN_FROM ) as $key ) {
			$by_meta = new WP_Query(
				array_merge(
					$base,
					array(
						'meta_query' => array(
							array(
								'key'     => $key,
								'compare' => 'EXISTS',
							),
						),
					)
				)
			);

			$ids = array_merge( $ids, array_map( 'intval', $by_meta->posts ) );
		}

		// In a pickup category.
		$locations = PCFM_Settings::location_term_ids();

		if ( $locations ) {
			$by_cat = new WP_Query(
				array_merge(
					$base,
					array(
						'tax_query' => array(
							array(
								'taxonomy' => 'product_cat',
								'field'    => 'term_id',
								'terms'    => $locations,
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
	 * Published products that look like leftover menu items: no schedule, no
	 * scheduling meta, and outside every pickup category.
	 *
	 * @return int[]
	 */
	public static function unscheduled_legacy_ids() {
		$args = array_merge(
			self::base_args(),
			array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => PCFM_Window::META_SERVICE_DATE,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => PCFM_Window::META_OPENED_AT,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => PCFM_Window::META_OPEN_FROM,
						'compare' => 'NOT EXISTS',
					),
				),
				'tax_query'  => array(
					'relation' => 'AND',
					array(
						'taxonomy' => PCFM_Schedules::TAXONOMY,
						'operator' => 'NOT EXISTS',
					),
				),
			)
		);

		$locations = PCFM_Settings::location_term_ids();

		if ( $locations ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $locations,
				'operator' => 'NOT IN',
			);
		}

		$query = new WP_Query( $args );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Portion limits ride on WooCommerce stock rather than a parallel counter, so
	 * sold-out handling, cart validation and race safety come for free.
	 */
	public static function set_capacity( $product_id, $portions ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$portions = (int) $portions;

		if ( $portions > 0 ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $portions );
			$product->set_backorders( 'no' );
		} else {
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
		}

		$product->save();
	}

	/**
	 * @return array{limit:int,remaining:int,sold:int}|null Null when unlimited.
	 */
	public static function capacity( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->managing_stock() ) {
			return null;
		}

		$remaining = (int) $product->get_stock_quantity();
		$sold      = (int) get_post_meta( $product_id, 'total_sales', true );

		return array(
			'limit'     => $remaining + $sold,
			'remaining' => max( 0, $remaining ),
			'sold'      => $sold,
		);
	}
}
