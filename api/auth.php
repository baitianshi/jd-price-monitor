<?php
/**
 * 认证API
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

set_security_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$db = Database::getInstance();

try {
    $auth = new Auth();
    
    switch ($action) {
        case 'login':
            if ($method !== 'POST') {
                json_error('请求方法错误', 405);
            }
            
            // 检查是否被锁定
            if (is_login_locked($db)) {
                $remaining = get_lockout_remaining($db);
                json_error("登录失败次数过多，请 {$remaining} 秒后重试", 429);
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $password = $data['password'] ?? '';
            
            if (empty($password)) {
                json_error('请输入密码');
            }
            
            if ($auth->login($password)) {
                clear_login_failures($db);
                json_success([
                    'message' => '登录成功',
                    'csrf_token' => generate_csrf_token()
                ]);
            } else {
                record_login_failure($db);
                $attempts = LOGIN_MAX_ATTEMPTS - get_login_attempts($db);
                if ($attempts > 0) {
                    json_error("密码错误，还剩 {$attempts} 次尝试机会");
                } else {
                    json_error('密码错误次数过多，账号已被锁定15分钟', 429);
                }
            }
            break;
            
        case 'logout':
            $auth->logout();
            json_success(['message' => '已退出登录']);
            break;
            
        case 'check':
            $isAuth = $auth->check();
            json_success([
                'authenticated' => $isAuth,
                'need_init' => $auth->needInit(),
                'csrf_token' => $isAuth ? generate_csrf_token() : null
            ]);
            break;
            
        case 'change-password':
            if (!$auth->check()) {
                json_error('未授权访问', 401);
            }
            
            require_csrf();
            
            $data = json_decode(file_get_contents('php://input'), true);
            $oldPassword = $data['old_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';
            
            if (empty($oldPassword) || empty($newPassword)) {
                json_error('请填写完整信息');
            }
            
            if (strlen($newPassword) < 6) {
                json_error('新密码至少6位');
            }
            
            $result = $auth->changePassword($oldPassword, $newPassword);
            if ($result['success']) {
                json_success(null, $result['message']);
            } else {
                json_error($result['message']);
            }
            break;
            
        default:
            json_error('未知操作');
    }
} catch (Exception $e) {
    error_log("Auth API error: " . $e->getMessage());
    json_error('服务器错误', 500);
}

function get_login_attempts($db) {
    $result = $db->fetch(
        "SELECT attempts FROM login_attempts WHERE ip_address = ?",
        [get_client_ip()]
    );
    return $result ? intval($result['attempts']) : 0;
}
