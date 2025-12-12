(function() {
	'use strict';

	/**
	 * Replace CHIP text with logo in FluentCart admin settings.
	 */
	function replaceChipTextWithLogo() {
		var logoUrl = chipAdminData.logoUrl;
		var logoImg = '<img src="' + logoUrl + '" alt="CHIP" style="height: 24px; vertical-align: middle;" />';

		// Replace in card header title.
		var headerTitles = document.querySelectorAll('.fct-card-header-title');
		headerTitles.forEach(function(el) {
			if (el.textContent.trim() === 'CHIP') {
				el.innerHTML = logoImg;
			}
		});

		// Replace in breadcrumb.
		var breadcrumbItems = document.querySelectorAll('.el-breadcrumb__inner');
		breadcrumbItems.forEach(function(el) {
			if (el.textContent.trim() === 'CHIP') {
				el.innerHTML = logoImg;
			}
		});
	}

	// Run on DOM ready and observe for Vue/dynamic content changes.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', replaceChipTextWithLogo);
	} else {
		replaceChipTextWithLogo();
	}

	// Use MutationObserver to catch Vue.js dynamic rendering.
	var observer = new MutationObserver(function(mutations) {
		replaceChipTextWithLogo();
	});

	observer.observe(document.body, {
		childList: true,
		subtree: true
	});
})();

