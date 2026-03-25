<?php
/**
 * 定时任务入口
 * 
 * 使用方法：
 * 1. 添加到crontab（Linux）：
 *    每5分钟执行: php /path/to/cron.php
 * 
 * 2. Windows任务计划程序：
 *    每5分钟执行一次：php d:\code\jd\cron.php
 * 
 * 3. 手动测试：
 *    php cron.php [task]
 * 
 * 可选参数：
 *    php cron.php update_prices    - 更新所有商品价格
 *    php cron.php check_cookie     - 检查Cookie状态
 *    php cron.php all              - 执行所有任务
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/jd.php';
require_once __DIR__ . '/includes/webhook.php';

// 设置为命令行模式
define('CLI_MODE', true);

// 日志函数
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}

// 获取命令行参数
$task = $argv[1] ?? 'all';

logMessage("=== 京东价格监控定时任务开始 ===");
logMessage("任务: {$task}");

$db = Database::getInstance();
$jd = new JdPrice();
$webhook = new Webhook();

// 1. 更新商品价格
if ($task === 'all' || $task === 'update_prices') {
    logMessage("\n--- 更新商品价格 ---");
    
    $products = $db->fetchAll("SELECT id, sku_id, name, current_price, target_price, lowest_price FROM products WHERE status = 'active'");
    logMessage("需要更新 " . count($products) . " 个商品");
    
    foreach ($products as $product) {
        // 获取完整商品信息（包含原价）
        $productInfo = $jd->getProductInfo($product['sku_id']);
        $price = $productInfo['price'] ?? 0;
        
        if ($price > 0) {
            $oldPrice = floatval($product['current_price']);
            $newPrice = $price;
            $originalPrice = $productInfo['original_price'] ?? $newPrice;
            
            // 更新当前价格和原价
            $db->execute(
                "UPDATE products SET current_price = ?, original_price = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$newPrice, $originalPrice, $product['id']]
            );
            
            // 更新历史最低价
            if ($newPrice < floatval($product['lowest_price'])) {
                $db->execute("UPDATE products SET lowest_price = ? WHERE id = ?", [$newPrice, $product['id']]);
            }
            
            // 记录价格历史
            $db->execute(
                "INSERT INTO price_history (product_id, price, stock_status) VALUES (?, ?, 'unknown')",
                [$product['id'], $newPrice]
            );
            
            // 检查是否达到目标价格
            if ($newPrice <= floatval($product['target_price']) && $oldPrice > floatval($product['target_price'])) {
                logMessage("  [降价提醒] {$product['name']} - ¥{$newPrice} <= 目标价 ¥{$product['target_price']}");
                
                // 发送通知
                $webhook->send('price_drop', [
                    'product_id' => $product['id'],
                    'product' => [
                        'name' => $product['name'],
                        'sku_id' => $product['sku_id'],
                        'current_price' => $newPrice,
                        'original_price' => $originalPrice,
                        'target_price' => $product['target_price'],
                        'lowest_price' => min($newPrice, floatval($product['lowest_price'])),
                        'url' => "https://item.jd.com/{$product['sku_id']}.html"
                    ],
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice
                ]);
            }
            
            logMessage("  [{$product['id']}] {$product['sku_id']}: ¥{$oldPrice} -> ¥{$newPrice} (原价: ¥{$originalPrice})");
        } else {
            logMessage("  [{$product['id']}] {$product['sku_id']}: 获取价格失败");
        }
        
        // 随机延迟3-5秒，避免请求过快
        $delay = rand(3000000, 5000000);
        usleep($delay);
    }
}

// 2. 检查Cookie状态
if ($task === 'all' || $task === 'check_cookie') {
    logMessage("\n--- 检查Cookie状态 ---");
    
    $settings = $db->fetch("SELECT jd_cookies, cookie_status, cookie_checked_at, cookie_check_interval FROM settings WHERE id = 1");
    
    if (!empty($settings['jd_cookies'])) {
        $checkInterval = intval($settings['cookie_check_interval'] ?? 360);
        $lastCheck = $settings['cookie_checked_at'];
        
        // 检查是否需要检测
        $needCheck = true;
        if ($lastCheck) {
            $lastCheckTime = strtotime($lastCheck);
            $nextCheckTime = $lastCheckTime + ($checkInterval * 60);
            if (time() < $nextCheckTime) {
                $needCheck = false;
                logMessage("距下次检测还有 " . round(($nextCheckTime - time()) / 60) . " 分钟，跳过");
            }
        }
        
        if ($needCheck) {
            // 检测Cookie有效性
            $isValid = $jd->checkCookie();
            $newStatus = $isValid ? 'valid' : 'invalid';
            
            $db->execute(
                "UPDATE settings SET cookie_status = ?, cookie_checked_at = CURRENT_TIMESTAMP WHERE id = 1",
                [$newStatus]
            );
            
            logMessage("Cookie状态: {$newStatus}");
            
            // 如果Cookie失效且之前是有效的，发送通知
            if (!$isValid && $settings['cookie_status'] === 'valid') {
                logMessage("Cookie已失效，发送通知...");
                
                $webhook->send('cookie_expired', [
                    'message' => '京东Cookie已失效，请重新登录获取新的Cookie',
                    'checked_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    } else {
        logMessage("未设置Cookie");
    }
}

logMessage("\n=== 定时任务完成 ===\n");
