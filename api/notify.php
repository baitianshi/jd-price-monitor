<?php
/**
 * 通知测试API
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/webhook.php';
require_once __DIR__ . '/../includes/config.php';

set_security_headers();
require_auth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    json_error('请求方法错误', 405);
}

// 需要CSRF验证
require_csrf();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'test';
    
    $webhook = new Webhook();
    
    switch ($action) {
        case 'test':
            // 测试单个Webhook
            $webhookData = $data['webhook'] ?? [];
            if (empty($webhookData['url'])) {
                json_error('请输入Webhook URL');
            }
            
            $result = $webhook->testWebhook($webhookData);
            
            if ($result['success']) {
                json_success($result, 'Webhook测试成功');
            } else {
                json_error('Webhook测试失败: ' . ($result['error'] ?? '未知错误'));
            }
            break;
            
        case 'test_all':
            // 测试所有配置的Webhook
            $result = $webhook->send('test', []);
            json_success($result);
            break;
            
        case 'send':
            // 发送自定义通知
            $type = $data['type'] ?? 'price_update';
            $productId = $data['product_id'] ?? null;
            
            $productData = [];
            if ($productId) {
                $db = Database::getInstance();
                $product = $db->fetch("SELECT * FROM products WHERE id = ?", [$productId]);
                
                if ($product) {
                    $productData = [
                        'product_id' => $productId,
                        'product' => [
                            'name' => $product['name'],
                            'sku_id' => $product['sku_id'],
                            'current_price' => $product['current_price'],
                            'target_price' => $product['target_price'],
                            'lowest_price' => $product['lowest_price'],
                            'url' => "https://item.jd.com/{$product['sku_id']}.html",
                            'created_at' => $product['created_at']
                        ]
                    ];
                }
            }
            
            $result = $webhook->send($type, $productData);
            json_success($result);
            break;
            
        default:
            json_error('未知操作');
    }
} catch (Exception $e) {
    error_log("Notify API error: " . $e->getMessage());
    json_error('服务器错误: ' . $e->getMessage(), 500);
}
