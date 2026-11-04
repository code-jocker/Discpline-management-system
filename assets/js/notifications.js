/**
 * SBMS notifications page actions.
 * Handles mark-as-read interactions on the standalone notifications screen.
 */

$(document).ready(function() {
    const baseUrl = window.appBaseUrl || '';
    const markReadUrl = `${baseUrl}/dashboard/mark_notification_read.php`;
    const markAllReadUrl = `${baseUrl}/dashboard/mark_all_notifications_read.php`;
    const notificationsContainer = $('#notificationsContainer');
    const markAllButton = $('#markAllReadPageBtn');

    function getCsrfToken() {
        return $('input[name="csrf_token"]').val() || '';
    }

    function updateSidebarBadge(count) {
        const badge = $('#sidebarNotifBadge');
        if (count > 0) {
            badge.text(count).show();
        } else {
            badge.hide();
        }
    }

    function updateEmptyState() {
        if (notificationsContainer.find('.notification-item').length === 0) {
            notificationsContainer.html('<div style="text-align: center; padding: 3rem 1rem;"><p style="color: #64748b; font-size: 1.1rem;">You have no notifications.</p></div>');
            markAllButton.hide();
        }
    }

    $(document).on('click', '.mark-read-btn', function() {
        const button = $(this);
        const notificationId = button.data('id');
        const csrfToken = getCsrfToken();

        if (!notificationId || !csrfToken) return;

        button.prop('disabled', true).css('opacity', '0.5');

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
                updateSidebarBadge(response.unread_count || 0);
                button.closest('.notification-item').fadeOut(250, function() {
                    $(this).remove();
                    updateEmptyState();
                });
            }
        }).fail(function() {
            button.prop('disabled', false).css('opacity', '1');
        });
    });

    markAllButton.on('click', function() {
        const button = $(this);
        const csrfToken = getCsrfToken();

        if (!csrfToken) return;
        if (!confirm('Mark all notifications as read?')) return;

        button.prop('disabled', true).css('opacity', '0.5');

        $.ajax({
            url: markAllReadUrl,
            method: 'POST',
            data: { csrf_token: csrfToken },
            dataType: 'json'
        }).done(function(response) {
            if (response.status === 'success') {
                updateSidebarBadge(0);
                notificationsContainer.find('.notification-item').fadeOut(250, function() {
                    $(this).remove();
                    updateEmptyState();
                });
                markAllButton.hide();
            }
        }).always(function() {
            button.prop('disabled', false).css('opacity', '1');
        });
    });

    if (notificationsContainer.find('.notification-item').length === 0) {
        markAllButton.hide();
    }
});