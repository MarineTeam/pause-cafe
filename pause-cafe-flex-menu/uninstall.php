<?php
/**
 * Removes the plugin's own settings on uninstall.
 *
 * Scheduling dates stay on the products and schedules stay as terms. They are
 * the only record of which week a dish belonged to, and deleting them would
 * make past orders impossible to attribute. Products, orders, stock levels and
 * wallet balances are never touched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'pcfm_settings' );
delete_option( 'pcfm_blackouts' );
delete_transient( 'pcfm_admin_notices' );
