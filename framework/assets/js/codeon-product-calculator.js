/*
 * CodeOn product-page monthly-payment widget (ProductCalculator).
 *
 * Vanilla JS; jQuery is used only, when present, to hear WooCommerce's
 * variation events. Every [data-codeon-pc] element carries its settings
 * as JSON (price, range, rate, terms or number of parts, separators):
 *
 *  - term buttons (card) and the slider (calculator) change the term;
 *  - picking a variation re-prices every widget of that product, and
 *    hides it while the price is outside the bank's range;
 *  - schedule dates are re-made in the browser only when the page was
 *    cached on another day.
 *
 * The maths mirrors PHP InstallmentEstimator::monthlyPayment().
 */
(function () {
    'use strict';

    var widgets = [];
    /** product id → price of the chosen variation (null: none chosen). */
    var chosen = {};

    function monthlyPayment(principal, months, aprPct) {
        if (principal <= 0 || months <= 0) {
            return 0;
        }
        var r = (aprPct / 100) / 12;
        if (r <= 0) {
            return principal / months;
        }
        var factor = Math.pow(1 + r, months);
        return principal * r * factor / (factor - 1);
    }

    function money(value, cfg) {
        var fixed = (Math.round(value * 100) / 100).toFixed(2);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thou);
        return parts[0] + cfg.dec + parts[1];
    }

    function setText(root, selector, text) {
        var nodes = root.querySelectorAll(selector);
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].textContent = text;
        }
    }

    function inRange(cfg, price) {
        if (!(price > 0)) {
            return false;
        }
        if (cfg.rmin > 0 && price < cfg.rmin) {
            return false;
        }
        return !(cfg.rmax > 0 && price > cfg.rmax);
    }

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    /** Same day `months` later; the month's last day when it is shorter. */
    function addMonths(from, months) {
        var first = new Date(from.getFullYear(), from.getMonth() + months, 1);
        var last = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
        return new Date(first.getFullYear(), first.getMonth(), Math.min(from.getDate(), last));
    }

    function refreshDates(w) {
        if (w.cfg.kind !== 'split' || !w.cfg.today) {
            return;
        }
        var now = new Date();
        var today = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
        if (today === w.cfg.today) {
            return;
        }
        var nodes = w.el.querySelectorAll('[data-pc-date]');
        if (!nodes.length || typeof Intl === 'undefined') {
            return;
        }
        var format;
        try {
            format = new Intl.DateTimeFormat((document.documentElement.lang || 'en').replace('_', '-'), { day: 'numeric', month: 'long' });
        } catch (e) {
            return;
        }
        for (var i = 0; i < nodes.length; i++) {
            var months = parseInt(nodes[i].getAttribute('data-pc-date'), 10);
            if (months > 0) {
                nodes[i].textContent = format.format(addMonths(now, months));
            }
        }
    }

    function render(w) {
        var cfg = w.cfg;
        var el = w.el;
        // A loan's fee counts toward the bank's limits (mult is 1 otherwise).
        if (!inRange(cfg, w.price * cfg.mult)) {
            el.hidden = true;
            return;
        }
        el.hidden = false;

        if (cfg.kind === 'split') {
            // Whole tetri that add up to the price; the first payment takes the rest.
            var cents = Math.round(w.price * 100);
            var rest = Math.floor(cents / cfg.parts);
            setText(el, '[data-pc-part]', money(rest / 100, cfg));
            setText(el, '[data-pc-first]', money((cents - rest * (cfg.parts - 1)) / 100, cfg));
            setText(el, '[data-pc-total]', money(w.price, cfg));
            return;
        }

        setText(el, '[data-pc-amount]', money(monthlyPayment(w.price * cfg.mult, w.term, cfg.apr), cfg));
        setText(el, '[data-pc-term-value]', String(w.term));

        var i;
        for (i = 0; i < w.chips.length; i++) {
            var on = parseInt(w.chips[i].getAttribute('data-pc-term'), 10) === w.term;
            w.chips[i].classList.toggle('is-active', on);
            w.chips[i].setAttribute('aria-pressed', on ? 'true' : 'false');
        }
        for (i = 0; i < w.marks.length; i++) {
            w.marks[i].classList.toggle('is-active', parseInt(w.marks[i].getAttribute('data-pc-mark'), 10) === w.term);
        }
        if (w.range) {
            var index = cfg.terms.indexOf(w.term);
            var span = cfg.terms.length - 1;
            if (index >= 0 && parseInt(w.range.value, 10) !== index) {
                w.range.value = String(index);
            }
            w.range.setAttribute('aria-valuetext', String(cfg.monthsText || '%d').replace('%d', String(w.term)));
            w.range.style.setProperty('--codeon-pc-fill', (span > 0 ? (Math.max(index, 0) / span) * 100 : 50) + '%');
        }
    }

    function init(el) {
        if (el.getAttribute('data-codeon-pc-ready') === '1') {
            return;
        }
        var cfg;
        try {
            cfg = JSON.parse(el.getAttribute('data-codeon-pc') || '');
        } catch (e) {
            return;
        }
        if (!cfg || typeof cfg !== 'object') {
            return;
        }
        el.setAttribute('data-codeon-pc-ready', '1');

        cfg.price = parseFloat(cfg.price) || 0;
        cfg.rmin = parseFloat(cfg.rmin) || 0;
        cfg.rmax = parseFloat(cfg.rmax) || 0;
        cfg.apr = parseFloat(cfg.apr) || 0;
        cfg.mult = parseFloat(cfg.mult) || 1;
        cfg.parts = parseInt(cfg.parts, 10) || 4;
        cfg.dec = typeof cfg.dec === 'string' ? cfg.dec : '.';
        cfg.thou = typeof cfg.thou === 'string' ? cfg.thou : ',';
        cfg.terms = Array.isArray(cfg.terms) ? cfg.terms.map(function (t) { return parseInt(t, 10); }) : [];

        var w = {
            el: el,
            cfg: cfg,
            price: cfg.price,
            term: parseInt(cfg.term, 10) || cfg.terms[0] || 0,
            chips: el.querySelectorAll('[data-pc-term]'),
            marks: el.querySelectorAll('[data-pc-mark]'),
            range: el.querySelector('[data-pc-range]')
        };
        if (typeof chosen[cfg.product] === 'number') {
            w.price = chosen[cfg.product];
        }
        widgets.push(w);

        for (var i = 0; i < w.chips.length; i++) {
            w.chips[i].addEventListener('click', function (event) {
                event.preventDefault();
                var term = parseInt(this.getAttribute('data-pc-term'), 10);
                if (term > 0) {
                    w.term = term;
                    render(w);
                }
            });
        }
        if (w.range) {
            var onSlide = function () {
                var term = cfg.terms[parseInt(w.range.value, 10)];
                if (term > 0 && term !== w.term) {
                    w.term = term;
                    render(w);
                }
            };
            w.range.addEventListener('input', onSlide);
            w.range.addEventListener('change', onSlide);
        }

        refreshDates(w);
        render(w);
    }

    function initAll() {
        var nodes = document.querySelectorAll('[data-codeon-pc]');
        for (var i = 0; i < nodes.length; i++) {
            init(nodes[i]);
        }
    }

    /** Re-price the widgets of one product; null goes back to its own price. */
    function priceProduct(productId, price) {
        if (!productId) {
            return;
        }
        initAll();
        chosen[productId] = price;
        for (var i = 0; i < widgets.length; i++) {
            if (widgets[i].cfg.product === productId) {
                widgets[i].price = price === null ? widgets[i].cfg.price : price;
                render(widgets[i]);
            }
        }
    }

    // The widgets are already in the page when this footer script runs.
    initAll();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    }
    document.addEventListener('codeon-product-calculator:rescan', initAll);

    if (typeof window.jQuery === 'function') {
        var $ = window.jQuery;
        $(document).on('found_variation', 'form.variations_form', function (event, variation) {
            var price = variation ? parseFloat(variation.display_price) : NaN;
            priceProduct(parseInt($(this).attr('data-product_id'), 10), price > 0 ? price : null);
        });
        $(document).on('reset_data', 'form.variations_form', function () {
            priceProduct(parseInt($(this).attr('data-product_id'), 10), null);
        });
        $(document).on('wc_variation_form', 'form.variations_form', initAll);
    }
})();
