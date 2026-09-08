<?php
/**
 * 价格保护API
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/price_protection.php';
require_once __DIR__ . '/../includes/config.php';

set_security_headers();
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$protection = new PriceProtection();

// POST需要CSRF验证
if ($method === 'POST') {
    require_csrf();
}

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'run':
            handleRunProtection($protection);
            break;
            
        case 'logs':
            handleGetLogs($protection);
            break;
            
        case 'stats':
            handleGetStats($protection);
            break;
            
        case 'settings':
            if ($method === 'GET') {
                handleGetSettings($db);
            } else {
                handleSaveSettings($db);
            }
            break;
            
        default:
            json_error('未知操作', 400);
    }
} catch (Exception $e) {
    error_log("Price protection API error: " . $e->getMessage());
    json_error('服务器错误: ' . $e->getMessage(), 500);
}

/**
 * 执行一键价保
 */
function handleRunProtection($protection) {
    $result = $protection->oneClickProtection();
    
    if ($result['success']) {
        $protection->updateLastRunTime();
    }
    
    json_success($result, $result['message']);
}

/**
 * 获取价保日志
 */
function handleGetLogs($protection) {
    $limit = intval($_GET['limit'] ?? 50);
    $logs = $protection->getLogs($limit);
    
    json_success(['logs' => $logs]);
}

/**
 * 获取价保统计
 */
function handleGetStats($protection) {
    $stats = $protection->getStats();
    
    json_success($stats);
}

/**
 * 获取价保设置
 */
function handleGetSettings($db) {
    $settings = $db->fetch(
        "SELECT price_protection_enabled, price_protection_interval, price_protection_last_run FROM settings WHERE id = 1"
    );
    
    json_success([
        'enabled' => (bool)($settings['price_protection_enabled'] ?? false),
        'interval' => (int)($settings['price_protection_interval'] ?? 360),
        'last_run' => $settings['price_protection_last_run'] ?? null,
    ]);
}

/**
 * 保存价保设置
 */
function handleSaveSettings($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $enabled = isset($data['enabled']) ? (int)$data['enabled'] : 0;
    $interval = isset($data['interval']) ? max(30, min(10080, (int)$data['interval'])) : 360;
    
    $db->execute(
        "UPDATE settings SET price_protection_enabled = ?, price_protection_interval = ?, updated_at = datetime('now', 'localtime') WHERE id = 1",
        [$enabled, $interval]
    );
    
    json_success(null, '设置已保存');
}
