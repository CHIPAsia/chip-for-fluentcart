<?php
/**
 * CHIP Settings Base for FluentCart
 *
 * @package    Chip_For_Fluentcart
 * @subpackage Chip_For_Fluentcart/includes
 */

namespace FluentCart\App\Modules\PaymentMethods\Chip;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ChipSettingsBase
 *
 * Handles CHIP payment gateway settings.
 *
 * @since 1.0.0
 */
class ChipSettingsBase extends BaseGatewaySettings {

	/**
	 * Settings array.
	 *
	 * @var array
	 */
	public $settings;

	/**
	 * Method handler identifier.
	 *
	 * @var string
	 */
	public $methodHandler = 'fluent_cart_payment_settings_chip'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Parent class uses camelCase.

	/**
	 * Get default settings.
	 *
	 * @since  1.0.0
	 * @return array Default settings array.
	 */
	public static function getDefaults(): array {
		return array(
			'is_active'                => 'no',
			'payment_mode'             => 'live',
			'brand_id'                 => '',
			'secret_key'               => '',
			'public_key'               => '',
			'payment_method_whitelist' => array(),
			'email_fallback'           => '',
			'debug'                    => 'no',
		);
	}

	/**
	 * Check if the payment gateway is active.
	 *
	 * @since  1.0.0
	 * @return bool True if active, false otherwise.
	 */
	public function isActive(): bool {
		return 'yes' === $this->settings['is_active'];
	}

	/**
	 * Get the payment mode.
	 *
	 * @since  1.0.0
	 * @return string Payment mode (live or test).
	 */
	public function getMode() {
		return $this->settings['payment_mode'] ?? 'live';
	}

	/**
	 * Get the API secret key.
	 *
	 * @since  1.0.0
	 * @return string API secret key.
	 */
	public function getApiKey() {
		return $this->settings['secret_key'] ?? '';
	}

	/**
	 * Get the Brand ID.
	 *
	 * @since  1.0.0
	 * @return string Brand ID.
	 */
	public function getBrandId() {
		return $this->settings['brand_id'] ?? '';
	}

	/**
	 * Get the payment method whitelist.
	 *
	 * @since  1.0.0
	 * @return array Payment method whitelist.
	 */
	public function getPaymentMethodWhitelist() {
		return $this->settings['payment_method_whitelist'] ?? array();
	}

	/**
	 * Get the email fallback.
	 *
	 * @since  1.0.0
	 * @return string Email fallback address.
	 */
	public function getEmailFallback() {
		return $this->settings['email_fallback'] ?? '';
	}

	/**
	 * Check if debug mode is enabled.
	 *
	 * @since  1.0.0
	 * @return bool True if debug enabled, false otherwise.
	 */
	public function isDebugEnabled() {
		return 'yes' === ( $this->settings['debug'] ?? 'no' );
	}

	/**
	 * Get settings or a specific setting value.
	 *
	 * @since  1.0.0
	 * @param  string $key Optional. Setting key to retrieve.
	 * @return mixed Settings array or specific setting value.
	 */
	public function get( $key = '' ) {
		$settings = $this->settings;

		if ( $key && isset( $this->settings[ $key ] ) ) {
			return $this->settings[ $key ];
		}
		return $settings;
	}
}

