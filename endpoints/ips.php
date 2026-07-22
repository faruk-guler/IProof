<?php
if ($action === 'get_ips') {
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    if (!$subnet_id) {
        respond('error', 'Invalid Subnet ID');
    }
    
    $subnet_stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
    $subnet_stmt->execute([$subnet_id]);
    $subnet = $subnet_stmt->fetch();
    if (!$subnet) {
        respond('error', 'Subnet not found');
    }
    
    $ip_stmt = $db->prepare("SELECT * FROM ip_addresses WHERE subnet_id = ?");
    $ip_stmt->execute([$subnet_id]);
    $db_ips = $ip_stmt->fetchAll();
    
    $ip_map = [];
    foreach ($db_ips as $db_ip) {
        $ip_map[$db_ip['ip']] = $db_ip;
    }
    
    $ips = [];
    if ($subnet['mask'] >= 22) {
        $range = getIpRange($subnet['subnet'], $subnet['mask']);
        foreach ($range as $ip) {
            if (isset($ip_map[$ip])) {
                $ips[] = $ip_map[$ip];
            } else {
                $ips[] = [
                    'id' => null,
                    'subnet_id' => $subnet_id,
                    'ip' => $ip,
                    'status' => 'free',
                    'hostname' => '',
                    'mac' => '',
                    'ports' => null,
                    'description' => '',
                    'last_seen' => null
                ];
            }
        }
    } else {
        $ips = $db_ips;
    }
    
    $subnet['is_private'] = filter_var($subnet['subnet'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

    respond('success', '', [
        'subnet' => $subnet,
        'ips' => $ips,
        'is_full_range' => ($subnet['mask'] >= 22)
    ]);
}

if ($action === 'save_ip') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = isset($input['id']) ? (int)$input['id'] : null;
    $subnet_id = (int)($input['subnet_id'] ?? 0);
    $ip = trim($input['ip'] ?? '');
    $status = trim($input['status'] ?? 'active');
    $hostname = trim($input['hostname'] ?? '');
    $mac = strtoupper(trim($input['mac'] ?? ''));
    $description = trim($input['description'] ?? '');
    
    if (!empty($mac) && !preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac)) {
        respond('error', 'Invalid MAC Address format');
    }
    
    if (!$subnet_id || empty($ip)) {
        respond('error', 'Subnet ID and IP fields are required');
    }
    
    if (ip2long($ip) === false) {
        respond('error', 'Invalid IP format');
    }
    
    $subnet_stmt = $db->prepare("SELECT subnet, mask FROM subnets WHERE id = ?");
    $subnet_stmt->execute([$subnet_id]);
    $subnet = $subnet_stmt->fetch();
    if (!$subnet) {
        respond('error', 'Subnet not found');
    }
    
    if (!cidr_overlap($ip, 32, $subnet['subnet'], $subnet['mask'])) {
        respond('error', "The IP {$ip} does not belong to subnet {$subnet['subnet']}/{$subnet['mask']}!");
    }
    
    if ($id) {
        $stmt = $db->prepare("UPDATE ip_addresses SET status = ?, hostname = ?, mac = ?, description = ? WHERE id = ?");
        $stmt->execute([$status, $hostname, $mac, $description, $id]);
        respond('success', 'IP address updated');
    } else {
        $check = $db->prepare("SELECT id FROM ip_addresses WHERE subnet_id = ? AND ip = ?");
        $check->execute([$subnet_id, $ip]);
        if ($check->fetch()) {
            $stmt = $db->prepare("UPDATE ip_addresses SET status = ?, hostname = ?, mac = ?, description = ? WHERE subnet_id = ? AND ip = ?");
            $stmt->execute([$status, $hostname, $mac, $description, $subnet_id, $ip]);
            respond('success', 'IP address updated');
        } else {
            $stmt = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip, status, hostname, mac, description) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$subnet_id, $ip, $status, $hostname, $mac, $description]);
            respond('success', 'IP address saved successfully');
        }
    }
}

if ($action === 'delete_ip') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $subnet_id = (int)($input['subnet_id'] ?? 0);
    $ip = trim($input['ip'] ?? '');
    
    if ($id) {
        $stmt = $db->prepare("DELETE FROM ip_addresses WHERE id = ?");
        $stmt->execute([$id]);
        respond('success', 'IP address deleted (released)');
    } elseif ($subnet_id && !empty($ip)) {
        $stmt = $db->prepare("DELETE FROM ip_addresses WHERE subnet_id = ? AND ip = ?");
        $stmt->execute([$subnet_id, $ip]);
        respond('success', 'IP address deleted (released)');
    } else {
        respond('error', 'Invalid parameters');
    }
}

if ($action === 'export_ips') {
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    if (!$subnet_id) {
        respond('error', 'Invalid Subnet ID');
    }
    
    $subnet_stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
    $subnet_stmt->execute([$subnet_id]);
    $subnet = $subnet_stmt->fetch();
    if (!$subnet) {
        respond('error', 'Subnet not found');
    }
    
    $ip_stmt = $db->prepare("SELECT ip, status, hostname, mac, description FROM ip_addresses WHERE subnet_id = ?");
    $ip_stmt->execute([$subnet_id]);
    $ips = $ip_stmt->fetchAll();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=subnet_' . $subnet['subnet'] . '_' . $subnet['mask'] . '.csv');
    
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['IP Address', 'Status', 'Device Name', 'MAC Address', 'Description']);
    
    foreach ($ips as $ip) {
        fputcsv($output, [
            $ip['ip'],
            $ip['status'],
            $ip['hostname'],
            $ip['mac'],
            $ip['description']
        ]);
    }
    fclose($output);
    exit;
}

if ($action === 'import_ips') {
    $subnet_id = (int)($_GET['subnet_id'] ?? 0);
    if (!$subnet_id) respond('error', 'Invalid Subnet ID');
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) respond('error', 'File upload error occurred');
    
    $subnet_stmt = $db->prepare("SELECT * FROM subnets WHERE id = ?");
    $subnet_stmt->execute([$subnet_id]);
    $subnet = $subnet_stmt->fetch();
    if (!$subnet) respond('error', 'Subnet not found');
    
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) respond('error', 'Could not read file');
    
    $firstLine = fgets($handle);
    $delimiter = ',';
    if ($firstLine !== false) {
        $commaCount = substr_count($firstLine, ',');
        $semicolonCount = substr_count($firstLine, ';');
        if ($semicolonCount > $commaCount) $delimiter = ';';
    }
    
    rewind($handle);
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    
    $header = fgetcsv($handle, 1000, $delimiter);
    
    $check = $db->prepare("SELECT id FROM ip_addresses WHERE subnet_id = ? AND ip = ?");
    $insert = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip, status, hostname, mac, description) VALUES (?, ?, ?, ?, ?, ?)");
    $update = $db->prepare("UPDATE ip_addresses SET status = ?, hostname = ?, mac = ?, description = ? WHERE id = ?");
    
    $db->beginTransaction();
    try {
        $imported_count = 0;
        $processed_rows = 0;
        $row_limit = 50000;
        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            $processed_rows++;
            if ($processed_rows > $row_limit) break;
            
            if (empty($row[0])) continue;
            
            $ip = trim($row[0]);
            $status = trim($row[1] ?? 'active');
            $hostname = trim($row[2] ?? '');
            $mac = strtoupper(trim($row[3] ?? ''));
            if (!empty($mac) && !preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $mac)) $mac = '';
            $description = trim($row[4] ?? '');
            
            if (ip2long($ip) === false) continue;
            if (!cidr_overlap($ip, 32, $subnet['subnet'], $subnet['mask'])) continue;
            
            $check->execute([$subnet_id, $ip]);
            $existing = $check->fetch();
            
            if ($existing) {
                $update->execute([$status, $hostname, $mac, $description, $existing['id']]);
            } else {
                $insert->execute([$subnet_id, $ip, $status, $hostname, $mac, $description]);
            }
            $imported_count++;
        }
        fclose($handle);
        $db->commit();
    } catch (Exception $e) {
        fclose($handle);
        $db->rollBack();
        respond('error', 'Import failed: ' . $e->getMessage());
    }
    respond('success', "Total {$imported_count} IP addresses successfully imported.");
}
