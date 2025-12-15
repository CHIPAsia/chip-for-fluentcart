<?php
/**
 * CHIP API wrapper for FluentCart
 *
 * @package    Chip_For_Fluentcart
 * @subpackage Chip_For_Fluentcart/includes
 */

namespace FluentCart\App\Modules\PaymentMethods\Chip;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This is CHIP API URL Endpoint as per documented in: https://docs.chip-in.asia.
if ( ! defined( 'CHIP_FOR_FLUENTCART_ROOT_URL' ) ) {
	define( 'CHIP_FOR_FLUENTCART_ROOT_URL', 'https://gate.chip-in.asia/api' );
}

/**
 * Class ChipFluentCartApi
 *
 * API wrapper for CHIP payment gateway.
 *
 * @since 1.0.0
 */
class ChipFluentCartApi {

	/**
	 * API secret key.
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Brand ID.
	 *
	 * @var string
	 */
	public $brand_id;

	/**
	 * Logger instance.
	 *
	 * @var ChipLogger
	 */
	public $logger;

	/**
	 * Debug mode flag.
	 *
	 * @var string
	 */
	public $debug;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string     $secret_key API secret key.
	 * @param string     $brand_id   Brand ID.
	 * @param ChipLogger $logger     Logger instance.
	 * @param string     $debug      Debug mode (yes/no).
	 */
	public function __construct( $secret_key, $brand_id, $logger, $debug ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
		$this->logger     = $logger;
		$this->debug      = $debug;
	}

	/**
	 * Set API credentials.
	 *
	 * @since 1.0.0
	 * @param string $secret_key API secret key.
	 * @param string $brand_id   Brand ID.
	 * @return void
	 */
	public function set_key( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id   = $brand_id;
	}

	/**
	 * Create a payment.
	 *
	 * @since 1.0.0
	 * @param array $params Payment parameters.
	 * @return array|null API response or null on error.
	 */
	public function create_payment( $params ) {
		$this->log_info( 'creating purchase' );
		return $this->call( 'POST', '/purchases/?time=' . time(), $params );
	}

	/**
	 * Get payment details.
	 *
	 * @since 1.0.0
	 * @param string $payment_id Payment ID.
	 * @return array|null API response or null on error.
	 */
	public function get_payment( $payment_id ) {
		$this->log_info( sprintf( 'get payment: %s', $payment_id ) );
		// time() is to force fresh instead of cache.
		$result = $this->call( 'GET', "/purchases/{$payment_id}/?time=" . time() );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when enabled.
		$this->log_info( sprintf( 'success check result: %s', print_r( $result, true ) ) );
		return $result;
	}

	/**
	 * Refund a payment.
	 *
	 * @since 1.0.0
	 * @param string $payment_id Payment ID.
	 * @param array  $params     Refund parameters.
	 * @return array|null API response or null on error.
	 */
	public function refund_payment( $payment_id, $params ) {
		$this->log_info( sprintf( 'refunding payment: %s', $payment_id ) );
		$result = $this->call( 'POST', "/purchases/{$payment_id}/refund/", $params );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when enabled.
		$this->log_info( sprintf( 'payment refund result: %s', print_r( $result, true ) ) );
		return $result;
	}

	/**
	 * Get public key for signature verification.
	 *
	 * @since 1.0.0
	 * @return string|null Public key or null on error.
	 */
	public function public_key() {
		$this->log_info( 'getting public key' );
		$result = $this->call( 'GET', '/public_key/' );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when enabled.
		$this->log_info( sprintf( 'public key: %s', print_r( $result, true ) ) );
		return $result;
	}

	/**
	 * Make API call.
	 *
	 * @since 1.0.0
	 * @param string $method HTTP method.
	 * @param string $route  API route.
	 * @param array  $params Request parameters.
	 * @return array|null API response or null on error.
	 */
	private function call( $method, $route, $params = array() ) {
		$secret_key = $this->secret_key;

		if ( ! empty( $params ) ) {
			$params = wp_json_encode( $params );
		}

		$response = $this->request(
			$method,
			sprintf( '%s/v1%s', CHIP_FOR_FLUENTCART_ROOT_URL, $route ),
			$params,
			array(
				'Content-type'  => 'application/json',
				'Authorization' => "Bearer {$secret_key}",
			)
		);

		$this->log_info( sprintf( 'received response: %s', $response ) );

		$result = json_decode( $response, true );

		if ( ! $result ) {
			$this->log_error( 'JSON parsing error/NULL API response' );
			return null;
		}

		if ( ! empty( $result['errors'] ) ) {
			$this->log_error( 'API error', $result['errors'] );
			return null;
		}

		return $result;
	}

	/**
	 * Make HTTP request.
	 *
	 * @since 1.0.0
	 * @param string $method  HTTP method.
	 * @param string $url     Request URL.
	 * @param mixed  $params  Request parameters.
	 * @param array  $headers Request headers.
	 * @return string Response body.
	 */
	private function request( $method, $url, $params = array(), $headers = array() ) {
		$this->log_info(
			sprintf(
				'%s `%s`\n%s\n%s',
				$method,
				$url,
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when enabled.
				print_r( $params, true ),
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug logging only when enabled.
				print_r( $headers, true )
			)
		);

		$wp_request = wp_remote_request(
			$url,
			array(
				'method'    => $method,
				'sslverify' => ! defined( 'CHIP_FOR_FLUENTCART_SSLVERIFY_FALSE' ),
				'headers'   => $headers,
				'body'      => $params,
				'timeout'   => 10, // Charge card requires longer timeout.
			)
		);

		$response = wp_remote_retrieve_body( $wp_request );
		$code     = wp_remote_retrieve_response_code( $wp_request );

		if ( 200 !== $code && 201 !== $code ) {
			$this->log_error(
				sprintf( '%s %s: %d', $method, $url, $code ),
				$response
			);
		}

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'wp_remote_request', $response->get_error_message() );
		}

		return $response;
	}

	/**
	 * Log info message.
	 *
	 * @since 1.0.0
	 * @param string $text Log message.
	 * @return void
	 */
	public function log_info( $text ) {
		if ( 'yes' === $this->debug ) {
			$this->logger->log( $text, 'info' );
		}
	}

	/**
	 * Log error message.
	 *
	 * @since 1.0.0
	 * @param string $error_text Error message.
	 * @param mixed  $error_data Error data.
	 * @return void
	 */
	public function log_error( $error_text, $error_data = null ) {
		if ( 'yes' !== $this->debug ) {
			return;
		}
		$other_info = array();
		if ( $error_data ) {
			$other_info['error_data'] = $error_data;
		}
		$this->logger->log( $error_text, 'error', $other_info );
	}
}

