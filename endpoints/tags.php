<?php
if ($action === 'get_tags') {
    $stmt = $db->query("SELECT * FROM tags ORDER BY name ASC");
    $tags = $stmt->fetchAll();
    foreach ($tags as &$t) {
        $sub_stmt = $db->prepare("
            SELECT subnets.id, subnets.name, subnets.subnet, subnets.mask 
            FROM subnets 
            JOIN subnet_tags ON subnets.id = subnet_tags.subnet_id 
            WHERE subnet_tags.tag_id = ?
        ");
        $sub_stmt->execute([$t['id']]);
        $t['subnets'] = $sub_stmt->fetchAll();
    }
    respond('success', '', ['tags' => $tags]);
}

if ($action === 'add_tag') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    $color = trim($input['color'] ?? '#3b82f6');
    $description = trim($input['description'] ?? '');
    
    if (empty($name)) {
        respond('error', 'Tag Name is required');
    }
    
    $check = $db->prepare("SELECT id FROM tags WHERE name = ?");
    $check->execute([$name]);
    if ($check->fetch()) {
        respond('error', 'A tag with this name already exists');
    }
    
    $stmt = $db->prepare("INSERT INTO tags (name, color, description) VALUES (?, ?, ?)");
    $stmt->execute([$name, $color, $description]);
    $new_tag_id = (int)$db->lastInsertId();
    
    if (isset($input['subnet_ids']) && is_array($input['subnet_ids'])) {
        $subnet_ids = array_filter(array_map('intval', $input['subnet_ids']));
        $insert_st = $db->prepare("INSERT OR IGNORE INTO subnet_tags (subnet_id, tag_id) VALUES (?, ?)");
        foreach ($subnet_ids as $sid) {
            $insert_st->execute([$sid, $new_tag_id]);
        }
    }
    
    respond('success', 'Tag added successfully');
}

if ($action === 'edit_tag') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $color = trim($input['color'] ?? '#3b82f6');
    $description = trim($input['description'] ?? '');
    
    if (!$id || empty($name)) {
        respond('error', 'Missing required parameters');
    }
    
    $check = $db->prepare("SELECT id FROM tags WHERE name = ? AND id != ?");
    $check->execute([$name, $id]);
    if ($check->fetch()) {
        respond('error', 'A tag with this name already exists');
    }
    
    $stmt = $db->prepare("UPDATE tags SET name = ?, color = ?, description = ? WHERE id = ?");
    $stmt->execute([$name, $color, $description, $id]);
    
    if (isset($input['subnet_ids']) && is_array($input['subnet_ids'])) {
        $stmt_reset = $db->prepare("DELETE FROM subnet_tags WHERE tag_id = ?");
        $stmt_reset->execute([$id]);
        
        $subnet_ids = array_filter(array_map('intval', $input['subnet_ids']));
        if (!empty($subnet_ids)) {
            $insert_st = $db->prepare("INSERT OR IGNORE INTO subnet_tags (subnet_id, tag_id) VALUES (?, ?)");
            foreach ($subnet_ids as $sid) {
                $insert_st->execute([$sid, $id]);
            }
        }
    }
    
    respond('success', 'Tag updated successfully');
}

if ($action === 'delete_tag') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);
    if (!$id) respond('error', 'Invalid ID');
    
    $stmt = $db->prepare("DELETE FROM tags WHERE id = ?");
    $stmt->execute([$id]);
    respond('success', 'Tag deleted successfully');
}
