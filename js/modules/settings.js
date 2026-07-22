import { state, dom } from './state.js';
import { escapeHTML } from './utils.js';
import { showToast } from './ui.js';

export async function renderSettings() {
    try {
        const sRes = await fetch('api.php?action=get_settings');
        const sData = await sRes.json();
        const settings = sData.status === 'success' && sData.settings ? sData.settings : { site_title: 'IProof', scan_interval: 0, last_scan_time: null };
        
        dom.contentArea.innerHTML = `
            <div class="animate-fade-in" style="max-width: 1100px;">
                <div class="content-header" style="border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 24px;">
                    <div>
                        <h2 class="content-title">Application Settings</h2>
                        <p class="content-subtitle" style="margin-top: 4px;">Manage background scanning schedules, ports, and SNMP discovery automation</p>
                    </div>
                </div>

                <form id="settings-general-form">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(440px, 1fr)); gap: 24px;">
                        <!-- Panel 1: General & Auto-Scan Settings -->
                        <div class="panel" style="display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div class="panel-header">
                                    <h3 class="panel-title">General & Auto-Scan Settings</h3>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:14px;padding:15px 0 0 0;">
                                    <div class="input-group">
                                        <label class="input-label" for="settings-scan-interval">Auto-Scan Interval</label>
                                        <select id="settings-scan-interval" class="form-input">
                                            <option value="0" ${settings.scan_interval == 0 ? 'selected' : ''}>Disabled</option>
                                            <option value="1" ${settings.scan_interval == 1 ? 'selected' : ''}>Every 1 minute</option>
                                            <option value="5" ${settings.scan_interval == 5 ? 'selected' : ''}>Every 5 minutes</option>
                                            <option value="15" ${settings.scan_interval == 15 ? 'selected' : ''}>Every 15 minutes</option>
                                            <option value="30" ${settings.scan_interval == 30 ? 'selected' : ''}>Every 30 minutes</option>
                                            <option value="60" ${settings.scan_interval == 60 ? 'selected' : ''}>Every 1 hour</option>
                                            <option value="360" ${settings.scan_interval == 360 ? 'selected' : ''}>Every 6 hours</option>
                                            <option value="720" ${settings.scan_interval == 720 ? 'selected' : ''}>Every 12 hours</option>
                                            <option value="1440" ${settings.scan_interval == 1440 ? 'selected' : ''}>Daily (24 hours)</option>
                                        </select>
                                    </div>
                                    <div class="input-group">
                                        <label class="input-label" for="settings-ports-to-scan">Ports to Scan (comma-separated)</label>
                                        <input type="text" id="settings-ports-to-scan" class="form-input" value="${escapeHTML(settings.ports_to_scan || '22,80,443,3389')}" placeholder="22,80,443,3389" pattern="[0-9,]+" title="Only numbers and commas are allowed">
                                        <p style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;">Ports checked during active network discovery. (e.g. 22,80,443,3389)</p>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:0.85rem;margin-top:4px;">
                                        <span style="color:var(--text-secondary);">Last Automatic Scan:</span>
                                        <span style="font-weight:600;color:var(--text-primary);">${escapeHTML(settings.last_scan_time || 'Never')}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Panel 2: SNMP Discovery Settings -->
                        <div class="panel" style="display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <div class="panel-header">
                                    <h3 class="panel-title">SNMP Discovery Settings</h3>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:14px;padding:15px 0 0 0;">
                                    <div class="input-group">
                                        <label class="input-label" for="settings-snmp-community">Default Community String</label>
                                        <input type="text" id="settings-snmp-community" class="form-input" value="${escapeHTML(settings.snmp_community || 'public')}" placeholder="e.g. public">
                                        <p style="font-size:0.8rem;color:var(--text-secondary);margin-top:4px;">Default SNMP read community string used when querying network routers/switches.</p>
                                    </div>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                        <div class="input-group">
                                            <label class="input-label" for="settings-snmp-version">SNMP Version</label>
                                            <select id="settings-snmp-version" class="form-input">
                                                <option value="v1" ${settings.snmp_version === 'v1' ? 'selected' : ''}>SNMP v1</option>
                                                <option value="v2c" ${(!settings.snmp_version || settings.snmp_version === 'v2c') ? 'selected' : ''}>SNMP v2c (Recommended)</option>
                                                <option value="v3" ${settings.snmp_version === 'v3' ? 'selected' : ''}>SNMP v3</option>
                                            </select>
                                        </div>
                                        <div class="input-group">
                                            <label class="input-label" for="settings-snmp-port">SNMP Port</label>
                                            <input type="number" id="settings-snmp-port" class="form-input" value="${escapeHTML(settings.snmp_port || 161)}" min="1" max="65535" placeholder="161">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 24px; display: flex; justify-content: flex-end;">
                        <button type="submit" class="btn" style="padding: 10px 24px;"><i class="fa fa-save"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        `;

        const portsInput = document.getElementById('settings-ports-to-scan');
        if (portsInput) {
            portsInput.addEventListener('input', () => {
                portsInput.value = portsInput.value.replace(/[^0-9,]/g, '');
            });
        }

        const settingsForm = document.getElementById('settings-general-form');
        if (settingsForm) {
            settingsForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const scan_interval = parseInt(document.getElementById('settings-scan-interval').value);
                const ports_to_scan = document.getElementById('settings-ports-to-scan').value.trim().replace(/[^0-9,]/g, '');
                const snmp_community = document.getElementById('settings-snmp-community').value.trim();
                const snmp_version = document.getElementById('settings-snmp-version').value;
                const snmp_port = parseInt(document.getElementById('settings-snmp-port').value) || 161;
                
                try {
                    const sSaveRes = await fetch('api.php?action=save_settings', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ scan_interval, ports_to_scan, snmp_community, snmp_version, snmp_port })
                    });
                    const sSaveData = await sSaveRes.json();
                    if (sSaveData.status === 'success') {
                        showToast(sSaveData.message, 'success');
                        renderSettings();
                    } else {
                        showToast(sSaveData.message, 'error');
                    }
                } catch (err) {
                    showToast('Failed to save settings', 'error');
                }
            });
        }
    } catch (err) {
        showToast('Settings could not be loaded', 'error');
    }
}
