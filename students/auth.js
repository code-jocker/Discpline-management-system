$(function () {
    const $loginForm = $('#loginForm');
    const $registerForm = $('#registerForm');
    const $loginBtn = $('#loginButton');
    const $registerBtn = $('#registerButton');
    const $loginFeedback = $('#loginFeedback');
    const $registerFeedback = $('#registerFeedback');

    function handleAuthSubmit($form, $btn, $feedback) {
        $form.on('submit', function (e) {
            e.preventDefault();
            
            $btn.prop('disabled', true).find('.btn-loader').attr('hidden', false);
            $feedback.hide().removeClass('error success');

            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                success: function (res) {
                    if (res.status === 'success') {
                        $feedback.text(res.message || 'Success! Redirecting...').addClass('success').show();
                        setTimeout(() => {
                            window.location.href = res.redirect;
                        }, 1000);
                    } else {
                        $feedback.text(res.message).addClass('error').show();
                        $btn.prop('disabled', false).find('.btn-loader').attr('hidden', true);
                    }
                },
                error: function (xhr) {
                    const res = xhr.responseJSON;
                    const msg = res && res.message ? res.message : 'A system error occurred. Please try again.';
                    $feedback.text(msg).addClass('error').show();
                    $btn.prop('disabled', false).find('.btn-loader').attr('hidden', true);
                }
            });
        });
    }

    if ($loginForm.length) handleAuthSubmit($loginForm, $loginBtn, $loginFeedback);
    if ($registerForm.length) handleAuthSubmit($registerForm, $registerBtn, $registerFeedback);
});