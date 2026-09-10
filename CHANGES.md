# 京东商品价格监控系统 - 修改记录

> **文档更新规范**：每次功能变更必须包含以下内容：
> 1. **功能变更记录**：精确记录变更内容、新增功能说明、修改调整点及原因
> 2. **技术难点与解决方案**：问题现象、分析过程、最终方案
> 3. **错误陷阱及规避方法**：潜在问题、识别方法、规避措施
> 4. **使用说明与注意事项**：前置条件、操作流程、参数说明、限制条件

---

## v2.5.2 - 2026-09-10

### 移除扫码登录，改为手动输入 Cookie（含一键复制书签）

**【功能变更记录】**

京东扫码登录（`api/jd-qrcode.php`）在纯 PHP 后端下无法稳定获取有效 Cookie：京东在「确认登录」一步持续返回 `errcode=264`（风控拦截），多次修复（移动端流程、Cookie 隔离、state/returnurl 一致性）均无法根治。同时调研确认短信验证码登录同样依赖浏览器 JS 生成的设备指纹（FingerprintJS2）与私有 AES 参数（`risk_jd[fp]`/`jstub`/`ct`/`tk`），纯 PHP 亦无法实现。故彻底移除扫码登录，保留并增强手动输入。

**变更内容：**

| 文件 | 变更 |
|------|------|
| `api/jd-qrcode.php` | 删除整个文件（扫码登录后端） |
| `assets/js/modules/settings.js` | 删除扫码状态与全部扫码方法（openQrLogin/closeQrLogin/getQrCode/startQrPolling/verifyQrTicket/refreshQrCode）；新增 `parseCookie()` 与 `cookieRaw` 状态 |
| `index.php` | 删除「扫码登录」按钮与扫码弹窗；新增「快速回填」输入框（粘贴完整 Cookie 一键解析）；Cookie 助手中新增「一键复制 Cookie」书签脚本 |

**【技术难点与解决方案】**

**难点：扫码/短信登录均被京东风控拦截，纯 PHP 无法绕过**

- 扫码：移动端流程 `tmauthchecktoken` 在确认登录时返回 `errcode=264`，即使修正 state/returnurl 一致性（二维码存活从 4-5 次轮询延长至 10-14 次）后仍在确认步被拒。
- 短信：`jcapsid` 依赖 FingerprintJS2 `x64hash128` 设备指纹，`risk_jd[jstub]`/`ct`/`tk` 依赖京东私有 AES，纯 PHP 后端无法生成（与扫码 264 为同一类风控根因）。

**方案：** 彻底移除后端对接登录，改为「用户在真实浏览器登录 → 一键复制完整 Cookie → 粘贴解析回填 pt_key/pt_pin」的离线回填方案。

**【错误陷阱及规避方法】**

- **陷阱：** 书签脚本在非登录状态下点击会复制到空字符串。
- **规避：** 脚本内先判空 `document.cookie`，为空时弹窗提示「请先登录京东」。
- **陷阱：** 部分浏览器禁止拖拽 `javascript:` 书签或拦截剪贴板写入。
- **规避：** 使用 `document.execCommand('copy')` 兜底（点击书签属于用户手势，可正常复制）；无法拖拽时可手动新建书签。

**【使用说明与注意事项】**

1. 打开浏览器访问 m.jd.com 并登录账号。
2. 点击「一键复制 Cookie」书签（或 F12 → Application → Cookies 手动复制 pt_key/pt_pin）。
3. 回到系统设置页，将完整 Cookie 粘贴到「快速回填」框，点击「解析回填」。
4. 确认 pt_key/pt_pin 已回填后点击「保存设置」。

- 也可直接手动填写 pt_key、pt_pin 两个字段（系统会自动组合）。
- Cookie 有效期通常 1-3 个月，失效后需重新获取。

---

## v2.5.1 - 2026-09-09

### 死代码清理：删除无用方法与资源

**【功能变更记录】**

对全项目进行死代码/无用代码清理，删除未被任何调用方引用的方法、常量、函数与文件，功能行为保持不变。

**删除内容：**

| 文件 | 删除内容 |
|------|----------|
| `includes/jd.php` | 常量 `M_JD_REFERER`；方法 `getDegradationStatus()`、`checkCookieValid()`、`getFinalPrice()`、`getPromoPrice()`、文件末尾旧版 `checkCookie()` |
| `includes/jd_antiban.php` | `JdDeviceFingerprint::getSecChUaHeaders()`；`JdCookieJar::getAllCookiesFlat()`；`JdBehaviorSimulator::simulateBrowse()`；`JdApiDegradation::getAllStatus()`、`getRemainingTime()` |
| `includes/webhook.php` | `Webhook::test($url)` |
| `includes/db.php` | `getPdo()`、`beginTransaction()`、`commit()`、`rollBack()` |
| `includes/config.php` | 函数 `get_login_rate_limit_key()`；`is_login_locked()` 中的无用变量 `$key` |
| `cron.zip` | 删除整个文件（git 备份归档，非运行资源） |

**保留说明：**
- `MOBILE_USER_AGENT` / `PC_USER_AGENT` 常量仍被子方法与 `makeRequest()` 引用，保留
- `lastInsertId()` 被 `api/products.php`、`api/tags.php` 使用，保留
- `makeRequest()` 被 `randomBrowseCart()` 内部引用，保留
- `execWithCookieCapture()` 为 Cookie 自动捕获核心方法，保留
- 登录限流相关函数（`is_login_locked`、`record_login_failure`、`get_client_ip`、`clear_login_failures`）保留
- `JdApiDegradation` 保留 `isAvailable()` / `recordSuccess()` / `recordFailure()` / `resetDegradation()`，接口降级功能不受影响

**【技术难点与解决方案】**

**难点：删除前如何确认方法确为死代码**
- 问题：直接删除可能误删被动态调用或间接引用的方法，导致运行时 Fatal Error
- 方案：先通过全局 grep 建立符号依赖图，逐一确认每个候选方法无任何调用方后再删除；删除后对全部 PHP 文件执行 `php -l` 语法检查，并用 `php -S` 内置服务器做 HTTP 冒烟测试验证核心接口（首页、认证检查、价格历史、网络检查）均返回 200

**【错误陷阱及规避方法】**

1. **删除方法时勿误删类结束大括号**：删除 `getSecChUaHeaders()` 时曾连带删掉 `JdDeviceFingerprint` 类的结束大括号，导致 `php -l` 报 `unexpected 'class' (T_CLASS)`。删除方法后必须立即执行 `php -l` 验证语法
2. **历史变更记录不得改动**：CHANGES.md 中 v2.3.0 等历史章节记录的方法列表（含已删除方法）属历史事实，保持原样

**【使用说明与注意事项】**

- 本次为纯删除变更，无新增配置项，数据库结构不变，覆盖相关文件即可
- 升级后建议执行一次完整刷新验证（首页、查价、历史记录、网络检查）

---

## v2.5.0 - 2026-09-09

### 反爬增强：设备指纹 + Cookie分域 + TLS模拟 + 行为多样性 + 接口降级

**【功能变更记录】**

**1. 新增反爬增强模块 `includes/jd_antiban.php`**

新增 5 个核心类，完整覆盖京东反爬风控的各个层面：

| 类名 | 功能 | 关键参数 |
|------|------|----------|
| `JdDeviceFingerprint` | 稳定设备指纹生成器（MacBook Pro 14" M1 Pro） | UA、屏幕、时区、硬件、WebGL、字体、Canvas指纹等 |
| `JdCookieJar` | Cookie 分域存储管理器 | `.jd.com` / `.3.cn` 域严格隔离，自动合并 Set-Cookie |
| `JdRequestForgery` | 请求伪造器（TLS指纹 + Header顺序） | Chrome风格加密套件顺序、sec-ch-ua 在前的Header排序 |
| `JdBehaviorSimulator` | 行为多样性模拟器 | 前置商品页访问、30%概率查库存、20%概率查评价 |
| `JdApiDegradation` | 接口降级管理器 | 连续失败3次自动降级，1小时后自动恢复 |

**设备指纹特点：**
- 固定 MacBook Pro 14寸（M1 Pro / 16GB / 10核）配置
- 生成后持久化到 `system_settings` 表，永久稳定，不会每次变化
- 自动生成京东专属设备Cookie：`__jda`、`__jdb`、`__jdc`、`__jdu`、`guid`、`_t`

**Cookie分域特点：**
- `.jd.com` 与 `.3.cn` 域的Cookie严格隔离，模拟真实浏览器行为
- 自动从响应的 `Set-Cookie` 头中提取并合并Cookie
- 登录态Cookie（pt_key/pt_pin等）变化时自动同步到 settings 表

**TLS指纹模拟：**
- 通过 `CURLOPT_SSL_CIPHER_LIST` 设置 Chrome 125 风格的加密套件顺序
- 启用 ALPN/NPN 扩展（HTTP/2 + HTTP/1.1）
- 模拟 Chrome 的 sec-ch-ua → UA → Accept → sec-fetch → Accept-Language → Cookie Header 顺序

**行为多样性：**
- 价格接口调用前 **必须** 先访问商品详情页（item.jd.com 或 item.m.jd.com）
- 30% 概率附加库存查询（`c0.3.cn/stock`）
- 20% 概率附加评价查询（`club.jd.com/comment`）
- 模拟页面停留时间（100-300ms 加速模式）

**接口降级：**
- 每个价格接口独立统计失败次数
- 连续失败 3 次自动降级，1 小时后自动恢复
- 降级期间跳过该接口，避免死磕触发风控升级
- 降级状态持久化于 `api_degradation` 表，各接口查询前由 `JdApiDegradation::isAvailable()` 自动判断

**修改文件：**
- 新增 `includes/jd_antiban.php` — 反爬增强模块（5个类）
- 修改 `includes/jd.php` — `JdPrice` 类整合反爬模块，`getProductInfo()` 加入前置页面访问、行为多样性、降级检查
- 修改 `includes/db.php` — 新增 3 张表：`system_settings`、`domain_cookies`、`api_degradation`

**2. 修复 jd-qrcode.php 函数重复声明导致的 Fatal Error**

`jd-qrcode.php` 自行定义了 `json_success()` 和 `json_error()` 函数，但它引入的 `auth.php` → `config.php` 也定义了同名函数，导致 PHP Fatal Error: `Cannot redeclare json_success()`，前端表现为"网络错误，请重试"。

删除了 `jd-qrcode.php` 中重复的函数定义，改为通过 `config.php` 统一引用，与其他 API 文件保持一致。

**修改文件：**
- 修改 `api/jd-qrcode.php` — 删除重复的 `json_success()` / `json_error()`，顶部增加 `require_once config.php`

**【技术难点与解决方案】**

**难点1：TLS指纹在 PHP curl 中的模拟程度有限**
- 问题：PHP curl 的 `CURLOPT_SSL_CIPHER_LIST` 只能控制 TLS 1.2 的加密套件顺序，TLS 1.3 的 ciphersuites 顺序和扩展顺序无法精确控制（底层 OpenSSL 决定），JA3 指纹无法做到和真实浏览器 100% 一致
- 方案：加密套件顺序是 JA3 指纹中权重最高的部分，配合 Header 顺序模拟和行为模拟，足以规避基础风控。如需要更强的指纹模拟，建议后续改用 Playwright/Puppeteer 方案

**难点2：Cookie 分域与现有单域架构的兼容**
- 问题：原有系统只有 `settings.jd_cookies` 一个字段存整串 Cookie，无法区分域名
- 方案：新增 `domain_cookies` 表做分域存储，同时保持向后兼容——`JdPrice` 的 `$cookies` 属性仍同步为 jd.com 域的Cookie字符串，旧代码无需修改

**难点3：前置页面访问可能大幅拖慢价格查询速度**
- 问题：每个商品查价前都多一次商品页请求，时间翻倍
- 方案：用 `$preVisitedSkus` 缓存已访问过的SKU，同一个SKU只访问一次；页面停留时间改为 100-300ms 加速模式（生产环境可调整为 1-3 秒更真实）

**【错误陷阱及规避方法】**

1. **函数重复声明**：在新增公共文件（如 jd_antiban.php）时，所有全局函数和类名都必须唯一，禁止与 config.php / auth.php 中已有的重名。命名约定：反爬模块的类都加 `Jd` 前缀（如 `JdDeviceFingerprint`）

2. **TLS 证书验证**：Windows PHP 环境通常缺少 CA 根证书，`CURLOPT_SSL_VERIFYPEER = true` 会导致所有 HTTPS 请求失败。默认设为 `false`，生产环境如有 CA 证书可改为 `true`

3. **降级表不存在**：升级时如数据库已存在，`CREATE TABLE IF NOT EXISTS` 会自动创建新表；但如果 `Database::initTables()` 没有在升级后被调用，需要手动触发一次数据库初始化（访问页面即可）

4. **设备指纹首次生成后不要手动修改**：如果删除 `system_settings` 表中 `device_fingerprint` 记录，下次启动会重新生成一个全新的指纹，相当于换了一台设备，可能触发京东的"新设备登录"风控

**【使用说明与注意事项】**

- **数据库迁移**：升级后首次访问会自动创建 3 张新表，无需手动操作
- **升级方式**：覆盖 `includes/jd_antiban.php`、`includes/jd.php`、`includes/db.php`、`api/jd-qrcode.php` 即可
- **回滚方式**：删除 `includes/jd_antiban.php`，还原另外3个文件即可，数据库表不影响旧版本运行
- **性能影响**：单次价格查询增加约 300-800ms（商品页前置访问 + 可能的库存/评价查询），批量刷新时总耗时约增加 50%
- **配置调整**：可在 `jd_antiban.php` 中调整以下参数：
  - `JdBehaviorSimulator::STOCK_CHECK_PROBABILITY` — 库存查询概率（默认0.3）
  - `JdBehaviorSimulator::COMMENT_CHECK_PROBABILITY` — 评价查询概率（默认0.2）
  - `JdApiDegradation::FAIL_THRESHOLD` — 降级失败阈值（默认3次）
  - `JdApiDegradation::DEGRADE_DURATION` — 降级持续时间（默认3600秒）

---

## v2.4.1 - 2026-09-09

### 文档同步修正

**【功能变更记录】**

**1. README.md 补全 plus_price 字段**

`products` 表实际存在 `plus_price REAL DEFAULT 0`（PLUS会员价）字段，`db.php` 建表、`scheduler.php` 读写均有使用，但 README 表结构说明遗漏。本次补全该字段说明。

**修改文件**：
- `README.md` - products 表结构补充 `plus_price | REAL | PLUS会员价`

**2. scheduler.php 文件头注释修正**

文件头注释第 2 条「检查Cookie状态」原写为 `120-300分钟间隔，精确到秒`，与实际代码 `rand(6 * 60, 12 * 60)` 不符（6-12小时间隔）。修正为与实际逻辑一致的描述。

**修改文件**：
- `scheduler.php` - 注释从「120-300分钟间隔，精确到秒」改为「6-12小时间隔，精确到分钟」

**【使用说明】**

- 本次仅为文档与注释修正，无功能变更，无需数据库迁移
- 升级方式：覆盖对应文件即可

---

## v2.4.0 - 2026-09-08

### 行为模拟改造：购物车浏览替代随机逛页面

**【功能变更记录】**

**1. 浏览模拟从「首页随机逛」改为「购物车 + 监控商品」**

原行为模拟从 m.jd.com 首页实时提取商品链接后随机访问 3-5 个页面，并包含分类/秒杀/PLUS 等兜底页面和 30% 概率的深度跳转。这些访问的都是与监控任务无关的页面，且连续逛 category→seckill→plus 的跳跃路径容易被风控识别。

现改为更贴近真实用户的浏览路径：进入移动端购物车 → 随机抽取 1-3 个监控商品，浏览其详情页。去掉了首页抓链接、兜底页面、深度跳转等发散行为，请求更聚焦、次数更少。

**修改文件**：
- `includes/jd.php` - 删除 `randomBrowseFromMobile()`，新增 `randomBrowseCart($monitorSkus)`
- `scheduler.php` - `randomBrowseJd()` 改为查询监控商品 SKU 列表后调用 `randomBrowseCart()`

**新方法逻辑**：
1. 访问移动端购物车 `https://m.jd.com/cart/`
2. 对监控商品 SKU 列表去重后，随机抽取 1-3 个
3. 逐个浏览移动端详情页 `https://item.m.jd.com/product/{sku}.html`，每个停留 2-4 秒

**【技术难点与解决方案】**

| 难点 | 说明 | 解决方案 |
|------|------|----------|
| 购物车页面可用性 | 移动端购物车 URL 需确认可用 | 实测 `https://m.jd.com/cart/` 返回 200，配合移动端 UA 访问 |
| 浏览对象需为监控商品 | 原逻辑访问无关商品 | scheduler 查询 `products.status='active'` 的 SKU 列表传入，浏览的都是监控中的商品 |
| 空监控列表 | 无监控商品时不能凭空浏览 | 仅访问购物车页面，不浏览商品 |

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| SKU 重复导致重复浏览 | 同一商品多次加监控会出现重复 SKU | `array_unique` 去重后再抽取 |
| 非法/空 SKU | 空值会被当作 SKU 生成无效链接 | `array_filter` 过滤空值后 `array_values` 重建索引 |
| 浏览数量超上限 | 监控商品多时不应对所有商品都访问 | `min(rand(1,3), count($skus))` 限制最多 3 个 |

**【使用说明】**

- 无需任何手动操作，功能自动生效
- 每次价格检查前自动进入购物车并随机浏览 1-3 个监控商品
- 未加购的商品不会触发加购（仅浏览详情页），不引入加购接口风控风险

---

## v2.3.0 - 2026-09-08

### Cookie 自动维护功能

**【功能变更记录】**

**1. 自动捕获并持久化响应中的 Set-Cookie**

此前系统所有请求（价格查询、Cookie 检查、用户信息、随机浏览等）均不捕获响应头中的 Set-Cookie，导致京东下发的跟踪 Cookie（如 `__jda`、`thor` 等）和轮换的登录 Cookie 无法回写，Cookie 链长期停滞、逐渐"变薄"，更容易触发风控。

现在所有携带 Cookie 的请求统一通过 `execWithCookieCapture()` 执行，自动解析响应中的 `Set-Cookie` 头并合并回写数据库（`settings.jd_cookies`），实现 Cookie 链的自维护：

**修改文件**：
- `includes/jd.php` - 新增 3 个辅助方法，16 个请求方法改用 Cookie 捕获执行

**新增辅助方法**：
- `parseCookieStringToArray()` - 将 Cookie 字符串解析为数组，容错处理（空段、空格、无等号段）
- `mergeAndPersistCookies()` - 合并捕获的新 Cookie 并持久化；登录关键 Cookie 变化时重置 `cookie_status`，普通跟踪 Cookie 仅更新值
- `execWithCookieCapture()` - 通过 `CURLOPT_HEADERFUNCTION` 回调捕获 Set-Cookie，不污染响应体

**接入 Cookie 捕获的方法**（16 个）：
- `makeRequest()`、`checkCookieValid()`、`getPromoPrice()`、`getProductInfoFromMobile()`、`getProductInfoFromMobilePage()`、`getImageFromPcPage()`、`getPriceFromPcPage()`、`getPriceFromMobilePage()`、`getPriceFromMobileApi()`、`checkCookieByUserInfo()`、`checkCookieByMobileLogin()`、`checkCookieByMobilePage()`、`checkCookieByPriceApi()`、`checkCookieByMobileProductPage()`、`getUserInfo()`、`checkCookie()`

**有意不捕获的请求**：
- `resolveShortUrl()` - 短链接解析，无 Cookie
- `getProductInfoFromPublicApi()` / `getPriceFromPublicApi()` - p.3.cn 公开 API，无需 Cookie

**【技术难点与解决方案】**

| 难点 | 说明 | 解决方案 |
|------|------|----------|
| 捕获头部不污染响应体 | `CURLOPT_HEADER=true` 后需手动分离 header/body，极易破坏现有解析逻辑 | 使用 `CURLOPT_HEADERFUNCTION` 回调逐行接收响应头，响应体保持不变 |
| cookie_status 重置语义 | 每次变化都重置为 `unknown` 会破坏调度器的"有效→失效"通知逻辑（依赖检查前状态为 valid） | 仅当登录关键 Cookie（`pt_key`/`pt_pin`/`pt_token`/`thor`/`sso_uc`）变化时重置为 `unknown`；普通跟踪 Cookie 变化只更新值 |
| Cookie 链被误删 | 京东会下发 `deleted`/空值标记的过期间Cookie | 合并时忽略空值与 `deleted` 标记，保护 pt_key/pt_pin 等关键登录状态 |
| 内存与数据库一致 | 合并后 `$this->cookies` 与数据库可能不同步 | 持久化成功后同步更新内存中的 cookies 字符串 |

**【错误陷阱及规避方法】**

| 陷阱 | 说明 | 规避方法 |
|------|------|----------|
| 相同 Cookie 反复写库 | 每次请求都触发 UPDATE，造成无意义写入 | 合并后与当前值比对，无变化直接返回，不写库 |
| 无 Cookie 时凭空创建 | `$this->cookies` 为空时合并会创造不存在的 Cookie 链 | `mergeAndPersistCookies` 入口增加空 cookies 保护 |
| 调试模拟服务踩坑 | PHP 内置服务器中 `header()` 默认替换同名头，多个 Set-Cookie 只剩最后一个 | 模拟服务需 `header(..., false)` 允许重复头；真实京东响应不受影响 |

**【使用说明】**

- 无需任何手动操作，功能自动生效
- 每次京东请求响应中的 Set-Cookie 会自动合并进现有 Cookie 并持久化
- 登录态关键 Cookie 变化（如轮换）会自动将状态重置为 `unknown`，等待下一次 Cookie 检查重新验证
- 普通跟踪 Cookie（`__jda`、`nbpt` 等）变化不会影响已确认的"有效"状态，避免频繁通知

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
