<?php
/**
 * Plugin Name:       Pause Cafe Flex Menu
 * Description:       Flexible menu scheduling for WooCommerce. Several menus at once, each opening on a plan or on publish, with per-dish overrides, portion limits, blackout dates and per-location cutoffs.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pause Cafe
 * Text Domain:       pause-cafe-flex-menu
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'PCFM_VERSION', '1.0.0' );
define( 'PCFM_FILE', __FILE__ );
define( 'PCFM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCFM_URL', plugin_dir_url( __FILE__ ) );

function pcfm_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p><strong>Pause Cafe Flex Menu</strong> needs WooCommerce to be installed and active.</p></div>';
}

/**
 * All three Pause Cafe scheduling plugins decide whether a dish is buyable. Two
 * of them running at once would have competing filters disagreeing, so the most
 * capable one stands down rather than fight.
 */
function pcfm_conflict_notice() {
	echo '<div class="notice notice-error"><p><strong>Pause Cafe Flex Menu</strong> is not running because another Pause Cafe scheduling plugin is active. Deactivate <strong>Pause Cafe Menu</strong> or <strong>Pause Cafe Live Menu</strong> first — only one can be on at a time.</p></div>';
}

function pcfm_bootstrap() {
	if ( defined( 'PCM_VERSION' ) || defined( 'PCLM_VERSION' ) ) {
		add_action( 'admin_notices', 'pcfm_conflict_notice' );
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'pcfm_woocommerce_missing_notice' );
		return;
	}

	require_once PCFM_DIR . 'includes/class-pcfm-settings.php';
	require_once PCFM_DIR . 'includes/class-pcfm-schedules.php';
	require_once PCFM_DIR . 'includes/class-pcfm-blackouts.php';
	require_once PCFM_DIR . 'includes/class-pcfm-window.php';
	require_once PCFM_DIR . 'includes/class-pcfm-product.php';
	require_once PCFM_DIR . 'includes/class-pcfm-visibility.php';
	require_once PCFM_DIR . 'includes/class-pcfm-guard.php';
	require_once PCFM_DIR . 'includes/class-pcfm-shortcode.php';
	require_once PCFM_DIR . 'includes/class-pcfm-orders.php';

	PCFM_Schedules::init();
	PCFM_Product::init();
	PCFM_Visibility::init();
	PCFM_Guard::init();
	PCFM_Shortcode::init();
	PCFM_Orders::init();

	if ( is_admin() ) {
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin.php';
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin-builder.php';
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin-schedules.php';
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin-report.php';
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin-blackouts.php';
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin-settings.php';
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin-legacy.php';
		require_once PCFM_DIR . 'includes/admin/class-pcfm-admin-product-panel.php';

		PCFM_Admin::init();
		PCFM_Admin_Builder::init();
		PCFM_Admin_Schedules::init();
		PCFM_Admin_Report::init();
		PCFM_Admin_Blackouts::init();
		PCFM_Admin_Settings::init();
		PCFM_Admin_Legacy::init();
		PCFM_Admin_Product_Panel::init();
	}
}
add_action( 'plugins_loaded', 'pcfm_bootstrap' );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PCFM_FILE, true );
		}
	}
);

/**
 * Detects pickup locations from existing categories so the settings screen opens
 * pre-filled, and registers the taxonomy before flushing rewrite rules.
 */
function pcfm_activate() {
	require_once PCFM_DIR . 'includes/class-pcfm-settings.php';
	require_once PCFM_DIR . 'includes/class-pcfm-schedules.php';

	PCFM_Schedules::register_taxonomy();

	$settings = get_option( PCFM_Settings::OPTION, array() );

	if ( empty( $settings['locations'] ) && taxonomy_exists( 'product_cat' ) ) {
		$terms     = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$locations = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( 0 === stripos( $term->name, 'pick up' ) ) {
					$label = trim( preg_replace( '/^pick\s*up\s*/i', '', $term->name ), " \t\n\r\0\x0B()" );

					$locations[] = array(
						'label'   => '' !== $label ? $label : $term->name,
						'term_id' => (int) $term->term_id,
					);
				}
			}
		}

		if ( $locations ) {
			$settings['locations'] = $locations;
			update_option( PCFM_Settings::OPTION, $settings );
		}
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pcfm_activate' );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
