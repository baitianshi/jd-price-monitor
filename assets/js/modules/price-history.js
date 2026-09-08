function createPriceHistoryModule() {
    return {
        showPriceHistoryModal: false,
        priceHistoryList: [],
        priceHistoryFilter: 'week',
        priceHistoryProductId: '',
        priceHistoryPage: 1,
        priceHistoryHasMore: false,
        
        async loadPriceHistoryList() {
            this.priceHistoryPage = 1;
            this.priceHistoryList = [];
            await this.loadMorePriceHistory();
        },
        
        async loadMorePriceHistory() {
            try {
                const params = new URLSearchParams({
                    page: this.priceHistoryPage,
                    filter: this.priceHistoryFilter,
                    product_id: this.priceHistoryProductId
                });
                
                const res = await fetch(BASE_PATH + '/api/price-history.php?' + params);
                const data = await res.json();
                
                if (data.success && data.data) {
                    if (this.priceHistoryPage === 1) {
                        this.priceHistoryList = data.data.records;
                    } else {
                        this.priceHistoryList = [...this.priceHistoryList, ...data.data.records];
                    }
                    this.priceHistoryHasMore = data.data.has_more;
                    this.priceHistoryPage++;
                }
            } catch (e) {
                console.error('加载价格记录失败', e);
            }
        }
    };
}
