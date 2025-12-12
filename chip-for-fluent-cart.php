<?php
/**
 * Plugin Name: CHIP for Fluent Cart
 * Plugin URI: https://www.chip-in.asia
 * Description: Integrate CHIP payment gateway with Fluent Cart for seamless payment processing.
 * Version: 1.0.0
 * Author: CHIP IN SDN. BHD.
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: chip-for-fluent-cart
 * Reference: https://dev.fluentcart.com/modules/payment-methods
 * Reference: https://dev.fluentcart.com/payment-methods-integration/quick-implementation.html#step-4-create-javascript-file-for-frontend-checkout
 *
 * Requires Plugins: fluent-cart
 *
 * @package Chip_For_Fluentcart
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Define plugin version.
 */
define( 'CHIP_FOR_FLUENTCART_VERSION', '1.0.0' );

/**
 * Register CHIP payment gateway with Fluent Cart.
 */
add_action(
	'fluent_cart/register_payment_methods',
	function () {

		if ( ! function_exists( 'fluent_cart_api' ) ) {
			return; // Fluent Cart not active.
		}

		// Include CHIP payment gateway classes.
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-chiplogger.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-chipfluentcartapi.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-chip.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-chipsettingsbase.php';

		// Create and register your custom gateway.
		$chip_gateway = new \FluentCart\App\Modules\PaymentMethods\Chip\Chip();
		fluent_cart_api()->registerCustomPaymentMethod( 'chip', $chip_gateway );
	}
);

/**
 * Register init redirect handler using init hook (not admin-ajax).
 * This needs to be in the main plugin file to avoid wp-admin access issues.
 */
add_action(
	'init',
	function () {
		if ( ! function_exists( 'fluent_cart_api' ) ) {
			return; // Fluent Cart not active.
		}

		// Get the CHIP payment gateway instance using GatewayManager.
		$chip_gateway = \FluentCart\App\Modules\PaymentMethods\Core\GatewayManager::getInstance( 'chip' );

		if ( $chip_gateway && method_exists( $chip_gateway, 'handleInitRedirect' ) ) {
			$chip_gateway->handleInitRedirect();
		}
	},
	100,
	0
);

/**
 * Add settings link on plugin page.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		if ( ! function_exists( 'fluent_cart_api' ) ) {
			return $links;
		}

		$settings_url  = \FluentCart\App\Services\URL::getDashboardUrl( 'settings/payments/chip' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'chip-for-fluent-cart' ) . '</a>';

		array_unshift( $links, $settings_link );

		return $links;
	}
);

/**
 * Enqueue admin script to replace CHIP text with logo in FluentCart settings.
 */
add_action(
	'admin_enqueue_scripts',
	function () {
		// Only load on FluentCart admin pages.
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'fluent-cart' ) ) {
			return;
		}

		wp_enqueue_script(
			'chip-admin',
			plugin_dir_url( __FILE__ ) . 'assets/admin.js',
			array(),
			CHIP_FOR_FLUENTCART_VERSION,
			true
		);

		wp_localize_script(
			'chip-admin',
			'chipAdminData',
			array(
				'logoUrl' => plugin_dir_url( __FILE__ ) . 'assets/logo.png',
			)
		);
	}
);
