<?php
// Start output buffering to prevent premature header output
if (ob_get_level() == 0) {
    ob_start();
}
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Link_Redirector_Admin {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('linkmaster_after_admin_menu', array($this, 'add_submenu_page'), 10);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_linkmaster_save_redirect', array($this, 'ajax_save_redirect'));
        add_action('wp_ajax_linkmaster_delete_redirect', array($this, 'ajax_delete_redirect'));
        add_action('wp_ajax_linkmaster_bulk_redirect_action', array($this, 'ajax_bulk_redirect_action'));
        add_action('wp_ajax_linkmaster_get_redirect_data', array($this, 'ajax_get_redirect_data'));
        require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-csv-manager.php';
        
        // Hook into send_headers to handle redirections
        add_action('send_headers', array($this, 'process_redirects'));
    }

    public function add_submenu_page() {
        add_submenu_page(
            'linkmaster',
            esc_html__('Redirections', 'linkmaster'),
            esc_html__('Redirections', 'linkmaster'),
            'edit_posts',
            'linkmaster-redirections',
            array($this, 'display_admin_page')
        );
    }

    public function enqueue_scripts($hook) {
        if ('linkmaster_page_linkmaster-redirections' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'linkmaster-redirector-js',
            plugins_url('js/redirector.js', dirname(__FILE__)),
            array('jquery'),
            LINKMASTER_VERSION,
            true
        );

        wp_localize_script('linkmaster-redirector-js', 'linkmaster_redirector', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('linkmaster_redirector'),
            'save_nonce' => wp_create_nonce('linkmaster_save_redirect'),
            'delete_nonce' => wp_create_nonce('linkmaster_delete_redirect'),
            'bulk_nonce' => wp_create_nonce('linkmaster_bulk_redirect_action'),
            'get_redirect_nonce' => wp_create_nonce('linkmaster_get_redirect_nonce'),
            'save_success' => esc_html__('Redirect saved successfully', 'linkmaster'),
            'save_error' => esc_html__('Error saving redirect', 'linkmaster'),
            'delete_success' => esc_html__('Redirect deleted successfully', 'linkmaster'),
            'delete_error' => esc_html__('Error deleting redirect', 'linkmaster'),
            'bulk_success' => esc_html__('Bulk action completed successfully', 'linkmaster'),
            'bulk_error' => esc_html__('Error performing bulk action', 'linkmaster'),
            'select_bulk_action' => esc_html__('Please select a bulk action', 'linkmaster'),
            'select_items' => esc_html__('Please select items to perform action on', 'linkmaster'),
            'bulk_delete_confirm' => esc_html__('Are you sure you want to delete the selected redirects?', 'linkmaster'),
            'bulk_enable_confirm' => esc_html__('Are you sure you want to enable the selected redirects?', 'linkmaster'),
            'bulk_disable_confirm' => esc_html__('Are you sure you want to disable the selected redirects?', 'linkmaster')
        ));
        
        wp_enqueue_style(
            'linkmaster-redirector-css',
            plugins_url('css/redirector.css', dirname(__FILE__)),
            array(),
            LINKMASTER_VERSION
        );
    }

    public function display_admin_page() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Instantiate CSV Manager
        $csv_manager = new LinkMaster_CSV_Manager();

        // Handle CSV export/import
        if (isset($_POST['action']) && $_POST['action'] === 'linkmaster_export_redirects_csv' && isset($_POST['export_csv'])) {
            $csv_manager->export_redirects_csv();
        }
        if (isset($_POST['import_csv'])) {
            $csv_manager->import_redirects_csv();
        }

        $redirects = $this->get_redirects();
        
        // Pagination settings
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $total_items = count($redirects);
        $total_pages = ceil($total_items / $per_page);

        // Adjust current page if it exceeds total pages
        if ($current_page > $total_pages && $total_pages > 0) {
            $current_page = $total_pages;
        }

        // Get the slice of redirects for current page
        $offset = ($current_page - 1) * $per_page;
        $page_redirects = array_slice($redirects, $offset, $per_page);

        // Get current active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'list';

        ?>
        <div class="wrap linkmaster-wrap">
            <h1 class="linkmaster-title"><?php esc_html_e('Redirections', 'linkmaster'); ?></h1>

            <?php settings_errors('linkmaster_redirects_messages'); ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'list', remove_query_arg('tab'))); ?>" class="nav-tab <?php echo $active_tab == 'list' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Redirections List', 'linkmaster'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'add', remove_query_arg('tab'))); ?>" class="nav-tab <?php echo $active_tab == 'add' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Add New Redirect', 'linkmaster'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'csv', remove_query_arg('tab'))); ?>" class="nav-tab <?php echo $active_tab == 'csv' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('CSV Import/Export', 'linkmaster'); ?>
                </a>
                <?php do_action('linkmaster_redirections_tabs', $active_tab); ?>
            </h2>

            <?php if ($active_tab == 'csv'): ?>
            <!-- CSV Import/Export Tab -->
            <div class="linkmaster-card">
                <h2 class="card-title"><?php esc_html_e('CSV Import/Export', 'linkmaster'); ?></h2>
                <p class="card-subtitle"><?php esc_html_e('Manage your redirects in bulk using CSV files.', 'linkmaster'); ?></p>
                
                <form method="post" enctype="multipart/form-data" class="linkmaster-form-inline">
                    <input type="file" name="csv_file" accept=".csv" required class="linkmaster-file-input" />
                    <input type="submit" name="import_csv" value="<?php esc_attr_e('Import CSV', 'linkmaster'); ?>" class="button button-primary linkmaster-button" />
                    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=linkmaster_download_redirects_sample_csv')); ?>" class="button linkmaster-button-secondary">
                        <?php esc_html_e('Download Sample CSV', 'linkmaster'); ?>
                    </a>
                </form>
                
                <form method="post" class="linkmaster-form-inline">
                    <input type="hidden" name="action" value="linkmaster_export_redirects_csv">
                    <?php wp_nonce_field('linkmaster_export_redirects_csv', 'linkmaster_export_nonce'); ?>
                    <input type="submit" name="export_csv" value="<?php esc_attr_e('Export Redirects to CSV', 'linkmaster'); ?>" class="button linkmaster-button-secondary" />
                </form>
            </div>
            <?php endif; ?>

            <?php if ($active_tab == 'add'): ?>
            <!-- Add New Redirect Tab -->
            <div class="linkmaster-card">
                <h2 class="card-title"><?php esc_html_e('Add New Redirect', 'linkmaster'); ?></h2>
                <form id="linkmaster-add-redirect-form">
                    <?php wp_nonce_field('linkmaster_save_redirect', 'linkmaster_redirect_nonce'); ?>
                    <input type="hidden" name="redirect_id" id="redirect_id" value="">
                    
                    <table class="form-table linkmaster-form-table">
                        <tr>
                            <th scope="row">
                                <label for="source_url"><?php esc_html_e('Source URL', 'linkmaster'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="source_url" id="source_url" class="regular-text" placeholder="/old-url" required aria-required="true">
                                <p class="description"><?php esc_html_e('Enter the source path (e.g., /old-url). It should start with "/".', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="target_url"><?php esc_html_e('Target URL', 'linkmaster'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="target_url" id="target_url" class="regular-text" placeholder="https://example.com/new-url" required aria-required="true">
                                <p class="description"><?php esc_html_e('Enter the full target URL (e.g., https://example.com/new-url).', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="redirect_type"><?php esc_html_e('Redirect Type', 'linkmaster'); ?></label>
                            </th>
                            <td>
                                <select name="redirect_type" id="redirect_type" class="linkmaster-select">
                                    <?php foreach ($this->get_redirect_types() as $code => $description) : ?>
                                        <option value="<?php echo esc_attr($code); ?>">
                                            <?php echo esc_html($code . ' - ' . $description); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Select the HTTP status code for this redirect (e.g., 301 for permanent, 302 for temporary).', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="status"><?php esc_html_e('Status', 'linkmaster'); ?></label>
                            </th>
                            <td>
                                <select name="status" id="status" class="linkmaster-select">
                                    <option value="enabled"><?php esc_html_e('Enabled', 'linkmaster'); ?></option>
                                    <option value="disabled"><?php esc_html_e('Disabled', 'linkmaster'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Set the status of this redirect.', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="expiration_date"><?php esc_html_e('Expiration Date', 'linkmaster'); ?></label>
                            </th>
                            <td>
                                <input type="date" name="expiration_date" id="expiration_date" class="regular-text">
                                <p class="description"><?php esc_html_e('Set an optional expiration date for this redirect. Leave blank for no expiration.', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Link Attributes', 'linkmaster'); ?></label>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="nofollow" id="nofollow" value="1">
                                    <?php esc_html_e('Add nofollow attribute', 'linkmaster'); ?>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox" name="sponsored" id="sponsored" value="1">
                                    <?php esc_html_e('Add sponsored attribute', 'linkmaster'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('Select link attributes to be added to the redirect.', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary linkmaster-button" id="save-redirect"><?php esc_html_e('Save Redirect', 'linkmaster'); ?></button>
                        <button type="button" class="button linkmaster-button-secondary" id="cancel-edit" style="display:none;"><?php esc_html_e('Cancel', 'linkmaster'); ?></button>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($active_tab == 'list'): ?>
            <!-- Existing Redirects Tab -->
            <div class="linkmaster-section linkmaster-redirects-list">
                <div class="linkmaster-actions-bar">
                    <a href="<?php echo esc_url(add_query_arg('tab', 'add', remove_query_arg('tab'))); ?>" class="button button-primary linkmaster-button">
                        <?php esc_html_e('Add New Redirect', 'linkmaster'); ?>
                    </a>
                </div>
                
                <?php if (empty($redirects)) : ?>
                    <div class="linkmaster-empty-state">
                        <p><?php esc_html_e('No redirects found. Add a new redirect to get started.', 'linkmaster'); ?></p>
                    </div>
                <?php else : ?>
                    <div class="linkmaster-tablenav top">
                        <div class="linkmaster-actions">
                            <select name="bulk-action" id="bulk-action-selector-top" class="linkmaster-select">
                                <option value="-1"><?php esc_html_e('Bulk Actions', 'linkmaster'); ?></option>
                                <option value="delete"><?php esc_html_e('Delete Selected', 'linkmaster'); ?></option>
                                <option value="enable"><?php esc_html_e('Enable Selected', 'linkmaster'); ?></option>
                                <option value="disable"><?php esc_html_e('Disable Selected', 'linkmaster'); ?></option>
                                <option value="reset_hits"><?php esc_html_e('Reset Hit Count', 'linkmaster'); ?></option>
                            </select>
                            <button type="button" id="doaction" class="button linkmaster-button-secondary">
                                <?php esc_html_e('Apply', 'linkmaster'); ?>
                            </button>
                        </div>
                        <div class="linkmaster-pagination">
                            <span class="displaying-num">
                                <?php printf(
                                    _n('%s item', '%s items', $total_items, 'linkmaster'),
                                    number_format_i18n($total_items)
                                ); ?>
                            </span>
                            <?php if ($total_pages > 1): ?>
                                <?php
                                $first_page_url = esc_url(add_query_arg('paged', 1));
                                $prev_page = max(1, $current_page - 1);
                                $prev_page_url = esc_url(add_query_arg('paged', $prev_page));
                                $next_page = min($total_pages, $current_page + 1);
                                $next_page_url = esc_url(add_query_arg('paged', $next_page));
                                $last_page_url = esc_url(add_query_arg('paged', $total_pages));
                                ?>
                                <span class="pagination-links">
                                    <a class="first-page button <?php echo $current_page == 1 ? 'disabled' : ''; ?>" href="<?php echo $first_page_url; ?>">
                                        <span aria-hidden="true">«</span>
                                    </a>
                                    <a class="prev-page button <?php echo $current_page == 1 ? 'disabled' : ''; ?>" href="<?php echo $prev_page_url; ?>">
                                        <span aria-hidden="true">‹</span>
                                    </a>
                                    <span class="paging-info">
                                        <?php printf(
                                            esc_html__('%1$s of %2$s', 'linkmaster'),
                                            number_format_i18n($current_page),
                                            number_format_i18n($total_pages)
                                        ); ?>
                                    </span>
                                    <a class="next-page button <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>" href="<?php echo $next_page_url; ?>">
                                        <span aria-hidden="true">›</span>
                                    </a>
                                    <a class="last-page button <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>" href="<?php echo $last_page_url; ?>">
                                        <span aria-hidden="true">»</span>
                                    </a>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <table class="linkmaster-table wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-cb check-column">
                                    <input type="checkbox" id="cb-select-all-1">
                                </th>
                                <th scope="col" class="manage-column column-source"><?php esc_html_e('Source URL', 'linkmaster'); ?></th>
                                <th scope="col" class="manage-column column-target"><?php esc_html_e('Target URL', 'linkmaster'); ?></th>
                                <th scope="col" class="manage-column column-type"><?php esc_html_e('Redirect Type', 'linkmaster'); ?></th>
                                <th scope="col" class="manage-column column-hits text-center"><?php esc_html_e('Hits', 'linkmaster'); ?></th>
                                <th scope="col" class="manage-column column-expiration"><?php esc_html_e('Expiration', 'linkmaster'); ?></th>
                                <th scope="col" class="manage-column column-attributes"><?php esc_html_e('Link Attributes', 'linkmaster'); ?></th>
                                <th scope="col" class="manage-column column-actions"><?php esc_html_e('Actions', 'linkmaster'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="the-list">
                            <?php foreach ($page_redirects as $id => $redirect) : ?>
                                <tr id="redirect-<?php echo esc_attr($id); ?>">
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" class="link-cb" name="redirect_ids[]" value="<?php echo esc_attr($id); ?>">
                                    </th>
                                    <td class="column-source" data-source="<?php echo isset($redirect['source_url']) ? esc_attr($redirect['source_url']) : ''; ?>">
                                        <strong><?php echo isset($redirect['source_url']) ? esc_html($redirect['source_url']) : esc_html__('(No source URL)', 'linkmaster'); ?></strong>
                                        <?php if (isset($redirect['status']) && $redirect['status'] === 'disabled'): ?>
                                            <span class="status-disabled"><?php esc_html_e('(Disabled)', 'linkmaster'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-target" data-target="<?php echo esc_attr($redirect['target_url']); ?>">
                                        <a href="<?php echo esc_url($redirect['target_url']); ?>" target="_blank" class="linkmaster-page-link">
                                            <?php echo esc_html($redirect['target_url']); ?>
                                        </a>
                                    </td>
                                    <td class="column-type" data-type="<?php echo esc_attr($type = isset($redirect['redirect_type']) ? $redirect['redirect_type'] : '301'); ?>">
                                        <?php 
                                        $redirect_types = $this->get_redirect_types();
                                        $description = isset($redirect_types[$type]) ? $redirect_types[$type] : '';
                                        ?>
                                        <span class="status-badge redirect-<?php echo esc_attr($type); ?>">
                                            <?php echo esc_html($type . ' - ' . $description); ?>
                                        </span>
                                    </td>
                                    <td class="column-hits text-center">
                                        <?php echo isset($redirect['hits']) ? intval($redirect['hits']) : 0; ?>
                                    </td>
                                    <td class="column-expiration">
                                        <?php 
                                        if (!empty($redirect['expiration_date'])) {
                                            $expiration_timestamp = strtotime($redirect['expiration_date']);
                                            $expiration_class = $expiration_timestamp < time() ? 'expired' : 'active';
                                            echo '<span class="expiration-date ' . esc_attr($expiration_class) . '" data-date="' . esc_attr($redirect['expiration_date']) . '">';
                                            echo esc_html(date_i18n(get_option('date_format'), $expiration_timestamp));
                                            echo '</span>';
                                        } else {
                                            echo '<span class="expiration-date never" data-date="never">—</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="column-attributes">
                                        <?php
                                        $attributes = array();
                                        if (!empty($redirect['nofollow'])) {
                                            $attributes[] = '<span class="attribute nofollow">nofollow</span>';
                                        }
                                        if (!empty($redirect['sponsored'])) {
                                            $attributes[] = '<span class="attribute sponsored">sponsored</span>';
                                        }
                                        echo empty($attributes) ? '<span class="no-attributes">' . esc_html__('None', 'linkmaster') . '</span>' : implode(' ', $attributes);
                                        ?>
                                    </td>
                                    <td class="column-actions">
                                        <div class="action-buttons">
                                            <button type="button" class="button linkmaster-button-edit edit-redirect" data-id="<?php echo esc_attr($id); ?>">
                                                <?php esc_html_e('Edit', 'linkmaster'); ?>
                                            </button>
                                            <button type="button" class="button linkmaster-button-unlink delete-redirect" 
                                                    data-id="<?php echo esc_attr($id); ?>"
                                                    data-nonce="<?php echo wp_create_nonce('delete_redirect_' . $id); ?>">
                                                <?php esc_html_e('Delete', 'linkmaster'); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Bottom Pagination -->
                    <?php if (!empty($page_redirects)): ?>
                        <div class="linkmaster-tablenav bottom">
                            <div class="linkmaster-actions">
                                <select name="bulk-action2" id="bulk-action-selector-bottom" class="linkmaster-select">
                                    <option value="-1"><?php esc_html_e('Bulk Actions', 'linkmaster'); ?></option>
                                    <option value="delete"><?php esc_html_e('Delete', 'linkmaster'); ?></option>
                                    <option value="enable"><?php esc_html_e('Enable', 'linkmaster'); ?></option>
                                    <option value="disable"><?php esc_html_e('Disable', 'linkmaster'); ?></option>
                                    <option value="reset_hits"><?php esc_html_e('Reset Hits', 'linkmaster'); ?></option>
                                </select>
                                <button type="button" id="doaction2" class="button linkmaster-button-secondary">
                                    <?php esc_html_e('Apply', 'linkmaster'); ?>
                                </button>
                            </div>
                            <div class="linkmaster-pagination">
                                <span class="displaying-num">
                                    <?php printf(
                                        _n('%s item', '%s items', $total_items, 'linkmaster'),
                                        number_format_i18n($total_items)
                                    ); ?>
                                </span>
                                <?php if ($total_pages > 1): ?>
                                    <span class="pagination-links">
                                        <a class="first-page button <?php echo $current_page == 1 ? 'disabled' : ''; ?>" href="<?php echo $first_page_url; ?>">
                                            <span aria-hidden="true">«</span>
                                        </a>
                                        <a class="prev-page button <?php echo $current_page == 1 ? 'disabled' : ''; ?>" href="<?php echo $prev_page_url; ?>">
                                            <span aria-hidden="true">‹</span>
                                        </a>
                                        <span class="paging-info">
                                            <?php printf(
                                                esc_html__('%1$s of %2$s', 'linkmaster'),
                                                number_format_i18n($current_page),
                                                number_format_i18n($total_pages)
                                            ); ?>
                                        </span>
                                        <a class="next-page button <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>" href="<?php echo $next_page_url; ?>">
                                            <span aria-hidden="true">›</span>
                                        </a>
                                        <a class="last-page button <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>" href="<?php echo $last_page_url; ?>">
                                            <span aria-hidden="true">»</span>
                                        </a>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php do_action('linkmaster_redirections_tab_content', $active_tab); ?>
        </div>
        <style>
            /* General Layout */
            .linkmaster-wrap {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px 0;
            }
            .linkmaster-title {
                font-size: 24px;
                font-weight: 600;
                color: #1d2327;
                margin-bottom: 20px;
            }

            /* Card Styling */
            .linkmaster-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            }
            .card-title {
                font-size: 18px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 10px;
            }
            .card-subtitle {
                color: #6b7280;
                margin: 0 0 15px;
            }
            .linkmaster-form-inline {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            .linkmaster-file-input {
                padding: 5px;
            }
            .linkmaster-button {
                background-color: #2271b1;
                border-color: #2271b1;
                padding: 6px 16px;
                font-weight: 500;
            }
            .linkmaster-button:hover {
                background-color: #135e96;
                border-color: #135e96;
            }

            /* Form Table */
            .linkmaster-form-table th {
                padding: 12px 10px 12px 0;
            }
            .linkmaster-form-table td {
                padding: 12px 0;
            }

            /* Section Styling */
            .linkmaster-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            }
            .section-title {
                font-size: 18px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 20px;
            }

            /* Empty State */
            .linkmaster-empty-state {
                text-align: center;
                padding: 40px;
                color: #6b7280;
            }

            /* Tablenav and Filters */
            .linkmaster-tablenav {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 10px;
                padding: 10px 0;
            }
            .linkmaster-tablenav.bottom {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #e5e7eb;
            }
            .linkmaster-actions {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .linkmaster-select {
                height: 32px;
                border-radius: 4px;
                border: 1px solid #8c8f94;
            }
            .linkmaster-button-secondary {
                background: #fff;
                color: #2271b1;
                border: 1px solid #2271b1;
                padding: 5px 15px;
            }
            .linkmaster-button-secondary:hover {
                background: #f0f6fc;
                border-color: #135e96;
                color: #135e96;
            }

            /* Table Styling */
            .linkmaster-table {
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                overflow: hidden;
                border-collapse: collapse;
                width: 100%;
            }
            .linkmaster-table th,
            .linkmaster-table td {
                padding: 8px 12px;
                vertical-align: middle;
                border-bottom: 1px solid #e5e7eb;
                line-height: 1.3;
            }
            .linkmaster-table th {
                background: #f6f7f7;
                font-weight: 600;
                color: #1d2327;
            }
            .linkmaster-table tr {
                height: auto;
            }
            .linkmaster-table tr:nth-child(even) {
                background-color: #f9fafb;
            }
            .linkmaster-table tr:hover {
                background-color: #f0f6fc;
            }
            .status-disabled {
                color: #dc2626;
                font-style: italic;
                margin-left: 5px;
            }
            .status-badge {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                display: inline-block;
                line-height: 1.2;
            }
            .status-badge.redirect-301,
            .status-badge.redirect-308 {
                background: #d1fae5;
                color: #059669;
            }
            .status-badge.redirect-302,
            .status-badge.redirect-307 {
                background: #fef3c7;
                color: #d97706;
            }
            .status-badge.redirect-303 {
                background: #dbeafe;
                color: #2563eb;
            }
            .linkmaster-button-edit {
                background: #fff;
                color: #2271b1;
                border: 1px solid #2271b1;
                margin-right: 5px;
            }
            .linkmaster-button-edit:hover {
                background: #f0f6fc;
                border-color: #135e96;
                color: #135e96;
            }
            .linkmaster-button-unlink {
                background: #fff;
                color: #d94f4f;
                border: 1px solid #d94f4f;
            }
            .linkmaster-button-unlink:hover {
                background: #fef2f2;
                border-color: #b32d2e;
                color: #b32d2e;
            }

            /* Pagination */
            .linkmaster-pagination {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .linkmaster-pagination .pagination-links .button {
                min-width: 32px;
                height: 32px;
                padding: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .linkmaster-pagination .paging-info {
                margin: 0 10px;
                color: #6b7280;
            }
            .linkmaster-pagination .disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
        </style>
        <?php
    }

    public function ajax_save_redirect() {
        try {
            check_ajax_referer('linkmaster_save_redirect', 'nonce');
            
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => esc_html__('Unauthorized access', 'linkmaster')));
            }

            $redirect_id = isset($_POST['redirect_id']) ? sanitize_text_field($_POST['redirect_id']) : '';
            $source_url = isset($_POST['source_url']) ? sanitize_text_field($_POST['source_url']) : '';
            $target_url = isset($_POST['target_url']) ? esc_url_raw($_POST['target_url']) : '';
            $redirect_type = isset($_POST['redirect_type']) ? sanitize_text_field($_POST['redirect_type']) : '301';
            $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'enabled';

            // Validate inputs
            if (empty($source_url) || empty($target_url)) {
                throw new Exception(esc_html__('Source and target URLs are required', 'linkmaster'));
            }

            // Ensure source URL starts with a slash if it's a relative path
            if (strpos($source_url, 'http') !== 0 && strpos($source_url, '/') !== 0) {
                $source_url = '/' . $source_url;
            }

            // Get existing redirects
            $redirects = $this->get_redirects();

            // Check for duplicates
            foreach ($redirects as $existing_id => $existing_redirect) {
                if ($existing_id !== $redirect_id && // Skip comparing with self when editing
                    $existing_redirect['source_url'] === $source_url) {
                    throw new Exception(esc_html__('A redirect with this source URL already exists', 'linkmaster'));
                }
            }
            
            // Create new redirect or update existing one
            if (empty($redirect_id)) {
                $redirect_id = uniqid('redirect_');
            }
            
            // Get link options and expiration
            $expiration_date = isset($_POST['expiration_date']) ? sanitize_text_field($_POST['expiration_date']) : '';
            $nofollow = isset($_POST['nofollow']) ? (bool)$_POST['nofollow'] : false;
            $sponsored = isset($_POST['sponsored']) ? (bool)$_POST['sponsored'] : false;
            
            $redirects[$redirect_id] = array(
                'source_url' => $source_url,
                'target_url' => $target_url,
                'redirect_type' => $redirect_type,
                'hits' => isset($redirects[$redirect_id]['hits']) ? $redirects[$redirect_id]['hits'] : 0,
                'status' => $status,
                'expiration_date' => $expiration_date,
                'nofollow' => $nofollow,
                'sponsored' => $sponsored
            );
            
            update_option('linkmaster_redirects', $redirects);
            
            wp_send_json_success(array(
                'message' => esc_html__('Redirect saved successfully', 'linkmaster'),
                'redirect' => $redirects[$redirect_id],
                'redirect_id' => $redirect_id
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_delete_redirect() {
        try {
            check_ajax_referer('linkmaster_delete_redirect', 'nonce');
            
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => esc_html__('Unauthorized access', 'linkmaster')));
            }

            $redirect_id = isset($_POST['redirect_id']) ? sanitize_text_field($_POST['redirect_id']) : '';
            
            if (empty($redirect_id)) {
                throw new Exception(esc_html__('Invalid redirect ID', 'linkmaster'));
            }
            
            $redirects = $this->get_redirects();
            
            if (!isset($redirects[$redirect_id])) {
                throw new Exception(esc_html__('Redirect not found', 'linkmaster'));
            }
            
            unset($redirects[$redirect_id]);
            update_option('linkmaster_redirects', $redirects);
            
            wp_send_json_success(array(
                'message' => esc_html__('Redirect deleted successfully', 'linkmaster')
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_bulk_redirect_action() {
        try {
            check_ajax_referer('linkmaster_bulk_redirect_action', 'nonce');
            
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => esc_html__('Unauthorized access', 'linkmaster')));
            }
    
            $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
            $redirect_ids = isset($_POST['redirect_ids']) ? array_map('sanitize_text_field', $_POST['redirect_ids']) : array();
            
            if (empty($bulk_action) || $bulk_action === '-1') {
                throw new Exception(esc_html__('No action selected', 'linkmaster'));
            }
            
            if (empty($redirect_ids)) {
                throw new Exception(esc_html__('No redirects selected', 'linkmaster'));
            }
            
            $redirects = $this->get_redirects();
            
            switch ($bulk_action) {
                case 'delete':
                    foreach ($redirect_ids as $redirect_id) {
                        if (isset($redirects[$redirect_id])) {
                            unset($redirects[$redirect_id]);
                        }
                    }
                    $message = esc_html__('Selected redirects deleted successfully', 'linkmaster');
                    break;
    
                case 'enable':
                    foreach ($redirect_ids as $redirect_id) {
                        if (isset($redirects[$redirect_id])) {
                            $redirects[$redirect_id]['status'] = 'enabled';
                        }
                    }
                    $message = esc_html__('Selected redirects enabled successfully', 'linkmaster');
                    break;
    
                case 'disable':
                    foreach ($redirect_ids as $redirect_id) {
                        if (isset($redirects[$redirect_id])) {
                            $redirects[$redirect_id]['status'] = 'disabled';
                            error_log('LinkMaster: Disabled redirect ' . $redirect_id);
                        }
                    }
                    $message = esc_html__('Selected redirects disabled successfully', 'linkmaster');
                    break;
    
                case 'reset_hits':
                    foreach ($redirect_ids as $redirect_id) {
                        if (isset($redirects[$redirect_id])) {
                            $redirects[$redirect_id]['hits'] = 0;
                        }
                    }
                    $message = esc_html__('Selected redirects hit count reset successfully', 'linkmaster');
                    break;
    
                default:
                    throw new Exception(esc_html__('Invalid bulk action', 'linkmaster'));
            }
            
            update_option('linkmaster_redirects', $redirects);
            wp_send_json_success(array('message' => $message));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_get_redirect_data() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'linkmaster_get_redirect_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'linkmaster')));
        }

        // Check if redirect ID is provided
        if (!isset($_POST['redirect_id'])) {
            wp_send_json_error(array('message' => __('No redirect ID provided.', 'linkmaster')));
        }

        $redirect_id = sanitize_text_field($_POST['redirect_id']);
        $redirects = $this->get_redirects();

        if (!isset($redirects[$redirect_id])) {
            wp_send_json_error(array('message' => __('Redirect not found.', 'linkmaster')));
        }

        $redirect = $redirects[$redirect_id];
        
        // Prepare data for response
        $data = array(
            'id' => $redirect_id,
            'source_url' => $redirect['source_url'],
            'target_url' => $redirect['target_url'],
            'redirect_type' => isset($redirect['redirect_type']) ? $redirect['redirect_type'] : '301',
            'status' => isset($redirect['status']) ? $redirect['status'] : 'enabled',
            'expiration_date' => isset($redirect['expiration_date']) ? $redirect['expiration_date'] : '',
            'nofollow' => !empty($redirect['nofollow']),
            'sponsored' => !empty($redirect['sponsored'])
        );

        wp_send_json_success($data);
    }

    private function perform_redirect($target_url, $redirect_type) {
        // Get the current redirect from the redirects array
        $redirects = get_option('linkmaster_redirects', array());
        $current_redirect = null;
        foreach ($redirects as $redirect) {
            if ($redirect['target_url'] === $target_url) {
                $current_redirect = $redirect;
                break;
            }
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        
        if (!defined('DOING_REDIRECT')) {
            define('DOING_REDIRECT', true);
        }
        
        $redirect_type = (int)$redirect_type;
        if (!in_array($redirect_type, [301, 302, 303, 307, 308])) {
            $redirect_type = 301;
        }
        
        
        if (!headers_sent($filename, $linenum)) {
            error_log("Headers not sent; proceeding with proper redirect.");
            
            header_remove('Last-Modified');
            header_remove('ETag');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');
            header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

            // Add meta tags for sponsored and nofollow attributes if present
            if ($current_redirect) {
                $rel_parts = array();
                if (!empty($current_redirect['nofollow'])) {
                    $rel_parts[] = 'nofollow';
                }
                if (!empty($current_redirect['sponsored'])) {
                    $rel_parts[] = 'sponsored';
                }
                if (!empty($rel_parts)) {
                    header('Link: <' . esc_url($target_url) . '>; rel="' . implode(' ', $rel_parts) . '"');
                }
            }
            
            http_response_code($redirect_type);
            
            if (!preg_match('~^(?:f|ht)tps?://~i', $target_url)) {
                $target_url = home_url($target_url);
            }
            header('Location: ' . $target_url, true, $redirect_type);
        } else {
            error_log("Headers already sent in $filename on line $linenum; using JavaScript fallback");
            // Add meta tags for nofollow/sponsored attributes
            if ($current_redirect) {
                $rel_parts = array();
                if (!empty($current_redirect['nofollow'])) {
                    $rel_parts[] = 'nofollow';
                }
                if (!empty($current_redirect['sponsored'])) {
                    $rel_parts[] = 'sponsored';
                }
                if (!empty($rel_parts)) {
                    echo '<meta name="robots" content="' . esc_attr(implode(',', $rel_parts)) . '">';
                }
            }
            echo '<script>window.location.href = "' . esc_js($target_url) . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url($target_url) . '"></noscript>';
        }
        
        exit();
    }
    
    public function process_redirects() {
        static $redirect_count = 0;
        $max_redirects = 10; // Maximum number of redirects to prevent loops
        
        if ($redirect_count >= $max_redirects) {
            error_log('LinkMaster: Maximum redirect limit reached. Possible redirect loop detected.');
            return;
        }
        $redirect_count++;
        
        $request_uri = $_SERVER['REQUEST_URI'];
        $request_path = parse_url($request_uri, PHP_URL_PATH);
        $skip_extensions = ['jpg', 'jpeg', 'png', 'gif', 'ico', 'css', 'js', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
        $path_info = pathinfo($request_path);
    
        if (
            strpos($_SERVER['PHP_SELF'], 'admin-ajax.php') !== false ||
            (isset($path_info['extension']) && in_array(strtolower($path_info['extension']), $skip_extensions))
        ) {
            return;
        }
    
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
    
        $current_url = $this->get_current_url();
        if (empty($current_url)) {
            error_log('LinkMaster: Skipping redirect processing due to unavailable current URL');
            return;
        }
        
        $current_path = trim(parse_url($current_url, PHP_URL_PATH), '/');
        $site_url = parse_url(site_url(), PHP_URL_PATH);
        $site_path = trim($site_url, '/');
    
        // Remove site path from current path if it exists
        if (!empty($site_path) && strpos($current_path, $site_path) === 0) {
            $current_path = substr($current_path, strlen($site_path));
        }
        $current_path = trim($current_path, '/');
    
        $redirects = get_option('linkmaster_redirects', array());
    
        if (empty($redirects)) {
            return;
        }
    
        foreach ($redirects as $redirect_id => $redirect) {
            // Check if redirect is disabled
            if (isset($redirect['status']) && $redirect['status'] === 'disabled') {
                error_log('LinkMaster: Skipping disabled redirect ' . $redirect_id);
                continue;
            }

            // Check expiration
            if (!empty($redirect['expiration_date'])) {
                $expiration_timestamp = strtotime($redirect['expiration_date']);
                if ($expiration_timestamp && $expiration_timestamp < time()) {
                    // Redirect has expired, disable it
                    $redirects[$redirect_id]['status'] = 'disabled';
                    update_option('linkmaster_redirects', $redirects);
                    error_log('LinkMaster: Redirect ' . $redirect_id . ' expired and disabled');
                    continue;
                }
            }

            // Add link attributes if needed
            $rel_attributes = array();
            if (!empty($redirect['nofollow'])) {
                $rel_attributes[] = 'nofollow';
            }
            if (!empty($redirect['sponsored'])) {
                $rel_attributes[] = 'sponsored';
            }
    
            $source_url = rtrim($redirect['source_url'], '/');
            $target_url = $redirect['target_url'];
            $redirect_type = isset($redirect['redirect_type']) ? (int) $redirect['redirect_type'] : 301;
    
            // Handle full URLs
            if (strpos($source_url, 'http') === 0) {
                // For absolute URLs, normalize both URLs for comparison
                $normalized_source = rtrim($source_url, '/');
                $normalized_current = rtrim($current_url, '/');
                $normalized_target = rtrim($target_url, '/');
                
                // Check for potential direct loop
                if ($normalized_target === $normalized_source) {
                    error_log('LinkMaster: Redirect loop detected. Source and target URLs are the same: ' . $source_url);
                    continue;
                }
                
                if ($normalized_current === $normalized_source) {
                    $this->log_redirect_hit($redirect_id);
                    if (!empty($rel_attributes)) {
                        header('Link: <' . esc_url($target_url) . '>; rel="' . implode(' ', $rel_attributes) . '"');
                    }
                    $this->perform_redirect($target_url, $redirect_type);
                    return;
                }
            } else {
                // Handle relative paths and slugs
                $source_path = trim($source_url, '/');
                $request_path_clean = trim($request_path, '/');
                $current_path_clean = trim($current_path, '/');
                $source_path_clean = trim($source_path, '/');

                error_log('LinkMaster Debug - Comparing paths:');
                error_log('Request Path: ' . $request_path_clean);
                error_log('Current Path: ' . $current_path_clean);
                error_log('Source Path: ' . $source_path_clean);
                error_log('Redirect Status: ' . $redirect['status']);
                
                // Compare both with and without leading slash
                if ($current_path_clean === $source_path_clean || 
                    $request_path_clean === $source_path_clean) {
                    
                    // Double check if the redirect is enabled
                    if (isset($redirect['status']) && $redirect['status'] === 'disabled') {
                        error_log('LinkMaster: Matched but skipping disabled redirect ' . $redirect_id);
                        continue;
                    }

                    error_log('LinkMaster: Processing redirect ' . $redirect_id);
                    $this->log_redirect_hit($redirect_id);
                    
                    if (!empty($rel_attributes)) {
                        header('Link: <' . esc_url($target_url) . '>; rel="' . implode(' ', $rel_attributes) . '"');
                    }
                    
                    // If target URL doesn't start with http, make it a full URL
                    if (strpos($target_url, 'http') !== 0) {
                        $target_url = home_url($target_url);
                    }
                    
                    $this->perform_redirect($target_url, $redirect_type);
                    return;
                }
            }
        }
    }
    
    private function log_redirect_hit($redirect_id) {
        // Track click using the click tracker
        $click_tracker = LinkMaster_Click_Tracker::get_instance();
        
        // Get redirect data
        $redirects = get_option('linkmaster_redirects', array());
        $redirect = isset($redirects[$redirect_id]) ? $redirects[$redirect_id] : null;
        
        if ($redirect) {
            $click_data = array(
                'link_id' => $redirect_id,
                'link_type' => 'redirect',
                'url' => $redirect['target_url'],
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                'referrer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : ''
            );
            
            // Track the click
            if ($click_tracker->track_click($click_data)) {
                // Only update hits if click was successfully tracked
                // Get accurate click count from database
                $total_clicks = $click_tracker->get_clicks_count($redirect_id, 'redirect');
                
                // Update hits in redirects option
                $redirects[$redirect_id]['hits'] = $total_clicks;
                $redirects[$redirect_id]['last_click'] = current_time('mysql');
                update_option('linkmaster_redirects', $redirects);
            }
        }
    }
    
    private function get_current_url() {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        
        // Safely get the host
        $host = '';
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (isset($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
            if (isset($_SERVER['SERVER_PORT']) && !in_array($_SERVER['SERVER_PORT'], [80, 443])) {
                $host .= ':' . $_SERVER['SERVER_PORT'];
            }
        } else {
            // Fallback to WordPress site URL host
            $site_url = parse_url(site_url(), PHP_URL_HOST);
            $host = $site_url ? $site_url : '';
        }
        
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        
        // If no host is available, return an empty string or handle gracefully
        if (empty($host)) {
            error_log('LinkMaster: No host information available in get_current_url()');
            return '';
        }
        
        return $protocol . "://" . $host . $request_uri;
    }

    public function fetch_redirects() {
        return $this->get_redirects();
    }

    private function get_redirects() {
        $redirects = get_option('linkmaster_redirects', array());
        
        // Initialize hits and status for all redirects
        foreach ($redirects as $id => &$redirect) {
            if (!isset($redirect['hits'])) {
                $redirect['hits'] = 0;
            }
            if (!isset($redirect['status'])) {
                $redirect['status'] = 'enabled';
            }
        }
        unset($redirect); // Break the reference
        
        return $redirects;
    }
    
    private function get_redirect_types() {
        return array(
            '301' => esc_html__('Moved Permanently', 'linkmaster'),
            '302' => esc_html__('Found (Temporary Redirect)', 'linkmaster'),
            '303' => esc_html__('See Other', 'linkmaster'),
            '307' => esc_html__('Temporary Redirect', 'linkmaster'),
            '308' => esc_html__('Permanent Redirect', 'linkmaster')
        );
    }
}