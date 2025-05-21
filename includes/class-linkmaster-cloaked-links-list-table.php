<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class LinkMaster_Cloaked_Links_List_Table extends WP_List_Table {
    private $table_name;
    private $items_per_page = 20;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'linkmaster_cloaked_links';
        
        parent::__construct(array(
            'singular' => 'cloaked_link',
            'plural'   => 'cloaked_links',
            'ajax'     => false
        ));
    }
    
    public function get_columns() {
        return array(
            'cb'              => '<input type="checkbox" />',
            'slug'            => __('Slug', 'linkmaster'),
            'destination_url' => __('Destination URL', 'linkmaster'),
            'status'          => __('Status', 'linkmaster'),
            'clicks'          => __('Clicks', 'linkmaster'),
            'protection'      => __('Protection', 'linkmaster'),
            'expiry_date'     => __('Expires', 'linkmaster'),
            'created_at'      => __('Created', 'linkmaster')
        );
    }
    
    public function get_sortable_columns() {
        return array(
            'slug'        => array('slug', true),
            'status'      => array('status', false),
            'clicks'      => array('total_clicks', false),
            'expiry_date' => array('expiry_date', false),
            'created_at'  => array('created_at', true)
        );
    }
    
    protected function column_default($item, $column_name) {
        return esc_html($item->$column_name);
    }
    
    protected function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="link[]" value="%s" />',
            $item->id
        );
    }
    
    protected function column_slug($item) {
        $actions = array(
            'edit'   => sprintf(
                '<a href="%s">%s</a>',
                add_query_arg(array('tab' => 'add', 'id' => $item->id)),
                __('Edit', 'linkmaster')
            ),
            'delete' => sprintf(
                '<a href="#" class="linkmaster-delete-link" data-id="%d">%s</a>',
                $item->id,
                __('Delete', 'linkmaster')
            )
        );
        
        $prefix = LinkMaster_Cloaked_Links::get_instance()->get_prefix();
        $full_url = home_url($prefix . '/' . $item->slug);
        
        return sprintf(
            '<strong><a href="%s" target="_blank">%s</a></strong> %s<br><small>%s</small>',
            esc_url($full_url),
            esc_html($item->slug),
            $this->row_actions($actions),
            sprintf(
                '<a href="#" class="linkmaster-copy-url" data-url="%s">%s</a>',
                esc_url($full_url),
                __('Copy URL', 'linkmaster')
            )
        );
    }
    
    protected function column_destination_url($item) {
        return sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url($item->destination_url),
            esc_html(strlen($item->destination_url) > 50 ? substr($item->destination_url, 0, 47) . '...' : $item->destination_url)
        );
    }
    
    protected function column_status($item) {
        $status_classes = array(
            'active'   => 'linkmaster-status-active',
            'disabled' => 'linkmaster-status-disabled',
            'expired'  => 'linkmaster-status-expired'
        );
        
        $status = $item->status;
        if ($status === 'active' && !empty($item->expiry_date) && strtotime($item->expiry_date) < time()) {
            $status = 'expired';
        }
        
        return sprintf(
            '<span class="linkmaster-status %s">%s</span>',
            esc_attr($status_classes[$status]),
            esc_html(ucfirst($status))
        );
    }
    
    private function get_click_count($link_id) {
        if (class_exists('LinkMaster_Click_Tracker')) {
            $click_tracker = LinkMaster_Click_Tracker::get_instance();
            return $click_tracker->get_clicks_count((string)$link_id);
        }
        return 0;
    }

    protected function column_clicks($item) {
        $total_clicks = $this->get_click_count($item->id);
        return sprintf(
            '<span class="click-count">%d</span>',
            $total_clicks
        );
    }
    
    protected function column_protection($item) {
        $protections = array();
        
        if (!empty($item->password)) {
            $protections[] = __('Password', 'linkmaster');
        }
        
        if (!empty($item->ip_restrictions)) {
            $protections[] = __('IP', 'linkmaster');
        }
        
        if (!empty($item->click_limit)) {
            $protections[] = sprintf(
                __('Clicks: %d/%d', 'linkmaster'),
                $item->total_clicks,
                $item->click_limit
            );
        }
        
        return !empty($protections) ? implode('<br>', $protections) : '—';
    }
    
    protected function column_expiry_date($item) {
        if (empty($item->expiry_date)) {
            return '—';
        }
        
        $expiry = strtotime($item->expiry_date);
        $now = time();
        
        if ($expiry < $now) {
            return sprintf(
                '<span class="linkmaster-status-expired">%s</span>',
                __('Expired', 'linkmaster')
            );
        }
        
        return date_i18n(get_option('date_format'), $expiry);
    }
    
    protected function column_created_at($item) {
        return date_i18n(get_option('date_format'), strtotime($item->created_at));
    }
    
    protected function get_bulk_actions() {
        return array(
            'enable'  => __('Enable', 'linkmaster'),
            'disable' => __('Disable', 'linkmaster'),
            'delete'  => __('Delete', 'linkmaster')
        );
    }
    
    public function prepare_items() {
        global $wpdb;
        
        $this->process_bulk_action();
        
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        $orderby = (!empty($_REQUEST['orderby'])) ? sanitize_sql_orderby($_REQUEST['orderby']) : 'created_at';
        $order = (!empty($_REQUEST['order'])) ? sanitize_text_field($_REQUEST['order']) : 'desc';
        
        $this->items = $wpdb->get_results($wpdb->prepare(
            "SELECT *, 
                COALESCE(total_clicks, 0) as total_clicks,
                COALESCE(unique_clicks, 0) as unique_clicks
            FROM {$this->table_name} 
            ORDER BY %s %s 
            LIMIT %d OFFSET %d",
            $orderby,
            $order,
            $this->items_per_page,
            ($current_page - 1) * $this->items_per_page
        ));
        
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $this->items_per_page,
            'total_pages' => ceil($total_items / $this->items_per_page)
        ));
    }
    
    protected function process_bulk_action() {
        global $wpdb;
        
        $action = $this->current_action();
        if (!$action) {
            return;
        }
        
        $link_ids = isset($_REQUEST['link']) ? array_map('intval', $_REQUEST['link']) : array();
        if (empty($link_ids)) {
            return;
        }
        
        switch ($action) {
            case 'enable':
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$this->table_name} SET status = 'active' WHERE id IN (" . implode(',', array_fill(0, count($link_ids), '%d')) . ")",
                        $link_ids
                    )
                );
                break;
                
            case 'disable':
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$this->table_name} SET status = 'disabled' WHERE id IN (" . implode(',', array_fill(0, count($link_ids), '%d')) . ")",
                        $link_ids
                    )
                );
                break;
                
            case 'delete':
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$this->table_name} WHERE id IN (" . implode(',', array_fill(0, count($link_ids), '%d')) . ")",
                        $link_ids
                    )
                );
                break;
        }
    }
}
