<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Broken_Links_Admin {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_submenu'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_linkmaster_update_link', array($this, 'ajax_update_link'));
        add_action('wp_ajax_linkmaster_unlink', array($this, 'ajax_unlink'));
        add_action('wp_ajax_linkmaster_bulk_action', array($this, 'ajax_bulk_action'));
        add_action('admin_post_linkmaster_export_csv', array($this, 'export_csv'));
        add_action('wp_ajax_linkmaster_copy_url', array($this, 'ajax_copy_url'));
    }

    public function add_submenu() {
        add_submenu_page(
            'linkmaster',
            esc_html__('Broken Links', 'linkmaster'),
            esc_html__('Broken Links', 'linkmaster'),
            'edit_posts',
            'linkmaster-broken-links',
            array($this, 'display_admin_page')
        );
    }

    public function enqueue_scripts($hook) {
        if ('linkmaster_page_linkmaster-broken-links' !== $hook) {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'linkmaster-scanner-css',
            plugins_url('css/scanner.css', dirname(__FILE__)),
            array(),
            LINKMASTER_VERSION
        );
        wp_enqueue_script(
            'linkmaster-admin-js',
            plugins_url('js/admin.js', dirname(__FILE__)),
            array('jquery'),
            LINKMASTER_VERSION,
            true
        );

        wp_localize_script('linkmaster-admin-js', 'linkmaster', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('linkmaster_scan'),
            'update_nonce' => wp_create_nonce('linkmaster_update_link'),
            'bulk_nonce' => wp_create_nonce('linkmaster_bulk_action'),
            'scanner_options_nonce' => wp_create_nonce('linkmaster_scanner_options'),
            'scanning_text' => esc_html__('Scanning...', 'linkmaster'),
            'scan_complete' => esc_html__('Scan Complete', 'linkmaster'),
            'update_success' => esc_html__('Link updated successfully', 'linkmaster'),
            'update_error' => esc_html__('Error updating link', 'linkmaster'),
            'bulk_success' => esc_html__('Bulk action completed successfully', 'linkmaster'),
            'bulk_error' => esc_html__('Error performing bulk action', 'linkmaster')
        ));
    }

    public function ajax_update_link() {
        try {
            check_ajax_referer('linkmaster_update_link', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => 'Unauthorized access'));
            }

            $post_id = intval($_POST['post_id']);
            $old_url = sanitize_text_field($_POST['old_url']);
            $new_url = sanitize_url($_POST['new_url']);
            $post = get_post($post_id);
            if (!$post) {
                throw new Exception('Post not found');
            }

            $updated_content = str_replace(
                'href="' . esc_attr($old_url) . '"',
                'href="' . esc_attr($new_url) . '"',
                $post->post_content
            );

            $result = wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $updated_content
            ));

            if (!$result) {
                throw new Exception('Failed to update post');
            }

            $broken_links = get_option('linkmaster_broken_links', array());
            foreach ($broken_links as $key => $link) {
                if ($link['post_id'] == $post_id && $link['url'] == $old_url) {
                    unset($broken_links[$key]);
                    break;
                }
            }
            update_option('linkmaster_broken_links', array_values($broken_links));

            wp_send_json_success(array('message' => 'Link updated successfully'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_unlink() {
        try {
            check_ajax_referer('linkmaster_update_link', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => 'Unauthorized access'));
            }

            $post_id = intval($_POST['post_id']);
            $url = sanitize_text_field($_POST['url']);
            $post = get_post($post_id);
            if (!$post) {
                throw new Exception('Post not found');
            }

            $pattern = '/<a\s+(?:[^>]*?\s+)?href=["\']' . preg_quote($url, '/') . '["\'][^>]*>(.*?)<\/a>/is';
            $updated_content = preg_replace($pattern, '$1', $post->post_content);

            $result = wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $updated_content
            ));

            if (!$result) {
                throw new Exception('Failed to update post');
            }

            $broken_links = get_option('linkmaster_broken_links', array());
            foreach ($broken_links as $key => $link) {
                if ($link['post_id'] == $post_id && $link['url'] == $url) {
                    unset($broken_links[$key]);
                    break;
                }
            }
            update_option('linkmaster_broken_links', array_values($broken_links));

            wp_send_json_success(array('message' => 'Link removed successfully'));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_bulk_action() {
        try {
            check_ajax_referer('linkmaster_bulk_action', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => 'Unauthorized access'));
            }

            $action = sanitize_text_field($_POST['bulk_action']);
            $items = json_decode(stripslashes($_POST['items']), true);
            if (!is_array($items) || empty($items)) {
                throw new Exception('No items selected');
            }

            $processed = 0;
            foreach ($items as $item) {
                $post_id = intval($item['post_id']);
                $url = sanitize_text_field($item['url']);
                $post = get_post($post_id);
                if (!$post) {
                    continue;
                }

                if ($action === 'unlink') {
                    $pattern = '/<a\s+(?:[^>]*?\s+)?href=["\']' . preg_quote($url, '/') . '["\'][^>]*>(.*?)<\/a>/is';
                    $updated_content = preg_replace($pattern, '$1', $post->post_content);
                    $result = wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $updated_content
                    ));
                    if ($result) {
                        $processed++;
                    }
                }
            }

            $broken_links = get_option('linkmaster_broken_links', array());
            foreach ($items as $item) {
                foreach ($broken_links as $key => $link) {
                    if ($link['post_id'] == $item['post_id'] && $link['url'] == $item['url']) {
                        unset($broken_links[$key]);
                        break;
                    }
                }
            }
            update_option('linkmaster_broken_links', array_values($broken_links));

            wp_send_json_success(array(
                'message' => sprintf(
                    esc_html__('%d items processed successfully', 'linkmaster'),
                    $processed
                )
            ));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function export_csv() {
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        check_admin_referer('linkmaster_export_csv', 'nonce');
        
        $broken_links = get_option('linkmaster_broken_links', array());
        if (empty($broken_links)) {
            wp_redirect(add_query_arg('export', 'nodata', wp_get_referer()));
            exit;
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=broken-links-export-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, array('Page', 'Broken Link', 'Status', 'Anchor Text', 'Date Checked'));
        
        foreach ($broken_links as $link) {
            fputcsv($output, array(
                $link['post_title'],
                $link['url'],
                $link['status'],
                $link['anchor_text'],
                $link['date_checked']
            ));
        }
        
        fclose($output);
        exit;
    }

    private function get_status_label($status) {
        $status_labels = array(
            'error' => esc_html__('Connection Error', 'linkmaster'),
            '200' => esc_html__('200 OK', 'linkmaster'),
            '301' => esc_html__('301 Moved Permanently', 'linkmaster'),
            '302' => esc_html__('302 Temporary Redirect', 'linkmaster'),
            '400' => esc_html__('400 Bad Request', 'linkmaster'),
            '401' => esc_html__('401 Unauthorized', 'linkmaster'),
            '403' => esc_html__('403 Forbidden', 'linkmaster'),
            '404' => esc_html__('Not Found', 'linkmaster'),
            '500' => esc_html__('Server Error', 'linkmaster'),
            '502' => esc_html__('Bad Gateway', 'linkmaster'),
            '503' => esc_html__('Service Unavailable', 'linkmaster'),
        );
        
        // Convert status to string for array lookup
        $status = (string)$status;
        return isset($status_labels[$status]) ? $status_labels[$status] : sprintf(esc_html__('Status: %s', 'linkmaster'), $status);
    }

    private function get_status_class($status) {
        if ($status === 'error') return 'error';
        $status_code = intval($status);
        if ($status_code >= 500) return 'server-error';
        if ($status_code >= 400) return 'client-error';
        if ($status_code >= 300) return 'redirect';
        if ($status_code === 200) return 'success';
        return 'unknown';
    }

    private function get_all_status_types($broken_links) {
        $status_types = array();
        foreach ($broken_links as $link) {
            if (!in_array($link['status'], $status_types)) {
                $status_types[] = $link['status'];
            }
        }
        
        // Sort status types in a logical order
        usort($status_types, function($a, $b) {
            // Put 'error' first
            if ($a === 'error') return -1;
            if ($b === 'error') return 1;
            
            // Then sort numerically
            $a_num = intval($a);
            $b_num = intval($b);
            
            if ($a_num === $b_num) return 0;
            return ($a_num < $b_num) ? -1 : 1;
        });
        
        return $status_types;
    }

    private function get_all_post_types_with_broken_links($broken_links) {
        $post_types = array();
        foreach ($broken_links as $link) {
            $post_type = get_post_type($link['post_id']);
            if ($post_type && !in_array($post_type, $post_types)) {
                $post_types[] = $post_type;
            }
        }
        return $post_types;
    }

    private function render_pagination($total_pages, $current_page) {
        if ($total_pages <= 1) return '';
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
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'broken-links';
        $scanner = LinkMaster_Scanner::get_instance();
        $scanner_options = get_option('linkmaster_scanner_options', array());
        if (empty($scanner_options)) {
            $scanner_options = array(
                'frequency' => 'weekly',
                'frequency_day' => 'monday',
                'frequency_time' => '00:00',
                'email_notifications' => true,
                'notification_email' => get_option('admin_email'),
                'last_scan' => get_option('linkmaster_last_scan', ''),
                'next_scan' => '',
                'enable_scheduled_scans' => true
            );
            update_option('linkmaster_scanner_options', $scanner_options);
        }

        $all_broken_links = get_option('linkmaster_broken_links', array());
        $broken_links = $all_broken_links;
        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';
        $post_type_filter = isset($_GET['post_type_filter']) ? sanitize_text_field($_GET['post_type_filter']) : '';
        $post_types_with_broken_links = $this->get_all_post_types_with_broken_links($all_broken_links);
        $last_scan = $scanner_options['last_scan'];
        $items_per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $items_per_page;

        // Apply filters
        if (!empty($status_filter)) {
            $broken_links = array_filter($broken_links, function($link) use ($status_filter) {
                // Convert both to strings for comparison to ensure numeric status codes match
                return (string)$link['status'] === (string)$status_filter;
            });
        }
        if (!empty($post_type_filter)) {
            $broken_links = array_filter($broken_links, function($link) use ($post_type_filter) {
                $post = get_post($link['post_id']);
                return $post && $post->post_type === $post_type_filter;
            });
        }

        $total_items = count($broken_links);
        $total_pages = ceil($total_items / $items_per_page);
        $broken_links = array_slice($broken_links, $offset, $items_per_page);
        
    
        $scan_message = get_option('linkmaster_scan_message', '');
    
        if ($current_page > $total_pages) $current_page = $total_pages;
    
        ?>
        <div class="wrap linkmaster-wrap">
            <h1 class="linkmaster-title"><?php esc_html_e('Broken Links Scanner', 'linkmaster'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=linkmaster-broken-links&tab=broken-links" class="nav-tab <?php echo $active_tab == 'broken-links' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Broken Links', 'linkmaster'); ?>
                </a>
                <a href="?page=linkmaster-broken-links&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Scanner Settings', 'linkmaster'); ?>
                </a>
            </h2>

            <?php if ($active_tab == 'settings'): ?>
            <div class="linkmaster-section linkmaster-scanner-settings">
                <h2 class="section-title"><?php esc_html_e('Scanner Settings', 'linkmaster'); ?></h2>
                
                <?php
                // Check if WordPress cron is disabled
                if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                    ?>
                    <div class="notice notice-warning">
                        <p>
                            <strong><?php esc_html_e('WordPress Cron is disabled!', 'linkmaster'); ?></strong>
                            <?php 
                            echo sprintf(
                                /* translators: %s: Documentation URL */
                                esc_html__('Scheduled scans may not run automatically. Please either enable WordPress Cron or set up an external cron job to call %s. Learn more about setting up external cron jobs in the WordPress documentation.', 'linkmaster'),
                                '<code>wp-cron.php</code>'
                            ); 
                            ?>
                        </p>
                        <p>
                            <?php esc_html_e('Recommended external cron job command:', 'linkmaster'); ?><br>
                            <code>wget -q -O /dev/null '<?php echo esc_url(site_url('wp-cron.php?doing_wp_cron')); ?>' >/dev/null 2>&1</code>
                        </p>
                    </div>
                    <?php
                }
                ?>

                <form method="post" id="linkmaster-scanner-settings" class="linkmaster-settings-form">
                    <?php wp_nonce_field('linkmaster_scanner_options', 'nonce'); ?>
                    <input type="hidden" name="action" value="linkmaster_save_scanner_options">
                    
                    <script type="text/javascript">
                    var formSubmissionInProgress = false;
                    
                    function handleFrequencyChange(frequency) {
                        console.log('Inline handler - Frequency changed to:', frequency);
                        
                        // Hide all frequency option divs first
                        var options = document.querySelectorAll('.frequency-options, .linkmaster-weekly-options, .linkmaster-monthly-options');
                        for (var i = 0; i < options.length; i++) {
                            options[i].style.display = 'none';
                        }
                        
                        // Disable all day selects to prevent them from being submitted
                        document.getElementById('weekly_day_select').disabled = true;
                        document.getElementById('monthly_day_select').disabled = true;
                        
                        // Show the appropriate options based on selection and enable the correct select
                        if (frequency === 'weekly') {
                            var weeklyOptions = document.querySelectorAll('#weekly-options, .linkmaster-weekly-options');
                            for (var i = 0; i < weeklyOptions.length; i++) {
                                weeklyOptions[i].style.display = '';
                            }
                            document.getElementById('weekly_day_select').disabled = false;
                        } else if (frequency === 'monthly') {
                            var monthlyOptions = document.querySelectorAll('#monthly-options, .linkmaster-monthly-options');
                            for (var i = 0; i < monthlyOptions.length; i++) {
                                monthlyOptions[i].style.display = '';
                            }
                            document.getElementById('monthly_day_select').disabled = false;
                        }
                    }
                    
                    function showNotice(type, message, form) {
                        // Remove any existing notices
                        var existingNotices = document.querySelectorAll('.notice');
                        for (var i = 0; i < existingNotices.length; i++) {
                            existingNotices[i].remove();
                        }
                        
                        // Show message
                        var notice = document.createElement('div');
                        notice.className = 'notice notice-' + type + ' is-dismissible';
                        notice.innerHTML = '<p>' + message + '</p>' +
                            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
                        form.parentNode.insertBefore(notice, form);
                        
                        // Add dismiss functionality
                        notice.querySelector('.notice-dismiss').addEventListener('click', function() {
                            notice.remove();
                        });
                    }
                    
                    // Initialize on page load
                    document.addEventListener('DOMContentLoaded', function() {
                        var frequencySelect = document.querySelector('#scan-frequency, .linkmaster-frequency-selector');
                        if (frequencySelect) {
                            handleFrequencyChange(frequencySelect.value);
                            
                            // Add event listener to the frequency selector
                            frequencySelect.addEventListener('change', function() {
                                handleFrequencyChange(this.value);
                            });
                        }
                        
                        // Also handle form submission
                        var form = document.getElementById('linkmaster-scanner-settings');
                        if (form) {
                            form.addEventListener('submit', function(e) {
                                e.preventDefault();
                                
                                // Prevent multiple submissions
                                if (formSubmissionInProgress) {
                                    console.log('Form submission already in progress, ignoring duplicate submission');
                                    return;
                                }
                                
                                formSubmissionInProgress = true;
                                var submitButton = form.querySelector('input[type="submit"]');
                                if (submitButton) {
                                    submitButton.disabled = true;
                                    submitButton.value = 'Saving...';
                                }
                                
                                // Create a new FormData object
                                var formData = new FormData(form);
                                
                                // Ensure the correct day selector is enabled based on frequency
                                var frequency = document.querySelector('#scan-frequency').value;
                                if (frequency === 'weekly') {
                                    document.getElementById('weekly_day_select').disabled = false;
                                    document.getElementById('monthly_day_select').disabled = true;
                                } else if (frequency === 'monthly') {
                                    document.getElementById('weekly_day_select').disabled = true;
                                    document.getElementById('monthly_day_select').disabled = false;
                                }
                                
                                var xhr = new XMLHttpRequest();
                                xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>', true);
                                xhr.onload = function() {
                                    formSubmissionInProgress = false;
                                    if (submitButton) {
                                        submitButton.disabled = false;
                                        submitButton.value = 'Save Settings';
                                    }
                                    
                                    if (xhr.status >= 200 && xhr.status < 400) {
                                        try {
                                            var response = JSON.parse(xhr.responseText);
                                            if (response.success) {
                                                showNotice('success', response.data.message, form);
                                                
                                                // Update next scan time if available
                                                if (response.data.next_scan) {
                                                    var nextScanElements = document.querySelectorAll('.next-scan-info');
                                                    for (var i = 0; i < nextScanElements.length; i++) {
                                                        nextScanElements[i].textContent = 'Next scheduled scan: ' + response.data.next_scan;
                                                    }
                                                }
                                            } else {
                                                showNotice('error', (response.data ? response.data.message : 'Error saving settings'), form);
                                            }
                                        } catch (e) {
                                            console.error('Error parsing JSON response:', e);
                                            showNotice('error', 'Error processing server response', form);
                                        }
                                    } else {
                                        showNotice('error', 'Server error: ' + xhr.status, form);
                                    }
                                };
                                
                                xhr.onerror = function() {
                                    formSubmissionInProgress = false;
                                    if (submitButton) {
                                        submitButton.disabled = false;
                                        submitButton.value = 'Save Settings';
                                    }
                                    showNotice('error', 'Network error occurred', form);
                                };
                                
                                xhr.send(formData);
                            });
                        }
                    });
                    </script>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Scheduled Scans', 'linkmaster'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="linkmaster_scanner_options[enable_scheduled_scans]" value="1" 
                                           <?php checked(isset($scanner_options['enable_scheduled_scans']) ? $scanner_options['enable_scheduled_scans'] : true); ?>>
                                    <?php esc_html_e('Automatically scan for broken links on schedule', 'linkmaster'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr class="scheduled-scan-options" <?php echo (!isset($scanner_options['enable_scheduled_scans']) || $scanner_options['enable_scheduled_scans']) ? '' : 'style="display:none;"'; ?>>
                            <th scope="row"><?php esc_html_e('Scan Frequency', 'linkmaster'); ?></th>
                            <td>
                                <select name="linkmaster_scanner_options[frequency]" id="scan-frequency" class="linkmaster-frequency-selector" onchange="handleFrequencyChange(this.value)">
                                    <option value="daily" <?php selected($scanner_options['frequency'], 'daily'); ?>>
                                        <?php esc_html_e('Daily', 'linkmaster'); ?>
                                    </option>
                                    <option value="weekly" <?php selected($scanner_options['frequency'], 'weekly'); ?>>
                                        <?php esc_html_e('Weekly', 'linkmaster'); ?>
                                    </option>
                                    <option value="monthly" <?php selected($scanner_options['frequency'], 'monthly'); ?>>
                                        <?php esc_html_e('Monthly', 'linkmaster'); ?>
                                    </option>
                                </select>

                                <div id="weekly-options" class="frequency-options linkmaster-weekly-options" <?php echo $scanner_options['frequency'] === 'weekly' ? '' : 'style="display:none;"'; ?>>
                                    <select name="linkmaster_scanner_options[frequency_day]" class="margin-left" id="weekly_day_select">
                                        <?php
                                        $days = array(
                                            'monday' => __('Monday', 'linkmaster'),
                                            'tuesday' => __('Tuesday', 'linkmaster'),
                                            'wednesday' => __('Wednesday', 'linkmaster'),
                                            'thursday' => __('Thursday', 'linkmaster'),
                                            'friday' => __('Friday', 'linkmaster'),
                                            'saturday' => __('Saturday', 'linkmaster'),
                                            'sunday' => __('Sunday', 'linkmaster')
                                        );
                                        foreach ($days as $value => $label) {
                                            printf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($value),
                                                selected($scanner_options['frequency_day'], $value, false),
                                                esc_html($label)
                                            );
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div id="monthly-options" class="frequency-options linkmaster-monthly-options" <?php echo $scanner_options['frequency'] === 'monthly' ? '' : 'style="display:none;"'; ?>>
                                    <select name="linkmaster_scanner_options[frequency_day]" class="margin-left" id="monthly_day_select">
                                        <?php
                                        for ($i = 1; $i <= 28; $i++) {
                                            printf(
                                                '<option value="%d" %s>%s</option>',
                                                $i,
                                                selected($scanner_options['frequency_day'], (string)$i, false),
                                                sprintf(__('Day %d', 'linkmaster'), $i)
                                            );
                                        }
                                        ?>
                                    </select>
                                </div>

                                <input type="time" name="linkmaster_scanner_options[frequency_time]" 
                                       value="<?php echo esc_attr(isset($scanner_options['frequency_time']) ? $scanner_options['frequency_time'] : '00:00'); ?>" 
                                       class="margin-left">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Last Scan', 'linkmaster'); ?></th>
                            <td>
                                <?php 
                                if (!empty($scanner_options['last_scan'])) {
                                    echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($scanner_options['last_scan'])));
                                } else {
                                    esc_html_e('Never', 'linkmaster');
                                }
                                ?>
                            </td>
                        </tr>
                        <tr class="scheduled-scan-options" <?php echo (!isset($scanner_options['enable_scheduled_scans']) || $scanner_options['enable_scheduled_scans']) ? '' : 'style="display:none;"'; ?>>
                            <th scope="row"><?php esc_html_e('Next Scheduled Scan', 'linkmaster'); ?></th>
                            <td>
                                <?php 
                                if (!empty($scanner_options['next_scan']) && (!isset($scanner_options['enable_scheduled_scans']) || $scanner_options['enable_scheduled_scans'])) {
                                    echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($scanner_options['next_scan'])));
                                } else {
                                    esc_html_e('Not scheduled', 'linkmaster');
                                }
                                ?>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Settings', 'linkmaster')); ?>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($active_tab == 'broken-links'): ?>
            <!-- Scan Status Card -->
            <div class="linkmaster-card">
                <h2 class="card-title"><?php esc_html_e('Scan Status', 'linkmaster'); ?></h2>
                <p class="card-subtitle">
                    <?php if ($last_scan): ?>
                        <?php printf(
                            esc_html__('Last scan: %s', 'linkmaster'),
                            esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_scan)))
                        ); ?>
                    <?php else: ?>
                        <?php esc_html_e('No scans performed yet.', 'linkmaster'); ?>
                    <?php endif; ?>
                </p>
                <div id="scan-progress" style="display: none; margin-bottom: 15px;">
                </div>
                <div id="scan-warning" class="scan-warning notice notice-warning" style="display: none; margin: 10px 0;">
                    <p><strong><?php esc_html_e('Warning:', 'linkmaster'); ?></strong> <?php esc_html_e('Please do not refresh or leave this page until the scan completes to ensure accurate results.', 'linkmaster'); ?></p>
                </div>
                <div class="scan-buttons">
                    <button type="button" id="scan-links" class="button linkmaster-button">
                        <?php esc_html_e('Scan Now', 'linkmaster'); ?>
                    </button>
                    <button type="button" id="cancel-scan" class="button linkmaster-button-delete" style="display: none;">
                        <?php esc_html_e('Cancel Scan', 'linkmaster'); ?>
                    </button>
                    <span class="spinner" style="float: none; margin-top: 4px;"></span>
                </div>
            </div>
    
            <!-- Scan Completion Notice -->
            <?php if (!empty($scan_message)) : ?>
                <div id="linkmaster-notice" class="notice notice-info is-dismissible">
                    <p><?php echo esc_html($scan_message); ?></p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text"><?php esc_html_e('Dismiss this notice.', 'linkmaster'); ?></span>
                    </button>
                </div>
                <?php update_option('linkmaster_scan_message', ''); ?>
            <?php endif; ?>
    
            <!-- Broken Links Report -->
            <div class="linkmaster-section" id="broken-links-table">
                <h2 class="card-title"><?php esc_html_e('Broken Links Report', 'linkmaster'); ?></h2>
    
                <?php if (empty($all_broken_links)): ?>
                    <p class="linkmaster-empty-state"><?php esc_html_e('No broken links found.', 'linkmaster'); ?></p>
                <?php else: ?>
                    <!-- Filters and Actions -->
                    <form method="get" class="linkmaster-tablenav top">
                        <input type="hidden" name="page" value="linkmaster-broken-links">
                        <div class="linkmaster-actions">
                            <select name="bulk-action" id="bulk-action-selector-top" class="linkmaster-select">
                                <option value="-1"><?php esc_html_e('Bulk Actions', 'linkmaster'); ?></option>
                                <option value="unlink"><?php esc_html_e('Unlink', 'linkmaster'); ?></option>
                            </select>
                            <button type="button" id="doaction" class="button linkmaster-button-secondary">
                                <?php esc_html_e('Apply', 'linkmaster'); ?>
                            </button>
                        </div>
                        <div class="linkmaster-actions">
                            <select name="status_filter" id="status-filter" class="linkmaster-select">
                                <option value=""><?php esc_html_e('All Status Types', 'linkmaster'); ?></option>
                                <?php foreach ($this->get_all_status_types($all_broken_links) as $status): ?>
                                    <option value="<?php echo esc_attr($status); ?>" <?php selected($status_filter, $status); ?>>
                                        <?php 
                                        $label = $this->get_status_label($status);
                                        // For numeric status codes, show the code and description separately
                                        if (is_numeric($status)) {
                                            echo esc_html($label . ' (' . $status . ')'); 
                                        } else {
                                            echo esc_html($label);
                                        }
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="post_type_filter" id="post-type-filter" class="linkmaster-select">
                                <option value=""><?php esc_html_e('All Content Types', 'linkmaster'); ?></option>
                                <?php foreach ($post_types_with_broken_links as $type): ?>
                                    <?php $post_type_obj = get_post_type_object($type); ?>
                                    <?php if ($post_type_obj): ?>
                                        <option value="<?php echo esc_attr($type); ?>" <?php selected($post_type_filter, $type); ?>>
                                            <?php echo esc_html($post_type_obj->labels->singular_name); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button linkmaster-button-secondary">
                                <?php esc_html_e('Filter', 'linkmaster'); ?>
                            </button>
                            <?php if (!empty($status_filter) || !empty($post_type_filter)): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=linkmaster-broken-links')); ?>" class="button linkmaster-button-secondary">
                                    <?php esc_html_e('Clear Filter', 'linkmaster'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="linkmaster-actions">
                            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=linkmaster_export_csv'), 'linkmaster_export_csv', 'nonce'); ?>" class="button linkmaster-button-secondary">
                                <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                                <?php esc_html_e('Export to CSV', 'linkmaster'); ?>
                            </a>
                        </div>
                    </form>
    
                    <!-- Broken Links Table -->
                    <table class="linkmaster-table wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-cb check-column">
                                    <input type="checkbox" id="cb-select-all">
                                </th>
                                <th scope="col"><?php esc_html_e('Page', 'linkmaster'); ?></th>
                                <th scope="col"><?php esc_html_e('Link', 'linkmaster'); ?></th>
                                <th scope="col"><?php esc_html_e('Anchor Text', 'linkmaster'); ?></th>
                                <th scope="col"><?php esc_html_e('Status', 'linkmaster'); ?></th>
                                <th scope="col"><?php esc_html_e('Actions', 'linkmaster'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($broken_links)): ?>
                                <tr>
                                    <td colspan="6" class="linkmaster-empty-state">
                                        <?php esc_html_e('No broken links found for the selected filters.', 'linkmaster'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($broken_links as $link): ?>
                                    <tr>
                                        <th scope="row" class="check-column">
                                            <input type="checkbox" class="link-cb" 
                                                   data-post-id="<?php echo esc_attr($link['post_id']); ?>"
                                                   data-url="<?php echo esc_attr($link['url']); ?>">
                                        </th>
                                        <td>
                                            <a href="<?php echo esc_url(get_edit_post_link($link['post_id'])); ?>" target="_blank" class="linkmaster-page-link">
                                                <?php echo esc_html(get_the_title($link['post_id'])); ?>
                                            </a>
                                            <div class="row-actions">
                                                <?php 
                                                $post_type = get_post_type($link['post_id']);
                                                $post_type_obj = get_post_type_object($post_type);
                                                ?>
                                                <span class="post-type">
                                                    <?php echo esc_html($post_type_obj->labels->singular_name); ?> | 
                                                </span>
                                                <a href="<?php echo esc_url(get_permalink($link['post_id'])); ?>" target="_blank">
                                                    <?php esc_html_e('View', 'linkmaster'); ?>
                                                </a>
                                            </div>
                                        </td>
                                        <td class="link-url-cell">
                                            <span class="link-display"><?php echo esc_html($link['url']); ?></span>
                                            <div class="link-edit" style="display: none;">
                                                <input type="text" class="widefat new-link-url" value="<?php echo esc_attr($link['url']); ?>">
                                                <div class="link-edit-actions">
                                                    <button type="button" class="button linkmaster-button save-link">
                                                        <?php esc_html_e('Save', 'linkmaster'); ?>
                                                    </button>
                                                    <button type="button" class="button linkmaster-button-secondary cancel-edit">
                                                        <?php esc_html_e('Cancel', 'linkmaster'); ?>
                                                    </button>
                                                    <button class="copy-url button linkmaster-button-secondary" data-url="<?php echo esc_attr($link['url']); ?>">
                                                        <span class="dashicons dashicons-clipboard"></span>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $anchor_text = $link['anchor_text'];
                                            if (strpos($anchor_text, '!IMAGE') === 0) {
                                                echo '<span class="dashicons dashicons-format-image" style="margin-right: 5px;"></span>' . esc_html('IMAGE');
                                            } else {
                                                echo esc_html($anchor_text);
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo esc_attr($this->get_status_class($link['status'])); ?>">
                                                <?php echo $this->get_status_label($link['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="button linkmaster-button-edit edit-link">
                                                <?php esc_html_e('Edit Link', 'linkmaster'); ?>
                                            </button>
                                            <button type="button" class="button linkmaster-button-delete unlink-button" 
                                                    data-post-id="<?php echo esc_attr($link['post_id']); ?>"
                                                    data-url="<?php echo esc_attr($link['url']); ?>">
                                                <?php esc_html_e('Unlink', 'linkmaster'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
    
                    <!-- Bottom Pagination and Actions -->
                    <?php if (!empty($broken_links)): ?>
                        <div class="linkmaster-tablenav bottom">
                            <div class="linkmaster-actions">
                                <select name="bulk-action2" id="bulk-action-selector-bottom" class="linkmaster-select">
                                    <option value="-1"><?php esc_html_e('Bulk Actions', 'linkmaster'); ?></option>
                                    <option value="unlink"><?php esc_html_e('Unlink', 'linkmaster'); ?></option>
                                </select>
                                <button type="button" id="doaction2" class="button linkmaster-button-secondary">
                                    <?php esc_html_e('Apply', 'linkmaster'); ?>
                                </button>
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
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            </div>
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
                padding: 10px 0;
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
            .linkmaster-select {
                height: 32px;
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
                padding: 12px;
                vertical-align: middle;
                border-bottom: 1px solid #e5e7eb;
            }
            .linkmaster-table th {
                background: #f6f7f7;
                font-weight: 600;
                color: #1d2327;
            }
            .linkmaster-table tr:nth-child(even) {
                background-color: #f9fafb;
            }
            .linkmaster-table tr:hover {
                background-color: #f0f6fc;
            }
            .linkmaster-empty-state {
                text-align: center;
                padding: 40px;
                color: #6b7280;
            }

            /* Link Editing */
            .link-url-cell .link-display {
                word-break: break-all;
            }
            .link-edit {
                margin-top: 5px;
            }
            .link-edit-actions {
                margin-top: 5px;
                display: flex;
                gap: 5px;
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
            .linkmaster-button-delete {
                background: #fff;
                color: #d94f4f;
                border: 1px solid #d94f4f;
            }
            .linkmaster-button-delete:hover {
                background: #fef2f2;
                border-color: #b32d2e;
                color: #b32d2e;
            }
            .copy-url {
                padding: 5px;
                min-width: 32px;
            }
            .copy-url.copied {
                background-color: #46b450;
                border-color: #46b450;
                color: white;
            }

            /* Status Badges */
            .status-badge {
                display: inline;
                font-weight: 500;
            }
            
            .status-badge.error,
            .status-badge.server-error {
                color: #dc3545;
            }
            
            .status-badge.client-error {
                color: #e65100;
            }
            
            .status-badge.redirect {
                color: #17a2b8;
            }
            
            .status-badge.success {
                color: #28a745;
            }
            
            .status-badge.unknown {
                color: #6c757d;
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

            /* Dashicons */
            .dashicons {
                vertical-align: middle;
                font-size: 18px;
            }
        </style>
        <?php
    }

    public function ajax_copy_url() {
        // This method is not implemented in the provided code.
        // Assuming it's intended to handle copying URLs to clipboard via AJAX,
        // here's a basic implementation:
        try {
            check_ajax_referer('linkmaster_update_link', 'nonce');
            if (!current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => 'Unauthorized access'));
            }
            $url = isset($_POST['url']) ? sanitize_text_field($_POST['url']) : '';
            if (empty($url)) {
                throw new Exception('No URL provided');
            }
            wp_send_json_success(array('message' => 'URL copied to clipboard', 'url' => $url));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}