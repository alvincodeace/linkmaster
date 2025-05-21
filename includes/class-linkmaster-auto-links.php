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
        
        // Get all auto-link rules ordered by priority (DESC) and keyword length (DESC)
        $rules = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} 
            ORDER BY priority DESC, LENGTH(keyword) DESC"
        );
        
        if (empty($rules)) {
            return $content;
        }
        
        // Use DOMDocument for reliable HTML parsing
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Only process text within paragraphs and lists
        $textNodes = $xpath->query('//p//text() | //li//text()');
        
        foreach ($rules as $rule) {
            $count = 0;
            foreach ($textNodes as $node) {
                if ($count >= $rule->link_limit) {
                    break;
                }
                
                // Skip if node is already part of a link
                if ($this->is_node_in_link($node)) {
                    continue;
                }
                
                $text = $node->nodeValue;
                $pattern = $rule->case_sensitive ? 
                    '/\b(' . preg_quote($rule->keyword, '/') . ')\b/' :
                    '/\b(' . preg_quote($rule->keyword, '/') . ')\b/i';
                
                if (preg_match($pattern, $text)) {
                    $count++;
                    $this->replace_text_with_link($node, $rule);
                }
            }
        }
        
        $content = $dom->saveHTML();
        return $content;
    }
    
    private function is_node_in_link(DOMNode $node) {
        while ($node) {
            if ($node->nodeName === 'a') {
                return true;
            }
            $node = $node->parentNode;
        }
        return false;
    }
    
    private function replace_text_with_link(DOMText $node, $rule) {
        $text = $node->nodeValue;
        $pattern = $rule->case_sensitive ? 
            '/\b(' . preg_quote($rule->keyword, '/') . ')\b/' :
            '/\b(' . preg_quote($rule->keyword, '/') . ')\b/i';
        
        $link = $node->ownerDocument->createElement('a');
        $link->setAttribute('href', esc_url($rule->target_url));
        if ($rule->nofollow) {
            $link->setAttribute('rel', 'nofollow');
        }
        if ($rule->new_tab) {
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', ($rule->nofollow ? 'nofollow ' : '') . 'noopener noreferrer');
        }
        
        $parts = preg_split($pattern, $text, 2, PREG_SPLIT_DELIM_CAPTURE);
        
        if (count($parts) === 3) {
            if ($parts[0]) {
                $node->parentNode->insertBefore(
                    $node->ownerDocument->createTextNode($parts[0]),
                    $node
                );
            }
            
            $link->appendChild($node->ownerDocument->createTextNode($parts[1]));
            $node->parentNode->insertBefore($link, $node);
            
            if ($parts[2]) {
                $node->parentNode->insertBefore(
                    $node->ownerDocument->createTextNode($parts[2]),
                    $node
                );
            }
            
            $node->parentNode->removeChild($node);
        }
    }
    
    public function get_active_rules($post_type = null, $post_id = null) {
        global $wpdb;
        
        $rules = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY LENGTH(keyword) DESC");
        
        if (empty($rules)) {
            return array();
        }
        
        return array_filter($rules, function($rule) use ($post_type, $post_id) {
            // Check post type
            $allowed_types = maybe_unserialize($rule->post_types);
            if (!empty($allowed_types) && !in_array($post_type, $allowed_types)) {
                return false;
            }
            
            // Check excluded posts
            $excluded = maybe_unserialize($rule->excluded_posts);
            if (!empty($excluded) && in_array($post_id, $excluded)) {
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
