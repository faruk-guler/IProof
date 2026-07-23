<?php
/**
 * IProof IPAM - Automated Subnet Scanner
 * This script is designed to run via cron (Linux) or Task Scheduler (Windows).
 * It should only be executed from the CLI.
 */

if (php_sapi_name() !== 'cli') {
    die("Error: This script can only be run from the command line.\n");
}

$lockFile = __DIR__ . '/cron_scan.lock';
$lockHandle = fopen($lockFile, 'w+');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    die("[" . date('Y-m-d H:i:s') . "] Another scan instance is already running. Exiting.\n");
}

// Ensure lock is always released, even on early exit() or fatal error
register_shutdown_function(function() use (&$lockHandle) {
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});

$start_time = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Starting automated subnet scan...\n";

// Load DB and Ping utilities
require_once __DIR__ . '/functions/db.php';
require_once __DIR__ . '/functions/ping.php';

try {
    // Check scan interval settings first
    $stmt_settings = $db->query("SELECT scan_interval, last_scan_time, ports_to_scan FROM settings LIMIT 1");
    $settings = $stmt_settings->fetch();
    
    if (!$settings || (int)$settings['scan_interval'] === 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Automated scan is disabled in settings. Exiting.\n";
        exit(0);
    }
    
    $ports_str = $settings['ports_to_scan'] ?? '22,80,443,3389';
    $ports = array_filter(array_map('intval', explode(',', $ports_str)));
    
    if (!empty($settings['last_scan_time'])) {
        $last_scan = strtotime($settings['last_scan_time']);
        $elapsed_minutes = (time() - $last_scan) / 60;
        $interval = (int)$settings['scan_interval'];
        
        if ($elapsed_minutes < $interval) {
            $wait_left = round($interval - $elapsed_minutes, 1);
            echo "[" . date('Y-m-d H:i:s') . "] Throttled: Last scan was completed " . round($elapsed_minutes, 1) . " minutes ago. Interval is {$interval} minutes. Waiting {$wait_left} minutes. Exiting.\n";
            exit(0);
        }
    }

    // Update last scan start time in DB to avoid retry loops on crash
    $stmt_update_time = $db->prepare("UPDATE settings SET last_scan_time = ?");
    $stmt_update_time->execute([date('Y-m-d H:i:s')]);

    // Fetch all subnets from the database
    $stmt = $db->query("SELECT * FROM subnets ORDER BY subnet ASC");
    $subnets = $stmt->fetchAll();

    if (empty($subnets)) {
        echo "[" . date('Y-m-d H:i:s') . "] No subnets found in the database. Exiting.\n";
        exit(0);
    }

    echo "[" . date('Y-m-d H:i:s') . "] Found " . count($subnets) . " subnets to scan.\n";

    // Prepare SQL statements for database updates
    $stmt_check = $db->prepare("SELECT id, status, hostname FROM ip_addresses WHERE subnet_id = ? AND ip = ?");
    $stmt_update = $db->prepare("UPDATE ip_addresses SET status = ?, last_seen = ?, ports = ? WHERE id = ?");
    $stmt_update_host = $db->prepare("UPDATE ip_addresses SET hostname = ? WHERE id = ?");
    $stmt_insert = $db->prepare("INSERT INTO ip_addresses (subnet_id, ip, status, hostname, last_seen, ports, description) VALUES (?, ?, ?, ?, ?, ?, 'Auto Discovered')");

    foreach ($subnets as $subnet) {
        // Skip subnets larger than /23 to avoid running too long
        if ($subnet['mask'] < 23) {
            echo "[" . date('Y-m-d H:i:s') . "] Skipping subnet '{$subnet['name']}' ({$subnet['subnet']}/{$subnet['mask']}) - size too large (exceeds /23 limit).\n";
            continue;
        }

        $is_private = filter_var($subnet['subnet'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        if (!$is_private) {
            echo "[" . date('Y-m-d H:i:s') . "] Skipping subnet '{$subnet['name']}' ({$subnet['subnet']}/{$subnet['mask']}) - Public IP range (manual IPAM only).\n";
            continue;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Scanning subnet '{$subnet['name']}' ({$subnet['subnet']}/{$subnet['mask']})...\n";

        // Get IP Range
        $ips = getIpRange($subnet['subnet'], $subnet['mask']);
        if (empty($ips)) {
            echo "[" . date('Y-m-d H:i:s') . "] Failed to calculate IP range for {$subnet['subnet']}/{$subnet['mask']}. Skipping.\n";
            continue;
        }

        // Parallel ping scan (uses 32 concurrent processes)
        $ping_results = pingMultiple($ips);
        $now = date('Y-m-d H:i:s');
        
        // Gather online IPs for port scanning
        $online_ips = [];
        foreach ($ping_results as $ip => $online) {
            if ($online) {
                $online_ips[] = $ip;
            }
        }
        
        $port_results = [];
        if (!empty($ips) && !empty($ports)) {
            $port_results = scanPortsMultiple($ips, $ports, 350);
        }
        
        $db->beginTransaction();
        try {
            $updated_count = 0;
            $discovered_count = 0;

            foreach ($ips as $ip) {
                $ping_online = !empty($ping_results[$ip]);
                $open_ports_arr = $port_results[$ip] ?? [];
                $port_online = !empty($open_ports_arr);

                $online = ($ping_online || $port_online);
                $open_ports = $port_online ? implode(',', $open_ports_arr) : null;

                $stmt_check->execute([$subnet['id'], $ip]);
                $row = $stmt_check->fetch();

                $new_status = $online ? 'active' : 'offline';

                if ($row) {
                    // Preserve reserved status - don't change it during cron scan
                    if ($row['status'] === 'reserved') {
                        if ($online) {
                            // Only update last_seen and ports for reserved IPs
                            $stmt_update->execute(['reserved', $now, $open_ports, $row['id']]);
                        }
                        continue;
                    }
                    // Update existing IP record
                    $last_seen_val = $online ? $now : ($row['last_seen'] ?? null);
                    $stmt_update->execute([$new_status, $last_seen_val, $open_ports, $row['id']]);
                    if ($online && empty($row['hostname'])) {
                        $hostname = getDeviceHostname($ip);
                        if (!empty($hostname)) {
                            $stmt_update_host->execute([$hostname, $row['id']]);
                        }
                    }
                    $updated_count++;
                } else {
                    // Insert new IP if active (auto-discovery)
                    if ($online) {
                        $hostname = getDeviceHostname($ip);
                        $stmt_insert->execute([$subnet['id'], $ip, 'active', $hostname, $now, $open_ports]);
                        $discovered_count++;
                    }
                }
            }
            $db->commit();
            echo "[" . date('Y-m-d H:i:s') . "] Subnet scan completed. Updated: {$updated_count}, Discovered: {$discovered_count}.\n";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo "[" . date('Y-m-d H:i:s') . "] Database transaction error in subnet {$subnet['subnet']}: " . $e->getMessage() . "\n";
        }
    }

    // Update last scan time in DB
    $stmt_update_time = $db->prepare("UPDATE settings SET last_scan_time = ?");
    $stmt_update_time->execute([date('Y-m-d H:i:s')]);

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Critical Scan Error: " . $e->getMessage() . "\n";
    exit(1);
}

$end_time = microtime(true);
$duration = round($end_time - $start_time, 2);
echo "[" . date('Y-m-d H:i:s') . "] Automated scan finished in {$duration} seconds.\n";

// Release lock on normal exit (shutdown function serves as safety net for crashes/early exits)
if (is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    $lockHandle = null;
}
