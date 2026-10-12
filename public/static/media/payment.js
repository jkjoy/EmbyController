(function () {
    let timer = null;
    let orderId = null;
    let pending = null;
    let generation = 0;
    window.PaymentUI = {
        renderQRCode(selector, url, method) {
            const valid = typeof url === 'string' && (method === 'alipay' ? /^https:\/\//.test(url) : method === 'wxpay' && /^weixin:\/\//.test(url));
            if (!valid) {
                rStatusMessage.error('支付二维码无效，请重新发起充值');
                return false;
            }
            const container = document.querySelector(selector);
            if (!container) return false;
            const image = document.createElement('img');
            image.src = '/api/common/getqrcode?data=' + encodeURIComponent(url);
            image.alt = method === 'alipay' ? '支付宝支付二维码' : '微信支付二维码';
            image.className = 'w-full max-w-xs mx-auto';
            container.replaceChildren(image);
            return true;
        },
        startPolling(id) {
            this.stopPolling();
            orderId = id;
            const current = generation;
            const started = Date.now();
            timer = setInterval(() => {
                if (Date.now() - started > 1800000) return this.stopPolling();
                if (pending) return;
                pending = $.ajax({url: '/finance/checkPay', type: 'POST', data: {id: id}, dataType: 'json', timeout: 20000, success: data => {
                    if (generation === current && orderId === id && data.paid === true) {
                        this.stopPolling();
                        rStatusMessage.success('支付成功，充值已到账');
                        setTimeout(() => location.reload(), 800);
                    }
                }}).always(() => { if (generation === current) pending = null; });
            }, 5000);
        },
        stopPolling() {
            if (timer !== null) clearInterval(timer);
            timer = null;
            orderId = null;
            generation++;
            if (pending) pending.abort();
            pending = null;
        }
    };
    window.addEventListener('beforeunload', () => PaymentUI.stopPolling());
})();
