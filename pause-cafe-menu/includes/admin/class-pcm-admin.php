<?php
/**
 * Admin menu registration and shared admin plumbing.
 */

defined( 'ABSPATH' ) || exit;

class PCM_Admin {

	const CAPABILITY = 'manage_woocommerce';

	const PAGE_BUILDER  = 'pcm-builder';
	const PAGE_REPORT   = 'pcm-report';
	const PAGE_LEGACY   = 'pcm-legacy';
	const PAGE_SETTINGS = 'pcm-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Pause Cafe', 'pause-cafe-menu' ),
			__( 'Pause Cafe', 'pause-cafe-menu' ),
			self::CAPABILITY,
			self::PAGE_BUILDER,
			array( 'PCM_Admin_Builder', 'render' ),
			'dashicons-food',
			56
		);

		add_submenu_page(
			self::PAGE_BUILDER,
			__( 'Build menu', 'pause-cafe-menu' ),
			__( 'Build menu', 'pause-cafe-menu' ),
			self::CAPABILITY,
			self::PAGE_BUILDER,
			array( 'PCM_Admin_Builder', 'render' )
		);

		add_submenu_page(
			self::PAGE_BUILDER,
			__( 'Kitchen report', 'pause-cafe-menu' ),
			__( 'Kitchen report', 'pause-cafe-menu' ),
			self::CAPABILITY,
			self::PAGE_REPORT,
			array( 'PCM_Admin_Report', 'render' )
		);

		add_submenu_page(
			self::PAGE_BUILDER,
			__( 'Legacy items', 'pause-cafe-menu' ),
			__( 'Legacy items', 'pause-cafe-menu' ),
			self::CAPABILITY,
			self::PAGE_LEGACY,
			array( 'PCM_Admin_Legacy', 'render' )
		);

		add_submenu_page(
			self::PAGE_BUILDER,
			__( 'Settings', 'pause-cafe-menu' ),
			__( 'Settings', 'pause-cafe-menu' ),
			self::CAPABILITY,
			self::PAGE_SETTINGS,
			array( 'PCM_Admin_Settings', 'render' )
		);
	}

	public static function is_plugin_screen( $hook_suffix ) {
		return false !== strpos( (string) $hook_suffix, 'pcm-' );
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( ! self::is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style( 'pcm-admin', PCM_URL . 'assets/admin.css', array(), PCM_VERSION );

		wp_enqueue_script(
			'pcm-admin',
			PCM_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-autocomplete' ),
			PCM_VERSION,
			true
		);

		wp_localize_script(
			'pcm-admin',
			'pcmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pcm_search_dishes' ),
			)
		);

		wp_enqueue_style( 'wp-jquery-ui-dialog' );
	}

	/**
	 * Queues a notice to show after a redirect, so form handlers can post-redirect-get.
	 */
	public static function add_notice( $message, $type = 'success' ) {
		$notices   = get_transient( 'pcm_admin_notices' );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		set_transient( 'pcm_admin_notices', $notices, 60 );
	}

	public static function print_notices() {
		$notices = get_transient( 'pcm_admin_notices' );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( 'pcm_admin_notices' );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Shown at the top of every plugin screen when setup is incomplete, because
	 * every other screen is useless until locations exist.
	 */
	public static function warn_if_unconfigured() {
		if ( PCM_Settings::locations() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'No pickup locations are configured yet.', 'pause-cafe-menu' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ),
			esc_html__( 'Set them up in Settings', 'pause-cafe-menu' )
		);
	}
}
