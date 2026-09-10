<?php
/**
 * 京东反爬增强模块
 * - 稳定设备指纹（MacBook Pro）
 * - Cookie 分域存储
 * - TLS 指纹模拟
 * - Header 顺序模拟
 * - 行为多样性（库存/评价接口）
 * - 接口失败降级
 */

require_once __DIR__ . '/db.php';

/**
 * 设备指纹生成器 - 固定 MacBook Pro 配置
 */
class JdDeviceFingerprint {
    // 固定设备指纹（生成一次后持久化）
    private static $fingerprint = null;
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureFingerprint();
    }

    /**
     * 确保有稳定的设备指纹，生成后存入数据库
     */
    private function ensureFingerprint() {
        if (self::$fingerprint !== null) {
            return;
        }

        $row = $this->db->fetch("SELECT setting_value FROM system_settings WHERE setting_key = 'device_fingerprint'");
        if ($row && !empty($row['setting_value'])) {
            self::$fingerprint = json_decode($row['setting_value'], true);
            if (is_array(self::$fingerprint)) {
                return;
            }
        }

        // 生成全新的固定设备指纹
        self::$fingerprint = $this->generateMacBookProFingerprint();

        // 存入数据库
        $json = json_encode(self::$fingerprint, JSON_UNESCAPED_UNICODE);
        $existing = $this->db->fetch("SELECT setting_key FROM system_settings WHERE setting_key = 'device_fingerprint'");
        if ($existing) {
            $this->db->execute(
                "UPDATE system_settings SET setting_value = ?, updated_at = datetime('now', 'localtime') WHERE setting_key = 'device_fingerprint'",
                [$json]
            );
        } else {
            $this->db->execute(
                "INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('device_fingerprint', ?, datetime('now', 'localtime'))",
                [$json]
            );
        }
    }

    /**
     * 生成 MacBook Pro 设备指纹
     */
    private function generateMacBookProFingerprint() {
        // 生成稳定的随机标识（基于 mt_rand 但只生成一次）
        $mtSeed = hexdec(substr(md5('jd_macbook_pro_' . time()), 0, 8));
        mt_srand($mtSeed);

        $fingerprint = [
            // === 浏览器 UA（MacBook Pro + Chrome） ===
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            'sec_ch_ua' => '"Google Chrome";v="125", "Chromium";v="125", "Not-A.Brand";v="24"',
            'sec_ch_ua_mobile' => '?0',
            'sec_ch_ua_platform' => '"macOS"',

            // === 设备信息（MacBook Pro 14寸） ===
            'platform' => 'MacIntel',
            'os_version' => '10.15.7', // navigator.appVersion
            'screen_width' => 1512,
            'screen_height' => 982,
            'screen_color_depth' => 30,
            'screen_pixel_depth' => 30,
            'device_pixel_ratio' => 2.0,
            'avail_width' => 1512,
            'avail_height' => 932,

            // === 时区 & 语言 ===
            'timezone' => 'Asia/Shanghai',
            'timezone_offset' => -480, // GMT+8
            'language' => 'zh-CN',
            'languages' => 'zh-CN,zh,en',

            // === 硬件信息 ===
            'hardware_concurrency' => 10, // M1 Pro 10核
            'device_memory' => 16, // GB
            'max_touch_points' => 0,

            // === WebGL 指纹 ===
            'webgl_vendor' => 'Google Inc. (Apple)',
            'webgl_renderer' => 'ANGLE (Apple, Apple M1 Pro, OpenGL 4.1)',
            'webgl_version' => 'WebGL 1.0',

            // === Canvas 指纹种子 ===
            'canvas_seed' => $this->generateCanvasSeed(),

            // === 字体列表 ===
            'fonts' => $this->getMacFontList(),

            // === 网络 ===
            'connection_type' => '4g',
            'downlink' => 10,

            // === 音频指纹 ===
            'audio_base_latency' => 0.01,
            'sample_rate' => 48000,

            // === 京东专用设备ID ===
            '__jda' => $this->generateJdaCookie(),
            'guid' => $this->generateGuid(),
            'md5_fp' => md5($mtSeed . 'jd_fingerprint'),
        ];

        mt_srand(); // 重置随机种子
        return $fingerprint;
    }

    /**
     * 生成 __jda Cookie（京东设备标识）
     * 格式: __jda=122270672.时间戳.IP.时间戳.时间戳.1;
     */
    private function generateJdaCookie() {
        $randomId = str_pad((string)mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
        $visitTime = time() - mt_rand(86400 * 30, 86400 * 90);
        $ip = mt_rand(1, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
        $lastVisit = $visitTime;
        $openId = $visitTime;
        return "{$randomId}.{$visitTime}.{$ip}.{$lastVisit}.{$openId}.1";
    }

    /**
     * 生成 guid（登录页跟踪）
     */
    private function generateGuid() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Canvas 指纹种子
     */
    private function generateCanvasSeed() {
        return base64_encode(substr(md5(mt_rand() . 'canvas_jd'), 0, 32));
    }

    /**
     * Mac 系统常见字体列表
     */
    private function getMacFontList() {
        return [
            'Arial', 'Arial Black', 'Arial Narrow', 'Arial Rounded MT Bold',
            'Helvetica', 'Helvetica Neue', 'Times New Roman', 'Times',
            'Courier New', 'Courier', 'Verdana', 'Georgia',
            'Palatino', 'Garamond', 'Bookman', 'Avant Garde',
            'Comic Sans MS', 'Impact', 'Charcoal', 'Chicago',
            'Geneva', 'Monaco', 'Trebuchet MS', 'Lucida Console',
            'Tahoma', 'Symbol', 'Wingdings', 'Wingdings 2',
            'Wingdings 3', 'ITC Zapf Dingbats', 'Apple Chancery',
            'Baskerville', 'Big Caslon', 'Copperplate', 'Futura',
            'Gill Sans', 'Herculanum', 'Hoefler Text', 'Optima',
            'Papyrus', 'Didot', 'American Typewriter', 'Andale Mono',
            'Brush Script MT', 'Luminari', 'Marker Felt', 'Phosphate',
            'STHeiti', 'STXihei', 'STKaiti', 'STFangsong',
            'Songti SC', 'Heiti SC', 'Kaiti SC', 'Yuanti SC',
            'PingFang SC', 'Hiragino Sans GB',
        ];
    }

    /**
     * 获取完整指纹
     */
    public function getFingerprint() {
        return self::$fingerprint;
    }

    /**
     * 获取 UA
     */
    public function getUserAgent() {
        return self::$fingerprint['user_agent'];
    }

    /**
     * 获取京东设备Cookie（__jda/__jdb/__jdc/__jdu）
     * 模拟真实浏览器的生成逻辑
     */
    public function getJdDeviceCookies() {
        $fp = self::$fingerprint;
        $jda = $fp['__jda'];
        $now = time();

        // 解析 jda 获取基础ID
        $parts = explode('.', $jda);
        $deviceId = $parts[0] ?? '122270672';

        return [
            '__jda' => $jda,
            '__jdb' => "{$deviceId}.{$now}." . mt_rand(1, 99) . '.' . mt_rand(1, 99),
            '__jdc' => $deviceId,
            '__jdu' => $fp['md5_fp'],
            '__jdv' => "{$deviceId}|direct|-|none|-|{$now}",
            'guid' => $fp['guid'],
            '_t' => (string)$now,
        ];
    }
}

/**
 * Cookie 分域管理器
 * 支持 .jd.com / .3.cn 等多域名Cookie隔离
 */
class JdCookieJar {
    private $db;
    private $cookies = []; // 按域名存储: ['jd.com' => [...], '3.cn' => [...]]
    private $domainMap = [
        'jd.com' => ['jd.com', 'api.m.jd.com', 'item.jd.com', 'm.jd.com', 'item.m.jd.com',
                     'p.3.cn', 'me-api.jd.com', 'wq.jd.com', 'plogin.m.jd.com',
                     'cart.jd.com', 'search.jd.com', 'u.jd.com'],
        '3.cn' => ['3.cn'],
    ];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadCookies();
    }

    /**
     * 从数据库加载Cookie
     */
    private function loadCookies() {
        $settings = $this->db->fetch("SELECT jd_cookies FROM settings WHERE id = 1");
        if ($settings && !empty($settings['jd_cookies'])) {
            // 旧格式：整串Cookie字符串，默认归入 jd.com
            $parsed = $this->parseCookieString($settings['jd_cookies']);
            if (!isset($this->cookies['jd.com'])) {
                $this->cookies['jd.com'] = [];
            }
            $this->cookies['jd.com'] = array_merge($this->cookies['jd.com'], $parsed);
        }

        // 加载分域Cookie
        $rows = $this->db->fetchAll("SELECT domain, cookie_data FROM domain_cookies");
        foreach ($rows as $row) {
            $data = json_decode($row['cookie_data'], true);
            if (is_array($data)) {
                $this->cookies[$row['domain']] = $data;
            }
        }
    }

    /**
     * 解析Cookie字符串
     */
    private function parseCookieString($str) {
        $cookies = [];
        foreach (explode(';', $str) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $eqPos = strpos($part, '=');
            if ($eqPos === false) continue;
            $name = trim(substr($part, 0, $eqPos));
            $value = trim(substr($part, $eqPos + 1));
            if ($name !== '') {
                $cookies[$name] = $value;
            }
        }
        return $cookies;
    }

    /**
     * 根据URL获取对应域名的Cookie
     */
    public function getCookiesForUrl($url) {
        $domain = $this->extractDomain($url);
        $topDomain = $this->getTopDomain($domain);
        return $this->cookies[$topDomain] ?? [];
    }

    /**
     * 获取某个顶级域的Cookie字符串
     */
    public function getCookieStringForUrl($url) {
        $cookies = $this->getCookiesForUrl($url);
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }

    /**
     * 设置Cookie到指定域名
     */
    public function setCookie($url, $name, $value) {
        if ($value === '' || strtolower($value) === 'deleted') {
            return;
        }
        $domain = $this->extractDomain($url);
        $topDomain = $this->getTopDomain($domain);

        if (!isset($this->cookies[$topDomain])) {
            $this->cookies[$topDomain] = [];
        }
        $this->cookies[$topDomain][$name] = $value;
        $this->persistDomainCookies($topDomain);
    }

    /**
     * 批量设置Cookie
     */
    public function setCookies($url, $cookies) {
        foreach ($cookies as $name => $value) {
            $this->setCookie($url, $name, $value);
        }
    }

    /**
     * 合并 Set-Cookie 响应头中的Cookie
     */
    public function mergeSetCookieHeaders($url, $setCookieHeaders) {
        $domain = $this->extractDomain($url);
        $topDomain = $this->getTopDomain($domain);

        $newCookies = [];
        foreach ($setCookieHeaders as $headerLine) {
            if (preg_match('/^\s*([^;=\s]+)\s*=\s*([^;]*)/i', $headerLine, $m)) {
                $name = trim($m[1]);
                $value = trim($m[2]);
                if ($value !== '' && strtolower($value) !== 'deleted') {
                    $newCookies[$name] = $value;
                }
            }
        }

        if (!empty($newCookies)) {
            if (!isset($this->cookies[$topDomain])) {
                $this->cookies[$topDomain] = [];
            }

            // 检查登录态Cookie是否变化
            $loginCookies = ['pt_key', 'pt_pin', 'pt_token', 'thor', 'sso_uc'];
            $loginChanged = false;
            foreach ($loginCookies as $lc) {
                if (isset($newCookies[$lc]) &&
                    (!isset($this->cookies[$topDomain][$lc]) ||
                     $this->cookies[$topDomain][$lc] !== $newCookies[$lc])) {
                    $loginChanged = true;
                    break;
                }
            }

            $this->cookies[$topDomain] = array_merge($this->cookies[$topDomain], $newCookies);
            $this->persistDomainCookies($topDomain);

            // 登录态变化时同步更新 settings 表的主Cookie（保持向后兼容）
            if ($loginChanged && $topDomain === 'jd.com') {
                $cookieStr = $this->getCookieStringForUrl('https://jd.com');
                $this->db->execute(
                    "UPDATE settings SET jd_cookies = ?, cookie_status = 'unknown', updated_at = datetime('now', 'localtime') WHERE id = 1",
                    [$cookieStr]
                );
            }
        }
    }

    /**
     * 持久化某个域的Cookie到数据库
     */
    private function persistDomainCookies($topDomain) {
        if (!isset($this->cookies[$topDomain])) return;

        $data = json_encode($this->cookies[$topDomain], JSON_UNESCAPED_UNICODE);
        $existing = $this->db->fetch("SELECT domain FROM domain_cookies WHERE domain = ?", [$topDomain]);
        if ($existing) {
            $this->db->execute(
                "UPDATE domain_cookies SET cookie_data = ?, updated_at = datetime('now', 'localtime') WHERE domain = ?",
                [$data, $topDomain]
            );
        } else {
            $this->db->execute(
                "INSERT INTO domain_cookies (domain, cookie_data, updated_at) VALUES (?, ?, datetime('now', 'localtime'))",
                [$topDomain, $data]
            );
        }
    }

    /**
     * 从URL提取完整域名
     */
    private function extractDomain($url) {
        $parsed = parse_url($url);
        return $parsed['host'] ?? '';
    }

    /**
     * 获取顶级域名（用于Cookie分域）
     */
    private function getTopDomain($domain) {
        // 精确匹配短域名优先（如 3.cn）
        $shortDomains = ['3.cn', 'jd.cn'];
        foreach ($shortDomains as $sd) {
            // 精确匹配或以 . 开头的子域名匹配
            if ($domain === $sd || substr($domain, -(strlen($sd) + 1)) === '.' . $sd) {
                return $sd;
            }
        }

        // jd.com 家族域名
        $jdSuffix = '.jd.com';
        if ($domain === 'jd.com' || substr($domain, -strlen($jdSuffix)) === $jdSuffix) {
            return 'jd.com';
        }

        // 默认按二级域名处理
        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            return $parts[count($parts)-2] . '.' . $parts[count($parts)-1];
        }
        return $domain;
    }

    /**
     * 获取 jd.com 域的Cookie字符串（向后兼容）
     */
    public function getMainCookieString() {
        return $this->getCookieStringForUrl('https://item.jd.com/');
    }

    /**
     * 注入设备跟踪Cookie（如果不存在）
     */
    public function ensureDeviceCookies($deviceFp) {
        $deviceCookies = $deviceFp->getJdDeviceCookies();
        $jdCookies = $this->cookies['jd.com'] ?? [];

        $needUpdate = false;
        foreach ($deviceCookies as $name => $value) {
            if (!isset($jdCookies[$name]) || empty($jdCookies[$name])) {
                $jdCookies[$name] = $value;
                $needUpdate = true;
            }
        }

        if ($needUpdate) {
            $this->cookies['jd.com'] = $jdCookies;
            $this->persistDomainCookies('jd.com');
        }
    }
}

/**
 * 请求伪造器 - TLS指纹 + Header顺序 + 行为模拟
 */
class JdRequestForgery {
    private $deviceFp;
    private $cookieJar;

    // Chrome 浏览器加密套件顺序（模拟 Chrome 125 的 TLS 指纹）
    // 注意：PHP curl 的 cipher_list 只控制 TLS 1.2 及以下，TLS 1.3 需要 CURLOPT_SSL_CIPHER_LIST + TLS 1.3 ciphersuites
    const CHROME_CIPHERS = 'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES256-SHA384:ECDHE-RSA-AES256-SHA384';

    public function __construct($deviceFp, $cookieJar) {
        $this->deviceFp = $deviceFp;
        $this->cookieJar = $cookieJar;
    }

    /**
     * 构建 Chrome 风格的请求头（严格按浏览器顺序）
     * @param string $url 请求URL
     * @param string $type 请求类型: 'html' | 'json' | 'image' | 'script'
     * @param string $referer 来源页
     * @param array $extraHeaders 额外Header
     * @return array 按顺序排列的Header数组
     */
    public function buildHeaders($url, $type = 'html', $referer = '', $extraHeaders = []) {
        $fp = $this->deviceFp->getFingerprint();
        $headers = [];

        // === Chrome 请求头顺序（严格模拟） ===
        // 1. sec-ch-ua 系列
        $headers[] = 'sec-ch-ua: ' . $fp['sec_ch_ua'];
        $headers[] = 'sec-ch-ua-platform: ' . $fp['sec_ch_ua_platform'];
        $headers[] = 'sec-ch-ua-mobile: ' . $fp['sec_ch_ua_mobile'];

        // 2. User-Agent
        $headers[] = 'User-Agent: ' . $fp['user_agent'];

        // 3. Accept（根据类型变化）
        switch ($type) {
            case 'json':
                $headers[] = 'Accept: application/json, text/plain, */*';
                break;
            case 'image':
                $headers[] = 'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';
                break;
            case 'script':
                $headers[] = 'Accept: */*';
                break;
            default: // html
                $headers[] = 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7';
                break;
        }

        // 4. sec-fetch 相关
        switch ($type) {
            case 'json':
                $headers[] = 'sec-fetch-site: same-site';
                $headers[] = 'sec-fetch-mode: cors';
                $headers[] = 'sec-fetch-dest: empty';
                break;
            case 'image':
                $headers[] = 'sec-fetch-site: same-site';
                $headers[] = 'sec-fetch-mode: no-cors';
                $headers[] = 'sec-fetch-dest: image';
                break;
            default:
                $headers[] = 'sec-fetch-site: none';
                $headers[] = 'sec-fetch-mode: navigate';
                $headers[] = 'sec-fetch-user: ?1';
                $headers[] = 'sec-fetch-dest: document';
                break;
        }

        // 5. Accept-Language
        $headers[] = 'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8';

        // 6. Accept-Encoding
        $headers[] = 'Accept-Encoding: gzip, deflate, br';

        // 7. Referer（如果有）
        if ($referer) {
            $headers[] = 'Referer: ' . $referer;
        }

        // 8. Cookie
        $cookieStr = $this->cookieJar->getCookieStringForUrl($url);
        if ($cookieStr) {
            $headers[] = 'Cookie: ' . $cookieStr;
        }

        // 9. 额外Header（追加到最后）
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        return $headers;
    }

    /**
     * 配置 curl 的 TLS 和基础选项（模拟 Chrome）
     * 注意：Windows PHP 环境通常缺少 CA 根证书，SSL验证默认关闭以保持兼容
     */
    public function configureCurlTls($ch) {
        // 支持 TLS 1.2 和 1.3
        if (defined('CURL_SSLVERSION_MAX_TLSv1_3')) {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_3);
        } else {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        }

        // 模拟 Chrome 加密套件顺序（JA3指纹关键部分）
        curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, self::CHROME_CIPHERS);

        // ALPN 扩展（HTTP/2 + HTTP/1.1）
        if (defined('CURLOPT_SSL_ENABLE_ALPN')) {
            curl_setopt($ch, CURLOPT_SSL_ENABLE_ALPN, true);
        }
        if (defined('CURLOPT_SSL_ENABLE_NPN')) {
            curl_setopt($ch, CURLOPT_SSL_ENABLE_NPN, true);
        }

        // Windows PHP 环境通常缺少 CA 证书，保持 SSL 验证关闭以兼容
        // TLS指纹模拟的核心是加密套件顺序和Header顺序，而非证书验证
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    /**
     * 执行请求并捕获 Set-Cookie
     */
    public function execRequest($ch, $url) {
        $setCookieHeaders = [];

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$setCookieHeaders) {
            if (stripos(trim($headerLine), 'Set-Cookie:') === 0) {
                $setCookieHeaders[] = $headerLine;
            }
            return strlen($headerLine);
        });

        $response = curl_exec($ch);

        // 合并Cookie
        if (!empty($setCookieHeaders)) {
            $this->cookieJar->mergeSetCookieHeaders($url, $setCookieHeaders);
        }

        return $response;
    }
}

/**
 * 行为多样性控制器
 * - 价格接口前先访问商品页
 * - 偶尔调用库存/评价接口
 */
class JdBehaviorSimulator {
    private $requestForgery;
    private $cookieJar;
    private $deviceFp;

    // 行为概率配置
    const STOCK_CHECK_PROBABILITY = 0.3;  // 30% 概率查库存
    const COMMENT_CHECK_PROBABILITY = 0.2; // 20% 概率查评价
    const PAGE_STAY_MIN = 1;  // 页面停留最小秒数
    const PAGE_STAY_MAX = 3;  // 页面停留最大秒数

    public function __construct($requestForgery, $cookieJar, $deviceFp) {
        $this->requestForgery = $requestForgery;
        $this->cookieJar = $cookieJar;
        $this->deviceFp = $deviceFp;
    }

    /**
     * 价格接口前置：先访问商品详情页
     * 这是必要步骤，模拟真实用户行为
     */
    public function preVisitProductPage($skuId) {
        $urls = [
            "https://item.jd.com/{$skuId}.html",
            "https://item.m.jd.com/product/{$skuId}.html",
        ];

        // 随机选一个入口
        $url = $urls[array_rand($urls)];

        $headers = $this->requestForgery->buildHeaders(
            $url,
            'html',
            'https://www.jd.com/'
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING => 'gzip, deflate, br',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HEADER => false,
        ]);
        $this->requestForgery->configureCurlTls($ch);

        $response = $this->requestForgery->execRequest($ch, $url);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 模拟页面停留
        $stayTime = rand(self::PAGE_STAY_MIN, self::PAGE_STAY_MAX);
        usleep($stayTime * 100000); // 实际用100-300ms（加速模式）

        return [
            'success' => $httpCode == 200,
            'http_code' => $httpCode,
            'url' => $url,
            'stay_ms' => $stayTime * 100,
        ];
    }

    /**
     * 随机行为：库存查询
     */
    public function maybeCheckStock($skuId, $area = '1_72_2799_0') {
        if (mt_rand() / mt_getrandmax() > self::STOCK_CHECK_PROBABILITY) {
            return ['skipped' => true];
        }

        $url = "https://c0.3.cn/stock?skuId={$skuId}&area={$area}&buyNum=1&ch=1&callback=jQuery" . time();

        $headers = $this->requestForgery->buildHeaders(
            $url,
            'script',
            "https://item.jd.com/{$skuId}.html"
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_REFERER => "https://item.jd.com/{$skuId}.html",
        ]);
        $this->requestForgery->configureCurlTls($ch);

        $response = $this->requestForgery->execRequest($ch, $url);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 解析库存状态
        $stockStatus = 'unknown';
        if ($response && preg_match('/"StockState"\s*:\s*(\d+)/', $response, $m)) {
            $code = intval($m[1]);
            $stockStatus = $this->parseStockCode($code);
        }

        return [
            'success' => $httpCode == 200,
            'http_code' => $httpCode,
            'stock_status' => $stockStatus,
        ];
    }

    /**
     * 随机行为：评价查询
     */
    public function maybeCheckComments($skuId) {
        if (mt_rand() / mt_getrandmax() > self::COMMENT_CHECK_PROBABILITY) {
            return ['skipped' => true];
        }

        $url = "https://club.jd.com/comment/productCommentSummaries.action?referenceIds={$skuId}&callback=jQuery" . time();

        $headers = $this->requestForgery->buildHeaders(
            $url,
            'script',
            "https://item.jd.com/{$skuId}.html"
        );

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_ENCODING => 'gzip, deflate',
            CURLOPT_REFERER => "https://item.jd.com/{$skuId}.html",
        ]);
        $this->requestForgery->configureCurlTls($ch);

        $response = $this->requestForgery->execRequest($ch, $url);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $commentCount = 0;
        if ($response && preg_match('/"CommentCount"\s*:\s*(\d+)/', $response, $m)) {
            $commentCount = intval($m[1]);
        }

        return [
            'success' => $httpCode == 200,
            'http_code' => $httpCode,
            'comment_count' => $commentCount,
        ];
    }

    private function parseStockCode($code) {
        $map = [
            0 => 'unknown',
            1 => 'in_stock',
            2 => 'low_stock',
            3 => 'out_of_stock',
            4 => 'presale',
            33 => 'in_stock',
            34 => 'low_stock',
            36 => 'out_of_stock',
        ];
        return $map[$code] ?? 'unknown';
    }
}

/**
 * 接口降级管理器
 * 连续失败3次自动降级，1小时后恢复
 */
class JdApiDegradation {
    private $db;

    // 降级配置
    const FAIL_THRESHOLD = 3;      // 连续失败阈值
    const DEGRADE_DURATION = 3600;  // 降级持续时间（秒）= 1小时
    const TABLE = 'api_degradation';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTable();
    }

    private function ensureTable() {
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                api_name TEXT UNIQUE NOT NULL,
                fail_count INTEGER DEFAULT 0,
                is_degraded INTEGER DEFAULT 0,
                degraded_at DATETIME,
                recover_at DATETIME,
                last_fail_at DATETIME,
                last_success_at DATETIME,
                total_fail INTEGER DEFAULT 0,
                total_success INTEGER DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * 检查接口是否可用（未被降级）
     */
    public function isAvailable($apiName) {
        $row = $this->db->fetch(
            "SELECT is_degraded, recover_at FROM " . self::TABLE . " WHERE api_name = ?",
            [$apiName]
        );

        if (!$row) {
            return true;
        }

        if ($row['is_degraded']) {
            // 检查降级时间是否已过
            if ($row['recover_at'] && strtotime($row['recover_at']) <= time()) {
                // 自动恢复
                $this->resetDegradation($apiName);
                return true;
            }
            return false;
        }

        return true;
    }

    /**
     * 记录一次成功
     */
    public function recordSuccess($apiName) {
        $row = $this->db->fetch(
            "SELECT * FROM " . self::TABLE . " WHERE api_name = ?",
            [$apiName]
        );

        if ($row) {
            $this->db->execute(
                "UPDATE " . self::TABLE . " SET 
                    fail_count = 0,
                    is_degraded = 0,
                    degraded_at = NULL,
                    recover_at = NULL,
                    last_success_at = datetime('now', 'localtime'),
                    total_success = total_success + 1,
                    updated_at = datetime('now', 'localtime')
                WHERE api_name = ?",
                [$apiName]
            );
        } else {
            $this->db->execute(
                "INSERT INTO " . self::TABLE . " 
                    (api_name, fail_count, is_degraded, last_success_at, total_success, updated_at)
                    VALUES (?, 0, 0, datetime('now', 'localtime'), 1, datetime('now', 'localtime'))",
                [$apiName]
            );
        }
    }

    /**
     * 记录一次失败
     * @return bool 是否已触发降级
     */
    public function recordFailure($apiName) {
        $row = $this->db->fetch(
            "SELECT * FROM " . self::TABLE . " WHERE api_name = ?",
            [$apiName]
        );

        if (!$row) {
            $this->db->execute(
                "INSERT INTO " . self::TABLE . " 
                    (api_name, fail_count, is_degraded, last_fail_at, total_fail, updated_at)
                    VALUES (?, 1, 0, datetime('now', 'localtime'), 1, datetime('now', 'localtime'))",
                [$apiName]
            );
            return false;
        }

        $newFailCount = $row['fail_count'] + 1;
        $isDegraded = 0;
        $degradedAt = null;
        $recoverAt = null;

        if ($newFailCount >= self::FAIL_THRESHOLD) {
            $isDegraded = 1;
            $degradedAt = date('Y-m-d H:i:s');
            $recoverAt = date('Y-m-d H:i:s', time() + self::DEGRADE_DURATION);
        }

        $this->db->execute(
            "UPDATE " . self::TABLE . " SET 
                fail_count = ?,
                is_degraded = ?,
                degraded_at = ?,
                recover_at = ?,
                last_fail_at = datetime('now', 'localtime'),
                total_fail = total_fail + 1,
                updated_at = datetime('now', 'localtime')
            WHERE api_name = ?",
            [$newFailCount, $isDegraded, $degradedAt, $recoverAt, $apiName]
        );

        return $isDegraded === 1;
    }

    /**
     * 手动重置降级
     */
    public function resetDegradation($apiName) {
        $this->db->execute(
            "UPDATE " . self::TABLE . " SET 
                fail_count = 0,
                is_degraded = 0,
                degraded_at = NULL,
                recover_at = NULL,
                updated_at = datetime('now', 'localtime')
            WHERE api_name = ?",
            [$apiName]
        );
    }
}
