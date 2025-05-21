jQuery(document).ready(function($) {
    // Handle auto-link form submission
    $('#linkmaster-auto-link-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitButton = form.find('input[type="submit"]');
        submitButton.prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'linkmaster_save_auto_link',
                ...form.serializeArray().reduce((obj, item) => {
                    if (item.name === 'post_types[]') {
                        if (!obj.post_types) obj.post_types = [];
                        obj.post_types.push(item.value);
                    } else {
                        obj[item.name] = item.value;
                    }
                    return obj;
                }, {})
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    var notice = $('<div class="notice notice-success is-dismissible"><p></p></div>')
                        .find('p')
                        .text(response.data.message)
                        .end()
                        .insertBefore(form);
                    
                    // Add dismiss button
                    notice.append('<button type="button" class="notice-dismiss"></button>');
                    
                    // Redirect after a short delay
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 1000);
                } else {
                    // Show error message
                    var notice = $('<div class="notice notice-error is-dismissible"><p></p></div>')
                        .find('p')
                        .text(response.data)
                        .end()
                        .insertBefore(form);
                    
                    // Add dismiss button
                    notice.append('<button type="button" class="notice-dismiss"></button>');
                    
                    // Re-enable submit button
                    submitButton.prop('disabled', false);
                }
            },
            error: function() {
                // Show error message
                var notice = $('<div class="notice notice-error is-dismissible"><p></p></div>')
                    .find('p')
                    .text('An error occurred while saving the auto-link rule.')
                    .end()
                    .insertBefore(form);
                
                // Add dismiss button
                notice.append('<button type="button" class="notice-dismiss"></button>');
                
                // Re-enable submit button
                submitButton.prop('disabled', false);
            }
        });
    });
    
    // Dismiss notices
    $(document).on('click', '.notice-dismiss', function() {
        $(this).parent().fadeOut(300, function() { $(this).remove(); });
    });
    
    // Handle rule deletion
    $('.delete-rule').on('click', function() {
        if (!confirm('Are you sure you want to delete this auto-link rule?')) {
            return;
        }
        
        var button = $(this);
        var id = button.data('id');
        button.prop('disabled', true);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'linkmaster_delete_auto_link',
                id: id,
                nonce: $('#_wpnonce').val()
            },
            success: function(response) {
                if (response.success) {
                    button.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                        if ($('table tbody tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert('An error occurred while deleting the auto-link rule.');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
    
    // Handle form submission
    $('#auto-link-form').on('submit', function(e) {
        e.preventDefault();
        var form = $(this);
        
        var submitButton = form.find('button[type="submit"]');
        var originalButtonText = submitButton.text();
        
        // Disable submit button and show loading state
        submitButton.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: form.serialize(),
            success: function(response) {
                if (response.success) {
                    // Show success message
                    var notice = $('<div class="notice notice-success is-dismissible"><p></p></div>')
                        .find('p')
                        .text(response.data.message)
                        .end()
                        .insertBefore(form);
                    
                    // Add dismiss button
                    notice.append('<button type="button" class="notice-dismiss"></button>');
                    
                    // Redirect after a short delay
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url;
                    }, 1000);
                } else {
                    // Show error message
                    var notice = $('<div class="notice notice-error is-dismissible"><p></p></div>')
                        .find('p')
                        .text(response.data.message || 'Error saving auto link.')
                        .end()
                        .insertBefore(form);
                    
                    // Add dismiss button
                    notice.append('<button type="button" class="notice-dismiss"></button>');
                    
                    // Re-enable submit button
                    submitButton.prop('disabled', false).text(originalButtonText);
                }
            },
            error: function() {
                // Show error message
                var notice = $('<div class="notice notice-error is-dismissible"><p></p></div>')
                    .find('p')
                    .text('Error saving auto link.')
                    .end()
                    .insertBefore(form);
                
                // Add dismiss button
                notice.append('<button type="button" class="notice-dismiss"></button>');
                
                // Re-enable submit button
                submitButton.prop('disabled', false).text(originalButtonText);
            }
        });
    });

    // Handle delete button clicks
    $('.delete-auto-link').on('click', function() {
        var id = $(this).data('id');
        var keyword = $(this).data('keyword');
        
        if (!confirm('Are you sure you want to delete the auto link for "' + keyword + '"?')) {
            return;
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'linkmaster_delete_auto_link',
                id: id,
                nonce: linkmaster_auto_links.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    var notice = $('<div class="notice notice-success is-dismissible"><p></p></div>')
                        .find('p')
                        .text('Auto-link rule deleted successfully.')
                        .end()
                        .prependTo('.wrap');
                    
                    // Add dismiss button
                    notice.append('<button type="button" class="notice-dismiss"></button>');
                    
                    window.location.reload();
                } else {
                    alert(response.data.message || 'Error deleting auto link.');
                }
            },
            error: function() {
                alert('Error deleting auto link.');
            }
        });
    });

    // Handle bulk actions
    $('#doaction').on('click', function(e) {
        e.preventDefault();
        var action = $('#bulk-action-selector-top').val();
        var ids = [];
        
        $('input[name="auto_link[]"]:checked').each(function() {
            ids.push($(this).val());
        });
        
        if (ids.length === 0) {
            alert('Please select items to process.');
            return;
        }
        
        if (action === 'delete') {
            if (!confirm('Are you sure you want to delete the selected auto links?')) {
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'linkmaster_bulk_action_auto_links',
                    bulk_action: action,
                    ids: ids,
                    nonce: linkmaster_auto_links.nonce
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Error performing bulk action.');
                    }
                },
                error: function() {
                    alert('Error performing bulk action.');
                }
            });
        }
    });

    // Usage details modal functionality
    var modal = $('#usage-details-modal');
    
    // Close modal when clicking outside
    $(window).on('click', function(e) {
        if ($(e.target).is(modal)) {
            modal.hide();
        }
    });
    
    // Close modal with escape key
    $(document).on('keyup', function(e) {
        if (e.key === "Escape") {
            modal.hide();
        }
    });
    
    // Handle post type selection
    $('#post-types').on('change', function() {
        var selected = $(this).val();
        if (selected && selected.length > 0) {
            $('.post-type-specific-options').show();
        } else {
            $('.post-type-specific-options').hide();
        }
    }).trigger('change');
});
