import { state, dom } from './state.js';
import { escapeHTML, renderTagBadge, renderSubnetTagBadges } from './utils.js';
import { showToast } from './ui.js';
import { openIpModal, openImportModal, openSnmpModal } from './modals.js';
import { loadView } from './navigation.js';

export async function releaseIp(ip) {
    try {
        const res = await fetch('api.php?action=delete_ip', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: ip.id, subnet_id: ip.subnet_id, ip: ip.ip })
        });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(data.message, 'success');
            renderSubnetDetail(state.activeSubnet.id);
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Operation failed', 'error');
    }
}

function getPortBadgeHtml(portsStr, isGrid = false, isBlockActive = false) {
    if (!portsStr) return isGrid ? '' : '-';
    const title = `Open Ports: ${escapeHTML(portsStr)}`;

    if (isGrid) {
        const bg = isBlockActive ? 'rgba(255,255,255,0.25)' : 'rgba(0,102,204,0.1)';
        const fg = isBlockActive ? '#ffffff' : 'var(--color-primary)';
        return `<span style="font-size:0.75rem;padding:3px 6px;border-radius:3px;background:${bg};color:${fg};display:inline-flex;align-items:center;" title="${title}"><i class="fa fa-plug"></i></span>`;
    } else {
        return `<span title="${title}" style="display:inline-flex;align-items:center;font-size:0.95rem;color:var(--color-primary);"><i class="fa fa-plug"></i></span>`;
    }
}

export function renderIpsList() {
    const container = document.getElementById('subnet-ips-container');
    if (!container) return;

    container.innerHTML = '';

    if (state.activeIps.length === 0) {
        container.innerHTML = `<div style="text-align:center;padding:40px;color:var(--text-muted);">No IPs defined in this subnet yet.</div>`;
        return;
    }

    const isReadOnly = state.userRole === 'readonly';

    if (state.isGridView) {
        const grid = document.createElement('div');
        grid.className = 'ip-grid';

        state.activeIps.forEach(ip => {
            const block = document.createElement('div');
            block.className = `ip-block ip-block-${ip.status}`;

            let tooltip = `IP: ${ip.ip}\nStatus: ${ip.status.toUpperCase()}`;
            if (ip.hostname) tooltip += `\nDevice: ${ip.hostname}`;
            if (ip.mac) tooltip += `\nMAC: ${ip.mac}`;
            if (ip.ports) tooltip += `\nOpen Ports: ${ip.ports}`;
            if (ip.description) tooltip += `\nDescription: ${ip.description}`;
            if (ip.last_seen) tooltip += `\nLast Seen: ${ip.last_seen}`;

            block.title = tooltip;
            block.innerHTML = `
                <div class="ip-block-address">.${escapeHTML(ip.ip.split('.').pop())}</div>
            `;

            block.addEventListener('click', () => openIpModal(ip));
            grid.appendChild(block);
        });
        container.appendChild(grid);
    } else {
        const panel = document.createElement('div');
        panel.className = 'panel';
        panel.innerHTML = `
            <div class="table-container">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Status</th>
                            <th>Hostname</th>
                            <th>MAC Address</th>
                            <th>Open Ports</th>
                            <th>Description</th>
                            <th>Last Seen</th>
                            ${isReadOnly ? '' : '<th>Actions</th>'}
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        `;

        const tbody = panel.querySelector('tbody');
        state.activeIps.forEach(ip => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="font-weight:600;">${escapeHTML(ip.ip)}</td>
                <td><span class="badge badge-${ip.status}">${ip.status === 'free' ? 'FREE' : ip.status}</span></td>
                <td>${escapeHTML(ip.hostname) || '-'}</td>
                <td style="font-family:monospace;">${escapeHTML(ip.mac) || '-'}</td>
                <td>${getPortBadgeHtml(ip.ports, false)}</td>
                <td>${escapeHTML(ip.description) || '-'}</td>
                <td style="font-size:0.85rem;color:var(--text-secondary);">${escapeHTML(ip.last_seen) || '-'}</td>
                ${isReadOnly ? '' : `
                <td style="white-space: nowrap; text-align: right;">
                    <button class="action-btn edit-ip-btn" title="Edit"><i class="fa fa-edit"></i></button>
                    ${ip.status !== 'free' ? `
                        <button class="action-btn action-btn-danger release-ip-btn" title="Release"><i class="fa fa-trash"></i></button>
                        ${state.activeSubnet.is_private ? `<button class="action-btn scan-ports-btn" title="Scan Ports" style="color:var(--color-primary);"><i class="fa fa-plug"></i></button>` : ''}
                    ` : ''}
                    ${state.activeSubnet.is_private ? `<button class="action-btn ping-ip-btn" title="Ping At"><i class="fa fa-terminal"></i></button>` : ''}
                </td>`}
            `;

            const editBtn = tr.querySelector('.edit-ip-btn');
            if (editBtn) editBtn.addEventListener('click', () => openIpModal(ip));

            const releaseBtn = tr.querySelector('.release-ip-btn');
            if (releaseBtn) releaseBtn.addEventListener('click', () => {
                if (confirm(`${escapeHTML(ip.ip)} address to be released. Are you sure?`)) {
                    releaseIp(ip);
                }
            });

            const scanPortsBtn = tr.querySelector('.scan-ports-btn');
            if (scanPortsBtn) scanPortsBtn.addEventListener('click', async () => {
                scanPortsBtn.disabled = true;
                scanPortsBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
                try {
                    const res = await fetch(`api.php?action=scan_ip_ports&ip=${encodeURIComponent(ip.ip)}`);
                    const rData = await res.json();
                    if (rData.status === 'success') {
                        showToast(`Port scan complete for ${ip.ip}. Open ports: ${rData.open_ports.join(', ') || 'None'}`, 'success');
                        renderSubnetDetail(state.activeSubnet.id);
                    } else {
                        showToast(rData.message, 'error');
                    }
                } catch (err) {
                    showToast('An error occurred during port scan', 'error');
                } finally {
                    scanPortsBtn.disabled = false;
                    scanPortsBtn.innerHTML = '<i class="fa fa-plug"></i>';
                }
            });

            const pingBtn = tr.querySelector('.ping-ip-btn');
            if (pingBtn) pingBtn.addEventListener('click', async () => {
                pingBtn.disabled = true;
                pingBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
                try {
                    const res = await fetch(`api.php?action=ping_ip&ip=${encodeURIComponent(ip.ip)}&subnet_id=${state.activeSubnet.id}`);
                    const pingData = await res.json();
                    if (pingData.status === 'success') {
                        if (pingData.online) {
                            showToast(`Host ${escapeHTML(ip.ip)} responded (Active)`, 'success');
                        } else {
                            showToast(`Host ${escapeHTML(ip.ip)} did not respond (Offline)`, 'error');
                        }
                        renderSubnetDetail(state.activeSubnet.id);
                    } else {
                        showToast(pingData.message, 'error');
                    }
                } catch (err) {
                    showToast('Error occurred during ping', 'error');
                } finally {
                    pingBtn.disabled = false;
                    pingBtn.innerHTML = '<i class="fa fa-terminal"></i>';
                }
            });

            tbody.appendChild(tr);
        });
        container.appendChild(panel);
    }
}

export async function renderSubnetDetail(id) {
    try {
        const res = await fetch(`api.php?action=get_ips&subnet_id=${id}`);
        const data = await res.json();

        if (data.status !== 'success') {
            showToast(data.message, 'error');
            loadView('dashboard');
            return;
        }

        state.activeSubnet = data.subnet;
        state.activeIps = data.ips;
        const isReadOnly = state.userRole === 'readonly';

        const rawTotal = state.activeSubnet.mask >= 31 ? Math.pow(2, 32 - state.activeSubnet.mask) : Math.pow(2, 32 - state.activeSubnet.mask) - 2;
        const totalHosts = Math.max(1, rawTotal);
        const activeCount = state.activeIps.filter(ip => ip.status === 'active').length;
        const reservedCount = state.activeIps.filter(ip => ip.status === 'reserved').length;
        const offlineCount = state.activeIps.filter(ip => ip.status === 'offline').length;
        const inUseCount = activeCount + reservedCount + offlineCount;
        const freeCount = Math.max(0, totalHosts - inUseCount);
        const fillPercent = isNaN(inUseCount / totalHosts) ? 0 : Math.min(100, Math.round((inUseCount / totalHosts) * 100));

        const stateSubnet = (Array.isArray(state.subnets)) ? state.subnets.find(s => s.id == state.activeSubnet.id) : null;
        const children = (stateSubnet && stateSubnet.children) ? stateSubnet.children : [];
        let childrenHtml = '';

        if (children.length > 0) {
            childrenHtml = `
            <div style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px; font-weight: 600; font-size: 1.1rem; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Child Subnets (Folders)</h3>
                <div class="ct-table-card">
                    <table class="custom-table">
                        <thead><tr><th>Subnet</th><th>Name</th><th>Tag</th><th>Actions</th></tr></thead>
                        <tbody>
                            ${children.map(c => `
                            <tr>
                                <td><strong>${escapeHTML(c.subnet)}/${escapeHTML(c.mask)}</strong></td>
                                <td>${escapeHTML(c.name)}</td>
                                <td>${renderTagBadge(c.tag_name, c.tag_color)}</td>
                                <td><button class="btn btn-secondary" onclick="document.querySelector('.sidebar .nav-link[data-subnet-id=\'${c.id}\']')?.click()" style="padding: 4px 10px; font-size: 0.8rem;">Open</button></td>
                            </tr>`).join('')}
                        </tbody>
                    </table>
                </div>
            </div>`;
        }

        dom.contentArea.innerHTML = `
            <div class="animate-fade-in">
                ${childrenHtml}
                <div class="content-header" style="flex-wrap: wrap; gap: 20px; align-items: flex-end; border-bottom: 1px solid var(--border-color); padding-bottom: 16px;">
                    <div>
                        <h2 class="content-title" style="display:flex; align-items:center; gap:12px;">
                            ${state.activeSubnet.subnet}/${state.activeSubnet.mask} - ${state.activeSubnet.name}
                            ${state.activeSubnet.is_private ? '<span style="font-size:0.85rem;color:var(--text-secondary);font-weight:500;">(Private)</span>' : '<span style="font-size:0.85rem;color:var(--text-secondary);font-weight:500;">(Public)</span>'}
                        </h2>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;">
                        ${isReadOnly ? '' : '<button class="btn" id="btn-add-ip" style="padding: 10px 16px;"><i class="fa fa-plus"></i> Add IP Manually</button>'}
                        <button class="btn btn-secondary" id="btn-toggle-view" title="Toggle Grid/Table View" style="padding: 10px 14px;"><i class="fa ${state.isGridView ? 'fa-list' : 'fa-th'}"></i></button>
                        <div class="dropdown" style="position:relative;display:inline-block;">
                            <button class="btn btn-secondary" id="btn-diagnostic-toggle" style="padding: 10px 16px;">Diagnostic <i class="fa fa-chevron-down" style="font-size:0.75rem;margin-left:4px;"></i></button>
                            <div class="dropdown-menu" id="diagnostic-dropdown" style="display:none;position:absolute;right:0;top:100%;background-color:var(--bg-panel);border:1px solid var(--border-color);border-radius:3px;box-shadow:0 4px 12px rgba(0,0,0,0.5);z-index:20;min-width:180px;margin-top:4px;overflow:hidden;">
                                ${isReadOnly ? '' : `
                                ${state.activeSubnet.is_private ? `
                                <a href="#" class="dropdown-item" id="btn-scan-subnet" style="display:block;padding:12px 16px;color:var(--text-primary);text-decoration:none;font-size:0.9rem;border-bottom:1px solid var(--border-color);"><i class="fa fa-sync fa-fw"></i> Scan Subnet</a>
                                <a href="#" class="dropdown-item" id="btn-snmp-discover" style="display:block;padding:12px 16px;color:var(--text-primary);text-decoration:none;font-size:0.9rem;border-bottom:1px solid var(--border-color);"><i class="fa fa-bolt fa-fw"></i> SNMP Discovery</a>
                                ` : ''}
                                <a href="#" class="dropdown-item" id="btn-import-csv" style="display:block;padding:12px 16px;color:var(--text-primary);text-decoration:none;font-size:0.9rem;border-bottom:1px solid var(--border-color);"><i class="fa fa-upload fa-fw"></i> Import (CSV)</a>
                                `}
                                <a href="api.php?action=export_ips&subnet_id=${state.activeSubnet.id}" target="_blank" class="dropdown-item" style="display:block;padding:12px 16px;color:var(--text-primary);text-decoration:none;font-size:0.9rem;"><i class="fa fa-download fa-fw"></i> Export (CSV)</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-row">
                    <div class="panel">
                        <div class="panel-header" style="border-bottom:none;margin-bottom:0;padding-bottom:0;">
                            <h3 class="panel-title" style="font-size:1.15rem;font-weight:600;color:var(--text-primary);">Subnet Overview</h3>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:20px;margin:20px 0 30px 0;border-bottom:1px solid var(--border-color);padding-bottom:20px;">
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <span style="color:var(--text-muted);font-size:0.85rem;font-weight:500;">Total IPs</span>
                                <span style="font-size:1.8rem;font-weight:600;color:var(--text-primary);">${totalHosts}</span>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;border-left:1px solid var(--border-color);padding-left:20px;">
                                <span style="color:var(--text-muted);font-size:0.85rem;font-weight:500;">Active</span>
                                <span style="font-size:1.8rem;font-weight:600;color:var(--color-active);">${activeCount}</span>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;border-left:1px solid var(--border-color);padding-left:20px;">
                                <span style="color:var(--text-muted);font-size:0.85rem;font-weight:500;">Offline</span>
                                <span style="font-size:1.8rem;font-weight:600;color:var(--color-offline);">${offlineCount}</span>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:6px;border-left:1px solid var(--border-color);padding-left:20px;">
                                <span style="color:var(--text-muted);font-size:0.85rem;font-weight:500;">Free</span>
                                <span style="font-size:1.8rem;font-weight:600;color:var(--color-free);">${freeCount}</span>
                            </div>
                        </div>
                        <div id="subnet-ips-container"></div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:24px;">
                        <div class="panel">
                            <div class="panel-header"><h3 class="panel-title">IP Utilization</h3></div>
                            <div style="padding:15px 0 0 0;text-align:center;">
                                <svg viewBox="0 0 300 120" style="width:100%;height:auto;display:block;">
                                    <defs>
                                        <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="#0066cc" stop-opacity="0.3"/>
                                            <stop offset="100%" stop-color="#0066cc" stop-opacity="0.0"/>
                                        </linearGradient>
                                    </defs>
                                    <line x1="30" y1="10" x2="280" y2="10" stroke="var(--border-color)" stroke-width="1" stroke-dasharray="2,2"/>
                                    <line x1="30" y1="40" x2="280" y2="40" stroke="var(--border-color)" stroke-width="1" stroke-dasharray="2,2"/>
                                    <line x1="30" y1="70" x2="280" y2="70" stroke="var(--border-color)" stroke-width="1" stroke-dasharray="2,2"/>
                                    <line x1="30" y1="100" x2="280" y2="100" stroke="var(--border-color)" stroke-width="1"/>
                                    <text x="25" y="14" fill="#8a8d90" font-size="8" text-anchor="end">100%</text>
                                    <text x="25" y="44" fill="#8a8d90" font-size="8" text-anchor="end">50%</text>
                                    <text x="25" y="74" fill="#8a8d90" font-size="8" text-anchor="end">25%</text>
                                    <text x="25" y="104" fill="#8a8d90" font-size="8" text-anchor="end">0%</text>
                                    <text x="30" y="115" fill="#8a8d90" font-size="8" text-anchor="middle">0</text>
                                    <text x="113" y="115" fill="#8a8d90" font-size="8" text-anchor="middle">25</text>
                                    <text x="196" y="115" fill="#8a8d90" font-size="8" text-anchor="middle">50</text>
                                    <text x="280" y="115" fill="#8a8d90" font-size="8" text-anchor="middle">90</text>
                                    <path d="M 30 100 L 70 85 L 110 50 L 150 65 L 190 40 L 230 55 L 280 ${100 - (fillPercent * 0.9)} L 280 100 Z" fill="url(#chartGrad)"/>
                                    <path d="M 30 100 L 70 85 L 110 50 L 150 65 L 190 40 L 230 55 L 280 ${100 - (fillPercent * 0.9)}" fill="none" stroke="#0066cc" stroke-width="2"/>
                                </svg>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 10px;">Subnet Utilization Rate: <span style="color:var(--text-primary); font-weight:600;">${fillPercent}%</span></div>
                            </div>
                        </div>
                        <div class="panel">
                            <div class="panel-header"><h3 class="panel-title">Subnet Info</h3></div>
                            <div style="display:flex;flex-direction:column;gap:12px;padding:15px 0 0 0;">
                                <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                    <span style="color:var(--text-secondary);font-size:0.9rem;">Description</span><span style="font-weight:600;font-size:0.9rem;">${state.activeSubnet.description || '-'}</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border-color);padding-bottom:8px;">
                                    <span style="color:var(--text-secondary);font-size:0.9rem;">Tag</span><span style="font-weight:600;font-size:0.9rem;">${renderSubnetTagBadges(state.activeSubnet)}</span>
                                </div>
                                <div style="display:flex;justify-content:space-between;padding-bottom:0;">
                                    <span style="color:var(--text-secondary);font-size:0.9rem;">VRF</span><span style="font-weight:600;font-size:0.9rem;">${state.activeSubnet.vrf || '-'}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        renderIpsList();

        const btnAddIp = document.getElementById('btn-add-ip');
        if (btnAddIp) btnAddIp.addEventListener('click', () => openIpModal());

        const diagToggle = document.getElementById('btn-diagnostic-toggle');
        const diagDropdown = document.getElementById('diagnostic-dropdown');
        if (diagToggle && diagDropdown) {
            diagToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                diagDropdown.style.display = diagDropdown.style.display === 'none' ? 'block' : 'none';
            });
            window.addEventListener('click', () => {
                diagDropdown.style.display = 'none';
            });
        }

        const btnToggleView = document.getElementById('btn-toggle-view');
        if (btnToggleView) {
            btnToggleView.addEventListener('click', (e) => {
                e.preventDefault();
                state.isGridView = !state.isGridView;
                renderIpsList();
                btnToggleView.innerHTML = `<i class="fa ${state.isGridView ? 'fa-list' : 'fa-th'}"></i>`;
            });
        }

        const btnImportCsv = document.getElementById('btn-import-csv');
        if (btnImportCsv) btnImportCsv.addEventListener('click', (e) => { e.preventDefault(); openImportModal(); });

        const btnSnmpDiscover = document.getElementById('btn-snmp-discover');
        if (btnSnmpDiscover) btnSnmpDiscover.addEventListener('click', (e) => { e.preventDefault(); openSnmpModal(); });

        const scanBtn = document.getElementById('btn-scan-subnet');
        if (scanBtn) {
            scanBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                if (!confirm('This process may take a few seconds depending on the size of your network. Start scan?')) return;

                scanBtn.disabled = true;
                scanBtn.innerHTML = '<i class="fa fa-sync fa-spin"></i> Scanning...';

                try {
                    const res = await fetch(`api.php?action=scan_subnet&subnet_id=${state.activeSubnet.id}`);
                    const scanData = await res.json();
                    if (scanData.status === 'success') {
                        showToast(`Scan finished! ${scanData.discovered} new devices discovered, ${scanData.updated} IP status updated.`, 'success');
                        renderSubnetDetail(state.activeSubnet.id);
                    } else {
                        showToast(scanData.message, 'error');
                    }
                } catch (err) {
                    showToast('Error occurred during scan', 'error');
                } finally {
                    scanBtn.disabled = false;
                    scanBtn.innerHTML = '<i class="fa fa-sync"></i> Scan Subnet';
                }
            });
        }
    } catch (err) {
        showToast('Failed to load subnet details', 'error');
    }
}
