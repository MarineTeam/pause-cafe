<?php
/**
 * Creating menu schedules and setting their rules.
 *
 * This is the screen that makes several menus possible: each one picks its own
 * mode, its own cutoff, its own locations and its own per-location offsets.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin_Schedules {

	public static function init() {
		add_action( 'admin_post_pcfm_create_schedule', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_pcfm_save_schedule', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_pcfm_delete_schedule', array( __CLASS__, 'handle_delete' ) );
	}

	private static function url( array $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => PCFM_Admin::PAGE_SCHEDULES ), $args ),
			admin_url( 'admin.php' )
		);
	}

	public static function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$editing = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;

		echo '<div class="wrap pcfm-wrap">';
		echo '<h1>' . esc_html__( 'Menu schedules', 'pause-cafe-flex-menu' ) . '</h1>';

		PCFM_Admin::print_notices();

		if ( $editing && PCFM_Schedules::exists( $editing ) ) {
			self::render_editor( $editing );
		} else {
			self::render_list();
			self::render_create();
		}

		echo '</div>';
	}

	private static function render_list() {
		$schedules = PCFM_Schedules::all();

		if ( ! $schedules ) {
			echo '<p>' . esc_html__( 'No schedules yet. Create one below — most sites need only a single Sunday lunch schedule.', 'pause-cafe-flex-menu' ) . '</p>';

			return;
		}

		$modes = PCFM_Schedules::modes();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Schedule', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Mode', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Locations', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Dishes', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $schedules as $schedule ) {
			$rules     = PCFM_Schedules::rules( $schedule->term_id );
			$locations = PCFM_Schedules::locations( $schedule->term_id );

			printf(
				'<tr><td><strong>%s</strong><br><code>%s</code></td><td>%s</td><td>%s</td><td>%d</td>
				<td><a class="button" href="%s">%s</a> <a class="button-link delete" href="%s" onclick="return confirm(%s)">%s</a></td></tr>',
				esc_html( $schedule->name ),
				esc_html( $schedule->slug ),
				esc_html( isset( $modes[ $rules['mode'] ] ) ? $modes[ $rules['mode'] ] : $rules['mode'] ),
				esc_html( $locations ? implode( ', ', wp_list_pluck( $locations, 'label' ) ) : __( 'None', 'pause-cafe-flex-menu' ) ),
				(int) $schedule->count,
				esc_url( self::url( array( 'edit' => $schedule->term_id ) ) ),
				esc_html__( 'Edit rules', 'pause-cafe-flex-menu' ),
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action'   => 'pcfm_delete_schedule',
								'schedule' => $schedule->term_id,
							),
							admin_url( 'admin-post.php' )
						),
						'pcfm_delete_schedule'
					)
				),
				esc_js( wp_json_encode( __( 'Delete this schedule? Dishes on it stay put but stop being scheduled.', 'pause-cafe-flex-menu' ) ) ),
				esc_html__( 'Delete', 'pause-cafe-flex-menu' )
			);
		}

		echo '</tbody></table>';
	}

	private static function render_create() {
		echo '<h2>' . esc_html__( 'Add a schedule', 'pause-cafe-flex-menu' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcfm_create_schedule' );
		echo '<input type="hidden" name="action" value="pcfm_create_schedule">';

		printf(
			'<p><input type="text" name="name" class="regular-text" placeholder="%s" required></p>',
			esc_attr__( 'e.g. Sunday lunch', 'pause-cafe-flex-menu' )
		);

		submit_button( __( 'Create schedule', 'pause-cafe-flex-menu' ), 'secondary' );
		echo '</form>';
	}

	private static function render_editor( $schedule_id ) {
		$schedule = get_term( $schedule_id, PCFM_Schedules::TAXONOMY );
		$rules    = PCFM_Schedules::rules( $schedule_id );
		$weekdays = array(
			__( 'Sunday', 'pause-cafe-flex-menu' ),
			__( 'Monday', 'pause-cafe-flex-menu' ),
			__( 'Tuesday', 'pause-cafe-flex-menu' ),
			__( 'Wednesday', 'pause-cafe-flex-menu' ),
			__( 'Thursday', 'pause-cafe-flex-menu' ),
			__( 'Friday', 'pause-cafe-flex-menu' ),
			__( 'Saturday', 'pause-cafe-flex-menu' ),
		);

		printf(
			'<h2>%s <a class="button" href="%s">%s</a></h2>',
			esc_html( $schedule->name ),
			esc_url( self::url() ),
			esc_html__( 'Back to all schedules', 'pause-cafe-flex-menu' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pcfm_save_schedule' );
		echo '<input type="hidden" name="action" value="pcfm_save_schedule">';
		printf( '<input type="hidden" name="schedule" value="%d">', (int) $schedule_id );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="pcfm-name">%s</label></th><td><input type="text" id="pcfm-name" name="name" value="%s" class="regular-text"></td></tr>',
			esc_html__( 'Name', 'pause-cafe-flex-menu' ),
			esc_attr( $schedule->name )
		);

		echo '<tr><th scope="row"><label for="pcfm-mode">' . esc_html__( 'When ordering opens', 'pause-cafe-flex-menu' ) . '</label></th><td>';
		echo '<select name="mode" id="pcfm-mode" class="pcfm-mode-select">';

		foreach ( PCFM_Schedules::modes() as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $rules['mode'], $value, false ),
				esc_html( $label )
			);
		}

		echo '</select></td></tr>';
		echo '</tbody></table>';

		// Planned.
		printf( '<div class="pcfm-mode-panel" data-mode="%s"><h3>%s</h3><table class="form-table" role="presentation"><tbody>', esc_attr( PCFM_Schedules::MODE_PLANNED ), esc_html__( 'Planned schedule', 'pause-cafe-flex-menu' ) );

		echo '<tr><th scope="row">' . esc_html__( 'Food is served on', 'pause-cafe-flex-menu' ) . '</th><td><select name="service_weekday">';

		foreach ( $weekdays as $index => $label ) {
			printf( '<option value="%d" %s>%s</option>', (int) $index, selected( (int) $rules['service_weekday'], $index, false ), esc_html( $label ) );
		}

		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" max="30" name="open_days_before" value="%s" class="small-text"> %s <input type="time" name="open_time" value="%s"></td></tr>',
			esc_html__( 'Ordering opens', 'pause-cafe-flex-menu' ),
			esc_attr( $rules['open_days_before'] ),
			esc_html__( 'days before, at', 'pause-cafe-flex-menu' ),
			esc_attr( $rules['open_time'] )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" max="30" name="close_days_before" value="%s" class="small-text"> %s</td></tr>',
			esc_html__( 'Ordering closes', 'pause-cafe-flex-menu' ),
			esc_attr( $rules['close_days_before'] ),
			esc_html__( 'days before the service day, at the closing time below', 'pause-cafe-flex-menu' )
		);

		echo '</tbody></table></div>';

		// On publish.
		printf( '<div class="pcfm-mode-panel" data-mode="%s"><h3>%s</h3><table class="form-table" role="presentation"><tbody>', esc_attr( PCFM_Schedules::MODE_ON_PUBLISH ), esc_html__( 'On-publish schedule', 'pause-cafe-flex-menu' ) );

		echo '<tr><th scope="row">' . esc_html__( 'Ordering closes on', 'pause-cafe-flex-menu' ) . '</th><td><select name="close_weekday">';

		foreach ( $weekdays as $index => $label ) {
			printf( '<option value="%d" %s>%s</option>', (int) $index, selected( (int) $rules['close_weekday'], $index, false ), esc_html( $label ) );
		}

		printf(
			'</select><p class="description">%s</p></td></tr>',
			esc_html__( 'A menu published at or after the cutoff runs to the following week rather than closing in the past.', 'pause-cafe-flex-menu' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" max="14" name="service_days_after_close" value="%s" class="small-text"> %s</td></tr>',
			esc_html__( 'Food is served', 'pause-cafe-flex-menu' ),
			esc_attr( $rules['service_days_after_close'] ),
			esc_html__( 'days after the cutoff', 'pause-cafe-flex-menu' )
		);

		echo '</tbody></table></div>';

		// Shared.
		echo '<h3>' . esc_html__( 'All modes', 'pause-cafe-flex-menu' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td><input type="time" name="close_time" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Closing time', 'pause-cafe-flex-menu' ),
			esc_attr( $rules['close_time'] ),
			esc_html__( 'The time of day ordering shuts. Ignored by manual schedules, which carry their own times per dish.', 'pause-cafe-flex-menu' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="preview_upcoming" value="1" %s> %s</label></td></tr>',
			esc_html__( 'Preview upcoming', 'pause-cafe-flex-menu' ),
			checked( 'yes', $rules['preview_upcoming'], false ),
			esc_html__( 'Show dishes before ordering opens', 'pause-cafe-flex-menu' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" name="default_capacity" value="%s" class="small-text"><p class="description">%s</p></td></tr>',
			esc_html__( 'Default portions', 'pause-cafe-flex-menu' ),
			esc_attr( $rules['default_capacity'] ),
			esc_html__( 'Applied to new dishes. 0 means unlimited. Runs on WooCommerce stock, so sold-out handling is the usual one.', 'pause-cafe-flex-menu' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="default_price" value="%s" class="small-text" placeholder="%s"></td></tr>',
			esc_html__( 'Default price', 'pause-cafe-flex-menu' ),
			esc_attr( $rules['default_price'] ),
			esc_attr( PCFM_Settings::get( 'default_price' ) )
		);

		echo '</tbody></table>';

		self::render_locations( $rules );

		submit_button( __( 'Save schedule', 'pause-cafe-flex-menu' ) );
		echo '</form>';
	}

	private static function render_locations( array $rules ) {
		$locations = PCFM_Settings::locations();

		echo '<h3>' . esc_html__( 'Locations', 'pause-cafe-flex-menu' ) . '</h3>';

		if ( ! $locations ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'No pickup locations are configured yet. Add them in Settings first.', 'pause-cafe-flex-menu' )
			);

			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Tick the locations this menu serves — leave all unticked to serve every location. An offset closes that location earlier than the rest of the schedule; it can never extend the window.', 'pause-cafe-flex-menu' )
		);

		$selected = array_map( 'intval', (array) $rules['locations'] );
		$offsets  = is_array( $rules['location_offsets'] ) ? $rules['location_offsets'] : array();

		echo '<table class="widefat striped pcfm-locations"><thead><tr>';
		echo '<th>' . esc_html__( 'Serves', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Location', 'pause-cafe-flex-menu' ) . '</th>';
		echo '<th>' . esc_html__( 'Closes earlier by (minutes)', 'pause-cafe-flex-menu' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $locations as $location ) {
			$term_id = (int) $location['term_id'];

			printf(
				'<tr><td><input type="checkbox" name="locations[]" value="%1$d" %2$s></td><td>%3$s</td>
				<td><input type="number" min="0" step="15" name="location_offsets[%1$d]" value="%4$s" class="small-text"></td></tr>',
				$term_id,
				checked( in_array( $term_id, $selected, true ), true, false ),
				esc_html( $location['label'] ),
				esc_attr( isset( $offsets[ $term_id ] ) ? (int) $offsets[ $term_id ] : 0 )
			);
		}

		echo '</tbody></table>';
	}

	public static function handle_create() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage schedules.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_create_schedule' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' === $name ) {
			PCFM_Admin::add_notice( __( 'A schedule needs a name.', 'pause-cafe-flex-menu' ), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}

		$result = wp_insert_term( $name, PCFM_Schedules::TAXONOMY );

		if ( is_wp_error( $result ) ) {
			PCFM_Admin::add_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( self::url() );
			exit;
		}

		PCFM_Schedules::save_rules( $result['term_id'], PCFM_Schedules::default_rules() );
		PCFM_Admin::add_notice( __( 'Schedule created. Set its rules next.', 'pause-cafe-flex-menu' ) );

		wp_safe_redirect( self::url( array( 'edit' => $result['term_id'] ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage schedules.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_save_schedule' );

		$schedule_id = isset( $_POST['schedule'] ) ? absint( $_POST['schedule'] ) : 0;

		if ( ! PCFM_Schedules::exists( $schedule_id ) ) {
			wp_die( esc_html__( 'Unknown schedule.', 'pause-cafe-flex-menu' ) );
		}

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( '' !== $name ) {
			wp_update_term( $schedule_id, PCFM_Schedules::TAXONOMY, array( 'name' => $name ) );
		}

		$modes = array_keys( PCFM_Schedules::modes() );
		$mode  = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';

		$offsets_raw = isset( $_POST['location_offsets'] ) ? (array) wp_unslash( $_POST['location_offsets'] ) : array();
		$offsets     = array();

		foreach ( $offsets_raw as $term_id => $minutes ) {
			$minutes = absint( $minutes );

			if ( $minutes > 0 ) {
				$offsets[ (int) $term_id ] = $minutes;
			}
		}

		PCFM_Schedules::save_rules(
			$schedule_id,
			array(
				'mode'                     => in_array( $mode, $modes, true ) ? $mode : PCFM_Schedules::MODE_PLANNED,
				'service_weekday'          => isset( $_POST['service_weekday'] ) ? min( 6, max( 0, (int) $_POST['service_weekday'] ) ) : 0,
				'open_days_before'         => isset( $_POST['open_days_before'] ) ? min( 30, absint( $_POST['open_days_before'] ) ) : 5,
				'open_time'                => self::sanitize_time( isset( $_POST['open_time'] ) ? wp_unslash( $_POST['open_time'] ) : '', '12:00' ),
				'close_days_before'        => isset( $_POST['close_days_before'] ) ? min( 30, absint( $_POST['close_days_before'] ) ) : 1,
				'close_weekday'            => isset( $_POST['close_weekday'] ) ? min( 6, max( 0, (int) $_POST['close_weekday'] ) ) : 6,
				'close_time'               => self::sanitize_time( isset( $_POST['close_time'] ) ? wp_unslash( $_POST['close_time'] ) : '', '13:00' ),
				'service_days_after_close' => isset( $_POST['service_days_after_close'] ) ? min( 14, absint( $_POST['service_days_after_close'] ) ) : 1,
				'preview_upcoming'         => isset( $_POST['preview_upcoming'] ) ? 'yes' : 'no',
				'default_capacity'         => isset( $_POST['default_capacity'] ) ? absint( $_POST['default_capacity'] ) : 0,
				'default_price'            => isset( $_POST['default_price'] ) ? wc_format_decimal( wp_unslash( $_POST['default_price'] ) ) : '',
				'locations'                => isset( $_POST['locations'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['locations'] ) ) : array(),
				'location_offsets'         => $offsets,
			)
		);

		// Rule changes can move every service date on the schedule.
		PCFM_Product::resync_schedule( $schedule_id );
		PCFM_Visibility::flush();

		PCFM_Admin::add_notice( __( 'Schedule saved.', 'pause-cafe-flex-menu' ) );

		wp_safe_redirect( self::url( array( 'edit' => $schedule_id ) ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( PCFM_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage schedules.', 'pause-cafe-flex-menu' ) );
		}

		check_admin_referer( 'pcfm_delete_schedule' );

		$schedule_id = isset( $_GET['schedule'] ) ? absint( $_GET['schedule'] ) : 0;

		if ( PCFM_Schedules::exists( $schedule_id ) ) {
			wp_delete_term( $schedule_id, PCFM_Schedules::TAXONOMY );
			PCFM_Visibility::flush();
			PCFM_Admin::add_notice( __( 'Schedule deleted. Its dishes are untouched but no longer scheduled.', 'pause-cafe-flex-menu' ) );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	private static function sanitize_time( $value, $fallback ) {
		$value = trim( (string) $value );

		return preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $value ) ? $value : $fallback;
	}
}
