<?php

/**
 * Plugin Name: Manual Translations for Polylang
 * Plugin URI: https://github.com/kz370/manual-translations-for-polylang
 * Description: Manually translate frontend strings using MutationObserver with Polylang language support.
 * Version: 1.0.0
 * Author: kz370
 * Author URI: https://github.com/kz370
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Requires Plugins: polylang
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: manual-translations-for-polylang
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (! defined('ABSPATH')) {
	exit;
}

// Define plugin constants.
define('MTFP_VERSION', '1.0.0');
define('MTFP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MTFP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MTFP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Autoload classes or include them directly.
 * Since this is a simple, lightweight plugin, we will include files directly.
 */
require_once MTFP_PLUGIN_DIR . 'includes/class-manual-translations.php';
require_once MTFP_PLUGIN_DIR . 'includes/class-manual-translations-admin.php';

/**
 * Activate the plugin.
 */
function mtfp_activate_plugin()
{
	// Initialize default settings option if it doesn't exist
	if (false === get_option('manual_translations_strings')) {
		update_option('manual_translations_strings', array());
	}
}
register_activation_hook(__FILE__, 'mtfp_activate_plugin');

/**
 * Deactivate the plugin.
 */
function mtfp_deactivate_plugin()
{
	// Clean up if necessary, but keep translation options so users don't lose data on deactivation.
}
register_deactivation_hook(__FILE__, 'mtfp_deactivate_plugin');

/**
 * Run the plugin classes.
 */
function mtfp_run_manual_translations()
{
	// Load the core class
	$plugin = new Manual_Translations();
	$plugin->run();

	// Load the admin page and AJAX class
	if (is_admin()) {
		$admin = new Manual_Translations_Admin();
		$admin->run();
	}
}
add_action('plugins_loaded', 'mtfp_run_manual_translations');
