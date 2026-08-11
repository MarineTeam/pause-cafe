<?php
/**
 * Removes the plugin's own settings on uninstall.
 *
 * Service dates stay on the products. They are the only record of which week a
 * dish belonged to, and deleting them would make every past order impossible to
 * attribute. Products, orders and wallet balances are never touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pcm_settings' );
delete_transient( 'pcm_admin_notices' );
