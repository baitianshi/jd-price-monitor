<?php
/**
 * 商品管理API
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/jd.php';
require_once __DIR__ . '/../includes/config.php';

set_security_headers();
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();
$jd = new JdPrice();

// POST/PUT/DELETE需要CSRF验证
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    require_csrf();
}

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
            
        case 'POST':
            handlePost($db, $jd);
            break;
            
        case 'PUT':
            handlePut($db);
            break;
            
        case 'DELETE':
            handleDelete($db);
            break;
            
        default:
            json_error('请求方法错误', 405);
    }
} catch (Exception $e) {
    error_log("Products API error: " . $e->getMessage());
    json_error('服务器错误: ' . $e->getMessage(), 500);
}

/**
 * 获取商品列表
 */
function handleGet($db) {
    $id = $_GET['id'] ?? null;
    
    if ($id) {
        // 获取单个商品详情
        $product = $db->fetch(
            "SELECT * FROM products WHERE id = ?",
            [$id]
        );
        
        if (!$product) {
            json_error('商品不存在', 404);
        }
        
        // 获取价格历史（返回所有数据，前端按时间范围筛选）
        $history = $db->fetchAll(
            "SELECT price, stock_status, recorded_at FROM price_history 
             WHERE product_id = ? 
             ORDER BY recorded_at DESC",
            [$id]
        );
        
        $product['price_history'] = array_reverse($history);
        json_success($product);
    } else {
        // 获取商品列表
        $status = $_GET['status'] ?? 'all';
        $tag = $_GET['tag'] ?? null;
        $search = $_GET['search'] ?? null;
        
        $sql = "SELECT * FROM products WHERE 1=1";
        $params = [];
        
        if ($status !== 'all') {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        if ($tag) {
            $sql .= " AND tags LIKE ?";
            $params[] = "%{$tag}%";
        }
        
        if ($search) {
            $sql .= " AND (name LIKE ? OR sku_id LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $products = $db->fetchAll($sql, $params);
        
        // 解析tags
        foreach ($products as &$product) {
            $product['tags_array'] = $product['tags'] ? explode(',', $product['tags']) : [];
        }
        
        json_success([
            'products' => $products,
            'total' => count($products)
        ]);
    }
}

/**
 * 添加商品
 */
function handlePost($db, $jd) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // 支持批量添加
    $urls = $data['urls'] ?? [];
    if (!empty($urls)) {
        return handleBatchAdd($db, $jd, $urls, $data);
    }
    
    // 单个添加
    $url = $data['url'] ?? '';
    
    if (empty($url)) {
        json_error('请输入商品链接');
    }
    
    // 解析链接
    $parsed = $jd->parseUrl($url);
    if (!$parsed) {
        json_error('无法解析商品链接，请检查链接格式');
    }
    
    $skuId = $parsed['sku_id'];
    
    // 检查是否已存在
    $exists = $db->fetch("SELECT id FROM products WHERE sku_id = ?", [$skuId]);
    if ($exists) {
        json_error('该商品已在监控列表中');
    }
    
    // 获取商品信息
    $productInfo = $jd->getProductInfo($skuId);
    
    // 如果获取不到商品名称，使用SKU ID作为名称
    $name = $productInfo['name'] ?? "商品 {$skuId}";
    
    // 获取价格 - 尝试多种方式
    $price = 0;
    
    // 1. 从商品信息中获取
    if ($productInfo && isset($productInfo['price']) && $productInfo['price'] > 0) {
        $price = $productInfo['price'];
    }
    
    // 2. 尝试获取到手价
    if ($price == 0) {
        $price = $jd->getFinalPrice($skuId);
    }
    
    // 3. 尝试普通价格
    if ($price == 0) {
        $price = $jd->getPrice($skuId);
    }
    
    // 获取默认设置
    $settings = $db->fetch("SELECT default_notify_interval, default_price_threshold FROM settings WHERE id = 1");
    $defaultInterval = $settings['default_notify_interval'] ?? 60;
    $defaultThreshold = $settings['default_price_threshold'] ?? 5;
    
    // 获取原价
    $originalPrice = $productInfo['original_price'] ?? $price;
    
    // 插入数据库
    $db->execute(
        "INSERT INTO products (
            sku_id, name, image_url, current_price, target_price, original_price,
            lowest_price, highest_price, notify_interval, price_change_threshold,
            tags, notify_price_drop, notify_price_update, notify_lowest, notify_oos,
            status, stock_status, stock_num
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)",
        [
            $skuId,
            $name,
            $productInfo['image_url'] ?? '',
            $price,
            $data['target_price'] ?? ($price > 0 ? $price : 0),
            $originalPrice,
            $price,
            $price,
            $data['notify_interval'] ?? $defaultInterval,
            $data['price_change_threshold'] ?? $defaultThreshold,
            $data['tags'] ?? '',
            $data['notify_price_drop'] ?? 1,
            $data['notify_price_update'] ?? 1,
            $data['notify_lowest'] ?? 1,
            $data['notify_oos'] ?? 1,
            $productInfo['stock_status'] ?? 'unknown',
            $productInfo['stock_num'] ?? null
        ]
    );
    
    $productId = $db->lastInsertId();
    
    // 记录初始价格
    if ($price > 0) {
        $db->execute(
            "INSERT INTO price_history (product_id, price, stock_status) VALUES (?, ?, ?)",
            [$productId, $price, $productInfo['stock_status'] ?? 'unknown']
        );
    }
    
    json_success([
        'id' => $productId,
        'sku_id' => $skuId,
        'name' => $name,
        'price' => $price,
        'image_url' => $productInfo['image_url'] ?? '',
        'price_warning' => $price == 0 ? '无法获取价格，请稍后手动刷新' : null
    ], '添加成功');
}

/**
 * 批量添加商品
 */
function handleBatchAdd($db, $jd, $urls, $data) {
    $results = [
        'success' => [],
        'failed' => []
    ];
    
    foreach ($urls as $url) {
        $url = trim($url);
        if (empty($url)) continue;
        
        try {
            $parsed = $jd->parseUrl($url);
            if (!$parsed) {
                $results['failed'][] = ['url' => $url, 'reason' => '无法解析链接'];
                continue;
            }
            
            $skuId = $parsed['sku_id'];
            
            // 检查是否已存在
            $exists = $db->fetch("SELECT id FROM products WHERE sku_id = ?", [$skuId]);
            if ($exists) {
                $results['failed'][] = ['url' => $url, 'reason' => '已在监控列表中'];
                continue;
            }
            
            // 获取商品信息
            $productInfo = $jd->getProductInfo($skuId);
            if (!$productInfo || empty($productInfo['name'])) {
                $results['failed'][] = ['url' => $url, 'reason' => '无法获取商品信息'];
                continue;
            }
            
            $price = $productInfo['price'] > 0 ? $productInfo['price'] : $jd->getPrice($skuId);
            $originalPrice = $productInfo['original_price'] ?? $price;
            
            // 获取默认设置
            $settings = $db->fetch("SELECT default_notify_interval, default_price_threshold FROM settings WHERE id = 1");
            
            // 插入数据库
            $db->execute(
                "INSERT INTO products (
                    sku_id, name, image_url, current_price, target_price, original_price,
                    lowest_price, highest_price, notify_interval, price_change_threshold,
                    tags, status, stock_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)",
                [
                    $skuId,
                    $productInfo['name'],
                    $productInfo['image_url'] ?? '',
                    $price,
                    $price,
                    $originalPrice,
                    $price,
                    $price,
                    $settings['default_notify_interval'] ?? 60,
                    $settings['default_price_threshold'] ?? 5,
                    $data['tags'] ?? '',
                    $productInfo['stock_status'] ?? 'unknown'
                ]
            );
            
            $productId = $db->lastInsertId();
            
            // 记录初始价格
            if ($price > 0) {
                $db->execute(
                    "INSERT INTO price_history (product_id, price, stock_status) VALUES (?, ?, ?)",
                    [$productId, $price, $productInfo['stock_status'] ?? 'unknown']
                );
            }
            
            $results['success'][] = [
                'sku_id' => $skuId,
                'name' => $productInfo['name'],
                'price' => $price
            ];
            
        } catch (Exception $e) {
            $results['failed'][] = ['url' => $url, 'reason' => $e->getMessage()];
        }
    }
    
    json_success($results, "成功添加 " . count($results['success']) . " 个商品");
}

/**
 * 更新商品
 */
function handlePut($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? $_GET['id'] ?? null;
    
    if (!$id) {
        json_error('缺少商品ID');
    }
    
    $product = $db->fetch("SELECT id FROM products WHERE id = ?", [$id]);
    if (!$product) {
        json_error('商品不存在', 404);
    }
    
    // 批量更新状态
    if (isset($data['ids']) && isset($data['status'])) {
        $ids = $data['ids'];
        $status = $data['status'];
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->execute(
            "UPDATE products SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})",
            array_merge([$status], $ids)
        );
        
        json_success(null, "已更新 " . count($ids) . " 个商品状态");
    }
    
    // 更新单个商品
    $updateFields = [];
    $updateValues = [];
    
    $allowedFields = [
        'name', 'target_price', 'notify_interval', 'price_change_threshold',
        'tags', 'notify_price_drop', 'notify_price_update', 'notify_lowest', 
        'notify_oos', 'status'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateFields[] = "{$field} = ?";
            $updateValues[] = $data[$field];
        }
    }
    
    if (empty($updateFields)) {
        json_error('没有需要更新的字段');
    }
    
    $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
    $updateValues[] = $id;
    
    $sql = "UPDATE products SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $db->execute($sql, $updateValues);
    
    json_success(null, '更新成功');
}

/**
 * 删除商品
 */
function handleDelete($db) {
    $id = $_GET['id'] ?? null;
    $ids = $_GET['ids'] ?? null;
    
    if ($ids) {
        // 批量删除
        $ids = explode(',', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // 删除价格历史
        $db->execute("DELETE FROM price_history WHERE product_id IN ({$placeholders})", $ids);
        // 删除通知日志
        $db->execute("DELETE FROM notification_logs WHERE product_id IN ({$placeholders})", $ids);
        // 删除商品
        $db->execute("DELETE FROM products WHERE id IN ({$placeholders})", $ids);
        
        json_success(null, "已删除 " . count($ids) . " 个商品");
    } elseif ($id) {
        // 单个删除
        $product = $db->fetch("SELECT id FROM products WHERE id = ?", [$id]);
        if (!$product) {
            json_error('商品不存在', 404);
        }
        
        // 删除价格历史
        $db->execute("DELETE FROM price_history WHERE product_id = ?", [$id]);
        // 删除通知日志
        $db->execute("DELETE FROM notification_logs WHERE product_id = ?", [$id]);
        // 删除商品
        $db->execute("DELETE FROM products WHERE id = ?", [$id]);
        
        json_success(null, '删除成功');
    } else {
        json_error('缺少商品ID');
    }
}
