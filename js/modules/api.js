import { state, dom } from './state.js';
import { showToast } from './ui.js';

export function setupApiInterceptor() {
    const originalFetch = window.fetch;
    window.fetch = async function (url, options = {}) {
        if (!options.headers) options.headers = {};
        if (state.csrfToken) {
            options.headers['X-CSRF-Token'] = state.csrfToken;
        }
        const res = await originalFetch(url, options);
        if (res.status === 401 || res.status === 403) {
            state.isAuthed = false;
            state.csrfToken = '';
            if (dom.authSection) dom.authSection.style.display = 'flex';
            if (dom.mainSection) dom.mainSection.style.display = 'none';
            showToast('Session expired or unauthorized request', 'error');
            throw new Error('Unauthorized');
        }
        return res;
    };
}
