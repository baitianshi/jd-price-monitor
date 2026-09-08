function createSettingsModule() {
    return {
        settings: {},
        stats: {},
        methodStats: [],
        showSettings: false,
        showCookieHelper: false,
        showMethodStats: false,
        showQrLogin: false,
        qrcodeImage: '',
        qrStatus: 'loading', // loading, waiting, scanned, confirmed, expired, error
        qrMessage: '',
        qrPollTimer: null,
        
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
        
        // ========== 扫码登录相关方法 ==========
        
        openQrLogin() {
            this.showQrLogin = true;
            this.qrStatus = 'loading';
            this.qrMessage = '正在获取二维码...';
            this.qrcodeImage = '';
            this.getQrCode();
        },
        
        closeQrLogin() {
            this.showQrLogin = false;
            if (this.qrPollTimer) {
                clearInterval(this.qrPollTimer);
                this.qrPollTimer = null;
            }
        },
        
        async getQrCode() {
            try {
                const res = await fetch(BASE_PATH + '/api/jd-qrcode.php?action=get_qrcode');
                const data = await res.json();
                
                if (data.success) {
                    this.qrcodeImage = data.data.qrcode;
                    this.qrStatus = 'waiting';
                    this.qrMessage = '请使用京东APP扫描二维码';
                    this.startQrPolling();
                } else {
                    this.qrStatus = 'error';
                    this.qrMessage = data.message || '获取二维码失败';
                }
            } catch (e) {
                this.qrStatus = 'error';
                this.qrMessage = '网络错误，请重试';
            }
        },
        
        startQrPolling() {
            if (this.qrPollTimer) {
                clearInterval(this.qrPollTimer);
            }
            
            let pollCount = 0;
            const maxPolls = 90; // 最多轮询90次（约3分钟）
            
            this.qrPollTimer = setInterval(async () => {
                pollCount++;
                
                if (pollCount > maxPolls) {
                    clearInterval(this.qrPollTimer);
                    this.qrPollTimer = null;
                    this.qrStatus = 'expired';
                    this.qrMessage = '二维码已过期，请点击刷新';
                    return;
                }
                
                try {
                    const res = await fetch(BASE_PATH + '/api/jd-qrcode.php?action=check_status');
                    const data = await res.json();
                    
                    if (data.success) {
                        const status = data.data.status;
                        
                        switch (status) {
                            case 'waiting':
                                // 等待扫描，继续轮询
                                break;
                            case 'scanned':
                                this.qrStatus = 'scanned';
                                this.qrMessage = '扫描成功，请在手机上确认登录';
                                break;
                            case 'confirmed':
                                clearInterval(this.qrPollTimer);
                                this.qrPollTimer = null;
                                this.qrStatus = 'confirmed';
                                this.qrMessage = '登录成功，正在获取Cookie...';
                                await this.verifyQrTicket();
                                break;
                            case 'expired':
                                clearInterval(this.qrPollTimer);
                                this.qrPollTimer = null;
                                this.qrStatus = 'expired';
                                this.qrMessage = '二维码已过期，请点击刷新';
                                break;
                            default:
                                this.qrMessage = data.data.message || '状态未知';
                        }
                    }
                } catch (e) {
                    console.error('轮询扫码状态失败', e);
                }
            }, 2000); // 每2秒轮询一次
        },
        
        async verifyQrTicket() {
            try {
                const res = await fetch(BASE_PATH + '/api/jd-qrcode.php?action=verify_ticket', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({})
                });
                const data = await res.json();
                
                if (data.success) {
                    this.qrMessage = '登录成功！正在刷新设置...';
                    this.showToast('京东登录成功：' + (data.data.username || ''), 'success');
                    
                    // 关闭弹窗并刷新设置
                    setTimeout(() => {
                        this.closeQrLogin();
                        this.loadSettings();
                    }, 1000);
                } else {
                    this.qrStatus = 'error';
                    this.qrMessage = data.message || '验证登录失败';
                }
            } catch (e) {
                this.qrStatus = 'error';
                this.qrMessage = '验证登录失败，请重试或手动输入Cookie';
            }
        },
        
        refreshQrCode() {
            if (this.qrPollTimer) {
                clearInterval(this.qrPollTimer);
                this.qrPollTimer = null;
            }
            this.getQrCode();
        }
    };
}
