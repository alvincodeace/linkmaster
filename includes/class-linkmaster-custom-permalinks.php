<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Custom_Permalinks {
    private static $instance = null;
    private $per_page = 20;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('linkmaster_after_admin_menu', array($this, 'add_submenu_page'), 5);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('add_meta_boxes', array($this, 'add_permalink_meta_box'));
        add_action('save_post', array($this, 'save_custom_permalink'), 10, 1);
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_init', array($this, 'admin_template_refresh'));

        if (class_exists('WooCommerce')) {
            add_filter('woocommerce_product_data_tabs', array($this, 'remove_wc_permalink_tab'), 999);
            add_action('woocommerce_product_data_panels', array($this, 'remove_wc_permalink_panel'), 999);
            add_filter('product_permalink', array($this, 'custom_post_permalink'), 10, 2);
            add_filter('woocommerce_product_get_permalink', array($this, 'custom_post_permalink'), 10, 2);
            add_filter('post_type_link', array($this, 'remove_product_base'), 10, 2);
        }

        add_action('init', array($this, 'register_rewrite_rules'));
        add_action('parse_request', array($this, 'custom_parse_request'));
        add_action('template_redirect', array($this, 'redirect_old_to_custom_permalink'));
        add_filter('post_link', array($this, 'custom_post_permalink'), 10, 2);
        add_filter('page_link', array($this, 'custom_post_permalink'), 10, 2);
        add_filter('post_type_link', array($this, 'custom_post_permalink'), 10, 2);
        add_filter('template_include', array($this, 'enhanced_template_handling'), 99);
        add_filter('redirect_canonical', array($this, 'disable_canonical_redirect'), 10, 2);

        add_action('save_post', array($this, 'clear_template_cache'), 10, 1);
        add_action('edited_post', array($this, 'clear_template_cache'), 10, 1);
        add_action('update_option_page_on_front', array($this, 'clear_template_cache'));
        add_action('update_option_page_for_posts', array($this, 'clear_template_cache'));
        add_action('update_option_show_on_front', array($this, 'clear_template_cache'));
        add_action('update_option_sidebars_widgets', array($this, 'clear_template_cache'));
        add_action('page_template_updated', array($this, 'after_template_switch'), 10, 1);

        add_action('wp_ajax_lmcp_save_permalink', array($this, 'ajax_save_permalink'));
    }

    public function add_submenu_page() {
        add_submenu_page(
            'linkmaster',
            esc_html__('Permalinks', 'linkmaster'),
            esc_html__('Permalinks', 'linkmaster'),
            'edit_posts',
            'linkmaster-custom-permalinks',
            array($this, 'display_admin_page')
        );
    }

    public function enqueue_admin_scripts($hook) {
        global $post;
        
        if (in_array($hook, array('post.php', 'post-new.php')) && $post) {
            wp_enqueue_script(
                'linkmaster-editor-slug-update',
                plugins_url('js/editor-slug-update.js', dirname(__FILE__)),
                array('jquery'),
                LINKMASTER_VERSION,
                true
            );

            wp_localize_script('linkmaster-editor-slug-update', 'lmcpSettings', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('lmcp_save_custom_permalink')
            ));
        }

        if ($hook === 'linkmaster_page_linkmaster-custom-permalinks') {
            wp_enqueue_style(
                'linkmaster-admin-css',
                plugins_url('css/admin.css', dirname(__FILE__)),
                array(),
                LINKMASTER_VERSION
            );
        }
    }

    public function register_settings() {
        register_setting(
            'linkmaster_permalink_options', 
            'linkmaster_permalink_settings', 
            array($this, 'sanitize_settings')
        );
        
        // Register redirects option
        register_setting(
            'linkmaster_permalink_options',
            'linkmaster_redirects',
            array($this, 'sanitize_redirects')
        );
        
        add_settings_section(
            'linkmaster_permalink_section',
            esc_html__('Custom Permalink Settings', 'linkmaster'),
            array($this, 'section_callback'),
            'linkmaster-custom-permalinks'
        );
        
        $this->add_settings_fields();
    }

    public function section_callback() {
        echo '<p>' . esc_html__('Configure your custom permalink settings below.', 'linkmaster') . '</p>';
    }

    public function sanitize_settings($input) {
        $sanitized = array();
        $sanitized['enable_redirects'] = isset($input['enable_redirects']) ? 1 : 0;
        $valid_redirect_types = array('301', '302', '307');
        $sanitized['redirect_type'] = in_array($input['redirect_type'], $valid_redirect_types) 
            ? $input['redirect_type'] 
            : '301';
        $sanitized['preserve_query_strings'] = isset($input['preserve_query_strings']) ? 1 : 0;
        return $sanitized;
    }

    private function add_settings_fields() {
        $fields = array(
            'enable_redirects' => array(
                'title' => __('Enable Redirects', 'linkmaster'),
                'type' => 'checkbox',
                'description' => __('Redirect from default permalinks to custom permalinks', 'linkmaster'),
                'default' => 1
            ),
            'redirect_type' => array(
                'title' => __('Redirect Type', 'linkmaster'),
                'type' => 'select',
                'options' => array(
                    '301' => __('301 (Permanent)', 'linkmaster'),
                    '302' => __('302 (Temporary)', 'linkmaster'),
                    '307' => __('307 (Temporary Strict)', 'linkmaster')
                ),
                'description' => __('Select the type of redirect to use', 'linkmaster'),
                'default' => '301'
            ),
            'preserve_query_strings' => array(
                'title' => __('Preserve Query Strings', 'linkmaster'),
                'type' => 'checkbox',
                'description' => __('Keep query parameters when redirecting', 'linkmaster'),
                'default' => 0
            ),
            'create_redirects' => array(
                'title' => __('Create Redirects When Deleting Permalinks', 'linkmaster'),
                'type' => 'checkbox',
                'description' => __('Automatically create redirects when custom permalinks are deleted to prevent broken links', 'linkmaster'),
                'default' => 1
            )
        );

        foreach ($fields as $key => $field) {
            add_settings_field(
                $key,
                $field['title'],
                array($this, 'render_field'),
                'linkmaster-custom-permalinks',
                'linkmaster_permalink_section',
                array(
                    'label_for' => $key,
                    'field' => $field,
                    'key' => $key
                )
            );
        }
    }

    public function render_field($args) {
        $options = get_option('linkmaster_permalink_settings', array());
        $key = $args['key'];
        $field = $args['field'];
        $value = isset($options[$key]) ? $options[$key] : $field['default'];

        switch ($field['type']) {
            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%1$s" name="linkmaster_permalink_settings[%1$s]" value="1" %2$s>',
                    esc_attr($key),
                    checked(1, $value, false)
                );
                break;

            case 'select':
                printf(
                    '<select id="%s" name="linkmaster_permalink_settings[%s]" class="linkmaster-select">',
                    esc_attr($key),
                    esc_attr($key)
                );
                foreach ($field['options'] as $option_key => $option_label) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr($option_key),
                        selected($option_key, $value, false),
                        esc_html($option_label)
                    );
                }
                echo '</select>';
                break;
        }

        if (!empty($field['description'])) {
            printf(
                '<p class="description">%s</p>',
                wp_kses_post($field['description'])
            );
        }
    }

    private function render_pagination($total_pages, $current_page) {
        if ($total_pages <= 1) {
            return '';
        }

        $first_page_url = esc_url(add_query_arg('paged', 1));
        $prev_page = max(1, $current_page - 1);
        $prev_page_url = esc_url(add_query_arg('paged', $prev_page));
        $next_page = min($total_pages, $current_page + 1);
        $next_page_url = esc_url(add_query_arg('paged', $next_page));
        $last_page_url = esc_url(add_query_arg('paged', $total_pages));

        ob_start();
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
        <?php
        return ob_get_clean();
    }

    public function display_admin_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'linkmaster'));
            return;
        }

        $csv_manager = new LinkMaster_CSV_Manager();

        if (isset($_POST['action']) && $_POST['action'] === 'linkmaster_export_permalinks_csv' && isset($_POST['export_csv'])) {
            $csv_manager->export_permalinks_csv();
        }
        if (isset($_POST['import_csv'])) {
            $csv_manager->import_permalinks_csv();
        }

        $this->process_bulk_actions();

        // Handle bulk search and replace
        if (isset($_POST['bulk_search_replace']) && check_admin_referer('linkmaster_bulk_search_replace')) {
            $this->process_bulk_search_replace();
        }

        // Get active tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'list';

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field($_GET['post_type_filter']) : '';
        $post_types = $this->get_supported_post_types();

        $args = array(
            'post_type' => empty($post_type_filter) ? $post_types : array($post_type_filter),
            'posts_per_page' => $this->per_page,
            'paged' => $current_page,
            'post_status' => array('publish', 'draft'),
            'meta_query' => array(
                array(
                    'key' => '_lmcp_custom_permalink',
                    'compare' => 'EXISTS',
                ),
            ),
        );

        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $total_items = $query->found_posts;
        $total_pages = ceil($total_items / $this->per_page);

        ?>
        <div class="wrap linkmaster-wrap">
            <h1 class="linkmaster-title"><?php esc_html_e('Custom Permalinks', 'linkmaster'); ?></h1>
            
            <!-- Tabs Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'list', remove_query_arg('tab'))); ?>" class="nav-tab <?php echo $active_tab == 'list' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Permalinks List', 'linkmaster'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'csv', remove_query_arg('tab'))); ?>" class="nav-tab <?php echo $active_tab == 'csv' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('CSV Import/Export', 'linkmaster'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'search_replace', remove_query_arg('tab'))); ?>" class="nav-tab <?php echo $active_tab == 'search_replace' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Search & Replace', 'linkmaster'); ?>
                </a>
            </h2>
            
            <?php settings_errors('linkmaster_permalinks_messages'); ?>

            <?php if ($active_tab == 'csv') : ?>
            <!-- CSV Import/Export Section -->
            <div class="linkmaster-card">
                <h2 class="card-title"><?php esc_html_e('CSV Import/Export', 'linkmaster'); ?></h2>
                <p class="card-subtitle"><?php esc_html_e('Manage your custom permalinks in bulk using CSV files.', 'linkmaster'); ?></p>
                
                <form method="post" enctype="multipart/form-data" class="linkmaster-form-inline">
                    <input type="file" name="csv_file" accept=".csv" required class="linkmaster-file-input" />
                    <input type="submit" name="import_csv" value="<?php esc_attr_e('Import CSV', 'linkmaster'); ?>" class="button button-primary linkmaster-button" />
                    <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=linkmaster_download_permalinks_sample_csv')); ?>" class="button linkmaster-button-secondary">
                        <?php esc_html_e('Download Sample CSV', 'linkmaster'); ?>
                    </a>
                </form>
                
                <form method="post" class="linkmaster-form-inline">
                    <input type="hidden" name="action" value="linkmaster_export_permalinks_csv">
                    <?php wp_nonce_field('linkmaster_export_permalinks_csv', 'linkmaster_export_nonce'); ?>
                    <input type="submit" name="export_csv" value="<?php esc_attr_e('Export Permalinks to CSV', 'linkmaster'); ?>" class="button linkmaster-button-secondary" />
                </form>
            </div>
            
            <!-- CSV Format Help -->
            <div class="linkmaster-card" style="margin-top: 20px;">
                <h2 class="card-title"><?php esc_html_e('CSV Format Guide', 'linkmaster'); ?></h2>
                <p class="card-subtitle"><?php esc_html_e('Your CSV file should contain the following columns:', 'linkmaster'); ?></p>
                <ul>
                    <li><strong>type</strong> - <?php esc_html_e('Should be "permalink" for custom permalinks', 'linkmaster'); ?></li>
                    <li><strong>source_url</strong> - <?php esc_html_e('The custom permalink path you want to use (without domain or leading slash)', 'linkmaster'); ?></li>
                    <li><strong>post_title</strong> - <?php esc_html_e('The title of the post or page', 'linkmaster'); ?></li>
                </ul>
                <p><?php esc_html_e('Example: To set a custom permalink "my-custom-url" for a post titled "My Post Title", your CSV row would be: permalink,my-custom-url,My Post Title,', 'linkmaster'); ?></p>
            </div>
            
            <?php elseif ($active_tab == 'search_replace') : ?>
            <!-- Search and Replace Section -->
            <div class="linkmaster-card">
                <h2 class="card-title"><?php esc_html_e('Bulk Search and Replace', 'linkmaster'); ?></h2>
                <p class="card-subtitle"><?php esc_html_e('Search and replace text in your custom permalinks across multiple posts.', 'linkmaster'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('linkmaster_bulk_search_replace'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Search for', 'linkmaster'); ?></th>
                            <td>
                                <input type="text" name="search_pattern" class="regular-text" required>
                                <p class="description"><?php _e('Text or pattern to search for in permalinks', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Replace with', 'linkmaster'); ?></th>
                            <td>
                                <input type="text" name="replace_pattern" class="regular-text" required>
                                <p class="description"><?php _e('Text to replace it with', 'linkmaster'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Post Types', 'linkmaster'); ?></th>
                            <td>
                                <?php
                                $post_types = $this->get_supported_post_types();
                                foreach ($post_types as $post_type) {
                                    $obj = get_post_type_object($post_type);
                                    ?>
                                    <label>
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type); ?>" checked>
                                        <?php echo esc_html($obj->labels->name); ?>
                                    </label><br>
                                    <?php
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Preview Changes', 'linkmaster'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="preview_only" value="1" checked>
                                    <?php _e('Preview changes before applying', 'linkmaster'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="bulk_search_replace" class="button button-primary" value="<?php _e('Search and Replace', 'linkmaster'); ?>">
                    </p>
                </form>
            </div>

            <!-- Search and Replace Help -->
            <div class="linkmaster-card" style="margin-top: 20px;">
                <h2 class="card-title"><?php esc_html_e('Search & Replace Help', 'linkmaster'); ?></h2>
                <p class="card-subtitle"><?php esc_html_e('How to use the search and replace functionality:', 'linkmaster'); ?></p>
                <ul>
                    <li><?php esc_html_e('Enter the text you want to find in the "Search for" field', 'linkmaster'); ?></li>
                    <li><?php esc_html_e('Enter the replacement text in the "Replace with" field', 'linkmaster'); ?></li>
                    <li><?php esc_html_e('Select which post types to include in the search', 'linkmaster'); ?></li>
                    <li><?php esc_html_e('Use the preview option to see changes before applying them', 'linkmaster'); ?></li>
                    <li><?php esc_html_e('Click "Search and Replace" to start the process', 'linkmaster'); ?></li>
                </ul>
                <p><strong><?php esc_html_e('Note:', 'linkmaster'); ?></strong> <?php esc_html_e('This operation cannot be undone. Always use the preview option first.', 'linkmaster'); ?></p>
            </div>
            
            <?php else : /* Default tab - Permalinks List */ ?>
            
            <!-- Filters and Search -->
            <div class="linkmaster-section">
                <form method="get" class="linkmaster-tablenav top">
                    <input type="hidden" name="page" value="linkmaster-custom-permalinks">
                    <input type="hidden" name="tab" value="list">
                    <div class="linkmaster-actions">
                        <select name="post_type_filter" id="post-type-filter" class="linkmaster-select">
                            <option value="" <?php selected($post_type_filter, ''); ?>><?php esc_html_e('All Types', 'linkmaster'); ?></option>
                            <?php foreach ($post_types as $type) : 
                                $post_type_obj = get_post_type_object($type);
                                if ($post_type_obj) : ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($post_type_filter, $type); ?>>
                                        <?php echo esc_html($post_type_obj->labels->singular_name); ?>
                                    </option>
                                <?php endif;
                            endforeach; ?>
                        </select>
                        <input type="submit" id="post-query-submit" class="button linkmaster-button-secondary" value="<?php esc_attr_e('Filter', 'linkmaster'); ?>">
                        <?php if (!empty($post_type_filter) || !empty($search)) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-custom-permalinks&tab=list')); ?>" class="button linkmaster-button-secondary">
                                <?php esc_html_e('Clear Filters', 'linkmaster'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="linkmaster-search">
                        <input type="search" id="permalink-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search permalinks...', 'linkmaster'); ?>">
                        <input type="submit" id="search-submit" class="button linkmaster-button" value="<?php esc_attr_e('Search', 'linkmaster'); ?>">
                    </div>
                </form>

                <!-- Permalinks List -->
                <form method="post" id="linkmaster-permalinks-form">
                    <?php wp_nonce_field('linkmaster_bulk_action', 'linkmaster_bulk_nonce'); ?>
                    <div class="linkmaster-tablenav top">
                        <div class="linkmaster-actions">
                            <select name="action" id="bulk-action-selector-top" class="linkmaster-select">
                                <option value="-1"><?php esc_html_e('Bulk Actions', 'linkmaster'); ?></option>
                                <option value="delete"><?php esc_html_e('Delete Custom Permalink', 'linkmaster'); ?></option>
                            </select>
                            <input type="submit" id="doaction" class="button linkmaster-button-secondary" value="<?php esc_attr_e('Apply', 'linkmaster'); ?>">
                        </div>
                        <div class="linkmaster-pagination">
                            <span class="displaying-num">
                                <?php printf(
                                    _n('%s item', '%s items', $total_items, 'linkmaster'),
                                    number_format_i18n($total_items)
                                ); ?>
                            </span>
                            <?php echo $this->render_pagination($total_pages, $current_page); ?>
                        </div>
                    </div>

                    <table class="linkmaster-table wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-cb check-column">
                                    <input id="cb-select-all-1" type="checkbox">
                                </th>
                                <th scope="col"><?php esc_html_e('Title', 'linkmaster'); ?></th>
                                <th scope="col"><?php esc_html_e('Type', 'linkmaster'); ?></th>
                                <th scope="col"><?php esc_html_e('Custom Permalink', 'linkmaster'); ?></th>
                                <th scope="col"><?php esc_html_e('Actions', 'linkmaster'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post(); 
                                $post_id = get_the_ID();
                                $custom_permalink = get_post_meta($post_id, '_lmcp_custom_permalink', true);
                                $post_type_obj = get_post_type_object(get_post_type());
                                ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input id="cb-select-<?php echo esc_attr($post_id); ?>" type="checkbox" name="post[]" value="<?php echo esc_attr($post_id); ?>">
                                    </th>
                                    <td>
                                        <strong>
                                            <a href="<?php echo esc_url(get_edit_post_link()); ?>" class="linkmaster-page-link">
                                                <?php the_title(); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td><?php echo esc_html($post_type_obj->labels->singular_name); ?></td>
                                    <td>
                                        <code><?php echo esc_html($custom_permalink); ?></code>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url(get_edit_post_link()); ?>" class="button linkmaster-button-edit">
                                            <?php esc_html_e('Edit', 'linkmaster'); ?>
                                        </a>
                                        <a href="<?php echo esc_url(home_url('/' . $custom_permalink)); ?>" target="_blank" class="button linkmaster-button-secondary">
                                            <?php esc_html_e('Preview', 'linkmaster'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; 
                            else : ?>
                                <tr>
                                    <td colspan="5" class="linkmaster-empty-state">
                                        <?php esc_html_e('No custom permalinks found.', 'linkmaster'); ?>
                                    </td>
                                </tr>
                            <?php endif; 
                            wp_reset_postdata(); ?>
                        </tbody>
                    </table>

                    <div class="linkmaster-tablenav bottom">
                        <div class="linkmaster-actions">
                            <select name="action2" id="bulk-action-selector-bottom" class="linkmaster-select">
                                <option value="-1"><?php esc_html_e('Bulk Actions', 'linkmaster'); ?></option>
                                <option value="delete"><?php esc_html_e('Delete Custom Permalink', 'linkmaster'); ?></option>
                            </select>
                            <input type="submit" id="doaction2" class="button linkmaster-button-secondary" value="<?php esc_attr_e('Apply', 'linkmaster'); ?>">
                        </div>
                        <div class="linkmaster-pagination">
                            <span class="displaying-num">
                                <?php printf(
                                    _n('%s item', '%s items', $total_items, 'linkmaster'),
                                    number_format_i18n($total_items)
                                ); ?>
                            </span>
                            <?php echo $this->render_pagination($total_pages, $current_page); ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Quick Help -->
            <div class="linkmaster-card" style="margin-top: 20px;">
                <h2 class="card-title"><?php esc_html_e('Quick Help', 'linkmaster'); ?></h2>
                <p class="card-subtitle"><?php esc_html_e('How to create and manage custom permalinks:', 'linkmaster'); ?></p>
                
                <div class="linkmaster-help-section">
                    <h3><?php esc_html_e('Adding a Custom Permalink', 'linkmaster'); ?></h3>
                    <ol>
                        <li><?php esc_html_e('Navigate to the post or page you want to customize', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Click "Edit" under the post/page title', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Find the "Custom Permalink" meta box (usually below the main content editor)', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Enter your desired URL path without the domain or leading slash', 'linkmaster'); ?></li>
                        <li><strong><?php esc_html_e('Example:', 'linkmaster'); ?></strong> <?php esc_html_e('For https://example.com/my-special-page, just enter "my-special-page"', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Click "Save" to update the permalink', 'linkmaster'); ?></li>
                    </ol>
                </div>
                
                <div class="linkmaster-help-section">
                    <h3><?php esc_html_e('Special Features', 'linkmaster'); ?></h3>
                    <ul>
                        <li><strong><?php esc_html_e('Query Parameters:', 'linkmaster'); ?></strong> <?php esc_html_e('You can include query parameters like "?" in your custom permalinks', 'linkmaster'); ?></li>
                        <li><strong><?php esc_html_e('Example:', 'linkmaster'); ?></strong> <?php esc_html_e('product/search?category=shoes', 'linkmaster'); ?></li>
                        <li><strong><?php esc_html_e('Special Characters:', 'linkmaster'); ?></strong> <?php esc_html_e('LinkMaster supports special characters that WordPress normally doesn\'t allow', 'linkmaster'); ?></li>
                    </ul>
                </div>
                
                <div class="linkmaster-help-section">
                    <h3><?php esc_html_e('Bulk Management', 'linkmaster'); ?></h3>
                    <p><?php esc_html_e('Use the CSV Import/Export tab to manage multiple permalinks at once:', 'linkmaster'); ?></p>
                    <ul>
                        <li><?php esc_html_e('Export your current permalinks to CSV', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Edit the CSV file with a spreadsheet program', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Import the updated CSV to apply changes', 'linkmaster'); ?></li>
                    </ul>
                </div>
                
                <div class="linkmaster-help-section">
                    <h3><?php esc_html_e('Best Practices', 'linkmaster'); ?></h3>
                    <ul>
                        <li><?php esc_html_e('Use short, descriptive URLs for better SEO', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Include relevant keywords in your permalinks', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('Avoid changing permalinks of established pages with high traffic', 'linkmaster'); ?></li>
                        <li><?php esc_html_e('If you must change an established URL, set up a redirect from the old URL', 'linkmaster'); ?></li>
                    </ul>
                </div>
            </div>
            
            <style>
                .linkmaster-help-section {
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 1px solid #eee;
                }
                .linkmaster-help-section:last-child {
                    border-bottom: none;
                    padding-bottom: 0;
                }
                .linkmaster-help-section h3 {
                    font-size: 16px;
                    margin: 15px 0 10px;
                    color: #2271b1;
                }
                .linkmaster-help-section ul, .linkmaster-help-section ol {
                    margin-left: 20px;
                }
                .linkmaster-help-section li {
                    margin-bottom: 8px;
                }
            </style>
            <?php endif; /* End of list tab */ ?>
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

            /* Section Styling */
            .linkmaster-section {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            }

            /* Tablenav and Filters */
            .linkmaster-tablenav {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 10px;
                padding: 0;
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
            .linkmaster-search {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .linkmaster-select {
                height: 32px;
                border-radius: 4px;
                border: 1px solid #8c8f94;
            }
            #permalink-search-input {
                padding: 6px 10px;
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
            .linkmaster-table .linkmaster-page-link {
                color: #2271b1;
                text-decoration: none;
                font-weight: 500;
            }
            .linkmaster-table .linkmaster-page-link:hover {
                color: #135e96;
                text-decoration: underline;
            }
            .linkmaster-table code {
                background: #f1f5f9;
                padding: 2px 4px;
                border-radius: 3px;
                font-size: 12px;
                display: inline-block;
                line-height: 1.4;
                margin: 0;
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
            .linkmaster-empty-state {
                text-align: center;
                padding: 40px;
                color: #6b7280;
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

    private function process_bulk_actions() {
        if (!isset($_POST['linkmaster_bulk_nonce']) || 
            !wp_verify_nonce($_POST['linkmaster_bulk_nonce'], 'linkmaster_bulk_action')) {
            return;
        }

        $action = '';
        if (isset($_POST['action']) && $_POST['action'] !== '-1') {
            $action = $_POST['action'];
        } elseif (isset($_POST['action2']) && $_POST['action2'] !== '-1') {
            $action = $_POST['action2'];
        }

        if (empty($action) || !isset($_POST['post']) || !is_array($_POST['post'])) {
            return;
        }

        $processed = 0;
        $posts = array_map('intval', $_POST['post']);
        $redirects_created = 0;

        switch ($action) {
            case 'delete':
                // Store old permalinks for potential redirection
                $old_permalinks = array();
                
                // First pass: collect all the custom permalinks before deletion
                foreach ($posts as $post_id) {
                    if (current_user_can('edit_post', $post_id)) {
                        $custom_permalink = get_post_meta($post_id, '_lmcp_custom_permalink', true);
                        if (!empty($custom_permalink)) {
                            $old_permalinks[$post_id] = $custom_permalink;
                        }
                    }
                }
                
                // Second pass: delete the permalinks and create redirects if option is enabled
                $create_redirects = get_option('linkmaster_permalink_settings', array());
                $create_redirects = isset($create_redirects['create_redirects']) ? $create_redirects['create_redirects'] : false;
                
                foreach ($posts as $post_id) {
                    if (current_user_can('edit_post', $post_id)) {
                        if (delete_post_meta($post_id, '_lmcp_custom_permalink')) {
                            $processed++;
                            
                            // Create redirect if enabled
                            if ($create_redirects && isset($old_permalinks[$post_id])) {
                                $post_url = get_permalink($post_id);
                                if ($post_url) {
                                    // Store redirect in the database
                                    $this->create_redirect($old_permalinks[$post_id], $post_url, $post_id);
                                    $redirects_created++;
                                }
                            }
                        }
                    }
                }
                
                if ($processed > 0) {
                    flush_rewrite_rules();
                    
                    $message = sprintf(
                        _n(
                            '%d custom permalink deleted successfully.',
                            '%d custom permalinks deleted successfully.',
                            $processed,
                            'linkmaster'
                        ),
                        $processed
                    );
                    
                    if ($redirects_created > 0) {
                        $message .= ' ' . sprintf(
                            _n(
                                '%d redirect created to maintain link integrity.',
                                '%d redirects created to maintain link integrity.',
                                $redirects_created,
                                'linkmaster'
                            ),
                            $redirects_created
                        );
                    }
                    
                    add_settings_error(
                        'linkmaster_notices',
                        'permalinks_deleted',
                        $message,
                        'updated'
                    );
                }
                break;
        }
    }
    
    /**
     * Create a redirect from an old permalink to a new URL
     *
     * @param string $old_permalink The old permalink path
     * @param string $new_url The new URL to redirect to
     * @param int $post_id The post ID associated with this redirect
     * @return bool True if redirect was created successfully
     */
    private function create_redirect($old_permalink, $new_url, $post_id) {
        // Clean up the permalinks
        $old_permalink = trim($old_permalink, '/');
        
        // Store in the redirects table or option
        $redirects = get_option('linkmaster_redirects', array());
        $redirects[$old_permalink] = array(
            'destination' => $new_url,
            'post_id' => $post_id,
            'created' => current_time('mysql'),
            'type' => '301' // Permanent redirect
        );
        
        update_option('linkmaster_redirects', $redirects);
        return true;
    }
    
    /**
     * Process any saved redirects that match the current URL
     * 
     * @return bool True if a redirect was processed, false otherwise
     */
    private function process_saved_redirects() {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return false;
        }
        
        // Get current path
        $request_uri = rawurldecode($_SERVER['REQUEST_URI']);
        $request_uri = sanitize_text_field(wp_unslash($request_uri));
        $site_path = parse_url(home_url(), PHP_URL_PATH);
        if ($site_path && '/' !== $site_path) {
            $request_uri = preg_replace('#^' . preg_quote($site_path, '#') . '#', '', $request_uri);
        }
        
        $url_parts = parse_url($request_uri);
        $path = isset($url_parts['path']) ? trim($url_parts['path'], '/') : '';
        
        // Check if this path is in our redirects
        $redirects = get_option('linkmaster_redirects', array());
        
        if (isset($redirects[$path])) {
            $redirect = $redirects[$path];
            $destination = $redirect['destination'];
            $status = isset($redirect['type']) ? intval($redirect['type']) : 301;
            
            // Add query parameters if they exist and settings allow it
            $settings = get_option('linkmaster_permalink_settings', array());
            $preserve_query = isset($settings['preserve_query_strings']) ? $settings['preserve_query_strings'] : false;
            
            if ($preserve_query && isset($url_parts['query']) && !empty($url_parts['query'])) {
                $destination = add_query_arg($url_parts['query'], $destination);
            }
            
            // Perform the redirect
            wp_redirect($destination, $status);
            exit;
            
            return true;
        }
        
        return false;
    }

    public function register_rewrite_rules() {
        $post_types = $this->get_supported_post_types();

        $args = array(
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'post_status'    => array('publish','draft'),
            'meta_query'     => array(
                array(
                    'key'     => '_lmcp_custom_permalink',
                    'compare' => 'EXISTS',
                ),
            ),
            'fields' => 'ids',
        );

        $posts = get_posts($args);

        foreach ($posts as $post_id) {
            $custom_permalink = get_post_meta($post_id, '_lmcp_custom_permalink', true);
            if ($custom_permalink) {
                $post = get_post($post_id);
                $clean_permalink = trim($custom_permalink, '/');
                $path_info = pathinfo($clean_permalink);
                $base_path = isset($path_info['dirname']) && $path_info['dirname'] !== '.' ? 
                            trailingslashit($path_info['dirname']) . $path_info['filename'] : 
                            $path_info['filename'];
                
                $patterns = array(
                    '^' . preg_quote($clean_permalink, '/') . '$',
                    '^' . preg_quote($clean_permalink, '/') . '/$',
                    '^' . preg_quote($clean_permalink, '/') . '/?\?.*$',
                    '^' . preg_quote($base_path, '/') . '\.[^.\/]+$',
                    '^' . preg_quote($base_path, '/') . '\.[^.\/]+/$',
                    '^' . preg_quote($base_path, '/') . '\.[^.\/]+/?\?.*$'
                );

                foreach ($patterns as $pattern) {
                    switch ($post->post_type) {
                        case 'product':
                            add_rewrite_rule($pattern, 'index.php?product=' . $post->post_name . '&preview=' . ($post->post_status === 'draft' ? 'true' : 'false'), 'top'); // Added preview parameter
                            break;
                        case 'page':
                            add_rewrite_rule($pattern, 'index.php?page_id=' . $post_id . '&preview=' . ($post->post_status === 'draft' ? 'true' : 'false'), 'top'); // Added preview parameter
                            break;
                        case 'post':
                            add_rewrite_rule($pattern, 'index.php?p=' . $post_id . '&preview=' . ($post->post_status === 'draft' ? 'true' : 'false'), 'top'); // Added preview parameter
                            break;
                        default:
                            add_rewrite_rule(
                                $pattern, 
                                'index.php?post_type=' . $post->post_type . '&name=' . $post->post_name . '&preview=' . ($post->post_status === 'draft' ? 'true' : 'false'), // Added preview parameter
                                'top'
                            );
                            break;
                    }
                }
            }
        }

        flush_rewrite_rules(true);
    }

    public function custom_parse_request($wp) {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        $request_uri = rawurldecode($_SERVER['REQUEST_URI']);
        $request_uri = sanitize_text_field(wp_unslash($request_uri));
        $site_path = parse_url(home_url(), PHP_URL_PATH);
        if ($site_path && '/' !== $site_path) {
            $request_uri = preg_replace('#^' . preg_quote($site_path, '#') . '#', '', $request_uri);
        }

        $url_parts = parse_url($request_uri);
        $path = isset($url_parts['path']) ? trim($url_parts['path'], '/') : '';
        $query = isset($url_parts['query']) ? $url_parts['query'] : '';

        // Check if this is a preview request
        $is_preview = isset($_GET['preview']) && $_GET['preview'] === 'true' && current_user_can('edit_posts');

        // If there's a query string in the URL, we need to check if the path with the query is a custom permalink
        if (!empty($query)) {
            $path_with_query = $path . '?' . $query;
            $post_id = $this->get_post_id_by_custom_permalink($path_with_query, $is_preview);
            if ($post_id) {
                // We found a match with the query string included
                $this->handle_permalink_match($wp, $post_id, '', $is_preview);
                return;
            }
        }

        $path_info = pathinfo($path);
        $base_path = isset($path_info['dirname']) && $path_info['dirname'] !== '.' ? 
                    trailingslashit($path_info['dirname']) . $path_info['filename'] : 
                    $path_info['filename'];
        
        $paths_to_try = array(
            $path,
            $base_path,
            trailingslashit($path),
            untrailingslashit($path)
        );

        $post_id = false;
        foreach ($paths_to_try as $try_path) {
            $post_id = $this->get_post_id_by_custom_permalink($try_path, $is_preview);
            if ($post_id) {
                break;
            }
        }

        if ($post_id) {
            $this->handle_permalink_match($wp, $post_id, $query, $is_preview);
        }
    }
    
    /**
     * Handle a permalink match by setting up the proper query variables
     *
     * @param object $wp WP object
     * @param int $post_id Post ID
     * @param string $query Query string
     */
    private function handle_permalink_match($wp, $post_id, $query, $is_preview = false) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }

        // For draft posts, check if user has permission to preview
        if ($post->post_status === 'draft' && !$is_preview) {
            return;
        }

        $wp->query_vars = array();
        switch ($post->post_type) {
            case 'page':
                $wp->query_vars['page_id'] = $post_id;
                $wp->query_vars['post_type'] = 'page';
                $wp->is_page = true;
                $wp->is_singular = true;
                $wp->is_home = false;
                $wp->is_archive = false;
                $wp->is_category = false;
                break;
            case 'product':
                $wp->query_vars['product'] = $post->post_name;
                $wp->query_vars['post_type'] = 'product';
                $wp->query_vars['name'] = $post->post_name;
                $wp->is_single = true;
                $wp->is_singular = true;
                $wp->is_archive = false;
                $wp->is_product = true;
                break;
            default:
                $wp->query_vars['p'] = $post_id;
                $wp->query_vars['post_type'] = $post->post_type;
                $wp->query_vars['name'] = $post->post_name;
                $wp->is_single = true;
                $wp->is_singular = true;
                $wp->is_archive = false;
                break;
        }

        if (!empty($query)) {
            wp_parse_str($query, $query_vars);
            foreach ($query_vars as $key => $value) {
                $wp->query_vars[$key] = $value;
            }
        }

        // Add preview-specific query vars if needed
        if ($is_preview) {
            $wp->query_vars['preview'] = 'true';
            $wp->query_vars['post_id'] = $post_id;
        }

        $wp->query_vars['post_id'] = $post_id;
        $wp->is_404 = false;
        unset($wp->query_vars['error']);
        unset($wp->query_vars['m']);
        unset($wp->query_vars['monthnum']);
        unset($wp->query_vars['day']);
        unset($wp->query_vars['year']);
        unset($wp->query_vars['pagename']);
        unset($wp->query_vars['feed']);
        
        add_filter('template_include', array($this, 'enhanced_template_handling'), 99);
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');
    }

    public function get_post_id_by_custom_permalink($custom_permalink, $is_preview = false) {
        global $wpdb;

            // Modify SQL to include draft posts if previewing
        $post_statuses = $is_preview ? array('publish', 'draft') : array('publish');
        $status_placeholders = implode(',', array_fill(0, count($post_statuses), '%s'));
            
        // Check for exact match first (including query parameters)
        $exact_match_sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_lmcp_custom_permalink' 
            AND meta_value = %s
            LIMIT 1",
            $custom_permalink
        );
        
        $post_id = $wpdb->get_var($exact_match_sql);
        if ($post_id) {
            return (int) $post_id;
        }
        
        // If no exact match, try variations without query parameters
        // First check if there's a query string and separate it
        $url_parts = explode('?', $custom_permalink, 2);
        $path_only = $url_parts[0];
        
        $path_only = trim($path_only, '/');
        $path_info = pathinfo($path_only);
        $dirname = isset($path_info['dirname']) && $path_info['dirname'] !== '.' ? $path_info['dirname'] : '';
        $filename = isset($path_info['filename']) ? $path_info['filename'] : '';
        $extension = isset($path_info['extension']) ? $path_info['extension'] : '';
        $base_path = !empty($dirname) ? trailingslashit($dirname) . $filename : $filename;

        $variations = array_unique(array(
            $path_only,
            trailingslashit($path_only),
            untrailingslashit($path_only),
            $base_path,
            trailingslashit($base_path),
            untrailingslashit($base_path),
            $extension ? $base_path . '.' . $extension : '',
            $extension ? trailingslashit($base_path . '.' . $extension) : ''
        ));

        $placeholders = implode(',', array_fill(0, count($variations), '%s'));
        $sql = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_lmcp_custom_permalink' 
            AND meta_value IN ($placeholders)
            LIMIT 1",
            array_merge($variations, $post_statuses)
        );
        
        $post_id = $wpdb->get_var($sql);
        return $post_id ? (int) $post_id : false;
    }

    public function redirect_old_to_custom_permalink() {
        if (is_admin() || !isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        $settings = get_option('linkmaster_permalink_settings', array(
            'enable_redirects' => 1,
            'redirect_type' => '301',
            'preserve_query_strings' => 0
        ));

        if (empty($settings['enable_redirects'])) {
            return;
        }
        
        // First check for saved redirects (from deleted permalinks)
        if ($this->process_saved_redirects()) {
            return; // Redirect was processed, no need to continue
        }

        $current_request = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        $current_request_path = wp_parse_url($current_request, PHP_URL_PATH);
        $query_string = wp_parse_url($current_request, PHP_URL_QUERY);

        if (strpos($current_request_path, 'favicon.ico') !== false) {
            return;
        }

        $args = array(
            'post_type'      => array('post', 'page'),
            'posts_per_page' => -1,
            'post_status'    => array('publish','draft'),
            'meta_query'     => array(
                array(
                    'key'     => '_lmcp_custom_permalink',
                    'compare' => 'EXISTS',
                ),
            ),
            'fields' => 'ids',
        );

        $posts = get_posts($args);

        foreach ($posts as $post_id) {
            $custom_permalink = get_post_meta($post_id, '_lmcp_custom_permalink', true);
            if ($custom_permalink) {
                $custom_permalink_path = untrailingslashit(wp_make_link_relative(home_url('/' . $custom_permalink)));
                $default_permalink_path = untrailingslashit(wp_make_link_relative(get_permalink($post_id)));
                $current_path = untrailingslashit($current_request_path);

                if (trailingslashit($current_path) === trailingslashit($custom_permalink_path)) {
                    return;
                }

                if (trailingslashit($current_path) === trailingslashit($default_permalink_path)) {
                    $redirect_url = home_url('/' . trim($custom_permalink, '/'));
                    if (!empty($settings['preserve_query_strings']) && !empty($query_string)) {
                        $redirect_url .= '?' . $query_string;
                    }
                    $redirect_type = !empty($settings['redirect_type']) ? intval($settings['redirect_type']) : 301;
                    wp_safe_redirect($redirect_url, $redirect_type);
                    exit;
                }
            }
        }
    }

    public function enhanced_template_handling($template) {
        global $wp_query, $post;

        if (!isset($wp_query->queried_object) || !isset($wp_query->queried_object->ID)) {
            return $template;
        }

        $post_id = $wp_query->queried_object->ID;
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');
        clean_post_cache($post_id);
        
        $post = get_post($post_id);
        setup_postdata($post);
        
        $template_slug = get_page_template_slug($post_id);
        wp_cache_delete('sidebars_widgets', 'options');
        wp_cache_delete('widget_recent_posts', 'options');
        wp_cache_delete('widget_recent_comments', 'options');
        
        if ($template_slug) {
            $possible_templates = array(
                $template_slug,
                str_replace('page-templates/', '', $template_slug),
                'templates/' . $template_slug
            );
            foreach ($possible_templates as $possible_template) {
                if ($located_template = locate_template($possible_template)) {
                    return $located_template;
                }
            }
        }

        if (is_page()) {
            return get_page_template();
        } elseif (is_single()) {
            return get_single_template();
        }
        return $template;
    }

    public function disable_canonical_redirect($redirect_url, $requested_url) {
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            $site_path = wp_parse_url(home_url(), PHP_URL_PATH);
            if ($site_path && '/' !== $site_path) {
                $request_uri = preg_replace('#^' . preg_quote($site_path, '#') . '#', '', $request_uri);
            }
            $request_uri_decoded = untrailingslashit(urldecode($request_uri));
            $post_id = $this->get_post_id_by_custom_permalink($request_uri_decoded);
            if ($post_id) {
                return false;
            }
        }
        return $redirect_url;
    }

    public function clear_template_cache($post_id) {
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'post_meta');
        wp_cache_delete('widget_recent_entries', 'widget');
        wp_cache_delete('widget_recent_comments', 'widget');
        wp_cache_delete('widget_archives', 'widget');
        wp_cache_delete('widget_categories', 'widget');
        flush_rewrite_rules();
    }

    public function refresh_sidebar_cache($post_id) {
        $sidebars_widgets = wp_get_sidebars_widgets();
        if (!empty($sidebars_widgets)) {
            foreach ($sidebars_widgets as $sidebar => $widgets) {
                if (!empty($widgets) && is_array($widgets)) {
                    foreach ($widgets as $widget) {
                        $widget_type = preg_replace('/[0-9]+/', '', $widget);
                        wp_cache_delete("widget_{$widget_type}", 'options');
                    }
                }
            }
        }
        wp_cache_delete('sidebars_widgets', 'options');
        wp_cache_delete($post_id, 'post_meta');
        wp_cache_delete("page_templates-" . wp_get_theme()->get_stylesheet(), 'themes');
        
        global $wp_filter;
        if (isset($wp_filter['template_include'])) {
            $wp_filter['template_include']->callbacks = array();
            add_filter('template_include', array($this, 'enhanced_template_handling'), 99);
        }
    }

    public function admin_template_refresh() {
        if (isset($_POST['lmcp_custom_permalink_nonce']) && 
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lmcp_custom_permalink_nonce'])), 'lmcp_save_custom_permalink')) {
            if (isset($_GET['post']) && is_numeric($_GET['post'])) {
                $post_id = intval($_GET['post']);
                $this->refresh_sidebar_cache($post_id);
            }
        }
    }

    public function after_template_switch($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        $this->refresh_sidebar_cache($post_id);
        flush_rewrite_rules();
    }

    public function admin_notices() {
        if (get_option('linkmaster_permalinks_updated')) {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 esc_html__('Custom permalink updated successfully.', 'linkmaster') . 
                 '</p></div>';
            delete_option('linkmaster_permalinks_updated');
        }
    }

    public function add_permalink_meta_box() {
        $post_types = $this->get_supported_post_types();
        foreach ($post_types as $post_type) {
            $position = ($post_type === 'product') ? 'side' : 'normal';
            $priority = ($post_type === 'product') ? 'high' : 'high';
            add_meta_box(
                'lmcp_permalink_meta_box',
                __('Custom Permalink', 'linkmaster'),
                array($this, 'permalink_meta_box_callback'),
                $post_type,
                $position,
                $priority
            );
        }
    }

    public function permalink_meta_box_callback($post) {
        wp_nonce_field('lmcp_save_custom_permalink', 'lmcp_custom_permalink_nonce');
        $custom_permalink = get_post_meta($post->ID, '_lmcp_custom_permalink', true);
        $site_url = trailingslashit(home_url());
        ?>
        <div class="lmcp-permalink-wrap">
            <div class="lmcp-permalink-input">
                <label for="lmcp_custom_permalink" class="screen-reader-text">
                    <?php esc_html_e('Custom Permalink:', 'linkmaster'); ?>
                </label>
                <span class="lmcp-permalink-prefix"><?php echo esc_url($site_url); ?></span>
                <input type="text" 
                       id="lmcp_custom_permalink" 
                       name="lmcp_custom_permalink" 
                       value="<?php echo esc_attr($custom_permalink); ?>" 
                       class="widefat" 
                       placeholder="<?php esc_attr_e('Enter custom permalink', 'linkmaster'); ?>"
                       style="margin-top: 5px; width: calc(100% - 20px);"
                />
            </div>
            <p class="description">
                <?php esc_html_e('Enter your desired URL path. Leave empty to use the default permalink. Do not include the site URL or leading slash.', 'linkmaster'); ?>
            </p>
            <?php if (!empty($custom_permalink)) : ?>
                <p class="description" style="color: #007cba;">
                    <?php esc_html_e('Current custom permalink:', 'linkmaster'); ?>
                    <a href="<?php echo esc_url($site_url . $custom_permalink); ?>" target="_blank" style="text-decoration: none;">
                        <code style="font-size: 12px; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; display: block;">
                            <?php echo esc_html($site_url . $custom_permalink); ?>
                        </code>
                    </a>
                </p>
            <?php endif; ?>
            <div id="lmcp-save-status" class="hidden">
                <span class="spinner is-active" style="float: none; margin-top: 0;"></span>
                <span class="status-text"></span>
            </div>
        </div>
        <style>
            .lmcp-permalink-wrap {
                padding: 10px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .lmcp-permalink-input {
                display: flex;
                flex-direction: column;
                margin-bottom: 10px;
            }
            .lmcp-permalink-prefix {
                color: #666;
                margin-bottom: 5px;
                font-family: monospace;
            }
            #lmcp_custom_permalink {
                padding: 8px;
                font-family: monospace;
            }
            .description {
                margin-top: 8px;
                margin-bottom: 8px;
            }
            #lmcp-save-status {
                margin-top: 10px;
                padding: 5px;
            }
            #lmcp-save-status.hidden {
                display: none;
            }
            #lmcp-save-status .status-text {
                margin-left: 5px;
                color: #007cba;
            }
        </style>
        <?php
    }

    public function save_custom_permalink($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (get_post_status($post_id) === 'auto-draft') {
            return;
        }
        if (!isset($_POST['lmcp_custom_permalink_nonce']) || 
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lmcp_custom_permalink_nonce'])), 'lmcp_save_custom_permalink')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $old_permalink = get_post_meta($post_id, '_lmcp_custom_permalink', true);

        if (isset($_POST['lmcp_custom_permalink'])) {
            $custom_permalink = sanitize_text_field(wp_unslash($_POST['lmcp_custom_permalink']));
            $custom_permalink = preg_replace('/\.[^.\/]+$/', '', $custom_permalink);
            $extension = '';
            if (preg_match('/(\.[^.\/]+)$/', $_POST['lmcp_custom_permalink'], $matches)) {
                $extension = $matches[1];
            }
            $custom_permalink = ltrim($custom_permalink, '/');
            if (!empty($extension)) {
                $custom_permalink .= $extension;
            }

            delete_post_meta($post_id, '_lmcp_custom_permalink');
            if (!empty($custom_permalink)) {
                update_post_meta($post_id, '_lmcp_custom_permalink', $custom_permalink);
                clean_post_cache($post_id);
                wp_cache_delete($post_id, 'posts');
                wp_cache_delete($post_id, 'post_meta');
                wp_cache_delete('get_pages', 'posts');
                delete_option('rewrite_rules');
                global $wp_rewrite;
                $wp_rewrite->init();
                $wp_rewrite->flush_rules(true);

                $preview_url = home_url('/' . $custom_permalink);
                update_option('linkmaster_permalinks_updated', true);
                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    array('post_name' => sanitize_title($custom_permalink)),
                    array('ID' => $post_id)
                );
            }

            clean_post_cache($post_id);
            delete_option('rewrite_rules');
            $this->register_rewrite_rules();
            flush_rewrite_rules(true);
        }
    }

    public function custom_post_permalink($permalink, $post) {
        if (!is_object($post)) {
            $post = get_post($post);
        }
        if (!$post) {
            return $permalink;
        }

        $custom_permalink = get_post_meta($post->ID, '_lmcp_custom_permalink', true);
        if (!empty($custom_permalink)) {
            // Get permalink structure to check for trailing slash
            $permalink_structure = get_option('permalink_structure');
            $should_use_trailing_slash = substr($permalink_structure, -1) === '/';

            $has_extension = (bool) preg_match('/\.[^.\/]+$/', $custom_permalink);
            $custom_permalink = ltrim($custom_permalink, '/');
            
            if ($has_extension) {
                return home_url('/' . $custom_permalink);
            }
            
            $extension = '';
            if (preg_match('/(\.[^.\/]+)$/', $permalink, $matches)) {
                $extension = $matches[1];
            }

            $url = home_url('/' . $custom_permalink . $extension);
            
            // Add trailing slash only if there's no extension and permalink structure uses it
            if (!$extension && $should_use_trailing_slash) {
                $url = trailingslashit($url);
            }

            if ($post->post_status === 'draft' && current_user_can('edit_post', $post->ID)) {
                $url = add_query_arg('preview', 'true', $url);
            }
            
            return $url;
        }
        return $permalink;
    }

    public function ajax_save_permalink() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lmcp_save_custom_permalink')) {
                throw new Exception('Security check failed');
            }
            if (!current_user_can('edit_posts')) {
                throw new Exception('Insufficient permissions');
            }

            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            $custom_permalink = isset($_POST['custom_permalink']) ? sanitize_text_field(wp_unslash($_POST['custom_permalink'])) : '';
            if (!$post_id || !$custom_permalink) {
                throw new Exception('Missing required data');
            }

            $post = get_post($post_id);
            if (!$post) {
                throw new Exception('Invalid post ID');
            }

            $custom_permalink = trim($custom_permalink, '/');
            global $wpdb;
            $wpdb->query('START TRANSACTION');

            try {
                delete_post_meta($post_id, '_lmcp_custom_permalink');
                $meta_updated = update_post_meta($post_id, '_lmcp_custom_permalink', $custom_permalink);
                if ($meta_updated === false) {
                    throw new Exception('Failed to update permalink meta');
                }

                $post_name_updated = $wpdb->update(
                    $wpdb->posts,
                    array('post_name' => sanitize_title($custom_permalink)),
                    array('ID' => $post_id)
                );
                if ($post_name_updated === false) {
                    throw new Exception('Failed to update post name');
                }

                clean_post_cache($post_id);
                wp_cache_delete($post_id, 'posts');
                wp_cache_delete($post_id, 'post_meta');
                wp_cache_delete('get_pages', 'posts');
                delete_option('rewrite_rules');
                global $wp_rewrite;
                $wp_rewrite->init();
                $wp_rewrite->flush_rules(true);

                $wpdb->query('COMMIT');

                $preview_url = home_url('/' . $custom_permalink);
                wp_send_json_success(array(
                    'message' => 'Permalink updated successfully',
                    'permalink' => $preview_url,
                    'post_name' => sanitize_title($custom_permalink)
                ));
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                throw $e;
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function remove_wc_permalink_tab($tabs) {
        if (isset($tabs['permalink'])) {
            unset($tabs['permalink']);
        }
        return $tabs;
    }

    public function remove_wc_permalink_panel() {
        remove_action('woocommerce_product_options_permalink', 'woocommerce_product_options_permalink');
    }

    public function remove_product_base($permalink, $post) {
        if ($post->post_type !== 'product') {
            return $permalink;
        }
        $custom_permalink = get_post_meta($post->ID, '_lmcp_custom_permalink', true);
        if (!empty($custom_permalink)) {
            return home_url('/' . trim($custom_permalink, '/'));
        }
        return $permalink;
    }

    private function get_supported_post_types() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $supported_types = array();
        foreach ($post_types as $post_type => $post_type_obj) {
            if ($post_type === 'attachment') {
                continue;
            }
            $supported_types[] = $post_type;
        }
        return $supported_types;
    }

    private function process_bulk_search_replace() {
        $search_pattern = sanitize_text_field(wp_unslash($_POST['search_pattern']));
        $replace_pattern = sanitize_text_field(wp_unslash($_POST['replace_pattern']));
        $post_types = isset($_POST['post_types']) ? $_POST['post_types'] : array();
        $preview_only = isset($_POST['preview_only']) && $_POST['preview_only'] == '1';

        if (empty($search_pattern) || empty($replace_pattern)) {
            add_settings_error('linkmaster_notices', 'invalid_input', __('Please enter both search and replace patterns.', 'linkmaster'), 'error');
            return;
        }

        $args = array(
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => '_lmcp_custom_permalink',
                    'compare' => 'EXISTS',
                ),
            ),
            'fields' => 'ids',
        );

        $posts = get_posts($args);
        $changes = array();
        
        foreach ($posts as $post_id) {
            $custom_permalink = get_post_meta($post_id, '_lmcp_custom_permalink', true);
            if (!empty($custom_permalink)) {
                $new_permalink = str_replace($search_pattern, $replace_pattern, $custom_permalink);
                if ($new_permalink !== $custom_permalink) {
                    $changes[] = array(
                        'post_id' => $post_id,
                        'title' => get_the_title($post_id),
                        'old_permalink' => $custom_permalink,
                        'new_permalink' => $new_permalink,
                    );
                }
            }
        }

        if (empty($changes)) {
            add_settings_error(
                'linkmaster_notices', 
                'no_changes', 
                __('No permalinks found matching your search pattern.', 'linkmaster'), 
                'info'
            );
            return;
        }

        if ($preview_only) {
            // Show preview table
            ?>
            <div class="notice notice-info">
                <p><?php _e('Preview of changes (these changes have not been applied yet):', 'linkmaster'); ?></p>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field('linkmaster_bulk_search_replace'); ?>
                <input type="hidden" name="search_pattern" value="<?php echo esc_attr($search_pattern); ?>">
                <input type="hidden" name="replace_pattern" value="<?php echo esc_attr($replace_pattern); ?>">
                <?php foreach ($post_types as $post_type) : ?>
                    <input type="hidden" name="post_types[]" value="<?php echo esc_attr($post_type); ?>">
                <?php endforeach; ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Post Title', 'linkmaster'); ?></th>
                            <th><?php _e('Current Permalink', 'linkmaster'); ?></th>
                            <th><?php _e('New Permalink', 'linkmaster'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changes as $change) : ?>
                            <tr>
                                <td><?php echo esc_html($change['title']); ?></td>
                                <td><code><?php echo esc_html($change['old_permalink']); ?></code></td>
                                <td><code><?php echo esc_html($change['new_permalink']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="submit">
                    <input type="submit" name="bulk_search_replace" class="button button-primary" value="<?php _e('Apply Changes', 'linkmaster'); ?>">
                </p>
            </form>
            <?php
        } else {
            // Apply changes
            $updated = 0;
            foreach ($changes as $change) {
                $post_id = $change['post_id'];
                $new_permalink = $change['new_permalink'];
                
                // Update the permalink
                update_post_meta($post_id, '_lmcp_custom_permalink', $new_permalink);
                
                // Create redirect from old to new URL
                $this->create_redirect($change['old_permalink'], $new_permalink, $post_id);
                
                $updated++;
            }
            
            add_settings_error(
                'linkmaster_notices', 
                'changes_applied', 
                sprintf(
                    _n(
                        'Updated %d permalink successfully.',
                        'Updated %d permalinks successfully.',
                        $updated,
                        'linkmaster'
                    ), 
                    $updated
                ), 
                'success'
            );
        }
    }
}