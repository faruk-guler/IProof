import { state, dom } from './state.js';
import { escapeHTML, renderTagBadge, renderSubnetTagBadges } from './utils.js';
import { showToast } from './ui.js';
import { loadView } from './navigation.js';
import { openSubnetModal, openIpModal } from './modals.js';

export async function renderSearch(query) {
    try {
        const res = await fetch(`api.php?action=search&q=${encodeURIComponent(query)}`);
        const data = await res.json();
        
        dom.contentArea.innerHTML = `
            <div class="animate-fade-in">
                <div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 24px;">
                    <div>
                        <h2 class="content-title">Search Results</h2>
                        <p class="content-subtitle" style="margin-top: 4px;">Search results for "${escapeHTML(query)}"</p>
                    </div>
                    <div>
                        <button class="btn btn-secondary" id="btn-back-search" style="padding: 9px 16px;"><i class="fa fa-arrow-left"></i> Back to Overview</button>
                    </div>
                </div>

                <div style="display:flex;flex-direction:column;gap:30px;">
                    <div class="panel">
                        <div class="panel-header"><h3 class="panel-title">Matching Subnets</h3></div>
                        <div class="table-container">
                            <table class="custom-table" id="search-subnets-table">
                                <thead>
                                    <tr>
                                        <th>Subnet</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Tag</th>
                                        <th>VRF</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3 class="panel-title">Matching IP Addresses</h3></div>
                        <div class="table-container">
                            <table class="custom-table" id="search-ips-table">
                                <thead>
                                    <tr>
                                        <th>IP Address</th>
                                        <th>Subnet</th>
                                        <th>Status</th>
                                        <th>Hostname</th>
                                        <th>MAC Address</th>
                                        <th>Description</th>
                                        <th>Last Seen</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        `;

        const tbodySub = document.querySelector('#search-subnets-table tbody');
        if (data.subnets.length === 0) {
            tbodySub.innerHTML = `<tr><td colspan="6" style="text-align:center;color:var(--text-muted);">No matching subnets found.</td></tr>`;
        } else {
            data.subnets.forEach(s => {
                const typeBadge = s.is_private ? '<span style="font-size:0.8rem;padding:3px 10px;border-radius:3px;background:var(--bg-main);border:1px solid var(--border-color);color:var(--text-primary);font-weight:600;white-space:nowrap;display:inline-block;">Internal</span>' : '<span style="font-size:0.8rem;padding:3px 10px;border-radius:3px;background:var(--bg-main);border:1px solid var(--border-color);color:var(--text-primary);font-weight:600;white-space:nowrap;display:inline-block;">External</span>';
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:600;"><a href="#" class="subnet-link" data-id="${s.id}">${escapeHTML(s.name)} (${escapeHTML(s.subnet)}/${escapeHTML(s.mask)})</a></td>
                    <td>${typeBadge}</td>
                    <td>${escapeHTML(s.description) || '-'}</td>
                    <td>${renderSubnetTagBadges(s)}</td>
                    <td>${escapeHTML(s.vrf) || '-'}</td>
                    <td><button class="action-btn edit-subnet-btn" data-id="${s.id}"><i class="fa fa-edit"></i></button></td>
                `;
                tr.querySelector('.subnet-link').addEventListener('click', (e) => {
                    e.preventDefault();
                    loadView('subnet', { id: s.id });
                });
                tr.querySelector('.edit-subnet-btn').addEventListener('click', () => {
                    openSubnetModal(s);
                });
                tbodySub.appendChild(tr);
            });
        }

        const tbodyIps = document.querySelector('#search-ips-table tbody');
        if (data.ips.length === 0) {
            tbodyIps.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--text-muted);">No matching IP addresses found.</td></tr>`;
        } else {
            data.ips.forEach(ip => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:600;"><a href="#" class="ip-link">${escapeHTML(ip.ip)}</a></td>
                    <td>${escapeHTML(ip.subnet_name)}</td>
                    <td><span class="badge badge-${ip.status}">${ip.status}</span></td>
                    <td>${escapeHTML(ip.hostname) || '-'}</td>
                    <td style="font-family:monospace;">${escapeHTML(ip.mac) || '-'}</td>
                    <td>${escapeHTML(ip.description) || '-'}</td>
                    <td style="font-size:0.85rem;color:var(--text-secondary);">${escapeHTML(ip.last_seen) || '-'}</td>
                    <td><button class="action-btn edit-ip-btn"><i class="fa fa-edit"></i></button></td>
                `;
                tr.querySelector('.ip-link').addEventListener('click', (e) => {
                    e.preventDefault();
                    loadView('subnet', { id: ip.subnet_id });
                });
                tr.querySelector('.edit-ip-btn').addEventListener('click', () => {
                    openIpModal(ip);
                });
                tbodyIps.appendChild(tr);
            });
        }

        const btnBack = document.getElementById('btn-back-search');
        if (btnBack) {
            btnBack.addEventListener('click', () => {
                if (dom.searchInput) dom.searchInput.value = '';
                document.querySelectorAll('.sidebar .nav-link').forEach(l => l.classList.remove('active'));
                const overviewLink = document.querySelector('.sidebar .nav-link[data-view="dashboard"]');
                if (overviewLink) overviewLink.classList.add('active');
                loadView('dashboard');
            });
        }

    } catch (err) {
        showToast('Search failed', 'error');
    }
}
