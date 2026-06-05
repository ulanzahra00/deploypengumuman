<?php
/**
 * ================================================================
 * LOGOUT ENDPOINT
 * Menghapus session dan redirect ke halaman utama
 * ================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// Start session dan destroy semua data
secure_session_start();

// Clear all session data
$_SESSION = [];

// Hapus session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session
session_destroy();

// Log logout activity
error_log('User logged out at ' . date('Y-m-d H:i:s'));

// Redirect ke halaman utama
redirect('index.php');

