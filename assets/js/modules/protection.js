function createProtectionModule() {
    return {
        showPriceProtectionLogs: false,
        priceProtectionRunning: false,
        priceProtectionStats: null,
        priceProtectionLogs: [],
        
        async loadPriceProtectionStats() {
            try {
                const res = await fetch(BASE_PATH + '/api/price-protection.php?action=stats');
                const data = await res.json();
                if (data.success) {
                    this.priceProtectionStats = data.data;
                }
            } catch (e) {
                console.error('加载价保统计失败', e);
            }
        },
        
        async runPriceProtection() {
            this.priceProtectionRunning = true;
            try {
                const res = await fetch(BASE_PATH + '/api/price-protection.php?action=run', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast(data.message || '价保执行完成');
                    this.loadPriceProtectionStats();
                } else {
                    this.showToast(data.message || '价保执行失败', 'error');
                }
            } catch (e) {
                this.showToast('价保执行失败', 'error');
            }
            this.priceProtectionRunning = false;
        },
        
        async loadPriceProtectionLogs() {
            try {
                const res = await fetch(BASE_PATH + '/api/price-protection.php?action=logs');
                const data = await res.json();
                if (data.success) {
                    this.priceProtectionLogs = data.data.logs || [];
                }
            } catch (e) {
                console.error('加载价保日志失败', e);
            }
        }
    };
}
