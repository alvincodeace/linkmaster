<?php
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Click_Tracker {
    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'linkmaster_clicks';
        $this->create_table();
    }

    private function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            link_id varchar(255) NOT NULL,
            link_type varchar(20) NOT NULL DEFAULT 'redirect',
            user_ip varchar(100) NOT NULL,
            user_agent text,
            referer text,
            clicked_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY link_id (link_id),
            KEY link_type (link_type),
            KEY clicked_at (clicked_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Check if link_type column exists, if not add it
        $row = $wpdb->get_results("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = '" . DB_NAME . "' 
            AND TABLE_NAME = '{$this->table_name}'
            AND COLUMN_NAME = 'link_type'");
            
        if (empty($row)) {
            $wpdb->query("ALTER TABLE {$this->table_name} 
                ADD COLUMN link_type varchar(20) NOT NULL DEFAULT 'redirect' AFTER link_id,
                ADD KEY link_type (link_type)");
        }
    }

    public function track_click($data) {
        if (empty($data) || !is_array($data) || empty($data['link_id'])) {
            return false;
        }

        global $wpdb;
        
        // Check for recent clicks from same IP to prevent duplicates
        $recent_click = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
            WHERE link_id = %s 
            AND user_ip = %s 
            AND clicked_at > DATE_SUB(NOW(), INTERVAL 2 SECOND)",
            $data['link_id'],
            $data['ip']
        ));

        if ($recent_click) {
            error_log(sprintf('LinkMaster: Duplicate click prevented for link ID %s from IP %s', $data['link_id'], $data['ip']));
            return false;
        }

        // Insert click data with link type
        $insert_data = array(
            'link_id' => $data['link_id'],
            'link_type' => isset($data['link_type']) ? $data['link_type'] : 'redirect',
            'user_ip' => $data['ip'],
            'user_agent' => isset($data['user_agent']) ? $data['user_agent'] : '',
            'referer' => isset($data['referrer']) ? $data['referrer'] : ''
        );

        $insert_format = array(
            '%s', // link_id
            '%s', // link_type
            '%s', // user_ip
            '%s', // user_agent
            '%s'  // referer
        );

        $result = $wpdb->insert($this->table_name, $insert_data, $insert_format);
        
        return $result !== false;
    }
    
    /**
     * Update the hit counter for a redirect
     *
     * @param string $link_id The redirect ID
     * @return void
     */
    private function update_hit_counter($link_id) {
        // Get the redirects option
        $redirects = get_option('linkmaster_redirects', array());
        
        if (isset($redirects[$link_id])) {
            // Increment the hit counter instead of replacing it with the total count
            if (!isset($redirects[$link_id]['hits'])) {
                $redirects[$link_id]['hits'] = 1;
            } else {
                $redirects[$link_id]['hits'] = (int)$redirects[$link_id]['hits'] + 1;
            }
            
            // Store the last click time
            $redirects[$link_id]['last_click'] = current_time('mysql');
            
            // Update the option
            update_option('linkmaster_redirects', $redirects);
        }
    }

    private function get_client_ip() {
        $ip = '';
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }

    public function get_clicks_count($link_id, $link_type = null) {
        global $wpdb;
        
        $query = "SELECT COUNT(DISTINCT id) FROM {$this->table_name} WHERE link_id = %s";
        $params = array($link_id);
        
        if ($link_type !== null) {
            $query .= " AND link_type = %s";
            $params[] = $link_type;
        }
        
        return (int)$wpdb->get_var($wpdb->prepare($query, $params));
    }

    public function get_today_clicks($link_type = null) {
        global $wpdb;
        
        $query = "SELECT COUNT(DISTINCT id) FROM {$this->table_name} 
                 WHERE DATE(clicked_at) = CURDATE()";
        $params = array();
        
        if ($link_type !== null) {
            $query .= " AND link_type = %s";
            $params[] = $link_type;
        }
        
        return (int)$wpdb->get_var($wpdb->prepare($query, $params));
    }

    public function get_clicks_by_date_range($start_date, $end_date, $link_type = null) {
        global $wpdb;
        
        $query = "SELECT DATE(clicked_at) as date, COUNT(*) as clicks 
                 FROM {$this->table_name}
                 WHERE clicked_at BETWEEN %s AND %s";
        
        $params = array($start_date, $end_date);
        
        if ($link_type !== null) {
            $query .= " AND link_type = %s";
            $params[] = $link_type;
        }
        
        $query .= " GROUP BY DATE(clicked_at) ORDER BY date ASC";
        
        $results = $wpdb->get_results($wpdb->prepare($query, $params));
        
        // Fill in missing dates with zero clicks
        $filled_results = array();
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $found = false;
            
            foreach ($results as $row) {
                if ($row->date === $date) {
                    $filled_results[] = array(
                        'date' => $date,
                        'clicks' => (int)$row->clicks
                    );
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $filled_results[] = array(
                    'date' => $date,
                    'clicks' => 0
                );
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        return $filled_results;
    }

    public function get_table_name() {
        return $this->table_name;
    }
}
