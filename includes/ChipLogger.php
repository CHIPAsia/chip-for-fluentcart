<?php

namespace FluentCart\App\Modules\PaymentMethods\Chip;

class ChipLogger {
	
	public function log( $message, $logStatus = 'info', $otherInfo = [] ) {
		if ( function_exists( 'fluent_cart_add_log' ) ) {
			fluent_cart_add_log(
				'CHIP for FluentCart',
				$message,
				$logStatus,
				$otherInfo
			);
		}
	}
}

