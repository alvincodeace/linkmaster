<?php
// Prevent direct file access
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<div class="linkmaster-password-form">
    <div class="linkmaster-password-container">
        <h1><?php _e('Protected Link', 'linkmaster'); ?></h1>
        <p><?php _e('This link is password protected. Please enter the password to continue.', 'linkmaster'); ?></p>
        
        <form id="linkmaster-password-form" method="post">
            <div class="form-group">
                <label for="linkmaster-password"><?php _e('Password', 'linkmaster'); ?></label>
                <input type="password" id="linkmaster-password" name="password" required>
            </div>
            
            <input type="hidden" name="slug" value="<?php echo esc_attr($slug); ?>">
            <input type="hidden" name="action" value="linkmaster_verify_password">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('linkmaster_verify_password'); ?>">
            
            <button type="submit" class="button button-primary">
                <?php _e('Continue', 'linkmaster'); ?>
            </button>
        </form>
        
        <div id="linkmaster-password-error" class="error-message" style="display: none;"></div>
    </div>
</div>

<style>
.linkmaster-password-form {
    max-width: 600px;
    margin: 60px auto;
    padding: 30px;
    background: #fff;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    border-radius: 4px;
    text-align: center;
}

.linkmaster-password-container {
    max-width: 400px;
    margin: 0 auto;
}

.linkmaster-password-form h1 {
    margin-bottom: 20px;
    color: #23282d;
}

.linkmaster-password-form p {
    margin-bottom: 30px;
    color: #666;
}

.form-group {
    margin-bottom: 20px;
    text-align: left;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #23282d;
}

.form-group input {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 16px;
}

.button-primary {
    display: inline-block;
    padding: 10px 24px;
    background: #0073aa;
    color: #fff;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
    transition: background 0.2s;
}

.button-primary:hover {
    background: #005177;
}

.error-message {
    margin-top: 20px;
    padding: 10px;
    background: #dc3232;
    color: #fff;
    border-radius: 4px;
    display: none;
}
</style>

<script>
jQuery(document).ready(function($) {
    $('#linkmaster-password-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $error = $('#linkmaster-password-error');
        var $submit = $form.find('button[type="submit"]');
        
        $error.hide();
        $submit.prop('disabled', true).text('<?php _e("Verifying...", "linkmaster"); ?>');
        
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: $form.serialize(),
            success: function(response) {
                if (response.success) {
                    window.location.href = response.data.redirect_url;
                } else {
                    $error.text(response.data).show();
                    $submit.prop('disabled', false).text('<?php _e("Continue", "linkmaster"); ?>');
                }
            },
            error: function() {
                $error.text('<?php _e("An error occurred. Please try again.", "linkmaster"); ?>').show();
                $submit.prop('disabled', false).text('<?php _e("Continue", "linkmaster"); ?>');
            }
        });
    });
});
</script>

<?php get_footer(); ?>
