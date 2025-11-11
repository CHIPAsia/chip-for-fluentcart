<?php

namespace FluentCart\App\Modules\PaymentMethods\Chip;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class ChipSettingsBase extends BaseGatewaySettings
{

    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_chip';


    public static function getDefaults(): array
    {
        return [
            'is_active'            => 'no',
            'payment_mode'         => 'live',
            'brand_id'             => '',
            'secret_key'           => '',
            'public_key'           => '',
        ];
    }

    /**
     * Get default settings for CHIP payment gateway
     *
     * @since    1.0.0
     * @return   array    Default settings array
     */
    public static function getDefaultSettings(): array
    {
        return [
            'is_active' => 'no',
            'brand_id' => '',
            'secret_key' => '',
            'public_key' => '',
            'payment_mode' => 'live',
        ];
    }

    /**
     * Get settings fields for CHIP payment gateway
     *
     * @since    1.0.0
     * @return   array    Settings fields configuration
     */
    public function getFields(): array
    {
        return [
            'is_active' => [
                'type' => 'yes_no',
                'label' => __('Enable CHIP', 'chip-for-fluentcart'),
                'default' => 'no',
                'description' => __('Enable CHIP payment gateway', 'chip-for-fluentcart')
            ],
            'brand_id' => [
                'type' => 'text',
                'label' => __('Brand ID', 'chip-for-fluentcart'),
                'required' => true,
                'description' => __('Your CHIP Brand ID', 'chip-for-fluentcart')
            ],
            'secret_key' => [
                'type' => 'password',
                'label' => __('Secret Key', 'chip-for-fluentcart'),
                'required' => true,
                'description' => __('Your CHIP secret key. The payment mode (test/live) will be determined automatically based on this key.', 'chip-for-fluentcart')
            ],
            'public_key' => [
                'type' => 'text',
                'label' => __('Public Key', 'chip-for-fluentcart'),
                'required' => false,
                'description' => __('Public key will be automatically retrieved from CHIP API when settings are saved.', 'chip-for-fluentcart'),
                'readonly' => true
            ],
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function getMode()
    {
        return $this->settings['payment_mode'] ?? 'live';
    }

    public function getPublicKey()
    {
        return $this->settings['public_key'] ?? '';
    }

    public function getApiKey()
    {
        return $this->settings['secret_key'] ?? '';
    }

    public function getBrandId()
    {
        return $this->settings['brand_id'] ?? '';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }
}

