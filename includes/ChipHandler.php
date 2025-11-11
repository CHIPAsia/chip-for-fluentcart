<?php

namespace FluentCart\App\Modules\PaymentMethods\Chip;

use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\SubscriptionHelper;

class ChipHandler {

    protected $chip;

    public function __construct(Chip $chip)
    {
        $this->chip = $chip;
    }
    
    public function handlePayment($paymentInstance)
    {
        $settings = $this->chip->settings->get();
        $order = $paymentInstance->order;
        
        if ($order->total_amount == 0) {
            return $this->handleZeroTotalPayment($paymentInstance);
        }

        if ($settings['is_active'] !== 'yes') {
            throw new \Exception(__('CHIP payment is not activated', 'chip-for-fluentcart'));
        }

        $order->payment_method_title = $this->chip->getMeta('title');
        $order->save();

        if (!$order->id) {
            throw new \Exception(__('Order not found!', 'chip-for-fluentcart'));
        }

        $paymentHelper = new PaymentHelper('chip');

        $relatedCart = Cart::query()->where('order_id', $order->id)
            ->where('stage', '!=', 'completed')
            ->first();

        if ($relatedCart) {
            $relatedCart->stage = 'completed';
            $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
            $relatedCart->save();
        }

        // TODO: Implement CHIP payment processing logic here
        // This should redirect to CHIP payment page or process payment via API
        
        return $paymentHelper->successUrl($paymentInstance->transaction->uuid);
    }

    public function handleZeroTotalPayment($paymentInstance)
    {
        $order = $paymentInstance->order;

        $transaction = $paymentInstance->transaction;
        $transaction->status = Status::TRANSACTION_SUCCEEDED;
        $transaction->save();

        (new StatusHelper($order))->syncOrderStatuses($transaction);

        $paymentHelper = new PaymentHelper('chip');
        return $paymentHelper->successUrl($transaction->uuid);
    }
}

