import { state, dom } from './state.js';
import { escapeHTML, renderSubnetTagBadges } from './utils.js';
import { openSubnetModal } from './modals.js';
import { renderSubnetDetail } from './subnets.js';
import { deleteSubnet } from './dashboard.js';

export async function renderExternalIPs() {
    if (!dom.contentArea) return;
    
    dom.contentArea.innerHTML = '<div style="display:flex;justify-content:center;padding:50px;"><i class="fa fa-spinner fa-spin fa-2x" style="color:var(--color-primary)"></i></div>';

    try {
        const res = await fetch('api.php?action=get_subnets');
        const data = await res.json();
        
        if (data.status !== 'success') {
            dom.contentArea.innerHTML = `<div style="padding:30px;color:var(--color-offline);">Failed to load external subnets: ${escapeHTML(data.message)}</div>`;
            return;
        }

        const allSubnets = data.subnets || [];
        const externalSubnets = allSubnets.filter(s => !s.is_private);
        
        let totalSubnets = externalSubnets.length;
        let totalCapacity = 0;
        let totalActive = 0;
        let totalReserved = 0;
        let totalOffline = 0;
        
        const subnetsWithDetails = await Promise.all(externalSubnets.map(async (subnet) => {
            try {
                const ipRes = await fetch(`api.php?action=get_ips&subnet_id=${subnet.id}`);
                const ipData = await ipRes.json();
                if (ipData.status === 'success') {
                    const ips = ipData.ips || [];
                    const activeCount = ips.filter(i => i.status === 'active').length;
                    const reservedCount = ips.filter(i => i.status === 'reserved').length;
                    const offlineCount = ips.filter(i => i.status === 'offline').length;
                    
                    const rawTotal = subnet.mask >= 31 ? Math.pow(2, 32 - subnet.mask) : Math.pow(2, 32 - subnet.mask) - 2;
                    const capacity = Math.max(1, rawTotal);
                    
                    totalCapacity += capacity;
                    totalActive += activeCount;
                    totalReserved += reservedCount;
                    totalOffline += offlineCount;
                    
                    return { ...subnet, ips, activeCount, reservedCount, offlineCount, capacity };
                }
            } catch (e) {
                console.error(e);
            }
            return { ...subnet, ips: [], activeCount: 0, reservedCount: 0, offlineCount: 0, capacity: 0 };
        }));

        const totalUsed = totalActive + totalReserved + totalOffline;
        const overallUtilization = totalCapacity > 0 ? Math.min(100, Math.round((totalUsed / totalCapacity) * 100)) : 0;
        const isReadOnly = state.userRole === 'readonly';

        dom.contentArea.innerHTML = `
            <div class="fade-in">
                <!-- Page Header -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin: 0;">
                            <i class="fa fa-globe" style="color: #3b82f6; margin-right: 8px;"></i> External Subnets & IP Management
                        </h2>
                        <p style="color: var(--text-secondary); margin-top: 4px; margin-bottom: 0; font-size: 0.9rem;">
                            Manage public subnets, WAN gateways, cloud ranges, and public IP address allocations.
                        </p>
                    </div>
                    ${isReadOnly ? '' : `
                        <button class="btn" id="btn-add-external-subnet" style="padding: 10px 18px; font-weight: 600;">
                            <i class="fa fa-plus" style="margin-right: 6px;"></i> Add External Subnet
                        </button>
                    `}
                </div>

                <!-- Overview Metrics Grid -->
                <div class="metrics-grid" style="margin-bottom: 24px;">
                    <div class="metric-card">
                        <div class="metric-icon" style="background: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                            <i class="fa fa-network-wired"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value">${totalSubnets}</div>
                            <div class="metric-label">External Subnets</div>
                        </div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--color-active);">
                            <i class="fa fa-check-circle"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value">${totalActive}</div>
                            <div class="metric-label">Active Public IPs</div>
                        </div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--color-reserved);">
                            <i class="fa fa-bookmark"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value">${totalReserved}</div>
                            <div class="metric-label">Reserved IPs</div>
                        </div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-icon" style="background: rgba(99, 102, 241, 0.1); color: #6366f1;">
                            <i class="fa fa-chart-pie"></i>
                        </div>
                        <div class="metric-info">
                            <div class="metric-value">${overallUtilization}%</div>
                            <div class="metric-label">Overall Utilization</div>
                        </div>
                    </div>
                </div>

                <!-- Subnets Table Section -->
                ${subnetsWithDetails.length === 0 ? `
                    <div class="panel" style="text-align: center; padding: 50px 20px;">
                        <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(59, 130, 246, 0.1); color: #3b82f6; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto; font-size: 1.8rem;">
                            <i class="fa fa-globe"></i>
                        </div>
                        <h3 style="font-size: 1.2rem; font-weight: 600; color: var(--text-primary); margin-bottom: 8px;">No External Subnets Found</h3>
                        <p style="color: var(--text-secondary); max-width: 500px; margin: 0 auto 24px auto; font-size: 0.9rem;">
                            You have not created any public/external subnets yet. Click below to add an external IP range or WAN subnet.
                        </p>
                        ${isReadOnly ? '' : `
                            <button class="btn" id="btn-add-external-subnet-empty" style="padding: 10px 20px;">
                                <i class="fa fa-plus" style="margin-right: 6px;"></i> Add External Subnet
                            </button>
                        `}
                    </div>
                ` : `
                    <div class="panel" style="padding: 0; overflow: hidden; border: 1px solid var(--border-color); border-radius: 8px;">
                        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); background: var(--bg-panel); display: flex; justify-content: space-between; align-items: center;">
                            <h3 style="font-size: 1.05rem; font-weight: 600; color: var(--text-primary); margin: 0;">
                                <i class="fa fa-list" style="margin-right: 8px; color: #3b82f6;"></i> External Subnets List
                            </h3>
                            <span style="font-size: 0.85rem; color: var(--text-secondary);">${totalSubnets} subnets registered</span>
                        </div>
                        <div class="ct-table-card" style="overflow-x: auto; margin: 0; border: none; border-radius: 0;">
                            <table class="custom-table">
                                <thead>
                                    <tr>
                                        <th>Subnet CIDR</th>
                                        <th>Name & Details</th>
                                        <th>Total Capacity</th>
                                        <th>Active</th>
                                        <th>Reserved</th>
                                        <th>Free</th>
                                        <th>Utilization</th>
                                        <th style="text-align: right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${subnetsWithDetails.map(subnet => {
                                        const used = subnet.activeCount + subnet.reservedCount + subnet.offlineCount;
                                        const free = Math.max(0, subnet.capacity - used);
                                        const util = subnet.capacity > 0 ? Math.min(100, Math.round((used / subnet.capacity) * 100)) : 0;

                                        return `
                                            <tr>
                                                <td>
                                                    <a class="btn-open-external-subnet" data-subnet-id="${subnet.id}" style="cursor: pointer; font-family: monospace; font-weight: 700; font-size: 0.95rem; color: #3b82f6; text-decoration: none;">
                                                        <i class="fa fa-network-wired" style="margin-right: 6px; font-size: 0.85rem;"></i>${escapeHTML(subnet.subnet)}/${subnet.mask}
                                                    </a>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 600; color: var(--text-primary);">${escapeHTML(subnet.name)}</div>
                                                    ${subnet.description ? `<div style="font-size: 0.8rem; color: var(--text-secondary);">${escapeHTML(subnet.description)}</div>` : ''}
                                                    ${renderSubnetTagBadges(subnet)}
                                                </td>
                                                <td><strong style="font-weight: 600;">${subnet.capacity}</strong></td>
                                                <td><span class="badge badge-active">${subnet.activeCount}</span></td>
                                                <td><span class="badge badge-reserved">${subnet.reservedCount}</span></td>
                                                <td><span style="color: var(--text-secondary);">${free}</span></td>
                                                <td style="width: 140px;">
                                                    <div style="display: flex; align-items: center; gap: 8px;">
                                                        <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); width: 32px;">${util}%</span>
                                                        <div style="flex: 1; height: 6px; background: var(--border-color); border-radius: 3px; overflow: hidden;">
                                                            <div style="height: 100%; width: ${util}%; background: ${util > 85 ? 'var(--color-offline)' : '#3b82f6'}; border-radius: 3px;"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td style="text-align: right; white-space: nowrap;">
                                                    <button class="btn btn-secondary btn-open-external-subnet" data-subnet-id="${subnet.id}" style="padding: 5px 10px; font-size: 0.8rem; margin-right: 4px;" title="Manage IP Addresses">
                                                        <i class="fa fa-sliders-h" style="margin-right: 4px;"></i> Manage IPs
                                                    </button>
                                                    ${isReadOnly ? '' : `
                                                        <button class="action-btn btn-edit-external-subnet" data-subnet-id="${subnet.id}" title="Edit Subnet">
                                                            <i class="fa fa-edit"></i>
                                                        </button>
                                                        <button class="action-btn action-btn-danger btn-delete-external-subnet" data-subnet-id="${subnet.id}" title="Delete Subnet">
                                                            <i class="fa fa-trash"></i>
                                                        </button>
                                                    `}
                                                </td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `}
            </div>
        `;

        // Attach event listeners
        const btnAddExt = document.getElementById('btn-add-external-subnet');
        if (btnAddExt) btnAddExt.addEventListener('click', () => openSubnetModal(null, false));

        const btnAddExtEmpty = document.getElementById('btn-add-external-subnet-empty');
        if (btnAddExtEmpty) btnAddExtEmpty.addEventListener('click', () => openSubnetModal(null, false));

        document.querySelectorAll('.btn-edit-external-subnet').forEach(btn => {
            btn.addEventListener('click', () => {
                const sId = parseInt(btn.dataset.subnetId);
                const sub = externalSubnets.find(s => s.id === sId);
                if (sub) openSubnetModal(sub);
            });
        });

        document.querySelectorAll('.btn-delete-external-subnet').forEach(btn => {
            btn.addEventListener('click', () => {
                const sId = parseInt(btn.dataset.subnetId);
                const sub = externalSubnets.find(s => s.id === sId);
                if (sub && confirm(`Are you sure you want to delete external subnet ${sub.subnet}/${sub.mask} (${sub.name})?`)) {
                    deleteSubnet(sId);
                }
            });
        });

        document.querySelectorAll('.btn-open-external-subnet').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const sId = parseInt(btn.dataset.subnetId);
                renderSubnetDetail(sId);
            });
        });

    } catch (err) {
        console.error(err);
        dom.contentArea.innerHTML = `<div style="padding:30px;color:var(--color-offline);">Error loading External Subnets view.</div>`;
    }
}
