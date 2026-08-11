<?php
/**
 * Site-wide settings. Anything per-menu lives on the schedule instead.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin_Settings {

	public static function init() {
		add_action( 'admin_post_pcfm_save_settings', array( __CLASS__, 'handle_save' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_save_settings' );

		$locations = array();
		$labels    = isset( $_POST['location_label'] ) ? (array) wp_unslash( $_POST['location_label'] ) : array();
		$terms     = isset( $_POST['location_term'] ) ? (array) wp_unslash( $_POST['location_term'] ) : array();

		foreach ( $terms as $index => $term_id ) {
			$term_id = (int) $term_id;

			if ( ! $term_id ) {
				continue;
			}

			$label = isset( $labels[ $index ] ) ? sanitize_text_field( $labels[ $index ] ) : '';

			if ( '' === $label ) {
				$term  = get_term( $term_id, 'product_cat' );
				$label = ( $term && ! is_wp_error( $term ) ) ? $term->name : (string) $term_id;
			}

			$locations[] = array(
				'label'   => $label,
				'term_id' => $term_id,
			);
		}

		PCFM_Settings::update(
			array(
				'default_price' => isset( $_POST['default_price'] ) ? wc_format_decimal( wp_unslash( $_POST['default_price'] ) ) : '10.00',
				'menu_page_id'  => isset( $_POST['menu_page_id'] ) ? absint( $_POST['menu_page_id'] ) : 0,
				'locations'     => $locations,
			)
		);

		PCFM_Visibility::flush();
		PCFM_Admin::add_notice( __( 'Settings saved.', 'pause-cafe-flex-menu' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . PCFM_Admin::PAGE_SETTINGS ) );
		exit;
	}

	public static function render() {
		$settings   = PCFM_Settings::all();
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$categories = is_wp_error( $categories ) ? array() : $categories;
		$rows       = array_merge( (array) $settings['locations'], array( array(), array() ) );

		echo '<div class="wrap pcfm-wrap">';
		echo '<h1>' . esc_html__( 'Pause Cafe settings', 'pause-cafe-flex-menu' ) . '</h1>';

		PCFM_Admin::print_notices();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcfm_save_settings' );
		echo '<input type="hidden" name="action" value="pcfm_save_settings">';

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="pcfm-default-price">%s</label></th><td><input type="text" id="pcfm-default-price" name="default_price" value="%s" class="small-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Default dish price', 'pause-cafe-flex-menu' ),
			esc_attr( $settings['default_price'] ),
			esc_html__( 'Used when a schedule has no price of its own. Individual dishes can still be priced differently.', 'pause-cafe-flex-menu' )
		);

		echo '<tr><th scope="row"><label for="pcfm-menu-page">' . esc_html__( 'Menu page', 'pause-cafe-flex-menu' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'menu_page_id',
				'id'                => 'pcfm-menu-page',
				'selected'          => (int) $settings['menu_page_id'],
				'show_option_none'  => __( '— Use the shop page —', 'pause-cafe-flex-menu' ),
				'option_none_value' => 0,
			)
		);
		echo '<p class="description">' . esc_html__( 'Links to dishes from a finished week redirect here.', 'pause-cafe-flex-menu' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Pickup locations', 'pause-cafe-flex-menu' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Every location on the site. Each schedule then picks which of them it serves. Only products in these categories, on a schedule, or carrying scheduling dates are governed — drinks, desserts and special orders are left alone.', 'pause-cafe-flex-menu' )
		);

		echo '<table class="widefat striped pcfm-locations"><thead><tr>';
		echo '<th>' . esc_html__( 'Label shown on the menu', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Product category', 'pause-cafe-flex-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $index => $row ) {
			$label   = isset( $row['label'] ) ? $row['label'] : '';
			$term_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

			echo '<tr><td>';
			printf(
				'<input type="text" name="location_label[%d]" value="%s" class="regular-text" placeholder="%s">',
				(int) $index,
				esc_attr( $label ),
				esc_attr__( 'e.g. Marine', 'pause-cafe-flex-menu' )
			);
			echo '</td><td>';
			printf( '<select name="location_term[%d]">', (int) $index );
			printf( '<option value="0">%s</option>', esc_html__( '— None —', 'pause-cafe-flex-menu' ) );

			foreach ( $categories as $category ) {
				printf(
					'<option value="%d" %s>%s</option>',
					(int) $category->term_id,
					selected( $term_id, (int) $category->term_id, false ),
					esc_html( $category->name )
				);
			}

			echo '</select></td></tr>';
		}

		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'pause-cafe-flex-menu' ) );
		echo '</form></div>';
	}
}
