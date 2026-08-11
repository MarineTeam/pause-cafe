<?php
/**
 * The scheduling panel on the normal product edit screen.
 *
 * The builder is the everyday route, but a single dish still needs to be
 * adjustable without going through it -- including the per-dish override that
 * takes precedence over whatever its schedule says.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin_Product_Panel {

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_panel' ) );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	public static function render_panel() {
		global $post;

		$product_id = $post->ID;
		$window     = PCFM_Window::for_product( $product_id );
		$schedule   = $window->schedule_id ? get_term( $window->schedule_id, PCFM_Schedules::TAXONOMY ) : null;

		echo '<div class="options_group">';

		printf(
			'<p class="form-field"><label>%s</label><span>%s</span></p>',
			esc_html__( 'Schedule', 'pause-cafe-flex-menu' ),
			esc_html( $schedule && ! is_wp_error( $schedule ) ? $schedule->name : __( 'Not on a schedule', 'pause-cafe-flex-menu' ) )
		);

		printf(
			'<p class="form-field"><label>%s</label><span>%s</span></p>',
			esc_html__( 'Status', 'pause-cafe-flex-menu' ),
			esc_html( $window->message() )
		);

		if ( $window->close_at ) {
			printf(
				'<p class="form-field"><label>%s</label><span>%s &rarr; %s</span></p>',
				esc_html__( 'Window', 'pause-cafe-flex-menu' ),
				esc_html( $window->format_moment( $window->open_from ) ),
				esc_html( $window->format_moment( $window->close_at ) )
			);
		}

		woocommerce_wp_text_input(
			array(
				'id'          => '_pcfm_service_date',
				'label'       => __( 'Service date', 'pause-cafe-flex-menu' ),
				'value'       => (string) get_post_meta( $product_id, PCFM_Window::META_SERVICE_DATE, true ),
				'type'        => 'date',
				'desc_tip'    => true,
				'description' => __( 'The day this dish is served. Required by planned schedules; optional elsewhere, where it is otherwise worked out from the closing time.', 'pause-cafe-flex-menu' ),
			)
		);

		echo '</div><div class="options_group">';

		printf( '<p class="form-field"><strong>%s</strong></p>', esc_html__( 'Override this dish', 'pause-cafe-flex-menu' ) );

		woocommerce_wp_text_input(
			array(
				'id'          => '_pcfm_open_from',
				'label'       => __( 'Orderable from', 'pause-cafe-flex-menu' ),
				'value'       => self::to_input( get_post_meta( $product_id, PCFM_Window::META_OPEN_FROM, true ) ),
				'type'        => 'datetime-local',
				'desc_tip'    => true,
				'description' => __( 'Setting both from and until overrides the schedule for this dish alone. Clear both to go back to the schedule.', 'pause-cafe-flex-menu' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'    => '_pcfm_close_at',
				'label' => __( 'Orderable until', 'pause-cafe-flex-menu' ),
				'value' => self::to_input( get_post_meta( $product_id, PCFM_Window::META_CLOSE_AT, true ) ),
				'type'  => 'datetime-local',
			)
		);

		$capacity = PCFM_Product::capacity( $product_id );

		woocommerce_wp_text_input(
			array(
				'id'                => '_pcfm_portions',
				'label'             => __( 'Portions', 'pause-cafe-flex-menu' ),
				'value'             => $capacity ? $capacity['limit'] : '',
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0' ),
				'desc_tip'          => true,
				'description'       => __( 'How many can be ordered in total. Blank or zero means unlimited. This drives WooCommerce stock, so sold-out behaves exactly as it does for any other product.', 'pause-cafe-flex-menu' ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => '_pcfm_reopen',
				'value'       => 'no',
				'label'       => __( 'Reopen ordering', 'pause-cafe-flex-menu' ),
				'description' => __( 'On-publish schedules only: restart the window from now.', 'pause-cafe-flex-menu' ),
			)
		);

		echo '</div>';
	}

	public static function save_panel( $product ) {
		$product_id = $product->get_id();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before this hook.
		$service  = isset( $_POST['_pcfm_service_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_pcfm_service_date'] ) ) : '';
		$from     = isset( $_POST['_pcfm_open_from'] ) ? sanitize_text_field( wp_unslash( $_POST['_pcfm_open_from'] ) ) : '';
		$until    = isset( $_POST['_pcfm_close_at'] ) ? sanitize_text_field( wp_unslash( $_POST['_pcfm_close_at'] ) ) : '';
		$portions = isset( $_POST['_pcfm_portions'] ) ? trim( (string) wp_unslash( $_POST['_pcfm_portions'] ) ) : '';
		$reopen   = isset( $_POST['_pcfm_reopen'] ) && 'yes' === $_POST['_pcfm_reopen'];
		// phpcs:enable

		self::store_date( $product_id, PCFM_Window::META_SERVICE_DATE, $service );
		self::store_datetime( $product_id, PCFM_Window::META_OPEN_FROM, $from );
		self::store_datetime( $product_id, PCFM_Window::META_CLOSE_AT, $until );

		if ( '' !== $portions ) {
			// Capacity rides on stock, which the product object owns, so it is set
			// on the object here rather than through a second save.
			$value = absint( $portions );

			if ( $value > 0 ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $value );
				$product->set_backorders( 'no' );
			} else {
				$product->set_manage_stock( false );
			}
		}

		if ( $reopen ) {
			PCFM_Product::open_now( $product_id );
		}

		PCFM_Window::flush();
		PCFM_Product::sync_resolved( $product_id );
	}

	private static function store_date( $product_id, $key, $value ) {
		if ( $value && PCFM_Window::parse_date( $value ) ) {
			update_post_meta( $product_id, $key, $value );
		} else {
			delete_post_meta( $product_id, $key );
		}
	}

	private static function store_datetime( $product_id, $key, $value ) {
		$parsed = PCFM_Window::parse_datetime( $value );

		if ( $parsed ) {
			update_post_meta( $product_id, $key, $parsed->format( 'Y-m-d H:i:s' ) );
		} else {
			delete_post_meta( $product_id, $key );
		}
	}

	private static function to_input( $stored ) {
		$parsed = PCFM_Window::parse_datetime( $stored );

		return $parsed ? $parsed->format( 'Y-m-d\TH:i' ) : '';
	}

	public static function add_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'name' === $key ) {
				$new['pcfm_window'] = __( 'Ordering', 'pause-cafe-flex-menu' );
			}
		}

		return $new;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'pcfm_window' !== $column ) {
			return;
		}

		if ( ! PCFM_Product::is_managed( $post_id ) ) {
			echo '<span aria-hidden="true">&mdash;</span>';

			return;
		}

		$window = PCFM_Window::for_product( $post_id );
		$state  = $window->state();
		$labels = array(
			PCFM_Window::OPEN     => __( 'Open', 'pause-cafe-flex-menu' ),
			PCFM_Window::UPCOMING => __( 'Upcoming', 'pause-cafe-flex-menu' ),
			PCFM_Window::CLOSED   => __( 'Closed', 'pause-cafe-flex-menu' ),
			PCFM_Window::PAST     => __( 'Served', 'pause-cafe-flex-menu' ),
			PCFM_Window::BLACKOUT => __( 'Blackout', 'pause-cafe-flex-menu' ),
			PCFM_Window::NONE     => __( 'Not scheduled', 'pause-cafe-flex-menu' ),
		);

		printf(
			'<span class="pcfm-state pcfm-state--%s">%s</span><br><span class="description">%s</span>',
			esc_attr( $state ),
			esc_html( isset( $labels[ $state ] ) ? $labels[ $state ] : $state ),
			esc_html( $window->service_date ? PCFM_Window::format_date( $window->service_date ) : '' )
		);
	}
}
