<?php
/**
 * Ties orders to the day the food is served.
 *
 * The service date, schedule and location are stamped onto each line item at
 * checkout, so the record stays accurate even if the dish is later edited, its
 * schedule retuned, or the product deleted outright.
 *
 * Reporting groups on the service date rather than the order date. Every mode
 * produces one, so a single report covers planned, on-publish and manual
 * schedules without knowing which is which.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Orders {

	const ITEM_META_SERVICE  = '_pcfm_service_date';
	const ITEM_META_SCHEDULE = '_pcfm_schedule';
	const ITEM_META_LOCATION = '_pcfm_location';

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_line_item' ), 10, 3 );
	}

	public static function stamp_line_item( $item, $cart_item_key, $values ) {
		$product_id = ! empty( $values['product_id'] ) ? (int) $values['product_id'] : 0;

		if ( ! $product_id ) {
			return;
		}

		$window = PCFM_Window::for_product( $product_id );

		if ( ! $window->service_date ) {
			return;
		}

		$item->add_meta_data( self::ITEM_META_SERVICE, $window->service_date, true );

		if ( $window->schedule_id ) {
			$schedule = get_term( $window->schedule_id, PCFM_Schedules::TAXONOMY );

			if ( $schedule && ! is_wp_error( $schedule ) ) {
				$item->add_meta_data( self::ITEM_META_SCHEDULE, $schedule->name, true );
			}
		}

		$label = PCFM_Settings::location_label( $window->location_id );

		if ( $label ) {
			$item->add_meta_data( self::ITEM_META_LOCATION, $label, true );
		}
	}

	/**
	 * @return string[]
	 */
	public static function counted_statuses() {
		return apply_filters(
			'pcfm_counted_order_statuses',
			array( 'processing', 'completed', 'on-hold' )
		);
	}

	/**
	 * Every line item serving on a date, optionally narrowed to one schedule.
	 *
	 * Matches on the stamped item meta, and falls back to product ID so that
	 * orders placed before this plugin was installed still appear.
	 *
	 * @return array[]
	 */
	public static function line_items_for_date( $service_date, $schedule_id = 0 ) {
		global $wpdb;

		if ( ! $service_date ) {
			return array();
		}

		$item_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_item_id
				 FROM {$wpdb->prefix}woocommerce_order_itemmeta
				 WHERE meta_key = %s AND meta_value = %s",
				self::ITEM_META_SERVICE,
				$service_date
			)
		);

		$product_ids = PCFM_Product::ids_for_service_date( $service_date, $schedule_id );

		if ( $product_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

			$legacy = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT order_item_id
					 FROM {$wpdb->prefix}woocommerce_order_itemmeta
					 WHERE meta_key = '_product_id' AND meta_value IN ( {$placeholders} )",
					$product_ids
				)
			);

			$item_ids = array_merge( $item_ids, (array) $legacy );
		}

		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $item_ids ) ) ) );

		if ( ! $item_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_item_id, order_id, order_item_name
				 FROM {$wpdb->prefix}woocommerce_order_items
				 WHERE order_item_type = 'line_item'
				   AND order_item_id IN ( {$placeholders} )",
				$item_ids
			)
		);

		$statuses     = self::counted_statuses();
		$allowed      = $schedule_id ? $product_ids : null;
		$orders       = array();
		$rows         = array();

		foreach ( $items as $item ) {
			$order_id = (int) $item->order_id;

			if ( ! isset( $orders[ $order_id ] ) ) {
				$orders[ $order_id ] = wc_get_order( $order_id );
			}

			$order = $orders[ $order_id ];

			if ( ! $order || ! in_array( $order->get_status(), $statuses, true ) ) {
				continue;
			}

			$order_item = $order->get_item( (int) $item->order_item_id );

			if ( ! $order_item ) {
				continue;
			}

			$product_id = (int) $order_item->get_product_id();

			// Narrowing by schedule has to drop items from other schedules that
			// happen to share a service date.
			if ( null !== $allowed && ! in_array( $product_id, $allowed, true ) ) {
				continue;
			}

			$location = $order_item->get_meta( self::ITEM_META_LOCATION, true );

			if ( ! $location ) {
				$location = PCFM_Settings::location_label( PCFM_Settings::location_for_product( $product_id ) );
			}

			$rows[] = array(
				'order_id'   => $order_id,
				'product_id' => $product_id,
				'name'       => $item->order_item_name,
				'qty'        => (int) $order_item->get_quantity(),
				'location'   => $location ? $location : __( 'Unassigned', 'pause-cafe-flex-menu' ),
				'schedule'   => (string) $order_item->get_meta( self::ITEM_META_SCHEDULE, true ),
				'customer'   => self::customer_name( $order ),
				'status'     => $order->get_status(),
			);
		}

		return $rows;
	}

	private static function customer_name( WC_Order $order ) {
		$name = trim( $order->get_formatted_billing_full_name() );

		if ( $name ) {
			return $name;
		}

		$user = $order->get_user();

		return $user ? $user->display_name : __( 'Guest', 'pause-cafe-flex-menu' );
	}

	/**
	 * Report rows folded into location -> dish -> quantity, plus who ordered it.
	 */
	public static function summary_for_date( $service_date, $schedule_id = 0 ) {
		$summary = array();

		foreach ( self::line_items_for_date( $service_date, $schedule_id ) as $row ) {
			$location = $row['location'];
			$dish     = $row['name'];

			if ( ! isset( $summary[ $location ] ) ) {
				$summary[ $location ] = array();
			}

			if ( ! isset( $summary[ $location ][ $dish ] ) ) {
				$summary[ $location ][ $dish ] = array(
					'qty'        => 0,
					'people'     => array(),
					'product_id' => $row['product_id'],
				);
			}

			$summary[ $location ][ $dish ]['qty']     += $row['qty'];
			$summary[ $location ][ $dish ]['people'][] = sprintf( '%s (%d)', $row['customer'], $row['qty'] );
		}

		ksort( $summary );

		return $summary;
	}
}
