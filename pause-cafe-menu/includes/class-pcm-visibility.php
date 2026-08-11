<?php
/**
 * Turns schedule state into what a visitor can see and buy.
 *
 * Visibility is computed live rather than written into the product_visibility
 * taxonomy. Nothing is stored, so deactivating the plugin restores the catalog
 * exactly as it was.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Visibility {

	/** Guards against re-entering the hidden-ID calculation from its own queries. */
	private static $computing = false;

	private static $hidden_cache = null;

	public static function init() {
		add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'filter_is_visible' ), 10, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'filter_is_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'filter_is_purchasable' ), 10, 2 );

		add_action( 'pre_get_posts', array( __CLASS__, 'filter_queries' ) );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_expired' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_state_notice' ), 25 );

		// Any product edit can change which week is live.
		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'pcm_menu_saved', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		self::$hidden_cache = null;
		wp_cache_delete( 'pcm_service_dates' );
	}

	/**
	 * Managed products that should not appear in listings right now.
	 *
	 * @return int[]
	 */
	public static function hidden_product_ids() {
		if ( null !== self::$hidden_cache ) {
			return self::$hidden_cache;
		}

		if ( self::$computing ) {
			return array();
		}

		self::$computing = true;

		$hidden = array();

		foreach ( PCM_Product::all_managed_ids() as $product_id ) {
			$service_date = PCM_Product::get_service_date( $product_id );

			if ( ! $service_date || ! PCM_Schedule::is_listed( $service_date ) ) {
				$hidden[] = (int) $product_id;
			}
		}

		self::$computing = false;
		self::$hidden_cache = $hidden;

		return $hidden;
	}

	private static function is_hidden( $product_id ) {
		return in_array( (int) $product_id, self::hidden_product_ids(), true );
	}

	public static function filter_is_visible( $visible, $product_id ) {
		if ( ! $visible ) {
			return $visible;
		}

		return ! self::is_hidden( $product_id );
	}

	/**
	 * The core of the cutoff: only a dish whose window is open can be bought.
	 */
	public static function filter_is_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! $product instanceof WC_Product ) {
			return $purchasable;
		}

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( ! PCM_Product::is_managed( $product_id ) ) {
			return $purchasable;
		}

		$service_date = PCM_Product::get_service_date( $product_id );

		if ( ! $service_date ) {
			return false;
		}

		return PCM_Schedule::is_orderable( $service_date );
	}

	private static function is_product_query( WP_Query $query ) {
		$post_type = $query->get( 'post_type' );

		if ( 'product' === $post_type ) {
			return true;
		}

		if ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) {
			return true;
		}

		if ( $query->is_post_type_archive( 'product' ) ) {
			return true;
		}

		foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
			if ( $query->is_tax( $taxonomy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Excludes out-of-window dishes from every product listing, whichever plugin
	 * built the query. Elementor and Essential Addons grids run their own
	 * WP_Query, so filtering here rather than in WooCommerce's product query
	 * catches them too.
	 */
	public static function filter_queries( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( $query->get( 'pcm_bypass_visibility' ) ) {
			return;
		}

		if ( ! self::is_product_query( $query ) ) {
			return;
		}

		// A single product page must still resolve, so it can explain itself.
		if ( $query->is_singular() ) {
			return;
		}

		$hidden = self::hidden_product_ids();

		if ( ! $hidden ) {
			return;
		}

		$existing = $query->get( 'post__not_in' );
		$existing = is_array( $existing ) ? $existing : array();

		$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $hidden ) ) ) );
	}

	/**
	 * Closes the direct-link hole: an old bookmark to a dish that has already
	 * been served goes to the menu instead of a buyable page.
	 *
	 * Dishes for a future week are left reachable on purpose -- the page explains
	 * when ordering opens, which is more useful than a redirect.
	 */
	public static function redirect_expired() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();

		if ( ! $product_id || ! PCM_Product::is_managed( $product_id ) ) {
			return;
		}

		$service_date = PCM_Product::get_service_date( $product_id );
		$expired      = ! $service_date || PCM_Schedule::PAST === PCM_Schedule::state_for( $service_date );

		if ( ! $expired ) {
			return;
		}

		wp_safe_redirect( self::menu_url(), 302 );
		exit;
	}

	public static function menu_url() {
		$page_id = (int) PCM_Settings::get( 'menu_page_id' );

		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return get_permalink( $page_id );
		}

		$shop = wc_get_page_permalink( 'shop' );

		return $shop ? $shop : home_url( '/' );
	}

	/**
	 * Replaces a silently missing add-to-cart button with a sentence saying why.
	 */
	public static function render_state_notice() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! PCM_Product::is_managed( $product_id ) ) {
			return;
		}

		$service_date = PCM_Product::get_service_date( $product_id );

		if ( ! $service_date || PCM_Schedule::is_orderable( $service_date ) ) {
			return;
		}

		printf(
			'<p class="pcm-state-notice">%s</p>',
			esc_html( PCM_Schedule::state_message( $service_date ) )
		);
	}
}
