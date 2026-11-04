/**
 * SBMS AI Assistant Logic
 */

document.addEventListener('DOMContentLoaded', () => {
    // Determine the base URL for API requests. Usually SBMSDashboardConfig is available, but if not we fallback.
    const baseUrl = window.appBaseUrl || (window.SBMSDashboardConfig ? window.SBMSDashboardConfig.metricsUrl.replace('/dashboard/metrics.php', '') : '');
    const apiUrl = `${baseUrl}/api/assistant.php`;

    // Elements
    const btn = document.getElementById('ai-assistant-btn');
    const container = document.getElementById('ai-assistant-container');
    const messagesEl = document.getElementById('ai-assistant-messages');
    const inputEl = document.getElementById('ai-assistant-input');
    const sendBtn = document.getElementById('ai-assistant-send');

    if (!btn || !container || !messagesEl || !inputEl || !sendBtn) {
        console.warn('AI Assistant elements not found.');
        return;
    }

    let isOpen = false;
    let isWaiting = false;

    // Toggle Chat
    btn.addEventListener('click', () => {
        isOpen = !isOpen;
        if (isOpen) {
            btn.classList.add('active');
            container.classList.add('active');
            // Focus input after transition
            setTimeout(() => inputEl.focus(), 300);
            
            // Send welcome message if empty
            if (messagesEl.children.length === 0) {
                appendMessage('ai', 'Hello! I am your SBMS AI Assistant. How can I help you manage student behavior today?');
            }
        } else {
            btn.classList.remove('active');
            container.classList.remove('active');
        }
    });

    // Send Message
    const sendMessage = async () => {
        const text = inputEl.value.trim();
        if (!text || isWaiting) return;

        // Add user message
        appendMessage('user', text);
        inputEl.value = '';
        inputEl.disabled = true;
        sendBtn.disabled = true;
        isWaiting = true;

        // Show typing indicator
        const typingId = showTypingIndicator();

        try {
            // Include CSRF if available from a meta tag or standard hidden input
            let csrfToken = '';
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) csrfToken = csrfMeta.getAttribute('content');
            const currentContext = {
                pageTitle: document.title || '',
                pagePath: window.location.pathname || '',
                pageUrl: window.location.href || ''
            };
            
            const response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ message: text, context: currentContext })
            });

            const data = await response.json();
            
            removeTypingIndicator(typingId);

            if (response.ok && data.reply) {
                appendMessage('ai', data.reply);
            } else {
                appendMessage('ai', data.error || 'Sorry, I encountered an error. Please try again.');
            }
        } catch (error) {
            console.error('AI Assistant Error:', error);
            removeTypingIndicator(typingId);
            appendMessage('ai', 'Network error. Please ensure you are connected and try again.');
        } finally {
            inputEl.disabled = false;
            sendBtn.disabled = false;
            isWaiting = false;
            inputEl.focus();
        }
    };

    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });

    // UI Helpers
    function appendMessage(sender, text) {
        const wrapper = document.createElement('div');
        wrapper.className = `ai-message-wrapper ${sender}`;
        
        const msg = document.createElement('div');
        msg.className = 'ai-message';
        // Basic HTML escaping
        const escapedText = text.replace(/</g, "&lt;").replace(/>/g, "&gt;");
        msg.innerHTML = escapedText.replace(/\n/g, '<br>');
        
        wrapper.appendChild(msg);
        messagesEl.appendChild(wrapper);
        scrollToBottom();
    }

    function showTypingIndicator() {
        const id = 'typing-' + Date.now();
        const indicator = document.createElement('div');
        indicator.className = 'ai-typing-indicator';
        indicator.id = id;
        indicator.innerHTML = `
            <div class="ai-typing-dot"></div>
            <div class="ai-typing-dot"></div>
            <div class="ai-typing-dot"></div>
        `;
        messagesEl.appendChild(indicator);
        scrollToBottom();
        return id;
    }

    function removeTypingIndicator(id) {
        const indicator = document.getElementById(id);
        if (indicator) {
            indicator.remove();
        }
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }
});
