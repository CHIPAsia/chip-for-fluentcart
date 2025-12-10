/**
 * The public-facing JavaScript for the plugin.
 *
 * @package    Chip_For_Fluentcart
 * @subpackage Chip_For_Fluentcart/public
 * @author     CHIP IN SDN. BHD. <support@chip-in.asia>
 */

(function() {
	'use strict';

	/**
	 * Listen for Fluent Cart payment gateway load event
	 * This event is fired when the CHIP payment gateway is selected on checkout
	 * 
	 * Event name format: fluent_cart_load_payments_{gateway_slug}
	 * Gateway slug is defined in Chip.php meta() method as 'slug' => 'chip'
	 */
	window.addEventListener("fluent_cart_load_payments_chip", function (e) {
		
		// Get submit button from Fluent Cart checkout variables
		const submitButton = window.fluentcart_checkout_vars?.submit_button;

		// Get the gateway container element
		// Selector format: .fluent-cart-checkout_embed_payment_container_{gateway_slug}
		const gatewayContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_chip');

		// Get translations from localized data
		// Data is provided by Chip.php getLocalizeData() method
		const translations = window.fct_chip_data?.translations || {};

		/**
		 * Translation helper function
		 * @param {string} string - The translation key
		 * @returns {string} - Translated string or original if not found
		 */
		function $t(string) {
			return translations[string] || string;
		}

		// Display payment instructions in the gateway container
		if (gatewayContainer) {
			gatewayContainer.innerHTML = `<p>${$t('CHIP - Pay securely with CHIP Collect. Accept FPX, Cards, E-Wallet, Duitnow QR.')}</p>`;
		}

		// Enable the checkout button
		// When clicked, Fluent Cart will process the payment via makePaymentFromPaymentInstance()
		// and redirect to CHIP payment page
		if (e.detail && e.detail.paymentLoader && submitButton) {
			e.detail.paymentLoader.enableCheckoutButton(submitButton.text);
		}

	});

})();

