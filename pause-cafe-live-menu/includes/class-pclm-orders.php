<?php
/**
 * Ties orders to the cycle they were placed in.
 *
 * The cycle and the service date are stamped onto each line item at checkout,
 * so the record stays accurate even if the dish is later edited or removed.
 * Reporting groups on the cycle rather than the order date: an order placed
 * right after publishing and one placed minutes before the cutoff belong to the
 * same cook list, and order-date grouping splits them.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Orders {

	const ITEM_META_CYCLE    = '_pclm_cycle';
	const ITEM_META_SERVICE  = '_pclm_service_date';
	const ITEM_META_LOCATION = '_pclm_location';

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_line_item' ), 10, 3 );
	}

	public static function stamp_line_item( $item, $cart_item_key, $values ) {
		$product_id = ! empty( $values['product_id'] ) ? (int) $values['product_id'] : 0;

		if ( ! $product_id ) {
			return;
		}

		$cycle = PCLM_Product::get_cycle( $product_id );

		if ( ! $cycle ) {
			return;
		}

		$item->add_meta_data( self::ITEM_META_CYCLE, $cycle, true );
		$item->add_meta_data( self::ITEM_META_SERVICE, PCLM_Schedule::service_date_for_cycle( $cycle ), true );

		$location = self::location_for_product( $product_id );

		if ( $location ) {
			$item->add_meta_data( self::ITEM_META_LOCATION, $location, true );
		}
	}

	public static function location_for_product( $product_id ) {
		foreach ( PCLM_Settings::locations() as $location ) {
			if ( has_term( (int) $location['term_id'], 'product_cat', (int) $product_id ) ) {
				return $location['label'];
			}
		}

		return '';
	}

	/**
	 * @return string[]
	 */
	public static function counted_statuses() {
		return apply_filters(
			'pclm_counted_order_statuses',
			array( 'processing', 'completed', 'on-hold' )
		);
	}

	/**
	 * Every line item belonging to a cycle.
	 *
	 * Matches on the stamped item meta, and falls back to product ID so orders
	 * placed before this plugin was installed still appear.
	 *
	 * @return array[]
	 */
	public static function line_items_for_cycle( $cycle ) {
		global $wpdb;

		if ( ! $cycle ) {
			return array();
		}

		$item_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_item_id
				 FROM {$wpdb->prefix}woocommerce_order_itemmeta
				 WHERE meta_key = %s AND meta_value = %s",
				self::ITEM_META_CYCLE,
				$cycle
			)
		);

		$product_ids = PCLM_Product::ids_for_cycle( $cycle );

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

		$statuses = self::counted_statuses();
		$orders   = array();
		$rows     = array();

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
			$location   = $order_item->get_meta( self::ITEM_META_LOCATION, true );

			if ( ! $location ) {
				$location = self::location_for_product( $product_id );
			}

			$rows[] = array(
				'order_id'   => $order_id,
				'product_id' => $product_id,
				'name'       => $item->order_item_name,
				'qty'        => (int) $order_item->get_quantity(),
				'location'   => $location ? $location : __( 'Unassigned', 'pause-cafe-live-menu' ),
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

		return $user ? $user->display_name : __( 'Guest', 'pause-cafe-live-menu' );
	}

	/**
	 * Report rows folded into location -> dish -> quantity, plus who ordered it.
	 */
	public static function summary_for_cycle( $cycle ) {
		$summary = array();

		foreach ( self::line_items_for_cycle( $cycle ) as $row ) {
			$location = $row['location'];
			$dish     = $row['name'];

			if ( ! isset( $summary[ $location ] ) ) {
				$summary[ $location ] = array();
			}

			if ( ! isset( $summary[ $location ][ $dish ] ) ) {
				$summary[ $location ][ $dish ] = array(
					'qty'    => 0,
					'people' => array(),
				);
			}

			$summary[ $location ][ $dish ]['qty']     += $row['qty'];
			$summary[ $location ][ $dish ]['people'][] = sprintf( '%s (%d)', $row['customer'], $row['qty'] );
		}

		ksort( $summary );

		return $summary;
	}
}
