<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="Description" content="IProof - Simple and Powerful IP Address Management Software">
    <title>IProof</title>
    
    <!-- Local Fonts & FontAwesome -->
    <link rel="stylesheet" href="css/fontawesome/all.min.css">
    <link rel="stylesheet" href="css/fonts.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="css/style.css?v=<?= @filemtime(__DIR__ . '/css/style.css') ?: '1.2.0' ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg">

    <!-- Global JS Error Diagnostic -->
    <script>
        window.addEventListener('error', function(event) {
            showGlobalError('JavaScript Error', event.message + '\nIn: ' + event.filename + ':' + event.lineno);
        });
        window.addEventListener('unhandledrejection', function(event) {
            var reason = event.reason;
            var details = reason ? (reason.stack || reason.message || reason) : 'Unknown Promise Rejection';
            showGlobalError('Promise Error', details);
        });
        function showGlobalError(title, details) {
            // Wait for DOM to load fully if not already
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() { displayError(title, details); });
            } else {
                displayError(title, details);
            }
        }
        function displayError(title, details) {
            var errorCard = document.getElementById('js-critical-error');
            if (errorCard) return; // Prevent duplicates

            errorCard = document.createElement('div');
            errorCard.id = 'js-critical-error';
            errorCard.style = 'position:fixed;top:20px;left:20px;right:20px;background:#151515;color:#c9190b;border:2px solid #c9190b;padding:24px;border-radius:3px;z-index:100000;box-shadow:0 10px 40px rgba(0,0,0,0.5);font-family:system-ui, -apple-system, sans-serif;';
            errorCard.innerHTML = '<h3 style="margin-top:0;font-weight:600;display:flex;align-items:center;gap:10px;"><i class="fa fa-exclamation-triangle"></i> App Loading Issue: ' + title + '</h3>' +
                '<p style="color:#d2d5d8;font-size:0.9rem;margin-bottom:10px;">The system encountered a client-side JavaScript error. Please perform a hard refresh or click the button below to clear the browser cache:</p>' +
                '<pre style="background:#0f1215;color:#a0a5ab;padding:14px;border:1px solid #383b40;border-radius:3px;overflow-x:auto;font-family:monospace;font-size:0.85rem;white-space:pre-wrap;word-break:break-all;margin-bottom:20px;">' + details + '</pre>' +
                '<button style="background:#0066cc;color:#ffffff;border:none;padding:10px 20px;border-radius:3px;cursor:pointer;font-weight:600;display:inline-flex;align-items:center;gap:8px;" onclick="location.reload(true)"><i class="fa fa-sync"></i> Force Reload (Ctrl+F5)</button>';
            document.body.appendChild(errorCard);
        }
    </script>
</head>
<body>

    <!-- 0. APP INITIAL LOADER (Prevents White Screen) -->
    <div id="app-loader" style="position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: #151515; color: #ffffff; display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 99999; font-family: 'Red Hat Text', system-ui, sans-serif;">
        <h1 style="font-size: 2.2rem; font-weight: 700; margin-bottom: 16px;"><span style="color: #ee0000; font-weight: 300; margin-right: 6px;">︿</span>IProof</h1>
        <div style="display: flex; align-items: center; gap: 12px; font-size: 1rem; color: #8a8d90;">
            <i class="fa fa-spinner fa-spin fa-lg" style="color: #d2d5d8;"></i> Loading...
        </div>
    </div>

    <!-- 1. AUTH SECTION -->
    <div class="auth-container" id="auth-section" style="display: none;">
        <div class="auth-card">
            <h1 class="auth-logo"><span style="color: #ee0000; font-weight: 300; margin-right: 6px;">︿</span>IProof</h1>
            <p class="auth-subtitle">Enter your password to login</p>
            <form id="login-form">
                <div class="input-group">
                    <label class="input-label" for="username-input">Username</label>
                    <select id="username-input" class="form-input" style="height: auto; padding: 10px 14px;">
                        <option value="admin" selected>Admin</option>
                        <option value="readonly">Read-Only</option>
                    </select>
                </div>
                <div class="input-group">
                    <label class="input-label" for="password-input">Password</label>
                    <input type="password" id="password-input" class="form-input" placeholder="••••••••" required autofocus>
                </div>
                <button type="submit" class="btn btn-block"><i class="fa fa-sign-in-alt"></i> Login</button>
            </form>
        </div>
    </div>

    <!-- 2. MAIN APP SECTION -->
    <div class="app-container" id="main-section" style="display: none; flex-direction: column;">
        <!-- Cockpit 3px accent line at the very top -->
        <div class="ct-accent-line"></div>

        <!-- Cockpit Masthead -->
        <header class="top-header">
            <div style="display: flex; align-items: center; gap: 15px;">
                <!-- Brand Logo -->
                <a href="#" class="brand-logo" data-view="dashboard" style="font-weight: 600; font-size: 1.2rem; color: var(--text-primary); display: flex; align-items: center; text-decoration: none; cursor: pointer;">
                    <span style="color: #ee0000; font-weight: 300; margin-right: 6px;">︿</span>IProof
                </a>
            </div>

            <!-- Right: Toolbar items -->
            <div class="header-tools">
                <div class="search-container">
                    <i class="fa fa-search search-icon"></i>
                    <input type="text" id="search-input" class="form-input search-input" placeholder="Search IP or Subnet...">
                </div>

                <!-- Theme Toggle Icon (Cockpit Dark/Light) -->
                <button class="ct-toolbar-btn" id="theme-toggle-btn" title="Toggle Dark/Light Mode">
                    <i class="fa fa-moon"></i>
                </button>

                <!-- Help icon (like Cockpit's HelpIcon) -->
                <button class="ct-toolbar-btn" title="Help">
                    <i class="fa fa-question-circle"></i>
                </button>

                <!-- Session menu (like Cockpit's CogIcon with dropdown) -->
                <div class="ct-session-menu" id="header-user-menu-btn" style="position: relative;">
                    <button class="ct-toolbar-btn" title="Session">
                        <i class="fa fa-cog"></i>
                    </button>
                    <!-- Dropdown List -->
                    <div class="dropdown-menu" id="user-dropdown-menu" style="display:none;position:absolute;right:0;top:100%;min-width:200px;margin-top:8px;overflow:hidden;border-radius:8px;">
                        <div style="padding:12px 16px;font-size:0.8rem;color:var(--text-secondary);border-bottom:1px solid var(--border-color);background:var(--bg-panel-light);">
                            Logged in as: <strong class="active-username" style="color:var(--text-primary);">admin</strong>
                        </div>
                        <a href="#" class="dropdown-item" id="btn-change-password-modal" style="display:block;padding:12px 16px;text-decoration:none;font-size:0.9rem;border-bottom:1px solid var(--border-color);"><i class="fa fa-key fa-fw" style="margin-right:8px;"></i> Change Password</a>
                        <a href="#" class="dropdown-item" id="btn-logout-header" style="display:block;padding:12px 16px;text-decoration:none;font-size:0.9rem;color:var(--color-offline);"><i class="fa fa-sign-out-alt fa-fw" style="margin-right:8px;"></i> Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="app-body">
            <!-- Sidebar (Cockpit's nav-system-menu) -->
            <aside class="sidebar">
                <!-- Nav Section: System -->
                <div class="ct-nav-group-label">System</div>
                <ul class="nav-menu">
                    <li>
                        <a class="nav-link active" data-view="dashboard">
                            <span>Overview</span>
                        </a>
                    </li>
                </ul>

                <div class="ct-nav-group-label" style="margin-top: 15px;">Management</div>
                <ul class="nav-menu">
                    <li>
                        <a class="nav-link" data-view="tags">
                            <span>Tags</span>
                        </a>
                    </li>
                </ul>

                <!-- Nav Section: Internal Networks -->
                <div class="ct-nav-group-label">Internal Networks</div>
                <div id="sidebar-private-subnets-menu" style="display:flex;flex-direction:column;"></div>

                <!-- Nav Section: External Networks -->
                <div class="ct-nav-group-label" style="margin-top: 15px;">External Networks</div>
                <div id="sidebar-public-subnets-menu" style="display:flex;flex-direction:column;"></div>

                <div class="sidebar-footer" style="display:flex;flex-direction:column;gap:5px;">
                    <a class="nav-link" data-view="settings">
                        <span>Settings</span>
                    </a>
                    <a class="nav-link" data-view="about">
                        <span>About</span>
                    </a>
                </div>
            </aside>

            <!-- Main Content Area -->
            <main class="main-content">
                <div id="content-area">
                    <div class="app-content">
            <!-- Dashboard View -->
            <div class="view-section" id="view-dashboard" style="display: none; padding: 20px;">
                <p>Welcome back! Select a subnet from the sidebar to view IP addresses or use the Search bar.</p>
            </div>
            
            <div id="dashboard-child-subnets" style="display:none; margin-top:20px;">
                <h3 style="margin-bottom: 10px; font-weight: 600; font-size: 1.1rem; color: var(--text-primary);">Child Subnets</h3>
                <div class="ct-table-card">
                    <table class="custom-table" id="child-subnets-table">
                        <thead>
                            <tr>
                                <th>Subnet</th>
                                <th>Name</th>
                                <th>Tag</th>
                                <th>Progress</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="child-subnets-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>

                </div>
            </main>
        </div>
    </div>

    <!-- 3. TOAST CONTAINER -->
    <div class="toast-container" id="toast-container"></div>

    <!-- 4. MODALS -->
    <!-- Add/Edit Subnet Modal -->
    <div class="modal-overlay" id="subnet-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Add New Subnet</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="subnet-form">
                <input type="hidden" id="sub-id">
                <div class="modal-body">
                    <div class="input-group">
                        <label class="input-label" for="sub-address">IP Address</label>
                        <input type="text" id="sub-address" class="form-input" placeholder="e.g. 192.168.1.0" required>
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="sub-mask">CIDR Mask</label>
                        <input type="number" id="sub-mask" class="form-input" min="1" max="32" placeholder="e.g. 24" required>
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="sub-name">Subnet Name</label>
                        <input type="text" id="sub-name" class="form-input" placeholder="e.g. Office Network" required>
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="sub-desc">Description</label>
                        <input type="text" id="sub-desc" class="form-input" placeholder="Brief info about the network...">
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="sub-parent">Master Subnet (Folder)</label>
                        <select id="sub-parent" class="form-input">
                            <option value="">-- None (Root Level) --</option>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="input-group">
                            <label class="input-label" for="sub-tag">Tag</label>
                            <select id="sub-tag" class="form-input">
                                <option value="">-- No Tag --</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label class="input-label" for="sub-vrf">VRF</label>
                            <input type="text" id="sub-vrf" class="form-input" placeholder="e.g. Default-VRF">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-close-modal">Cancel</button>
                    <button type="submit" class="btn">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add/Edit IP Modal -->
    <div class="modal-overlay" id="ip-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Define IP Address</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="ip-form">
                <input type="hidden" id="ip-id">
                <input type="hidden" id="ip-subnet-id">
                <div class="modal-body">
                    <div class="input-group">
                        <label class="input-label" for="ip-address">IP Address</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="ip-address" class="form-input" placeholder="e.g. 192.168.1.50" required style="flex-grow: 1;">
                            <button type="button" class="btn btn-secondary" id="btn-suggest-ip" title="Suggest Free IP" style="padding: 12px 16px;"><i class="fa fa-wand-magic-sparkles"></i> Suggest</button>
                        </div>
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="ip-status">Status</label>
                        <select id="ip-status" class="form-input">
                            <option value="active">Active (Online)</option>
                            <option value="reserved">Reserved</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="ip-hostname">Hostname</label>
                        <input type="text" id="ip-hostname" class="form-input" placeholder="e.g. switch-core-01">
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="ip-mac">MAC Address</label>
                        <input type="text" id="ip-mac" class="form-input" placeholder="e.g. 00:11:22:33:44:55">
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="ip-desc">Description</label>
                        <input type="text" id="ip-desc" class="form-input" placeholder="Purpose of use...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-close-modal">Cancel</button>
                    <button type="submit" class="btn">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- CSV Import Modal -->
    <div class="modal-overlay" id="import-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Import IPs from CSV</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="import-form" enctype="multipart/form-data">
                <input type="hidden" id="import-subnet-id">
                <div class="modal-body">
                    <p style="font-size:0.9rem;color:var(--text-secondary);margin-bottom:20px;line-height:1.4;">
                        Your CSV file must contain these columns:<br>
                        <strong>IP Address, Status, Hostname, MAC Address, Description</strong><br>
                        <span style="color:var(--color-reserved);font-size:0.8rem;">*The first row is considered the header and will be skipped.</span>
                    </p>
                    <div class="input-group">
                        <label class="input-label" for="csv-file-input">Select CSV File</label>
                        <input type="file" id="csv-file-input" name="csv_file" class="form-input" accept=".csv" required style="padding:8px;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-close-modal">Cancel</button>
                    <button type="submit" class="btn"><i class="fa fa-upload"></i> Import</button>
                </div>
            </form>
        </div>
    </div>

    <!-- SNMP Discovery Modal -->
    <div class="modal-overlay" id="snmp-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Device Discovery via SNMP</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="snmp-form">
                <input type="hidden" id="snmp-subnet-id">
                <div class="modal-body">
                    <p style="font-size:0.9rem;color:var(--text-secondary);margin-bottom:20px;line-height:1.4;">
                        Discovers active devices and MAC addresses via SNMP (ARP table) from your gateway router or switch.
                    </p>
                    <div class="input-group">
                        <label class="input-label" for="snmp-router-ip">Device IP Address (Router/Gateway)</label>
                        <input type="text" id="snmp-router-ip" class="form-input" placeholder="e.g. 192.168.1.1" required>
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="snmp-community">SNMP Community Name</label>
                        <input type="text" id="snmp-community" class="form-input" placeholder="e.g. public" value="public" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-close-modal">Cancel</button>
                    <button type="submit" class="btn"><i class="fa fa-bolt"></i> Start Discovery</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Tag Modal -->
    <div class="modal-overlay" id="tag-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="tag-modal-title">Add New Tag</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="tag-form">
                <input type="hidden" id="tag-id-input">
                <div class="modal-body">
                    <div class="input-group">
                        <label class="input-label" for="tag-name-input">Tag Name</label>
                        <input type="text" id="tag-name-input" class="form-input" placeholder="e.g. Production" required>
                    </div>
                    <div class="input-group">
                        <label class="input-label" for="tag-desc-input">Description</label>
                        <input type="text" id="tag-desc-input" class="form-input" placeholder="Optional details...">
                    </div>
                    <div class="input-group" style="margin-top:15px;margin-bottom:0;">
                        <label class="input-label">Assign Subnets (Networks)</label>
                        <div id="tag-subnets-checklist" style="max-height:140px;overflow-y:auto;border:1px solid var(--border-color);border-radius:4px;padding:8px;background:var(--bg-panel);">
                            <!-- Subnets checkboxes generated dynamically -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-close-modal">Cancel</button>
                    <button type="submit" class="btn">Save Tag</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Modal -->
    <div class="modal-overlay" id="password-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Change Admin Password</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="modal-password-form">
                <div class="modal-body">
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 15px;">Note: This action only updates the Administrator account password.</p>
                    <div class="input-group">
                        <label class="input-label" for="old-pass-modal">Current Password</label>
                        <input type="password" id="old-pass-modal" class="form-input" required>
                    </div>
                    <div class="input-group" style="margin-bottom:0;">
                        <label class="input-label" for="new-pass-modal">New Password</label>
                        <input type="password" id="new-pass-modal" class="form-input" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-close-modal">Cancel</button>
                    <button type="submit" class="btn"><i class="fa fa-key"></i> Update Password</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Help Modal -->
    <div class="modal-overlay" id="help-modal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fa fa-question-circle" style="color:var(--color-primary);margin-right:8px;"></i>System Help & Info</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body" style="font-size:0.95rem;line-height:1.6;display:flex;flex-direction:column;gap:15px;padding-top:15px;">
                <p>Welcome to <strong>IProof IPAM</strong>. Here are the core guidelines for the application:</p>
                
                <div style="border-left:3px solid var(--color-primary);padding-left:12px;margin-bottom:5px;">
                    <h4 style="font-weight:600;margin-bottom:4px;font-size:0.95rem;">Subnet Scanning</h4>
                    <p style="color:var(--text-secondary);font-size:0.85rem;">Ping sweeps scan subnets in parallel (up to 32 concurrent requests). Limits are configured up to <strong>/23 (512 IPs)</strong> to avoid timeouts.</p>
                </div>
                
                <div style="border-left:3px solid var(--color-active-border);padding-left:12px;margin-bottom:5px;">
                    <h4 style="font-weight:600;margin-bottom:4px;font-size:0.95rem;">Auto-Scan Engine</h4>
                    <p style="color:var(--text-secondary);font-size:0.85rem;">Set auto-scan interval in <strong>Settings</strong> page. Ensure your server cron job invokes <code>cron_scan.php</code> every minute.</p>
                </div>

                <div style="border-left:3px solid var(--color-reserved-border);padding-left:12px;">
                    <h4 style="font-weight:600;margin-bottom:4px;font-size:0.95rem;">Interactive Search</h4>
                    <p style="color:var(--text-secondary);font-size:0.85rem;">Search filters subnets, IPs, hostname, MAC address, and description. Clearing the input field returns you to the main dashboard.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-close-modal">Close</button>
            </div>
        </div>
    </div>

    <!-- Client-side App JS -->
    <script type="module" src="js/main.js?v=<?= @filemtime(__DIR__ . '/js/main.js') ?: '1.2.0' ?>"></script>
</body>
</html>
