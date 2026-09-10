<?php
/**
 * 批量任务入口（手动执行）
 * 
 * 注意：日常自动调度请使用 scheduler.php（每分钟运行）
 * 本脚本主要用于手动批量操作或一次性全量刷新
 * 
 * 使用方法：
 *    php cron.php update_prices    - 批量更新所有商品价格
 *    php cron.php check_cookie     - 检查Cookie状态
 *    php cron.php price_protection - 执行价格保护
 *    php cron.php all              - 执行所有任务
 * 
 * 推荐使用 scheduler.php 进行日常自动调度
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/jd.php';
require_once __DIR__ . '/includes/webhook.php';
require_once __DIR__ . '/includes/price_protection.php';

// 设置为命令行模式
define('CLI_MODE', true);

// 日志函数
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[{$timestamp}] {$message}\n";
    echo $log;
    
    $logDir = __DIR__ . '/data';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/cron.log', $log, FILE_APPEND);
}

function isInSilentPeriod($db) {
    $settings = $db->fetch("SELECT silent_start, silent_end FROM settings WHERE id = 1");
    $start = $settings['silent_start'] ?? '';
    $end = $settings['silent_end'] ?? '';

    if (empty($start) || empty($end)) {
        return false;
    }

    $now = date('H:i');
    $start = substr($start, 0, 5);
    $end = substr($end, 0, 5);

    if ($start > $end) {
        return ($now >= $start || $now < $end);
    }

    return ($now >= $start && $now < $end);
}

// 获取命令行参数
$task = $argv[1] ?? 'all';

logMessage("=== 京东价格监控定时任务开始 ===");
logMessage("任务: {$task}");

$db = Database::getInstance();
$jd = new JdPrice();
$webhook = new Webhook();

$inSilent = isInSilentPeriod($db);
if ($inSilent) {
    $settings = $db->fetch("SELECT silent_start, silent_end FROM settings WHERE id = 1");
    logMessage("当前处于静默时段（{$settings['silent_start']} - {$settings['silent_end']}），停止执行所有京东请求任务");
    logMessage("如需强制执行，请在设置页面关闭静默时段或调整时间");
    logMessage("\n=== 定时任务完成 ===\n");
    exit;
}

// 1. 更新商品价格
if ($task === 'all' || $task === 'update_prices') {
    logMessage("\n--- 更新商品价格 ---");
    
    $products = $db->fetchAll("SELECT id, sku_id, name, current_price, target_price, lowest_price, highest_price, stock_status, notify_price_drop, notify_lowest, notify_oos, notify_price_surge FROM products WHERE status = 'active'");
    logMessage("需要更新 " . count($products) . " 个商品");
    
    foreach ($products as $product) {
        $oldPrice = floatval($product['current_price']);
        $oldStockStatus = $product['stock_status'];
        
        $productInfo = $jd->getProductInfo($product['sku_id']);
        $newPrice = $productInfo['price'] ?? 0;
        $newStockStatus = $productInfo['stock_status'] ?? $oldStockStatus;
        $newStockNum = $productInfo['stock_num'] ?? null;
        $originalPrice = $productInfo['original_price'] ?? $newPrice;
        $plusPrice = $productInfo['plus_price'] ?? 0;
        
        if ($newPrice > 0) {
            $lowestPrice = floatval($product['lowest_price']);
            $highestPrice = floatval($product['highest_price']);
            
            if ($newPrice < $lowestPrice || $lowestPrice == 0) {
                $lowestPrice = $newPrice;
            }
            if ($newPrice > $highestPrice) {
                $highestPrice = $newPrice;
            }
            
            $db->execute(
                "UPDATE products SET 
                    current_price = ?, 
                    original_price = ?, 
                    plus_price = ?,
                    lowest_price = ?,
                    highest_price = ?,
                    stock_status = ?,
                    stock_num = ?,
                    last_checked_at = datetime('now', 'localtime'),
                    updated_at = datetime('now', 'localtime') 
                WHERE id = ?",
                [$newPrice, $originalPrice, $plusPrice, $lowestPrice, $highestPrice, $newStockStatus, $newStockNum, $product['id']]
            );
            
            $db->execute(
                "INSERT INTO price_history (product_id, price, stock_status, recorded_at) VALUES (?, ?, ?, datetime('now', 'localtime'))",
                [$product['id'], $newPrice, $newStockStatus]
            );
            
            if ($newPrice <= floatval($product['target_price']) && $oldPrice > floatval($product['target_price'])) {
                logMessage("  [降价提醒] {$product['name']} - ¥{$newPrice} <= 目标价 ¥{$product['target_price']}");
                
                $nextCheckTime = date('Y-m-d H:i:s', strtotime('+' . rand(30, 60) . ' minutes'));
                $webhook->send('price_drop', [
                    'product_id' => $product['id'],
                    'product' => [
                        'name' => $product['name'],
                        'sku_id' => $product['sku_id'],
                        'current_price' => $newPrice,
                        'original_price' => $originalPrice,
                        'target_price' => $product['target_price'],
                        'lowest_price' => $lowestPrice,
                        'url' => "https://item.jd.com/{$product['sku_id']}.html"
                    ],
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                    'next_check_time' => $nextCheckTime
                ]);
            }
            
            if ($newPrice == $lowestPrice && $newPrice < $oldPrice && !empty($product['notify_lowest'])) {
                logMessage("  [最低价提醒] {$product['name']} - ¥{$newPrice}");
                $webhook->send('lowest_price', [
                    'product_id' => $product['id'],
                    'product' => [
                        'name' => $product['name'],
                        'sku_id' => $product['sku_id'],
                        'current_price' => $newPrice,
                        'original_price' => $originalPrice,
                        'target_price' => $product['target_price'],
                        'lowest_price' => $lowestPrice,
                        'url' => "https://item.jd.com/{$product['sku_id']}.html"
                    ],
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice
                ]);
            }
            
            if ($oldStockStatus != 'out_of_stock' && $newStockStatus == 'out_of_stock') {
                logMessage("  [缺货提醒] {$product['name']}");
                $nextCheckTime = date('Y-m-d H:i:s', strtotime('+' . rand(30, 60) . ' minutes'));
                $webhook->send('out_of_stock', [
                    'product_id' => $product['id'],
                    'product' => [
                        'name' => $product['name'],
                        'sku_id' => $product['sku_id'],
                        'current_price' => $newPrice,
                        'url' => "https://item.jd.com/{$product['sku_id']}.html"
                    ],
                    'next_check_time' => $nextCheckTime
                ]);
            }
            
            if ($oldStockStatus == 'out_of_stock' && $newStockStatus != 'out_of_stock') {
                logMessage("  [恢复上架提醒] {$product['name']}");
                $webhook->send('back_in_stock', [
                    'product_id' => $product['id'],
                    'product' => [
                        'name' => $product['name'],
                        'sku_id' => $product['sku_id'],
                        'current_price' => $newPrice,
                        'url' => "https://item.jd.com/{$product['sku_id']}.html"
                    ],
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice
                ]);
            }
            
            logMessage("  [{$product['id']}] {$product['sku_id']}: ¥{$oldPrice} -> ¥{$newPrice} (原价: ¥{$originalPrice}, 库存: {$newStockStatus})");
        } else {
            logMessage("  [{$product['id']}] {$product['sku_id']}: 获取价格失败");
        }
        
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
            $status = $jd->checkCookieStatus();
            $newStatus = ($status === 'valid') ? 'valid' : 'invalid';
            
            $db->execute(
                "UPDATE settings SET cookie_status = ?, cookie_checked_at = datetime('now', 'localtime') WHERE id = 1",
                [$newStatus]
            );
            
            logMessage("Cookie状态: {$newStatus}");
            
            // 如果Cookie失效且之前是有效的，发送通知
            if ($status !== 'valid' && $settings['cookie_status'] === 'valid') {
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

// 3. 执行价格保护
if ($task === 'all' || $task === 'price_protection') {
    logMessage("\n--- 执行价格保护 ---");
    
    $protection = new PriceProtection();
    
    if ($protection->shouldRunProtection()) {
        logMessage("开始执行一键价保...");
        
        $result = $protection->oneClickProtection();
        
        if ($result['success']) {
            $protection->updateLastRunTime();
            logMessage("价保完成: {$result['message']}");
            
            if ($result['total_refund'] > 0) {
                $webhook->send('price_protection', [
                    'message' => $result['message'],
                    'total_refund' => $result['total_refund'],
                    'results' => $result['results'],
                ]);
            }
        } else {
            logMessage("价保失败: {$result['message']}");
        }
    } else {
        logMessage("未到价保执行时间，跳过");
    }
}

logMessage("\n=== 定时任务完成 ===\n");
