<?php

namespace FluentCart\App\Modules\PaymentMethods\Chip;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;


class Chip extends AbstractPaymentGateway
{
    public array $supportedFeatures = ['payment', 'refund', 'webhook'];

    public BaseGatewaySettings $settings;

    public function boot()
    {
        add_action('fluent_cart/payment_paid', [$this, 'handlePaymentPaid'], 10, 1);
        
        // Register webhook handlers
        add_action('wp_ajax_chip_webhook', [$this, 'handleWebhook']);
        add_action('wp_ajax_nopriv_chip_webhook', [$this, 'handleWebhook']);
        
        // Register settings filter
        add_filter('fluent_cart/payment_methods/chip_settings', [$this, 'getSettings']);
    }

    public function meta(): array
    {
        return [
            'title' => __('CHIP', 'chip-for-fluentcart'),
            'route' => 'chip',
            'slug' => 'chip',
            'description' => esc_html__('Pay securely with CHIP payment gateway', 'chip-for-fluentcart'),
            // TODO: Add logo and icon
            'logo' => plugin_dir_url( dirname( __FILE__ ) ) . 'admin/images/chip-logo.svg',
            'icon' => plugin_dir_url( dirname( __FILE__ ) ) . 'admin/images/chip-icon.svg',
            'brand_color' => '#136196',
            'upcoming' => false,
            'status' => $this->settings->get('is_active') === 'yes',
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function __construct()
    {
        parent::__construct(
            new ChipSettingsBase()
        );
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        try {
            $order = $paymentInstance->order;
            $transaction = $paymentInstance->transaction;

            // Prepare payment data
            $paymentData = [
                'amount' => $transaction->total,
                'currency' => $transaction->currency,
                'order_id' => $order->uuid,
                'customer_email' => $order->email,
                'return_url' => $this->getReturnUrl($transaction),
                'cancel_url' => ''
                // 'cancel_url' => $this->getCancelUrl($transaction)
            ];

            // Process payment with gateway API
            $result = $this->processPayment($paymentData);

            if ($result['success']) {
                return [
                    'status' => 'success',
                    'message' => __('Order has been placed successfully', 'chip-for-fluentcart'),
                    'redirect_to' => $result['redirect_url'],
                    'payment_id' => $result['payment_id'] ?? null
                ];
            }

            return [
                'status' => 'failed',
                'message' => $result['error_message'] ?? __('Payment processing failed', 'chip-for-fluentcart')
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
     * Process payment with CHIP API
     *
     * @since    1.0.0
     * @param    array    $paymentData    Payment data
     * @return   array                     Result array with success, redirect_url, payment_id, or error_message
     */
    protected function processPayment($paymentData)
    {
        // TODO: Implement CHIP API payment processing
        // This should call CHIP API to create a payment and return the redirect URL
        
        try {
            // Validate settings
            $settings = $this->settings->get();
            if ($settings['is_active'] !== 'yes') {
                return [
                    'success' => false,
                    'error_message' => __('CHIP payment is not activated', 'chip-for-fluentcart')
                ];
            }

            // TODO: Make API call to CHIP
            // Example structure:
            // $chipApi = new ChipApi($settings);
            // $response = $chipApi->createPayment($paymentData);
            
            // For now, return a placeholder structure
            return [
                'success' => false,
                'error_message' => __('CHIP payment processing not yet implemented', 'chip-for-fluentcart')
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error_message' => $e->getMessage()
            ];
        }
    }

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

        //if new status is processing and payment_method is chip and fulfillment_type is digital then make it completed
        if ($orderStatus === Status::ORDER_PROCESSING && $paymentMethod === 'chip' && $fulfillmentType === 'digital') {
            $order->status = Status::ORDER_COMPLETED;
            $order->completed_at = DateTime::gmtNow();
            $order->save();

            $actionActivity = [
                'title'   => __('Order status updated', 'chip-for-fluentcart'),
                'content' => sprintf(__('Order status has been updated from %s to %s', 'chip-for-fluentcart'), $orderStatus, $order->status)
            ];

            (new OrderStatusUpdated($order, $orderStatus, 'completed', true,  $actionActivity, 'order_status'))->dispatch();

            $this->maybeUpdateSubscription($params);

        } else if($orderStatus === Status::ORDER_PROCESSING && $paymentMethod === 'chip' && $fulfillmentType === 'physical') {
            $this->maybeUpdateSubscription($params);
        }

        return;
    }

    public function maybeUpdateSubscription($params)
    {
        $order = Arr::get($params, 'order');
        $orderId = Arr::get($params, 'order.id');
        $orderStatus = Arr::get($params, 'order.status');

        if (!$order) {
            return;
        }

        if (!in_array($orderStatus, [Status::ORDER_PROCESSING, Status::ORDER_COMPLETED])) {
            return;
        }

        // just search in subscription table with order id get every subscription
        $subscriptions = Subscription::query()->where('parent_order_id', $orderId)->get();

        // update subscription status
        foreach ($subscriptions as $subscription) {
            if ($subscription->status === 'active') {
                continue;
            }
            $subscription->status = 'active';
            $subscription->save();
        }
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-checkout-handler-chip',
                'src' => plugin_dir_url( dirname( __FILE__ ) ) . 'public/js/chip-checkout.js',
            ]
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_chip_data' => [
                'translations' => [
                    'Pay securely with CHIP payment gateway' => __('Pay securely with CHIP payment gateway', 'chip-for-fluentcart'),
                ]
            ]
        ];
    }

    public function fields()
    {
        return array(
            'chip_description' => array(
                'value' =>
                    wp_kses(sprintf(
                        "<div class='pt-4'>
                            <p>%s</p>
                        </div>",
                        __('✅  CHIP is a secure payment gateway that allows customers to pay for their orders online. Configure your API credentials to enable CHIP payments.', 'chip-for-fluentcart')
                    ),
                    [
                        'p' => [],
                        'div' => ['class' => true],
                        'i' => [],
                    ]),
                'label' => __('Description', 'chip-for-fluentcart'),
                'type' => 'html_attr'
            ),
        );

    }

    public static function validateSettings($data): array
    {
        return [
            'status' => 'success',
            'message' => __('Settings saved successfully', 'chip-for-fluentcart')
        ];
    }

    public function maybeUpdatePayments($orderHash)
    {
        $updateData = [
            'status' => Status::PAYMENT_PENDING
        ];
        $order = OrderResource::getOrderByHash($orderHash);
        $this->updateOrderDataByOrder($order, $updateData);
    }

    public function refund($refundInfo, $orderData, $transaction)
    {
        // TODO: Implement refund process for CHIP
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function handleIPN()
    {
        $webhookData = $this->getWebhookData();

        if (!$this->verifyWebhookSignature($webhookData)) {
            http_response_code(400);
            exit('Invalid signature');
        }

        $this->processWebhook($webhookData);

        http_response_code(200);
        exit('OK');
    }

    /**
     * Get webhook data from JSON body
     *
     * @since    1.0.0
     * @return   array    Webhook data
     */
    protected function getWebhookData()
    {
        // Get raw JSON input
        $rawInput = file_get_contents('php://input');
        
        // Decode JSON
        $webhookData = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $webhookData;
    }

    /**
     * Verify webhook signature from CHIP
     *
     * @since    1.0.0
     * @param    array    $webhookData    Webhook data
     * @return   bool                      True if signature is valid
     */
    protected function verifyWebhookSignature($webhookData)
    {
        // TODO: Implement CHIP webhook signature verification
        // CHIP typically sends a signature header that needs to be verified
        
        $settings = $this->settings->get();
        $secretKey = $settings['secret_key'] ?? '';

        if (empty($secretKey)) {
            return false;
        }

        // Get signature from headers
        $signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';
        
        if (empty($signature)) {
            return false;
        }

        // Get raw input for signature verification
        $rawInput = file_get_contents('php://input');
        
        // TODO: Implement actual CHIP signature verification algorithm
        // This typically involves creating a hash of the payload with the secret key
        // Example: $expectedSignature = hash_hmac('sha256', $rawInput, $secretKey);
        // return hash_equals($expectedSignature, $signature);
        
        // For now, return true (implement proper verification)
        return true;
    }

    /**
     * Process webhook data from CHIP
     *
     * @since    1.0.0
     * @param    array    $webhookData    Webhook data
     */
    protected function processWebhook($webhookData)
    {
        // TODO: Implement webhook processing logic
        // This should handle different webhook events from CHIP (payment.success, payment.failed, etc.)
        
        if (empty($webhookData)) {
            return;
        }

        // Extract relevant data from webhook
        $eventType = $webhookData['event'] ?? '';
        $paymentData = $webhookData['data'] ?? [];
        $orderId = $paymentData['order_id'] ?? $paymentData['reference'] ?? '';

        // Get order from webhook data
        $order = $this->getOrderFromWebhook($orderId);
        
        if (!$order) {
            return;
        }

        // Process based on event type
        switch ($eventType) {
            case 'payment.success':
            case 'payment.completed':
                $this->updateOrderStatus($order, Status::ORDER_PROCESSING);
                break;
            
            case 'payment.failed':
            case 'payment.cancelled':
                $this->updateOrderStatus($order, Status::ORDER_FAILED);
                break;
            
            case 'payment.refunded':
                // TODO: Handle refund
                break;
            
            default:
                // Log unknown event type
                break;
        }
    }

    /**
     * Handle webhook requests from CHIP
     *
     * @since    1.0.0
     */
    public function handleWebhook()
    {
        // TODO: Implement webhook handler for CHIP
        // This method will process webhook callbacks from CHIP payment gateway
    }

    /**
     * Get order from webhook data
     *
     * @since    1.0.0
     * @param    string    $orderId    Order ID or UUID
     * @return   object|null            Order object or null if not found
     */
    protected function getOrderFromWebhook($orderId)
    {
        if (empty($orderId)) {
            return null;
        }

        // Try to find by UUID first
        $order = Order::where('uuid', $orderId)->first();
        
        // If not found by UUID, try by ID
        if (!$order && is_numeric($orderId)) {
            $order = OrderResource::getQuery()->find($orderId);
        }

        return $order;
    }

    /**
     * Update order status
     *
     * @since    1.0.0
     * @param    object    $order    Order object
     * @param    string    $status   New status
     */
    protected function updateOrderStatus($order, $status)
    {
        if (!$order) {
            return;
        }

        $oldStatus = $order->status;
        $order->status = $status;
        
        // Set completed_at if status is completed
        if ($status === Status::ORDER_COMPLETED) {
            $order->completed_at = DateTime::gmtNow();
        }
        
        $order->save();

        // Dispatch order status updated event
        $actionActivity = [
            'title'   => __('Order status updated', 'chip-for-fluentcart'),
            'content' => sprintf(__('Order status has been updated from %s to %s via CHIP webhook', 'chip-for-fluentcart'), $oldStatus, $status)
        ];

        (new OrderStatusUpdated($order, $oldStatus, $status, true, $actionActivity, 'order_status'))->dispatch();
    }

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
}

