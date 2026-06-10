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

		// Register term translation hooks dynamically on admin_init
		add_action( 'admin_init', array( $this, 'register_term_translation_hooks' ) );

		// Register post translation metabox
		add_action( 'add_meta_boxes', array( $this, 'register_post_translation_metabox' ) );

		// AJAX Endpoints
		add_action( 'wp_ajax_mtfp_save_translation', array( $this, 'ajax_save_translation' ) );
		add_action( 'wp_ajax_mtfp_delete_translation', array( $this, 'ajax_delete_translation' ) );
		add_action( 'wp_ajax_mtfp_bulk_delete', array( $this, 'ajax_bulk_delete' ) );
		add_action( 'wp_ajax_mtfp_scan_theme', array( $this, 'ajax_scan_theme' ) );
		add_action( 'wp_ajax_mtfp_import_scanned', array( $this, 'ajax_import_scanned' ) );
		add_action( 'wp_ajax_mtfp_save_ai_settings', array( $this, 'ajax_save_ai_settings' ) );
		add_action( 'wp_ajax_mtfp_ai_translate', array( $this, 'ajax_ai_translate' ) );
		add_action( 'wp_ajax_mtfp_create_post_translation', array( $this, 'ajax_create_post_translation' ) );
		add_action( 'wp_ajax_mtfp_create_term_translation', array( $this, 'ajax_create_term_translation' ) );
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
	 * Enqueue assets on our plugin settings page, post edit screens, and term edit screens.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		$is_settings_page = ( false !== strpos( $hook, 'manual-translations' ) );
		$is_post_edit     = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		$is_term_edit     = in_array( $hook, array( 'term.php', 'edit-tags.php' ), true );

		if ( ! $is_settings_page && ! $is_post_edit && ! $is_term_edit ) {
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

		// Retrieve current AI settings
		$ai_settings = get_option( 'manual_translations_ai_settings', array( 'provider' => 'none' ) );

		// Pass data and security nonces to JavaScript
		$localize_data = array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'mtfp_admin_nonce' ),
			'languages'  => $this->get_active_languages(),
			'aiSettings' => array(
				'provider' => isset( $ai_settings['provider'] ) ? $ai_settings['provider'] : 'none',
			),
			'i18n'       => array(
				'saving'       => __( 'Saving...', 'manual-translations-for-polylang' ),
				'saved'        => __( 'Saved', 'manual-translations-for-polylang' ),
				'error'        => __( 'An error occurred.', 'manual-translations-for-polylang' ),
				'confirmDel'   => __( 'Are you sure you want to delete this translation?', 'manual-translations-for-polylang' ),
				'confirmBulk'  => __( 'Are you sure you want to delete the selected translations?', 'manual-translations-for-polylang' ),
				'noSelection'  => __( 'No items selected.', 'manual-translations-for-polylang' ),
				'emptySource'  => __( 'Source string cannot be empty.', 'manual-translations-for-polylang' ),
			),
		);

		if ( $is_settings_page ) {
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
			$localize_data['translations'] = $translations_list;
		}

		if ( $is_post_edit ) {
			$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
			$post = get_post( $post_id );
			if ( $post ) {
				$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : '';
				$localize_data['postDetails'] = array(
					'id'        => $post->ID,
					'post_type' => $post->post_type,
					'title'     => $post->post_title,
					'content'   => $post->post_content,
					'excerpt'   => $post->post_excerpt,
					'lang'      => $lang,
				);
			}
		}

		if ( $is_term_edit ) {
			$term_id = isset( $_GET['tag_ID'] ) ? (int) $_GET['tag_ID'] : 0;
			$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_text_field( $_GET['taxonomy'] ) : '';
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term->term_id ) : '';
				$localize_data['termDetails'] = array(
					'id'          => $term->term_id,
					'taxonomy'    => $term->taxonomy,
					'name'        => $term->name,
					'description' => $term->description,
					'lang'        => $lang,
				);
			}
		}

		wp_localize_script(
			'mtfp-admin-scripts',
			'manualTranslationsForPolylangAdminData',
			$localize_data
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

		// Set execution time limit to 2 minutes for large plugin directories or post queries
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : 'theme';
		$found_strings = array();

		if ( 'wp-content' === $target ) {
			// Query pages, posts, and templates (including elementor_library)
			$args = array(
				'post_type'      => array( 'post', 'page', 'elementor_library' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
			);
			$posts = get_posts( $args );
			$wp_strings = array();

			$has_polylang = function_exists( 'pll_default_language' ) && function_exists( 'pll_get_post_language' ) && function_exists( 'pll_get_post_translations' );
			$default_lang = $has_polylang ? pll_default_language() : '';

			foreach ( $posts as $post ) {
				if ( $has_polylang ) {
					$post_lang = pll_get_post_language( $post->ID );
					// 1. Skip posts that are not in the default language (e.g. translated Arabic posts)
					if ( $post_lang && $post_lang !== $default_lang ) {
						continue;
					}
					// 2. Skip posts that are already translated natively
					$translations = pll_get_post_translations( $post->ID );
					if ( is_array( $translations ) && count( $translations ) > 1 ) {
						continue;
					}
				}

				// Title
				$title = trim( $post->post_title );
				if ( ! empty( $title ) && ! is_numeric( $title ) && strlen( $title ) > 1 ) {
					$wp_strings[ $title ] = true;
				}

				// Content
				$content = trim( strip_tags( $post->post_content ) );
				if ( ! empty( $content ) ) {
					// Split by common delimiters (newline, periods, brackets, etc.)
					$lines = preg_split( '/[\r\n\.]+/', $content );
					foreach ( $lines as $line ) {
						$line = trim( html_entity_decode( $line ) );
						// Only capture text sentences/words, filtering out numbers/JSON/JS
						if ( strlen( $line ) > 2 && strlen( $line ) < 120 && ! is_numeric( $line ) && ! preg_match( '/^[{}\[\]"\'\:]+$/', $line ) ) {
							$wp_strings[ $line ] = true;
						}
					}
				}
			}

			// Also query taxonomies (categories, tags, etc.)
			$taxonomies = array( 'category', 'post_tag', 'elementor_library_category' );
			if ( taxonomy_exists( 'product_cat' ) ) {
				$taxonomies[] = 'product_cat';
			}
			if ( taxonomy_exists( 'product_tag' ) ) {
				$taxonomies[] = 'product_tag';
			}

			$has_polylang_terms = function_exists( 'pll_default_language' ) && function_exists( 'pll_get_term_language' ) && function_exists( 'pll_get_term_translations' );

			$terms = get_terms( array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => false,
			) );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $has_polylang_terms ) {
						// 1. Skip terms that are not in the default language (e.g. translated Arabic categories)
						$term_lang = pll_get_term_language( $term->term_id );
						if ( $term_lang && $term_lang !== $default_lang ) {
							continue;
						}
						// 2. Skip terms that are already translated natively
						$translations = pll_get_term_translations( $term->term_id );
						if ( is_array( $translations ) && count( $translations ) > 1 ) {
							continue;
						}
					}

					// Add term name
					$name = trim( $term->name );
					if ( ! empty( $name ) && ! is_numeric( $name ) && strlen( $name ) > 1 ) {
						$wp_strings[ $name ] = true;
					}

					// Add term description if not empty
					$description = trim( strip_tags( $term->description ) );
					if ( ! empty( $description ) && strlen( $description ) > 1 && ! is_numeric( $description ) ) {
						$wp_strings[ $description ] = true;
					}
				}
			}

			$found_strings = array_keys( $wp_strings );
		} else {
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
			foreach ( $dirs as $dir ) {
				$found_strings = array_merge( $found_strings, $this->scan_directory_for_strings( $dir ) );
			}
			$found_strings = array_unique( $found_strings );
		}

		// Filter out strings already in our database or containing Arabic characters
		$existing_data = $this->get_translations_data();
		$existing_sources = array_map( 'strtolower', wp_list_pluck( $existing_data, 'source' ) );

		$new_untranslated = array();
		foreach ( $found_strings as $str ) {
			// Skip if already manual translated
			if ( in_array( strtolower( $str ), $existing_sources, true ) ) {
				continue;
			}
			// Skip if it contains Arabic characters (as source strings should be in English)
			if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $str ) ) {
				continue;
			}
			$new_untranslated[] = $str;
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
	 * AJAX endpoint: Save AI Settings.
	 */
	public function ajax_save_ai_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$provider     = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : 'none';
		$openai_url   = isset( $_POST['openai_url'] ) ? esc_url_raw( wp_unslash( $_POST['openai_url'] ) ) : '';
		$openai_key   = isset( $_POST['openai_key'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_key'] ) ) : '';
		$openai_model = isset( $_POST['openai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_model'] ) ) : '';

		$settings = array(
			'provider'     => $provider,
			'openai_url'   => $openai_url,
			'openai_key'   => $openai_key,
			'openai_model' => $openai_model,
		);

		update_option( 'manual_translations_ai_settings', $settings );

		wp_send_json_success( array(
			'message'  => __( 'AI settings saved successfully.', 'manual-translations-for-polylang' ),
			'provider' => $provider,
		) );
	}

	/**
	 * Internal helper to translate a string via the active OpenAI provider.
	 *
	 * @param string $text        The source text.
	 * @param string $target_lang The target language slug.
	 * @return string|WP_Error Translated string or WP_Error.
	 */
	private function translate_via_openai( $text, $target_lang ) {
		if ( empty( $text ) ) {
			return '';
		}

		$ai_settings = get_option( 'manual_translations_ai_settings', array() );
		$provider    = $ai_settings['provider'] ?? 'none';

		if ( 'openai' !== $provider ) {
			return new WP_Error( 'openai_inactive', __( 'OpenAI provider is not active.', 'manual-translations-for-polylang' ) );
		}

		$api_url = ! empty( $ai_settings['openai_url'] ) ? esc_url_raw( $ai_settings['openai_url'] ) : 'https://api.openai.com/v1/chat/completions';
		$api_key = $ai_settings['openai_key'] ?? '';
		$model   = ! empty( $ai_settings['openai_model'] ) ? sanitize_text_field( $ai_settings['openai_model'] ) : 'gpt-4o-mini';

		// Resolve target language name
		$languages = $this->get_active_languages();
		$target_name = $target_lang;
		foreach ( $languages as $lang ) {
			if ( $lang['slug'] === $target_lang ) {
				$target_name = $lang['name'];
				break;
			}
		}

		$headers = array(
			'Content-Type' => 'application/json',
		);
		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$body = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You are a professional, accurate translator. Translate the user input into the target language. Preserve any placeholders (like %s, %d, {var}), HTML tags, block formatting, shortcodes, and formatting exactly. Respond with the translated string ONLY. Do not wrap the output in quotes, and do not add any markdown formatting, explanations, or conversational text.'
				),
				array(
					'role'    => 'user',
					'content' => sprintf( "Target language: %s (code: %s)\nText to translate: %s", $target_name, $target_lang, $text )
				)
			),
			'temperature' => 0.1,
		);

		$response = wp_remote_post( $api_url, array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 45,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_data = json_decode( $response_body, true );
			$error_message = $error_data['error']['message'] ?? sprintf( __( 'API returned HTTP %d', 'manual-translations-for-polylang' ), $response_code );
			return new WP_Error( 'api_error', $error_message );
		}

		$data = json_decode( $response_body, true );
		$translation = $data['choices'][0]['message']['content'] ?? '';
		$translation = trim( $translation );

		if ( ( strpos( $translation, '"' ) === 0 && strrpos( $translation, '"' ) === strlen( $translation ) - 1 ) || 
			( strpos( $translation, "'" ) === 0 && strrpos( $translation, "'" ) === strlen( $translation ) - 1 ) ) {
			$translation = substr( $translation, 1, -1 );
		}

		return $translation;
	}

	/**
	 * AJAX endpoint: Translate via OpenAI-compatible API (server-side to protect keys).
	 */
	public function ajax_ai_translate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$source      = isset( $_POST['source'] ) ? trim( wp_unslash( $_POST['source'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$target_lang = isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '';

		if ( '' === $source || '' === $target_lang ) {
			wp_send_json_error( array( 'message' => __( 'Missing source string or target language.', 'manual-translations-for-polylang' ) ) );
		}

		$translation = $this->translate_via_openai( $source, $target_lang );

		if ( is_wp_error( $translation ) ) {
			wp_send_json_error( array( 'message' => $translation->get_error_message() ) );
		}

		wp_send_json_success( array(
			'translation' => $translation,
		) );
	}

	/**
	 * Register term translation edit form fields dynamically for all public taxonomies.
	 */
	public function register_term_translation_hooks() {
		$taxonomies = get_taxonomies( array( 'show_ui' => true ) );
		foreach ( $taxonomies as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_term_translation_fields' ), 10, 2 );
		}
	}

	/**
	 * Register the metabox on all public post types.
	 */
	public function register_post_translation_metabox() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true ) );
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'mtfp-ai-translation-helper',
				__( 'AI Translation Helper', 'manual-translations-for-polylang' ),
				array( $this, 'render_post_translation_metabox' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the post translation metabox.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_post_translation_metabox( $post ) {
		$languages = $this->get_active_languages();
		$current_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : '';
		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post->ID ) : array();

		if ( empty( $languages ) ) {
			echo '<p>' . esc_html__( 'Please configure Polylang languages first.', 'manual-translations-for-polylang' ) . '</p>';
			return;
		}

		echo '<div class="mtfp-metabox-wrapper">';
		echo '<table class="mtfp-translation-helper-table" style="width: 100%; border-collapse: collapse;">';
		echo '<thead>';
		echo '<tr style="border-bottom: 1px solid #eee;">';
		echo '<th style="text-align: left; padding: 6px 0; font-size: 11px; color: #64748b; text-transform: uppercase;">' . esc_html__( 'Language', 'manual-translations-for-polylang' ) . '</th>';
		echo '<th style="text-align: right; padding: 6px 0; font-size: 11px; color: #64748b; text-transform: uppercase;">' . esc_html__( 'Actions', 'manual-translations-for-polylang' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $languages as $lang ) {
			if ( $lang['slug'] === $current_lang ) {
				continue;
			}

			$translated_post_id = isset( $translations[ $lang['slug'] ] ) ? $translations[ $lang['slug'] ] : 0;
			$has_translation = ! empty( $translated_post_id );

			echo '<tr style="border-bottom: 1px solid #f9f9f9;" data-lang="' . esc_attr( $lang['slug'] ) . '">';
			echo '<td style="padding: 8px 0; vertical-align: middle; font-size: 13px;">';
			echo esc_html( $lang['name'] );
			if ( $has_translation ) {
				echo ' <span class="dashicons dashicons-yes-alt" style="color: #10b981; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;" title="' . esc_attr__( 'Translated', 'manual-translations-for-polylang' ) . '"></span>';
			} else {
				echo ' <span class="dashicons dashicons-warning" style="color: #64748b; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;" title="' . esc_attr__( 'Not Translated', 'manual-translations-for-polylang' ) . '"></span>';
			}
			echo '</td>';
			echo '<td style="padding: 8px 0; text-align: right; vertical-align: middle;">';
			if ( $has_translation ) {
				$edit_url = get_edit_post_link( $translated_post_id );
				echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small" style="margin-right: 4px;" title="' . esc_attr__( 'Edit Translation', 'manual-translations-for-polylang' ) . '"><span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; margin-top: 3px;"></span></a>';
				echo '<button type="button" class="button button-small mtfp-post-retranslate-btn" data-lang="' . esc_attr( $lang['slug'] ) . '" data-post-id="' . esc_attr( $translated_post_id ) . '" title="' . esc_attr__( 'Re-translate with AI', 'manual-translations-for-polylang' ) . '"><span class="dashicons dashicons-admin-customizer" style="font-size: 14px; width: 14px; height: 14px; margin-top: 3px;"></span></button>';
			} else {
				echo '<button type="button" class="button button-small button-primary mtfp-post-translate-btn" data-lang="' . esc_attr( $lang['slug'] ) . '" title="' . esc_attr__( 'Translate', 'manual-translations-for-polylang' ) . '">' . esc_html__( 'Translate', 'manual-translations-for-polylang' ) . '</button>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render translation fields on Term/Category edit pages.
	 *
	 * @param WP_Term $term     The term object.
	 * @param string  $taxonomy The taxonomy slug.
	 */
	public function render_term_translation_fields( $term, $taxonomy ) {
		$languages = $this->get_active_languages();
		$current_lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term->term_id ) : '';
		$translations = function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $term->term_id ) : array();

		if ( empty( $languages ) ) {
			return;
		}
		?>
		<tr class="form-field term-translation-helper-wrap">
			<th scope="row" valign="top"><label><?php esc_html_e( 'AI Translation Helper', 'manual-translations-for-polylang' ); ?></label></th>
			<td>
				<div class="mtfp-term-translation-container" style="max-width: 500px; background: #fff; border: 1px solid #ccd0d4; padding: 12px; border-radius: 4px;">
					<table style="width: 100%; border-collapse: collapse;">
						<thead>
							<tr style="border-bottom: 1px solid #eee;">
								<th style="text-align: left; padding: 6px 0; font-size: 11px; color: #64748b; text-transform: uppercase;"><?php esc_html_e( 'Language', 'manual-translations-for-polylang' ); ?></th>
								<th style="text-align: right; padding: 6px 0; font-size: 11px; color: #64748b; text-transform: uppercase;"><?php esc_html_e( 'Actions', 'manual-translations-for-polylang' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $languages as $lang ) {
								if ( $lang['slug'] === $current_lang ) {
									continue;
								}

								$translated_term_id = isset( $translations[ $lang['slug'] ] ) ? $translations[ $lang['slug'] ] : 0;
								$has_translation = ! empty( $translated_term_id );
								?>
								<tr style="border-bottom: 1px solid #f9f9f9;" data-lang="<?php echo esc_attr( $lang['slug'] ); ?>">
									<td style="padding: 8px 0; vertical-align: middle; font-size: 13px;">
										<?php echo esc_html( $lang['name'] ); ?>
										<?php if ( $has_translation ) : ?>
											<span class="dashicons dashicons-yes-alt" style="color: #10b981; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;" title="<?php esc_attr_e( 'Translated', 'manual-translations-for-polylang' ); ?>"></span>
										<?php else : ?>
											<span class="dashicons dashicons-warning" style="color: #64748b; font-size: 16px; width: 16px; height: 16px; vertical-align: middle;" title="<?php esc_attr_e( 'Not Translated', 'manual-translations-for-polylang' ); ?>"></span>
										<?php endif; ?>
									</td>
									<td style="padding: 8px 0; text-align: right; vertical-align: middle;">
										<?php if ( $has_translation ) : ?>
											<?php $edit_url = get_edit_term_link( $translated_term_id, $taxonomy ); ?>
											<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small" style="margin-right: 4px;" title="<?php esc_attr_e( 'Edit Translation', 'manual-translations-for-polylang' ); ?>"><span class="dashicons dashicons-edit" style="font-size: 14px; width: 14px; height: 14px; margin-top: 3px;"></span></a>
											<button type="button" class="button button-small mtfp-term-retranslate-btn" data-lang="<?php echo esc_attr( $lang['slug'] ); ?>" data-term-id="<?php echo esc_attr( $translated_term_id ); ?>" title="<?php esc_attr_e( 'Re-translate with AI', 'manual-translations-for-polylang' ); ?>"><span class="dashicons dashicons-admin-customizer" style="font-size: 14px; width: 14px; height: 14px; margin-top: 3px;"></span></button>
										<?php else : ?>
											<button type="button" class="button button-small button-primary mtfp-term-translate-btn" data-lang="<?php echo esc_attr( $lang['slug'] ); ?>" title="<?php esc_attr_e( 'Translate', 'manual-translations-for-polylang' ); ?>"><?php esc_html_e( 'Translate', 'manual-translations-for-polylang' ); ?></button>
										<?php endif; ?>
									</td>
								</tr>
								<?php
							}
							?>
						</tbody>
					</table>
				</div>
				<p class="description"><?php esc_html_e( 'Create or update translations using the active AI provider.', 'manual-translations-for-polylang' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * AJAX endpoint: Create or update a post translation.
	 */
	public function ajax_create_post_translation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$source_post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$target_lang    = isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '';

		$source_post = get_post( $source_post_id );
		if ( ! $source_post ) {
			wp_send_json_error( array( 'message' => __( 'Source post not found.', 'manual-translations-for-polylang' ) ) );
		}

		if ( empty( $target_lang ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing target language.', 'manual-translations-for-polylang' ) ) );
		}

		$ai_settings = get_option( 'manual_translations_ai_settings', array( 'provider' => 'none' ) );
		$provider    = $ai_settings['provider'] ?? 'none';

		// Get translation parameters if sent by client (Browser AI)
		$translated_title   = isset( $_POST['translated_title'] ) ? sanitize_text_field( wp_unslash( $_POST['translated_title'] ) ) : '';
		$translated_content = isset( $_POST['translated_content'] ) ? wp_kses_post( wp_unslash( $_POST['translated_content'] ) ) : '';
		$translated_excerpt = isset( $_POST['translated_excerpt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['translated_excerpt'] ) ) : '';

		// If OpenAI provider and no client-side translation was supplied:
		if ( 'openai' === $provider && empty( $translated_title ) ) {
			$translated_title = $this->translate_via_openai( $source_post->post_title, $target_lang );
			if ( is_wp_error( $translated_title ) ) {
				wp_send_json_error( array( 'message' => sprintf( __( 'Failed to translate title: %s', 'manual-translations-for-polylang' ), $translated_title->get_error_message() ) ) );
			}

			if ( ! empty( $source_post->post_content ) ) {
				$translated_content = $this->translate_via_openai( $source_post->post_content, $target_lang );
				if ( is_wp_error( $translated_content ) ) {
					wp_send_json_error( array( 'message' => sprintf( __( 'Failed to translate content: %s', 'manual-translations-for-polylang' ), $translated_content->get_error_message() ) ) );
				}
			}

			if ( ! empty( $source_post->post_excerpt ) ) {
				$translated_excerpt = $this->translate_via_openai( $source_post->post_excerpt, $target_lang );
				if ( is_wp_error( $translated_excerpt ) ) {
					wp_send_json_error( array( 'message' => sprintf( __( 'Failed to translate excerpt: %s', 'manual-translations-for-polylang' ), $translated_excerpt->get_error_message() ) ) );
				}
			}
		}

		// Fallback/None provider: clone content as-is
		if ( empty( $translated_title ) ) {
			$translated_title   = $source_post->post_title . ' (' . strtoupper( $target_lang ) . ')';
			$translated_content = $source_post->post_content;
			$translated_excerpt = $source_post->post_excerpt;
		}

		// Check if we are re-translating an existing post
		$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $source_post_id ) : array();
		$existing_translated_post_id = isset( $translations[ $target_lang ] ) ? (int) $translations[ $target_lang ] : 0;

		if ( $existing_translated_post_id ) {
			$post_data = array(
				'ID'           => $existing_translated_post_id,
				'post_title'   => $translated_title,
				'post_content' => $translated_content,
				'post_excerpt' => $translated_excerpt,
			);
			$updated_post_id = wp_update_post( $post_data );
			if ( is_wp_error( $updated_post_id ) ) {
				wp_send_json_error( array( 'message' => $updated_post_id->get_error_message() ) );
			}
			$target_post_id = $existing_translated_post_id;
		} else {
			$post_data = array(
				'post_title'   => $translated_title,
				'post_content' => $translated_content,
				'post_excerpt' => $translated_excerpt,
				'post_status'  => 'draft',
				'post_type'    => $source_post->post_type,
				'post_author'  => get_current_user_id(),
			);
			$new_post_id = wp_insert_post( $post_data );
			if ( is_wp_error( $new_post_id ) ) {
				wp_send_json_error( array( 'message' => $new_post_id->get_error_message() ) );
			}

			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $new_post_id, $target_lang );
			}

			if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_save_post_translations' ) ) {
				$source_lang = pll_get_post_language( $source_post_id );
				if ( ! empty( $source_lang ) ) {
					$translations[ $source_lang ] = $source_post_id;
				}
				$translations[ $target_lang ] = $new_post_id;
				pll_save_post_translations( $translations );
			}

			$target_post_id = $new_post_id;
		}

		wp_send_json_success( array(
			'message'  => __( 'Translation processed successfully.', 'manual-translations-for-polylang' ),
			'post_id'  => $target_post_id,
			'edit_url' => get_edit_post_link( $target_post_id, 'raw' ),
		) );
	}

	/**
	 * AJAX endpoint: Create or update a term/category translation.
	 */
	public function ajax_create_term_translation() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'manual-translations-for-polylang' ) ) );
		}
		check_ajax_referer( 'mtfp_admin_nonce', 'nonce' );

		$source_term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$target_lang    = isset( $_POST['target_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['target_lang'] ) ) : '';

		$source_term = get_term( $source_term_id );
		if ( ! $source_term || is_wp_error( $source_term ) ) {
			wp_send_json_error( array( 'message' => __( 'Source term not found.', 'manual-translations-for-polylang' ) ) );
		}

		if ( empty( $target_lang ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing target language.', 'manual-translations-for-polylang' ) ) );
		}

		$ai_settings = get_option( 'manual_translations_ai_settings', array( 'provider' => 'none' ) );
		$provider    = $ai_settings['provider'] ?? 'none';

		// Get translation parameters if sent by client (Browser AI)
		$translated_name        = isset( $_POST['translated_name'] ) ? sanitize_text_field( wp_unslash( $_POST['translated_name'] ) ) : '';
		$translated_description = isset( $_POST['translated_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['translated_description'] ) ) : '';

		// If OpenAI provider and no client-side translation was supplied:
		if ( 'openai' === $provider && empty( $translated_name ) ) {
			$translated_name = $this->translate_via_openai( $source_term->name, $target_lang );
			if ( is_wp_error( $translated_name ) ) {
				wp_send_json_error( array( 'message' => sprintf( __( 'Failed to translate name: %s', 'manual-translations-for-polylang' ), $translated_name->get_error_message() ) ) );
			}

			if ( ! empty( $source_term->description ) ) {
				$translated_description = $this->translate_via_openai( $source_term->description, $target_lang );
				if ( is_wp_error( $translated_description ) ) {
					wp_send_json_error( array( 'message' => sprintf( __( 'Failed to translate description: %s', 'manual-translations-for-polylang' ), $translated_description->get_error_message() ) ) );
				}
			}
		}

		// Fallback/None provider: clone name/description
		if ( empty( $translated_name ) ) {
			$translated_name        = $source_term->name . ' (' . strtoupper( $target_lang ) . ')';
			$translated_description = $source_term->description;
		}

		// Check if we are re-translating an existing term
		$translations = function_exists( 'pll_get_term_translations' ) ? pll_get_term_translations( $source_term_id ) : array();
		$existing_translated_term_id = isset( $translations[ $target_lang ] ) ? (int) $translations[ $target_lang ] : 0;

		if ( $existing_translated_term_id ) {
			$updated = wp_update_term( $existing_translated_term_id, $source_term->taxonomy, array(
				'name'        => $translated_name,
				'description' => $translated_description,
			) );
			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
			}
			$target_term_id = $existing_translated_term_id;
		} else {
			$new_term_args = array(
				'description' => $translated_description,
			);
			
			$new_term = wp_insert_term( $translated_name, $source_term->taxonomy, $new_term_args );
			if ( is_wp_error( $new_term ) ) {
				$new_term_args['slug'] = sanitize_title( $translated_name ) . '-' . $target_lang;
				$new_term = wp_insert_term( $translated_name . ' (' . strtoupper( $target_lang ) . ')', $source_term->taxonomy, $new_term_args );
				if ( is_wp_error( $new_term ) ) {
					wp_send_json_error( array( 'message' => $new_term->get_error_message() ) );
				}
			}

			$new_term_id = (int) $new_term['term_id'];

			if ( function_exists( 'pll_set_term_language' ) ) {
				pll_set_term_language( $new_term_id, $target_lang );
			}

			if ( function_exists( 'pll_get_term_language' ) && function_exists( 'pll_save_term_translations' ) ) {
				$source_lang = pll_get_term_language( $source_term_id );
				if ( ! empty( $source_lang ) ) {
					$translations[ $source_lang ] = $source_term_id;
				}
				$translations[ $target_lang ] = $new_term_id;
				pll_save_term_translations( $translations );
			}

			$target_term_id = $new_term_id;
		}

		wp_send_json_success( array(
			'message'  => __( 'Term translation processed successfully.', 'manual-translations-for-polylang' ),
			'term_id'  => $target_term_id,
			'edit_url' => get_edit_term_link( $target_term_id, $source_term->taxonomy, 'raw' ),
		) );
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
				<?php
				$ai_settings = get_option( 'manual_translations_ai_settings', array( 'provider' => 'none' ) );
				?>
				<button type="button" class="page-title-action mtfp-trigger-auto-translate" style="margin-right: 8px; background: #8b5cf6; border-color: #8b5cf6; <?php echo 'none' === $ai_settings['provider'] ? 'display: none;' : ''; ?>">
					<span class="dashicons dashicons-admin-customizer"></span>
					<?php esc_html_e( 'Auto Translate', 'manual-translations-for-polylang' ); ?>
				</button>

				<button type="button" class="page-title-action mtfp-trigger-scan-modal" style="background: #0ea5e9; border-color: #0ea5e9; margin-right: 8px;">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Scan & Import', 'manual-translations-for-polylang' ); ?>
				</button>
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
								<option value="200">200</option>
								<option value="500">500</option>
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

				<!-- Card: AI Translation Settings -->
				<div class="mtfp-card mtfp-card-ai-settings">
					<h2><?php esc_html_e( 'AI Translation Settings', 'manual-translations-for-polylang' ); ?></h2>
					<form id="mtfp-ai-settings-form" method="post" action="">
						<?php
						$ai_settings = get_option( 'manual_translations_ai_settings', array(
							'provider'     => 'none',
							'openai_url'   => 'https://api.openai.com/v1/chat/completions',
							'openai_key'   => '',
							'openai_model' => 'gpt-4o-mini',
						) );
						?>
						<div class="mtfp-form-group">
							<label for="mtfp-ai-provider"><?php esc_html_e( 'Translation Provider', 'manual-translations-for-polylang' ); ?></label>
							<select id="mtfp-ai-provider" name="provider" class="mtfp-select full-width">
								<option value="none" <?php selected( $ai_settings['provider'], 'none' ); ?>><?php esc_html_e( 'None (Disabled)', 'manual-translations-for-polylang' ); ?></option>
								<option value="browser" <?php selected( $ai_settings['provider'], 'browser' ); ?>><?php esc_html_e( 'Browser Built-in AI (Chrome / Edge)', 'manual-translations-for-polylang' ); ?></option>
								<option value="openai" <?php selected( $ai_settings['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI-Compatible API', 'manual-translations-for-polylang' ); ?></option>
							</select>
						</div>

						<div class="mtfp-ai-openai-fields" style="<?php echo 'openai' === $ai_settings['provider'] ? '' : 'display: none;'; ?>">
							<div class="mtfp-form-group">
								<label for="mtfp-openai-url"><?php esc_html_e( 'API URL Base', 'manual-translations-for-polylang' ); ?></label>
								<input type="url" id="mtfp-openai-url" name="openai_url" class="mtfp-input full-width" value="<?php echo esc_url( $ai_settings['openai_url'] ); ?>" placeholder="https://api.openai.com/v1/chat/completions" />
							</div>

							<div class="mtfp-form-group">
								<label for="mtfp-openai-key"><?php esc_html_e( 'API Key (Optional)', 'manual-translations-for-polylang' ); ?></label>
								<input type="password" id="mtfp-openai-key" name="openai_key" class="mtfp-input full-width" value="<?php echo esc_attr( $ai_settings['openai_key'] ); ?>" placeholder="<?php esc_attr_e( 'sk-... (leave empty if local/none required)', 'manual-translations-for-polylang' ); ?>" autocomplete="new-password" />
							</div>

							<div class="mtfp-form-group">
								<label for="mtfp-openai-model"><?php esc_html_e( 'Model Name', 'manual-translations-for-polylang' ); ?></label>
								<input type="text" id="mtfp-openai-model" name="openai_model" class="mtfp-input full-width" value="<?php echo esc_attr( $ai_settings['openai_model'] ); ?>" placeholder="gpt-4o-mini" />
							</div>
						</div>

						<button type="submit" class="button mtfp-btn-primary full-width">
							<span class="dashicons dashicons-saved"></span>
							<?php esc_html_e( 'Save AI Settings', 'manual-translations-for-polylang' ); ?>
						</button>
					</form>
				</div>
			</div>

			<!-- Scan Modal -->
			<div id="mtfp-scan-modal" class="mtfp-modal-overlay">
				<div class="mtfp-modal-content">
					<div class="mtfp-modal-header">
						<h2><?php esc_html_e( 'Scan for Untranslated Strings', 'manual-translations-for-polylang' ); ?></h2>
						<button type="button" class="mtfp-modal-close" id="mtfp-scan-modal-close">&times;</button>
					</div>
					<div class="mtfp-modal-body">
						<div class="mtfp-form-group">
							<label for="mtfp-modal-scan-type" style="display: block; font-weight: 500; margin-bottom: 6px; font-size: 13px;"><?php esc_html_e( 'What would you like to scan?', 'manual-translations-for-polylang' ); ?></label>
							<select id="mtfp-modal-scan-type" class="mtfp-select full-width">
								<option value="theme"><?php esc_html_e( 'Active Theme & Child Theme', 'manual-translations-for-polylang' ); ?></option>
								<option value="all-plugins"><?php esc_html_e( 'All Installed Plugins', 'manual-translations-for-polylang' ); ?></option>
								<option value="specific-plugin"><?php esc_html_e( 'Specific Plugin...', 'manual-translations-for-polylang' ); ?></option>
								<option value="wp-content"><?php esc_html_e( 'WordPress Content (Pages, Posts & Templates)', 'manual-translations-for-polylang' ); ?></option>
							</select>
						</div>

						<div id="mtfp-modal-specific-plugin-group" class="mtfp-form-group" style="display: none; margin-top: 16px;">
							<label for="mtfp-modal-specific-plugin" style="display: block; font-weight: 500; margin-bottom: 6px; font-size: 13px;"><?php esc_html_e( 'Select Plugin to Scan', 'manual-translations-for-polylang' ); ?></label>
							<select id="mtfp-modal-specific-plugin" class="mtfp-select full-width">
								<?php
								if ( ! function_exists( 'get_plugins' ) ) {
									require_once ABSPATH . 'wp-admin/includes/plugin.php';
								}
								$all_plugins = get_plugins();
								foreach ( $all_plugins as $plugin_file => $plugin_data ) {
									$dir = dirname( $plugin_file );
									if ( '.' !== $dir && '' !== $dir ) {
										if ( strpos( $plugin_file, 'manual-translations-for-polylang' ) === false ) {
											echo '<option value="' . esc_attr( $dir ) . '">' . esc_html( $plugin_data['Name'] ) . '</option>';
										}
									}
								}
								?>
							</select>
						</div>

						<p class="description" style="margin-top: 16px; margin-bottom: 20px; font-size: 13px; color: var(--mtfp-text-muted);">
							<?php esc_html_e( 'Scanning will extract code-level translation strings or post content that is currently missing from your manual translation catalog.', 'manual-translations-for-polylang' ); ?>
						</p>

						<button type="button" class="button mtfp-btn-primary full-width" id="mtfp-modal-start-scan-btn">
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e( 'Start Scan', 'manual-translations-for-polylang' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
