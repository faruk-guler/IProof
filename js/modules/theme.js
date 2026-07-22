export function initTheme() {
    const savedTheme = localStorage.getItem('iproof_theme') || 'light';
    applyTheme(savedTheme);

    const toggleBtn = document.getElementById('theme-toggle-btn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            applyTheme(newTheme);
            localStorage.setItem('iproof_theme', newTheme);
        });
    }
}

export function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    const toggleBtn = document.getElementById('theme-toggle-btn');
    if (toggleBtn) {
        const icon = toggleBtn.querySelector('i');
        if (icon) {
            if (theme === 'dark') {
                icon.className = 'fa fa-sun';
                toggleBtn.title = 'Switch to Light Mode';
            } else {
                icon.className = 'fa fa-moon';
                toggleBtn.title = 'Switch to Dark Mode';
            }
        }
    }
}
