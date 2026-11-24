(function (wp) {
    const targets = document.querySelectorAll('[data-codeon-shipping-progress]');
    if (!targets.length || !wp.data) return;
    let previous = '';
    function render() {
        const store = wp.data.select('wc/store/cart');
        const notices = store?.getCartData()?.extensions?.['codeon-shipping-rules']?.notices;
        if (!Array.isArray(notices)) return;
        const state = JSON.stringify(notices);
        if (state === previous) return;
        previous = state;
        targets.forEach(function (target) {
            target.replaceChildren(...notices.map(function (notice) {
                const item = document.createElement('div');
                const label = document.createElement('strong');
                const message = document.createElement('p');
                const progress = document.createElement('progress');
                item.className = 'codeon-shipping-progress-item';
                label.textContent = notice.label;
                message.textContent = notice.message;
                progress.max = 100;
                progress.value = notice.percent;
                progress.setAttribute('aria-label', notice.message);
                item.append(label, message, progress);
                return item;
            }));
        });
    }
    wp.data.subscribe(render, 'wc/store/cart');
    render();
})(window.wp);
