<?php
/**
 * 数据库操作类
 */

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            // 确保data目录存在
            $dataDir = dirname(DB_PATH);
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            
            $this->pdo = new PDO(
                'sqlite:' . DB_PATH,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            $this->initTables();
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("数据库连接失败");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * 初始化数据表
     */
    private function initTables() {
        $sql = "
        -- 商品监控表
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku_id TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            image_url TEXT,
            current_price REAL DEFAULT 0,
            original_price REAL DEFAULT 0,
            target_price REAL DEFAULT 0,
            lowest_price REAL DEFAULT 0,
            highest_price REAL DEFAULT 0,
            notify_interval INTEGER DEFAULT 60,
            price_change_threshold REAL DEFAULT 5,
            last_notified_at DATETIME,
            last_checked_at DATETIME,
            status TEXT DEFAULT 'active',
            stock_status TEXT DEFAULT 'unknown',
            tags TEXT,
            notify_price_drop INTEGER DEFAULT 1,
            notify_price_update INTEGER DEFAULT 1,
            notify_lowest INTEGER DEFAULT 1,
            notify_oos INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- 价格历史表
        CREATE TABLE IF NOT EXISTS price_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            price REAL NOT NULL,
            stock_status TEXT,
            recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        );
        
        -- 系统配置表
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            access_password TEXT,
            session_secret TEXT,
            jd_cookies TEXT,
            cookie_status TEXT DEFAULT 'unknown',
            cookie_checked_at DATETIME,
            webhooks TEXT DEFAULT '[]',
            silent_start TEXT DEFAULT '23:00',
            silent_end TEXT DEFAULT '07:00',
            default_notify_interval INTEGER DEFAULT 60,
            default_price_threshold REAL DEFAULT 5,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- Session表
        CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT UNIQUE NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- 通知日志表
        CREATE TABLE IF NOT EXISTS notification_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            type TEXT NOT NULL,
            message TEXT,
            webhook_url TEXT,
            success INTEGER DEFAULT 0,
            response TEXT,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
        );
        
        -- 创建索引
        CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku_id);
        CREATE INDEX IF NOT EXISTS idx_products_status ON products(status);
        CREATE INDEX IF NOT EXISTS idx_price_history_product ON price_history(product_id);
        CREATE INDEX IF NOT EXISTS idx_price_history_time ON price_history(recorded_at);
        CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);
        CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);
        
        -- 登录失败记录表
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL UNIQUE,
            attempts INTEGER DEFAULT 0,
            lockout_until DATETIME,
            last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        -- 价格获取方法统计表
        CREATE TABLE IF NOT EXISTS price_method_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            method TEXT UNIQUE NOT NULL,
            success_count INTEGER DEFAULT 0,
            total_count INTEGER DEFAULT 0,
            total_time REAL DEFAULT 0,
            last_success_at DATETIME,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        ";
        
        $this->pdo->exec($sql);
        
        // 初始化默认设置
        $this->initDefaultSettings();
        
        // 数据库迁移 - 添加缺失的字段
        $this->runMigrations();
    }
    
    /**
     * 运行数据库迁移
     */
    private function runMigrations() {
        // 检查并添加original_price字段
        $columns = $this->pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC);
        $hasOriginalPrice = false;
        $hasStockNum = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'original_price') {
                $hasOriginalPrice = true;
            }
            if ($column['name'] === 'stock_num') {
                $hasStockNum = true;
            }
        }
        
        if (!$hasOriginalPrice) {
            $this->pdo->exec("ALTER TABLE products ADD COLUMN original_price REAL DEFAULT 0");
        }
        
        if (!$hasStockNum) {
            $this->pdo->exec("ALTER TABLE products ADD COLUMN stock_num INTEGER");
        }
    }
    
    /**
     * 初始化默认设置
     */
    private function initDefaultSettings() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM settings");
        if ($stmt->fetchColumn() == 0) {
            $defaultPassword = hash_password('admin123');
            $sessionSecret = generate_token(32);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO settings (id, access_password, session_secret)
                VALUES (1, ?, ?)
            ");
            $stmt->execute([$defaultPassword, $sessionSecret]);
        }
    }
    
    /**
     * 查询单行
     */
    public function fetch($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    /**
     * 查询多行
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * 执行SQL
     */
    public function execute($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * 获取最后插入ID
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * 开始事务
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * 提交事务
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * 回滚事务
     */
    public function rollBack() {
        return $this->pdo->rollBack();
    }
}
