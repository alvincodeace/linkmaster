<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Cloaked_Links {
    private static $instance = null;
    private $table_name;
    private $per_page = 20;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'linkmaster_cloaked_links';
        
        // Start output buffering to prevent premature header output
        if (ob_get_level() == 0) {
            ob_start();
        }
        
        // Check and upgrade database if needed
        $this->maybe_upgrade_database();
        
        // Register hooks
        add_action('init', array($this, 'register_rewrite_rules'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('template_redirect', array($this, 'handle_redirect'));
        
        // Admin hooks
        if (is_admin()) {
            require_once LINKMASTER_PLUGIN_DIR . 'includes/class-linkmaster-cloaked-links-list-table.php';
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_init', array($this, 'handle_export'));
            add_action('admin_init', array($this, 'handle_import'));
            add_action('admin_init', array($this, 'download_sample_csv'));
            add_action('linkmaster_after_admin_menu', array($this, 'add_submenu_page'), 30);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
            add_action('wp_ajax_linkmaster_save_cloaked_link', array($this, 'ajax_save_cloaked_link'));
            add_action('wp_ajax_linkmaster_delete_cloaked_link', array($this, 'ajax_delete_cloaked_link'));
            add_action('wp_ajax_linkmaster_bulk_action_cloaked', array($this, 'ajax_bulk_action'));
        }
        
        // Frontend hooks
        add_action('wp_ajax_nopriv_linkmaster_verify_password', array($this, 'ajax_verify_password'));
        add_action('wp_ajax_linkmaster_verify_password', array($this, 'ajax_verify_password'));
    }
    
    private function maybe_upgrade_database() {
        global $wpdb;
        $db_version = get_option('linkmaster_cloaked_links_db_version', '0');
        
        if (version_compare($db_version, '1.0', '<')) {
            $this->create_table(true); // Force recreation
            update_option('linkmaster_cloaked_links_db_version', '1.0');
        }
        
        // Check for nofollow column
        $nofollow_exists = $wpdb->get_row("SHOW COLUMNS FROM {$this->table_name} LIKE 'nofollow'");
        if (!$nofollow_exists) {
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN nofollow tinyint(1) DEFAULT 0 AFTER status");
        }
        
        // Remove new_window column if it exists
        $new_window_exists = $wpdb->get_row("SHOW COLUMNS FROM {$this->table_name} LIKE 'new_window'");
        if ($new_window_exists) {
            $wpdb->query("ALTER TABLE {$this->table_name} DROP COLUMN new_window");
        }
        
        if (!$nofollow_exists || $new_window_exists) {
            update_option('linkmaster_cloaked_links_db_version', '1.1');
        }
    }
    
    public static function create_table($force = false) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'linkmaster_cloaked_links';
        
        if ($force) {
            $wpdb->query("DROP TABLE IF EXISTS $table_name");
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(191) NOT NULL,
            destination_url text NOT NULL,
            redirect_type smallint(3) NOT NULL DEFAULT 302,
            password varchar(255) DEFAULT NULL,
            ip_restrictions text DEFAULT NULL,
            click_limit int(11) DEFAULT NULL,
            expiry_date datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            nofollow tinyint(1) NOT NULL DEFAULT 0,
            total_clicks bigint(20) NOT NULL DEFAULT 0,
            unique_clicks bigint(20) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY expiry_date (expiry_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function register_settings() {
        register_setting(
            'linkmaster_cloaked_links',
            'linkmaster_cloak_prefix',
            array(
                'type' => 'string',
                'default' => 'go',
                'sanitize_callback' => array($this, 'sanitize_prefix')
            )
        );
    }
    
    public function sanitize_prefix($prefix) {
        $prefix = sanitize_title($prefix);
        if (empty($prefix)) {
            $prefix = 'go';
        }
        return $prefix;
    }
    
    public function register_rewrite_rules() {
        $prefix = $this->get_prefix();
        add_rewrite_rule(
            '^' . $prefix . '/([^/]+)/?$',
            'index.php?linkmaster_cloak_slug=$matches[1]',
            'top'
        );
        add_rewrite_tag('%linkmaster_cloak_slug%', '([^/]+)');
    }
    
    public function register_query_vars($vars) {
        $vars[] = 'linkmaster_cloak_slug';
        return $vars;
    }
    
    public function handle_redirect() {
        $slug = get_query_var('linkmaster_cloak_slug');
        if (!empty($slug)) {
            // Remove canonical redirect filter
            remove_filter('template_redirect', 'redirect_canonical');
            
            global $wpdb;
            
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE slug = %s AND status = 'active'",
                $slug
            ));
            
            if ($link) {
                // Check expiry
                if (!empty($link->expiry_date) && strtotime($link->expiry_date) < time()) {
                    wp_die(__('This link has expired.', 'linkmaster'), '', array('response' => 410));
                }
                
                // Check click limit - do this check atomically with the database to prevent race conditions
                if (isset($link->click_limit) && $link->click_limit > 0) {
                    $current_clicks = $wpdb->get_var($wpdb->prepare(
                        "SELECT total_clicks FROM {$this->table_name} WHERE id = %d",
                        $link->id
                    ));
                    
                    if ($current_clicks >= $link->click_limit) {
                        wp_die(__('This link has reached its click limit.', 'linkmaster'), '', array('response' => 410));
                    }
                }
                
                // Check IP restrictions
                if (!empty($link->ip_restrictions)) {
                    $allowed_ips = json_decode($link->ip_restrictions, true);
                    $user_ip = $_SERVER['REMOTE_ADDR'];
                    $ip_allowed = false;
                    
                    foreach ($allowed_ips as $allowed_ip) {
                        if ($this->ip_in_range($user_ip, $allowed_ip)) {
                            $ip_allowed = true;
                            break;
                        }
                    }
                    
                    if (!$ip_allowed) {
                        wp_die(__('Access denied: Your IP is not allowed.', 'linkmaster'), '', array('response' => 403));
                    }
                }
                
                // Check password protection
                if (!empty($link->password)) {
                    if (!isset($_POST['link_password']) || !wp_check_password($_POST['link_password'], $link->password)) {
                        $this->show_password_form($link);
                        exit;
                    }
                }
                
                // Track the click before redirecting
                $this->track_click($link->id, $link);
                
                // Set appropriate headers for nofollow
                if ($link->nofollow) {
                    header('X-Robots-Tag: nofollow');
                }
                
                // Standard redirect for regular links
                wp_redirect($link->destination_url, $link->redirect_type ?: 302);
                exit;
            } else {
                wp_die(__('Link not found.', 'linkmaster'), '', array('response' => 404));
            }
        }
    }
    
    private function track_click($link_id, $link = null) {
        global $wpdb;
        
        // Track click details using click tracker
        if (class_exists('LinkMaster_Click_Tracker')) {
            $click_tracker = LinkMaster_Click_Tracker::get_instance();
            
            // If link object wasn't passed, fetch it
            if (!$link) {
                $link = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE id = %d",
                    $link_id
                ));
            }
            
            if ($link) {
                $click_data = array(
                    'link_id' => (string)$link_id,
                    'link_type' => 'cloak',
                    'url' => $link->destination_url,
                    'ip' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                    'referrer' => isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : ''
                );
                
                // Only update total_clicks and unique_clicks if click was successfully tracked
                if ($click_tracker->track_click($click_data)) {
                    // Update total clicks
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$this->table_name} SET total_clicks = total_clicks + 1 WHERE id = %d",
                        $link_id
                    ));
                    
                    // Track unique clicks using cookies
                    $cookie_name = 'linkmaster_click_' . $link_id;
                    if (!isset($_COOKIE[$cookie_name])) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$this->table_name} SET unique_clicks = unique_clicks + 1 WHERE id = %d",
                            $link_id
                        ));
                        setcookie($cookie_name, '1', time() + (86400 * 365), '/'); // 1 year expiry
                    }
                }
            }
        }
    }
    
    private function show_password_form($link) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title><?php _e('Protected Link', 'linkmaster'); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; line-height: 1.4; max-width: 500px; margin: 2rem auto; padding: 0 1rem; }
                form { background: #fff; padding: 2rem; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                input[type="password"] { width: 100%; padding: 0.5rem; margin: 0.5rem 0; }
                input[type="submit"] { background: #0073aa; color: #fff; border: none; padding: 0.5rem 1rem; cursor: pointer; }
                input[type="submit"]:hover { background: #006291; }
            </style>
        </head>
        <body>
            <form method="post">
                <h2><?php _e('This link is password protected', 'linkmaster'); ?></h2>
                <p><?php _e('Please enter the password to access this link:', 'linkmaster'); ?></p>
                <input type="password" name="link_password" required>
                <input type="submit" value="<?php esc_attr_e('Submit', 'linkmaster'); ?>">
            </form>
        </body>
        </html>
        <?php
    }
    
    private function ip_in_range($ip, $range) {
        if (strpos($range, '/') !== false) {
            // CIDR notation
            list($subnet, $bits) = explode('/', $range);
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            $subnet &= $mask;
            return ($ip & $mask) == $subnet;
        } else if (strpos($range, '-') !== false) {
            // IP range
            list($start, $end) = explode('-', $range);
            $ip = ip2long($ip);
            return $ip >= ip2long($start) && $ip <= ip2long($end);
        } else {
            // Single IP
            return $ip === $range;
        }
    }
    
    public function get_prefix() {
        return get_option('linkmaster_cloak_prefix', 'go');
    }
    
    public function ajax_verify_password() {
        check_ajax_referer('linkmaster_verify_password', 'nonce');
        
        $slug = sanitize_text_field($_POST['slug']);
        $password = sanitize_text_field($_POST['password']);
        
        $link = $this->get_link_by_slug($slug);
        if (!$link || !wp_check_password($password, $link->password)) {
            wp_send_json_error(__('Invalid password', 'linkmaster'));
        }
        
        setcookie(
            'lm_cloaked_' . $slug,
            base64_encode($password),
            time() + DAY_IN_SECONDS,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl()
        );
        
        wp_send_json_success(array(
            'redirect_url' => home_url($this->get_prefix() . '/' . $slug)
        ));
    }
    
    private function get_link_by_slug($slug) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE slug = %s",
            $slug
        ));
    }
    
    public function get_link($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }
    
    public function ajax_save_cloaked_link() {
        check_ajax_referer('linkmaster_cloaked_links', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'linkmaster'));
        }
        
        $data = array(
            'slug'            => sanitize_text_field($_POST['slug']),
            'destination_url' => esc_url_raw($_POST['destination_url']),
            'redirect_type'   => intval($_POST['redirect_type']),
            'nofollow'        => isset($_POST['nofollow']) ? 1 : 0,
            'status'          => sanitize_text_field($_POST['status'])
        );
        
        // Handle optional fields
        if (!empty($_POST['password'])) {
            $data['password'] = wp_hash_password($_POST['password']);
        } else if (isset($_POST['clear_password']) && $_POST['clear_password'] === '1') {
            $data['password'] = '';
        }
        
        if (isset($_POST['ip_restrictions']) && is_array($_POST['ip_restrictions'])) {
            // Filter out empty values and sanitize
            $ip_ranges = array_filter(array_map('sanitize_text_field', $_POST['ip_restrictions']));
            
            // Only save IP restrictions if there are actual values
            if (!empty($ip_ranges)) {
                $data['ip_restrictions'] = json_encode(array_values($ip_ranges));
            } else {
                $data['ip_restrictions'] = null; // Set to null to clear IP restrictions
            }
        } else if (isset($_POST['clear_ip_restrictions']) && $_POST['clear_ip_restrictions'] === '1') {
            $data['ip_restrictions'] = null; // Set to null instead of empty array
        }
        
        if (isset($_POST['click_limit'])) {
            $click_limit = intval($_POST['click_limit']);
            $data['click_limit'] = $click_limit > 0 ? $click_limit : null;
        } else if (isset($_POST['clear_click_limit']) && $_POST['clear_click_limit'] === '1') {
            $data['click_limit'] = null;
        }
        
        if (!empty($_POST['expiry_date'])) {
            $data['expiry_date'] = sanitize_text_field($_POST['expiry_date']);
        } else if (isset($_POST['clear_expiry']) && $_POST['clear_expiry'] === '1') {
            $data['expiry_date'] = null;
        }
        
        $format = array('%s', '%s', '%d', '%d', '%s'); // slug, destination_url, redirect_type, nofollow, status
        
        if (isset($data['password'])) $format[] = '%s';
        if (isset($data['ip_restrictions'])) $format[] = '%s';
        if (isset($data['click_limit'])) $format[] = '%d';
        if (isset($data['expiry_date'])) $format[] = '%s';
        
        global $wpdb;
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if ($id) {
            // Update
            $where = array('id' => $id);
            $where_format = array('%d');
            $result = $wpdb->update($this->table_name, $data, $where, $format, $where_format);
        } else {
            // Insert
            $result = $wpdb->insert($this->table_name, $data, $format);
            $id = $wpdb->insert_id;
        }
        
        if ($result === false) {
            wp_send_json_error(__('A cloaked link with this slug already exists', 'linkmaster'));
        }
        
        wp_send_json_success(array(
            'message' => __('Link saved successfully.', 'linkmaster'),
            'redirect_url' => add_query_arg(array(
                'page' => 'linkmaster-cloaked-links',
                'updated' => 1
            ), admin_url('admin.php'))
        ));
    }
    
    public function ajax_delete_cloaked_link() {
        check_ajax_referer('linkmaster_cloaked_links', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'linkmaster'));
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error(__('Invalid link ID.', 'linkmaster'));
        }
        
        global $wpdb;
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
        
        if ($result === false) {
            wp_send_json_error(__('Failed to delete link.', 'linkmaster'));
        }
        
        // Delete associated click data
        if (class_exists('LinkMaster_Click_Tracker')) {
            $click_tracker = LinkMaster_Click_Tracker::get_instance();
            $wpdb->delete(
                $click_tracker->get_table_name(),
                array('link_id' => (string)$id),
                array('%s')
            );
        }
        
        wp_send_json_success(__('Link deleted successfully.', 'linkmaster'));
    }
    
    public function add_submenu_page() {
        add_submenu_page(
            'linkmaster',
            __('Cloaked Links', 'linkmaster'),
            __('Cloaked Links', 'linkmaster'),
            'manage_options',
            'linkmaster-cloaked-links',
            array($this, 'render_admin_page')
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('linkmaster_page_linkmaster-cloaked-links' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'linkmaster-cloaked-links',
            LINKMASTER_PLUGIN_URL . 'css/cloaked-links.css',
            array(),
            LINKMASTER_VERSION
        );

        wp_enqueue_script(
            'linkmaster-cloaked-links',
            LINKMASTER_PLUGIN_URL . 'js/cloaked-links.js',
            array('jquery'),
            LINKMASTER_VERSION,
            true
        );

        wp_localize_script('linkmaster-cloaked-links', 'linkmaster_cloaked', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('linkmaster_cloaked_links'),
            'prefix' => $this->get_prefix()
        ));
    }

    public function render_admin_page() {
        // Admin UI template will be implemented here
        include(LINKMASTER_PLUGIN_DIR . 'templates/admin-cloaked-links.php');
    }
    
    public function handle_export() {
        if (!current_user_can('manage_options') || 
            !isset($_POST['linkmaster_export_cloaked_links']) || 
            !check_admin_referer('linkmaster_export_cloaked_links')) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'linkmaster_cloaked_links';
        
        // Get all cloaked links with specific columns in the correct order
        $links = $wpdb->get_results(
            "SELECT slug, destination_url, redirect_type, password, ip_restrictions, 
                    click_limit, expiry_date, status, total_clicks, unique_clicks 
             FROM $table_name",
            ARRAY_A
        );
        
        // CSV Headers exactly matching the database columns
        $headers = array(
            'slug', 'destination_url', 'redirect_type', 'password', 'ip_restrictions',
            'click_limit', 'expiry_date', 'status', 'total_clicks', 'unique_clicks'
        );
        
        // Output CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="cloaked-links-export-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Write headers
        fputcsv($output, $headers);
        
        // Write data rows
        foreach ($links as $link) {
            // Ensure all fields are present in the correct order
            $row = array(
                $link['slug'] ?? '',
                $link['destination_url'] ?? '',
                $link['redirect_type'] ?? '302',
                $link['password'] ?? '',
                $link['ip_restrictions'] ?? '',
                $link['click_limit'] ?? '',
                $link['expiry_date'] ?? '',
                $link['status'] ?? 'active',
                $link['total_clicks'] ?? '0',
                $link['unique_clicks'] ?? '0'
            );
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }
    
    public function handle_import() {
        if (!current_user_can('manage_options') || 
            !isset($_POST['linkmaster_import_cloaked_links']) || 
            !check_admin_referer('linkmaster_import_cloaked_links')) {
            return;
        }
        
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(add_query_arg('error', 'import_file', wp_get_referer()));
            exit;
        }
        
        $file = $_FILES['import_file']['tmp_name'];
        $handle = fopen($file, 'r');
        if ($handle === false) {
            wp_redirect(add_query_arg('error', 'file_read', wp_get_referer()));
            exit;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'linkmaster_cloaked_links';
        
        // Read headers and normalize them
        $headers = array_map('trim', fgetcsv($handle));
        $required_headers = array('slug', 'destination_url');
        
        // Validate headers
        foreach ($required_headers as $required) {
            if (!in_array($required, $headers)) {
                fclose($handle);
                wp_redirect(add_query_arg('error', 'invalid_format', wp_get_referer()));
                exit;
            }
        }
        
        $imported = 0;
        $skipped = 0;
        
        while (($data = fgetcsv($handle)) !== false) {
            // Make sure we have enough columns
            while (count($data) < count($headers)) {
                $data[] = '';
            }
            
            $row = array_combine($headers, $data);
            
            // Skip if required fields are empty
            if (empty($row['slug']) || empty($row['destination_url'])) {
                $skipped++;
                continue;
            }
            
            // Prepare data with proper defaults
            $link_data = array(
                'slug' => sanitize_title($row['slug']),
                'destination_url' => esc_url_raw($row['destination_url']),
                'redirect_type' => isset($row['redirect_type']) ? (int)$row['redirect_type'] : 302,
                'password' => isset($row['password']) ? sanitize_text_field($row['password']) : '',
                'ip_restrictions' => isset($row['ip_restrictions']) ? sanitize_textarea_field($row['ip_restrictions']) : '',
                'click_limit' => isset($row['click_limit']) ? (int)$row['click_limit'] : null,
                'expiry_date' => isset($row['expiry_date']) && !empty($row['expiry_date']) ? $row['expiry_date'] : null,
                'status' => isset($row['status']) && in_array($row['status'], array('active', 'inactive')) ? $row['status'] : 'active'
            );
            
            // Check if slug exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE slug = %s",
                $link_data['slug']
            ));
            
            if ($exists) {
                $skipped++;
                continue;
            }
            
            // Insert link
            $result = $wpdb->insert($table_name, $link_data);
            if ($result) {
                $imported++;
            } else {
                $skipped++;
            }
        }
        
        fclose($handle);
        
        wp_redirect(add_query_arg(array(
            'imported' => $imported,
            'skipped' => $skipped,
            'page' => 'linkmaster-cloaked-links'
        ), admin_url('admin.php')));
        exit;
    }
    
    public function download_sample_csv() {
        if (!current_user_can('manage_options') || 
            !isset($_POST['linkmaster_download_sample']) || 
            !check_admin_referer('linkmaster_download_sample')) {
            return;
        }
        
        $sample_data = array(
            // Headers
            array(
                'slug', 'destination_url', 'redirect_type', 'password', 'ip_restrictions',
                'click_limit', 'expiry_date', 'status', 'total_clicks', 'unique_clicks'
            ),
            // Example 1: Simple redirect
            array(
                'example-link',
                'https://example.com',
                '302',
                '',
                '',
                '',
                '',
                'active',
                '0',
                '0'
            ),
            // Example 2: Protected link with restrictions
            array(
                'protected-link',
                'https://example.org',
                '301',
                'secretpass',
                '192.168.1.0/24',
                '100',
                '2024-12-31 23:59:59',
                'active',
                '0',
                '0'
            )
        );
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="cloaked-links-sample.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        foreach ($sample_data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}
