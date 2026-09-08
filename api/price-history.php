<?php
/**
 * 价格记录API
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$action = $_GET['action'] ?? 'list';

$db = Database::getInstance();

switch ($action) {
    case 'list':
        handleList($db);
        break;
    default:
        json_error('未知操作');
}

function handleList($db) {
    $page = intval($_GET['page'] ?? 1);
    $perPage = 50;
    $offset = ($page - 1) * $perPage;
    
    $filter = $_GET['filter'] ?? 'week';
    $productId = $_GET['product_id'] ?? '';
    
    $whereConditions = [];
    $params = [];
    
    if ($productId) {
        $whereConditions[] = 'ph.product_id = ?';
        $params[] = $productId;
    }
    
    switch ($filter) {
        case 'today':
            $whereConditions[] = "date(ph.recorded_at) = date('now', 'localtime')";
            break;
        case 'week':
            $whereConditions[] = "ph.recorded_at >= datetime('now', '-7 days', 'localtime')";
            break;
        case 'month':
            $whereConditions[] = "ph.recorded_at >= datetime('now', '-30 days', 'localtime')";
            break;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    $sql = "SELECT 
                ph.id,
                ph.product_id,
                ph.price,
                ph.recorded_at,
                p.sku_id,
                p.name as product_name,
                p.original_price,
                LAG(ph.price) OVER (PARTITION BY ph.product_id ORDER BY ph.recorded_at) as prev_price
            FROM price_history ph
            LEFT JOIN products p ON ph.product_id = p.id
            {$whereClause}
            ORDER BY ph.recorded_at DESC
            LIMIT {$perPage} OFFSET {$offset}";
    
    $records = $db->fetchAll($sql, $params);
    
    foreach ($records as &$record) {
        $prevPrice = floatval($record['prev_price'] ?? 0);
        $currentPrice = floatval($record['price']);
        
        if ($prevPrice > 0) {
            $record['price_change'] = $currentPrice - $prevPrice;
        } else {
            $record['price_change'] = 0;
        }
        
        unset($record['prev_price']);
    }
    
    $countSql = "SELECT COUNT(*) as total FROM price_history ph {$whereClause}";
    $countResult = $db->fetch($countSql, $params);
    $total = intval($countResult['total'] ?? 0);
    
    json_success([
        'records' => $records,
        'has_more' => ($offset + $perPage) < $total,
        'total' => $total
    ]);
}
