<?php
/**
 * [pause_cafe_menu] -- the storefront menu.
 *
 * Renders the week's dishes grouped by pickup location, using the theme's own
 * product template so it inherits existing styling. This replaces the per-campus
 * product grids that had to be re-pointed by hand every week.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Shortcode {

	public static function init() {
		add_shortcode( 'pause_cafe_menu', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'pcm-public', PCM_URL . 'assets/public.css', array(), PCM_VERSION );
	}

	/**
	 * @param array $atts weeks: how many service dates to show. 0 shows every
	 *                    upcoming week. Defaults to 1, or all when previews are on.
	 *                    date:  render one specific Y-m-d instead.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'weeks' => null,
				'date'  => '',
			),
			$atts,
			'pause_cafe_menu'
		);

		wp_enqueue_style( 'pcm-public' );

		$dates = $atts['date']
			? array( sanitize_text_field( $atts['date'] ) )
			: self::dates_to_show( $atts['weeks'] );

		if ( ! $dates ) {
			return '<div class="pcm-menu pcm-menu--empty"><p>' .
				esc_html__( 'No menu has been published yet. Please check back soon.', 'pause-cafe-menu' ) .
				'</p></div>';
		}

		$locations = PCM_Settings::locations();

		if ( ! $locations ) {
			return '<div class="pcm-menu pcm-menu--empty"><p>' .
				esc_html__( 'No pickup locations have been configured.', 'pause-cafe-menu' ) .
				'</p></div>';
		}

		ob_start();

		echo '<div class="pcm-menu">';

		foreach ( $dates as $service_date ) {
			self::render_week( $service_date, $locations );
		}

		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * @return string[]
	 */
	private static function dates_to_show( $weeks ) {
		$current = PCM_Schedule::current_service_date();

		if ( ! $current ) {
			return array();
		}

		if ( null === $weeks || '' === $weeks ) {
			$weeks = PCM_Settings::preview_upcoming() ? 0 : 1;
		}

		$weeks = absint( $weeks );
		$dates = array();

		foreach ( PCM_Schedule::all_service_dates() as $date ) {
			if ( $date < $current ) {
				continue;
			}

			if ( ! PCM_Schedule::is_listed( $date ) ) {
				continue;
			}

			$dates[] = $date;

			if ( $weeks && count( $dates ) >= $weeks ) {
				break;
			}
		}

		return $dates;
	}

	private static function render_week( $service_date, array $locations ) {
		$state = PCM_Schedule::state_for( $service_date );

		printf(
			'<section class="pcm-week pcm-week--%s">',
			esc_attr( $state )
		);

		printf(
			'<header class="pcm-week__header"><h2 class="pcm-week__date">%s</h2><p class="pcm-week__state">%s</p></header>',
			esc_html( PCM_Schedule::format_date( $service_date, 'l j F' ) ),
			esc_html( PCM_Schedule::state_message( $service_date ) )
		);

		echo '<div class="pcm-week__locations">';

		$any = false;

		foreach ( $locations as $location ) {
			$ids = PCM_Product::ids_for_date( $service_date, $location['term_id'] );

			if ( ! $ids ) {
				continue;
			}

			$any = true;

			echo '<section class="pcm-location">';
			printf( '<h3 class="pcm-location__name">%s</h3>', esc_html( $location['label'] ) );
			self::render_products( $ids );
			echo '</section>';
		}

		if ( ! $any ) {
			printf(
				'<p class="pcm-week__empty">%s</p>',
				esc_html__( 'The menu for this week has not been published yet.', 'pause-cafe-menu' )
			);
		}

		echo '</div></section>';
	}

	/**
	 * Uses WooCommerce's own loop so themes, add-to-cart behaviour and the side
	 * cart all keep working exactly as they do elsewhere on the site.
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
