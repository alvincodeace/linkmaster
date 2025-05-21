<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Scanner {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Load scanner options
        $this->options = get_option('linkmaster_scanner_options', array(
            'frequency' => 'weekly',
            'frequency_day' => 'monday',
            'frequency_time' => '00:00',
            'email_notifications' => false,
            'notification_email' => get_option('admin_email'),
            'last_scan' => '',
            'next_scan' => '',
            'enable_scheduled_scans' => false, // Default to disabled
            'last_cron_check' => ''
        ));

        // Remove WordPress cron dependency
        remove_action('linkmaster_scheduled_scan', array($this, 'schedule_scan'));
        wp_clear_scheduled_hook('linkmaster_scheduled_scan');

        add_action('wp_ajax_linkmaster_manual_scan', array($this, 'ajax_manual_scan'));
        add_action('wp_ajax_linkmaster_scan_progress', array($this, 'ajax_scan_progress'));
        add_action('wp_ajax_linkmaster_save_scanner_options', array($this, 'ajax_save_options'));
        add_action('wp_ajax_linkmaster_check_resumable_scan', array($this, 'ajax_check_resumable_scan'));
        
        // Add settings page
        add_action('admin_init', array($this, 'register_settings'));
        
        // Check for pending scans on every page load
        add_action('init', array($this, 'check_pending_scans'));
    }

    /**
     * Check if a scan is due and run it
     */
    public function check_pending_scans() {
        // If scans are disabled, return
        if (!isset($this->options['enable_scheduled_scans']) || !$this->options['enable_scheduled_scans']) {
            return;
        }

        // If no next scan is set, initialize it
        if (empty($this->options['next_scan'])) {
            $this->calculate_next_scan_time();
            return;
        }

        $now = new DateTime('now', wp_timezone());
        $next_scan = new DateTime($this->options['next_scan'], wp_timezone());

        // If it's time for a scan
        if ($now >= $next_scan) {
            try {
                error_log('LinkMaster: Starting scheduled scan at ' . current_time('mysql'));

                // Run the scan
                $results = $this->scan_links(0, 50, false);
                
                if (!is_wp_error($results)) {
                    // Update last scan time
                    $this->options['last_scan'] = current_time('mysql');
                    
                    // Calculate next scan time
                    $this->calculate_next_scan_time();
                    
                    error_log('LinkMaster: Completed scheduled scan at ' . current_time('mysql'));
                } else {
                    error_log('LinkMaster Scan Error: ' . $results->get_error_message());
                }
                
                update_option('linkmaster_scanner_options', $this->options);
            } catch (Exception $e) {
                error_log('LinkMaster Scan Exception: ' . $e->getMessage());
            }
        }
    }

    /**
     * Calculate and set the next scan time
     */
    private function calculate_next_scan_time() {
        // If scheduled scans are disabled, clear next scan time and return
        if (isset($this->options['enable_scheduled_scans']) && !$this->options['enable_scheduled_scans']) {
            $this->options['next_scan'] = '';
            update_option('linkmaster_scanner_options', $this->options);
            return;
        }
        
        $wp_timezone = wp_timezone();
        $now = new DateTime('now', $wp_timezone);
        
        // Get configured time
        $time = isset($this->options['frequency_time']) ? $this->options['frequency_time'] : '00:00';
        list($hours, $minutes) = explode(':', $time);
        
        // Log the frequency and day settings
        error_log('LinkMaster: Calculating next scan time - Frequency: ' . $this->options['frequency'] . ', Day: ' . $this->options['frequency_day']);
        
        // Create next run time
        $next_run = new DateTime('today', $wp_timezone);
        $next_run->setTime((int)$hours, (int)$minutes);
        
        // Adjust based on frequency
        switch ($this->options['frequency']) {
            case 'daily':
                // If the time has passed today, start from tomorrow
                if ($next_run <= $now) {
                    $next_run->modify('+1 day');
                }
                break;
                
            case 'weekly':
                if (isset($this->options['frequency_day'])) {
                    $target_day = strtolower($this->options['frequency_day']);
                    $current_day = strtolower($now->format('l'));
                    
                    // Log the target day and current day
                    error_log('LinkMaster: Weekly schedule - Target day: ' . $target_day . ', Current day: ' . $current_day);
                    
                    // Reset to today
                    $next_run = new DateTime('today', $wp_timezone);
                    $next_run->setTime((int)$hours, (int)$minutes);
                    
                    // If today is the target day but the time has passed, start from next week
                    if ($current_day === $target_day && $next_run <= $now) {
                        $next_run->modify('+1 week');
                        error_log('LinkMaster: Today is target day but time passed, moving to next week');
                    } else {
                        // Find the next occurrence of the target day
                        while (strtolower($next_run->format('l')) !== $target_day) {
                            $next_run->modify('+1 day');
                        }
                        error_log('LinkMaster: Moving to next occurrence of target day: ' . $next_run->format('Y-m-d H:i:s'));
                    }
                }
                break;
                
            case 'monthly':
                if (isset($this->options['frequency_day'])) {
                    // Ensure target_day is an integer between 1 and 28
                    $target_day = is_numeric($this->options['frequency_day']) ? intval($this->options['frequency_day']) : 1;
                    $target_day = max(1, min(28, $target_day));
                    
                    // Log the target day
                    error_log('LinkMaster: Monthly schedule - Target day: ' . $target_day);
                    
                    $current_month = $now->format('Y-m');
                    
                    // Create a date for the target day in the current month
                    $month_target = new DateTime($current_month . '-' . str_pad($target_day, 2, '0', STR_PAD_LEFT), $wp_timezone);
                    $month_target->setTime((int)$hours, (int)$minutes);
                    
                    // If the target day in the current month has passed, move to next month
                    if ($month_target <= $now) {
                        $next_month = clone $now;
                        $next_month->modify('first day of next month');
                        $next_month_str = $next_month->format('Y-m');
                        
                        $next_run = new DateTime($next_month_str . '-' . str_pad($target_day, 2, '0', STR_PAD_LEFT), $wp_timezone);
                        $next_run->setTime((int)$hours, (int)$minutes);
                        error_log('LinkMaster: Target day in current month has passed, moving to next month: ' . $next_run->format('Y-m-d H:i:s'));
                    } else {
                        $next_run = clone $month_target;
                        error_log('LinkMaster: Using target day in current month: ' . $next_run->format('Y-m-d H:i:s'));
                    }
                    
                    // Handle invalid dates (e.g., February 30)
                    if ($next_run->format('j') != $target_day) {
                        // Move to the last day of the month
                        $next_run->modify('last day of ' . $next_run->format('F Y'));
                        error_log('LinkMaster: Invalid date detected, moving to last day of month: ' . $next_run->format('Y-m-d H:i:s'));
                    }
                }
                break;
        }
        
        $this->options['next_scan'] = $next_run->format('Y-m-d H:i:s');
        update_option('linkmaster_scanner_options', $this->options);
        error_log('LinkMaster: Next scan time set to: ' . $this->options['next_scan']);
        
        // Schedule the scan event
        $timestamp = $next_run->getTimestamp();
        wp_clear_scheduled_hook('linkmaster_scheduled_scan');
        wp_schedule_single_event($timestamp, 'linkmaster_scheduled_scan');
    }

    public function init() {
        // Simply call calculate_next_scan_time which now handles all the scheduling logic
        $this->calculate_next_scan_time();
    }

    /**
     * Handler for the scheduled scan event
     */
    public function schedule_scan() {
        try {
            error_log('LinkMaster: Starting scheduled scan at ' . current_time('mysql'));
            
            // Start the scan
            $results = $this->scan_links(0, 50, false);
            
            if (is_wp_error($results)) {
                error_log('LinkMaster Scheduled Scan Error: ' . $results->get_error_message());
                return;
            }

            // Update last scan time
            $this->options['last_scan'] = current_time('mysql');
            update_option('linkmaster_scanner_options', $this->options);
            
            error_log('LinkMaster: Completed scheduled scan at ' . current_time('mysql'));

            // Schedule next scan
            $this->init();

        } catch (Exception $e) {
            error_log('LinkMaster Scheduled Scan Exception: ' . $e->getMessage());
        }
    }

    /**
     * Check if WordPress cron is running as expected
     */
    public function check_cron_health() {
        // Only check once per hour
        $last_check = !empty($this->options['last_cron_check']) ? strtotime($this->options['last_cron_check']) : 0;
        if (time() - $last_check < HOUR_IN_SECONDS) {
            return;
        }

        // Update last check time
        $this->options['last_cron_check'] = current_time('mysql');
        update_option('linkmaster_scanner_options', $this->options);

        // If scheduled scans are disabled, no need to check
        if (isset($this->options['enable_scheduled_scans']) && !$this->options['enable_scheduled_scans']) {
            return;
        }

        // Check if next_scan is set and in the past
        if (!empty($this->options['next_scan'])) {
            $next_scan = strtotime($this->options['next_scan']);
            $hours_late = (time() - $next_scan) / HOUR_IN_SECONDS;

            // If the scan is more than 2 hours late, show admin notice
            if ($hours_late > 2) {
                add_action('admin_notices', function() {
                    ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <strong><?php esc_html_e('LinkMaster Warning:', 'linkmaster'); ?></strong>
                            <?php 
                            echo sprintf(
                                esc_html__('Scheduled scans are not running as expected. The last scheduled scan was %d hours late. This might indicate an issue with WordPress cron. Please check your server\'s cron configuration.', 'linkmaster'),
                                floor($hours_late)
                            ); 
                            ?>
                        </p>
                    </div>
                    <?php
                });
            }
        }
    }

    public function register_settings() {
        register_setting('linkmaster_scanner_options', 'linkmaster_scanner_options');
        
        add_settings_section(
            'linkmaster_scanner_settings',
            __('Scanner Settings', 'linkmaster'),
            array($this, 'render_settings_section'),
            'linkmaster-broken-links'
        );

        add_settings_field(
            'scan_frequency',
            __('Scan Frequency', 'linkmaster'),
            array($this, 'render_frequency_field'),
            'linkmaster-broken-links',
            'linkmaster_scanner_settings'
        );

        add_settings_field(
            'email_notifications',
            __('Email Notifications', 'linkmaster'),
            array($this, 'render_notifications_field'),
            'linkmaster-broken-links',
            'linkmaster_scanner_settings'
        );

        add_settings_field(
            'notification_email',
            __('Notification Email', 'linkmaster'),
            array($this, 'render_email_field'),
            'linkmaster-broken-links',
            'linkmaster_scanner_settings'
        );

        add_settings_field(
            'enable_scheduled_scans',
            __('Enable Scheduled Scans', 'linkmaster'),
            array($this, 'render_scheduled_scans_field'),
            'linkmaster-broken-links',
            'linkmaster_scanner_settings'
        );
    }

    public function render_settings_section() {
        echo '<p>' . esc_html__('Configure how the link scanner checks your site for broken links.', 'linkmaster') . '</p>';
    }

    public function render_frequency_field() {
        $frequency = $this->options['frequency'];
        $frequency_day = isset($this->options['frequency_day']) ? $this->options['frequency_day'] : 'monday';
        $frequency_time = isset($this->options['frequency_time']) ? $this->options['frequency_time'] : '00:00';
        
        // Days of the week for weekly scans
        $days_of_week = array(
            'monday' => __('Monday', 'linkmaster'),
            'tuesday' => __('Tuesday', 'linkmaster'),
            'wednesday' => __('Wednesday', 'linkmaster'),
            'thursday' => __('Thursday', 'linkmaster'),
            'friday' => __('Friday', 'linkmaster'),
            'saturday' => __('Saturday', 'linkmaster'),
            'sunday' => __('Sunday', 'linkmaster')
        );
        
        // Days of the month for monthly scans
        $days_of_month = range(1, 28);
        ?>
        <div class="linkmaster-scanner-frequency">
            <select name="linkmaster_scanner_options[frequency]" id="scan_frequency">
                <option value="daily" <?php selected($frequency, 'daily'); ?>>
                    <?php esc_html_e('Daily', 'linkmaster'); ?>
                </option>
                <option value="weekly" <?php selected($frequency, 'weekly'); ?>>
                    <?php esc_html_e('Weekly', 'linkmaster'); ?>
                </option>
                <option value="monthly" <?php selected($frequency, 'monthly'); ?>>
                    <?php esc_html_e('Monthly', 'linkmaster'); ?>
                </option>
            </select>
            
            <div id="weekly_options" class="frequency-options" <?php echo $frequency !== 'weekly' ? 'style="display:none;"' : ''; ?>>
                <label for="weekly_day"><?php esc_html_e('On', 'linkmaster'); ?></label>
                <select name="linkmaster_scanner_options[frequency_day]" id="weekly_day" class="frequency-day-select">
                    <?php foreach ($days_of_week as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($frequency_day, $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div id="monthly_options" class="frequency-options" <?php echo $frequency !== 'monthly' ? 'style="display:none;"' : ''; ?>>
                <label for="monthly_day"><?php esc_html_e('On day', 'linkmaster'); ?></label>
                <select name="linkmaster_scanner_options[frequency_day]" id="monthly_day" class="frequency-day-select">
                    <?php foreach ($days_of_month as $day) : ?>
                        <option value="<?php echo esc_attr($day); ?>" <?php selected($frequency_day, (string)$day); ?>>
                            <?php echo esc_html($day); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="time-options">
                <label for="scan_time"><?php esc_html_e('At', 'linkmaster'); ?></label>
                <input type="time" name="linkmaster_scanner_options[frequency_time]" id="scan_time" value="<?php echo esc_attr($frequency_time); ?>">
            </div>
            
            <?php if (!empty($this->options['next_scan'])) : ?>
                <p class="next-scan-info">
                    <strong><?php esc_html_e('Next scheduled scan:', 'linkmaster'); ?></strong>
                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($this->options['next_scan']))); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Show/hide appropriate options based on frequency selection
            $('#scan_frequency').on('change', function() {
                var frequency = $(this).val();
                $('.frequency-options').hide();
                
                if (frequency === 'weekly') {
                    $('#weekly_options').show();
                } else if (frequency === 'monthly') {
                    $('#monthly_options').show();
                }
                
                // Ensure the correct day selector is used for the current frequency
                $('.frequency-day-select').prop('disabled', true);
                if (frequency === 'weekly') {
                    $('#weekly_day').prop('disabled', false);
                } else if (frequency === 'monthly') {
                    $('#monthly_day').prop('disabled', false);
                }
            });
            
            // Initialize on page load
            var frequency = $('#scan_frequency').val();
            $('.frequency-day-select').prop('disabled', true);
            if (frequency === 'weekly') {
                $('#weekly_day').prop('disabled', false);
            } else if (frequency === 'monthly') {
                $('#monthly_day').prop('disabled', false);
            }
        });
        </script>
        <?php
    }

    public function render_notifications_field() {
        $notifications = !empty($this->options['email_notifications']);
        ?>
        <label>
            <input type="checkbox" name="linkmaster_scanner_options[email_notifications]" 
                   value="1" <?php checked($notifications); ?>>
            <?php esc_html_e('Send email notifications when broken links are found', 'linkmaster'); ?>
        </label>
        <?php
    }

    public function render_email_field() {
        $email = $this->options['notification_email'];
        ?>
        <input type="email" name="linkmaster_scanner_options[notification_email]" 
               value="<?php echo esc_attr($email); ?>" class="regular-text">
        <?php
    }

    public function render_scheduled_scans_field() {
        $scheduled_scans = !empty($this->options['enable_scheduled_scans']);
        ?>
        <div class="scheduled-scans-field">
            <label>
                <input type="checkbox" name="linkmaster_scanner_options[enable_scheduled_scans]" 
                       value="1" <?php checked($scheduled_scans); ?>>
                <strong><?php esc_html_e('Enable scheduled scans', 'linkmaster'); ?></strong>
            </label>
            <p class="description">
                <?php esc_html_e('Scheduled scans are disabled by default. Enable this option to automatically scan your site for broken links based on the frequency settings above.', 'linkmaster'); ?>
            </p>
            <?php if (!$scheduled_scans): ?>
            <div class="notice notice-info inline" style="margin: 10px 0;">
                <p>
                    <span class="dashicons dashicons-info" style="color: #00a0d2; margin-right: 5px;"></span>
                    <?php esc_html_e('Scheduled scans are currently disabled. Enable this option to automatically scan your site on a regular basis.', 'linkmaster'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function scan_links($offset = 0, $limit = 10, $is_manual = false) {
        try {
            // Check if scan was canceled
            $scan_canceled = get_option('linkmaster_scan_canceled', false);
            if ($scan_canceled) {
                update_option('linkmaster_scan_canceled', false);
                return array(
                    'broken_links' => array(),
                    'progress' => 0,
                    'complete' => true,
                    'canceled' => true
                );
            }

            // Get previous scan data
            $previous_broken_links = array();
            $current_broken_links = array();
            
            // Only reset for the first chunk of a manual scan if not resuming
            if ($is_manual && $offset === 0 && !get_option('linkmaster_scan_resuming', false)) {
                // Store previous broken links for comparison
                $previous_broken_links = get_option('linkmaster_broken_links', array());
                $broken_links = array(); // Start fresh for manual scans
                $total_links_scanned = 0;
                update_option('linkmaster_broken_links', $broken_links);
                update_option('linkmaster_total_scanned', 0);
                update_option('linkmaster_scan_resuming', false);
                update_option('linkmaster_previous_broken_links', $previous_broken_links);
                update_option('linkmaster_scan_last_offset', 0);
            } else {
                $broken_links = get_option('linkmaster_broken_links', array());
                $total_links_scanned = get_option('linkmaster_total_scanned', 0);
                $previous_broken_links = get_option('linkmaster_previous_broken_links', array());
            }

            // Track processed URLs to avoid duplicates within this chunk
            $processed_urls = array_column($broken_links, 'url');

            error_log('LinkMaster: Starting scan from offset ' . $offset);

            $post_types = get_post_types(array('public' => true));
            $posts = get_posts(array(
                'post_type' => array_values($post_types),
                'posts_per_page' => $limit,
                'offset' => $offset,
                'post_status' => 'publish'
            ));

            $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
            $progress = $total_posts > 0 ? ($offset + count($posts)) / $total_posts * 100 : 100;

            foreach ($posts as $post) {
                if (empty($post->post_content)) {
                    continue;
                }

                preg_match_all('/<a\s+(?:[^>]*?\s+)?href=(["\'])(.*?)\1.*?>(.*?)<\/a>/is', $post->post_content, $matches);

                if (!empty($matches[2])) {
                    foreach ($matches[2] as $key => $url) {
                        if (empty($url) || strpos($url, '#') === 0) {
                            continue;
                        }

                        // Normalize URL
                        if (strpos($url, 'http') !== 0 && strpos($url, '/') === 0) {
                            $url = site_url($url);
                        } elseif (strpos($url, 'http') !== 0) {
                            $url = site_url('/' . $url);
                        }

                        // Skip if URL was already processed
                        if (in_array($url, $processed_urls)) {
                            continue;
                        }

                        $total_links_scanned++;
                        $status = $this->check_link_status($url);

                        if ($status !== 200 && $status !== 'error') {
                            $broken_links[] = array(
                                'post_id' => $post->ID,
                                'post_title' => $post->post_title,
                                'url' => $url,
                                'status' => $status,
                                'anchor_text' => isset($matches[3][$key]) ? trim(strip_tags($matches[3][$key])) : '',
                                'date_checked' => current_time('mysql')
                            );
                            $processed_urls[] = $url;
                        } elseif ($status === 'error') {
                            $broken_links[] = array(
                                'post_id' => $post->ID,
                                'post_title' => $post->post_title,
                                'url' => $url,
                                'status' => 'error',
                                'anchor_text' => isset($matches[3][$key]) ? trim(strip_tags($matches[3][$key])) : '',
                                'date_checked' => current_time('mysql')
                            );
                            $processed_urls[] = $url;
                        }
                    }
                }
            }

            update_option('linkmaster_broken_links', $broken_links);
            update_option('linkmaster_total_scanned', $total_links_scanned);
            update_option('linkmaster_scan_last_offset', $offset + $limit);

            // Send email notification if enabled and broken links were found
            if ($this->options['email_notifications'] && !empty($broken_links)) {
                $this->send_notification_email($broken_links, $total_links_scanned);
            }
            update_option('linkmaster_scan_progress', $progress);

            return array(
                'broken_links' => $broken_links,
                'progress' => $progress,
                'complete' => ($offset + $limit) >= $total_posts,
                'total_posts' => $total_posts
            );

        } catch (Exception $e) {
            error_log('LinkMaster Scanner Error: ' . $e->getMessage());
            return new WP_Error('scan_failed', $e->getMessage());
        }
    }

    private function check_link_status($url) {
        try {
            $args = array(
                'timeout' => 30,
                'sslverify' => false,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'redirection' => 0
            );

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                error_log('LinkMaster Link Check Error: ' . $response->get_error_message());
                return 'error';
            }

            $response_code = wp_remote_retrieve_response_code($response);
            return $response_code ? $response_code : 'error';

        } catch (Exception $e) {
            error_log('LinkMaster Link Check Exception: ' . $e->getMessage());
            return 'error';
        }
    }

    public function ajax_manual_scan() {
        try {
            check_ajax_referer('linkmaster_scan', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => 'Unauthorized access'));
            }

            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $resume = isset($_POST['resume']) && $_POST['resume'] === 'true';
            
            // If resuming, use the last saved offset
            if ($resume && $offset === 0) {
                $offset = get_option('linkmaster_scan_last_offset', 0);
                update_option('linkmaster_scan_resuming', true);
            }
            
            // Check for cancel request
            if (isset($_POST['cancel']) && $_POST['cancel'] === 'true') {
                update_option('linkmaster_scan_canceled', true);
                wp_send_json_success(array(
                    'canceled' => true,
                    'message' => 'Scan canceled successfully.'
                ));
                return;
            }
            
            $results = $this->scan_links($offset, 10, true); // Pass true for manual scan

            if (is_wp_error($results)) {
                wp_send_json_error(array('message' => $results->get_error_message()));
            }
            
            // If scan was canceled
            if (isset($results['canceled']) && $results['canceled']) {
                wp_send_json_success(array(
                    'canceled' => true,
                    'message' => 'Scan canceled successfully.',
                    'progress' => 0,
                    'complete' => true
                ));
                return;
            }

            if ($results['complete']) {
                $last_scan = current_time('mysql');
                $this->options['last_scan'] = $last_scan;
                update_option('linkmaster_scanner_options', $this->options);
                
                // Compare with previous scan results to count new broken links
                $current_broken_links = $results['broken_links'];
                $previous_broken_links = get_option('linkmaster_previous_broken_links', array());
                
                // Create a more accurate comparison by using post_id + url as a unique identifier
                $previous_link_keys = array();
                foreach ($previous_broken_links as $link) {
                    if (isset($link['post_id']) && isset($link['url'])) {
                        $previous_link_keys[] = $link['post_id'] . '|' . $link['url'];
                    }
                }
                
                // Count new broken links by comparing post_id + url combinations
                $new_broken_links = array_filter($current_broken_links, function($link) use ($previous_link_keys) {
                    $key = $link['post_id'] . '|' . $link['url'];
                    return !in_array($key, $previous_link_keys);
                });
                
                $new_count = count($new_broken_links);
                $total_count = count($current_broken_links);
                
                $message = '';
                if ($total_count === 0) {
                    $message = 'No broken links found.';
                } else if ($new_count === 0) {
                    $message = "Found {$total_count} broken links. No new broken links since last scan.";
                } else {
                    $message = "Found {$total_count} broken links, including {$new_count} new broken links since last scan.";
                }
                
                // Reset resuming flag
                update_option('linkmaster_scan_resuming', false);
                
                wp_send_json_success(array(
                    'broken_links' => $results['broken_links'],
                    'last_scan' => $last_scan,
                    'message' => $message,
                    'progress' => 100,
                    'complete' => true,
                    'show_notification' => true,
                    'new_broken_links' => $new_count
                ));
            } else {
                wp_send_json_success(array(
                    'progress' => $results['progress'],
                    'offset' => $offset + 10,
                    'complete' => false
                ));
            }

        } catch (Exception $e) {
            error_log('LinkMaster AJAX Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error during scan: ' . $e->getMessage()));
        }
    }

    public function ajax_scan_progress() {
        $progress = get_option('linkmaster_scan_progress', 0);
        wp_send_json_success(array('progress' => $progress));
    }

    public function ajax_check_resumable_scan() {
        try {
            check_ajax_referer('linkmaster_scan', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => 'Unauthorized access'));
            }
            
            $last_offset = get_option('linkmaster_scan_last_offset', 0);
            $total_posts = wp_count_posts('post')->publish + wp_count_posts('page')->publish;
            
            // Can resume if there's a saved offset and it's not at the end
            $can_resume = ($last_offset > 0 && $last_offset < $total_posts);
            
            wp_send_json_success(array(
                'can_resume' => $can_resume,
                'last_offset' => $last_offset,
                'total_posts' => $total_posts
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    private function send_notification_email($broken_links, $total_scanned) {
        $to = $this->options['notification_email'];
        $subject = sprintf(__('[%s] Broken Links Found - Link Scanner Report', 'linkmaster'), get_bloginfo('name'));
        
        $message = sprintf(
            __('Link Scanner found %d broken links out of %d total links scanned on %s.\n\n', 'linkmaster'),
            count($broken_links),
            $total_scanned,
            current_time('mysql')
        );
        
        $message .= __('Broken Links Details:\n\n', 'linkmaster');
        
        foreach ($broken_links as $link) {
            $message .= sprintf(
                "URL: %s\nStatus: %s\nFound in: %s (ID: %d)\nAnchor Text: %s\nLast Checked: %s\n\n",
                $link['url'],
                $link['status'],
                $link['post_title'],
                $link['post_id'],
                $link['anchor_text'],
                $link['date_checked']
            );
        }
        
        $message .= sprintf(
            __('\nView all broken links in the admin panel: %s', 'linkmaster'),
            admin_url('admin.php?page=linkmaster-broken-links')
        );

        wp_mail($to, $subject, $message);
    }

    public function ajax_save_options() {
        // Prevent duplicate processing
        static $already_processed = false;
        if ($already_processed) {
            error_log('LinkMaster: Preventing duplicate ajax_save_options call');
            return;
        }
        $already_processed = true;
        
        check_ajax_referer('linkmaster_scanner_options', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized access', 'linkmaster')));
            return;
        }
        
        if (!isset($_POST['linkmaster_scanner_options']) || !is_array($_POST['linkmaster_scanner_options'])) {
            wp_send_json_error(array('message' => __('Invalid settings data', 'linkmaster')));
            return;
        }

        $posted_options = $_POST['linkmaster_scanner_options'];
        
        // Validate email
        $email = '';
        if (!empty($posted_options['notification_email'])) {
            $email = sanitize_email($posted_options['notification_email']);
            if (!is_email($email)) {
                wp_send_json_error(array('message' => __('Please enter a valid email address', 'linkmaster')));
                return;
            }
        }
        
        $frequency = isset($posted_options['frequency']) ? sanitize_text_field($posted_options['frequency']) : 'daily';
        
        // Ensure we get the correct frequency_day based on the selected frequency
        $frequency_day = 'monday'; // Default
        if ($frequency === 'weekly' && isset($posted_options['frequency_day'])) {
            $frequency_day = sanitize_text_field($posted_options['frequency_day']);
            // Validate that it's a valid day of the week
            $valid_days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
            if (!in_array($frequency_day, $valid_days)) {
                $frequency_day = 'monday';
            }
        } elseif ($frequency === 'monthly' && isset($posted_options['frequency_day'])) {
            $frequency_day = sanitize_text_field($posted_options['frequency_day']);
            // Ensure it's a numeric value between 1-28
            if (!is_numeric($frequency_day) || intval($frequency_day) < 1 || intval($frequency_day) > 28) {
                $frequency_day = '1';
            }
        }
        
        // Log the processed frequency and day
        error_log('LinkMaster: Processed frequency: ' . $frequency . ', day: ' . $frequency_day);
        
        $options = array(
            'frequency' => $frequency,
            'frequency_day' => $frequency_day,
            'frequency_time' => isset($posted_options['frequency_time']) ? sanitize_text_field($posted_options['frequency_time']) : '00:00',
            'email_notifications' => !empty($posted_options['email_notifications']),
            'notification_email' => !empty($email) ? $email : get_option('admin_email'),
            'last_scan' => isset($this->options['last_scan']) ? $this->options['last_scan'] : '',
            'next_scan' => '',
            'enable_scheduled_scans' => !empty($posted_options['enable_scheduled_scans'])
        );
        
        update_option('linkmaster_scanner_options', $options);
        $this->options = $options;
        
        // Calculate and save next scan time
        wp_clear_scheduled_hook('linkmaster_scheduled_scan');
        $this->calculate_next_scan_time(); // Use calculate_next_scan_time directly instead of init
        
        // Get the updated next scan time
        $next_scan = $this->options['next_scan'];
        
        // Log the next scan time
        error_log('LinkMaster: Next scan time set to: ' . $next_scan);
        
        // Send JSON response and exit to prevent any further processing
        wp_send_json_success(array(
            'message' => __('Settings saved successfully', 'linkmaster'),
            'next_scan' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($next_scan))
        ));
        // wp_send_json_success already calls exit(), so we don't need to call it again
    }
}