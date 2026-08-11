<?php
/**
 * [pause_cafe_live_menu] -- the storefront menu.
 *
 * Shows whatever is currently published, grouped by pickup location, using the
 * theme's own product template so it inherits existing styling.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Shortcode {

	public static function init() {
		add_shortcode( 'pause_cafe_live_menu', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'pclm-public', PCLM_URL . 'assets/public.css', array(), PCLM_VERSION );
	}

	/**
	 * @param array $atts cycle: render a specific cutoff date instead of the current one.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array( 'cycle' => '' ),
			$atts,
			'pause_cafe_live_menu'
		);

		wp_enqueue_style( 'pclm-public' );

		$cycle = $atts['cycle'] ? sanitize_text_field( $atts['cycle'] ) : PCLM_Schedule::current_cycle();

		if ( ! $cycle ) {
			return '<div class="pclm-menu pclm-menu--empty"><p>' .
				esc_html__( 'No menu has been published yet. Please check back soon.', 'pause-cafe-live-menu' ) .
				'</p></div>';
		}

		$locations = PCLM_Settings::locations();

		if ( ! $locations ) {
			return '<div class="pclm-menu pclm-menu--empty"><p>' .
				esc_html__( 'No pickup locations have been configured.', 'pause-cafe-live-menu' ) .
				'</p></div>';
		}

		ob_start();

		$state = PCLM_Schedule::cycle_state( $cycle );

		printf( '<div class="pclm-menu pclm-menu--%s">', esc_attr( $state ) );

		printf(
			'<header class="pclm-menu__header"><h2 class="pclm-menu__date">%s</h2><p class="pclm-menu__state">%s</p></header>',
			esc_html( PCLM_Schedule::format_date( PCLM_Schedule::service_date_for_cycle( $cycle ), 'l j F' ) ),
			esc_html( PCLM_Schedule::cycle_state_message( $cycle ) )
		);

		echo '<div class="pclm-menu__locations">';

		$any = false;

		foreach ( $locations as $location ) {
			$ids = PCLM_Product::ids_for_cycle( $cycle, $location['term_id'] );

			if ( ! $ids ) {
				continue;
			}

			$any = true;

			echo '<section class="pclm-location">';
			printf( '<h3 class="pclm-location__name">%s</h3>', esc_html( $location['label'] ) );
			self::render_products( $ids );
			echo '</section>';
		}

		if ( ! $any ) {
			printf(
				'<p class="pclm-menu__empty">%s</p>',
				esc_html__( 'The menu has not been published yet.', 'pause-cafe-live-menu' )
			);
		}

		echo '</div></div>';

		return ob_get_clean();
	}

	/**
	 * Uses WooCommerce's own loop so themes, add-to-cart behaviour and any cart
	 * plugin keep working exactly as they do elsewhere on the site.
	 */
	private static function render_products( array $ids ) {
		global $post, $product;

		$original_post    = $post;
		$original_product = $product;

		woocommerce_product_loop_start();

		foreach ( $ids as $product_id ) {
			$post_object = get_post( $product_id );

			if ( ! $post_object ) {
				continue;
			}

			$post    = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$product = wc_get_product( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

			setup_postdata( $post_object );

			wc_get_template_part( 'content', 'product' );
		}

		woocommerce_product_loop_end();

		$post    = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$product = $original_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		wp_reset_postdata();
	}
}
