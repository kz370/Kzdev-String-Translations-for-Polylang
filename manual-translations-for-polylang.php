<?php
/**
 * Plugin Name:       Manual Translations for Polylang
 * Plugin URI:        https://github.com/google-deepmind/antigravity
 * Description:       Manually translate specific frontend strings using a MutationObserver, supporting all active Polylang languages. Simplifies translation tweaks with CSV import/export.
 * Version:           1.0.0
 * Author:            Antigravity
 * Author URI:        https://github.com/google-deepmind/antigravity
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       manual-translations-for-polylang
 * Domain Path:       /languages
 *
 * @package           Manual_Translations_For_Polylang
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'MTFP_VERSION', '1.0.0' );
define( 'MTFP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MTFP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MTFP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload classes or include them directly.
 * Since this is a simple, lightweight plugin, we will include files directly.
 */
require_once MTFP_PLUGIN_DIR . 'includes/class-manual-translations.php';
require_once MTFP_PLUGIN_DIR . 'includes/class-manual-translations-admin.php';

/**
 * Activate the plugin.
 */
function mtfp_activate_plugin() {
	// Initialize default settings option if it doesn't exist
	if ( false === get_option( 'manual_translations_strings' ) ) {
		update_option( 'manual_translations_strings', array() );
	}
}
register_activation_hook( __FILE__, 'mtfp_activate_plugin' );

/**
 * Deactivate the plugin.
 */
function mtfp_deactivate_plugin() {
	// Clean up if necessary, but keep translation options so users don't lose data on deactivation.
}
register_deactivation_hook( __FILE__, 'mtfp_deactivate_plugin' );

/**
 * Run the plugin classes.
 */
function mtfp_run_manual_translations() {
	// Load the core class
	$plugin = new Manual_Translations();
	$plugin->run();

	// Load the admin page and AJAX class
	if ( is_admin() ) {
		$admin = new Manual_Translations_Admin();
		$admin->run();
	}
}
add_action( 'plugins_loaded', 'mtfp_run_manual_translations' );
