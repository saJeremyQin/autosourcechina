<?php
/**
 * Plugin Name:       ASC Quick Publish
 * Description:       One-form publishing for auto parts: builds a draft variable product with fitment attributes, condition variations and descriptions.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            AutoSourceChina
 * Text Domain:       asc-quick-publish
 */

defined( 'ABSPATH' ) || exit;

define( 'ASC_QP_VERSION', '0.1.0' );
define( 'ASC_QP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASC_QP_URL', plugin_dir_url( __FILE__ ) );

require_once ASC_QP_DIR . 'includes/class-asc-qp-config.php';
require_once ASC_QP_DIR . 'includes/class-asc-qp-builder.php';
require_once ASC_QP_DIR . 'includes/class-asc-qp-rest.php';
require_once ASC_QP_DIR . 'includes/class-asc-qp-admin.php';

// Create the global attributes (Make, Model, Year, Part Condition) if this
// site does not have them yet. Existing ones are left untouched.
register_activation_hook( __FILE__, array( 'ASC_QP_Config', 'ensure_attributes' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		ASC_QP_Admin::init();
		ASC_QP_Rest::init();
	}
);
