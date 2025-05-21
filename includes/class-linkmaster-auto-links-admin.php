<?php

if (!defined('ABSPATH')) {
    exit;
}

class LinkMaster_Auto_Links_Admin {
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
        
        add_action('admin_menu', array($this, 'add_submenu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_linkmaster_get_auto_link_usage_details', array($this, 'ajax_get_usage_details'));
        add_action('admin_footer', array($this, 'customize_admin_menu_labels'));
    }
    
    public function add_submenu() {
        add_submenu_page(
            'linkmaster',
            __('Auto Internal Links', 'linkmaster'),
            __('Auto Links (Pro)', 'linkmaster'),
            'edit_posts',
            'linkmaster-auto-links',
            array($this, 'render_page')
        );
    }

    public function customize_admin_menu_labels() {
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const menuItems = document.querySelectorAll('#adminmenu a.toplevel_page_linkmaster + ul li a');
                menuItems.forEach(link => {
                    if (link.textContent.includes('(Pro)')) {
                        link.innerHTML = link.innerHTML.replace('(Pro)', '<span class="linkmaster-pro-label">(Pro)</span>');
                    }
                });
            });
        </script>
        <style>
            .linkmaster-pro-label {
                color: #46b450; /* WordPress green (same as success color) */
                font-weight: 700;
                margin-left: 4px;
            }
        </style>
        <?php
    }
    
    public function enqueue_scripts($hook) {
        if ('linkmaster_page_linkmaster-auto-links' !== $hook) {
            return;
        }
        
        wp_enqueue_style('linkmaster-auto-links', plugins_url('css/auto-links.css', dirname(__FILE__)), array(), LINKMASTER_VERSION);
        
        wp_enqueue_script(
            'linkmaster-auto-links',
            plugins_url('js/auto-links.js', dirname(__FILE__)),
            array('jquery'),
            LINKMASTER_VERSION,
            true
        );
        
        wp_localize_script('linkmaster-auto-links', 'linkmaster_auto_links', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('linkmaster_admin'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this auto-link rule?', 'linkmaster'),
                'error' => __('An error occurred. Please try again.', 'linkmaster'),
                'success' => __('Operation completed successfully.', 'linkmaster')
            )
        ));
    }
    
    public function render_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch ($action) {
            case 'add':
                $this->render_form();
                break;
            case 'edit':
                $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
                $this->render_form($id);
                break;
            default:
                $this->render_list();
                break;
        }
    }
    
    private function render_form($id = 0) {
        global $wpdb;
        
        $rule = null;
        $rule_post_types = array();
        
        if ($id) {
            $rule = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}linkmaster_auto_links WHERE id = %d",
                $id
            ));
            
            if (!$rule) {
                wp_die(__('Auto-link rule not found.', 'linkmaster'));
            }
            
            $rule_post_types = maybe_unserialize($rule->post_types);
            if (!is_array($rule_post_types)) {
                $rule_post_types = array();
            }
        }
        
        // Get post types excluding media/attachments and internal WordPress types
        $post_types = get_post_types(array(
            'public' => true,
            'show_ui' => true
        ), 'objects');
        
        // Remove unwanted post types
        unset($post_types['attachment']);
        unset($post_types['wp_block']);
        unset($post_types['wp_navigation']);
        unset($post_types['wp_template']);
        unset($post_types['wp_template_part']);
        
        ?>
        <div class="wrap linkmaster-wrap">
            <h1 class="wp-heading-inline">
                <?php echo $id ? __('Edit Auto-Link Rule', 'linkmaster') : __('Add Auto-Link Rule', 'linkmaster'); ?>
            </h1>
            <a href="<?php echo admin_url('admin.php?page=linkmaster-auto-links'); ?>" class="page-title-action">
                <?php _e('← Back to List', 'linkmaster'); ?>
            </a>
            
            <div class="linkmaster-form-container">
                <form id="linkmaster-auto-link-form" method="post">
                    <?php wp_nonce_field('linkmaster_admin'); ?>
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    
                    <div class="linkmaster-form-section">
                        <h2><?php _e('Basic Settings', 'linkmaster'); ?></h2>
                        <div class="linkmaster-field-group">
                            <label for="keyword"><?php _e('Keyword/Phrase', 'linkmaster'); ?></label>
                            <input type="text" id="keyword" name="keyword" class="regular-text" 
                                value="<?php echo $id ? esc_attr($rule->keyword) : ''; ?>" required>
                            <p class="description"><?php _e('The text that will be automatically linked', 'linkmaster'); ?></p>
                        </div>
                        
                        <div class="linkmaster-field-group">
                            <label for="target_url"><?php _e('Target URL', 'linkmaster'); ?></label>
                            <input type="url" id="target_url" name="target_url" class="regular-text" 
                                value="<?php echo $id ? esc_url($rule->target_url) : ''; ?>" required>
                            <p class="description"><?php _e('The URL where the keyword will link to', 'linkmaster'); ?></p>
                        </div>

                        <div class="linkmaster-field-group">
                            <label for="priority"><?php _e('Priority', 'linkmaster'); ?></label>
                            <input type="number" id="priority" name="priority" class="small-text" min="0" 
                                value="<?php echo $id && isset($rule->priority) ? intval($rule->priority) : '0'; ?>">
                            <p class="description"><?php _e('Higher priority entries are processed first. When priorities are equal, longer keywords take precedence.', 'linkmaster'); ?></p>
                        </div>
                        
                        <div class="linkmaster-field-group">
                            <label for="link_limit"><?php _e('Link Limit per Page', 'linkmaster'); ?></label>
                            <input type="number" id="link_limit" name="link_limit" min="1" max="100" 
                                value="<?php echo $id ? intval($rule->link_limit) : 3; ?>">
                            <p class="description"><?php _e('Maximum number of times this keyword will be linked on a single page', 'linkmaster'); ?></p>
                        </div>
                    </div>
                    
                    <div class="linkmaster-form-section">
                        <h2><?php _e('Link Options', 'linkmaster'); ?></h2>
                        <div class="linkmaster-field-group linkmaster-checkbox-group">
                            <label>
                                <input type="checkbox" name="case_sensitive" value="1" 
                                    <?php checked($id && $rule->case_sensitive); ?>>
                                <?php _e('Case Sensitive', 'linkmaster'); ?>
                            </label>
                            <p class="description"><?php _e('Match exact case of the keyword', 'linkmaster'); ?></p>
                            
                            <label>
                                <input type="checkbox" name="nofollow" value="1" 
                                    <?php checked($id && $rule->nofollow); ?>>
                                <?php _e('Add nofollow', 'linkmaster'); ?>
                            </label>
                            <p class="description"><?php _e('Add rel="nofollow" attribute to links', 'linkmaster'); ?></p>
                            
                            <label>
                                <input type="checkbox" name="new_tab" value="1" 
                                    <?php checked($id && isset($rule->new_tab) && $rule->new_tab); ?>>
                                <?php _e('Open in New Tab', 'linkmaster'); ?>
                            </label>
                            <p class="description"><?php _e('Open links in a new browser tab', 'linkmaster'); ?></p>
                        </div>
                    </div>
                    
                    <div class="linkmaster-form-section">
                        <h2><?php _e('Content Types', 'linkmaster'); ?></h2>
                        <div class="linkmaster-field-group linkmaster-checkbox-grid">
                            <?php foreach ($post_types as $type): ?>
                                <label>
                                    <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type->name); ?>" 
                                        <?php checked(in_array($type->name, $rule_post_types)); ?>>
                                    <?php echo esc_html($type->labels->singular_name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description"><?php _e('Select which content types this rule applies to', 'linkmaster'); ?></p>
                    </div>
                    
                    <div class="linkmaster-form-actions">
                        <button type="submit" class="button button-primary button-large">
                            <?php echo $id ? __('Update Rule', 'linkmaster') : __('Add Rule', 'linkmaster'); ?>
                        </button>
                        <a href="<?php echo admin_url('admin.php?page=linkmaster-auto-links'); ?>" class="button button-large">
                            <?php _e('Cancel', 'linkmaster'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    
    private function render_list() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'linkmaster_auto_links';
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Handle search/filter
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $post_type_filter = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : '';
        
        // Build query
        $where = array("1=1");
        $query_params = array();
        
        if ($search) {
            $where[] = "keyword LIKE %s";
            $query_params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        if ($post_type_filter) {
            $where[] = "post_types LIKE %s";
            $query_params[] = '%' . $wpdb->esc_like($post_type_filter) . '%';
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Get total items with filters
        $total_items = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE $where_clause",
                $query_params
            )
        );
        
        // Get items for current page with filters
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE $where_clause ORDER BY priority DESC, id DESC LIMIT %d OFFSET %d",
                array_merge($query_params, array($per_page, $offset))
            )
        );
        
        // Get all post types used in auto links
        $post_types_used = $wpdb->get_col("SELECT DISTINCT post_types FROM $table WHERE post_types != ''");
        $all_post_types = array();
        foreach ($post_types_used as $types) {
            $types_array = maybe_unserialize($types);
            if (is_array($types_array)) {
                $all_post_types = array_merge($all_post_types, $types_array);
            }
        }
        $all_post_types = array_unique($all_post_types);
        
        // Get usage statistics for each auto-link rule
        $usage_stats = array();
        foreach ($items as $item) {
            $usage_stats[$item->id] = $this->get_auto_link_usage($item);
        }
        
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Auto Links', 'linkmaster'); ?></h1>
            <a href="<?php echo esc_url(add_query_arg('action', 'add')); ?>" class="page-title-action">
                <?php _e('Add New', 'linkmaster'); ?>
            </a>
            
            <?php if (!empty($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Auto link updated.', 'linkmaster'); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="get">
                <input type="hidden" name="page" value="linkmaster-auto-links">
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select id="bulk-action-selector-top" name="action">
                            <option value="-1"><?php _e('Bulk Actions', 'linkmaster'); ?></option>
                            <option value="delete"><?php _e('Delete', 'linkmaster'); ?></option>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'linkmaster'); ?>">
                    </div>
                    
                    <div class="alignleft actions">
                        <select name="post_type">
                            <option value=""><?php _e('All Post Types', 'linkmaster'); ?></option>
                            <?php foreach ($all_post_types as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>" <?php selected($post_type_filter, $type); ?>>
                                    <?php echo esc_html(ucfirst($type)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                               placeholder="<?php esc_attr_e('Search keywords...', 'linkmaster'); ?>">
                        
                        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'linkmaster'); ?>">
                        <?php if ($search || $post_type_filter): ?>
                            <a href="<?php echo esc_url(remove_query_arg(array('s', 'post_type'))); ?>" class="button">
                                <?php _e('Clear', 'linkmaster'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <?php
                    $total_pages = ceil($total_items / $per_page);
                    if ($total_pages > 1) {
                        echo '<div class="tablenav-pages">';
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        echo '</div>';
                    }
                    ?>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-cb check-column">
                                <input type="checkbox">
                            </th>
                            <th scope="col"><?php _e('Keyword', 'linkmaster'); ?></th>
                            <th scope="col"><?php _e('Target URL', 'linkmaster'); ?></th>
                            <th scope="col"><?php _e('Priority', 'linkmaster'); ?></th>
                            <th scope="col"><?php _e('Post Types', 'linkmaster'); ?></th>
                            <th scope="col"><?php _e('Link Options', 'linkmaster'); ?></th>
                            <th scope="col" class="column-usage">
                                <?php _e('Usage', 'linkmaster'); ?>
                                
                            </th>
                            <th scope="col"><?php _e('Actions', 'linkmaster'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="8">
                                    <?php 
                                    if ($search || $post_type_filter) {
                                        _e('No auto links found matching your criteria.', 'linkmaster');
                                    } else {
                                        _e('No auto links found.', 'linkmaster');
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php 
                                $post_types = maybe_unserialize($item->post_types);
                                $post_types = is_array($post_types) ? implode(', ', $post_types) : '';
                                $usage = $usage_stats[$item->id];
                                ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="auto_link[]" value="<?php echo esc_attr($item->id); ?>">
                                    </th>
                                    <td>
                                        <strong><?php echo esc_html($item->keyword); ?></strong>
                                        <?php if ($usage['count'] > 0): ?>
                                            <div class="row-actions">
                                                <span class="view">
                                                    <a href="#" class="show-usage-details" 
                                                       data-id="<?php echo esc_attr($item->id); ?>"
                                                       data-keyword="<?php echo esc_attr($item->keyword); ?>">
                                                        <?php _e('Show Details', 'linkmaster'); ?>
                                                    </a>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($item->target_url); ?>" target="_blank">
                                            <?php echo esc_url($item->target_url); ?>
                                            <span class="dashicons dashicons-external"></span>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html($item->priority); ?></td>
                                    <td><?php echo esc_html($post_types); ?></td>
                                    <td>
                                        <?php
                                        $options = array();
                                        if ($item->case_sensitive) $options[] = __('Case Sensitive', 'linkmaster');
                                        if ($item->nofollow) $options[] = __('Nofollow', 'linkmaster');
                                        if ($item->new_tab) $options[] = __('New Tab', 'linkmaster');
                                        if ($item->link_limit > 0) $options[] = sprintf(__('Max: %d', 'linkmaster'), $item->link_limit);
                                        echo esc_html(implode(', ', $options));
                                        ?>
                                    </td>
                                    <td class="column-usage">
                                        <?php if ($usage['count'] > 0): ?>
                                            <strong><?php printf(
                                                _n('%d link', '%d links', $usage['count'], 'linkmaster'),
                                                $usage['count']
                                            ); ?></strong>
                                            <br>
                                            <span class="description"><?php printf(
                                                _n('in %d post', 'in %d posts', $usage['post_count'], 'linkmaster'),
                                                $usage['post_count']
                                            ); ?></span>
                                        <?php else: ?>
                                            <span class="description"><?php _e('Not used', 'linkmaster'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-actions">
                                        <a href="<?php echo esc_url(add_query_arg(array('action' => 'edit', 'id' => $item->id))); ?>" 
                                           class="button button-small">
                                            <span class="dashicons dashicons-edit"></span>
                                            <?php _e('Edit', 'linkmaster'); ?>
                                        </a>
                                        <button type="button" class="button button-small button-link-delete delete-auto-link" 
                                                data-id="<?php echo esc_attr($item->id); ?>"
                                                data-keyword="<?php echo esc_attr($item->keyword); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                            <?php _e('Delete', 'linkmaster'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>

        <!-- Usage Details Modal -->
        <div id="usage-details-modal" class="linkmaster-modal" style="display: none;">
            <div class="linkmaster-modal-content">
                <span class="linkmaster-modal-close">&times;</span>
                <h2><?php _e('Usage Details', 'linkmaster'); ?></h2>
                <div id="usage-details-content"></div>
            </div>
        </div>

        <style>
            .linkmaster-modal {
                display: none;
                position: fixed;
                z-index: 999999;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0,0,0,0.4);
            }
            .linkmaster-modal-content {
                background-color: #fefefe;
                margin: 5% auto;
                padding: 20px;
                border: 1px solid #888;
                width: 80%;
                max-width: 800px;
                max-height: 80vh;
                overflow-y: auto;
                position: relative;
                border-radius: 4px;
            }
            .linkmaster-modal-close {
                color: #aaa;
                float: right;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
            }
            .linkmaster-modal-close:hover {
                color: #000;
            }
            .usage-item {
                margin-bottom: 15px;
                padding: 10px;
                background: #f9f9f9;
                border: 1px solid #e5e5e5;
                border-radius: 3px;
            }
            .usage-item h3 {
                margin: 0 0 10px 0;
            }
            .usage-item p {
                margin: 5px 0;
            }
            .usage-excerpt {
                background: #fff;
                padding: 10px;
                margin-top: 10px;
                border-left: 4px solid #0073aa;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Show usage details modal
            $('.show-usage-details').click(function() {
                var button = $(this);
                var id = button.data('id');
                var keyword = button.data('keyword');
                
                // Show loading state
                $('#usage-details-content').html('<p><?php _e('Loading...', 'linkmaster'); ?></p>');
                $('#usage-details-modal').show();
                
                // Fetch usage details via AJAX
                $.post(ajaxurl, {
                    action: 'linkmaster_get_auto_link_usage_details',
                    id: id,
                    nonce: '<?php echo wp_create_nonce('linkmaster_auto_links'); ?>'
                }, function(response) {
                    if (response.success) {
                        var content = '<h3>' + keyword + '</h3>';
                        if (response.data.length > 0) {
                            response.data.forEach(function(item) {
                                content += '<div class="usage-item">';
                                content += '<h3><a href="' + item.edit_url + '" target="_blank">' + item.title + '</a></h3>';
                                content += '<p><strong><?php _e('Type:', 'linkmaster'); ?></strong> ' + item.type + '</p>';
                                content += '<p><strong><?php _e('Occurrences:', 'linkmaster'); ?></strong> ' + item.count + '</p>';
                                if (item.excerpt) {
                                    content += '<div class="usage-excerpt">' + item.excerpt + '</div>';
                                }
                                content += '</div>';
                            });
                        } else {
                            content += '<p><?php _e('No usage found.', 'linkmaster'); ?></p>';
                        }
                        $('#usage-details-content').html(content);
                    } else {
                        $('#usage-details-content').html('<p><?php _e('Error loading usage details.', 'linkmaster'); ?></p>');
                    }
                });
            });
            
            // Close modal
            $('.linkmaster-modal-close').click(function() {
                $('#usage-details-modal').hide();
            });
            $(window).click(function(e) {
                if ($(e.target).hasClass('linkmaster-modal')) {
                    $('#usage-details-modal').hide();
                }
            });
        });
        </script>
        <?php
    }

    private function get_auto_link_usage($auto_link) {
        global $wpdb;
        
        $post_types = maybe_unserialize($auto_link->post_types);
        if (!is_array($post_types) || empty($post_types)) {
            $post_types = array('post', 'page');
        }
        
        $excluded_posts = maybe_unserialize($auto_link->excluded_posts);
        if (!is_array($excluded_posts)) {
            $excluded_posts = array();
        }
        
        $post_types_sql = "'" . implode("','", array_map('esc_sql', $post_types)) . "'";
        $excluded_posts_sql = !empty($excluded_posts) ? " AND ID NOT IN (" . implode(',', array_map('intval', $excluded_posts)) . ")" : "";
        
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type 
            FROM {$wpdb->posts} 
            WHERE post_type IN ($post_types_sql) 
            AND post_status = 'publish' 
            $excluded_posts_sql 
            AND post_content LIKE %s",
            '%' . $wpdb->esc_like($auto_link->keyword) . '%'
        ));
        
        $total_count = 0;
        $post_count = 0;
        $usage_stats = array();
        
        foreach ($posts as $post) {
            if (empty($post->post_content)) {
                continue;
            }
            
            // Use DOMDocument for accurate counting
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML(mb_convert_encoding($post->post_content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Only process text within paragraphs and lists
            $textNodes = $xpath->query('//p//text() | //li//text()');
            
            $matches_in_post = 0;
            $match_contexts = array();
            
            foreach ($textNodes as $node) {
                // Skip if node is already part of a link
                $parent = $node->parentNode;
                while ($parent) {
                    if ($parent->nodeName === 'a') {
                        continue 2;
                    }
                    $parent = $parent->parentNode;
                }
                
                $pattern = $auto_link->case_sensitive ? 
                    '/\b(' . preg_quote($auto_link->keyword, '/') . ')\b/' :
                    '/\b(' . preg_quote($auto_link->keyword, '/') . ')\b/i';
                
                if (preg_match_all($pattern, $node->nodeValue, $matches, PREG_OFFSET_CAPTURE)) {
                    // Count matches respecting link limit
                    $new_matches = count($matches[0]);
                    if ($auto_link->link_limit > 0) {
                        $new_matches = min($new_matches, $auto_link->link_limit - $matches_in_post);
                        $matches_in_post += $new_matches;
                    } else {
                        $matches_in_post += $new_matches;
                    }
                    
                    // Get context for each match in this node
                    $text = $node->nodeValue;
                    for ($i = 0; $i < $new_matches; $i++) {
                        $match_pos = $matches[0][$i][1];
                        $start = max(0, $match_pos - 30);
                        $length = strlen($auto_link->keyword) + 60;
                        $excerpt = substr($text, $start, $length);
                        
                        if ($start > 0) {
                            $excerpt = '...' . $excerpt;
                        }
                        if ($start + $length < strlen($text)) {
                            $excerpt .= '...';
                        }
                        
                        $match_contexts[] = $excerpt;
                    }
                    
                    if ($auto_link->link_limit > 0 && $matches_in_post >= $auto_link->link_limit) {
                        break;
                    }
                }
            }
            
            if ($matches_in_post > 0) {
                // Combine all contexts and highlight keywords
                $combined_excerpt = implode("\n", array_map(function($ctx) use ($auto_link) {
                    return preg_replace(
                        '/(' . preg_quote($auto_link->keyword, '/') . ')/i',
                        '<mark>$1</mark>',
                        esc_html($ctx)
                    );
                }, $match_contexts));
                
                $usage_stats[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => $post->post_type,
                    'count' => $matches_in_post,
                    'excerpt' => $combined_excerpt,
                    'edit_url' => get_edit_post_link($post->ID)
                );
                
                $post_count++;
                $total_count += $matches_in_post;
            }
        }
        
        return array(
            'count' => $total_count,
            'post_count' => $post_count,
            'usage_stats' => $usage_stats
        );
    }

    public function ajax_get_usage_details() {
        check_ajax_referer('linkmaster_auto_links', 'nonce');
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error();
        }
        
        global $wpdb;
        $auto_link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}linkmaster_auto_links WHERE id = %d",
            $id
        ));
        
        if (!$auto_link) {
            wp_send_json_error();
        }
        
        $post_types = maybe_unserialize($auto_link->post_types);
        
        if (!is_array($post_types) || empty($post_types)) {
            $post_types = array('post', 'page');
        }
        
        $post_types_sql = "'" . implode("','", array_map('esc_sql', $post_types)) . "'";
        $keyword = $wpdb->esc_like($auto_link->keyword);
        
        // Get posts that contain the keyword
        $query = $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_type, post_status 
            FROM {$wpdb->posts} 
            WHERE post_type IN ($post_types_sql) 
            AND post_status = 'publish' 
            AND post_content LIKE %s
            ORDER BY post_type, post_title",
            '%' . $keyword . '%'
        );
        
        $posts = $wpdb->get_results($query);
        $usage_details = array();
        
        foreach ($posts as $post) {
            // Use DOMDocument for accurate counting and excerpts
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML(mb_convert_encoding($post->post_content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Only process text within paragraphs and lists
            $textNodes = $xpath->query('//p//text() | //li//text()');
            
            $matches_in_post = 0;
            $match_contexts = array();
            
            foreach ($textNodes as $node) {
                // Skip if node is already part of a link
                $parent = $node->parentNode;
                while ($parent) {
                    if ($parent->nodeName === 'a') {
                        continue 2;
                    }
                    $parent = $parent->parentNode;
                }
                
                $pattern = $auto_link->case_sensitive ? 
                    '/\b(' . preg_quote($auto_link->keyword, '/') . ')\b/' :
                    '/\b(' . preg_quote($auto_link->keyword, '/') . ')\b/i';
                
                if (preg_match_all($pattern, $node->nodeValue, $matches, PREG_OFFSET_CAPTURE)) {
                    // Count matches respecting link limit
                    $new_matches = count($matches[0]);
                    if ($auto_link->link_limit > 0) {
                        $new_matches = min($new_matches, $auto_link->link_limit - $matches_in_post);
                    }
                    
                    // Get context for each match in this node
                    $text = $node->nodeValue;
                    for ($i = 0; $i < $new_matches; $i++) {
                        $match_pos = $matches[0][$i][1];
                        $start = max(0, $match_pos - 30);
                        $length = strlen($auto_link->keyword) + 60;
                        $excerpt = substr($text, $start, $length);
                        
                        if ($start > 0) {
                            $excerpt = '...' . $excerpt;
                        }
                        if ($start + $length < strlen($text)) {
                            $excerpt .= '...';
                        }
                        
                        $match_contexts[] = $excerpt;
                    }
                    
                    $matches_in_post += $new_matches;
                    if ($auto_link->link_limit > 0 && $matches_in_post >= $auto_link->link_limit) {
                        break;
                    }
                }
            }
            
            if ($matches_in_post > 0) {
                // Combine all contexts and highlight keywords
                $combined_excerpt = implode("\n", array_map(function($ctx) use ($auto_link) {
                    return preg_replace(
                        '/(' . preg_quote($auto_link->keyword, '/') . ')/i',
                        '<mark>$1</mark>',
                        esc_html($ctx)
                    );
                }, $match_contexts));
                
                $usage_details[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'type' => $post->post_type,
                    'count' => $matches_in_post,
                    'excerpt' => $combined_excerpt,
                    'edit_url' => get_edit_post_link($post->ID)
                );
            }
        }
        
        wp_send_json_success($usage_details);
    }
}
