<?php
/**
 * Plugin Name: CHIP for FluentCart
 * Plugin URI: https://www.chip-in.asia
 * Description: Integrate CHIP payment gateway with FluentCart for seamless payment processing.
 * Version: 1.0.0
 * Author: CHIP IN SDN. BHD.
 * Author URI: https://www.chip-in.asia
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: chip-for-fluentcart
 * Domain Path: /languages
 * Reference: https://dev.fluentcart.com/modules/payment-methods
 */
// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}


/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'CHIP_FOR_FLUENTCART_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-chip-for-fluentcart-activator.php
 */
function activate_chip_for_fluentcart() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-chip-for-fluentcart-activator.php';
	Chip_For_Fluentcart_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-chip-for-fluentcart-deactivator.php
 */
function deactivate_chip_for_fluentcart() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-chip-for-fluentcart-deactivator.php';
	Chip_For_Fluentcart_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_chip_for_fluentcart' );
register_deactivation_hook( __FILE__, 'deactivate_chip_for_fluentcart' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-chip-for-fluentcart.php';

/**
 * Register CHIP payment gateway with FluentCart
 */
add_action('fluent_cart/init', function($app) {
	// Include CHIP payment gateway classes
	require_once plugin_dir_path( __FILE__ ) . 'includes/ChipLogger.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/ChipFluentCartApi.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/Chip.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/ChipHandler.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/ChipSettingsBase.php';
	
	// Register the gateway
	$gatewayManager = \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::getInstance();
	$gatewayManager->register('chip', new \FluentCart\App\Modules\PaymentMethods\Chip\Chip());
});

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_chip_for_fluentcart() {
	$plugin = new Chip_For_Fluentcart();
	$plugin->run();
}

run_chip_for_fluentcart();