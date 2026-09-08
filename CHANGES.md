# 京东商品价格监控系统 - 修改记录

> **文档更新规范**：每次功能变更必须包含以下内容：
> 1. **功能变更记录**：精确记录变更内容、新增功能说明、修改调整点及原因
> 2. **技术难点与解决方案**：问题现象、分析过程、最终方案
> 3. **错误陷阱及规避方法**：潜在问题、识别方法、规避措施
> 4. **使用说明与注意事项**：前置条件、操作流程、参数说明、限制条件

---

## v2.2.0 - 2026-09-08

### 京东扫码登录功能

**【功能变更记录】**

**1. 新增扫码登录获取Cookie**

用户无需再手动从浏览器提取pt_key和pt_pin，直接在设置页面扫码即可完成登录，自动获取并保存完整Cookie。

**新增文件**：
- `api/jd-qrcode.php` - 扫码登录后端API（3个接口）

**修改文件**：
- `assets/js/modules/settings.js` - 新增扫码登录前端逻辑（7个方法）
- `index.php` - 新增扫码登录按钮和弹窗UI

**功能特性**：
- 一键生成京东登录二维码
- 实时显示扫码状态（等待扫描/已扫描/已确认/已过期/错误）
- 扫码确认后自动获取pt_key、pt_pin等完整Cookie
- 自动保存到数据库，无需手动输入
- 二维码3分钟过期，支持刷新
- 优雅的状态动画和交互反馈

**【技术难点与解决方案】**

| 难点 | 说明 | 解决方案 |
|------|------|----------|
| 二维码token获取 | 京东二维码接口通过Set-Cookie返回wlfstk_smdl作为token | 解析响应头中的Set-Cookie提取token，保存到session用于后续轮询 |
| 扫码状态轮询 | 需要持续查询扫码进度，不能阻塞 | 前端每2秒轮询一次check_status接口，最多90次（约3分钟） |
| Cookie获取不完整 | ticket验证后可能只返回部分cookie | 依次访问ticket验证URL → 跳转URL → 京东首页，收集全程所有Set-Cookie |
| 跨域重定向Cookie丢失 | curl重定向时可能丢失中间步骤的cookie | 禁用FOLLOWLOCATION，手动跟随重定向并收集每一步的Set-Cookie |
| Session状态保持 | 扫码登录是多步流程，需要在请求间保持状态 | 使用PHP session存储token和ticket，每次请求从session读取 |

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| 二维码token为空 | 解析Set-Cookie失败导致后续轮询全部失败 | 首次获取失败时备用方案：先访问passport页面再请求二维码 |
| 轮询超时未清理 | 关闭弹窗后轮询定时器继续运行 | closeQrLogin()中统一清理定时器 |
| Cookie不含pt_key | 登录成功但没拿到关键cookie，等于没登录 | 严格校验pt_key和pt_pin都存在才返回成功，否则提示重试 |
| 过期判断不一致 | 前端和后端过期时间不同步 |以后端session的jd_qr_time为准，前端只做辅助提示 |
| Token泄露 | URL中携带token可能被记录 | token只存在于session和请求头Cookie中，不暴露在URL或响应体 |

**【使用说明】**

1. 进入系统 → 点击右上角"设置"
2. 在"京东配置"区域，点击绿色的"扫码登录"按钮
3. 弹出二维码窗口，打开京东APP → 右上角"扫一扫"
4. 扫描成功后，在手机上点击"确认登录"
5. 系统自动获取Cookie并保存，弹窗自动关闭
6. 设置页面会自动刷新，显示登录的京东用户信息

**注意事项**：
- 二维码有效期约3分钟，过期后点击刷新即可
- 如果扫码登录失败，仍然可以使用手动输入pt_key/pt_pin的方式
- 首次使用建议用移动端浏览器登录过的账号扫码，成功率更高

---

## v2.1.5 - 2026-09-07

### 夜间静默期强化

**【功能变更记录】**

**1. scheduler.php 自动调度受静默期保护**

静默时段内，自动调度（`php scheduler.php` 无参模式）会跳过所有需要访问京东的任务（商品价格检查、Cookie检查、价格保护），仅执行本地历史数据清理，避免夜间产生请求行为进一步降低风控压力。

| 文件 | 变更 |
|------|------|
| `scheduler.php` | 新增 `isInSilentPeriod()` 函数，主入口 `all` 模式下静默期直接跳过京东请求任务 |

**2. cron.php 手动批量任务受静默期保护**

手动执行 `php cron.php all` 或任何涉及京东请求的任务时，静默期内同样阻止执行并给出提示，防止误操作在夜间触发大量请求。

| 文件 | 变更 |
|------|------|
| `cron.php` | 新增 `isInSilentPeriod()` 函数，所有任务执行前检查静默期 |

**静默期规则：**
- 默认 `23:00 - 07:00`，可在设置页面调整
- 支持跨天设置（如 23:00 - 07:00）
- 任一时段为空则不启用静默期
- 静默期内历史清理正常执行（纯本地操作，无外部请求）
- 手动指定 `scheduler.php product/cookie/protection/clean` 不受静默期限制（用户明确意图）
- `scheduler.php force` 模式同样不受限（强制执行语义）

**【技术难点与解决方案】**

| 难点 | 说明 | 解决方案 |
|------|------|----------|
| 静默期与 force 模式冲突 | force 语义是"忽略到期检查强制跑"，但静默期是更高优先级的风控保护 | 折中：手动指定任务和 force 模式都允许执行，只有默认 all 自动调度受静默期限制，既保安全又不堵死手动入口 |
| 历史清理要不要跳过 | 清理是纯数据库操作，无外部请求 | 静默期内仍执行清理，不影响风控 |
| 逻辑复用 | webhook.php 已有 isInSilentPeriod，但两个入口文件无法直接复用类私有方法 | 在 scheduler.php 和 cron.php 各自实现相同逻辑的独立函数，保持入口文件轻量无额外依赖 |

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| 静默期边界判断 | `23:00 - 07:00` 是跨天的，不能直接用 `start <= now <= end` | 先判断 start > end（跨天）用 `now >= start OR now < end`，否则用区间判断 |
| 空值误判 | silent_start 或 silent_end 为空时不能当作 00:00 处理 | 任一为空直接返回 false（不启用） |
| 手动任务被拦截 | 用户主动执行 cron.php 也被静默期挡掉，不符合预期 | cron 静默期直接拦截所有；scheduler 只有默认 all 模式受限制，手动指定子任务不受限 |

**【使用说明】**

1. 默认生效：升级后静默期立即生效，默认时段 23:00 - 07:00
2. 调整时段：设置页面 → 静默开始/结束时间，保存后立即生效
3. 关闭静默：将开始或结束时间清空即可
4. 夜间行为：调度器每分钟仍运行，但仅输出"静默时段"日志并跳过所有京东请求
5. 紧急执行：夜间想手动跑任务，用 `php scheduler.php product` 等指定任务方式（不受静默期限制）
6. 验证方式：将静默期设为包含当前时间，运行 `php scheduler.php` 观察是否输出"静默时段"日志

---

## v2.1.4 - 2026-09-07

### 12项Bug修复与调度逻辑统一

**【功能变更记录】**

**1. 时区问题修复（多处 CURRENT_TIMESTAMP → datetime('now','localtime')）**

SQLite 的 `CURRENT_TIMESTAMP` 使用 UTC 时间，比北京时间少 8 小时。本次修复了所有遗漏位置：

| 文件 | 修复内容 |
|------|----------|
| `includes/config.php` | 登录限流 `last_attempt` 写入 |
| `includes/auth.php` | `changePassword()` 的 `updated_at` |
| `includes/jd.php` | `price_method_stats` 的 `last_success_at/updated_at` |
| `includes/webhook.php` | 通知日志 `sent_at` |
| `includes/price_protection.php` | 价保日志 `applied_at`、`updateLastRunTime()` |
| `scheduler.php` | 调度器时间写入 |
| `cron.php` | 批量任务时间写入 |
| `api/check-price.php` | `last_checked_at/updated_at` |
| `api/products.php` | 批量/单商品更新的 `updated_at` |
| `api/settings.php`、`api/price-protection.php`、`api/tags.php` | 各类时间写入 |

> 注：`db.php` 中 `CREATE TABLE ... DEFAULT CURRENT_TIMESTAMP` 保持不变，因为 SQLite 的 DEFAULT 只支持 `CURRENT_*` 关键字，无法使用 `datetime('now','localtime')`。

**2. 价保调度逻辑统一**

**问题**：scheduler.php 原使用 `next_protection_at` 字段判断价保到期，与 `PriceProtection` 类的 `price_protection_interval` + `price_protection_last_run` 逻辑不一致，且手动执行价保后两个字段不同步，导致价保可能被重复触发或无法触发。

**修复**：scheduler.php 和 cron.php 统一使用 `PriceProtection::shouldRunProtection()` / `updateLastRunTime()`：
- `shouldRunProtection()`：`price_protection_last_run + price_protection_interval`（分钟）≤ 当前时间
- 执行成功后调用 `updateLastRunTime()` 写入本地时间
- `next_protection_at` 字段保留（迁移兼容），但不再参与调度

**3. 通知功能补全**

| 通知类型 | 修复内容 |
|----------|----------|
| lowest_price | scheduler.php 缺失的历史最低价通知块已补全 |
| back_in_stock | scheduler.php/cron.php 缺失的恢复上架通知块已补全 |
| price_surge | scheduler.php 通知中补全 `next_check_time` 字段 |
| cookie_expired | cron.php/scheduler.php 统一触发逻辑 |

**4. 废弃函数清理**

删除 scheduler.php 中未使用的 `randomSeconds()`、`randomMinutes()` 函数。

**5. 移除 jd.php 硬编码 pt_pin**

**问题**：`checkCookieStatus()` 中 Cookie 缺少 pt_pin 时自动注入硬编码值 `1160355588-373197`（特定账号标识，存在隐私与误判风险）。

**修复**：
- 删除硬编码注入逻辑
- Cookie 格式校验改为必须同时包含 `pt_key` 和 `pt_pin`，缺失任一字段返回 `invalid_format`
- 同步清理 index.php 设置页中该硬编码值的默认占位提示

**6. jd.php 深度浏览链接拼接修复**

深度浏览时，从页面提取的 `item.m.jd.com/product/{skuId}.html` 链接被错误拼接为 `https://m.jd.com/item.m.jd.com/product/xxx.html`，导致访问 404。修复为直接使用完整链接格式。

**7. webhook.php 降价模板变量修复**

降价通知模板中 `$product['old_price']` 不存在（实际在 `$data` 根级别），显示为空。修复为 `($product['old_price'] ?? $data['old_price'] ?? 0)` 兜底取值。

**8. 批量添加商品字段补全**

`api/products.php` 批量添加商品时，`original_price`、`stock_status`、`stock_num`、`notify_*`、`history_retention_days` 字段未写入，导致新商品这些字段为空。已补全 INSERT 字段。

**9. api/products.php PUT 数值验证**

单商品更新接口缺少数值字段校验，非数值输入会存入数据库。已添加 `target_price`、`history_retention_days` 等字段的数值验证。

**10. Cookie检查逻辑统一**

scheduler.php 原来自行实现 Cookie 验证，改用 `JdPrice::checkCookieStatus()` 统一 5 级验证逻辑（用户信息API → 移动端登录API → 移动端商品页面 → 价格API → 移动端首页）。

**11. 新增 cookie_check_interval 字段**

settings 表新增 `cookie_check_interval INTEGER DEFAULT 360`（分钟），cron.php 的 Cookie 检查间隔改为可配置，与设置页面联动。

**12. cron.php SELECT 字段扩展**

cron.php 查询商品时补充 `notify_price_drop`、`notify_lowest`、`notify_oos`、`notify_price_surge` 字段，修复批量更新时不发送通知的问题。

**【技术难点与解决方案】**

| 难点 | 问题现象 | 解决方案 |
|------|----------|----------|
| 价保双重调度 | scheduler 用 next_protection_at，类内用 interval+last_run，两者不同步 | 统一收口到 shouldRunProtection()/updateLastRunTime() |
| 硬编码账号泄露 | 代码中写死 pt_pin 值，校验时自动注入 | 删除注入，强制要求 Cookie 同时含 pt_key 和 pt_pin |
| 批量添加字段缺失 | 批量接口 INSERT 字段不全 | 对照单商品添加逻辑补齐全部字段 |
| SQLite 默认值限制 | CREATE TABLE DEFAULT 不支持函数 | 表结构默认值保留 CURRENT_TIMESTAMP，写入时用 localtime |
| 通知缺失 | 部分通知块（lowest_price/back_in_stock）未实现 | 参照 check-price.php 的 checkNotifications() 补全 |

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| 字段迁移遗漏 | 新增 cookie_check_interval 未迁移则 cron.php 报错 | 在 db.php runMigrations() 添加 ALTER TABLE |
| pt_pin 自动注入 | Cookie 缺 pt_pin 时被注入硬编码值 | 校验必须同时含 pt_key 和 pt_pin，缺一返回 invalid_format |
| 时间少8小时 | 遗留 CURRENT_TIMESTAMP 写入 | 全局搜索 `CURRENT_TIMESTAMP` 逐个确认，写入统一 localtime |
| 手动价保后不同步 | 手动执行不更新 next_protection_at | 统一用 updateLastRunTime() 记录最后执行时间 |
| 深度浏览链接404 | 完整链接被拼接域名前缀 | 直接使用提取到的完整 item.m.jd.com 链接 |
| 通知模板取错层级 | $product 与 $data 层级混淆 | 用 `?? ` 多重兜底取值 |

**【使用说明】**

1. 自动生效：本次修改全部为后端逻辑，部署后首次访问自动触发数据库迁移
2. 新字段：settings 表新增 `price_protection_last_run`、`cookie_check_interval`
3. Cookie 要求：设置的 Cookie 必须同时包含 `pt_key` 和 `pt_pin`，否则状态显示为"格式无效"
4. 价保间隔：设置页面"价格保护间隔"（分钟）即 `price_protection_interval`，自动执行时按该间隔计算到期时间
5. Cookie 检查间隔：设置页面新增"Cookie检查间隔"（分钟，默认360），cron.php 批量检查时生效
6. 预期结果：调度日志中价保显示"价保未到期，跳过执行"时，说明间隔判断正确

---

## v2.1.3 - 2026-03-21

### 涨价通知涨幅显示修复

**【功能变更记录】**

**修复**：涨价通知涨幅显示 INF% 的问题

**问题描述**：
- 涨价通知中涨幅显示为 `INF%`
- 之前价格字段显示为空

**修改文件**：
- `includes/webhook.php` - 修复涨幅计算逻辑

**【技术难点与解决方案】**

| 难点 | 问题现象 | 解决方案 |
|------|----------|----------|
| 数据层级错误 | `$product['old_price']` 不存在 | 使用 `$data['old_price']` |
| 重复计算 | webhook.php 重复计算涨幅 | 直接使用 scheduler.php 已计算好的值 |

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| 数据层级混淆 | `$data` 和 `$product` 层级不清 | 检查 scheduler.php 传递的数据结构 |
| 除以零 | old_price 为空时计算涨幅 | 在 scheduler.php 中已有 `oldPrice > 0` 判断 |

**【使用说明】**

1. 自动生效：修改后自动生效
2. 预期结果：涨幅正确显示金额和百分比，如 `¥0.32 (↑6.4%)`

---

## v2.1.2 - 2026-03-20

### 一、涨价通知功能

**【功能变更记录】**

**新增**：商品涨价时发送通知

**功能说明**：
- 每个商品可单独开启/关闭涨价通知
- 涨价时显示涨幅金额和百分比
- 默认关闭，需手动开启

**修改文件**：
- `includes/db.php` - 新增 `notify_price_surge` 字段
- `scheduler.php` - 添加涨价检测和通知逻辑
- `api/products.php` - 支持保存涨价通知设置
- `index.php` - 编辑弹窗添加涨价通知开关

**【技术难点与解决方案】**

无特殊技术难点，实现较为直接。

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| 字段未迁移 | 新字段未添加到数据库 | 在 `db.php` 的 `createTables()` 中添加迁移代码 |
| 前端未初始化 | 编辑时字段为 undefined | 在编辑按钮点击时添加 `!!Number()` 转换 |

**【使用说明】**

1. 前置条件：系统已运行，数据库已迁移
2. 操作流程：编辑商品 → 勾选"涨价提醒" → 保存
3. 预期结果：商品涨价时收到 Webhook 通知

---

### 二、防风控措施优化（实时抓取商品链接）

**【功能变更记录】**

**改进**：从 m.jd.com 首页实时抓取商品链接随机访问

**修改内容**：
- 只提取 `item.m.jd.com/product/xxx.html` 格式的商品链接
- 从 `/product/xxx` 路径提取并转换为正确格式
- 从 `sku=xxx` 参数提取并转换
- 链接不再拼接任何多余内容，避免错误链接

**修改文件**：
- `includes/jd.php` - 重构 randomBrowseFromMobile() 方法

**【技术难点与解决方案】**

| 难点 | 问题现象 | 解决方案 |
|------|----------|----------|
| 协议相对 URL | `//xxx.com` 被拼接为 `https://m.jd.com//xxx.com` | 跳过以 `//` 开头的链接 |
| CDN 链接误访问 | `img12.360buyimg.com` 等非页面链接被访问 | 只提取商品链接格式 |

**【错误陷阱及规避方法】**

| 陷阱 | 错误示例 | 正确做法 |
|------|----------|----------|
| 协议相对 URL 拼接 | `https://m.jd.com//img12.360buyimg.com` | `if (preg_match('/^\/\//', $link)) continue;` |
| 提取所有链接 | 包含 CDN、广告、统计等无效链接 | 只提取 `item.m.jd.com/product/xxx.html` 格式 |

**【使用说明】**

1. 自动触发：scheduler.php 每次检查商品前自动执行
2. 执行流程：访问 m.jd.com → 提取商品链接 → 随机访问 3-5 个
3. 预期结果：日志显示正确的商品链接格式

---

### 三、价格历史记录优化

**【功能变更记录】**

**改进**：只在价格或库存变化时才记录历史

**变更原因**：之前每次检查都无条件插入记录，导致大量重复数据

**修改内容**：
- 添加判断：`if ($newPrice != $oldPrice || $newStockStatus != $oldStockStatus)`
- 只在变化时才插入 price_history 表

**修改文件**：
- `scheduler.php` - 添加条件判断

**【技术难点与解决方案】**

无特殊技术难点。

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| 历史数据不完整 | 只记录变化可能导致某些时间点无数据 | 这是预期行为，只记录有意义的变化 |
| 旧数据清理 | 已存在的重复数据需要清理 | 手动执行清理脚本或等待自然过期 |

**【使用说明】**

1. 自动生效：修改后自动生效，无需手动操作
2. 预期结果：价格历史记录数量减少，间隔更合理

---

### 四、Cookie 检查优先级调整

**改进**：用户信息 API 排第一位（最准确）

**原因**：
- Cookie 失效后立即返回错误码，无延迟
- 不依赖商品数据，不受风控影响
- 直接查询登录状态，结果可靠

**检查顺序**：
1. 用户信息 API（最准确）
2. 移动端登录 API
3. 移动端商品页面
4. 价格 API
5. 移动端首页（最后备选）

**修改文件**：
- `includes/jd.php` - 调整 checkCookieStatus() 方法顺序

---

### 五、价格检查间隔调整

**改进**：从 30-60 分钟调整为 60-120 分钟

**修改文件**：
- `scheduler.php` - 修改 rand(30, 60) 为 rand(60, 120)
- `README.md` - 更新文档
- `DEVELOPMENT.md` - 更新文档

---

## v2.1.1 - 2026-03-20

### 防风控措施优化

**改进**：从 m.jd.com 进入后随机访问移动端页面

**修改内容**：
- 新增 `JdPrice::randomBrowseFromMobile()` 方法
- 先访问 m.jd.com 首页，再随机访问 3-5 个移动端页面
- 所有请求使用移动端 User-Agent 和 m.jd.com Referer
- 每个页面停留 2-4 秒，总耗时约 10-20 秒

**移动端页面列表**：
- 首页、分类、秒杀、PLUS、品牌、新品、试用、拍卖

**修改文件**：
- `includes/jd.php` - 新增 randomBrowseFromMobile() 方法
- `scheduler.php` - 更新 randomBrowseJd() 函数

---

## v2.1.0 - 2026-03-20 11:15

### 一、历史数据自动清理（商品级别）

**功能**：每个商品可单独设置历史数据保留天数

**修改内容**：
- 移除设置页面的全局历史保留天数设置
- 每个商品编辑弹窗添加"历史数据保留天数"字段（默认365天）
- 商品卡片不显示此设置，仅在编辑时可见

**新增数据库字段**：`products.history_retention_days`（默认365天）

**修改文件**：
- `includes/db.php` - 添加字段迁移
- `api/products.php` - 支持保存字段
- `index.php` - 编辑商品弹窗添加输入框，移除设置页面的全局设置

---

### 二、价格日历视图

**功能**：日历形式展示每日最低价格，颜色标识价格高低

**修改文件**：
- `assets/js/modules/chart.js` - 添加日历相关方法
- `index.php` - 价格日历弹窗、按钮

**功能特性**：
- 月份导航：前后月份切换
- 价格颜色标识：
  - 绿色：达到目标价 / 接近最低价
  - 蓝色：低于当前价
  - 红色：高于当前价10%
- 图例说明

---

### 三、标签管理系统

**功能**：完整的标签管理，支持颜色、筛选、选择器

**新增数据库表**：`tags`（id, name, color, created_at）

**新增文件**：
- `api/tags.php` - 标签CRUD接口
- `assets/js/modules/tags.js` - 标签前端模块

**修改文件**：
- `includes/db.php` - 创建tags表
- `assets/js/app.js` - 集成标签模块、添加标签筛选
- `index.php` - 标签管理弹窗、标签筛选、标签选择器

**功能特性**：
- 标签管理弹窗：添加/删除标签，自定义颜色
- 标签筛选：工具栏下拉选择标签筛选商品，选择"全部标签"取消筛选
- 标签选择器：添加/编辑商品时可选择已有标签或输入新标签
- 标签取消：已选标签旁边有 x 按钮可移除
- 标签显示：商品卡片显示带颜色的标签

---

### 四、Bug修复

**价格日历日期不显示**：
- **问题**：Alpine.js 的 `x-for` 模板中不能有两个根元素
- **修复**：使用 `x-if` 替代 `x-show` 来条件渲染两个不同的元素
- **涉及文件**：`index.php`

---

## v2.0.0 - 2026-03-19 18:00

### 一、JavaScript 模块化重构

**问题**：index.php 代码臃肿，影响加载和响应

**解决方案**：将 JavaScript 代码拆分为独立模块

**新增文件**：
```
assets/js/
├── app.js           # 主入口，组合所有模块
└── modules/
    ├── utils.js     # 工具函数（toast、csrf等）
    ├── products.js  # 商品管理模块
    ├── settings.js  # 设置模块
    ├── chart.js     # 图表模块
    ├── protection.js # 价保模块
    └── price-history.js # 价格记录模块
```

**效果**：index.php 体积减少约 35%

---

### 二、商品不显示问题修复

**问题**：模块化后添加的商品不显示

**原因**：Alpine.js 的 getter 方法通过对象展开运算符传递时丢失

**修复**：
- 在 app.js 中重新定义 `filteredProducts` getter
- 添加 `loadProducts()` 调用到 init() 方法

---

### 三、Cron 与价格记录同步问题

**问题**：cron.php 执行记录与 price_history 表不同步

**原因**：
- cron.php 硬编码 stock_status 为 'unknown'
- cron.php 缺少 highest_price、stock_num、last_checked_at 字段更新
- cron.php 和 check-price.php 实现不一致

**修复**：统一 cron.php 与 check-price.php 的实现逻辑

---

### 四、时间同步问题修复

**问题**：价格记录时间比实际时间少 8 小时

**原因**：SQLite 的 `CURRENT_TIMESTAMP` 默认使用 UTC 时间

**修复**：所有 INSERT 语句改用 `datetime('now', 'localtime')`

**涉及文件**：cron.php、scheduler.php、api/check-price.php、api/products.php

---

### 五、日志文件记录

**新增功能**：cron.php 和 scheduler.php 添加日志文件记录

**日志路径**：
- `data/cron.log`
- `data/scheduler.log`

---

### 六、防风控措施

**问题**：频繁请求京东 API 可能触发风控

**解决方案**：
1. 随机延迟：商品检查间隔 3-5 秒随机
2. 模拟真人行为：检查前随机浏览京东页面 10-15 秒
3. 随机浏览路径：首页、分类、秒杀、活动等页面随机访问

**新增方法**：
- `jd.php::makeRequest()` - 通用请求方法
- `jd.php::randomBrowseJd()` - 随机浏览模拟

---

### 七、轻量调度器 scheduler.php（新增）

**功能**：模拟真人行为的随机调度器，每分钟运行一次

**核心逻辑**：

| 任务 | 触发条件 | 随机间隔 | 模拟行为 |
|------|----------|----------|----------|
| 商品价格检查 | next_check_at <= 当前时间 | 30-60分钟 | 刷新前随机浏览京东页面10-15秒 |
| Cookie检查 | next_cookie_check_at <= 当前时间 | 6-12小时 | - |
| 价格保护 | next_protection_at <= 当前时间 | 2-5小时 | - |

**命令行参数**：
```bash
php scheduler.php              # 自动执行所有到期任务
php scheduler.php product      # 只检查商品价格
php scheduler.php cookie       # 只检查Cookie
php scheduler.php protection   # 只执行价保
php scheduler.php force        # 强制执行所有任务（忽略时间）
```

---

### 八、数据库调度字段（新增）

| 表 | 字段 | 类型 | 说明 |
|-----|------|------|------|
| products | next_check_at | DATETIME | 商品下次价格检查时间 |
| settings | next_cookie_check_at | DATETIME | Cookie下次检查时间 |
| settings | next_protection_at | DATETIME | 价保下次执行时间 |

---

### 九、移除通知间隔功能

**移除原因**：与新的随机调度逻辑冲突，不再需要固定间隔通知

**移除内容**：
- `notify_interval` 字段 → 重命名为 `_reserved_1`
- `default_notify_interval` 字段 → 重命名为 `_reserved_2`
- `notify_price_update` 字段 → 重命名为 `_reserved_3`
- 前端"通知间隔"输入框（添加商品、编辑商品、设置页面）
- 前端"定时价格更新"复选框

**涉及文件**：index.php、assets/js/modules/*.js、api/products.php、api/settings.php、api/export.php、includes/db.php

---

### 十、Webhook通知添加下次执行时间

**新增字段**：所有价格相关通知现在包含 `next_check_time`

**涉及通知类型**：price_drop、lowest_price、price_surge、out_of_stock、back_in_stock

**涉及文件**：scheduler.php、cron.php、api/check-price.php

---

### 十一、Bug修复

#### 1. "已达目标价"弹窗不显示商品

**问题**：点击"已达目标价"卡片，弹窗为空

**原因**：点击时未调用 `loadPriceMetProducts()` 方法

**修复**：在点击事件中添加方法调用
```html
@click="showPriceMetModal = true; loadPriceMetProducts()"
```

---

### 十二、清理无用文件

**删除文件**：
- `data/database.db` - 空文件
- `data/jd_monitor.db` - 空文件

---

### 十三、文档更新

**README.md**：
- 更新文件说明，添加 assets/js 模块目录
- 更新数据库表结构，移除废弃字段
- 更新通知类型说明，添加 next_check_time
- 添加"如何更新系统"FAQ

**CHANGES.md**：
- 完整记录本次所有修改内容
- 添加版本号标记

---

## v1.0.0 - 2024-12-01 10:00

### 一、问题诊断与修复

#### 1. 价格获取失败问题
**问题**：点击添加商品，只填了链接，然后保存，价格获取不到

**原因**：
- 移动端User-Agent访问会被重定向到风险处理页面
- 短链接解析时正则匹配到错误的SKU

**修复**：
- 将 `getProductInfoFromMobilePage()` 的User-Agent改为PC端
- 修改 `resolveShortUrl()` 从returnurl参数提取SKU

---

### 二、功能优化

#### 1. 价格获取方法优化

| 状态 | 方式 | 耗时 |
|------|------|------|
| 优化前 | 并行调用4个来源 | 3800ms+ |
| 优化后 | 顺序调用，成功即返回 | ~1000ms |

#### 2. 超时时间优化

所有方法超时时间缩短至5秒，新增连接超时3秒

#### 3. 成功率与耗时统计

**新增数据库表**：`price_method_stats`

**前端显示**：设置页面添加"价格获取方法统计"折叠面板

---

### 三、优化效果

| 指标 | 优化前 | 优化后 |
|------|--------|--------|
| 价格获取耗时 | 3800ms+ | ~1000ms |
| 风控风险 | 高 | 低 |
| 价格获取稳定性 | 一般 | 高 |

---

### 四、保留的原有功能

| 功能 | 状态 |
|------|------|
| CSRF防护 | ✅ 保留 |
| 登录限流 | ✅ 保留 |
| 安全响应头 | ✅ 保留 |
| HttpOnly Cookie | ✅ 保留 |
| Cookie验证 | ✅ 保留 |
| 短链接解析 | ✅ 保留 |
