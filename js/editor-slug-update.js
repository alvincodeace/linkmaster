jQuery(document).ready(function ($) {
    var permalinkTimer;
    var isUpdating = false;
    var lastPermalink = '';
    var postId = $('#post_ID').val();

    function initPermalinkHandler() {
        // Watch for input changes in the custom permalink field
        $(document).on('input', '#lmcp_custom_permalink', function () {
            var customPermalink = $(this).val().trim();
            
            // Clear any existing timer
            clearTimeout(permalinkTimer);
            
            // Show saving indicator
            $('#lmcp-save-status')
                .removeClass('hidden')
                .find('.status-text')
                .text('Saving...')
                .css('color', '#666');

            // Don't save if it's the same as last saved value
            if (customPermalink === lastPermalink) {
                $('#lmcp-save-status').addClass('hidden');
                return;
            }

            // Set a new timer to save after user stops typing
            permalinkTimer = setTimeout(function() {
                if (isUpdating) return;
                isUpdating = true;

                // Save the permalink
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'lmcp_save_permalink',
                        post_id: postId,
                        custom_permalink: customPermalink,
                        nonce: $('#lmcp_custom_permalink_nonce').val(),
                        post_type: $('#post_type').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            lastPermalink = customPermalink;
                            
                            // Update only the preview and status parts
                            if (response.data && response.data.permalink) {
                                // Update preview links
                                $('#preview-action a').attr('href', response.data.permalink);
                                $('#view-post-btn a').attr('href', response.data.permalink);
                                
                                // Update the permalink preview
                                $('.lmcp-permalink-wrap .description code').text(response.data.permalink);
                            }

                            // Show success status briefly
                            $('#lmcp-save-status .status-text')
                                .text('Saved')
                                .css('color', '#00a32a');

                            setTimeout(function() {
                                $('#lmcp-save-status').addClass('hidden');
                            }, 2000);
                        } else {
                            // Show error status
                            $('#lmcp-save-status .status-text')
                                .text('Error saving')
                                .css('color', '#dc3232');
                        }
                    },
                    error: function() {
                        // Show error status
                        $('#lmcp-save-status .status-text')
                            .text('Error saving')
                            .css('color', '#dc3232');
                    },
                    complete: function() {
                        isUpdating = false;
                    }
                });
            }, 1000); // Wait 1 second after user stops typing
        });
    }

    // Initialize the permalink handler
    initPermalinkHandler();

    // Handle form submission
    $('#post').on('submit', function() {
        clearTimeout(permalinkTimer); // Clear any pending permalink updates
    });
});
