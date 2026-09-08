<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

set_security_headers();
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getInstance();

if ($method === 'POST') {
    require_csrf();
}

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
            
        case 'POST':
            handlePost($db);
            break;
            
        case 'DELETE':
            handleDelete($db);
            break;
            
        default:
            json_error('请求方法错误', 405);
    }
} catch (Exception $e) {
    error_log("Tags API error: " . $e->getMessage());
    json_error('服务器错误: ' . $e->getMessage(), 500);
}

function handleGet($db) {
    $tags = $db->fetchAll("
        SELECT t.*, 
               (SELECT COUNT(*) FROM products WHERE tags LIKE '%' || t.name || '%') as product_count
        FROM tags t
        ORDER BY t.name
    ");
    
    json_success(['tags' => $tags]);
}

function handlePost($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['name'])) {
        json_error('标签名称不能为空');
    }
    
    $name = trim($data['name']);
    $color = $data['color'] ?? '#3B82F6';
    
    $existing = $db->fetch("SELECT id FROM tags WHERE name = ?", [$name]);
    if ($existing) {
        json_error('标签已存在');
    }
    
    $db->execute("INSERT INTO tags (name, color, created_at) VALUES (?, ?, datetime('now', 'localtime'))", [$name, $color]);
    $id = $db->lastInsertId();
    
    json_success(['id' => $id, 'name' => $name, 'color' => $color], '标签添加成功');
}

function handleDelete($db) {
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        json_error('无效的标签ID');
    }
    
    $tag = $db->fetch("SELECT name FROM tags WHERE id = ?", [$id]);
    if (!$tag) {
        json_error('标签不存在');
    }
    
    $db->execute("DELETE FROM tags WHERE id = ?", [$id]);
    
    json_success(null, '标签已删除');
}
