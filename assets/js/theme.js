// Theme management script
(function() {
    function getInitialTheme() {
        const savedTheme = localStorage.getItem('sbms-theme');
        if (savedTheme) {
            return savedTheme;
        }
        // Fallback to light mode as requested by user
        return 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('sbms-theme', theme);
    }

    // Apply initial theme immediately to prevent FOUC
    const currentTheme = getInitialTheme();
    applyTheme(currentTheme);

    // Global toggle function
    window.toggleTheme = function() {
        const current = document.documentElement.getAttribute('data-theme');
        const newTheme = current === 'dark' ? 'light' : 'dark';
        applyTheme(newTheme);
        
        // Dispatch event for other components if needed
        window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: newTheme } }));
    };
})();
