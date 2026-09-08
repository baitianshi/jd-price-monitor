<?php
/**
 * 京东商品价格监控系统 - 主页面
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';

// 设置安全响应头
set_security_headers();

// 获取当前脚本的基础路径（支持子目录部署）
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/' || $basePath === '\\') {
    $basePath = '';
}

$auth = new Auth();
$isLoggedIn = $auth->check();
$needInit = $auth->needInit();

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($auth->login($_POST['password'])) {
        header('Location: ' . $basePath . '/');
        exit;
    }
    $loginError = '密码错误，请重试';
}

// 处理退出
if (isset($_GET['logout'])) {
    $auth->logout();
    header('Location: ' . $basePath . '/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?= htmlspecialchars($basePath . '/') ?>">
    <title>京东商品价格监控</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/hammerjs@2.0.8"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1"></script>
    
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <script>
        // 基础路径配置（支持子目录部署）
        const BASE_PATH = '<?= $basePath ?>';
        
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#e53935',
                        secondary: '#ff6f00'
                    }
                }
            }
        }
    </script>
    
    <style>
        [x-cloak] { display: none !important; }
        
        .gradient-bg {
            background: linear-gradient(135deg, #e53935 0%, #ff6f00 100%);
        }
        
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .price-down { color: #22c55e; }
        .price-up { color: #ef4444; }
        
        .collapse-enter {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .collapse-enter-active {
            max-height: 500px;
        }
        
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #e53935;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .modal-overlay {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }
        
        .slide-in {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- 登录页面 -->
    <?php if (!$isLoggedIn): ?>
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl p-8 w-full max-w-md slide-in">
            <div class="text-center mb-8">
                <div class="w-16 h-16 gradient-bg rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="shield-check" class="w-8 h-8 text-white"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">京东价格监控</h1>
                <p class="text-gray-500 mt-2">请输入访问密码</p>
            </div>
            
            <?php if ($needInit): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                <div class="flex items-start gap-3">
                    <i data-lucide="info" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
                    <div class="text-sm text-amber-700">
                        <p class="font-medium">首次使用提示</p>
                        <p class="mt-1">默认密码：<code class="bg-amber-100 px-1 rounded">admin123</code></p>
                        <p>登录后请及时修改密码</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($loginError)): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                <div class="flex items-center gap-2 text-red-600">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <span><?= $loginError ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">访问密码</label>
                    <input type="password" name="password" required autofocus
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none transition"
                        placeholder="请输入密码">
                </div>
                <button type="submit"
                    class="w-full gradient-bg text-white py-3 rounded-lg font-medium hover:opacity-90 transition">
                    进入系统
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>
    
    <!-- 主应用 -->
    <div id="app" x-data="app()" x-init="init()">
        <!-- 顶部导航 -->
        <header class="bg-white shadow-sm sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 gradient-bg rounded-lg flex items-center justify-center">
                            <i data-lucide="trending-down" class="w-6 h-6 text-white"></i>
                        </div>
                        <h1 class="text-xl font-bold text-gray-800 hidden sm:block">京东价格监控</h1>
                    </div>
                    
                    <div class="flex items-center gap-2 sm:gap-4">
                        <!-- 用户信息（Cookie有效时显示） -->
                        <div x-show="settings.cookie_status === 'valid' && settings.jd_user" class="flex items-center gap-2 text-sm">
                            <div class="flex items-center gap-2 px-2 py-1 bg-gradient-to-r from-blue-50 to-purple-50 rounded-full border border-blue-100">
                                <span x-show="settings.jd_user?.isPlusVip" class="px-1.5 py-0.5 bg-gradient-to-r from-yellow-400 to-yellow-500 text-white text-xs rounded font-medium">PLUS</span>
                                <span class="text-gray-700 font-medium" x-text="settings.jd_user?.nickname || ''"></span>
                                <span x-show="settings.jd_user?.levelName" class="text-xs text-gray-500" x-text="settings.jd_user?.levelName"></span>
                            </div>
                        </div>
                        
                        <!-- Cookie状态 -->
                        <div class="flex items-center gap-2 text-sm">
                            <span class="hidden sm:inline text-gray-500">Cookie:</span>
                            <span x-show="settings.cookie_status === 'valid'" class="flex items-center gap-1 text-green-600" :title="settings.cookie_status_hint">
                                <i data-lucide="check-circle" class="w-4 h-4"></i>
                                <span class="hidden sm:inline">正常</span>
                            </span>
                            <span x-show="settings.cookie_status === 'invalid'" class="flex items-center gap-1 text-red-600" :title="settings.cookie_status_hint">
                                <i data-lucide="x-circle" class="w-4 h-4"></i>
                                <span class="hidden sm:inline">已失效</span>
                            </span>
                            <span x-show="settings.cookie_status === 'not_set'" class="flex items-center gap-1 text-gray-400" :title="settings.cookie_status_hint">
                                <i data-lucide="minus-circle" class="w-4 h-4"></i>
                                <span class="hidden sm:inline">未设置</span>
                            </span>
                            <span x-show="settings.cookie_status === 'invalid_format'" class="flex items-center gap-1 text-orange-600" :title="settings.cookie_status_hint">
                                <i data-lucide="alert-circle" class="w-4 h-4"></i>
                                <span class="hidden sm:inline">格式错误</span>
                            </span>
                            <span x-show="settings.cookie_status === 'unknown'" class="flex items-center gap-1 text-gray-500" :title="settings.cookie_status_hint">
                                <i data-lucide="help-circle" class="w-4 h-4"></i>
                                <span class="hidden sm:inline">未知</span>
                            </span>
                        </div>
                        
                        <!-- 刷新按钮 -->
                        <button @click="checkAllPrices()" :disabled="checking"
                            class="p-2 hover:bg-gray-100 rounded-lg transition" title="刷新价格">
                            <i data-lucide="refresh-cw" class="w-5 h-5 text-gray-600" :class="{'animate-spin': checking}"></i>
                        </button>
                        
                        <!-- 设置按钮 -->
                        <button @click="showSettings = true"
                            class="p-2 hover:bg-gray-100 rounded-lg transition" title="设置">
                            <i data-lucide="settings" class="w-5 h-5 text-gray-600"></i>
                        </button>
                        
                        <!-- 退出按钮 -->
                        <a href="?logout" class="p-2 hover:bg-gray-100 rounded-lg transition" title="退出">
                            <i data-lucide="log-out" class="w-5 h-5 text-gray-600"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- 主内容区 -->
        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
            <!-- 统计卡片 -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i data-lucide="package" class="w-5 h-5 text-blue-600"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-gray-800" x-text="stats.total_products || 0"></p>
                            <p class="text-sm text-gray-500">监控商品</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                            <i data-lucide="check-square" class="w-5 h-5 text-green-600"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-gray-800" x-text="stats.active_products || 0"></p>
                            <p class="text-sm text-gray-500">监控中</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-4 shadow-sm cursor-pointer hover:shadow-md transition" @click="showPriceMetModal = true; loadPriceMetProducts()">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center">
                            <i data-lucide="bell" class="w-5 h-5 text-amber-600"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-gray-800" x-text="stats.price_met_products || 0"></p>
                            <p class="text-sm text-gray-500">已达目标价</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-xl p-4 shadow-sm cursor-pointer hover:shadow-md transition" @click="showPriceHistoryModal = true; loadPriceHistoryList()">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i data-lucide="bar-chart-2" class="w-5 h-5 text-purple-600"></i>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-gray-800" x-text="stats.total_history || 0"></p>
                            <p class="text-sm text-gray-500">价格记录</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 工具栏 -->
            <div class="bg-white rounded-xl p-4 shadow-sm mb-6">
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col sm:flex-row gap-4 justify-between">
                        <div class="flex flex-wrap gap-2">
                            <button @click="showAddModal = true"
                                class="gradient-bg text-white px-4 py-2 rounded-lg font-medium hover:opacity-90 transition flex items-center gap-2">
                                <i data-lucide="plus" class="w-4 h-4"></i>
                                <span>添加商品</span>
                            </button>
                            
                            <button @click="showBatchAddModal = true"
                                class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg font-medium hover:bg-gray-50 transition flex items-center gap-2">
                                <i data-lucide="list-plus" class="w-4 h-4"></i>
                                <span>批量添加</span>
                            </button>
                            
                            <button @click="checkAllPrices()" :disabled="checking"
                                class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg font-medium hover:bg-gray-50 transition flex items-center gap-2 disabled:opacity-50">
                                <i data-lucide="refresh-cw" class="w-4 h-4" :class="{'animate-spin': checking}"></i>
                                <span>刷新价格</span>
                            </button>
                            
                            <button @click="showTagManager = true"
                                class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg font-medium hover:bg-gray-50 transition flex items-center gap-2">
                                <i data-lucide="tag" class="w-4 h-4"></i>
                                <span>标签管理</span>
                            </button>
                        </div>
                        
                        <div class="flex flex-wrap gap-2">
                            <div class="relative flex-1 sm:w-64">
                                <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" x-model="searchQuery" placeholder="搜索商品..."
                                    class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                            </div>
                            
                            <select x-model="filterStatus" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                                <option value="all">全部状态</option>
                                <option value="active">监控中</option>
                                <option value="paused">已暂停</option>
                            </select>
                            
                            <select x-model="filterTag" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                                <option value="">全部标签</option>
                                <template x-for="tag in tags" :key="tag.id">
                                    <option :value="tag.name" x-text="tag.name"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 商品列表 -->
            <div class="space-y-4">
                <!-- 空状态 -->
                <div x-show="filteredProducts.length === 0 && !loading" class="bg-white rounded-xl p-12 text-center">
                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="inbox" class="w-8 h-8 text-gray-400"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800 mb-2">暂无监控商品</h3>
                    <p class="text-gray-500 mb-4">点击"添加商品"开始监控</p>
                    <button @click="showAddModal = true"
                        class="gradient-bg text-white px-6 py-2 rounded-lg font-medium hover:opacity-90 transition">
                        添加第一个商品
                    </button>
                </div>
                
                <!-- 商品卡片 -->
                <template x-for="product in filteredProducts" :key="product.id">
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden card-hover transition-all duration-200"
                        :class="{'opacity-60': product.status !== 'active'}">
                        <div class="p-4 sm:p-6">
                            <div class="flex gap-4">
                                <!-- 商品图片 -->
                                <div class="flex-shrink-0">
                                    <a :href="'https://item.jd.com/' + product.sku_id + '.html'" target="_blank">
                                        <img :src="product.image_url && product.image_url !== '' ? product.image_url : 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect fill=%22%23f3f4f6%22 width=%22100%22 height=%22100%22/%3E%3Ctext x=%2250%22 y=%2250%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%239ca3af%22 font-size=%2212%22%3E暂无图片%3C/text%3E%3C/svg%3E'"
                                                :alt="product.name"
                                                class="w-20 h-20 sm:w-24 sm:h-24 object-cover rounded-lg border border-gray-200 hover:border-primary transition">
                                    </a>
                                </div>
                                
                                <!-- 商品信息 -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <a :href="'https://item.jd.com/' + product.sku_id + '.html'" target="_blank"
                                                class="font-medium text-gray-800 hover:text-primary line-clamp-2" x-text="product.name"></a>
                                            <div class="flex items-center flex-wrap gap-1 mt-1 text-sm text-gray-500">
                                                <span x-text="'SKU: ' + product.sku_id"></span>
                                                <template x-for="(tag, index) in parseTags(product.tags)" :key="index">
                                                    <span class="px-2 py-0.5 rounded text-xs text-white"
                                                        :style="'background-color: ' + getTagColor(tag)"
                                                        x-text="tag"></span>
                                                </template>
                                            </div>
                                        </div>
                                        
                                        <!-- 状态标签 -->
                                        <div class="flex-shrink-0">
                                            <span x-show="product.status === 'active'" class="px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full">监控中</span>
                                            <span x-show="product.status === 'paused'" class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded-full">已暂停</span>
                                        </div>
                                    </div>
                                    
                                    <!-- 价格信息 -->
                                    <div class="mt-3 flex flex-wrap items-center gap-4">
                                        <div>
                                            <span class="text-sm text-gray-500">到手价</span>
                                            <p class="text-2xl font-bold text-primary">
                                                <template x-if="product.current_price && Number(product.current_price) > 0">
                                                    <span>
                                                        ¥<span x-text="Number(product.current_price || 0).toFixed(2)"></span>
                                                        <span x-show="product.original_price && Number(product.original_price) >= Number(product.current_price)" 
                                                            class="text-sm font-normal text-gray-400 line-through ml-1">
                                                            ¥<span x-text="Number(product.original_price || 0).toFixed(2)"></span>
                                                        </span>
                                                    </span>
                                                </template>
                                                <template x-if="!product.current_price || Number(product.current_price) <= 0">
                                                    <span class="text-base text-gray-400">暂无价格</span>
                                                </template>
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <span class="text-sm text-gray-500">目标价格</span>
                                            <p class="text-lg font-semibold" :class="Number(product.current_price || 0) <= Number(product.target_price || 0) ? 'text-green-600' : 'text-gray-700'">
                                                ¥<span x-text="Number(product.target_price || 0).toFixed(2)"></span>
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <span class="text-sm text-gray-500">历史最低</span>
                                            <p class="text-lg font-semibold text-blue-600">
                                                ¥<span x-text="Number(product.lowest_price || 0).toFixed(2)"></span>
                                                <span x-show="Number(product.current_price || 0) === Number(product.lowest_price || 0)" class="text-xs bg-blue-100 text-blue-700 px-1 rounded ml-1">最低</span>
                                            </p>
                                        </div>
                                        
                                        <div x-show="product.stock_status && product.stock_status !== 'unknown'">
                                            <span class="text-sm text-gray-500">库存状态</span>
                                            <p class="text-sm font-medium"
                                                :class="{
                                                    'text-green-600': product.stock_status === 'in_stock',
                                                    'text-amber-600': product.stock_status === 'low_stock',
                                                    'text-red-600': product.stock_status === 'out_of_stock',
                                                    'text-purple-600': product.stock_status === 'presale'
                                                }">
                                                <span x-show="product.stock_num && product.stock_num > 0" x-text="'库存 ' + product.stock_num + ' 件'"></span>
                                                <span x-show="!product.stock_num || product.stock_num <= 0" x-text="{
                                                    'in_stock': '现货',
                                                    'low_stock': '库存紧张',
                                                    'out_of_stock': '无货',
                                                    'presale': '预售'
                                                }[product.stock_status] || product.stock_status"></span>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <!-- 操作按钮 -->
                                    <div class="mt-4 flex flex-wrap gap-2">
                                        <button @click="checkPrice(product.id)" 
                                            :disabled="checkingProductId === product.id"
                                            class="text-sm px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-1 disabled:opacity-50">
                                            <i data-lucide="refresh-cw" class="w-4 h-4" :class="{'animate-spin': checkingProductId === product.id}"></i>
                                            <span>刷新</span>
                                        </button>
                                        
                                        <button @click="showPriceHistory(product)"
                                            class="text-sm px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-1">
                                            <i data-lucide="line-chart" class="w-4 h-4"></i>
                                            <span>价格走势</span>
                                        </button>
                                        
                                        <button @click="showPriceCalendar(product)"
                                            class="text-sm px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-1">
                                            <i data-lucide="calendar" class="w-4 h-4"></i>
                                            <span>价格日历</span>
                                        </button>
                                        
                                        <button @click="editProduct = {
                                            ...product,
                                            notify_price_drop: !!Number(product.notify_price_drop),
                                            notify_lowest: !!Number(product.notify_lowest),
                                            notify_price_surge: !!Number(product.notify_price_surge),
                                            notify_oos: !!Number(product.notify_oos)
                                        }; showEditModal = true"
                                            class="text-sm px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-1">
                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                            <span>编辑</span>
                                        </button>
                                        
                                        <button x-show="product.status === 'active'" @click="toggleStatus(product, 'paused')"
                                            class="text-sm px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-1">
                                            <i data-lucide="pause" class="w-4 h-4"></i>
                                            <span>暂停</span>
                                        </button>
                                        
                                        <button x-show="product.status === 'paused'" @click="toggleStatus(product, 'active')"
                                            class="text-sm px-3 py-1.5 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-1">
                                            <i data-lucide="play" class="w-4 h-4"></i>
                                            <span>启用</span>
                                        </button>
                                        
                                        <button @click="confirmDelete(product)"
                                            class="text-sm px-3 py-1.5 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 transition flex items-center gap-1">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            <span>删除</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </main>
        
        <!-- 添加商品弹窗 -->
        <div x-show="showAddModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showAddModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md slide-in" @click.stop>
                <div class="p-6 border-b">
                    <h3 class="text-lg font-semibold text-gray-800">添加监控商品</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">商品链接 <span class="text-red-500">*</span></label>
                        <input type="text" x-model="newProduct.url"
                            placeholder="粘贴京东商品链接或SKU ID"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                        <p class="text-xs text-gray-500 mt-1">支持PC链接、APP分享链接、短链接或直接输入SKU</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">目标价格</label>
                        <input type="number" step="0.01" x-model="newProduct.target_price"
                            placeholder="低于此价格时通知"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">标签</label>
                        <div class="flex flex-wrap gap-2 mb-2">
                            <template x-for="(tag, index) in parseTags(newProduct.tags)" :key="index">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs text-white" :style="'background-color: ' + getTagColor(tag)">
                                    <span x-text="tag"></span>
                                    <button @click="newProduct.tags = tagsToString(parseTags(newProduct.tags).filter((t, i) => i !== index))" 
                                        class="hover:bg-white/20 rounded-full p-0.5 text-white">
                                        <span class="w-3 h-3 flex items-center justify-center">×</span>
                                    </button>
                                </span>
                            </template>
                        </div>
                        <div class="flex gap-2">
                            <select @change="if($event.target.value) { const currentTags = parseTags(newProduct.tags); if(!currentTags.includes($event.target.value)) { newProduct.tags = tagsToString([...currentTags, $event.target.value]); } $event.target.value = ''; }"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                                <option value="">选择标签...</option>
                                <template x-for="tag in tags" :key="tag.id">
                                    <option :value="tag.name" x-text="tag.name"></option>
                                </template>
                            </select>
                            <input type="text" placeholder="或输入新标签" 
                                @keydown.enter="if($event.target.value.trim()) { const currentTags = parseTags(newProduct.tags); if(!currentTags.includes($event.target.value.trim())) { newProduct.tags = tagsToString([...currentTags, $event.target.value.trim()]); } $event.target.value = ''; }"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                        </div>
                    </div>
                </div>
                <div class="p-6 border-t flex gap-3 justify-end">
                    <button @click="showAddModal = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">取消</button>
                    <button @click="addProduct()" :disabled="adding" class="gradient-bg text-white px-6 py-2 rounded-lg font-medium hover:opacity-90 transition disabled:opacity-50">
                        <span x-show="!adding">添加</span>
                        <span x-show="adding" class="flex items-center gap-2">
                            <div class="loading-spinner"></div>
                            <span x-text="addStatus || '添加中...'"></span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- 批量添加弹窗 -->
        <div x-show="showBatchAddModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showBatchAddModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg slide-in" @click.stop>
                <div class="p-6 border-b">
                    <h3 class="text-lg font-semibold text-gray-800">批量添加商品</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">商品链接（每行一个）</label>
                        <textarea x-model="batchUrls" rows="6"
                            placeholder="粘贴多个商品链接，每行一个&#10;https://item.jd.com/100012345.html&#10;https://u.jd.com/xxxxx"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none resize-none"></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">统一标签</label>
                        <div class="flex flex-wrap gap-2 mb-2">
                            <template x-for="(tag, index) in parseTags(batchTags)" :key="index">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs text-white"
                                    :style="'background-color: ' + getTagColor(tag)">
                                    <span x-text="tag"></span>
                                    <button @click="batchTags = tagsToString(parseTags(batchTags).filter((t, i) => i !== index))" 
                                        class="hover:bg-white/20 rounded-full p-0.5">
                                        <i data-lucide="x" class="w-3 h-3"></i>
                                    </button>
                                </span>
                            </template>
                        </div>
                        <div class="flex gap-2">
                            <select @change="if($event.target.value) { const currentTags = parseTags(batchTags); if(!currentTags.includes($event.target.value)) { batchTags = tagsToString([...currentTags, $event.target.value]); } $event.target.value = ''; }"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                                <option value="">选择标签...</option>
                                <template x-for="tag in tags" :key="tag.id">
                                    <option :value="tag.name" x-text="tag.name"></option>
                                </template>
                            </select>
                            <input type="text" placeholder="或输入新标签" 
                                @keydown.enter="if($event.target.value.trim()) { const currentTags = parseTags(batchTags); if(!currentTags.includes($event.target.value.trim())) { batchTags = tagsToString([...currentTags, $event.target.value.trim()]); } $event.target.value = ''; }"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                        </div>
                    </div>
                </div>
                <div class="p-6 border-t flex gap-3 justify-end">
                    <button @click="showBatchAddModal = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">取消</button>
                    <button @click="batchAddProducts()" :disabled="adding" class="gradient-bg text-white px-6 py-2 rounded-lg font-medium hover:opacity-90 transition disabled:opacity-50">
                        <span x-show="!adding">批量添加</span>
                        <span x-show="adding" class="flex items-center gap-2">
                            <div class="loading-spinner"></div>
                            添加中...
                        </span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- 编辑商品弹窗 -->
        <div x-show="showEditModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showEditModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md slide-in" @click.stop>
                <div class="p-6 border-b">
                    <h3 class="text-lg font-semibold text-gray-800">编辑商品</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">目标价格</label>
                        <input type="number" step="0.01" x-model="editProduct.target_price"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">价格波动阈值（%）</label>
                        <input type="number" step="0.1" x-model="editProduct.price_change_threshold"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                        <p class="text-xs text-gray-500 mt-1">价格涨跌超过此比例时通知</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">标签</label>
                        <div class="flex flex-wrap gap-2 mb-2">
                            <template x-for="(tag, index) in parseTags(editProduct.tags)" :key="index">
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs text-white" :style="'background-color: ' + getTagColor(tag)">
                                    <span x-text="tag"></span>
                                    <button @click="editProduct.tags = tagsToString(parseTags(editProduct.tags).filter((t, i) => i !== index))" 
                                        class="hover:bg-white/20 rounded-full p-0.5 text-white">
                                        <span class="w-3 h-3 flex items-center justify-center">×</span>
                                    </button>
                                </span>
                            </template>
                        </div>
                        <div class="flex gap-2">
                            <select @change="if($event.target.value) { const currentTags = parseTags(editProduct.tags); if(!currentTags.includes($event.target.value)) { editProduct.tags = tagsToString([...currentTags, $event.target.value]); } $event.target.value = ''; }"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                                <option value="">选择标签...</option>
                                <template x-for="tag in tags" :key="tag.id">
                                    <option :value="tag.name" x-text="tag.name"></option>
                                </template>
                            </select>
                            <input type="text" placeholder="或输入新标签" 
                                @keydown.enter="if($event.target.value.trim()) { const currentTags = parseTags(editProduct.tags); if(!currentTags.includes($event.target.value.trim())) { editProduct.tags = tagsToString([...currentTags, $event.target.value.trim()]); } $event.target.value = ''; }"
                                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">通知类型</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="editProduct.notify_price_drop" class="rounded">
                                <span class="text-sm">降价提醒</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="editProduct.notify_lowest" class="rounded">
                                <span class="text-sm">历史最低价提醒</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="editProduct.notify_price_surge" class="rounded">
                                <span class="text-sm">涨价提醒</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="editProduct.notify_oos" class="rounded">
                                <span class="text-sm">库存变化通知</span>
                            </label>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">历史数据保留天数</label>
                        <input type="number" min="7" max="365" x-model="editProduct.history_retention_days"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                        <p class="text-xs text-gray-500 mt-1">超过天数的价格历史记录将被自动清理（默认365天）</p>
                    </div>
                </div>
                <div class="p-6 border-t flex gap-3 justify-end">
                    <button @click="showEditModal = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">取消</button>
                    <button @click="updateProduct()" class="gradient-bg text-white px-6 py-2 rounded-lg font-medium hover:opacity-90 transition">保存</button>
                </div>
            </div>
        </div>
        
        <!-- 删除确认弹窗 -->
        <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showDeleteModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm slide-in" @click.stop>
                <div class="p-6 text-center">
                    <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="trash-2" class="w-6 h-6 text-red-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">确认删除</h3>
                    <p class="text-gray-500 mb-6">确定要删除 "<span x-text="deleteProduct?.name"></span>" 吗？此操作不可恢复。</p>
                    <div class="flex gap-3 justify-center">
                        <button @click="showDeleteModal = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">取消</button>
                        <button @click="deleteProductConfirm()" class="bg-red-600 text-white px-6 py-2 rounded-lg font-medium hover:bg-red-700 transition">删除</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 标签管理弹窗 -->
        <div x-show="showTagManager" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showTagManager = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">标签管理</h3>
                    <button @click="showTagManager = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-6">
                    <!-- 添加标签 -->
                    <div class="flex gap-2 mb-4">
                        <input type="text" x-model="newTagName" placeholder="输入标签名称"
                            @keydown.enter="addTag()"
                            class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                        <div class="relative">
                            <input type="color" x-model="newTagColor" class="w-10 h-10 rounded cursor-pointer border-0">
                        </div>
                        <button @click="addTag()" class="gradient-bg text-white px-4 py-2 rounded-lg font-medium hover:opacity-90 transition">
                            添加
                        </button>
                    </div>
                    
                    <!-- 预设颜色 -->
                    <div class="flex flex-wrap gap-2 mb-4">
                        <template x-for="color in tagColors" :key="color">
                            <button @click="newTagColor = color"
                                class="w-6 h-6 rounded-full border-2 transition"
                                :class="newTagColor === color ? 'border-gray-800 scale-110' : 'border-transparent hover:scale-110'"
                                :style="'background-color: ' + color">
                            </button>
                        </template>
                    </div>
                    
                    <!-- 标签列表 -->
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <template x-for="tag in tags" :key="tag.id">
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center gap-3">
                                    <span class="w-4 h-4 rounded-full" :style="'background-color: ' + tag.color"></span>
                                    <span class="font-medium" x-text="tag.name"></span>
                                    <span class="text-xs text-gray-500" x-text="tag.product_count + ' 个商品'"></span>
                                </div>
                                <button @click="deleteTag(tag.id)" class="text-red-500 hover:text-red-700 p-1">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </template>
                        <div x-show="tags.length === 0" class="text-center text-gray-500 py-4">
                            暂无标签，请添加
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 价格历史弹窗 -->
        <div x-show="showHistoryModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showHistoryModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">价格走势</h3>
                    <button @click="showHistoryModal = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-6">
                    <div class="mb-4">
                        <h4 class="font-medium text-gray-800" x-text="historyProduct?.name"></h4>
                    </div>
                    <div class="h-64 sm:h-80 relative">
                        <canvas id="priceChart"></canvas>
                    </div>
                    <div x-show="chartRange === '7days' || chartRange === 'year' || chartRange === 'all'" class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                        <i data-lucide="move" class="w-4 h-4"></i>
                        <span>拖动平移 | 滚轮/双指缩放</span>
                        <button @click="resetChartZoom()" class="ml-2 px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded text-xs">重置</button>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-4 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500">到手价:</span>
                            <span class="font-bold text-primary" x-text="'¥' + (Number(historyProduct?.current_price || 0).toFixed(2))"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500">历史最低:</span>
                            <span class="font-bold text-green-600" x-text="'¥' + (Number(historyProduct?.lowest_price || 0).toFixed(2))"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-gray-500">历史最高:</span>
                            <span class="font-bold text-red-600" x-text="'¥' + (Number(historyProduct?.highest_price || 0).toFixed(2))"></span>
                        </div>
                    </div>
                    <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div class="text-sm text-gray-500">
                            <span>时间范围:</span>
                        </div>
                        <div class="flex rounded-lg overflow-hidden border border-gray-200">
                            <button @click="setChartRange('7days')" 
                                :disabled="chartLoading"
                                :class="(chartRange === '7days' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50') + (chartLoading ? ' opacity-50 cursor-not-allowed' : '')"
                                class="px-3 py-1.5 text-sm font-medium transition">7天</button>
                            <button @click="setChartRange('week')" 
                                :disabled="chartLoading"
                                :class="(chartRange === 'week' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50') + (chartLoading ? ' opacity-50 cursor-not-allowed' : '')"
                                class="px-3 py-1.5 text-sm font-medium transition border-l border-gray-200">周</button>
                            <button @click="setChartRange('month')" 
                                :disabled="chartLoading"
                                :class="(chartRange === 'month' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50') + (chartLoading ? ' opacity-50 cursor-not-allowed' : '')"
                                class="px-3 py-1.5 text-sm font-medium transition border-l border-gray-200">月</button>
                            <button @click="setChartRange('quarter')" 
                                :disabled="chartLoading"
                                :class="(chartRange === 'quarter' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50') + (chartLoading ? ' opacity-50 cursor-not-allowed' : '')"
                                class="px-3 py-1.5 text-sm font-medium transition border-l border-gray-200">季</button>
                            <button @click="setChartRange('year')" 
                                :disabled="chartLoading"
                                :class="(chartRange === 'year' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50') + (chartLoading ? ' opacity-50 cursor-not-allowed' : '')"
                                class="px-3 py-1.5 text-sm font-medium transition border-l border-gray-200">年</button>
                            <button @click="setChartRange('all')" 
                                :disabled="chartLoading"
                                :class="(chartRange === 'all' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50') + (chartLoading ? ' opacity-50 cursor-not-allowed' : '')"
                                class="px-3 py-1.5 text-sm font-medium transition border-l border-gray-200">全部</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 价格日历弹窗 -->
        <div x-show="showCalendarModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showCalendarModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">📅 价格日历</h3>
                    <button @click="showCalendarModal = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <div class="p-6">
                    <div class="mb-4 text-sm text-gray-600" x-text="calendarProduct?.name"></div>
                    
                    <!-- 月份导航 -->
                    <div class="flex items-center justify-between mb-4">
                        <button @click="prevMonth()" class="p-2 hover:bg-gray-100 rounded-lg">
                            <i data-lucide="chevron-left" class="w-5 h-5"></i>
                        </button>
                        <span class="font-medium text-lg" x-text="calendarYear + '年 ' + getMonthName()"></span>
                        <button @click="nextMonth()" class="p-2 hover:bg-gray-100 rounded-lg">
                            <i data-lucide="chevron-right" class="w-5 h-5"></i>
                        </button>
                    </div>
                    
                    <!-- 星期标题 -->
                    <div class="grid grid-cols-7 gap-1 mb-2">
                        <div class="text-center text-xs font-medium text-gray-500 py-2">日</div>
                        <div class="text-center text-xs font-medium text-gray-500 py-2">一</div>
                        <div class="text-center text-xs font-medium text-gray-500 py-2">二</div>
                        <div class="text-center text-xs font-medium text-gray-500 py-2">三</div>
                        <div class="text-center text-xs font-medium text-gray-500 py-2">四</div>
                        <div class="text-center text-xs font-medium text-gray-500 py-2">五</div>
                        <div class="text-center text-xs font-medium text-gray-500 py-2">六</div>
                    </div>
                    
                    <!-- 日历格子 -->
                    <div class="grid grid-cols-7 gap-1">
                        <template x-for="day in calendarDays" :key="day.key">
                            <div class="aspect-square rounded-lg flex flex-col items-center justify-center text-xs"
                                :class="day.isNull ? '' : getCalendarColor(day)">
                                <span x-show="!day.isNull" class="font-medium" x-text="day.day"></span>
                                <span x-show="day.hasData" class="text-[10px]" x-text="'¥' + day.price"></span>
                            </div>
                        </template>
                    </div>
                    
                    <!-- 图例 -->
                    <div class="mt-4 flex flex-wrap gap-3 text-xs text-gray-600">
                        <div class="flex items-center gap-1">
                            <span class="w-3 h-3 rounded bg-green-500"></span>
                            <span>达到目标价</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="w-3 h-3 rounded bg-green-400"></span>
                            <span>接近最低价</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="w-3 h-3 rounded bg-blue-400"></span>
                            <span>低于当前价</span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="w-3 h-3 rounded bg-red-400"></span>
                            <span>高于当前价10%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 设置弹窗 -->
        <div x-show="showSettings" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showSettings = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center sticky top-0 bg-white">
                    <h3 class="text-lg font-semibold text-gray-800">系统设置</h3>
                    <button @click="showSettings = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                
                <div class="p-6 space-y-6">
                    <!-- 密码设置 -->
                    <div class="border-b pb-6">
                        <h4 class="font-medium text-gray-800 mb-4 flex items-center gap-2">
                            <i data-lucide="lock" class="w-5 h-5"></i>
                            访问密码
                        </h4>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">原密码</label>
                                <input type="password" x-model="passwordForm.old_password"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">新密码</label>
                                <input type="password" x-model="passwordForm.new_password"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                            </div>
                            <button @click="changePassword()" class="gradient-bg text-white px-4 py-2 rounded-lg font-medium hover:opacity-90 transition">
                                修改密码
                            </button>
                        </div>
                    </div>
                    
                    <!-- 京东配置 -->
                    <div class="border-b pb-6">
                        <h4 class="font-medium text-gray-800 mb-4 flex items-center gap-2">
                            <i data-lucide="shopping-bag" class="w-5 h-5"></i>
                            京东配置
                        </h4>
                        <div class="space-y-4">
                            <div>
                                <div class="flex justify-between items-center mb-2">
                                    <label class="text-sm font-medium text-gray-700">京东Cookie</label>
                                    <div class="flex gap-2">
                                        <button @click="openQrLogin()" type="button"
                                            class="text-xs px-3 py-1 bg-green-50 text-green-600 rounded-full hover:bg-green-100 transition flex items-center gap-1">
                                            <i data-lucide="scan-line" class="w-3 h-3"></i>
                                            扫码登录
                                        </button>
                                        <button @click="showCookieHelper = true" type="button"
                                            class="text-xs px-3 py-1 bg-blue-50 text-blue-600 rounded-full hover:bg-blue-100 transition flex items-center gap-1">
                                            <i data-lucide="key" class="w-3 h-3"></i>
                                            Cookie获取助手
                                        </button>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="text-xs text-gray-500 mb-1 block">pt_key</label>
                                        <input type="text" x-model="settingsForm.pt_key"
                                            placeholder="粘贴pt_key值"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none text-sm">
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500 mb-1 block">pt_pin (账号标识)</label>
                                        <input type="text" x-model="settingsForm.pt_pin"
                                            placeholder="粘贴pt_pin值（账号标识）"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none text-sm">
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2">💡 分别填写pt_key和pt_pin，系统会自动组合成完整Cookie</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="text-sm text-gray-500">Cookie状态：</span>
                                <span x-show="settings.cookie_status === 'valid'" class="text-green-600 flex items-center gap-1">
                                    <i data-lucide="check-circle" class="w-4 h-4"></i> 正常
                                </span>
                                <span x-show="settings.cookie_status === 'invalid'" class="text-red-600 flex items-center gap-1">
                                    <i data-lucide="x-circle" class="w-4 h-4"></i> 已失效
                                </span>
                                <span x-show="settings.cookie_status === 'not_set'" class="text-gray-400 flex items-center gap-1">
                                    <i data-lucide="minus-circle" class="w-4 h-4"></i> 未设置
                                </span>
                                <span x-show="settings.cookie_status === 'invalid_format'" class="text-orange-600 flex items-center gap-1">
                                    <i data-lucide="alert-circle" class="w-4 h-4"></i> 格式错误
                                </span>
                                <span x-show="settings.cookie_status === 'unknown'" class="text-gray-500 flex items-center gap-1">
                                    <i data-lucide="help-circle" class="w-4 h-4"></i> 未知
                                </span>
                                <button x-show="settings.cookie_status !== 'not_set'" @click="checkCookieStatus()" 
                                    class="text-xs text-blue-600 hover:underline ml-2">检查状态</button>
                            </div>
                            
                            <!-- 用户信息 -->
                            <div x-show="settings.jd_user" class="bg-gray-50 rounded-lg p-4 mt-3">
                                <div class="flex items-center gap-3 mb-3">
                                    <img x-show="settings.jd_user?.headImage" :src="settings.jd_user?.headImage" 
                                        class="w-10 h-10 rounded-full">
                                    <div>
                                        <div class="font-medium text-gray-800" x-text="settings.jd_user?.nickname || '京东用户'"></div>
                                        <div class="text-xs text-gray-500" x-text="settings.jd_user?.levelName || ''"></div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3 text-sm">
                                    <div class="flex items-center gap-2">
                                        <span class="text-gray-500">京豆:</span>
                                        <span class="font-medium text-orange-500" x-text="settings.jd_user?.beanNum || 0"></span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-gray-500">会员:</span>
                                        <span x-show="settings.jd_user?.isPlusVip" class="text-yellow-600 font-medium">Plus会员</span>
                                        <span x-show="!settings.jd_user?.isPlusVip" class="text-gray-400">普通用户</span>
                                    </div>
                                    <div x-show="settings.jd_user?.isPlusVip && settings.jd_user?.plusExpireTime" class="col-span-2 flex items-center gap-2">
                                        <span class="text-gray-500">到期时间:</span>
                                        <span class="text-gray-700" x-text="settings.jd_user?.plusExpireTime"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <p x-show="settings.cookie_status_hint" class="text-xs text-gray-500 mt-2" x-text="settings.cookie_status_hint"></p>
                            
                            <!-- 价格获取方法统计 -->
                            <div class="mt-4">
                                <button @click="showMethodStats = !showMethodStats" 
                                    class="flex items-center gap-2 text-sm text-gray-600 hover:text-gray-800 w-full">
                                    <i data-lucide="bar-chart-2" class="w-4 h-4"></i>
                                    <span>价格获取方法统计</span>
                                    <i data-lucide="chevron-down" class="w-4 h-4 transition-transform duration-200" :class="{'rotate-180': showMethodStats}"></i>
                                </button>
                                <div x-show="showMethodStats" 
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0 max-h-0"
                                    x-transition:enter-end="opacity-100 max-h-96"
                                    x-transition:leave="transition ease-in duration-150"
                                    x-transition:leave-start="opacity-100 max-h-96"
                                    x-transition:leave-end="opacity-0 max-h-0"
                                    class="mt-3 bg-gray-50 rounded-lg p-3 overflow-hidden">
                                    <div class="text-xs text-gray-500 mb-2">按成功率排序（成功率优先，耗时次之）</div>
                                    <div class="space-y-2">
                                        <template x-for="(stat, index) in methodStats" :key="stat.method">
                                            <div class="flex items-center gap-3 text-sm">
                                                <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-medium"
                                                    :class="{
                                                        'bg-green-100 text-green-700': index === 0,
                                                        'bg-blue-100 text-blue-700': index === 1,
                                                        'bg-gray-100 text-gray-700': index >= 2
                                                    }"
                                                    x-text="index + 1"></span>
                                                <span class="w-20 text-gray-700" x-text="stat.name"></span>
                                                <div class="flex-1 bg-gray-200 rounded-full h-2 overflow-hidden">
                                                    <div class="h-full rounded-full transition-all"
                                                        :class="{
                                                            'bg-green-500': stat.success_rate >= 80,
                                                            'bg-yellow-500': stat.success_rate >= 50 && stat.success_rate < 80,
                                                            'bg-red-500': stat.success_rate < 50
                                                        }"
                                                        :style="'width: ' + stat.success_rate + '%'"></div>
                                                </div>
                                                <span class="w-16 text-right text-xs"
                                                    :class="{
                                                        'text-green-600': stat.success_rate >= 80,
                                                        'text-yellow-600': stat.success_rate >= 50 && stat.success_rate < 80,
                                                        'text-red-600': stat.success_rate < 50
                                                    }"
                                                    x-text="stat.success_rate + '%'"></span>
                                                <span class="w-20 text-right text-xs text-gray-500" x-text="stat.avg_time + 'ms'"></span>
                                                <span class="w-16 text-right text-xs text-gray-400" x-text="stat.total + '次'"></span>
                                            </div>
                                        </template>
                                    </div>
                                    <div class="mt-2 pt-2 border-t border-gray-200 text-xs text-gray-400 flex justify-between">
                                        <span>方法名称</span>
                                        <span class="flex gap-4">
                                            <span>成功率</span>
                                            <span>平均耗时</span>
                                            <span>调用次数</span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 价格保护配置 -->
                    <div class="border-b pb-6">
                        <h4 class="font-medium text-gray-800 mb-4 flex items-center gap-2">
                            <i data-lucide="shield-check" class="w-5 h-5"></i>
                            价格保护
                        </h4>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <label class="text-sm font-medium text-gray-700">启用自动价保</label>
                                    <p class="text-xs text-gray-500 mt-1">自动检查订单并申请价格保护</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" x-model="settingsForm.price_protection_enabled" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>
                            
                            <div x-show="settingsForm.price_protection_enabled">
                                <label class="text-sm font-medium text-gray-700 mb-2 block">执行间隔</label>
                                <div class="flex gap-2">
                                    <select x-model="settingsForm.price_protection_interval_preset" 
                                        @change="if(settingsForm.price_protection_interval_preset !== 'custom') { settingsForm.price_protection_interval = settingsForm.price_protection_interval_preset; }"
                                        class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none text-sm">
                                        <option value="60">每小时</option>
                                        <option value="180">每3小时</option>
                                        <option value="360">每6小时</option>
                                        <option value="720">每12小时</option>
                                        <option value="1440">每天</option>
                                        <option value="custom">自定义...</option>
                                    </select>
                                    <input x-show="settingsForm.price_protection_interval_preset === 'custom'" 
                                        type="number" min="30" max="10080"
                                        x-model="settingsForm.price_protection_interval"
                                        placeholder="分钟"
                                        class="w-24 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none text-sm">
                                </div>
                                <p class="text-xs text-gray-500 mt-1">最小30分钟，最大7天（10080分钟）</p>
                            </div>
                            
                            <div x-show="settings.price_protection_last_run" class="text-xs text-gray-500">
                                上次执行: <span x-text="settings.price_protection_last_run"></span>
                            </div>
                            
                            <div class="flex gap-2">
                                <button @click="runPriceProtection()" 
                                    :disabled="priceProtectionRunning"
                                    class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition text-sm disabled:opacity-50 flex items-center gap-2">
                                    <i data-lucide="play" class="w-4 h-4" :class="{'animate-spin': priceProtectionRunning}"></i>
                                    <span x-text="priceProtectionRunning ? '执行中...' : '立即执行'"></span>
                                </button>
                                <button @click="showPriceProtectionLogs = true" 
                                    class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition text-sm flex items-center gap-2">
                                    <i data-lucide="history" class="w-4 h-4"></i>
                                    查看日志
                                </button>
                            </div>
                            
                            <!-- 价保统计 -->
                            <div x-show="priceProtectionStats" class="bg-gray-50 rounded-lg p-4 mt-3">
                                <div class="text-xs text-gray-500 mb-2">近30天统计</div>
                                <div class="grid grid-cols-3 gap-4 text-center">
                                    <div>
                                        <div class="text-2xl font-bold text-blue-600" x-text="priceProtectionStats?.total || 0"></div>
                                        <div class="text-xs text-gray-500">申请次数</div>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-green-600" x-text="priceProtectionStats?.success_count || 0"></div>
                                        <div class="text-xs text-gray-500">成功次数</div>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-orange-500">¥<span x-text="(priceProtectionStats?.total_refund || 0).toFixed(2)"></span></div>
                                        <div class="text-xs text-gray-500">退款总额</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Webhook配置 -->
                    <div class="border-b pb-6">
                        <h4 class="font-medium text-gray-800 mb-4 flex items-center gap-2">
                            <i data-lucide="bell" class="w-5 h-5"></i>
                            通知配置
                        </h4>
                        <div class="space-y-4">
                            <!-- Webhook列表 -->
                            <div class="space-y-3">
                                <template x-for="(webhook, index) in settingsForm.webhooks" :key="index">
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex justify-between items-start mb-3">
                                            <input type="text" x-model="webhook.name" placeholder="名称"
                                                class="px-3 py-1 border border-gray-300 rounded-lg text-sm">
                                            <div class="flex gap-2">
                                                <label class="flex items-center gap-1 text-sm">
                                                    <input type="checkbox" x-model="webhook.enabled" class="rounded">
                                                    启用
                                                </label>
                                                <button @click="settingsForm.webhooks.splice(index, 1)" class="text-red-500 hover:text-red-700">
                                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <input type="text" x-model="webhook.url" placeholder="Webhook URL"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm mb-2">
                                        <div class="flex items-center gap-4 mb-2">
                                            <label class="text-xs text-gray-500">通知格式：</label>
                                            <label class="flex items-center gap-1 text-sm">
                                                <input type="radio" :name="'format-' + index" value="markdown" x-model="webhook.format" class="text-primary">
                                                Markdown
                                            </label>
                                            <label class="flex items-center gap-1 text-sm">
                                                <input type="radio" :name="'format-' + index" value="text" x-model="webhook.format" class="text-primary">
                                                纯文本
                                            </label>
                                        </div>
                                        <div class="flex gap-2">
                                            <button @click="testWebhook(webhook)" class="text-xs px-2 py-1 border border-gray-300 rounded hover:bg-gray-50">测试</button>
                                            <span x-show="webhook.testResult" class="text-xs" :class="webhook.testResult?.success ? 'text-green-600' : 'text-red-600'" x-text="webhook.testResult?.message"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            
                            <button @click="settingsForm.webhooks.push({name: '', url: '', enabled: true, format: 'text'})"
                                class="w-full border-2 border-dashed border-gray-300 rounded-lg py-3 text-gray-500 hover:border-primary hover:text-primary transition">
                                + 添加Webhook
                            </button>
                            
                            <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-600">
                                <p class="font-medium mb-2">支持的Webhook：</p>
                                <ul class="space-y-1 list-disc list-inside">
                                    <li>钉钉机器人</li>
                                    <li>企业微信机器人</li>
                                    <li>Telegram Bot</li>
                                    <li>Discord Webhook</li>
                                    <li>Slack Webhook</li>
                                    <li>自定义Webhook</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 默认设置 -->
                    <div class="border-b pb-6">
                        <h4 class="font-medium text-gray-800 mb-4 flex items-center gap-2">
                            <i data-lucide="sliders" class="w-5 h-5"></i>
                            默认设置
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">默认价格波动阈值（%）</label>
                                <input type="number" step="0.1" x-model="settingsForm.default_price_threshold"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Cookie检测间隔（分钟）</label>
                                <input type="number" x-model="settingsForm.cookie_check_interval"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                                <p class="text-xs text-gray-500 mt-1">定时检测Cookie是否有效</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 静默时段 -->
                    <div class="border-b pb-6">
                        <h4 class="font-medium text-gray-800 mb-4 flex items-center gap-2">
                            <i data-lucide="moon" class="w-5 h-5"></i>
                            静默时段
                        </h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">开始时间</label>
                                <input type="time" x-model="settingsForm.silent_start"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">结束时间</label>
                                <input type="time" x-model="settingsForm.silent_end"
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent outline-none">
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">静默时段内不发送通知，但仍会记录价格</p>
                    </div>
                    
                    <!-- 数据导出 -->
                    <div>
                        <h4 class="font-medium text-gray-800 mb-4 flex items-center gap-2">
                            <i data-lucide="download" class="w-5 h-5"></i>
                            数据导出
                        </h4>
                        <div class="flex gap-3">
                            <a href="<?= $basePath ?>/api/export.php?type=products" target="_blank"
                                class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                                <i data-lucide="file-text" class="w-4 h-4"></i>
                                导出商品列表
                            </a>
                            <a href="<?= $basePath ?>/api/export.php?type=history" target="_blank"
                                class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-2">
                                <i data-lucide="file-text" class="w-4 h-4"></i>
                                导出价格历史
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="p-6 border-t bg-white sticky bottom-0 flex gap-3 justify-end">
                    <button @click="showSettings = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">取消</button>
                    <button @click="saveSettings()" class="gradient-bg text-white px-6 py-2 rounded-lg font-medium hover:opacity-90 transition">保存设置</button>
                </div>
            </div>
        </div>
        
        <!-- 扫码登录弹窗 -->
        <div x-show="showQrLogin" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="closeQrLogin()">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                        <i data-lucide="scan-line" class="w-5 h-5 text-green-600"></i>
                        京东扫码登录
                    </h3>
                    <button @click="closeQrLogin()" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                
                <div class="p-6 text-center">
                    <!-- 二维码区域 -->
                    <div class="relative mx-auto w-56 h-56 mb-4">
                        <!-- 加载状态 -->
                        <div x-show="qrStatus === 'loading'" class="w-full h-full flex items-center justify-center bg-gray-50 rounded-xl">
                            <div class="loading-spinner w-10 h-10"></div>
                        </div>
                        
                        <!-- 二维码图片 -->
                        <img x-show="qrStatus !== 'loading' && qrStatus !== 'error' && qrcodeImage" 
                            :src="qrcodeImage" 
                            alt="京东登录二维码"
                            class="w-full h-full object-contain rounded-xl border border-gray-200"
                            :class="{'opacity-30': qrStatus === 'scanned' || qrStatus === 'expired' || qrStatus === 'confirmed'}">
                        
                        <!-- 已扫描遮罩 -->
                        <div x-show="qrStatus === 'scanned'" class="absolute inset-0 flex items-center justify-center bg-blue-500/10 rounded-xl">
                            <div class="bg-white rounded-full p-4 shadow-lg">
                                <i data-lucide="smartphone" class="w-12 h-12 text-blue-600"></i>
                            </div>
                        </div>
                        
                        <!-- 已确认遮罩 -->
                        <div x-show="qrStatus === 'confirmed'" class="absolute inset-0 flex items-center justify-center bg-green-500/10 rounded-xl">
                            <div class="bg-white rounded-full p-4 shadow-lg">
                                <i data-lucide="check-circle" class="w-12 h-12 text-green-600"></i>
                            </div>
                        </div>
                        
                        <!-- 过期遮罩 -->
                        <div x-show="qrStatus === 'expired'" class="absolute inset-0 flex items-center justify-center bg-gray-500/10 rounded-xl">
                            <div class="bg-white rounded-full p-4 shadow-lg cursor-pointer" @click="refreshQrCode()">
                                <i data-lucide="refresh-cw" class="w-12 h-12 text-gray-600"></i>
                            </div>
                        </div>
                        
                        <!-- 错误状态 -->
                        <div x-show="qrStatus === 'error'" class="w-full h-full flex flex-col items-center justify-center bg-red-50 rounded-xl p-4">
                            <i data-lucide="alert-circle" class="w-12 h-12 text-red-500 mb-2"></i>
                            <button @click="refreshQrCode()" class="text-sm text-red-600 hover:underline">点击重试</button>
                        </div>
                    </div>
                    
                    <!-- 状态提示 -->
                    <p class="text-sm font-medium" 
                        :class="{
                            'text-gray-600': qrStatus === 'waiting' || qrStatus === 'loading',
                            'text-blue-600': qrStatus === 'scanned',
                            'text-green-600': qrStatus === 'confirmed',
                            'text-gray-500': qrStatus === 'expired',
                            'text-red-600': qrStatus === 'error'
                        }"
                        x-text="qrMessage"></p>
                    
                    <!-- 提示信息 -->
                    <p class="text-xs text-gray-400 mt-4">
                        打开京东APP → 右上角扫一扫 → 扫描二维码登录
                    </p>
                    
                    <!-- 刷新按钮 -->
                    <div class="mt-4" x-show="qrStatus === 'waiting'">
                        <button @click="refreshQrCode()" class="text-xs text-gray-500 hover:text-primary transition flex items-center gap-1 mx-auto">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i>
                            刷新二维码
                        </button>
                    </div>
                    
                    <!-- 过期刷新按钮 -->
                    <div class="mt-4" x-show="qrStatus === 'expired'">
                        <button @click="refreshQrCode()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition text-sm">
                            点击刷新
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cookie获取助手弹窗 -->
        <div x-show="showCookieHelper" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showCookieHelper = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center sticky top-0 bg-white z-10">
                    <h3 class="text-lg font-semibold text-gray-800">🔑 Cookie获取助手</h3>
                    <button @click="showCookieHelper = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                
                <div class="p-6 space-y-6">
                    <!-- 手动获取 -->
                    <div class="border border-gray-200 bg-gray-50 rounded-xl p-5">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="px-2 py-0.5 bg-primary text-white text-xs rounded-full">手动提取</span>
                            <h4 class="font-semibold text-gray-800">获取步骤</h4>
                        </div>
                        
                        <div class="space-y-3 text-sm text-gray-600">
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary text-white rounded-full flex items-center justify-center text-xs font-bold">1</span>
                                <p>访问 <a href="https://m.jd.com" target="_blank" class="text-blue-600 hover:underline">京东移动端首页</a> 并登录您的账号</p>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary text-white rounded-full flex items-center justify-center text-xs font-bold">2</span>
                                <p>按 <kbd class="px-1.5 py-0.5 bg-white border rounded text-xs">F12</kbd> 打开开发者工具</p>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary text-white rounded-full flex items-center justify-center text-xs font-bold">3</span>
                                <p>切换到 <strong>Application</strong>（应用程序）标签</p>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary text-white rounded-full flex items-center justify-center text-xs font-bold">4</span>
                                <p>左侧菜单：Cookies → <a href="https://m.jd.com" target="_blank" class="text-blue-600">https://m.jd.com</a></p>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary text-white rounded-full flex items-center justify-center text-xs font-bold">5</span>
                                <p>找到 <code class="bg-white px-1 rounded">pt_key</code> 和 <code class="bg-white px-1 rounded">pt_pin</code>，复制它们的值</p>
                            </div>
                            <div class="flex items-start gap-3">
                                <span class="flex-shrink-0 w-6 h-6 bg-primary text-white rounded-full flex items-center justify-center text-xs font-bold">6</span>
                                <p>格式：<code class="bg-white px-1 rounded">pt_key=xxx;pt_pin=xxx</code></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 注意事项 -->
                    <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                        <div class="flex items-start gap-3">
                            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
                            <div class="text-sm text-amber-700">
                                <p class="font-medium mb-2">⚠️ 注意事项</p>
                                <ul class="space-y-1 list-disc list-inside">
                                    <li>Cookie包含敏感信息，请勿泄露给他人</li>
                                    <li>Cookie有效期通常为1-3个月，过期后需重新获取</li>
                                    <li>退出京东登录会导致Cookie失效</li>
                                    <li>建议定期检查Cookie状态</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="p-6 border-t bg-white sticky bottom-0 flex gap-3 justify-end">
                    <button @click="showCookieHelper = false" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition">关闭</button>
                    <a href="https://m.jd.com" target="_blank" class="gradient-bg text-white px-6 py-2 rounded-lg font-medium hover:opacity-90 transition inline-flex items-center gap-2">
                        <i data-lucide="external-link" class="w-4 h-4"></i>
                        去京东登录
                    </a>
                </div>
            </div>
        </div>
        
        <!-- 已达目标价弹窗 -->
        <div x-show="showPriceMetModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showPriceMetModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[80vh] overflow-hidden slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">🎯 已达目标价商品</h3>
                    <button @click="showPriceMetModal = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                
                <div class="p-6 overflow-y-auto max-h-[60vh]">
                    <div class="space-y-4">
                        <template x-for="product in priceMetProducts" :key="product.id">
                            <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition">
                                <div class="flex items-start gap-4">
                                    <img :src="product.image_url || 'https://via.placeholder.com/80'" 
                                        class="w-20 h-20 object-cover rounded-lg flex-shrink-0">
                                    <div class="flex-1 min-w-0">
                                        <a :href="'https://item.jd.com/' + product.sku_id + '.html'" target="_blank"
                                            class="font-medium text-gray-800 hover:text-blue-600 line-clamp-2" x-text="product.name"></a>
                                        <div class="mt-2 flex flex-wrap gap-2 text-sm">
                                            <span class="px-2 py-1 bg-red-100 text-red-700 rounded">当前: ¥<span x-text="Number(product.current_price).toFixed(2)"></span></span>
                                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded">目标: ¥<span x-text="Number(product.target_price).toFixed(2)"></span></span>
                                            <span class="px-2 py-1 bg-gray-100 text-gray-700 rounded">原价: ¥<span x-text="Number(product.original_price || product.current_price).toFixed(2)"></span></span>
                                        </div>
                                        <div class="mt-2 text-xs text-gray-500">
                                            <span>SKU: <span x-text="product.sku_id"></span></span>
                                            <span class="mx-2">|</span>
                                            <span>最低价: ¥<span x-text="Number(product.lowest_price).toFixed(2)"></span></span>
                                            <span class="mx-2">|</span>
                                            <span>更新: <span x-text="product.updated_at"></span></span>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <div class="text-lg font-bold text-green-600">
                                            -¥<span x-text="(Number(product.original_price || product.current_price) - Number(product.current_price)).toFixed(2)"></span>
                                        </div>
                                        <div class="text-xs text-gray-500">已降价</div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                    
                    <div x-show="priceMetProducts.length === 0" class="text-center py-12 text-gray-500">
                        <i data-lucide="check-circle" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
                        <p>暂无已达目标价的商品</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 价保日志弹窗 -->
        <div x-show="showPriceProtectionLogs" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showPriceProtectionLogs = false" x-init="showPriceProtectionLogs && loadPriceProtectionLogs()">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">📋 价保申请日志</h3>
                    <button @click="showPriceProtectionLogs = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                
                <div class="p-6 overflow-y-auto max-h-[60vh]">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left">订单号</th>
                                <th class="px-4 py-3 text-left">商品</th>
                                <th class="px-4 py-3 text-right">购买价</th>
                                <th class="px-4 py-3 text-right">退款</th>
                                <th class="px-4 py-3 text-center">状态</th>
                                <th class="px-4 py-3 text-left">时间</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <template x-for="log in priceProtectionLogs" :key="log.id">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-xs" x-text="log.order_id"></td>
                                    <td class="px-4 py-3 truncate max-w-[200px]" x-text="log.product_name || log.sku_id"></td>
                                    <td class="px-4 py-3 text-right">¥<span x-text="Number(log.buy_price || 0).toFixed(2)"></span></td>
                                    <td class="px-4 py-3 text-right text-green-600">¥<span x-text="Number(log.refund_amount || 0).toFixed(2)"></span></td>
                                    <td class="px-4 py-3 text-center">
                                        <span x-show="log.status === 'success'" class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs">成功</span>
                                        <span x-show="log.status === 'failed'" class="px-2 py-1 bg-red-100 text-red-700 rounded text-xs">失败</span>
                                        <span x-show="log.status === 'pending'" class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs">处理中</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500" x-text="log.applied_at"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    
                    <div x-show="priceProtectionLogs.length === 0" class="text-center py-12 text-gray-500">
                        <i data-lucide="inbox" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
                        <p>暂无价保记录</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 价格记录弹窗 -->
        <div x-show="showPriceHistoryModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-overlay" @click.self="showPriceHistoryModal = false">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[80vh] overflow-hidden slide-in" @click.stop>
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">📊 价格记录</h3>
                    <button @click="showPriceHistoryModal = false" class="p-2 hover:bg-gray-100 rounded-lg">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </div>
                
                <div class="p-4 border-b bg-gray-50">
                    <div class="flex flex-wrap gap-3 items-center">
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600">时间范围：</label>
                            <select x-model="priceHistoryFilter" @change="loadPriceHistoryList()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
                                <option value="today">今天</option>
                                <option value="week">最近7天</option>
                                <option value="month">最近30天</option>
                                <option value="all">全部</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="text-sm text-gray-600">商品：</label>
                            <select x-model="priceHistoryProductId" @change="loadPriceHistoryList()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm min-w-[150px]">
                                <option value="">全部商品</option>
                                <template x-for="p in products" :key="p.id">
                                    <option :value="p.id" x-text="p.name.substring(0, 20) + (p.name.length > 20 ? '...' : '')"></option>
                                </template>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="p-6 overflow-y-auto max-h-[55vh]">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left">商品</th>
                                <th class="px-4 py-3 text-right">原价</th>
                                <th class="px-4 py-3 text-right">当前价</th>
                                <th class="px-4 py-3 text-center">变动</th>
                                <th class="px-4 py-3 text-left">记录时间</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <template x-for="record in priceHistoryList" :key="record.id">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <div class="truncate max-w-[200px]" x-text="record.product_name"></div>
                                        <div class="text-xs text-gray-400" x-text="record.sku_id"></div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-500">
                                        <span x-show="record.original_price > 0">¥<span x-text="Number(record.original_price).toFixed(2)"></span></span>
                                        <span x-show="!record.original_price || record.original_price == 0">-</span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-medium">
                                        ¥<span x-text="Number(record.price).toFixed(2)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <template x-if="record.price_change > 0">
                                            <span class="text-red-500 text-xs">↑ +¥<span x-text="Number(record.price_change).toFixed(2)"></span></span>
                                        </template>
                                        <template x-if="record.price_change < 0">
                                            <span class="text-green-500 text-xs">↓ -¥<span x-text="Number(Math.abs(record.price_change)).toFixed(2)"></span></span>
                                        </template>
                                        <template x-if="record.price_change == 0">
                                            <span class="text-gray-400 text-xs">-</span>
                                        </template>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500" x-text="record.recorded_at"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    
                    <div x-show="priceHistoryList.length === 0" class="text-center py-12 text-gray-500">
                        <i data-lucide="bar-chart-2" class="w-12 h-12 mx-auto mb-3 opacity-30"></i>
                        <p>暂无价格记录</p>
                    </div>
                    
                    <div x-show="priceHistoryList.length > 0 && priceHistoryHasMore" class="text-center py-4">
                        <button @click="loadMorePriceHistory()" class="px-4 py-2 text-sm text-primary hover:underline">
                            加载更多
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Toast通知 -->
        <div x-show="toast.show" x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 transform translate-y-2"
            x-transition:enter-end="opacity-100 transform translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 transform translate-y-0"
            x-transition:leave-end="opacity-0 transform translate-y-2"
            class="fixed bottom-4 right-4 z-50">
            <div class="px-4 py-3 rounded-lg shadow-lg flex items-center gap-3"
                :class="{
                    'bg-green-500 text-white': toast.type === 'success',
                    'bg-red-500 text-white': toast.type === 'error',
                    'bg-blue-500 text-white': toast.type === 'info'
                }">
                <i data-lucide="check-circle" x-show="toast.type === 'success'" class="w-5 h-5"></i>
                <i data-lucide="x-circle" x-show="toast.type === 'error'" class="w-5 h-5"></i>
                <i data-lucide="info" x-show="toast.type === 'info'" class="w-5 h-5"></i>
                <span x-text="toast.message"></span>
            </div>
        </div>
    </div>
    
    <?php endif; ?>
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <!-- App Modules -->
    <script src="assets/js/modules/utils.js"></script>
    <script src="assets/js/modules/products.js"></script>
    <script src="assets/js/modules/settings.js"></script>
    <script src="assets/js/modules/chart.js"></script>
    <script src="assets/js/modules/protection.js"></script>
    <script src="assets/js/modules/price-history.js"></script>
    <script src="assets/js/modules/tags.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>
