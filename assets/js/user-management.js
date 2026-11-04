$(function () {
    var config = window.SBMSUserConfig || {};
    var $tableBody = $('#userTableBody');
    var $tableMeta = $('#tableMeta');
    var $tableFeedback = $('#tableFeedback');
    var $pageIndicator = $('#pageIndicator');
    var $userModal = $('#userModal');
    var $userForm = $('#userForm');
    var $modalTitle = $('#modalTitle');
    var $submitButton = $('#userSubmitButton');
    var $submitLabel = $submitButton.find('.btn-label');
    var $submitLoader = $submitButton.find('.btn-loader');
    var $formFeedback = $('#formFeedback');

    var state = {
        page: 1,
        limit: 10000,
        total: 0,
        pages: 1,
    };

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function showTableMessage(message, isError) {
        $tableFeedback
            .removeClass('error success')
            .addClass(isError ? 'error' : 'success')
            .text(message || '');
    }

    function renderRows(rows) {
        if (!rows.length) {
            $tableBody.html('<tr><td colspan="7" class="table-placeholder">No users found.</td></tr>');
            return;
        }

        var html = rows.map(function (user) {
            var encodedUser = encodeURIComponent(JSON.stringify(user));
            return [
                '<tr>',
                '<td>', escapeHtml(user.full_name), '</td>',
                '<td>', escapeHtml(user.username), '</td>',
                '<td>', escapeHtml(user.email), '</td>',
                '<td>', escapeHtml(user.role_name), '</td>',
                '<td>', escapeHtml(String(user.assigned_class_count || 0)), '</td>',
                '<td>', escapeHtml(formatDate(user.created_at)), '</td>',
                '<td class="action-cell">',
                '<button type="button" class="inline-btn edit-user" data-user="', encodedUser, '">Edit</button>',
                '<button type="button" class="inline-btn danger delete-user" data-id="', escapeHtml(String(user.id)), '">Delete</button>',
                '</td>',
                '</tr>'
            ].join('');
        }).join('');

        $tableBody.html(html);
    }

    function formatDate(value) {
        if (!value) {
            return '-';
        }

        var date = new Date(value);
        if (isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    }

    function updatePagination(meta) {
        state.page = meta.page || 1;
        state.pages = meta.pages || 1;
        state.total = meta.total || 0;
        state.limit = meta.limit || state.limit;

        $tableMeta.text(state.total + ' user' + (state.total === 1 ? '' : 's'));
        $pageIndicator.text('Page ' + state.page + ' of ' + state.pages);
        $('#prevPage').prop('disabled', true);
        $('#nextPage').prop('disabled', true);
    }

    function fetchUsers() {
        if (!config.fetchUrl) {
            showTableMessage('User fetch URL is not configured.', true);
            renderRows([]);
            return;
        }

        $tableMeta.text('Loading...');

        $.ajax({
            url: config.fetchUrl,
            method: 'GET',
            dataType: 'json',
            data: {
                page: state.page,
                limit: state.limit
            }
        }).done(function (response) {
            var payload = response.data || {};
            renderRows(payload.items || []);
            updatePagination(payload.meta || { page: 1, pages: 1, total: 0, limit: state.limit });
            showTableMessage(response.message || 'Users loaded.', false);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to load users.', true);
            renderRows([]);
        });
    }

    function resetForm() {
        if (!$userForm.length) {
            return;
        }

        $userForm[0].reset();
        $('#userId').val('');
        $formFeedback.removeClass('error success').text('');
        $modalTitle.text('Add a user');
        $submitLabel.text('Save User');
        $('#password').prop('required', true);
        $('#passwordHint').text('(Required)');
    }

    function openModal() {
        $userModal.removeClass('hidden');
    }

    function closeModal() {
        $userModal.addClass('hidden');
        resetForm();
    }

    function setSubmitting(isSubmitting) {
        $submitButton.prop('disabled', isSubmitting);
        $submitLoader.prop('hidden', !isSubmitting);
        $submitLabel.text(isSubmitting ? 'Saving...' : ($('#userId').val() ? 'Update User' : 'Save User'));
    }

    function submitUserForm() {
        var isEdit = $('#userId').val() !== '';
        var endpoint = isEdit ? config.updateUrl : config.createUrl;

        if (!endpoint) {
            $formFeedback.addClass('error').text('Save URL is not configured.');
            return;
        }

        setSubmitting(true);
        $formFeedback.removeClass('error success').text('');

        $.ajax({
            url: endpoint,
            method: 'POST',
            data: $userForm.serialize(),
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function (response) {
            showTableMessage(response.message || 'User saved successfully.', false);
            closeModal();
            fetchUsers();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $formFeedback.addClass('error').text(response.message || 'Unable to save the user.');
        }).always(function () {
            setSubmitting(false);
        });
    }

    function editUser(user) {
        resetForm();
        $('#userId').val(user.id || '');
        $('#fullName').val(user.full_name || '');
        $('#username').val(user.username || '');
        $('#email').val(user.email || '');
        $('#roleId').val(user.role_id || '');
        $('#password').prop('required', false);
        $('#passwordHint').text('(Leave blank to keep current password)');
        $modalTitle.text('Edit user');
        $submitLabel.text('Update User');
        openModal();
    }

    function deleteUser(userId) {
        if (!config.deleteUrl) {
            showTableMessage('Delete URL is not configured.', true);
            return;
        }

        if (!window.confirm('Are you sure you want to delete this user?')) {
            return;
        }

        $.ajax({
            url: config.deleteUrl,
            method: 'POST',
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            data: {
                id: userId,
                csrf_token: config.csrfToken
            }
        }).done(function (response) {
            showTableMessage(response.message || 'User deleted.', false);
            fetchUsers();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to delete the user.', true);
        });
    }

    $('#userTableBody').on('click', '.edit-user', function () {
        var userJson = $(this).attr('data-user');
        if (!userJson) {
            return;
        }

        editUser(JSON.parse(decodeURIComponent(userJson)));
    });

    $('#userTableBody').on('click', '.delete-user', function () {
        deleteUser($(this).data('id'));
    });

    $('#openUserModal').on('click', openModal);
    $('#closeUserModal, #cancelUserModal').on('click', closeModal);
    $userForm.on('submit', function (event) {
        event.preventDefault();
        submitUserForm();
    });

    $('#prevPage').on('click', function () {
        return;
    });

    $('#nextPage').on('click', function () {
        return;
    });

    fetchUsers();
});
