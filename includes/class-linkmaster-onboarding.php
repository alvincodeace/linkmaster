<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LinkMaster Onboarding
 * 
 * Handles the guided tour for first-time users
 */
class LinkMaster_Onboarding {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_footer', array($this, 'add_tour_content'));
        add_action('wp_ajax_linkmaster_dismiss_tour', array($this, 'dismiss_tour'));
    }
    
    /**
     * Enqueue necessary scripts and styles for the guided tour
     */
    public function enqueue_scripts($hook) {
        // Debug logging
        error_log('LinkMaster Onboarding - Hook: ' . $hook);
        
        // Check if we're on the main LinkMaster page
        if ($hook === 'toplevel_page_linkmaster' || $hook === 'linkmaster_page_linkmaster-dashboard') {
            // Check if the tour has been dismissed
            $user_id = get_current_user_id();
            $dismissed = get_user_meta($user_id, 'linkmaster_tour_dismissed', true);
            
            error_log('LinkMaster Onboarding - User ID: ' . $user_id . ', Dismissed: ' . ($dismissed ? 'yes' : 'no'));
            
            if (!$dismissed) {
                error_log('LinkMaster Onboarding - Loading tour assets');
                
                // Enqueue Intro.js for the guided tour
                wp_enqueue_style(
                    'introjs',
                    'https://cdnjs.cloudflare.com/ajax/libs/intro.js/5.1.0/introjs.min.css',
                    array(),
                    '5.1.0'
                );
                
                wp_enqueue_script(
                    'introjs',
                    'https://cdnjs.cloudflare.com/ajax/libs/intro.js/5.1.0/intro.min.js',
                    array('jquery'),
                    '5.1.0',
                    true
                );
                
                // Enqueue our custom tour script
                wp_enqueue_script(
                    'linkmaster-tour',
                    plugins_url('/js/tour.js', dirname(__FILE__)),
                    array('jquery', 'introjs'),
                    LINKMASTER_VERSION,
                    true
                );
                
                // Add custom styles for the tour
                wp_enqueue_style(
                    'linkmaster-tour',
                    plugins_url('/css/tour.css', dirname(__FILE__)),
                    array('introjs'),
                    LINKMASTER_VERSION
                );
                
                // Pass data to the script
                wp_localize_script('linkmaster-tour', 'linkmasterTour', array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('linkmaster_tour_nonce'),
                    'startTour' => true
                ));
            }
        }
    }
    
    /**
     * Add the tour steps content
     */
    public function add_tour_content() {
        $screen = get_current_screen();
        
        // Only add on LinkMaster main page or dashboard
        if (!$screen || ($screen->id !== 'toplevel_page_linkmaster' && $screen->id !== 'linkmaster_page_linkmaster-dashboard')) {
            return;
        }
        
        // Check if the tour has been dismissed
        $user_id = get_current_user_id();
        $dismissed = get_user_meta($user_id, 'linkmaster_tour_dismissed', true);
        
        if ($dismissed) {
            return;
        }
        
        // Add hidden tour content that will be shown by Intro.js
        ?>
        <div class="linkmaster-tour-overlay" style="display: none;"></div>
        
        <!-- Tour welcome modal -->
        <div id="linkmaster-tour-welcome" class="linkmaster-tour-modal" style="display: none;">
            <div class="linkmaster-tour-modal-content">
                <h2><?php esc_html_e('Welcome to LinkMaster!', 'linkmaster'); ?></h2>
                <p><?php esc_html_e('Would you like a quick tour of the main features?', 'linkmaster'); ?></p>
                <div class="linkmaster-tour-modal-buttons">
                    <button id="linkmaster-start-tour" class="button button-primary"><?php esc_html_e('Yes, show me around', 'linkmaster'); ?></button>
                    <button id="linkmaster-skip-tour" class="button"><?php esc_html_e('Skip for now', 'linkmaster'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler to dismiss the tour
     */
    public function dismiss_tour() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'linkmaster_tour_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Save user preference
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'linkmaster_tour_dismissed', true);
        
        wp_send_json_success();
    }
    
    /**
     * Reset the tour for a user (for testing)
     */
    public static function reset_tour($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        delete_user_meta($user_id, 'linkmaster_tour_dismissed');
    }
}

// Initialize the onboarding class
LinkMaster_Onboarding::get_instance();
