(function () {
    'use strict';
    const form = document.getElementById('lotteryForm');
    if (!form) return;
    const container = document.getElementById('prizes-container');
    const inputClass = 'w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500';
    let nextIndex = 0;

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function field(label, name, value, type = 'text', maxLength) {
        const wrapper = element('div', 'space-y-2');
        wrapper.appendChild(element('label', 'text-sm font-medium', label));
        const input = element('input', inputClass);
        input.type = type;
        input.name = name;
        input.required = true;
        input.value = value;
        if (maxLength) input.maxLength = maxLength;
        wrapper.appendChild(input);
        return wrapper;
    }

    function updatePrizeContents(input) {
        const count = Number(input.value);
        const valid = Number.isInteger(count) && count >= 1 && count <= 1000;
        input.setCustomValidity(valid ? '' : '奖品数量必须是1至1000的整数');
        if (!valid) return;
        const item = input.closest('.prize-item');
        const contents = item.querySelector('.prize-contents');
        while (contents.children.length > count) contents.lastChild.remove();
        while (contents.children.length < count) {
            const index = contents.children.length;
            const wrapper = field('奖品内容 #' + (index + 1), 'prizes[' + item.dataset.index + '][contents][]', '', 'text', 3000);
            contents.appendChild(wrapper);
        }
    }

    function addPrize(prize = {}) {
        if (container.children.length >= 100) {
            rStatusMessage.error('奖项不能超过100个');
            return;
        }
        const item = element('div', 'prize-item space-y-4 p-4 bg-white/5 rounded-lg');
        item.dataset.index = String(nextIndex++);
        const grid = element('div', 'grid grid-cols-1 sm:grid-cols-2 gap-4');
        grid.appendChild(field('奖品名称', 'prizes[' + item.dataset.index + '][name]', prize.name || '', 'text', 100));
        const quantity = field('奖品数量', 'prizes[' + item.dataset.index + '][count]', prize.count || 1, 'number');
        const count = quantity.querySelector('input');
        count.min = '1';
        count.max = '1000';
        count.step = '1';
        count.addEventListener('change', () => updatePrizeContents(count));
        const remove = element('button', 'px-4 py-2 bg-red-500 hover:bg-red-600 rounded-lg transition-colors', '删除');
        remove.type = 'button';
        remove.addEventListener('click', () => item.remove());
        quantity.appendChild(remove);
        grid.appendChild(quantity);
        item.appendChild(grid);
        item.appendChild(element('div', 'prize-contents space-y-2'));
        container.appendChild(item);
        updatePrizeContents(count);
        item.querySelectorAll('.prize-contents input').forEach((input, index) => {
            input.value = (prize.contents || [])[index] ?? '';
        });
    }
    window.addPrize = addPrize;

    const initial = document.getElementById('lottery-prizes');
    const prizes = initial ? JSON.parse(initial.textContent) : [];
    if (Array.isArray(prizes) && prizes.length) prizes.forEach(addPrize);
    else addPrize();
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('drawTime').min = now.toISOString().slice(0, 16);

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        container.querySelectorAll('input[type="number"]').forEach(updatePrizeContents);
        if (!form.reportValidity()) return;
        if (!container.children.length) {
            rStatusMessage.error('请至少添加一个奖品');
            return;
        }
        const prizes = Array.from(container.children).map(item => ({
            name: item.querySelector('input[name$="[name]"]').value,
            count: Number(item.querySelector('input[name$="[count]"]').value),
            contents: Array.from(item.querySelectorAll('.prize-contents input'), input => input.value)
        }));
        if (prizes.reduce((total, prize) => total + prize.count, 0) > 1000) {
            rStatusMessage.error('单次抽奖奖品总数不能超过1000份');
            return;
        }
        const data = new FormData(form);
        const body = new URLSearchParams();
        ['id', 'title', 'description', 'keywords', 'chatId'].forEach(name => {
            if (data.has(name)) body.set(name, data.get(name));
        });
        body.set('drawTime', data.get('drawTime').replace('T', ' ') + ':00');
        body.set('prizes', JSON.stringify(prizes));
        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            const response = await fetch(form.dataset.submitUrl, { method: 'POST', body });
            const result = await response.json();
            if (result.code !== 200) {
                rStatusMessage.error(result.msg || '保存失败，请稍后重试');
                return;
            }
            rStatusMessage.success(result.msg);
            setTimeout(() => { window.location.href = form.dataset.returnUrl; }, 1000);
        } catch (error) {
            rStatusMessage.error('保存失败，请检查网络或重新登录后重试');
        } finally {
            submit.disabled = false;
        }
    });
}());
