<?php
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/functions/db.php';
require_once __DIR__ . '/functions/ping.php';

$action = $_GET['action'] ?? '';

// Helper function to return JSON and exit
function respond($status, $message, $data = []) {
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $data));
    exit;
}

// CIDR Overlap Check Helper
function cidr_overlap($subnet1, $mask1, $subnet2, $mask2) {
    $ip1 = ip2long($subnet1);
    $ip2 = ip2long($subnet2);
    if ($ip1 === false || $ip2 === false) return false;
    
    $min_mask = min((int)$mask1, (int)$mask2);
    if ($min_mask < 0 || $min_mask > 32) return false;

    $host_bits = 32 - $min_mask;
    $wildcard = ($host_bits === 32) ? 0xFFFFFFFF : ((1 << $host_bits) - 1);
    $net_mask = ($host_bits === 32) ? 0 : (~$wildcard & 0xFFFFFFFF);
    
    $u1 = (int)sprintf('%u', $ip1);
    $u2 = (int)sprintf('%u', $ip2);
    
    return ($u1 & $net_mask) === ($u2 & $net_mask);
}

// Public Auth actions
if (in_array($action, ['login', 'logout', 'check_auth'])) {
    require_once __DIR__ . '/endpoints/auth.php';
    exit;
}

// All other actions require authentication
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'readonly'])) {
    http_response_code(401);
    respond('error', 'Unauthorized access');
}

// CSRF Protection for state-modifying actions
$modifying_actions = [
    'add_subnet', 'edit_subnet', 'delete_subnet',
    'save_ip', 'delete_ip', 'scan_subnet', 'scan_ip_ports',
    'import_ips', 'snmp_discover', 'change_password',
    'reset_db', 'save_settings',
    'add_tag', 'edit_tag', 'delete_tag'
];

// backup_db requires admin role but uses GET link (cannot send CSRF in POST body)
if ($action === 'backup_db' && $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    respond('error', 'Unauthorized action (Read-Only account)');
}

if (in_array($action, $modifying_actions)) {
    // Modify actions require Admin role
    if ($_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        respond('error', 'Unauthorized action (Read-Only account)');
    }

    $client_token = '';
    if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } else {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-CSRF-Token') === 0) {
                $client_token = $value;
                break;
            }
        }
    }
    if (empty($client_token)) {
        $client_token = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    }
    $server_token = $_SESSION['csrf_token'] ?? '';
    if (empty($server_token) || !hash_equals($server_token, $client_token)) {
        http_response_code(403);
        respond('error', 'Invalid or missing CSRF token');
    }
}

// Route to modules
$routes = [
    'change_password' => 'auth.php',
    
    'get_tags' => 'tags.php',
    'add_tag' => 'tags.php',
    'edit_tag' => 'tags.php',
    'delete_tag' => 'tags.php',
    
    'get_subnets' => 'subnets.php',
    'add_subnet' => 'subnets.php',
    'edit_subnet' => 'subnets.php',
    'delete_subnet' => 'subnets.php',
    
    'get_ips' => 'ips.php',
    'save_ip' => 'ips.php',
    'delete_ip' => 'ips.php',
    'import_ips' => 'ips.php',
    'export_ips' => 'ips.php',
    
    'ping_ip' => 'network.php',
    'scan_subnet' => 'network.php',
    'scan_ip_ports' => 'network.php',
    'snmp_discover' => 'network.php',
    'get_next_free_ip' => 'network.php',
    'get_my_ip_info' => 'network.php',
    'search' => 'network.php',
    
    'get_stats' => 'system.php',
    'get_settings' => 'system.php',
    'save_settings' => 'system.php',
    'get_system_info' => 'system.php',
    'backup_db' => 'system.php',
    'reset_db' => 'system.php'
];

if (isset($routes[$action])) {
    try {
        require_once __DIR__ . '/endpoints/' . $routes[$action];
    } catch (Throwable $e) {
        error_log("API Error [{$action}]: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        http_response_code(500);
        respond('error', 'A system error occurred while processing the request. Check server logs.');
    }
} else {
    respond('error', 'Invalid operation');
}
