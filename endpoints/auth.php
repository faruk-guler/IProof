<?php

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond('error', 'Invalid request method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? 'admin');
    $password = $input['password'] ?? '';
    
    if (empty($password)) {
        respond('error', 'Password cannot be empty');
    }
    
    $stmt = $db->query("SELECT admin_password, readonly_password FROM settings LIMIT 1");
    $settings = $stmt->fetch();
    
    if (!$settings) {
        respond('error', 'Database settings are not initialized');
    }
    
    if ($username === 'admin') {
        if (password_verify($password, $settings['admin_password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['user_role'] = 'admin';
            $_SESSION['username'] = 'admin';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            respond('success', 'Login successful', [
                'csrf_token' => $_SESSION['csrf_token'],
                'role' => 'admin',
                'username' => 'admin'
            ]);
        } else {
            respond('error', 'Incorrect password');
        }
    } else if ($username === 'readonly') {
        $readonly_hash = $settings['readonly_password'] ?: password_hash('readonly', PASSWORD_DEFAULT);
        if (password_verify($password, $readonly_hash)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['user_role'] = 'readonly';
            $_SESSION['username'] = 'readonly';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            respond('success', 'Login successful', [
                'csrf_token' => $_SESSION['csrf_token'],
                'role' => 'readonly',
                'username' => 'readonly'
            ]);
        } else {
            respond('error', 'Incorrect password');
        }
    } else {
        respond('error', 'Invalid username');
    }
}

if ($action === 'logout') {
    unset($_SESSION['admin_logged_in']);
    unset($_SESSION['user_role']);
    unset($_SESSION['username']);
    unset($_SESSION['csrf_token']);
    session_destroy();
    respond('success', 'Logged out');
}

if ($action === 'check_auth') {
    $logged_in = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'readonly']);
    $csrf_token = $logged_in ? ($_SESSION['csrf_token'] ?? '') : '';
    if ($logged_in && empty($csrf_token)) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $csrf_token = $_SESSION['csrf_token'];
    }
    respond('success', '', [
        'logged_in' => $logged_in, 
        'csrf_token' => $csrf_token,
        'role' => $_SESSION['user_role'] ?? 'guest',
        'username' => $_SESSION['username'] ?? ''
    ]);
}

if ($action === 'change_password') {
    $input = json_decode(file_get_contents('php://input'), true);
    $old_password = $input['old_password'] ?? '';
    $new_password = $input['new_password'] ?? '';
    
    if (empty($old_password) || empty($new_password)) {
        respond('error', 'All fields are required');
    }
    
    $role = $_SESSION['user_role'] ?? 'admin';
    $column = ($role === 'readonly') ? 'readonly_password' : 'admin_password';
    
    $stmt = $db->query("SELECT admin_password, readonly_password FROM settings LIMIT 1");
    $settings = $stmt->fetch();
    
    $current_hash = $settings[$column] ?? '';
    if (empty($current_hash) && $role === 'readonly') {
        $current_hash = password_hash('readonly', PASSWORD_DEFAULT);
    }
    
    if ($settings && password_verify($old_password, $current_hash)) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $db->prepare("UPDATE settings SET {$column} = ?");
        $update->execute([$new_hash]);
        respond('success', 'Password updated successfully');
    } else {
        respond('error', 'Current password incorrect');
    }
}
