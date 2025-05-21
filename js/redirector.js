jQuery(document).ready(function ($) {
    // Handle tab navigation
    function handleTabNavigation() {
        // Get current tab from URL
        var urlParams = new URLSearchParams(window.location.search);
        var currentTab = urlParams.get('tab') || 'list';
        
        // Handle edit redirect action
        if (urlParams.has('edit') && currentTab !== 'add') {
            // Switch to add tab and populate form
            urlParams.set('tab', 'add');
            var newUrl = window.location.pathname + '?' + urlParams.toString();
            window.history.pushState({}, '', newUrl);
            
            // The edit functionality will be handled after page reload
        }
    }
    
    // Run on page load
    handleTabNavigation();
    
    // Handle form submission for adding/editing redirects
    $('#linkmaster-add-redirect-form').on('submit', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $submitButton = $('#save-redirect');
        var $cancelButton = $('#cancel-edit');

        // Disable submit button to prevent multiple submissions
        $submitButton.prop('disabled', true).addClass('updating-message');

        var formData = {
            action: 'linkmaster_save_redirect',
            nonce: linkmaster_redirector.save_nonce,
            redirect_id: $('#redirect_id').val(),
            source_url: $('#source_url').val(),
            target_url: $('#target_url').val(),
            redirect_type: $('#redirect_type').val() || '301', // Default to 301 if not set
            status: $('#status').val() || 'enabled', // Default to enabled if not set
            expiration_date: $('#expiration_date').val(),
            nofollow: $('#nofollow').prop('checked') ? 1 : 0,
            sponsored: $('#sponsored').prop('checked') ? 1 : 0
        };

        $.post(ajaxurl, formData, function (response) {
            if (response.success) {
                // Reset form
                $form[0].reset();
                $('#redirect_id').val('');
                $('#redirect_type').val('301'); // Reset to default 301
                $('#status').val('enabled'); // Reset to default enabled
                $submitButton.text(linkmaster_redirector.save_button_text || 'Save Redirect');
                $cancelButton.hide();

                // Show success message
                $('<div class="notice notice-success is-dismissible"><p>' + (response.data.message || linkmaster_redirector.save_success) + '</p></div>')
                    .insertBefore($form)
                    .delay(3000)
                    .fadeOut(function() {
                        $(this).remove();
                    });
                
                // Redirect to list tab after successful save
                setTimeout(function() {
                    window.location.href = window.location.pathname + '?page=linkmaster-redirections&tab=list';
                }, 1000);
            } else {
                alert(response.data.message || linkmaster_redirector.save_error);
            }
        }).fail(function () {
            $submitButton.prop('disabled', false).removeClass('updating-message');
            alert(linkmaster_redirector.save_error);
        });
    });

    // Handle edit redirect
    $('.edit-redirect').on('click', function (e) {
        e.preventDefault();

        var $button = $(this);
        var $row = $button.closest('tr');
        var redirectId = $button.data('id');
        
        // Redirect to the add tab with edit parameter
        var urlParams = new URLSearchParams(window.location.search);
        urlParams.set('tab', 'add');
        urlParams.set('edit', redirectId);
        window.location.href = window.location.pathname + '?' + urlParams.toString();
    });
    
    // Check if we need to populate the form (coming from edit button)
    function populateFormFromEditParam() {
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit') && urlParams.get('tab') === 'add') {
            var redirectId = urlParams.get('edit');
            
            // Show loading indicator
            var $form = $('#linkmaster-add-redirect-form');
            var $submitButton = $('#save-redirect');
            $submitButton.prop('disabled', true).addClass('updating-message');
            
            // Fetch redirect data via AJAX
            $.post(ajaxurl, {
                action: 'linkmaster_get_redirect_data',
                nonce: linkmaster_redirector.get_redirect_nonce,
                redirect_id: redirectId
            }, function(response) {
                if (response.success && response.data) {
                    // Populate form with data from AJAX response
                    $('#redirect_id').val(response.data.id);
                    $('#source_url').val(response.data.source_url);
                    $('#target_url').val(response.data.target_url);
                    $('#redirect_type').val(response.data.redirect_type);
                    $('#status').val(response.data.status);
                    
                    // Set expiration date
                    if (response.data.expiration_date) {
                        try {
                            var date = new Date(response.data.expiration_date);
                            if (!isNaN(date.getTime())) {
                                var formattedDate = date.toISOString().split('T')[0]; // Get YYYY-MM-DD format
                                $('#expiration_date').val(formattedDate);
                            }
                        } catch (e) {
                            console.error('Error parsing date:', e);
                            $('#expiration_date').val('');
                        }
                    } else {
                        $('#expiration_date').val('');
                    }
                    
                    // Set link attributes
                    $('#nofollow').prop('checked', response.data.nofollow);
                    $('#sponsored').prop('checked', response.data.sponsored);
                    
                    // Update button text and show cancel button
                    $('#save-redirect').text(linkmaster_redirector.update_button_text || 'Update Redirect');
                    $('#cancel-edit').show();
                    
                    // Remove the edit parameter from URL to prevent reloading the form on refresh
                    urlParams.delete('edit');
                    var newUrl = window.location.pathname + '?' + urlParams.toString();
                    window.history.replaceState({}, '', newUrl);
                } else {
                    // Show error message
                    alert(response.data ? response.data.message : 'Error loading redirect data');
                    
                    // Redirect back to list tab
                    window.location.href = window.location.pathname + '?page=linkmaster-redirections&tab=list';
                }
                
                // Remove loading indicator
                $submitButton.prop('disabled', false).removeClass('updating-message');
            }).fail(function() {
                alert('Failed to load redirect data. Please try again.');
                $submitButton.prop('disabled', false).removeClass('updating-message');
                
                // Redirect back to list tab
                window.location.href = window.location.pathname + '?page=linkmaster-redirections&tab=list';
            });
        }
    }
    
    // Run on page load
    populateFormFromEditParam();

    // Handle cancel edit
    $('#cancel-edit').on('click', function () {
        // Reset form
        $('#linkmaster-add-redirect-form')[0].reset();
        $('#redirect_id').val('');
        $('#redirect_type').val('301'); // Reset to default 301
        $('#status').val('enabled'); // Reset to default enabled
        $('#expiration_date').val('');
        $('#nofollow').prop('checked', false);
        $('#sponsored').prop('checked', false);
        
        // Update button text and hide cancel button
        $('#save-redirect').text(linkmaster_redirector.save_button_text || 'Save Redirect');
        $(this).hide();
        
        // Redirect to list tab
        window.location.href = window.location.pathname + '?page=linkmaster-redirections&tab=list';
    });

    // Handle delete redirect
    $('.delete-redirect').on('click', function (e) {
        e.preventDefault();

        if (!confirm(linkmaster_redirector.bulk_delete_confirm)) {
            return;
        }

        var $button = $(this);
        var $row = $button.closest('tr');
        var redirectId = $button.data('id');

        $button.prop('disabled', true).addClass('updating-message');

        var data = {
            action: 'linkmaster_delete_redirect',
            nonce: linkmaster_redirector.delete_nonce,
            redirect_id: redirectId
        };

        $.post(ajaxurl, data, function (response) {
            if (response.success) {
                $row.fadeOut(400, function () {
                    $(this).remove();
                    if ($('#the-list tr').length === 0) {
                        location.reload();
                    }
                });
            } else {
                alert(response.data.message);
                $button.prop('disabled', false).removeClass('updating-message');
            }
        }).fail(function () {
            alert(linkmaster_redirector.delete_error);
            $button.prop('disabled', false).removeClass('updating-message');
        });
    });

    // Handle bulk actions, including "Reset Hits"
    $('#doaction, #doaction2').on('click', function (e) {
        e.preventDefault();

        var $button = $(this);
        var $bulkSelect = $button.prev('select');
        var action = $bulkSelect.val();

        if (action === '-1') {
            alert(linkmaster_redirector.select_bulk_action);
            return;
        }

        var $checkedBoxes = $('.link-cb:checked');
        if ($checkedBoxes.length === 0) {
            alert(linkmaster_redirector.select_items);
            return;
        }

        if (action === 'delete' && !confirm(linkmaster_redirector.bulk_delete_confirm)) {
            return;
        }
        if (action === 'enable' && !confirm(linkmaster_redirector.bulk_enable_confirm)) {
            return;
        }
        if (action === 'disable' && !confirm(linkmaster_redirector.bulk_disable_confirm)) {
            return;
        }
        if (action === 'reset_hits' && !confirm('Are you sure you want to reset hit counts?')) {
            return;
        }

        $button.prop('disabled', true).addClass('updating-message');

        var redirectIds = [];
        $checkedBoxes.each(function () {
            redirectIds.push($(this).val());
        });

        var data = {
            action: 'linkmaster_bulk_redirect_action',
            nonce: linkmaster_redirector.bulk_nonce,
            bulk_action: action,
            redirect_ids: redirectIds
        };

        $.post(ajaxurl, data, function (response) {
            if (response.success) {
                if (action === 'reset_hits') {
                    // Reset hit counts visually without a full page refresh
                    $checkedBoxes.each(function () {
                        var row = $(this).closest('tr');
                        row.find('.column-hits').text('0'); // Update hit count to zero in UI
                    });

                    alert(linkmaster_redirector.bulk_success);
                } else {
                    location.reload();
                }
            } else {
                alert(response.data.message);
                $button.prop('disabled', false).removeClass('updating-message');
            }
        }).fail(function () {
            alert(linkmaster_redirector.bulk_error);
            $button.prop('disabled', false).removeClass('updating-message');
        });
    });

    // Handle select all checkboxes
    $('#cb-select-all-1, #cb-select-all-2').on('change', function () {
        var isChecked = $(this).prop('checked');
        $('.link-cb').prop('checked', isChecked);
    });
});