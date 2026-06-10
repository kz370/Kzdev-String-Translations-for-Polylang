/**
 * Frontend script for Manual Translations for Polylang.
 * Uses a MutationObserver to dynamically replace text nodes with translations.
 */
(function () {
	// If translations data is missing or empty, do nothing
	if (typeof manualTranslationsForPolylangData === 'undefined' || !manualTranslationsForPolylangData.translations) {
		return;
	}

	const translations = manualTranslationsForPolylangData.translations;

	// Tag names that should be ignored entirely to avoid breaking layouts or code
	const skipTags = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEXTAREA', 'INPUT', 'IFRAME', 'CODE', 'PRE']);

	/**
	 * Recursively walks the DOM tree and replaces matching text nodes.
	 *
	 * @param {Node} node The DOM node to evaluate.
	 */
	function translateNode(node) {
		if (node.nodeType === Node.TEXT_NODE) {
			const textValue = node.nodeValue;
			if (!textValue) {
				return;
			}

			const trimmed = textValue.trim();
			if (translations[trimmed]) {
				// Replace only the trimmed text part to preserve original surrounding spacing
				node.nodeValue = textValue.replace(trimmed, translations[trimmed]);
			}
		} else if (node.nodeType === Node.ELEMENT_NODE && !skipTags.has(node.tagName)) {
			// Iterate through children safely
			const children = node.childNodes;
			for (let i = 0; i < children.length; i++) {
				translateNode(children[i]);
			}
		}
	}

	/**
	 * Translates the entire body of the page.
	 */
	function translatePage() {
		if (document.body) {
			translateNode(document.body);
		}
	}

	// Trigger translation on load
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', translatePage);
	} else {
		translatePage();
	}

	// Set up MutationObserver to watch for additions/modifications (AJAX actions, cart, modals)
	const observer = new MutationObserver(function (mutations) {
		for (let i = 0; i < mutations.length; i++) {
			const mutation = mutations[i];
			if (mutation.addedNodes) {
				for (let j = 0; j < mutation.addedNodes.length; j++) {
					translateNode(mutation.addedNodes[j]);
				}
			}
		}
	});

	/**
	 * Initialize the mutation observer safely when the body element is ready.
	 */
	function initObserver() {
		if (document.body) {
			observer.observe(document.body, {
				childList: true,
				subtree: true,
			});
		} else {
			// If body is not ready yet, check again shortly
			setTimeout(initObserver, 50);
		}
	}

	initObserver();
})();
