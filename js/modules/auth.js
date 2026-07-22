import { state, dom } from './state.js';
import { showToast, openModal, closeModal } from './ui.js';
import { loadView, loadSubnetsSidebar } from './navigation.js';

export async function checkAuth() {
    try {
        const res = await fetch('api.php?action=check_auth');
        const data = await res.json();
        if (data.logged_in) {
            state.isAuthed = true;
            state.csrfToken = data.csrf_token;
            state.userRole = data.role || 'admin';

            const hostLabel = document.querySelector('.active-username');
            if (hostLabel) hostLabel.textContent = data.username || 'admin';

            const changePassBtn = document.getElementById('btn-change-password-modal');
            if (changePassBtn) changePassBtn.style.display = (state.userRole === 'readonly') ? 'none' : 'block';

            const settingsSidebarLink = document.querySelector('.sidebar .nav-link[data-view="settings"]');
            if (settingsSidebarLink) settingsSidebarLink.style.display = (state.userRole === 'readonly') ? 'none' : 'block';

            if(dom.authSection) dom.authSection.style.display = 'none';
            if(dom.mainSection) dom.mainSection.style.display = 'flex';
            
            loadView('dashboard');
            loadSubnetsSidebar();
            
            try {
                const sRes = await fetch('api.php?action=get_settings');
                const sData = await sRes.json();
                if (sData.status === 'success' && sData.settings) {
                    const siteTitle = sData.settings.site_title || 'IProof';
                    const brandLogo = document.querySelector('.brand-logo');
                    if (brandLogo) brandLogo.innerHTML = `<span style="color: #ee0000; font-weight: 300; margin-right: 6px;">︿</span>${siteTitle}`;
                    document.title = siteTitle;
                }
            } catch (e) {}
        } else {
            state.isAuthed = false;
            state.csrfToken = '';
            if(dom.authSection) dom.authSection.style.display = 'flex';
            if(dom.mainSection) dom.mainSection.style.display = 'none';
        }
    } catch (err) {
        showToast('Connection error', 'error');
        if(dom.authSection) dom.authSection.style.display = 'flex';
        if(dom.mainSection) dom.mainSection.style.display = 'none';
    } finally {
        const loader = document.getElementById('app-loader');
        if (loader) loader.style.display = 'none';
    }
}

export function setupAuthListeners() {
    const loginForm = document.getElementById('login-form');
    if(loginForm) {
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const usernameInput = document.getElementById('username-input');
            const passwordInput = document.getElementById('password-input');
            const username = usernameInput ? usernameInput.value.trim() : 'admin';
            const password = passwordInput ? passwordInput.value : '';

            try {
                const res = await fetch('api.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });
                
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseErr) {
                    console.error('Login response was not valid JSON:', text);
                    showToast('Server Error: Invalid response format', 'error');
                    return;
                }

                if (data.status === 'success') {
                    if (passwordInput) passwordInput.value = '';
                    state.csrfToken = data.csrf_token;
                    state.userRole = data.role || 'admin';
                    showToast(data.message, 'success');
                    checkAuth();
                } else {
                    showToast(data.message || 'Incorrect password', 'error');
                }
            } catch (err) {
                console.error('Login error:', err);
                showToast(err.message || 'Login failed', 'error');
            }
        });
    }

    const runLogout = async () => {
        try {
            const res = await fetch('api.php?action=logout');
            const data = await res.json();
            if (data.status === 'success') {
                state.csrfToken = '';
                showToast(data.message, 'info');
                checkAuth();
            }
        } catch (err) {
            showToast('Logout failed', 'error');
        }
    };

    if (dom.logoutBtn) dom.logoutBtn.addEventListener('click', runLogout);
    
    const headerLogout = document.getElementById('btn-logout-header');
    if (headerLogout) {
        headerLogout.addEventListener('click', (e) => {
            e.preventDefault();
            runLogout();
        });
    }

    const passForm = document.getElementById('modal-password-form');
    if (passForm) {
        passForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const old_password = document.getElementById('old-pass-modal').value;
            const new_password = document.getElementById('new-pass-modal').value;
            
            try {
                const res = await fetch('api.php?action=change_password', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ old_password, new_password })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    closeModal(dom.passwordModal);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Password update failed', 'error');
            }
        });
    }
}
