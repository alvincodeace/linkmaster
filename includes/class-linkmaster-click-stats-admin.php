<?php
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Click_Stats_Admin {
    private static $instance = null;
    private $click_tracker;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->click_tracker = LinkMaster_Click_Tracker::get_instance();
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_linkmaster_get_redirect_stats', array($this, 'get_click_stats_ajax'));
        
        // Hook into the redirections page to add our tab
        add_action('linkmaster_redirections_tabs', array($this, 'add_click_stats_tab'), 10, 1);
        add_action('linkmaster_redirections_tab_content', array($this, 'display_click_stats_tab_content'), 10, 1);
    }
    
    /**
     * Add the Click Stats tab to the redirections page
     * 
     * @param string $active_tab The currently active tab
     */
    public function add_click_stats_tab($active_tab) {
        ?>
        <a href="<?php echo esc_url(add_query_arg('tab', 'stats', remove_query_arg('tab'))); ?>" class="nav-tab <?php echo $active_tab == 'stats' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e('Click Stats', 'linkmaster'); ?>
        </a>
        <?php
    }
    
    /**
     * Display the Click Stats tab content
     * 
     * @param string $active_tab The currently active tab
     */
    public function display_click_stats_tab_content($active_tab) {
        if ($active_tab != 'stats') {
            return;
        }
        
        $this->display_admin_page();
    }

    public function enqueue_scripts($hook) {
        if ('linkmaster_page_linkmaster-redirections' !== $hook) {
            return;
        }
        
        // Only load scripts on the stats tab
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'stats') {
            return;
        }

        wp_enqueue_style('linkmaster-admin');
        wp_enqueue_style('linkmaster-click-stats', LINKMASTER_PLUGIN_URL . 'css/click-stats.css', array(), LINKMASTER_VERSION);
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '3.7.0', true);
        wp_enqueue_script('linkmaster-click-stats', LINKMASTER_PLUGIN_URL . 'js/click-stats.js', array('jquery', 'chart-js'), LINKMASTER_VERSION, true);
        
        wp_localize_script('linkmaster-click-stats', 'linkmaster', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'stats_nonce' => wp_create_nonce('linkmaster_stats')
        ));
    }

    public function display_admin_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'linkmaster'));
        }

        $current_tab = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'overview';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Redirected Link Click Statistics', 'linkmaster'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg(array('tab' => 'stats', 'view' => 'overview'), remove_query_arg('view'))); ?>" 
                   class="nav-tab <?php echo $current_tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-chart-pie"></span>
                    <?php esc_html_e('Overview', 'linkmaster'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(array('tab' => 'stats', 'view' => 'details'), remove_query_arg('view'))); ?>" 
                   class="nav-tab <?php echo $current_tab === 'details' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e('Detailed Status', 'linkmaster'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($current_tab) {
                    case 'details':
                        $this->render_detailed_stats();
                        break;
                    default:
                        $this->render_overview();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_overview() {
        $data = $this->get_overview_data();
        ?>
        <div class="linkmaster-overview">
            <div class="linkmaster-stats-cards">
                <div class="stats-card">
                    <h3><?php esc_html_e('Total Clicks', 'linkmaster'); ?></h3>
                    <div class="stats-number"><?php echo number_format_i18n($data['total_clicks']); ?></div>
                </div>
                <div class="stats-card">
                    <h3><?php esc_html_e('Today\'s Clicks', 'linkmaster'); ?></h3>
                    <div class="stats-number"><?php echo number_format_i18n($data['today_clicks']); ?></div>
                    <div class="stats-trend <?php echo $data['daily_trend'] >= 0 ? 'positive' : 'negative'; ?>">
                        <span class="trend-icon dashicons <?php echo $data['daily_trend'] >= 0 ? 'dashicons-arrow-up-alt' : 'dashicons-arrow-down-alt'; ?>"></span>
                        <span class="trend-value"><?php echo abs($data['daily_trend']); ?></span>
                        <span class="trend-label"><?php esc_html_e('vs yesterday', 'linkmaster'); ?></span>
                    </div>
                </div>
                <div class="stats-card">
                    <h3><?php esc_html_e('Most Clicked Link', 'linkmaster'); ?></h3>
                    <div class="stats-detail">
                        <div class="stats-url"><?php echo esc_html($data['most_clicked']['url']); ?></div>
                        <div class="stats-subtext">
                            <?php echo sprintf(
                                esc_html__('%s clicks', 'linkmaster'),
                                number_format_i18n($data['most_clicked']['clicks'])
                            ); ?>
                        </div>
                    </div>
                </div>
                <div class="stats-card">
                    <h3><?php esc_html_e('Avg. Daily Clicks', 'linkmaster'); ?></h3>
                    <div class="stats-number"><?php echo number_format_i18n($data['avg_daily_clicks']); ?></div>
                    <div class="stats-subtext"><?php esc_html_e('Last 7 days', 'linkmaster'); ?></div>
                </div>
            </div>

            <div class="linkmaster-chart-container">
                <div class="chart-header">
                    <h3><?php esc_html_e('Click Distribution', 'linkmaster'); ?></h3>
                    <div class="chart-actions">
                        <div class="chart-type-toggle"></div>
                    </div>
                </div>
                <div id="chart-wrapper" style="position: relative;">
                    <canvas id="clicks-chart"></canvas>
                </div>
            </div>

            <!-- Modal removed - now navigating directly to detailed stats tab -->
        </div>
        <?php
    }

    private function get_overview_data() {
        $click_tracker = LinkMaster_Click_Tracker::get_instance();
        global $wpdb;
        
        // Get total clicks (all-time)
        $total_clicks = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$click_tracker->get_table_name()} 
                WHERE link_type = %s",
                'redirect'
            )
        );
        $total_clicks = intval($total_clicks);
        
        // Get today's clicks
        $today_clicks = $click_tracker->get_today_clicks('redirect');
        
        // Get clicks for last 7 days for trend and average
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $daily_clicks = $click_tracker->get_clicks_by_date_range($start_date, $end_date, 'redirect');
        
        // Calculate daily trend
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $yesterday_clicks = 0;
        foreach ($daily_clicks as $day) {
            if ($day['date'] === $yesterday) {
                $yesterday_clicks = $day['clicks'];
                break;
            }
        }
        
        $daily_trend = $today_clicks - $yesterday_clicks;
        
        // Calculate average daily clicks from last 7 days
        $recent_total = 0;
        foreach ($daily_clicks as $day) {
            $recent_total += $day['clicks'];
        }
        $avg_daily_clicks = count($daily_clicks) > 0 ? round($recent_total / count($daily_clicks)) : 0;
        
        // Get most clicked redirect
        $most_clicked = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT link_id, COUNT(*) as clicks 
                FROM {$click_tracker->get_table_name()} 
                WHERE link_type = %s
                GROUP BY link_id 
                ORDER BY clicks DESC 
                LIMIT 1",
                'redirect'
            )
        );
        
        $most_clicked_url = '';
        if ($most_clicked) {
            $redirects = get_option('linkmaster_redirects', array());
            if (isset($redirects[$most_clicked->link_id])) {
                $most_clicked_url = $redirects[$most_clicked->link_id]['target_url'];
            }
        }
        
        return array(
            'total_clicks' => $total_clicks,
            'today_clicks' => $today_clicks,
            'daily_trend' => $daily_trend,
            'avg_daily_clicks' => $avg_daily_clicks,
            'most_clicked' => array(
                'url' => $most_clicked_url,
                'clicks' => $most_clicked ? $most_clicked->clicks : 0
            )
        );
    }

    private function render_detailed_stats() {
        global $wpdb;
        $redirects = get_option('linkmaster_redirects', array());
        $click_tracker = LinkMaster_Click_Tracker::get_instance();
        
        // Get all redirect clicks
        $click_stats = array();
        
        if (!empty($redirects)) {
            $stats = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT link_id, 
                            COUNT(*) as total_clicks,
                            MAX(clicked_at) as last_click,
                            COUNT(DISTINCT user_ip) as unique_visitors
                    FROM {$click_tracker->get_table_name()}
                    WHERE link_type = %s
                    GROUP BY link_id",
                    'redirect'
                )
            );
            
            if ($stats) {
                foreach ($stats as $stat) {
                    $click_stats[$stat->link_id] = $stat;
                }
            }
        }

        ?>
        <div class="linkmaster-detailed-stats">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Source URL', 'linkmaster'); ?></th>
                        <th><?php esc_html_e('Target URL', 'linkmaster'); ?></th>
                        <th><?php esc_html_e('Total Clicks', 'linkmaster'); ?></th>
                        <th><?php esc_html_e('Unique Visitors', 'linkmaster'); ?></th>
                        <th><?php esc_html_e('Last Click', 'linkmaster'); ?></th>
                        <th><?php esc_html_e('Status', 'linkmaster'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($redirects as $redirect_id => $redirect) : 
                        $stats = isset($click_stats[$redirect_id]) ? $click_stats[$redirect_id] : null;
                        $total_clicks = $stats ? $stats->total_clicks : 0;
                        $unique_visitors = $stats ? $stats->unique_visitors : 0;
                        $last_click = $stats ? $stats->last_click : null;
                        $status = isset($redirect['status']) && $redirect['status'] === 'disabled' ? 'disabled' : 'active';
                    ?>
                        <tr>
                            <td><?php echo esc_html($redirect['source_url']); ?></td>
                            <td><?php echo esc_html($redirect['target_url']); ?></td>
                            <td><?php echo number_format_i18n($total_clicks); ?></td>
                            <td><?php echo number_format_i18n($unique_visitors); ?></td>
                            <td><?php echo $last_click ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_click)) : '-'; ?></td>
                            <td>
                                <span class="linkmaster-status <?php echo esc_attr($status); ?>">
                                    <?php echo esc_html(ucfirst($status)); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function get_click_stats_ajax() {
        check_ajax_referer('linkmaster_stats', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $redirects = get_option('linkmaster_redirects', array());
        $data = array();

        foreach ($redirects as $id => $redirect) {
            if (isset($redirect['hits']) && $redirect['hits'] > 0) {
                $data[$id] = array(
                    'source_url' => $redirect['source_url'],
                    'target_url' => $redirect['target_url'],
                    'hits' => intval($redirect['hits'])
                );
            }
        }

        wp_send_json_success($data);
    }
}
