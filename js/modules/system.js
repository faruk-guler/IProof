import { state, dom } from './state.js';
import { escapeHTML, renderTagBadge } from './utils.js';
import { showToast } from './ui.js';
import { fetchTags, loadSubnetsSidebar, loadView } from './navigation.js';
import { openTagModal } from './modals.js';

export async function renderTags() {
    await fetchTags();
    
    let rowsHtml = '';
    if (state.tagsData.length === 0) {
        rowsHtml = '<tr><td colspan="4" style="text-align:center;padding:30px;">No Tags found.</td></tr>';
    } else {
        state.tagsData.forEach(t => {
            let subnetsHtml = '<span style="color:var(--text-muted);font-size:0.85rem;">None</span>';
            if (t.subnets && t.subnets.length > 0) {
                subnetsHtml = t.subnets.map(s => `
                    <a href="#" class="nav-to-subnet-link" data-id="${s.id}" style="display:inline-flex;align-items:center;gap:4px;background:var(--bg-panel);padding:3px 8px;border-radius:4px;border:1px solid var(--border-color);color:var(--text-primary);text-decoration:none;font-size:0.8rem;margin:2px;" title="Click to view ${escapeHTML(s.name)}">
                        <i class="fa fa-sitemap" style="color:var(--color-primary);"></i> <strong>${escapeHTML(s.name)}</strong> <span style="opacity:0.7;">(${escapeHTML(s.subnet)}/${escapeHTML(s.mask)})</span>
                    </a>
                `).join(' ');
            }

            rowsHtml += `
            <tr>
                <td><span class="tag-badge-clickable" data-tag-name="${escapeHTML(t.name)}" style="cursor:pointer;font-weight:600;color:var(--color-primary);" title="Click to view subnets with this tag">${renderTagBadge(t.name, t.color)}</span></td>
                <td>${subnetsHtml}</td>
                <td>${escapeHTML(t.description || '-')}</td>
                <td>
                    ${state.userRole === 'admin' ? `
                    <button class="action-btn btn-edit-tag" data-id="${t.id}"><i class="fa fa-edit"></i> Edit</button>
                    <button class="action-btn btn-delete-tag" data-id="${t.id}" style="color:var(--color-offline);"><i class="fa fa-trash"></i></button>
                    ` : '-'}
                </td>
            </tr>
            `;
        });
    }

    dom.contentArea.innerHTML = `
    <div class="animate-fade-in">
        <div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 24px;">
            <div>
                <h2 class="content-title">Tag Management</h2>
                <p class="content-subtitle" style="margin-top: 4px;">Organize subnets and network resources using custom tags</p>
            </div>
            ${state.userRole === 'admin' ? `<div><button class="btn" id="btn-add-tag"><i class="fa fa-plus"></i> Add Tag</button></div>` : ''}
        </div>
        <div class="ct-table-card">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th style="width: 160px;">Tag Name</th>
                        <th>Tagged Networks</th>
                        <th>Description</th>
                        <th style="width: 110px;">Actions</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        </div>
    </div>`;

    document.querySelectorAll('.nav-to-subnet-link').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const id = link.getAttribute('data-id');
            if (id) loadView('subnet', { id });
        });
    });

    if (state.userRole === 'admin') {
        const btnAddTag = document.getElementById('btn-add-tag');
        if(btnAddTag) btnAddTag.addEventListener('click', () => openTagModal());
    }
    
    document.querySelectorAll('.btn-edit-tag').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-id');
            const t = state.tagsData.find(x => x.id == id);
            if (t) openTagModal(t);
        });
    });
    document.querySelectorAll('.btn-delete-tag').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.getAttribute('data-id');
            if (confirm('Delete this Tag? Subnets will lose their Tag assignment.')) {
                try {
                    const res = await fetch('api.php?action=delete_tag', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id})
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        renderTags();
                        loadSubnetsSidebar();
                    } else showToast(data.message, 'error');
                } catch(e) {
                    showToast('Operation failed', 'error');
                }
            }
        });
    });
}

export async function renderAbout() {
    try {
        const res = await fetch('api.php?action=get_system_info');
        const data = await res.json();
        
        if (data.status !== 'success') {
            showToast('System information could not be loaded', 'error');
            return;
        }

        const dbSizeKB = Math.round((parseInt(data.db_size) || 0) / 1024);
        const isReadOnly = state.userRole === 'readonly';
        
        let warningHtml = '';
        if (data.exec_enabled === false || data.proc_open_enabled === false) {
            warningHtml = `
                <div style="background-color: var(--color-offline-bg); border: 1px solid var(--color-offline-border); color: var(--color-offline); padding: 14px 20px; border-radius: 4px; margin-bottom: 24px; display: flex; align-items: center; gap: 15px; font-size: 0.95rem; line-height: 1.4;">
                    <i class="fa fa-exclamation-triangle" style="font-size: 1.4rem; color: var(--color-offline);"></i>
                    <div>
                        <strong>System Restriction Detected:</strong> Automatic subnet scanning and ping discovery are disabled on this host because PHP's <code>exec()</code> or <code>proc_open()</code> functions are restricted (disabled) in your <code>php.ini</code> configuration.
                    </div>
                </div>
            `;
        }
        
        dom.contentArea.innerHTML = `
            <div class="animate-fade-in" style="max-width: 1200px;">
                <div class="content-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 24px;">
                    <div>
                        <h2 class="content-title">About IProof</h2>
                        <p class="content-subtitle" style="margin-top: 4px;">System details, network parameters and database diagnostics</p>
                    </div>
                </div>

                ${warningHtml}

                <div class="dashboard-row" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 30px;">
                    <div class="panel" style="display: flex; flex-direction: column; height: 100%;">
                        <div class="panel-header"><h3 class="panel-title">About IProof</h3></div>
                        <div style="display:flex;flex-direction:column;gap:14px;padding:15px 0 0 0;flex-grow:1;">
                            <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                <span style="color:var(--text-secondary);font-size:0.95rem;">Application Name</span><span style="font-weight:600;font-size:0.95rem;"><span style="color: #ee0000; font-weight: 300; margin-right: 4px;">︿</span>IProof IPAM</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                <span style="color:var(--text-secondary);font-size:0.95rem;">Software Version</span><span style="font-weight:600;font-size:0.95rem;">v1.2.0 (Stable)</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                <span style="color:var(--text-secondary);font-size:0.95rem;">PHP Version</span><span style="font-weight:600;font-size:0.95rem;font-family:'Red Hat Mono', monospace;">${escapeHTML(data.php_version)}</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                <span style="color:var(--text-secondary);font-size:0.95rem;">SQLite Version</span><span style="font-weight:600;font-size:0.95rem;font-family:'Red Hat Mono', monospace;">${escapeHTML(data.sqlite_version)}</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                <span style="color:var(--text-secondary);font-size:0.95rem;">Author</span><span style="font-weight:600;font-size:0.95rem;">faruk-guler</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                <span style="color:var(--text-secondary);font-size:0.95rem;">Website</span><span style="font-weight:600;font-size:0.95rem;"><a href="https://www.farukguler.com" target="_blank" style="color:var(--color-primary);text-decoration:none;"><i class="fa fa-globe"></i> www.farukguler.com</a></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding-bottom:0px;">
                                <span style="color:var(--text-secondary);font-size:0.95rem;">GitHub</span><span style="font-weight:600;font-size:0.95rem;"><a href="https://github.com/faruk-guler" target="_blank" style="color:var(--color-primary);text-decoration:none;"><i class="fab fa-github"></i> github.com/faruk-guler</a></span>
                            </div>
                        </div>
                    </div>

                    <div class="panel" style="display: flex; flex-direction: column; height: 100%;">
                        <div class="panel-header"><h3 class="panel-title">Local Network Info</h3></div>
                        <div style="display:flex;flex-direction:column;gap:14px;padding:15px 0 0 0;flex-grow:1;">
                            <div id="settings-local-body">
                                <div style="text-align:center;color:var(--text-muted);padding:10px;"><i class="fa fa-spinner fa-spin"></i> Loading local IP...</div>
                            </div>
                        </div>
                    </div>

                    ${isReadOnly ? '' : `
                    <div class="panel" style="display: flex; flex-direction: column; height: 100%;">
                        <div class="panel-header"><h3 class="panel-title">Database Management</h3></div>
                        <div style="display:flex;flex-direction:column;gap:20px;padding:15px 0 0 0;flex-grow:1;justify-content:space-between;">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <div><span style="color:var(--text-primary);font-weight:600;font-size:0.95rem;display:block;">Database Size</span><span style="color:var(--text-muted);font-size:0.85rem;">db.sqlite file size</span></div>
                                <span style="font-weight:600;font-size:1.1rem;font-family:'Red Hat Mono', monospace;">${dbSizeKB} KB</span>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:12px;margin-top:20px;">
                                <a href="api.php?action=backup_db&csrf_token=${state.csrfToken}" class="btn btn-secondary" style="justify-content:flex-start;width:100%;"><i class="fa fa-download"></i> Back Up Database (.sqlite)</a>
                                <button class="btn btn-danger" id="btn-reset-db" style="justify-content:flex-start;width:100%;"><i class="fa fa-trash-alt"></i> Reset Database (Erase All Data)</button>
                            </div>
                        </div>
                    </div>
                    `}
                </div>
            </div>
        `;
        
        try {
            const connRes = await fetch('api.php?action=get_my_ip_info');
            const connData = await connRes.json();
            if (connData.status === 'success') {
                document.getElementById('settings-local-body').innerHTML = `
                    <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;align-items:center;">
                        <span style="color:var(--text-secondary);font-size:0.95rem;">Your Local IP</span><span style="font-weight:600;font-family:monospace;color:var(--text-primary);font-size:0.95rem;">${escapeHTML(connData.ip || connData.client_ip || 'Unknown')}</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;padding-bottom:0px;align-items:center;">
                        <span style="color:var(--text-secondary);font-size:0.95rem;">Hostname</span><span style="font-weight:600;font-size:0.9rem;text-align:right;">${escapeHTML(connData.hostname || '-')}</span>
                    </div>
                `;
            } else {
                document.getElementById('settings-local-body').innerHTML = `<div style="color:var(--text-muted);text-align:center;padding:10px;">Local details could not be loaded.</div>`;
            }
        } catch (err) {
            document.getElementById('settings-local-body').innerHTML = `<div style="color:var(--text-muted);text-align:center;padding:10px;">Local details could not be loaded.</div>`;
        }

        const resetBtn = document.getElementById('btn-reset-db');
        if (resetBtn) {
            resetBtn.addEventListener('click', async () => {
                if (!confirm('WARNING: All subnets and IP addresses in the database will be permanently deleted! Do you approve this operation?')) return;
                if (!confirm('FINAL WARNING: This operation cannot be undone! Are you absolutely sure you want to reset the database?')) return;
                
                try {
                    const res = await fetch('api.php?action=reset_db', { method: 'POST' });
                    const rData = await res.json();
                    if (rData.status === 'success') {
                        showToast(rData.message, 'success');
                        loadSubnetsSidebar();
                        loadView('dashboard');
                    } else {
                        showToast(rData.message, 'error');
                    }
                } catch (err) {
                    showToast('An error occurred during database reset', 'error');
                }
            });
        }
    } catch (err) {
        showToast('System information could not be loaded', 'error');
    }
}
