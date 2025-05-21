<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Health_Score {
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize the health score system
        add_action('wp_ajax_linkmaster_get_health_score', array($this, 'ajax_get_health_score'));
    }
    
    /**
     * Calculate the overall health score for all links on the site
     * 
     * @return array Health score data
     */
    public function get_health_score() {
        global $wpdb;
        
        $score_data = array(
            'total_links' => 0,
            'broken_links' => 0,
            'redirected_links' => 0,
            'healthy_links' => 0,
            'health_score' => 0,
            'top_issues' => array(),
            'score_breakdown' => array(
                'broken' => 0,
                'healthy' => 0
            )
        );
        
        // Get all unique links
        $all_links = $this->get_all_site_links();
        $unique_urls = array();
        
        // Extract unique URLs and count them
        foreach ($all_links as $link) {
            if (isset($link['url'])) {
                $unique_urls[$link['url']] = true;
            }
        }
        
        $total_content_links = count($unique_urls);
        
        // Get broken links and redirects
        $all_broken_links = get_option('linkmaster_broken_links', array());
        $broken_links_count = 0;
        $redirect_count = 0;
        
        // Track unique URLs to avoid counting duplicates
        $processed_urls = array();
        
        foreach ($all_broken_links as $link) {
            $url = isset($link['url']) ? $link['url'] : '';
            if (!empty($url) && !isset($processed_urls[$url])) {
                $processed_urls[$url] = true;
                
                // Check if status is a redirect (300-399)
                $status = isset($link['status']) ? $link['status'] : '';
                $status_code = intval($status);
                
                if ($status_code >= 300 && $status_code < 400) {
                    $redirect_count++;
                } else {
                    $broken_links_count++;
                }
            }
        }
        
        $score_data['broken_links'] = $broken_links_count;
        
        // Get redirected links count from redirect option (avoiding duplicates)
        $redirects = get_option('linkmaster_redirects', array());
        foreach ($redirects as $redirect) {
            $url = isset($redirect['source_url']) ? $redirect['source_url'] : '';
            if (!empty($url) && !isset($processed_urls[$url])) {
                $processed_urls[$url] = true;
                $redirect_count++;
            }
        }
        
        $score_data['redirected_links'] = $redirect_count;
        
        // Calculate total links as the sum of all types
        $score_data['total_links'] = $total_content_links;
        
        // Get the raw numbers
        $total = $score_data['total_links'];
        $broken = $score_data['broken_links'];
        $redirected = $score_data['redirected_links'];
        
        // Calculate direct healthy links (not broken or redirected)
        $direct_healthy = $total - $broken - $redirected;
        
        // Store the numbers
        // Healthy links includes both direct healthy and redirected links
        $score_data['healthy_links'] = $direct_healthy + $redirected;
        
        // Calculate percentages based on total links
        if ($total > 0) {
            // Only count truly broken links (not redirects) for broken percentage
            $broken_percent = ($broken / $total) * 100;
            $total_healthy_percent = (($direct_healthy + $redirected) / $total) * 100;
            
                // Log the raw numbers and percentages
            error_log(sprintf(
                'Links Breakdown:\n' .
                'Total Links: %d\n' .
                'Direct Healthy: %d\n' .
                'Redirected: %d\n' .
                'Broken: %d\n' .
                'Total Healthy (Direct + Redirected): %d', 
                $total,
                $direct_healthy,
                $redirected,
                $broken,
                $score_data['healthy_links']
            ));
            
            error_log(sprintf(
                'Percentages:\n' .
                'Direct Healthy: %.2f%%\n' .
                'Redirected: %.2f%%\n' .
                'Broken: %.2f%%', 
                ($direct_healthy / $total) * 100,
                ($redirected / $total) * 100,
                $broken_percent
            ));
            
            // Calculate total healthy percentage (direct healthy + redirected)
            $total_healthy_percent = (($direct_healthy + $redirected) / $total) * 100;
            
            // Log the percentages
            error_log(sprintf(
                'Final Percentages:\n' .
                'Total Healthy (Direct + Redirected): %.2f%%\n' .
                'Broken: %.2f%%\n' .
                'Sum: %.2f%%',
                $total_healthy_percent,
                $broken_percent,
                $total_healthy_percent + $broken_percent
            ));
            
            // Calculate health score with a balanced penalty for broken links
            $penalty_multiplier = 0.8; // Reduced penalty to avoid too harsh scores
            $base_score = $total_healthy_percent;
            $penalty = $broken_percent * $penalty_multiplier;
            $weighted_score = max(0, min(100, $base_score - $penalty));
            
            // Ensure minimum score if there are any healthy links
            if ($direct_healthy + $redirected > 0) {
                $weighted_score = max(1, $weighted_score);
            }
            
            $score_data['health_score'] = round($weighted_score);
            
            // Store the breakdown with just healthy (including redirected) vs broken
            $score_data['score_breakdown'] = array(
                'broken' => round($broken_percent),
                'healthy' => round($total_healthy_percent)  // Combined healthy and redirected
            );
            
            // Log the percentages
            error_log(sprintf(
                'Final Percentages:\n' .
                'Total Healthy (Direct + Redirected): %d%%\n' .
                'Broken: %d%%\n' .
                'Sum: %d%%',
                round($total_healthy_percent),
                round($broken_percent),
                round($total_healthy_percent + $broken_percent)
            ));
        } else {
            // If no links found, set sensible defaults
            $score_data['health_score'] = 100; // Perfect score if no links to check
            $score_data['broken_links'] = 0;
            $score_data['redirected_links'] = 0;
            $score_data['healthy_links'] = 0;
            $score_data['total_links'] = 0;
            $score_data['score_breakdown'] = array(
                'broken' => 0,
                'healthy' => 100
            );
        }
        
        // Get top issues
        if ($score_data['broken_links'] > 0) {
            // First try to get from the broken_links option which has more detailed information
            $all_broken_links = get_option('linkmaster_broken_links', array());
            $filtered_broken = array();
            
            // Filter out redirects
            foreach ($all_broken_links as $link) {
                $status = isset($link['status']) ? $link['status'] : '';
                $status_code = intval($status);
                
                // Only include actual broken links, not redirects
                if ($status_code < 300 || $status_code >= 400 || $status === 'error') {
                    $filtered_broken[] = $link;
                }
            }
            
            // Sort by date checked (most recent first)
            usort($filtered_broken, function($a, $b) {
                $date_a = isset($a['date_checked']) ? strtotime($a['date_checked']) : 0;
                $date_b = isset($b['date_checked']) ? strtotime($b['date_checked']) : 0;
                return $date_b - $date_a;
            });
            
            // Get top 3 issues
            $top_broken = array_slice($filtered_broken, 0, 3);
            
            foreach ($top_broken as $broken) {
                $post_id = isset($broken['post_id']) ? $broken['post_id'] : 0;
                $post_title = isset($broken['post_title']) ? $broken['post_title'] : '';
                
                // If we have a post ID but no title, try to get the title
                if ($post_id && empty($post_title)) {
                    $post_title = get_the_title($post_id);
                }
                
                // If still no title, use post type if available
                if (empty($post_title)) {
                    $post_type = get_post_type($post_id);
                    $post_title = $post_type ? ucfirst($post_type) : 'Unknown';
                }
                
                $score_data['top_issues'][] = array(
                    'type' => 'broken',
                    'url' => $broken['url'],
                    'source' => $post_title
                );
            }
        }
        
        return $score_data;
    }
    
    /**
     * Get all links from posts and pages
     * 
     * @return array All links found in the site content
     */
    private function get_all_site_links() {
        $links = array();
        
        // 1. Get all public post types
        $post_types = get_post_types(array('public' => true));
        
        // Get posts from all public post types
        $args = array(
            'post_type' => array_values($post_types),
            'post_status' => 'publish',
            'posts_per_page' => -1,
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                // Get post content
                $content = apply_filters('the_content', get_the_content());
                
                // Extract links from content
                preg_match_all('/<a\s[^>]*href=([\"\'])(.*?)\1[^>]*>/i', $content, $matches);
                if (!empty($matches[2])) {
                    foreach ($matches[2] as $url) {
                        if (!empty($url) && $url[0] !== '#') {
                            $links[] = array(
                                'url' => esc_url($url),
                                'post_id' => $post_id,
                                'post_title' => get_the_title(),
                                'source_type' => 'post'
                            );
                        }
                    }
                }
                
                // Get links from custom fields
                $custom_fields = get_post_custom($post_id);
                if ($custom_fields) {
                    foreach ($custom_fields as $field) {
                        foreach ($field as $value) {
                            if (is_string($value)) {
                                preg_match_all('/<a\s[^>]*href=([\"\'])(.*?)\1[^>]*>/i', $value, $matches);
                                if (!empty($matches[2])) {
                                    foreach ($matches[2] as $url) {
                                        if (!empty($url) && $url[0] !== '#') {
                                            $links[] = esc_url($url);
                                        }
                                    }
                                }
                                // Also check for raw URLs in custom fields
                                if (filter_var($value, FILTER_VALIDATE_URL)) {
                                    $links[] = array(
                                        'url' => esc_url($value),
                                        'post_id' => $post_id,
                                        'post_title' => get_the_title(),
                                        'source_type' => 'post'
                                    );
                                }
                            }
                        }
                    }
                }
            }
            wp_reset_postdata();
        }
        
        // 2. Get links from navigation menus
        $nav_menus = wp_get_nav_menus();
        if ($nav_menus) {
            foreach ($nav_menus as $menu) {
                $menu_items = wp_get_nav_menu_items($menu->term_id);
                if ($menu_items) {
                    foreach ($menu_items as $item) {
                        if (!empty($item->url)) {
                            $links[] = array(
                                'url' => esc_url($item->url),
                                'post_id' => $item->object_id,
                                'post_title' => $item->title,
                                'source_type' => 'menu'
                            );
                        }
                    }
                }
            }
        }
        
        // 3. Get links from active widgets
        $widget_areas = wp_get_sidebars_widgets();
        if ($widget_areas) {
            foreach ($widget_areas as $area => $widgets) {
                if (is_array($widgets) && !empty($widgets)) {
                    ob_start();
                    dynamic_sidebar($area);
                    $widget_content = ob_get_clean();
                    
                    preg_match_all('/<a\s[^>]*href=([\"\'])(.*?)\1[^>]*>/i', $widget_content, $matches);
                    if (!empty($matches[2])) {
                        foreach ($matches[2] as $url) {
                            if (!empty($url) && $url[0] !== '#') {
                                $links[] = array(
                                    'url' => esc_url($url),
                                    'source_type' => 'widget',
                                    'post_title' => 'Widget Area'
                                );
                            }
                        }
                    }
                }
            }
        }
        
        // 4. Get links from comments if enabled
        if (get_option('default_comment_status') === 'open') {
            $comments = get_comments(array('status' => 'approve'));
            if ($comments) {
                foreach ($comments as $comment) {
                    preg_match_all('/<a\s[^>]*href=([\"\'])(.*?)\1[^>]*>/i', $comment->comment_content, $matches);
                    if (!empty($matches[2])) {
                        foreach ($matches[2] as $url) {
                            if (!empty($url) && $url[0] !== '#') {
                                $links[] = array(
                                    'url' => esc_url($url),
                                    'source_type' => 'widget',
                                    'post_title' => 'Widget Area'
                                );
                            }
                        }
                    }
                }
            }
        }
        
        // 5. Get links from theme customizer
        $theme_mods = get_theme_mods();
        if ($theme_mods) {
            foreach ($theme_mods as $mod) {
                if (is_string($mod) && filter_var($mod, FILTER_VALIDATE_URL)) {
                    $links[] = array(
                        'url' => esc_url($mod),
                        'source_type' => 'theme',
                        'post_title' => 'Theme Customizer'
                    );
                }
            }
        }
        
        // Remove duplicate URLs while preserving the first occurrence's metadata
        $unique_links = array();
        foreach ($links as $link) {
            if (isset($link['url']) && !isset($unique_links[$link['url']])) {
                $unique_links[$link['url']] = $link;
            }
        }
        
        return array_values($unique_links);
    }
    
    /**
     * AJAX handler for getting health score data
     */
    public function ajax_get_health_score() {
        check_ajax_referer('linkmaster_health_score', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $score_data = $this->get_health_score();
        
        // Ensure we have all required fields with fallbacks
        $required_fields = array('total_links', 'broken_links', 'redirected_links', 'healthy_links', 'health_score');
        foreach ($required_fields as $field) {
            if (!isset($score_data[$field])) {
                $score_data[$field] = 0;
            }
        }
        
        // Ensure score breakdown exists
        if (!isset($score_data['score_breakdown']) || !is_array($score_data['score_breakdown'])) {
            $score_data['score_breakdown'] = array(
                'broken' => 0,
                'healthy' => 100
            );
        }
        
        // Get top issues for broken links using the same data source as the broken links admin page
        $all_broken_links = get_option('linkmaster_broken_links', array());
        
        if (!empty($all_broken_links)) {
            // Sort by most recent and get top 5
            $top_issues = array_slice($all_broken_links, 0, 5);
            $formatted_issues = array();
            
            foreach ($top_issues as $issue) {
                $post_id = isset($issue['post_id']) ? $issue['post_id'] : 0;
                $post_title = isset($issue['post_title']) ? $issue['post_title'] : '';
                
                // Get post information and type
                if ($post_id) {
                    $post = get_post($post_id);
                    if ($post) {
                        // Get post type label
                        $post_type_obj = get_post_type_object($post->post_type);
                        $type_label = $post_type_obj ? $post_type_obj->labels->singular_name : 'Content';
                        
                        // Get post title
                        $title = $post->post_title;
                        if (!empty($title)) {
                            $post_title = $type_label . ': ' . $title;
                        } else {
                            $post_title = $type_label;
                        }
                    }
                }
                
                // Handle menu items
                if (empty($post_title) && isset($issue['source_type']) && $issue['source_type'] === 'menu') {
                    $menu_title = isset($issue['post_title']) ? $issue['post_title'] : '';
                    $post_title = 'Menu: ' . ($menu_title ? $menu_title : 'Navigation Menu');
                }
                
                // Handle widget areas
                if (empty($post_title) && isset($issue['source_type']) && $issue['source_type'] === 'widget') {
                    $widget_title = isset($issue['post_title']) ? $issue['post_title'] : '';
                    $post_title = 'Widget: ' . ($widget_title ? $widget_title : 'Widget Area');
                }
                
                // Final fallback
                if (empty($post_title)) {
                    $post_title = 'Site Content';
                }
                
                $formatted_issues[] = array(
                    'url' => isset($issue['url']) ? $issue['url'] : '',
                    'source' => $post_title
                );
            }
            
            $score_data['top_issues'] = $formatted_issues;
        }
        
        wp_send_json_success($score_data);
    }
    
    /**
     * Get health score status based on score value
     * 
     * @param int $score Health score value
     * @return string Status (excellent, good, fair, poor)
     */
    public static function get_score_status($score) {
        if ($score >= 90) {
            return 'excellent';
        } elseif ($score >= 75) {
            return 'good';
        } elseif ($score >= 50) {
            return 'fair';
        } else {
            return 'poor';
        }
    }
}
