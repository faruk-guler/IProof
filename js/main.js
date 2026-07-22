import { setupApiInterceptor } from './modules/api.js';
import { checkAuth, setupAuthListeners } from './modules/auth.js';
import { setupNavigationListeners, loadView } from './modules/navigation.js';
import { setupModals } from './modules/modals.js';
import { initTheme } from './modules/theme.js';
import { dom, state } from './modules/state.js';

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    setupApiInterceptor();
    setupAuthListeners();
    setupNavigationListeners();
    setupModals();

    let searchTimeout = null;
    if(dom.searchInput) {
        dom.searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            const query = dom.searchInput.value.trim();
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => {
                    if(dom.sidebarNav) {
                        dom.sidebarNav.forEach(l => l.classList.remove('active'));
                    }
                    loadView('search', { query });
                }, 350);
            } else {
                if (state.currentView === 'search') {
                    loadView('dashboard');
                    if(dom.sidebarNav) {
                        dom.sidebarNav.forEach(l => l.classList.remove('active'));
                        const overviewLink = document.querySelector('.sidebar .nav-link[data-view="dashboard"]');
                        if (overviewLink) overviewLink.classList.add('active');
                    }
                }
            }
        });
    }

    checkAuth();
});
