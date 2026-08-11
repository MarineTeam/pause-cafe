<?php
/**
 * Settings screen.
 *
 * There is nothing here about when ordering opens, because publishing is what
 * opens it. Only the cutoff is configurable.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Admin_Settings {

	public static function init() {
		add_action( 'admin_post_pclm_save_settings', array( __CLASS__, 'handle_save' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( PCLM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'pause-cafe-live-menu' ) );
		}

		check_admin_referer( 'pclm_save_settings' );

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

		PCLM_Settings::update(
			array(
				'close_weekday'            => isset( $_POST['close_weekday'] ) ? min( 6, max( 0, (int) $_POST['close_weekday'] ) ) : 6,
				'close_time'               => self::sanitize_time( isset( $_POST['close_time'] ) ? wp_unslash( $_POST['close_time'] ) : '', '13:00' ),
				'service_days_after_close' => isset( $_POST['service_days_after_close'] ) ? min( 7, absint( $_POST['service_days_after_close'] ) ) : 1,
				'default_price'            => isset( $_POST['default_price'] ) ? wc_format_decimal( wp_unslash( $_POST['default_price'] ) ) : '10.00',
				'menu_page_id'             => isset( $_POST['menu_page_id'] ) ? absint( $_POST['menu_page_id'] ) : 0,
				'locations'                => $locations,
			)
		);

		PCLM_Visibility::flush();
		PCLM_Admin::add_notice( __( 'Settings saved.', 'pause-cafe-live-menu' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . PCLM_Admin::PAGE_SETTINGS ) );
		exit;
	}

	private static function sanitize_time( $value, $fallback ) {
		$value = trim( (string) $value );

		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ? $value : $fallback;
	}

	public static function render() {
		$settings = PCLM_Settings::all();
		$weekdays = array(
			__( 'Sunday', 'pause-cafe-live-menu' ),
			__( 'Monday', 'pause-cafe-live-menu' ),
			__( 'Tuesday', 'pause-cafe-live-menu' ),
			__( 'Wednesday', 'pause-cafe-live-menu' ),
			__( 'Thursday', 'pause-cafe-live-menu' ),
			__( 'Friday', 'pause-cafe-live-menu' ),
			__( 'Saturday', 'pause-cafe-live-menu' ),
		);

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$categories = is_wp_error( $categories ) ? array() : $categories;
		$rows       = array_merge( (array) $settings['locations'], array( array(), array() ) );

		echo '<div class="wrap pclm-wrap">';
		echo '<h1>' . esc_html__( 'Pause Cafe settings', 'pause-cafe-live-menu' ) . '</h1>';

		PCLM_Admin::print_notices();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pclm_save_settings' );
		echo '<input type="hidden" name="action" value="pclm_save_settings">';

		echo '<h2>' . esc_html__( 'Cutoff', 'pause-cafe-live-menu' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Ordering opens the moment a menu is published. These settings only control when it shuts again.', 'pause-cafe-live-menu' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pclm-close-weekday">' . esc_html__( 'Ordering closes', 'pause-cafe-live-menu' ) . '</label></th><td>';
		echo '<select name="close_weekday" id="pclm-close-weekday">';

		foreach ( $weekdays as $index => $label ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $index,
				selected( (int) $settings['close_weekday'], $index, false ),
				esc_html( $label )
			);
		}

		printf(
			'</select> %s <input type="time" name="close_time" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'at', 'pause-cafe-live-menu' ),
			esc_attr( $settings['close_time'] ),
			esc_html__( 'Default: Saturday at 13:00. A menu published after that time runs to the following week instead of closing in the past.', 'pause-cafe-live-menu' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" max="7" name="service_days_after_close" value="%s" class="small-text"> %s<p class="description">%s</p></td></tr>',
			esc_html__( 'Food is served', 'pause-cafe-live-menu' ),
			esc_attr( $settings['service_days_after_close'] ),
			esc_html__( 'days after the cutoff', 'pause-cafe-live-menu' ),
			esc_html__( 'Default: 1, so a Saturday cutoff serves on Sunday. Dishes stay listed but unorderable until the end of that day.', 'pause-cafe-live-menu' )
		);

		printf(
			'<tr><th scope="row"><label for="pclm-default-price">%s</label></th><td><input type="text" id="pclm-default-price" name="default_price" value="%s" class="small-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Default dish price', 'pause-cafe-live-menu' ),
			esc_attr( $settings['default_price'] ),
			esc_html__( 'Used for new dishes. Individual dishes can still be priced differently.', 'pause-cafe-live-menu' )
		);

		echo '<tr><th scope="row"><label for="pclm-menu-page">' . esc_html__( 'Menu page', 'pause-cafe-live-menu' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'menu_page_id',
				'id'                => 'pclm-menu-page',
				'selected'          => (int) $settings['menu_page_id'],
				'show_option_none'  => __( '— Use the shop page —', 'pause-cafe-live-menu' ),
				'option_none_value' => 0,
			)
		);
		echo '<p class="description">' . esc_html__( 'Links to dishes from a finished week redirect here.', 'pause-cafe-live-menu' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Pickup locations', 'pause-cafe-live-menu' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each location maps to a product category. Only products in these categories, or products this plugin has published, are governed by the cutoff; drinks, desserts and special orders are left alone.', 'pause-cafe-live-menu' ) . '</p>';

		echo '<table class="widefat striped pclm-locations"><thead><tr>';
		echo '<th>' . esc_html__( 'Label shown on the menu', 'pause-cafe-live-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Product category', 'pause-cafe-live-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $index => $row ) {
			$label   = isset( $row['label'] ) ? $row['label'] : '';
			$term_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

			echo '<tr><td>';
			printf(
				'<input type="text" name="location_label[%d]" value="%s" class="regular-text" placeholder="%s">',
				(int) $index,
				esc_attr( $label ),
				esc_attr__( 'e.g. Marine', 'pause-cafe-live-menu' )
			);
			echo '</td><td>';
			printf( '<select name="location_term[%d]">', (int) $index );
			printf( '<option value="0">%s</option>', esc_html__( '— None —', 'pause-cafe-live-menu' ) );

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

		submit_button( __( 'Save settings', 'pause-cafe-live-menu' ) );
		echo '</form></div>';
	}
}
