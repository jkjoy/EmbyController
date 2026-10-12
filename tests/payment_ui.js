// node tests/payment_ui.js: QR URL encoding and polling lifecycle, no browser/network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const timers = new Map();
const requests = [];
let nextId = 0;
let successes = 0;
let reloads = 0;
const container = {replaceChildren(image) { this.image = image; }};
const context = {
    document: {querySelector() { return container; }, createElement() { return {}; }},
    rStatusMessage: {error() {}, success() { successes++; }},
    location: {reload() { reloads++; }},
    setInterval(callback) { const id = ++nextId; timers.set(id, callback); return id; },
    clearInterval(id) { timers.delete(id); },
    setTimeout(callback) { callback(); },
    Date,
    $: {ajax(options) {
        const request = {options, complete: null, aborted: false,
            always(callback) { this.complete = callback; return this; },
            abort() { this.aborted = true; this.complete(); }};
        requests.push(request);
        return request;
    }}
};
context.window = context;
context.addEventListener = () => {};
vm.runInNewContext(fs.readFileSync(require.resolve('../public/static/media/payment.js'), 'utf8'), context);
const ui = context.PaymentUI;
assert.equal(ui.renderQRCode('.qr', 'javascript:alert(1)', 'alipay'), false);
assert.equal(ui.renderQRCode('.qr', 'https://example.test', 'wxpay'), false);
const url = 'weixin://wxpay/bizpayurl?pr=fixture&another=1';
assert.equal(ui.renderQRCode('.qr', url, 'wxpay'), true);
assert.equal(new URL('https://example.test' + container.image.src).searchParams.get('data'), url);
ui.startPolling(1);
const tick = () => [...timers.values()][0]();
tick(); tick();
assert.equal(requests.length, 1, 'Only one status request may be in flight');
ui.startPolling(2);
assert.equal(requests[0].aborted, true, 'Switching orders must abort old request');
requests[0].options.success({paid: true});
assert.equal(successes, 0, 'Stale paid response must not affect the new order');
tick();
requests[1].options.success({paid: false}); requests[1].complete();
tick();
assert.equal(requests.length, 3, 'Unpaid response must permit another check');
requests[2].options.success({paid: true});
assert.equal(successes, 1); assert.equal(reloads, 1); assert.equal(timers.size, 0);
ui.startPolling(3); tick(); ui.stopPolling();
requests[3].options.success({paid: true});
assert.equal(successes, 1, 'Closed payment dialog must ignore its old response');
console.log('PASS: payment QR encoding; single in-flight request; order changes/close abort polling; verified paid response reloads once');
