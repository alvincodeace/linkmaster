/**
  * LinkMaster Guided Tour
  */
(function($) {
    'use strict';

    // Tour steps configuration
    const tourSteps = [
        {
            element: '.linkmaster-health-score-container',
            title: 'Dashboard Overview',
            intro: 'Welcome to LinkMaster! This dashboard gives you a complete overview of your link health and performance.',
            position: 'bottom'
        },
        {
            element: '.linkmaster-score-circle',
            title: 'Health Score',
            intro: 'This score represents the overall health of your links. A higher percentage means fewer broken links. Watch the animation as your score improves!',
            position: 'right'
        },
        {
            element: '.linkmaster-stat-item:nth-child(1)',
            title: 'Total Links',
            intro: 'This shows the total number of links detected on your site.',
            position: 'bottom'
        },
        {
            element: '.linkmaster-stat-item:nth-child(2)',
            title: 'Healthy Links',
            intro: 'See how many healthy links are on your site that are working properly.',
            position: 'bottom'
        },
        {
            element: '.linkmaster-stat-item:nth-child(3)',
            title: 'Broken Links',
            intro: 'This shows how many broken links were detected on your site that need to be fixed.',
            position: 'bottom'
        },
        {
            element: '.linkmaster-stat-item:nth-child(4)',
            title: 'Redirected Links',
            intro: 'See how many redirected links are currently active on your site.',
            position: 'bottom'
        },
        {
            element: 'a[href*="page=linkmaster-broken-links"]',
            title: 'Broken Links',
            intro: 'Access the Broken Links page to view and fix all detected broken links.',
            position: 'right'
        },
        {
            element: 'a[href*="page=linkmaster-redirections"]',
            title: 'Redirects & Stats',
            intro: 'Manage all your redirects from this page, including editing and deleting them. You can also view click statistics for your links in the Click Stats tab.',
            position: 'right'
        },
        {
            element: 'a[href*="page=linkmaster-custom-permalinks"]',
            title: 'Custom Permalinks',
            intro: 'Create and manage custom permalinks for your content, even with special characters that WordPress normally does not support.',
            position: 'right'
        },
        {
            element: 'a[href*="page=linkmaster-cloaked-links"]',
            title: 'Cloaked Links',
            intro: 'Create protected links with password protection, IP restrictions, expiration dates, and SEO attributes like nofollow and open in new window.',
            position: 'right'
        },
        {
            element: 'a[href*="page=linkmaster-auto-links"]',
            title: 'Auto Links',
            intro: 'Automatically insert links in your content based on keywords with nofollow and open-in-new-window options.',
            position: 'right'
        },
        {
            element: 'a[href*="page=linkmaster-custom-404"]',
            title: 'Custom 404 Pages',
            intro: 'Create and manage custom 404 error pages for different sections of your site. Improve user experience by showing relevant content when pages are not found.',
            position: 'right'
        }
    ];

    // Initialize the tour
    function initTour() {
        // Show welcome modal first
        $('.linkmaster-tour-overlay').fadeIn(200);
        $('#linkmaster-tour-welcome').fadeIn(200);

        // Start tour button
        $('#linkmaster-start-tour').on('click', function() {
            $('.linkmaster-tour-overlay').fadeOut(200);
            $('#linkmaster-tour-welcome').fadeOut(200);
            startTour();
        });

        // Skip tour button
        $('#linkmaster-skip-tour').on('click', function() {
            $('.linkmaster-tour-overlay').fadeOut(200);
            $('#linkmaster-tour-welcome').fadeOut(200);
            dismissTour();
        });
    }

    // Start the actual tour
    function startTour() {
        const intro = introJs();

        intro.setOptions({
            steps: tourSteps,
            showStepNumbers: false,
            showBullets: true,
            showProgress: true,
            hideNext: false,
            hidePrev: false,
            nextLabel: 'Next →',
            prevLabel: '← Back',
            skipLabel: 'Skip',
            doneLabel: 'Finish',
            tooltipClass: 'linkmaster-tour-tooltip',
            highlightClass: 'linkmaster-tour-highlight',
            exitOnOverlayClick: false,
            exitOnEsc: true,
            scrollToElement: true
        });

        // When tour is completed or skipped
        intro.oncomplete(function() {
            dismissTour();
        });

        intro.onexit(function() {
            dismissTour();
        });

        // Start the tour
        intro.start();
    }

    // Dismiss the tour and save preference
    function dismissTour() {
        $.ajax({
            url: linkmasterTour.ajaxurl,
            type: 'POST',
            data: {
                action: 'linkmaster_dismiss_tour',
                nonce: linkmasterTour.nonce
            },
            success: function(response) {
                console.log('Tour dismissed');
            }
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        if (typeof linkmasterTour !== 'undefined' && linkmasterTour.startTour) {
            // Wait a moment for the page to fully render
            setTimeout(initTour, 1000);
        }
    });

})(jQuery);