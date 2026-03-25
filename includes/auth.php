<?php
/**
 * 认证类
 */

require_once __DIR__ . '/db.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        
        // 配置安全的Session Cookie
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                   (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        // 设置Session Cookie参数
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,  // 仅HTTPS下启用
            'httponly' => true,    // 防止JavaScript访问
            'samesite' => 'Strict' // 防止CSRF
        ]);
        
        session_name(SESSION_NAME);
        session_start();
    }
    
    /**
     * 登录验证
     */
    public function login($password) {
        $settings = $this->db->fetch("SELECT access_password FROM settings WHERE id = 1");
        
        if (!$settings || !verify_password($password, $settings['access_password'])) {
            return false;
        }
        
        // 创建Session
        $token = generate_token(32);
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        
        $this->db->execute(
            "INSERT INTO sessions (token, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?)",
            [$token, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $expiresAt]
        );
        
        $_SESSION['token'] = $token;
        $_SESSION['expires'] = $expiresAt;
        
        return true;
    }
    
    /**
     * 检查登录状态
     */
    public function check() {
        if (empty($_SESSION['token'])) {
            return false;
        }
        
        // 清理过期Session
        $this->db->execute("DELETE FROM sessions WHERE expires_at < datetime('now')");
        
        // 验证Token
        $session = $this->db->fetch(
            "SELECT * FROM sessions WHERE token = ? AND expires_at > datetime('now')",
            [$_SESSION['token']]
        );
        
        if (!$session) {
            $this->logout();
            return false;
        }
        
        return true;
    }
    
    /**
     * 登出
     */
    public function logout() {
        if (!empty($_SESSION['token'])) {
            $this->db->execute("DELETE FROM sessions WHERE token = ?", [$_SESSION['token']]);
        }
        session_destroy();
        $_SESSION = [];
    }
    
    /**
     * 修改密码
     */
    public function changePassword($oldPassword, $newPassword) {
        $settings = $this->db->fetch("SELECT access_password FROM settings WHERE id = 1");
        
        if (!verify_password($oldPassword, $settings['access_password'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }
        
        $newHash = hash_password($newPassword);
        $this->db->execute("UPDATE settings SET access_password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1", [$newHash]);
        
        return ['success' => true, 'message' => '密码修改成功'];
    }
    
    /**
     * 重置密码（首次使用）
     */
    public function resetPassword($newPassword) {
        $newHash = hash_password($newPassword);
        $this->db->execute("UPDATE settings SET access_password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1", [$newHash]);
        return true;
    }
    
    /**
     * 是否需要初始化（首次使用）
     */
    public function needInit() {
        $settings = $this->db->fetch("SELECT access_password FROM settings WHERE id = 1");
        // 默认密码是 admin123
        return $settings && verify_password('admin123', $settings['access_password']);
    }
}

/**
 * 验证API请求
 */
function require_auth() {
    $auth = new Auth();
    if (!$auth->check()) {
        json_error('未授权访问，请先登录', 401);
    }
    return $auth;
}
