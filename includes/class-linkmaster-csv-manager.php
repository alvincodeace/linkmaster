<?php
// includes/class-linkmaster-csv-manager.php
if (!defined('ABSPATH')) {
    exit;
}

// Start output buffering as early as possible
if (isset($_POST['import_csv']) || isset($_POST['export_csv']) || 
    (isset($_GET['action']) && ($_GET['action'] === 'linkmaster_download_redirects_sample_csv' || 
                               $_GET['action'] === 'linkmaster_download_permalinks_sample_csv'))) {
    // Suppress all errors and warnings
    error_reporting(0);
    @ini_set('display_errors', 0);
    
    // Clean any existing output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start fresh buffer
    ob_start();
}

class LinkMaster_CSV_Manager {
    private $permalinks;
    private $redirector;

    public function __construct() {
        $this->permalinks = LinkMaster_Custom_Permalinks::get_instance();
        $this->redirector = LinkMaster_Link_Redirector_Admin::get_instance();
        
        // Add AJAX action for sample CSV download
        add_action('wp_ajax_linkmaster_download_redirects_sample_csv', array($this, 'download_redirects_sample_csv'));
    }
    



    // Export Permalinks
    public function export_permalinks_csv() {
        try {
            // Start output buffering to catch any unexpected output
            ob_start();
            
            if (!current_user_can('edit_posts') || !check_admin_referer('linkmaster_export_permalinks_csv', 'linkmaster_export_nonce')) {
                throw new Exception('Security check failed.');
            }

            $posts = get_posts(array(
                'post_type' => 'any',
                'posts_per_page' => -1,
                'meta_key' => '_lmcp_custom_permalink',
                'fields' => 'ids',
            ));

            if (empty($posts)) {
                throw new Exception('No permalinks to export.');
            }
            
            // Prepare the CSV data
            $csv_data = [];
            
            // Add CSV headers
            $csv_data[] = ['type', 'source_url', 'post_title'];
            
            // Add permalinks data
            foreach ($posts as $post_id) {
                $permalink = get_post_meta($post_id, '_lmcp_custom_permalink', true);
                if ($permalink) {
                    $post = get_post($post_id);
                    $csv_data[] = ['permalink', $permalink, $post->post_title, ''];
                }
            }
            
            // Clear all output buffers to prevent HTML output
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Generate a filename with timestamp
            $filename = 'linkmaster_permalinks_export_' . date('Y-m-d_H-i-s') . '.csv';
            
            // Set headers for CSV download
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Disable any PHP error reporting to prevent header issues
            error_reporting(0);
            
            // Open output stream
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new Exception('Failed to open output stream.');
            }
            
            // Write CSV data
            foreach ($csv_data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit;
            
        } catch (Exception $e) {
            // Clean any output buffer
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Store error message
            set_transient('linkmaster_permalinks_error', $e->getMessage(), 30);
            
            // Redirect back to the admin page
            wp_redirect(add_query_arg('page', 'linkmaster-custom-permalinks', admin_url('admin.php')));
            exit;
        }
    }

    // Export Redirects
    public function export_redirects_csv() {
        try {
            // Start output buffering to catch any unexpected output
            ob_start();
            
            if (!current_user_can('edit_posts') || !check_admin_referer('linkmaster_export_redirects_csv', 'linkmaster_export_nonce')) {
                throw new Exception('Security check failed.');
            }

            // Use the public fetch_redirects() method instead of private get_redirects()
            $redirects = $this->redirector->fetch_redirects();
            if (empty($redirects)) {
                throw new Exception('No redirects to export.');
            }
            
            // Prepare the CSV data
            $csv_data = [];
            
            // Add CSV headers
            $csv_data[] = [
                'type',
                'source_url',
                'target_url',
                'redirect_type',
                'expiration_date',
                'nofollow',
                'sponsored'
            ];
            
            // Add redirects data
            foreach ($redirects as $id => $redirect) {
                $csv_data[] = [
                    'redirect',
                    $redirect['source_url'],
                    $redirect['target_url'],
                    $redirect['redirect_type'],
                    isset($redirect['expiration_date']) ? $redirect['expiration_date'] : '',
                    isset($redirect['nofollow']) ? ($redirect['nofollow'] ? 'Yes' : 'No') : 'No',
                    isset($redirect['sponsored']) ? ($redirect['sponsored'] ? 'Yes' : 'No') : 'No'
                ];
            }

            // Clear all output buffers to prevent HTML output
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Generate a filename with timestamp
            $filename = 'linkmaster_redirects_export_' . date('Y-m-d_H-i-s') . '.csv';

            // Set headers for CSV download
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            // Disable any PHP error reporting to prevent header issues
            error_reporting(0);

            // Open output stream
        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new Exception('Failed to open output stream.');
        }

        // Write CSV data
        foreach ($csv_data as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
            
        } catch (Exception $e) {
            // Clean any output buffer
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            
            // Store error message
            set_transient('linkmaster_redirects_error', $e->getMessage(), 30);
            
            // Redirect back to the admin page
            wp_redirect(add_query_arg('page', 'linkmaster-redirections', admin_url('admin.php')));
            exit;
        }
    }

    // Import Permalinks
    public function import_permalinks_csv() {
        // Start output buffering to prevent any accidental output
        ob_start();
        
        try {
            if (!current_user_can('edit_posts')) {
                throw new Exception('Unauthorized access.');
            }
            
            // Validate file upload
            if (!isset($_FILES['csv_file'])) {
                throw new Exception('No file was uploaded.');
            }
            
            // Check for upload errors
            if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $error_message = $this->get_file_upload_error_message($_FILES['csv_file']['error']);
                throw new Exception($error_message);
            }
            
            // Check file type
            $file_info = pathinfo($_FILES['csv_file']['name']);
            $extension = strtolower($file_info['extension'] ?? '');
            
            if ($extension !== 'csv') {
                throw new Exception('The uploaded file is not a CSV file. Please upload a valid CSV file.');
            }
            
            // Process the file
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, 'r');
            
            if ($handle === false) {
                throw new Exception('Failed to open CSV file.');
            }
            
            $header = fgetcsv($handle);
            $processed = 0;
            $invalid_rows = 0;
            
            // Validate header
            if (!$header || count($header) < 3) {
                fclose($handle);
                throw new Exception('Invalid CSV format. The CSV file must have at least 3 columns.');
            }
            
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) < 3) {
                    $invalid_rows++;
                    continue;
                }
                
                $type = sanitize_text_field($data[0]);
                $source_url = sanitize_text_field($data[1]);
                $target = sanitize_text_field($data[2]);
                
                if ($type === 'permalink') {
                    $posts = get_posts(array(
                        'post_type' => 'any',
                        'title' => $target,
                        'posts_per_page' => 1,
                        'post_status' => 'publish',
                    ));
                    if (!empty($posts) && $post = $posts[0]) {
                        update_post_meta($post->ID, '_lmcp_custom_permalink', $source_url);
                        $this->permalinks->register_rewrite_rules();
                        $processed++;
                    } else {
                        $invalid_rows++;
                    }
                } else {
                    $invalid_rows++;
                }
            }
            
            fclose($handle);
            
            // Set appropriate message
            if ($processed > 0) {
                flush_rewrite_rules();
                $message = sprintf(_n('%d permalink imported successfully.', '%d permalinks imported successfully.', $processed, 'linkmaster'), $processed);
                
                if ($invalid_rows > 0) {
                    $message .= ' ' . sprintf(_n('%d row was invalid or could not be processed.', '%d rows were invalid or could not be processed.', $invalid_rows, 'linkmaster'), $invalid_rows);
                }
                
                set_transient('linkmaster_permalinks_success', $message, 30);
            } else {
                throw new Exception('No valid permalinks found in the CSV file.');
            }
            
            // Clean output buffer
            ob_end_clean();
            
            // Redirect
            wp_redirect(add_query_arg('page', 'linkmaster-custom-permalinks', admin_url('admin.php')));
            exit;
            
        } catch (Exception $e) {
            // Clean output buffer
            ob_end_clean();
            
            // Store error message
            set_transient('linkmaster_permalinks_error', $e->getMessage(), 30);
            
            // Redirect
            wp_redirect(add_query_arg('page', 'linkmaster-custom-permalinks', admin_url('admin.php')));
            exit;
        }
    }

    /**
     * Helper method to get human-readable file upload error messages
     *
     * @param int $error_code PHP file upload error code
     * @return string Human-readable error message
     */
    private function get_file_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload.';
            default:
                return 'Unknown upload error.';
        }
    }

    // Import Redirects
    public function import_redirects_csv() {
        // Start output buffering to prevent any accidental output
        ob_start();
        
        try {
            if (!current_user_can('edit_posts')) {
                throw new Exception('Unauthorized access.');
            }
            
            // Validate file upload
            if (!isset($_FILES['csv_file'])) {
                throw new Exception('No file was uploaded.');
            }
            
            // Check for upload errors
            if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $error_message = $this->get_file_upload_error_message($_FILES['csv_file']['error']);
                throw new Exception($error_message);
            }
            
            // Check file type
            $file_info = pathinfo($_FILES['csv_file']['name']);
            $extension = strtolower($file_info['extension'] ?? '');
            
            if ($extension !== 'csv') {
                throw new Exception('The uploaded file is not a CSV file. Please upload a valid CSV file.');
            }
            
            // Process the file
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, 'r');
            
            if ($handle === false) {
                throw new Exception('Failed to open CSV file.');
            }
            
            $header = fgetcsv($handle);
            // Use fetch_redirects() instead of get_redirects()
            $redirects = $this->redirector->fetch_redirects();
            $processed = 0;
            $invalid_rows = 0;
            
            // Validate header
            if (!$header || count($header) < 3) {
                fclose($handle);
                throw new Exception('Invalid CSV format. The CSV file must have at least 3 columns.');
            }
            
            while (($data = fgetcsv($handle)) !== false) {
                if (count($data) < 3 || empty($data[0]) || empty($data[1]) || empty($data[2])) {
                    $invalid_rows++;
                    continue;
                }
                
                $type = !empty($data[0]) ? sanitize_text_field($data[0]) : '';
                $source_url = !empty($data[1]) ? sanitize_text_field($data[1]) : '';
                $target = !empty($data[2]) ? sanitize_text_field($data[2]) : '';
                $redirect_type = !empty($data[3]) ? sanitize_text_field($data[3]) : '301';
                
                if ($type === 'redirect') {
                    // Check if a redirect with the same source URL already exists
                    $duplicate_found = false;
                    foreach ($redirects as $existing_redirect) {
                        if ($existing_redirect['source_url'] === $source_url) {
                            $duplicate_found = true;
                            $invalid_rows++;
                            break;
                        }
                    }

                    if (!$duplicate_found) {
                        $redirect_id = uniqid('redirect_');
                        $redirects[$redirect_id] = [
                            'source_url' => $source_url,
                            'target_url' => $target,
                            'redirect_type' => $redirect_type,
                            'hits' => 0,
                            'expiration_date' => !empty($data[4]) ? sanitize_text_field($data[4]) : '',
                            'nofollow' => !empty($data[5]) && strtolower(trim($data[5])) === 'yes',
                            'sponsored' => !empty($data[6]) && strtolower(trim($data[6])) === 'yes',
                            'status' => 'enabled'
                        ];
                        $processed++;
                    }
                } else {
                    $invalid_rows++;
                }
            }
            
            fclose($handle);
            
            // Set appropriate message
            if ($processed > 0) {
                update_option('linkmaster_redirects', $redirects);
                $message = sprintf(_n('%d redirect imported successfully.', '%d redirects imported successfully.', $processed, 'linkmaster'), $processed);
                
                if ($invalid_rows > 0) {
                    $message .= ' ' . sprintf(_n('%d row was invalid or could not be processed.', '%d rows were invalid or could not be processed.', $invalid_rows, 'linkmaster'), $invalid_rows);
                }
                
                set_transient('linkmaster_redirects_success', $message, 30);
            } else {
                throw new Exception('No valid redirects found in the CSV file.');
            }
            
            // Clean output buffer
            ob_end_clean();
            
            // Redirect
            wp_redirect(add_query_arg('page', 'linkmaster-redirections', admin_url('admin.php')));
            exit;
            
        } catch (Exception $e) {
            // Clean output buffer
            ob_end_clean();
            
            // Store error message
            set_transient('linkmaster_redirects_error', $e->getMessage(), 30);
            
            // Redirect
            wp_redirect(add_query_arg('page', 'linkmaster-redirections', admin_url('admin.php')));
            exit;
        }
    }

    // Sample CSV for Permalinks
    public function download_permalinks_sample_csv() {
        try {
            // Suppress all errors and warnings
            error_reporting(0);
            @ini_set('display_errors', 0);
            
            // Clean any existing output
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Prepare CSV data
            $csv_data = [];
            $csv_data[] = ['type', 'source_url', 'post_title'];
            $csv_data[] = ['permalink', '/sample-page/', 'Hello world!'];
            
            // Send headers
            nocache_headers(); // WordPress function to send no-cache headers
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="linkmaster_permalink_sample.csv"');
            
            // Output CSV data
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new Exception('Failed to open output stream.');
            }
            
            foreach ($csv_data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit;
            
        } catch (Exception $e) {
            // If something goes wrong, redirect back with error
            wp_redirect(add_query_arg(
                array(
                    'page' => 'linkmaster-custom-permalinks',
                    'error' => urlencode($e->getMessage())
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }

    // Sample CSV for Redirects
    public function download_redirects_sample_csv() {
        try {
            // Suppress all errors and warnings
            error_reporting(0);
            @ini_set('display_errors', 0);
            
            // Clean any existing output
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Prepare CSV data
            $csv_data = [];
            $csv_data[] = [
                'type',
                'source_url',
                'target_url',
                'redirect_type',
                'expiration_date',
                'nofollow',
                'sponsored'
            ];
            
            // Example with all fields
            $csv_data[] = [
                'redirect',
                'old-page',
                'https://example.com/new-page',
                '301',
                '2025-12-31 23:59:59',
                'Yes',
                'Yes'
            ];
            
            // Example without expiration and attributes
            $csv_data[] = [
                'redirect',
                'another-old-page',
                'https://example.com/another-new-page',
                '302',
                '',
                'No',
                'No'
            ];
            
            // Send headers
            nocache_headers(); // WordPress function to send no-cache headers
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="linkmaster_redirects_sample.csv"');
            
            // Output CSV data
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new Exception('Failed to open output stream.');
            }
            
            foreach ($csv_data as $row) {
                fputcsv($output, $row);
            }
            
            fclose($output);
            exit;
            
        } catch (Exception $e) {
            // If something goes wrong, redirect back with error
            wp_redirect(add_query_arg(
                array(
                    'page' => 'linkmaster-redirections',
                    'error' => urlencode($e->getMessage())
                ),
                admin_url('admin.php')
            ));
            exit;
        }
    }

    // Check Import Status for Permalinks
    public function check_permalinks_import_status() {
        if (isset($_GET['page']) && $_GET['page'] === 'linkmaster-custom-permalinks') {
            $this->display_import_status('linkmaster_permalinks');
        }
    }

    // Check Import Status for Redirects
    public function check_redirects_import_status() {
        if (isset($_GET['page']) && $_GET['page'] === 'linkmaster-redirections') {
            $this->display_import_status('linkmaster_redirects');
        }
    }

    // Helper to Display Import Status
    private function display_import_status($prefix) {
        $import_success = get_transient("{$prefix}_success");
        if ($import_success !== false) {
            add_settings_error(
                "{$prefix}_messages",
                'csv_import_success',
                sprintf(__('%d items imported successfully!', 'linkmaster'), $import_success),
                'updated'
            );
            delete_transient("{$prefix}_success");
        }

        $import_error = get_transient("{$prefix}_error");
        if ($import_error !== false) {
            add_settings_error(
                "{$prefix}_messages",
                'csv_import_error',
                $import_error,
                'error'
            );
            delete_transient("{$prefix}_error");
        }
    }
}

// Initialize and register hooks
function linkmaster_register_csv_manager() {
    $csv_manager = new LinkMaster_CSV_Manager();
    add_action('wp_ajax_linkmaster_download_permalinks_sample_csv', array($csv_manager, 'download_permalinks_sample_csv'));
    add_action('wp_ajax_linkmaster_download_redirects_sample_csv', array($csv_manager, 'download_redirects_sample_csv'));
    add_action('admin_init', array($csv_manager, 'check_permalinks_import_status'));
    add_action('admin_init', array($csv_manager, 'check_redirects_import_status'));
}
add_action('plugins_loaded', 'linkmaster_register_csv_manager', 20);