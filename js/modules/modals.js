import { state, dom } from './state.js';
import { showToast, openModal, closeModal } from './ui.js';
import { escapeHTML } from './utils.js';
import { loadSubnetsSidebar, loadView } from './navigation.js';
import { renderDashboard } from './dashboard.js';
import { renderSubnetDetail } from './subnets.js';
import { renderTags } from './system.js';
import { renderExternalIPs } from './external_ips.js';
import { renderSearch } from './search.js';

export function openSubnetModal(subnetObj = null) {
    const isEdit = !!subnetObj;
    const form = document.getElementById('subnet-form');
    if (form && !isEdit) form.reset();
    dom.subnetModal.querySelector('.modal-title').textContent = isEdit ? 'Edit Subnet' : 'Add New Subnet';
    
    const tagSelect = document.getElementById('sub-tag');
    tagSelect.innerHTML = '<option value="">-- No Tag --</option>';
    state.tagsData.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = t.name;
        tagSelect.appendChild(opt);
    });
    
    const parentSelect = document.getElementById('sub-parent');
    parentSelect.innerHTML = '<option value="">-- None (Root Level) --</option>';
    state.subnets.forEach(s => {
        if (isEdit && s.id == subnetObj.id) return; 
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = `${s.name} (${s.subnet}/${s.mask})`;
        parentSelect.appendChild(opt);
    });
    
    document.getElementById('sub-id').value = isEdit ? subnetObj.id : '';
    document.getElementById('sub-address').value = isEdit ? subnetObj.subnet : '';
    document.getElementById('sub-mask').value = isEdit ? subnetObj.mask : '24';
    document.getElementById('sub-name').value = isEdit ? subnetObj.name : '';
    document.getElementById('sub-desc').value = isEdit ? subnetObj.description || '' : '';
    document.getElementById('sub-tag').value = isEdit ? subnetObj.tag_id || '' : '';
    document.getElementById('sub-parent').value = isEdit ? subnetObj.parent_id || '' : '';
    document.getElementById('sub-vrf').value = isEdit ? subnetObj.vrf || '' : '';
    
    document.getElementById('sub-address').disabled = false;
    document.getElementById('sub-mask').disabled = false;
    
    openModal(dom.subnetModal);
}

export function openIpModal(ipObj = null) {
    const isEdit = !!ipObj;
    const isReadOnly = state.userRole === 'readonly';
    const form = document.getElementById('ip-form');
    if (form && !isEdit) form.reset();
    
    dom.ipModal.querySelector('.modal-title').textContent = isReadOnly ? 'View IP Address Details' : ((isEdit && ipObj.status !== 'free') ? 'Edit IP Address' : 'Define IP Address');
    
    document.getElementById('ip-id').value = (isEdit && ipObj.status !== 'free') ? ipObj.id : '';
    document.getElementById('ip-subnet-id').value = isEdit ? ipObj.subnet_id : state.activeSubnet.id;
    document.getElementById('ip-address').value = isEdit ? ipObj.ip : '';
    document.getElementById('ip-status').value = (isEdit && ipObj.status !== 'free') ? ipObj.status : 'active';
    document.getElementById('ip-hostname').value = (isEdit && ipObj.status !== 'free') ? ipObj.hostname || '' : '';
    document.getElementById('ip-mac').value = (isEdit && ipObj.status !== 'free') ? ipObj.mac || '' : '';
    document.getElementById('ip-desc').value = (isEdit && ipObj.status !== 'free') ? ipObj.description || '' : '';
    
    document.getElementById('ip-status').disabled = isReadOnly;
    document.getElementById('ip-hostname').disabled = isReadOnly;
    document.getElementById('ip-mac').disabled = isReadOnly;
    document.getElementById('ip-desc').disabled = isReadOnly;
    document.getElementById('ip-address').disabled = isReadOnly || isEdit;
    
    const suggestBtn = document.getElementById('btn-suggest-ip');
    if (suggestBtn) suggestBtn.style.display = isReadOnly ? 'none' : 'block';
    
    const saveBtn = dom.ipModal.querySelector('button[type="submit"]');
    if (saveBtn) saveBtn.style.display = isReadOnly ? 'none' : 'inline-flex';
    
    openModal(dom.ipModal);
}

export function openImportModal() {
    const form = document.getElementById('import-form');
    if (form) form.reset();
    document.getElementById('import-subnet-id').value = state.activeSubnet.id;
    document.getElementById('csv-file-input').value = '';
    openModal(dom.importModal);
}

export async function openSnmpModal() {
    const form = document.getElementById('snmp-form');
    if (form) form.reset();
    document.getElementById('snmp-subnet-id').value = state.activeSubnet.id;
    const subParts = state.activeSubnet.subnet.split('.');
    if (subParts.length === 4) {
        subParts[3] = '1';
        document.getElementById('snmp-router-ip').value = subParts.join('.');
    }
    
    let defaultCommunity = 'public';
    try {
        const res = await fetch('api.php?action=get_settings');
        const data = await res.json();
        if (data.status === 'success' && data.settings && data.settings.snmp_community) {
            defaultCommunity = data.settings.snmp_community;
        }
    } catch (e) {}
    
    document.getElementById('snmp-community').value = defaultCommunity;
    openModal(dom.snmpModal);
}

export function openTagModal(tagObj = null) {
    const isEdit = !!tagObj;
    const form = document.getElementById('tag-form');
    if (form && !isEdit) form.reset();
    document.getElementById('tag-modal-title').textContent = isEdit ? 'Edit Tag' : 'Add New Tag';
    document.getElementById('tag-id-input').value = isEdit ? tagObj.id : '';
    document.getElementById('tag-name-input').value = isEdit ? tagObj.name : '';
    document.getElementById('tag-desc-input').value = isEdit ? (tagObj.description || '') : '';
    
    const checklist = document.getElementById('tag-subnets-checklist');
    if (checklist) {
        if (!state.subnets || state.subnets.length === 0) {
            checklist.innerHTML = '<span style="font-size:0.85rem;color:var(--text-muted);">No subnets available</span>';
        } else {
            let html = '';
            state.subnets.forEach(s => {
                let isChecked = false;
                if (isEdit) {
                    // Check many-to-many relation via all_tags array
                    if (s.all_tags && Array.isArray(s.all_tags)) {
                        isChecked = s.all_tags.some(t => t.id == tagObj.id);
                    }
                    // Fallback: check legacy single tag_id
                    if (!isChecked) {
                        isChecked = s.tag_id == tagObj.id;
                    }
                    // Fallback: check subnets array from tag data
                    if (!isChecked && tagObj.subnets && Array.isArray(tagObj.subnets)) {
                        isChecked = tagObj.subnets.some(ts => ts.id == s.id);
                    }
                }
                html += `
                    <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:0.85rem;cursor:pointer;color:var(--text-primary);border-bottom:1px solid var(--border-color);">
                        <input type="checkbox" class="tag-subnet-checkbox" value="${s.id}" ${isChecked ? 'checked' : ''}>
                        <span><strong>${escapeHTML(s.name)}</strong> <small style="opacity:0.7;">(${escapeHTML(s.subnet)}/${escapeHTML(s.mask)})</small></span>
                    </label>
                `;
            });
            checklist.innerHTML = html;
        }
    }
    openModal(dom.tagModal);
}

export function setupModals() {
    document.querySelectorAll('.modal-close, .btn-close-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            closeModal(dom.subnetModal);
            closeModal(dom.ipModal);
            closeModal(dom.importModal);
            closeModal(dom.snmpModal);
            closeModal(dom.passwordModal);
            closeModal(dom.helpModal);
            closeModal(dom.tagModal);
        });
    });

    window.addEventListener('click', (e) => {
        if (e.target === dom.subnetModal) closeModal(dom.subnetModal);
        if (e.target === dom.ipModal) closeModal(dom.ipModal);
        if (e.target === dom.importModal) closeModal(dom.importModal);
        if (e.target === dom.snmpModal) closeModal(dom.snmpModal);
        if (e.target === dom.passwordModal) closeModal(dom.passwordModal);
        if (e.target === dom.helpModal) closeModal(dom.helpModal);
        if (e.target === dom.tagModal) closeModal(dom.tagModal);
    });

    const userMenuBtn = document.getElementById('header-user-menu-btn');
    const userDropdown = document.getElementById('user-dropdown-menu');
    if (userMenuBtn && userDropdown) {
        userMenuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.style.display = userDropdown.style.display === 'none' ? 'block' : 'none';
        });
        window.addEventListener('click', () => {
            userDropdown.style.display = 'none';
        });
    }

    const changePasswordBtn = document.getElementById('btn-change-password-modal');
    if (changePasswordBtn) {
        changePasswordBtn.addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById('old-pass-modal').value = '';
            document.getElementById('new-pass-modal').value = '';
            openModal(dom.passwordModal);
        });
    }

    const helpBtn = document.querySelector('button[title="Help"]');
    if (helpBtn) {
        helpBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openModal(dom.helpModal);
        });
    }

    const btnSuggest = document.getElementById('btn-suggest-ip');
    if (btnSuggest) {
        btnSuggest.onclick = async () => {
            try {
                const res = await fetch(`api.php?action=get_next_free_ip&subnet_id=${state.activeSubnet.id}`);
                const sData = await res.json();
                if (sData.status === 'success') {
                    const freeIp = sData.ip || sData.free_ip;
                    document.getElementById('ip-address').value = freeIp;
                    showToast(`Free IP address found: ${freeIp}`, 'success');
                } else {
                    showToast(sData.message, 'error');
                }
            } catch (err) {
                showToast('Could not suggest IP', 'error');
            }
        };
    }

    const tagForm = document.getElementById('tag-form');
    if (tagForm) {
        tagForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('tag-id-input').value;
            const checkedSubnets = Array.from(document.querySelectorAll('.tag-subnet-checkbox:checked')).map(cb => parseInt(cb.value));
            const payload = {
                id,
                name: document.getElementById('tag-name-input').value,
                description: document.getElementById('tag-desc-input').value,
                subnet_ids: checkedSubnets
            };
            const action = id ? 'edit_tag' : 'add_tag';
            try {
                const res = await fetch(`api.php?action=${action}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': state.csrfToken
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    closeModal(dom.tagModal);
                    renderTags();
                    loadSubnetsSidebar();
                    if (state.currentView === 'external-ips') {
                        renderExternalIPs();
                    } else if (state.currentView === 'dashboard') {
                        renderDashboard();
                    }
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast('Operation failed', 'error');
            }
        });
    }

    const subnetForm = document.getElementById('subnet-form');
    if (subnetForm) {
        subnetForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = {
                id: document.getElementById('sub-id').value,
                subnet: document.getElementById('sub-address').value,
                mask: document.getElementById('sub-mask').value,
                name: document.getElementById('sub-name').value,
                description: document.getElementById('sub-desc').value,
                tag_id: document.getElementById('sub-tag').value,
                parent_id: document.getElementById('sub-parent').value,
                vrf: document.getElementById('sub-vrf').value
            };
            const action = payload.id ? 'edit_subnet' : 'add_subnet';
            try {
                const res = await fetch(`api.php?action=${action}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': state.csrfToken
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    closeModal(dom.subnetModal);
                    loadSubnetsSidebar();
                    if (state.currentView === 'dashboard') {
                        renderDashboard();
                    } else if (state.currentView === 'external-ips') {
                        renderExternalIPs();
                    } else if (state.currentView === 'subnet' && state.activeSubnetId == payload.id) {
                        renderSubnetDetail(payload.id);
                    } else if (state.currentView === 'search' && dom.searchInput) {
                        renderSearch(dom.searchInput.value.trim());
                    }
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Save error', 'error');
            }
        });
    }

    const ipForm = document.getElementById('ip-form');
    if (ipForm) {
        ipForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (state.userRole === 'readonly') {
                showToast('Unauthorized operation (Read-Only account)', 'error');
                closeModal(dom.ipModal);
                return;
            }
            const macVal = document.getElementById('ip-mac').value.trim().toUpperCase();
            if (macVal !== '' && !/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/.test(macVal)) {
                showToast('Invalid MAC Address format.', 'error');
                return;
            }
            const subnet_id = document.getElementById('ip-subnet-id').value;
            const payload = {
                id: document.getElementById('ip-id').value || null,
                subnet_id,
                ip: document.getElementById('ip-address').value,
                status: document.getElementById('ip-status').value,
                hostname: document.getElementById('ip-hostname').value,
                mac: macVal,
                description: document.getElementById('ip-desc').value
            };
            try {
                const res = await fetch('api.php?action=save_ip', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': state.csrfToken
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    closeModal(dom.ipModal);
                    if (state.currentView === 'subnet') {
                        renderSubnetDetail(subnet_id);
                    } else if (state.currentView === 'external-ips') {
                        renderExternalIPs();
                    } else if (state.currentView === 'dashboard') {
                        renderDashboard();
                    } else if (state.currentView === 'search' && dom.searchInput) {
                        renderSearch(dom.searchInput.value.trim());
                    }
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Save error', 'error');
            }
        });
    }
    
    const importForm = document.getElementById('import-form');
    if (importForm) {
        importForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const subnetId = document.getElementById('import-subnet-id').value;
            const formData = new FormData(importForm);
            try {
                const res = await fetch(`api.php?action=import_ips&subnet_id=${subnetId}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': state.csrfToken },
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    closeModal(dom.importModal);
                    renderSubnetDetail(subnetId);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('CSV upload failed', 'error');
            }
        });
    }
    
    const snmpForm = document.getElementById('snmp-form');
    if (snmpForm) {
        snmpForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const subnetId = document.getElementById('snmp-subnet-id').value;
            const routerIp = document.getElementById('snmp-router-ip').value;
            const community = document.getElementById('snmp-community').value;
            
            const btn = e.target.querySelector('button[type="submit"]');
            const oldText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Discovering...';
            
            try {
                const res = await fetch(`api.php?action=snmp_discover&subnet_id=${encodeURIComponent(subnetId)}&router_ip=${encodeURIComponent(routerIp)}&community=${encodeURIComponent(community)}`, {
                    headers: { 'X-CSRF-Token': state.csrfToken }
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    closeModal(dom.snmpModal);
                    renderSubnetDetail(subnetId);
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('SNMP discovery failed', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = oldText;
            }
        });
    }
}
