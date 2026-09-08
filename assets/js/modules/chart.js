function createChartModule() {
    return {
        priceChart: null,
        chartRange: 'month',
        allPriceHistory: [],
        chartLoading: false,
        showHistoryModal: false,
        historyProduct: null,
        showCalendarModal: false,
        calendarProduct: null,
        calendarData: {},
        calendarMonth: new Date().getMonth(),
        calendarYear: new Date().getFullYear(),
        calendarDays: [],
        
        async showPriceHistory(product) {
            this.historyProduct = product;
            this.showHistoryModal = true;
            this.chartRange = 'month';
            
            try {
                const res = await fetch(BASE_PATH + '/api/products.php?id=' + product.id);
                const data = await res.json();
                
                if (data.success) {
                    this.allPriceHistory = data.data.price_history || [];
                    this.renderChart();
                }
            } catch (e) {
                this.showToast('加载价格历史失败', 'error');
            }
        },
        
        async showPriceCalendar(product) {
            this.calendarProduct = product;
            this.showCalendarModal = true;
            this.calendarMonth = new Date().getMonth();
            this.calendarYear = new Date().getFullYear();
            
            try {
                const res = await fetch(BASE_PATH + '/api/products.php?id=' + product.id);
                const data = await res.json();
                
                if (data.success) {
                    this.allPriceHistory = data.data.price_history || [];
                    this.processCalendarData();
                    this.updateCalendarDays();
                }
            } catch (e) {
                this.showToast('加载价格历史失败', 'error');
            }
        },
        
        processCalendarData() {
            this.calendarData = {};
            
            this.allPriceHistory.forEach(h => {
                const date = h.recorded_at.substring(0, 10);
                if (!this.calendarData[date] || h.price < this.calendarData[date].price) {
                    this.calendarData[date] = h;
                }
            });
        },
        
        updateCalendarDays() {
            const firstDay = new Date(this.calendarYear, this.calendarMonth, 1);
            const lastDay = new Date(this.calendarYear, this.calendarMonth + 1, 0);
            const days = [];
            
            const startPadding = firstDay.getDay();
            for (let i = 0; i < startPadding; i++) {
                days.push({isNull: true, key: 'pad-' + i});
            }
            
            for (let i = 1; i <= lastDay.getDate(); i++) {
                const dateStr = `${this.calendarYear}-${String(this.calendarMonth + 1).padStart(2, '0')}-${String(i).padStart(2, '0')}`;
                const priceData = this.calendarData[dateStr];
                days.push({
                    isNull: false,
                    key: dateStr,
                    day: i,
                    hasData: priceData !== undefined,
                    price: priceData ? priceData.price : null
                });
            }
            
            this.calendarDays = days;
        },
        
        prevMonth() {
            if (this.calendarMonth === 0) {
                this.calendarMonth = 11;
                this.calendarYear--;
            } else {
                this.calendarMonth--;
            }
            this.updateCalendarDays();
        },
        
        nextMonth() {
            if (this.calendarMonth === 11) {
                this.calendarMonth = 0;
                this.calendarYear++;
            } else {
                this.calendarMonth++;
            }
            this.updateCalendarDays();
        },
        
        getMonthName() {
            const months = ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'];
            return months[this.calendarMonth];
        },
        
        getCalendarColor(day) {
            if (!day || day.isNull || !day.hasData || !this.calendarProduct) return 'bg-gray-100';
            
            const price = day.price;
            if (!price) return 'bg-gray-50 hover:bg-gray-100 cursor-pointer';
            
            const current = parseFloat(this.calendarProduct.current_price) || 0;
            const lowest = parseFloat(this.calendarProduct.lowest_price) || 0;
            const target = parseFloat(this.calendarProduct.target_price) || 0;
            
            if (target > 0 && price <= target) return 'bg-green-500 text-white';
            if (lowest > 0 && price <= lowest * 1.02) return 'bg-green-400 text-white';
            if (current > 0 && price <= current) return 'bg-blue-400 text-white';
            if (current > 0 && price > current * 1.1) return 'bg-red-400 text-white';
            
            return 'bg-yellow-100';
        },
        
        setChartRange(range) {
            if (this.chartLoading) return;
            this.chartLoading = true;
            this.chartRange = range;
            
            const delay = 3000 + Math.random() * 2000;
            setTimeout(() => {
                this.renderChart();
            }, delay);
        },
        
        renderChart() {
            this.$nextTick(() => {
                const ctx = document.getElementById('priceChart');
                if (!ctx) {
                    this.chartLoading = false;
                    return;
                }
                
                if (this.priceChart) {
                    this.priceChart.destroy();
                }
                
                let filteredHistory = this.allPriceHistory;
                const now = new Date();
                
                if (this.chartRange === 'week') {
                    const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
                    filteredHistory = this.allPriceHistory.filter(h => new Date(h.recorded_at) >= weekAgo);
                } else if (this.chartRange === 'month') {
                    const monthAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
                    filteredHistory = this.allPriceHistory.filter(h => new Date(h.recorded_at) >= monthAgo);
                }
                
                if (filteredHistory.length === 0) {
                    this.chartLoading = false;
                    return;
                }
                
                const labels = filteredHistory.map(h => {
                    const date = new Date(h.recorded_at);
                    return `${date.getMonth() + 1}/${date.getDate()}`;
                });
                const prices = filteredHistory.map(h => h.price);
                
                this.priceChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: '价格',
                            data: prices,
                            borderColor: '#3B82F6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                ticks: {
                                    callback: function(value) {
                                        return '¥' + value;
                                    }
                                }
                            }
                        }
                    }
                });
                
                this.chartLoading = false;
            });
        }
    };
}
