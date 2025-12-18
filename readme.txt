=== CHIP for FluentCart ===

Contributors: chipasia, wanzulnet
Tags: chip, fluentcart, payment
Requires at least: 6.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Accept payments via FPX, Visa, Mastercard, E-Wallets, and DuitNow QR on your FluentCart store.

== Description ==

This is an official CHIP plugin for FluentCart.

With this plugin, merchants can collect payments through multiple channels:

* **FPX** – Malaysia's online banking payment gateway.
* **Credit/Debit Cards** – Visa and Mastercard.
* **E-Wallets** – GrabPay, Touch 'n Go, ShopeePay, Atome, and more.
* **DuitNow QR** – Malaysia's national QR payment standard.

= Key Features =

* **Easy Setup** – Configure with just your Brand ID and Secret Key.
* **Real-time Callbacks (IPN)** – Order status updates automatically via webhook notifications.
* **Secure Payments** – All transactions are processed through CHIP's PCI-DSS compliant platform.
* **Refund Support** – Process refunds directly from the FluentCart dashboard.
* **Payment Method Whitelist** – Optionally restrict available payment methods.

For integration details, refer to the [API Documentation](https://docs.chip-in.asia).

== Screenshots ==

* Fill in the form with Brand ID and Secret Key. Toggle Active and Save Settings to activate.
* Checkout and pay with CHIP.
* CHIP payment page.
* FluentCart order received page.
* FluentCart dashboard order page.
* FluentCart refund order.

== Changelog ==

= 1.0.0 2025-12-30 =

* Initial release

[See changelog for all versions](https://raw.githubusercontent.com/CHIPAsia/chip-for-fluent-cart/main/changelog.txt).

== Installation ==

= Minimum Requirements =

* PHP 7.4 or greater is required.
* MySQL 5.6 or greater, OR MariaDB version 10.1 or greater, is required.
* WordPress 6.8 or higher.
* FluentCart plugin installed and activated.

= Automatic installation =

Automatic installation is the easiest option — WordPress will handle the file transfer, and you won't need to leave your web browser. To do an automatic install of CHIP for FluentCart, log in to your WordPress dashboard, navigate to the Plugins menu, and click "Add New."

In the search field, type "CHIP for FluentCart," then click "Search Plugins." Once you've found us, you can view details about it such as the point release, rating, and description. Most importantly, you can install it by clicking "Install Now," and WordPress will take it from there.

= Manual installation =

Manual installation requires downloading the CHIP for FluentCart plugin and uploading it to your web server via your favorite FTP application. The WordPress codex contains [instructions on how to do this here](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation).

= Updating =

Automatic updates should work smoothly, but we still recommend you back up your site.

= Configuration =

1. Navigate to **WordPress Dashboard** → **Plugins** → **CHIP for FluentCart** → **Settings**
   Or: **WordPress Dashboard** → **FluentCart** → **Settings** → **Payment Settings** → **CHIP**

2. Set your **Secret Key** and **Brand ID**. Optionally set **Email Fallback** and **Payment Method Whitelist**. Merchants should leave these options blank or unset to use the default settings.

3. Ensure FluentCart checkout currency is set to **Malaysian Ringgit (MYR)**.

4. Perform a test payment to verify that the integration is working correctly.

== Frequently Asked Questions ==

= Where are the Brand ID and Secret Key located? =

The Brand ID and Secret Key are available through our merchant dashboard.

= Do I need to set the public key for the webhook? =

This is optional. You may set the public key for the webhook to synchronize the card token availability.

= Where can I find documentation? =

You can visit our [API documentation](https://docs.chip-in.asia/) for your reference.

= What CHIP API services are used in this plugin? =

This plugin relies on the CHIP API ([CHIP_FOR_FLUENTCART_ROOT_URL](https://gate.chip-in.asia)) as follows:

  - **/purchases/**

    - Used for accepting payments.

  - **/purchases/<id\>/**

    - Used for getting payment status from CHIP.

  - **/purchases/<id\>/refund/**

    - Used for refunding payments.

  - **/public_key/**

    - Used for getting the public key to verify webhook signatures.

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://docs.chip-in.asia/)

[FluentCart Documentation](https://dev.fluentcart.com/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)

