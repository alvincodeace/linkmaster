jQuery(document).ready(function($) {
    // Initialize tooltips if any
    if (typeof $.fn.tooltip !== 'undefined') {
        $('.linkmaster-tooltip').tooltip();
    }

    // Handle filter form submission
    $('#permalink-search-input').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).closest('form').submit();
        }
    });

    // Handle post type filter change
    $('#post-type-filter').on('change', function() {
        $(this).closest('form').submit();
    });

    // Handle settings form submission
    $('.linkmaster-settings-form').on('submit', function() {
        // Add loading state
        var $submitButton = $(this).find(':submit');
        var originalText = $submitButton.val();
        $submitButton.val('Saving...').prop('disabled', true);

        // Remove loading state after WordPress handles the submission
        setTimeout(function() {
            $submitButton.val(originalText).prop('disabled', false);
        }, 1000);
    });

    // Handle permalink preview
    $('.preview-permalink').on('click', function(e) {
        e.preventDefault();
        var permalink = $(this).data('permalink');
        var $modal = $('#permalink-preview-modal');
        
        if ($modal.length === 0) {
            $modal = $('<div id="permalink-preview-modal" class="modal">' +
                '<div class="modal-content">' +
                '<span class="close">&times;</span>' +
                '<iframe src="' + permalink + '" style="width:100%;height:500px;border:0;"></iframe>' +
                '</div></div>');
            $('body').append($modal);
        }
        
        $modal.show();
    });

    // Handle modal close
    $(document).on('click', '.modal .close', function() {
        $('#permalink-preview-modal').hide();
    });

    // Close modal on outside click
    $(window).on('click', function(e) {
        var $modal = $('#permalink-preview-modal');
        if ($(e.target).is($modal)) {
            $modal.hide();
        }
    });
}); 