<?php
/**
 * Admin menu registration and shared admin plumbing.
 */

defined( 'ABSPATH' ) || exit;

class PCFM_Admin {

	const CAPABILITY = 'manage_woocommerce';

	const PAGE_BUILDER   = 'pcfm-builder';
	const PAGE_SCHEDULES = 'pcfm-schedules';
	const PAGE_REPORT    = 'pcfm-report';
	const PAGE_BLACKOUTS = 'pcfm-blackouts';
	const PAGE_LEGACY    = 'pcfm-legacy';
	const PAGE_SETTINGS  = 'pcfm-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Pause Cafe', 'pause-cafe-flex-menu' ),
			__( 'Pause Cafe', 'pause-cafe-flex-menu' ),
			self::CAPABILITY,
			self::PAGE_BUILDER,
			array( 'PCFM_Admin_Builder', 'render' ),
			'dashicons-food',
			56
		);

		$pages = array(
			self::PAGE_BUILDER   => array( __( 'Build menu', 'pause-cafe-flex-menu' ), array( 'PCFM_Admin_Builder', 'render' ) ),
			self::PAGE_SCHEDULES => array( __( 'Schedules', 'pause-cafe-flex-menu' ), array( 'PCFM_Admin_Schedules', 'render' ) ),
			self::PAGE_REPORT    => array( __( 'Kitchen report', 'pause-cafe-flex-menu' ), array( 'PCFM_Admin_Report', 'render' ) ),
			self::PAGE_BLACKOUTS => array( __( 'Blackout dates', 'pause-cafe-flex-menu' ), array( 'PCFM_Admin_Blackouts', 'render' ) ),
			self::PAGE_LEGACY    => array( __( 'Legacy items', 'pause-cafe-flex-menu' ), array( 'PCFM_Admin_Legacy', 'render' ) ),
			self::PAGE_SETTINGS  => array( __( 'Settings', 'pause-cafe-flex-menu' ), array( 'PCFM_Admin_Settings', 'render' ) ),
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page( self::PAGE_BUILDER, $page[0], $page[0], self::CAPABILITY, $slug, $page[1] );
		}
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'pcfm-' ) ) {
			return;
		}

		wp_enqueue_style( 'pcfm-admin', PCFM_URL . 'assets/admin.css', array(), PCFM_VERSION );

		wp_enqueue_script(
			'pcfm-admin',
			PCFM_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-autocomplete' ),
			PCFM_VERSION,
			true
		);

		wp_localize_script(
			'pcfm-admin',
			'pcfmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pcfm_search_dishes' ),
			)
		);

		wp_enqueue_style( 'wp-jquery-ui-dialog' );
	}

	public static function add_notice( $message, $type = 'success' ) {
		$notices   = get_transient( 'pcfm_admin_notices' );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		set_transient( 'pcfm_admin_notices', $notices, 60 );
	}

	public static function print_notices() {
		$notices = get_transient( 'pcfm_admin_notices' );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( 'pcfm_admin_notices' );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Every other screen is useless until locations and at least one schedule
	 * exist, so say so rather than showing an empty grid.
	 */
	public static function warn_if_unconfigured() {
		if ( ! PCFM_Settings::locations() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'No pickup locations are configured yet.', 'pause-cafe-flex-menu' ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ),
				esc_html__( 'Set them up in Settings', 'pause-cafe-flex-menu' )
			);
		}

		if ( ! PCFM_Schedules::all() ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'No menu schedules exist yet.', 'pause-cafe-flex-menu' ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE_SCHEDULES ) ),
				esc_html__( 'Create one', 'pause-cafe-flex-menu' )
			);
		}
	}

	/**
	 * Schedule picker shared by the builder and the report.
	 */
	public static function render_schedule_picker( $page, $selected, array $extra = array() ) {
		$schedules = PCFM_Schedules::all();

		if ( count( $schedules ) < 2 ) {
			return;
		}

		echo '<form method="get" class="pcfm-schedule-picker">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( $page ) );

		foreach ( $extra as $name => $value ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( $value ) );
		}

		echo '<label for="pcfm-schedule">' . esc_html__( 'Schedule', 'pause-cafe-flex-menu' ) . '</label> ';
		echo '<select name="schedule" id="pcfm-schedule" onchange="this.form.submit()">';

		foreach ( $schedules as $schedule ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $schedule->term_id,
				selected( (int) $selected, (int) $schedule->term_id, false ),
				esc_html( $schedule->name )
			);
		}

		echo '</select></form>';
	}

	/**
	 * The schedule the current screen is working on: the one asked for, or the
	 * first that exists.
	 */
	public static function current_schedule_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$requested = isset( $_GET['schedule'] ) ? absint( $_GET['schedule'] ) : 0;

		if ( $requested && PCFM_Schedules::exists( $requested ) ) {
			return $requested;
		}

		$all = PCFM_Schedules::all();

		return $all ? (int) $all[0]->term_id : 0;
	}
}
