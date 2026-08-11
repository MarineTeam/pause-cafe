<?php
/**
 * Ordering state on the normal product edit screen.
 *
 * The opening time is stamped automatically, so this shows rather than asks --
 * with one control to restart the window for a dish that is already live.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Admin_Product_Field {

	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_field' ) );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	public static function render_field() {
		global $post;

		$opened_at = PCLM_Product::get_opened_at( $post->ID );

		echo '<div class="options_group">';

		if ( $opened_at ) {
			$opened = PCLM_Schedule::parse( $opened_at );

			printf(
				'<p class="form-field"><label>%s</label><span>%s</span></p>',
				esc_html__( 'Ordering opened', 'pause-cafe-live-menu' ),
				esc_html( PCLM_Schedule::format_moment( $opened ) )
			);

			printf(
				'<p class="form-field"><label>%s</label><span>%s</span></p>',
				esc_html__( 'Status', 'pause-cafe-live-menu' ),
				esc_html( PCLM_Schedule::state_message( $opened_at ) )
			);
		} else {
			printf(
				'<p class="form-field"><span class="description">%s</span></p>',
				esc_html__( 'This dish has not been through the menu schedule. Publishing it from the Pause Cafe screen opens ordering.', 'pause-cafe-live-menu' )
			);
		}

		woocommerce_wp_checkbox(
			array(
				'id'          => '_pclm_reopen',
				'value'       => 'no',
				'label'       => __( 'Reopen ordering', 'pause-cafe-live-menu' ),
				'description' => __( 'Restart the ordering window from now, running to the next cutoff. Use this when correcting a dish that has already closed.', 'pause-cafe-live-menu' ),
			)
		);

		echo '</div>';
	}

	public static function save_field( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies before this hook.
		$reopen = isset( $_POST['_pclm_reopen'] ) && 'yes' === $_POST['_pclm_reopen'];

		if ( $reopen ) {
			PCLM_Product::open_now( $product->get_id() );
		}

		PCLM_Visibility::flush();
	}

	public static function add_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'name' === $key ) {
				$new['pclm_state'] = __( 'Ordering', 'pause-cafe-live-menu' );
			}
		}

		return $new;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'pclm_state' !== $column ) {
			return;
		}

		$opened_at = PCLM_Product::get_opened_at( $post_id );

		if ( ! $opened_at ) {
			echo '<span aria-hidden="true">&mdash;</span>';

			return;
		}

		$state  = PCLM_Schedule::state_for( $opened_at );
		$labels = array(
			PCLM_Schedule::OPEN   => __( 'Open', 'pause-cafe-live-menu' ),
			PCLM_Schedule::CLOSED => __( 'Closed', 'pause-cafe-live-menu' ),
			PCLM_Schedule::PAST   => __( 'Finished', 'pause-cafe-live-menu' ),
		);

		$cycle = PCLM_Product::get_cycle( $post_id );

		printf(
			'<span class="pclm-state pclm-state--%s">%s</span><br><span class="description">%s</span>',
			esc_attr( $state ),
			esc_html( isset( $labels[ $state ] ) ? $labels[ $state ] : $state ),
			esc_html( $cycle ? PCLM_Schedule::format_date( PCLM_Schedule::service_date_for_cycle( $cycle ) ) : '' )
		);
	}
}
