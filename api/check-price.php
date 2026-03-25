<?php
/**
 * 价格检查API
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/jd.php';
require_once __DIR__ . '/../includes/webhook.php';
require_once __DIR__ . '/../includes/config.php';

// 定时任务调用不需要认证
$isCron = isset($_GET['cron']) || isset($_GET['token']);

if (!$isCron) {
    set_security_headers();
    require_auth();
}

$db = Database::getInstance();
$jd = new JdPrice();
$webhook = new Webhook();

try {
    $id = $_GET['id'] ?? null;
    
    if ($id) {
        // 检查单个商品
        $result = checkSingleProduct($db, $jd, $webhook, $id);
        json_success($result);
    } else {
        // 检查所有活跃商品
        $results = checkAllProducts($db, $jd, $webhook);
        json_success($results);
    }
} catch (Exception $e) {
    error_log("Check price API error: " . $e->getMessage());
    json_error('服务器错误: ' . $e->getMessage(), 500);
}

/**
 * 检查单个商品价格
 */
function checkSingleProduct($db, $jd, $webhook, $id) {
    $product = $db->fetch(
        "SELECT * FROM products WHERE id = ?",
        [$id]
    );
    
    if (!$product) {
        throw new Exception('商品不存在');
    }
    
    $oldPrice = $product['current_price'];
    $oldStockStatus = $product['stock_status'];
    
    // 获取商品信息（包括价格和库存状态）
    $productInfo = $jd->getProductInfo($product['sku_id']);
    $newPrice = $productInfo['price'] ?? 0;
    $newStockStatus = $productInfo['stock_status'] ?? $oldStockStatus;
    $newStockNum = $productInfo['stock_num'] ?? null;
    
    if ($newPrice <= 0) {
        return [
            'success' => false,
            'message' => '无法获取价格，请检查Cookie是否有效'
        ];
    }
    
    // 记录价格历史
    $db->execute(
        "INSERT INTO price_history (product_id, price, stock_status) VALUES (?, ?, ?)",
        [$id, $newPrice, $newStockStatus]
    );
    
    // 更新商品信息
    $lowestPrice = $product['lowest_price'];
    $highestPrice = $product['highest_price'];
    
    if ($newPrice < $lowestPrice || $lowestPrice == 0) {
        $lowestPrice = $newPrice;
    }
    if ($newPrice > $highestPrice) {
        $highestPrice = $newPrice;
    }
    
    $db->execute(
        "UPDATE products SET 
            current_price = ?, 
            lowest_price = ?, 
            highest_price = ?, 
            stock_status = ?,
            stock_num = ?,
            last_checked_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = ?",
        [$newPrice, $lowestPrice, $highestPrice, $newStockStatus, $newStockNum, $id]
    );
    
    // 检查通知条件
    $notifications = checkNotifications($db, $webhook, $product, $oldPrice, $newPrice, $oldStockStatus, $newStockStatus, $lowestPrice);
    
    return [
        'success' => true,
        'old_price' => $oldPrice,
        'new_price' => $newPrice,
        'lowest_price' => $lowestPrice,
        'stock_status' => $newStockStatus,
        'notifications' => $notifications
    ];
}

/**
 * 检查所有活跃商品价格
 */
function checkAllProducts($db, $jd, $webhook) {
    $products = $db->fetchAll(
        "SELECT * FROM products WHERE status = 'active' ORDER BY last_checked_at ASC"
    );
    
    $results = [
        'checked' => 0,
        'updated' => 0,
        'notifications' => []
    ];
    
    // 检查Cookie状态
    $cookieStatus = $jd->checkCookieStatus();
    updateCookieStatus($db, $cookieStatus);
    
    if ($cookieStatus === 'invalid') {
        // Cookie失效通知
        $webhook->send('cookie_invalid', []);
        $results['notifications'][] = ['type' => 'cookie_invalid'];
    }
    
    foreach ($products as $product) {
        try {
            $oldPrice = $product['current_price'];
            $oldStockStatus = $product['stock_status'];
            
            // 获取新价格
            $newPrice = $jd->getPrice($product['sku_id']);
            $results['checked']++;
            
            if ($newPrice <= 0) {
                continue;
            }
            
            // 获取商品信息
            $productInfo = $jd->getProductInfo($product['sku_id']);
            $newStockStatus = $productInfo['stock_status'] ?? $oldStockStatus;
            $newStockNum = $productInfo['stock_num'] ?? null;
            
            // 记录价格历史
            $db->execute(
                "INSERT INTO price_history (product_id, price, stock_status) VALUES (?, ?, ?)",
                [$product['id'], $newPrice, $newStockStatus]
            );
            
            // 更新商品信息
            $lowestPrice = $product['lowest_price'];
            $highestPrice = $product['highest_price'];
            
            if ($newPrice < $lowestPrice || $lowestPrice == 0) {
                $lowestPrice = $newPrice;
            }
            if ($newPrice > $highestPrice) {
                $highestPrice = $newPrice;
            }
            
            $db->execute(
                "UPDATE products SET 
                    current_price = ?, 
                    lowest_price = ?, 
                    highest_price = ?, 
                    stock_status = ?,
                    stock_num = ?,
                    last_checked_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [$newPrice, $lowestPrice, $highestPrice, $newStockStatus, $newStockNum, $product['id']]
            );
            
            if ($newPrice != $oldPrice) {
                $results['updated']++;
            }
            
            // 检查通知条件
            $notifications = checkNotifications($db, $webhook, $product, $oldPrice, $newPrice, $oldStockStatus, $newStockStatus, $lowestPrice);
            $results['notifications'] = array_merge($results['notifications'], $notifications);
            
            // 随机延迟3-5秒，避免请求过快
            $delay = rand(3000000, 5000000);
            usleep($delay);
            
        } catch (Exception $e) {
            error_log("Check product {$product['id']} failed: " . $e->getMessage());
        }
    }
    
    return $results;
}

/**
 * 检查通知条件
 */
function checkNotifications($db, $webhook, $product, $oldPrice, $newPrice, $oldStockStatus, $newStockStatus, $lowestPrice) {
    $notifications = [];
    $now = time();
    $lastNotified = strtotime($product['last_notified_at'] ?? '1970-01-01');
    $notifyInterval = $product['notify_interval'] * 60; // 转换为秒
    
    $productData = [
        'product_id' => $product['id'],
        'product' => [
            'name' => $product['name'],
            'sku_id' => $product['sku_id'],
            'current_price' => $newPrice,
            'old_price' => $oldPrice,
            'target_price' => $product['target_price'],
            'lowest_price' => $lowestPrice,
            'url' => "https://item.jd.com/{$product['sku_id']}.html",
            'created_at' => $product['created_at']
        ]
    ];
    
    // 1. 降价通知：价格 <= 目标价
    if ($product['notify_price_drop'] && $newPrice <= $product['target_price'] && $newPrice < $oldPrice) {
        $result = $webhook->send('price_drop', $productData);
        $notifications[] = ['type' => 'price_drop', 'product_id' => $product['id'], 'result' => $result];
        updateLastNotified($db, $product['id']);
    }
    
    // 2. 历史最低价通知
    if ($product['notify_lowest'] && $newPrice == $lowestPrice && $newPrice < $oldPrice) {
        $result = $webhook->send('lowest_price', $productData);
        $notifications[] = ['type' => 'lowest_price', 'product_id' => $product['id'], 'result' => $result];
        updateLastNotified($db, $product['id']);
    }
    
    // 3. 价格波动通知（涨幅超过阈值）
    if ($oldPrice > 0 && $product['price_change_threshold'] > 0) {
        $changePercent = abs($newPrice - $oldPrice) / $oldPrice * 100;
        if ($changePercent >= $product['price_change_threshold']) {
            if ($newPrice > $oldPrice) {
                $result = $webhook->send('price_surge', $productData);
                $notifications[] = ['type' => 'price_surge', 'product_id' => $product['id'], 'result' => $result];
            }
        }
    }
    
    // 4. 定时价格更新通知
    if ($product['notify_price_update'] && ($now - $lastNotified) >= $notifyInterval) {
        $result = $webhook->send('price_update', $productData);
        $notifications[] = ['type' => 'price_update', 'product_id' => $product['id'], 'result' => $result];
        updateLastNotified($db, $product['id']);
    }
    
    // 5. 库存状态变化通知
    if ($product['notify_oos']) {
        if ($oldStockStatus != 'out_of_stock' && $newStockStatus == 'out_of_stock') {
            $result = $webhook->send('out_of_stock', $productData);
            $notifications[] = ['type' => 'out_of_stock', 'product_id' => $product['id'], 'result' => $result];
        } elseif ($oldStockStatus == 'out_of_stock' && $newStockStatus != 'out_of_stock') {
            $result = $webhook->send('back_in_stock', $productData);
            $notifications[] = ['type' => 'back_in_stock', 'product_id' => $product['id'], 'result' => $result];
        }
    }
    
    return $notifications;
}

/**
 * 更新最后通知时间
 */
function updateLastNotified($db, $productId) {
    $db->execute(
        "UPDATE products SET last_notified_at = CURRENT_TIMESTAMP WHERE id = ?",
        [$productId]
    );
}

/**
 * 更新Cookie状态
 */
function updateCookieStatus($db, $status) {
    $db->execute(
        "UPDATE settings SET cookie_status = ?, cookie_checked_at = CURRENT_TIMESTAMP WHERE id = 1",
        [$status]
    );
}
