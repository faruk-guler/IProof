<?php
if (!function_exists('is_subnet_descendant')) {
    function is_subnet_descendant($db, $child_id, $parent_id) {
        if (!$child_id || !$parent_id) return false;
        $curr = $child_id;
        while ($curr !== null) {
            if ((int)$curr === (int)$parent_id) return true;
            $stmt = $db->prepare("SELECT parent_id FROM subnets WHERE id = ?");
            $stmt->execute([$curr]);
            $row = $stmt->fetch();
            $curr = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
        }
        return false;
    }
}

if ($action === 'get_subnets') {
    $stmt = $db->query("
        SELECT subnets.*, tags.name as tag_name, tags.color as tag_color,
               COUNT(ip_addresses.id) as total_ips,
               SUM(CASE WHEN ip_addresses.status='active' THEN 1 ELSE 0 END) as active_ips
        FROM subnets 
        LEFT JOIN tags ON subnets.tag_id = tags.id 
        LEFT JOIN ip_addresses ON subnets.id = ip_addresses.subnet_id
        GROUP BY subnets.id
        ORDER BY subnets.subnet ASC
    ");
    $subnets = $stmt->fetchAll();
    
    foreach ($subnets as &$s) {
        $s['total_ips'] = (int)$s['total_ips'];
        $s['active_ips'] = (int)($s['active_ips'] ?? 0);
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
    
    respond('success', '', ['subnets' => $subnets]);
}

if ($action === 'add_subnet') {
    $input = json_decode(file_get_contents('php://input'), true);
    $subnet = trim($input['subnet'] ?? '');
    $mask = (int)($input['mask'] ?? 24);
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $tag_id = (isset($input['tag_id']) && $input['tag_id'] !== '') ? (int)$input['tag_id'] : null;
    $parent_id = (isset($input['parent_id']) && $input['parent_id'] !== '') ? (int)$input['parent_id'] : null;
    $vrf = trim($input['vrf'] ?? '');
    
    if (empty($subnet) || empty($name)) respond('error', 'Subnet and Name fields are required');
    if (ip2long($subnet) === false) respond('error', 'Invalid IP format');
    if ($mask < 1 || $mask > 32) respond('error', 'Mask value must be between 1 and 32');
    
    $ip_long = ip2long($subnet);
    $wildcard = pow(2, 32 - $mask) - 1;
    $net_mask = ~ $wildcard;
    $subnet = long2ip($ip_long & $net_mask);
    
    if ($parent_id) {
        $parent = $db->prepare("SELECT subnet, mask FROM subnets WHERE id = ?");
        $parent->execute([$parent_id]);
        $p = $parent->fetch();
        if (!$p) respond('error', 'Parent subnet not found');
        if (!cidr_overlap($subnet, $mask, $p['subnet'], $p['mask']) || $mask <= $p['mask']) {
            respond('error', "Subnet must be strictly contained within its Master Subnet ({$p['subnet']}/{$p['mask']})");
        }
    }
    
    $check_stmt = $db->query("SELECT id, name, subnet, mask, parent_id FROM subnets");
    $existing_subnets = $check_stmt->fetchAll();
    foreach ($existing_subnets as $ex_sub) {
        if ($subnet === $ex_sub['subnet'] && $mask === (int)$ex_sub['mask']) {
            respond('error', "This subnet identically conflicts with existing '{$ex_sub['name']}' subnet!");
        }
        if (cidr_overlap($subnet, $mask, $ex_sub['subnet'], $ex_sub['mask'])) {
            if ($mask > (int)$ex_sub['mask']) {
                $is_ancestor = ($parent_id === (int)$ex_sub['id']) || is_subnet_descendant($db, $parent_id, $ex_sub['id']);
                if (!$is_ancestor) {
                    respond('error', "Subnet overlaps with '{$ex_sub['name']}' ({$ex_sub['subnet']}/{$ex_sub['mask']}). Assign a Master Subnet if intended.");
                }
            }
        }
    }
    
    $stmt = $db->prepare("INSERT INTO subnets (subnet, mask, name, description, tag_id, parent_id, vrf) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$subnet, $mask, $name, $description, $tag_id, $parent_id, $vrf]);
    $new_subnet_id = (int)$db->lastInsertId();
    if ($tag_id) {
        $db->prepare("INSERT OR IGNORE INTO subnet_tags (subnet_id, tag_id) VALUES (?, ?)")->execute([$new_subnet_id, $tag_id]);
    }
    
    respond('success', 'Subnet added successfully');
}

if ($action === 'edit_subnet') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $subnet = trim($input['subnet'] ?? '');
    $mask = (int)($input['mask'] ?? 24);
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $tag_id = (isset($input['tag_id']) && $input['tag_id'] !== '') ? (int)$input['tag_id'] : null;
    $parent_id = (isset($input['parent_id']) && $input['parent_id'] !== '') ? (int)$input['parent_id'] : null;
    $vrf = trim($input['vrf'] ?? '');
    
    if (!$id || empty($subnet) || empty($name)) respond('error', 'Missing required parameters');
    if ($parent_id === $id) respond('error', 'Subnet cannot be its own parent');
    if (ip2long($subnet) === false) respond('error', 'Invalid IP format');
    if ($mask < 1 || $mask > 32) respond('error', 'Mask value must be between 1 and 32');
    
    $ip_long = ip2long($subnet);
    $wildcard = pow(2, 32 - $mask) - 1;
    $net_mask = ~ $wildcard;
    $subnet = long2ip($ip_long & $net_mask);
    
    if ($parent_id) {
        $current_parent = $parent_id;
        while ($current_parent !== null) {
            if ((int)$current_parent === $id) {
                respond('error', 'Cyclic inheritance detected: Setting this parent would create an infinite loop.');
            }
            $p_stmt = $db->prepare("SELECT parent_id FROM subnets WHERE id = ?");
            $p_stmt->execute([$current_parent]);
            $p_row = $p_stmt->fetch();
            $current_parent = $p_row && $p_row['parent_id'] !== null ? (int)$p_row['parent_id'] : null;
        }

        $parent = $db->prepare("SELECT subnet, mask FROM subnets WHERE id = ?");
        $parent->execute([$parent_id]);
        $p = $parent->fetch();
        if (!$p) respond('error', 'Parent subnet not found');
        if (!cidr_overlap($subnet, $mask, $p['subnet'], $p['mask']) || $mask <= $p['mask']) {
            respond('error', "Subnet must be strictly contained within its Master Subnet ({$p['subnet']}/{$p['mask']})");
        }
    }
    
    $check_stmt = $db->prepare("SELECT id, name, subnet, mask, parent_id FROM subnets WHERE id != ?");
    $check_stmt->execute([$id]);
    $existing_subnets = $check_stmt->fetchAll();
    foreach ($existing_subnets as $ex_sub) {
        if ($subnet === $ex_sub['subnet'] && $mask === (int)$ex_sub['mask']) {
            respond('error', "This subnet identically conflicts with existing '{$ex_sub['name']}' subnet!");
        }
        if (cidr_overlap($subnet, $mask, $ex_sub['subnet'], $ex_sub['mask'])) {
            if ($mask > (int)$ex_sub['mask']) {
                $is_ancestor = ($parent_id === (int)$ex_sub['id']) || is_subnet_descendant($db, $parent_id, $ex_sub['id']);
                if (!$is_ancestor) {
                    respond('error', "Subnet overlaps with '{$ex_sub['name']}' ({$ex_sub['subnet']}/{$ex_sub['mask']}). Assign a Master Subnet if intended.");
                }
            } else {
                // We are broader, so ex_sub must be our descendant
                $is_descendant = is_subnet_descendant($db, $ex_sub['id'], $id);
                if (!$is_descendant) {
                    respond('error', "Subnet overlaps with '{$ex_sub['name']}' ({$ex_sub['subnet']}/{$ex_sub['mask']}). Assign a Master Subnet if intended.");
                }
            }
        }
    }
    
    // Check if existing child subnets are still strictly contained within the updated range
    $children_stmt = $db->prepare("SELECT id, name, subnet, mask FROM subnets WHERE parent_id = ?");
    $children_stmt->execute([$id]);
    $children = $children_stmt->fetchAll();
    foreach ($children as $child) {
        if (!cidr_overlap($subnet, $mask, $child['subnet'], $child['mask']) || (int)$child['mask'] <= $mask) {
            respond('error', "Child subnet '{$child['name']}' ({$child['subnet']}/{$child['mask']}) would not be contained within the new subnet range.");
        }
    }
    
    // Remove IP addresses that fall outside the modified subnet range
    $ip_check = $db->prepare("SELECT id, ip FROM ip_addresses WHERE subnet_id = ?");
    $ip_check->execute([$id]);
    $existing_ips = $ip_check->fetchAll();
    foreach ($existing_ips as $e_ip) {
        if (!cidr_overlap($e_ip['ip'], 32, $subnet, $mask)) {
            $db->prepare("DELETE FROM ip_addresses WHERE id = ?")->execute([$e_ip['id']]);
        }
    }
    
    $stmt = $db->prepare("UPDATE subnets SET subnet = ?, mask = ?, name = ?, description = ?, tag_id = ?, parent_id = ?, vrf = ? WHERE id = ?");
    $stmt->execute([$subnet, $mask, $name, $description, $tag_id, $parent_id, $vrf, $id]);
    
    $db->prepare("DELETE FROM subnet_tags WHERE subnet_id = ?")->execute([$id]);
    if ($tag_id) {
        $db->prepare("INSERT OR IGNORE INTO subnet_tags (subnet_id, tag_id) VALUES (?, ?)")->execute([$id, $tag_id]);
    }
    
    respond('success', 'Subnet updated successfully');
}

if ($action === 'delete_subnet') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    
    if (!$id) {
        respond('error', 'Invalid ID');
    }
    
    $stmt = $db->prepare("DELETE FROM ip_addresses WHERE subnet_id = ?");
    $stmt->execute([$id]);
    
    $stmt = $db->prepare("DELETE FROM subnets WHERE id = ?");
    $stmt->execute([$id]);
    
    respond('success', 'Subnet deleted successfully');
}
