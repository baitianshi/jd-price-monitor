<?php
/**
 * 数据导出API
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

set_security_headers();
require_auth();

$type = $_GET['type'] ?? 'products';
$db = Database::getInstance();

try {
    switch ($type) {
        case 'products':
            exportProducts($db);
            break;
            
        case 'history':
            $productId = $_GET['product_id'] ?? null;
            exportHistory($db, $productId);
            break;
            
        default:
            json_error('未知的导出类型');
    }
} catch (Exception $e) {
    error_log("Export API error: " . $e->getMessage());
    json_error('服务器错误: ' . $e->getMessage(), 500);
}

/**
 * 导出商品列表
 */
function exportProducts($db) {
    $products = $db->fetchAll("
        SELECT 
            id, sku_id, name, current_price, target_price, 
            lowest_price, highest_price, notify_interval, 
            status, stock_status, tags, created_at
        FROM products 
        ORDER BY created_at DESC
    ");
    
    $filename = 'products_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // 标题行
    fputcsv($output, [
        'ID', 'SKU', '商品名称', '当前价格', '目标价格', 
        '历史最低', '历史最高', '通知间隔(分钟)', 
        '状态', '库存状态', '标签', '添加时间'
    ]);
    
    // 数据行
    foreach ($products as $product) {
        fputcsv($output, [
            $product['id'],
            $product['sku_id'],
            $product['name'],
            $product['current_price'],
            $product['target_price'],
            $product['lowest_price'],
            $product['highest_price'],
            $product['notify_interval'],
            $product['status'] === 'active' ? '监控中' : '已暂停',
            $product['stock_status'],
            $product['tags'],
            $product['created_at']
        ]);
    }
    
    fclose($output);
    exit;
}

/**
 * 导出价格历史
 */
function exportHistory($db, $productId) {
    if ($productId) {
        $product = $db->fetch("SELECT name FROM products WHERE id = ?", [$productId]);
        if (!$product) {
            json_error('商品不存在');
        }
        $filename = 'price_history_' . $productId . '_' . date('Y-m-d_His') . '.csv';
        
        $history = $db->fetchAll("
            SELECT ph.price, ph.stock_status, ph.recorded_at
            FROM price_history ph
            WHERE ph.product_id = ?
            ORDER BY ph.recorded_at DESC
        ", [$productId]);
    } else {
        $filename = 'price_history_all_' . date('Y-m-d_His') . '.csv';
        
        $history = $db->fetchAll("
            SELECT p.name, p.sku_id, ph.price, ph.stock_status, ph.recorded_at
            FROM price_history ph
            JOIN products p ON p.id = ph.product_id
            ORDER BY ph.recorded_at DESC
            LIMIT 10000
        ");
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    if ($productId) {
        fputcsv($output, ['价格', '库存状态', '记录时间']);
        foreach ($history as $row) {
            fputcsv($output, [$row['price'], $row['stock_status'], $row['recorded_at']]);
        }
    } else {
        fputcsv($output, ['商品名称', 'SKU', '价格', '库存状态', '记录时间']);
        foreach ($history as $row) {
            fputcsv($output, [
                $row['name'], $row['sku_id'], $row['price'], 
                $row['stock_status'], $row['recorded_at']
            ]);
        }
    }
    
    fclose($output);
    exit;
}
