<?php
/**
 * Webhook通知类
 */

require_once __DIR__ . '/db.php';

class Webhook {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * 获取所有Webhook配置
     */
    private function getWebhooks() {
        $settings = $this->db->fetch("SELECT webhooks, silent_start, silent_end FROM settings WHERE id = 1");
        
        if (!$settings || empty($settings['webhooks'])) {
            return [];
        }
        
        $webhooks = json_decode($settings['webhooks'], true);
        if (!is_array($webhooks)) {
            return [];
        }
        
        // 检查是否在静默时段
        if ($this->isInSilentPeriod($settings['silent_start'], $settings['silent_end'])) {
            return []; // 静默时段不发送通知
        }
        
        return array_filter($webhooks, function($w) {
            return !empty($w['url']) && ($w['enabled'] ?? true);
        });
    }
    
    /**
     * 检查是否在静默时段
     */
    private function isInSilentPeriod($start, $end) {
        if (empty($start) || empty($end)) {
            return false;
        }
        
        $now = date('H:i');
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);
        
        // 处理跨天情况（如 23:00 - 07:00）
        if ($start > $end) {
            return ($now >= $start || $now < $end);
        }
        
        return ($now >= $start && $now < $end);
    }
    
    /**
     * 发送通知
     */
    public function send($type, $data) {
        $webhooks = $this->getWebhooks();
        
        if (empty($webhooks)) {
            return ['success' => false, 'message' => '没有可用的Webhook配置'];
        }
        
        $message = $this->formatMessage($type, $data);
        $results = [];
        
        foreach ($webhooks as $webhook) {
            $result = $this->sendToWebhook($webhook, $message, $type, $data);
            $results[] = $result;
            
            // 记录日志
            $this->logNotification($data['product_id'] ?? null, $type, $message['text'], $webhook['url'], $result['success']);
        }
        
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        
        return [
            'success' => $successCount > 0,
            'message' => "成功发送 {$successCount}/" . count($webhooks) . " 个Webhook",
            'results' => $results
        ];
    }
    
    /**
     * 发送到单个Webhook
     */
    private function sendToWebhook($webhook, $message, $type, $data) {
        $url = $webhook['url'];
        $name = $webhook['name'] ?? 'Webhook';
        
        try {
            // 根据Webhook类型选择发送格式
            $payload = $this->buildPayload($webhook, $message, $type, $data);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: JD-Price-Monitor/1.0'
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            return [
                'name' => $name,
                'success' => $httpCode >= 200 && $httpCode < 300,
                'http_code' => $httpCode,
                'response' => $response,
                'error' => $error
            ];
        } catch (Exception $e) {
            return [
                'name' => $name,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 构建发送载荷
     */
    private function buildPayload($webhook, $message, $type, $data) {
        $url = $webhook['url'];
        $format = $webhook['format'] ?? 'markdown';
        
        // 钉钉
        if (strpos($url, 'oapi.dingtalk.com') !== false) {
            if ($format === 'text') {
                return [
                    'msgtype' => 'text',
                    'text' => [
                        'content' => $message['title'] . "\n\n" . strip_tags(str_replace(['**', "\n\n"], ['', "\n"], $message['text']))
                    ]
                ];
            }
            return [
                'msgtype' => 'markdown',
                'markdown' => [
                    'title' => $message['title'],
                    'text' => $message['text']
                ]
            ];
        }
        
        // 企业微信
        if (strpos($url, 'qyapi.weixin.qq.com') !== false) {
            if ($format === 'text') {
                return [
                    'msgtype' => 'text',
                    'text' => [
                        'content' => $message['title'] . "\n\n" . strip_tags(str_replace(['**', "\n\n"], ['', "\n"], $message['text']))
                    ]
                ];
            }
            return [
                'msgtype' => 'markdown',
                'markdown' => [
                    'content' => $message['text']
                ]
            ];
        }
        
        // Telegram
        if (strpos($url, 'api.telegram.org') !== false) {
            return [
                'chat_id' => $webhook['chat_id'] ?? '',
                'text' => $format === 'text' ? strip_tags(str_replace(['**'], '', $message['text'])) : $message['text'],
                'parse_mode' => $format === 'markdown' ? 'Markdown' : 'HTML'
            ];
        }
        
        // Slack
        if (strpos($url, 'hooks.slack.com') !== false) {
            if ($format === 'text') {
                return ['text' => $message['title'] . "\n\n" . strip_tags(str_replace(['**'], '', $message['text']))];
            }
            return [
                'text' => $message['title'],
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => $message['text']
                        ]
                    ]
                ]
            ];
        }
        
        // Discord
        if (strpos($url, 'discord.com/api/webhooks') !== false) {
            if ($format === 'text') {
                return ['content' => $message['title'] . "\n\n" . strip_tags(str_replace(['**'], '', $message['text']))];
            }
            return [
                'content' => $message['title'],
                'embeds' => [
                    [
                        'title' => $message['title'],
                        'description' => $message['text'],
                        'color' => $this->getColorForType($type),
                        'timestamp' => date('c')
                    ]
                ]
            ];
        }
        
        // 默认格式
        return [
            'type' => $type,
            'title' => $message['title'],
            'content' => $format === 'text' ? strip_tags(str_replace(['**'], '', $message['text'])) : $message['text'],
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * 格式化消息
     */
    private function formatMessage($type, $data) {
        $product = $data['product'] ?? [];
        $templates = [
            'price_drop' => [
                'title' => '🎉 降价提醒',
                'text' => "**{$product['name']}**\n\n" .
                         "💰 当前价格：¥{$product['current_price']}\n" .
                         "🎯 目标价格：¥{$product['target_price']}\n" .
                         "📉 降价幅度：¥" . ($product['old_price'] - $product['current_price']) . "\n\n" .
                         "[点击查看商品]({$product['url']})"
            ],
            'price_update' => [
                'title' => '📊 价格更新',
                'text' => "**{$product['name']}**\n\n" .
                         "💰 当前价格：¥{$product['current_price']}\n" .
                         "📈 历史最低：¥{$product['lowest_price']}\n" .
                         "📊 监控时长：" . $this->formatDuration($product['created_at']) . "\n\n" .
                         "[点击查看商品]({$product['url']})"
            ],
            'lowest_price' => [
                'title' => '🏆 历史最低价',
                'text' => "**{$product['name']}**\n\n" .
                         "💰 当前价格：¥{$product['current_price']}\n" .
                         "📉 创历史新低！\n\n" .
                         "[点击查看商品]({$product['url']})"
            ],
            'price_surge' => [
                'title' => '📈 价格上涨',
                'text' => "**{$product['name']}**\n\n" .
                         "💰 当前价格：¥{$product['current_price']}\n" .
                         "📊 之前价格：¥{$product['old_price']}\n" .
                         "📈 涨幅：" . round(($product['current_price'] - $product['old_price']) / $product['old_price'] * 100, 1) . "%\n\n" .
                         "[点击查看商品]({$product['url']})"
            ],
            'out_of_stock' => [
                'title' => '📦 商品无货',
                'text' => "**{$product['name']}**\n\n" .
                         "当前库存状态：无货\n\n" .
                         "[点击查看商品]({$product['url']})"
            ],
            'back_in_stock' => [
                'title' => '✅ 商品有货',
                'text' => "**{$product['name']}**\n\n" .
                         "💰 当前价格：¥{$product['current_price']}\n" .
                         "商品已补货！\n\n" .
                         "[点击查看商品]({$product['url']})"
            ],
            'cookie_invalid' => [
                'title' => '⚠️ Cookie失效',
                'text' => "**京东账号Cookie已失效**\n\n" .
                         "请及时更新Cookie，否则无法获取会员价格。\n\n" .
                         "更新方式：设置 → 京东配置 → 更新Cookie"
            ],
            'cookie_expired' => [
                'title' => '⚠️ Cookie已失效',
                'text' => "**京东账号Cookie已失效**\n\n" .
                         "检测时间：" . ($data['checked_at'] ?? date('Y-m-d H:i:s')) . "\n\n" .
                         "请及时更新Cookie，否则无法获取会员价格。\n\n" .
                         "更新方式：设置 → 京东配置 → 更新Cookie"
            ],
            'cookie_expiring' => [
                'title' => '⏰ Cookie即将过期',
                'text' => "**京东Cookie即将过期**\n\n" .
                         "建议尽快更新Cookie以确保监控正常。\n\n" .
                         "更新方式：设置 → 京东配置 → 更新Cookie"
            ]
        ];
        
        return $templates[$type] ?? ['title' => '通知', 'text' => ''];
    }
    
    /**
     * 获取通知类型的颜色
     */
    private function getColorForType($type) {
        $colors = [
            'price_drop' => 3066993,      // 绿色
            'lowest_price' => 3066993,    // 绿色
            'price_update' => 3447003,    // 蓝色
            'price_surge' => 15158332,    // 红色
            'out_of_stock' => 10070709,   // 灰色
            'back_in_stock' => 3066993,   // 绿色
            'cookie_invalid' => 15158332, // 红色
            'cookie_expired' => 15158332, // 红色
            'cookie_expiring' => 16776960 // 黄色
        ];
        
        return $colors[$type] ?? 3447003;
    }
    
    /**
     * 格式化时长
     */
    private function formatDuration($createdAt) {
        $diff = time() - strtotime($createdAt);
        $days = floor($diff / 86400);
        
        if ($days > 30) {
            $months = floor($days / 30);
            return "{$months}个月";
        }
        
        return "{$days}天";
    }
    
    /**
     * 记录通知日志
     */
    private function logNotification($productId, $type, $message, $webhookUrl, $success) {
        $this->db->execute(
            "INSERT INTO notification_logs (product_id, type, message, webhook_url, success, sent_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)",
            [$productId, $type, $message, $webhookUrl, $success ? 1 : 0]
        );
    }
    
    /**
     * 测试Webhook
     */
    public function test($url) {
        $message = [
            'title' => '🔔 京东价格监控 - 测试通知',
            'text' => "这是一条测试消息\n\n" .
                     "发送时间：" . date('Y-m-d H:i:s') . "\n\n" .
                     "如果您收到此消息，说明Webhook配置正确。"
        ];
        
        $webhook = [
            'url' => $url,
            'name' => '测试',
            'format' => 'markdown'
        ];
        
        return $this->sendToWebhook($webhook, $message, 'test', []);
    }
    
    /**
     * 测试Webhook（支持格式选择）
     */
    public function testWebhook($webhookData) {
        $message = [
            'title' => '🔔 京东价格监控 - 测试通知',
            'text' => "这是一条测试消息\n\n" .
                     "发送时间：" . date('Y-m-d H:i:s') . "\n\n" .
                     "如果您收到此消息，说明Webhook配置正确。"
        ];
        
        $webhook = array_merge([
            'name' => '测试',
            'format' => 'markdown'
        ], $webhookData);
        
        return $this->sendToWebhook($webhook, $message, 'test', []);
    }
}
