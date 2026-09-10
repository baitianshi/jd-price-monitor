# 京东商品价格监控系统 - 技术文档

本文档详细记录系统各功能模块的实现逻辑、架构设计和开发约定。

---

## 〇、新对话预设关键信息

> **使用方法**：打开新对话时，将以下内容复制粘贴给 AI，帮助快速理解项目上下文。
>
> **长时间对话恢复**：如果对话已进行多天，让 AI 先读取 `CONVERSATION_STATE.md` 恢复进度。

### 快速预设消息（直接复制发送）

```
我在开发一个京东商品价格监控系统，项目目录在 d:\code\jd

【项目文档说明】
- README.md：用户文档，包含功能说明、安装使用、数据库结构
- DEVELOPMENT.md：技术文档，包含架构设计、开发约定、常见错误陷阱
- CHANGES.md：版本变更记录，每次修改都有详细的技术难点和错误陷阱说明
- CONVERSATION_STATE.md：对话状态记录，记录已完成任务和关键决策

【技术栈】
- 后端：PHP 7.4+ + SQLite3
- 前端：Alpine.js + Tailwind CSS + Chart.js
- 调度：scheduler.php（每分钟运行，随机调度）

【开发约定】
1. 新增字段：修改 db.php 的 createTables() + api/products.php 的 $allowedFields
2. Alpine.js：避免嵌套 <template x-if>，用 x-show 替代
3. 时间：使用 datetime('now', 'localtime')
4. 功能变更后必须更新：CHANGES.md、README.md、DEVELOPMENT.md

请先阅读 README.md 和 DEVELOPMENT.md 了解项目，然后帮我处理以下任务：
```

---

### 完整预设信息（需要时使用）

```
# 项目概述
京东商品价格监控系统 - 基于 PHP + SQLite 的京东商品价格监控系统
- 后端：PHP 7.4+ + SQLite3
- 前端：Alpine.js + Tailwind CSS
- 图表：Chart.js
- 调度：scheduler.php（每分钟运行，随机调度）

# 核心功能
- 商品管理：添加/编辑/删除，支持多种链接格式解析
- 价格监控：实时检查、走势图表、价格日历
- 价格保护：一键价保，调用京东官方API
- 通知系统：降价/涨价/库存变化/Cookie失效通知，支持多种Webhook
- 数据管理：历史数据自动清理，每个商品单独设置保留天数

# 关键文件
| 文件 | 用途 |
|------|------|
| index.php | 主页面（HTML + Alpine.js 模块化） |
| scheduler.php | 轻量调度器（价格检查、Cookie检查、价保、历史清理） |
| includes/db.php | 数据库类（含字段迁移） |
| includes/jd.php | JD API 封装（价格获取、防风控浏览） |
| includes/webhook.php | 通知发送类 |
| api/products.php | 商品 API（CRUD + 字段白名单） |
| api/tags.php | 标签 API |
| api/settings.php | 设置 API |
| assets/js/modules/*.js | 前端模块（products.js, tags.js, chart.js 等） |

# 核心调度逻辑
scheduler.php 每分钟运行：
1. 商品价格检查：next_check_at <= 当前时间 → 随机60-120分钟
   - 进入移动端购物车，随机浏览1-3个监控商品（模拟真人）
   - 只在价格或库存变化时记录历史
   - 触发 webhook（降价/涨价/缺货）
2. Cookie检查：next_cookie_check_at <= 当前时间 → 随机6-12小时
   - 优先检查用户信息API（最准确）
3. 价保执行：price_protection_last_run + price_protection_interval <= 当前时间 → 按设置间隔（默认360分钟）
4. 历史清理：next_clean_at <= 当前时间 → 每24小时

# 数据库表结构
- products：商品信息（含 next_check_at, history_retention_days, notify_price_surge 等）
- price_history：价格历史（recorded_at, price, stock_status）
- tags：标签
- product_tags：商品标签关联
- settings：系统设置（含 next_cookie_check_at, cookie_check_interval, price_protection_last_run, next_clean_at）
- price_protection_logs：价保日志
- notification_logs：通知日志

# 防风控措施
从 m.jd.com 进入 → 实时抓取商品链接 → 随机访问3-5个页面
只提取 item.m.jd.com/product/xxx.html 格式链接
每个页面停留 2-4 秒

# 开发约定
1. 新增字段：db.php 的 createTables() + api/products.php 的 $allowedFields
2. Alpine.js：避免嵌套 <template x-if>，用 x-show 替代
3. 时间：使用 datetime('now', 'localtime')，不用 CURRENT_TIMESTAMP
4. 价格历史：只在价格或库存变化时记录
5. API响应：{ success: true/false, data: {}, message: '' }
6. CSRF验证：POST 请求需携带 X-CSRF-Token

# 常见错误陷阱
1. Alpine.js 嵌套 template 不渲染 → 用 x-show 替代
2. 新字段不生效 → 检查 db.php 迁移 + api/products.php 白名单
3. 时间时区问题 → 用 datetime('now', 'localtime')
4. 链接拼接错误 → 跳过 // 开头的链接，只提取商品链接格式
5. 价格历史重复 → 只在变化时插入
6. Webhook 数据层级混淆 → 检查 $data 和 $product 的层级关系

# 文档更新规范（必须严格遵守）
每次功能变更必须更新：
1. CHANGES.md - 功能变更记录、技术难点、错误陷阱、使用说明
2. README.md - 功能说明、数据库表结构
3. DEVELOPMENT.md - 技术文档、开发注意事项
4. CONVERSATION_STATE.md - 对话进度记录

# 详细文档位置
- README.md - 用户文档、功能说明、数据库结构、安装使用
- DEVELOPMENT.md - 技术文档、架构设计、开发约定、常见错误
- CHANGES.md - 版本变更记录（含技术难点、错误陷阱）
- CONVERSATION_STATE.md - 对话状态记录（已完成任务、关键决策）
```

---

## 一、系统架构

### 技术栈

| 层级 | 技术 |
|------|------|
| 后端 | PHP 7.4+ + SQLite3 |
| 前端 | Alpine.js + Tailwind CSS |
| 图表 | Chart.js |
| 调度 | scheduler.php（每分钟运行） |

### 目录结构

```
├── index.php              # 主页面（HTML + Alpine.js）
├── scheduler.php          # 轻量调度器
├── cron.php               # 批量任务脚本
│
├── api/                   # API 接口
│   ├── auth.php           # 认证
│   ├── products.php       # 商品管理
│   ├── tags.php           # 标签管理
│   ├── settings.php       # 系统设置
│   ├── check-price.php    # 价格检查
│   ├── price-protection.php # 价格保护
│   ├── price-history.php  # 价格记录
│   ├── notify.php         # 通知测试
│   ├── export.php         # 数据导出
│   └── network_check.php  # 网络检测
│
├── assets/js/             # 前端 JavaScript
│   ├── app.js             # 主入口（模块组合）
│   └── modules/           # 功能模块
│       ├── utils.js       # 工具函数
│       ├── products.js    # 商品管理
│       ├── settings.js    # 设置
│       ├── chart.js       # 图表+日历
│       ├── protection.js  # 价保
│       ├── price-history.js # 价格记录
│       └── tags.js        # 标签管理
│
├── includes/              # 后端核心类
│   ├── config.php         # 配置常量
│   ├── db.php             # 数据库类
│   ├── auth.php           # 认证类
│   ├── jd.php             # 京东价格获取
│   ├── webhook.php        # Webhook 通知
│   └── price_protection.php # 价格保护
│
└── data/                  # 数据目录
    ├── monitor.db         # SQLite 数据库
    ├── cron.log           # cron 日志
    └── scheduler.log      # scheduler 日志
```

---

## 二、前端架构

### 模块化设计

前端采用 Alpine.js 模块化架构，每个功能独立为一个模块文件。

#### 模块组合方式（app.js）

```javascript
function app() {
    return Object.assign(
        { /* 共享状态和方法 */ },
        createUtilsModule(),
        createProductsModule(),
        createSettingsModule(),
        createChartModule(),
        createProtectionModule(),
        createPriceHistoryModule(),
        createTagsModule()
    );
}
```

#### 模块结构规范

每个模块遵循统一结构：

```javascript
function createXxxModule() {
    return {
        // 1. 状态
        items: [],
        loading: false,
        showModal: false,
        
        // 2. 初始化
        init() { },
        
        // 3. 数据加载
        async loadData() { },
        
        // 4. 业务方法
        doSomething() { },
        
        // 5. 辅助方法
        helper() { }
    };
}
```

### Alpine.js 使用约定

#### 条件渲染

```html
<!-- 推荐：x-show 用于频繁切换 -->
<div x-show="isVisible">内容</div>

<!-- 推荐：x-if 用于初始条件渲染 -->
<template x-if="hasData">
    <div>数据内容</div>
</template>

<!-- 禁止：x-for 内嵌套多个 x-if -->
<template x-for="item in items" :key="item.id">
    <template x-if="item.type === 'a'">  <!-- ❌ 避免 -->
        <div>A</div>
    </template>
    <template x-if="item.type === 'b'">  <!-- ❌ 避免 -->
        <div>B</div>
    </template>
</template>

<!-- 推荐：使用单一元素 + x-show -->
<template x-for="item in items" :key="item.id">
    <div>
        <span x-show="item.type === 'a'">A</span>
        <span x-show="item.type === 'b'">B</span>
    </div>
</template>
```

#### 事件处理

```html
<!-- 点击事件 -->
<button @click="doAction()">按钮</button>

<!-- 带参数 -->
<button @click="editItem(item)">编辑</button>

<!-- 阻止默认行为 -->
<form @submit.prevent="submitForm()">

<!-- 事件修饰符 -->
<div @click.self="closeModal()">
```

#### 双向绑定

```html
<input x-model="formData.name">
<textarea x-model="formData.description"></textarea>
<select x-model="formData.type">
    <option value="a">A</option>
    <option value="b">B</option>
</select>
```

### Toast 通知

```javascript
// 调用方式
this.showToast('操作成功', 'success');
this.showToast('操作失败', 'error');
this.showToast('提示信息', 'info');

// 实现（utils.js）
toast: { show: false, message: '', type: 'info' },

showToast(message, type = 'info') {
    this.toast = { show: true, message, type };
    setTimeout(() => {
        this.toast.show = false;
    }, 3000);
}
```

---

## 三、后端架构

### API 响应格式

所有 API 返回统一格式：

```json
{
    "success": true,
    "data": { },
    "message": "操作成功"
}
```

错误响应：

```json
{
    "success": false,
    "message": "错误原因"
}
```

### CSRF 防护

所有 POST/PUT/DELETE 请求必须携带 CSRF Token：

```javascript
// 前端获取 Token
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// 请求头携带
fetch(url, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify(data)
});
```

### 数据库操作（includes/db.php）

#### 基本方法

```php
$db = Database::getInstance();

// 查询单条
$row = $db->fetch("SELECT * FROM products WHERE id = ?", [$id]);

// 查询多条
$rows = $db->fetchAll("SELECT * FROM products WHERE status = ?", ['active']);

// 执行语句
$db->execute("UPDATE products SET name = ? WHERE id = ?", [$name, $id]);

// 获取最后插入ID
$lastId = $db->lastInsertId();
```

#### 字段迁移

新增字段时，在 `createTables()` 方法中添加：

```php
if (!in_array('new_field', $existingFields)) {
    $this->pdo->exec("ALTER TABLE table_name ADD COLUMN new_field TYPE DEFAULT value");
}
```

---

## 四、功能模块详解

### 4.1 商品管理

#### 数据流程

```
用户输入链接
    ↓
解析 SKU（支持短链接、APP分享链接、PC链接）
    ↓
获取商品信息（jd.php）
    ├── 移动端页面（item.m.jd.com）
    ├── 移动端 API（api.m.jd.com）
    ├── 公开 API（p.3.cn）
    └── PC 端页面（item.jd.com）- 补充图片
    ↓
保存到数据库
    ↓
返回商品信息
```

#### SKU 解析逻辑

```php
// includes/jd.php - parseSkuFromUrl()

// 1. 短链接解析
if (strpos($url, 'jd.com/') !== false && strlen($url) < 50) {
    // 跟随重定向，从 returnurl 参数提取 SKU
}

// 2. APP 分享链接
// 格式：https://item.m.jd.com/product/100012345.html
preg_match('/product\/(\d+)/', $url, $matches);

// 3. PC 链接
// 格式：https://item.jd.com/100012345.html
preg_match('/jd\.com\/(\d+)/', $url, $matches);

// 4. 直接 SKU
if (preg_match('/^\d+$/', $url)) {
    return $url;
}
```

#### 价格获取优先级

| 优先级 | 方法 | 来源 | 获取内容 |
|--------|------|------|----------|
| 1 | 移动端页面 | item.m.jd.com | 名称、到手价、原价、库存 |
| 2 | 移动端 API | api.m.jd.com | 名称、到手价、库存 |
| 3 | 公开 API | p.3.cn | 到手价 |
| 4 | PC 端页面 | item.jd.com | 图片、原价（补充） |

#### 成功率统计

系统自动统计每种方法的成功率，存储在 `price_method_stats` 表：

```sql
SELECT method, 
       success_count, 
       total_count,
       ROUND(success_count * 100.0 / total_count, 1) as success_rate,
       ROUND(total_time / total_count, 2) as avg_time
FROM price_method_stats
ORDER BY success_rate DESC, avg_time ASC
```

---

### 4.2 价格监控与调度

#### 调度器核心逻辑（scheduler.php）

```
scheduler.php 每分钟运行
    │
    ├── 1. 检查商品价格
    │   ├── 查询 next_check_at <= 当前时间 的商品
    │   ├── 随机选一个商品
    │   ├── 进入移动端购物车，随机浏览1-3个监控商品（模拟真人）
    │   ├── 获取价格、库存
    │   ├── 记录价格历史（仅当价格或库存变化时）
    │   ├── 触发 Webhook（降价/涨价/缺货）
    │   └── 设置 next_check_at = 当前时间 + 随机60-120分钟
    │
    ├── 2. 检查 Cookie 状态
    │   ├── 检查 next_cookie_check_at 是否到期
    │   ├── 验证 Cookie 有效性
    │   ├── 失效时触发 Webhook
    │   └── 设置 next_cookie_check_at = 当前时间 + 随机6-12小时
    │
    ├── 3. 执行价格保护
    │   ├── shouldRunProtection()：price_protection_last_run + 间隔 <= 当前时间
    │   ├── 调用京东价保 API
    │   ├── 有退款时触发 Webhook
    │   └── updateLastRunTime()：记录 price_protection_last_run = 当前时间
    │
    └── 4. 清理过期历史数据
        ├── 检查 next_clean_at 是否到期
        ├── 遍历每个商品
        │   └── 删除 recorded_at < 当前时间 - history_retention_days
        └── 设置 next_clean_at = 当前时间 + 24小时
```

#### 防风控措施

```php
// includes/jd.php - randomBrowseCart()

// 1. 先访问移动端购物车 m.jd.com/cart/
// 2. 从数据库中查询监控中的商品 SKU 列表
// 3. 对 SKU 列表去重、过滤空值、重建索引
// 4. 随机抽取 1-3 个监控商品，浏览移动端详情页：
//    item.m.jd.com/product/{skuId}.html
// 5. 每个详情页随机停留 2-4 秒
// 6. 若没有监控商品，则仅访问购物车页面

// 所有请求使用移动端 User-Agent 和 m.jd.com Referer
// 浏览路径贴近真实用户（购物车 -> 商品），避免从首页发散抓链接
```

#### 时间字段说明

| 表 | 字段 | 用途 |
|-----|------|------|
| products | next_check_at | 下次价格检查时间 |
| settings | next_cookie_check_at | 下次 Cookie 检查时间 |
| settings | cookie_checked_at | Cookie 最后检查时间（cron.php） |
| settings | cookie_check_interval | Cookie 检查间隔（默认360分钟） |
| settings | price_protection_interval | 价保执行间隔（默认360分钟） |
| settings | price_protection_last_run | 价保最后执行时间 |
| settings | next_clean_at | 下次历史清理时间 |

#### Cookie 检查方式（按优先级）

| 优先级 | 方法 | API/页面 | 判断逻辑 | 准确性 |
|--------|------|----------|----------|--------|
| 1 | 用户信息API | `me-api.jd.com/user_new/info/GetJDUserInfoUnion` | `retcode=0` 或有 `userInfo` 则有效 | ⭐⭐⭐⭐⭐ 最准确 |
| 2 | 移动端登录API | `plogin.m.jd.com/cgi-bin/ml/islogin` | `islogin=1` 则有效 | ⭐⭐⭐⭐ |
| 3 | 移动端商品页面 | `item.m.jd.com/product/{skuId}.html` | 能获取有效价格(>1)则有效 | ⭐⭐⭐ 可能受风控影响 |
| 4 | 价格API | `api.m.jd.com/client.action?functionId=wareBusiness` | 能获取 `wareInfo.price` 则有效 | ⭐⭐⭐ 可能受风控影响 |
| 5 | 移动端首页 | `m.jd.com/` | 页面包含 `nickName`/`isLogin:true` 则有效 | ⭐⭐ 最后备选 |

**为什么用户信息API最准确：**
- Cookie 失效后立即返回错误码，无延迟
- 不依赖商品数据，不受风控影响
- 直接查询登录状态，结果可靠

---

### 4.3 价格日历

#### 数据结构

```javascript
// chart.js - calendarDays 数组

calendarDays: [
    { isNull: true, key: 'pad-0' },           // 空白填充
    { isNull: true, key: 'pad-1' },           // 空白填充
    { isNull: false, key: '2026-03-01', day: 1, hasData: true, price: 199 },
    { isNull: false, key: '2026-03-02', day: 2, hasData: false, price: null },
    // ...
]
```

#### 渲染逻辑

```html
<template x-for="day in calendarDays" :key="day.key">
    <div class="aspect-square"
        :class="day.isNull ? '' : getCalendarColor(day)">
        <span x-show="!day.isNull" x-text="day.day"></span>
        <span x-show="day.hasData" x-text="'¥' + day.price"></span>
    </div>
</template>
```

#### 颜色规则

```javascript
getCalendarColor(day) {
    if (!day.hasData) return 'bg-gray-50';
    
    const price = day.price;
    const current = this.calendarProduct.current_price;
    const lowest = this.calendarProduct.lowest_price;
    const target = this.calendarProduct.target_price;
    
    if (target > 0 && price <= target) return 'bg-green-500 text-white';  // 达到目标价
    if (lowest > 0 && price <= lowest * 1.02) return 'bg-green-400 text-white';  // 接近最低价
    if (current > 0 && price <= current) return 'bg-blue-400 text-white';  // 低于当前价
    if (current > 0 && price > current * 1.1) return 'bg-red-400 text-white';  // 高于当前价10%
    
    return 'bg-yellow-100';
}
```

---

### 4.4 标签管理

#### 数据库表

```sql
CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE NOT NULL,
    color TEXT DEFAULT '#3B82F6',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
```

#### 商品标签存储

商品的 `tags` 字段存储逗号分隔的标签名：

```
"路由器,数码,推荐"
```

#### 前端标签选择器

```javascript
// tags.js

// 解析标签字符串为数组
parseTags(tagString) {
    return tagString ? tagString.split(',').filter(t => t.trim()) : [];
}

// 标签数组转字符串
tagsToString(tags) {
    return tags.join(',');
}

// 获取标签颜色（从已保存的标签中查找）
getTagColor(tagName) {
    const tag = this.tags.find(t => t.name === tagName);
    return tag ? tag.color : '#3B82F6';
}
```

#### 标签筛选

```javascript
// app.js

selectedTag: null,

get filteredProducts() {
    let products = this.products;
    
    if (this.selectedTag) {
        products = products.filter(p => {
            const tags = this.parseTags(p.tags);
            return tags.includes(this.selectedTag);
        });
    }
    
    return products;
}
```

---

### 4.5 历史数据清理

#### 清理逻辑

```php
// scheduler.php - cleanOldHistory()

function cleanOldHistory($db, $force = false) {
    // 1. 检查是否到期
    $settings = $db->fetch("SELECT next_clean_at FROM settings WHERE id = 1");
    if (!$force && strtotime($settings['next_clean_at']) > time()) {
        return false;  // 未到期
    }
    
    // 2. 遍历所有活跃商品
    $products = $db->fetchAll(
        "SELECT id, name, history_retention_days FROM products WHERE status = 'active'"
    );
    
    foreach ($products as $product) {
        // 3. 获取保留天数（默认365天，最少7天）
        $retentionDays = max($product['history_retention_days'] ?? 365, 7);
        
        // 4. 计算截止日期
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        
        // 5. 删除过期记录
        $db->execute(
            "DELETE FROM price_history WHERE product_id = ? AND recorded_at < ?",
            [$product['id'], $cutoffDate]
        );
    }
    
    // 6. 设置下次清理时间（24小时后）
    $db->execute(
        "UPDATE settings SET next_clean_at = ? WHERE id = 1",
        [date('Y-m-d H:i:s', time() + 24 * 3600)]
    );
}
```

#### 清理间隔 vs 保留天数

| 概念 | 说明 |
|------|------|
| 清理间隔（24小时） | 调度器多久执行一次清理任务 |
| 保留天数（每个商品） | 清理时删除多久之前的数据 |

示例：清理任务每天执行，商品A保留30天，商品B保留90天。

---

### 4.6 价格保护

#### 执行流程

```
触发价保
    ↓
获取 PIN（从 Cookie 提取 pt_pin）
    ↓
获取可保价订单列表（priceskusPull API）
    ↓
遍历每个订单的每个商品
    ├── 检查是否可保价（skuProResultPC API）
    ├── 申请价保（skuProtectApply API）
    ├── 记录日志
    └── 累计退款金额
    ↓
返回结果 + 发送通知（有退款时）
```

#### 核心 API

| API | 用途 | 请求方式 |
|-----|------|----------|
| `priceskusPull` | 获取可保价订单列表 | POST |
| `skuProResultPC` | 检查订单是否可保价 | POST |
| `getOrderListSkuPrice` | 获取订单商品购买价格 | POST |
| `skuProtectApply` | 申请价格保护 | POST |

#### 代码实现

```php
// includes/price_protection.php

class PriceProtection {
    const BASE_URL = 'https://pcsitepp-fm.jd.com';
    
    // 1. 获取 PIN（从 Cookie 提取）
    private function getPin() {
        if (preg_match('/pt_pin=([^;]+)/i', $this->cookies, $matches)) {
            return urldecode($matches[1]);
        }
        return null;
    }
    
    // 2. 获取可保价订单列表
    public function getOrderList($page = 1, $pageSize = 20) {
        $url = self::BASE_URL . '/rest/pricepro/priceskusPull';
        // 返回订单列表，每个订单包含 sku_list
    }
    
    // 3. 检查订单是否可保价
    public function checkOrderProtectable($orderId, $skuId, $pin) {
        $url = 'https://sitepp-fm.jd.com/rest/webserver/skuProResultPC';
        // 返回是否可保价
    }
    
    // 4. 申请价格保护
    public function applyProtection($orderId, $skuId) {
        $url = self::BASE_URL . '/rest/pricepro/skuProtectApply';
        // 返回 success, message, refund_amount
    }
    
    // 5. 一键价保入口
    public function oneClickProtection() {
        $pin = $this->getPin();
        $orders = $this->getOrderList();
        
        foreach ($orders as $order) {
            foreach ($order['sku_list'] as $skuItem) {
                if ($this->checkOrderProtectable($orderId, $skuId, $pin)) {
                    $result = $this->applyProtection($orderId, $skuId);
                    $this->logProtection($result);
                }
            }
        }
    }
}
```

#### 触发条件

| 场景 | 触发方式 |
|------|----------|
| 自动执行 | scheduler.php/cron.php 调用 `shouldRunProtection()`（`price_protection_last_run` + `price_protection_interval`） |
| 手动执行 | 点击"立即执行"按钮（同步更新 `price_protection_last_run`） |
| 强制执行 | `php scheduler.php force` |

#### 执行间隔

- **自动模式**：按 `price_protection_interval` 间隔（分钟，默认360）
- **条件**：`price_protection_enabled = 1`

#### 日志记录

```sql
-- price_protection_logs 表
INSERT INTO price_protection_logs (
    order_id, sku_id, product_name, 
    buy_price, current_price, refund_amount,
    status, message
) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
```

---

### 4.7 Webhook 通知

#### 支持的通知类型

| 类型 | 触发条件 | 数据字段 |
|------|----------|----------|
| price_drop | 价格 <= 目标价 | 商品名、当前价、原价、降幅、下次检查时间 |
| lowest_price | 价格 <= 历史最低价 | 商品名、当前价、原价、下次检查时间 |
| price_surge | 价格上涨（需开启 notify_price_surge） | 商品名、当前价、原价、涨幅金额、涨幅百分比 |
| out_of_stock | 商品无货 | 商品名、原价、下次检查时间 |
| back_in_stock | 商品有货 | 商品名、当前价、原价、下次检查时间 |
| cookie_invalid | Cookie 无效 | 提示信息 |
| cookie_expired | Cookie 已失效 | 检测时间、提示信息 |
| price_protection | 价保成功 | 退款金额、处理结果 |

#### 发送逻辑

```php
// includes/webhook.php

function send($type, $data) {
    $webhooks = json_decode($settings['webhooks'], true);
    
    foreach ($webhooks as $webhook) {
        if ($webhook['enabled']) {
            $this->sendToWebhook($webhook['url'], $type, $data);
        }
    }
}
```

---

## 五、数据库设计

### 表结构总览

| 表名 | 用途 |
|------|------|
| products | 商品信息 |
| price_history | 价格历史 |
| price_protection_logs | 价保日志 |
| notification_logs | 通知日志 |
| price_method_stats | 价格获取方法统计 |
| tags | 标签 |
| settings | 系统设置 |
| login_attempts | 登录尝试 |

### 核心表详解

#### products

```sql
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku_id TEXT NOT NULL,
    name TEXT,
    image_url TEXT,
    current_price REAL,
    original_price REAL,
    target_price REAL,
    lowest_price REAL,
    highest_price REAL,
    stock_status TEXT,
    stock_num INTEGER,
    status TEXT DEFAULT 'active',
    tags TEXT,
    next_check_at DATETIME,
    history_retention_days INTEGER DEFAULT 365,
    notify_price_drop INTEGER DEFAULT 1,
    notify_lowest INTEGER DEFAULT 1,
    notify_price_surge INTEGER DEFAULT 0,
    notify_oos INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)
```

#### settings

```sql
CREATE TABLE settings (
    id INTEGER PRIMARY KEY,
    access_password TEXT,
    session_secret TEXT,
    jd_cookies TEXT,
    cookie_status TEXT,
    cookie_checked_at DATETIME,
    webhooks TEXT,
    price_protection_enabled INTEGER DEFAULT 0,
    price_protection_interval INTEGER DEFAULT 360,
    price_protection_last_run DATETIME,
    cookie_check_interval INTEGER DEFAULT 360,
    next_cookie_check_at DATETIME,
    next_clean_at DATETIME,
    silent_start TEXT,
    silent_end TEXT
)
```

> 注：`next_protection_at` 字段已不再参与调度（由 `price_protection_interval` + `price_protection_last_run` 替代），迁移代码保留仅为兼容旧库。

#### Cookie 自动维护开发约定（v2.3.0）

- 所有携带 Cookie 的京东请求**必须**通过 `JdPrice::execWithCookieCapture($ch)` 执行（而非直接 `curl_exec`），以便自动捕获并回写 `Set-Cookie`；无 Cookie 的请求（公开 API、短链接解析）可保持 `curl_exec`
- `mergeAndPersistCookies()` 的 `cookie_status` 重置语义：**仅**登录关键 Cookie（`pt_key`/`pt_pin`/`pt_token`/`thor`/`sso_uc`）变化时重置为 `'unknown'`，普通跟踪 Cookie 变化只更新值——不要改为"所有变化都重置"，否则会破坏调度器的"有效→失效"通知逻辑
- 合并时自动忽略空值和 `deleted` 标记，防止京东过期 Cookie 误删登录态
- 新增关键 Cookie 类型（如新增登录依赖字段）时，需同步维护 `mergeAndPersistCookies()` 中的登录关键 Cookie 白名单

---

## 六、开发注意事项

### 新增功能检查清单

添加新功能时，请按以下清单检查：

#### 1. 数据库字段

```php
// includes/db.php - createTables() 方法中添加
if (!in_array('new_field', $existingFields)) {
    $this->pdo->exec("ALTER TABLE products ADD COLUMN new_field INTEGER DEFAULT 0");
}
```

#### 2. API 接口

```php
// api/products.php - 添加到 $allowedFields 数组
$allowedFields = [
    'name', 'target_price', 'new_field',  // 添加新字段
    // ...
];

// 添加商品时也要包含
"INSERT INTO products (..., new_field, ...) VALUES (..., ?, ...)"
```

#### 3. 前端模块

```javascript
// assets/js/modules/products.js

// 添加商品时初始化
addProduct: {
    name: '',
    new_field: 0,  // 添加默认值
}

// 编辑商品时转换
editProduct = {
    ...product,
    new_field: !!Number(product.new_field),  // 布尔值转换
}
```

#### 4. 前端模板

```html
<!-- index.php - 编辑弹窗 -->
<label class="flex items-center gap-2">
    <input type="checkbox" x-model="editProduct.new_field" class="rounded">
    <span class="text-sm">新功能开关</span>
</label>
```

#### 5. 文档更新（必须严格遵守）

每次新增或修改系统功能时，必须对相关的 Markdown 文档进行全面检查和详细更新：

**（1）功能变更记录**
- 精确记录功能变更的具体内容
- 新增功能的详细说明
- 修改功能的具体调整点及变更原因
- 修改文件列表

**（2）技术难点与解决方案**
- 详细描述功能实现过程中遇到的技术难点
- 包括问题现象、分析过程
- 最终采用的解决方案及原因

**（3）错误陷阱及规避方法**
- 明确指出功能实现和使用过程中的潜在错误陷阱（"坑"）
- 提供具体的识别方法
- 提供规避措施

**（4）使用说明与注意事项**
- 清晰的功能使用步骤说明
- 前置条件、操作流程、参数说明
- 预期结果、注意事项和限制条件

**文档更新清单**：
- `CHANGES.md` - 记录修改内容（必须）
- `README.md` - 更新功能说明、数据库表结构（必须）
- `DEVELOPMENT.md` - 更新技术文档、开发注意事项（必须）

---

### 常见错误及解决方案

#### 错误 1：Alpine.js 模板不渲染

**原因**：`x-for` 内嵌套多个 `x-if`

```html
<!-- ❌ 错误写法 -->
<template x-for="item in items" :key="item.id">
    <template x-if="item.type === 'a'">
        <div>A</div>
    </template>
    <template x-if="item.type === 'b'">
        <div>B</div>
    </template>
</template>

<!-- ✅ 正确写法 -->
<template x-for="item in items" :key="item.id">
    <div>
        <span x-show="item.type === 'a'">A</span>
        <span x-show="item.type === 'b'">B</span>
    </div>
</template>
```

#### 错误 2：新字段不生效

**排查步骤**：
1. 检查 `includes/db.php` 是否添加了迁移代码
2. 检查 `api/products.php` 的 `$allowedFields` 数组
3. 检查 INSERT 语句是否包含新字段
4. 刷新页面触发数据库迁移

#### 错误 3：前端方法找不到

**原因**：模块未正确合并到 app.js

```javascript
// app.js - 确保模块已合并
function app() {
    return Object.assign(
        {},
        createUtilsModule(),
        createProductsModule(),
        createNewModule(),  // 添加新模块
    );
}
```

#### 错误 4：链接拼接错误

**原因**：未正确处理相对 URL

```php
// ❌ 错误：协议相对 URL 被错误拼接
$link = 'https://m.jd.com' . $relativeUrl;  // //xxx.com → https://m.jd.com//xxx.com

// ✅ 正确：跳过协议相对 URL
if (preg_match('/^\/\//', $link)) {
    continue;  // 跳过 //xxx.com 格式
}
```

#### 错误 5：价格历史重复记录

**原因**：每次检查都无条件插入记录

```php
// ❌ 错误：无条件插入
$db->execute("INSERT INTO price_history ...");

// ✅ 正确：只在变化时插入
if ($newPrice != $oldPrice || $newStockStatus != $oldStockStatus) {
    $db->execute("INSERT INTO price_history ...");
}
```

#### 错误 6：Webhook 数据层级混淆

**原因**：webhook.php 中混淆了 `$data` 和 `$product` 层级

```php
// scheduler.php 传递的数据结构
$webhook->send('price_surge', [
    'product' => [
        'name' => $product['name'],
        'current_price' => $newPrice,
        // ...
    ],
    'old_price' => $oldPrice,        // 在 $data 根级别
    'surge_amount' => $surgeAmount,   // 在 $data 根级别
    'surge_percent' => $surgePercent  // 在 $data 根级别
]);

// ❌ 错误：从 $product 中获取
"之前价格：¥{$product['old_price']}"  // 不存在

// ✅ 正确：从 $data 中获取
"之前价格：¥{$data['old_price']}"
"涨幅：¥{$data['surge_amount']} (↑{$data['surge_percent']}%)"
```

---

### 时间处理约定

#### SQLite 时间函数

```php
// 使用本地时间（+8小时）
"INSERT INTO table (created_at) VALUES (datetime('now', 'localtime'))"

// ❌ 不要使用 CURRENT_TIMESTAMP（UTC 时间）
"INSERT INTO table (created_at) VALUES (CURRENT_TIMESTAMP)"  // 少8小时
```

#### 时间间隔随机化

```php
// 商品检查：60-120 分钟随机
$nextMinutes = rand(60, 120);
$nextCheck = date('Y-m-d H:i:s', strtotime("+{$nextMinutes} minutes"));

// Cookie 检查：6-12 小时随机（精确到分钟）
$nextHours = rand(6, 12);
$nextMinutes = rand(0, 59);
$nextCheck = date('Y-m-d H:i:s', strtotime("+{$nextHours} hours +{$nextMinutes} minutes"));
```

---

### 防风控注意事项

#### 购物车浏览模拟

```php
// ✅ 正确：进入购物车后，从监控商品中随机抽取浏览
$jd->randomBrowseCart(['100012345', '100067890']);

// ❌ 错误：从首页抓取所有链接随机跳转
//    （CDN、广告、活动页混入，且发散路径易被风控识别）
```

#### 链接格式

```php
// ✅ 正确格式
'https://item.m.jd.com/product/100012345.html'

// ❌ 错误格式（不要拼接）
'https://m.jd.com//item.m.jd.com/product/100012345.html'
'https://m.jd.com//img12.360buyimg.com'
```

---

### 通知功能注意事项

#### 新增通知类型

1. 在 `scheduler.php` 添加触发逻辑
2. 在 `includes/webhook.php` 的 `send()` 方法中处理
3. 更新 `README.md` 的通知类型表格
4. 添加数据库开关字段（如 `notify_xxx`）

#### 通知数据结构

```php
$webhook->send('notification_type', [
    'product_id' => $product['id'],
    'product' => [
        'name' => $product['name'],
        'sku_id' => $product['sku_id'],
        'current_price' => $newPrice,
        'url' => "https://item.jd.com/{$product['sku_id']}.html"
    ],
    'next_check_time' => $nextCheck
]);
```

---

## 七、调度器字段速查

| 表 | 字段 | 间隔 | 用途 |
|-----|------|------|------|
| products | next_check_at | 60-120分钟随机 | 价格检查 |
| products | history_retention_days | - | 历史保留天数 |
| settings | next_cookie_check_at | 6-12小时随机 | Cookie 检查（scheduler.php） |
| settings | cookie_checked_at | - | Cookie 最后检查时间（cron.php） |
| settings | cookie_check_interval | 自定义（默认360分钟） | Cookie 检查间隔（cron.php） |
| settings | price_protection_last_run | - | 价保最后执行时间 |
| settings | price_protection_interval | 自定义（默认360分钟） | 价保执行间隔 |
| settings | next_clean_at | 24小时固定 | 历史清理 |

### 常见问题排查

| 问题 | 排查方向 |
|------|----------|
| Alpine.js 不渲染 | 检查是否嵌套 `<template x-if>` |
| 新字段不生效 | 检查 `includes/db.php` 迁移代码 |
| 模块方法找不到 | 检查 `app.js` 是否正确合并模块 |
| API 返回错误 | 检查 CSRF Token 是否正确携带 |
| 价格获取失败 | 检查 Cookie 是否有效 |
| 定时任务不执行 | 检查 `next_*_at` 字段是否初始化 |

### 最近新增功能

1. **标签系统**：`api/tags.php` + `assets/js/modules/tags.js`
2. **价格日历**：`chart.js` 中的 `calendarDays` 数组
3. **历史清理**：每个商品单独设置 `history_retention_days`
4. **定时清理**：`settings.next_clean_at` 字段

---

## 八、命令速查

### 调度器命令

```bash
php scheduler.php              # 自动执行所有到期任务
php scheduler.php product      # 只检查商品价格
php scheduler.php cookie       # 只检查 Cookie
php scheduler.php protection   # 只执行价保
php scheduler.php clean        # 强制执行历史清理
php scheduler.php force        # 强制执行所有任务
```

### 批量任务命令

```bash
php cron.php all               # 批量执行所有任务
php cron.php update_prices     # 批量更新价格
php cron.php check_cookie      # 检查 Cookie
php cron.php price_protection  # 执行价保
```

---

## 九、更新日志

详见 [CHANGES.md](CHANGES.md)
