<?php
/**
 * 轻量调度器 - 每分钟运行
 * 
 * 功能：
 * 1. 随机刷新单个商品价格（60-120分钟间隔）
 * 2. 检查Cookie状态（6-12小时间隔，精确到分钟）
 * 3. 执行价格保护（6-12小时间隔，精确到分钟）
 * 4. 清理过期历史数据（每24小时执行一次）
 * 5. 随机浏览京东页面模拟真人行为
 * 
 * 使用方法：
 *   php scheduler.php              # 自动执行所有到期任务
 *   php scheduler.php product      # 只检查商品价格
 *   php scheduler.php cookie       # 只检查Cookie
 *   php scheduler.php protection   # 只执行价保
 *   php scheduler.php clean        # 强制执行历史清理
 *   php scheduler.php force        # 强制执行所有任务
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/jd.php';
require_once __DIR__ . '/includes/webhook.php';
require_once __DIR__ . '/includes/price_protection.php';

define('CLI_MODE', true);

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[{$timestamp}] {$message}\n";
    echo $log;
    
    $logDir = __DIR__ . '/data';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/scheduler.log', $log, FILE_APPEND);
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

function randomBrowseJd($db, $jd) {
    logMessage("模拟真人浏览购物车...");
    
    $products = $db->fetchAll("SELECT sku_id FROM products WHERE status = 'active'");
    $skus = array_map(function($p) { return $p['sku_id']; }, $products);
    
    $result = $jd->randomBrowseCart($skus);
    
    if ($result['success']) {
        $cartCount = isset($result['cart_count']) ? "，监控商品 {$result['cart_count']} 个" : '';
        logMessage("浏览完成，共访问 {$result['visited_count']} 个页面{$cartCount}");
        foreach ($result['pages'] as $page) {
            $status = $page['success'] ? '成功' : '失败';
            $delay = isset($page['delay']) ? "，停留 {$page['delay']} 秒" : '';
            logMessage("  - {$page['url']} [{$status}]{$delay}");
        }
    }
    
    return $result;
}

function checkProductPrice($db, $jd, $webhook, $force = false) {
    $now = date('Y-m-d H:i:s');
    
    $sql = $force 
        ? "SELECT * FROM products WHERE status = 'active' ORDER BY RANDOM() LIMIT 1"
        : "SELECT * FROM products WHERE status = 'active' AND (next_check_at IS NULL OR next_check_at <= ?) ORDER BY RANDOM() LIMIT 1";
    
    $params = $force ? [] : [$now];
    $product = $db->fetch($sql, $params);
    
    if (!$product) {
        if (!$force) {
            logMessage("没有需要检查的商品");
        }
        return false;
    }
    
    randomBrowseJd($db, $jd);
    
    logMessage("检查商品: [{$product['id']}] {$product['name']}");
    
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
        
        if ($newPrice != $oldPrice || $newStockStatus != $oldStockStatus) {
            $db->execute(
                "INSERT INTO price_history (product_id, price, stock_status, recorded_at) VALUES (?, ?, ?, datetime('now', 'localtime'))",
                [$product['id'], $newPrice, $newStockStatus]
            );
        }
        
        if ($newPrice <= floatval($product['target_price']) && $oldPrice > floatval($product['target_price'])) {
            $nextMinutes = rand(60, 120);
            $nextCheck = date('Y-m-d H:i:s', strtotime("+{$nextMinutes} minutes"));
            logMessage("  [降价提醒] ¥{$newPrice} <= 目标价 ¥{$product['target_price']}");
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
                'next_check_time' => $nextCheck
            ]);
        }
        
        if ($newPrice == $lowestPrice && $newPrice < $oldPrice && !empty($product['notify_lowest'])) {
            logMessage("  [最低价提醒] ¥{$newPrice}");
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
        
        // 涨价提醒
        if ($newPrice > $oldPrice && $oldPrice > 0 && !empty($product['notify_price_surge'])) {
            $surgeAmount = $newPrice - $oldPrice;
            $surgePercent = round(($surgeAmount / $oldPrice) * 100, 1);
            $nextCheckTime = date('Y-m-d H:i:s', strtotime('+' . rand(30, 60) . ' minutes'));
            logMessage("  [涨价提醒] ¥{$oldPrice} -> ¥{$newPrice} (↑{$surgePercent}%)");
            $webhook->send('price_surge', [
                'product_id' => $product['id'],
                'product' => [
                    'name' => $product['name'],
                    'sku_id' => $product['sku_id'],
                    'current_price' => $newPrice,
                    'original_price' => $originalPrice,
                    'url' => "https://item.jd.com/{$product['sku_id']}.html"
                ],
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'surge_amount' => $surgeAmount,
                'surge_percent' => $surgePercent,
                'next_check_time' => $nextCheckTime
            ]);
        }
        
        if ($oldStockStatus != 'out_of_stock' && $newStockStatus == 'out_of_stock') {
            $nextMinutes = rand(60, 120);
            $nextCheck = date('Y-m-d H:i:s', strtotime("+{$nextMinutes} minutes"));
            logMessage("  [缺货提醒]");
            $webhook->send('out_of_stock', [
                'product_id' => $product['id'],
                'product' => [
                    'name' => $product['name'],
                    'sku_id' => $product['sku_id'],
                    'current_price' => $newPrice,
                    'url' => "https://item.jd.com/{$product['sku_id']}.html"
                ],
                'next_check_time' => $nextCheck
            ]);
        }
        
        if ($oldStockStatus == 'out_of_stock' && $newStockStatus != 'out_of_stock') {
            logMessage("  [恢复上架提醒]");
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
        
        logMessage("  价格: ¥{$oldPrice} -> ¥{$newPrice}, 库存: {$newStockStatus}");
    } else {
        logMessage("  获取价格失败");
    }
    
    $nextMinutes = rand(60, 120);
    $nextCheck = date('Y-m-d H:i:s', strtotime("+{$nextMinutes} minutes"));
    $db->execute("UPDATE products SET next_check_at = ? WHERE id = ?", [$nextCheck, $product['id']]);
    logMessage("  下次检查: {$nextCheck}");
    
    return true;
}

function checkCookie($db, $jd, $webhook, $force = false) {
    $settings = $db->fetch("SELECT jd_cookies, cookie_status, next_cookie_check_at FROM settings WHERE id = 1");
    
    if (empty($settings['jd_cookies'])) {
        logMessage("未设置Cookie");
        return false;
    }
    
    $now = time();
    $nextCheck = $settings['next_cookie_check_at'];
    
    if (!$force && $nextCheck && strtotime($nextCheck) > $now) {
        $remaining = strtotime($nextCheck) - $now;
        logMessage("Cookie检查未到期，剩余 {$remaining} 秒");
        return false;
    }
    
    logMessage("检查Cookie状态...");
    
    $status = $jd->checkCookieStatus();
    $newStatus = ($status === 'valid') ? 'valid' : 'invalid';
    
    $db->execute(
        "UPDATE settings SET cookie_status = ?, cookie_checked_at = datetime('now', 'localtime') WHERE id = 1",
        [$newStatus]
    );
    
    logMessage("Cookie状态: {$newStatus}");
    
    if ($status !== 'valid' && $settings['cookie_status'] === 'valid') {
        logMessage("Cookie已失效，发送通知...");
        $webhook->send('cookie_expired', [
            'message' => '京东Cookie已失效，请重新登录获取新的Cookie',
            'checked_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    $nextMinutes = rand(6 * 60, 12 * 60);
    $nextCheckTime = date('Y-m-d H:i:s', $now + $nextMinutes * 60);
    $db->execute("UPDATE settings SET next_cookie_check_at = ? WHERE id = 1", [$nextCheckTime]);
    logMessage("下次Cookie检查: {$nextCheckTime}");
    
    return true;
}

function runPriceProtection($db, $webhook, $force = false) {
    $settings = $db->fetch("SELECT price_protection_enabled FROM settings WHERE id = 1");
    
    if (empty($settings['price_protection_enabled'])) {
        return false;
    }
    
    $protection = new PriceProtection();
    if (!$force && !$protection->shouldRunProtection()) {
        logMessage("价保未到期，跳过执行");
        return false;
    }
    
    logMessage("执行价格保护...");
    
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
    
    return true;
}

function initNextCheckTimes($db) {
    $products = $db->fetchAll("SELECT id FROM products WHERE status = 'active' AND next_check_at IS NULL");
    foreach ($products as $product) {
        $nextMinutes = rand(0, 60);
        $nextCheck = date('Y-m-d H:i:s', strtotime("+{$nextMinutes} minutes"));
        $db->execute("UPDATE products SET next_check_at = ? WHERE id = ?", [$nextCheck, $product['id']]);
    }
    
    $settings = $db->fetch("SELECT next_cookie_check_at, next_clean_at FROM settings WHERE id = 1");
    
    if (empty($settings['next_cookie_check_at'])) {
        $nextMinutes = rand(0, 12 * 60);
        $nextCheck = date('Y-m-d H:i:s', time() + $nextMinutes * 60);
        $db->execute("UPDATE settings SET next_cookie_check_at = ? WHERE id = 1", [$nextCheck]);
    }
    
    if (empty($settings['next_clean_at'])) {
        $nextClean = date('Y-m-d H:i:s', time() + rand(0, 24) * 3600);
        $db->execute("UPDATE settings SET next_clean_at = ? WHERE id = 1", [$nextClean]);
    }
}

function cleanOldHistory($db, $force = false) {
    $settings = $db->fetch("SELECT next_clean_at FROM settings WHERE id = 1");
    
    $now = time();
    $nextClean = $settings['next_clean_at'] ?? null;
    
    if (!$force && $nextClean && strtotime($nextClean) > $now) {
        $remaining = round((strtotime($nextClean) - $now) / 3600, 1);
        logMessage("历史清理未到期，剩余 {$remaining} 小时");
        return false;
    }
    
    logMessage("执行历史数据清理...");
    
    $products = $db->fetchAll("SELECT id, name, history_retention_days FROM products WHERE status = 'active'");
    
    if (empty($products)) {
        logMessage("没有需要清理的商品");
        $nextCleanTime = date('Y-m-d H:i:s', $now + 24 * 3600);
        $db->execute("UPDATE settings SET next_clean_at = ? WHERE id = 1", [$nextCleanTime]);
        return false;
    }
    
    $totalCleaned = 0;
    
    foreach ($products as $product) {
        $retentionDays = intval($product['history_retention_days'] ?? 365);
        
        if ($retentionDays < 7) {
            $retentionDays = 365;
        }
        
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        
        $countResult = $db->fetch(
            "SELECT COUNT(*) as count FROM price_history WHERE product_id = ? AND recorded_at < ?",
            [$product['id'], $cutoffDate]
        );
        $count = intval($countResult['count']);
        
        if ($count > 0) {
            $db->execute(
                "DELETE FROM price_history WHERE product_id = ? AND recorded_at < ?",
                [$product['id'], $cutoffDate]
            );
            $totalCleaned += $count;
            logMessage("商品 [{$product['name']}] 清理 {$count} 条记录（保留 {$retentionDays} 天）");
        }
    }
    
    if ($totalCleaned === 0) {
        logMessage("无需清理历史数据");
    } else {
        logMessage("共清理 {$totalCleaned} 条过期历史记录");
    }
    
    $nextCleanTime = date('Y-m-d H:i:s', $now + 24 * 3600);
    $db->execute("UPDATE settings SET next_clean_at = ? WHERE id = 1", [$nextCleanTime]);
    logMessage("下次清理时间: {$nextCleanTime}");
    
    return true;
}

$task = $argv[1] ?? 'all';
$force = ($task === 'force');

logMessage("=== 调度器启动 ===");

$db = Database::getInstance();
$jd = new JdPrice();
$webhook = new Webhook();

initNextCheckTimes($db);

$inSilent = isInSilentPeriod($db);
if ($inSilent && $task === 'all') {
    $settings = $db->fetch("SELECT silent_start, silent_end FROM settings WHERE id = 1");
    logMessage("当前处于静默时段（{$settings['silent_start']} - {$settings['silent_end']}），跳过所有京东请求任务");
    cleanOldHistory($db, false);
    logMessage("=== 调度器结束 ===\n");
    exit;
}

switch ($task) {
    case 'product':
        checkProductPrice($db, $jd, $webhook, false);
        break;
    case 'cookie':
        checkCookie($db, $jd, $webhook, false);
        break;
    case 'protection':
        runPriceProtection($db, $webhook, false);
        break;
    case 'clean':
        cleanOldHistory($db, true);
        break;
    case 'force':
        logMessage("强制执行模式");
        checkProductPrice($db, $jd, $webhook, true);
        checkCookie($db, $jd, $webhook, true);
        runPriceProtection($db, $webhook, true);
        cleanOldHistory($db, true);
        break;
    default:
        checkProductPrice($db, $jd, $webhook, false);
        checkCookie($db, $jd, $webhook, false);
        runPriceProtection($db, $webhook, false);
        cleanOldHistory($db, false);
        break;
}

logMessage("=== 调度器结束 ===\n");
