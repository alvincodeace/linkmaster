<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Custom_404 {
    private static $instance = null;
    private $table_name;
    private $options;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'linkmaster_404_logs';
        $this->options = get_option('linkmaster_404_settings', array(
            'enabled' => false,
            'page_id' => 0,
            'custom_message' => '',
            'enable_search' => true,
            'noindex' => true
        ));

        // Create table if it doesn't exist
        $this->create_table();

        // Initialize the feature
        $this->init();
    }

    public function create_table() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                url varchar(2048) NOT NULL,
                timestamp datetime DEFAULT CURRENT_TIMESTAMP,
                hit_count bigint(20) DEFAULT 1,
                PRIMARY KEY (id),
                UNIQUE KEY url (url(191)),
                KEY hit_count (hit_count)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);

            // Verify table was created
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
            if (!$table_exists) {
                error_log('LinkMaster Error: Failed to create 404 logs table');
                error_log('LinkMaster SQL Error: ' . $wpdb->last_error);
            } else {
                error_log('LinkMaster: Successfully created 404 logs table');
            }
        }
    }

    private function init() {
        // Admin hooks
        add_action('linkmaster_after_admin_menu', array($this, 'add_submenu_page'), 25);
        add_action('admin_init', array($this, 'register_settings'));
        
        // Frontend hooks
        add_action('template_redirect', array($this, 'handle_404'), 1);
    }

    public function add_submenu_page() {
        add_submenu_page(
            'linkmaster',
            __('Custom 404 Pages', 'linkmaster'),
            __('Custom 404 Pages', 'linkmaster'),
            'manage_options',
            'linkmaster-custom-404',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings() {
        register_setting('linkmaster_404_settings', 'linkmaster_404_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
    }

    public function sanitize_settings($input) {
        $sanitized = array();
        $sanitized['enabled'] = isset($input['enabled']) ? true : false;
        $sanitized['page_id'] = absint($input['page_id']);
        $sanitized['custom_message'] = wp_kses_post($input['custom_message']);
        $sanitized['enable_search'] = isset($input['enable_search']) ? true : false;
        $sanitized['noindex'] = isset($input['noindex']) ? true : false;
        return $sanitized;
    }

    public function handle_404() {
        if (!is_404() || empty($this->options['enabled'])) {
            return;
        }

        // Add noindex meta tag if enabled
        if (!empty($this->options['noindex'])) {
            add_action('wp_head', array($this, 'add_noindex_tag'), 0);
        }

        // Log the 404 error
        $this->log_404_error();

        // Get the custom 404 page content
        $page_id = !empty($this->options['page_id']) ? intval($this->options['page_id']) : 0;
        $custom_message = !empty($this->options['custom_message']) ? $this->options['custom_message'] : '';
        $enable_search = !empty($this->options['enable_search']);

        // Set proper 404 status
        status_header(404);
        nocache_headers();

        // Add custom styles
        add_action('wp_head', function() use ($enable_search) {
            ?>
            <style>
                .linkmaster-404-container {
                    max-width: 800px;
                    margin: 40px auto;
                    padding: 20px;
                    text-align: center;
                }
                .linkmaster-404-message {
                    display: inline-block;
                    font-size: 24px;
                    line-height: 1.6;
                    margin-bottom: 30px;
                    color: #333;
                    padding: 20px;
                }
                .linkmaster-404-message p {
                    margin: 0;
                }
                <?php if (!$enable_search): ?>
                .linkmaster-404-search {
                    display: none !important;
                }
                <?php endif; ?>
                .linkmaster-404-search {
                    max-width: 500px;
                    margin: 0 auto 40px;
                    padding: 0 20px;
                }
                .linkmaster-404-search form {
                    display: flex;
                    gap: 10px;
                }
                .linkmaster-404-search .search-field {
                    flex: 1;
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }
                .linkmaster-404-search .search-submit {
                    padding: 8px 20px;
                    background: #0073aa;
                    color: #fff;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                }
                .linkmaster-404-search .search-submit:hover {
                    background: #005177;
                }
                @media (max-width: 600px) {
                    .linkmaster-404-search form {
                        flex-direction: column;
                        gap: 15px;
                    }
                    .linkmaster-404-search .search-field,
                    .linkmaster-404-search .search-submit {
                        width: 100%;
                        max-width: none;
                    }
                }
            </style>
            <?php
        });

        // Get header
        get_header();

        ?>
        <div class="linkmaster-404-container">
            <?php if (!empty($custom_message)) : ?>
                <div class="linkmaster-404-message">
                    <?php echo wp_kses_post($custom_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($enable_search) : ?>
            <div class="linkmaster-404-search">
                <form role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                    <input type="text" class="search-field" placeholder="<?php echo esc_attr_x('Search...', 'placeholder', 'linkmaster'); ?>" value="<?php echo get_search_query(); ?>" name="s" />
                    <button type="submit" class="search-submit"><?php _e('Search', 'linkmaster'); ?></button>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($page_id > 0) : ?>
                <div class="linkmaster-404-content">
                    <?php 
                    $page = get_post($page_id);
                    if ($page) {
                        echo apply_filters('the_content', $page->post_content);
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        // Get footer
        get_footer();
        exit;
    }

    private function log_404_error() {
        global $wpdb;

        // First verify table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            $this->create_table();
        }

        // Get current URL
        $current_url = esc_url_raw((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
            "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");

        // Get current time in WordPress format
        $current_time = current_time('mysql', true);
        
        // Insert or update the record
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table_name} (url, hit_count, timestamp) 
            VALUES (%s, 1, %s) 
            ON DUPLICATE KEY UPDATE 
            hit_count = hit_count + 1,
            timestamp = %s",
            $current_url,
            $current_time,
            $current_time
        ));

        if ($result === false) {
            error_log('LinkMaster Error: Failed to log 404 error');
            error_log('LinkMaster SQL Error: ' . $wpdb->last_error);
        }
    }

    public function add_noindex_tag() {
        // Only proceed if this is a 404 page and the feature is enabled
        if (!is_404() || empty($this->options['enabled'])) {
            return;
        }

        // Check if noindex is enabled in settings
        if (!empty($this->options['noindex'])) {
            // Remove any existing robots meta tag to prevent conflicts
            remove_action('wp_head', 'wp_robots', 1);
            remove_action('wp_head', 'noindex', 1);
            
            // Add our noindex meta tag
            echo "<!-- LinkMaster Custom 404 noindex tag -->\n";
            echo '<meta name="robots" content="noindex,follow" />' . "\n";
            
            // Log that we've added the noindex tag
            error_log('LinkMaster: Added noindex meta tag to 404 page: ' . $_SERVER['REQUEST_URI']);
        }
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        
        // Check for settings update
        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Custom 404 settings saved successfully!', 'linkmaster'); ?></p>
            </div>
            <?php
        }

        // First verify table exists
        global $wpdb;
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            $this->create_table();
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=linkmaster-custom-404&tab=settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Settings', 'linkmaster'); ?>
                </a>
                <a href="?page=linkmaster-custom-404&tab=logs" class="nav-tab <?php echo $current_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('404 Logs', 'linkmaster'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                if ($current_tab === 'settings') {
                    $this->render_settings_tab();
                } else {
                    $this->render_logs_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_settings_tab() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('linkmaster_404_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Custom 404', 'linkmaster'); ?></th>
                    <td>
                        <label class="switch">
                            <input type="checkbox" name="linkmaster_404_settings[enabled]" 
                                <?php checked(!empty($this->options['enabled'])); ?>>
                            <span class="slider round"></span>
                        </label>
                        <p class="description"><?php _e('Toggle custom 404 page functionality', 'linkmaster'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Select 404 Page', 'linkmaster'); ?></th>
                    <td>
                        <?php
                        wp_dropdown_pages(array(
                            'name' => 'linkmaster_404_settings[page_id]',
                            'show_option_none' => __('Select a page', 'linkmaster'),
                            'selected' => $this->options['page_id']
                        ));
                        ?>
                        <p class="description"><?php _e('Choose a page to display when a 404 error occurs.', 'linkmaster'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Custom Message', 'linkmaster'); ?></th>
                    <td>
                        <input type="text" name="linkmaster_404_settings[custom_message]" 
                            value="<?php echo esc_attr($this->options['custom_message']); ?>" 
                            class="regular-text">
                        <p class="description"><?php _e('Optional custom message to display on the 404 page.', 'linkmaster'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Enable Search', 'linkmaster'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="linkmaster_404_settings[enable_search]" 
                                <?php checked($this->options['enable_search']); ?>>
                            <?php _e('Add a search bar to help visitors find content', 'linkmaster'); ?>
                        </label>
                        <p class="description"><?php _e('Use shortcode [linkmaster_404_search] in your 404 page.', 'linkmaster'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('SEO Settings', 'linkmaster'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="linkmaster_404_settings[noindex]" 
                                <?php checked($this->options['noindex']); ?>>
                            <?php _e('Add noindex meta tag to 404 pages', 'linkmaster'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }

    private function render_logs_tab() {
        // Get current page number
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 5;
        $offset = ($page - 1) * $per_page;

        global $wpdb;

        // Get total count for unique URLs
        $total_items = $wpdb->get_var("SELECT COUNT(DISTINCT url) FROM {$this->table_name}");

        // Get paginated results
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT url, hit_count, MAX(timestamp) as last_hit 
            FROM {$this->table_name} 
            GROUP BY url, hit_count
            ORDER BY hit_count DESC 
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));

        $total_pages = ceil($total_items / $per_page);

        ?>
        <div class="linkmaster-404-logs">
            <div class="tablenav top">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php echo number_format_i18n($total_items); ?> items
                    </span>
                    <?php if ($total_pages > 1) : ?>
                        <span class="pagination-links">
                            <?php
                            // First page
                            $first_page = 1;
                            $first_disabled = $page <= 1;
                            printf(
                                '<a class="first-page button %s" href="%s" aria-label="%s">%s</a>',
                                $first_disabled ? 'disabled' : '',
                                $first_disabled ? '#' : esc_url(add_query_arg(array('paged' => $first_page, 'tab' => 'logs'))),
                                __('First page'),
                                '«'
                            );

                            // Previous page
                            $prev_page = max(1, $page - 1);
                            $prev_disabled = $page <= 1;
                            printf(
                                '<a class="prev-page button %s" href="%s" aria-label="%s">%s</a>',
                                $prev_disabled ? 'disabled' : '',
                                $prev_disabled ? '#' : esc_url(add_query_arg(array('paged' => $prev_page, 'tab' => 'logs'))),
                                __('Previous page'),
                                '‹'
                            );

                            // Current of total pages
                            echo '<span class="paging-input">' . 
                                 sprintf(_x('%1$s of %2$s', 'paging'), $page, $total_pages) .
                                 '</span>';

                            // Next page
                            $next_page = min($total_pages, $page + 1);
                            $next_disabled = $page >= $total_pages;
                            printf(
                                '<a class="next-page button %s" href="%s" aria-label="%s">%s</a>',
                                $next_disabled ? 'disabled' : '',
                                $next_disabled ? '#' : esc_url(add_query_arg(array('paged' => $next_page, 'tab' => 'logs'))),
                                __('Next page'),
                                '›'
                            );

                            // Last page
                            $last_disabled = $page >= $total_pages;
                            printf(
                                '<a class="last-page button %s" href="%s" aria-label="%s">%s</a>',
                                $last_disabled ? 'disabled' : '',
                                $last_disabled ? '#' : esc_url(add_query_arg(array('paged' => $total_pages, 'tab' => 'logs'))),
                                __('Last page'),
                                '»'
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-url"><?php _e('URL', 'linkmaster'); ?></th>
                        <th class="column-hits"><?php _e('Hits', 'linkmaster'); ?></th>
                        <th class="column-last-hit"><?php _e('Last Occurrence', 'linkmaster'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr>
                            <td colspan="3"><?php _e('No 404 errors have been logged yet.', 'linkmaster'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="column-url">
                                <?php echo esc_html($log->url); ?>
                            </td>
                            <td class="column-hits">
                                <?php echo esc_html($log->hit_count); ?>
                            </td>
                            <td class="column-last-hit">
                                <?php 
                                echo esc_html(get_date_from_gmt(
                                    $log->last_hit,
                                    get_option('date_format') . ' ' . get_option('time_format')
                                )); 
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php if ($total_pages > 1) : ?>
                        <span class="pagination-links">
                            <?php
                            // First page
                            $first_page = 1;
                            $first_disabled = $page <= 1;
                            printf(
                                '<a class="first-page button %s" href="%s" aria-label="%s">%s</a>',
                                $first_disabled ? 'disabled' : '',
                                $first_disabled ? '#' : esc_url(add_query_arg(array('paged' => $first_page, 'tab' => 'logs'))),
                                __('First page'),
                                '«'
                            );

                            // Previous page
                            $prev_page = max(1, $page - 1);
                            $prev_disabled = $page <= 1;
                            printf(
                                '<a class="prev-page button %s" href="%s" aria-label="%s">%s</a>',
                                $prev_disabled ? 'disabled' : '',
                                $prev_disabled ? '#' : esc_url(add_query_arg(array('paged' => $prev_page, 'tab' => 'logs'))),
                                __('Previous page'),
                                '‹'
                            );

                            // Current of total pages
                            echo '<span class="paging-input">' . 
                                 sprintf(_x('%1$s of %2$s', 'paging'), $page, $total_pages) .
                                 '</span>';

                            // Next page
                            $next_page = min($total_pages, $page + 1);
                            $next_disabled = $page >= $total_pages;
                            printf(
                                '<a class="next-page button %s" href="%s" aria-label="%s">%s</a>',
                                $next_disabled ? 'disabled' : '',
                                $next_disabled ? '#' : esc_url(add_query_arg(array('paged' => $next_page, 'tab' => 'logs'))),
                                __('Next page'),
                                '›'
                            );

                            // Last page
                            $last_disabled = $page >= $total_pages;
                            printf(
                                '<a class="last-page button %s" href="%s" aria-label="%s">%s</a>',
                                $last_disabled ? 'disabled' : '',
                                $last_disabled ? '#' : esc_url(add_query_arg(array('paged' => $total_pages, 'tab' => 'logs'))),
                                __('Last page'),
                                '»'
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
