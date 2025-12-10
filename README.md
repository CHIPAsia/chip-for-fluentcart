<img src="./assets/logo.svg" alt="CHIP Logo" width="50"/>

# CHIP for Fluent Cart

This module adds CHIP payment method option to your WordPress-based Fluent Cart store.

## Installation

* [Download zip file ofFluent Cart plugin.](https://github.com/CHIPAsia/chip-for-fluent-cart/releases/latest)

* Log in to your WordPress admin panel and go: **Plugins** -> **Add New**

* Select **Upload Plugin**, choose zip file you downloaded in step 1 and press **Install Now**

* Activate plugin

## Requirements

* WordPress 6.8 or higher
* Fluent Cart plugin installed and activated
* PHP 7.4 or higher

## Configuration

1. Navigate to **FluentCart** -> **Settings** -> **Payment Settings**

2. Find **CHIP** in the payment methods list and click manage to configure

3. Set the following required fields:
   - **Brand ID**: Your CHIP Brand ID
   - **Secret Key**: Your CHIP Secret Key

4. Optional settings:
   - **Email Fallback**: Fallback email address for purchase creation
   - **Payment Method Whitelist**: Select specific payment methods to enable (leave empty to enable all)

5. Toggle **Payment Activation** to enable the payment method

6. Click **Save Settings**

## Features

* Support for multiple payment methods:
  * Online Banking (FPX)
  * Corporate Online Banking (FPX)
  * Credit/Debit Cards (Visa, Mastercard, Maestro)
  * E-Wallets (Atome, GrabPay, ShopeePay, TnG)
  * QR Payments (DuitNow QR, MaybankQR)
  * Digital Wallets (Google Pay, Apple Pay)
* Automatic payment mode detection (test/live) based on Secret Key
* Payment method whitelist configuration
* Debug logging for troubleshooting
* Webhook support for payment status updates
* Automatic order status updates
* Subscription support

## Known Issues

* None at the moment. If you encounter any issues, please report them on [GitHub Issues](https://github.com/CHIPAsia/chip-for-fluent-cart/issues).

## Support

For support and documentation, visit:
* [CHIP API Documentation](https://docs.chip-in.asia/)
* [FluentCart Documentation](https://dev.fluentcart.com/)

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)

