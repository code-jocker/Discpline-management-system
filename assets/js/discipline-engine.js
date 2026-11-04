$(function () {
    var config = window.SBMSDisciplineConfig || {};
    var state = {
        page: 1,
        limit: 10,
        totalPages: 1
    };

    var $historyBody = $('#historyTableBody');
    var $historyFeedback = $('#historyFeedback');
    var $historyMeta = $('#historyMeta');
    var $historyPageIndicator = $('#historyPageIndicator');
    var $notificationList = $('#notificationList');
    var $modal = $('#deductionModal');
    var $form = $('#deductionForm');
    var $submitButton = $('#deductionSubmitButton');
    var $submitLabel = $submitButton.find('.btn-label');
    var $submitLoader = $submitButton.find('.btn-loader');
    var $deductionFeedback = $('#deductionFeedback');
    var canDeleteHistory = !!config.canDeleteHistory;

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function renderHistory(rows) {
        if (!rows.length) {
            $historyBody.html('<tr><td colspan="' + (canDeleteHistory ? '11' : '10') + '" class="table-placeholder">No deductions match the current filters.</td></tr>');
            return;
        }

        var html = $.map(rows, function (item) {
            var marksValue = parseInt(item.marks_deducted, 10);
            var marksDisplay = marksValue < 0 
                ? '<span style="color: #10b981; font-weight: bold;">+' + Math.abs(marksValue) + '</span>' 
                : escapeHtml(String(item.marks_deducted));

            var categoryHtml = item.incident_category ? escapeHtml(item.incident_category) : '<span class="cell-muted">—</span>';
            var letterHtml = '<span class="cell-muted">—</span>';
            if (item.letter_id) {
                var letterUrl = config.letterViewUrl ? config.letterViewUrl + item.letter_id : '/letters/view.php?id=' + item.letter_id;
                letterHtml = '<a href="' + escapeHtml(letterUrl) + '" class="btn-secondary" style="padding: 0.25rem 0.55rem; font-size: 0.8rem;">View Letter</a>';
            }

            var evidenceHtml = '';
            if (item.evidence_file_id && item.storage_path) {
                var evidenceUrl = '/storage/uploads/' + item.storage_path;
                var evidenceLabel = escapeHtml(item.original_name || 'Evidence');
                var evidencePreview = '';
                if (item.mime_type && item.mime_type.indexOf('image/') === 0) {
                    evidencePreview = '<img src="' + evidenceUrl + '" alt="Evidence preview" style="max-width: 90px; max-height: 60px; object-fit: cover; border-radius: 4px;">';
                } else {
                    evidencePreview = '<span style="font-size: 0.8rem;">📄</span>';
                }
                evidenceHtml = '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">' + evidencePreview + '<a href="' + evidenceUrl + '" target="_blank" rel="noreferrer">' + evidenceLabel + '</a></div>';
            } else {
                evidenceHtml = '<span class="cell-muted">—</span>';
            }

            return [
                '<tr>',
                '<td>', escapeHtml(item.full_name), ' <span class="cell-muted">(', escapeHtml(item.student_code), ')</span></td>',
                '<td>', escapeHtml(item.class_name), '</td>',
                '<td>Term ', escapeHtml(String(item.term)), '</td>',
                '<td>', marksDisplay, '</td>',
                '<td>', categoryHtml, '</td>',
                '<td>', escapeHtml(item.reason), '</td>',
                '<td>', letterHtml, '</td>',
                '<td>', evidenceHtml, '</td>',
                '<td>', escapeHtml(item.performed_by_name), '</td>',
                '<td>', escapeHtml(item.timestamp), '</td>',
                canDeleteHistory ? '<td><button type="button" class="ghost-btn history-delete-one-btn" data-id="' + escapeHtml(String(item.id)) + '" style="padding: 0.25rem 0.6rem; font-size: 0.8rem; color: #fb7185;">Delete Entry</button></td>' : '',
                '</tr>'
            ].join('');
        }).join('');

        $historyBody.html(html);
    }

    function updateHistoryMeta(meta) {
        var from = meta.total === 0 ? 0 : ((meta.page - 1) * meta.limit) + 1;
        var to = Math.min(meta.page * meta.limit, meta.total);
        state.page = meta.page || 1;
        state.totalPages = meta.pages || 1;
        $historyMeta.text('Showing ' + from + ' to ' + to + ' of ' + meta.total + ' deductions');
        $historyPageIndicator.text('Page ' + state.page + ' of ' + state.totalPages);
        $('#historyPrev').prop('disabled', state.page <= 1);
        $('#historyNext').prop('disabled', state.page >= state.totalPages);
    }

    function showHistoryMessage(message, isError) {
        $historyFeedback.removeClass('error success').addClass(isError ? 'error' : 'success').text(message || '');
    }

    function fetchHistory() {
        $historyBody.html('<tr><td colspan="' + (canDeleteHistory ? '11' : '10') + '" class="table-placeholder">Loading history...</td></tr>');
        showHistoryMessage('', false);

        $.ajax({
            url: config.historyUrl,
            method: 'GET',
            dataType: 'json',
            data: {
                student_id: $('#historyStudentFilter').val(),
                class_id: $('#historyClassFilter').val(),
                term: $('#historyTermFilter').val(),
                sort_by: $('#historySortFilter').val(),
                page: state.page,
                limit: state.limit
            }
        }).done(function (response) {
            if (response.meta && typeof response.meta.can_delete !== 'undefined') {
                canDeleteHistory = !!response.meta.can_delete;
            }

            renderHistory(response.data || []);
            updateHistoryMeta(response.meta || { page: 1, pages: 1, total: 0, limit: state.limit });
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showHistoryMessage(response.message || 'Unable to load deduction history.', true);
            renderHistory([]);
        });
    }

    function fetchNotifications() {
        $.ajax({
            url: config.notificationsUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            var items = response.data || [];
            if (!items.length) {
                $notificationList.html('<p class="placeholder-item">No unread notifications.</p>');
                return;
            }

            var html = $.map(items, function (item) {
                var priorityColor = item.priority === 'high' ? '#fb7185' : (item.priority === 'medium' ? '#f59e0b' : '#38bdf8');

                return [
                    '<article class="notification-item" style="border-left: 3px solid ' + priorityColor + '; padding-left: 10px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-start;">',
                    '<div><p style="margin:0; padding-right:10px;">', escapeHtml(item.message), '</p>',
                    '<span style="font-size:0.8rem; color:#94a3b8;">', escapeHtml(item.timestamp), '</span></div>',
                    '<div style="display:flex; gap:5px;">',
                    '<button type="button" class="notification-action ghost-btn" data-id="'+item.id+'" data-action="hide" style="padding:0.2rem 0.5rem; font-size:0.8rem;" title="Hide/Read">Hide</button>',
                    '<button type="button" class="notification-action ghost-btn" data-id="'+item.id+'" data-action="delete" style="padding:0.2rem 0.5rem; font-size:0.8rem; color:#fb7185;" title="Delete">Delete</button>',
                    '</div>',
                    '</article>'
                ].join('');
            }).join('');

            $notificationList.html(html);
        }).fail(function () {
            $notificationList.html('<p class="placeholder-item">Unable to load notifications.</p>');
        });
    }

    function setSubmitting(isSubmitting) {
        $submitButton.prop('disabled', isSubmitting);
        if (isSubmitting) {
            $submitLoader.show();
            $submitLabel.text('Applying...');
        } else {
            $submitLoader.hide();
            var action = $('input[name="action_type"]:checked').val();
            if (action === 'add') {
                $submitLabel.text('Apply Reward');
            } else if (action === 'set') {
                $submitLabel.text('Set Marks');
            } else {
                $submitLabel.text('Apply Deduction');
            }
        }
    }

    function updateMarksInputConstraints() {
        var action = $('input[name="action_type"]:checked').val();
        var $marksInput = $('#deductMarks');
        if (action === 'set') {
            $marksInput.attr('min', '0');
            $marksInput.attr('max', '40');
            $marksInput.attr('placeholder', 'Enter final marks (0-40)');
        } else {
            $marksInput.attr('min', '1');
            $marksInput.attr('max', '40');
            $marksInput.attr('placeholder', action === 'add' ? 'Enter amount to add (1-40)' : 'Enter amount to deduct (1-40)');
        }
    }

    function updateEvidenceLabel() {
        var fileInput = $('#evidenceFile')[0];
        var label = fileInput && fileInput.files && fileInput.files.length ? fileInput.files[0].name : 'No file chosen';
        $('#evidenceFileLabel').text(label);
    }

    function updateWholeClassMode() {
        var shouldApplyToClass = $('#applyToClassCheckbox').is(':checked');
        var $classSelect = $('#deductClassFilterModal');
        var $studentSelect = $('#deductStudentId');
        var $studentSearch = $('#deductStudentSearch');

        if (shouldApplyToClass) {
            $classSelect.attr('required', 'required');
            $studentSelect.prop('disabled', true).removeAttr('required').val('');
            $studentSearch.prop('disabled', true);
        } else {
            $classSelect.removeAttr('required');
            $studentSelect.prop('disabled', false).attr('required', 'required');
            $studentSearch.prop('disabled', false);
        }
    }

    var $formContainer = $('#deductionFormContainer');

    $('input[name="action_type"]').on('change', function() {
        var action = $(this).val();
        var $marksLabel = $('label[for="deductMarks"]');
        var $submitLabel = $('#deductionSubmitButton .btn-label');
        if (action === 'add') {
            $marksLabel.text('Marks to Add');
            $submitLabel.text('Apply Reward');
        } else if (action === 'set') {
            $marksLabel.text('Marks to Set');
            $submitLabel.text('Set Marks');
        } else {
            $marksLabel.text('Marks to Deduct');
            $submitLabel.text('Apply Deduction');
        }
        updateMarksInputConstraints();
    });

    updateMarksInputConstraints();

    function openModal() {
        $formContainer.slideDown(300).removeClass('hidden');
        window.setTimeout(function () {
            var panel = $formContainer.get(0);
            if (panel && typeof panel.scrollIntoView === 'function') {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 50);
    }

    function closeModal() {
        $formContainer.slideUp(300, function() {
            $formContainer.addClass('hidden');
            if ($form.length) {
                $form[0].reset();
                $('#deductClassFilterModal').val('');
                $('#deductStudentSearch').val('');
                $('#deductStudentId option').show().prop('disabled', false);
                $('#deductStudentId').prop('disabled', false);
            }
            $deductionFeedback.removeClass('error success').text('');
        });
    }

    // Modal filtering logic
    function filterModalStudents() {
        var classId = $('#deductClassFilterModal').val();
        var searchVal = $('#deductStudentSearch').val().toLowerCase();
        var $studentSelect = $('#deductStudentId');

        $studentSelect.find('option').each(function () {
            var $opt = $(this);
            var optVal = $opt.val();
            
            if (optVal === "") return; // Skip select placeholder

            var optClassId = $opt.attr('data-class-id');
            var text = $opt.text().toLowerCase();

            var matchesClass = !classId || String(optClassId) === String(classId);
            var matchesSearch = !searchVal || text.indexOf(searchVal) !== -1;

            if (matchesClass && matchesSearch) {
                $opt.show().prop('disabled', false);
            } else {
                // If it is currently selected, clear selection
                if ($opt.is(':selected')) {
                    $studentSelect.val('');
                }
                $opt.hide().prop('disabled', true);
            }
        });
    }

    $(document).on('change', '#deductClassFilterModal', function () {
        filterModalStudents();
        updateWholeClassMode();
    });
    $(document).on('input', '#deductStudentSearch', filterModalStudents);
    $('#applyToClassCheckbox').on('change', updateWholeClassMode);
    $('#openCameraBtn').on('click', function () {
        var fileInput = $('#evidenceFile')[0];
        if (fileInput) {
            fileInput.click();
        }
    });
    $('#evidenceFile').on('change', updateEvidenceLabel);

    function submitDeduction() {
        setSubmitting(true);
        $deductionFeedback.removeClass('error success').text('');

        var formData = new FormData($form[0]);
        var selectedClassId = $('#deductClassFilterModal').val();
        var applyToClass = $('#applyToClassCheckbox').is(':checked');

        if (applyToClass && selectedClassId) {
            formData.set('class_id', selectedClassId);
        } else {
            formData.delete('class_id');
        }

        if (applyToClass) {
            formData.set('apply_to_class', '1');
            formData.delete('student_id');
        } else {
            formData.set('apply_to_class', '0');
        }

        $.ajax({
            url: config.deductUrl,
            method: 'POST',
            dataType: 'json',
            data: formData,
            processData: false,
            contentType: false,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function (response) {
            try {
                var payload = response && response.data !== undefined ? response.data : response;
                var summary = payload.updated_count !== undefined
                    ? 'Updated students: ' + payload.updated_count
                    : 'Updated marks: ' + (payload.updated_marks ?? 'n/a');
                var message = response.message || payload.message || 'Deduction recorded.';
                $deductionFeedback.addClass('success').text(message + ' ' + summary);
                fetchHistory();
                fetchNotifications();
                window.setTimeout(closeModal, 900);
            } catch(e) {
                console.error(e);
            }
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            var message = response && response.data && response.data.message
                ? response.data.message
                : response.message || 'Unable to process the deduction.';
            $deductionFeedback.addClass('error').text(message);
        }).always(function () {
            setSubmitting(false);
        });
    }

    $('#historyStudentFilter, #historyClassFilter, #historyTermFilter, #historySortFilter').on('change', function () {
        state.page = 1;
        fetchHistory();
    });

    $('#historyPrev').on('click', function () {
        if (state.page > 1) {
            state.page -= 1;
            fetchHistory();
        }
    });

    $('#historyNext').on('click', function () {
        if (state.page < state.totalPages) {
            state.page += 1;
            fetchHistory();
        }
    });

    $('#openDeductionModal').on('click', openModal);
    $('#closeDeductionModal, #cancelDeductionModal').on('click', closeModal);

    $('#historyExpandAllBtn').on('click', function() {
        state.limit = 10000;
        state.page = 1;
        $('#historyContentContainer').show();
        fetchHistory();
    });

    $('#historyCollapseAllBtn').on('click', function() {
        state.limit = 10;
        state.page = 1;
        fetchHistory();
    });

    $('#refreshHistoryBtn').on('click', function() {
        var $btn = $(this);
        var originalHtml = $btn.html();
        $btn.html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l3.08 2.69"/></svg> Refreshing...');
        
        fetchHistory();
        
        setTimeout(function() {
            $btn.html(originalHtml);
        }, 800);
    });

    $(document).on('click', '.history-delete-one-btn', function() {
        if (!canDeleteHistory) return;
        var $btn = $(this);
        var historyId = $btn.data('id');

        if (!historyId) return;
        if (!confirm('Delete this deduction entry?')) return;

        $btn.prop('disabled', true).text('...');

        $.ajax({
            url: config.historyDeleteUrl || config.historyUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'delete_one',
                id: historyId,
                csrf_token: config.csrfToken || $('input[name="csrf_token"]').val()
            }
        }).done(function(response) {
            if (response.status === 'success') {
                fetchHistory();
            } else {
                showHistoryMessage(response.message || 'Unable to delete the history entry.', true);
            }
        }).fail(function(xhr) {
            var response = xhr.responseJSON || {};
            showHistoryMessage(response.message || 'Unable to delete the history entry.', true);
        }).always(function() {
            fetchHistory();
        });
    });

    $('#historyDeleteAllBtn').on('click', function() {
        if (!canDeleteHistory) return;
        if (!confirm('Delete all deduction history matching the current filters?')) return;

        var $btn = $(this);
        $btn.prop('disabled', true).text('Deleting...');

        $.ajax({
            url: config.historyDeleteUrl || config.historyUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'delete_all',
                student_id: $('#historyStudentFilter').val(),
                class_id: $('#historyClassFilter').val(),
                term: $('#historyTermFilter').val(),
                csrf_token: config.csrfToken || $('input[name="csrf_token"]').val()
            }
        }).done(function(response) {
            if (response.status === 'success') {
                fetchHistory();
            } else {
                showHistoryMessage(response.message || 'Unable to delete history entries.', true);
            }
        }).fail(function(xhr) {
            var response = xhr.responseJSON || {};
            showHistoryMessage(response.message || 'Unable to delete history entries.', true);
        }).always(function() {
            $btn.prop('disabled', false).text('Delete All');
            fetchHistory();
        });
    });

    $(document).on('click', '.history-delete-student-btn', function() {
        if (!canDeleteHistory) return;
        var $btn = $(this);
        var studentId = $btn.data('student-id');
        var studentName = $btn.data('student-name') || 'this student';

        if (!studentId) return;
        if (!confirm('Delete all deduction records for ' + studentName + '?')) return;

        $btn.prop('disabled', true).text('...');

        $.ajax({
            url: config.historyDeleteUrl || config.historyUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'delete_all',
                student_id: studentId,
                csrf_token: config.csrfToken || $('input[name="csrf_token"]').val()
            }
        }).done(function(response) {
            if (response.status === 'success') {
                fetchHistory();
            } else {
                showHistoryMessage(response.message || 'Unable to delete this student\'s deduction history.', true);
            }
        }).fail(function(xhr) {
            var response = xhr.responseJSON || {};
            showHistoryMessage(response.message || 'Unable to delete this student\'s deduction history.', true);
        }).always(function() {
            fetchHistory();
        });
    });

    $form.on('submit', function (event) {
        event.preventDefault();
        submitDeduction();
    });

    $(document).on('click', '.notification-action', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var id = $btn.data('id');
        var action = $btn.data('action');
        
        $btn.text('...').prop('disabled', true);
        
        $.ajax({
            url: config.notificationActionUrl || (config.deductUrl.replace('deduct.php', 'notification_action.php')),
            method: 'POST',
            dataType: 'json',
            data: {
                id: id,
                action: action,
                csrf_token: config.csrfToken || $('input[name="csrf_token"]').val()
            }
        }).done(function(res) {
            fetchNotifications();
        }).fail(function() {
            $btn.text(action === 'delete' ? 'Delete' : 'Hide').prop('disabled', false);
        });
    });

    $('#notifShowAllBtn').on('click', function() {
        window.location.href = '../notifications/index.php';
    });

    $('#notifHideAllBtn').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('...');
        $.ajax({
            url: config.notificationActionUrl || (config.deductUrl.replace('deduct.php', 'notification_action.php')),
            method: 'POST',
            dataType: 'json',
            data: {
                id: 0,
                action: 'hide_all',
                csrf_token: config.csrfToken || $('input[name="csrf_token"]').val()
            }
        }).done(function() {
            fetchNotifications();
        }).always(function() {
            $btn.prop('disabled', false).text('Hide All');
        });
    });

    $('#notifDeleteAllBtn').on('click', function() {
        if (!confirm('Are you sure you want to delete all notifications?')) return;
        var $btn = $(this);
        $btn.prop('disabled', true).text('...');
        $.ajax({
            url: config.notificationActionUrl || (config.deductUrl.replace('deduct.php', 'notification_action.php')),
            method: 'POST',
            dataType: 'json',
            data: {
                id: 0,
                action: 'delete_all',
                csrf_token: config.csrfToken || $('input[name="csrf_token"]').val()
            }
        }).done(function() {
            fetchNotifications();
        }).always(function() {
            $btn.prop('disabled', false).text('Delete All');
        });
    });

    updateWholeClassMode();
    fetchHistory();
    fetchNotifications();
    window.setInterval(fetchNotifications, 30000);
});
