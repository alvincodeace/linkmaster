<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'list';
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Cloaked Links', 'linkmaster'); ?></h1>
    
    <?php 
    $show_notification = false;
    if (isset($_GET['imported']) || isset($_GET['skipped'])): 
        $show_notification = true;
    ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php
                $imported = isset($_GET['imported']) ? intval($_GET['imported']) : 0;
                $skipped = isset($_GET['skipped']) ? intval($_GET['skipped']) : 0;
                printf(
                    __('Import completed. %d links imported, %d skipped.', 'linkmaster'),
                    $imported,
                    $skipped
                );
                ?>
            </p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): 
        $show_notification = true;
    ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                $error = $_GET['error'];
                switch ($error) {
                    case 'import_file':
                        _e('Please select a valid CSV file to import.', 'linkmaster');
                        break;
                    case 'file_read':
                        _e('Could not read the import file.', 'linkmaster');
                        break;
                    case 'invalid_format':
                        _e('The CSV file format is invalid. Please use the sample CSV as a template.', 'linkmaster');
                        break;
                    default:
                        _e('An error occurred during import.', 'linkmaster');
                }
                ?>
            </p>
        </div>
    <?php endif; ?>
    
    <?php if ($show_notification): ?>
    <script>
        window.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.delete('imported');
            urlParams.delete('skipped');
            urlParams.delete('error');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '') + window.location.hash;
            window.history.replaceState({}, '', newUrl);
        });
    </script>
    <?php endif; ?>
    
    <nav class="nav-tab-wrapper">
        <a href="<?php echo remove_query_arg('tab'); ?>" class="nav-tab <?php echo $current_tab === 'list' ? 'nav-tab-active' : ''; ?>">
            <?php _e('All Links', 'linkmaster'); ?>
        </a>
        <a href="<?php echo add_query_arg('tab', 'add'); ?>" class="nav-tab <?php echo $current_tab === 'add' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Add New', 'linkmaster'); ?>
        </a>
        <a href="<?php echo add_query_arg('tab', 'settings'); ?>" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
            <?php _e('Settings', 'linkmaster'); ?>
        </a>
    </nav>
    
    <?php
    switch ($current_tab) {
        case 'add':
            $link_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            $link = $link_id ? $this->get_link($link_id) : null;
            ?>
            <form id="linkmaster-cloaked-form" class="linkmaster-cloaked-form" method="post">
                <input type="hidden" name="id" value="<?php echo $link_id; ?>">
                
                <table class="form-table">
                    <tr>
                        <th><label for="slug"><?php _e('Slug', 'linkmaster'); ?></label></th>
                        <td>
                            <input type="text" id="slug" name="slug" value="<?php echo esc_attr($link ? $link->slug : ''); ?>" required>
                            <p class="description">
                                <?php printf(__('Your link will be: %s/%s', 'linkmaster'), home_url($this->get_prefix()), '<span id="slug-preview">example</span>'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="destination_url"><?php _e('Destination URL', 'linkmaster'); ?></label></th>
                        <td>
                            <input type="url" id="destination_url" name="destination_url" value="<?php echo esc_url($link ? $link->destination_url : ''); ?>" required>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="redirect_type"><?php _e('Redirect Type', 'linkmaster'); ?></label></th>
                        <td>
                            <select id="redirect_type" name="redirect_type">
                                <option value="301" <?php selected($link ? $link->redirect_type : 302, 301); ?>>301 (Permanent)</option>
                                <option value="302" <?php selected($link ? $link->redirect_type : 302, 302); ?>>302 (Temporary)</option>
                                <option value="307" <?php selected($link ? $link->redirect_type : 302, 307); ?>>307 (Temporary)</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="password"><?php _e('Password Protection', 'linkmaster'); ?></label></th>
                        <td>
                            <input type="password" id="password" name="password" value="">
                            <?php if ($link && $link->password): ?>
                                <p class="description"><?php _e('Leave empty to keep the current password.', 'linkmaster'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <tr>
                        <th>
                            <label><?php _e('IP Restrictions', 'linkmaster'); ?></label>
                            <span class="linkmaster-tooltip" data-tooltip="<?php esc_attr_e('Enter IP addresses, ranges (e.g., 192.168.1.1-192.168.1.255), or CIDR notation (e.g., 192.168.1.0/24)', 'linkmaster'); ?>">?</span>
                        </th>
                        <td>
                            <div class="ip-restrictions">
                                <?php
                                $ip_restrictions = array();
                                if ($link && !empty($link->ip_restrictions)) {
                                    $ip_restrictions = json_decode($link->ip_restrictions, true) ?: array();
                                }
                                if ($ip_restrictions):
                                    foreach ($ip_restrictions as $range):
                                ?>
                                    <div class="ip-restriction">
                                        <input type="text" name="ip_restrictions[]" value="<?php echo esc_attr($range); ?>">
                                        <button type="button" class="button linkmaster-remove-ip"><?php _e('Remove', 'linkmaster'); ?></button>
                                    </div>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>
                            <button type="button" id="linkmaster-add-ip" class="button"><?php _e('Add IP Restriction', 'linkmaster'); ?></button>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="click_limit"><?php _e('Click Limit', 'linkmaster'); ?></label></th>
                        <td>
                            <input type="number" id="click_limit" name="click_limit" value="<?php echo esc_attr($link ? $link->click_limit : ''); ?>" min="0">
                            <p class="description"><?php _e('Leave empty for unlimited clicks.', 'linkmaster'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="expiry_date"><?php _e('Expiration Date', 'linkmaster'); ?></label></th>
                        <td>
                            <input type="text" id="expiry_date" name="expiry_date" class="linkmaster-datepicker" 
                                   placeholder="YYYY-MM-DD" 
                                   pattern="\d{4}-\d{2}-\d{2}"
                                   value="<?php echo esc_attr($link && $link->expiry_date ? date('Y-m-d', strtotime($link->expiry_date)) : ''); ?>">
                            <p class="description"><?php _e('Optional. Format: YYYY-MM-DD. Leave empty for no expiration.', 'linkmaster'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><?php _e('Link Attributes', 'linkmaster'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="nofollow" value="1" <?php checked($link && !empty($link->nofollow)); ?>>
                                <?php _e('Add nofollow', 'linkmaster'); ?>
                            </label>
                            <p class="description"><?php _e('Add rel="nofollow" attribute to links', 'linkmaster'); ?></p>
                            
                            <br>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="status"><?php _e('Status', 'linkmaster'); ?></label></th>
                        <td>
                            <select id="status" name="status">
                                <option value="active" <?php selected($link ? $link->status : 'active', 'active'); ?>><?php _e('Active', 'linkmaster'); ?></option>
                                <option value="disabled" <?php selected($link ? $link->status : 'active', 'disabled'); ?>><?php _e('Disabled', 'linkmaster'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button($link_id ? __('Update Link', 'linkmaster') : __('Create Link', 'linkmaster')); ?>
            </form>
            
            <script type="text/template" id="ip-restriction-template">
                <div class="ip-restriction">
                    <input type="text" name="ip_restrictions[]" value="">
                    <button type="button" class="button linkmaster-remove-ip"><?php _e('Remove', 'linkmaster'); ?></button>
                </div>
            </script>
            <?php
            break;
            
        case 'settings':
            ?>
            <div class="linkmaster-settings-container">
                <form method="post" action="options.php" class="linkmaster-settings-form">
                    <?php
                    settings_fields('linkmaster_cloaked_links');
                    do_settings_sections('linkmaster_cloaked_links');
                    ?>
                    
                    <h2><?php _e('URL Prefix', 'linkmaster'); ?></h2>
                    <p class="description">
                        <?php _e('This prefix will be used in your cloaked URLs. Example: yoursite.com/PREFIX/link-slug', 'linkmaster'); ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('URL Prefix', 'linkmaster'); ?></th>
                            <td>
                                <input type="text" name="linkmaster_cloak_prefix" 
                                       value="<?php echo esc_attr(get_option('linkmaster_cloak_prefix', 'go')); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(); ?>
                </form>
                
                <div class="linkmaster-import-export-section">
                    <h2><?php _e('Import/Export Links', 'linkmaster'); ?></h2>
                    
                    <div class="linkmaster-import-export-container">
                        <!-- Export Box -->
                        <div class="linkmaster-settings-box">
                            <h3><?php _e('Export Links', 'linkmaster'); ?></h3>
                            <p><?php _e('Download all your cloaked links as a CSV file.', 'linkmaster'); ?></p>
                            <form method="post">
                                <?php wp_nonce_field('linkmaster_export_cloaked_links'); ?>
                                <button type="submit" name="linkmaster_export_cloaked_links" class="button button-secondary">
                                    <?php _e('Export to CSV', 'linkmaster'); ?>
                                </button>
                            </form>
                        </div>
                        
                        <!-- Import Box -->
                        <div class="linkmaster-settings-box">
                            <h3><?php _e('Import Links', 'linkmaster'); ?></h3>
                            <p><?php _e('Import cloaked links from a CSV file.', 'linkmaster'); ?></p>
                            <form method="post" enctype="multipart/form-data">
                                <?php wp_nonce_field('linkmaster_import_cloaked_links'); ?>
                                <div class="linkmaster-file-input">
                                    <input type="file" name="import_file" accept=".csv" required>
                                </div>
                                <p class="submit">
                                    <button type="submit" name="linkmaster_import_cloaked_links" class="button button-secondary">
                                        <?php _e('Import from CSV', 'linkmaster'); ?>
                                    </button>
                                </p>
                                <p class="description">
                                    <a href="#" onclick="event.preventDefault(); document.querySelector('form[name=linkmaster_sample_download]').submit();">
                                        <?php _e('Download Sample CSV', 'linkmaster'); ?>
                                    </a>
                                </p>
                            </form>
                            
                            <!-- Hidden form for sample CSV download -->
                            <form method="post" name="linkmaster_sample_download" style="display: none;">
                                <?php wp_nonce_field('linkmaster_download_sample'); ?>
                                <input type="hidden" name="linkmaster_download_sample" value="1">
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <style>
                .linkmaster-settings-container {
                    margin-top: 20px;
                }
                
                .linkmaster-import-export-section {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #ccd0d4;
                }
                
                .linkmaster-import-export-container {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                    gap: 30px;
                    margin-top: 20px;
                }
                
                .linkmaster-settings-box {
                    background: #fff;
                    padding: 20px;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                }
                
                .linkmaster-settings-box h3 {
                    margin-top: 0;
                    margin-bottom: 15px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #eee;
                }
                
                .linkmaster-file-input {
                    margin-bottom: 15px;
                }
                
                .linkmaster-settings-box .submit {
                    margin-top: 0;
                    padding-top: 0;
                }
                
                .linkmaster-settings-box .description {
                    margin-top: 10px;
                }
                
                .linkmaster-settings-form {
                    max-width: 600px;
                }
            </style>
            <?php
            break;
            
        default: // list view
            ?>
            <div class="linkmaster-list-container">
                <form method="post">
                    <?php
                    $list_table = new LinkMaster_Cloaked_Links_List_Table();
                    $list_table->prepare_items();
                    $list_table->display();
                    ?>
                </form>
            </div>
            <?php
            break;
    }
    ?>
</div>

<style>
    .linkmaster-import-export-section {
        background: #fff;
        padding: 20px;
        margin: 20px 0;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }
    
    .linkmaster-import-export-container {
        display: flex;
        gap: 40px;
        margin-top: 20px;
    }
    
    .linkmaster-export-box,
    .linkmaster-import-box {
        flex: 1;
    }
    
    .linkmaster-sample-download {
        margin-top: 10px;
    }
    
    .linkmaster-sample-download .button-link {
        color: #0073aa;
        text-decoration: underline;
        padding: 0;
    }
</style>
