<?php
/**
 * Stops an order outside its window from completing.
 *
 * The case this exists for: a dish added to the cart a minute before the cutoff
 * and checked out a minute after. Without a re-check at checkout the cutoff is
 * advisory, and because payment comes out of a wallet balance, such an order
 * moves real money for food the kitchen was never told to cook.
 *
 * Portion limits are not enforced here -- WooCommerce stock already does that,
 * including the race between two people buying the last one.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Guard {

	public static function init() {
		// Classic cart and checkout.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout' ) );

		// Block cart and checkout, which is the default on a fresh WooCommerce
		// install and runs none of the hooks above.
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( __CLASS__, 'block_store_api' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'validate_store_api_add' ), 10, 2 );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'validate_store_api_cart' ), 10, 2 );
	}

	/**
	 * @return string Empty when the product may be ordered, otherwise the reason.
	 */
	private static function rejection_reason( $product_id ) {
		if ( ! PCFM_Product::is_managed( $product_id ) ) {
			return '';
		}

		$window = PCFM_Window::for_product( $product_id );

		if ( $window->is_orderable() ) {
			return '';
		}

		return sprintf(
			/* translators: 1: product name, 2: explanation of the ordering window. */
			__( '%1$s could not be ordered. %2$s', 'pause-cafe-flex-menu' ),
			get_the_title( $product_id ),
			$window->message()
		);
	}

	public static function validate_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}

		$reason = self::rejection_reason( $product_id );

		if ( $reason ) {
			wc_add_notice( $reason, 'error' );

			return false;
		}

		return $passed;
	}

	/**
	 * Runs on the cart and checkout pages. Anything past its cutoff is taken out
	 * of the cart rather than left sitting there looking orderable.
	 */
	public static function validate_cart() {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

			if ( ! $product_id ) {
				continue;
			}

			$reason = self::rejection_reason( $product_id );

			if ( ! $reason ) {
				continue;
			}

			WC()->cart->remove_cart_item( $cart_item_key );

			wc_add_notice(
				sprintf(
					/* translators: %s: reason the item was removed. */
					__( 'Removed from your cart: %s', 'pause-cafe-flex-menu' ),
					$reason
				),
				'error'
			);
		}
	}

	public static function validate_checkout() {
		if ( ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

			if ( ! $product_id ) {
				continue;
			}

			$reason = self::rejection_reason( $product_id );

			if ( $reason ) {
				wc_add_notice( $reason, 'error' );
			}
		}
	}

	public static function block_store_api( $maximum, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $maximum;
		}

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( self::rejection_reason( $product_id ) ) {
			return 0;
		}

		return $maximum;
	}

	public static function validate_store_api_add( $product, $request ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$reason     = self::rejection_reason( $product_id );

		if ( ! $reason ) {
			return;
		}

		$exception = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';

		if ( class_exists( $exception ) ) {
			throw new $exception( 'pcfm_ordering_closed', $reason, 400 );
		}
	}

	/**
	 * The block checkout refuses to submit while the cart carries an error, so
	 * this is the last thing standing between a closed window and a wallet debit.
	 */
	public static function validate_store_api_cart( $errors, $cart ) {
		if ( ! $errors instanceof WP_Error || ! $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

			if ( ! $product_id ) {
				continue;
			}

			$reason = self::rejection_reason( $product_id );

			if ( $reason ) {
				$errors->add( 'pcfm_ordering_closed', $reason );
			}
		}
	}
}
