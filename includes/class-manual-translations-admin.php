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

		// Pass data and security nonces to JavaScript
		wp_localize_script(
			'mtfp-admin-scripts',
			'mtfpAdminData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'mtfp_admin_nonce' ),
				'languages' => $this->get_active_languages(),
				'i18n'      => array(
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

		// Handle pagination
		$per_page     = 20;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$search_query = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';

		// Filter data based on search
		$filtered_data = $data;
		if ( '' !== $search_query ) {
			$filtered_data = array_filter( $data, function( $item ) use ( $search_query ) {
				if ( false !== stripos( $item['source'], $search_query ) ) {
					return true;
				}
				foreach ( $item['translations'] as $t ) {
					if ( false !== stripos( $t, $search_query ) ) {
						return true;
					}
				}
				return false;
			});
		}

		$total_items = count( $filtered_data );
		$total_pages = ceil( $total_items / $per_page );
		
		// Slice data for pagination
		$paginated_data = array_slice( $filtered_data, ( $current_page - 1 ) * $per_page, $per_page, true );

		// Output settings notices
		settings_errors( 'mtfp_messages' );
		?>
		<div class="wrap mtfp-admin-wrap">
			<!-- Header Panel -->
			<div class="mtfp-header">
				<div class="mtfp-header-content">
					<div class="mtfp-logo">
						<span class="dashicons dashicons-translation"></span>
					</div>
					<div class="mtfp-title-group">
						<h1><?php esc_html_e( 'Manual Translations', 'manual-translations-for-polylang' ); ?></h1>
						<p class="description">
							<?php esc_html_e( 'Manually translate specific front-end strings across Polylang languages. Useful for cart, checkout, or AJAX contents.', 'manual-translations-for-polylang' ); ?>
						</p>
					</div>
				</div>
				<div class="mtfp-stats">
					<div class="mtfp-stat-card">
						<span class="mtfp-stat-num"><?php echo esc_html( count( $data ) ); ?></span>
						<span class="mtfp-stat-label"><?php esc_html_e( 'Total Strings', 'manual-translations-for-polylang' ); ?></span>
					</div>
					<div class="mtfp-stat-card">
						<span class="mtfp-stat-num"><?php echo esc_html( count( $languages ) ); ?></span>
						<span class="mtfp-stat-label"><?php esc_html_e( 'Active Languages', 'manual-translations-for-polylang' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Main Content Grid -->
			<div class="mtfp-grid">
				<!-- Left Column: Table List -->
				<div class="mtfp-col-main">
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

							<div class="mtfp-search-group">
								<form method="get" action="">
									<input type="hidden" name="page" value="manual-translations" />
									<?php if ( isset( $_GET['post_type'] ) ) : ?>
										<input type="hidden" name="post_type" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) ); ?>" />
									<?php endif; ?>
									<input type="search" id="mtfp-search" name="s" class="mtfp-input" placeholder="<?php esc_attr_e( 'Search strings...', 'manual-translations-for-polylang' ); ?>" value="<?php echo esc_attr( $search_query ); ?>" />
									<button class="button mtfp-btn-secondary" type="submit"><?php esc_html_e( 'Search', 'manual-translations-for-polylang' ); ?></button>
								</form>
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
										<?php if ( empty( $paginated_data ) ) : ?>
											<tr class="mtfp-empty-row">
												<td colspan="<?php echo esc_attr( count( $languages ) + 3 ); ?>">
													<div class="mtfp-empty-state">
														<span class="dashicons dashicons-editor-help"></span>
														<p><?php esc_html_e( 'No translation strings found.', 'manual-translations-for-polylang' ); ?></p>
													</div>
												</td>
											</tr>
										<?php else : ?>
											<?php foreach ( $paginated_data as $hash => $row ) : ?>
												<tr data-hash="<?php echo esc_attr( $hash ); ?>" class="mtfp-row">
													<td>
														<input type="checkbox" name="mtfp_selected[]" class="mtfp-row-cb" value="<?php echo esc_attr( $hash ); ?>" />
													</td>
													<td class="mtfp-cell-source" data-value="<?php echo esc_attr( $row['source'] ); ?>">
														<strong class="mtfp-source-text"><?php echo esc_html( $row['source'] ); ?></strong>
													</td>
													<?php foreach ( $languages as $lang ) : ?>
														<?php
														$slug = $lang['slug'];
														$val  = isset( $row['translations'][ $slug ] ) ? $row['translations'][ $slug ] : '';
														?>
														<td class="mtfp-cell-editable" data-lang="<?php echo esc_attr( $slug ); ?>" data-value="<?php echo esc_attr( $val ); ?>">
															<span class="mtfp-editable-text"><?php echo esc_html( $val ); ?></span>
															<span class="dashicons dashicons-edit mtfp-edit-indicator"></span>
														</td>
													<?php endforeach; ?>
													<td class="mtfp-cell-actions">
														<button type="button" class="mtfp-btn-icon mtfp-delete-row" title="<?php esc_attr_e( 'Delete', 'manual-translations-for-polylang' ); ?>">
															<span class="dashicons dashicons-trash"></span>
														</button>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php endif; ?>
									</tbody>
								</table>
							</div>

							<!-- Pagination & Bulk Export -->
							<div class="mtfp-table-footer">
								<div class="mtfp-footer-actions">
									<button type="submit" class="button mtfp-btn-secondary" id="mtfp-export-selected">
										<span class="dashicons dashicons-download"></span>
										<?php esc_html_e( 'Export Selected to CSV', 'manual-translations-for-polylang' ); ?>
									</button>
								</div>
								<?php if ( $total_pages > 1 ) : ?>
									<div class="mtfp-pagination">
										<?php
										echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
											'base'      => add_query_arg( 'paged', '%#%' ),
											'format'    => '',
											'prev_text' => '&laquo;',
											'next_text' => '&raquo;',
											'total'     => $total_pages,
											'current'   => $current_page,
										) );
										?>
									</div>
								<?php endif; ?>
							</div>
						</form>
					</div>
				</div>

				<!-- Right Column: Sidebar (Add String & Import/Export) -->
				<div class="mtfp-col-side">
					<!-- Card: Add Translation -->
					<div class="mtfp-card mtfp-card-add">
						<h2><?php esc_html_e( 'Add New String', 'manual-translations-for-polylang' ); ?></h2>
						<form id="mtfp-add-string-form">
							<div class="mtfp-form-group">
								<label for="mtfp-add-source"><?php esc_html_e( 'Source String (Original Text)', 'manual-translations-for-polylang' ); ?></label>
								<textarea id="mtfp-add-source" class="mtfp-textarea" rows="2" placeholder="<?php esc_attr_e( 'e.g. Subtotal:', 'manual-translations-for-polylang' ); ?>" required></textarea>
							</div>

							<div class="mtfp-divider"></div>

							<div class="mtfp-lang-inputs">
								<h3><?php esc_html_e( 'Translations', 'manual-translations-for-polylang' ); ?></h3>
								<?php foreach ( $languages as $lang ) : ?>
									<div class="mtfp-form-group">
										<label for="mtfp-lang-<?php echo esc_attr( $lang['slug'] ); ?>">
											<?php echo esc_html( $lang['name'] ); ?> <span class="mtfp-label-code">(<?php echo esc_html( $lang['slug'] ); ?>)</span>
										</label>
										<input type="text" id="mtfp-lang-<?php echo esc_attr( $lang['slug'] ); ?>" class="mtfp-input mtfp-lang-val" data-lang="<?php echo esc_attr( $lang['slug'] ); ?>" placeholder="<?php esc_attr_e( 'Translation value', 'manual-translations-for-polylang' ); ?>" />
									</div>
								<?php endforeach; ?>
							</div>

							<button type="submit" class="button mtfp-btn-primary full-width">
								<span class="dashicons dashicons-plus"></span>
								<?php esc_html_e( 'Add Translation String', 'manual-translations-for-polylang' ); ?>
							</button>
						</form>
					</div>

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
				</div>
			</div>
		</div>
		<?php
	}
}
