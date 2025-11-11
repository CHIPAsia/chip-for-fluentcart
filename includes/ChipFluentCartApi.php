<?php

namespace FluentCart\App\Modules\PaymentMethods\Chip;

// This is CHIP API URL Endpoint as per documented in: https://docs.chip-in.asia
if ( ! defined( 'CHIP_FOR_FLUENTCART_ROOT_URL' ) ) {
	define( 'CHIP_FOR_FLUENTCART_ROOT_URL', 'https://gate.chip-in.asia/api' );
}

class ChipFluentCartApi {

	public $secret_key;

	public $brand_id;

	public $logger;

	public $debug;

	public function __construct( $secret_key, $brand_id, $logger, $debug ) {
		$this->secret_key = $secret_key;
		$this->brand_id = $brand_id;
		$this->logger = $logger;
		$this->debug = $debug;
	}

	public function set_key( $secret_key, $brand_id ) {
		$this->secret_key = $secret_key;
		$this->brand_id = $brand_id;
	}

	public function create_payment( $params ) {
		$this->log_info( 'creating purchase' );
		return $this->call( 'POST', '/purchases/?time=' . time(), $params );
	}

	public function create_client( $params ) {
		$this->log_info( 'creating client' );
		return $this->call( 'POST', '/clients/', $params );
	}

	public function get_client_by_email( $email ) {
		$this->log_info( "get client by email: {$email}" );
		$email_encoded = urlencode( $email );
		return $this->call( 'GET', "/clients/?q={$email_encoded}" );
	}

	public function patch_client( $client_id, $params ) {
		$this->log_info( "patch client: {$client_id}" );
		return $this->call( 'PATCH', "/clients/{$client_id}/", $params );
	}

	public function delete_token( $purchase_id ) {
		$this->log_info( "delete token: {$purchase_id}" );
		return $this->call( 'POST', "/purchases/$purchase_id/delete_recurring_token/" );
	}

	public function capture_payment( $payment_id, $params = [] ) {
		$this->log_info( "capture payment: {$payment_id}" );
		return $this->call( 'POST', "/purchases/{$payment_id}/capture/", $params );
	}

	public function release_payment( $payment_id ) {
		$this->log_info( "release payment: {$payment_id}" );
		return $this->call( 'POST', "/purchases/{$payment_id}/release/" );
	}

	public function charge_payment( $payment_id, $params ) {
		$this->log_info( "charge payment: {$payment_id}" );
		return $this->call( 'POST', "/purchases/{$payment_id}/charge/", $params );
	}

	public function payment_methods( $currency, $language, $amount ) {
		$this->log_info( 'fetching payment methods' );
		return $this->call(
			'GET',
			"/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}&amount={$amount}"
		);
	}

	public function payment_recurring_methods( $currency, $language, $amount ) {
		$this->log_info( 'fetching payment methods' );
		return $this->call(
			'GET',
			"/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}&amount={$amount}&recurring=true"
		);
	}

	public function get_payment( $payment_id ) {
		$this->log_info( sprintf( "get payment: %s", $payment_id ) );
		// time() is to force fresh instead cache
		$result = $this->call( 'GET', "/purchases/{$payment_id}/?time=" . time() );
		$this->log_info( sprintf( 'success check result: %s', var_export( $result, true ) ) );
		return $result;
	}

	public function refund_payment( $payment_id, $params ) {
		$this->log_info( sprintf( "refunding payment: %s", $payment_id ) );
		$result = $this->call( 'POST', "/purchases/{$payment_id}/refund/", $params );
		$this->log_info( sprintf( "payment refund result: %s", var_export( $result, true ) ) );
		return $result;
	}

	public function public_key() {
		$this->log_info( 'getting public key' );
		$result = $this->call( 'GET', '/public_key/' );
		$this->log_info( sprintf( 'public key: %s', var_export( $result, true ) ) );
		return $result;
	}

	public function turnover() {
		$this->log_info( 'getting company turnover' );
		$result = $this->call( 'GET', '/account/json/turnover/?currency=MYR' );
		return $result;
	}

	public function balance() {
		$this->log_info( 'getting company balance' );
		$result = $this->call( 'GET', '/account/json/balance/?currency=MYR' );
		return $result;
	}

	private function call( $method, $route, $params = [] ) {
		$secret_key = $this->secret_key;

		if ( ! empty( $params ) ) {
			$params = json_encode( $params );
		}

		$response = $this->request(
			$method,
			sprintf( '%s/v1%s', CHIP_FOR_FLUENTCART_ROOT_URL, $route ),
			$params,
			[ 
				'Content-type' => 'application/json',
				'Authorization' => "Bearer {$secret_key}",
			]
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

	private function request( $method, $url, $params = [], $headers = [] ) {
		$this->log_info( sprintf(
			'%s `%s`\n%s\n%s',
			$method,
			$url,
			var_export( $params, true ),
			var_export( $headers, true )
		) );

		$wp_request = wp_remote_request( $url, array(
			'method' => $method,
			'sslverify' => ! defined( 'CHIP_FOR_FLUENTCART_SSLVERIFY_FALSE' ),
			'headers' => $headers,
			'body' => $params,
			'timeout' => 10, // charge card require longer timeout
		) );

		$response = wp_remote_retrieve_body( $wp_request );

		switch ( $code = wp_remote_retrieve_response_code( $wp_request ) ) {
			case 200:
			case 201:
				break;
			default:
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

	public function log_info( $text ) {
		if ( $this->debug === 'yes' ) {
			$this->logger->log( $text, 'info' );
		}
	}

	public function log_error( $error_text, $error_data = null ) {
		if ( $this->debug !== 'yes' ) {
			return;
		}
		$otherInfo = [];
		if ( $error_data ) {
			$otherInfo['error_data'] = $error_data;
		}
		$this->logger->log( $error_text, 'error', $otherInfo );
	}
}

