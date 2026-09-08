<?php
/**
 * 京东扫码登录API
 * 流程：获取二维码 -> 轮询状态 -> 验证ticket -> 获取cookies
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

set_security_headers();
require_auth();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_GET['action'] ?? '';
$db = Database::getInstance();

try {
    switch ($action) {
        case 'get_qrcode':
            getQrCode();
            break;
        case 'check_status':
            checkScanStatus();
            break;
        case 'verify_ticket':
            verifyTicket($db);
            break;
        default:
            json_error('未知操作');
    }
} catch (Exception $e) {
    error_log("JD QRCode Login error: " . $e->getMessage());
    json_error('操作失败: ' . $e->getMessage());
}

/**
 * 辅助函数：解析HTTP响应头中的Set-Cookie，返回cookie数组
 */
function parseCookiesFromHeaders($headerStr) {
    $cookies = [];
    if (preg_match_all('/Set-Cookie:\s*([^;=\s]+)\s*=\s*([^;]*)/i', $headerStr, $m)) {
        foreach ($m[1] as $i => $name) {
            $name = trim($name);
            $value = trim($m[2][$i]);
            if ($value !== '' && strtolower($value) !== 'deleted') {
                $cookies[$name] = $value;
            }
        }
    }
    return $cookies;
}

/**
 * 辅助函数：将cookie数组构建为Cookie请求头
 */
function buildCookieHeader($cookies) {
    $parts = [];
    foreach ($cookies as $n => $v) {
        $parts[] = $n . '=' . $v;
    }
    return implode('; ', $parts);
}

/**
 * 生成京东扫码登录二维码
 */
function getQrCode() {
    $appid = 133;
    $size = 200;
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    $allCookies = [];

    // 第一步：先访问passport页面获取初始跟踪Cookie（必须，否则二维码token无效）
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://passport.jd.com/new/login.aspx',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
        ],
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    error_log("JD QRCode step1 passport: HTTP {$httpCode}, Error: {$error}");

    if ($httpCode == 200) {
        $headerStr = substr($response, 0, $headerSize);
        $allCookies = array_merge($allCookies, parseCookiesFromHeaders($headerStr));
    }

    // 第二步：请求二维码
    $t = floor(microtime(true) * 1000);
    $qrUrl = "https://qr.m.jd.com/show?appid={$appid}&size={$size}&t={$t}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $qrUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $userAgent,
            'Referer: https://passport.jd.com/new/login.aspx',
            'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Connection: keep-alive',
            'Cookie: ' . buildCookieHeader($allCookies),
        ],
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    error_log("JD QRCode step2 qrcode: HTTP {$httpCode}, Error: {$error}");

    if ($httpCode != 200 || !$response) {
        json_error('获取二维码失败，请重试');
    }

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // 合并二维码响应返回的新Cookie
    $allCookies = array_merge($allCookies, parseCookiesFromHeaders($headerStr));

    $qrcodeToken = $allCookies['wlfstk_smdl'] ?? '';

    if (empty($qrcodeToken) || empty($body)) {
        error_log("JD QRCode: wlfstk_smdl not found, cookies: " . json_encode(array_keys($allCookies)));
        json_error('二维码生成失败，请刷新重试');
    }

    // 保存token和所有Cookie到session（后续轮询和验证都需要完整Cookie链）
    $_SESSION['jd_qr_token'] = $qrcodeToken;
    $_SESSION['jd_qr_cookies'] = $allCookies;
    $_SESSION['jd_qr_time'] = time();
    $_SESSION['jd_qr_appid'] = $appid;

    $qrcodeBase64 = 'data:image/png;base64,' . base64_encode($body);

    json_success([
        'token' => $qrcodeToken,
        'qrcode' => $qrcodeBase64,
        'expire_time' => time() + 180,
    ]);
}

/**
 * 检查扫码状态
 */
function checkScanStatus() {
    $token = $_SESSION['jd_qr_token'] ?? '';
    $sessionCookies = $_SESSION['jd_qr_cookies'] ?? [];

    if (empty($token)) {
        json_success(['status' => 'expired', 'message' => '二维码已失效，请刷新重试']);
        return;
    }

    if (time() - ($_SESSION['jd_qr_time'] ?? 0) > 180) {
        json_success(['status' => 'expired', 'message' => '二维码已过期']);
        return;
    }

    $appid = $_SESSION['jd_qr_appid'] ?? 133;
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // 确保wlfstk_smdl在cookie里（可能session中没有则补上）
    $sessionCookies['wlfstk_smdl'] = $token;

    $callback = 'jQuery' . mt_rand(1000000, 9999999);
    $t = floor(microtime(true) * 1000);

    $checkUrl = "https://qr.m.jd.com/check?appid={$appid}&callback={$callback}&token={$token}&_={$t}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $checkUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $userAgent,
            'Referer: https://passport.jd.com/new/login.aspx',
            'Accept: */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cookie: ' . buildCookieHeader($sessionCookies),
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) {
        json_success(['status' => 'waiting', 'message' => '等待扫描...']);
        return;
    }

    // 解析JSONP
    $data = null;
    if (preg_match('/jQuery\d+\((.+)\)/s', $response, $matches)) {
        $data = json_decode($matches[1], true);
    } else {
        $data = json_decode($response, true);
    }

    if (!$data) {
        json_success(['status' => 'waiting', 'message' => '等待扫描...']);
        return;
    }

    $code = intval($data['code'] ?? -1);
    $ticket = $data['ticket'] ?? '';
    $message = $data['msg'] ?? '';

    /*
     * code:
     * 200 - 确认登录成功（返回ticket）
     * 201 - 未扫描
     * 202 - 已扫描未确认
     * 203 - 二维码过期
     * 257 - 二维码无效（通常是Cookie问题）
     */
    switch ($code) {
        case 200:
            $_SESSION['jd_ticket'] = $ticket;
            json_success([
                'status' => 'confirmed',
                'message' => '登录成功',
                'ticket' => $ticket
            ]);
            break;
        case 201:
            json_success(['status' => 'waiting', 'message' => '请使用京东APP扫描二维码']);
            break;
        case 202:
            json_success(['status' => 'scanned', 'message' => '扫描成功，请在手机上确认登录']);
            break;
        case 203:
            json_success(['status' => 'expired', 'message' => '二维码已过期']);
            break;
        default:
            json_success(['status' => 'waiting', 'message' => $message ?: '等待扫描...']);
    }
}

/**
 * 验证ticket并获取cookies
 */
function verifyTicket($db) {
    $ticket = $_SESSION['jd_ticket'] ?? '';
    $sessionCookies = $_SESSION['jd_qr_cookies'] ?? [];

    if (empty($ticket)) {
        json_error('缺少登录凭证，请重新扫码');
    }

    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // 使用ticket验证登录，带上扫码阶段的完整Cookie链
    $verifyUrl = "https://passport.jd.com/uc/qrCodeTicketValidation?t=" . urlencode($ticket);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $verifyUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $userAgent,
            'Referer: https://passport.jd.com/new/login.aspx',
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Language: zh-CN,zh;q=0.9',
            'X-Requested-With: XMLHttpRequest',
            'Cookie: ' . buildCookieHeader($sessionCookies),
        ],
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    error_log("JD QRCode verify: HTTP {$httpCode}");

    if ($httpCode != 200 && $httpCode != 302) {
        json_error('验证登录失败，请重试');
    }

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // 合并扫码阶段Cookie + 验证返回的新Cookie（如pt_key/pt_pin）
    $cookies = $sessionCookies;
    $cookies = array_merge($cookies, parseCookiesFromHeaders($headerStr));
    
    // 解析返回JSON，获取跳转URL
    $result = json_decode($body, true);
    $returnUrl = $result['url'] ?? '';
    
    error_log("JD QRCode verify result: " . substr($body, 0, 500));
    error_log("JD QRCode cookies before follow: " . json_encode(array_keys($cookies)));
    
    // 访问跳转URL获取更多cookies
    if (!empty($returnUrl)) {
        $cookieHeader = buildCookieHeader($cookies);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $returnUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $userAgent,
                'Cookie: ' . $cookieHeader,
            ],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
        ]);
        $response2 = curl_exec($ch);
        $headerSize2 = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        // 解析所有重定向的cookies
        $headerStr2 = substr($response2, 0, $headerSize2);

        $headerSections = preg_split('/HTTP\/\d\.\d \d+ .*\r?\n/', $headerStr2);
        foreach ($headerSections as $section) {
            if (empty(trim($section))) continue;
            $cookies = array_merge($cookies, parseCookiesFromHeaders($section));
        }
    }

    // 访问京东首页确保获取完整cookies
    $cookieHeader = buildCookieHeader($cookies);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://www.jd.com/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $userAgent,
            'Cookie: ' . $cookieHeader,
        ],
    ]);
    $response3 = curl_exec($ch);
    $headerSize3 = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerStr3 = substr($response3, 0, $headerSize3);
    $cookies = array_merge($cookies, parseCookiesFromHeaders($headerStr3));

    error_log("JD QRCode cookies after follow: " . json_encode(array_keys($cookies)));
    
    // 检查是否有pt_key
    if (empty($cookies['pt_key'])) {
        error_log("JD QRCode: pt_key not found, all cookies: " . json_encode($cookies));
        json_error('登录失败，未能获取有效Cookie，请重试或手动输入');
    }
    
    // 确保有pt_pin
    if (empty($cookies['pt_pin'])) {
        json_error('登录失败，未能获取用户信息，请重试');
    }
    
    // 构建cookie字符串，只保留关键cookies
    $cookieParts = [];
    $importantCookies = ['pt_key', 'pt_pin', 'pt_token', 'thor', 'pt_type', 'sso_uc', '__jda', '__jdb', '__jdc', '__jdu'];
    foreach ($importantCookies as $name) {
        if (isset($cookies[$name]) && $cookies[$name] !== '') {
            $cookieParts[] = $name . '=' . $cookies[$name];
        }
    }
    
    // 也添加所有其他cookies
    foreach ($cookies as $name => $value) {
        if (!in_array($name, $importantCookies) && $value !== '') {
            $cookieParts[] = $name . '=' . $value;
        }
    }
    
    $finalCookieStr = implode(';', $cookieParts) . ';';
    
    $userName = urldecode($cookies['pt_pin']);
    
    // 保存到数据库
    $db->execute(
        "UPDATE settings SET jd_cookies = ?, cookie_status = 'unknown', updated_at = datetime('now', 'localtime') WHERE id = 1",
        [$finalCookieStr]
    );
    
    // 清除session
    unset($_SESSION['jd_qr_token']);
    unset($_SESSION['jd_qr_cookies']);
    unset($_SESSION['jd_qr_time']);
    unset($_SESSION['jd_qr_appid']);
    unset($_SESSION['jd_ticket']);
    
    json_success([
        'message' => '登录成功',
        'username' => $userName,
    ]);
}

/**
 * JSON成功响应
 */
function json_success($data = null, $message = 'success') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * JSON错误响应
 */
function json_error($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
