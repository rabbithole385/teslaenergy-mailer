<?php
/**
 * Tesla Energy Foundation Mailer — Configuration
 * Dual-Gateway Bulk Sending Engine (Resend Paid API + Google Workspace Paid SMTP)
 */

// Helper to read environment variables (with fallback)
if (!function_exists('env')) {
    function env(string $k, $default = '') {
        $v = getenv($k);
        return ($v === false || $v === '') ? $default : $v;
    }
}

// Behind Railway, Cloudflare, or reverse proxies, trust HTTPS
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS'])
    ]);
    session_start();
}

date_default_timezone_set('America/New_York');

// Identity & Domain Specifications
define('APP_NAME', 'Tesla Energy Foundation Mailer');
define('APP_VERSION', '2.0.0');
define('SENDING_DOMAIN', env('SENDING_DOMAIN', 'teslaenergyfoundation.com'));
define('REPLY_DOMAIN', env('REPLY_DOMAIN', 'teslafoundationreward.com'));
define('DEFAULT_REPLY_TO', env('DEFAULT_REPLY_TO', 'support@teslafoundationreward.com'));
define('DEFAULT_FROM_NAME', env('DEFAULT_FROM_NAME', 'Tesla Energy Foundation'));
define('DEFAULT_FROM_EMAIL', env('DEFAULT_FROM_EMAIL', 'grants@teslaenergyfoundation.com'));

// Database Directory & Path
define('DATA_DIR', rtrim(env('DATA_DIR', __DIR__ . '/../data'), '/\\'));
define('DB_PATH', DATA_DIR . '/tesla_mailer.sqlite');

// Default Admin Credentials (can be updated from dashboard)
define('DEFAULT_ADMIN_USER', 'admin');
define('DEFAULT_ADMIN_EMAIL', 'admin@teslaenergyfoundation.com');
define('DEFAULT_ADMIN_PASS', 'TeslaEnergy2026!');
