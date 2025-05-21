jQuery(document).ready(function($) {
    // Wait for Chart.js to load
    setTimeout(function() {
        var ctx = document.getElementById('clicks-chart');
        if (!ctx) {
            console.error('Chart canvas element not found');
            return;
        }
        
        // Add loading indicator
        $(ctx).parent().append('<div class="chart-loading"><div class="spinner"></div><span>Loading data...</span></div>');

        var chart = null;
        var allStats = null;
        var currentPage = 1;
        var itemsPerPage = 20;
        var chartType = 'pie'; // Default chart type

        // Add chart type toggle buttons
        $('.chart-type-toggle').html('<button type="button" data-type="pie" class="chart-type-btn active">Pie</button><button type="button" data-type="bar" class="chart-type-btn">Bar</button>');
        
        // Handle chart type toggle
        $('.chart-type-btn').on('click', function() {
            $('.chart-type-btn').removeClass('active');
            $(this).addClass('active');
            chartType = $(this).data('type');
            initChart();
        });

        // Pagination and modal functionality removed - now using WordPress pagination in the detailed stats tab

        function initChart() {
            // Show loading indicator
            $('.chart-loading').show();
            
            $.ajax({
                url: linkmaster.ajax_url,
                type: 'POST',
                data: {
                    action: 'linkmaster_get_redirect_stats',
                    nonce: linkmaster.stats_nonce
                },
                success: function(response) {
                    // Hide loading indicator
                    $('.chart-loading').hide();
                    
                    if (response.success && response.data) {
                        allStats = response.data;
                        
                        var labels = [];
                        var clicks = [];
                        var colors = [];
                        var hoverColors = [];
                        
                        var sortedRedirects = Object.entries(allStats)
                            .sort((a, b) => b[1].hits - a[1].hits);
                        
                        // Generate consistent colors with better palette
                        var baseColors = [
                            '#3366cc', '#dc3912', '#ff9900', '#109618', '#990099', '#0099c6'
                        ];
                        
                        // Only show top 5 links
                        sortedRedirects.slice(0, 5).forEach(function([id, redirect], index) {
                            // Truncate long URLs
                            var displayUrl = redirect.source_url;
                            if (displayUrl.length > 30) {
                                displayUrl = displayUrl.substring(0, 27) + '...';
                            }
                            labels.push(displayUrl);
                            clicks.push(redirect.hits);
                            colors.push(baseColors[index % baseColors.length]);
                            
                            // Create slightly darker hover colors
                            var color = baseColors[index % baseColors.length];
                            hoverColors.push(adjustColorBrightness(color, -15));
                        });

                        // Group remaining links as "Others"
                        if (sortedRedirects.length > 5) {
                            var otherClicks = 0;
                            var otherCount = sortedRedirects.length - 5;
                            sortedRedirects.slice(5).forEach(function([id, redirect]) {
                                otherClicks += redirect.hits;
                            });
                            if (otherClicks > 0) {
                                labels.push(`Others (${otherCount} links)`);
                                clicks.push(otherClicks);
                                colors.push('#808080');
                                hoverColors.push('#666666');
                            }
                        }

                        if (chart) {
                            chart.destroy();
                        }

                        if (clicks.length === 0) {
                            $(ctx).parent().html('<div class="no-data">No click data available</div>');
                            return;
                        }
                        
                        // Add animation effect
                        $(ctx).css('opacity', 0).animate({opacity: 1}, 500);

                        // Create chart configuration based on selected type
                        var chartConfig = {
                            type: chartType,
                            data: {
                                labels: labels,
                                datasets: [{
                                    data: clicks,
                                    backgroundColor: colors,
                                    hoverBackgroundColor: hoverColors,
                                    borderWidth: 1,
                                    borderColor: '#ffffff',
                                    borderRadius: chartType === 'bar' ? 6 : 0,
                                    label: 'Clicks'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                layout: {
                                    padding: {
                                        left: 20,
                                        right: 20,
                                        top: 20,
                                        bottom: 20
                                    }
                                },
                                animation: {
                                    duration: 1000,
                                    easing: 'easeOutQuart'
                                },
                                plugins: {
                                    legend: {
                                        position: chartType === 'pie' ? 'right' : 'top',
                                        align: 'center',
                                        labels: {
                                            boxWidth: 15,
                                            padding: 15,
                                            font: {
                                                size: 12,
                                                family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
                                            },
                                            generateLabels: function(chart) {
                                                const data = chart.data;
                                                if (data.labels.length && data.datasets.length) {
                                                    return data.labels.map((label, i) => ({
                                                        text: label + ': ' + data.datasets[0].data[i] + ' clicks',
                                                        fillStyle: data.datasets[0].backgroundColor[i],
                                                        hidden: isNaN(data.datasets[0].data[i]),
                                                        index: i
                                                    }));
                                                }
                                                return [];
                                            }
                                        }
                                    },
                                    tooltip: {
                                        backgroundColor: 'rgba(0,0,0,0.8)',
                                        titleFont: {
                                            size: 14,
                                            family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
                                        },
                                        bodyFont: {
                                            size: 13,
                                            family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
                                        },
                                        padding: 12,
                                        cornerRadius: 6,
                                        callbacks: {
                                            label: function(context) {
                                                var label = context.label || '';
                                                var value = chartType === 'pie' ? context.parsed : context.parsed.y;
                                                var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                                var percentage = ((value / total) * 100).toFixed(1);
                                                return label + ': ' + value + ' clicks (' + percentage + '%)';
                                            }
                                        }
                                    }
                                }
                            }
                        };
                        
                        // Add specific options for bar chart
                        if (chartType === 'bar') {
                            chartConfig.options.scales = {
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        font: {
                                            size: 11,
                                            family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
                                        }
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: 'rgba(0,0,0,0.05)'
                                    },
                                    ticks: {
                                        precision: 0,
                                        font: {
                                            size: 11,
                                            family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
                                        }
                                    }
                                }
                            };
                        }
                        
                        chart = new Chart(ctx, chartConfig);
                    } else {
                        console.error('Failed to load chart data:', response);
                        $('.chart-loading').hide();
                        $(ctx).parent().html('<div class="no-data">Failed to load chart data</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    $('.chart-loading').hide();
                    $(ctx).parent().html('<div class="no-data">Error loading data: ' + error + '</div>');
                }
            });
        }

        // Initialize chart
        initChart();

        // Handle window resize
        var resizeTimer;
        $(window).on('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (chart) {
                    chart.resize();
                }
            }, 250);
        });
        
        // Helper function to adjust color brightness
        function adjustColorBrightness(hex, percent) {
            // Convert hex to RGB
            var r = parseInt(hex.substring(1, 3), 16);
            var g = parseInt(hex.substring(3, 5), 16);
            var b = parseInt(hex.substring(5, 7), 16);

            // Adjust brightness
            r = Math.max(0, Math.min(255, r + percent));
            g = Math.max(0, Math.min(255, g + percent));
            b = Math.max(0, Math.min(255, b + percent));

            // Convert back to hex
            return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
        }
    }, 500);
});
