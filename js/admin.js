jQuery(document).ready(function($) {
    // Handle frequency options visibility - using multiple selectors and approaches for maximum compatibility
    $(document).on('change', 'select[name="linkmaster_scanner_options[frequency]"], #scan-frequency, .linkmaster-frequency-selector', function() {
        var frequency = $(this).val();
        
        // Hide all frequency option divs first
        $('.frequency-options, .linkmaster-weekly-options, .linkmaster-monthly-options').hide();
        
        // Show the appropriate options based on selection
        if (frequency === 'weekly') {
            $('#weekly-options, .linkmaster-weekly-options').show();
        } else if (frequency === 'monthly') {
            $('#monthly-options, .linkmaster-monthly-options').show();
        }
    });
    
    // Ensure frequency options are correctly displayed on page load
    function initFrequencyOptions() {
        // Try multiple ways to get the current frequency value
        var $frequencySelect = $('select[name="linkmaster_scanner_options[frequency]"], #scan-frequency, .linkmaster-frequency-selector').first();
        var frequency = $frequencySelect.val();
        
        // Hide all frequency option divs first
        $('.frequency-options, .linkmaster-weekly-options, .linkmaster-monthly-options').hide();
        
        // Show the appropriate options based on selection
        if (frequency === 'weekly') {
            $('#weekly-options, .linkmaster-weekly-options').show();
        } else if (frequency === 'monthly') {
            $('#monthly-options, .linkmaster-monthly-options').show();
        }
    }
    
    // Initialize on document ready
    initFrequencyOptions();
    
    // Also initialize after a short delay to handle any dynamic content loading
    setTimeout(initFrequencyOptions, 500);

    // Scanner Settings Form
    $('#linkmaster-scanner-settings').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $submitButton = $form.find(':submit');
        var originalText = $submitButton.val();

        // Validate email
        var $emailInput = $form.find('input[name="linkmaster_scanner_options[notification_email]"]');
        var email = $emailInput.val();
        if (email && !email.match(/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/)) {
            var $notice = $('<div class="notice notice-error is-dismissible"><p>Please enter a valid email address</p></div>');
            $form.before($notice);
            return;
        }

        $submitButton.val('Saving...').prop('disabled', true);

        // Make sure we're using the correct nonce
        var formData = $form.serialize();
        if (!formData.includes('nonce=')) {
            formData += '&nonce=' + linkmaster.scanner_options_nonce;
        }

        $.ajax({
            url: linkmaster.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    // Show success message
                    var $notice = $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                    $form.before($notice);
                    
                    if (response.data.next_scan) {
                        $('#next-scan-time').text(response.data.next_scan);
                    }
                    
                    // Force page reload to show updated settings
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error message
                    var $notice = $('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                    $form.before($notice);
                }
            },
            error: function() {
                var $notice = $('<div class="notice notice-error is-dismissible"><p>Error saving settings</p></div>');
                $form.before($notice);
            },
            complete: function() {
                $submitButton.val(originalText).prop('disabled', false);
            }
        });
    });

    // Handle scheduled scan options visibility
    $('input[name="linkmaster_scanner_options[enable_scheduled_scans]"]').on('change', function() {
        $('.scheduled-scan-options').toggle($(this).is(':checked'));
    });

    var $scanButton = $('#scan-links');
    var $cancelButton = $('#cancel-scan');
    var $spinner = $('.scan-buttons .spinner');
    var $progressDisplay;
    var $scanProgress = $('#scan-progress');
    var $scanWarning = $('#scan-warning');
    var scanInProgress = false;

    // Function to show notification
    function showScanNotification(message) {
        $('.linkmaster-notice').remove();
        var $notification = $('<div class="notice notice-info is-dismissible linkmaster-notice"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>');
        $('#wpbody-content .wrap').prepend($notification);
        $notification.hide().fadeIn(300);
        
        $notification.on('click', '.notice-dismiss', function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        });
    }

    // Initialize progress display
    function initProgressDisplay() {
        $scanProgress.show();
        $scanWarning.show(); // Show warning when scan starts
        if (!$progressDisplay) {
            $progressDisplay = $('<span class="scan-progress">0%</span>');
            $spinner.after($progressDisplay);
        }
    }

    // Update progress
    function updateProgress(progress) {
        if ($progressDisplay) {
            $progressDisplay.text(Math.round(progress) + '%');
        }
    }

    // Reset button and spinner
    function resetButton() {
        $scanButton.prop('disabled', false).show();
        $cancelButton.hide();
        $spinner.removeClass('is-active');
        $scanButton.text('Scan Now');
        $scanProgress.hide();
        $scanWarning.hide(); // Hide warning when scan ends
        scanInProgress = false;
        if ($progressDisplay) {
            $progressDisplay.remove();
            $progressDisplay = null;
        }
    }

    // Start scan UI
    function startScanUI() {
        $scanButton.prop('disabled', true);
        $cancelButton.show();
        $spinner.addClass('is-active');
        $scanButton.text(linkmaster.scanning_text);
        initProgressDisplay();
        scanInProgress = true;
    }

    // Scan button click handler
    $scanButton.on('click', function() {
        startScanUI();
        scanChunk(0, false);
    });

    // Cancel button click handler
    $cancelButton.on('click', function() {
        if (confirm('Are you sure you want to cancel the scan?')) {
            $.ajax({
                url: linkmaster.ajax_url,
                type: 'POST',
                data: {
                    action: 'linkmaster_manual_scan',
                    nonce: linkmaster.nonce,
                    cancel: 'true'
                },
                success: function(response) {
                    if (response.success) {
                        showScanNotification('Scan canceled.');
                        resetButton();
                    }
                }
            });
        }
    });

    function scanChunk(offset, resume) {
        $.ajax({
            url: linkmaster.ajax_url,
            type: 'POST',
            data: {
                action: 'linkmaster_manual_scan',
                nonce: linkmaster.nonce,
                offset: offset
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.canceled) {
                        resetButton();
                        showScanNotification(response.data.message);
                        return;
                    }
                    
                    updateProgress(response.data.progress);
                    if (!response.data.complete) {
                        scanChunk(response.data.offset, false);
                    } else {
                        resetButton();
                        if (response.data.show_notification) {
                            showScanNotification(response.data.message);
                            setTimeout(function() {
                                location.reload();
                            }, 5000);
                        } else {
                            location.reload();
                        }
                    }
                } else {
                    alert(response.data.message);
                    resetButton();
                }
            },
            error: function() {
                alert('Error during scan');
                resetButton();
            }
        });
    }

    // Edit link functionality
    $('.edit-link').on('click', function() {
        var $row = $(this).closest('tr');
        var $urlCell = $row.find('.link-url-cell');
        $urlCell.find('.link-display').hide();
        $urlCell.find('.link-edit').show();
        $(this).hide();
    });

    // Cancel edit functionality
    $('.cancel-edit').on('click', function() {
        var $row = $(this).closest('tr');
        var $urlCell = $row.find('.link-url-cell');
        $urlCell.find('.link-display').show();
        $urlCell.find('.link-edit').hide();
        $row.find('.edit-link').show();
    });

    // Save link functionality
    $('.save-link').on('click', function() {
        var $row = $(this).closest('tr');
        var $urlCell = $row.find('.link-url-cell');
        var postId = $row.find('.unlink-button').data('post-id');
        var oldUrl = $urlCell.find('.link-display').text();
        var newUrl = $urlCell.find('.new-link-url').val();
        var $button = $(this);
        $button.prop('disabled', true);
        $button.text('Saving...');

        $.ajax({
            url: linkmaster.ajax_url,
            type: 'POST',
            data: {
                action: 'linkmaster_update_link',
                nonce: linkmaster.update_nonce,
                post_id: postId,
                old_url: oldUrl,
                new_url: newUrl
            },
            success: function(response) {
                if (response.success) {
                    $urlCell.find('.link-display').text(newUrl).show();
                    $urlCell.find('.link-edit').hide();
                    $row.find('.edit-link').show();
                    showScanNotification(linkmaster.update_success);
                } else {
                    alert(response.data.message || linkmaster.update_error);
                }
            },
            error: function() {
                alert(linkmaster.update_error);
            },
            complete: function() {
                $button.prop('disabled', false);
                $button.text('Save');
            }
        });
    });

    // Unlink functionality
    $('.unlink-button').on('click', function() {
        var $button = $(this);
        var postId = $button.data('post-id');
        var url = $button.data('url');
        $button.prop('disabled', true);
        $button.text('Removing...');

        $.ajax({
            url: linkmaster.ajax_url,
            type: 'POST',
            data: {
                action: 'linkmaster_unlink',
                nonce: linkmaster.update_nonce,
                post_id: postId,
                url: url
            },
            success: function(response) {
                if (response.success) {
                    $button.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                        if ($('.wp-list-table tbody tr:visible').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    alert(response.data.message || 'Error removing link');
                }
            },
            error: function() {
                alert('Error removing link');
            },
            complete: function() {
                $button.prop('disabled', false);
                $button.text('Unlink');
            }
        });
    });

    // Bulk action handler
    $('#doaction, #doaction2').on('click', function(e) {
        e.preventDefault();
        var $select = $(this).prev('select');
        var action = $select.val();
        if (action === '-1') return;

        var $checked = $('.link-cb:checked');
        if ($checked.length === 0) {
            alert('Please select at least one link.');
            return;
        }

        var items = [];
        $checked.each(function() {
            items.push({
                post_id: $(this).data('post-id'),
                url: $(this).data('url')
            });
        });

        $.ajax({
            url: linkmaster.ajax_url,
            type: 'POST',
            data: {
                action: 'linkmaster_bulk_action',
                nonce: linkmaster.bulk_nonce,
                bulk_action: action,
                items: JSON.stringify(items)
            },
            success: function(response) {
                if (response.success) {
                    $checked.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                        if ($('.wp-list-table tbody tr:visible').length === 0) {
                            location.reload();
                        }
                    });
                    showScanNotification(response.data.message || linkmaster.bulk_success);
                } else {
                    alert(response.data.message || linkmaster.bulk_error);
                }
            },
            error: function() {
                alert(linkmaster.bulk_error);
            }
        });
    });

    // URL validation
    $('.new-link-url').on('input', function() {
        var $input = $(this);
        var $saveButton = $input.closest('.link-edit').find('.save-link');
        var urlPattern = /^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/i;
        if (!urlPattern.test($input.val()) || $input.val().trim() === '') {
            $input.addClass('error');
            $saveButton.prop('disabled', true);
        } else {
            $input.removeClass('error');
            $saveButton.prop('disabled', false);
        }
    });

    // Keyboard shortcuts
    $('.new-link-url').on('keydown', function(e) {
        var $input = $(this);
        var $row = $input.closest('tr');
        if (e.key === 'Enter' && !$input.hasClass('error')) {
            $row.find('.save-link').click();
            e.preventDefault();
        } else if (e.key === 'Escape') {
            $row.find('.cancel-edit').click();
            e.preventDefault();
        }
    });

    // Handle select all checkbox
    $('#cb-select-all').on('change', function() {
        $('.link-cb').prop('checked', $(this).prop('checked'));
    });

    // Handle filter form submission (enhance form with AJAX if desired)
    $('.linkmaster-tablenav.top').on('submit', function(e) {
        // Let the form submit naturally - no need to prevent default
        // The form already has the correct method="get" and inputs
    });

    // Handle pagination input
    $('#current-page-selector').on('keydown', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            var page = parseInt($(this).val());
            var maxPage = parseInt($('.total-pages').text()) || 1;
            if (page > 0 && page <= maxPage) {
                var url = new URL(window.location.href);
                url.searchParams.set('paged', page);
                window.location.href = url.toString();
            } else {
                alert('Please enter a valid page number between 1 and ' + maxPage);
            }
        }
    });

    // Copy URL functionality
    $('.copy-url').on('click', function() {
        var $button = $(this);
        var url = $button.data('url');
        navigator.clipboard.writeText(url).then(function() {
            $button.addClass('copied').text('Copied!');
            setTimeout(function() {
                $button.removeClass('copied').html('<span class="dashicons dashicons-clipboard"></span>');
            }, 2000);
        }).catch(function() {
            alert('Failed to copy URL');
        });
    });
});