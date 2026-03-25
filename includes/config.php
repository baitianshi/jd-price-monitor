<?php
/**
 * 系统配置文件
 */

// 数据库路径
define('DB_PATH', __DIR__ . '/../data/monitor.db');

// Session配置
define('SESSION_NAME', 'jd_monitor_session');
define('SESSION_LIFETIME', 86400 * 7); // 7天

// 安全配置
define('CSRF_TOKEN_NAME', 'csrf_token');
define('LOGIN_MAX_ATTEMPTS', 5); // 最大登录尝试次数
define('LOGIN_LOCKOUT_TIME', 900); // 锁定时间（秒），15分钟

// 默认配置
define('DEFAULT_NOTIFY_INTERVAL', 60); // 默认通知间隔（分钟）
define('DEFAULT_CHECK_INTERVAL', 5); // 默认检查间隔（分钟）
define('PRICE_CHANGE_THRESHOLD', 5); // 价格波动阈值（%）

// 京东API
define('JD_MOBILE_API', 'https://api.m.jd.com/');
define('JD_PC_ITEM_URL', 'https://item.jd.com/');

// 时区
date_default_timezone_set('Asia/Shanghai');

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../data/error.log');

/**
 * 设置安全响应头
 */
function set_security_headers() {
    // 防止点击劫持
    header('X-Frame-Options: SAMEORIGIN');
    
    // 防止MIME类型嗅探
    header('X-Content-Type-Options: nosniff');
    
    // XSS保护
    header('X-XSS-Protection: 1; mode=block');
    
    // 引用策略
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // 内容安全策略（允许内联脚本和样式，因为使用了CDN）
    header("Content-Security-Policy: default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://unpkg.com; " .
           "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; " .
           "img-src 'self' data: https:; " .
           "font-src 'self' https://cdn.jsdelivr.net; " .
           "connect-src 'self'; " .
           "frame-ancestors 'self';");
    
    // 权限策略
    header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
}

/**
 * JSON响应
 */
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 成功响应
 */
function json_success($data = null, $message = 'success') {
    json_response([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * 错误响应
 */
function json_error($message, $code = 400) {
    json_response([
        'success' => false,
        'message' => $message
    ], $code);
}

/**
 * 安全过滤
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * 生成随机字符串
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * 密码哈希
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * 验证密码
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * 生成CSRF Token
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token(32);
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证CSRF Token
 */
function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 检查并验证CSRF（用于API）
 */
function require_csrf() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$token && isset($data['csrf_token'])) {
        $token = $data['csrf_token'];
    }
    
    if (!verify_csrf_token($token)) {
        json_error('安全验证失败，请刷新页面重试', 403);
    }
}

/**
 * 获取客户端IP
 */
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/**
 * 获取登录限流键
 */
function get_login_rate_limit_key() {
    return 'login_attempts_' . get_client_ip();
}

/**
 * 检查登录是否被锁定
 */
function is_login_locked($db) {
    $key = get_login_rate_limit_key();
    $result = $db->fetch(
        "SELECT attempts, lockout_until FROM login_attempts WHERE ip_address = ?",
        [get_client_ip()]
    );
    
    if (!$result) {
        return false;
    }
    
    if ($result['lockout_until'] && strtotime($result['lockout_until']) > time()) {
        return true;
    }
    
    return false;
}

/**
 * 记录登录失败
 */
function record_login_failure($db) {
    $ip = get_client_ip();
    $result = $db->fetch(
        "SELECT id, attempts FROM login_attempts WHERE ip_address = ?",
        [$ip]
    );
    
    if ($result) {
        $newAttempts = $result['attempts'] + 1;
        if ($newAttempts >= LOGIN_MAX_ATTEMPTS) {
            $db->execute(
                "UPDATE login_attempts SET attempts = ?, lockout_until = datetime('now', '+15 minutes'), last_attempt = CURRENT_TIMESTAMP WHERE ip_address = ?",
                [$newAttempts, $ip]
            );
        } else {
            $db->execute(
                "UPDATE login_attempts SET attempts = ?, last_attempt = CURRENT_TIMESTAMP WHERE ip_address = ?",
                [$newAttempts, $ip]
            );
        }
    } else {
        $db->execute(
            "INSERT INTO login_attempts (ip_address, attempts, last_attempt) VALUES (?, 1, CURRENT_TIMESTAMP)",
            [$ip]
        );
    }
}

/**
 * 清除登录失败记录
 */
function clear_login_failures($db) {
    $db->execute(
        "DELETE FROM login_attempts WHERE ip_address = ?",
        [get_client_ip()]
    );
}

/**
 * 获取剩余锁定时间
 */
function get_lockout_remaining($db) {
    $result = $db->fetch(
        "SELECT lockout_until FROM login_attempts WHERE ip_address = ?",
        [get_client_ip()]
    );
    
    if ($result && $result['lockout_until']) {
        $remaining = strtotime($result['lockout_until']) - time();
        return max(0, $remaining);
    }
    
    return 0;
}
