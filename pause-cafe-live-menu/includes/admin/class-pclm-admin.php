<?php
/**
 * Admin menu registration and shared admin plumbing.
 */

defined( 'ABSPATH' ) || exit;

class PCLM_Admin {

	const CAPABILITY = 'manage_woocommerce';

	const PAGE_PUBLISH  = 'pclm-publish';
	const PAGE_REPORT   = 'pclm-report';
	const PAGE_LEGACY   = 'pclm-legacy';
	const PAGE_SETTINGS = 'pclm-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Pause Cafe', 'pause-cafe-live-menu' ),
			__( 'Pause Cafe', 'pause-cafe-live-menu' ),
			self::CAPABILITY,
			self::PAGE_PUBLISH,
			array( 'PCLM_Admin_Publish', 'render' ),
			'dashicons-food',
			56
		);

		$pages = array(
			self::PAGE_PUBLISH  => array( __( 'Publish menu', 'pause-cafe-live-menu' ), array( 'PCLM_Admin_Publish', 'render' ) ),
			self::PAGE_REPORT   => array( __( 'Kitchen report', 'pause-cafe-live-menu' ), array( 'PCLM_Admin_Report', 'render' ) ),
			self::PAGE_LEGACY   => array( __( 'Legacy items', 'pause-cafe-live-menu' ), array( 'PCLM_Admin_Legacy', 'render' ) ),
			self::PAGE_SETTINGS => array( __( 'Settings', 'pause-cafe-live-menu' ), array( 'PCLM_Admin_Settings', 'render' ) ),
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page(
				self::PAGE_PUBLISH,
				$page[0],
				$page[0],
				self::CAPABILITY,
				$slug,
				$page[1]
			);
		}
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'pclm-' ) ) {
			return;
		}

		wp_enqueue_style( 'pclm-admin', PCLM_URL . 'assets/admin.css', array(), PCLM_VERSION );

		wp_enqueue_script(
			'pclm-admin',
			PCLM_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-autocomplete' ),
			PCLM_VERSION,
			true
		);

		wp_localize_script(
			'pclm-admin',
			'pclmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pclm_search_dishes' ),
			)
		);

		wp_enqueue_style( 'wp-jquery-ui-dialog' );
	}

	/**
	 * Queues a notice to show after a redirect, so form handlers can
	 * post-redirect-get.
	 */
	public static function add_notice( $message, $type = 'success' ) {
		$notices   = get_transient( 'pclm_admin_notices' );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		set_transient( 'pclm_admin_notices', $notices, 60 );
	}

	public static function print_notices() {
		$notices = get_transient( 'pclm_admin_notices' );

		if ( ! is_array( $notices ) ) {
			return;
		}

		delete_transient( 'pclm_admin_notices' );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['message'] )
			);
		}
	}

	public static function warn_if_unconfigured() {
		if ( PCLM_Settings::locations() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'No pickup locations are configured yet.', 'pause-cafe-live-menu' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ),
			esc_html__( 'Set them up in Settings', 'pause-cafe-live-menu' )
		);
	}
}
