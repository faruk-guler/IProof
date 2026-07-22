<?php
if ($action === 'ping_ip') {
    $ip = trim($_GET['ip'] ?? '');
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    if (empty($ip) || ip2long($ip) === false) {
        respond('error', 'Invalid IP Address');
    }
    
    $is_online = pingHost($ip);
    
    if ($subnet_id) {
        $now = date('Y-m-d H:i:s');
        $check = $db->prepare("SELECT id, status FROM ip_addresses WHERE ip = ? AND subnet_id = ?");
        $check->execute([$ip, $subnet_id]);
        $existing = $check->fetch();

        if ($is_online) {
            if ($existing) {
                if ($existing['status'] !== 'reserved') {
                    $stmt = $db->prepare("UPDATE ip_addresses SET status = 'active', last_seen = ? WHERE id = ?");
                    $stmt->execute([$now, $existing['id']]);
                }
            } else {
                $stmt = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip, status, last_seen) VALUES (?, ?, 'active', ?)");
                $stmt->execute([$subnet_id, $ip, $now]);
            }
        } else {
            if ($existing && $existing['status'] !== 'reserved') {
                $stmt = $db->prepare("UPDATE ip_addresses SET status = 'offline' WHERE id = ?");
                $stmt->execute([$existing['id']]);
            }
        }
    }
    
    respond('success', '', ['online' => $is_online]);
}

if ($action === 'scan_ip_ports') {
    $ip = trim($_GET['ip'] ?? '');
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    if (empty($ip) || ip2long($ip) === false) {
        respond('error', 'Invalid IP Address');
    }
    
    $settings_stmt = $db->query("SELECT ports_to_scan FROM settings LIMIT 1");
    $settings = $settings_stmt->fetch();
    $ports_str = $settings['ports_to_scan'] ?? '22,80,443,3389';
    $ports = array_filter(array_map('intval', explode(',', $ports_str)));
    
    $results = scanPortsMultiple([$ip], $ports);
    $open_ports = !empty($results[$ip]) ? implode(',', $results[$ip]) : null;
    $now = date('Y-m-d H:i:s');

    if ($subnet_id) {
        $check = $db->prepare("SELECT id FROM ip_addresses WHERE ip = ? AND subnet_id = ?");
        $check->execute([$ip, $subnet_id]);
        $existing = $check->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE ip_addresses SET ports = ?, last_seen = ? WHERE id = ?");
            $stmt->execute([$open_ports, $now, $existing['id']]);
        } else if ($open_ports) {
            $stmt = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip, status, ports, last_seen) VALUES (?, ?, 'active', ?, ?)");
            $stmt->execute([$subnet_id, $ip, $open_ports, $now]);
        }
    } else {
        $stmt = $db->prepare("UPDATE ip_addresses SET ports = ?, last_seen = ? WHERE ip = ?");
        $stmt->execute([$open_ports, $now, $ip]);
    }
    
    respond('success', 'Port scan complete', [
        'ip' => $ip,
        'open_ports' => $open_ports ? explode(',', $open_ports) : []
    ]);
}

if ($action === 'scan_subnet') {
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    if (!$subnet_id) respond('error', 'Invalid Subnet ID');
    
    $subnet_stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
    $subnet_stmt->execute([$subnet_id]);
    $subnet = $subnet_stmt->fetch();
    if (!$subnet) respond('error', 'Subnet not found');
    
    $is_private = filter_var($subnet['subnet'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if (!$is_private) respond('error', 'Public subnets cannot be scanned. They are strictly for manual IP management.');
    
    $ip_range = getIpRange($subnet['subnet'], $subnet['mask']);
    if (empty($ip_range)) respond('error', 'Could not generate IP range');
    
    $ping_results = pingMultiple($ip_range);
    
    // Load ports to scan
    $settings_stmt = $db->query("SELECT ports_to_scan FROM settings LIMIT 1");
    $settings = $settings_stmt->fetch();
    $ports_str = $settings['ports_to_scan'] ?? '22,80,443,3389';
    $ports = array_filter(array_map('intval', explode(',', $ports_str)));
    
    // Port scan all IPs in the subnet (discovers hosts that block ICMP Ping like Cloudflare / Firewalls)
    $port_results = [];
    if (!empty($ip_range) && !empty($ports)) {
        $port_results = scanPortsMultiple($ip_range, $ports);
    }
    
    $ip_stmt = $db->prepare("SELECT * FROM ip_addresses WHERE subnet_id = ?");
    $ip_stmt->execute([$subnet_id]);
    $existing_ips = [];
    while ($row = $ip_stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_ips[$row['ip']] = $row;
    }
    
    $stmt_update = $db->prepare("UPDATE ip_addresses SET status = ?, last_seen = ?, ports = ?, hostname = ? WHERE id = ?");
    $stmt_insert = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip, status, hostname, last_seen, ports) VALUES (?, ?, ?, ?, ?, ?)");
    
    $db->beginTransaction();
    $updated_count = 0;
    $discovered_count = 0;
    $now = date('Y-m-d H:i:s');
    
    try {
        foreach ($ip_range as $ip) {
            $ping_online = !empty($ping_results[$ip]);
            $open_ports_arr = $port_results[$ip] ?? [];
            $port_online = !empty($open_ports_arr);
            
            $online = ($ping_online || $port_online);
            $open_ports = $port_online ? implode(',', $open_ports_arr) : null;
            
            $row = $existing_ips[$ip] ?? null;
            $new_status = $online ? 'active' : 'offline';
            
            if ($row) {
                if ($row['status'] === 'reserved') {
                    if ($online) {
                        $stmt_update_reserved = $db->prepare("UPDATE ip_addresses SET last_seen = ?, ports = ? WHERE id = ?");
                        $stmt_update_reserved->execute([$now, $open_ports, $row['id']]);
                    }
                    continue; // Do not touch reserved IP status
                }
                if ($row['status'] !== $new_status || $online) {
                    $last_seen_val = $online ? $now : ($row['last_seen'] ?? null);
                    $existing_host = $row['hostname'] ?? '';
                    if (preg_match('/(Apache|Debian|Default Page|Nginx|Welcome|Test)/i', $existing_host)) {
                        $existing_host = '';
                    }
                    $hostname = $online ? (empty($existing_host) ? getDeviceHostname($ip) : $existing_host) : $existing_host;
                    $stmt_update->execute([$new_status, $last_seen_val, $open_ports, $hostname, $row['id']]);
                    $updated_count++;
                }
            } else {
                if ($online) {
                    $hostname = getDeviceHostname($ip);
                    $stmt_insert->execute([$subnet_id, $ip, 'active', $hostname, $now, $open_ports]);
                    $discovered_count++;
                }
            }
        }
        $db->commit();
        respond('success', "Scan complete. Updated: $updated_count, Discovered: $discovered_count", [
            'updated' => $updated_count,
            'discovered' => $discovered_count
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        respond('error', 'Database error during scan: ' . $e->getMessage());
    }
}

if ($action === 'snmp_discover') {
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    $router_ip = trim($_GET['router_ip'] ?? '');
    $community = trim($_GET['community'] ?? 'public');
    
    if (!$subnet_id) respond('error', 'Invalid Subnet ID');
    if (empty($router_ip) || ip2long($router_ip) === false) respond('error', 'Invalid Router IP Address');
    if (!function_exists('snmp2_real_walk') && !function_exists('snmprealwalk')) {
        respond('error', 'PHP SNMP extension is not installed or lacks walk functions');
    }
    
    $subnet_stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
    $subnet_stmt->execute([$subnet_id]);
    $subnet = $subnet_stmt->fetch();
    if (!$subnet) respond('error', 'Subnet not found');
    
    $is_private = filter_var($subnet['subnet'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if (!$is_private) respond('error', 'Public subnets cannot be scanned. They are strictly for manual IP management.');
    
    
    // Retrieve ARP table from Router/Gateway via SNMP (ipNetToMediaPhysAddress)
    snmp_set_oid_numeric_print(true);
    snmp_set_quick_print(true);
    snmp_set_enum_print(true);
    
    $arp_raw = @snmp2_real_walk($router_ip, $community, "1.3.6.1.2.1.4.22.1.2", 1500000, 1);
    if ($arp_raw === false) {
        $arp_raw = @snmprealwalk($router_ip, $community, "1.3.6.1.2.1.4.22.1.2", 1500000, 1);
    }
    
    if ($arp_raw === false) {
        respond('error', 'Could not query router via SNMP. Verify Router IP, SNMP Community, or route.');
    }
    
    $arp_table = [];
    foreach ($arp_raw as $oid => $val) {
        $oid = ltrim($oid, '.');
        if (strpos($oid, '1.3.6.1.2.1.4.22.1.2') === 0) {
            $parts = explode('.', $oid);
            $ip_parts = array_slice($parts, -4);
            $ip = implode('.', $ip_parts);
            
            if (ip2long($ip) !== false) {
                $mac = trim($val);
                if (strpos($mac, 'Hex-STRING:') !== false) {
                    $mac = str_replace('Hex-STRING:', '', $mac);
                }
                $mac = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
                if (strlen($mac) === 12) {
                    $mac = implode(':', str_split(strtoupper($mac), 2));
                    $arp_table[$ip] = $mac;
                }
            }
        }
    }
    
    $ip_stmt = $db->prepare("SELECT ip, id, status, hostname FROM ip_addresses WHERE subnet_id = ?");
    $ip_stmt->execute([$subnet_id]);
    $existing_ips = [];
    while ($row = $ip_stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_ips[$row['ip']] = $row;
    }
    
    $stmt_update_reserved = $db->prepare("UPDATE ip_addresses SET mac = ?, hostname = ?, last_seen = ? WHERE id = ?");
    $stmt_update_active = $db->prepare("UPDATE ip_addresses SET mac = ?, hostname = ?, status = 'active', last_seen = ? WHERE id = ?");
    $stmt_insert = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip, hostname, status, mac, last_seen, description) VALUES (?, ?, ?, 'active', ?, ?, 'SNMP Discovered')");
    
    $db->beginTransaction();
    $updated_count = 0;
    $discovered_count = 0;
    $now = date('Y-m-d H:i:s');
    
    try {
        foreach ($arp_table as $ip => $mac) {
            if (!cidr_overlap($ip, 32, $subnet['subnet'], $subnet['mask'])) {
                continue;
            }
            
            $device_host = getDeviceHostname($ip, $community);
            
            if (isset($existing_ips[$ip])) {
                $ex = $existing_ips[$ip];
                $final_host = !empty($device_host) ? $device_host : ($ex['hostname'] ?? '');
                if ($ex['status'] === 'reserved') {
                    $stmt_update_reserved->execute([$mac, $final_host, $now, $ex['id']]);
                } else {
                    $stmt_update_active->execute([$mac, $final_host, $now, $ex['id']]);
                }
                $updated_count++;
            } else {
                $stmt_insert->execute([$subnet_id, $ip, $device_host, $mac, $now]);
                $discovered_count++;
            }
        }
        $db->commit();
        respond('success', "SNMP Discovery Complete. Updated: $updated_count, Discovered: $discovered_count", [
            'updated' => $updated_count,
            'discovered' => $discovered_count
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        respond('error', 'Database error during SNMP discovery: ' . $e->getMessage());
    }
}

if ($action === 'get_next_free_ip') {
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    if (!$subnet_id) respond('error', 'Invalid Subnet ID');
    
    $subnet_stmt = $db->prepare("SELECT subnet, mask FROM subnets WHERE id = ?");
    $subnet_stmt->execute([$subnet_id]);
    $subnet = $subnet_stmt->fetch();
    if (!$subnet) respond('error', 'Subnet not found');
    
    $range = getIpRange($subnet['subnet'], $subnet['mask']);
    
    $ip_stmt = $db->prepare("SELECT ip FROM ip_addresses WHERE subnet_id = ?");
    $ip_stmt->execute([$subnet_id]);
    $used_ips = $ip_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $used_map = array_flip($used_ips);
    
    foreach ($range as $ip) {
        if (!isset($used_map[$ip])) {
            respond('success', '', ['ip' => $ip]);
        }
    }
    
    respond('error', 'No free IP addresses available in this subnet');
}

if ($action === 'get_my_ip_info') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    respond('success', '', [
        'ip' => $ip,
        'hostname' => gethostbyaddr($ip)
    ]);
}

if ($action === 'search') {
    $q = trim($_GET['q'] ?? '');
    if (empty($q)) {
        respond('success', '', ['subnets' => [], 'ips' => []]);
    }
    
    $like = "%$q%";
    
    $sub_stmt = $db->prepare("
        SELECT DISTINCT subnets.* 
        FROM subnets 
        LEFT JOIN subnet_tags ON subnets.id = subnet_tags.subnet_id
        LEFT JOIN tags ON subnet_tags.tag_id = tags.id OR subnets.tag_id = tags.id
        WHERE subnets.name LIKE ? OR subnets.description LIKE ? OR subnets.subnet LIKE ? OR subnets.vrf LIKE ? OR tags.name LIKE ?
    ");
    $sub_stmt->execute([$like, $like, $like, $like, $like]);
    $subnets = $sub_stmt->fetchAll();
    
    foreach ($subnets as &$s) {
        $count_stmt = $db->prepare("SELECT COUNT(*) as total FROM ip_addresses WHERE subnet_id = ?");
        $count_stmt->execute([$s['id']]);
        $s['total_ips'] = $count_stmt->fetchColumn();
        $s['is_private'] = filter_var($s['subnet'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        
        $t_stmt = $db->prepare("
            SELECT tags.id, tags.name, tags.color, tags.description 
            FROM tags 
            JOIN subnet_tags ON tags.id = subnet_tags.tag_id 
            WHERE subnet_tags.subnet_id = ?
        ");
        $t_stmt->execute([$s['id']]);
        $s['all_tags'] = $t_stmt->fetchAll();
    }
    
    $ip_stmt = $db->prepare("
        SELECT ip_addresses.*, subnets.name as subnet_name, subnets.subnet as subnet_ip, subnets.mask as subnet_mask
        FROM ip_addresses 
        JOIN subnets ON ip_addresses.subnet_id = subnets.id 
        WHERE ip_addresses.ip LIKE ? OR ip_addresses.hostname LIKE ? OR ip_addresses.description LIKE ? OR ip_addresses.mac LIKE ?
    ");
    $ip_stmt->execute([$like, $like, $like, $like]);
    $ips = $ip_stmt->fetchAll();
    
    respond('success', '', [
        'subnets' => $subnets,
        'ips' => $ips
    ]);
}
