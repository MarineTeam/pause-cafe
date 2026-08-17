<?php
/**
 * Plugin Name:       Pause Cafe Menu
 * Description:       Weekly lunch menu scheduling for WooCommerce. One service date per dish; visibility, ordering cutoff and the kitchen report all derive from it.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Pause Cafe
 * Text Domain:       pause-cafe-menu
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read from the header above rather than written out again here.
 *
 * These two had already drifted -- the header said 1.0.1 while the constant
 * said 1.0.0 -- and the constant is what every stylesheet and script is cache-
 * busted with, so browsers were being told nothing had changed when it had.
 * WordPress reads the header for updates and the plugins screen, which makes it
 * the one that has to be right, so it is the one to derive from.
 *
 * get_file_data() lives in wp-includes and is loaded well before plugins, so it
 * is safe here. It costs one read of the first 8 KB of this file per request,
 * which is a fair price for a number that cannot be wrong.
 */
define(
	'PCM_VERSION',
	function_exists( 'get_file_data' )
		? ( get_file_data( __FILE__, array( 'version' => 'Version' ) )['version'] ?: '0.0.0' )
		: '0.0.0'
);

define( 'PCM_FILE', __FILE__ );
define( 'PCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCM_URL', plugin_dir_url( __FILE__ ) );

/**
 * The plugin does nothing at all unless WooCommerce is active. Failing loud and
 * early is better than half-registering hooks against a missing WC_Product.
 */
function pcm_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p><strong>Pause Cafe Menu</strong> needs WooCommerce to be installed and active.</p></div>';
}

function pcm_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'pcm_woocommerce_missing_notice' );
		return;
	}

	require_once PCM_DIR . 'includes/class-pcm-settings.php';
	require_once PCM_DIR . 'includes/class-pcm-schedule.php';
	require_once PCM_DIR . 'includes/class-pcm-product.php';
	require_once PCM_DIR . 'includes/class-pcm-visibility.php';
	require_once PCM_DIR . 'includes/class-pcm-guard.php';
	require_once PCM_DIR . 'includes/class-pcm-shortcode.php';
	require_once PCM_DIR . 'includes/class-pcm-orders.php';

	PCM_Visibility::init();
	PCM_Guard::init();
	PCM_Shortcode::init();
	PCM_Orders::init();

	if ( is_admin() ) {
		require_once PCM_DIR . 'includes/admin/class-pcm-admin.php';
		require_once PCM_DIR . 'includes/admin/class-pcm-admin-builder.php';
		require_once PCM_DIR . 'includes/admin/class-pcm-admin-report.php';
		require_once PCM_DIR . 'includes/admin/class-pcm-admin-settings.php';
		require_once PCM_DIR . 'includes/admin/class-pcm-admin-legacy.php';
		require_once PCM_DIR . 'includes/admin/class-pcm-admin-product-field.php';

		PCM_Admin::init();
		PCM_Admin_Builder::init();
		PCM_Admin_Report::init();
		PCM_Admin_Settings::init();
		PCM_Admin_Legacy::init();
		PCM_Admin_Product_Field::init();
	}
}
add_action( 'plugins_loaded', 'pcm_bootstrap' );

/**
 * Orders live in WooCommerce's own tables under HPOS. Nothing here touches the
 * order schema directly, so declare compatibility rather than forcing the site
 * back onto legacy post storage.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PCM_FILE, true );
		}
	}
);

/**
 * On first activation, try to map the pickup locations from existing product
 * categories so the settings screen opens pre-filled instead of empty.
 */
function pcm_activate() {
	require_once PCM_DIR . 'includes/class-pcm-settings.php';

	$settings = get_option( PCM_Settings::OPTION, array() );

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
			update_option( PCM_Settings::OPTION, $settings );
		}
	}
}
register_activation_hook( __FILE__, 'pcm_activate' );
