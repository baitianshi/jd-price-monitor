<?php
/**
 * 京东价格保护类
 * 
 * 调用京东官方价保接口，自动申请价格保护
 */

require_once __DIR__ . '/db.php';

class PriceProtection {
    private $db;
    private $cookies = '';
    
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    const BASE_URL = 'https://pcsitepp-fm.jd.com';
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadCookies();
    }
    
    private function loadCookies() {
        $settings = $this->db->fetch("SELECT jd_cookies FROM settings WHERE id = 1");
        $this->cookies = $settings['jd_cookies'] ?? '';
    }
    
    /**
     * 获取PIN值 - 直接从Cookie中提取
     */
    private function getPin() {
        if (preg_match('/pt_pin=([^;]+)/i', $this->cookies, $matches)) {
            return urldecode($matches[1]);
        }
        return null;
    }
    
    /**
     * 获取可保价订单列表
     */
    public function getOrderList($page = 1, $pageSize = 20) {
        $url = self::BASE_URL . '/rest/pricepro/priceskusPull';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'page' => $page,
                'pageSize' => $pageSize,
            ]),
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Cookie: ' . $this->cookies,
                'Referer: ' . self::BASE_URL . '/',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $this->parseOrderList($response);
    }
    
    /**
     * 解析订单列表
     */
    private function parseOrderList($html) {
        $orders = [];
        
        $orderBlocks = preg_split('/<tr class="sep-row"><td colspan="6"><\/td><\/tr>/i', $html);
        array_shift($orderBlocks);
        
        foreach ($orderBlocks as $block) {
            if (preg_match('/订单号：(\d+)/i', $block, $orderMatch)) {
                $orderId = $orderMatch[1];
                
                preg_match_all('/queryOrderSkuPriceParam\.skuidAndSequence\.push\("(\d+,\d+)"\);/i', $block, $skuMatches);
                
                $skuList = [];
                foreach ($skuMatches[1] as $skuSeq) {
                    $parts = explode(',', $skuSeq);
                    $skuList[] = [
                        'sku_id' => $parts[0],
                        'sequence' => $parts[1] ?? '1',
                    ];
                }
                
                if (!empty($skuList)) {
                    $orders[] = [
                        'order_id' => $orderId,
                        'sku_list' => $skuList,
                    ];
                }
            }
        }
        
        return $orders;
    }
    
    /**
     * 检查订单是否可保价
     */
    public function checkOrderProtectable($orderId, $skuId, $pin) {
        $url = 'https://sitepp-fm.jd.com/rest/webserver/skuProResultPC';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'orderId' => $orderId,
                'skuId' => $skuId,
                'pin' => $pin,
            ]),
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Cookie: ' . $this->cookies,
                'Referer: ' . self::BASE_URL . '/',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return strpos($response, 'overTime') === false;
    }
    
    /**
     * 获取订单商品购买价格
     */
    public function getOrderSkuPrice($orderList) {
        $url = 'https://sitepp-fm.jd.com/rest/webserver/getOrderListSkuPrice';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['queryOrderPriceParam' => $orderList]),
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Cookie: ' . $this->cookies,
                'Referer: ' . self::BASE_URL . '/',
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true) ?: [];
    }
    
    /**
     * 申请价格保护
     */
    public function applyProtection($orderId, $skuId) {
        $url = self::BASE_URL . '/rest/pricepro/skuProtectApply';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'orderId' => $orderId,
                'orderCategory' => 'Others',
                'skuId' => $skuId,
                'refundtype' => 1,
            ]),
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Cookie: ' . $this->cookies,
                'Referer: ' . self::BASE_URL . '/',
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'X-Requested-With: XMLHttpRequest',
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true) ?: [];
        
        return [
            'success' => isset($result['success']) ? $result['success'] : ($httpCode == 200),
            'message' => $result['message'] ?? $result['errorMessage'] ?? '未知结果',
            'refund_amount' => $result['refundAmount'] ?? $result['refund'] ?? 0,
            'raw_response' => $response,
        ];
    }
    
    /**
     * 一键价保 - 自动检查所有订单并申请
     */
    public function oneClickProtection() {
        if (empty($this->cookies)) {
            return ['success' => false, 'message' => '未设置京东Cookie'];
        }
        
        $pin = $this->getPin();
        if (!$pin) {
            return ['success' => false, 'message' => '获取PIN失败，请检查Cookie是否有效'];
        }
        
        $orders = $this->getOrderList();
        if (empty($orders)) {
            return ['success' => true, 'message' => '没有可申请价保的订单', 'results' => []];
        }
        
        $results = [];
        $totalRefund = 0;
        
        foreach ($orders as $order) {
            $orderId = $order['order_id'];
            
            foreach ($order['sku_list'] as $skuItem) {
                $skuId = $skuItem['sku_id'];
                
                if (!$this->checkOrderProtectable($orderId, $skuId, $pin)) {
                    continue;
                }
                
                $applyResult = $this->applyProtection($orderId, $skuId);
                
                $logData = [
                    'order_id' => $orderId,
                    'sku_id' => $skuId,
                    'product_name' => '',
                    'buy_price' => 0,
                    'current_price' => 0,
                    'refund_amount' => $applyResult['refund_amount'] ?? 0,
                    'status' => $applyResult['success'] ? 'success' : 'failed',
                    'message' => $applyResult['message'],
                ];
                
                $this->logProtection($logData);
                
                $results[] = $logData;
                
                if ($applyResult['success'] && $applyResult['refund_amount'] > 0) {
                    $totalRefund += $applyResult['refund_amount'];
                }
                
                usleep(500000);
            }
        }
        
        return [
            'success' => true,
            'message' => sprintf('价保申请完成，共处理%d个订单，退差价%.2f元', count($results), $totalRefund),
            'total_refund' => $totalRefund,
            'results' => $results,
        ];
    }
    
    /**
     * 记录价保日志
     */
    private function logProtection($data) {
        $this->db->execute(
            "INSERT INTO price_protection_logs (order_id, sku_id, product_name, buy_price, current_price, refund_amount, status, message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['order_id'],
                $data['sku_id'],
                $data['product_name'] ?? '',
                $data['buy_price'] ?? 0,
                $data['current_price'] ?? 0,
                $data['refund_amount'] ?? 0,
                $data['status'] ?? 'pending',
                $data['message'] ?? '',
            ]
        );
    }
    
    /**
     * 获取价保日志
     */
    public function getLogs($limit = 50) {
        return $this->db->fetchAll(
            "SELECT * FROM price_protection_logs ORDER BY applied_at DESC LIMIT ?",
            [$limit]
        );
    }
    
    /**
     * 获取价保统计
     */
    public function getStats() {
        $stats = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(refund_amount) as total_refund
             FROM price_protection_logs
             WHERE applied_at >= datetime('now', '-30 days')"
        );
        
        return $stats ?: [
            'total' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'total_refund' => 0,
        ];
    }
    
    /**
     * 检查是否需要执行价保
     */
    public function shouldRunProtection() {
        $settings = $this->db->fetch(
            "SELECT price_protection_enabled, price_protection_interval, price_protection_last_run FROM settings WHERE id = 1"
        );
        
        if (!$settings || !$settings['price_protection_enabled']) {
            return false;
        }
        
        $interval = $settings['price_protection_interval'] ?: 360;
        $lastRun = $settings['price_protection_last_run'];
        
        if (!$lastRun) {
            return true;
        }
        
        $lastRunTime = strtotime($lastRun);
        $nextRunTime = $lastRunTime + ($interval * 60);
        
        return time() >= $nextRunTime;
    }
    
    /**
     * 更新最后执行时间
     */
    public function updateLastRunTime() {
        $this->db->execute(
            "UPDATE settings SET price_protection_last_run = datetime('now', 'localtime') WHERE id = 1"
        );
    }
}
