/**
 * dashboard.js
 * Handles dynamic loading and updating of dashboard analytics charts.
 */

$(document).ready(function() {
    const metricsUrl = window.SBMSDashboardConfig.metricsUrl;
    const notificationsUrl = window.SBMSDashboardConfig.notificationsUrl;
    const markReadUrl = window.SBMSDashboardConfig.markReadUrl;
    const markAllReadUrl = window.SBMSDashboardConfig.markAllReadUrl;
    const appIconUrl = window.SBMSDashboardConfig.appIconUrl;
    let lastUnreadCount = window.SBMSDashboardConfig.initialUnreadCount || 0;
    let riskDistributionChart;
    let classComparisonChart;

    const refreshButton = $('#refreshAnalyticsButton');
    const chartLoaders = $('.analytics-spinner');
    const notificationList = $('#dashboardNotificationList');
    const dashboardFeedback = $('#dashboardFeedback');
    const topOffendersList = $('#topOffendersList');
    const classChartEmpty = $('#classChartEmpty');
    const termSwitcherForm = $('.term-switcher-form');
    const currentTermSelect = $('select[name="current_term"]');
    const muteToggle = $('#muteSoundToggle');
    let currentNotificationScopeHtml = '';

    let notificationPermission = 'default'; // 'default', 'granted', 'denied'

    // Function to request notification permission
    function requestNotificationPermission() {
        if (!('Notification' in window)) {
            console.warn('Browser does not support desktop notifications.');
            return;
        }
    
        if (Notification.permission === 'default') {
            Notification.requestPermission().then(permission => {
                notificationPermission = permission;
                if (permission === 'granted') {
                    console.log('Desktop notification permission granted.');
                } else {
                    console.warn('Desktop notification permission denied.');
                }
            });
        } else {
            notificationPermission = Notification.permission;
        }
    }

    // Initialize mute state from localStorage
    let isMuted = localStorage.getItem('sbms_muted') === 'true';
    muteToggle.prop('checked', isMuted);

    muteToggle.on('change', function() {
        isMuted = $(this).is(':checked');
        localStorage.setItem('sbms_muted', isMuted);
    });

    // Administrative submenu is toggled by vanilla JS in includes/sidebar.php
    // so it works on every page, not just the dashboard.

    // Function to play sound alert
    function playNotificationSound(priority) {
        if (isMuted) return;

        let playSoundForPriority = false;
        if (priority === 'high') {
            playSoundForPriority = localStorage.getItem('sbms_sound_priority_high') !== 'false';
        } else if (priority === 'medium') {
            playSoundForPriority = localStorage.getItem('sbms_sound_priority_medium') !== 'false';
        } else { // 'low' or any other default
            playSoundForPriority = localStorage.getItem('sbms_sound_priority_low') !== 'false';
        }

        if (!playSoundForPriority) return;

        const customSound = localStorage.getItem('sbms_custom_sound_url');
        const audio = document.getElementById('notificationSound');
        if (audio) {
            const originalSrc = audio.src; // Store original src
            if (customSound) {
                audio.src = customSound;
            }
            audio.play().catch(() => { /* Browser may block autoplay until interaction */ });
            // Restore original src after a short delay to allow playback to start
            setTimeout(() => audio.src = originalSrc, 1000); 
        }
    }

    // Function to display a desktop notification
    function displayDesktopNotification(title, body) {
        const desktopEnabled = localStorage.getItem('sbms_desktop_enabled') !== 'false';
        if (notificationPermission === 'granted' && desktopEnabled) {
            if (!('Notification' in window)) {
                return;
            }
            new Notification(title, {
                body: body,
                icon: appIconUrl
            });
        }
    }

    // Function to update the notification badge count in the navigation
    function updateBadge(count) {
        const $badge = $('#sidebarNotifBadge');
        if (count > 0) {
            $badge.text(count).show();
        } else {
            $badge.hide();
        }
    }

    // Function to show feedback messages
    function showFeedback(message, type = 'error') {
        dashboardFeedback.removeClass('success error').addClass(type).text(message).show();
    }

    // Function to hide feedback messages
    function hideFeedback() {
        dashboardFeedback.hide();
    }

    // Utility function to escape HTML
    function escapeHtml(unsafe) {
        if (!unsafe) return '';
        return String(unsafe)
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    // Function to render/update the Risk Distribution Chart
    function renderRiskDistributionChart(data) {
        const ctx = document.getElementById('riskDistributionChart').getContext('2d');
        if (riskDistributionChart) {
            riskDistributionChart.destroy(); // Destroy existing chart before re-rendering
        }
        riskDistributionChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: data.datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || 'white'
                        }
                    },
                    title: {
                        display: false,
                    }
                }
            }
        });
    }

    // Function to render/update the Class Comparison Chart
    function renderClassComparisonChart(data) {
        const chartCanvas = document.getElementById('classComparisonChart');
        const labels = Array.isArray(data?.labels) ? data.labels : [];
        const values = Array.isArray(data?.datasets?.[0]?.data) ? data.datasets[0].data.map(Number) : [];
        if (classComparisonChart) {
            classComparisonChart.destroy(); // Destroy existing chart before re-rendering
        }
        if (labels.length === 0 || values.length === 0) {
            chartCanvas.hidden = true;
            classChartEmpty.prop('hidden', false);
            return;
        }
        chartCanvas.hidden = false;
        classChartEmpty.prop('hidden', true);
        const ctx = chartCanvas.getContext('2d');
        const textColor = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || 'white';
        const gridColor = getComputedStyle(document.documentElement).getPropertyValue('--panel-border').trim() || 'rgba(255, 255, 255, 0.1)';
        const highestValue = Math.max(...values, 0);
        const scaleMax = Math.max(10, Math.ceil((highestValue * 1.15) / 10) * 10);
        const valueLabels = {
            id: 'classValueLabels',
            afterDatasetsDraw(chart) {
                const { ctx: chartContext } = chart;
                chartContext.save();
                chartContext.font = '600 10px Aptos, Manrope, Segoe UI, sans-serif';
                chartContext.fillStyle = textColor;
                chartContext.textAlign = 'center';
                chartContext.textBaseline = 'bottom';
                chart.getDatasetMeta(0).data.forEach((bar, index) => {
                    chartContext.fillText(`${values[index].toFixed(1)}%`, bar.x, bar.y - 5);
                });
                chartContext.restore();
            }
        };

        classComparisonChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: data.datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 18, right: 8, bottom: 4, left: 0 } },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: false,
                    },
                    title: {
                        display: false,
                    },
                    tooltip: { callbacks: { label: item => ` Average: ${Number(item.raw).toFixed(1)}%` } }
                },
                scales: {
                    x: {
                        ticks: {
                            color: textColor,
                            autoSkip: false,
                            maxRotation: 40,
                            minRotation: 25,
                            padding: 5
                        },
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: scaleMax,
                        ticks: {
                            color: textColor,
                            padding: 7,
                            callback: value => `${value}%`
                        },
                        grid: {
                            color: gridColor,
                            drawBorder: false
                        }
                    }
                }
            },
            plugins: [valueLabels]
        });
    }

    // Function to update Top Offenders List
    function updateTopOffendersList(data, termLabel) {
        topOffendersList.empty();
        if (data.length === 0) {
            topOffendersList.append(`
                <li class="behavior-empty-state">
                    <span class="behavior-empty-icon" aria-hidden="true">&#9673;</span>
                    <strong>No offenders to display.</strong>
                    <span>Great news! Keep up the good work.</span>
                </li>`);
            return;
        }
        const config = window.SBMSDashboardConfig || {};
        const canExpel = config.canExpel === true || config.canExpel === 'true';
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

        data.forEach(function(offender) {
            const hasOffenderId = Boolean(offender && offender.id);
            let expelFormHtml = '';
            if (canExpel && hasOffenderId) {
                expelFormHtml = `
                <form method="POST" style="margin: 0;" action="" onsubmit="return confirm('Are you sure you want to expel this student?');">
                    <input type="hidden" name="csrf_token" value="${csrfToken}">
                    <input type="hidden" name="action" value="add_expulsion">
                    <input type="hidden" name="student_id" value="${offender.id}">
                    <input type="hidden" name="reason" value="Top Offender (Behavior Signals)">
                    <button type="submit" title="Expel Student" style="background: transparent; border: 1px solid rgba(251, 113, 133, 0.3); color: #fb7185; border-radius: 4px; padding: 2px 6px; cursor: pointer; font-size: 0.75rem; font-weight: bold;">Expel</button>
                </form>`;
            }

            // Visible delete action for all rows that have a student id. The backend still enforces
            // the real admin/authorization check before clearing offense records.
            let deleteBtnHtml = '';
            if (hasOffenderId) {
                deleteBtnHtml = `<button data-student-id="${offender.id}" class="inline-btn delete-offender-btn" style="margin-left:8px; background:transparent; border:1px solid rgba(255,255,255,0.06); color:var(--brand-2); padding:6px 8px; border-radius:8px; font-weight:600;">Delete</button>`;
            }

            topOffendersList.append(
                `<li class="notification-item" style="padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 12px; border-left: 3px solid #f59e0b; padding-left: 10px; margin-bottom: 5px;">
                    <div style="min-width: 0;">
                        <p style="margin: 0; font-size: 0.9rem; color: #f8fafc; overflow-wrap: anywhere;">
                            <strong>${escapeHtml(offender.name)}</strong> (${escapeHtml(offender.code)})
                        </p>
                        <span style="display: inline-block; max-width: 100%; font-size: 0.75rem; color: #94a3b8; overflow-wrap: anywhere;">${escapeHtml(termLabel)} | Impact: <span style="color: #ef4444;">${offender.impact}</span> marks | Incidents: ${offender.incidents}</span>
                    </div>
                    <div style="justify-self: end; display:flex; gap:8px; align-items:center;">
                        ${expelFormHtml}
                        ${deleteBtnHtml}
                    </div>
                </li>`
            );
        });
    }

    // Delegate click handler for delete buttons in Behavior Signals
    $(document).off('click', '.delete-offender-btn');
    $(document).on('click', '.delete-offender-btn', function(ev) {
        ev.preventDefault();
        const btn = $(this);
        const sid = btn.data('student-id');
        if (!sid) return;
        if (!confirm('Clear offenses for this student? This cannot be undone.')) return;
        btn.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: window.location.origin + '/dashboard/delete_student.php',
            method: 'POST',
            data: { student_id: sid, csrf_token: $('input[name="csrf_token"]').val() },
            dataType: 'json'
        }).done(function(resp) {
            if (resp && resp.status === 'success') {
                // Refresh dashboard analytics
                fetchAndRenderCharts();
            } else {
                alert((resp && resp.message) || 'Unable to delete offender.');
                btn.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            alert('Server error while deleting offender.');
            btn.prop('disabled', false).text('Delete');
        });
    });

    // Function to fetch and render unread notifications
    function fetchNotifications() {
        if (!notificationList.length) return;

        $.ajax({
            url: notificationsUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function(response) {
            if (response.status === 'success') {
                if (response.unread_count > lastUnreadCount) {
                    let newestPriority = 'low';
                    const groupKeys = Object.keys(response.data || {});
                    if (groupKeys.length > 0 && response.data[groupKeys[0]].length > 0) {
                        newestPriority = response.data[groupKeys[0]][0].priority || 'low';
                    }

                    playNotificationSound(newestPriority);
                    displayDesktopNotification('New SBMS Alert', `You have ${response.unread_count} unread notifications.`);
                }
                lastUnreadCount = response.unread_count;

                updateBadge(response.unread_count);

                const grouped = response.data || {};
                const scope = response.scope || {};
                const groupKeys = Object.keys(grouped);
                const scopeTone = ['all', 'class', 'general'].includes(scope.tone) ? scope.tone : 'general';
                const scopeHtml = scope.label
                    ? `<p class="group-label" style="font-size: 0.7rem; color: var(--brand-2); text-transform: uppercase; letter-spacing: 0.05em; margin: 10px 0 5px; font-weight: 600;">
                           <span class="sidebar-scope-tag scope-${scopeTone}" style="margin-left: 0; margin-right: 0.5rem;">
                               <span aria-hidden="true" style="margin-right: 0.25rem;">${escapeHtml(scope.icon || '🔔')}</span>
                               ${escapeHtml(scope.short_label || scope.label)}
                           </span>
                           ${escapeHtml(scope.label)}
                       </p>
                       <p style="margin: 0 0 10px 0; font-size: 0.8rem; color: #94a3b8;">${escapeHtml(scope.description || '')}</p>`
                    : '';
                currentNotificationScopeHtml = scopeHtml;

                if (groupKeys.length === 0) {
                    notificationList.html(`${scopeHtml}<p class="placeholder-item" style="color: #64748b; font-size: 0.9rem;">No unread system alerts.</p>`);
                    $('#markAllReadBtn').hide();
                    return;
                }

                $('#markAllReadBtn').show();
                let html = scopeHtml;
                for (const label in grouped) {
                    html += `<p class="group-label" style="font-size: 0.7rem; color: var(--brand-2); text-transform: uppercase; letter-spacing: 0.05em; margin: 10px 0 5px; font-weight: 600;">${$('<div>').text(label).html()}</p>`;
                    
                    grouped[label].forEach(function(item) {
                        const priorityColor = item.priority === 'high' ? '#fb7185' : (item.priority === 'medium' ? '#f59e0b' : '#38bdf8');

                        html += `
                            <div class="notification-item" style="padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; border-left: 3px solid ${priorityColor}; padding-left: 10px; margin-bottom: 5px;">
                                <div style="flex: 1;">
                                    <p style="margin: 0; font-size: 0.9rem; color: #f8fafc;">${$('<div>').text(item.message).html()}</p>
                                    <span style="font-size: 0.7rem; color: #94a3b8;">${$('<div>').text(item.timestamp).html()}</span>
                                </div>
                                <button class="mark-read-btn" data-id="${item.id}" title="Mark as read" 
                                    style="background: transparent; border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; border-radius: 4px; padding: 2px 6px; cursor: pointer; font-size: 0.75rem;">✓</button>
                            </div>
                        `;
                    });
                }

                notificationList.html(html);
            }
        }).fail(function() {
            // Silently fail polling to avoid interrupting dashboard experience
        });
    }

    // Event listener for marking notifications as read
    $(document).on('click', '.mark-read-btn', function() {
        const btn = $(this);
        const notificationId = btn.data('id');
        const csrfToken = $('input[name="csrf_token"]').val();

        btn.prop('disabled', true).css('opacity', '0.5');

        $.ajax({
            url: markReadUrl,
            method: 'POST',
            data: { 
                id: notificationId,
                csrf_token: csrfToken 
            },
            dataType: 'json'
        }).done(function(response) {
            if (response.status === 'success') {
                lastUnreadCount = response.unread_count;
                updateBadge(response.unread_count);
                btn.closest('.notification-item').fadeOut(300, function() {
                    $(this).remove();
                    if (notificationList.find('.notification-item').length === 0) {
                        notificationList.html(`${currentNotificationScopeHtml}<p class="placeholder-item" style="color: #64748b; font-size: 0.9rem;">No unread system alerts.</p>`);
                    }
                });
            }
        }).fail(function() {
            btn.prop('disabled', false).css('opacity', '1');
        });
    });

    // Event listener for marking ALL notifications as read
    $('#markAllReadBtn').on('click', function() {
        const btn = $(this);
        const csrfToken = $('input[name="csrf_token"]').val();

        if (!confirm('Mark all notifications as read?')) return;

        btn.prop('disabled', true).css('opacity', '0.5');

        $.ajax({
            url: markAllReadUrl,
            method: 'POST',
            data: { csrf_token: csrfToken },
            dataType: 'json'
        }).done(function(response) {
            if (response.status === 'success') {
                lastUnreadCount = 0;
                updateBadge(0);
                notificationList.find('.notification-item').fadeOut(300, function() {
                    $(this).remove();
                    notificationList.html('<p class="placeholder-item" style="color: #64748b; font-size: 0.9rem;">No unread system alerts.</p>');
                    btn.hide();
                });
            }
        }).always(function() {
            btn.prop('disabled', false).css('opacity', '1');
        });
    });

    // Main function to fetch and render all analytics data
    function fetchAndRenderCharts(term = null) {
        refreshButton.prop('disabled', true);
        chartLoaders.removeAttr('hidden');
        hideFeedback();

        let url = metricsUrl;
        if (term !== null) {
            url += `?term=${term}`;
        }

        $.ajax({
            url: url,
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    if (response.data.summary_stats) {
                        if ($('#stat-students').length) $('#stat-students').text(response.data.summary_stats.students);
                        if ($('#stat-classes').length) $('#stat-classes').text(response.data.summary_stats.classes);
                        if ($('#stat-roles').length) $('#stat-roles').text(response.data.summary_stats.roles);
                        if ($('#stat-incidents').length) $('#stat-incidents').text(response.data.summary_stats.incidents);
                        if ($('#stat-high-risk').length) $('#stat-high-risk').text(response.data.summary_stats.high_risk);
                    }
                    renderRiskDistributionChart(response.data.risk_distribution);
                    renderClassComparisonChart(response.data.class_averages);
                    const selectedTerm = response.data.selected_term ? `Term ${response.data.selected_term}` : 'Current term';
                    updateTopOffendersList(response.data.top_offenders, selectedTerm);
                } else {
                    showFeedback(response.message || 'Failed to load analytics data.');
                }
            },
            error: function(xhr) {
                showFeedback(xhr.responseJSON?.message || 'An error occurred while fetching analytics.');
            },
            complete: function() {
                refreshButton.prop('disabled', false);
                chartLoaders.attr('hidden', true);
            }
        });
    }

    // Initial load of analytics data
    fetchAndRenderCharts(currentTermSelect.val());
    requestNotificationPermission(); // Request permission on page load
    fetchNotifications();
    setInterval(fetchNotifications, 60000); // Poll for alerts every 60 seconds

    // Poll for bulk delete events so the dashboard refreshes automatically
    let lastBulkDelete = null;
    function pollBulkDelete() {
        $.ajax({
            url: window.location.origin + '/dashboard/last_bulk_delete.php',
            method: 'GET',
            dataType: 'json'
        }).done(function(resp) {
            if (resp && resp.status === 'success') {
                const ts = resp.last_bulk_delete || null;
                if (ts && (!lastBulkDelete || ts > lastBulkDelete)) {
                    lastBulkDelete = ts;
                    // New bulk delete detected — refresh charts
                    fetchAndRenderCharts(currentTermSelect.val());
                } else {
                    lastBulkDelete = ts;
                }
            }
        }).fail(function() {
            // silent
        });
    }

    // Start polling every 10s
    pollBulkDelete();
    setInterval(pollBulkDelete, 10000);

    // Event listener for refresh button
    refreshButton.on('click', function() {
        fetchAndRenderCharts(currentTermSelect.val());
    });

    // Event listener for term selection change
    currentTermSelect.on('change', function() {
        const newTerm = $(this).val();
        fetchAndRenderCharts(newTerm); // Refresh charts with new term

        // Also persist the new term in the database via AJAX
        $.ajax({
            url: termSwitcherForm.attr('action'),
            method: 'POST',
            data: {
                action: 'update_term',
                current_term: newTerm,
                csrf_token: $('input[name="csrf_token"]').val() // Get CSRF token from the form
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    // Optionally show a success message for term persistence
                    // showFeedback(response.message, 'success');
                } else {
                    showFeedback(response.message || 'Failed to persist term setting.', 'error');
                }
            },
            error: function(xhr) {
                showFeedback(xhr.responseJSON?.message || 'An error occurred while persisting term setting.', 'error');
            }
        });
    });

    // Keep the command-center search useful without changing the live data queries.
    $('[data-dashboard-search]').on('input', function() {
        const query = String($(this).val() || '').trim().toLowerCase();
        $('.dashboard-grid > article, .dashboard-analytics > article').each(function() {
            const matches = query === '' || $(this).text().toLowerCase().includes(query);
            $(this).toggle(matches);
        });
    });

    // Listen for theme changes to re-render charts with correct colors
    window.addEventListener('themeChanged', function() {
        fetchAndRenderCharts(currentTermSelect.val());
    });
});
