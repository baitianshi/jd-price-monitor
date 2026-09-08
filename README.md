# 京东商品价格监控系统

一个基于 PHP + SQLite 的京东商品价格监控系统，支持价格监控、降价通知、价格走势图表、一键价保等功能。

## 功能特性

### 商品管理
- 添加/编辑/删除商品
- 支持PC链接、APP分享链接、短链接解析
- 商品图片自动获取
- 当前价格与原价同时显示
- 标签分类管理（自定义颜色、筛选）
- 自定义通知类型（降价提醒、涨价提醒、历史最低价、库存变化）
- 历史数据保留天数（每个商品单独设置）

### 价格监控
- 实时价格检查
- 价格走势图表（7天/周/月/季/年/全部）
- 价格日历视图（日历形式展示每日价格）
- 价格涨跌颜色区分（绿色降价/红色涨价）
- 图表支持拖动平移和缩放
- 历史最低价标记
- 价格记录弹窗（支持时间筛选、商品筛选）

### 数据管理
- 历史数据自动清理（每24小时执行）
- 每个商品可单独设置保留天数（默认365天）
- 数据导出功能

### 价格保护
- 一键价保功能（调用京东官方API）
- 自定义执行间隔
- 价保日志记录
- 退款金额统计
- 支持手动执行和定时自动执行

### 通知系统
- 降价提醒、价格上涨提醒
- 历史最低价提醒
- 商品无货/有货提醒
- Cookie失效提醒
- 价保成功通知
- 支持钉钉、企业微信、Telegram、Discord、Slack等
- 静默时段设置（夜间完全停止京东请求，降低风控）
- 通知日志记录

### 安全特性
- CSRF防护
- 登录限流（5次失败锁定15分钟）
- 安全响应头（CSP、X-Frame-Options等）
- HttpOnly Cookie

## 环境要求

- PHP 7.4+
- SQLite3 扩展
- cURL 扩展

## 安装使用

```bash
# 内置sqlite数据库
直接上传使用
```

### 使用步骤

1. 首次登录默认密码：`admin123`，登录后请修改密码
2. 设置 → 京东配置 → 获取Cookie
3. 设置 → 通知配置 → 添加Webhook
4. 添加商品链接，设置目标价格

### 定时任务配置

系统提供两种调度方式：

#### 方式一：scheduler.php（推荐）

轻量调度器，每分钟运行一次，模拟真人行为，降低风控风险。

**核心逻辑：**

| 任务 | 触发条件 | 随机间隔 | 模拟行为 |
|------|----------|----------|----------|
| 商品价格检查 | next_check_at <= 当前时间 | 60-120分钟 | 刷新前从 m.jd.com 进入随机浏览3-5个商品页面 |
| Cookie检查 | next_cookie_check_at <= 当前时间 | 6-12小时（精确到分钟） | - |
| 价格保护 | price_protection_last_run + 间隔 <= 当前时间 | 自定义间隔（默认360分钟） | - |
| 历史数据清理 | next_clean_at <= 当前时间 | 每24小时 | - |

**执行流程：**

```
scheduler.php 每分钟运行
    │
    ├── 1. 检查商品价格（随机单商品）
    │   ├── 查询 next_check_at <= 当前时间的商品
    │   ├── 随机选一个商品
    │   ├── 从 m.jd.com 进入随机浏览3-5个商品页面（模拟真人）
    │   ├── 刷新价格、库存
    │   ├── 记录价格历史（仅当价格或库存变化时）
    │   ├── 触发 webhook（降价/涨价/缺货）
    │   └── 设置下次检查时间 = 当前时间 + 随机60-120分钟
    │
    ├── 2. 检查Cookie状态
    │   ├── 检查 next_cookie_check_at 是否到期
    │   ├── 验证Cookie有效性
    │   ├── 失效时触发 webhook
    │   └── 设置下次检查时间 = 当前时间 + 随机6-12小时（精确到分钟）
    │
    └── 3. 执行价格保护
        ├── 检查 price_protection_last_run + 间隔 是否到期
        ├── 调用京东价保API
        ├── 有退款时触发 webhook
        └── 更新 price_protection_last_run = 当前时间（间隔默认360分钟）
    
    └── 4. 清理过期历史数据
        ├── 检查 next_clean_at 是否到期
        ├── 遍历每个商品，按 history_retention_days 清理
        ├── 删除超过保留天数的价格记录
        └── 设置下次清理时间 = 当前时间 + 24小时
```

**命令行参数：**

```bash
php scheduler.php              # 自动执行所有到期任务
php scheduler.php product      # 只检查商品价格
php scheduler.php cookie       # 只检查Cookie
php scheduler.php protection   # 只执行价保
php scheduler.php clean        # 强制执行历史清理
php scheduler.php force        # 强制执行所有任务（忽略时间）
```

**Windows 任务计划程序配置：**
1. 打开"任务计划程序"
2. 创建基本任务 → 名称：京东价格监控调度器
3. 触发器：每天，重复间隔1分钟
4. 操作：启动程序
   - 程序：`php`
   - 参数：`scheduler.php`
   - 起始位置：`D:\code\jd`

**Linux/Mac (Crontab):**
```bash
* * * * * php /path/to/jd/scheduler.php
```

#### 方式二：cron.php（批量操作）

用于手动批量操作或一次性全量刷新，不推荐日常自动调度。

**命令行参数：**

| 命令 | 说明 |
|------|------|
| `php cron.php all` | 执行所有任务（批量刷新所有商品、检查Cookie、执行价保） |
| `php cron.php update_prices` | 批量更新所有商品价格 |
| `php cron.php check_cookie` | 检查Cookie状态 |
| `php cron.php price_protection` | 执行价格保护 |

**注意事项：**
- 批量刷新会按顺序刷新所有商品，有风控风险
- 商品间有3-5秒随机延迟
- 推荐使用 scheduler.php 进行日常调度

#### 两种方式对比

| 特性 | scheduler.php | cron.php |
|------|---------------|----------|
| 执行频率 | 每分钟 | 手动/低频 |
| 商品刷新 | 每次随机一个 | 批量全部 |
| 时间间隔 | 随机分散 | 固定间隔 |
| 风控风险 | 低 | 较高 |
| 适用场景 | 日常自动调度 | 手动批量操作 |

#### 数据库调度字段

| 表 | 字段 | 说明 |
|-----|------|------|
| products | next_check_at | 商品下次价格检查时间 |
| products | history_retention_days | 历史数据保留天数（默认365天） |
| settings | next_cookie_check_at | Cookie下次检查时间(scheduler.php) |
| settings | cookie_checked_at | Cookie最后检查时间(cron.php) |
| settings | cookie_check_interval | Cookie检查间隔(分钟，默认360) |
| settings | price_protection_interval | 价保执行间隔(分钟，默认360) |
| settings | price_protection_last_run | 价保最后执行时间 |
| settings | next_clean_at | 历史数据下次清理时间 |

---

## 价格获取机制

### 获取方法（按顺序尝试）

| 排名 | 方法 | 来源 | 获取内容 | 超时 |
|------|------|------|----------|------|
| 1 | 移动端页面 | item.m.jd.com | 名称、到手价、原价、库存 | 5秒 |
| 2 | 移动端API | api.m.jd.com | 名称、到手价、库存 | 5秒 |
| 3 | 公开API | p.3.cn | 到手价 | 5秒 |
| 4 | PC端页面 | item.jd.com | 图片、原价 | 5秒 |

### 获取逻辑

```
1. 按顺序尝试移动端页面 → 移动端API → 公开API
2. 成功获取到手价后，继续尝试获取原价
3. 最后从PC端补充图片和原价
4. 如果原价 < 到手价，使用到手价作为原价
```

### 成功率统计

系统自动记录每种方法的成功率和平均耗时，在设置页面显示动态排名：

- **成功率**：成功次数 / 总调用次数
- **平均耗时**：所有调用的平均响应时间
- **排名规则**：成功率优先，耗时次之

---

## Cookie 检查机制

### 检查方式（按优先级）

| 优先级 | 方法 | 判断逻辑 | 准确性 |
|--------|------|----------|--------|
| 1 | 用户信息API | `retcode=0` 则有效 | ⭐⭐⭐⭐⭐ 最准确 |
| 2 | 移动端登录API | `islogin=1` 则有效 | ⭐⭐⭐⭐ |
| 3 | 移动端商品页面 | 能获取有效价格则有效 | ⭐⭐⭐ |
| 4 | 价格API | 能获取价格则有效 | ⭐⭐⭐ |
| 5 | 移动端首页 | 页面包含用户信息则有效 | ⭐⭐ |

### 为什么用户信息API最准确

- Cookie 失效后立即返回错误码，无延迟
- 不依赖商品数据，不受风控影响
- 直接查询登录状态，结果可靠

### 检查间隔

- **自动检查**：随机 6-12 小时
- **手动检查**：点击设置页面的"检查Cookie"按钮

---

## 价格走势图表

### 时间范围
7天 | 周(7天) | 月(30天) | 季(90天) | 年(365天) | 全部

### 图表操作
- **拖动平移**：按住鼠标左键/手指拖动
- **缩放**：滚轮/双指缩放
- **重置**：点击重置按钮

### 价格颜色
- 🟢 绿色：价格下降
- 🔴 红色：价格上涨

---

## 价格记录弹窗

点击首页"价格记录"卡片，可查看所有价格变更记录：

### 筛选功能
- **时间范围**：今天 / 最近7天 / 最近30天 / 全部
- **商品筛选**：选择特定商品查看

### 显示内容
| 商品 | 原价 | 当前价 | 变动 | 记录时间 |
|------|------|--------|------|----------|
| 商品名称 | ¥129.00 | ¥89.00 | ↓ -¥40.00 | 2026-03-18 10:00 |

### 变动标识
- 🟢 绿色 ↓：降价
- 🔴 红色 ↑：涨价
- 灰色 -：无变化

---

## 价格保护功能

### 功能说明
调用京东官方一键价保API，自动申请订单差价退款。

### 配置方式
1. 设置 → 价格保护 → 启用功能
2. 设置执行间隔（如每6小时）
3. 配置通知Webhook

### 执行方式
- **手动执行**：点击"立即执行"按钮
- **自动执行**：通过cron定时任务自动执行

### 价保日志
记录每次价保申请的详细信息：
- 订单号
- 商品名称
- 购买价格
- 退款金额
- 申请状态
- 申请时间

---

## 通知类型

| 类型 | 说明 | 包含信息 |
|------|------|----------|
| price_drop | 降价提醒 | 商品名、当前价、原价、目标价、降价幅度、下次检查时间 |
| lowest_price | 历史最低价 | 商品名、当前价、原价、下次检查时间 |
| price_surge | 价格上涨 | 商品名、当前价、原价、之前价格、涨幅、下次检查时间 |
| out_of_stock | 商品无货 | 商品名、原价、下次检查时间 |
| back_in_stock | 商品有货 | 商品名、当前价、原价、下次检查时间 |
| cookie_invalid | Cookie失效 | 提示信息 |
| cookie_expired | Cookie已失效 | 检测时间、提示信息 |
| price_protection | 价保成功 | 退款金额、处理结果 |

### 自定义通知
每个商品可单独设置通知类型：
- 降价提醒
- 涨价提醒
- 历史最低价提醒
- 库存变化通知

---

## Cron任务日志

执行 `php cron.php all` 时，终端输出示例：

```
[2026-03-18 10:00:00] === 京东价格监控定时任务开始 ===
[2026-03-18 10:00:00] 任务: all

[2026-03-18 10:00:00] --- 更新商品价格 ---
[2026-03-18 10:00:00] 需要更新 3 个商品
[2026-03-18 10:00:05]   [降价提醒] 商品名 - ¥89.00 <= 目标价 ¥90.00
[2026-03-18 10:00:05]     [Webhook] ✓ 钉钉通知: 发送成功
[2026-03-18 10:00:05]     [Webhook] ✓ 企业微信: 发送成功
[2026-03-18 10:00:05]   [1] 100012345: ¥99.00 -> ¥89.00 (原价: ¥129.00)
[2026-03-18 10:00:10]   [2] 100012346: ¥50.00 -> ¥48.00 (原价: ¥60.00)

[2026-03-18 10:00:15] --- 检查Cookie状态 ---
[2026-03-18 10:00:15] Cookie状态: valid

[2026-03-18 10:00:15] --- 执行价格保护 ---
[2026-03-18 10:00:15] 开始执行一键价保...
[2026-03-18 10:00:20] 价保完成: 价保申请完成，共处理2个订单，退差价10.00元

[2026-03-18 10:00:20] === 定时任务完成 ===
```

---

## 文件说明

```
├── index.php              # 主页面（前端界面）
├── scheduler.php          # 轻量调度器（推荐日常使用）
├── cron.php               # 批量任务脚本（手动执行）
│
├── api/
│   ├── auth.php           # 认证API（登录/登出/密码修改）
│   ├── products.php       # 商品管理API（增删改查）
│   ├── check-price.php    # 价格检查API
│   ├── settings.php       # 系统设置API
│   ├── notify.php         # 通知测试API
│   ├── export.php         # 数据导出API
│   ├── network_check.php  # 网络检测API
│   ├── price-protection.php # 价格保护API
│   └── price-history.php  # 价格记录API
│
├── assets/
│   └── js/
│       ├── app.js         # 主入口（组合所有模块）
│       └── modules/       # JavaScript模块
│           ├── utils.js
│           ├── products.js
│           ├── settings.js
│           ├── chart.js
│           ├── protection.js
│           ├── price-history.js
│           └── tags.js
│
├── includes/
│   ├── config.php         # 配置文件（常量定义、CSRF、安全头）
│   ├── db.php             # 数据库类（SQLite操作、自动迁移）
│   ├── auth.php           # 认证类（登录验证、限流）
│   ├── jd.php             # 京东价格获取类
│   ├── webhook.php        # Webhook通知类
│   └── price_protection.php # 价格保护类
│
└── data/
    ├── monitor.db         # SQLite数据库（用户数据，勿覆盖）
    ├── cron.log           # cron日志
    └── scheduler.log      # scheduler日志
```

---

## 数据库表结构

### products - 商品表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| sku_id | TEXT | 京东SKU |
| name | TEXT | 商品名称 |
| image_url | TEXT | 图片URL |
| current_price | REAL | 当前价格 |
| original_price | REAL | 原价 |
| target_price | REAL | 目标价格 |
| lowest_price | REAL | 历史最低价 |
| highest_price | REAL | 历史最高价 |
| stock_status | TEXT | 库存状态 |
| stock_num | INTEGER | 库存数量 |
| status | TEXT | 状态(active/paused) |
| tags | TEXT | 标签 |
| next_check_at | DATETIME | 下次价格检查时间 |
| history_retention_days | INTEGER | 历史数据保留天数 |
| notify_price_drop | INTEGER | 降价提醒开关 |
| notify_lowest | INTEGER | 历史最低价提醒开关 |
| notify_price_surge | INTEGER | 涨价提醒开关 |
| notify_oos | INTEGER | 库存变化通知开关 |

### price_history - 价格历史表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| product_id | INTEGER | 商品ID |
| price | REAL | 价格 |
| stock_status | TEXT | 库存状态 |
| recorded_at | DATETIME | 记录时间 |

### price_protection_logs - 价保日志表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| order_id | TEXT | 订单号 |
| sku_id | TEXT | 商品SKU |
| product_name | TEXT | 商品名称 |
| buy_price | REAL | 购买价格 |
| refund_amount | REAL | 退款金额 |
| status | TEXT | 状态(success/failed/pending) |
| message | TEXT | 结果消息 |
| applied_at | DATETIME | 申请时间 |

### notification_logs - 通知日志表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| product_id | INTEGER | 商品ID |
| type | TEXT | 通知类型 |
| message | TEXT | 通知内容 |
| webhook_url | TEXT | Webhook地址 |
| success | INTEGER | 是否成功 |
| created_at | DATETIME | 创建时间 |

### price_method_stats - 价格获取方法统计表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| method | TEXT | 方法名称 |
| success_count | INTEGER | 成功次数 |
| total_count | INTEGER | 总调用次数 |
| total_time | REAL | 总耗时(秒) |
| last_success_at | DATETIME | 最后成功时间 |

### settings - 系统设置表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| access_password | TEXT | 访问密码 |
| jd_cookies | TEXT | 京东Cookie |
| cookie_status | TEXT | Cookie状态 |
| cookie_checked_at | DATETIME | Cookie最后检查时间 |
| cookie_check_interval | INTEGER | Cookie检查间隔(分钟，默认360) |
| webhooks | TEXT | Webhook配置(JSON) |
| price_protection_enabled | INTEGER | 价保开关 |
| price_protection_interval | INTEGER | 价保间隔(分钟) |
| price_protection_last_run | DATETIME | 价保最后执行时间 |
| next_cookie_check_at | DATETIME | Cookie下次检查时间(scheduler.php) |
| next_clean_at | DATETIME | 历史数据下次清理时间 |
| silent_start | TEXT | 静默开始时间 |
| silent_end | TEXT | 静默结束时间 |

### tags - 标签表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| name | TEXT | 标签名称 |
| color | TEXT | 标签颜色 |
| created_at | DATETIME | 创建时间 |

### login_attempts - 登录尝试表
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER | 主键 |
| ip_address | TEXT | IP地址 |
| attempts | INTEGER | 失败次数 |
| lockout_until | DATETIME | 锁定到期时间 |

---

## 常见问题

**Q: Cookie多久过期？**
A: 1-3个月，系统会发送失效通知。

**Q: 为什么获取不到价格？**
A: 
1. Cookie未设置或已失效
2. 商品已下架
3. 被京东风控拦截（尝试更换Cookie）

**Q: 价格获取很慢怎么办？**
A: 
1. 查看设置页面的"价格获取方法统计"
2. 成功率低的方法会被自动降权
3. 网络问题可尝试使用代理

**Q: 图表空白怎么办？**
A: 点击重置按钮，检查浏览器控制台错误。

**Q: 短链接无法解析？**
A: 
1. 确保服务器能访问外网
2. 检查cURL扩展是否安装
3. 尝试直接使用完整商品链接

**Q: 价保功能提示获取PIN失败？**
A: 
1. 确保Cookie中包含pt_pin字段
2. 重新获取完整的Cookie
3. 检查Cookie是否有效（能否刷新价格）

**Q: 通知设置保存后不显示？**
A: 刷新页面后重新打开编辑弹窗查看。

**Q: 如何更新系统？**
A: 
1. 备份 `data/monitor.db` 数据库文件
2. 覆盖代码文件（排除 data/ 目录）
3. 访问系统，自动触发数据库迁移
4. 完成

---

## 更新日志

详见 [CHANGES.md](CHANGES.md)

---

## 开源协议

MIT License
