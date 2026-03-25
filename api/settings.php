<?php
/**
 * 设置管理API
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/jd.php';
require_once __DIR__ . '/../includes/config.php';

set_security_headers();
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$jd = new JdPrice();

// POST需要CSRF验证
if ($method === 'POST') {
    require_csrf();
}

try {
    switch ($method) {
        case 'GET':
            handleGet($db, $jd);
            break;
            
        case 'POST':
            handlePost($db);
            break;
            
        default:
            json_error('请求方法错误', 405);
    }
} catch (Exception $e) {
    error_log("Settings API error: " . $e->getMessage());
    json_error('服务器错误: ' . $e->getMessage(), 500);
}

/**
 * 获取设置
 */
function handleGet($db, $jd) {
    $settings = $db->fetch("SELECT * FROM settings WHERE id = 1");
    
    if (!$settings) {
        json_error('设置不存在', 404);
    }
    
    // 解析webhooks
    $settings['webhooks_array'] = json_decode($settings['webhooks'] ?? '[]', true);
    
    // 隐藏敏感信息
    unset($settings['access_password']);
    unset($settings['session_secret']);
    
    // 验证Cookie状态
    $cookieStatus = $jd->checkCookieStatus();
    $settings['cookie_status'] = $cookieStatus;
    
    // 获取用户信息
    $userInfo = null;
    if ($cookieStatus === 'valid') {
        $userInfo = $jd->getUserInfo();
    }
    $settings['jd_user'] = $userInfo;
    
    // 更新数据库中的Cookie状态
    $db->execute("UPDATE settings SET cookie_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1", [$cookieStatus]);
    
    $settings['cookie_status_text'] = [
        'valid' => '正常',
        'invalid' => '已失效',
        'not_set' => '未设置',
        'invalid_format' => '格式错误',
        'unknown' => '未知'
    ][$cookieStatus] ?? '未知';
    
    // Cookie状态提示信息
    $settings['cookie_status_hint'] = [
        'valid' => 'Cookie有效，可以正常获取价格',
        'invalid' => 'Cookie已失效，请重新登录京东移动端获取',
        'not_set' => '请先设置京东Cookie',
        'invalid_format' => 'Cookie格式错误，需要包含pt_key和pt_pin',
        'unknown' => '无法验证Cookie状态，建议刷新重试'
    ][$cookieStatus] ?? '未知状态';
    
    // 获取统计数据
    $stats = $db->fetch("
        SELECT 
            COUNT(*) as total_products,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_products,
            SUM(CASE WHEN current_price <= target_price THEN 1 ELSE 0 END) as price_met_products,
            (SELECT COUNT(*) FROM price_history) as total_history
        FROM products
    ");
    
    // 获取所有标签
    $tags = [];
    $products = $db->fetchAll("SELECT DISTINCT tags FROM products WHERE tags IS NOT NULL AND tags != ''");
    foreach ($products as $p) {
        $productTags = explode(',', $p['tags']);
        $tags = array_merge($tags, $productTags);
    }
    $tags = array_unique(array_filter($tags));
    
    // 获取价格获取方法统计
    $methodStats = $jd->getMethodStats();
    
    json_success([
        'settings' => $settings,
        'stats' => $stats,
        'tags' => array_values($tags),
        'method_stats' => $methodStats
    ]);
}

/**
 * 更新设置
 */
function handlePost($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $updateFields = [];
    $updateValues = [];
    
    // 京东Cookie
    if (isset($data['jd_cookies'])) {
        $updateFields[] = "jd_cookies = ?";
        $updateValues[] = $data['jd_cookies'];
        // 重置Cookie状态（使用参数绑定）
        $updateFields[] = "cookie_status = ?";
        $updateValues[] = 'unknown';
    }
    
    // Webhooks
    if (isset($data['webhooks'])) {
        $updateFields[] = "webhooks = ?";
        $updateValues[] = json_encode($data['webhooks']);
    }
    
    // 静默时段
    if (isset($data['silent_start'])) {
        $updateFields[] = "silent_start = ?";
        $updateValues[] = $data['silent_start'];
    }
    if (isset($data['silent_end'])) {
        $updateFields[] = "silent_end = ?";
        $updateValues[] = $data['silent_end'];
    }
    
    // 默认设置
    if (isset($data['default_notify_interval'])) {
        $updateFields[] = "default_notify_interval = ?";
        $updateValues[] = intval($data['default_notify_interval']);
    }
    if (isset($data['default_price_threshold'])) {
        $updateFields[] = "default_price_threshold = ?";
        $updateValues[] = floatval($data['default_price_threshold']);
    }
    
    // Cookie检测间隔
    if (isset($data['cookie_check_interval'])) {
        $updateFields[] = "cookie_check_interval = ?";
        $updateValues[] = intval($data['cookie_check_interval']);
    }
    
    if (empty($updateFields)) {
        json_error('没有需要更新的设置');
    }
    
    $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
    
    $sql = "UPDATE settings SET " . implode(', ', $updateFields) . " WHERE id = 1";
    $db->execute($sql, $updateValues);
    
    json_success(null, '设置已保存');
}
