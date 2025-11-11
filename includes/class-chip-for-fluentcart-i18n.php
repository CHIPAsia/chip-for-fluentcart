<?php
/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       https://www.chip-in.asia
 * @since      1.0.0
 *
 * @package    Chip_For_Fluentcart
 * @subpackage Chip_For_Fluentcart/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Chip_For_Fluentcart
 * @subpackage Chip_For_Fluentcart/includes
 * @author     CHIP IN SDN. BHD. <support@chip-in.asia>
 */
class Chip_For_Fluentcart_i18n {

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'chip-for-fluentcart',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);
	}
}

