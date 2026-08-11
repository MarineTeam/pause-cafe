<?php
/**
 * [pause_cafe_flex_menu] -- the storefront menu.
 *
 * Renders one or every schedule, using the theme's own product template so it
 * inherits existing styling.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Shortcode {

	public static function init() {
		add_shortcode( 'pause_cafe_flex_menu', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'pcfm-public', PCFM_URL . 'assets/public.css', array(), PCFM_VERSION );
	}

	/**
	 * @param array $atts schedule: slug or ID, omit for every schedule.
	 *                    weeks:    service dates to show, 0 for all upcoming.
	 *                    date:     render one specific Y-m-d.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'schedule' => '',
				'weeks'    => 1,
				'date'     => '',
			),
			$atts,
			'pause_cafe_flex_menu'
		);

		wp_enqueue_style( 'pcfm-public' );

		$schedules = self::resolve_schedules( $atts['schedule'] );

		if ( ! $schedules ) {
			return self::notice( __( 'No menu schedules have been set up yet.', 'pause-cafe-flex-menu' ) );
		}

		if ( ! PCFM_Settings::locations() ) {
			return self::notice( __( 'No pickup locations have been configured.', 'pause-cafe-flex-menu' ) );
		}

		ob_start();

		echo '<div class="pcfm-menu">';

		$rendered = 0;

		foreach ( $schedules as $schedule ) {
			$dates = $atts['date']
				? array( sanitize_text_field( $atts['date'] ) )
				: self::dates_to_show( $schedule->term_id, $atts['weeks'] );

			foreach ( $dates as $service_date ) {
				self::render_block( $schedule, $service_date );
				++$rendered;
			}
		}

		if ( ! $rendered ) {
			printf(
				'<p class="pcfm-menu__empty">%s</p>',
				esc_html__( 'No menu has been published yet. Please check back soon.', 'pause-cafe-flex-menu' )
			);
		}

		echo '</div>';

		return ob_get_clean();
	}

	private static function notice( $message ) {
		return '<div class="pcfm-menu pcfm-menu--empty"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * @return WP_Term[]
	 */
	private static function resolve_schedules( $reference ) {
		if ( '' === $reference ) {
			return PCFM_Schedules::all();
		}

		$term = is_numeric( $reference )
			? get_term( (int) $reference, PCFM_Schedules::TAXONOMY )
			: get_term_by( 'slug', sanitize_title( $reference ), PCFM_Schedules::TAXONOMY );

		return ( $term && ! is_wp_error( $term ) ) ? array( $term ) : array();
	}

	/**
	 * @return string[]
	 */
	private static function dates_to_show( $schedule_id, $weeks ) {
		$current = PCFM_Product::current_service_date( $schedule_id );

		if ( ! $current ) {
			return array();
		}

		$weeks = absint( $weeks );
		$dates = array();

		foreach ( PCFM_Product::all_service_dates( $schedule_id ) as $date ) {
			if ( $date < $current ) {
				continue;
			}

			$dates[] = $date;

			if ( $weeks && count( $dates ) >= $weeks ) {
				break;
			}
		}

		return $dates;
	}

	private static function render_block( WP_Term $schedule, $service_date ) {
		$locations = PCFM_Schedules::locations( $schedule->term_id );
		$ids       = PCFM_Product::ids_for_service_date( $service_date, $schedule->term_id );

		if ( ! $ids ) {
			return;
		}

		$reference = PCFM_Window::for_product( $ids[0] );
		$state     = $reference->state();

		printf( '<section class="pcfm-block pcfm-block--%s">', esc_attr( $state ) );

		printf(
			'<header class="pcfm-block__header"><h2 class="pcfm-block__date">%s</h2><p class="pcfm-block__state">%s</p></header>',
			esc_html( PCFM_Window::format_date( $service_date, 'l j F' ) ),
			esc_html( $reference->message() )
		);

		// A blacked-out week says why instead of showing an empty list.
		if ( PCFM_Window::BLACKOUT === $state ) {
			echo '</section>';

			return;
		}

		if ( count( PCFM_Schedules::all() ) > 1 ) {
			printf( '<p class="pcfm-block__schedule">%s</p>', esc_html( $schedule->name ) );
		}

		echo '<div class="pcfm-block__locations">';

		$any = false;

		foreach ( $locations as $location ) {
			$location_ids = PCFM_Product::ids_for_service_date( $service_date, $schedule->term_id, $location['term_id'] );

			if ( ! $location_ids ) {
				continue;
			}

			$any = true;

			echo '<section class="pcfm-location">';
			printf( '<h3 class="pcfm-location__name">%s</h3>', esc_html( $location['label'] ) );
			self::render_capacity( $location_ids );
			self::render_products( $location_ids );
			echo '</section>';
		}

		if ( ! $any ) {
			printf(
				'<p class="pcfm-block__empty">%s</p>',
				esc_html__( 'The menu for this week has not been published yet.', 'pause-cafe-flex-menu' )
			);
		}

		echo '</div></section>';
	}

	/**
	 * A quiet "3 left" line when a dish is running out. WooCommerce shows its own
	 * stock message on the single product page; this is for the listing.
	 */
	private static function render_capacity( array $ids ) {
		foreach ( $ids as $product_id ) {
			$capacity = PCFM_Product::capacity( $product_id );

			if ( ! $capacity || $capacity['remaining'] > 5 ) {
				continue;
			}

			printf(
				'<p class="pcfm-location__capacity">%s</p>',
				esc_html(
					$capacity['remaining'] > 0
						? sprintf(
							/* translators: %d: portions left. */
							_n( 'Only %d portion left', 'Only %d portions left', $capacity['remaining'], 'pause-cafe-flex-menu' ),
							$capacity['remaining']
						)
						: __( 'Sold out', 'pause-cafe-flex-menu' )
				)
			);
		}
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
