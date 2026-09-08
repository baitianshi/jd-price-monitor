function app() {
    const utils = createAppUtils();
    const products = createProductsModule();
    const settings = createSettingsModule();
    const chart = createChartModule();
    const protection = createProtectionModule();
    const priceHistory = createPriceHistoryModule();
    const tags = createTagsModule();
    
    return {
        ...utils,
        ...products,
        ...settings,
        ...chart,
        ...protection,
        ...priceHistory,
        ...tags,
        
        csrfToken: '',
        
        toast: {
            show: false,
            message: '',
            type: 'success'
        },
        
        showPriceMetModal: false,
        priceMetProducts: [],
        
        get filteredProducts() {
            return this.products.filter(p => {
                const matchStatus = this.filterStatus === 'all' || p.status === this.filterStatus;
                const matchSearch = !this.searchQuery || 
                    p.name.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                    p.sku_id.includes(this.searchQuery);
                const matchTag = !this.filterTag || (p.tags && p.tags.includes(this.filterTag));
                return matchStatus && matchSearch && matchTag;
            });
        },
        
        async init() {
            await this.checkAuth();
            await this.loadProducts();
            await this.loadSettings();
            await this.loadTags();
            lucide.createIcons();
        },
        
        async checkAuth() {
            try {
                const res = await fetch(BASE_PATH + '/api/auth.php?action=check');
                const data = await res.json();
                if (data.success && data.data.csrf_token) {
                    this.csrfToken = data.data.csrf_token;
                }
            } catch (e) {
                console.error('Auth check failed:', e);
            }
        },
        
        async loadPriceMetProducts() {
            this.priceMetProducts = this.products.filter(p => 
                p.status === 'active' && 
                p.current_price > 0 && 
                p.target_price > 0 && 
                p.current_price <= p.target_price
            );
        }
    };
}
