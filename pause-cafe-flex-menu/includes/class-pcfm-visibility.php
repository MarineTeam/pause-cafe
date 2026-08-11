<?php
/**
 * Turns a resolved window into what a visitor can see and buy.
 *
 * Computed live rather than written into the product_visibility taxonomy, so
 * deactivating the plugin restores the catalog exactly as it was.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Visibility {

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

		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'pcfm_menu_saved', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		self::$hidden_cache = null;
		PCFM_Window::flush();
	}

	/**
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

		foreach ( PCFM_Product::all_managed_ids() as $product_id ) {
			if ( ! PCFM_Window::for_product( $product_id )->is_listed() ) {
				$hidden[] = (int) $product_id;
			}
		}

		self::$computing    = false;
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
	 * The core of the cutoff. Capacity is left to WooCommerce's own stock
	 * handling, which has already run by the time this filter sees $purchasable.
	 */
	public static function filter_is_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! $product instanceof WC_Product ) {
			return $purchasable;
		}

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( ! PCFM_Product::is_managed( $product_id ) ) {
			return $purchasable;
		}

		return PCFM_Window::for_product( $product_id )->is_orderable();
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

		foreach ( array( 'product_cat', 'product_tag', PCFM_Schedules::TAXONOMY ) as $taxonomy ) {
			if ( $query->is_tax( $taxonomy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Excludes dishes outside their window from every product listing, whichever
	 * plugin built the query. Page-builder grids run their own WP_Query, so
	 * filtering here rather than in WooCommerce's product query catches them too.
	 */
	public static function filter_queries( $query ) {
		if ( ! $query instanceof WP_Query ) {
			return;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( $query->get( 'pcfm_bypass_visibility' ) ) {
			return;
		}

		if ( ! self::is_product_query( $query ) || $query->is_singular() ) {
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
	 * Closes the direct-link hole: a bookmark to a dish from a finished week goes
	 * to the menu rather than a buyable page.
	 *
	 * Dishes for a future week stay reachable on purpose -- the page explains when
	 * ordering opens, which is more useful than a redirect.
	 */
	public static function redirect_expired() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();

		if ( ! $product_id || ! PCFM_Product::is_managed( $product_id ) ) {
			return;
		}

		$state = PCFM_Window::for_product( $product_id )->state();

		if ( ! in_array( $state, array( PCFM_Window::PAST, PCFM_Window::NONE, PCFM_Window::BLACKOUT ), true ) ) {
			return;
		}

		wp_safe_redirect( self::menu_url(), 302 );
		exit;
	}

	public static function menu_url() {
		$page_id = (int) PCFM_Settings::get( 'menu_page_id' );

		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return get_permalink( $page_id );
		}

		$shop = wc_get_page_permalink( 'shop' );

		return $shop ? $shop : home_url( '/' );
	}

	public static function render_state_notice() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! PCFM_Product::is_managed( $product_id ) ) {
			return;
		}

		$window = PCFM_Window::for_product( $product_id );

		if ( $window->is_orderable() ) {
			return;
		}

		printf(
			'<p class="pcfm-state-notice">%s</p>',
			esc_html( $window->message() )
		);
	}
}
