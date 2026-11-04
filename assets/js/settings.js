$(function () {
    const $muteToggle = $('#settingMuteToggle');
    const $desktopToggle = $('#settingDesktopToggle');
    const $feedback = $('#settingsFeedback');
    const $resetBtn = $('#resetPreferencesBtn');
    const $highPrioritySoundToggle = $('#settingHighPrioritySound');
    const $mediumPrioritySoundToggle = $('#settingMediumPrioritySound');
    const $lowPrioritySoundToggle = $('#settingLowPrioritySound'); // Fixed typo here
    const $customSoundUrlInput = $('#settingCustomSoundUrl');
    const $testSoundBtn = $('#testSoundBtn');
    const $testDesktopBtn = $('#testDesktopBtn');
    const config = window.SBMSConfig || {};
    const appBaseUrl = config.appBaseUrl || window.appBaseUrl || '';
    const appIconUrl = config.appIconUrl || `${appBaseUrl}/assets/img/logo.svg`;
    const soundPlayer = document.getElementById('notificationSound');
    const hasNotificationApi = 'Notification' in window;

    // Load initial states
    const isMuted = localStorage.getItem('sbms_muted') === 'true';
    const isDesktopEnabled = localStorage.getItem('sbms_desktop_enabled') !== 'false'; // Default to true
    const soundHighPriority = localStorage.getItem('sbms_sound_priority_high') !== 'false'; // Default to true
    const soundMediumPriority = localStorage.getItem('sbms_sound_priority_medium') !== 'false'; // Default to true
    const soundLowPriority = localStorage.getItem('sbms_sound_priority_low') !== 'false'; // Default to true 
    const customSoundUrl = localStorage.getItem('sbms_custom_sound_url') || '';

    $muteToggle.prop('checked', !isMuted); // Toggle is "Enable Audio", so reverse of muted
    $desktopToggle.prop('checked', isDesktopEnabled);
    $highPrioritySoundToggle.prop('checked', soundHighPriority);
    $mediumPrioritySoundToggle.prop('checked', soundMediumPriority);
    $lowPrioritySoundToggle.prop('checked', soundLowPriority);
    $customSoundUrlInput.val(customSoundUrl);

    $muteToggle.on('change', function () {
        const enabled = $(this).is(':checked');
        localStorage.setItem('sbms_muted', !enabled);
        showSuccess('Audio settings updated.');
    });

    $desktopToggle.on('change', function () {
        const enabled = $(this).is(':checked');
        if (enabled && !hasNotificationApi) {
            $(this).prop('checked', false);
            localStorage.setItem('sbms_desktop_enabled', false);
            showError('Your browser does not support desktop notifications.');
            return;
        }

        localStorage.setItem('sbms_desktop_enabled', enabled);
        
        if (enabled && Notification.permission === 'default') {
            // Request permission if enabling desktop notifications and permission is default
            Notification.requestPermission().then(permission => {
                if (permission !== 'granted') {
                    $(this).prop('checked', false);
                    localStorage.setItem('sbms_desktop_enabled', false);
                    alert('Browser notification permission was denied.');
                }
            });
        }
        showSuccess('Desktop notification settings updated.');
    });

    $highPrioritySoundToggle.on('change', function () {
        localStorage.setItem('sbms_sound_priority_high', $(this).is(':checked'));
        showSuccess('High priority sound settings updated.');
    });

    $mediumPrioritySoundToggle.on('change', function () {
        localStorage.setItem('sbms_sound_priority_medium', $(this).is(':checked'));
        showSuccess('Medium priority sound settings updated.');
    });

    $lowPrioritySoundToggle.on('change', function () {
        localStorage.setItem('sbms_sound_priority_low', $(this).is(':checked'));
        showSuccess('Low priority sound settings updated.');
    });

    $customSoundUrlInput.on('change', function () {
        localStorage.setItem('sbms_custom_sound_url', $(this).val());
        showSuccess('Custom sound URL updated.');
    });

    $resetBtn.on('click', function () {
        if (confirm('Are you sure you want to reset all notification preferences to defaults?')) {
            localStorage.removeItem('sbms_muted');
            localStorage.removeItem('sbms_desktop_enabled');
            localStorage.removeItem('sbms_sound_priority_high');
            localStorage.removeItem('sbms_sound_priority_medium');
            localStorage.removeItem('sbms_sound_priority_low');
            localStorage.removeItem('sbms_custom_sound_url');
            
            // Apply defaults to UI
            $muteToggle.prop('checked', true); // Default: Audio enabled (muted = false)
            $desktopToggle.prop('checked', true); // Default: Desktop enabled
            $highPrioritySoundToggle.prop('checked', true);
            $mediumPrioritySoundToggle.prop('checked', true);
            $lowPrioritySoundToggle.prop('checked', true);
            $customSoundUrlInput.val('');
            showSuccess('All preferences reset to defaults.');
        }
    });

    // Test Sound Button
    $testSoundBtn.on('click', function () {
        const currentIsMuted = localStorage.getItem('sbms_muted') === 'true';
        if (currentIsMuted) {
            showError('Audio notifications are currently muted.');
            return;
        }

        // For testing, we'll use 'medium' priority as a default
        const playSoundForPriority = localStorage.getItem('sbms_sound_priority_medium') !== 'false';
        if (!playSoundForPriority) {
            showError('Sound for Medium priority notifications is disabled.');
            return;
        }

        playNotificationPreview();
    });

    // Test Desktop Notification Button
    $testDesktopBtn.on('click', function () {
        const desktopEnabled = localStorage.getItem('sbms_desktop_enabled') !== 'false';

        if (!desktopEnabled) {
            showError('Desktop notifications are currently disabled.');
            return;
        }

        if (!hasNotificationApi) {
            showError('Your browser does not support desktop notifications.');
            return;
        }

        if (Notification.permission === 'granted') {
            new Notification('SBMS Test Notification', {
                body: 'This is a test desktop notification from SBMS.',
                icon: appIconUrl
            });
            showSuccess('Test desktop notification sent.');
        } else if (Notification.permission === 'denied') {
            showError('Desktop notification permission denied. Please enable it in browser settings.');
        } else { // 'default'
            Notification.requestPermission().then(permission => {
                if (permission === 'granted') {
                    new Notification('SBMS Test Notification', {
                        body: 'This is a test desktop notification from SBMS.',
                        icon: appIconUrl
                    });
                    showSuccess('Test desktop notification sent.');
                } else {
                    showError('Permission not granted for desktop notifications.');
                }
            });
        }
    });

    // Test Custom Sound Button
    $('#testCustomSoundBtn').on('click', function() {
        const customSound = localStorage.getItem('sbms_custom_sound_url');
        if (!customSound) {
            showError('No custom sound URL provided.');
            return;
        }

        playNotificationPreview(customSound)
            .then(() => showSuccess('Test custom sound played.'))
            .catch(e => {
                console.error('Error playing custom test sound:', e);
                showError('Could not play custom test sound. Check URL and browser autoplay settings.');
            });
    });

    function playNotificationPreview(customSoundUrl = '') {
        const sourceUrl = customSoundUrl || localStorage.getItem('sbms_custom_sound_url') || '';
        const defaultSrc = soundPlayer ? (soundPlayer.getAttribute('src') || '') : '';

        if (soundPlayer && (sourceUrl || defaultSrc)) {
            const originalSrc = soundPlayer.getAttribute('src') || '';
            if (sourceUrl) {
                soundPlayer.src = sourceUrl;
            }

            const playback = soundPlayer.play();
            if (playback && typeof playback.then === 'function') {
                return playback.then(() => {
                    if (sourceUrl) {
                        soundPlayer.src = originalSrc;
                    }
                }).catch(error => {
                    if (sourceUrl) {
                        soundPlayer.src = originalSrc;
                    }
                    return playFallbackBeep().catch(() => Promise.reject(error));
                });
            }

            if (sourceUrl) {
                soundPlayer.src = originalSrc;
            }
            return Promise.resolve();
        }

        return playFallbackBeep();
    }

    function playFallbackBeep() {
        const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextCtor) {
            return Promise.reject(new Error('Web Audio API is not available.'));
        }

        try {
            const context = new AudioContextCtor();
            const oscillator = context.createOscillator();
            const gainNode = context.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = 880;
            gainNode.gain.value = 0.04;

            oscillator.connect(gainNode);
            gainNode.connect(context.destination);

            oscillator.start();
            oscillator.stop(context.currentTime + 0.16);

            oscillator.onended = function () {
                if (typeof context.close === 'function') {
                    context.close().catch(() => {});
                }
            };

            return Promise.resolve();
        } catch (error) {
            return Promise.reject(error);
        }
    }

    function showSuccess(msg) {
        $feedback.text(msg).css('color', '#34d399').show();
        setTimeout(() => $feedback.fadeOut(), 2000);
    }

    function showError(msg) {
        $feedback.text(msg).css('color', '#fb7185').show();
        setTimeout(() => $feedback.fadeOut(), 3000);
    }
});
