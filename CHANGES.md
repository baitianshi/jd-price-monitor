# 京东商品价格监控系统 - 修改记录

## 2024年19点后修改内容汇总

### 一、问题诊断与修复

#### 1. 价格获取失败问题
**问题**：点击添加商品，只填了链接，然后保存，价格获取不到

**原因分析**：
- 移动端User-Agent访问会被重定向到风险处理页面
- 短链接解析时正则匹配到错误的SKU

**修复**：
- 将 `getProductInfoFromMobilePage()` 的User-Agent改为PC端：`Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36`
- 修改 `resolveShortUrl()` 从returnurl参数提取SKU

---

### 二、功能优化

#### 1. 价格获取方法优化

**原状态**：
- 并行调用4个来源获取价格
- 风控风险高，耗时3800ms+
- 一个方法失败后直接换下一个

**优化后**：
- 顺序调用4个来源，成功即返回
- 耗时降低至~1000ms
- 新增PC端页面价格获取方法

**新增方法**：
- `getPriceFromPcPage()` - 从PC端页面获取价格

**保留方法**：
- `getProductInfoFromMobilePage()` - 移动端页面解析
- `getProductInfoFromMobile()` - 移动端API
- `getProductInfoFromPublicApi()` - 公开价格API

---

#### 2. 超时时间优化

| 方法 | 原超时 | 新超时 |
|------|--------|--------|
| getImageFromPcPage | 15秒 | 5秒 |
| getProductInfoFromMobile | 15秒 | 5秒 |
| getProductInfoFromMobilePage | 15秒 | 5秒 |
| getProductInfoFromPublicApi | 10秒 | 5秒 |
| getPriceFromMobilePage | 15秒 | 5秒 |
| getPriceFromMobile | 15秒 | 5秒 |
| getPriceFromPublicApi | 10秒 | 5秒 |

新增连接超时：`CURLOPT_CONNECTTIMEOUT => 3秒`

---

#### 3. 成功率与耗时统计

**新增数据库表**：
```sql
CREATE TABLE price_method_stats (
    method TEXT UNIQUE NOT NULL,
    success_count INTEGER DEFAULT 0,
    total_count INTEGER DEFAULT 0,
    total_time REAL DEFAULT 0,
    last_success_at DATETIME,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

**新增功能**：
- `loadMethodStats()` - 启动时从数据库加载历史统计
- `getMethodStats()` - 获取排序后的统计数据
- `recordMethodStats()` - 每次调用后记录统计
- `saveMethodStats()` - 持久化到数据库

**前端显示**：
- 设置页面添加"价格获取方法统计"折叠面板
- 按成功率动态排名
- 显示：方法名称、成功次数、平均耗时、调用次数

---

### 三、文件修改清单

#### 1. `includes/jd.php`
| 修改类型 | 内容 |
|----------|------|
| 修改 | User-Agent常量，添加PC_USER_AGENT |
| 修改 | getProductInfo() 改为顺序获取 |
| 修改 | 所有方法超时时间缩短至5秒 |
| 修改 | 添加getPriceFromPcPage()方法 |
| 修改 | 添加成功率统计逻辑 |
| 新增 | price_method_stats表相关方法 |
| 修复 | 移动端页面使用PC User-Agent避免风控 |

#### 2. `includes/db.php`
| 修改类型 | 内容 |
|----------|------|
| 新增 | price_method_stats表结构 |

#### 3. `api/settings.php`
| 修改类型 | 内容 |
|----------|------|
| 新增 | method_stats返回数据 |

#### 4. `index.php`
| 修改类型 | 内容 |
|----------|------|
| 新增 | methodStats数据变量 |
| 新增 | showMethodStats状态变量 |
| 新增 | 价格获取方法统计UI（折叠面板） |
| 新增 | x-transition动画效果 |

---

### 四、价格获取逻辑

```
getProductInfo() 执行流程：

1. 尝试移动端页面 (mobile_page)
   ↓ 成功？
   ├─ 是：获取名称、到手价、库存 → 继续尝试获取原价
   └─ 否：继续下一个方法

2. 尝试移动端API (mobile_api)
   ↓ 成功？
   ├─ 是：获取名称、到手价、库存 → 继续尝试获取原价
   └─ 否：继续下一个方法

3. 尝试公开API (public_api)
   ↓ 成功？
   ├─ 是：获取到手价
   └─ 否：返回空

4. 补充获取（如果图片或原价还沒有）
   └─ 尝试PC端获取图片和原价

5. 合并结果
   - 名称：取第一个有效的
   - 到手价：取第一个>1的
   - 原价：取第一个>1的（如果<到手价则使用到手价）
   - 图片：优先PC端
   - 库存：取第一个有效的

6. 如果原价<=0，使用到手价作为原价
```

---

### 五、保留的原有功能

| 功能 | 状态 | 说明 |
|------|------|------|
| CSRF防护 | ✅ 保留 | token生成验证 |
| 登录限流 | ✅ 保留 | 5次失败锁定15分钟 |
| 安全响应头 | ✅ 保留 | CSP、X-Frame-Options等 |
| HttpOnly Cookie | ✅ 保留 | session安全配置 |
| 图标显示 | ✅ 保留 | CSP已添加unpkg.com |
| Cookie验证 | ✅ 保留 | 优先移动端商品页验证 |
| 短链接解析 | ✅ 保留 | 从returnurl提取SKU |

---

### 六、优化效果

| 指标 | 优化前 | 优化后 |
|------|--------|--------|
| 价格获取耗时 | 3800ms+ | ~1000ms |
| 风控风险 | 高（并行请求） | 低（顺序请求） |
| 价格获取稳定性 | 一般 | 高（多方法兜底） |
| 统计持久化 | 无 | 有（数据库存储） |
| 方法排名 | 无 | 有（动态调整） |

---

### 七、后续可优化方向

1. **异步获取图片**：图片加载不影响价格显示
2. **缓存机制**：热门商品缓存价格，减少请求
3. **智能重试**：失败时自动切换代理/IP
4. **批量处理**：多个商品同时检查价格
