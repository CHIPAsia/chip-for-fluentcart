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

class Chip extends AbstractPaymentGateway
{
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
    ];

    public BaseGatewaySettings $settings;

    const REDIRECT_KEY = 'chip-for-fluent-cart-redirect';
    const REDIRECT_PASSPHRASE_OPTION = 'chip-for-fluent-cart-redirect-passphrase';
    const PUBLIC_KEY_OPTION = 'chip-for-fluent-cart-public-key';

    public function __construct()
    {
        parent::__construct(new ChipSettingsBase());
    }

    /**
     * Boot the plugin
     *
     * @since    1.0.0
     */
    public function boot()
    {
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
     * @param    string    $url     Default URL (empty)
     * @param    array     $data    Transaction data containing vendor_charge_id (purchase_id)
     * @return   string             CHIP receipt URL
     */
    public function getTransactionReceiptUrl($url, $data)
    {
        // Refund transactions don't have a CHIP receipt
        if (($data['transaction_type'] ?? '') === 'refund') {
            return $url;
        }

        $purchaseId = $data['vendor_charge_id'] ?? '';
        
        if (empty($purchaseId)) {
            return $url;
        }
        
        return 'https://gate.chip-in.asia/p/' . sanitize_text_field( $purchaseId ) . '/receipt/';
    }

    /**
     * Get the plugin meta
     *
     * @since    1.0.0
     * @return   array                     Plugin meta
     */
    public function meta(): array
    {
        return [
            'title' => __('CHIP', 'chip-for-fluent-cart'),
            'route' => 'chip',
            'slug' => 'chip',
            'description' => esc_html__('CHIP - Pay securely with CHIP Collect. Accept FPX, Cards, E-Wallet, Duitnow QR.', 'chip-for-fluent-cart'),
            'logo' => plugin_dir_url( __DIR__ ) . 'assets/logo.svg',
            'icon' => plugin_dir_url( __DIR__ ) . 'assets/logo.svg',
            'brand_color' => '#136196',
            'upcoming' => false,
            'status' => 'yes' === $this->settings->get('is_active'),
            'supported_features' => $this->supportedFeatures,
        ];
    }

    /**
     * Make payment from payment instance
     *
     * @since    1.0.0
     * @param    PaymentInstance    $paymentInstance    Payment instance
     * @return   array Result array with success, message, redirect_to, payment_id, or error_message
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        try {
            $order = $paymentInstance->order;
            $customer = $order->customer;
            $transaction = $paymentInstance->transaction;

            // Get order items from order_items relation
            $orderItems = [];
            if (isset($order->order_items) && count($order->order_items) > 0) {
                $orderItems = $order->order_items;
            }

            // Get customer full name
            $customerFullName = trim($customer->first_name . ' ' . $customer->last_name);

            // Get customer phone from billing or shipping address meta
            $customerPhone = '';
            if (isset($order->billing_address->meta)) {
                $billingMeta = is_string($order->billing_address->meta) 
                    ? json_decode($order->billing_address->meta, true) 
                    : $order->billing_address->meta;
                if (!empty($billingMeta['other_data']['phone'])) {
                    $customerPhone = $billingMeta['other_data']['phone'];
                }
            }
            if (empty($customerPhone) && isset($order->shipping_address->meta)) {
                $shippingMeta = is_string($order->shipping_address->meta) 
                    ? json_decode($order->shipping_address->meta, true) 
                    : $order->shipping_address->meta;
                if (!empty($shippingMeta['other_data']['phone'])) {
                    $customerPhone = $shippingMeta['other_data']['phone'];
                }
            }

            // Prepare payment data
            $paymentData = [
                'amount' => $transaction->total,
                'currency' => $transaction->currency,
                'order_id' => $order->uuid,
                'customer_email' => $customer->email,
                'customer_full_name' => $customerFullName,
                'customer_phone' => $customerPhone,
                'customer_country' => $customer->country,
                'customer_city' => $customer->city,
                'customer_zip_code' => $customer->postcode,
                'customer_state' => $customer->state,
                'customer_personal_code' => $customer->id,
                'customer_notes' => $order->note ?? '',
                'return_url' => $this->getReturnUrl($transaction),
                'order_items' => $orderItems,
                'cancel_url' => self::getCancelUrl($transaction),
                'transaction_uuid' => $transaction->uuid
            ];

            // Process payment with gateway API
            $result = $this->processPayment($paymentData);

            if ($result['success']) {
                // Store purchase ID (payment_id) in transaction metadata for later reference
                if (!empty($result['payment_id'])) {
                    $this->storePurchaseId($transaction, $result['payment_id']);
                }

                return [
                    'status' => 'success',
                    'message' => __('Order has been placed successfully', 'chip-for-fluent-cart'),
                    'redirect_to' => $result['redirect_url'],
                    'payment_id' => $result['payment_id'] ?? null
                ];
            }

            return [
                'status' => 'failed',
                'message' => $result['error_message'] ?? __('Payment processing failed', 'chip-for-fluent-cart')
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get return URL for successful payment
     *
     * @since    1.0.0
     * @param    object    $transaction    Transaction object
     * @return   string                     Return URL
     */
    protected function getReturnUrl($transaction)
    {
        $paymentHelper = new \FluentCart\App\Services\Payments\PaymentHelper('chip');
        return $paymentHelper->successUrl($transaction->uuid);
    }

    /**
     * Get init URL for payment status check
     * Uses a custom endpoint with passphrase for security (not admin-ajax)
     *
     * @since    1.0.0
     * @param    object    $transaction    Transaction object
     * @return   string                     Init URL
     */
    protected function getInitUrl($transaction)
    {
        // Get or generate passphrase for security
        $passphrase = get_option(self::REDIRECT_PASSPHRASE_OPTION, false);
        if (!$passphrase) {
            $passphrase = md5(site_url() . time() . wp_salt());
            update_option(self::REDIRECT_PASSPHRASE_OPTION, $passphrase);
        }

        $params = [
            self::REDIRECT_KEY => $passphrase,
            'transaction_uuid' => $transaction->uuid
        ];

        return add_query_arg($params, site_url('/'));
    }

    /**
     * Handle init redirect - check order status and redirect accordingly
     * Uses init hook instead of admin-ajax to avoid wp-admin access issues
     *
     * @since    1.0.0
     */
    public function handleInitRedirect()
    {
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
        if ( $passphrase !== sanitize_text_field( wp_unslash( $_GET[ self::REDIRECT_KEY ] ) ) ) {
            status_header( 403 );
            exit( esc_html__( 'Invalid redirect passphrase', 'chip-for-fluent-cart' ) );
        }

        // Get transaction UUID from URL.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a redirect callback from payment gateway.
        $transactionUuid = isset( $_GET['transaction_uuid'] ) ? sanitize_text_field( wp_unslash( $_GET['transaction_uuid'] ) ) : '';
        
        if ( empty( $transactionUuid ) ) {
            status_header( 400 );
            exit( esc_html__( 'Invalid transaction UUID', 'chip-for-fluent-cart' ) );
        }

        // Get order transaction by UUID using OrderTransaction model
        $orderTransaction = OrderTransaction::where('uuid', $transactionUuid)->first();
        
        if ( ! $orderTransaction ) {
            status_header( 404 );
            exit( esc_html__( 'Transaction not found', 'chip-for-fluent-cart' ) );
        }

        // Get order from transaction
        $order = OrderResource::getQuery()->find($orderTransaction->order_id);
        
        if ( ! $order ) {
            status_header( 404 );
            exit( esc_html__( 'Order not found', 'chip-for-fluent-cart' ) );
        }

        // Acquire lock to prevent race condition with processWebhook
        $this->acquireLock($order->id);

        try {
            // Refresh order data to get latest status (in case webhook updated it)
            $order = OrderResource::getQuery()->find($orderTransaction->order_id);

            // Check if order status indicates payment is completed
            // Order is considered paid if status is PROCESSING or COMPLETED
            $paidStatuses = array( Status::ORDER_PROCESSING, Status::ORDER_COMPLETED );
            $isPaid       = in_array( $order->status, $paidStatuses, true );

            if ($isPaid) {
                // Order has been paid (possibly updated by webhook), redirect to success URL
                $returnUrl = $this->getReturnUrl($orderTransaction);
                $this->releaseLock($order->id);
                wp_safe_redirect($returnUrl);
                exit;
            } else {
                // Order has not been paid, check API one-time to get latest payment status
                $purchaseId = $this->getPurchaseId($order);
                
                if ($purchaseId) {
                    // Call API to check latest payment status
                    $paymentData = $this->checkPaymentStatus($purchaseId);
                    
                    if ( $paymentData && 'paid' === $paymentData['status'] ) {
                        // Payment is actually paid, sync order status
                        $orderTransaction->fill([
                            'status' => Status::TRANSACTION_SUCCEEDED,
                            'chip_purchase_id' => $purchaseId,
                            'vendor_charge_id' => $purchaseId,
                            'payment_method_type' => $this->mapPaymentMethodType($paymentData['payment_method_type'], $paymentData['transaction_data'] ?? []),
                        ]);
                        $orderTransaction->save();
                        (new StatusHelper($order))->syncOrderStatuses($orderTransaction);
                        
                        // Redirect to success URL
                        $returnUrl = $this->getReturnUrl($orderTransaction);
                        $this->releaseLock($order->id);
                        wp_safe_redirect($returnUrl);
                        exit;
                    }
                }
                
                // Payment is not paid, redirect to cancel URL
                $cancelUrl = self::getCancelUrl($orderTransaction);
                $this->releaseLock($order->id);
                wp_safe_redirect($cancelUrl);
                exit;
            }
        } catch (\Exception $e) {
            // Release lock on exception
            $this->releaseLock($order->id);
            throw $e;
        }
    }

    /**
     * Process payment with CHIP API
     *
     * @since    1.0.0
     * @param    array    $paymentData    Payment data
     * @return   array                     Result array with success, redirect_url, payment_id, or error_message
     */
    protected function processPayment($paymentData)
    {
        try {
            // Validate settings
            $settings = $this->settings->get();
            if ( 'yes' !== $settings['is_active'] ) {
                return [
                    'success' => false,
                    'error_message' => __('CHIP payment is not activated', 'chip-for-fluent-cart')
                ];
            }

            // Validate required settings
            $secretKey = $settings['secret_key'] ?? '';
            $brandId = $settings['brand_id'] ?? '';
            
            if (empty($secretKey) || empty($brandId)) {
                return [
                    'success' => false,
                    'error_message' => __('CHIP API credentials are not configured', 'chip-for-fluent-cart')
                ];
            }

            // Initialize logger and API
            $logger = new ChipLogger();
            $debug = $this->settings->isDebugEnabled() ? 'yes' : 'no';
            $chipApi = new ChipFluentCartApi($secretKey, $brandId, $logger, $debug);

            // Build products array from order items
            $products = [];
            $orderItems = $paymentData['order_items'] ?? [];
            
            if (!empty($orderItems) && is_array($orderItems)) {
                foreach ($orderItems as $item) {
                    $productName = '';
                    $productPrice = 0;
                    $productQty = 1;

                    // Handle different item structures (values already in cents from FluentCart)
                    if (is_object($item)) {
                        $productName = $item->post_title ?? $item->item_name ?? $item->title ?? $item->name ?? '';
                        $productPrice = (int) ($item->line_total ?? 0);
                        $productQty = $item->quantity ?? $item->qty ?? 1;
                    } elseif (is_array($item)) {
                        $productName = $item['post_title'] ?? $item['item_name'] ?? $item['title'] ?? $item['name'] ?? '';
                        $productPrice = (int) ($item['line_total'] ?? 0);
                        $productQty = $item['quantity'] ?? $item['qty'] ?? 1;
                    }

                    if (!empty($productName)) {
                        $products[] = [
                            'name' => $productName,
                            'price' => $productPrice,
                            'qty' => (int) $productQty
                        ];
                    }
                }
            }

            // If no products found, create a default product entry (amount already in cents)
            if (empty($products)) {
                $products[] = [
                    'name' => __('Order', 'chip-for-fluent-cart') . ' #' . $paymentData['order_id'],
                    'price' => (int) $paymentData['amount'],
                    'qty' => 1
                ];
            }

            // Get init URL for status check before redirecting
            // This will redirect to a temporary page that checks order status
            // before redirecting to the final success or cancel URL
            $transactionUuid = $paymentData['transaction_uuid'] ?? $paymentData['order_id'];
            $initUrl = $this->getInitUrl((object)['uuid' => $transactionUuid]);
            
            // Get callback URL using FluentCart's IPN listener
            $callbackUrl = add_query_arg([
                'fluent-cart' => 'fct_payment_listener_ipn',
                'method' => 'chip'
            ], site_url('/'));
            
            // Prepare CHIP API parameters (amount already in cents from FluentCart)
            $chipParams = [
                'brand_id' => $brandId,
                'total_override' => (int) $paymentData['amount'],
                'reference' => $paymentData['order_id'],
                'success_redirect' => $initUrl,
                'success_callback' => $callbackUrl,
                'failure_redirect' => $paymentData['cancel_url'],
                'cancel_redirect' => $paymentData['cancel_url'],
                'send_receipt' => false,
                'creator_agent' => 'FluentCart v' . FLUENTCART_VERSION,
                // TODO: Add platform
                // 'platform' => 'fluentcart',
                'purchase' => [
                    'currency' => $paymentData['currency'],
                    'products' => $products,
                    'notes' => !empty($paymentData['customer_notes']) ? mb_substr(trim($paymentData['customer_notes']), 0, 1000) : '',
                ]
            ];

            // Add customer information if available
            if (!empty($paymentData['customer_email']) || !empty($paymentData['customer_full_name'])) {
                $chipParams['client'] = [];
                
                // Add email
                if (!empty($paymentData['customer_email'])) {
                    $chipParams['client']['email'] = $paymentData['customer_email'];
                }
                
                // Add full name
                if (!empty($paymentData['customer_full_name'])) {
                    $chipParams['client']['full_name'] = $paymentData['customer_full_name'];
                }
                
                // Add phone
                if (!empty($paymentData['customer_phone'])) {
                    $chipParams['client']['phone'] = $paymentData['customer_phone'];
                }
                
                // Add country
                if (!empty($paymentData['customer_country'])) {
                    $chipParams['client']['country'] = $paymentData['customer_country'];
                }
                
                // Add city
                if (!empty($paymentData['customer_city'])) {
                    $chipParams['client']['city'] = $paymentData['customer_city'];
                }
                
                // Add zip code
                if (!empty($paymentData['customer_zip_code'])) {
                    $chipParams['client']['zip_code'] = $paymentData['customer_zip_code'];
                }
                
                // Add state
                if (!empty($paymentData['customer_state'])) {
                    $chipParams['client']['state'] = $paymentData['customer_state'];
                }
                
                // Add personal code (customer ID)
                if (!empty($paymentData['customer_personal_code'])) {
                    $chipParams['client']['personal_code'] = $paymentData['customer_personal_code'];
                }
            }

            // Add email fallback if configured
            $emailFallback = $this->settings->getEmailFallback();
            if (!empty($emailFallback)) {
                if (!isset($chipParams['client'])) {
                    $chipParams['client'] = [];
                }
                $chipParams['client']['email'] = $emailFallback;
            }

            // Add payment method whitelist if configured
            $paymentMethodWhitelist = $this->settings->getPaymentMethodWhitelist();
            if (!empty($paymentMethodWhitelist) && is_array($paymentMethodWhitelist)) {
                $chipParams['payment_method_whitelist'] = $paymentMethodWhitelist;
            }

            // Create payment via CHIP API
            $response = $chipApi->create_payment($chipParams);

            // Check for errors
            if ( null === $response ) {
                return [
                    'success' => false,
                    'error_message' => __('Failed to create payment. Please try again.', 'chip-for-fluent-cart')
                ];
            }

            // Check for __all__ error key
            if (isset($response['__all__']) && is_array($response['__all__'])) {
                $errorMessages = array_map(
                    function ( $error ) {
                        // Each error is an array with 'message' and 'code' keys
                        return isset($error['message']) ? $error['message'] : '';
                    },
                    $response['__all__']
                );
                
                // Filter out empty messages
                $errorMessages = array_filter($errorMessages);
                
                if (!empty($errorMessages)) {
                    return [
                        'success' => false,
                        'error_message' => implode(' ', $errorMessages)
                    ];
                }
            }

            // Check for checkout_url and id in response
            if (isset($response['checkout_url']) && isset($response['id'])) {
                return [
                    'success' => true,
                    'redirect_url' => $response['checkout_url'],
                    'payment_id' => $response['id']
                ];
            }

            // If we reach here, response structure is unexpected
            return [
                'success' => false,
                'error_message' => __('Unexpected response from payment gateway', 'chip-for-fluent-cart')
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error_message' => $e->getMessage()
            ];
        }
    }

    /**
     * Handle payment paid event.
     *
     * Updates order status to completed for digital products.
     *
     * @since    1.0.0
     * @param    array    $params    Payment parameters including order data.
     * @return   void
     */
    public function handlePaymentPaid($params)
    {
        $orderId = Arr::get($params, 'order.id');
        // get the order with the id
        $order = OrderResource::getQuery()->find($orderId);

        if (!$order) {
            return;
        }

        $orderStatus = Arr::get($params, 'order.status');
        $fulfillmentType = Arr::get($params, 'order.fulfillment_type');
        $paymentMethod = Arr::get($params, 'order.payment_method');

        // If new status is processing and payment_method is chip and fulfillment_type is digital then make it completed.
        if ( Status::ORDER_PROCESSING === $orderStatus && 'chip' === $paymentMethod && 'digital' === $fulfillmentType ) {
            $order->status = Status::ORDER_COMPLETED;
            $order->completed_at = DateTime::gmtNow();
            $order->save();

            $actionActivity = [
                'title'   => __('Order status updated', 'chip-for-fluent-cart'),
                /* translators: %1$s: old order status, %2$s: new order status */
                'content' => sprintf(__('Order status has been updated from %1$s to %2$s', 'chip-for-fluent-cart'), $orderStatus, $order->status)
            ];

            ( new OrderStatusUpdated( $order, $orderStatus, 'completed', true, $actionActivity, 'order_status' ) )->dispatch();

            $this->maybeUpdateSubscription($params);

        } elseif ( Status::ORDER_PROCESSING === $orderStatus && 'chip' === $paymentMethod && 'physical' === $fulfillmentType ) {
            $this->maybeUpdateSubscription($params);
        }

        return;
    }

    /**
     * Maybe update subscription status.
     *
     * Updates subscription status to active if order is processing or completed.
     *
     * @since    1.0.0
     * @param    array    $params    Order parameters.
     * @return   void
     */
    public function maybeUpdateSubscription($params)
    {
        $order = Arr::get($params, 'order');
        $orderId = Arr::get($params, 'order.id');
        $orderStatus = Arr::get($params, 'order.status');

        if (!$order) {
            return;
        }

        if ( ! in_array( $orderStatus, array( Status::ORDER_PROCESSING, Status::ORDER_COMPLETED ), true ) ) {
            return;
        }

        // just search in subscription table with order id get every subscription
        $subscriptions = Subscription::query()->where('parent_order_id', $orderId)->get();

        // update subscription status
        foreach ($subscriptions as $subscription) {
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
     * @param    string    $hasSubscription    Whether order has subscription ('yes' or 'no').
     * @return   array                          Array of script configurations.
     */
    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-checkout-handler-chip',
                'src' => plugin_dir_url( __DIR__ ) . 'public/js/chip-checkout.js',
                'version' => CHIP_FOR_FLUENTCART_VERSION,
            ]
        ];
    }

    /**
     * Get localized data for JavaScript.
     *
     * @since    1.0.0
     * @return   array    Localized data array with translations.
     */
    public function getLocalizeData(): array
    {
        return [
            'fct_chip_data' => [
                'translations' => [
                    'CHIP - Pay securely with CHIP Collect. Accept FPX, Cards, E-Wallet, Duitnow QR.' => __('CHIP - Pay securely with CHIP Collect. Accept FPX, Cards, E-Wallet, Duitnow QR.', 'chip-for-fluent-cart'),
                ]
            ]
        ];
    }

    /**
     * Get settings fields for the payment gateway.
     *
     * @since    1.0.0
     * @return   array    Array of settings field configurations.
     */
    public function fields()
    {
        $settings = $this->settings->get();
        
        return array(
            'chip_description' => array(
                'value' =>
                    wp_kses(sprintf(
                        "<div class='pt-4'>
                            <p>%s</p>
                        </div>",
                        __('✅  CHIP is a secure payment gateway that allows customers to pay for their orders online. Configure your API credentials to enable CHIP payments.', 'chip-for-fluent-cart')
                    ),
                    [
                        'p' => [],
                        'div' => ['class' => true],
                        'i' => [],
                    ]),
                'label' => __('Description', 'chip-for-fluent-cart'),
                'type' => 'html_attr'
            ),
            'secret_key' => array(
                'value' => $settings['secret_key'] ?? '',
                'label' => __('Secret Key', 'chip-for-fluent-cart'),
                'type' => 'password',
                'required' => true,
                'description' => __('Your CHIP secret key. The payment mode (test/live) will be determined automatically based on this key.', 'chip-for-fluent-cart')
            ),
            'brand_id' => array(
                'value' => $settings['brand_id'] ?? '',
                'label' => __('Brand ID', 'chip-for-fluent-cart'),
                'type' => 'text',
                'required' => true,
                'description' => __('Your CHIP Brand ID', 'chip-for-fluent-cart')
            ),
            'email_fallback' => array(
                'value' => $settings['email_fallback'] ?? '',
                'label' => __('Email Fallback', 'chip-for-fluent-cart'),
                'type' => 'email',
                'required' => false,
                'description' => __('Fallback email address for purchase creation', 'chip-for-fluent-cart')
            ),
            'payment_method_whitelist_heading' => array(
                'value' =>
                    wp_kses(sprintf(
                        '<div class="pt-4 pb-2">
                            <h3 class="text-lg font-semibold mb-2">%s</h3>
                            <p class="text-sm text-gray-600">%s</p>
                        </div>',
                        __('Payment Method Whitelist', 'chip-for-fluent-cart'),
                        __('You may not tick any of the options to activate all payment methods.', 'chip-for-fluent-cart')
                    ),
                    [
                        'div' => ['class' => true],
                        'h3' => ['class' => true],
                        'p' => ['class' => true],
                    ]),
                'label' => __('Payment Method Whitelist', 'chip-for-fluent-cart'),
                'type' => 'html_attr'
            ),
            'payment_method_whitelist' => array(
                'value' => $settings['payment_method_whitelist'] ?? [],
                'label' => '',
                'type' => 'checkbox_group',
                'required' => false,
                'options' => array(
                    'fpx' => __('Online Banking (FPX)', 'chip-for-fluent-cart'),
                    'fpx_b2b1' => __('Corporate Online Banking (FPX)', 'chip-for-fluent-cart'),
                    'mastercard' => __('Mastercard', 'chip-for-fluent-cart'),
                    'maestro' => __('Maestro', 'chip-for-fluent-cart'),
                    'visa' => __('Visa', 'chip-for-fluent-cart'),
                    'razer_atome' => __('Atome', 'chip-for-fluent-cart'),
                    'razer_grabpay' => __('Razer GrabPay', 'chip-for-fluent-cart'),
                    'razer_maybankqr' => __('Razer MaybankQR', 'chip-for-fluent-cart'),
                    'razer_shopeepay' => __('Razer ShopeePay', 'chip-for-fluent-cart'),
                    'razer_tng' => __('Razer TnG', 'chip-for-fluent-cart'),
                    'duitnow_qr' => __('DuitNow QR', 'chip-for-fluent-cart'),
                    'mpgs_google_pay' => __('Google Pay', 'chip-for-fluent-cart'),
                    'mpgs_apple_pay' => __('Apple Pay', 'chip-for-fluent-cart'),
                )
            ),
            'debug' => array(
                'value' => $settings['debug'] ?? 'no',
                'label' => __('Enable Debug', 'chip-for-fluent-cart'),
                'type' => 'yes_no',
                'required' => false,
                'description' => __('Enable debug mode to log payment processing details for troubleshooting.', 'chip-for-fluent-cart')
            ),
        );

    }

    /**
     * Validate settings data.
     *
     * @since    1.0.0
     * @param    array    $data    Settings data to validate.
     * @return   array             Validation result with status and message.
     */
    public static function validateSettings($data): array
    {
        return array(
            'status'  => 'success',
            'message' => __( 'Settings saved successfully', 'chip-for-fluent-cart' ),
        );
    }

    /**
     * Maybe update payment status.
     *
     * @since    1.0.0
     * @param    string    $orderHash    Order hash.
     * @return   void
     */
    public function maybeUpdatePayments($orderHash)
    {
        $updateData = array(
            'status' => Status::PAYMENT_PENDING,
        );
        $order = OrderResource::getOrderByHash($orderHash);
        $this->updateOrderDataByOrder($order, $updateData);
    }

    /**
     * Process refund via CHIP API.
     *
     * @since    1.0.0
     * @param    object    $transaction    Transaction object.
     * @param    int       $amount         Refund amount in cents.
     * @param    array     $args           Additional arguments.
     * @return   string|\WP_Error          Refund ID on success or WP_Error on failure.
     */
    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'fluent_cart_chip_refund_error',
                __('Refund amount is required.', 'chip-for-fluent-cart')
            );
        }

        // Get purchase ID from transaction
        $purchaseId = $transaction->vendor_charge_id;

        if (!$purchaseId) {
            return new \WP_Error(
                'fluent_cart_chip_refund_error',
                __('Invalid transaction ID for refund.', 'chip-for-fluent-cart')
            );
        }

        // Get settings
        $settings = $this->settings->get();
        $secretKey = $settings['secret_key'] ?? '';
        $brandId = $settings['brand_id'] ?? '';

        if (empty($secretKey) || empty($brandId)) {
            return new \WP_Error(
                'fluent_cart_chip_refund_error',
                __('CHIP API credentials are not configured.', 'chip-for-fluent-cart')
            );
        }

        // Initialize API
        $logger = new ChipLogger();
        $debug = $this->settings->isDebugEnabled() ? 'yes' : 'no';
        $chipApi = new ChipFluentCartApi($secretKey, $brandId, $logger, $debug);

        // Refund amount (already in cents from FluentCart)
        $refundAmount = (int) $amount;

        // Prepare refund data
        $refundData = [
            'amount' => $refundAmount
        ];

        // Call CHIP API to process refund
        $refunded = $chipApi->refund_payment($purchaseId, $refundData);

        if (empty($refunded)) {
            return new \WP_Error(
                'fluent_cart_chip_refund_error',
                __('Refund could not be processed. Please check your CHIP account.', 'chip-for-fluent-cart')
            );
        }

        // Check refund status
        $status          = $refunded['status'] ?? '';
        $acceptedStatus  = array( 'success', 'refunded' );

        if ( ! in_array( $status, $acceptedStatus, true ) ) {
            return new \WP_Error(
                'fluent_cart_chip_refund_error',
                __('Refund could not be processed in CHIP. Please check your CHIP account.', 'chip-for-fluent-cart')
            );
        }

        // Return refund ID
        return $refunded['id'] ?? $purchaseId;
    }

    /**
     * Get webhook payment method name.
     *
     * @since    1.0.0
     * @return   string    Payment method route name.
     */
    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    /**
     * Handle IPN (Instant Payment Notification) from CHIP.
     *
     * Validates signature and processes webhook data.
     *
     * @since    1.0.0
     * @return   void
     */
    public function handleIPN()
    {
        // Get raw JSON input directly for webhook signature verification.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading from php://input for webhook payload.
        $rawInput = file_get_contents('php://input');
        
        if (empty($rawInput)) {
            http_response_code(400);
            exit('Empty request body');
        }

        // Decode JSON to get brand_id first
        $webhookData = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($webhookData)) {
            http_response_code(400);
            exit('Invalid JSON');
        }

        // Get brand_id from the webhook data
        $webhookBrandId = $webhookData['brand_id'] ?? '';
        
        if (empty($webhookBrandId)) {
            http_response_code(400);
            exit('Missing brand_id');
        }

        // Get settings and verify brand_id matches
        $settings = $this->settings->get();
        $configuredBrandId = $settings['brand_id'] ?? '';
        
        if ( $configuredBrandId !== $webhookBrandId ) {
            http_response_code(400);
            exit('Brand ID mismatch');
        }

        // Get signature from headers.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Signature must remain intact for verification.
        $signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) : '';
        
        if (empty($signature)) {
            http_response_code(400);
            exit('Missing signature');
        }

        // Get public key for signature verification
        $publicKey = $this->getPublicKey($configuredBrandId);
        
        if (empty($publicKey)) {
            http_response_code(500);
            exit('Failed to get public key');
        }

        // Verify signature using public key
        if (!$this->verifySignature($rawInput, $signature, $publicKey)) {
            http_response_code(400);
            exit('Invalid signature');
        }

        $this->processWebhook($webhookData);

        http_response_code(200);
        exit('OK');
    }

    /**
     * Get public key from CHIP API (cached per brand_id)
     *
     * @since    1.0.0
     * @param    string    $brandId    Brand ID
     * @return   string|null           Public key or null on failure
     */
    protected function getPublicKey($brandId)
    {
        // Get stored public key data
        $storedData = get_option(self::PUBLIC_KEY_OPTION, []);
        
        // Check if we have a cached public key for this brand_id
        if (!empty($storedData['brand_id']) && $storedData['brand_id'] === $brandId && !empty($storedData['public_key'])) {
            return $storedData['public_key'];
        }

        // Fetch public key from API
        $settings = $this->settings->get();
        $secretKey = $settings['secret_key'] ?? '';
        
        if (empty($secretKey)) {
            return null;
        }

        $logger = new ChipLogger();
        $debug = $this->settings->isDebugEnabled() ? 'yes' : 'no';
        $chipApi = new ChipFluentCartApi($secretKey, $brandId, $logger, $debug);
        
        $publicKeyResponse = $chipApi->public_key();
        
        if (empty($publicKeyResponse)) {
            return null;
        }

        // Replace escaped newlines with actual newlines
        $publicKey = str_replace('\n', "\n", $publicKeyResponse);

        // Store the public key mapped to brand_id
        update_option(self::PUBLIC_KEY_OPTION, [
            'brand_id' => $brandId,
            'public_key' => $publicKey
        ]);

        return $publicKey;
    }

    /**
     * Verify signature using public key
     *
     * @since    1.0.0
     * @param    string    $rawInput    Raw input data
     * @param    string    $signature   Base64 encoded signature from header
     * @param    string    $publicKey   Public key for verification
     * @return   bool                   True if signature is valid
     */
    protected function verifySignature($rawInput, $signature, $publicKey)
    {
        // Decode base64 signature from X-Signature header for cryptographic verification.
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for RSA signature verification.
        $decodedSignature = base64_decode($signature);
        
        if ( false === $decodedSignature ) {
            return false;
        }

        // Verify using openssl
        $result = openssl_verify(
            $rawInput,
            $decodedSignature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        return 1 === $result;
    }

    /**
     * Process webhook data from CHIP
     *
     * @since    1.0.0
     * @param    array    $webhookData    Webhook data
     */
    protected function processWebhook($webhookData)
    {
        if (empty($webhookData)) {
            return;
        }

        // Extract relevant data from webhook
        $eventType = $webhookData['event_type'] ?? '';
        $purchaseId = $webhookData['id'] ?? '';
        $paymentStatus = $webhookData['status'] ?? '';
        $orderUuid = $webhookData['reference'] ?? '';
        $transactionData = $webhookData['transaction_data'] ?? [];
        $paymentMethodType = $transactionData['payment_method'] ?? '';

        // Only process paid/settled events.
        if ( ! in_array( $eventType, array( 'purchase.paid', 'purchase.settled' ), true ) ) {
            return;
        }

        // Check payment status
        if ( 'paid' !== $paymentStatus ) {
            return;
        }

        if (empty($orderUuid)) {
            return;
        }

        // Get order by UUID (reference)
        $order = Order::where('uuid', $orderUuid)->first();
        
        if (!$order) {
            return;
        }

        // Get order transaction
        $orderTransaction = OrderTransaction::where('order_id', $order->id)
            ->where('payment_method', 'chip')
            ->first();
        
        if (!$orderTransaction) {
            return;
        }

        // Acquire lock to prevent race condition with handleInitRedirect
        $this->acquireLock($order->id);

        try {
            // Re-fetch order after acquiring lock to get latest status
            $order = Order::where('uuid', $orderUuid)->first();

            // Check if already processed (order already in paid status).
            $paidStatuses = array( Status::ORDER_PROCESSING, Status::ORDER_COMPLETED );
            if ( in_array( $order->status, $paidStatuses, true ) ) {
                return;
            }

            // Update transaction status
            $orderTransaction->fill([
                'status' => Status::TRANSACTION_SUCCEEDED,
                'chip_purchase_id' => $purchaseId,
                'vendor_charge_id' => $purchaseId,
                'payment_method_type' => $this->mapPaymentMethodType($paymentMethodType, $transactionData),
            ]);
            $orderTransaction->save();

            // Sync order statuses using StatusHelper
            (new StatusHelper($order))->syncOrderStatuses($orderTransaction);
        } finally {
            // Always release lock
            $this->releaseLock($order->id);
        }
    }

    /**
     * Get order information.
     *
     * @since    1.0.0
     * @param    array    $data    Data array containing order_id.
     * @return   array|\WP_Error   Order info array or WP_Error if not found.
     */
    public function getOrderInfo(array $data)
    {
        $orderId = $data['order_id'] ?? '';
        $order = Order::where('uuid', $orderId)->first();

        if (!$order) {
            return new \WP_Error('order_not_found', 'Order not found');
        }

        return [
            'order' => $order,
            'total' => $order->total,
            'currency' => $order->currency,
            'status' => $order->status
        ];
    }

    /**
     * Store purchase ID in fct_order_meta table using OrderMeta model
     *
     * @since    1.0.0
     * @param    object    $transaction    Transaction object (or order object)
     * @param    string    $purchaseId     CHIP purchase ID
     */
    protected function storePurchaseId($transaction, $purchaseId)
    {
        if (!$transaction || !$purchaseId) {
            return;
        }

        // Get order ID from transaction or order object
        $orderId = $transaction->order_id;

        $metaKey = 'chip_purchase_id';
        $metaValue = sanitize_text_field($purchaseId);

        // Use updateOrCreate to update existing or create new record.
        // Timestamps (created_at, updated_at) are handled automatically by the model.
        OrderMeta::query()->updateOrCreate(
            array(
                'order_id'  => $orderId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'  => $metaKey,
            ),
            array(
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $metaValue,
            )
        );
    }

    /**
     * Get purchase ID from fct_order_meta table using OrderMeta model
     *
     * @since    1.0.0
     * @param    object    $order    Order object
     * @return   string|null          CHIP purchase ID or null if not found
     */
    protected function getPurchaseId($order)
    {
        if (!$order) {
            return null;
        }

        // Get order ID from order object
        // The order model has 'id' in its attributes array
        $orderId = $order->id ?? null;

        if (!$orderId) {
            return null;
        }
        $metaKey = 'chip_purchase_id';

        // Query using OrderMeta model
        $orderMeta = OrderMeta::query()->where('order_id', $orderId)
            ->where('meta_key', $metaKey)
            ->first();

        // The model automatically handles JSON decoding via getMetaValueAttribute accessor
        return $orderMeta ? $orderMeta->meta_value : null;
    }

    /**
     * Check payment status via CHIP API
     *
     * @since    1.0.0
     * @param    string    $purchaseId    CHIP purchase ID
     * @return   array|null               Array with 'status', 'payment_method_type', and 'transaction_data', or null on error
     */
    protected function checkPaymentStatus($purchaseId)
    {
        if (empty($purchaseId)) {
            return null;
        }

        try {
            // Get settings
            $settings = $this->settings->get();
            $secretKey = $settings['secret_key'] ?? '';
            $brandId = $settings['brand_id'] ?? '';

            if (empty($secretKey) || empty($brandId)) {
                return null;
            }

            // Initialize API
            $logger = new ChipLogger();
            $debug = $this->settings->isDebugEnabled() ? 'yes' : 'no';
            $chipApi = new ChipFluentCartApi($secretKey, $brandId, $logger, $debug);

            // Get payment from API
            $payment = $chipApi->get_payment($purchaseId);

            if ($payment && isset($payment['status'])) {
                $transactionData = $payment['transaction_data'] ?? [];
                return [
                    'status' => $payment['status'],
                    'payment_method_type' => $transactionData['payment_method'] ?? '',
                    'transaction_data' => $transactionData,
                ];
            }

            return null;
        } catch (\Exception $e) {
            // Log error but don't throw
            return null;
        }
    }

    /**
     * Acquire database lock for payment processing
     * Compatible with both MySQL and PostgreSQL
     *
     * @since    1.0.0
     * @param    int|string    $orderId    Order ID for lock key
     * @param    int           $timeout    Lock timeout in seconds (MySQL only)
     * @return   bool                      True if lock acquired, false otherwise
     */
    protected function acquireLock($orderId, $timeout = 15)
    {
        global $wpdb;
        
        // Determine database type
        if ($this->isPostgreSQL()) {
            // PostgreSQL: Use advisory lock with numeric key
            // Convert order ID to integer for pg_advisory_lock
            $lockKey = abs(crc32('chip_payment_' . $orderId));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock requires direct query.
            $result = $wpdb->get_var(
                $wpdb->prepare("SELECT pg_advisory_lock(%d)", $lockKey)
            );
            return true; // pg_advisory_lock blocks until acquired
        } else {
            // MySQL: Use named lock with timeout
            $lockName = 'chip_payment_' . $orderId;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock requires direct query.
            $result = $wpdb->get_var(
                $wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lockName, $timeout)
            );
            return $result == 1;
        }
    }

    /**
     * Release database lock for payment processing
     * Compatible with both MySQL and PostgreSQL
     *
     * @since    1.0.0
     * @param    int|string    $orderId    Order ID for lock key
     * @return   bool                      True if lock released, false otherwise
     */
    protected function releaseLock($orderId)
    {
        global $wpdb;
        
        // Determine database type
        if ($this->isPostgreSQL()) {
            // PostgreSQL: Release advisory lock
            $lockKey = abs(crc32('chip_payment_' . $orderId));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory lock requires direct query.
            $result = $wpdb->get_var(
                $wpdb->prepare("SELECT pg_advisory_unlock(%d)", $lockKey)
            );
            return $result === 't' || $result === true || $result == 1;
        } else {
            // MySQL: Release named lock
            $lockName = 'chip_payment_' . $orderId;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock requires direct query.
            $result = $wpdb->get_var(
                $wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName)
            );
            return $result == 1;
        }
    }

    /**
     * Check if the database is PostgreSQL
     *
     * @since    1.0.0
     * @return   bool    True if PostgreSQL, false otherwise (MySQL/MariaDB)
     */
    protected function isPostgreSQL()
    {
        global $wpdb;
        
        // Check if using PostgreSQL by examining the db server info
        if (method_exists($wpdb, 'db_server_info')) {
            $serverInfo = $wpdb->db_server_info();
            return stripos($serverInfo, 'postgresql') !== false || stripos($serverInfo, 'pgsql') !== false;
        }
        
        // Alternative: Check the database driver
        if (defined('DB_DRIVER') && stripos(DB_DRIVER, 'pgsql') !== false) {
            return true;
        }
        
        return false;
    }

    /**
     * Map payment method type to display name
     *
     * @since    1.0.0
     * @param    string    $paymentMethod     Raw payment method from CHIP API
     * @param    array     $transactionData   Full transaction_data array (optional, needed for FPX)
     * @return   string                       Mapped display name
     */
    protected function mapPaymentMethodType($paymentMethod, $transactionData = [])
    {
        // Handle FPX specially - get bank name from fpx_buyerBankBranch
        if ($paymentMethod === 'fpx' && !empty($transactionData['extra'])) {
            $bankBranch = $this->extractFpxBuyerBankBranch($transactionData['extra']);
            if (!empty($bankBranch)) {
                return $bankBranch;
            }
        }

        $mapping = [
            'razer_atome'     => 'Atome',
            'razer_grabpay'   => 'GrabPay',
            'razer_tng'       => 'TnG',
            'razer_shopeepay' => 'ShopeePay',
        ];

        return $mapping[$paymentMethod] ?? $paymentMethod;
    }

    /**
     * Extract fpx_buyerBankBranch from transaction_data.extra
     * The value could be in various nested keys (redirect_payload, payload, callback_payload, etc.)
     *
     * @since    1.0.0
     * @param    array     $extra    The extra data from transaction_data
     * @return   string              The bank branch name or empty string if not found
     */
    protected function extractFpxBuyerBankBranch($extra)
    {
        if (!is_array($extra)) {
            return '';
        }

        // Search through all keys in extra for fpx_buyerBankBranch
        foreach ($extra as $key => $value) {
            if (is_array($value) && isset($value['fpx_buyerBankBranch'])) {
                $bankBranch = $value['fpx_buyerBankBranch'];
                // The value might be an array (e.g., ["MAYBANK2U"]), get first element
                if (is_array($bankBranch)) {
                    return !empty($bankBranch[0]) ? $bankBranch[0] : '';
                }
                return $bankBranch;
            }
        }

        return '';
    }
}

