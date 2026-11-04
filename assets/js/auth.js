$(function () {
    const escapeHtml = (value) => $('<div>').text(value || '').html();

    const bindAjaxForm = (formId, buttonId, feedbackId) => { // Changed to const and moved inside $(function)
        var $form = $(formId);
        if (!$form.length) {
            return;
        }

        var $button = $(buttonId);
        if (!$button.length) {
            return;
        }

        var $feedback = $(feedbackId);
        var $label = $button.find('.btn-label');
        var $loader = $button.find('.btn-loader');
        var originalLabel = $button.data('label') || $button.attr('data-label') || ($label.length ? $label.text().trim() : 'Submit');
        $button.data('label', originalLabel);
        $button.attr('data-label', originalLabel);

        $form.on('submit', function (event) {
            event.preventDefault();

            $feedback.removeClass('error success').text('');
            $button.prop('disabled', true);
            $loader.prop('hidden', false);
            $label.text('Processing...');

            $.ajax({
                url: $form.attr('action') || window.location.href,
                method: 'POST',
                data: $form.serialize(),
                dataType: 'json',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).done(function (response) {
                var isSuccess = response.status === 'success';
                $feedback.removeClass('error success');

                if (isSuccess && response.reset_url && formId === '#forgotPasswordForm') {
                    $feedback.addClass('success').html((response.message || 'Request completed.') + ' <a href="' + escapeHtml(response.reset_url) + '" style="color: var(--brand-2); font-weight: 700; text-decoration: underline;">Open reset link</a>');
                } else {
                    $feedback.addClass(isSuccess ? 'success' : 'error').text(response.message || 'Request completed.');
                }

                if (isSuccess && response.redirect) {
                    window.setTimeout(function () {
                        window.location.href = response.redirect;
                    }, 700);
                }
            }).fail(function (xhr) {
                var response = {};
                try {
                    response = xhr.responseJSON || {};
                } catch (error) {
                    response = {};
                }

                $feedback.addClass('error').text(response.message || 'The request failed. Please try again.');
            }).always(function () {
                $button.prop('disabled', false);
                $loader.prop('hidden', true);
                $label.text($button.data('label') || 'Submit');
            });
        });
    }

    bindAjaxForm('#loginForm', '#loginButton', '#loginFeedback');
    bindAjaxForm('#registerForm', '#registerButton', '#registerFeedback');
    bindAjaxForm('#forgotPasswordForm', '#forgotPasswordButton', '#forgotPasswordFeedback');
    bindAjaxForm('#resetPasswordForm', '#resetPasswordButton', '#resetPasswordFeedback');

    // Handle smooth transitions for navigation links between Login and Register
    $('.ghost-btn, .helper-text a').on('click', function (event) {
        var href = $(this).attr('href');
        if (href && !href.startsWith('#') && !href.startsWith('javascript:')) {
            event.preventDefault();
            $('.auth-shell').css({
                'transition': 'opacity 0.3s ease, transform 0.3s ease',
                'opacity': '0',
                'transform': 'translateY(10px)'
            });
            setTimeout(function () { window.location.href = href; }, 300);
        }
    });
});
