<?php
/**
 * Blackout dates: days no menu runs.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin_Blackouts {

	public static function init() {
		add_action( 'admin_post_pcfm_save_blackouts', array( __CLASS__, 'handle_save' ) );
	}

	public static function render() {
		$blackouts = PCFM_Blackouts::all();
		$rows      = $blackouts;

		// Always offer a few blank rows so dates can be added.
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[ '__new_' . $i ] = '';
		}

		echo '<div class="wrap pcfm-wrap">';
		echo '<h1>' . esc_html__( 'Blackout dates', 'pause-cafe-flex-menu' ) . '</h1>';

		PCFM_Admin::print_notices();

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Days no menu runs. Any dish serving on a blackout date is hidden and cannot be ordered, and the menu shows the label instead of an empty page. Clearing a date removes it.', 'pause-cafe-flex-menu' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcfm_save_blackouts' );
		echo '<input type="hidden" name="action" value="pcfm_save_blackouts">';

		echo '<table class="widefat striped pcfm-blackouts"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Shown on the menu', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'pause-cafe-flex-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		$index = 0;
		$today = PCFM_Window::now()->format( 'Y-m-d' );

		foreach ( $rows as $date => $label ) {
			$is_real = 0 !== strpos( (string) $date, '__new_' );
			$value   = $is_real ? $date : '';

			printf(
				'<tr><td><input type="date" name="date[%1$d]" value="%2$s"></td>
				<td><input type="text" name="label[%1$d]" value="%3$s" class="regular-text" placeholder="%4$s"></td>
				<td>%5$s</td></tr>',
				$index,
				esc_attr( $value ),
				esc_attr( $label ),
				esc_attr__( 'e.g. Christmas — no lunch', 'pause-cafe-flex-menu' ),
				$is_real
					? esc_html( $value >= $today ? __( 'Upcoming', 'pause-cafe-flex-menu' ) : __( 'Past', 'pause-cafe-flex-menu' ) )
					: ''
			);

			++$index;
		}

		echo '</tbody></table>';

		submit_button( __( 'Save blackout dates', 'pause-cafe-flex-menu' ) );
		echo '</form></div>';
	}

	public static function handle_save() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change blackout dates.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_save_blackouts' );

		$dates  = isset( $_POST['date'] ) ? (array) wp_unslash( $_POST['date'] ) : array();
		$labels = isset( $_POST['label'] ) ? (array) wp_unslash( $_POST['label'] ) : array();

		$clean = array();

		foreach ( $dates as $index => $date ) {
			$date = sanitize_text_field( $date );

			if ( '' === $date ) {
				continue;
			}

			$clean[ $date ] = isset( $labels[ $index ] ) ? $labels[ $index ] : '';
		}

		PCFM_Blackouts::update( $clean );
		PCFM_Visibility::flush();

		PCFM_Admin::add_notice(
			sprintf(
				/* translators: %d: number of blackout dates. */
				_n( '%d blackout date saved.', '%d blackout dates saved.', count( $clean ), 'pause-cafe-flex-menu' ),
				count( $clean )
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . PCFM_Admin::PAGE_BLACKOUTS ) );
		exit;
	}
}
