<?php
/*
Plugin Name: LinkMaster
Description: The ultimate WordPress plugin for managing custom permalinks, SEO redirects, broken link detection, and link tracking. Supports advanced URL structures, query parameters, file extensions, and smart redirections to boost SEO and user experience.
Version: 2.5.0
Author: Codeace
Author URI: https://codeace.com
License: GPLv3
Text Domain: linkmaster
*/

// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LINKMASTER_VERSION', '2.5.0');
define('LINKMASTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LINKMASTER_PLUGIN_URL', plugin_dir_url(__FILE__));

class LinkMaster_Plugin {
    private static $instance = null;
    private $redirector = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init();

        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('wp_ajax_lmcp_save_permalink', array($this, 'ajax_save_permalink'));
        add_action('wp_ajax_lmcp_flush_rules', array($this, 'ajax_flush_rules'));
        add_action('wp_ajax_linkmaster_manual_scan', array($this, 'handle_manual_scan'));
        add_action('wp_ajax_linkmaster_get_health_score', array($this, 'handle_get_health_score'));
    }

    private function load_dependencies() {
        if (is_admin()) {
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-scanner.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-broken-links-admin.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-link-redirector-admin.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-custom-permalinks.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-csv-manager.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-click-tracker.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-click-stats-admin.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-health-score.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-onboarding.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-custom-404.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-cloaked-links.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-auto-links.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-auto-links-admin.php';
        } else {
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-custom-permalinks.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-custom-404.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-click-tracker.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-link-redirector-admin.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-cloaked-links.php';
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-auto-links.php';
            error_log('LinkMaster: Frontend dependencies loaded');
        }
    }

    private function init() {
        // Register custom schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        
        // Initialize components
        $custom_permalinks = LinkMaster_Custom_Permalinks::get_instance();
        $custom_404 = LinkMaster_Custom_404::get_instance();
        $this->redirector = LinkMaster_Link_Redirector_Admin::get_instance();
        $cloaked_links = LinkMaster_Cloaked_Links::get_instance();
        $auto_links = LinkMaster_Auto_Links::get_instance();

        if (!is_admin() || wp_doing_ajax() || defined('DOING_AJAX')) {
            if (!class_exists('LinkMaster_Link_Redirector_Admin')) {
                require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-link-redirector-admin.php';
                $this->redirector = LinkMaster_Link_Redirector_Admin::get_instance();
            }
        }

        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
            
            // Initialize admin components
            LinkMaster_Click_Stats_Admin::get_instance();
            $broken_links = LinkMaster_Broken_Links_Admin::get_instance();
            $scanner = LinkMaster_Scanner::get_instance();
            $redirector = LinkMaster_Link_Redirector_Admin::get_instance();
            $health_score = LinkMaster_Health_Score::get_instance();
            $cloaked_links = LinkMaster_Cloaked_Links::get_instance();
            $auto_links_admin = LinkMaster_Auto_Links_Admin::get_instance();
            $onboarding = LinkMaster_Onboarding::get_instance();
            
            // Register AJAX handler for manual scan
            add_action('wp_ajax_linkmaster_manual_scan', array($this, 'handle_manual_scan'));
            if (!$this->redirector) {
                $this->redirector = $redirector;
            }
            add_action('init', array($scanner, 'init'));
            error_log('LinkMaster: Admin initialization complete');
        }

        add_action('muplugins_loaded', array($this, 'handle_redirects'), -999999);
        add_action('plugins_loaded', array($this, 'handle_redirects'), -999999);
        add_action('send_headers', array($this, 'handle_redirects'), -999999);
        add_action('init', array($this, 'handle_redirects'), -999999);
        add_action('parse_request', array($this, 'handle_redirects'), -999999);
        add_action('wp', array($this, 'handle_redirects'), -999999);
        add_action('template_redirect', array($this, 'handle_redirects'), -999999);
    }

    public function add_admin_menu() {
        // Add main menu page
        add_menu_page(
            __('LinkMaster', 'linkmaster'),
            __('LinkMaster', 'linkmaster'),
            'edit_posts',
            'linkmaster',
            array($this, 'display_main_page'),
            'dashicons-admin-links',
            30
        );

        // Add Dashboard as first submenu with a different slug to avoid duplicate
        add_submenu_page(
            'linkmaster',
            __('Dashboard', 'linkmaster'),
            __('Dashboard', 'linkmaster'),
            'edit_posts',
            'linkmaster-dashboard',
            array($this, 'display_main_page')
        );

        // Remove the duplicate "LinkMaster" submenu item
        global $submenu;
        if (isset($submenu['linkmaster'])) {
            unset($submenu['linkmaster'][0]);
        }
        
        // Hook for other submenu items
        do_action('linkmaster_after_admin_menu');
        error_log('LinkMaster: Admin menu added');
    }

    public function display_main_page() {
        $docs_url = 'https://getlinkmaster.com/docs';
        $support_url = 'https://wordpress.org/support/plugin/linkmaster/';
        $add_review_url = 'https://wordpress.org/support/plugin/linkmaster/reviews/#new-post';
        error_log('LinkMaster: Displaying main dashboard page');
        ?>
        <div class="wrap linkmaster-dashboard">
            <h1>
                <span class="dashicons dashicons-admin-links"></span>
                <?php esc_html_e('LinkMaster', 'linkmaster'); ?>
            </h1>
            
            <!-- Link Health Score Card - Full Width -->
            <div class="linkmaster-health-score-section">
                <div class="linkmaster-card linkmaster-health-score-card">
                    <div class="linkmaster-card-header">
                        <h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e('Link Health Score', 'linkmaster'); ?></h2>
                    </div>
                    <!-- Hidden nonce field for AJAX security -->
                    <input type="hidden" id="linkmaster-health-nonce" value="<?php echo esc_attr(wp_create_nonce('linkmaster_health_score')); ?>" />
                    <div class="linkmaster-card-content">
                        <div id="linkmaster-health-score-container" class="linkmaster-health-score-container">
                            <div class="linkmaster-score-loading">
                                <span class="spinner is-active"></span>
                                <p><?php esc_html_e('Calculating link health score...', 'linkmaster'); ?></p>
                            </div>
                            <div class="linkmaster-score-content" style="display: none;">
                                <div class="linkmaster-score-overview">
                                    <div class="linkmaster-score-gauge">
                                        <div class="linkmaster-score-circle">
                                            <span class="linkmaster-score-value">0</span>
                                            <span class="linkmaster-score-label"><?php esc_html_e('Health Score', 'linkmaster'); ?></span>
                                        </div>
                                    </div>
                                    <div class="linkmaster-score-stats">
                                        <div class="linkmaster-stat-item">
                                            <span class="linkmaster-stat-value total-links">0</span>
                                            <span class="linkmaster-stat-label"><?php esc_html_e('Total Links', 'linkmaster'); ?></span>
                                        </div>
                                        <div class="linkmaster-stat-item">
                                            <span class="linkmaster-stat-value healthy-links">0</span>
                                            <span class="linkmaster-stat-label"><?php esc_html_e('Healthy Links', 'linkmaster'); ?></span>
                                        </div>
                                        <div class="linkmaster-stat-item">
                                            <span class="linkmaster-stat-value broken-links">0</span>
                                            <span class="linkmaster-stat-label"><?php esc_html_e('Broken Links', 'linkmaster'); ?></span>
                                        </div>
                                        <div class="linkmaster-stat-item">
                                            <span class="linkmaster-stat-value redirected-links">0</span>
                                            <span class="linkmaster-stat-label"><?php esc_html_e('Redirected Links', 'linkmaster'); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="linkmaster-score-breakdown">
                                    <div class="linkmaster-breakdown-bar">
                                        <div class="linkmaster-bar-segment healthy" style="width: 0%;"></div>
                                        <div class="linkmaster-bar-segment broken" style="width: 0%;"></div>
                                    </div>
                                    <div class="linkmaster-breakdown-legend">
                                        <div class="linkmaster-legend-item">
                                            <span class="linkmaster-legend-color healthy"></span>
                                            <span class="linkmaster-legend-label"><?php esc_html_e('Working Links', 'linkmaster'); ?></span>
                                            <span class="linkmaster-legend-value healthy-percent">0%</span>
                                        </div>
                                        <div class="linkmaster-legend-item">
                                            <span class="linkmaster-legend-color broken"></span>
                                            <span class="linkmaster-legend-label"><?php esc_html_e('Broken Links', 'linkmaster'); ?></span>
                                            <span class="linkmaster-legend-value broken-percent">0%</span>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Grid Layout -->
            <div class="linkmaster-grid">
                <!-- Quick Actions Card -->
                <div class="linkmaster-card">
                    <div class="linkmaster-card-header">
                        <h2><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e('Quick Actions', 'linkmaster'); ?></h2>
                    </div>
                    <div class="linkmaster-card-content">
                        <div class="linkmaster-actions-grid">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-custom-permalinks')); ?>" class="linkmaster-action-item">
                                <span class="dashicons dashicons-admin-links"></span>
                                <div class="linkmaster-action-text">
                                    <span class="linkmaster-action-title"><?php esc_html_e('Manage Permalinks', 'linkmaster'); ?></span>
                                    <span class="linkmaster-action-desc"><?php esc_html_e('Create and edit custom URLs', 'linkmaster'); ?></span>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-cloaked-links')); ?>" class="linkmaster-action-item">
                                <span class="dashicons dashicons-shield"></span>
                                <div class="linkmaster-action-text">
                                    <span class="linkmaster-action-title"><?php esc_html_e('Cloaked Links', 'linkmaster'); ?></span>
                                    <span class="linkmaster-action-desc"><?php esc_html_e('Create protected & tracked links', 'linkmaster'); ?></span>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-auto-links')); ?>" class="linkmaster-action-item">
                                <span class="dashicons dashicons-admin-site"></span>
                                <div class="linkmaster-action-text">
                                    <span class="linkmaster-action-title"><?php esc_html_e('Auto Links', 'linkmaster'); ?></span>
                                    <span class="linkmaster-action-desc"><?php esc_html_e('Automate link insertion', 'linkmaster'); ?></span>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-broken-links&action=scan')); ?>" class="linkmaster-action-item">
                                <span class="dashicons dashicons-search"></span>
                                <div class="linkmaster-action-text">
                                    <span class="linkmaster-action-title"><?php esc_html_e('Scan Now', 'linkmaster'); ?></span>
                                    <span class="linkmaster-action-desc"><?php esc_html_e('Run broken links scan', 'linkmaster'); ?></span>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-redirections')); ?>" class="linkmaster-action-item">
                                <span class="dashicons dashicons-randomize"></span>
                                <div class="linkmaster-action-text">
                                    <span class="linkmaster-action-title"><?php esc_html_e('Setup Redirections', 'linkmaster'); ?></span>
                                    <span class="linkmaster-action-desc"><?php esc_html_e('Create URL redirects', 'linkmaster'); ?></span>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-click-stats')); ?>" class="linkmaster-action-item">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <div class="linkmaster-action-text">
                                    <span class="linkmaster-action-title"><?php esc_html_e('View Statistics', 'linkmaster'); ?></span>
                                    <span class="linkmaster-action-desc"><?php esc_html_e('Analyze link performance', 'linkmaster'); ?></span>
                                </div>
                            </a>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-broken-links')); ?>" class="linkmaster-action-item linkmaster-action-item-primary">
                                <span class="dashicons dashicons-admin-tools"></span>
                                <div class="linkmaster-action-text">
                                    <span class="linkmaster-action-title"><?php esc_html_e('Fix Broken Links', 'linkmaster'); ?></span>
                                    <span class="linkmaster-action-desc"><?php esc_html_e('Repair all broken links', 'linkmaster'); ?></span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Features Card -->
                <div class="linkmaster-card">
                    <div class="linkmaster-card-header">
                        <h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Features', 'linkmaster'); ?></h2>
                    </div>
                    <div class="linkmaster-card-content">
                        <div class="linkmaster-features">
                            <div class="linkmaster-feature">
                                <span class="dashicons dashicons-chart-line"></span>
                                <h3><?php esc_html_e('Link Health Score', 'linkmaster'); ?></h3>
                                <p><?php esc_html_e('Monitor your site\'s link health with an animated score that shows the percentage of working links.', 'linkmaster'); ?></p>
                            </div>
                            <div class="linkmaster-feature">
                                <span class="dashicons dashicons-admin-links"></span>
                                <h3><?php esc_html_e('Custom Permalinks', 'linkmaster'); ?></h3>
                                <p><?php esc_html_e('Create and manage custom URLs for your posts and pages, including support for query parameters.', 'linkmaster'); ?></p>
                            </div>
                            <div class="linkmaster-feature">
                                <span class="dashicons dashicons-shield"></span>
                                <h3><?php esc_html_e('Cloaked Links', 'linkmaster'); ?></h3>
                                <p><?php esc_html_e('Create cloaked links with password protection, IP restrictions, expiration dates, and SEO attributes.', 'linkmaster'); ?></p>
                            </div>
                            <div class="linkmaster-feature">
                                <span class="dashicons dashicons-admin-site"></span>
                                <h3><?php esc_html_e('Auto Link Injection', 'linkmaster'); ?></h3>
                                <p><?php esc_html_e('Automatically insert links in your content based on keywords with nofollow and open-in-new-window options.', 'linkmaster'); ?></p>
                            </div>
                            <div class="linkmaster-feature">
                                <span class="dashicons dashicons-warning"></span>
                                <h3><?php esc_html_e('Broken Link Detection', 'linkmaster'); ?></h3>
                                <p><?php esc_html_e('Automatically scan and identify broken links across your site with scheduled scans.', 'linkmaster'); ?></p>
                            </div>
                            <div class="linkmaster-feature">
                                <span class="dashicons dashicons-randomize"></span>
                                <h3><?php esc_html_e('Link Redirections', 'linkmaster'); ?></h3>
                                <p><?php esc_html_e('Set up and manage URL redirections with various response codes (301, 302, 307).', 'linkmaster'); ?></p>
                            </div>
                            <div class="linkmaster-feature">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <h3><?php esc_html_e('Click Statistics', 'linkmaster'); ?></h3>
                                <p><?php esc_html_e('Track and analyze link clicks to understand user behavior and link performance.', 'linkmaster'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help & Support Card -->
                <div class="linkmaster-card">
                    <div class="linkmaster-card-header">
                        <h2><span class="dashicons dashicons-editor-help"></span> <?php esc_html_e('Help & Support', 'linkmaster'); ?></h2>
                    </div>
                    <div class="linkmaster-card-content">
                        <div class="linkmaster-help">
                            <p><?php esc_html_e('Need assistance? Check out our support resources:', 'linkmaster'); ?></p>
                            <ul>
                                <li><a href="<?php echo esc_url($docs_url); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-book"></span> <?php esc_html_e('Documentation', 'linkmaster'); ?> <span class="screen-reader-text"><?php esc_html_e('(opens in new tab)', 'linkmaster'); ?></span></a></li>
                                <li><a href="<?php echo esc_url($support_url); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-format-chat"></span> <?php esc_html_e('Support Forum', 'linkmaster'); ?> <span class="screen-reader-text"><?php esc_html_e('(opens in new tab)', 'linkmaster'); ?></span></a></li>
                                <li><a href="<?php echo esc_url($add_review_url); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-star-filled"></span> <?php esc_html_e('Add a Review', 'linkmaster'); ?> <span class="screen-reader-text"><?php esc_html_e('(opens in new tab)', 'linkmaster'); ?></span></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function admin_enqueue_scripts($hook) {
        // Register admin styles
        wp_register_style(
            'linkmaster-admin',
            plugins_url('css/admin.css', __FILE__),
            array(),
            LINKMASTER_VERSION
        );

        // Register dashboard styles
        wp_register_style(
            'linkmaster-dashboard',
            plugins_url('css/dashboard.css', __FILE__),
            array('linkmaster-admin'),
            LINKMASTER_VERSION
        );

        // Register click stats styles
        wp_register_style(
            'linkmaster-click-stats',
            plugins_url('css/click-stats.css', __FILE__),
            array('linkmaster-admin'),
            LINKMASTER_VERSION
        );

        // Enqueue admin styles
        wp_enqueue_style('linkmaster-admin');
        
        // Enqueue dashboard styles for main page
        if ($hook === 'toplevel_page_linkmaster' || $hook === 'linkmaster_page_linkmaster-dashboard') {
            wp_enqueue_style('linkmaster-dashboard');
            wp_enqueue_script(
                'linkmaster-health-score',
                plugins_url('js/health-score.js', __FILE__),
                array('jquery'),
                LINKMASTER_VERSION,
                true
            );
            
            wp_localize_script('linkmaster-health-score', 'linkmaster_health', array(
                'nonce' => wp_create_nonce('linkmaster_health_score')
            ));
        }
        
        // Enqueue click stats styles
        if (strpos($hook, 'linkmaster-click-stats') !== false) {
            wp_enqueue_style('linkmaster-click-stats');
        }
    }

    public function handle_redirects() {
        static $redirected = false;
        if ($redirected) return;
        if ($this->redirector) {
            remove_filter('template_redirect', 'redirect_canonical');
            $this->redirector->process_redirects();
            $redirected = true;
        }
    }

    public function ajax_save_permalink() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lmcp_save_custom_permalink')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $custom_permalink = isset($_POST['custom_permalink']) ? sanitize_text_field(wp_unslash($_POST['custom_permalink'])) : '';
        if (!$post_id || !$custom_permalink) {
            wp_send_json_error('Missing required data');
        }
        $custom_permalink = trim($custom_permalink, '/');
        $updated = update_post_meta($post_id, '_lmcp_custom_permalink', $custom_permalink);
        if ($updated) {
            delete_option('rewrite_rules');
            $this->register_rewrite_rules();
            flush_rewrite_rules(true);
            clean_post_cache($post_id);
            update_option('linkmaster_permalinks_updated', true);
            wp_send_json_success(array(
                'message' => 'Permalink saved successfully',
                'permalink' => home_url('/' . $custom_permalink)
            ));
        } else {
            wp_send_json_error('Failed to update permalink');
        }
    }

    public function ajax_flush_rules() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lmcp_save_custom_permalink')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }
        delete_option('rewrite_rules');
        $this->register_rewrite_rules();
        flush_rewrite_rules(true);
        wp_send_json_success(array('message' => 'Rewrite rules flushed successfully'));
    }

    private function register_rewrite_rules() {
        $custom_permalinks = LinkMaster_Custom_Permalinks::get_instance();
        $custom_permalinks->register_rewrite_rules();
        
        $cloaked_links = LinkMaster_Cloaked_Links::get_instance();
        $cloaked_links->register_rewrite_rules();
    }

    public function activate() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create cloaked links table
        LinkMaster_Cloaked_Links::create_table(true);
        update_option('linkmaster_cloaked_links_db_version', '1.0');
        
        // Create or update other tables
        $custom_404 = LinkMaster_Custom_404::get_instance();
        $custom_404->create_table();
        
        $click_tracker = LinkMaster_Click_Tracker::get_instance();
        $click_tracker->create_table();
        
        // Initialize auto-links database
        require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-auto-links.php';
        $auto_links = LinkMaster_Auto_Links::get_instance();
        
        // Force database upgrade check
        delete_option('linkmaster_auto_links_db_version');
        
        // Initialize tables and run upgrades
        $auto_links->init();
        
        // Clear rewrite rules
        flush_rewrite_rules();
        
        // Initialize default options if not set
        if (!get_option('linkmaster_cloak_prefix')) {
            update_option('linkmaster_cloak_prefix', 'go');
        }
        
        // Initialize 404 settings if not set
        $default_404_settings = array(
            'page_id' => 0,
            'custom_message' => '',
            'enable_search' => true,
            'noindex' => true
        );
        if (!get_option('linkmaster_404_settings')) {
            add_option('linkmaster_404_settings', $default_404_settings);
        }
        
        // Set first-time activation flag for the tour
        update_option('linkmaster_first_activation', true);
        
        // Register rewrite rules
        $cloaked_links = LinkMaster_Cloaked_Links::get_instance();
        $cloaked_links->register_rewrite_rules();
        
        error_log('LinkMaster: Plugin activated, all tables created');
    }

    public function add_cron_schedules($schedules) {
        $schedules['monthly'] = array(
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Once Monthly', 'linkmaster')
        );
        return $schedules;
    }

    public function handle_manual_scan() {
        check_ajax_referer('linkmaster_health_score', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'linkmaster')));
        }
        
        // Load the scanner class if not already loaded
        if (!class_exists('LinkMaster_Scanner')) {
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-scanner.php';
        }
        
        $scanner = new LinkMaster_Scanner();
        $result = $scanner->run_scan();
        
        if ($result) {
            wp_send_json_success(array('message' => __('Scan completed successfully', 'linkmaster')));
        } else {
            wp_send_json_error(array('message' => __('Failed to complete scan', 'linkmaster')));
        }
    }
    
    /**
     * AJAX handler for getting health score data
     */
    public function handle_get_health_score() {
        check_ajax_referer('linkmaster_health_score', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'linkmaster')));
        }
        
        // Load the health score class if not already loaded
        if (!class_exists('LinkMaster_Health_Score')) {
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-health-score.php';
        }
        
        $health_score = new LinkMaster_Health_Score();
        $score_data = $health_score->get_health_score_data();
        
        wp_send_json_success($score_data);
    }
}

function linkmaster_init() {
    return LinkMaster_Plugin::get_instance();
}
add_action('plugins_loaded', 'linkmaster_init');