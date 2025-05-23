<?php

if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Auto_Links {
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'linkmaster_auto_links';
        
        // Initialize
        add_action('init', array($this, 'init'));
        
        // Content filtering
        add_filter('the_content', array($this, 'process_content'), 20);
        add_filter('the_excerpt', array($this, 'process_content'), 20);
        
        // Ajax handlers
        add_action('wp_ajax_linkmaster_save_auto_link', array($this, 'ajax_save_auto_link'));
        add_action('wp_ajax_linkmaster_delete_auto_link', array($this, 'ajax_delete_auto_link'));
        add_action('wp_ajax_linkmaster_import_auto_links', array($this, 'ajax_import_auto_links'));
        
        // Force database update
        $this->force_update_database();
    }
    
    private function force_update_database() {
        global $wpdb;
        
        // First, check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        
        if (!$table_exists) {
            // Create the table if it doesn't exist
            $this->init();
        }
        
        // Check for priority column
        $priority_exists = $wpdb->get_row("SHOW COLUMNS FROM {$this->table_name} LIKE 'priority'");
        
        if (!$priority_exists) {
            // Add priority column if it doesn't exist
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN priority int DEFAULT 0 AFTER new_tab");
        }
        
        // Check for new_tab column
        $new_tab_exists = $wpdb->get_row("SHOW COLUMNS FROM {$this->table_name} LIKE 'new_tab'");
        
        if (!$new_tab_exists) {
            // Add new_tab column if it doesn't exist
            $wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN new_tab tinyint(1) DEFAULT 0 AFTER nofollow");
        }
    }
    
    public function init() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            keyword varchar(255) NOT NULL,
            target_url varchar(255) NOT NULL,
            case_sensitive tinyint(1) DEFAULT 0,
            link_limit int DEFAULT 3,
            post_types text,
            nofollow tinyint(1) DEFAULT 0,
            new_tab tinyint(1) DEFAULT 0,
            priority int DEFAULT 0,
            excluded_posts text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY keyword (keyword(191))
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function process_content($content) {
        if (empty($content) || is_admin()) {
            return $content;
        }

        global $wpdb, $post;
        
        // Get current post info for filtering
        $current_post_type = get_post_type();
        $current_post_id = get_the_ID();
        
        // Get filtered rules based on current post
        $rules = $this->get_active_rules($current_post_type, $current_post_id);
        
        if (empty($rules)) {
            return $content;
        }
        
        // Sort rules by priority (DESC) first, then by keyword length (DESC)
        usort($rules, function($a, $b) {
            if ($a->priority == $b->priority) {
                return strlen($b->keyword) - strlen($a->keyword);
            }
            return $b->priority - $a->priority;
        });
        
        // Track replacements to avoid conflicts
        $processed_positions = array();
        
        foreach ($rules as $rule) {
            $replacement_count = 0;
            
            // Create pattern for keyword matching
            $pattern = $rule->case_sensitive ? 
                '/\b(' . preg_quote($rule->keyword, '/') . ')\b/' :
                '/\b(' . preg_quote($rule->keyword, '/') . ')\b/i';
            
            // Find all matches with positions
            if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                // Process matches in reverse order to maintain positions
                $matches_with_positions = array_reverse($matches[0]);
                
                foreach ($matches_with_positions as $match) {
                    if ($replacement_count >= $rule->link_limit) {
                        break;
                    }
                    
                    $match_text = $match[0];
                    $match_position = $match[1];
                    $match_end = $match_position + strlen($match_text);
                    
                    // Check if this position conflicts with previous replacements
                    $conflict = false;
                    foreach ($processed_positions as $pos) {
                        if (($match_position >= $pos['start'] && $match_position < $pos['end']) ||
                            ($match_end > $pos['start'] && $match_end <= $pos['end']) ||
                            ($match_position <= $pos['start'] && $match_end >= $pos['end'])) {
                            $conflict = true;
                            break;
                        }
                    }
                    
                    if ($conflict) {
                        continue;
                    }
                    
                    // Check if we're inside an existing link or other HTML tag
                    if ($this->is_inside_link($content, $match_position)) {
                        continue;
                    }
                    
                    // Create the link
                    $link_attributes = array();
                    $link_attributes[] = 'href="' . esc_url($rule->target_url) . '"';
                    
                    if ($rule->nofollow) {
                        $rel_parts = array('nofollow');
                    } else {
                        $rel_parts = array();
                    }
                    
                    if ($rule->new_tab) {
                        $link_attributes[] = 'target="_blank"';
                        $rel_parts[] = 'noopener';
                        $rel_parts[] = 'noreferrer';
                    }
                    
                    if (!empty($rel_parts)) {
                        $link_attributes[] = 'rel="' . implode(' ', $rel_parts) . '"';
                    }
                    
                    $link_html = '<a ' . implode(' ', $link_attributes) . '>' . $match_text . '</a>';
                    
                    // Replace the text with the link
                    $content = substr_replace($content, $link_html, $match_position, strlen($match_text));
                    
                    // Track this replacement
                    $processed_positions[] = array(
                        'start' => $match_position,
                        'end' => $match_position + strlen($link_html)
                    );
                    
                    $replacement_count++;
                }
            }
        }
        
        return $content;
    }
    
    /**
     * Check if a position in content is inside an existing link or HTML tag
     */
    private function is_inside_link($content, $position) {
        // Get content before position
        $before = substr($content, 0, $position);
        
        // Check for unclosed <a> tags
        $open_tags = preg_match_all('/<a\b[^>]*>/i', $before);
        $close_tags = preg_match_all('/<\/a>/i', $before);
        
        if ($open_tags > $close_tags) {
            return true;
        }
        
        // Check if inside any HTML tag
        $last_open = strrpos($before, '<');
        $last_close = strrpos($before, '>');
        
        if ($last_open !== false && ($last_close === false || $last_open > $last_close)) {
            return true;
        }
        
        return false;
    }
    
    public function get_active_rules($post_type = null, $post_id = null) {
        global $wpdb;
        
        // Get all rules without ordering here - we'll sort in process_content
        $rules = $wpdb->get_results("SELECT * FROM {$this->table_name}");
        
        if (empty($rules)) {
            return array();
        }
        
        return array_filter($rules, function($rule) use ($post_type, $post_id) {
            // Check post type
            $allowed_types = maybe_unserialize($rule->post_types);
            if (!empty($allowed_types) && is_array($allowed_types) && !in_array($post_type, $allowed_types)) {
                return false;
            }
            
            // Check excluded posts
            $excluded = maybe_unserialize($rule->excluded_posts);
            if (!empty($excluded) && is_array($excluded) && in_array($post_id, $excluded)) {
                return false;
            }
            
            return true;
        });
    }
    
    public function ajax_save_auto_link() {
        check_ajax_referer('linkmaster_admin');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.'));
            return;
        }
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        $data = array(
            'keyword' => sanitize_text_field($_POST['keyword']),
            'target_url' => esc_url_raw($_POST['target_url']),
            'case_sensitive' => isset($_POST['case_sensitive']) ? 1 : 0,
            'link_limit' => intval($_POST['link_limit']),
            'post_types' => maybe_serialize(isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array()),
            'nofollow' => isset($_POST['nofollow']) ? 1 : 0,
            'new_tab' => isset($_POST['new_tab']) ? 1 : 0,
            'priority' => isset($_POST['priority']) ? intval($_POST['priority']) : 0,
            'excluded_posts' => maybe_serialize(isset($_POST['excluded_posts']) ? array_map('intval', $_POST['excluded_posts']) : array())
        );
        
        global $wpdb;
        
        if ($id) {
            $result = $wpdb->update($this->table_name, $data, array('id' => $id));
            if ($result === false) {
                wp_send_json_error(__('Failed to update auto-link rule.', 'linkmaster'));
                return;
            }
            wp_send_json_success(array(
                'message' => __('Auto-link rule updated successfully.', 'linkmaster'),
                'redirect' => add_query_arg('updated', 'true', admin_url('admin.php?page=linkmaster-auto-links'))
            ));
        } else {
            $result = $wpdb->insert($this->table_name, $data);
            if ($result === false) {
                wp_send_json_error(__('Failed to add auto-link rule.', 'linkmaster'));
                return;
            }
            wp_send_json_success(array(
                'message' => __('Auto-link rule added successfully.', 'linkmaster'),
                'redirect' => add_query_arg('updated', 'true', admin_url('admin.php?page=linkmaster-auto-links'))
            ));
        }
    }
    
    public function ajax_delete_auto_link() {
        check_ajax_referer('linkmaster_admin', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $id = intval($_POST['id']);
        
        global $wpdb;
        $wpdb->delete($this->table_name, array('id' => $id));
        
        wp_send_json_success();
    }
    
    public function ajax_import_auto_links() {
        check_ajax_referer('linkmaster_admin', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error('No file uploaded');
        }
        
        $file = $_FILES['csv_file'];
        $csv = array_map('str_getcsv', file($file['tmp_name']));
        array_shift($csv); // Remove header row
        
        global $wpdb;
        
        foreach ($csv as $row) {
            if (count($row) < 2) continue;
            
            $data = array(
                'keyword' => sanitize_text_field($row[0]),
                'target_url' => esc_url_raw($row[1]),
                'case_sensitive' => isset($row[2]) ? intval($row[2]) : 0,
                'link_limit' => isset($row[3]) ? intval($row[3]) : 3,
                'post_types' => isset($row[4]) ? maybe_serialize(explode(',', sanitize_text_field($row[4]))) : '',
                'nofollow' => isset($row[5]) ? intval($row[5]) : 0,
                'new_tab' => isset($row[6]) ? intval($row[6]) : 0,
                'priority' => isset($row[7]) ? intval($row[7]) : 0,
                'excluded_posts' => isset($row[8]) ? maybe_serialize(explode(',', sanitize_text_field($row[8]))) : ''
            );
            
            $wpdb->insert($this->table_name, $data);
        }
        
        wp_send_json_success();
    }
}