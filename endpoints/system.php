<?php
if ($action === 'get_stats') {
    $stats = [];
    $stats['total_subnets'] = $db->query("SELECT COUNT(*) FROM subnets")->fetchColumn();
    $stats['total_ips'] = $db->query("SELECT COUNT(*) FROM ip_addresses")->fetchColumn();
    $stats['active_ips'] = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE status = 'active'")->fetchColumn();
    $stats['offline_ips'] = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE status = 'offline'")->fetchColumn();
    $stats['reserved_ips'] = $db->query("SELECT COUNT(*) FROM ip_addresses WHERE status = 'reserved'")->fetchColumn();
    
    // Top 5 largest subnets
    $largest = $db->query("
        SELECT subnets.name, subnets.subnet, subnets.mask, COUNT(ip_addresses.id) as count 
        FROM subnets 
        LEFT JOIN ip_addresses ON subnets.id = ip_addresses.subnet_id 
        GROUP BY subnets.id 
        ORDER BY count DESC 
        LIMIT 5
    ")->fetchAll();
    
    // Top 5 recent scans / updates
    $recent = $db->query("
        SELECT ip_addresses.ip, ip_addresses.status, ip_addresses.last_seen, subnets.name as subnet_name 
        FROM ip_addresses 
        JOIN subnets ON ip_addresses.subnet_id = subnets.id 
        WHERE ip_addresses.last_seen IS NOT NULL 
        ORDER BY ip_addresses.last_seen DESC 
        LIMIT 5
    ")->fetchAll();
    
    respond('success', '', [
        'subnets_count' => (int)$stats['total_subnets'],
        'ips_active' => (int)$stats['active_ips'],
        'ips_offline' => (int)$stats['offline_ips'],
        'ips_reserved' => (int)$stats['reserved_ips'],
        'recent_ips' => $recent,
        'largest_subnets' => $largest
    ]);
}

if ($action === 'get_settings') {
    $stmt = $db->query("SELECT site_title, ping_timeout, scan_interval, ports_to_scan, snmp_community, snmp_version, snmp_port, admin_password, readonly_password FROM settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($settings) {
        $settings['has_readonly'] = !empty($settings['readonly_password']);
        unset($settings['admin_password']);
        unset($settings['readonly_password']);
    }
    
    respond('success', '', ['settings' => $settings]);
}

if ($action === 'save_settings') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Fetch existing settings to merge omitted fields
    $settings_stmt = $db->query("SELECT site_title, ping_timeout, scan_interval, ports_to_scan, snmp_community, snmp_version, snmp_port FROM settings LIMIT 1");
    $existing = $settings_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        $existing = ['site_title' => 'IProof', 'ping_timeout' => 1, 'scan_interval' => 60, 'ports_to_scan' => '22,80,443,3389', 'snmp_community' => 'public', 'snmp_version' => 'v2c', 'snmp_port' => 161];
    }
    
    $site_title = isset($input['site_title']) ? trim($input['site_title']) : $existing['site_title'];
    $ping_timeout = isset($input['ping_timeout']) ? (int)$input['ping_timeout'] : $existing['ping_timeout'];
    $scan_interval = isset($input['scan_interval']) ? (int)$input['scan_interval'] : $existing['scan_interval'];
    $snmp_community = isset($input['snmp_community']) && trim($input['snmp_community']) !== '' ? trim($input['snmp_community']) : ($existing['snmp_community'] ?? 'public');
    $snmp_version = isset($input['snmp_version']) && in_array($input['snmp_version'], ['v1', 'v2c', 'v3']) ? $input['snmp_version'] : ($existing['snmp_version'] ?? 'v2c');
    $snmp_port = isset($input['snmp_port']) && (int)$input['snmp_port'] >= 1 && (int)$input['snmp_port'] <= 65535 ? (int)$input['snmp_port'] : (int)($existing['snmp_port'] ?? 161);
    
    if (isset($input['ports_to_scan'])) {
        $raw_ports = preg_split('/[\s,]+/', trim($input['ports_to_scan']));
        $valid_ports = [];
        foreach ($raw_ports as $p) {
            if (is_numeric($p)) {
                $port_num = (int)$p;
                if ($port_num >= 1 && $port_num <= 65535) {
                    $valid_ports[] = $port_num;
                }
            }
        }
        $valid_ports = array_unique($valid_ports);
        if (empty($valid_ports)) {
            respond('error', 'Invalid port format. Please enter valid port numbers between 1 and 65535.');
        }
        $ports_to_scan = implode(',', $valid_ports);
    } else {
        $ports_to_scan = $existing['ports_to_scan'];
    }
    
    $readonly_password = $input['readonly_password'] ?? null;
    
    $stmt = $db->prepare("UPDATE settings SET site_title = ?, ping_timeout = ?, scan_interval = ?, ports_to_scan = ?, snmp_community = ?, snmp_version = ?, snmp_port = ?");
    $stmt->execute([$site_title, $ping_timeout, $scan_interval, $ports_to_scan, $snmp_community, $snmp_version, $snmp_port]);
    
    if ($readonly_password !== null) {
        if ($readonly_password === '') {
            $db->exec("UPDATE settings SET readonly_password = NULL");
        } else {
            $hash = password_hash($readonly_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE settings SET readonly_password = ?");
            $stmt->execute([$hash]);
        }
    }
    
    respond('success', 'Settings saved successfully');
}

if ($action === 'get_system_info') {
    $db_size = file_exists(__DIR__ . '/../db.sqlite') ? filesize(__DIR__ . '/../db.sqlite') : 0;
    
    $disabled_funcs = array_map('trim', explode(',', ini_get('disable_functions')));
    $exec_enabled = function_exists('exec') && !in_array('exec', $disabled_funcs);
    $proc_open_enabled = function_exists('proc_open') && !in_array('proc_open', $disabled_funcs);

    respond('success', '', [
        'php_version' => phpversion(),
        'os' => php_uname('s') . ' ' . php_uname('r'),
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'db_size' => $db_size,
        'sqlite_version' => $db->query('select sqlite_version()')->fetchColumn(),
        'exec_enabled' => $exec_enabled,
        'proc_open_enabled' => $proc_open_enabled
    ]);
}

if ($action === 'reset_db') {
    try {
        $db->exec("DELETE FROM ip_addresses");
        $db->exec("DELETE FROM subnet_tags");
        $db->exec("DELETE FROM subnets");
        $db->exec("DELETE FROM tags");
        $db->exec("VACUUM"); // Shrink DB file
        respond('success', 'Database reset successfully');
    } catch (Exception $e) {
        respond('error', 'Reset failed: ' . $e->getMessage());
    }
}

if ($action === 'backup_db') {
    $file = __DIR__ . '/../db.sqlite';
    if (!file_exists($file)) respond('error', 'Database not found');
    
    if (ob_get_level()) ob_end_clean();
    header('Content-Description: File Transfer');
    header('Content-Type: application/x-sqlite3');
    header('Content-Disposition: attachment; filename="iproof_backup_' . date('Y-m-d_H-i') . '.sqlite"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
