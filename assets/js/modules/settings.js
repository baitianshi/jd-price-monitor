function createSettingsModule() {
    return {
        settings: {},
        stats: {},
        methodStats: [],
        showSettings: false,
        showCookieHelper: false,
        showMethodStats: false,
        userInfoExpanded: false,
        cookieRaw: '',
        
        settingsForm: {
            jd_cookies: '',
            pt_key: '',
            pt_pin: '',
            webhooks: [],
            silent_start: '23:00',
            silent_end: '07:00',
            default_price_threshold: 5,
            cookie_check_interval: 360,
            history_retention_days: 90,
            price_protection_enabled: false,
            price_protection_interval: 360,
            price_protection_interval_preset: '360'
        },
        
        passwordForm: {
            old_password: '',
            new_password: ''
        },
        
        async loadSettings() {
            try {
                const res = await fetch(BASE_PATH + '/api/settings.php');
                const data = await res.json();
                if (data.success) {
                    this.settings = data.data.settings || {};
                    this.stats = data.data.stats || {};
                    this.methodStats = data.data.method_stats || [];
                    
                    this.settingsForm.jd_cookies = this.settings.jd_cookies || '';
                    
                    const cookies = this.settings.jd_cookies || '';
                    const ptKeyMatch = cookies.match(/pt_key=([^;]+)/);
                    const ptPinMatch = cookies.match(/pt_pin=([^;]+)/);
                    this.settingsForm.pt_key = ptKeyMatch ? ptKeyMatch[1] : '';
                    this.settingsForm.pt_pin = ptPinMatch ? ptPinMatch[1] : '';
                    
                    this.settingsForm.webhooks = this.settings.webhooks_array || [];
                    this.settingsForm.silent_start = this.settings.silent_start || '23:00';
                    this.settingsForm.silent_end = this.settings.silent_end || '07:00';
                    this.settingsForm.default_price_threshold = this.settings.default_price_threshold || 5;
                    this.settingsForm.cookie_check_interval = this.settings.cookie_check_interval || 360;
                    this.settingsForm.history_retention_days = this.settings.history_retention_days || 90;
                    this.settingsForm.price_protection_enabled = this.settings.price_protection_enabled == 1;
                    this.settingsForm.price_protection_interval = this.settings.price_protection_interval || 360;
                    
                    const presets = ['60', '180', '360', '720', '1440'];
                    const interval = String(this.settingsForm.price_protection_interval);
                    this.settingsForm.price_protection_interval_preset = presets.includes(interval) ? interval : 'custom';
                    
                    this.loadPriceProtectionStats();
                }
            } catch (e) {
                console.error('加载设置失败', e);
            }
        },
        
        async saveSettings() {
            try {
                const formData = {...this.settingsForm, csrf_token: this.csrfToken};
                if (formData.pt_key || formData.pt_pin) {
                    formData.jd_cookies = `pt_key=${formData.pt_key};pt_pin=${formData.pt_pin};`;
                }
                
                await fetch(BASE_PATH + '/api/price-protection.php?action=settings', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        enabled: formData.price_protection_enabled ? 1 : 0,
                        interval: formData.price_protection_interval,
                        csrf_token: this.csrfToken
                    })
                });
                
                const res = await fetch(BASE_PATH + '/api/settings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(formData)
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast('设置已保存');
                    await this.loadSettings();
                } else {
                    this.showToast(data.message || '保存失败', 'error');
                }
            } catch (e) {
                this.showToast('保存失败', 'error');
            }
        },
        
        async testWebhook(webhook) {
            if (!webhook.url) {
                this.showToast('请输入Webhook URL', 'error');
                return;
            }
            
            try {
                const res = await fetch(BASE_PATH + '/api/notify.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'test', webhook, csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                webhook.testResult = {
                    success: data.success,
                    message: data.success ? '发送成功' : (data.message || '发送失败')
                };
                
                if (data.success) {
                    this.showToast('Webhook测试成功');
                } else {
                    this.showToast(data.message || '测试失败', 'error');
                }
            } catch (e) {
                webhook.testResult = {success: false, message: '请求失败'};
                this.showToast('测试失败', 'error');
            }
        },
        
        async changePassword() {
            if (!this.passwordForm.old_password || !this.passwordForm.new_password) {
                this.showToast('请填写完整信息', 'error');
                return;
            }
            
            try {
                const res = await fetch(BASE_PATH + '/api/auth.php?action=change-password', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({...this.passwordForm, csrf_token: this.csrfToken})
                });
                const data = await res.json();
                
                if (data.success) {
                    this.showToast('密码修改成功');
                    this.passwordForm = {old_password: '', new_password: ''};
                } else {
                    this.showToast(data.message || '修改失败', 'error');
                }
            } catch (e) {
                this.showToast('修改失败', 'error');
            }
        },
        
        async checkCookieStatus() {
            try {
                await this.loadSettings();
                
                const statusMessages = {
                    'valid': { msg: 'Cookie状态正常', type: 'success' },
                    'invalid': { msg: 'Cookie已失效，请重新登录京东移动端获取', type: 'error' },
                    'not_set': { msg: '请先设置京东Cookie', type: 'info' },
                    'invalid_format': { msg: 'Cookie格式错误，需要包含pt_key和pt_pin', type: 'error' },
                    'unknown': { msg: '无法验证Cookie状态，建议刷新重试', type: 'info' }
                };
                
                const status = statusMessages[this.settings.cookie_status] || { msg: 'Cookie状态未知', type: 'info' };
                this.showToast(status.msg, status.type);
            } catch (e) {
                this.showToast('检查失败', 'error');
            }
        },
        
        // ========== 会员信息相关方法 ==========
        
        jdLevelInfo() {
            const u = this.settings.jd_user;
            if (!u) return null;
            
            const name = u.levelName || '';
            const growth = Number(u.growthValue || 0);
            
            const tiers = [
                { key: 'registered', label: '注册会员', min: 0, icon: 'user', color: '#9CA3AF', bg: '#F3F4F6' },
                { key: 'bronze', label: '铜牌会员', min: 1000, icon: 'medal', color: '#B45309', bg: '#FEF3C7' },
                { key: 'silver', label: '银牌会员', min: 3000, icon: 'medal', color: '#6B7280', bg: '#F3F4F6' },
                { key: 'gold', label: '金牌会员', min: 10000, icon: 'award', color: '#D97706', bg: '#FEF3C7' },
                { key: 'diamond', label: '钻石会员', min: 40000, icon: 'gem', color: '#2563EB', bg: '#DBEAFE' },
                { key: 'crown', label: '皇冠会员', min: 100000, icon: 'crown', color: '#9333EA', bg: '#F3E8FF' }
            ];
            
            let current = tiers.find(t => name.includes(t.label)) || null;
            if (!current) {
                for (let i = tiers.length - 1; i >= 0; i--) {
                    if (growth >= tiers[i].min) { current = tiers[i]; break; }
                }
                current = current || tiers[0];
            }
            
            const idx = tiers.indexOf(current);
            const next = idx < tiers.length - 1 ? tiers[idx + 1] : null;
            let progress = 100;
            if (next) {
                progress = Math.min(100, Math.max(0, ((growth - current.min) / (next.min - current.min)) * 100));
            }
            
            return {
                current,
                next,
                growth,
                progress: Math.round(progress)
            };
        },
        
        jdShareScoreInfo() {
            const s = Number(this.settings.jd_user?.jdShareScore || 0);
            let label = '暂无数据';
            let color = '#9CA3AF';
            
            if (s >= 9000) { label = '顶尖'; color = '#9333EA'; }
            else if (s >= 6000) { label = '卓越'; color = '#2563EB'; }
            else if (s >= 4000) { label = '优秀'; color = '#16A34A'; }
            else if (s >= 2000) { label = '良好'; color = '#D97706'; }
            else if (s >= 500) { label = '一般'; color = '#6B7280'; }
            else if (s >= 200) { label = '入门'; color = '#9CA3AF'; }
            else if (s > 0) { label = '基础'; color = '#9CA3AF'; }
            
            return { score: s, label, color };
        },
        
        toggleUserInfo() {
            this.userInfoExpanded = !this.userInfoExpanded;
            if (this.userInfoExpanded) {
                setTimeout(() => { if (window.lucide) lucide.createIcons(); }, 0);
            }
        },
        
        parseCookie() {
            const raw = (this.cookieRaw || '').trim();
            if (!raw) {
                this.showToast('请先粘贴完整Cookie字符串', 'error');
                return;
            }
            const ptKeyMatch = raw.match(/pt_key=([^;]+)/);
            const ptPinMatch = raw.match(/pt_pin=([^;]+)/);
            if (ptKeyMatch) {
                this.settingsForm.pt_key = ptKeyMatch[1].trim();
            }
            if (ptPinMatch) {
                this.settingsForm.pt_pin = ptPinMatch[1].trim();
            }
            if (!ptKeyMatch && !ptPinMatch) {
                this.showToast('未在Cookie中找到pt_key/pt_pin，请确认复制的是登录后的完整Cookie', 'error');
                return;
            }
            this.showToast('已自动解析并回填pt_key/pt_pin，点击「保存设置」即可生效', 'success');
        }
    };
}
