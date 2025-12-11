<?php
/**
 * CHIP Logger for FluentCart
 *
 * @package    Chip_For_Fluentcart
 * @subpackage Chip_For_Fluentcart/includes
 */

namespace FluentCart\App\Modules\PaymentMethods\Chip;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ChipLogger
 *
 * Handles logging for the CHIP payment gateway.
 *
 * @since 1.0.0
 */
class ChipLogger {

	/**
	 * Log a message.
	 *
	 * @since  1.0.0
	 * @param  string $message    Log message.
	 * @param  string $log_status Log status (info, error, etc.).
	 * @param  array  $other_info Additional information.
	 * @return void
	 */
	public function log( $message, $log_status = 'info', $other_info = array() ) {
		if ( function_exists( 'fluent_cart_add_log' ) ) {
			fluent_cart_add_log(
				'CHIP for FluentCart',
				$message,
				$log_status,
				$other_info
			);
		}
	}
}
