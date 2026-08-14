=== Kzdev String Translations for Polylang ===
Contributors: kzdev
Tags: polylang, translation, localization, multilingual, ajax
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manually translate frontend strings on the fly with Polylang language support and CSV import/export.

== Description ==

**Kzdev String Translations for Polylang** (formerly "Manual Translations for Polylang") is a lightweight utility plugin that resolves the common issue of untranslatable frontend strings (e.g. dynamic cart widgets, checkout fragments, AJAX loaders, and JS-injected content). By hooking into Polylang's active languages, it lets you map original strings to custom translation values and applies them client-side in real-time.

This plugin is independent from Polylang: Polylang is a trademark of WP SYNTEX, the manufacturer of the Polylang plugin. This plugin is not affiliated with, endorsed by, or sponsored by WP SYNTEX.

= Features =

* Centralized translation management UI under the Polylang "Languages" menu
* Client-side text replacement engine powered by MutationObserver
* Instant translation of dynamic AJAX changes (like WooCommerce cart increments, checkout updates)
* No page reloads – fully reactive dashboard
* Selectable rows per page (10, 20, 50, 100) and live instant filtering
* One-click CSV Export (selected or all translations)
* Seamless CSV Import (Merge or Overwrite mode)
* AI Translation Helper for posts, terms, and UI strings (optional, see "External services")
* Deep security integration (nonces, user capabilities, output escaping, validated sanitization)
* Safe database-only storage in WordPress Options (no custom tables, no file clutter)
* Graceful fallback to the default site locale if Polylang is missing or disabled

= Storage =

Translations are stored safely in the database using the WordPress Options API. No custom database tables are created, and no template or script files are generated.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Open **Languages → String Translations** to start adding translations.

== FAQ ==

= Do I need Polylang to use this plugin? =

No. If Polylang is not active, the plugin falls back to the default site locale.

= Can I import or export my translations? =

Yes. Use the CSV Export and Import buttons on the plugin page. Import supports "Merge" (add new and update matches) and "Overwrite" (replace all current translations).

= Which elements are skipped on the frontend? =

Scripts, styles, textareas, inputs, and code elements are ignored to prevent breaking layouts or admin fields. Text matching is case-sensitive and trimmed of surrounding whitespace.

== Changelog ==

= 1.0.0 =
* Initial release.

== External services ==

This plugin offers an optional AI Translation Helper. When enabled by the site administrator, it sends data to an OpenAI-compatible API:

* **Service:** OpenAI-compatible Chat Completions API (configurable endpoint, defaults to `api.openai.com`).
* **Data sent:** The source text to be translated (a UI string, post title/content/excerpt, or term name/description) together with the target language, a system prompt, and the configured API key for authentication.
* **When:** Data is sent only when an administrator explicitly triggers an AI translation action and a provider (with API key) has been configured. No data is sent automatically, in the background, or on the public frontend.
* **Data storage:** The API key and model settings are stored in the WordPress database and used solely to authenticate translation requests.

* [OpenAI Terms of Use](https://openai.com/policies/terms-of-use/)
* [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy/)

Site owners are responsible for the content they submit via this feature. You can disable the AI Translation Helper at any time in the plugin settings; the plugin's manual translation features work fully without it.

== Upgrade Notice ==

= 1.0.0 =
Initial release.