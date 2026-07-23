import { state, dom } from './state.js';
import { escapeHTML, renderTagBadge, renderSubnetTagBadges } from './utils.js';
import { showToast } from './ui.js';
import { openSubnetModal } from './modals.js';
import { loadView, loadSubnetsSidebar } from './navigation.js';

export async function deleteSubnet(id) {
    try {
        const res = await fetch('api.php?action=delete_subnet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(data.message, 'success');
            loadSubnetsSidebar();
            if (state.currentView === 'dashboard') {
                renderDashboard();
            } else if (state.currentView === 'external-ips') {
                loadView('external-ips');
            } else if (state.currentView === 'subnet' && state.activeSubnetId == id) {
                loadView('dashboard');
            }
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Deletion failed', 'error');
    }
}

export async function renderDashboard() {
    try {
        const res = await fetch('api.php?action=get_stats');
        const data = await res.json();
        const isReadOnly = state.userRole === 'readonly';
        
        if(!dom.contentArea) return;
        
        dom.contentArea.innerHTML = `
            <div class="animate-fade-in">
                <div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 24px;">
                    <div>
                        <h2 class="content-title">Overview</h2>
                        <p class="content-subtitle" style="margin-top: 4px;">IPAM system status summary</p>
                    </div>
                    ${isReadOnly ? '' : '<div><button class="btn" id="btn-new-subnet-dash"><i class="fa fa-plus"></i> Add New Subnet</button></div>'}
                </div>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-blue"><i class="fa fa-sitemap"></i></div>
                        <div class="stat-info"><span class="stat-value">${data.subnets_count || 0}</span><span class="stat-label">Subnets</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-green"><i class="fa fa-check"></i></div>
                        <div class="stat-info"><span class="stat-value">${data.ips_active || 0}</span><span class="stat-label">Active IPs</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-red"><i class="fa fa-times"></i></div>
                        <div class="stat-info"><span class="stat-value">${data.ips_offline || 0}</span><span class="stat-label">Offline</span></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon stat-icon-amber"><i class="fa fa-thumbtack"></i></div>
                        <div class="stat-info"><span class="stat-value">${data.ips_reserved || 0}</span><span class="stat-label">Reserved IPs</span></div>
                    </div>
                </div>
                <div class="dashboard-row">
                    <div class="panel">
                        <div class="panel-header"><h3 class="panel-title">Subnets</h3></div>
                        <div class="table-container">
                            <table class="custom-table" id="dash-subnets-table">
                                <thead>
                                    <tr>
                                        <th>Subnet</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Tag</th>
                                        <th>VRF</th>
                                        <th>Status (Usage)</th>
                                        ${isReadOnly ? '' : '<th>Actions</th>'}
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="panel-header"><h3 class="panel-title">Recent Scans</h3></div>
                        <div class="table-container">
                            <table class="custom-table" id="dash-recent-ips">
                                <thead>
                                    <tr>
                                        <th>IP Address</th>
                                        <th>Subnet</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        `;

        const btnNewSubnet = document.getElementById('btn-new-subnet-dash');
        if (btnNewSubnet) btnNewSubnet.addEventListener('click', () => openSubnetModal());

        const tbodySubnets = document.querySelector('#dash-subnets-table tbody');
        if (state.subnets.length === 0) {
            tbodySubnets.innerHTML = `<tr><td colspan="${isReadOnly ? 6 : 7}" style="text-align:center;color:var(--text-muted);">No registered subnets found.</td></tr>`;
        } else {
            state.subnets.forEach(s => {
                const totalHosts = s.mask >= 31 ? Math.pow(2, 32 - s.mask) : Math.pow(2, 32 - s.mask) - 2;
                const fillPercent = Math.min(100, Math.round((s.total_ips / totalHosts) * 100));
                
                const typeBadge = s.is_private ? '<span style="font-size:0.8rem;padding:3px 10px;border-radius:3px;background:var(--bg-main);border:1px solid var(--border-color);color:var(--text-primary);font-weight:600;white-space:nowrap;display:inline-block;">Internal</span>' : '<span style="font-size:0.8rem;padding:3px 10px;border-radius:3px;background:var(--bg-main);border:1px solid var(--border-color);color:var(--text-primary);font-weight:600;white-space:nowrap;display:inline-block;">External</span>';
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:600;white-space:nowrap;"><a href="#" class="subnet-link" data-id="${s.id}">${escapeHTML(s.name)} (${escapeHTML(s.subnet)}/${escapeHTML(s.mask)})</a></td>
                    <td style="white-space:nowrap;">${typeBadge}</td>
                    <td>${escapeHTML(s.description) || '-'}</td>
                    <td>${renderSubnetTagBadges(s)}</td>
                    <td>${escapeHTML(s.vrf) || '-'}</td>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="flex-grow:1;background:var(--border-color);height:6px;border-radius:3px;overflow:hidden;min-width:60px;">
                                <div style="width:${fillPercent}%;background:var(--color-primary);height:100%;"></div>
                            </div>
                            <span style="font-size:0.8rem;color:var(--text-secondary);white-space:nowrap;">${fillPercent}% (${s.total_ips}/${totalHosts})</span>
                        </div>
                    </td>
                    ${isReadOnly ? '' : `
                    <td>
                        <button class="action-btn edit-subnet-btn" data-id="${s.id}"><i class="fa fa-edit"></i></button>
                        <button class="action-btn action-btn-danger delete-subnet-btn" data-id="${s.id}"><i class="fa fa-trash"></i></button>
                    </td>`}
                `;
                
                tr.querySelector('.subnet-link').addEventListener('click', (e) => {
                    e.preventDefault();
                    loadView('subnet', { id: s.id });
                });
                
                const editBtn = tr.querySelector('.edit-subnet-btn');
                if (editBtn) editBtn.addEventListener('click', () => openSubnetModal(s));
                
                const deleteBtn = tr.querySelector('.delete-subnet-btn');
                if (deleteBtn) deleteBtn.addEventListener('click', () => {
                    if (confirm(`"${escapeHTML(s.name)}" subnet and all its IPs. Are you sure?`)) {
                        deleteSubnet(s.id);
                    }
                });
                
                tbodySubnets.appendChild(tr);
            });
        }

        const tbodyRecent = document.querySelector('#dash-recent-ips tbody');
        if (!data.recent_ips || data.recent_ips.length === 0) {
            tbodyRecent.innerHTML = `<tr><td colspan="3" style="text-align:center;color:var(--text-muted);">No recently scanned IPs found.</td></tr>`;
        } else {
            data.recent_ips.forEach(ip => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:600;"><span class="badge badge-${ip.status}">${escapeHTML(ip.ip)}</span></td>
                    <td>${escapeHTML(ip.subnet_name)}</td>
                    <td style="font-size:0.8rem;color:var(--text-secondary);">${escapeHTML(ip.last_seen)}</td>
                `;
                tbodyRecent.appendChild(tr);
            });
        }
    } catch (err) {
        showToast('Failed to load overview data', 'error');
    }
}
