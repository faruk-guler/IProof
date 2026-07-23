<?php
$db_file = __DIR__ . '/../db.sqlite';
$db_exists = file_exists($db_file);

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Safely attempt journal mode & foreign keys setup
    try {
        $db->exec("PRAGMA journal_mode=WAL;");
    } catch (\Throwable $e) {
        // Fallback gracefully if database or filesystem is read-only
    }

    try {
        $db->exec("PRAGMA foreign_keys = ON;");
    } catch (\Throwable $e) {
        // Ignore if unsupported or read-only
    }
    
    if (!$db_exists) {
        try {
            // Create tables fresh
            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                site_title TEXT NOT NULL,
                admin_password TEXT NOT NULL,
                readonly_password TEXT DEFAULT NULL,
                ping_timeout INTEGER DEFAULT 1,
                scan_interval INTEGER DEFAULT 1,
                last_scan_time TEXT DEFAULT NULL,
                ports_to_scan TEXT DEFAULT '22,80,443,3389',
                snmp_community TEXT DEFAULT 'public',
                snmp_version TEXT DEFAULT 'v2c',
                snmp_port INTEGER DEFAULT 161
            )");
            
            $db->exec("CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                color TEXT DEFAULT '#3b82f6',
                description TEXT
            )");
            
            $db->exec("CREATE TABLE IF NOT EXISTS subnets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subnet TEXT NOT NULL,
                mask INTEGER NOT NULL,
                name TEXT NOT NULL,
                description TEXT,
                tag_id INTEGER DEFAULT NULL,
                parent_id INTEGER DEFAULT NULL,
                vrf TEXT,
                FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE SET NULL,
                FOREIGN KEY(parent_id) REFERENCES subnets(id) ON DELETE SET NULL
            )");
            
            $db->exec("CREATE TABLE IF NOT EXISTS ip_addresses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subnet_id INTEGER NOT NULL,
                ip TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                hostname TEXT,
                mac TEXT,
                ports TEXT DEFAULT NULL,
                description TEXT,
                last_seen DATETIME,
                FOREIGN KEY(subnet_id) REFERENCES subnets(id) ON DELETE CASCADE
            )");
            
            $db->exec("CREATE TABLE IF NOT EXISTS subnet_tags (
                subnet_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY(subnet_id, tag_id),
                FOREIGN KEY(subnet_id) REFERENCES subnets(id) ON DELETE CASCADE,
                FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
            )");
            
            // Insert default settings with hashed 'admin' and 'readonly' passwords
            $default_password = password_hash('admin', PASSWORD_DEFAULT);
            $default_readonly = password_hash('readonly', PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO settings (site_title, admin_password, readonly_password) VALUES (?, ?, ?)");
            $stmt->execute(['IProof', $default_password, $default_readonly]);
        } catch (\Throwable $e) {
            // DB initial creation failed if read-only
        }
    } else {
        // -----------------------------------------------
        // Safely run migrations for existing installations
        // -----------------------------------------------
        try {
            // settings table migrations
            $settings_cols = $db->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('scan_interval', $settings_cols)) {
                $db->exec("ALTER TABLE settings ADD COLUMN scan_interval INTEGER DEFAULT 1");
            }
            if (!in_array('last_scan_time', $settings_cols)) {
                $db->exec("ALTER TABLE settings ADD COLUMN last_scan_time TEXT DEFAULT NULL");
            }
            if (!in_array('readonly_password', $settings_cols)) {
                $db->exec("ALTER TABLE settings ADD COLUMN readonly_password TEXT DEFAULT NULL");
                $default_readonly = password_hash('readonly', PASSWORD_DEFAULT);
                $stmt_r = $db->prepare("UPDATE settings SET readonly_password = ?");
                $stmt_r->execute([$default_readonly]);
            }
            if (!in_array('ports_to_scan', $settings_cols)) {
                $db->exec("ALTER TABLE settings ADD COLUMN ports_to_scan TEXT DEFAULT '22,80,443,3389'");
            }
            if (!in_array('snmp_community', $settings_cols)) {
                $db->exec("ALTER TABLE settings ADD COLUMN snmp_community TEXT DEFAULT 'public'");
            }
            if (!in_array('snmp_version', $settings_cols)) {
                $db->exec("ALTER TABLE settings ADD COLUMN snmp_version TEXT DEFAULT 'v2c'");
            }
            if (!in_array('snmp_port', $settings_cols)) {
                $db->exec("ALTER TABLE settings ADD COLUMN snmp_port INTEGER DEFAULT 161");
            }

            // ip_addresses table migrations
            $ip_cols = $db->query("PRAGMA table_info(ip_addresses)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('mac', $ip_cols)) {
                $db->exec("ALTER TABLE ip_addresses ADD COLUMN mac TEXT");
            }
            if (!in_array('ports', $ip_cols)) {
                $db->exec("ALTER TABLE ip_addresses ADD COLUMN ports TEXT DEFAULT NULL");
            }

            // subnets table migrations
            $subnets_cols = $db->query("PRAGMA table_info(subnets)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('tag_id', $subnets_cols)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN tag_id INTEGER DEFAULT NULL");
            }
            if (!in_array('parent_id', $subnets_cols)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN parent_id INTEGER DEFAULT NULL");
            }
            if (!in_array('vrf', $subnets_cols)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN vrf TEXT");
            }

            // Create tags table if it does not exist
            $db->exec("CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                color TEXT DEFAULT '#3b82f6',
                description TEXT
            )");

            // Run migration: Convert old VLANs data to Tags if 'vlans' table exists
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('vlans', $tables)) {
                $old_vlans = $db->query("SELECT * FROM vlans")->fetchAll();
                if (!empty($old_vlans)) {
                    $stmt_insert = $db->prepare("INSERT OR IGNORE INTO tags (id, name, color, description) VALUES (?, ?, ?, ?)");
                    $colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#6b7280'];
                    foreach ($old_vlans as $index => $v) {
                        $color = $colors[$index % count($colors)];
                        $tag_name = !empty($v['name']) ? $v['name'] : "VLAN " . $v['vlan_number'];
                        $stmt_insert->execute([$v['id'], $tag_name, $color, $v['description']]);
                    }
                }
            }

            // Create subnet_tags junction table
            $db->exec("CREATE TABLE IF NOT EXISTS subnet_tags (
                subnet_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY(subnet_id, tag_id),
                FOREIGN KEY(subnet_id) REFERENCES subnets(id) ON DELETE CASCADE,
                FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
            )");

            // Migration: Copy existing subnets.tag_id into subnet_tags
            $db->exec("INSERT OR IGNORE INTO subnet_tags (subnet_id, tag_id) SELECT id, tag_id FROM subnets WHERE tag_id IS NOT NULL");
        } catch (\Throwable $e) {
            // Silently ignore migration write failures when file is read-only
        }
    }

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}
