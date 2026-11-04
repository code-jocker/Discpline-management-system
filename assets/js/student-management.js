$(function () {
    var config = window.SBMSStudentConfig || {};
    var state = {
        page: 1,
        limit: 10000,
        totalPages: 1
    };

    var $categoryFilter = $('#categoryFilter');
    var $classFilter = $('#classFilter');
    var $searchFilter = $('#searchFilter');
    var $tableBody = $('#studentAccordionContainer');
    var $tableMeta = $('#tableMeta');
    var $tableFeedback = $('#tableFeedback');
    var $studentModal = $('#studentModal');
    var $studentForm = $('#studentForm');
    var $modalTitle = $('#modalTitle');
    var $submitButton = $('#studentSubmitButton');
    var $submitLabel = $submitButton.find('.btn-label');
    var $submitLoader = $submitButton.find('.btn-loader');
    var $formFeedback = $('#formFeedback');
    var currentPortraitUrl = '';

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    var school = config.school || {};

    function initialsOf(name) {
        var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
        if (!parts.length) {
            return '—';
        }
        return (parts[0].charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    }

    function barcodeBars(code) {
        // Deterministic bars derived from the student code, so every card prints
        // a unique pattern without pulling in a barcode library.
        var value = String(code || '');
        var seed = 7;
        var i;
        for (i = 0; i < value.length; i++) {
            seed = (seed * 31 + value.charCodeAt(i)) % 1000003;
        }

        var bars = '';
        for (i = 0; i < 26; i++) {
            seed = (seed * 1103515245 + 12345) % 2147483648;
            bars += '<i style="width:' + (1 + seed % 3) + 'px' + (seed % 2 ? ';opacity:1' : ';opacity:.55') + '"></i>';
        }

        return bars;
    }

    function idRow(label, value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }
        return '<div class="sid-row"><span class="sid-label">' + escapeHtml(label) + '</span>'
            + '<span class="sid-value">' + escapeHtml(value) + '</span></div>';
    }

    function photoUrl(path) {
        if (!path) {
            return '';
        }
        return (config.baseUrl || '') + '/storage/uploads/' + String(path).replace(/^\/+/, '');
    }

    function studentIdCard(student) {
        var actions = '';
        if (config.canManageStructure) {
            actions = '<div class="sid-actions">'
                + '<button type="button" class="inline-btn edit-student" data-student="' + encodeURIComponent(JSON.stringify(student)) + '">Edit</button>'
                + '<button type="button" class="inline-btn danger delete-student" data-id="' + escapeHtml(String(student.id)) + '">Delete</button>'
                + '</div>';
        }

        var portrait = student.photo_path
            ? '<img class="sid-monogram sid-photo" src="' + escapeHtml(photoUrl(student.photo_path)) + '" alt="">'
            : '<span class="sid-monogram" aria-hidden="true">' + escapeHtml(initialsOf(student.full_name)) + '</span>';

        return '<article class="sid-card">'
            + '<div class="sid-crest">'
            + '<img class="sid-logo" src="' + escapeHtml(school.logo || '') + '" alt="" aria-hidden="true">'
            + '<span class="sid-school">' + escapeHtml(school.name || 'SBMS') + '</span>'
            + '<span class="sid-year">' + escapeHtml(school.year || '') + '</span>'
            + '</div>'
            + '<div class="sid-main">'
            + '<div class="sid-portrait">'
            + portrait
            + '<span class="sid-portrait-label">' + (student.photo_path ? 'Portrait' : 'No photo') + '</span>'
            + '</div>'
            + '<div class="sid-details">'
            + '<p class="sid-band">Student Card</p>'
            + '<button type="button" class="sid-name-btn sid-row-open" data-student="' + encodeURIComponent(JSON.stringify(student)) + '">'
            + '<span class="sid-name-value">' + escapeHtml(student.full_name) + '</span>'
            + '<span class="sid-name-hint">View full details</span>'
            + '</button>'
            + idRow('ID', student.student_code)
            + idRow('Class', student.class_name)
            + idRow('Parent contact', student.parent_contact)
            + '</div>'
            + '</div>'
            + '<div class="sid-foot">'
            + '<div class="sid-barcode" aria-hidden="true">' + barcodeBars(student.student_code) + '</div>'
            + '<div class="sid-foot-meta">'
            + '<span class="sid-address">' + escapeHtml(student.address || school.address || '') + '</span>'
            + '</div>'
            + '<div class="sid-foot-actions">'
            + '<a class="inline-btn sid-print" href="' + escapeHtml(cardUrlFor(student.id)) + '" target="_blank" rel="noreferrer">Card</a>'
            + actions
            + '</div>'
            + '</div>'
            + '</article>';
    }

    function cardUrlFor(studentId) {
        return (config.cardUrl || 'students/card.php') + '?student_id=' + encodeURIComponent(String(studentId)) + '&print=1';
    }

    function classCardUrl(classId) {
        return (config.cardUrl || 'students/card.php') + '?class_id=' + encodeURIComponent(String(classId));
    }

    function filterClassOptions($select, categoryId) {
        $select.find('option').each(function () {
            var optionCategoryId = $(this).data('category-id');
            if (!optionCategoryId || !categoryId || String(optionCategoryId) === String(categoryId)) {
                $(this).prop('hidden', false);
            } else {
                $(this).prop('hidden', true);
            }
        });

        if (categoryId && $select.find('option:selected').data('category-id') !== Number(categoryId)) {
            $select.val('');
        }
    }

    function showTableMessage(message, isError) {
        $tableFeedback.removeClass('error success').addClass(isError ? 'error' : 'success').text(message || '');
    }

    function renderRows(rows) {
        if (!rows.length) {
            $tableBody.html('<div class="table-placeholder">No students match the current filters.</div>');
            return;
        }

        var groupedByCategory = {};
        $.each(rows, function(i, student) {
            var cat = student.category_name || 'Uncategorized';
            var cls = student.class_name || 'Unassigned';
            
            if (!groupedByCategory[cat]) {
                groupedByCategory[cat] = {};
            }
            if (!groupedByCategory[cat][cls]) {
                groupedByCategory[cat][cls] = [];
            }
            groupedByCategory[cat][cls].push(student);
        });

        var tabButtons = '<div class="category-pills" style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap;">';
        var tabContents = '';
        var first = true;

        $.each(groupedByCategory, function(catName, classes) {
            var catId = 'cat-' + catName.replace(/[^a-zA-Z0-9]/g, '');
            var activeClass = ''; // No active pill by default
            var displayStyle = 'none'; // Hidden by default
            
            tabButtons += '<button type="button" class="category-pill-btn ' + activeClass + '" data-target="' + catId + '" style="background: transparent; border: 1px solid var(--line, rgba(255,255,255,0.2)); color: var(--text, #94a3b8); padding: 0.5rem 1rem; border-radius: 20px; font-weight: 600; cursor: pointer; transition: all 0.2s;">' + escapeHtml(catName) + '</button>';
            
            tabContents += '<div id="' + catId + '" class="category-content-panel" style="display: ' + displayStyle + ';">';
            
            $.each(classes, function(clsName, students) {
                tabContents += '<details class="sid-group">';
                tabContents += '  <summary>';
                tabContents += '    <span>' + escapeHtml(clsName) + ' (' + students.length + ' students)</span>';
                tabContents += '    <span class="sid-summary-hint">Tap to open cards</span>';
                tabContents += '  </summary>';
                tabContents += '  <div class="sid-group-tools">';
                tabContents += '    <a class="inline-btn" href="' + escapeHtml(classCardUrl(students[0].class_id)) + '" target="_blank" rel="noreferrer">Print class cards</a>';
                tabContents += '  </div>';
                tabContents += '  <div class="sid-grid">';

                $.each(students, function(idx, student) {
                    tabContents += studentIdCard(student);
                });

                tabContents += '  </div></details>';
            });
            
            tabContents += '</div>';
        });

        tabButtons += '</div>';
        $tableBody.html(tabButtons + tabContents);
    }

    function updatePagination(meta) {
        $tableMeta.text('Showing all ' + meta.total + ' students');
    }

    function fetchStudents() {
        $tableBody.html('<div class="table-placeholder">Loading students...</div>');
        showTableMessage('', false);

        $.ajax({
            url: config.fetchUrl,
            method: 'GET',
            dataType: 'json',
            data: {
                category_id: $categoryFilter.val(),
                class_id: $classFilter.val(),
                search: $searchFilter.val(),
                page: state.page,
                limit: state.limit
            }
        }).done(function (response) {
            renderRows(response.data && response.data.items ? response.data.items : []);
            updatePagination((response.data && response.data.meta) || { page: 1, pages: 1, total: 0, limit: state.limit });
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to load students.', true);
            renderRows([]);
        });
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
        resetForm();
        $('#studentId').val(student.id);
        $('#studentCode').val(student.student_code).prop('disabled', true);
        $('#fullName').val(student.full_name);
        $('#parentContact').val(student.parent_contact);
        $('#dateOfBirth').val(student.date_of_birth || '');
        $('#address').val(student.address || '');
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
    var sheetStudent = null;

    function cardPreviewUrl(studentId) {
        return (config.cardUrl || 'students/card.php') + '?student_id=' + encodeURIComponent(String(studentId)) + '&embed=1';
    }

    // The preview iframe always renders the full 900x570 card, then scales to the
    // sheet width so the phone layout never re-flows the printed design.
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

    function openStudentSheet(student) {
        sheetStudent = student;
        $('#sheetName').text(student.full_name || '');

        var facts = [
            ['Student ID', student.student_code],
            ['Class', student.class_name],
            ['Level', student.category_name],
            ['Date of birth', student.date_of_birth || 'Not recorded'],
            ['Home address', student.address || 'Not recorded'],
            ['Parent contact', student.parent_contact]
        ];

        $('#sheetFacts').html($.map(facts, function (fact) {
            return '<div class="sheet-fact"><dt>' + escapeHtml(fact[0]) + '</dt><dd>' + escapeHtml(fact[1] || '—') + '</dd></div>';
        }).join(''));

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

        $('#sheetEditBtn').prop('hidden', !config.canManageStructure);
        $('#sheetDeleteBtn').prop('hidden', !config.canManageStructure);

        $sheet.removeClass('hidden');
        $('body').addClass('sheet-open');
    }

    function closeStudentSheet() {
        $sheet.addClass('hidden');
        $('body').removeClass('sheet-open');
        $('#sheetCardFrame').removeAttr('src');
        sheetStudent = null;
    }

    $tableBody.on('click', '.sid-row-open', function () {
        var encoded = $(this).attr('data-student');
        if (!encoded) {
            return;
        }
        openStudentSheet(JSON.parse(decodeURIComponent(encoded)));
    });

    $('#closeStudentSheet').on('click', closeStudentSheet);

    $sheet.on('click', function (event) {
        if (event.target === $sheet[0]) {
            closeStudentSheet();
        }
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && !$sheet.hasClass('hidden')) {
            closeStudentSheet();
        }
    });

    $('#sheetEditBtn').on('click', function () {
        if (!sheetStudent) {
            return;
        }
        var student = sheetStudent;
        closeStudentSheet();
        editStudent(student);
    });

    $('#sheetDeleteBtn').on('click', function () {
        if (!sheetStudent) {
            return;
        }
        deleteStudent(sheetStudent.id);
        closeStudentSheet();
    });

    function deleteStudent(studentId) {
        if (!window.confirm('Delete this student and linked term marks?')) {
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
                csrf_token: $studentForm.find('input[name="csrf_token"]').val()
            }
        }).done(function (response) {
            showTableMessage(response.message || 'Student deleted.', false);
            fetchStudents();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to delete the student.', true);
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
                csrf_token: $studentForm.find('input[name="csrf_token"]').val()
            }
        }).done(function (response) {
            showTableMessage(response.message || 'All students deleted.', false);
            fetchStudents();
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showTableMessage(response.message || 'Unable to delete all students.', true);
        });
    }

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

    $categoryFilter.on('change', function () {
        filterClassOptions($classFilter, $(this).val());
        state.page = 1;
        fetchStudents();
    });

    $classFilter.on('change', function () {
        state.page = 1;
        fetchStudents();
    });

    $searchFilter.on('input', function () {
        clearTimeout(window.searchTimeout);
        window.searchTimeout = setTimeout(function() {
            state.page = 1;
            fetchStudents();
        }, 400);
    });



    $('#expandAllBtn').on('click', function() {
        $tableBody.find('.category-content-panel').show();
        $tableBody.find('.category-pill-btn').css({
            background: 'var(--brand-2, #38bdf8)',
            color: '#04101d',
            border: '1px solid var(--brand-2, #38bdf8)'
        }).addClass('active-pill');
        $tableBody.find('details').prop('open', true);
    });

    $('#collapseAllBtn').on('click', function() {
        $tableBody.find('.category-content-panel').hide();
        $tableBody.find('.category-pill-btn').css({
            background: 'transparent',
            color: 'var(--text, #94a3b8)',
            border: '1px solid var(--line, rgba(255,255,255,0.2))'
        }).removeClass('active-pill');
        $tableBody.find('details').prop('open', false);
    });

    $('#refreshStudentsBtn').on('click', function() {
        var $btn = $(this);
        var originalHtml = $btn.html();
        $btn.html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.92-10.26l3.08 2.69"/></svg> Refreshing...');
        
        fetchStudents();
        
        setTimeout(function() {
            $btn.html(originalHtml);
        }, 800);
    });

    $tableBody.on('click', '.category-pill-btn', function() {
        $tableBody.find('.category-pill-btn').css({
            background: 'transparent',
            color: 'var(--brand-2, #38bdf8)'
        }).removeClass('active-pill');
        
        $(this).css({
            background: 'var(--brand-2, #38bdf8)',
            color: '#04101d'
        }).addClass('active-pill');
        
        $tableBody.find('.category-content-panel').hide();
        $('#' + $(this).data('target')).show();
    });

    $tableBody.on('click', '.edit-student', function () {
        var studentJson = $(this).attr('data-student');
        if (!studentJson) {
            return;
        }

        editStudent(JSON.parse(decodeURIComponent(studentJson)));
    });

    $tableBody.on('click', '.delete-student', function () {
        deleteStudent($(this).data('id'));
    });

    $('#deleteAllStudentsBtn').on('click', deleteAllStudents);

    $('#openStudentModal').on('click', function () {
        resetForm();
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

    // --- Upload Logic ---
    var $uploadModal = $('#uploadModal');
    var $uploadForm = $('#uploadForm');
    var $uploadFormFeedback = $('#uploadFormFeedback');
    var $uploadSubmitButton = $('#uploadSubmitButton');
    var $uploadSubmitLabel = $uploadSubmitButton.find('.btn-label');
    var $uploadSubmitLoader = $uploadSubmitButton.find('.btn-loader');
    var $pasteData = $('#pasteData');
    var $uploadCategoryId = $('#uploadCategoryId');
    var $uploadClassId = $('#uploadClassId');

    $('#openUploadModal').on('click', function() {
        $uploadForm[0].reset();
        $uploadFormFeedback.removeClass('error success partial').text('');
        filterClassOptions($uploadClassId, $uploadCategoryId.val());
        $uploadModal.removeClass('hidden');
    });

    $('#closeUploadModal, #cancelUploadModal').on('click', function() {
        $uploadModal.addClass('hidden');
    });

    $uploadCategoryId.on('change', function () {
        filterClassOptions($uploadClassId, $(this).val());
    });

    $uploadForm.on('submit', function (e) {
        e.preventDefault();

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
                setTimeout(function() {
                    $uploadModal.addClass('hidden');
                    fetchStudents();
                }, 1500);
            } else {
                fetchStudents(); // refresh to show whatever got imported
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

    // --- Tabs Logic ---
    $('.tab-btn').on('click', function() {
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

    // --- Category Logic ---
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
                var actions = config.canManageStructure ? '<td class="action-cell"><button type="button" class="inline-btn edit-category" data-cat=\'' + encodeURIComponent(JSON.stringify(cat)) + '\'>Edit</button> <button type="button" class="inline-btn danger delete-category" data-id="' + escapeHtml(String(cat.id)) + '">Delete</button></td>' : '';
                return '<tr><td>' + escapeHtml(cat.name) + '</td>' + actions + '</tr>';
            }).join('');
            $categoryTableBody.html(html);
        }).fail(function () {
            $categoryTableBody.html('<tr><td colspan="' + (config.canManageStructure ? 2 : 1) + '" class="table-placeholder">Failed to load categories.</td></tr>');
        });
    }

    $('#openCategoryModal').on('click', function() {
        $categoryForm[0].reset();
        $('#categoryIdInput').val('');
        $('#categoryModalTitle').text('Add a category');
        $categoryModal.removeClass('hidden');
    });

    $('#closeCategoryModal, #cancelCategoryModal').on('click', function() {
        $categoryModal.addClass('hidden');
    });

    $categoryForm.on('submit', function (e) {
        e.preventDefault();
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

    // --- Class Logic ---
    var $classModal = $('#classModal');
    var $classForm = $('#classForm');
    var $classTableBody = $('#classTableBody');
    var $classTableFeedback = $('#classTableFeedback');

    var $classAccordionContainer = $('#classAccordionContainer');
    
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
            
            // Group by category
            var grouped = {};
            $.each(rows, function(i, cls) {
                var cat = cls.category_name || 'Uncategorized';
                if (!grouped[cat]) grouped[cat] = [];
                grouped[cat].push(cls);
            });

            var html = '';
            $.each(grouped, function(catName, classes) {
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
                
                $.each(classes, function(idx, cls) {
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

    $('#openClassModal').on('click', function() {
        $classForm[0].reset();
        $('#classIdInput').val('');
        $('#classTeacherId').val('');
        $('#classModalTitle').text('Add a class');
        $classModal.removeClass('hidden');
    });

    $('#closeClassModal, #cancelClassModal').on('click', function() {
        $classModal.addClass('hidden');
    });

    $('#deleteAllClassesBtn').on('click', deleteAllClasses);

    $classForm.on('submit', function (e) {
        e.preventDefault();
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
        var classId = $(this).data('id');
        $('#reportClassId').val(classId);
        $reportModal.removeClass('hidden');
    });

    $('#closeReportModal, #cancelReportModal').on('click', function() {
        $reportModal.addClass('hidden');
    });

    $('#reportForm').on('submit', function() {
        // Hide the modal shortly after submit since it triggers a file download
        setTimeout(function() {
            $reportModal.addClass('hidden');
        }, 500);
    });

    $('#openAssignTeacherModal').on('click', function() {
        $('#assignTeacherForm')[0].reset();
        $('#assignTeacherFeedback').removeClass('error success').text('');
        $('#assignTeacherModalTitle').text('Assign a teacher to a class');
        $('#assignTeacherModal').removeClass('hidden');
    });

    $('#closeAssignTeacherModal, #cancelAssignTeacherModal').on('click', function() {
        $('#assignTeacherModal').addClass('hidden');
    });

    $('#assignTeacherForm').on('submit', function (e) {
        e.preventDefault();
        var $submitButton = $('#assignTeacherSubmitButton');
        var $submitLabel = $submitButton.find('.btn-label');
        var $submitLoader = $submitButton.find('.btn-loader');
        var $feedback = $('#assignTeacherFeedback');
        var $form = $(this);

        $submitButton.prop('disabled', true);
        $submitLoader.prop('hidden', false);
        $submitLabel.text('Assigning...');
        $feedback.removeClass('error success').text('');

        $.ajax({
            url: config.classAssignTeacherUrl,
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (response) {
            $feedback.addClass('success').text(response.message || 'Teacher assigned successfully.');
            setTimeout(function() {
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

    fetchStudents();
});
