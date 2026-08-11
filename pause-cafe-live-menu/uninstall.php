<?php
/**
 * Removes the plugin's own settings on uninstall.
 *
 * The opening stamps stay on the products. They are the only record of which
 * week a dish belonged to, and deleting them would make past orders impossible
 * to attribute. Products, orders and wallet balances are never touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pclm_settings' );
delete_transient( 'pclm_admin_notices' );
