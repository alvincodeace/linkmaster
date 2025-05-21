jQuery(document).ready(function($) {
    // Initialize health score widget
    function initHealthScore(showLoading = true) {
        var container = $('#linkmaster-health-score-container');
        if (container.length === 0) return;
        
        // Show loading indicator only if requested and content is not already visible
        if (showLoading && !$('.linkmaster-score-content').is(':visible')) {
            $('.linkmaster-score-content').fadeOut(100, function() {
                $('.linkmaster-score-loading').fadeIn(100);
            });
        }
        
        // Add animation to refresh button only when manually refreshing
        if (showLoading) {
            $('#refresh-health-score .dashicons').addClass('spin');
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'linkmaster_get_health_score',
                nonce: linkmaster_health.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    updateHealthScoreUI(response.data, showLoading);
                } else {
                    showError('Failed to load health score data');
                }
                // Stop refresh button animation
                $('#refresh-health-score .dashicons').removeClass('spin');
            },
            error: function() {
                showError('Error connecting to server');
                // Stop refresh button animation
                $('#refresh-health-score .dashicons').removeClass('spin');
            }
        });
    }
    
    function updateHealthScoreUI(data, showLoading = true) {
        // Only show loading/fade effects if showLoading is true
        if (showLoading) {
            $('.linkmaster-score-loading').fadeOut(100, function() {
                $('.linkmaster-score-content').fadeIn(100);
            });
        }
        
        // Update score value and status with animation
        var scoreValue = data.health_score;
        var scoreStatus = getScoreStatus(scoreValue);
        
        // Remove any existing status classes
        $('.linkmaster-score-circle').removeClass('excellent good fair poor');
        
        // Animate the score value
        var $scoreValue = $('.linkmaster-score-value');
        var currentScore = parseInt($scoreValue.text()) || 0;
        $({ score: currentScore }).animate({ score: scoreValue }, {
            duration: 600,
            easing: 'swing',
            step: function() {
                $scoreValue.text(Math.round(this.score));
            },
            complete: function() {
                $scoreValue.text(scoreValue);
                // Add the new status class after animation
                $('.linkmaster-score-circle').addClass(scoreStatus);
            }
        });
        
        // Update stats with count-up animation
        animateCounter('.linkmaster-stat-value.total-links', data.total_links || 0);
        animateCounter('.linkmaster-stat-value.healthy-links', data.healthy_links || 0);
        animateCounter('.linkmaster-stat-value.broken-links', data.broken_links || 0);
        animateCounter('.linkmaster-stat-value.redirected-links', data.redirected_links || 0);
        
        // Reset bar segments to 0 width first
        $('.linkmaster-bar-segment.healthy, .linkmaster-bar-segment.broken').css('width', '0%');
        
        // Update breakdown percentages with animation after a short delay
        setTimeout(function() {
            $('.linkmaster-bar-segment.healthy').css('width', data.score_breakdown.healthy + '%');
            $('.linkmaster-bar-segment.broken').css('width', data.score_breakdown.broken + '%');
            
            // Show redirected segment if available
            if (data.score_breakdown.redirected && data.score_breakdown.redirected > 0) {
                $('.linkmaster-bar-segment.redirected').css('width', data.score_breakdown.redirected + '%').show();
                $('.linkmaster-legend-value.redirected-percent').text(data.score_breakdown.redirected + '%').parent().show();
                animateCounter('.linkmaster-legend-value.redirected-percent', data.score_breakdown.redirected, '%');
            } else {
                // Hide redirected segment
                $('.linkmaster-bar-segment.redirected').css('width', '0%').hide();
                $('.linkmaster-legend-value.redirected-percent').parent().hide();
            }
            
            // Update percentage labels with animation
            animateCounter('.linkmaster-legend-value.healthy-percent', data.score_breakdown.healthy, '%');
            animateCounter('.linkmaster-legend-value.broken-percent', data.score_breakdown.broken, '%');
        }, 150);
        
        // Show top issues - only show broken links (filter out redirects)
        if (data.top_issues && data.top_issues.length > 0) {
            var issuesList = $('.linkmaster-issues-list');
            issuesList.empty();
            
            // Filter to only include broken links
            var brokenIssues = [];
            $.each(data.top_issues, function(index, issue) {
                // Strict filtering to exclude redirects and include only broken links
                // Check if explicitly marked as broken
                if (issue.status && issue.status === 'broken') {
                    brokenIssues.push(issue);
                }
                // Or has an error status code (4xx or 5xx)
                else if (issue.status_code && issue.status_code >= 400) {
                    brokenIssues.push(issue);
                }
                // Explicitly exclude redirects
                else if (issue.status && (issue.status === 'redirect' || issue.status.indexOf('redirect') >= 0)) {
                    // Skip these
                }
                else if (issue.status_code && issue.status_code >= 300 && issue.status_code < 400) {
                    // Skip redirect status codes (300-399)
                }
                // Include unknown status if needed
                else if (!issue.status && !issue.status_code) {
                    brokenIssues.push(issue);
                }
            });
            
            if (brokenIssues.length > 0) {
                $.each(brokenIssues, function(index, issue) {
                    var issueItem = $('<li class="linkmaster-issue-item"></li>');
                    issueItem.append('<div class="issue-url">' + issue.url + '</div>');
                    
                    // Check if source exists before adding it
                    if (issue.source && issue.source.trim() !== '') {
                        issueItem.append('<div class="issue-source"><strong>Found in:</strong> ' + issue.source + '</div>');
                    } else {
                        issueItem.append('<div class="issue-source"><strong>Found in:</strong> Unknown location</div>');
                    }
                    
                    // Add with fade-in effect
                    issueItem.hide();
                    issuesList.append(issueItem);
                    issueItem.delay(index * 50).fadeIn(150); // Faster animation
                });
                
                $('.linkmaster-top-issues').fadeIn(150);
            } else {
                $('.linkmaster-top-issues').hide();
            }
        } else {
            $('.linkmaster-top-issues').hide();
        }
    }
    
    // Helper function to animate counters
    function animateCounter(selector, targetValue, suffix) {
        suffix = suffix || '';
        var $element = $(selector);
        var currentValue = parseInt($element.text()) || 0;
        
        $({ value: currentValue }).animate({ value: targetValue }, {
            duration: 600, // Reduced animation time
            easing: 'swing',
            step: function() {
                $element.text(Math.round(this.value) + suffix);
            },
            complete: function() {
                $element.text(targetValue + suffix);
            }
        });
    }
    
    function showError(message) {
        $('.linkmaster-score-loading').html('<p class="error"><span class="dashicons dashicons-warning"></span> ' + message + '</p>');
    }
    
    function getScoreStatus(score) {
        if (score >= 90) {
            return 'excellent';
        } else if (score >= 75) {
            return 'good';
        } else if (score >= 50) {
            return 'fair';
        } else {
            return 'poor';
        }
    }
    
    // Add refresh button functionality
    $('#refresh-health-score').on('click', function() {
        // Make sure spin is removed in case of previous errors
        $('#refresh-health-score .dashicons').removeClass('spin');
        // Start new refresh
        initHealthScore(true);
        
        // Safety timeout to remove spin class after 10 seconds if AJAX fails
        setTimeout(function() {
            $('#refresh-health-score .dashicons').removeClass('spin');
        }, 10000);
    });
    
    // Add scan now button functionality
    $('#linkmaster-scan-now').on('click', function(e) {
        e.preventDefault();
        
        // Show confirmation dialog
        if (confirm('Start a full link scan? This may take a few minutes depending on your site size.')) {
            // Show loading state
            var $button = $(this);
            var $icon = $button.find('.dashicons');
            var $title = $button.find('.linkmaster-action-title');
            var $desc = $button.find('.linkmaster-action-desc');
            
            // Save original text
            var originalTitle = $title.text();
            var originalDesc = $desc.text();
            
            // Update button state
            $button.addClass('scanning');
            $icon.addClass('spin');
            $title.text('Scanning...');
            $desc.text('Please wait');
            
            // Make AJAX request to start scan
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'linkmaster_manual_scan',
                    nonce: linkmaster_health.nonce || $('#linkmaster-health-nonce').val() // Fallback to get nonce from hidden field
                },
                success: function(response) {
                    // Reset button state
                    $button.removeClass('scanning');
                    $icon.removeClass('spin');
                    
                    if (response.success) {
                        // Show success message
                        $title.text('Scan Complete');
                        $desc.text('Refreshing data...');
                        
                        // Refresh health score after scan
                        setTimeout(function() {
                            initHealthScore(true);
                            
                            // Reset button text after a delay
                            setTimeout(function() {
                                $title.text(originalTitle);
                                $desc.text(originalDesc);
                            }, 2000);
                        }, 1000);
                    } else {
                        // Show error message
                        $title.text('Scan Failed');
                        $desc.text('Try again later');
                        
                        // Reset button text after a delay
                        setTimeout(function() {
                            $title.text(originalTitle);
                            $desc.text(originalDesc);
                        }, 3000);
                    }
                },
                error: function() {
                    // Reset button state and show error
                    $button.removeClass('scanning');
                    $icon.removeClass('spin');
                    $title.text('Error');
                    $desc.text('Connection failed');
                    
                    // Reset button text after a delay
                    setTimeout(function() {
                        $title.text(originalTitle);
                        $desc.text(originalDesc);
                    }, 3000);
                }
            });
        }
    });
    
    // Add CSS for animations and dynamic elements
    $('<style>\n\
        @keyframes spin {\n\
            0% { transform: rotate(0deg); }\n\
            100% { transform: rotate(360deg); }\n\
        }\n\
        .spin {\n\
            animation: spin 1s linear infinite;\n\
        }\n\
        .linkmaster-card-header {\n\
            display: flex;\n\
            justify-content: space-between;\n\
            align-items: center;\n\
        }\n\
        .linkmaster-refresh-btn {\n\
            background: transparent;\n\
            border: 1px solid #ccd0d4;\n\
            border-radius: 4px;\n\
            padding: 4px 10px;\n\
            cursor: pointer;\n\
            display: flex;\n\
            align-items: center;\n\
            gap: 5px;\n\
            transition: all 0.2s ease;\n\
        }\n\
        .linkmaster-refresh-btn:hover {\n\
            background: #f0f0f1;\n\
            border-color: #999;\n\
        }\n\
        .linkmaster-version {\n\
            font-size: 14px;\n\
            color: #646970;\n\
            font-weight: normal;\n\
            margin-left: 10px;\n\
        }\n\
        .linkmaster-action-item.scanning {\n\
            background-color: rgba(34, 113, 177, 0.1);\n\
            pointer-events: none;\n\
        }\n\
        .linkmaster-issues-list li {\n\
            animation-delay: calc(var(--i, 0) * 100ms);\n\
        }\n\
    </style>').appendTo('head');
    
    // Set up auto-refresh every 5 minutes
    setInterval(function() {
        initHealthScore(false); // Pass false to prevent showing loading indicator
    }, 300000); // 5 minutes in milliseconds
    
    // Initial load with loading indicator
    initHealthScore(true);
});
