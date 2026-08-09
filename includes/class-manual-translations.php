<?php
/**
 * Core plugin class.
 *
 * @package           Manual_Translations_For_Polylang
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manual_Translations
 *
 * Handles core frontend hooks and localized translation injection.
 */
class Manual_Translations {

	/**
	 * Run the class actions.
	 */
	public function run() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_translations' ) );
	}

	/**
	 * Load the text domain for internationalization (i18n).
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'manual-translations-for-polylang',
			false,
			dirname( MTFP_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Get the current language slug.
	 *
	 * Falls back to the standard site locale if Polylang is not active.
	 *
	 * @return string Language slug (e.g., 'en', 'ar', 'fr').
	 */
	public function get_current_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( 'slug' );
			if ( ! empty( $lang ) ) {
				return $lang;
			}
			// Try fallback to default Polylang language
			if ( function_exists( 'pll_default_language' ) ) {
				$default = pll_default_language( 'slug' );
				if ( ! empty( $default ) ) {
					return $default;
				}
			}
		}

		// Fallback to standard WordPress locale
		$locale = get_locale();
		return strtolower( strtok( $locale, '_-' ) );
	}

	/**
	 * Enqueues the translation script on the frontend and injects translations.
	 */
	public function enqueue_frontend_translations() {
		// Do not run on login page
		if ( in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ), true ) ) {
			return;
		}

		$current_lang = $this->get_current_language();
		if ( empty( $current_lang ) ) {
			return;
		}

		// Fetch all translation definitions from option
		$all_translations = get_option( 'manual_translations_strings', array() );
		if ( empty( $all_translations ) || ! is_array( $all_translations ) ) {
			return;
		}

		// Build key-value mapping for the active frontend language
		$translation_map = array();
		foreach ( $all_translations as $item ) {
			if ( isset( $item['source'] ) && isset( $item['translations'][ $current_lang ] ) ) {
				$trimmed_source = trim( $item['source'] );
				$trimmed_translation = trim( $item['translations'][ $current_lang ] );

				if ( '' !== $trimmed_source && '' !== $trimmed_translation ) {
					$translation_map[ $trimmed_source ] = $trimmed_translation;
				}
			}
		}

		// If we don't have any translation strings for this language, we don't need to load the script
		if ( empty( $translation_map ) ) {
			return;
		}

		// Enqueue the frontend translation script
		wp_enqueue_script(
			'mtfp-frontend-translation',
			MTFP_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			filemtime( MTFP_PLUGIN_DIR . 'assets/js/frontend.js' ),
			true
		);

		// Localize translations map for script access
		wp_localize_script(
			'mtfp-frontend-translation',
			'manualTranslationsForPolylangData',
			array(
				'translations' => $translation_map,
			)
		);
	}
}
