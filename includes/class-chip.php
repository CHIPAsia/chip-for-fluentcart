<?php
/**
 * CHIP Payment Gateway for FluentCart
 *
 * @package    Chip_For_Fluentcart
 * @subpackage Chip_For_Fluentcart/includes
 */

namespace FluentCart\App\Modules\PaymentMethods\Chip;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

/**
 * Class Chip
 *
 * CHIP payment gateway integration for FluentCart.
 *
 * @since 1.0.0
 */
class Chip extends AbstractPaymentGateway {

	/**
	 * Supported features for the payment gateway.
	 *
	 * @var array
	 */
	public array $supportedFeatures = array( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Parent class uses camelCase.
		'payment',
		'refund',
		'webhook',
	);

	/**
	 * Settings instance.
	 *
	 * @var BaseGatewaySettings
	 */
	public BaseGatewaySettings $settings;

	/**
	 * Redirect key for payment callbacks.
	 */
	const REDIRECT_KEY = 'chip-for-fluent-cart-redirect';

	/**
	 * Option key for redirect passphrase.
	 */
	const REDIRECT_PASSPHRASE_OPTION = 'chip-for-fluent-cart-redirect-passphrase';

	/**
	 * Option key for public key storage.
	 */
	const PUBLIC_KEY_OPTION = 'chip-for-fluent-cart-public-key';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct( new ChipSettingsBase() );
	}

	/**
	 * Boot the plugin
	 *
	 * @since    1.0.0
	 */
	public function boot() {
		add_action( 'fluent_cart/payment_paid', array( $this, 'handlePaymentPaid' ), 10, 1 );

		// Register settings filter.
		add_filter( 'fluent_cart/payment_methods/chip_settings', array( $this, 'getSettings' ) );

		// Register transaction receipt URL filter.
		add_filter( 'fluent_cart/transaction/url_chip', array( $this, 'getTransactionReceiptUrl' ), 10, 2 );
	}

	/**
	 * Get CHIP transaction receipt URL
	 *
	 * @since    1.0.0
	 * @param    string $url  Default URL (empty).
	 * @param    array  $data Transaction data containing vendor_charge_id (purchase_id).
	 * @return   string       CHIP receipt URL.
	 */
	public function getTransactionReceiptUrl( $url, $data ) {
		// Refund transactions don't have a CHIP receipt.
		if ( 'refund' === ( $data['transaction_type'] ?? '' ) ) {
			return $url;
		}

		$purchase_id = $data['vendor_charge_id'] ?? '';

		if ( empty( $purchase_id ) ) {
			return $url;
		}

		return 'https://gate.chip-in.asia/p/' . sanitize_text_field( $purchase_id ) . '/receipt/';
	}

	/**
	 * Get the plugin meta
	 *
	 * @since    1.0.0
	 * @return   array                     Plugin meta
	 */
	public function meta(): array {
		return array(
			'title'              => __( 'CHIP', 'chip-for-fluent-cart' ),
			'route'              => 'chip',
			'slug'               => 'chip',
			'description'        => esc_html__( 'CHIP - Pay securely with CHIP Collect. Accept FPX, Cards, E-Wallet, Duitnow QR.', 'chip-for-fluent-cart' ),
			'logo'               => plugin_dir_url( __DIR__ ) . 'assets/logo-checkout.png',
			'icon'               => plugin_dir_url( __DIR__ ) . 'assets/logo.png',
			'brand_color'        => '#136196',
			'upcoming'           => false,
			'status'             => 'yes' === $this->settings->get( 'is_active' ),
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Parent class uses camelCase.
			'supported_features' => $this->supportedFeatures,
		);
	}

	/**
	 * Make payment from payment instance
	 *
	 * @since    1.0.0
	 * @param    PaymentInstance $payment_instance Payment instance.
	 * @return   array Result array with success, message, redirect_to, payment_id, or error_message.
	 */
	public function makePaymentFromPaymentInstance( PaymentInstance $payment_instance ) {
		try {
			$order       = $payment_instance->order;
			$customer    = $order->customer;
			$transaction = $payment_instance->transaction;

			// Get order items from order_items relation.
			$order_items = array();
			if ( isset( $order->order_items ) && count( $order->order_items ) > 0 ) {
				$order_items = $order->order_items;
			}

			// Get customer full name.
			$customer_full_name = trim( $customer->first_name . ' ' . $customer->last_name );

			// Get customer phone from billing or shipping address meta.
			$customer_phone = '';
			if ( isset( $order->billing_address->meta ) ) {
				$billing_meta = is_string( $order->billing_address->meta )
					? json_decode( $order->billing_address->meta, true )
					: $order->billing_address->meta;
				if ( ! empty( $billing_meta['other_data']['phone'] ) ) {
					$customer_phone = $billing_meta['other_data']['phone'];
				}
			}
			if ( empty( $customer_phone ) && isset( $order->shipping_address->meta ) ) {
				$shipping_meta = is_string( $order->shipping_address->meta )
					? json_decode( $order->shipping_address->meta, true )
					: $order->shipping_address->meta;
				if ( ! empty( $shipping_meta['other_data']['phone'] ) ) {
					$customer_phone = $shipping_meta['other_data']['phone'];
				}
			}

			// Get billing address data.
			$billing_address = $order->billing_address;
			$billing_street  = '';
			if ( $billing_address ) {
				$billing_street = trim( ( $billing_address->address_1 ?? '' ) . ' ' . ( $billing_address->address_2 ?? '' ) );
			}

			// Get shipping address data.
			$shipping_address = $order->shipping_address;
			$shipping_street  = '';
			if ( $shipping_address ) {
				$shipping_street = trim( ( $shipping_address->address_1 ?? '' ) . ' ' . ( $shipping_address->address_2 ?? '' ) );
			}

			// Prepare payment data.
			$payment_data = array(
				'amount'                  => $transaction->total,
				'currency'                => $transaction->currency,
				'order_id'                => $order->uuid,
				'customer_email'          => $customer->email,
				'customer_full_name'      => $customer_full_name,
				'customer_phone'          => $customer_phone,
				'customer_personal_code'  => $customer->id,
				'customer_notes'          => $order->note ?? '',
				// Billing address.
				'billing_street_address'  => $billing_street,
				'billing_country'         => $billing_address->country ?? '',
				'billing_city'            => $billing_address->city ?? '',
				'billing_zip_code'        => $billing_address->postcode ?? '',
				'billing_state'           => $billing_address->state ?? '',
				// Shipping address.
				'shipping_street_address' => $shipping_street,
				'shipping_country'        => $shipping_address->country ?? '',
				'shipping_city'           => $shipping_address->city ?? '',
				'shipping_zip_code'       => $shipping_address->postcode ?? '',
				'shipping_state'          => $shipping_address->state ?? '',
				// Other data.
				'return_url'              => $this->getReturnUrl( $transaction ),
				'order_items'             => $order_items,
				'cancel_url'              => self::getCancelUrl( $transaction ),
				'transaction_uuid'        => $transaction->uuid,
				'shipping_total'          => $order->shipping_total ?? 0,
			);

			// Process payment with gateway API.
			$result = $this->processPayment( $payment_data );

			if ( $result['success'] ) {
				// Store purchase ID (payment_id) in transaction metadata for later reference.
				if ( ! empty( $result['payment_id'] ) ) {
					$this->storePurchaseId( $transaction, $result['payment_id'] );
				}

				return array(
					'status'      => 'success',
					'message'     => __( 'Order has been placed successfully', 'chip-for-fluent-cart' ),
					'redirect_to' => $result['redirect_url'],
					'payment_id'  => $result['payment_id'] ?? null,
				);
			}

			return array(
				'status'  => 'failed',
				'message' => $result['error_message'] ?? __( 'Payment processing failed', 'chip-for-fluent-cart' ),
			);

		} catch ( \Exception $e ) {
			return array(
				'status'  => 'failed',
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Get return URL for successful payment.
	 *
	 * @since  1.0.0
	 * @param  object $transaction Transaction object.
	 * @return string              Return URL.
	 */
	protected function getReturnUrl( $transaction ) {
		$payment_helper = new \FluentCart\App\Services\Payments\PaymentHelper( 'chip' );
		return $payment_helper->successUrl( $transaction->uuid );
	}

	/**
	 * Get init URL for payment status check.
	 * Uses a custom endpoint with passphrase for security (not admin-ajax).
	 *
	 * @since  1.0.0
	 * @param  object $transaction Transaction object.
	 * @return string              Init URL.
	 */
	protected function getInitUrl( $transaction ) {
		// Get or generate passphrase for security.
		$passphrase = get_option( self::REDIRECT_PASSPHRASE_OPTION, false );
		if ( ! $passphrase ) {
			$passphrase = md5( site_url() . time() . wp_salt() );
			update_option( self::REDIRECT_PASSPHRASE_OPTION, $passphrase );
		}

		$params = array(
			self::REDIRECT_KEY => $passphrase,
			'transaction_uuid' => $transaction->uuid,
		);

		return add_query_arg( $params, site_url( '/' ) );
	}

	/**
	 * Handle init redirect - check order status and redirect accordingly.
	 * Uses init hook instead of admin-ajax to avoid wp-admin access issues.
	 *
	 * @since  1.0.0
	 * @return void
	 *
	 * @throws \Exception If an error occurs during processing.
	 */
	public function handleInitRedirect() {
		// Check if this is a redirect request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a redirect callback from payment gateway.
		if ( ! isset( $_GET[ self::REDIRECT_KEY ] ) ) {
			return;
		}

		// Verify passphrase.
		$passphrase = get_option( self::REDIRECT_PASSPHRASE_OPTION, false );
		if ( ! $passphrase ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a redirect callback from payment gateway.
		if ( sanitize_text_field( wp_unslash( $_GET[ self::REDIRECT_KEY ] ) ) !== $passphrase ) {
			status_header( 403 );
			exit( esc_html__( 'Invalid redirect passphrase', 'chip-for-fluent-cart' ) );
		}

		// Get transaction UUID from URL.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a redirect callback from payment gateway.
		$transaction_uuid = isset( $_GET['transaction_uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_uuid'] ) ) : '';

		if ( empty( $transaction_uuid ) ) {
			status_header( 400 );
			exit( esc_html__( 'Invalid transaction UUID', 'chip-for-fluent-cart' ) );
		}

		// Get order transaction by UUID using OrderTransaction model.
		$order_transaction = OrderTransaction::where( 'uuid', $transaction_uuid )->first();

		if ( ! $order_transaction ) {
			status_header( 404 );
			exit( esc_html__( 'Transaction not found', 'chip-for-fluent-cart' ) );
		}

		// Get order from transaction.
		$order = OrderResource::getQuery()->find( $order_transaction->order_id );

		if ( ! $order ) {
			status_header( 404 );
			exit( esc_html__( 'Order not found', 'chip-for-fluent-cart' ) );
		}

		// Acquire lock to prevent race condition with processWebhook.
		$this->acquireLock( $order->id );

		try {
			// Refresh order data to get latest status (in case webhook updated it).
			$order = OrderResource::getQuery()->find( $order_transaction->order_id );

			// Check if order status indicates payment is completed.
			// Order is considered paid if status is PROCESSING or COMPLETED.
			$paid_statuses = array( Status::ORDER_PROCESSING, Status::ORDER_COMPLETED );
			$is_paid       = in_array( $order->status, $paid_statuses, true );

			if ( $is_paid ) {
				// Order has been paid (possibly updated by webhook), redirect to success URL.
				$return_url = $this->getReturnUrl( $order_transaction );
				$this->releaseLock( $order->id );
				wp_safe_redirect( $return_url );
				exit;
			} else {
				// Order has not been paid, check API one-time to get latest payment status.
				$purchase_id = $this->getPurchaseId( $order );

				if ( $purchase_id ) {
					// Call API to check latest payment status.
					$payment_data = $this->checkPaymentStatus( $purchase_id );

					if ( $payment_data && 'paid' === $payment_data['status'] ) {
						// Payment is actually paid, sync order status.
						$order_transaction->fill(
							array(
								'status'              => Status::TRANSACTION_SUCCEEDED,
								'chip_purchase_id'    => $purchase_id,
								'vendor_charge_id'    => $purchase_id,
								'payment_method_type' => $this->mapPaymentMethodType( $payment_data['payment_method_type'], $payment_data['transaction_data'] ?? array() ),
							)
						);
						$order_transaction->save();
						( new StatusHelper( $order ) )->syncOrderStatuses( $order_transaction );

						// Redirect to success URL.
						$return_url = $this->getReturnUrl( $order_transaction );
						$this->releaseLock( $order->id );
						wp_safe_redirect( $return_url );
						exit;
					}
				}

				// Payment is not paid, redirect to cancel URL.
				$cancel_url = self::getCancelUrl( $order_transaction );
				$this->releaseLock( $order->id );
				wp_safe_redirect( $cancel_url );
				exit;
			}
		} catch ( \Exception $e ) {
			// Release lock on exception.
			$this->releaseLock( $order->id );
			throw $e;
		}
	}

	/**
	 * Process payment with CHIP API.
	 *
	 * @since  1.0.0
	 * @param  array $payment_data Payment data.
	 * @return array               Result array with success, redirect_url, payment_id, or error_message.
	 */
	protected function processPayment( $payment_data ) {
		try {
			// Validate settings.
			$settings = $this->settings->get();
			if ( 'yes' !== $settings['is_active'] ) {
				return array(
					'success'       => false,
					'error_message' => __( 'CHIP payment is not activated', 'chip-for-fluent-cart' ),
				);
			}

			// Validate required settings.
			$secret_key = $settings['secret_key'] ?? '';
			$brand_id   = $settings['brand_id'] ?? '';

			if ( empty( $secret_key ) || empty( $brand_id ) ) {
				return array(
					'success'       => false,
					'error_message' => __( 'CHIP API credentials are not configured', 'chip-for-fluent-cart' ),
				);
			}

			// Initialize logger and API.
			$logger   = new ChipLogger();
			$debug    = $this->settings->isDebugEnabled() ? 'yes' : 'no';
			$chip_api = new ChipFluentCartApi( $secret_key, $brand_id, $logger, $debug );

			// Build products array from order items.
			$products    = array();
			$order_items = $payment_data['order_items'] ?? array();

			// Handle both arrays and Collection objects (is_iterable covers both).
			if ( ! empty( $order_items ) && is_iterable( $order_items ) ) {
				foreach ( $order_items as $item ) {
					$product_name  = '';
					$product_price = 0;
					$product_qty   = 1;

					// Handle OrderItem model objects (values already in cents from FluentCart).
					$product_name  = $item->post_title ?? '';
					$product_price = (int) ( $item->line_total ?? 0 );
					$product_qty   = (int) ( $item->quantity ?? 1 );

					if ( ! empty( $product_name ) ) {
						$products[] = array(
							'name'  => $product_name,
							'price' => $product_price,
							'qty'   => $product_qty,
						);
					}
				}
			}

			// Add shipping fee if greater than 0.
			$shipping_total = (int) ( $payment_data['shipping_total'] ?? 0 );
			if ( $shipping_total > 0 ) {
				$products[] = array(
					'name'  => __( 'Shipping Fee', 'chip-for-fluent-cart' ),
					'price' => $shipping_total,
					'qty'   => 1,
				);
			}

			// If no products found, create a default product entry (amount already in cents).
			if ( empty( $products ) ) {
				$products[] = array(
					'name'  => __( 'Order', 'chip-for-fluent-cart' ) . ' #' . $payment_data['order_id'],
					'price' => (int) $payment_data['amount'],
					'qty'   => 1,
				);
			}

			// Get init URL for status check before redirecting.
			// This will redirect to a temporary page that checks order status.
			// before redirecting to the final success or cancel URL.
			$transaction_uuid = $payment_data['transaction_uuid'] ?? $payment_data['order_id'];
			$init_url         = $this->getInitUrl( (object) array( 'uuid' => $transaction_uuid ) );

			// Get callback URL using FluentCart's IPN listener.
			$callback_url = add_query_arg(
				array(
					'fluent-cart' => 'fct_payment_listener_ipn',
					'method'      => 'chip',
				),
				site_url( '/' )
			);

			// Prepare CHIP API parameters (amount already in cents from FluentCart).
			$chip_params = array(
				'brand_id'         => $brand_id,
				'reference'        => $payment_data['order_id'],
				'success_redirect' => $init_url,
				'success_callback' => $callback_url,
				'failure_redirect' => $payment_data['cancel_url'],
				'cancel_redirect'  => $payment_data['cancel_url'],
				'send_receipt'     => false,
				'creator_agent'    => 'FluentCart v' . FLUENTCART_VERSION,
				// TODO: Add platform.
				// 'platform' => 'fluentcart'.
				'purchase'         => array(
					'currency' => $payment_data['currency'],
					'total_override'   => (int) $payment_data['amount'],
					'products' => $products,
					'notes'    => ! empty( $payment_data['customer_notes'] ) ? mb_substr( trim( $payment_data['customer_notes'] ), 0, 1000 ) : '',
				),
			);

			// Add customer information if available.
			if ( ! empty( $payment_data['customer_email'] ) || ! empty( $payment_data['customer_full_name'] ) ) {
				$chip_params['client'] = array();

				// Add email.
				if ( ! empty( $payment_data['customer_email'] ) ) {
					$chip_params['client']['email'] = $payment_data['customer_email'];
				}

				// Add full name.
				if ( ! empty( $payment_data['customer_full_name'] ) ) {
					$chip_params['client']['full_name'] = $payment_data['customer_full_name'];
				}

				// Add phone.
				if ( ! empty( $payment_data['customer_phone'] ) ) {
					$chip_params['client']['phone'] = $payment_data['customer_phone'];
				}

				// Add personal code (customer ID).
				if ( ! empty( $payment_data['customer_personal_code'] ) ) {
					$chip_params['client']['personal_code'] = $payment_data['customer_personal_code'];
				}

				// Add billing address fields.
				if ( ! empty( $payment_data['billing_street_address'] ) ) {
					$chip_params['client']['street_address'] = $payment_data['billing_street_address'];
				}
				if ( ! empty( $payment_data['billing_country'] ) ) {
					$chip_params['client']['country'] = $payment_data['billing_country'];
				}
				if ( ! empty( $payment_data['billing_city'] ) ) {
					$chip_params['client']['city'] = $payment_data['billing_city'];
				}
				if ( ! empty( $payment_data['billing_zip_code'] ) ) {
					$chip_params['client']['zip_code'] = $payment_data['billing_zip_code'];
				}
				if ( ! empty( $payment_data['billing_state'] ) ) {
					$chip_params['client']['state'] = $payment_data['billing_state'];
				}

				// Add shipping address fields.
				if ( ! empty( $payment_data['shipping_street_address'] ) ) {
					$chip_params['client']['shipping_street_address'] = $payment_data['shipping_street_address'];
				}
				if ( ! empty( $payment_data['shipping_country'] ) ) {
					$chip_params['client']['shipping_country'] = $payment_data['shipping_country'];
				}
				if ( ! empty( $payment_data['shipping_city'] ) ) {
					$chip_params['client']['shipping_city'] = $payment_data['shipping_city'];
				}
				if ( ! empty( $payment_data['shipping_zip_code'] ) ) {
					$chip_params['client']['shipping_zip_code'] = $payment_data['shipping_zip_code'];
				}
				if ( ! empty( $payment_data['shipping_state'] ) ) {
					$chip_params['client']['shipping_state'] = $payment_data['shipping_state'];
				}
			}

			// Add email fallback if configured and client email is not set.
			$email_fallback = $this->settings->getEmailFallback();
			if ( ! empty( $email_fallback ) && empty( $chip_params['client']['email'] ) ) {
				if ( ! isset( $chip_params['client'] ) ) {
					$chip_params['client'] = array();
				}
				$chip_params['client']['email'] = $email_fallback;
			}

			// Add payment method whitelist if configured.
			$payment_method_whitelist = $this->settings->getPaymentMethodWhitelist();
			if ( ! empty( $payment_method_whitelist ) && is_array( $payment_method_whitelist ) ) {
				$chip_params['payment_method_whitelist'] = $payment_method_whitelist;
			}

			// Create payment via CHIP API.
			$response = $chip_api->create_payment( $chip_params );

			// Check for errors.
			if ( null === $response ) {
				return array(
					'success'       => false,
					'error_message' => __( 'Failed to create payment. Please try again.', 'chip-for-fluent-cart' ),
				);
			}

			// Check for __all__ error key.
			if ( isset( $response['__all__'] ) && is_array( $response['__all__'] ) ) {
				$error_messages = array_map(
					function ( $error ) {
						// Each error is an array with 'message' and 'code' keys.
						return isset( $error['message'] ) ? $error['message'] : '';
					},
					$response['__all__']
				);

				// Filter out empty messages.
				$error_messages = array_filter( $error_messages );

				if ( ! empty( $error_messages ) ) {
					return array(
						'success'       => false,
						'error_message' => implode( ' ', $error_messages ),
					);
				}
			}

			// Check for checkout_url and id in response.
			if ( isset( $response['checkout_url'] ) && isset( $response['id'] ) ) {
				return array(
					'success'      => true,
					'redirect_url' => $response['checkout_url'],
					'payment_id'   => $response['id'],
				);
			}

			// If we reach here, response structure is unexpected.
			return array(
				'success'       => false,
				'error_message' => __( 'Unexpected response from payment gateway', 'chip-for-fluent-cart' ),
			);

		} catch ( \Exception $e ) {
			return array(
				'success'       => false,
				'error_message' => $e->getMessage(),
			);
		}
	}

	/**
	 * Handle payment paid event.
	 *
	 * Updates order status to completed for digital products.
	 *
	 * @since    1.0.0
	 * @param    array $params    Payment parameters including order data.
	 * @return   void
	 */
	public function handlePaymentPaid( $params ) {
		$order_id = Arr::get( $params, 'order.id' );
		// Get the order with the id.
		$order = OrderResource::getQuery()->find( $order_id );

		if ( ! $order ) {
			return;
		}

		$order_status     = Arr::get( $params, 'order.status' );
		$fulfillment_type = Arr::get( $params, 'order.fulfillment_type' );
		$payment_method   = Arr::get( $params, 'order.payment_method' );

		// If new status is processing and payment_method is chip and fulfillment_type is digital then make it completed.
		if ( Status::ORDER_PROCESSING === $order_status && 'chip' === $payment_method && 'digital' === $fulfillment_type ) {
			$order->status       = Status::ORDER_COMPLETED;
			$order->completed_at = DateTime::gmtNow();
			$order->save();

			$action_activity = array(
				'title'   => __( 'Order status updated', 'chip-for-fluent-cart' ),
				/* translators: %1$s: old order status, %2$s: new order status */
				'content' => sprintf( __( 'Order status has been updated from %1$s to %2$s', 'chip-for-fluent-cart' ), $order_status, $order->status ),
			);

			( new OrderStatusUpdated( $order, $order_status, 'completed', true, $action_activity, 'order_status' ) )->dispatch();

			$this->maybeUpdateSubscription( $params );

		} elseif ( Status::ORDER_PROCESSING === $order_status && 'chip' === $payment_method && 'physical' === $fulfillment_type ) {
			$this->maybeUpdateSubscription( $params );
		}
	}

	/**
	 * Maybe update subscription status.
	 *
	 * Updates subscription status to active if order is processing or completed.
	 *
	 * @since    1.0.0
	 * @param    array $params    Order parameters.
	 * @return   void
	 */
	public function maybeUpdateSubscription( $params ) {
		$order        = Arr::get( $params, 'order' );
		$order_id     = Arr::get( $params, 'order.id' );
		$order_status = Arr::get( $params, 'order.status' );

		if ( ! $order ) {
			return;
		}

		if ( ! in_array( $order_status, array( Status::ORDER_PROCESSING, Status::ORDER_COMPLETED ), true ) ) {
			return;
		}

		// Search in subscription table with order id get every subscription.
		$subscriptions = Subscription::query()->where( 'parent_order_id', $order_id )->get();

		// Update subscription status.
		foreach ( $subscriptions as $subscription ) {
			if ( 'active' === $subscription->status ) {
				continue;
			}
			$subscription->status = 'active';
			$subscription->save();
		}
	}

	/**
	 * Get script sources to enqueue for checkout.
	 *
	 * @since    1.0.0
	 * @param    string $has_subscription    Whether order has subscription ('yes' or 'no').
	 * @return   array                          Array of script configurations.
	 */
	public function getEnqueueScriptSrc( $has_subscription = 'no' ): array {
		return array(
			array(
				'handle'  => 'fluent-cart-checkout-handler-chip',
				'src'     => plugin_dir_url( __DIR__ ) . 'public/js/chip-checkout.js',
				'version' => CHIP_FOR_FLUENTCART_VERSION,
			),
		);
	}

	/**
	 * Get localized data for JavaScript.
	 *
	 * @since    1.0.0
	 * @return   array    Localized data array with translations.
	 */
	public function getLocalizeData(): array {
		return array(
			'fct_chip_data' => array(
				'translations' => array(
					'CHIP - Pay securely with CHIP Collect. Accept FPX, Cards, E-Wallet, Duitnow QR.' => __( 'CHIP - Pay securely with CHIP Collect. Accept FPX, Cards, E-Wallet, Duitnow QR.', 'chip-for-fluent-cart' ),
				),
			),
		);
	}

	/**
	 * Get settings fields for the payment gateway.
	 *
	 * @since    1.0.0
	 * @return   array    Array of settings field configurations.
	 */
	public function fields() {
		$settings = $this->settings->get();

		return array(
			'chip_description'                 => array(
				'value' =>
					wp_kses(
						sprintf(
							"<div class='pt-4'>
                            <p>%s</p>
                        </div>",
							__( '✅  CHIP is a secure payment gateway that allows customers to pay for their orders online. Configure your API credentials to enable CHIP payments.', 'chip-for-fluent-cart' )
						),
						array(
							'p'   => array(),
							'div' => array( 'class' => true ),
							'i'   => array(),
						)
					),
				'label' => __( 'Description', 'chip-for-fluent-cart' ),
				'type'  => 'html_attr',
			),
			'secret_key'                       => array(
				'value'       => $settings['secret_key'] ?? '',
				'label'       => __( 'Secret Key', 'chip-for-fluent-cart' ),
				'type'        => 'password',
				'required'    => true,
				'description' => __( 'Your CHIP secret key. The payment mode (test/live) will be determined automatically based on this key.', 'chip-for-fluent-cart' ),
			),
			'brand_id'                         => array(
				'value'       => $settings['brand_id'] ?? '',
				'label'       => __( 'Brand ID', 'chip-for-fluent-cart' ),
				'type'        => 'text',
				'required'    => true,
				'description' => __( 'Your CHIP Brand ID', 'chip-for-fluent-cart' ),
			),
			'email_fallback'                   => array(
				'value'       => $settings['email_fallback'] ?? '',
				'label'       => __( 'Email Fallback', 'chip-for-fluent-cart' ),
				'type'        => 'email',
				'required'    => false,
				'description' => __( 'Fallback email address for purchase creation', 'chip-for-fluent-cart' ),
			),
			'payment_method_whitelist_heading' => array(
				'value' =>
					wp_kses(
						sprintf(
							'<div class="pt-4 pb-2">
                            <h3 class="text-lg font-semibold mb-2">%s</h3>
                            <p class="text-sm text-gray-600">%s</p>
                        </div>',
							__( 'Payment Method Whitelist', 'chip-for-fluent-cart' ),
							__( 'You may not tick any of the options to activate all payment methods.', 'chip-for-fluent-cart' )
						),
						array(
							'div' => array( 'class' => true ),
							'h3'  => array( 'class' => true ),
							'p'   => array( 'class' => true ),
						)
					),
				'label' => __( 'Payment Method Whitelist', 'chip-for-fluent-cart' ),
				'type'  => 'html_attr',
			),
			'payment_method_whitelist'         => array(
				'value'    => $settings['payment_method_whitelist'] ?? array(),
				'label'    => '',
				'type'     => 'checkbox_group',
				'required' => false,
				'options'  => array(
					'fpx'             => __( 'Online Banking (FPX)', 'chip-for-fluent-cart' ),
					'fpx_b2b1'        => __( 'Corporate Online Banking (FPX)', 'chip-for-fluent-cart' ),
					'mastercard'      => __( 'Mastercard', 'chip-for-fluent-cart' ),
					'maestro'         => __( 'Maestro', 'chip-for-fluent-cart' ),
					'visa'            => __( 'Visa', 'chip-for-fluent-cart' ),
					'razer_atome'     => __( 'Atome', 'chip-for-fluent-cart' ),
					'razer_grabpay'   => __( 'Razer GrabPay', 'chip-for-fluent-cart' ),
					'razer_maybankqr' => __( 'Razer MaybankQR', 'chip-for-fluent-cart' ),
					'razer_shopeepay' => __( 'Razer ShopeePay', 'chip-for-fluent-cart' ),
					'razer_tng'       => __( 'Razer TnG', 'chip-for-fluent-cart' ),
					'duitnow_qr'      => __( 'DuitNow QR', 'chip-for-fluent-cart' ),
					'mpgs_google_pay' => __( 'Google Pay', 'chip-for-fluent-cart' ),
					'mpgs_apple_pay'  => __( 'Apple Pay', 'chip-for-fluent-cart' ),
				),
			),
			'debug'                            => array(
				'value'       => $settings['debug'] ?? 'no',
				'label'       => __( 'Enable Debug', 'chip-for-fluent-cart' ),
				'type'        => 'yes_no',
				'required'    => false,
				'description' => __( 'Enable debug mode to log payment processing details for troubleshooting.', 'chip-for-fluent-cart' ),
			),
		);
	}

	/**
	 * Validate settings data.
	 *
	 * @since    1.0.0
	 * @param    array $data    Settings data to validate.
	 * @return   array             Validation result with status and message.
	 */
	public static function validateSettings( $data ): array {
		return array(
			'status'  => 'success',
			'message' => __( 'Settings saved successfully', 'chip-for-fluent-cart' ),
		);
	}

	/**
	 * Maybe update payment status.
	 *
	 * @since    1.0.0
	 * @param    string $order_hash    Order hash.
	 * @return   void
	 */
	public function maybeUpdatePayments( $order_hash ) {
		$update_data = array(
			'status' => Status::PAYMENT_PENDING,
		);
		$order       = OrderResource::getOrderByHash( $order_hash );
		$this->updateOrderDataByOrder( $order, $update_data );
	}

	/**
	 * Process refund via CHIP API.
	 *
	 * @since    1.0.0
	 * @param    object $transaction    Transaction object.
	 * @param    int    $amount         Refund amount in cents.
	 * @param    array  $args           Additional arguments.
	 * @return   string|\WP_Error          Refund ID on success or WP_Error on failure.
	 */
	public function processRefund( $transaction, $amount, $args ) {
		if ( ! $amount ) {
			return new \WP_Error(
				'fluent_cart_chip_refund_error',
				__( 'Refund amount is required.', 'chip-for-fluent-cart' )
			);
		}

		// Get purchase ID from transaction.
		$purchase_id = $transaction->vendor_charge_id;

		if ( ! $purchase_id ) {
			return new \WP_Error(
				'fluent_cart_chip_refund_error',
				__( 'Invalid transaction ID for refund.', 'chip-for-fluent-cart' )
			);
		}

		// Get settings.
		$settings   = $this->settings->get();
		$secret_key = $settings['secret_key'] ?? '';
		$brand_id   = $settings['brand_id'] ?? '';

		if ( empty( $secret_key ) || empty( $brand_id ) ) {
			return new \WP_Error(
				'fluent_cart_chip_refund_error',
				__( 'CHIP API credentials are not configured.', 'chip-for-fluent-cart' )
			);
		}

		// Initialize API.
		$logger   = new ChipLogger();
		$debug    = $this->settings->isDebugEnabled() ? 'yes' : 'no';
		$chip_api = new ChipFluentCartApi( $secret_key, $brand_id, $logger, $debug );

		// Refund amount (already in cents from FluentCart).
		$refund_amount = (int) $amount;

		// Prepare refund data.
		$refund_data = array(
			'amount' => $refund_amount,
		);

		// Call CHIP API to process refund.
		$refunded = $chip_api->refund_payment( $purchase_id, $refund_data );

		if ( empty( $refunded ) ) {
			return new \WP_Error(
				'fluent_cart_chip_refund_error',
				__( 'Refund could not be processed. Please check your CHIP account.', 'chip-for-fluent-cart' )
			);
		}

		// Check refund status.
		$status          = $refunded['status'] ?? '';
		$accepted_status = array( 'success', 'refunded' );

		if ( ! in_array( $status, $accepted_status, true ) ) {
			return new \WP_Error(
				'fluent_cart_chip_refund_error',
				__( 'Refund could not be processed in CHIP. Please check your CHIP account.', 'chip-for-fluent-cart' )
			);
		}

		// Return refund ID.
		return $refunded['id'] ?? $purchase_id;
	}

	/**
	 * Get webhook payment method name.
	 *
	 * @since    1.0.0
	 * @return   string    Payment method route name.
	 */
	public function webHookPaymentMethodName() {
		return $this->getMeta( 'route' );
	}

	/**
	 * Handle IPN (Instant Payment Notification) from CHIP.
	 *
	 * Validates signature and processes webhook data.
	 *
	 * @since    1.0.0
	 * @return   void
	 */
	public function handleIPN() {
		// Get raw JSON input directly for webhook signature verification.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading from php://input for webhook payload.
		$raw_input = file_get_contents( 'php://input' );

		if ( empty( $raw_input ) ) {
			http_response_code( 400 );
			exit( 'Empty request body' );
		}

		// Decode JSON to get brand_id first.
		$webhook_data = json_decode( $raw_input, true );

		if ( json_last_error() !== JSON_ERROR_NONE || empty( $webhook_data ) ) {
			http_response_code( 400 );
			exit( 'Invalid JSON' );
		}

		// Get brand_id from the webhook data.
		$webhook_brand_id = $webhook_data['brand_id'] ?? '';

		if ( empty( $webhook_brand_id ) ) {
			http_response_code( 400 );
			exit( 'Missing brand_id' );
		}

		// Get settings and verify brand_id matches.
		$settings            = $this->settings->get();
		$configured_brand_id = $settings['brand_id'] ?? '';

		if ( $configured_brand_id !== $webhook_brand_id ) {
			http_response_code( 400 );
			exit( 'Brand ID mismatch' );
		}

		// Get signature from headers.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Signature must remain intact for verification.
		$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) : '';

		if ( empty( $signature ) ) {
			http_response_code( 400 );
			exit( 'Missing signature' );
		}

		// Get public key for signature verification.
		$public_key = $this->getPublicKey( $configured_brand_id );

		if ( empty( $public_key ) ) {
			http_response_code( 500 );
			exit( 'Failed to get public key' );
		}

		// Verify signature using public key.
		if ( ! $this->verifySignature( $raw_input, $signature, $public_key ) ) {
			http_response_code( 400 );
			exit( 'Invalid signature' );
		}

		$this->processWebhook( $webhook_data );

		http_response_code( 200 );
		exit( 'OK' );
	}

	/**
	 * Get public key from CHIP API (cached per brand_id).
	 *
	 * @since  1.0.0
	 * @param  string $brand_id Brand ID.
	 * @return string|null      Public key or null on failure.
	 */
	protected function getPublicKey( $brand_id ) {
		// Get stored public key data.
		$stored_data = get_option( self::PUBLIC_KEY_OPTION, array() );

		// Check if we have a cached public key for this brand_id.
		if ( ! empty( $stored_data['brand_id'] ) && $stored_data['brand_id'] === $brand_id && ! empty( $stored_data['public_key'] ) ) {
			return $stored_data['public_key'];
		}

		// Fetch public key from API.
		$settings   = $this->settings->get();
		$secret_key = $settings['secret_key'] ?? '';

		if ( empty( $secret_key ) ) {
			return null;
		}

		$logger   = new ChipLogger();
		$debug    = $this->settings->isDebugEnabled() ? 'yes' : 'no';
		$chip_api = new ChipFluentCartApi( $secret_key, $brand_id, $logger, $debug );

		$public_key_response = $chip_api->public_key();

		if ( empty( $public_key_response ) ) {
			return null;
		}

		// Replace escaped newlines with actual newlines.
		$public_key = str_replace( '\n', "\n", $public_key_response );

		// Store the public key mapped to brand_id.
		update_option(
			self::PUBLIC_KEY_OPTION,
			array(
				'brand_id'   => $brand_id,
				'public_key' => $public_key,
			)
		);

		return $public_key;
	}

	/**
	 * Verify signature using public key.
	 *
	 * @since  1.0.0
	 * @param  string $raw_input  Raw input data.
	 * @param  string $signature  Base64 encoded signature from header.
	 * @param  string $public_key Public key for verification.
	 * @return bool               True if signature is valid.
	 */
	protected function verifySignature( $raw_input, $signature, $public_key ) {
		// Decode base64 signature from X-Signature header for cryptographic verification.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for RSA signature verification.
		$decoded_signature = base64_decode( $signature );

		if ( false === $decoded_signature ) {
			return false;
		}

		// Verify using openssl.
		$result = openssl_verify(
			$raw_input,
			$decoded_signature,
			$public_key,
			OPENSSL_ALGO_SHA256
		);

		return 1 === $result;
	}

	/**
	 * Process webhook data from CHIP.
	 *
	 * @since  1.0.0
	 * @param  array $webhook_data Webhook data.
	 * @return void
	 */
	protected function processWebhook( $webhook_data ) {
		if ( empty( $webhook_data ) ) {
			return;
		}

		// Extract relevant data from webhook.
		$event_type          = $webhook_data['event_type'] ?? '';
		$purchase_id         = $webhook_data['id'] ?? '';
		$payment_status      = $webhook_data['status'] ?? '';
		$order_uuid          = $webhook_data['reference'] ?? '';
		$transaction_data    = $webhook_data['transaction_data'] ?? array();
		$payment_method_type = $transaction_data['payment_method'] ?? '';

		// Only process paid/settled events.
		if ( ! in_array( $event_type, array( 'purchase.paid', 'purchase.settled' ), true ) ) {
			return;
		}

		// Check payment status.
		if ( 'paid' !== $payment_status ) {
			return;
		}

		if ( empty( $order_uuid ) ) {
			return;
		}

		// Get order by UUID (reference).
		$order = Order::where( 'uuid', $order_uuid )->first();

		if ( ! $order ) {
			return;
		}

		// Get order transaction.
		$order_transaction = OrderTransaction::where( 'order_id', $order->id )
			->where( 'payment_method', 'chip' )
			->first();

		if ( ! $order_transaction ) {
			return;
		}

		// Acquire lock to prevent race condition with handleInitRedirect.
		$this->acquireLock( $order->id );

		try {
			// Re-fetch order after acquiring lock to get latest status.
			$order = Order::where( 'uuid', $order_uuid )->first();

			// Check if already processed (order already in paid status).
			$paid_statuses = array( Status::ORDER_PROCESSING, Status::ORDER_COMPLETED );
			if ( in_array( $order->status, $paid_statuses, true ) ) {
				return;
			}

			// Update transaction status.
			$order_transaction->fill(
				array(
					'status'              => Status::TRANSACTION_SUCCEEDED,
					'chip_purchase_id'    => $purchase_id,
					'vendor_charge_id'    => $purchase_id,
					'payment_method_type' => $this->mapPaymentMethodType( $payment_method_type, $transaction_data ),
				)
			);
			$order_transaction->save();

			// Sync order statuses using StatusHelper.
			( new StatusHelper( $order ) )->syncOrderStatuses( $order_transaction );
		} finally {
			// Always release lock.
			$this->releaseLock( $order->id );
		}
	}

	/**
	 * Get order information.
	 *
	 * @since    1.0.0
	 * @param    array $data    Data array containing order_id.
	 * @return   array|\WP_Error   Order info array or WP_Error if not found.
	 */
	public function getOrderInfo( array $data ) {
		$order_id = $data['order_id'] ?? '';
		$order    = Order::where( 'uuid', $order_id )->first();

		if ( ! $order ) {
			return new \WP_Error( 'order_not_found', 'Order not found' );
		}

		return array(
			'order'    => $order,
			'total'    => $order->total,
			'currency' => $order->currency,
			'status'   => $order->status,
		);
	}

	/**
	 * Store purchase ID in fct_order_meta table using OrderMeta model.
	 *
	 * @since  1.0.0
	 * @param  object $transaction Transaction object (or order object).
	 * @param  string $purchase_id CHIP purchase ID.
	 * @return void
	 */
	protected function storePurchaseId( $transaction, $purchase_id ) {
		if ( ! $transaction || ! $purchase_id ) {
			return;
		}

		// Get order ID from transaction or order object.
		$order_id = $transaction->order_id;

		$meta_key   = 'chip_purchase_id';
		$meta_value = sanitize_text_field( $purchase_id );

		// Use updateOrCreate to update existing or create new record.
		// Timestamps (created_at, updated_at) are handled automatically by the model.
		OrderMeta::query()->updateOrCreate(
			array(
				'order_id' => $order_id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key' => $meta_key,
			),
			array(
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => $meta_value,
			)
		);
	}

	/**
	 * Get purchase ID from fct_order_meta table using OrderMeta model.
	 *
	 * @since  1.0.0
	 * @param  object $order Order object.
	 * @return string|null   CHIP purchase ID or null if not found.
	 */
	protected function getPurchaseId( $order ) {
		if ( ! $order ) {
			return null;
		}

		// Get order ID from order object.
		// The order model has 'id' in its attributes array.
		$order_id = $order->id ?? null;

		if ( ! $order_id ) {
			return null;
		}
		$meta_key = 'chip_purchase_id';

		// Query using OrderMeta model.
		$order_meta = OrderMeta::query()->where( 'order_id', $order_id )
			->where( 'meta_key', $meta_key )
			->first();

		// The model automatically handles JSON decoding via getMetaValueAttribute accessor.
		return $order_meta ? $order_meta->meta_value : null;
	}

	/**
	 * Check payment status via CHIP API.
	 *
	 * @since  1.0.0
	 * @param  string $purchase_id CHIP purchase ID.
	 * @return array|null          Array with 'status', 'payment_method_type', and 'transaction_data', or null on error.
	 */
	protected function checkPaymentStatus( $purchase_id ) {
		if ( empty( $purchase_id ) ) {
			return null;
		}

		try {
			// Get settings.
			$settings   = $this->settings->get();
			$secret_key = $settings['secret_key'] ?? '';
			$brand_id   = $settings['brand_id'] ?? '';

			if ( empty( $secret_key ) || empty( $brand_id ) ) {
				return null;
			}

			// Initialize API.
			$logger   = new ChipLogger();
			$debug    = $this->settings->isDebugEnabled() ? 'yes' : 'no';
			$chip_api = new ChipFluentCartApi( $secret_key, $brand_id, $logger, $debug );

			// Get payment from API.
			$payment = $chip_api->get_payment( $purchase_id );

			if ( $payment && isset( $payment['status'] ) ) {
				$transaction_data = $payment['transaction_data'] ?? array();
				return array(
					'status'              => $payment['status'],
					'payment_method_type' => $transaction_data['payment_method'] ?? '',
					'transaction_data'    => $transaction_data,
				);
			}

			return null;
		} catch ( \Exception $e ) {
			// Log error but don't throw.
			return null;
		}
	}

	/**
	 * Acquire database lock for payment processing.
	 * Compatible with both MySQL and PostgreSQL.
	 *
	 * @since  1.0.0
	 * @param  int|string $order_id Order ID for lock key.
	 * @param  int        $timeout  Lock timeout in seconds (MySQL only).
	 * @return bool                 True if lock acquired, false otherwise.
	 */
	protected function acquireLock( $order_id, $timeout = 15 ) {
		global $wpdb;

		// Determine database type.
		if ( $this->isPostgreSQL() ) {
			// PostgreSQL: Use advisory lock with numeric key.
			// Convert order ID to integer for pg_advisory_lock.
			$lock_key = abs( crc32( 'chip_payment_' . $order_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock requires direct query.
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SELECT pg_advisory_lock(%d)', $lock_key )
			);
			return true; // pg_advisory_lock blocks until acquired.
		} else {
			// MySQL: Use named lock with timeout.
			$lock_name = 'chip_payment_' . $order_id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock requires direct query.
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, $timeout )
			);
			return 1 === (int) $result;
		}
	}

	/**
	 * Release database lock for payment processing.
	 * Compatible with both MySQL and PostgreSQL.
	 *
	 * @since  1.0.0
	 * @param  int|string $order_id Order ID for lock key.
	 * @return bool                 True if lock released, false otherwise.
	 */
	protected function releaseLock( $order_id ) {
		global $wpdb;

		// Determine database type.
		if ( $this->isPostgreSQL() ) {
			// PostgreSQL: Release advisory lock.
			$lock_key = abs( crc32( 'chip_payment_' . $order_id ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock requires direct query.
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SELECT pg_advisory_unlock(%d)', $lock_key )
			);
			return 't' === $result || true === $result || 1 === (int) $result;
		} else {
			// MySQL: Release named lock.
			$lock_name = 'chip_payment_' . $order_id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock requires direct query.
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
			);
			return 1 === (int) $result;
		}
	}

	/**
	 * Check if the database is PostgreSQL.
	 *
	 * @since  1.0.0
	 * @return bool True if PostgreSQL, false otherwise (MySQL/MariaDB).
	 */
	protected function isPostgreSQL() {
		global $wpdb;

		// Check if using PostgreSQL by examining the db server info.
		if ( method_exists( $wpdb, 'db_server_info' ) ) {
			$server_info = $wpdb->db_server_info();
			return false !== stripos( $server_info, 'postgresql' ) || false !== stripos( $server_info, 'pgsql' );
		}

		// Alternative: Check the database driver.
		if ( defined( 'DB_DRIVER' ) && false !== stripos( DB_DRIVER, 'pgsql' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Map payment method type to display name.
	 *
	 * @since  1.0.0
	 * @param  string $payment_method   Raw payment method from CHIP API.
	 * @param  array  $transaction_data Full transaction_data array (optional, needed for FPX).
	 * @return string                   Mapped display name.
	 */
	protected function mapPaymentMethodType( $payment_method, $transaction_data = array() ) {
		// Handle FPX specially - get bank name from fpx_buyerBankBranch.
		if ( 'fpx' === $payment_method && ! empty( $transaction_data['extra'] ) ) {
			$bank_branch = $this->extractFpxBuyerBankBranch( $transaction_data['extra'] );
			if ( ! empty( $bank_branch ) ) {
				return $bank_branch;
			}
		}

		$mapping = array(
			'razer_atome'     => 'Atome',
			'razer_grabpay'   => 'GrabPay',
			'razer_tng'       => 'TnG',
			'razer_shopeepay' => 'ShopeePay',
		);

		return $mapping[ $payment_method ] ?? $payment_method;
	}

	/**
	 * Extract fpx_buyerBankBranch from transaction_data.extra.
	 * The value could be in various nested keys (redirect_payload, payload, callback_payload, etc.).
	 *
	 * @since  1.0.0
	 * @param  array $extra The extra data from transaction_data.
	 * @return string       The bank branch name or empty string if not found.
	 */
	protected function extractFpxBuyerBankBranch( $extra ) {
		if ( ! is_array( $extra ) ) {
			return '';
		}

		// Search through all keys in extra for fpx_buyerBankBranch.
		foreach ( $extra as $key => $value ) {
			if ( is_array( $value ) && isset( $value['fpx_buyerBankBranch'] ) ) {
				$bank_branch = $value['fpx_buyerBankBranch'];
				// The value might be an array (e.g., ["MAYBANK2U"]), get first element.
				if ( is_array( $bank_branch ) ) {
					return ! empty( $bank_branch[0] ) ? $bank_branch[0] : '';
				}
				return $bank_branch;
			}
		}

		return '';
	}
}
