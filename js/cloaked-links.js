/* global jQuery, linkmaster_cloaked */
(function($) {
    'use strict';

    const CloakedLinks = {
        init: function() {
            this.bindEvents();
            this.initializeTooltips();
            this.initializeDatepicker();
        },

        bindEvents: function() {
            $('#linkmaster-cloaked-form').on('submit', this.handleFormSubmit);
            $('.linkmaster-delete-link').on('click', this.handleDelete);
            $('#doaction, #doaction2').on('click', this.handleBulkAction);
            $('.linkmaster-copy-url').on('click', this.handleCopyUrl);
            $('#linkmaster-add-ip').on('click', this.addIpRestriction);
            $('.linkmaster-remove-ip').on('click', this.removeIpRestriction);
        },

        handleFormSubmit: function(e) {
            e.preventDefault();
            const $form = $(this);
            const $submit = $form.find('button[type="submit"]');
            
            $submit.prop('disabled', true);
            
            const formData = new FormData($form[0]);
            formData.append('action', 'linkmaster_save_cloaked_link');
            formData.append('nonce', linkmaster_cloaked.nonce);
            
            // Add clear flags for empty fields
            if (!$form.find('input[name="password"]').val()) {
                formData.append('clear_password', '1');
            }
            
            // Handle IP restrictions
            const ipInputs = $form.find('input[name="ip_restrictions[]"]');
            if (ipInputs.length === 0) {
                formData.append('clear_ip_restrictions', '1');
            }
            
            if (!$form.find('input[name="click_limit"]').val()) {
                formData.append('clear_click_limit', '1');
            }
            
            if (!$form.find('input[name="expiry_date"]').val()) {
                formData.append('clear_expiry', '1');
            }
            
            $.ajax({
                url: linkmaster_cloaked.ajax_url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect_url;
                    } else {
                        alert(response.data);
                        $submit.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $submit.prop('disabled', false);
                }
            });
        },

        handleDelete: function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete this link?')) {
                return;
            }

            const $link = $(this);
            const linkId = $link.data('id');
            
            $.post(linkmaster_cloaked.ajax_url, {
                action: 'linkmaster_delete_cloaked_link',
                nonce: linkmaster_cloaked.nonce,
                id: linkId
            }, function(response) {
                if (response.success) {
                    $link.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data);
                }
            });
        },

        handleBulkAction: function(e) {
            const action = $('#bulk-action-selector-top, #bulk-action-selector-bottom').val();
            if (!action) {
                return;
            }

            const ids = [];
            $('input[name="link[]"]:checked').each(function() {
                ids.push($(this).val());
            });

            if (!ids.length) {
                alert('Please select at least one link.');
                e.preventDefault();
                return;
            }

            if (action === 'delete' && !confirm('Are you sure you want to delete the selected links?')) {
                e.preventDefault();
                return;
            }
        },

        handleCopyUrl: function(e) {
            e.preventDefault();
            const $button = $(this);
            const url = $button.data('url');
            
            const textarea = document.createElement('textarea');
            textarea.value = url;
            document.body.appendChild(textarea);
            textarea.select();
            
            try {
                document.execCommand('copy');
                $button.text('Copied!');
                setTimeout(() => {
                    $button.text('Copy URL');
                }, 2000);
            } catch (err) {
                console.error('Failed to copy URL:', err);
            }
            
            document.body.removeChild(textarea);
        },

        addIpRestriction: function(e) {
            e.preventDefault();
            const template = $('#ip-restriction-template').html();
            $('.ip-restrictions').append(template);
        },

        removeIpRestriction: function(e) {
            e.preventDefault();
            $(this).closest('.ip-restriction').remove();
        },

        initializeTooltips: function() {
            $('.linkmaster-tooltip').each(function() {
                const $tooltip = $(this);
                const content = $tooltip.data('tooltip');
                
                $tooltip.attr('title', content);
            });
        },

        initializeDatepicker: function() {
            if ($.fn.datepicker) {
                $('.linkmaster-datepicker').datepicker({
                    dateFormat: 'yy-mm-dd',
                    minDate: 0
                });
            }
        },

        validateIpRange: function(range) {
            // Basic IP range validation
            const ipv4Regex = /^(\d{1,3}\.){3}\d{1,3}$/;
            const cidrRegex = /^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/;
            const rangeRegex = /^(\d{1,3}\.){3}\d{1,3}-(\d{1,3}\.){3}\d{1,3}$/;
            
            if (range.includes('/')) {
                return cidrRegex.test(range);
            } else if (range.includes('-')) {
                return rangeRegex.test(range);
            }
            return ipv4Regex.test(range);
        }
    };

    $(document).ready(function() {
        CloakedLinks.init();
    });

})(jQuery);
