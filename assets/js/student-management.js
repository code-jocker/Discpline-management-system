$(function () {
    var config = window.SBMSStudentConfig || {};
    var maxMarks = Number(config.maxMarks) || 40;

    var state = {
        page: 1,
        limit: Number($('#pageSize').val()) || 25,
        sort: 'class',
        dir: 'asc',
        total: 0,
        pages: 1,
        loading: false
    };

    var studentCache = {};
    var sheetStudentId = null;
    var lifecycleStudent = null;

    var $categoryFilter = $('#categoryFilter');
    var $classFilter = $('#classFilter');
    var $searchFilter = $('#searchFilter');
    var $yearFilter = $('#academicYearFilter');
    var $streamFilter = $('#streamFilter');
    var $dormitoryFilter = $('#dormitoryFilter');
    var $statusFilter = $('#statusFilter');
    var $table = $('#studentTable');
    var $tableBody = $('#studentTableBody');
    var $tableMeta = $('#tableMeta');
    var $tableFeedback = $('#tableFeedback');
    var $pagination = $('#studentPagination');
    var $studentModal = $('#studentModal');
    var $studentForm = $('#studentForm');
    var $modalTitle = $('#modalTitle');
    var $submitButton = $('#studentSubmitButton');
    var $submitLabel = $submitButton.find('.btn-label');
    var $submitLoader = $submitButton.find('.btn-loader');
    var $formFeedback = $('#formFeedback');
    var currentPortraitUrl = '';

    var school = config.school || {};

    function escapeHtml(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function number(value) {
        var parsed = Number(value);
        if (!isFinite(parsed)) {
            return '0';
        }
        return parsed.toLocaleString(undefined, { maximumFractionDigits: 1 });
    }

    function toast(message, type) {
        if (window.SBMS && typeof window.SBMS.toast === 'function') {
            window.SBMS.toast(message, type || 'success');
        }
    }

    function initialsOf(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) {
            return '—';
        }
        return (parts[0].charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    }

    function photoUrl(path) {
        if (!path) {
            return '';
        }
        return (config.baseUrl || '') + '/storage/uploads/' + String(path).replace(/^\/+/, '');
    }

    function cardUrlFor(studentId) {
        return (config.cardUrl || 'students/card.php') + '?student_id=' + encodeURIComponent(String(studentId)) + '&print=1';
    }

    function cardPreviewUrl(studentId) {
        return (config.cardUrl || 'students/card.php') + '?student_id=' + encodeURIComponent(String(studentId)) + '&embed=1';
    }

    function statusMeta(status) {
        var map = {
            active: { label: 'Active', className: 'active' },
            transferred: { label: 'Transferred', className: 'transferred' },
            graduated: { label: 'Graduated', className: 'graduated' },
            archived: { label: 'Archived', className: 'archived' }
        };
        return map[status] || { label: status || 'Active', className: 'active' };
    }

    function riskMeta(risk) {
        var map = {
            'High Risk': { label: 'High', className: 'high' },
            'Medium Risk': { label: 'Medium', className: 'medium' },
            'Low Risk': { label: 'Low', className: 'low' }
        };
        return map[risk] || { label: 'None', className: 'none' };
    }

    function badge(label, variant) {
        return '<span class="sd-badge sd-badge--' + variant + '">' + escapeHtml(label) + '</span>';
    }

    function avatarMarkup(student, extraClass) {
        var className = 'sd-avatar' + (extraClass ? ' ' + extraClass : '');
        if (student && student.photo_path) {
            return '<span class="' + className + '"><img src="' + escapeHtml(photoUrl(student.photo_path)) + '" alt=""></span>';
        }
        return '<span class="' + className + '" aria-hidden="true">' + escapeHtml(initialsOf(student ? student.full_name : '')) + '</span>';
    }

    function conductMarkup(student) {
        if (student.conduct_marks === null || student.conduct_marks === undefined || student.conduct_marks === '') {
            return '<span class="sd-cell-muted">—</span>';
        }
        var marks = Number(student.conduct_marks);
        var percent = Math.max(0, Math.min(100, (marks / maxMarks) * 100));
        var low = percent < 50;
        return '<div class="sd-conduct' + (low ? ' is-low' : '') + '">'
            + '<span class="sd-conduct-bar' + (low ? ' is-low' : '') + '"><span style="width:' + percent.toFixed(1) + '%"></span></span>'
            + '<span class="sd-conduct-value">' + escapeHtml(String(marks)) + '/' + maxMarks + '</span>'
            + '</div>';
    }

    function showTableMessage(message, isError) {
        $tableFeedback
            .removeClass('error success')
            .addClass(isError ? 'error' : 'success')
            .text(message || '');
    }

    function setLoading(isLoading) {
        state.loading = isLoading;
        if (isLoading) {
            $tableBody.html('<tr><td colspan="9" class="sd-loading-cell"><span class="sd-spinner" aria-hidden="true"></span>Loading student records…</td></tr>');
        }
    }

    function renderRows(rows) {
        studentCache = {};

        if (!rows.length) {
            $tableBody.html('<tr><td colspan="9"><div class="sd-empty">'
                + '<strong>No students match these filters</strong>'
                + '<p>Try clearing the search or choosing a different class, stream or status.</p>'
                + '</div></td></tr>');
            return;
        }

        var html = '';
        $.each(rows, function (index, student) {
            studentCache[String(student.id)] = student;

            var status = statusMeta(student.status);
            var risk = riskMeta(student.risk_level);
            var actions = '<div class="sd-row-actions">'
                + '<button type="button" class="sd-icon-btn view-student" data-id="' + escapeHtml(String(student.id)) + '" title="View profile" aria-label="View ' + escapeHtml(student.full_name) + '">'
                + '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/><circle cx="12" cy="12" r="3"/></svg>'
                + '</button>';

            if (config.canManage) {
                actions += '<button type="button" class="sd-icon-btn edit-student" data-id="' + escapeHtml(String(student.id)) + '" title="Edit student" aria-label="Edit ' + escapeHtml(student.full_name) + '">'
                    + '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/><path d="m13.5 6.5 3 3"/></svg>'
                    + '</button>';
            }

            if (config.canManageStructure) {
                actions += '<button type="button" class="sd-icon-btn sd-icon-btn--danger delete-student" data-id="' + escapeHtml(String(student.id)) + '" title="Delete student" aria-label="Delete ' + escapeHtml(student.full_name) + '">'
                    + '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/></svg>'
                    + '</button>';
            }

            actions += '</div>';

            html += '<tr data-student-id="' + escapeHtml(String(student.id)) + '">'
                + '<td data-label="Student">'
                + '<div class="sd-student-cell">' + avatarMarkup(student)
                + '<div class="sd-student-meta">'
                + '<p class="sd-student-name">' + escapeHtml(student.full_name) + '</p>'
                + '<p class="sd-student-sub">' + escapeHtml(student.category_name || 'Uncategorised')
                + (student.school_year ? ' · ' + escapeHtml(student.school_year) : '') + '</p>'
                + '</div></div>'
                + '</td>'
                + '<td data-label="Admission No."><span class="sd-mono">' + escapeHtml(student.student_code || '—') + '</span></td>'
                + '<td data-label="Class">' + escapeHtml(student.class_name || '—') + '</td>'
                + '<td data-label="Stream"><span class="sd-cell-muted">' + escapeHtml(student.stream || '—') + '</span></td>'
                + '<td data-label="Dormitory"><span class="sd-cell-muted">' + escapeHtml(student.dormitory || '—') + '</span></td>'
                + '<td data-label="Conduct">' + conductMarkup(student) + '</td>'
                + '<td data-label="Risk">' + badge(risk.label, risk.className) + '</td>'
                + '<td data-label="Status">' + badge(status.label, status.className) + '</td>'
                + '<td data-label="Actions" class="sd-actions-cell">' + actions + '</td>'
                + '</tr>';
        });

        $tableBody.html(html);
    }

    function pageNumbers(page, pages) {
        var list = [];
        var i;
        if (pages <= 7) {
            for (i = 1; i <= pages; i++) {
                list.push(i);
            }
            return list;
        }

        list.push(1);
        if (page > 3) {
            list.push('…');
        }
        for (i = Math.max(2, page - 1); i <= Math.min(pages - 1, page + 1); i++) {
            list.push(i);
        }
        if (page < pages - 2) {
            list.push('…');
        }
        list.push(pages);
        return list;
    }

    function updatePagination(meta) {
        state.page = meta.page;
        state.pages = meta.pages;
        state.total = meta.total;

        var start = meta.total === 0 ? 0 : (meta.page - 1) * meta.limit + 1;
        var end = Math.min(meta.total, meta.page * meta.limit);
        $tableMeta.text(meta.total === 0
            ? 'No students to show'
            : 'Showing ' + start + '–' + end + ' of ' + number(meta.total) + ' students');

        if (meta.total === 0) {
            $pagination.empty();
            return;
        }

        var html = '<button type="button" class="sd-page-btn" data-page="' + (meta.page - 1) + '"' + (meta.page <= 1 ? ' disabled' : '') + ' aria-label="Previous page">‹</button>';
        $.each(pageNumbers(meta.page, meta.pages), function (index, page) {
            if (page === '…') {
                html += '<span class="sd-page-ellipsis">…</span>';
                return;
            }
            html += '<button type="button" class="sd-page-btn' + (page === meta.page ? ' is-active' : '') + '" data-page="' + page + '"'
                + (page === meta.page ? ' aria-current="page"' : '') + '>' + page + '</button>';
        });
        html += '<button type="button" class="sd-page-btn" data-page="' + (meta.page + 1) + '"' + (meta.page >= meta.pages ? ' disabled' : '') + ' aria-label="Next page">›</button>';

        $pagination.html(html);
    }

    function updateStats(stats) {
        if (!stats) {
            return;
        }

        $('#statTotalValue').text(number(stats.total));
        $('#statTotalSub').text(
            number(stats.active) + ' active · avg conduct '
            + (stats.avg_conduct === null || stats.avg_conduct === undefined ? '—' : Number(stats.avg_conduct).toFixed(1) + '/' + maxMarks)
        );
        $('#statRiskValue').text(number(stats.high_risk));

        var byCategory = {};
        $.each(stats.categories || [], function (index, category) {
            byCategory[String(category.id)] = category;
        });

        $('.sd-stat[data-stat="category"]').each(function () {
            var categoryId = String($(this).data('category-id') || '');
            var category = byCategory[categoryId];
            if (!category) {
                return;
            }
            $(this).find('.sd-stat-label').text(category.name);
            $(this).find('.sd-stat-value').text(number(category.total));
        });
    }

    function currentFilters() {
        return {
            search: $searchFilter.val() || '',
            academic_year: $yearFilter.val() || '',
            school_year: $yearFilter.val() || '',
            category_id: $categoryFilter.val() || '',
            class_id: $classFilter.val() || '',
            stream: $streamFilter.val() || '',
            dormitory: $dormitoryFilter.val() || '',
            status: $statusFilter.val() || '',
            sort: state.sort,
            dir: state.dir
        };
    }

    function fetchStudents() {
        if (state.loading) {
            return $.Deferred().resolve().promise();
        }

        setLoading(true);
        showTableMessage('', false);

        var filters = currentFilters();

        return $.ajax({
            url: config.fetchUrl,
            method: 'GET',
            dataType: 'json',
            data: $.extend({}, filters, {
                page: state.page,
                limit: state.limit
            })
        }).done(function (response) {
            var data = (response && response.data) || {};
            var meta = data.meta || { page: 1, pages: 1, total: 0, limit: state.limit };

            if (meta.total > 0 && state.page > meta.pages) {
                state.page = meta.pages;
                state.loading = false;
                fetchStudents();
                return;
            }

            renderRows(data.items || []);
            updatePagination(meta);
            updateStats(data.stats || null);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to load students.', true);
            renderRows([]);
            updatePagination({ page: 1, pages: 1, total: 0, limit: state.limit });
        }).always(function () {
            state.loading = false;
        });
    }

    function syncScope(categoryId) {
        var value = categoryId ? String(categoryId) : '';
        var matched = false;

        $('.sd-scope-btn').each(function () {
            var isActive = String($(this).data('scope') || '') === value;
            $(this).toggleClass('is-active', isActive);
            matched = matched || isActive;
        });

        if (!matched) {
            $('.sd-scope-btn[data-scope=""]').addClass('is-active');
        }
    }

    function setSort(sort, dir) {
        state.sort = sort;
        state.dir = dir === 'desc' ? 'desc' : 'asc';

        $table.find('th').attr('aria-sort', 'none');
        $table.find('th').has('button[data-sort="' + sort + '"]').attr(
            'aria-sort',
            state.dir === 'asc' ? 'ascending' : 'descending'
        );
    }

    // --- Student form ---
    function filterClassOptions($select, categoryId) {
        $select.find('option').each(function () {
            var optionCategoryId = $(this).data('category-id');
            if (!optionCategoryId || !categoryId || String(optionCategoryId) === String(categoryId)) {
                $(this).prop('hidden', false);
            } else {
                $(this).prop('hidden', true);
            }
        });

        if (categoryId && String($select.find('option:selected').data('category-id')) !== String(categoryId)) {
            $select.val('');
        }
    }

    function resetForm() {
        if (!$studentForm.length) {
            return;
        }

        $studentForm[0].reset();
        $('#studentId').val('');
        $('#studentCode').val('').prop('disabled', true);
        $('#dateOfBirth').val('');
        $('#address').val('');
        $('#stream').val('');
        $('#dormitory').val('');
        $('#schoolYear').val('');
        $('#removePhoto').prop('checked', false);
        $('#removePhotoWrap').prop('hidden', true);
        currentPortraitUrl = '';
        showPortraitPreview('', '');
        $('#photoCurrentHint').text('JPG, PNG, WEBP or GIF up to 10 MiB. Printed as-is on the card.');
        $formFeedback.removeClass('error success').text('');
        $modalTitle.text('Add a student');
        $submitLabel.text('Save Student');
        filterClassOptions($('#classId'), '');
    }

    function showPortraitPreview(url, altName) {
        var $preview = $('#photoPreview');
        if (!url) {
            $preview.prop('hidden', true).removeAttr('src');
            return;
        }
        $preview.attr('src', url).attr('alt', altName || '').prop('hidden', false);
    }

    function openModal() {
        $studentModal.removeClass('hidden');
    }

    function closeModal() {
        $studentModal.addClass('hidden');
        resetForm();
    }

    function setSubmitting(isSubmitting) {
        $submitButton.prop('disabled', isSubmitting);
        $submitLoader.prop('hidden', !isSubmitting);
        if (isSubmitting) {
            $submitLabel.text('Saving...');
        }
    }

    function submitStudentForm() {
        var isEdit = $('#studentId').val() !== '';
        var endpoint = isEdit ? config.updateUrl : config.createUrl;

        setSubmitting(true);
        $formFeedback.removeClass('error success').text('');

        $.ajax({
            url: endpoint,
            method: 'POST',
            data: new FormData($studentForm[0]),
            processData: false,
            contentType: false,
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function (response) {
            showTableMessage(response.message || 'Student saved successfully.', false);
            toast(response.message || 'Student saved successfully.', 'success');
            closeModal();
            fetchStudents();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $formFeedback.addClass('error').text(response.message || 'Unable to save the student.');
        }).always(function () {
            setSubmitting(false);
            $submitLabel.text(isEdit ? 'Update Student' : 'Save Student');
        });
    }

    function editStudent(student) {
        if (!student || !$studentForm.length) {
            return;
        }
        resetForm();
        $('#studentId').val(student.id);
        $('#studentCode').val(student.student_code || '').prop('disabled', true);
        $('#fullName').val(student.full_name || '');
        $('#parentContact').val(student.parent_contact || '');
        $('#dateOfBirth').val(student.date_of_birth || '');
        $('#address').val(student.address || '');
        $('#stream').val(student.stream || '');
        $('#dormitory').val(student.dormitory || '');
        $('#schoolYear').val(student.school_year || config.currentYear || '');
        $('#categoryId').val(student.category_id);
        filterClassOptions($('#classId'), student.category_id);
        $('#classId').val(student.class_id);
        if (student.photo_path) {
            currentPortraitUrl = photoUrl(student.photo_path);
            showPortraitPreview(currentPortraitUrl, 'Current portrait');
            $('#removePhotoWrap').prop('hidden', false);
            $('#photoCurrentHint').text('Current portrait shown above. Choose a file to replace it.');
        } else {
            $('#photoCurrentHint').text('JPG, PNG, WEBP or GIF up to 10 MiB. Printed as-is on the card.');
        }
        $modalTitle.text('Edit student');
        $submitLabel.text('Update Student');
        openModal();
    }

    // --- Student detail sheet ---
    var $sheet = $('#studentSheet');

    function fitCardPreview() {
        var wrap = $('#sheetCard');
        if (!wrap.length || wrap.prop('hidden')) {
            return;
        }

        var width = wrap.innerWidth();
        if (width <= 0) {
            return;
        }

        var scale = Math.min(1, width / 900);
        wrap[0].style.setProperty('--sid-card-scale', String(scale));
        wrap.height(Math.round(570 * scale));
    }

    if (window.ResizeObserver && $('#sheetCard').length) {
        new ResizeObserver(fitCardPreview).observe($('#sheetCard')[0]);
    }

    $(window).on('resize orientationchange', fitCardPreview);

    function fact(label, value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }
        return '<div class="sheet-fact"><dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value) + '</dd></div>';
    }

    function contactMarkup(contact) {
        var links = '';
        if (contact.phone_number) {
            links += '<a href="tel:' + escapeHtml(String(contact.phone_number).replace(/[^\d+]/g, '')) + '">' + escapeHtml(contact.phone_number) + '</a>';
        }
        if (contact.email) {
            links += '<a href="mailto:' + escapeHtml(contact.email) + '">' + escapeHtml(contact.email) + '</a>';
        }
        if (contact.whatsapp_number) {
            links += '<span>WhatsApp ' + escapeHtml(contact.whatsapp_number) + '</span>';
        }

        return '<div class="sd-contact">'
            + '<div class="sd-contact-main">'
            + '<p class="sd-contact-name">' + escapeHtml(contact.parent_name || 'Guardian') + '</p>'
            + '<p class="sd-contact-role">' + escapeHtml(contact.relationship || 'guardian')
            + (contact.contact_preference ? ' · prefers ' + escapeHtml(contact.contact_preference) : '') + '</p>'
            + (links ? '<div class="sd-contact-links">' + links + '</div>' : '')
            + '</div>'
            + (Number(contact.is_primary) === 1 ? badge('Primary', 'active') : '')
            + '</div>';
    }

    function renderProfile(student) {
        sheetStudentId = student.id;
        lifecycleStudent = student;

        $('#sheetAvatar').replaceWith(
            avatarMarkup(student, 'sd-avatar--lg').replace('class="sd-avatar', 'id="sheetAvatar" class="sd-avatar')
        );
        $('#sheetName').text(student.full_name || 'Student');

        var status = statusMeta(student.status);
        var risk = riskMeta(student.risk_level);
        $('#sheetBadges').html(
            badge(status.label, status.className)
            + badge(risk.className === 'none' ? 'Not assessed' : risk.label + ' risk', risk.className)
            + badge(student.class_name || 'Unassigned', 'archived')
            + (student.school_year ? badge(student.school_year, 'archived') : '')
        );

        $('#sheetFacts').html([
            fact('Admission no.', student.student_code),
            fact('Level', student.category_name),
            fact('Class', student.class_name),
            fact('Stream', student.stream),
            fact('Dormitory', student.dormitory),
            fact('Academic year', student.school_year),
            fact('Date of birth', student.date_of_birth || 'Not recorded'),
            fact('Home address', student.address || 'Not recorded'),
            fact('Parent contact', student.parent_contact),
            fact('Conduct marks', student.conduct_marks === null || student.conduct_marks === undefined ? '—' : student.conduct_marks + '/' + maxMarks)
        ].join(''));

        var contacts = student.parent_contacts || [];
        $('#sheetContacts').html(contacts.length
            ? $.map(contacts, contactMarkup).join('')
            : '<p class="sd-panel-empty">No parent or guardian contacts recorded yet.</p>');

        var academic = '';
        var marks = student.marks_history || [];
        if (marks.length) {
            academic += '<ul class="sd-history-list">';
            $.each(marks.slice(0, 8), function (index, mark) {
                academic += '<li class="sd-history-item">'
                    + '<div class="sd-history-item-main"><strong>Term ' + escapeHtml(mark.term) + '</strong>'
                    + '<span>' + escapeHtml(mark.school_year || '') + '</span></div>'
                    + '<span class="sd-history-item-value">' + escapeHtml(String(mark.marks)) + '/' + maxMarks + '</span>'
                    + '</li>';
            });
            academic += '</ul>';
        } else {
            academic = '<p class="sd-panel-empty">No conduct marks recorded for this student.</p>';
        }
        $('#sheetAcademic').html(academic);

        var discipline = '';
        if (student.expulsion) {
            discipline += '<p class="sd-lifecycle-note"><strong>Expulsion on record.</strong> '
                + escapeHtml(student.expulsion.reason || '') + '</p>';
        }
        var deductions = student.deductions || [];
        if (deductions.length) {
            discipline += '<ul class="sd-history-list">';
            $.each(deductions.slice(0, 10), function (index, item) {
                discipline += '<li class="sd-history-item">'
                    + '<div class="sd-history-item-main"><strong>' + escapeHtml(item.reason || item.incident_category || 'Deduction') + '</strong>'
                    + '<span>' + escapeHtml(item.incident_category || 'General')
                    + (item.timestamp ? ' · ' + escapeHtml(item.timestamp) : '')
                    + (item.performed_by_name ? ' · by ' + escapeHtml(item.performed_by_name) : '') + '</span></div>'
                    + '<span class="sd-history-item-value is-negative">-' + escapeHtml(String(item.marks_deducted)) + '</span>'
                    + '</li>';
            });
            discipline += '</ul>';
        } else if (!student.expulsion) {
            discipline = '<p class="sd-panel-empty">No deductions or disciplinary incidents on record.</p>';
        }
        $('#sheetDiscipline').html(discipline);

        var rewards = student.rewards;
        var rewardHtml = '<div class="sd-reward-grid">'
            + '<div class="sd-reward-tile"><span>Reward points</span><strong>' + escapeHtml(String(rewards && rewards.total_points ? rewards.total_points : 0)) + '</strong></div>'
            + '<div class="sd-reward-tile"><span>Current streak</span><strong>' + escapeHtml(String(rewards && rewards.current_streak ? rewards.current_streak : 0)) + '</strong></div>'
            + '</div>';

        var letters = student.letters || [];
        if (letters.length) {
            rewardHtml += '<ul class="sd-history-list" style="margin-top:10px;">';
            $.each(letters.slice(0, 4), function (index, letter) {
                rewardHtml += '<li class="sd-history-item">'
                    + '<div class="sd-history-item-main"><strong>' + escapeHtml(letter.subject || 'Recognition letter') + '</strong>'
                    + '<span>' + escapeHtml(letter.source || '') + '</span></div>'
                    + '<span class="sd-history-item-value">' + escapeHtml(String(letter.score)) + '</span>'
                    + '</li>';
            });
            rewardHtml += '</ul>';
        }
        $('#sheetRewards').html(rewardHtml);

        if (config.canManage) {
            $('#sheetCard').prop('hidden', false);
            $('#sheetCardFrame').attr('src', cardPreviewUrl(student.id)).one('load', fitCardPreview);
            $('#sheetPrintBtn').attr('href', cardUrlFor(student.id)).prop('hidden', false);
            fitCardPreview();
        } else {
            $('#sheetCard').prop('hidden', true);
            $('#sheetCardFrame').removeAttr('src');
            $('#sheetPrintBtn').prop('hidden', true);
        }

        $('#sheetEditBtn').prop('hidden', !config.canManage);
        $('#sheetDeleteBtn').prop('hidden', !config.canManageStructure);

        var $lifecycle = $('#sheetLifecycleActions');
        if (config.canManageStructure) {
            $lifecycle.prop('hidden', false);
            var current = student.status || 'active';
            $lifecycle.find('[data-lifecycle="promote"], [data-lifecycle="transfer"], [data-lifecycle="graduate"]').prop('hidden', current !== 'active');
            $lifecycle.find('[data-lifecycle="archive"]').prop('hidden', current === 'archived');
            $lifecycle.find('[data-lifecycle="reactivate"]').prop('hidden', current === 'active');
        } else {
            $lifecycle.prop('hidden', true);
        }
    }

    function openStudentSheet(studentOrId) {
        var student = typeof studentOrId === 'object'
            ? studentOrId
            : studentCache[String(studentOrId)];

        sheetStudentId = student ? student.id : null;
        lifecycleStudent = null;

        if (student) {
            $('#sheetName').text(student.full_name || 'Student');
            $('#sheetAvatar').replaceWith(
                avatarMarkup(student, 'sd-avatar--lg').replace('class="sd-avatar', 'id="sheetAvatar" class="sd-avatar')
            );
            $('#sheetBadges').html('');
        }

        $('#sheetFacts').empty();
        $('#sheetContacts').empty();
        $('#sheetAcademic').empty();
        $('#sheetDiscipline').empty();
        $('#sheetRewards').empty();
        $('#sheetContent').prop('hidden', true);
        $('#sheetLoading').prop('hidden', false);
        $('#sheetLifecycleActions').prop('hidden', true);
        $('#sheetEditBtn').prop('hidden', true);
        $('#sheetDeleteBtn').prop('hidden', true);
        $('#sheetPrintBtn').prop('hidden', true);

        $sheet.removeClass('hidden');
        $('body').addClass('sheet-open');

        $.ajax({
            url: config.detailUrl,
            method: 'GET',
            dataType: 'json',
            data: { id: sheetStudentId }
        }).done(function (response) {
            var detail = response && response.data;
            if (response.status !== 'success' || !detail) {
                $('#sheetLoading').html('<span class="error">' + escapeHtml((response && response.message) || 'Unable to load this profile.') + '</span>');
                return;
            }
            $('#sheetLoading').prop('hidden', true);
            $('#sheetContent').prop('hidden', false);
            renderProfile(detail);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $('#sheetLoading').html('<span class="error">' + escapeHtml(response.message || 'Unable to load this profile.') + '</span>');
        });
    }

    function closeStudentSheet() {
        $sheet.addClass('hidden');
        $('body').removeClass('sheet-open');
        $('#sheetCardFrame').removeAttr('src');
        sheetStudentId = null;
        lifecycleStudent = null;
    }

    function deleteStudent(studentId) {
        if (!window.confirm('Delete this student and all linked term marks? This cannot be undone.')) {
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
                id: studentId,
                csrf_token: config.csrfToken
            }
        }).done(function (response) {
            showTableMessage(response.message || 'Student deleted.', false);
            toast(response.message || 'Student deleted.', 'success');
            closeStudentSheet();
            fetchStudents();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to delete the student.', true);
            toast(response.message || 'Unable to delete the student.', 'error');
        });
    }

    function deleteAllStudents() {
        if (!window.confirm('Delete all students from the system? This cannot be undone.')) {
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
                action: 'delete_all',
                csrf_token: config.csrfToken
            }
        }).done(function (response) {
            showTableMessage(response.message || 'All students deleted.', false);
            fetchStudents();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to delete all students.', true);
        });
    }

    // --- Lifecycle actions ---
    var $lifecycleModal = $('#lifecycleModal');
    var $lifecycleForm = $('#lifecycleForm');
    var $lifecycleFeedback = $('#lifecycleFeedback');
    var $lifecycleSubmit = $('#lifecycleSubmitBtn');

    var lifecycleLabels = {
        promote: 'Promote student',
        transfer: 'Transfer student',
        graduate: 'Graduate student',
        archive: 'Archive student',
        reactivate: 'Reactivate student'
    };

    function classOptions(selectedId) {
        var html = '<option value="">Select destination class</option>';
        $('#classFilter').find('option').each(function () {
            if (!this.value) {
                return;
            }
            html += '<option value="' + escapeHtml(this.value) + '"'
                + (String(selectedId) === String(this.value) ? ' selected' : '') + '>'
                + escapeHtml($(this).text()) + '</option>';
        });
        return html;
    }

    function yearOptions() {
        var html = '';
        $('#schoolYearOptions option').each(function () {
            html += '<option value="' + escapeHtml(this.value) + '"></option>';
        });
        return html;
    }

    function lifecycleFields(action) {
        if (action === 'promote') {
            return '<div class="sd-lifecycle-fields">'
                + '<div class="field"><label for="lifecycleClassId">Destination class</label>'
                + '<select id="lifecycleClassId" name="class_id" required>' + classOptions('') + '</select></div>'
                + '<div class="field"><label for="lifecycleYear">Academic year</label>'
                + '<input type="text" id="lifecycleYear" name="school_year" list="lifecycleYearOptions" pattern="\\d{4}-\\d{4}" value="' + escapeHtml(config.currentYear || '') + '" placeholder="2026-2027">'
                + '<datalist id="lifecycleYearOptions">' + yearOptions() + '</datalist></div>'
                + '<p class="sd-lifecycle-note">Promotion reindexes the student code for the destination class and sets the record back to active.</p>'
                + '</div>';
        }

        if (action === 'transfer') {
            return '<div class="sd-lifecycle-fields">'
                + '<div class="field"><label for="lifecycleDestination">Receiving school</label>'
                + '<input type="text" id="lifecycleDestination" name="destination" maxlength="160" required placeholder="Name of the receiving school"></div>'
                + '<div class="field"><label for="lifecycleNote">Note</label>'
                + '<textarea id="lifecycleNote" name="note" rows="3" maxlength="400" placeholder="Optional transfer note"></textarea></div>'
                + '</div>';
        }

        return '<div class="sd-lifecycle-fields">'
            + '<p class="sd-lifecycle-note" id="lifecycleBlurb"></p>'
            + '<div class="field"><label for="lifecycleNote">Note</label>'
            + '<textarea id="lifecycleNote" name="note" rows="3" maxlength="400" placeholder="Optional note for the audit trail"></textarea></div>'
            + '</div>';
    }

    function openLifecycle(action) {
        if (!lifecycleStudent || !$lifecycleModal.length) {
            return;
        }

        var status = lifecycleStudent.status || 'active';
        if (action === 'reactivate' && status === 'active') {
            toast('This student is already active.', 'info');
            return;
        }

        $('#lifecycleStudentId').val(lifecycleStudent.id);
        $('#lifecycleAction').val(action);
        $('#lifecycleTitle').text(lifecycleLabels[action] || 'Update student status');
        $('#lifecycleStudentName').text((lifecycleStudent.full_name || 'Student') + ' · ' + (lifecycleStudent.class_name || 'Unassigned'));
        $('#lifecycleFields').html(lifecycleFields(action));

        var blurbs = {
            graduate: 'The student will be marked as graduated and kept in the historical directory.',
            archive: 'The student will be archived and hidden from active class lists. Their records are retained.',
            reactivate: 'The student will return to active status and appear in class lists again.'
        };
        $('#lifecycleBlurb').text(blurbs[action] || '');

        $lifecycleFeedback.removeClass('error success').text('');
        $lifecycleSubmit.prop('disabled', false).find('.btn-loader').prop('hidden', true);
        $lifecycleSubmit.find('.btn-label').text('Apply action');
        $lifecycleModal.removeClass('hidden');
    }

    function closeLifecycle() {
        $lifecycleModal.addClass('hidden');
        $lifecycleForm[0].reset();
        $('#lifecycleFields').empty();
        $lifecycleFeedback.removeClass('error success').text('');
    }

    if ($lifecycleForm.length) {
        $lifecycleForm.on('submit', function (event) {
            event.preventDefault();

            var action = $('#lifecycleAction').val();
            var $label = $lifecycleSubmit.find('.btn-label');
            var $loader = $lifecycleSubmit.find('.btn-loader');

            if (action === 'promote' && !$('#lifecycleClassId').val()) {
                $lifecycleFeedback.addClass('error').text('Select a destination class.');
                return;
            }

            if (action === 'transfer' && !$.trim($('#lifecycleDestination').val() || '')) {
                $lifecycleFeedback.addClass('error').text('Enter the receiving school.');
                return;
            }

            if (['transfer', 'graduate', 'archive'].indexOf(action) !== -1
                && !window.confirm('Apply "' + lifecycleLabels[action].toLowerCase() + '" to this student?')) {
                return;
            }

            $lifecycleSubmit.prop('disabled', true);
            $loader.prop('hidden', false);
            $label.text('Applying...');
            $lifecycleFeedback.removeClass('error success').text('');

            $.ajax({
                url: config.lifecycleUrl,
                method: 'POST',
                dataType: 'json',
                data: $lifecycleForm.serialize(),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).done(function (response) {
                $lifecycleFeedback.addClass('success').text(response.message || 'Student updated.');
                toast(response.message || 'Student updated.', 'success');
                window.setTimeout(function () {
                    closeLifecycle();
                    closeStudentSheet();
                    fetchStudents();
                }, 700);
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                $lifecycleFeedback.addClass('error').text(response.message || 'Unable to update the student.');
            }).always(function () {
                $lifecycleSubmit.prop('disabled', false);
                $loader.prop('hidden', true);
                $label.text('Apply action');
            });
        });
    }

    // --- Directory events ---
    $tableBody.on('click', '.view-student', function () {
        openStudentSheet($(this).data('id'));
    });

    $tableBody.on('click', '.edit-student', function () {
        var student = studentCache[String($(this).data('id'))];
        if (student) {
            editStudent(student);
        }
    });

    $tableBody.on('click', '.delete-student', function () {
        deleteStudent($(this).data('id'));
    });

    $table.on('click', '.sd-sort', function () {
        var sort = $(this).data('sort');
        var dir = state.sort === sort && state.dir === 'asc' ? 'desc' : 'asc';
        setSort(sort, dir);
        state.page = 1;
        fetchStudents();
    });

    $pagination.on('click', '.sd-page-btn', function () {
        if ($(this).prop('disabled')) {
            return;
        }
        var page = Number($(this).data('page'));
        if (!page || page === state.page) {
            return;
        }
        state.page = page;
        fetchStudents();
    });

    $('#pageSize').on('change', function () {
        state.limit = Number($(this).val()) || 25;
        state.page = 1;
        fetchStudents();
    });

    $searchFilter.on('input', function () {
        clearTimeout(window.searchTimeout);
        window.searchTimeout = setTimeout(function () {
            state.page = 1;
            fetchStudents();
        }, 400);
    });

    $yearFilter.on('change', function () {
        state.page = 1;
        fetchStudents();
    });

    $streamFilter.add($dormitoryFilter).add($statusFilter).on('change', function () {
        state.page = 1;
        fetchStudents();
    });

    $categoryFilter.on('change', function () {
        syncScope($(this).val());
        filterClassOptions($classFilter, $(this).val());
        state.page = 1;
        fetchStudents();
    });

    $classFilter.on('change', function () {
        state.page = 1;
        fetchStudents();
    });

    $('.sd-scope-btn').on('click', function () {
        var scope = $(this).data('scope');
        $categoryFilter.val(scope ? String(scope) : '');
        syncScope(scope);
        filterClassOptions($classFilter, scope || '');
        state.page = 1;
        fetchStudents();
    });

    $('#clearFiltersBtn').on('click', function () {
        $searchFilter.val('');
        $yearFilter.val('');
        $categoryFilter.val('');
        $classFilter.val('');
        $streamFilter.val('');
        $dormitoryFilter.val('');
        $statusFilter.val('');
        filterClassOptions($classFilter, '');
        syncScope('');
        state.page = 1;
        fetchStudents();
    });

    $('#refreshStudentsBtn').on('click', function () {
        var $btn = $(this).addClass('is-loading').prop('disabled', true);
        fetchStudents().always(function () {
            $btn.removeClass('is-loading').prop('disabled', false);
        });
    });

    $('#closeStudentSheet').on('click', closeStudentSheet);

    $sheet.on('click', function (event) {
        if (event.target === $sheet[0]) {
            closeStudentSheet();
        }
    });

    $(document).on('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        if ($lifecycleModal.length && !$lifecycleModal.hasClass('hidden')) {
            closeLifecycle();
            return;
        }
        if (!$sheet.hasClass('hidden')) {
            closeStudentSheet();
        }
    });

    $('#sheetEditBtn').on('click', function () {
        if (!lifecycleStudent) {
            return;
        }
        var student = lifecycleStudent;
        closeStudentSheet();
        editStudent(student);
    });

    $('#sheetDeleteBtn').on('click', function () {
        if (!lifecycleStudent) {
            return;
        }
        deleteStudent(lifecycleStudent.id);
    });

    $('#sheetLifecycleActions').on('click', '[data-lifecycle]', function () {
        openLifecycle($(this).data('lifecycle'));
    });

    if ($lifecycleModal.length) {
        $('#closeLifecycleModal, #cancelLifecycleModal').on('click', closeLifecycle);
        $lifecycleModal.on('click', function (event) {
            if (event.target === $lifecycleModal[0]) {
                closeLifecycle();
            }
        });
    }

    $('#deleteAllStudentsBtn').on('click', deleteAllStudents);

    $('#openStudentModal').on('click', function () {
        resetForm();
        if (config.currentYear) {
            $('#schoolYear').val(config.currentYear);
        }
        openModal();
    });
    $('#closeStudentModal, #cancelStudentModal').on('click', closeModal);

    $('#photoFile').on('change', function () {
        var file = this.files && this.files[0];
        if (!file) {
            return;
        }
        if (!/^image\//.test(file.type)) {
            $formFeedback.addClass('error').text('Portrait must be an image file.');
            this.value = '';
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            $formFeedback.addClass('error').text('Portrait must be 10 MiB or smaller.');
            this.value = '';
            return;
        }
        $('#removePhoto').prop('checked', false);
        showPortraitPreview(URL.createObjectURL(file), file.name);
        $formFeedback.removeClass('error success').text('');
    });

    $('#removePhoto').on('change', function () {
        if ($(this).is(':checked')) {
            $('#photoFile').val('');
            showPortraitPreview('', '');
            $('#photoCurrentHint').text('Portrait will be cleared when you save.');
            return;
        }
        $('#photoCurrentHint').text('Current portrait shown above. Choose a file to replace it.');
        showPortraitPreview(currentPortraitUrl, 'Current portrait');
    });

    $('#categoryId').on('change', function () {
        filterClassOptions($('#classId'), $(this).val());
    });

    $studentForm.on('submit', function (event) {
        event.preventDefault();
        submitStudentForm();
    });

    // --- Upload ---
    var $uploadModal = $('#uploadModal');
    var $uploadForm = $('#uploadForm');
    var $uploadFormFeedback = $('#uploadFormFeedback');
    var $uploadSubmitButton = $('#uploadSubmitButton');
    var $uploadSubmitLabel = $uploadSubmitButton.find('.btn-label');
    var $uploadSubmitLoader = $uploadSubmitButton.find('.btn-loader');
    var $pasteData = $('#pasteData');
    var $uploadCategoryId = $('#uploadCategoryId');
    var $uploadClassId = $('#uploadClassId');

    $('#openUploadModal').on('click', function () {
        $uploadForm[0].reset();
        $uploadFormFeedback.removeClass('error success partial').text('');
        filterClassOptions($uploadClassId, $uploadCategoryId.val());
        $uploadModal.removeClass('hidden');
    });

    $('#closeUploadModal, #cancelUploadModal').on('click', function () {
        $uploadModal.addClass('hidden');
    });

    $uploadCategoryId.on('change', function () {
        filterClassOptions($uploadClassId, $(this).val());
    });

    $uploadForm.on('submit', function (event) {
        event.preventDefault();

        var hasFile = $('#csvFile').val() !== '';
        var hasPaste = $.trim($pasteData.val() || '') !== '';

        if (!hasFile && !hasPaste) {
            $uploadFormFeedback.addClass('error').text('Upload a CSV/Excel file or paste rows copied from Excel.');
            return;
        }

        var formData = new FormData(this);
        if (hasPaste) {
            formData.set('paste_data', $pasteData.val());
        } else {
            formData.delete('paste_data');
        }

        $uploadSubmitButton.prop('disabled', true);
        $uploadSubmitLoader.prop('hidden', false);
        $uploadSubmitLabel.text('Uploading...');
        $uploadFormFeedback.removeClass('error success partial').text('');

        $.ajax({
            url: config.importUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).done(function (response) {
            $uploadFormFeedback.addClass(response.status === 'success' ? 'success' : 'partial').text(response.message);
            if (response.status === 'success') {
                window.setTimeout(function () {
                    $uploadModal.addClass('hidden');
                    fetchStudents();
                }, 1200);
            } else {
                fetchStudents();
            }
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $uploadFormFeedback.addClass('error').text(response.message || 'Error uploading file.');
        }).always(function () {
            $uploadSubmitButton.prop('disabled', false);
            $uploadSubmitLoader.prop('hidden', true);
            $uploadSubmitLabel.text('Upload');
        });
    });

    // --- Tabs ---
    $('.tab-btn').on('click', function () {
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.tab-content').removeClass('active');
        $('#' + $(this).data('target')).addClass('active');

        if ($(this).data('target') === 'tab-classes') {
            fetchClasses();
        } else if ($(this).data('target') === 'tab-categories') {
            fetchCategories();
        }
    });

    // --- Categories ---
    var $categoryModal = $('#categoryModal');
    var $categoryForm = $('#categoryForm');
    var $categoryTableBody = $('#categoryTableBody');
    var $categoryTableFeedback = $('#categoryTableFeedback');

    function fetchCategories() {
        $categoryTableBody.html('<tr><td colspan="' + (config.canManageStructure ? 2 : 1) + '" class="table-placeholder">Loading...</td></tr>');
        $categoryTableFeedback.text('');

        $.ajax({
            url: config.categoryFetchUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            var rows = response.data && response.data.items ? response.data.items : [];
            if (!rows.length) {
                $categoryTableBody.html('<tr><td colspan="' + (config.canManageStructure ? 2 : 1) + '" class="table-placeholder">No categories found.</td></tr>');
                return;
            }
            var html = $.map(rows, function (cat) {
                var actions = config.canManageStructure
                    ? '<td class="action-cell"><button type="button" class="inline-btn edit-category" data-cat=\'' + encodeURIComponent(JSON.stringify(cat)) + '\'>Edit</button> <button type="button" class="inline-btn danger delete-category" data-id="' + escapeHtml(String(cat.id)) + '">Delete</button></td>'
                    : '';
                return '<tr><td>' + escapeHtml(cat.name) + '</td>' + actions + '</tr>';
            }).join('');
            $categoryTableBody.html(html);
        }).fail(function () {
            $categoryTableBody.html('<tr><td colspan="' + (config.canManageStructure ? 2 : 1) + '" class="table-placeholder">Failed to load categories.</td></tr>');
        });
    }

    $('#openCategoryModal').on('click', function () {
        $categoryForm[0].reset();
        $('#categoryIdInput').val('');
        $('#categoryModalTitle').text('Add a category');
        $categoryModal.removeClass('hidden');
    });

    $('#closeCategoryModal, #cancelCategoryModal').on('click', function () {
        $categoryModal.addClass('hidden');
    });

    $categoryForm.on('submit', function (event) {
        event.preventDefault();
        var isEdit = $('#categoryIdInput').val() !== '';
        $.ajax({
            url: isEdit ? config.categoryUpdateUrl : config.categoryCreateUrl,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function () {
            location.reload();
        }).fail(function (xhr) {
            alert((xhr.responseJSON && xhr.responseJSON.message) || 'Error saving category');
        });
    });

    $categoryTableBody.on('click', '.edit-category', function () {
        var cat = JSON.parse(decodeURIComponent($(this).attr('data-cat')));
        $categoryForm[0].reset();
        $('#categoryIdInput').val(cat.id);
        $('#categoryName').val(cat.name);
        $('#categoryModalTitle').text('Edit category');
        $categoryModal.removeClass('hidden');
    });

    $categoryTableBody.on('click', '.delete-category', function () {
        if (!confirm('Delete this category?')) return;
        $.ajax({
            url: config.categoryDeleteUrl,
            method: 'POST',
            data: { id: $(this).data('id'), csrf_token: config.csrfToken },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function () {
            location.reload();
        }).fail(function (xhr) {
            alert((xhr.responseJSON && xhr.responseJSON.message) || 'Error deleting category');
        });
    });

    // --- Classes ---
    var $classModal = $('#classModal');
    var $classForm = $('#classForm');
    var $classTableFeedback = $('#classTableFeedback');
    var $classAccordionContainer = $('#classAccordionContainer');

    function deleteAllClasses() {
        if (!window.confirm('Delete all classes from the system? This cannot be undone.')) {
            return;
        }

        $.ajax({
            url: config.classDeleteUrl,
            method: 'POST',
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            data: {
                action: 'delete_all',
                csrf_token: config.csrfToken
            }
        }).done(function (response) {
            alert(response.message || 'All classes deleted.');
            window.location.reload();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            alert(response.message || 'Unable to delete all classes.');
        });
    }

    function fetchClasses() {
        $classAccordionContainer.html('<div class="table-placeholder">Loading...</div>');
        $classTableFeedback.text('');

        $.ajax({
            url: config.classFetchUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function (response) {
            var rows = response.data && response.data.items ? response.data.items : [];
            if (!rows.length) {
                $classAccordionContainer.html('<div class="table-placeholder">No classes found.</div>');
                return;
            }

            var grouped = {};
            $.each(rows, function (index, cls) {
                var cat = cls.category_name || 'Uncategorized';
                if (!grouped[cat]) grouped[cat] = [];
                grouped[cat].push(cls);
            });

            var html = '';
            $.each(grouped, function (catName, classes) {
                html += '<details style="background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.05); border-radius: 8px; padding: 1rem; cursor: pointer; transition: background 0.2s;">';
                html += '  <summary style="font-weight: 600; font-size: 1.1rem; color: var(--brand-2, #38bdf8); list-style: none; display: flex; justify-content: space-between; align-items: center;">';
                html += '    <span>' + escapeHtml(catName) + ' (' + classes.length + ' classes)</span>';
                html += '    <span style="font-size: 0.8rem; color: #94a3b8; font-weight: normal;">Click to view classes ▾</span>';
                html += '  </summary>';
                html += '  <div style="margin-top: 1rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1rem; cursor: default;">';
                html += '    <table class="students-table" style="margin: 0; width: 100%;">';
                html += '      <thead><tr><th>Class Name</th><th>Teacher</th>';
                if (config.canManage || config.canManageStructure) html += '<th>Actions</th>';
                html += '      </tr></thead><tbody>';

                $.each(classes, function (index, cls) {
                    var actions = '';
                    if (config.canManage || config.canManageStructure) {
                        actions = '<td class="action-cell">';
                        if (config.canManage) {
                            actions += '<button type="button" class="inline-btn report-class" data-id="' + escapeHtml(String(cls.id)) + '">Report</button> ';
                        }
                        if (config.canManageStructure) {
                            actions += '<button type="button" class="inline-btn edit-class" data-cls=\'' + encodeURIComponent(JSON.stringify(cls)) + '\'>Edit</button> <button type="button" class="inline-btn danger delete-class" data-id="' + escapeHtml(String(cls.id)) + '">Delete</button>';
                        }
                        actions += '</td>';
                    }
                    html += '<tr><td style="color: #f8fafc;">' + escapeHtml(cls.name) + '</td>';
                    html += '<td>' + escapeHtml(cls.class_teacher_name || 'Unassigned') + '</td>';
                    html += actions + '</tr>';
                });

                html += '      </tbody></table>';
                html += '  </div>';
                html += '</details>';
            });
            $classAccordionContainer.html(html);
        }).fail(function () {
            $classAccordionContainer.html('<div class="table-placeholder">Failed to load classes.</div>');
        });
    }

    $('#openClassModal').on('click', function () {
        $classForm[0].reset();
        $('#classIdInput').val('');
        $('#classTeacherId').val('');
        $('#classModalTitle').text('Add a class');
        $classModal.removeClass('hidden');
    });

    $('#closeClassModal, #cancelClassModal').on('click', function () {
        $classModal.addClass('hidden');
    });

    $('#deleteAllClassesBtn').on('click', deleteAllClasses);

    $classForm.on('submit', function (event) {
        event.preventDefault();
        var isEdit = $('#classIdInput').val() !== '';
        $.ajax({
            url: isEdit ? config.classUpdateUrl : config.classCreateUrl,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function () {
            location.reload();
        }).fail(function (xhr) {
            alert((xhr.responseJSON && xhr.responseJSON.message) || 'Error saving class');
        });
    });

    $classAccordionContainer.on('click', '.edit-class', function () {
        var cls = JSON.parse(decodeURIComponent($(this).attr('data-cls')));
        $classForm[0].reset();
        $('#classIdInput').val(cls.id);
        $('#className').val(cls.name);
        $('#classCategoryId').val(cls.category_id);
        $('#classTeacherId').val(cls.class_teacher_user_id || '');
        $('#classModalTitle').text('Edit class');
        $classModal.removeClass('hidden');
    });

    $classAccordionContainer.on('click', '.delete-class', function () {
        if (!confirm('Delete this class?')) return;
        $.ajax({
            url: config.classDeleteUrl,
            method: 'POST',
            data: { id: $(this).data('id'), csrf_token: config.csrfToken },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function () {
            location.reload();
        }).fail(function (xhr) {
            alert((xhr.responseJSON && xhr.responseJSON.message) || 'Error deleting class');
        });
    });

    var $reportModal = $('#reportModal');
    $classAccordionContainer.on('click', '.report-class', function () {
        $('#reportClassId').val($(this).data('id'));
        $reportModal.removeClass('hidden');
    });

    $('#closeReportModal, #cancelReportModal').on('click', function () {
        $reportModal.addClass('hidden');
    });

    $('#reportForm').on('submit', function () {
        window.setTimeout(function () {
            $reportModal.addClass('hidden');
        }, 500);
    });

    $('#openAssignTeacherModal').on('click', function () {
        $('#assignTeacherForm')[0].reset();
        $('#assignTeacherFeedback').removeClass('error success').text('');
        $('#assignTeacherModalTitle').text('Assign a teacher to a class');
        $('#assignTeacherModal').removeClass('hidden');
    });

    $('#closeAssignTeacherModal, #cancelAssignTeacherModal').on('click', function () {
        $('#assignTeacherModal').addClass('hidden');
    });

    $('#assignTeacherForm').on('submit', function (event) {
        event.preventDefault();
        var $submitButton = $('#assignTeacherSubmitButton');
        var $submitLabel = $submitButton.find('.btn-label');
        var $submitLoader = $submitButton.find('.btn-loader');
        var $feedback = $('#assignTeacherFeedback');

        $submitButton.prop('disabled', true);
        $submitLoader.prop('hidden', false);
        $submitLabel.text('Assigning...');
        $feedback.removeClass('error success').text('');

        $.ajax({
            url: config.classAssignTeacherUrl,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (response) {
            $feedback.addClass('success').text(response.message || 'Teacher assigned successfully.');
            window.setTimeout(function () {
                $('#assignTeacherModal').addClass('hidden');
                fetchClasses();
            }, 900);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $feedback.addClass('error').text(response.message || 'Unable to assign teacher.');
        }).always(function () {
            $submitButton.prop('disabled', false);
            $submitLoader.prop('hidden', true);
            $submitLabel.text('Assign Teacher');
        });
    });

    setSort(state.sort, state.dir);
    fetchStudents();
});
