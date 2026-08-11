<?php
/**
 * The service date field on the normal product edit screen.
 *
 * The month builder is the everyday route, but a dish still needs to be
 * editable one-off without going through the grid.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Admin_Product_Field {

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_field' ) );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	public static function render_field() {
		global $post;

		$service_date = PCM_Product::get_service_date( $post->ID );

		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			array(
				'id'          => '_pcm_service_date',
				'label'       => __( 'Service date', 'pause-cafe-menu' ),
				'placeholder' => 'YYYY-MM-DD',
				'value'       => $service_date,
				'type'        => 'date',
				'desc_tip'    => true,
				'description' => __( 'The day this dish is served. Ordering opens and closes automatically around this date. Leave blank for items that are not part of the weekly menu.', 'pause-cafe-menu' ),
			)
		);

		if ( $service_date ) {
			printf(
				'<p class="form-field"><span class="description">%s</span></p>',
				esc_html( PCM_Schedule::state_message( $service_date ) )
			);
		}

		echo '</div>';
	}

	public static function save_field( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before this hook.
		$raw = isset( $_POST['_pcm_service_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_pcm_service_date'] ) ) : '';

		if ( $raw && ! PCM_Schedule::date_obj( $raw ) ) {
			$raw = '';
		}

		PCM_Product::set_service_date( $product->get_id(), $raw );
		PCM_Visibility::flush();
	}

	public static function add_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'name' === $key ) {
				$new['pcm_service_date'] = __( 'Service date', 'pause-cafe-menu' );
			}
		}

		return $new;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'pcm_service_date' !== $column ) {
			return;
		}

		$service_date = PCM_Product::get_service_date( $post_id );

		if ( ! $service_date ) {
			echo '<span aria-hidden="true">&mdash;</span>';

			return;
		}

		$state  = PCM_Schedule::state_for( $service_date );
		$labels = array(
			PCM_Schedule::OPEN     => __( 'Ordering open', 'pause-cafe-menu' ),
			PCM_Schedule::UPCOMING => __( 'Upcoming', 'pause-cafe-menu' ),
			PCM_Schedule::CLOSED   => __( 'Closed', 'pause-cafe-menu' ),
			PCM_Schedule::PAST     => __( 'Past', 'pause-cafe-menu' ),
		);

		printf(
			'%s<br><span class="pcm-state pcm-state--%s">%s</span>',
			esc_html( PCM_Schedule::format_date( $service_date ) ),
			esc_attr( $state ),
			esc_html( isset( $labels[ $state ] ) ? $labels[ $state ] : $state )
		);
	}
}
