<?php
/**
 * Admin page and AJAX controller.
 *
 * @package           Manual_Translations_For_Polylang
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manual_Translations_Admin
 *
 * Handles admin menu, assets, CSV operations, and AJAX endpoints.
 */
class Manual_Translations_Admin {

	/**
	 * Run the admin hooks.
	 */
	public function run() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// CSV Actions
		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
		add_action( 'admin_init', array( $this, 'handle_csv_import' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_mtfp_save_translation', array( $this, 'ajax_save_translation' ) );
		add_action( 'wp_ajax_mtfp_delete_translation', array( $this, 'ajax_delete_translation' ) );
		add_action( 'wp_ajax_mtfp_bulk_delete', array( $this, 'ajax_bulk_delete' ) );
		add_action( 'wp_ajax_mtfp_scan_theme', array( $this, 'ajax_scan_theme' ) );
		add_action( 'wp_ajax_mtfp_import_scanned', array( $this, 'ajax_import_scanned' ) );
	}

	/**
	 * Get the active Polylang languages, or fallback to site locale.
	 *
	 * @return array Array of languages.
	 */
	public function get_active_languages() {
		global $polylang;
		$langs = array();

		if ( isset( $polylang ) && is_object( $polylang->model ) ) {
			$languages = $polylang->model->get_languages_list();
			foreach ( $languages as $lang ) {
				$langs[] = array(
					'slug' => $lang->slug,
					'name' => $lang->name,
				);
			}
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			$slugs = pll_languages_list();
			foreach ( $slugs as $slug ) {
				$langs[] = array(
					'slug' => $slug,
					'name' => strtoupper( $slug ),
				);
			}
		}

		// Fallback to default WordPress locale if empty or Polylang not active
		if ( empty( $langs ) ) {
			$locale = get_locale();
			$slug   = strtolower( strtok( $locale, '_-' ) );
			$langs[] = array(
				'slug' => $slug,
				'name' => 'Default (' . $locale . ')',
			);
		}

		return $langs;
	}

	/**
	 * Register submenu page under the Polylang Languages menu.
	 */
	public function register_admin_menu() {
		// Polylang's main page slug is 'mlang'.
		// Check if Polylang is active and registered the mlang page.
		$parent_slug = function_exists( 'pll_languages_list' ) ? 'mlang' : 'options-general.php';

		add_submenu_page(
			$parent_slug,
			__( 'Manual Translations', 'manual-translations-for-polylang' ),
			__( 'Manual Translations', 'manual-translations-for-polylang' ),
			'manage_options',
			'manual-translations',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue assets only on our plugin settings page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on the specific submenus
		if ( false === strpos( $hook, 'manual-translations' ) ) {
			return;
		}

		wp_enqueue_style(
			'mtfp-admin-styles',
			MTFP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MTFP_VERSION
		);

		wp_enqueue_script(
			'mtfp-admin-scripts',
			MTFP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MTFP_VERSION,
			true
		);

		// Format translations list for JavaScript usage
		$raw_data = $this->get_translations_data();
		$translations_list = array();
		foreach ( $raw_data as $hash => $row ) {
			$translations_list[] = array(
				'hash'         => $hash,
				'source'       => $row['source'],
				'translations' => $row['translations'],
			);
		}

		// Pass data and security nonces to JavaScript
		wp_localize_script(
			'mtfp-admin-scripts',
			'manualTranslationsForPolylangAdminData',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'mtfp_admin_nonce' ),
				'languages'    => $this->get_active_languages(),
				'translations' => $translations_list,
				'i18n'         => array(
					'saving'       => __( 'Saving...', 'manual-translations-for-polylang' ),
					'saved'        => __( 'Saved', 'manual-translations-for-polylang' ),
					'error'        => __( 'An error occurred.', 'manual-translations-for-polylang' ),
					'confirmDel'   => __( 'Are you sure you want to delete this translation?', 'manual-translations-for-polylang' ),
					'confirmBulk'  => __( 'Are you sure you want to delete the selected translations?', 'manual-translations-for-polylang' ),
					'noSelection'  => __( 'No items selected.', 'manual-translations-for-polylang' ),
					'emptySource'  => __( 'Source string cannot be empty.', 'manual-translations-for-polylang' ),
				),
			)
		);
	}

	/**
	 * Helper function to retrieve sanitised translations array.
	 *
	 * @return array Array of translations keyed by source md5.
	 */
	private function get_translations_data() {
		$data = get_option( 'manual_translations_strings', array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * AJAX endpoint: Add or update a translation.
	 */
	public function ajax_save_translation() {
		// Verify capability and nonce
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$source = isset( $_POST['source'] ) ? trim( wp_unslash( $_POST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $source ) {
			wp_send_json_error( array( 'message' => __( 'Source string cannot be empty.', 'manual-translations-for-polylang' ) ) );
		}

		$hash = md5( $source );
		$languages = $this->get_active_languages();
		$translations = array();

		foreach ( $languages as $lang ) {
			$slug = $lang['slug'];
			if ( isset( $_POST['translations'][ $slug ] ) ) {
				$translations[ $slug ] = sanitize_textarea_field( wp_unslash( $_POST['translations'][ $slug ] ) );
			} else {
				$translations[ $slug ] = '';
			}
		}

		$data = $this->get_translations_data();
		
		// Save item
		$data[ $hash ] = array(
			'source'       => $source,
			'translations' => $translations,
		);

		update_option( 'manual_translations_strings', $data );

		wp_send_json_success( array(
			'message' => __( 'Translation saved successfully.', 'manual-translations-for-polylang' ),
			'hash'    => $hash,
			'row'     => $data[ $hash ],
		) );
	}

	/**
	 * AJAX endpoint: Delete a translation.
	 */
	public function ajax_delete_translation() {
		// Verify capability and nonce
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( empty( $hash ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request parameters.', 'manual-translations-for-polylang' ) ) );
		}

		$data = $this->get_translations_data();

		if ( isset( $data[ $hash ] ) ) {
			unset( $data[ $hash ] );
			update_option( 'manual_translations_strings', $data );
			wp_send_json_success( array( 'message' => __( 'Translation deleted successfully.', 'manual-translations-for-polylang' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Translation not found.', 'manual-translations-for-polylang' ) ) );
	}

	/**
	 * AJAX endpoint: Bulk delete translations.
	 */
	public function ajax_bulk_delete() {
		// Verify capability and nonce
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$hashes = isset( $_POST['hashes'] ) ? map_deep( wp_unslash( $_POST['hashes'] ), 'sanitize_text_field' ) : array();
		if ( empty( $hashes ) || ! is_array( $hashes ) ) {
			wp_send_json_error( array( 'message' => __( 'No items selected.', 'manual-translations-for-polylang' ) ) );
		}

		$data = $this->get_translations_data();
		$count = 0;

		foreach ( $hashes as $hash ) {
			if ( isset( $data[ $hash ] ) ) {
				unset( $data[ $hash ] );
				$count++;
			}
		}

		if ( $count > 0 ) {
			update_option( 'manual_translations_strings', $data );
			wp_send_json_success( array( 'message' => sprintf( _n( 'Successfully deleted %d translation.', 'Successfully deleted %d translations.', $count, 'manual-translations-for-polylang' ), $count ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'No translations were deleted.', 'manual-translations-for-polylang' ) ) );
	}

	/**
	 * AJAX endpoint: Scan active theme or specific plugin folders for gettext strings.
	 */
	public function ajax_scan_theme() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		// Set execution time limit to 2 minutes for large plugin directories
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'theme';
		$dirs = array();

		if ( 'theme' === $target ) {
			$dirs[] = get_stylesheet_directory();
			if ( is_child_theme() ) {
				$dirs[] = get_template_directory();
			}
		} elseif ( 'all-plugins' === $target ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
			foreach ( $plugins as $file => $data ) {
				$dir = dirname( $file );
				if ( '.' !== $dir && '' !== $dir ) {
					// Exclude this plugin
					if ( strpos( $file, 'manual-translations-for-polylang' ) === false ) {
						$dirs[] = WP_PLUGIN_DIR . '/' . $dir;
					}
				}
			}
		} elseif ( 'all-theme-plugins' === $target ) {
			$dirs[] = get_stylesheet_directory();
			if ( is_child_theme() ) {
				$dirs[] = get_template_directory();
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugins = get_plugins();
			foreach ( $plugins as $file => $data ) {
				$dir = dirname( $file );
				if ( '.' !== $dir && '' !== $dir ) {
					// Exclude this plugin
					if ( strpos( $file, 'manual-translations-for-polylang' ) === false ) {
						$dirs[] = WP_PLUGIN_DIR . '/' . $dir;
					}
				}
			}
		} elseif ( strpos( $target, 'plugin:' ) === 0 ) {
			$plugin_folder = str_replace( 'plugin:', '', $target );
			$plugin_folder = sanitize_file_name( $plugin_folder );
			$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_folder;

			if ( is_dir( $plugin_dir ) ) {
				$dirs[] = $plugin_dir;
			} else {
				wp_send_json_error( array( 'message' => __( 'Plugin directory not found.', 'manual-translations-for-polylang' ) ) );
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Invalid scan target parameter.', 'manual-translations-for-polylang' ) ) );
		}

		$dirs = array_unique( $dirs );
		$found_strings = array();
		foreach ( $dirs as $dir ) {
			$found_strings = array_merge( $found_strings, $this->scan_directory_for_strings( $dir ) );
		}
		$found_strings = array_unique( $found_strings );

		// Filter out strings already in our database
		$existing_data = $this->get_translations_data();
		$existing_sources = array_map( 'strtolower', wp_list_pluck( $existing_data, 'source' ) );

		$new_untranslated = array();
		foreach ( $found_strings as $str ) {
			if ( ! in_array( strtolower( $str ), $existing_sources, true ) ) {
				$new_untranslated[] = $str;
			}
		}

		// Sort strings alphabetically
		sort( $new_untranslated );

		wp_send_json_success( array(
			'strings' => $new_untranslated,
		) );
	}

	/**
	 * Recursively scan directory for PHP files and extract gettext strings.
	 */
	private function scan_directory_for_strings( $dir ) {
		$strings = array();
		if ( ! is_dir( $dir ) ) {
			return $strings;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		// Match first argument of standard gettext functions: __, _e, esc_html__, esc_html_e, esc_attr__, esc_attr_e, _x, _ex
		$pattern = '/\b(?:__|__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex)\(\s*([\'"])((?:\\\\|\\\\\1|(?!\1).)*)\1/s';

		foreach ( $files as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$content = file_get_contents( $file->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( empty( $content ) ) {
				continue;
			}

			if ( preg_match_all( $pattern, $content, $matches ) ) {
				if ( ! empty( $matches[2] ) ) {
					foreach ( $matches[2] as $match ) {
						// Strip slashes from escaped quotes inside strings
						$str = stripcslashes( $match );
						$str = trim( $str );
						if ( strlen( $str ) > 1 && ! is_numeric( $str ) ) {
							$strings[ $str ] = true;
						}
					}
				}
			}
		}

		return array_keys( $strings );
	}

	/**
	 * AJAX endpoint: Import selected scanned strings.
	 */
	public function ajax_import_scanned() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$strings = isset( $_POST['strings'] ) ? map_deep( wp_unslash( $_POST['strings'] ), 'sanitize_text_field' ) : array();
		if ( empty( $strings ) || ! is_array( $strings ) ) {
			wp_send_json_error( array( 'message' => __( 'No strings selected.', 'manual-translations-for-polylang' ) ) );
		}

		$languages = $this->get_active_languages();
		$lang_slugs = wp_list_pluck( $languages, 'slug' );
		$data = $this->get_translations_data();
		$imported = array();

		foreach ( $strings as $source ) {
			$source = trim( $source );
			if ( '' === $source ) {
				continue;
			}

			$hash = md5( $source );
			if ( ! isset( $data[ $hash ] ) ) {
				$data[ $hash ] = array(
					'source'       => $source,
					'translations' => array_fill_keys( $lang_slugs, '' ),
				);
				$imported[] = array(
					'hash'         => $hash,
					'source'       => $source,
					'translations' => $data[ $hash ]['translations'],
				);
			}
		}

		if ( ! empty( $imported ) ) {
			update_option( 'manual_translations_strings', $data );
			wp_send_json_success( array(
				'message'  => sprintf( _n( 'Successfully imported %d string.', 'Successfully imported %d strings.', count( $imported ), 'manual-translations-for-polylang' ), count( $imported ) ),
				'imported' => $imported,
			) );
		}

		wp_send_json_error( array( 'message' => __( 'No new strings were imported.', 'manual-translations-for-polylang' ) ) );
	}

	/**
	 * Handle CSV Export request.
	 */
	public function handle_csv_export() {
		if ( ! isset( $_POST['mtfp_export_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'manual-translations-for-polylang' ) );
		}

		check_admin_referer( 'mtfp_csv_export', 'mtfp_export_nonce' );

		$data = $this->get_translations_data();
		$languages = $this->get_active_languages();

		// Check if exporting selected rows
		$selected_hashes = isset( $_POST['mtfp_selected'] ) ? map_deep( wp_unslash( $_POST['mtfp_selected'] ), 'sanitize_text_field' ) : array();

		// Output CSV headers
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="manual-translations-' . date( 'Y-m-d' ) . '.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			wp_die( esc_html__( 'Failed to generate CSV output.', 'manual-translations-for-polylang' ) );
		}

		// CSV Column Headers: Source String, Language 1, Language 2...
		$headers = array( 'Source String' );
		foreach ( $languages as $lang ) {
			$headers[] = $lang['slug'];
		}
		fputcsv( $output, $headers );

		// Write translation rows
		foreach ( $data as $hash => $row ) {
			// If user selected specific rows, only export those. Otherwise export all.
			if ( ! empty( $selected_hashes ) && ! in_array( $hash, $selected_hashes, true ) ) {
				continue;
			}

			$line = array( $row['source'] );
			foreach ( $languages as $lang ) {
				$slug = $lang['slug'];
				$line[] = isset( $row['translations'][ $slug ] ) ? $row['translations'][ $slug ] : '';
			}
			fputcsv( $output, $line );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Handle CSV Import request.
	 */
	public function handle_csv_import() {
		if ( ! isset( $_POST['mtfp_import_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'manual-translations-for-polylang' ) );
		}

		check_admin_referer( 'mtfp_csv_import', 'mtfp_import_nonce' );

		// Check file was uploaded
		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			add_settings_error(
				'mtfp_messages',
				'mtfp_import_error',
				__( 'Please select a valid CSV file to import.', 'manual-translations-for-polylang' ),
				'error'
			);
			return;
		}

		$file = sanitize_meta( 'import_file', $_FILES['import_file']['tmp_name'], '' );
		if ( ! is_uploaded_file( $_FILES['import_file']['tmp_name'] ) ) {
			add_settings_error(
				'mtfp_messages',
				'mtfp_import_error',
				__( 'Invalid file upload source.', 'manual-translations-for-polylang' ),
				'error'
			);
			return;
		}

		$handle = fopen( $_FILES['import_file']['tmp_name'], 'r' );
		if ( ! $handle ) {
			add_settings_error(
				'mtfp_messages',
				'mtfp_import_error',
				__( 'Unable to open imported file.', 'manual-translations-for-polylang' ),
				'error'
			);
			return;
		}

		$languages = $this->get_active_languages();
		$lang_slugs = wp_list_pluck( $languages, 'slug' );

		// Read headers
		$headers = fgetcsv( $handle );
		if ( ! $headers || count( $headers ) < 1 || 'Source String' !== trim( $headers[0] ) ) {
			fclose( $handle );
			add_settings_error(
				'mtfp_messages',
				'mtfp_import_error',
				__( 'Invalid CSV structure. The first column must be named "Source String".', 'manual-translations-for-polylang' ),
				'error'
			);
			return;
		}

		// Map CSV header indices to language codes
		$csv_map = array();
		for ( $i = 1; $i < count( $headers ); $i++ ) {
			$col_lang = trim( $headers[ $i ] );
			if ( in_array( $col_lang, $lang_slugs, true ) ) {
				$csv_map[ $i ] = $col_lang;
			}
		}

		$mode = isset( $_POST['import_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['import_mode'] ) ) : 'merge';
		$data = ( 'overwrite' === $mode ) ? array() : $this->get_translations_data();
		$count = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( empty( $row ) || ! isset( $row[0] ) || '' === trim( $row[0] ) ) {
				continue;
			}

			$source = trim( $row[0] );
			$hash   = md5( $source );

			// Initialize item structure
			if ( ! isset( $data[ $hash ] ) ) {
				$data[ $hash ] = array(
					'source'       => $source,
					'translations' => array_fill_keys( $lang_slugs, '' ),
				);
			}

			// Map CSV columns to languages
			for ( $i = 1; $i < count( $row ); $i++ ) {
				if ( isset( $csv_map[ $i ] ) ) {
					$lang_code = $csv_map[ $i ];
					$data[ $hash ]['translations'][ $lang_code ] = sanitize_textarea_field( $row[ $i ] );
				}
			}
			$count++;
		}

		fclose( $handle );

		update_option( 'manual_translations_strings', $data );

		add_settings_error(
			'mtfp_messages',
			'mtfp_import_success',
			sprintf( _n( 'Successfully imported %d translation string.', 'Successfully imported %d translation strings.', $count, 'manual-translations-for-polylang' ), $count ),
			'success'
		);
	}

	/**
	 * Render the administrative page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'manual-translations-for-polylang' ) );
		}

		$languages = $this->get_active_languages();
		$data      = $this->get_translations_data();

		// Output settings notices
		settings_errors( 'mtfp_messages' );
		?>
		<div class="wrap mtfp-admin-wrap">
			<!-- Header Title Row -->
			<div class="mtfp-page-title-row" style="flex-wrap: wrap;">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Manual Translations', 'manual-translations-for-polylang' ); ?></h1>
				<button type="button" class="page-title-action mtfp-trigger-add-row" style="margin-right: 8px;">
					<span class="dashicons dashicons-plus"></span>
					<?php esc_html_e( 'Add New Translation', 'manual-translations-for-polylang' ); ?>
				</button>

				<div class="mtfp-scan-group">
					<select id="mtfp-scan-target" class="mtfp-select">
						<option value="theme"><?php esc_html_e( 'Scan: Active Theme', 'manual-translations-for-polylang' ); ?></option>
						<option value="all-plugins"><?php esc_html_e( 'Scan: All Plugins', 'manual-translations-for-polylang' ); ?></option>
						<option value="all-theme-plugins"><?php esc_html_e( 'Scan: All Plugins + Theme', 'manual-translations-for-polylang' ); ?></option>
						<?php
						if ( ! function_exists( 'get_plugins' ) ) {
							require_once ABSPATH . 'wp-admin/includes/plugin.php';
						}
						$all_plugins = get_plugins();
						foreach ( $all_plugins as $plugin_file => $plugin_data ) {
							$dir = dirname( $plugin_file );
							if ( '.' !== $dir && '' !== $dir ) {
								// Do not include this plugin itself
								if ( strpos( $plugin_file, 'manual-translations-for-polylang' ) === false ) {
									echo '<option value="plugin:' . esc_attr( $dir ) . '">' . sprintf( esc_html__( 'Scan Plugin: %s', 'manual-translations-for-polylang' ), esc_html( $plugin_data['Name'] ) ) . '</option>';
								}
							}
						}
						?>
					</select>
					<button type="button" class="page-title-action mtfp-trigger-scan">
						<span class="dashicons dashicons-search"></span>
						<?php esc_html_e( 'Scan', 'manual-translations-for-polylang' ); ?>
					</button>
				</div>
			</div>

			<!-- Scan Results Container -->
			<div id="mtfp-scan-results-container"></div>

			<!-- Main Content Table (Full Width) -->
			<div class="mtfp-card mtfp-card-table">
				<!-- Table Actions Bar -->
				<div class="mtfp-table-actions">
					<!-- Bulk Actions & Search -->
					<div class="mtfp-bulk-action-group">
						<select id="mtfp-bulk-action" class="mtfp-select">
							<option value=""><?php esc_html_e( 'Bulk Actions', 'manual-translations-for-polylang' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete Selected', 'manual-translations-for-polylang' ); ?></option>
						</select>
						<button id="mtfp-apply-bulk" class="button mtfp-btn-secondary" type="button"><?php esc_html_e( 'Apply', 'manual-translations-for-polylang' ); ?></button>
					</div>

					<!-- Per Page Selector & Live Search -->
					<div class="mtfp-filter-group" style="display: flex; gap: 16px; align-items: center;">
						<div class="mtfp-per-page-container" style="display: flex; gap: 6px; align-items: center; font-size: 13px; color: var(--mtfp-text-muted);">
							<label for="mtfp-per-page"><?php esc_html_e( 'Show', 'manual-translations-for-polylang' ); ?></label>
							<select id="mtfp-per-page" class="mtfp-select">
								<option value="10">10</option>
								<option value="20" selected>20</option>
								<option value="50">50</option>
								<option value="100">100</option>
							</select>
							<span><?php esc_html_e( 'per page', 'manual-translations-for-polylang' ); ?></span>
						</div>

						<div class="mtfp-search-container">
							<input type="search" id="mtfp-search" class="mtfp-input" placeholder="<?php esc_attr_e( 'Search strings...', 'manual-translations-for-polylang' ); ?>" />
						</div>
					</div>
				</div>

				<!-- Export Selected Form -->
				<form id="mtfp-table-form" method="post" action="">
					<?php wp_nonce_field( 'mtfp_csv_export', 'mtfp_export_nonce' ); ?>
					<input type="hidden" name="mtfp_export_action" value="1" />

					<!-- Custom Premium Table -->
					<div class="mtfp-table-responsive">
						<table class="mtfp-table">
							<thead>
								<tr>
									<th class="mtfp-col-cb">
										<input type="checkbox" id="mtfp-select-all" />
									</th>
									<th class="mtfp-col-source"><?php esc_html_e( 'Source String', 'manual-translations-for-polylang' ); ?></th>
									<?php foreach ( $languages as $lang ) : ?>
										<th><?php echo esc_html( $lang['name'] ); ?> <span class="mtfp-lang-code">(<?php echo esc_html( $lang['slug'] ); ?>)</span></th>
									<?php endforeach; ?>
									<th class="mtfp-col-actions"><?php esc_html_e( 'Actions', 'manual-translations-for-polylang' ); ?></th>
								</tr>
							</thead>
							<tbody id="mtfp-translations-list">
								<!-- Dynamically rendered by JavaScript -->
							</tbody>
						</table>
					</div>

					<!-- Table Footer with Export -->
					<div class="mtfp-table-footer">
						<div class="mtfp-footer-actions">
							<button type="submit" class="button mtfp-btn-secondary" id="mtfp-export-selected">
								<span class="dashicons dashicons-download"></span>
								<?php esc_html_e( 'Export Selected to CSV', 'manual-translations-for-polylang' ); ?>
							</button>
						</div>
						<!-- Dynamic table info and pagination links -->
						<div class="mtfp-pagination-group" style="display: flex; gap: 20px; align-items: center;">
							<div id="mtfp-table-info" class="mtfp-table-info" style="font-size: 13px; color: var(--mtfp-text-muted);"></div>
							<div id="mtfp-pagination" class="mtfp-pagination"></div>
						</div>
					</div>
				</form>
			</div>

			<!-- Bottom Tools Grid -->
			<div class="mtfp-bottom-grid">
				<!-- Card: CSV Import -->
				<div class="mtfp-card mtfp-card-import">
					<h2><?php esc_html_e( 'Import CSV', 'manual-translations-for-polylang' ); ?></h2>
					<form method="post" enctype="multipart/form-data" action="">
						<?php wp_nonce_field( 'mtfp_csv_import', 'mtfp_import_nonce' ); ?>
						<input type="hidden" name="mtfp_import_action" value="1" />

						<div class="mtfp-form-group">
							<label for="import_file"><?php esc_html_e( 'Select CSV File', 'manual-translations-for-polylang' ); ?></label>
							<input type="file" id="import_file" name="import_file" accept=".csv" required />
						</div>

						<div class="mtfp-form-group">
							<label><?php esc_html_e( 'Import Mode', 'manual-translations-for-polylang' ); ?></label>
							<div class="mtfp-radio-group">
								<label class="mtfp-radio-label">
									<input type="radio" name="import_mode" value="merge" checked />
									<span><strong><?php esc_html_e( 'Merge', 'manual-translations-for-polylang' ); ?></strong> – <?php esc_html_e( 'Keep existing records; add new ones and update matches.', 'manual-translations-for-polylang' ); ?></span>
								</label>
								<label class="mtfp-radio-label">
									<input type="radio" name="import_mode" value="overwrite" />
									<span><strong><?php esc_html_e( 'Overwrite', 'manual-translations-for-polylang' ); ?></strong> – <?php esc_html_e( 'Erase all current translations and replace with CSV values.', 'manual-translations-for-polylang' ); ?></span>
								</label>
							</div>
						</div>

						<button type="submit" class="button mtfp-btn-secondary full-width">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Upload and Import', 'manual-translations-for-polylang' ); ?>
						</button>
					</form>
				</div>

				<!-- Card: CSV Export All -->
				<div class="mtfp-card mtfp-card-export-all">
					<h2><?php esc_html_e( 'Export CSV', 'manual-translations-for-polylang' ); ?></h2>
					<p class="description" style="margin-bottom: 20px;">
						<?php esc_html_e( 'Download a backup of all manual translations in CSV format.', 'manual-translations-for-polylang' ); ?>
					</p>
					<form method="post" action="">
						<?php wp_nonce_field( 'mtfp_csv_export', 'mtfp_export_nonce' ); ?>
						<input type="hidden" name="mtfp_export_action" value="1" />
						<button type="submit" class="button mtfp-btn-primary full-width">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Export All to CSV', 'manual-translations-for-polylang' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
