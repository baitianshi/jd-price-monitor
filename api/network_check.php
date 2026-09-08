<?php
/**
 * 网络环境检测API
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/jd.php';

header('Content-Type: application/json');

$response = [
    'success' => true,
    'message' => '网络环境正常',
    'data' => []
];

// 1. 测试JD网站可访问性
$jdUrls = [
    'PC端' => 'https://item.jd.com/100008348542.html',
    '移动端' => 'https://item.m.jd.com/product/100008348542.html',
    '短链接' => 'https://3.cn/1',
];

$jdStatus = [];
foreach ($jdUrls as $name => $url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $start = microtime(true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $time = round((microtime(true) - $start) * 1000, 2);
    curl_close($ch);
    
    $jdStatus[$name] = [
        'accessible' => ($httpCode >= 200 && $httpCode < 400),
        'status_code' => $httpCode,
        'response_time' => $time
    ];
    
    if (!$jdStatus[$name]['accessible']) {
        $response['success'] = false;
        $response['message'] = '无法访问京东网站';
    }
}

// 2. 测试价格获取
$jd = new JdPrice();
$priceTest = [];
try {
    $price = $jd->getPrice('100008348542');
    $priceTest = [
        'success' => ($price > 0),
        'price' => $price,
        'error' => $jd->getLastError()
    ];
    
    if (!$priceTest['success']) {
        $response['success'] = false;
        $response['message'] = '无法获取价格信息';
    }
} catch (Exception $e) {
    $priceTest = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    $response['success'] = false;
    $response['message'] = '价格获取异常';
}

// 3. 测试数据库连接
$dbTest = [];
try {
    $db = Database::getInstance();
    $count = $db->fetch("SELECT COUNT(*) as count FROM products");
    $dbTest = [
        'success' => true,
        'product_count' => $count['count']
    ];
} catch (Exception $e) {
    $dbTest = [
        'success' => false,
        'error' => $e->getMessage()
    ];
    $response['success'] = false;
    $response['message'] = '数据库连接失败';
}

$response['data'] = [
    'jd_status' => $jdStatus,
    'price_test' => $priceTest,
    'db_test' => $dbTest,
    'timestamp' => date('Y-m-d H:i:s')
];

echo json_encode($response);
