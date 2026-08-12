<?php
/**
 * Plugin Name:       Pause Cafe Live Menu
 * Description:       Publish the week's menu and ordering opens immediately. Closes automatically at the next Saturday cutoff, stays shut over Sunday, and reopens the moment the next menu is published.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pause Cafe
 * Text Domain:       pause-cafe-live-menu
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'PCLM_VERSION', '1.0.0' );
define( 'PCLM_FILE', __FILE__ );
define( 'PCLM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCLM_URL', plugin_dir_url( __FILE__ ) );

function pclm_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p><strong>Pause Cafe Live Menu</strong> needs WooCommerce to be installed and active.</p></div>';
}

/**
 * This plugin and Pause Cafe Menu answer the same question in incompatible ways
 * -- one derives the ordering window from a service date set in advance, the
 * other from the moment of publishing. Running both would have two sets of
 * filters fighting over whether a dish is buyable.
 */
function pclm_conflict_notice() {
	echo '<div class="notice notice-error"><p><strong>Pause Cafe Live Menu</strong> is not running because <strong>Pause Cafe Menu</strong> is also active. The two schedule dishes in different ways and cannot both be on. Deactivate one.</p></div>';
}

function pclm_bootstrap() {
	if ( defined( 'PCM_VERSION' ) ) {
		add_action( 'admin_notices', 'pclm_conflict_notice' );
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'pclm_woocommerce_missing_notice' );
		return;
	}

	require_once PCLM_DIR . 'includes/class-pclm-settings.php';
	require_once PCLM_DIR . 'includes/class-pclm-schedule.php';
	require_once PCLM_DIR . 'includes/class-pclm-product.php';
	require_once PCLM_DIR . 'includes/class-pclm-visibility.php';
	require_once PCLM_DIR . 'includes/class-pclm-guard.php';
	require_once PCLM_DIR . 'includes/class-pclm-shortcode.php';
	require_once PCLM_DIR . 'includes/class-pclm-orders.php';

	PCLM_Product::init();
	PCLM_Visibility::init();
	PCLM_Guard::init();
	PCLM_Shortcode::init();
	PCLM_Orders::init();

	if ( is_admin() ) {
		require_once PCLM_DIR . 'includes/admin/class-pclm-admin.php';
		require_once PCLM_DIR . 'includes/admin/class-pclm-admin-publish.php';
		require_once PCLM_DIR . 'includes/admin/class-pclm-admin-report.php';
		require_once PCLM_DIR . 'includes/admin/class-pclm-admin-settings.php';
		require_once PCLM_DIR . 'includes/admin/class-pclm-admin-legacy.php';
		require_once PCLM_DIR . 'includes/admin/class-pclm-admin-product-field.php';

		PCLM_Admin::init();
		PCLM_Admin_Publish::init();
		PCLM_Admin_Report::init();
		PCLM_Admin_Settings::init();
		PCLM_Admin_Legacy::init();
		PCLM_Admin_Product_Field::init();
	}
}
add_action( 'plugins_loaded', 'pclm_bootstrap' );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PCLM_FILE, true );
		}
	}
);

function pclm_activate() {
	require_once PCLM_DIR . 'includes/class-pclm-settings.php';

	$settings = get_option( PCLM_Settings::OPTION, array() );

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
			update_option( PCLM_Settings::OPTION, $settings );
		}
	}
}
register_activation_hook( __FILE__, 'pclm_activate' );
