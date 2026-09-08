function createProductsModule() {
    return {
        products: [],
        loading: false,
        checking: false,
        adding: false,
        addStatus: '',
        checkingProductId: null,
        
        showAddModal: false,
        showBatchAddModal: false,
        showEditModal: false,
        showDeleteModal: false,
        editProduct: {},
        deleteProduct: null,
        
        newProduct: {
            url: '',
            target_price: '',
            tags: ''
        },
        batchUrls: '',
        batchTags: '',
        
        searchQuery: '',
        filterStatus: 'all',
        
        async loadProducts() {
            this.loading = true;
            try {
                const res = await fetch(BASE_PATH + '/api/products.php');
                const data = await res.json();
                if (data.success) {
                    this.products = data.data.products || [];
                }
            } catch (e) {
                this.showToast('加载商品失败', 'error');
            }
            this.loading = false;
        },
        
        async addProduct() {
            if (!this.newProduct.url) {
                this.showToast('请输入商品链接', 'error');
                return;
            }
            
            this.adding = true;
            this.addStatus = '正在解析链接...';
            try {
                const res = await fetch(BASE_PATH + '/api/products.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({...this.newProduct, csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                if (data.success) {
                    const result = data.data;
                    
                    if (result.error_message) {
                        this.showToast(result.error_message, 'warning');
                    } else {
                        this.showToast('添加成功');
                    }
                    
                    this.showAddModal = false;
                    this.newProduct = {url: '', target_price: '', tags: ''};
                    await this.loadProducts();
                    
                    if (result.need_load_image && result.id) {
                        this.loadProductImage(result.id, result.sku_id);
                    }
                } else {
                    this.showToast(data.message || '添加失败', 'error');
                }
            } catch (e) {
                this.showToast('添加失败', 'error');
            }
            this.adding = false;
            this.addStatus = '';
        },
        
        async loadProductImage(productId, skuId) {
            try {
                const res = await fetch(BASE_PATH + `/api/products.php?action=load_image&id=${productId}&sku_id=${skuId}`);
                const data = await res.json();
                if (data.success && data.data.image_url) {
                    const product = this.products.find(p => p.id === productId);
                    if (product) {
                        product.image_url = data.data.image_url;
                    }
                }
            } catch (e) {
                console.log('加载图片失败', e);
            }
        },
        
        async batchAddProducts() {
            if (!this.batchUrls.trim()) {
                this.showToast('请输入商品链接', 'error');
                return;
            }
            
            const urls = this.batchUrls.split('\n').map(u => u.trim()).filter(u => u);
            if (urls.length === 0) {
                this.showToast('请输入商品链接', 'error');
                return;
            }
            
            this.adding = true;
            try {
                const res = await fetch(BASE_PATH + '/api/products.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({urls, tags: this.batchTags, csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast(`成功添加 ${data.data.success.length} 个商品`);
                    if (data.data.failed.length > 0) {
                        console.log('失败的商品:', data.data.failed);
                    }
                    this.showBatchAddModal = false;
                    this.batchUrls = '';
                    this.batchTags = '';
                    await this.loadProducts();
                } else {
                    this.showToast(data.message || '添加失败', 'error');
                }
            } catch (e) {
                this.showToast('添加失败', 'error');
            }
            this.adding = false;
        },
        
        async updateProduct() {
            try {
                const res = await fetch(BASE_PATH + '/api/products.php?id=' + this.editProduct.id, {
                    method: 'PUT',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        ...this.editProduct,
                        notify_price_drop: this.editProduct.notify_price_drop ? 1 : 0,
                        notify_lowest: this.editProduct.notify_lowest ? 1 : 0,
                        notify_oos: this.editProduct.notify_oos ? 1 : 0,
                        csrf_token: this.csrfToken
                    })
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast('保存成功');
                    this.showEditModal = false;
                    await this.loadProducts();
                } else {
                    this.showToast(data.message || '保存失败', 'error');
                }
            } catch (e) {
                this.showToast('保存失败', 'error');
            }
        },
        
        async toggleStatus(product, status) {
            try {
                const res = await fetch(BASE_PATH + '/api/products.php?id=' + product.id, {
                    method: 'PUT',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({status, csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                if (data.success) {
                    product.status = status;
                    this.showToast(status === 'active' ? '已启用监控' : '已暂停监控');
                }
            } catch (e) {
                this.showToast('操作失败', 'error');
            }
        },
        
        confirmDelete(product) {
            this.deleteProduct = product;
            this.showDeleteModal = true;
        },
        
        async deleteProductConfirm() {
            try {
                const res = await fetch(BASE_PATH + '/api/products.php?id=' + this.deleteProduct.id, {
                    method: 'DELETE',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast('删除成功');
                    this.showDeleteModal = false;
                    await this.loadProducts();
                } else {
                    this.showToast(data.message || '删除失败', 'error');
                }
            } catch (e) {
                this.showToast('删除失败', 'error');
            }
        },
        
        async checkPrice(productId) {
            this.checkingProductId = productId;
            try {
                const res = await fetch(BASE_PATH + '/api/check-price.php?id=' + productId);
                const data = await res.json();
                
                if (data.success) {
                    const result = data.data;
                    if (result.new_price !== result.old_price) {
                        const diff = result.new_price - result.old_price;
                        const diffText = diff > 0 ? `上涨¥${diff.toFixed(2)}` : `下降¥${Math.abs(diff).toFixed(2)}`;
                        this.showToast(`价格已更新: ¥${result.new_price} (${diffText})`);
                    } else {
                        this.showToast(`价格无变化: ¥${result.new_price}`);
                    }
                    await this.loadProducts();
                } else {
                    this.showToast(data.message || '刷新失败', 'error');
                }
            } catch (e) {
                this.showToast('刷新失败', 'error');
            }
            this.checkingProductId = null;
        },
        
        async checkAllPrices() {
            this.checking = true;
            try {
                const res = await fetch(BASE_PATH + '/api/check-price.php');
                const data = await res.json();
                
                if (data.success) {
                    this.showToast(`检查完成: ${data.data.checked}个商品`);
                    await this.loadProducts();
                } else {
                    this.showToast(data.message || '刷新失败', 'error');
                }
            } catch (e) {
                this.showToast('刷新失败', 'error');
            }
            this.checking = false;
        }
    };
}
