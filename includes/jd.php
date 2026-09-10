<?php
/**
 * 京东价格获取类 - 反爬增强版
 * - 稳定设备指纹（MacBook Pro）
 * - Cookie 分域存储
 * - TLS 指纹模拟
 * - Header 顺序模拟
 * - 行为多样性（库存/评价接口）
 * - 接口失败降级
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jd_antiban.php';

class JdPrice {
    private $db;
    private $cookies = '';
    private $lastError = '';
    private $lastErrorType = '';

    // === 反爬增强组件 ===
    /** @var JdDeviceFingerprint */
    private $deviceFp;
    /** @var JdCookieJar */
    private $cookieJar;
    /** @var JdRequestForgery */
    private $requestForgery;
    /** @var JdBehaviorSimulator */
    private $behaviorSim;
    /** @var JdApiDegradation */
    private $degradation;

    // 记录是否已执行过前置页面访问（避免同一个sku重复访问）
    private $preVisitedSkus = [];

    // 移动端API常量（保留兼容，但主要走MacBook Pro Chrome指纹）
    const MOBILE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1';
    const PC_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    
    // 价格获取方法优先级（根据成功率动态调整）
    private $priceMethodStats = [
        'pc_page' => ['success' => 0, 'total' => 0, 'avg_time' => 0],
        'mobile_page' => ['success' => 0, 'total' => 0, 'avg_time' => 0],
        'mobile_api' => ['success' => 0, 'total' => 0, 'avg_time' => 0],
        'public_api' => ['success' => 0, 'total' => 0, 'avg_time' => 0],
    ];
    
    private $methodNames = [
        'pc_page' => 'PC端页面',
        'mobile_page' => '移动端页面',
        'mobile_api' => '移动端API',
        'public_api' => '公开API',
    ];
    
    private $errorTypes = [
        'cookie_invalid' => 'Cookie已失效，请重新获取',
        'cookie_not_set' => '未设置京东Cookie',
        'network_timeout' => '网络请求超时',
        'product_offline' => '商品已下架',
        'risk_blocked' => '被京东风控拦截',
        'price_hidden' => '价格未公开显示',
        'parse_failed' => '数据解析失败',
        'unknown' => '未知错误',
    ];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadCookies();
        $this->loadMethodStats();

        // === 初始化反爬增强组件 ===
        $this->deviceFp = new JdDeviceFingerprint();
        $this->cookieJar = new JdCookieJar();
        $this->requestForgery = new JdRequestForgery($this->deviceFp, $this->cookieJar);
        $this->behaviorSim = new JdBehaviorSimulator($this->requestForgery, $this->cookieJar, $this->deviceFp);
        $this->degradation = new JdApiDegradation();

        // 确保设备跟踪Cookie存在（__jda/__jdb/__jdc/__jdu/guid/_t）
        $this->cookieJar->ensureDeviceCookies($this->deviceFp);

        // 同步Cookie到旧属性（向后兼容）
        $this->cookies = $this->cookieJar->getMainCookieString();
    }
    
    /**
     * 加载Cookie
     */
    private function loadCookies() {
        $settings = $this->db->fetch("SELECT jd_cookies FROM settings WHERE id = 1");
        if ($settings && !empty($settings['jd_cookies'])) {
            $this->cookies = $settings['jd_cookies'];
        }
    }
    
    /**
     * 解析Cookie字符串为数组
     */
    private function parseCookieStringToArray($cookieStr) {
        $cookies = [];
        if (empty($cookieStr)) {
            return $cookies;
        }
        foreach (explode(';', $cookieStr) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eqPos = strpos($part, '=');
            if ($eqPos === false) {
                continue;
            }
            $name = trim(substr($part, 0, $eqPos));
            $value = trim(substr($part, $eqPos + 1));
            if ($name !== '') {
                $cookies[$name] = $value;
            }
        }
        return $cookies;
    }
    
    /**
     * 合并新捕获的Set-Cookie并持久化到数据库
     * 仅当登录态关键Cookie（pt_key/pt_pin等）变化时重置cookie_status，
     * 普通跟踪Cookie变化仅更新值，避免影响失效通知逻辑
     */
    private function mergeAndPersistCookies($newCookies) {
        if (empty($newCookies) || empty($this->cookies)) {
            return;
        }
        
        $current = $this->parseCookieStringToArray($this->cookies);
        $changed = false;
        $loginChanged = false;
        
        foreach ($newCookies as $name => $value) {
            // 忽略空值/删除标记（保护pt_key等关键Cookie不被误删）
            if ($value === '' || strtolower($value) === 'deleted') {
                continue;
            }
            if (!isset($current[$name]) || $current[$name] !== $value) {
                $current[$name] = $value;
                $changed = true;
                if (in_array($name, ['pt_key', 'pt_pin', 'pt_token', 'thor', 'sso_uc'], true)) {
                    $loginChanged = true;
                }
            }
        }
        
        if (!$changed) {
            return;
        }
        
        $parts = [];
        foreach ($current as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        $newCookieStr = implode('; ', $parts) . ';';
        
        if ($newCookieStr === $this->cookies) {
            return;
        }
        
        if ($loginChanged) {
            $this->db->execute(
                "UPDATE settings SET jd_cookies = ?, cookie_status = 'unknown', updated_at = datetime('now', 'localtime') WHERE id = 1",
                [$newCookieStr]
            );
        } else {
            $this->db->execute(
                "UPDATE settings SET jd_cookies = ?, updated_at = datetime('now', 'localtime') WHERE id = 1",
                [$newCookieStr]
            );
        }
        $this->cookies = $newCookieStr;
    }
    
    /**
     * 执行请求并自动捕获响应中的Set-Cookie，维护Cookie链
     * 使用CURLOPT_HEADERFUNCTION避免污染响应体
     */
    private function execWithCookieCapture($ch) {
        $capturedCookies = [];
        
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$capturedCookies) {
            if (preg_match('/^Set-Cookie:\s*([^;=\s]+)\s*=\s*([^;]*)/i', $headerLine, $m)) {
                $name = trim($m[1]);
                $value = trim($m[2]);
                if ($value !== '' && strtolower($value) !== 'deleted') {
                    $capturedCookies[$name] = $value;
                }
            }
            return strlen($headerLine);
        });
        
        $response = curl_exec($ch);
        
        if (!empty($capturedCookies)) {
            $this->mergeAndPersistCookies($capturedCookies);
        }
        
        return $response;
    }
    
    /**
     * 从数据库加载方法统计数据
     */
    private function loadMethodStats() {
        $stats = $this->db->fetchAll("SELECT * FROM price_method_stats");
        foreach ($stats as $row) {
            $method = $row['method'];
            if (isset($this->priceMethodStats[$method])) {
                $avgTime = $row['total_count'] > 0 ? $row['total_time'] / $row['total_count'] : 0;
                $this->priceMethodStats[$method] = [
                    'success' => $row['success_count'],
                    'total' => $row['total_count'],
                    'avg_time' => $avgTime,
                ];
            }
        }
    }
    
    /**
     * 获取方法统计数据（用于前端显示）
     */
    public function getMethodStats() {
        $result = [];
        foreach ($this->priceMethodStats as $method => $data) {
            $successRate = $data['total'] > 0 ? round($data['success'] / $data['total'] * 100, 1) : 0;
            $avgTime = $data['avg_time'] > 0 ? round($data['avg_time'] * 1000) : 0;
            $result[] = [
                'method' => $method,
                'name' => $this->methodNames[$method] ?? $method,
                'success' => $data['success'],
                'total' => $data['total'],
                'success_rate' => $successRate,
                'avg_time' => $avgTime,
            ];
        }
        usort($result, function($a, $b) {
            $aRate = (float)$a['success_rate'];
            $bRate = (float)$b['success_rate'];
            if ($aRate !== $bRate) {
                return $bRate <=> $aRate;
            }
            return (int)$a['avg_time'] <=> (int)$b['avg_time'];
        });
        return $result;
    }
    
    public function makeRequest($url) {
        $headers = [
            'User-Agent: ' . self::MOBILE_USER_AGENT,
            'Referer: https://m.jd.com/',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cookie: ' . $this->cookies,
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        $response = $this->execWithCookieCapture($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode == 200,
            'http_code' => $httpCode,
            'response' => $response,
        ];
    }

    /**
     * 增强版请求 - 使用反爬模块（TLS指纹 + Header顺序 + Cookie分域）
     * @param string $url 请求URL
     * @param string $type 请求类型: html/json/image/script
     * @param string $referer 来源页
     * @param array $options 额外选项: timeout, follow_location, encoding
     * @return array [success, http_code, response, effective_url]
     */
    private function antRequest($url, $type = 'html', $referer = '', $options = []) {
        $headers = $this->requestForgery->buildHeaders($url, $type, $referer);

        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 10,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? 5,
            CURLOPT_ENCODING => $options['encoding'] ?? 'gzip, deflate, br',
            CURLOPT_FOLLOWLOCATION => $options['follow_location'] ?? true,
            CURLOPT_MAXREDIRS => $options['max_redirs'] ?? 5,
            CURLOPT_HEADER => false,
            CURLOPT_USERAGENT => $this->deviceFp->getUserAgent(),
        ];
        curl_setopt_array($ch, $curlOpts);

        // 配置 TLS 指纹
        $this->requestForgery->configureCurlTls($ch);

        // 执行请求并捕获 Cookie
        $response = $this->requestForgery->execRequest($ch, $url);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        // 同步Cookie到旧属性（向后兼容）
        $this->cookies = $this->cookieJar->getMainCookieString();

        return [
            'success' => $httpCode == 200,
            'http_code' => $httpCode,
            'response' => $response,
            'effective_url' => $effectiveUrl,
            'error' => $error,
        ];
    }

    /**
     * 确保已完成商品页前置访问（价格接口调用前必须先逛页面）
     */
    private function ensurePreVisit($skuId) {
        if (isset($this->preVisitedSkus[$skuId])) {
            return $this->preVisitedSkus[$skuId];
        }

        $result = $this->behaviorSim->preVisitProductPage($skuId);
        $this->preVisitedSkus[$skuId] = $result;

        return $result;
    }

    /**
     * 执行行为多样性（库存/评价随机查询）
     */
    private function triggerBehaviorDiversity($skuId) {
        // 库存查询（概率触发）
        $stockResult = $this->behaviorSim->maybeCheckStock($skuId);
        // 评价查询（概率触发）
        $commentResult = $this->behaviorSim->maybeCheckComments($skuId);
        return [
            'stock' => $stockResult,
            'comment' => $commentResult,
        ];
    }

    /**
     * 检查接口是否被降级
     */
    private function isApiDegraded($apiName) {
        return !$this->degradation->isAvailable($apiName);
    }

    /**
     * 记录接口调用结果（成功/失败）
     * @return bool 失败时是否触发了降级
     */
    private function recordApiResult($apiName, $success) {
        if ($success) {
            $this->degradation->recordSuccess($apiName);
            return false;
        }
        return $this->degradation->recordFailure($apiName);
    }
    
    /**
     * 模拟真人浏览：进入移动端购物车，再随机浏览若干监控商品详情页
     * 浏览路径贴近真实用户（购物车 -> 商品），降低风控风险
     * @param array $monitorSkus 监控中的商品SKU列表
     * @return array 访问结果
     */
    public function randomBrowseCart($monitorSkus = []) {
        $visitedPages = [];
        
        // 1. 进入移动端购物车
        $result = $this->makeRequest('https://m.jd.com/cart/');
        $visitedPages[] = [
            'url' => 'https://m.jd.com/cart/',
            'success' => $result['success']
        ];
        
        // 2. 从监控商品中随机抽取1-3个，浏览移动端详情页
        $monitorSkus = array_values(array_unique(array_filter($monitorSkus)));
        if (!empty($monitorSkus)) {
            $visitCount = min(rand(1, 3), count($monitorSkus));
            shuffle($monitorSkus);
            $selectedSkus = array_slice($monitorSkus, 0, $visitCount);
            
            foreach ($selectedSkus as $skuId) {
                // 随机停留时间（2-4秒）
                $delay = rand(2, 4);
                sleep($delay);
                
                $page = "https://item.m.jd.com/product/{$skuId}.html";
                $result = $this->makeRequest($page);
                $visitedPages[] = [
                    'url' => $page,
                    'success' => $result['success'],
                    'delay' => $delay
                ];
            }
        }
        
        return [
            'success' => true,
            'visited_count' => count($visitedPages),
            'cart_count' => count($monitorSkus),
            'pages' => $visitedPages
        ];
    }
    
    /**
     * 解析京东链接，提取SKU ID
     */
    public function parseUrl($url) {
        $skuId = null;
        $url = trim($url);
        
        // 处理京东APP分享文案，提取链接
        // 格式: 【京东】https://3.cn/-2HuUe81?jkl=@xxx@ CA1507 「商品名」点击链接...
        if (preg_match('/https?:\/\/[^\s]+/', $url, $matches)) {
            $url = $matches[0];
            // 清理URL末尾的特殊字符
            $url = preg_replace('/[?&]jkl=.*$/', '', $url);
            $url = rtrim($url, '?&');
        }
        
        // 如果只是SKU ID
        if (preg_match('/^\d+$/', $url)) {
            return ['sku_id' => $url, 'url' => "https://m.jd.com/product/{$url}.html"];
        }
        
        // PC链接: https://item.jd.com/100012345.html
        if (preg_match('/item\.jd\.com\/(\d+)\.html/i', $url, $matches)) {
            $skuId = $matches[1];
        }
        // 移动端链接: https://m.jd.com/product/100012345.html
        elseif (preg_match('/m\.jd\.com\/product\/(\d+)/i', $url, $matches)) {
            $skuId = $matches[1];
        }
        // 移动端商品详情: https://m.jd.com/product/details/100012345.html
        elseif (preg_match('/m\.jd\.com\/.*?\/(\d+)/i', $url, $matches)) {
            $skuId = $matches[1];
        }
        // APP分享短链接: https://u.jd.com/xxxx
        elseif (preg_match('/u\.jd\.com\/([a-zA-Z0-9]+)/i', $url, $matches)) {
            $skuId = $this->resolveShortUrl($url);
        }
        // 京东APP分享短链接: https://3.cn/xxxx 或 https://3.cn/-xxxx
        elseif (preg_match('/3\.cn\/(-?[a-zA-Z0-9]+)/i', $url, $matches)) {
            $skuId = $this->resolveShortUrl($url);
        }
        // 京东短链接: https://jd.cn/xxxx
        elseif (preg_match('/jd\.cn\/([a-zA-Z0-9]+)/i', $url, $matches)) {
            $skuId = $this->resolveShortUrl($url);
        }
        // 其他京东短链接
        elseif (preg_match('/jd\.com\/([a-zA-Z0-9]+)/i', $url, $matches)) {
            $skuId = $this->resolveShortUrl($url);
        }
        // 其他包含数字的链接
        elseif (preg_match('/\/(\d{6,})\.html/i', $url, $matches)) {
            $skuId = $matches[1];
        }
        
        if ($skuId) {
            return [
                'sku_id' => $skuId,
                'url' => "https://m.jd.com/product/{$skuId}.html"
            ];
        }
        
        return null;
    }
    
    /**
     * 解析短链接
     */
    private function resolveShortUrl($url) {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => self::MOBILE_USER_AGENT,
                CURLOPT_HEADER => false,
            ]);
            curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            
            // 从最终URL中提取SKU
            if ($finalUrl) {
                // 优先从returnurl参数中提取（处理风险重定向）
                if (preg_match('/returnurl=([^&]+)/i', $finalUrl, $matches)) {
                    $decodedUrl = urldecode($matches[1]);
                    if (preg_match('/product\/(\d+)/i', $decodedUrl, $skuMatch)) {
                        return $skuMatch[1];
                    }
                    if (preg_match('/(\d{6,})\.html/i', $decodedUrl, $skuMatch)) {
                        return $skuMatch[1];
                    }
                }
                
                // 直接从URL中提取
                if (preg_match('/product\/(\d+)/i', $finalUrl, $matches)) {
                    return $matches[1];
                }
                if (preg_match('/(\d{6,})\.html/i', $finalUrl, $matches)) {
                    return $matches[1];
                }
                // 避免匹配到风险处理页面的数字
                if (preg_match('/(\d{10,})/', $finalUrl, $matches)) {
                    return $matches[1];
                }
            }
        } catch (Exception $e) {
            error_log("Resolve short URL failed: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * 获取商品信息 - 按顺序尝试多个来源，避免风控
     * 增强版：前置页面访问 + 接口降级 + 行为多样性
     * @param string $skuId 商品SKU
     * @param bool $skipImage 是否跳过图片获取（用于异步加载）
     * @return array 商品信息，包含status字段表示当前状态
     */
    public function getProductInfo($skuId, $skipImage = false) {
        $this->lastError = '';
        $this->lastErrorType = '';
        
        $result = [
            'name' => '',
            'image_url' => '',
            'price' => 0,
            'original_price' => 0,
            'plus_price' => 0,
            'stock_status' => 'unknown',
            'stock_num' => null,
            'source' => '',
            'status' => '',
            'error_type' => '',
            'error_message' => '',
        ];
        
        // 检查Cookie
        if (empty($this->cookies)) {
            $result['error_type'] = 'cookie_not_set';
            $result['error_message'] = $this->errorTypes['cookie_not_set'];
            $result['status'] = 'error';
            return $result;
        }

        // === 反爬增强：先访问商品详情页（必须步骤） ===
        $this->ensurePreVisit($skuId);

        // === 反爬增强：行为多样性（库存/评价随机查询） ===
        $this->triggerBehaviorDiversity($skuId);
        
        // 按顺序尝试的方法列表（带接口名用于降级检查）
        $methods = [
            ['name' => 'mobile_page', 'api' => 'price_mobile_page', 'func' => 'getProductInfoFromMobilePage', 'label' => '移动端页面'],
            ['name' => 'mobile_api', 'api' => 'price_mobile_api', 'func' => 'getProductInfoFromMobile', 'label' => '移动端API'],
            ['name' => 'public_api', 'api' => 'price_public_api', 'func' => 'getProductInfoFromPublicApi', 'label' => '公开API'],
        ];
        
        $methodErrors = [];
        
        // 按顺序尝试获取
        foreach ($methods as $method) {
            // === 反爬增强：检查接口是否被降级 ===
            if ($this->isApiDegraded($method['api'])) {
                $methodErrors[$method['name']] = 'degraded';
                continue;
            }

            $result['status'] = 'trying_' . $method['name'];
            
            $startTime = microtime(true);
            $data = $this->{$method['func']}($skuId);
            $elapsed = round((microtime(true) - $startTime) * 1000);

            // 记录接口调用结果（用于降级）
            $apiSuccess = $data && !(isset($data['risk_blocked']) && $data['risk_blocked']);
            $this->recordApiResult($method['api'], $apiSuccess);
            
            if (!$data) {
                $methodErrors[$method['name']] = 'no_response';
                continue;
            }
            
            // 检查是否被风控
            if (isset($data['risk_blocked']) && $data['risk_blocked']) {
                $methodErrors[$method['name']] = 'risk_blocked';
                continue;
            }
            
            // 检查是否超时
            if (isset($data['timeout']) && $data['timeout']) {
                $methodErrors[$method['name']] = 'network_timeout';
                continue;
            }
            
            // 记录数据来源
            if (empty($result['source']) && !empty($data['name']) && strpos($data['name'], '商品 ') !== 0) {
                $result['source'] = $method['name'];
            }
            
            // 填充名称
            if (empty($result['name']) && !empty($data['name']) && strpos($data['name'], '商品 ') !== 0) {
                $result['name'] = $data['name'];
            }
            
            // 填充图片（如果不跳过）
            if (!$skipImage && empty($result['image_url']) && !empty($data['image_url'])) {
                $result['image_url'] = $data['image_url'];
            }
            
            // 填充到手价
            if (($result['price'] ?? 0) <= 1 && ($data['price'] ?? 0) > 1) {
                $result['price'] = $data['price'];
            }
            
            // 填充原价
            if (($result['original_price'] ?? 0) <= 1 && ($data['original_price'] ?? 0) > 1) {
                $result['original_price'] = $data['original_price'];
            }
            
            // 填充PLUS专享会员价
            if (($result['plus_price'] ?? 0) <= 1 && ($data['plus_price'] ?? 0) > 1) {
                $result['plus_price'] = $data['plus_price'];
            }
            
            // 填充库存状态
            if ($result['stock_status'] === 'unknown' && !empty($data['stock_status']) && $data['stock_status'] !== 'unknown') {
                $result['stock_status'] = $data['stock_status'];
            }
            
            // 如果已经获取到名称和到手价，继续尝试获取原价（如果还没有）
            if (!empty($result['name']) && $result['price'] > 1) {
                if ($result['original_price'] > 1) {
                    break;
                }
            }
        }
        
        // 如果没有图片且不跳过，尝试从PC端获取
        if (!$skipImage && empty($result['image_url'])) {
            $result['status'] = 'trying_pc_page';
            $pcData = $this->getImageFromPcPage($skuId);
            if (!empty($pcData['image_url'])) {
                $result['image_url'] = $pcData['image_url'];
            }
            if ($result['original_price'] <= 1 && ($pcData['original_price'] ?? 0) > 1) {
                $result['original_price'] = $pcData['original_price'];
            }
            if ($result['plus_price'] <= 1 && ($pcData['plus_price'] ?? 0) > 1) {
                $result['plus_price'] = $pcData['plus_price'];
            }
        }
        
        // 如果原价小于到手价，说明原价获取失败
        if ($result['original_price'] > 0 && $result['original_price'] < $result['price']) {
            $result['original_price'] = $result['price'];
        }
        
        // 如果还是没有原价，使用到手价作为原价
        if ($result['original_price'] <= 0 && $result['price'] > 0) {
            $result['original_price'] = $result['price'];
        }
        
        // PLUS专享价必须低于到手价，否则视为无效
        if ($result['plus_price'] > 0 && $result['price'] > 0 && $result['plus_price'] >= $result['price']) {
            $result['plus_price'] = 0;
        }
        
        // 设置最终状态和错误信息
        if ($result['price'] > 1) {
            $result['status'] = 'success';
            $result['error_type'] = '';
            $result['error_message'] = '';
        } else {
            $result['status'] = 'error';
            // 根据错误类型设置错误信息
            if (in_array('risk_blocked', $methodErrors)) {
                $result['error_type'] = 'risk_blocked';
                $result['error_message'] = $this->errorTypes['risk_blocked'];
            } elseif (in_array('network_timeout', $methodErrors)) {
                $result['error_type'] = 'network_timeout';
                $result['error_message'] = $this->errorTypes['network_timeout'];
            } elseif (in_array('degraded', $methodErrors) && count($methodErrors) == count($methods)) {
                $result['error_type'] = 'risk_blocked';
                $result['error_message'] = '所有价格接口已临时降级，请稍后重试';
            } elseif (empty($result['name']) && $result['price'] <= 0) {
                $result['error_type'] = 'product_offline';
                $result['error_message'] = $this->errorTypes['product_offline'];
            } else {
                $result['error_type'] = 'price_hidden';
                $result['error_message'] = $this->errorTypes['price_hidden'];
            }
        }
        
        return $result;
    }
    
    /**
     * 异步获取商品图片（仅通过PC端获取）
     */
    public function getProductImage($skuId) {
        $data = $this->getImageFromPcPage($skuId);
        return $data['image_url'] ?? '';
    }
    
    /**
     * 方法1: 移动端商品详情API
     */
    private function getProductInfoFromMobile($skuId) {
        $url = "https://api.m.jd.com/client.action?functionId=wareBusiness&appid=m_item_detail&body=" . urlencode(json_encode(['skuId' => $skuId]));
        
        $headers = [
            'User-Agent: ' . self::MOBILE_USER_AGENT,
            'Referer: https://m.jd.com/',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Origin: https://m.jd.com',
        ];
        
        if (!empty($this->cookies)) {
            $headers[] = 'Cookie: ' . $this->cookies;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate',
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['wareInfo'])) {
                $wareInfo = $data['wareInfo'];
                
                // 提取到手价和京东价 - 过滤模糊价格（如 "1?"）
                $finalPrice = floatval($wareInfo['price'] ?? 0);
                $jdPrice = floatval($wareInfo['jdPrice'] ?? 0);
                
                // 过滤掉无效价格（<=1的通常是模糊价格）
                if ($finalPrice <= 1) $finalPrice = 0;
                if ($jdPrice <= 1) $jdPrice = 0;
                
                // 确定当前价格：优先使用到手价，否则使用京东价
                $price = $finalPrice > 0 ? $finalPrice : $jdPrice;
                
                // 确定原价：如果有到手价且京东价大于到手价，则京东价是原价
                $originalPrice = 0;
                if ($finalPrice > 0 && $jdPrice > $finalPrice) {
                    $originalPrice = $jdPrice;
                }
                
                // 提取PLUS专享会员价
                $plusPrice = floatval(
                    $wareInfo['plusPrice']
                    ?? $wareInfo['plus_price']
                    ?? $wareInfo['pPrice']
                    ?? $wareInfo['pprice']
                    ?? 0
                );
                if ($plusPrice <= 1) $plusPrice = 0;
                
                return [
                    'name' => $wareInfo['wname'] ?? '',
                    'image_url' => isset($wareInfo['imageurl']) ? 'https:' . $wareInfo['imageurl'] : '',
                    'price' => $price,
                    'original_price' => $originalPrice,
                    'plus_price' => $plusPrice,
                    'stock_status' => $this->parseStockStatus($wareInfo['StockState'] ?? 0),
                    'stock_num' => $wareInfo['stockNum'] ?? $wareInfo['StockNum'] ?? null,
                    'source' => 'mobile_api'
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 方法2: 移动端页面解析 (item.m.jd.com)
     */
    private function getProductInfoFromMobilePage($skuId) {
        // 使用 item.m.jd.com 而不是 m.jd.com（更稳定）
        $urls = [
            "https://item.m.jd.com/product/{$skuId}.html",
            "https://m.jd.com/product/{$skuId}.html",
        ];
        
        foreach ($urls as $url) {
            $headers = [
                'User-Agent: ' . self::PC_USER_AGENT,
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9',
            ];
            
            if (!empty($this->cookies)) {
                $headers[] = 'Cookie: ' . $this->cookies;
            }
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING => 'gzip, deflate',
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
            ]);
            
            $response = $this->execWithCookieCapture($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            
            // 检查是否被重定向到风险处理页面
            if ($httpCode != 200 || !$response || strpos($finalUrl, 'risk_handler') !== false) {
                continue;
            }
            
            $name = '';
            $image = '';
            $price = 0;
            
            // 提取商品名称 - 优先使用skuName和wname字段
            // 方式1: skuName字段（最准确）
            if (preg_match('/"skuName"\s*:\s*"([^"]+)"/i', $response, $matches)) {
                $name = $matches[1];
            }
            // 方式2: wname字段
            if (empty($name) && preg_match('/"wname"\s*:\s*"([^"]+)"/i', $response, $matches)) {
                $name = $matches[1];
            }
            // 方式3: title标签
            if (empty($name) && preg_match('/<title>([^<]+?)(?:\s*[-_|])/i', $response, $matches)) {
                $name = trim($matches[1]);
            }
            
            // 提取图片 - 优先使用imagePath（第一张图片）
            if (preg_match('/"imagePath"\s*:\s*"([^"]+)"/i', $response, $matches)) {
                $image = 'https://img14.360buyimg.com/' . $matches[1];
            } elseif (preg_match('/"skuImage"\s*:\s*"(\/\/[^"]+)"/i', $response, $matches)) {
                $image = 'https:' . $matches[1];
            } elseif (preg_match('/"imageurl"\s*:\s*"(\/\/[^"]+)"/i', $response, $matches)) {
                $image = 'https:' . $matches[1];
            } elseif (preg_match('/"img"\s*:\s*"(\/\/[^"]+)"/i', $response, $matches)) {
                $image = 'https:' . $matches[1];
            } elseif (preg_match('/"pic"\s*:\s*"(\/\/[^"]+)"/i', $response, $matches)) {
                $image = 'https:' . $matches[1];
            }

            // 提取价格 - 优先使用到手价(price)，其次京东价(jdPrice)
            // price字段通常是促销后的到手价，jdPrice是京东价
            $jdPrice = 0;
            $finalPrice = 0;
            
            // 提取到手价 (price字段) - 过滤掉模糊价格（如 "1?"）
            if (preg_match('/"price"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) {  // 过滤掉无效价格
                    $finalPrice = $val;
                }
            }
            
            // 提取京东价 (jdPrice字段) - 这是原价
            if (preg_match('/"jdPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) {  // 过滤掉无效价格
                    $jdPrice = $val;
                }
            }
            
            // 优先使用到手价，如果没有则使用京东价
            $price = $finalPrice > 1 ? $finalPrice : ($jdPrice > 1 ? $jdPrice : 0);
            
            // 如果都没有，尝试其他字段
            if ($price <= 1 && preg_match('/"m"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) $price = $val;
            }
            if ($price <= 1 && preg_match('/"op"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) $price = $val;
            }
            
            // 确定原价：优先提取日常价(daily字段)，其次使用京东价
            $originalPrice = 0;
            
            // 提取日常价 (daily字段) - 这是商品原价
            if (preg_match('/"daily"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) $originalPrice = $val;
            }
            
            // 如果没有日常价，使用京东价作为原价
            if ($originalPrice <= 1 && $finalPrice > 1 && $jdPrice > $finalPrice) {
                $originalPrice = $jdPrice;
            }
            
            // 提取PLUS专享会员价
            $plusPrice = 0;
            if (preg_match('/"plusPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) $plusPrice = $val;
            }
            if ($plusPrice <= 1 && preg_match('/"plus_price"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) $plusPrice = $val;
            }
            if ($plusPrice <= 1 && preg_match('/"pPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) $plusPrice = $val;
            }
            
            // 提取库存状态
            $stockStatus = 'unknown';
            $stockNum = null;
            
            // 提取库存状态码 (StockState字段)
            if (preg_match('/"StockState"\s*:\s*(\d+)/i', $response, $matches)) {
                $stockStatus = $this->parseStockStatus(intval($matches[1]));
            }
            
            // 提取库存数量 (stockNum字段)
            if (preg_match('/"stockNum"\s*:\s*(\d+)/i', $response, $matches)) {
                $stockNum = intval($matches[1]);
            } elseif (preg_match('/"StockNum"\s*:\s*(\d+)/i', $response, $matches)) {
                $stockNum = intval($matches[1]);
            }
            
            if ($name || $price > 0) {
                return [
                    'name' => $name ?: "商品 {$skuId}",
                    'image_url' => $image,
                    'price' => $price,
                    'original_price' => $originalPrice,
                    'plus_price' => $plusPrice,
                    'stock_status' => $stockStatus,
                    'stock_num' => $stockNum,
                    'source' => 'mobile_page'
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 方法3: 公开价格API（无需登录）
     */
    private function getProductInfoFromPublicApi($skuId) {
        // 获取价格
        $priceUrl = "https://p.3.cn/prices/mgets?skuIds=J_{$skuId}&type=1&pduid=" . time();
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $priceUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::MOBILE_USER_AGENT,
                'Referer: https://m.jd.com/',
            ],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $price = 0;
        if ($response) {
            $priceData = json_decode($response, true);
            if ($priceData && isset($priceData[0]['p'])) {
                $val = floatval($priceData[0]['p']);
                if ($val > 1) $price = $val;
            }
        }
        
        return [
            'name' => "商品 {$skuId}",
            'image_url' => '',
            'price' => $price,
            'original_price' => 0,
            'plus_price' => 0,
            'stock_status' => 'unknown',
            'source' => 'public_api'
        ];
    }
    
    /**
     * 从PC端页面获取商品图片和原价
     */
    private function getImageFromPcPage($skuId) {
        $url = "https://item.jd.com/{$skuId}.html";
        
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml',
        ];
        
        if (!empty($this->cookies)) {
            $headers[] = 'Cookie: ' . $this->cookies;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate, br',
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        curl_close($ch);
        
        $result = ['image_url' => '', 'original_price' => 0, 'plus_price' => 0];
        
        if (!$response) {
            return $result;
        }
        
        // 提取PLUS专享会员价
        if (preg_match('/"plusPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
            $val = floatval($matches[1]);
            if ($val > 1) $result['plus_price'] = $val;
        }
        if ($result['plus_price'] <= 1 && preg_match('/"pPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
            $val = floatval($matches[1]);
            if ($val > 1) $result['plus_price'] = $val;
        }
        
        // 提取原价 - 从imageList字段所在行提取价格信息
        if (preg_match('/imageList\s*:\s*\[(.*?)\]/is', $response, $matches)) {
            $beforeImageList = substr($response, 0, strpos($response, 'imageList'));
            
            if (preg_match_all('/"price"\s*:\s*"?(\d+\.?\d*)"?/i', $beforeImageList, $priceMatches)) {
                $result['original_price'] = floatval(end($priceMatches[1]));
            }
            
            // 提取第一张图片 - avif格式
            if (preg_match('/"([^"]+)"/', $matches[1], $imgMatch)) {
                $imgPath = $imgMatch[1];
                // 图片路径格式: jfs/t1/xxx/xxx.jpg
                // 正确的URL格式: https://img10.360buyimg.com/pcpubliccms/s1440x1440_jfs/t1/xxx.jpg.avif
                $result['image_url'] = 'https://img10.360buyimg.com/pcpubliccms/s1440x1440_' . $imgPath . '.avif';
                return $result;
            }
        }
        
        // 尝试从spec-list获取第一张预览图
        if (preg_match('/<ul id="spec-list"[^>]*>(.*?)<\/ul>/is', $response, $matches)) {
            if (preg_match('/data-url\s*=\s*"([^"]+)"/i', $matches[1], $imgMatch)) {
                $imgPath = $imgMatch[1];
                if (strpos($imgPath, '//') === 0) {
                    $result['image_url'] = 'https:' . $imgPath;
                } elseif (strpos($imgPath, 'jfs/') === 0) {
                    $result['image_url'] = 'https://img14.360buyimg.com/' . $imgPath;
                } else {
                    $result['image_url'] = 'https://img14.360buyimg.com/' . $imgPath;
                }
                return $result;
            }
        }
        
        // 尝试从J-lazy-img获取
        if (preg_match('/J-lazy-img[^>]*data-lazy-img\s*=\s*"([^"]+)"/i', $response, $matches)) {
            $img = $matches[1];
            if (strpos($img, '//') === 0) {
                $result['image_url'] = 'https:' . $img;
            } elseif (strpos($img, 'jfs/') === 0) {
                $result['image_url'] = 'https://img14.360buyimg.com/' . $img;
            } else {
                $result['image_url'] = $img;
            }
            return $result;
        }
        
        // 尝试从preview数组获取
        if (preg_match('/"preview"\s*:\s*\[\s*"([^"]+)"/i', $response, $matches)) {
            $img = $matches[1];
            if (strpos($img, '//') === 0) {
                $result['image_url'] = 'https:' . $img;
            } elseif (strpos($img, 'jfs/') === 0) {
                $result['image_url'] = 'https://img14.360buyimg.com/' . $img;
            } else {
                $result['image_url'] = $img;
            }
            return $result;
        }
        
        return $result;
    }
    
    /**
     * 获取价格 - 优化版本，缩短超时，记录成功率
     */
    public function getPrice($skuId) {
        // 获取方法优先级（根据成功率排序）
        $methods = $this->getPriceMethodPriority();
        
        foreach ($methods as $method) {
            $startTime = microtime(true);
            $price = 0;
            
            switch ($method) {
                case 'pc_page':
                    $price = $this->getPriceFromPcPage($skuId);
                    break;
                case 'mobile_page':
                    $price = $this->getPriceFromMobilePage($skuId);
                    break;
                case 'mobile_api':
                    $price = $this->getPriceFromMobileApi($skuId);
                    break;
                case 'public_api':
                    $price = $this->getPriceFromPublicApi($skuId);
                    break;
            }
            
            $elapsed = microtime(true) - $startTime;
            $this->recordMethodStats($method, $price > 1, $elapsed);
            
            if ($price > 1) {
                return $price;
            }
        }
        
        return 0;
    }
    
    /**
     * 获取价格方法优先级（根据成功率动态调整）
     */
    private function getPriceMethodPriority() {
        $stats = $this->priceMethodStats;
        
        // 计算每个方法的得分（成功率优先，响应时间次之）
        $scores = [];
        foreach ($stats as $method => $data) {
            if ($data['total'] == 0) {
                // 新方法，给予中等优先级
                $scores[$method] = 0.5;
            } else {
                $successRate = $data['success'] / $data['total'];
                // 成功率占70%，响应时间占30%（响应越快分数越高）
                $timeScore = $data['avg_time'] > 0 ? min(1, 2 / $data['avg_time']) : 0.5;
                $scores[$method] = $successRate * 0.7 + $timeScore * 0.3;
            }
        }
        
        // 按得分降序排序
        arsort($scores);
        return array_keys($scores);
    }
    
    /**
     * 记录方法统计信息
     */
    private function recordMethodStats($method, $success, $time) {
        $stats = $this->priceMethodStats[$method];
        $stats['total']++;
        if ($success) {
            $stats['success']++;
        }
        $stats['avg_time'] = (($stats['avg_time'] * ($stats['total'] - 1)) + $time) / $stats['total'];
        $this->priceMethodStats[$method] = $stats;
        
        // 保存到数据库
        $this->saveMethodStats($method, $success, $time);
    }
    
    /**
     * 保存方法统计到数据库
     */
    private function saveMethodStats($method, $success, $time) {
        $existing = $this->db->fetch("SELECT * FROM price_method_stats WHERE method = ?", [$method]);
        
        if ($existing) {
            $newTotal = $existing['total_count'] + 1;
            $newSuccess = $existing['success_count'] + ($success ? 1 : 0);
            $newTime = $existing['total_time'] + $time;
            
            $this->db->execute(
                "UPDATE price_method_stats SET 
                    success_count = ?, 
                    total_count = ?, 
                    total_time = ?, 
                    last_success_at = ?, 
                    updated_at = datetime('now', 'localtime') 
                WHERE method = ?",
                [$newSuccess, $newTotal, $newTime, $success ? date('Y-m-d H:i:s') : $existing['last_success_at'], $method]
            );
        } else {
            $this->db->execute(
                "INSERT INTO price_method_stats (method, success_count, total_count, total_time, last_success_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, datetime('now', 'localtime'))",
                [$method, $success ? 1 : 0, 1, $time, $success ? date('Y-m-d H:i:s') : null]
            );
        }
    }
    
    /**
     * 从PC端页面获取价格
     */
    private function getPriceFromPcPage($skuId) {
        $url = "https://item.jd.com/{$skuId}.html";
        
        $headers = [
            'User-Agent: ' . self::PC_USER_AGENT,
            'Accept: text/html,application/xhtml+xml',
        ];
        
        if (!empty($this->cookies)) {
            $headers[] = 'Cookie: ' . $this->cookies;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate, br',
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        curl_close($ch);
        
        if ($response) {
            // 提取到手价 - 优先price字段
            if (preg_match('/"price"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) return $val;
            }
            // 提取京东价
            if (preg_match('/"jdPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) return $val;
            }
        }
        
        return 0;
    }
    
    /**
     * 从移动端页面获取价格
     */
    private function getPriceFromMobilePage($skuId) {
        $url = "https://item.m.jd.com/product/{$skuId}.html";
        
        $headers = [
            'User-Agent: ' . self::PC_USER_AGENT,
            'Accept: text/html,application/xhtml+xml',
        ];
        
        if (!empty($this->cookies)) {
            $headers[] = 'Cookie: ' . $this->cookies;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        // 检查是否被重定向到风险处理页面
        if (strpos($finalUrl, 'risk_handler') !== false) {
            return 0;
        }
        
        if ($response) {
            // 优先提取到手价 (price字段)，其次京东价 (jdPrice字段)
            if (preg_match('/"price"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) return $val;
            }
            if (preg_match('/"jdPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) return $val;
            }
            if (preg_match('/"m"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $val = floatval($matches[1]);
                if ($val > 1) return $val;
            }
        }
        
        return 0;
    }
    
    /**
     * 通过移动端API获取价格
     */
    private function getPriceFromMobileApi($skuId) {
        $url = "https://api.m.jd.com/client.action?functionId=wareBusiness&appid=m_item_detail&body=" . urlencode(json_encode(['skuId' => $skuId]));
        
        $headers = [
            'User-Agent: ' . self::MOBILE_USER_AGENT,
            'Referer: https://m.jd.com/',
            'Accept: application/json',
        ];
        
        if (!empty($this->cookies)) {
            $headers[] = 'Cookie: ' . $this->cookies;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data['wareInfo']['jdPrice'])) {
                $val = floatval($data['wareInfo']['jdPrice']);
                if ($val > 1) return $val;
            }
            if ($data && isset($data['wareInfo']['price'])) {
                $val = floatval($data['wareInfo']['price']);
                if ($val > 1) return $val;
            }
        }
        
        return 0;
    }
    
    /**
     * 通过公开API获取价格
     */
    private function getPriceFromPublicApi($skuId) {
        $url = "https://p.3.cn/prices/mgets?skuIds=J_{$skuId}&type=1";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::MOBILE_USER_AGENT,
                'Referer: https://m.jd.com/',
            ],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && isset($data[0]['p'])) {
                $val = floatval($data[0]['p']);
                if ($val > 1) return $val;
            }
        }
        
        return 0;
    }
    
    /**
     * 检查Cookie是否有效
     */
    public function checkCookieStatus() {
        if (empty($this->cookies)) {
            return 'not_set';
        }
        
        // 验证Cookie格式 - 必须同时包含pt_key和pt_pin
        if (!preg_match('/pt_key\s*=/i', $this->cookies) || !preg_match('/pt_pin\s*=/i', $this->cookies)) {
            return 'invalid_format';
        }
        
        // 方法1: 通过京东用户信息API验证（最准确，Cookie失效立即返回错误）
        $status = $this->checkCookieByUserInfo();
        if ($status !== 'unknown') {
            return $status;
        }
        
        // 方法2: 通过移动端登录状态API验证
        $status = $this->checkCookieByMobileLogin();
        if ($status !== 'unknown') {
            return $status;
        }
        
        // 方法3: 通过移动端商品页面验证
        $status = $this->checkCookieByMobileProductPage();
        if ($status !== 'unknown') {
            return $status;
        }
        
        // 方法4: 通过获取商品价格API验证
        $status = $this->checkCookieByPriceApi();
        if ($status !== 'unknown') {
            return $status;
        }
        
        // 方法5: 通过移动端首页验证
        return $this->checkCookieByMobilePage();
    }
    
    /**
     * 方法1: 通过京东用户信息API验证（最可靠）
     */
    private function checkCookieByUserInfo() {
        // 京东用户信息接口 - PC端和移动端通用
        $urls = [
            "https://me-api.jd.com/user_new/info/GetJDUserInfoUnion",
            "https://wq.jd.com/user_new/info/GetJDUserInfoUnion",
        ];
        
        foreach ($urls as $url) {
            $headers = [
                'User-Agent: ' . self::MOBILE_USER_AGENT,
                'Referer: https://m.jd.com/',
                'Cookie: ' . $this->cookies,
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Origin: https://m.jd.com',
            ];
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING => 'gzip, deflate',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            
            $response = $this->execWithCookieCapture($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            // 记录调试日志
            error_log("JD Cookie Check - UserInfo API ($url): HTTP {$httpCode}, Error: {$error}, Response: " . substr($response ?: '', 0, 500));
            
            if ($httpCode == 200 && $response) {
                $data = json_decode($response, true);
                
                // 检查返回码 - retcode 为 0 或 "0" 表示登录成功
                if (isset($data['retcode'])) {
                    if ($data['retcode'] === 0 || $data['retcode'] === "0") {
                        return 'valid';
                    }
                    // retcode 为其他特定值表示未登录或Cookie失效
                    if (in_array($data['retcode'], [1, "1", 1001, "1001", 1002, "1002", 13, "13"])) {
                        return 'invalid';
                    }
                }
                
                // 检查是否有用户信息
                if (isset($data['data']['userInfo']) || isset($data['data']['baseInfo']) || isset($data['data']['nickname'])) {
                    return 'valid';
                }
                
                // 检查是否明确返回未登录
                if (isset($data['msg']) && stripos($data['msg'], '请登录') !== false) {
                    return 'invalid';
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * 方法2: 通过移动端登录状态API验证（直接查询登录状态）
     */
    private function checkCookieByMobileLogin() {
        $url = "https://plogin.m.jd.com/cgi-bin/ml/islogin";
        
        $headers = [
            'User-Agent: ' . self::MOBILE_USER_AGENT,
            'Referer: https://m.jd.com/',
            'Cookie: ' . $this->cookies,
            'Accept: application/json',
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("JD Cookie Check - MobileLogin API: HTTP {$httpCode}, Response: " . substr($response ?: '', 0, 200));
        
        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            
            // 检查登录状态
            if (isset($data['islogin'])) {
                return $data['islogin'] === '1' || $data['islogin'] === 1 ? 'valid' : 'invalid';
            }
        }
        
        return 'unknown';
    }
    
    /**
     * 方法5: 通过移动端首页验证（最后备选）
     */
    private function checkCookieByMobilePage() {
        $url = "https://m.jd.com/";
        
        $headers = [
            'User-Agent: ' . self::MOBILE_USER_AGENT,
            'Cookie: ' . $this->cookies,
            'Accept: text/html,application/xhtml+xml',
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("JD Cookie Check - Mobile Page: HTTP {$httpCode}, Response length: " . strlen($response ?: ''));
        
        if ($response) {
            // 检查是否有用户名/昵称信息
            if (preg_match('/"nickName"\s*:\s*"([^"]+)"/', $response, $matches) && !empty($matches[1])) {
                return 'valid';
            }
            // 检查登录状态标记
            if (preg_match('/"isLogin"\s*:\s*true/i', $response)) {
                return 'valid';
            }
            // 检查用户名
            if (preg_match('/"userName"\s*:\s*"([^"]+)"/', $response, $matches) && !empty($matches[1])) {
                return 'valid';
            }
            // 检查pin（用户标识）
            if (preg_match('/"pin"\s*:\s*"([^"]+)"/', $response, $matches) && !empty($matches[1])) {
                return 'valid';
            }
        }
        
        return 'unknown';
    }
    
    /**
     * 方法4: 通过获取商品价格验证Cookie（可能受风控影响）
     */
    private function checkCookieByPriceApi() {
        // 使用一个热门商品ID测试
        $testSkuId = '100012043978'; // iPhone 15
        
        $url = "https://api.m.jd.com/client.action?functionId=wareBusiness&appid=m_item_detail&body=" . urlencode(json_encode(['skuId' => $testSkuId]));
        
        $headers = [
            'User-Agent: ' . self::MOBILE_USER_AGENT,
            'Referer: https://m.jd.com/',
            'Cookie: ' . $this->cookies,
            'Accept: application/json',
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("JD Cookie Check - Price API: HTTP {$httpCode}, Response: " . substr($response ?: '', 0, 500));
        
        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            
            // 如果能获取到商品信息，说明Cookie有效
            if (isset($data['wareInfo'])) {
                // 检查是否有会员价等需要登录才能看到的信息
                if (isset($data['wareInfo']['jdPrice']) || isset($data['wareInfo']['price'])) {
                    return 'valid';
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * 方法3: 通过移动端商品页面验证（可能受风控影响）
     */
    private function checkCookieByMobileProductPage() {
        $testSkuId = '100012043978'; // iPhone 15
        $url = "https://item.m.jd.com/product/{$testSkuId}.html";
        
        $headers = [
            'User-Agent: ' . self::PC_USER_AGENT,
            'Accept: text/html,application/xhtml+xml',
            'Cookie: ' . $this->cookies,
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        // 如果被重定向到风险处理页面，说明Cookie无效
        if (strpos($finalUrl, 'risk_handler') !== false) {
            return 'invalid';
        }
        
        if ($httpCode == 200 && $response) {
            // 检查是否能获取到有效价格（大于1）
            if (preg_match('/"price"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $price = floatval($matches[1]);
                if ($price > 1) {
                    return 'valid';
                }
            }
            if (preg_match('/"jdPrice"\s*:\s*"?(\d+\.?\d*)"?/i', $response, $matches)) {
                $price = floatval($matches[1]);
                if ($price > 1) {
                    return 'valid';
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * 解析库存状态
     */
    private function parseStockStatus($code) {
        $statusMap = [
            0 => 'unknown',
            1 => 'in_stock',      // 现货
            2 => 'low_stock',     // 库存紧张
            3 => 'out_of_stock',  // 无货
            4 => 'presale',       // 预售
            33 => 'in_stock',     // 现货（移动端）
            34 => 'low_stock',    // 库存紧张（移动端）
            36 => 'out_of_stock', // 无货（移动端）
        ];
        
        return $statusMap[$code] ?? 'unknown';
    }
    
    /**
     * 获取京东用户信息
     * @return array|null 用户信息数组，失败返回null
     */
    public function getUserInfo() {
        if (empty($this->cookies)) {
            return null;
        }
        
        // 验证Cookie格式
        if (!preg_match('/pt_key\s*=/i', $this->cookies) || !preg_match('/pt_pin\s*=/i', $this->cookies)) {
            return null;
        }
        
        $url = "https://me-api.jd.com/user_new/info/GetJDUserInfoUnion";
        
        $headers = [
            'User-Agent: ' . self::MOBILE_USER_AGENT,
            'Referer: https://m.jd.com/',
            'Cookie: ' . $this->cookies,
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING => 'gzip, deflate',
        ]);
        
        $response = $this->execWithCookieCapture($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200 && $response) {
            $data = json_decode($response, true);
            
            // 检查返回码
            if (isset($data['retcode']) && ($data['retcode'] === '0' || $data['retcode'] === 0)) {
                $userInfo = $data['data']['userInfo'] ?? [];
                $baseInfo = $userInfo['baseInfo'] ?? [];
                $assetInfo = $data['data']['assetInfo'] ?? [];
                
                // 京豆数量
                $beanNum = $assetInfo['beanNum'] ?? 0;
                
                // Plus会员状态
                $isPlusVip = ($userInfo['isPlusVip'] ?? '0') === '1' || ($userInfo['isPlusVip'] ?? 0) === 1;
                
                // Plus会员到期时间
                $plusExpireTime = '';
                if (isset($data['data']['JdVvipInfo']['jdVvipExpireTime'])) {
                    $plusExpireTime = $data['data']['JdVvipInfo']['jdVvipExpireTime'];
                } elseif (isset($userInfo['plusExpireTime'])) {
                    $plusExpireTime = $userInfo['plusExpireTime'];
                }
                
                // 成长值具体数值
                $growthValue = intval(
                    $baseInfo['growthValue']
                    ?? $baseInfo['userGrowthValue']
                    ?? $baseInfo['growth']
                    ?? $userInfo['growthValue']
                    ?? $userInfo['userGrowthValue']
                    ?? 0
                );
                
                // 京享值
                $jdShareScore = intval(
                    $data['data']['jdShareScore']
                    ?? $baseInfo['jdShareScore']
                    ?? $userInfo['jdShareScore']
                    ?? $data['data']['shareScore']
                    ?? $baseInfo['shareScore']
                    ?? $userInfo['shareScore']
                    ?? 0
                );
                
                return [
                    'nickname' => $baseInfo['nickname'] ?? '',
                    'levelName' => $baseInfo['levelName'] ?? '',
                    'isPlusVip' => $isPlusVip,
                    'plusExpireTime' => $plusExpireTime,
                    'userLevel' => $baseInfo['userLevel'] ?? '0',
                    'headImage' => $baseInfo['headImageUrl'] ?? '',
                    'beanNum' => $beanNum,
                    'growthValue' => $growthValue,
                    'jdShareScore' => $jdShareScore,
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 获取最后错误
     */
    public function getLastError() {
        return $this->lastError;
    }
}
