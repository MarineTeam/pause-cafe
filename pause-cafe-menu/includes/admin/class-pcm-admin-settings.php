<?php
/**
 * Settings screen: the ordering window, and which categories are pickup locations.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Admin_Settings {

	public static function init() {
		add_action( 'admin_post_pcm_save_settings', array( __CLASS__, 'handle_save' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( PCM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'pause-cafe-menu' ) );
		}

		check_admin_referer( 'pcm_save_settings' );

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

		PCM_Settings::update(
			array(
				'service_weekday'   => isset( $_POST['service_weekday'] ) ? min( 6, max( 0, (int) $_POST['service_weekday'] ) ) : 0,
				'open_days_before'  => isset( $_POST['open_days_before'] ) ? min( 30, absint( $_POST['open_days_before'] ) ) : 5,
				'open_time'         => self::sanitize_time( isset( $_POST['open_time'] ) ? wp_unslash( $_POST['open_time'] ) : '', '12:00' ),
				'close_days_before' => isset( $_POST['close_days_before'] ) ? min( 30, absint( $_POST['close_days_before'] ) ) : 1,
				'close_time'        => self::sanitize_time( isset( $_POST['close_time'] ) ? wp_unslash( $_POST['close_time'] ) : '', '13:00' ),
				'default_price'     => isset( $_POST['default_price'] ) ? wc_format_decimal( wp_unslash( $_POST['default_price'] ) ) : '10.00',
				'preview_upcoming'  => isset( $_POST['preview_upcoming'] ) ? 'yes' : 'no',
				'menu_page_id'      => isset( $_POST['menu_page_id'] ) ? absint( $_POST['menu_page_id'] ) : 0,
				'locations'         => $locations,
			)
		);

		PCM_Visibility::flush();
		PCM_Admin::add_notice( __( 'Settings saved.', 'pause-cafe-menu' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . PCM_Admin::PAGE_SETTINGS ) );
		exit;
	}

	private static function sanitize_time( $value, $fallback ) {
		$value = trim( (string) $value );

		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return $value;
		}

		return $fallback;
	}

	public static function render() {
		$settings = PCM_Settings::all();
		$weekdays = array(
			__( 'Sunday', 'pause-cafe-menu' ),
			__( 'Monday', 'pause-cafe-menu' ),
			__( 'Tuesday', 'pause-cafe-menu' ),
			__( 'Wednesday', 'pause-cafe-menu' ),
			__( 'Thursday', 'pause-cafe-menu' ),
			__( 'Friday', 'pause-cafe-menu' ),
			__( 'Saturday', 'pause-cafe-menu' ),
		);

		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$categories = is_wp_error( $categories ) ? array() : $categories;
		$locations  = $settings['locations'];

		// Always render a couple of blank rows so locations can be added.
		$rows = array_merge( is_array( $locations ) ? $locations : array(), array( array(), array() ) );

		echo '<div class="wrap pcm-wrap">';
		echo '<h1>' . esc_html__( 'Pause Cafe settings', 'pause-cafe-menu' ) . '</h1>';

		PCM_Admin::print_notices();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcm_save_settings' );
		echo '<input type="hidden" name="action" value="pcm_save_settings">';

		echo '<h2>' . esc_html__( 'Ordering window', 'pause-cafe-menu' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Everything is expressed relative to the day the food is served, so each week inherits the same rules without being configured individually.', 'pause-cafe-menu' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="pcm-service-weekday">' . esc_html__( 'Food is served on', 'pause-cafe-menu' ) . '</label></th><td>';
		echo '<select name="service_weekday" id="pcm-service-weekday">';
		foreach ( $weekdays as $index => $label ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $index,
				selected( (int) $settings['service_weekday'], $index, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" max="30" name="open_days_before" value="%s" class="small-text"> %s <input type="time" name="open_time" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Ordering opens', 'pause-cafe-menu' ),
			esc_attr( $settings['open_days_before'] ),
			esc_html__( 'days before, at', 'pause-cafe-menu' ),
			esc_attr( $settings['open_time'] ),
			esc_html__( 'Default: 5 days before Sunday at 12:00, which is Tuesday lunchtime.', 'pause-cafe-menu' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" max="30" name="close_days_before" value="%s" class="small-text"> %s <input type="time" name="close_time" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Ordering closes', 'pause-cafe-menu' ),
			esc_attr( $settings['close_days_before'] ),
			esc_html__( 'days before, at', 'pause-cafe-menu' ),
			esc_attr( $settings['close_time'] ),
			esc_html__( 'Default: 1 day before Sunday at 13:00, which is Saturday 1pm.', 'pause-cafe-menu' )
		);

		printf(
			'<tr><th scope="row"><label for="pcm-default-price">%s</label></th><td><input type="text" id="pcm-default-price" name="default_price" value="%s" class="small-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Default dish price', 'pause-cafe-menu' ),
			esc_attr( $settings['default_price'] ),
			esc_html__( 'Used for new dishes created in the builder. Individual dishes can still be priced differently.', 'pause-cafe-menu' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="preview_upcoming" value="1" %s> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Preview upcoming weeks', 'pause-cafe-menu' ),
			checked( 'yes', $settings['preview_upcoming'], false ),
			esc_html__( 'Show future weeks on the menu before ordering opens', 'pause-cafe-menu' ),
			esc_html__( 'Off by default. The nearest week always shows, whether or not ordering has opened.', 'pause-cafe-menu' )
		);

		echo '<tr><th scope="row"><label for="pcm-menu-page">' . esc_html__( 'Menu page', 'pause-cafe-menu' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'menu_page_id',
				'id'                => 'pcm-menu-page',
				'selected'          => (int) $settings['menu_page_id'],
				'show_option_none'  => __( '— Use the shop page —', 'pause-cafe-menu' ),
				'option_none_value' => 0,
			)
		);
		echo '<p class="description">' . esc_html__( 'Links to dishes that have already been served redirect here.', 'pause-cafe-menu' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Pickup locations', 'pause-cafe-menu' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each location maps to a product category. Only products in these categories, or products carrying a service date, are governed by the schedule; drinks, desserts and special orders are left alone.', 'pause-cafe-menu' ) . '</p>';

		echo '<table class="widefat striped pcm-locations"><thead><tr>';
		echo '<th>' . esc_html__( 'Label shown on the menu', 'pause-cafe-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Product category', 'pause-cafe-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $index => $row ) {
			$label   = isset( $row['label'] ) ? $row['label'] : '';
			$term_id = isset( $row['term_id'] ) ? (int) $row['term_id'] : 0;

			echo '<tr><td>';
			printf(
				'<input type="text" name="location_label[%d]" value="%s" class="regular-text" placeholder="%s">',
				(int) $index,
				esc_attr( $label ),
				esc_attr__( 'e.g. Marine', 'pause-cafe-menu' )
			);
			echo '</td><td>';
			printf( '<select name="location_term[%d]">', (int) $index );
			printf( '<option value="0">%s</option>', esc_html__( '— None —', 'pause-cafe-menu' ) );

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

		submit_button( __( 'Save settings', 'pause-cafe-menu' ) );
		echo '</form></div>';
	}
}
