/* global wp, codeonShippingRules */
(() => {
    'use strict';

    const root = document.querySelector('[data-csr-view]');
    if (!root || !window.codeonShippingRules || !window.wp?.i18n) {
        return;
    }

    const { __, _n, sprintf } = wp.i18n;
    const settings = codeonShippingRules;
    const currentUrl = new URL(location.href);
    const text = {
        methodUnavailable: __('Choose a shipping method.', 'codeon-shipping-rules'),
        loading: __('Loading…', 'codeon-shipping-rules'),
        unsaved: __('You have unsaved changes.', 'codeon-shipping-rules'),
        readFailed: __('The request failed. Refresh the page and try again.', 'codeon-shipping-rules'),
        none: __('None', 'codeon-shipping-rules'),
        rule: __('Rule', 'codeon-shipping-rules'),
        remove: __('Remove', 'codeon-shipping-rules'),
        continue: __('Continue', 'codeon-shipping-rules'),
        search: __('Search', 'codeon-shipping-rules'),
        amount: __('Amount', 'codeon-shipping-rules'),
        noMethods: __('Create a shipping method or select an existing method in a WooCommerce zone.', 'codeon-shipping-rules'),
        saving: __('Saving…', 'codeon-shipping-rules'),
    };
    const rangeLabels = {
        price: __('Merchandise value', 'codeon-shipping-rules'),
        gross_price: __('Merchandise value including tax', 'codeon-shipping-rules'),
        price_before_discount: __('Value before discounts', 'codeon-shipping-rules'),
        gross_before_discount: __('Value with tax before discounts', 'codeon-shipping-rules'),
        weight: __('Weight', 'codeon-shipping-rules'),
        quantity: __('Item quantity', 'codeon-shipping-rules'),
        line_count: __('Cart lines', 'codeon-shipping-rules'),
        volume: __('Volume', 'codeon-shipping-rules'),
        dimensional_weight: __('Dimensional weight', 'codeon-shipping-rules'),
        max_dimension: __('Longest edge', 'codeon-shipping-rules'),
        total_dimensions: __('Sum of dimensions', 'codeon-shipping-rules'),
        length: __('Length', 'codeon-shipping-rules'),
        width: __('Width', 'codeon-shipping-rules'),
        height: __('Height', 'codeon-shipping-rules'),
        stock_quantity: __('Stock quantity', 'codeon-shipping-rules'),
        product_field_range: __('Product field range', 'codeon-shipping-rules'),
        running_cost: __('Shipping cost so far', 'codeon-shipping-rules'),
    };
    const selectionLabels = {
        product: __('Product', 'codeon-shipping-rules'),
        variation: __('Variation', 'codeon-shipping-rules'),
        sku: __('Product SKU', 'codeon-shipping-rules'),
        category: __('Product category', 'codeon-shipping-rules'),
        tag: __('Product tag', 'codeon-shipping-rules'),
        shipping_class: __('Shipping class', 'codeon-shipping-rules'),
        stock_status: __('Stock status', 'codeon-shipping-rules'),
        product_field: __('Product field value', 'codeon-shipping-rules'),
        role: __('Customer role', 'codeon-shipping-rules'),
        weekday: __('Day of the week', 'codeon-shipping-rules'),
        country: __('Country code', 'codeon-shipping-rules'),
        state: __('State or region code', 'codeon-shipping-rules'),
        city: __('City', 'codeon-shipping-rules'),
        postcode: __('Postcode', 'codeon-shipping-rules'),
        municipality: __('Municipality', 'codeon-shipping-rules'),
        settlement: __('Settlement', 'codeon-shipping-rules'),
        currency: __('Currency code', 'codeon-shipping-rules'),
    };
    const conditionGroups = [
        [__('General', 'codeon-shipping-rules'), { always: __('Always', 'codeon-shipping-rules') }],
        [__('Amounts and measurements', 'codeon-shipping-rules'), rangeLabels],
        [__('Products, customers, and destinations', 'codeon-shipping-rules'), selectionLabels],
        [__('Schedule', 'codeon-shipping-rules'), { time: __('Time of day', 'codeon-shipping-rules') }],
    ];
    const freeModes = {
        none: __('Off', 'codeon-shipping-rules'),
        amount: __('Minimum merchandise value', 'codeon-shipping-rules'),
        quantity: __('Minimum item quantity', 'codeon-shipping-rules'),
        coupon: __('Eligible free-shipping coupon', 'codeon-shipping-rules'),
        amount_or_coupon: __('Minimum value or eligible coupon', 'codeon-shipping-rules'),
        amount_and_coupon: __('Minimum value and eligible coupon', 'codeon-shipping-rules'),
    };
    const scopes = {
        package: __('Current package', 'codeon-shipping-rules'),
        cart: __('Whole cart', 'codeon-shipping-rules'),
    };
    const labelFormats = {
        replace: __('Instead of the method title', 'codeon-shipping-rules'),
        append: __('After the method title, in brackets', 'codeon-shipping-rules'),
    };
    const actions = {
        continue: text.continue,
        stop: __('Apply the charge and stop', 'codeon-shipping-rules'),
        hide: __('Hide this shipping rate', 'codeon-shipping-rules'),
    };
    const weekdays = {
        1: __('Monday', 'codeon-shipping-rules'), 2: __('Tuesday', 'codeon-shipping-rules'),
        3: __('Wednesday', 'codeon-shipping-rules'), 4: __('Thursday', 'codeon-shipping-rules'),
        5: __('Friday', 'codeon-shipping-rules'), 6: __('Saturday', 'codeon-shipping-rules'),
        7: __('Sunday', 'codeon-shipping-rules'),
    };
    const chargeBases = Object.fromEntries([
        'price', 'gross_price', 'price_before_discount', 'gross_before_discount', 'weight',
        'quantity', 'line_count', 'volume', 'dimensional_weight', 'running_cost',
    ].map(key => [key, rangeLabels[key]]));
    const model = { selected: Number(currentUrl.searchParams.get('method')) || 0, methods: [], zones: [], dirty: false };
    const message = node('div', { class: 'csr-message', 'aria-live': 'polite', tabindex: '-1' });
    let fieldId = 0;

    function node(tag, attrs, ...children) {
        const element = document.createElement(tag);
        for (const [name, value] of Object.entries(attrs ?? {})) {
            if (name.startsWith('on') && typeof value === 'function') {
                element.addEventListener(name.slice(2), value);
            } else if (value !== false && value != null) {
                element.setAttribute(name, value === true ? '' : String(value));
            }
        }
        for (const child of children.flat()) {
            if (child instanceof Node) {
                element.append(child);
            } else if (child != null) {
                element.append(document.createTextNode(String(child)));
            }
        }
        return element;
    }

    function button(label, callback, options = {}) {
        return node('button', { type: 'button', class: options.primary ? 'button button-primary' : 'button',
            onclick: callback, disabled: options.disabled, 'aria-label': options.aria }, label);
    }

    function input(value, callback, attributes = {}) {
        const element = node('input', { type: 'text', ...attributes });
        if (value != null) {
            element.value = value;
        }
        if (callback) {
            element.addEventListener('input', () => callback(element.value));
        }
        return element;
    }

    function select(value, options, callback, groups = null) {
        const select = node('select');
        const append = (parent, rows) => {
            for (const [key, label] of Object.entries(rows)) {
                parent.append(node('option', { value: key }, label));
            }
        };
        if (groups) {
            for (const [label, rows] of groups) {
                const group = node('optgroup', { label });
                append(group, rows);
                select.append(group);
            }
        } else {
            append(select, options);
        }
        select.value = value ?? '';
        // Long localized options can be cut off by the column width; the tooltip keeps the full choice readable.
        const syncTitle = () => {
            select.title = select.multiple ? '' : select.selectedOptions[0]?.textContent || '';
        };
        syncTitle();
        select.addEventListener('change', syncTitle);
        if (callback) {
            select.addEventListener('change', () => callback(select.value));
        }
        return select;
    }

    // Shows a unit such as kg or ₾ inside the control instead of as a note below it.
    function withUnit(control, unit) {
        const suffix = node('span', { class: 'csr-affix-unit', id: 'csr-unit-' + ++fieldId }, unit || '');
        control.setAttribute('aria-describedby', suffix.id);
        return node('div', { class: 'csr-affix' }, control, suffix);
    }

    function field(label, control, help = '', full = false) {
        const id = 'csr-control-' + ++fieldId;
        const target = control.matches('.csr-affix, .csr-file') ? control.firstElementChild : control;
        target.id = id;
        const wrapper = node('div', { class: 'csr-field' + (full ? ' csr-field-full' : '') },
            node('label', { for: id }, label), control);
        if (help) {
            const described = target.getAttribute('aria-describedby');
            target.setAttribute('aria-describedby', (described ? described + ' ' : '') + id + '-help');
            wrapper.append(node('small', { id: id + '-help' }, help));
        }
        return wrapper;
    }

    function checkbox(label, checked, callback) {
        return node('label', { class: 'csr-check' }, node('input', {
            type: 'checkbox', checked: Boolean(checked), onchange: event => callback(event.target.checked),
        }), label);
    }

    function decimal(value, callback, attrs = {}) {
        return input(value, callback, { inputmode: 'decimal', ...attrs });
    }

    function heading(title, help = '', trailing = null) {
        return node('div', { class: 'csr-intro' }, node('div', { class: 'csr-stack', style: 'gap: var(--codeon-space-1)' },
            node('h2', {}, title), help ? node('p', { class: 'csr-help' }, help) : null), trailing);
    }

    function section(...children) {
        return node('section', { class: 'codeon-section csr-stack' }, children);
    }

    function empty(title, description, action = null) {
        return node('div', { class: 'csr-empty' }, node('strong', {}, title), node('p', {}, description), action);
    }

    function flash(value, tone = 'ok', focus = false) {
        message.dataset.tone = tone;
        message.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        message.textContent = value;
        if (value && focus) {
            message.focus({ preventScroll: true });
            message.scrollIntoView({ block: 'nearest' });
        }
    }

    function query(tab, id = null) {
        const next = new URL(settings.pageUrl);
        next.searchParams.set('tab', tab);
        if (id) {
            next.searchParams.set('method', id);
        }
        return next.href;
    }

    function methodTitle(method) {
        return method.title || sprintf(__('Method %d', 'codeon-shipping-rules'), method.id);
    }

    function currency(value, currencyCode = settings.currency) {
        if (value == null || value === '' || !Number.isFinite(Number(value))) {
            return '—';
        }
        // Keep the decimals a merchant entered (6.2) even when the shop rounds prices to whole units.
        const entered = String(value).split('.')[1]?.replace(/0+$/, '').length || 0;
        const digits = Math.min(4, Math.max(settings.digits ?? 2, entered));
        const number = Number(value);
        if (currencyCode && currencyCode === settings.currency && settings.currencySymbol) {
            // Match WooCommerce's own price format: symbol, position and separators.
            const [whole, fraction] = Math.abs(number).toFixed(digits).split('.');
            const amount = whole.replace(/\B(?=(\d{3})+(?!\d))/g, settings.thousandSeparator ?? ',')
                + (fraction ? (settings.decimalSeparator ?? '.') + fraction : '');
            const symbol = settings.currencySymbol;
            const formatted = {
                left: symbol + amount,
                right: amount + symbol,
                left_space: symbol + ' ' + amount,
                right_space: amount + ' ' + symbol,
            }[settings.currencyPosition] ?? symbol + amount;
            return (number < 0 ? '−' : '') + formatted;
        }
        try {
            return new Intl.NumberFormat(settings.locale || undefined, {
                style: 'currency', currency: currencyCode || 'USD',
                minimumFractionDigits: digits, maximumFractionDigits: digits,
            }).format(number);
        } catch {
            return String(value) + ' ' + currencyCode;
        }
    }

    function copy(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function issueText(issue) {
        return typeof issue === 'string' ? issue : String(issue?.message || issue?.reason || '');
    }

    async function request(path, method = 'GET', body = null, signal = undefined) {
        const url = new URL(settings.apiUrl);
        if (url.searchParams.has('rest_route')) {
            const route = url.searchParams.get('rest_route').replace(/\/$/, '');
            url.searchParams.set('rest_route', route + path.split('?')[0]);
            for (const [key, value] of new URLSearchParams(path.split('?')[1] || '')) {
                url.searchParams.set(key, value);
            }
        } else {
            const [pathname, search = ''] = path.split('?');
            url.pathname = url.pathname.replace(/\/$/, '') + pathname;
            for (const [key, value] of new URLSearchParams(search)) {
                url.searchParams.set(key, value);
            }
        }
        const headers = { 'X-WP-Nonce': settings.nonce, Accept: 'application/json' };
        if (body !== null) {
            headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(url, {
            method, headers, credentials: 'same-origin', body: body === null ? null : JSON.stringify(body), signal,
        });
        const responseNonce = response.headers.get('x-wp-nonce');
        if (responseNonce) {
            settings.nonce = responseNonce;
        }
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            const issues = Array.isArray(result?.data?.issues) ? result.data.issues.map(issueText).filter(Boolean) : [];
            throw new Error([result?.message || text.readFailed, ...issues].join(' '));
        }
        return result;
    }

    async function busy(control, callback) {
        if (control.disabled) {
            return;
        }
        control.disabled = true;
        control.setAttribute('aria-busy', 'true');
        flash('');
        try {
            await callback();
        } catch (error) {
            flash(error.message || text.readFailed, 'error', true);
        } finally {
            if (control.isConnected) {
                control.disabled = false;
                control.removeAttribute('aria-busy');
            }
        }
    }

    function markDirty() {
        model.dirty = true;
        const status = root.querySelector('[data-csr-draft-state]');
        if (status) {
            status.textContent = text.unsaved;
        }
    }

    function change(target, key) {
        return value => {
            target[key] = value;
            markDirty();
        };
    }

    function sendToHistory() {
        const target = new URL(query('methods', model.selected));
        history.replaceState(null, '', target.href);
    }

    function onUnload(event) {
        if (model.dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    function captionForMethod(method) {
        const title = methodTitle(method);
        return method.zone ? title + ' — ' + method.zone : title;
    }

    function productSearch(onSelect) {
        const list = node('ul', { class: 'csr-results csr-plain-list', hidden: true });
        const status = node('span', { class: 'csr-help', role: 'status' });
        let timeout, controller;
        const search = input('', value => {
            clearTimeout(timeout);
            controller?.abort();
            list.replaceChildren();
            list.hidden = true;
            if (value.trim().length < 2) {
                status.textContent = '';
                return;
            }
            status.textContent = text.loading;
            timeout = setTimeout(async () => {
                controller = new AbortController();
                try {
                    const { products = [] } = await request('/products?search=' + encodeURIComponent(value), 'GET', null, controller.signal);
                    status.textContent = products.length ? '' : __('No matching products.', 'codeon-shipping-rules');
                    for (const product of products) {
                        const label = product.name + (product.sku ? ' (' + product.sku + ')' : '');
                        list.append(node('li', {}, node('button', {
                            type: 'button', class: 'button-link', onclick: () => {
                                onSelect(product);
                                search.value = '';
                                list.hidden = true;
                                status.textContent = '';
                                if (search.isConnected) {
                                    search.focus();
                                }
                            },
                        }, label)));
                    }
                    list.hidden = products.length === 0;
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        status.textContent = error.message;
                    }
                }
            }, 300);
        }, { type: 'search', placeholder: __('Find by name or SKU', 'codeon-shipping-rules'),
            'aria-label': __('Search products', 'codeon-shipping-rules') });
        return node('div', { class: 'csr-search-product' }, search, status, list);
    }

    function enabled(method) {
        return method.enabled === true || method.enabled === 'yes' || method.enabled === 1;
    }

    function statusPill(method) {
        const active = enabled(method);
        return node('span', { class: 'codeon-pill codeon-tone-' + (active ? 'ok' : 'muted') },
            active ? __('Enabled', 'codeon-shipping-rules') : __('Disabled', 'codeon-shipping-rules'));
    }

    function defaultConfig(method) {
        return {
            title: method?.title || '',
            description: '',
            delivery_time: '',
            logo_id: 0,
            tax_status: 'taxable',
            cost_includes_tax: false,
            scope: 'package',
            value_basis: 'net',
            aggregation: 'sum',
            min_cost: '',
            max_cost: '',
            fallback: '',
            default: false,
            logged_in_only: false,
            dim_divisor: '5000',
            missing_measurements: 'unavailable',
            bridge_rate_ids: [],
            free: { mode: 'none', threshold: '', before_discount: false, label: '', notice: false, scope: 'cart', label_on_zero: false, label_format: 'replace' },
            rules: [],
        };
    }

    async function refreshMethods() {
        const result = await request('/methods');
        model.methods = Array.isArray(result?.methods) ? result.methods : [];
        model.zones = Array.isArray(result?.zones) ? result.zones : [];
    }

    function createMethodForm(done) {
        const name = input('', null, { required: true, maxlength: '120',
            placeholder: __('Standard delivery', 'codeon-shipping-rules') });
        const zones = Object.fromEntries(model.zones.map(zone => [zone.id, zone.name]));
        const zone = select(Object.keys(zones)[0], zones);
        zone.required = true;
        const create = node('button', { type: 'submit', class: 'button button-primary' },
            __('Create method', 'codeon-shipping-rules'));
        const form = node('form', { class: 'csr-stack', onsubmit: event => {
            event.preventDefault();
            busy(create, async () => {
                const title = name.value.trim();
                if (!title) {
                    name.focus();
                    throw new Error(__('Enter a method name.', 'codeon-shipping-rules'));
                }
                const method = await request('/methods', 'POST', { zone_id: Number(zone.value), title });
                await refreshMethods();
                await done(Number(method.id ?? method.method_id));
                flash(__('Method created. Publish a rule set before enabling it.', 'codeon-shipping-rules'));
            });
        } }, node('h3', {}, __('New shipping method', 'codeon-shipping-rules')),
            node('div', { class: 'csr-grid' },
                field(__('Method name', 'codeon-shipping-rules'), name),
                field(__('Shipping zone', 'codeon-shipping-rules'), zone)),
            node('div', { class: 'csr-bar-actions' }, create, button(__('Cancel', 'codeon-shipping-rules'),
                () => { form.parentElement.hidden = true; })));
        return form;
    }

    function mountMethods() {
        const narrowPicker = node('div', { class: 'csr-rail-compact' });
        const railResults = node('div', { class: 'csr-rail-list' });
        const filter = input('', () => drawRail(), { type: 'search', placeholder: __('Search names and zones', 'codeon-shipping-rules'),
            'aria-label': __('Search shipping methods', 'codeon-shipping-rules') });
        const rail = node('nav', { class: 'codeon-section csr-rail', 'aria-label': __('Shipping methods', 'codeon-shipping-rules') },
            node('h3', {}, __('Shipping methods', 'codeon-shipping-rules')),
            node('label', { class: 'csr-search' }, node('span', { class: 'screen-reader-text' }, text.search), filter),
            narrowPicker, railResults);
        const editor = node('div', { class: 'csr-stack' });
        const create = node('section', { class: 'codeon-section', hidden: true });
        const createButton = button(__('Add method', 'codeon-shipping-rules'), () => {
            create.hidden = !create.hidden;
            createButton.setAttribute('aria-expanded', String(!create.hidden));
            if (!create.hidden) {
                create.querySelector('input')?.focus();
            }
        }, { primary: true, disabled: model.zones.length === 0 });
        create.id = 'csr-create-method';
        createButton.setAttribute('aria-controls', create.id);
        createButton.setAttribute('aria-expanded', 'false');
        create.append(createMethodForm(async id => {
            create.hidden = true;
            createButton.setAttribute('aria-expanded', 'false');
            await openMethod(id);
        }));
        create.addEventListener('click', event => {
            if (event.target.closest('button') && create.hidden) {
                createButton.setAttribute('aria-expanded', 'false');
                createButton.focus();
            }
        });

        const zonesLink = node('a', { class: 'button', href: settings.zonesUrl },
            __('WooCommerce zones', 'codeon-shipping-rules'));
        root.replaceChildren(heading(__('Manage shipping rates', 'codeon-shipping-rules'),
            __('Set what each shipping method costs and when it appears at checkout.', 'codeon-shipping-rules'),
            node('div', { class: 'csr-bar-actions' }, zonesLink, createButton)),
            message, node('div', { class: 'csr-stack' }, create,
                node('div', { class: 'csr-body' }, rail, editor)));

        let currentRequest = 0;
        function drawRail() {
            const options = Object.fromEntries(model.methods.map(method => [method.id, captionForMethod(method)]));
            narrowPicker.replaceChildren(model.methods.length
                ? field(__('Shipping method', 'codeon-shipping-rules'), select(model.selected, options, value => {
                    openMethod(Number(value), true);
                })) : node('p', { class: 'csr-help' }, __('No shipping methods yet.', 'codeon-shipping-rules')));
            const term = filter.value.trim().toLocaleLowerCase();
            const visible = model.methods.filter(method => captionForMethod(method).toLocaleLowerCase().includes(term));
            const groups = new Map();
            for (const method of visible) {
                const name = method.zone || __('Zone', 'codeon-shipping-rules');
                if (!groups.has(name)) {
                    groups.set(name, []);
                }
                groups.get(name).push(method);
            }
            railResults.replaceChildren();
            for (const [name, methods] of groups) {
                const list = node('ul');
                for (const method of methods) {
                    const id = Number(method.id);
                    list.append(node('li', {}, node('a', {
                        class: 'csr-method-link', href: query('methods', id),
                        'aria-current': id === model.selected ? 'page' : null,
                        onclick: event => {
                            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                                return;
                            }
                            event.preventDefault();
                            if (id !== model.selected) {
                                openMethod(id, true);
                            }
                        },
                    }, node('span', {}, methodTitle(method)), statusPill(method))));
                }
                railResults.append(node('h4', { class: 'csr-zone' }, name), list);
            }
            if (!visible.length) {
                railResults.append(node('p', { class: 'csr-zone' }, term
                    ? __('No matching methods.', 'codeon-shipping-rules')
                    : __('No shipping methods yet.', 'codeon-shipping-rules')));
            }
        }

        async function openMethod(id, focus = false) {
            if (model.processing || (model.dirty && !window.confirm(__('Discard unsaved changes?', 'codeon-shipping-rules')))) {
                return;
            }
            const method = model.methods.find(entry => Number(entry.id) === id);
            model.selected = method ? id : 0;
            model.dirty = false;
            drawRail();
            const expected = ++currentRequest;
            if (!method) {
                editor.replaceChildren(section(empty(
                    __('Choose a shipping method', 'codeon-shipping-rules'), text.noMethods,
                    button(__('Add method', 'codeon-shipping-rules'), () => createButton.click(), { disabled: createButton.disabled }))));
                return;
            }
            flash('');
            sendToHistory();
            editor.replaceChildren(section(node('p', { role: 'status' }, text.loading)));
            try {
                const detail = await request('/methods/' + id);
                if (expected !== currentRequest) {
                    return;
                }
                editor.replaceChildren(methodEditor(method, detail, async (newId = id) => {
                    await refreshMethods();
                    await openMethod(newId);
                }));
                if (focus) {
                    editor.querySelector('.csr-method-head')?.focus();
                }
            } catch (error) {
                if (expected !== currentRequest) {
                    return;
                }
                editor.replaceChildren(section(empty(__('Could not load this method', 'codeon-shipping-rules'),
                    error.message, button(__('Try again', 'codeon-shipping-rules'), () => openMethod(id)))));
            }
        }

        drawRail();
        const selected = model.methods.find(method => Number(method.id) === model.selected)
            || model.methods.find(method => method.native) || model.methods[0];
        openMethod(Number(selected?.id || 0));
    }

    function methodEditor(method, detail, reload) {
        let draftVersion = detail.draft_version ?? detail.draft?.version ?? null;
        const draft = detail.draft?.config ?? detail.draft ?? detail.config ?? {};
        const defaults = defaultConfig(method);
        const config = { ...defaults, ...copy(draft), free: { ...defaults.free, ...copy(draft.free || {}) } };
        config.rules = Array.isArray(config.rules) ? config.rules : [];
        const id = Number(method.id);
        const native = Boolean(method.native);
        const isEnabled = enabled(method);
        const formFields = node('fieldset', { class: 'csr-editor-fields csr-stack' });
        const form = node('form', { class: 'csr-stack', onsubmit: event => { event.preventDefault(); } }, formFields);
        const state = node('span', { class: 'csr-help', 'data-csr-draft-state': true, role: 'status' },
            detail.draft_pending ? __('Draft saved', 'codeon-shipping-rules')
                : Number(detail.revision) > 0 ? sprintf(__('Live revision %d', 'codeon-shipping-rules'), detail.revision)
                    : __('No published revision', 'codeon-shipping-rules'));
        const previewLink = node('a', { class: 'button', href: query('simulator', id), onclick: event => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            if (model.dirty && !window.confirm(__('Open the simulator with the last saved draft?', 'codeon-shipping-rules'))) {
                event.preventDefault();
            } else {
                model.dirty = false;
            }
        } }, __('Preview rates', 'codeon-shipping-rules'));

        const lock = async callback => {
            if (model.processing) {
                throw new Error(__('A change is already in progress.', 'codeon-shipping-rules'));
            }
            model.processing = true;
            formFields.disabled = true;
            try {
                await callback();
            } finally {
                model.processing = false;
                formFields.disabled = false;
            }
        };

        const saveDraft = () => request('/methods/' + id + '/draft', 'PUT', {
            config, revision: detail.revision ?? 0, draft_version: draftVersion,
        });

        function validForm() {
            const invalid = form.querySelector('input:invalid, select:invalid, textarea:invalid');
            for (let ancestor = invalid?.parentElement; ancestor && ancestor !== form; ancestor = ancestor.parentElement) {
                if (ancestor.tagName === 'DETAILS') {
                    ancestor.open = true;
                }
            }
            return form.reportValidity();
        }

        const save = button(__('Save draft', 'codeon-shipping-rules'), event => {
            if (!validForm()) {
                return;
            }
            busy(event.currentTarget, async () => {
                await lock(saveDraft);
                model.dirty = false;
                await reload();
                flash(__('Draft saved.', 'codeon-shipping-rules'), 'ok', true);
            });
        });
        const publish = button(__('Publish', 'codeon-shipping-rules'), event => {
            if (!validForm()) {
                return;
            }
            if (!detail.draft_pending && !model.dirty && Number(detail.revision) > 0) {
                flash(__('This revision is already published.', 'codeon-shipping-rules'), 'info', true);
                return;
            }
            if (!window.confirm(__('Publish this rule set? Enabled methods use it immediately.', 'codeon-shipping-rules'))) {
                return;
            }
            busy(event.currentTarget, async () => {
                await lock(async () => {
                    let version = draftVersion;
                    if (model.dirty || !detail.draft_pending) {
                        const saved = await saveDraft();
                        version = saved?.draft_version ?? saved?.draft?.version ?? null;
                        if (version === null) {
                            const refreshed = await request('/methods/' + id);
                            version = refreshed?.draft_version ?? refreshed?.draft?.version ?? null;
                        }
                        draftVersion = version;
                        model.dirty = false;
                        detail.draft = config;
                        detail.draft_pending = true;
                    }
                    await request('/methods/' + id + '/publish', 'POST', {
                        revision: detail.revision ?? 0, draft_version: version,
                    });
                });
                model.dirty = false;
                await reload();
                flash(__('Rules published.', 'codeon-shipping-rules'), 'ok', true);
            });
        }, { primary: true });
        const toggle = native ? button(isEnabled ? __('Disable method', 'codeon-shipping-rules') : __('Enable method', 'codeon-shipping-rules'),
            event => {
                if (model.dirty && !window.confirm(__('Discard unsaved edits and change the method status?', 'codeon-shipping-rules'))) {
                    return;
                }
                if (isEnabled && !window.confirm(__('Remove this method from checkout?', 'codeon-shipping-rules'))) {
                    return;
                }
                busy(event.currentTarget, async () => {
                    await lock(() => request('/methods/' + id + '/state', 'PUT', { enabled: !isEnabled }));
                    model.dirty = false;
                    await reload();
                    flash(isEnabled ? __('Method disabled.', 'codeon-shipping-rules') : __('Method enabled.', 'codeon-shipping-rules'));
                });
            }, { disabled: !isEnabled && Number(detail.revision) < 1 }) : null;
        const duplicate = native ? button(__('Duplicate method', 'codeon-shipping-rules'), event => {
            if (model.dirty) {
                flash(__('Save your changes before duplicating this method.', 'codeon-shipping-rules'), 'info', true);
                return;
            }
            busy(event.currentTarget, async () => {
                let result;
                await lock(async () => {
                    result = await request('/methods/' + id + '/duplicate', 'POST', {});
                });
                await reload(Number(result.id));
                flash(__('Method duplicated as a disabled draft.', 'codeon-shipping-rules'), 'ok', true);
            });
        }) : null;

        const header = node('div', { class: 'csr-method-head', tabindex: '-1' },
            node('div', { class: 'csr-stack', style: 'gap: var(--codeon-space-1)' },
                method.zone ? node('p', { class: 'csr-help' }, method.zone) : null,
                node('div', { class: 'csr-heading-group' }, node('h3', {}, detail.title || methodTitle(method)), statusPill(method)),
                !native ? node('p', { class: 'csr-help' },
                    __('These rules adjust this provider’s existing rates.', 'codeon-shipping-rules')) : null),
            node('div', { class: 'csr-bar-actions' }, duplicate, toggle, previewLink));

        formFields.append(methodSettings(config, native), freeSettings(config.free), rulesEditor(config));
        form.append(node('div', { class: 'csr-toolbar csr-save-bar' }, state, node('div', { class: 'csr-bar-actions' }, save, publish)));
        return section(header, form);
    }

    function methodSettings(config, native) {
        const basic = node('div', { class: 'csr-grid' });
        if (native) {
            basic.append(
                field(__('Checkout title', 'codeon-shipping-rules'), input(config.title, change(config, 'title'), { required: true, maxlength: '120' })),
                field(__('Shipping tax', 'codeon-shipping-rules'), select(config.tax_status, {
                    taxable: __('Taxable', 'codeon-shipping-rules'), none: __('No shipping tax', 'codeon-shipping-rules'),
                }, change(config, 'tax_status'))),
                field(__('Checkout description', 'codeon-shipping-rules'),
                    input(config.description, change(config, 'description'), { maxlength: '500' }),
                    __('Shown under the shipping method when the checkout supports descriptions.', 'codeon-shipping-rules'), true),
                field(__('Estimated delivery time', 'codeon-shipping-rules'),
                    input(config.delivery_time, change(config, 'delivery_time'), {
                        maxlength: '120', placeholder: __('2–3 business days', 'codeon-shipping-rules'),
                    }))
            );
        } else {
            basic.append(field(__('Target rate IDs', 'codeon-shipping-rules'),
                input(config.bridge_rate_ids.join(', '), value => {
                    config.bridge_rate_ids = value.split(',').map(part => part.trim()).filter(Boolean);
                    markDirty();
                }), __('Separate IDs with commas. Leave blank to apply to every service from this method.', 'codeon-shipping-rules'), true));
        }
        basic.append(
            field(__('Calculate from', 'codeon-shipping-rules'), select(config.scope, scopes, change(config, 'scope'))),
            field(__('Combine matching rules', 'codeon-shipping-rules'), select(config.aggregation, {
                sum: __('Add all matching costs', 'codeon-shipping-rules'),
                lowest: __('Use the lowest cost', 'codeon-shipping-rules'),
                highest: __('Use the highest cost', 'codeon-shipping-rules'),
                first: __('Use the first match', 'codeon-shipping-rules'),
            }, change(config, 'aggregation')))
        );
        const advanced = node('details', { class: 'csr-details' },
            node('summary', {}, __('Advanced method settings', 'codeon-shipping-rules')),
            node('div', { class: 'csr-grid' },
                field(__('Order value basis', 'codeon-shipping-rules'), select(config.value_basis, {
                    net: __('Exclude merchandise taxes', 'codeon-shipping-rules'),
                    gross: __('Include merchandise taxes', 'codeon-shipping-rules'),
                }, change(config, 'value_basis'))),
                field(__('Dimensional weight divisor', 'codeon-shipping-rules'),
                    decimal(config.dim_divisor, change(config, 'dim_divisor')),
                    __('Used when a rule measures dimensional weight.', 'codeon-shipping-rules')),
                field(__('When a product has no weight or dimensions', 'codeon-shipping-rules'), select(config.missing_measurements || 'unavailable', {
                    unavailable: __('Hide this rate', 'codeon-shipping-rules'),
                    zero: __('Count them as zero', 'codeon-shipping-rules'),
                }, change(config, 'missing_measurements'))),
                field(__('Minimum shipping charge', 'codeon-shipping-rules'), decimal(config.min_cost, change(config, 'min_cost'))),
                field(__('Maximum shipping charge', 'codeon-shipping-rules'), decimal(config.max_cost, change(config, 'max_cost'))),
                native ? field(__('Fallback charge', 'codeon-shipping-rules'), decimal(config.fallback, change(config, 'fallback')),
                    __('Leave blank to hide this method when no rule matches.', 'codeon-shipping-rules')) : null,
                native ? field(__('Media attachment ID', 'codeon-shipping-rules'),
                    input(config.logo_id || '', value => {
                        config.logo_id = value || 0;
                        markDirty();
                    }, { type: 'number', min: '1' }),
                    __('Optional image from the Media Library.', 'codeon-shipping-rules')) : null,
                native ? checkbox(__('Amounts already include shipping tax', 'codeon-shipping-rules'),
                    config.cost_includes_tax, change(config, 'cost_includes_tax')) : null,
                native ? checkbox(__('Prefer this method when no option is selected', 'codeon-shipping-rules'),
                    config.default, change(config, 'default')) : null,
                checkbox(__('Require a signed-in customer', 'codeon-shipping-rules'),
                    config.logged_in_only, change(config, 'logged_in_only'))
            )
        );
        return node('div', { class: 'csr-stack' }, basic, advanced);
    }

    function freeSettings(free) {
        const container = node('div', { class: 'csr-grid' });
        const mode = field(__('Offer free shipping when', 'codeon-shipping-rules'), select(free.mode || 'none', freeModes, value => {
            free.mode = value;
            markDirty();
            draw();
        }));
        function draw() {
            const visible = free.mode && free.mode !== 'none';
            const amountRequired = visible && free.mode !== 'coupon';
            const isQuantity = free.mode === 'quantity';
            const labelled = visible || free.label_on_zero;
            container.replaceChildren(...[mode,
                amountRequired ? field(isQuantity ? __('Minimum items', 'codeon-shipping-rules') : __('Minimum value', 'codeon-shipping-rules'),
                    decimal(free.threshold, change(free, 'threshold'), { required: true })) : null,
                visible ? field(__('Threshold applies to', 'codeon-shipping-rules'), select(free.scope || 'cart', scopes, change(free, 'scope'))) : null,
                checkbox(__('Show the free label whenever the rate costs nothing', 'codeon-shipping-rules'), free.label_on_zero, value => {
                    free.label_on_zero = value;
                    markDirty();
                    draw();
                }),
                labelled ? field(__('Free rate label', 'codeon-shipping-rules'), input(free.label, change(free, 'label'), { maxlength: '120' })) : null,
                labelled ? field(__('Free label position', 'codeon-shipping-rules'),
                    select(free.label_format || 'replace', labelFormats, change(free, 'label_format'))) : null,
                amountRequired && !isQuantity ? checkbox(__('Use the value before discounts', 'codeon-shipping-rules'),
                    free.before_discount, change(free, 'before_discount')) : null,
                amountRequired ? checkbox(__('Show progress toward free shipping', 'codeon-shipping-rules'),
                    free.notice, change(free, 'notice')) : null,
            ].filter(Boolean));
        }
        draw();
        return node('details', { class: 'csr-details', open: (free.mode && free.mode !== 'none') || free.label_on_zero },
            node('summary', {}, __('Free shipping', 'codeon-shipping-rules')), container);
    }

    function ruleId() {
        return window.crypto?.randomUUID?.() || 'rule-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function defaultRule() {
        return { id: ruleId(), label: '', logic: 'all', scope: 'method',
            conditions: [{ type: 'always' }], base: '', charges: [], action: 'continue' };
    }

    function conditionSummary(rule) {
        const parts = (Array.isArray(rule.conditions) ? rule.conditions : []).filter(entry => entry.type && entry.type !== 'always').map(entry => {
            if (entry.type === 'time' || Object.hasOwn(rangeLabels, entry.type)) {
                const isMoney = ['price', 'gross_price', 'price_before_discount', 'gross_before_discount', 'running_cost'].includes(entry.type);
                const unit = entry.type === 'time' ? '' : unitFor(entry.type);
                const show = value => isMoney ? currency(value) : String(value) + (unit ? '\u00a0' + unit : '');
                const hasMin = entry.min != null && entry.min !== '';
                const hasMax = entry.max != null && entry.max !== '';
                // "0\u20133.5 kg": a measured range names its unit once.
                const both = isMoney ? show(entry.min) + '\u2013' + show(entry.max)
                    : String(entry.min) + '\u2013' + String(entry.max) + (unit ? '\u00a0' + unit : '');
                let range = hasMin && hasMax ? both
                    : hasMin ? sprintf(__('from %s', 'codeon-shipping-rules'), show(entry.min))
                        : hasMax ? sprintf(__('up to %s', 'codeon-shipping-rules'), show(entry.max)) : '';
                if (range && entry.operator === 'not_between') {
                    range = sprintf(__('outside %s', 'codeon-shipping-rules'), range);
                }
                const label = entry.type === 'time' ? __('Time of day', 'codeon-shipping-rules') : rangeLabels[entry.type];
                return range ? label + ': ' + range : label;
            }
            const count = Array.isArray(entry.values) ? entry.values.length : 0;
            const label = selectionLabels[entry.type] || entry.type;
            return count ? label + ': ' + sprintf(_n('%d value', '%d values', count, 'codeon-shipping-rules'), count) : label;
        });
        if (!parts.length) {
            return __('Applies to every order', 'codeon-shipping-rules');
        }
        return parts.join(rule.logic === 'any' ? ' ' + __('or', 'codeon-shipping-rules') + ' ' : ' \u00b7 ');
    }

    function priceLabel(rule) {
        if (rule.action === 'hide') {
            return actions.hide;
        }
        const base = rule.base == null || rule.base === '' ? '' : currency(rule.base);
        const count = rule.charges.length;
        if (!count) {
            return base || __('Set a base charge', 'codeon-shipping-rules');
        }
        const extras = sprintf(_n('%d adjustment', '%d adjustments', count, 'codeon-shipping-rules'), count);
        return base ? base + ' + ' + extras : extras;
    }

    function rulesEditor(config) {
        const list = node('ol', { class: 'csr-rule-list' });
        const expanded = new Set();
        const preset = select('', {
            '': __('Choose a template', 'codeon-shipping-rules'),
            flat: __('Flat delivery charge', 'codeon-shipping-rules'),
            weight: __('Two weight bands', 'codeon-shipping-rules'),
            region: __('Destination-based delivery', 'codeon-shipping-rules'),
            oversized: __('Hide for oversized goods', 'codeon-shipping-rules'),
        });
        const applyPreset = button(__('Use template', 'codeon-shipping-rules'), () => {
            if (!preset.value) {
                preset.focus();
                return;
            }
            if (config.rules.length && !window.confirm(__('Replace the rules in this draft with a template?', 'codeon-shipping-rules'))) {
                return;
            }
            const first = defaultRule();
            if (preset.value === 'weight') {
                const second = defaultRule();
                first.label = __('Up to 5', 'codeon-shipping-rules') + ' ' + settings.weightUnit;
                first.conditions = [{ type: 'weight', operator: 'between', max: '5' }];
                second.label = __('Over 5', 'codeon-shipping-rules') + ' ' + settings.weightUnit;
                second.conditions = [{ type: 'weight', operator: 'between', min: '5', min_exclusive: true }];
                config.rules = [first, second];
            } else {
                if (preset.value === 'region') {
                    first.conditions = [{ type: 'city', operator: 'any', values: [] }];
                }
                if (preset.value === 'oversized') {
                    first.conditions = [{ type: 'max_dimension', operator: 'between', min: '' }];
                    first.action = 'hide';
                    first.base = '0';
                }
                config.rules = [first];
            }
            preset.value = '';
            expanded.clear();
            expanded.add(first.id);
            markDirty();
            drawRules(first.id);
        });
        const addRule = button(__('Add rule', 'codeon-shipping-rules'), () => {
            const rule = defaultRule();
            config.rules.push(rule);
            expanded.add(rule.id);
            markDirty();
            drawRules(rule.id);
        }, { primary: true });

        function drawRules(focusId = null, focusAction = null) {
            const rows = config.rules.map((rule, index) => {
                if (!rule.id) {
                    rule.id = ruleId();
                }
                rule.conditions = Array.isArray(rule.conditions) ? rule.conditions : [];
                rule.charges = Array.isArray(rule.charges) ? rule.charges : [];
                const defaultTitle = sprintf(__('Rule %d', 'codeon-shipping-rules'), index + 1);
                const title = node('strong', {}, rule.label || defaultTitle);
                const summary = node('span', { class: 'csr-help' }, conditionSummary(rule));
                const price = node('span', { class: 'csr-rule-price' }, priceLabel(rule));
                function updateSummary() {
                    title.textContent = rule.label || defaultTitle;
                    summary.textContent = conditionSummary(rule);
                    price.textContent = priceLabel(rule);
                }
                const move = offset => {
                    const next = index + offset;
                    config.rules.splice(index, 1);
                    config.rules.splice(next, 0, rule);
                    markDirty();
                    drawRules(rule.id, offset < 0 ? 'up' : 'down');
                };
                const up = button(node('span', { class: 'dashicons dashicons-arrow-up-alt2', 'aria-hidden': 'true' }), () => move(-1), {
                    disabled: index === 0, aria: sprintf(__('Move rule %d up', 'codeon-shipping-rules'), index + 1),
                });
                up.dataset.focus = 'up';
                const down = button(node('span', { class: 'dashicons dashicons-arrow-down-alt2', 'aria-hidden': 'true' }), () => move(1), {
                    disabled: index === config.rules.length - 1, aria: sprintf(__('Move rule %d down', 'codeon-shipping-rules'), index + 1),
                });
                down.dataset.focus = 'down';
                const duplicate = button(__('Duplicate', 'codeon-shipping-rules'), () => {
                    const clone = { ...copy(rule), id: ruleId() };
                    config.rules.splice(index + 1, 0, clone);
                    expanded.add(clone.id);
                    markDirty();
                    drawRules(clone.id);
                }, { aria: sprintf(__('Duplicate rule %d', 'codeon-shipping-rules'), index + 1) });
                const remove = button(text.remove, () => {
                    if (!window.confirm(sprintf(__('Remove rule %d from the draft?', 'codeon-shipping-rules'), index + 1))) {
                        return;
                    }
                    config.rules.splice(index, 1);
                    expanded.delete(rule.id);
                    markDirty();
                    drawRules(config.rules[Math.min(index, config.rules.length - 1)]?.id);
                    if (!config.rules.length) {
                        addRule.focus();
                    }
                }, { aria: sprintf(__('Remove rule %d', 'codeon-shipping-rules'), index + 1) });
                const details = node('details', { open: expanded.has(rule.id), ontoggle: event => {
                    if (event.currentTarget.open) {
                        expanded.add(rule.id);
                    } else {
                        expanded.delete(rule.id);
                    }
                } }, node('summary', {}, sprintf(__('Edit rule %d', 'codeon-shipping-rules'), index + 1)));
                const label = input(rule.label, value => {
                    rule.label = value;
                    markDirty();
                    updateSummary();
                }, { maxlength: 120 });
                const identity = node('div', { class: 'csr-grid' },
                    field(__('Rule name', 'codeon-shipping-rules'), label),
                    field(__('Rule scope', 'codeon-shipping-rules'), select(rule.scope || 'method', {
                        method: __('Use method scope', 'codeon-shipping-rules'), ...scopes,
                    }, change(rule, 'scope'))));
                const baseCharge = decimal(rule.base, value => {
                    rule.base = value;
                    markDirty();
                    updateSummary();
                }, { required: rule.action !== 'hide' });
                const pricing = node('div', { class: 'csr-grid' },
                    field(__('Base charge', 'codeon-shipping-rules'), withUnit(baseCharge, settings.currencySymbol || settings.currency)),
                    field(__('After a match', 'codeon-shipping-rules'),
                        select(rule.action || 'continue', actions, value => {
                            rule.action = value;
                            baseCharge.required = value !== 'hide';
                            markDirty();
                            updateSummary();
                        })));
                details.append(node('div', { class: 'csr-rule-content csr-stack' },
                    identity, conditionsEditor(rule), pricing, chargesEditor(rule, updateSummary)));
                return node('li', { class: 'csr-rule', 'data-rule-key': rule.id },
                    node('div', { class: 'csr-rule-head' }, node('span', { class: 'csr-rule-title' }, title, summary), price,
                        node('div', { class: 'csr-rule-controls' }, up, down, duplicate, remove)), details);
            });
            list.replaceChildren(...rows);
            if (!rows.length) {
                list.append(node('li', {}, empty(__('No rules in this draft', 'codeon-shipping-rules'),
                    __('Add a rule or use a template to start pricing this method.', 'codeon-shipping-rules'))));
            }
            if (focusId) {
                const row = rows.find(entry => entry.dataset.ruleKey === String(focusId));
                const control = focusAction ? row?.querySelector('[data-focus="' + focusAction + '"]:not([disabled])') : null;
                (control || row?.querySelector('summary'))?.focus({ preventScroll: true });
            }
        }
        drawRules();
        return node('div', { class: 'csr-stack' },
            node('div', { class: 'csr-toolbar' },
                node('div', { class: 'csr-stack', style: 'gap: var(--codeon-space-1)' },
                    node('h3', {}, __('Rate rules', 'codeon-shipping-rules')),
                    node('p', { class: 'csr-help' }, __('Checkout checks the rules from top to bottom.', 'codeon-shipping-rules'))),
                addRule),
            node('div', { class: 'csr-bar-actions' },
                node('label', {}, node('span', { class: 'screen-reader-text' }, __('Rule template', 'codeon-shipping-rules')), preset),
                applyPreset),
            list);
    }

    function valuesHint(type) {
        const hints = {
            product: __('Separate product IDs with commas, or search below.', 'codeon-shipping-rules'),
            variation: __('Separate variation IDs with commas, or search variations below.', 'codeon-shipping-rules'),
            category: __('Use category IDs or slugs, separated by commas.', 'codeon-shipping-rules'),
            tag: __('Use tag IDs or slugs, separated by commas.', 'codeon-shipping-rules'),
            shipping_class: __('Use class IDs or slugs, separated by commas.', 'codeon-shipping-rules'),
            role: __('Use role keys such as customer or guest, separated by commas.', 'codeon-shipping-rules'),
            country: __('Use two-letter country codes such as GE or GB, separated by commas.', 'codeon-shipping-rules'),
            currency: __('Use ISO currency codes such as GEL or EUR, separated by commas.', 'codeon-shipping-rules'),
        };
        return hints[type] || __('Separate multiple values with commas.', 'codeon-shipping-rules');
    }

    function conditionsEditor(rule) {
        const list = node('div', { class: 'csr-entries' });
        const logic = select(rule.logic || 'all', {
            all: __('All conditions must match', 'codeon-shipping-rules'),
            any: __('Any condition can match', 'codeon-shipping-rules'),
        }, change(rule, 'logic'));
        const logicField = field(__('Match conditions', 'codeon-shipping-rules'), logic);
        function draw() {
            logicField.hidden = rule.conditions.length < 2;
            list.replaceChildren(...rule.conditions.map((condition, index) => {
                const attributes = node('div', { class: 'csr-grid' });
                const groups = conditionGroups.slice();
                const known = groups.some(([, options]) => Object.hasOwn(options, condition.type));
                if (!known && condition.type) {
                    groups.push([__('Other condition', 'codeon-shipping-rules'), { [condition.type]: condition.type }]);
                }
                const types = select(condition.type || 'always', null, value => {
                    for (const key of Object.keys(condition)) {
                        delete condition[key];
                    }
                    condition.type = value;
                    if (value === 'time' || Object.hasOwn(rangeLabels, value)) {
                        condition.operator = 'between';
                    } else if (Object.hasOwn(selectionLabels, value)) {
                        condition.operator = 'any';
                        condition.values = [];
                    }
                    markDirty();
                    drawConditionFields();
                }, groups);

                function drawConditionFields() {
                    attributes.replaceChildren();
                    const type = condition.type || 'always';
                    const isTime = type === 'time';
                    if (isTime || Object.hasOwn(rangeLabels, type)) {
                        const decimalOrTime = isTime ? (value, onInput) => input(value, onInput, {
                            pattern: '(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00',
                            placeholder: '09:00',
                        }) : decimal;
                        attributes.append(field(__('Comparison', 'codeon-shipping-rules'), select(condition.operator || 'between', {
                            between: __('Within the range', 'codeon-shipping-rules'),
                            not_between: __('Outside the range', 'codeon-shipping-rules'),
                        }, change(condition, 'operator'))));
                        if (type === 'product_field_range') {
                            attributes.append(field(__('Product field key', 'codeon-shipping-rules'),
                                input(condition.field, change(condition, 'field'), { required: true })));
                        }
                        const measure = isTime ? '' : unitFor(type);
                        attributes.append(
                            field(isTime ? __('From time', 'codeon-shipping-rules') : __('From', 'codeon-shipping-rules'),
                                measure ? withUnit(decimalOrTime(condition.min, change(condition, 'min')), measure) : decimalOrTime(condition.min, change(condition, 'min'))),
                            field(isTime ? __('To time', 'codeon-shipping-rules') : __('To', 'codeon-shipping-rules'),
                                measure ? withUnit(decimalOrTime(condition.max, change(condition, 'max')), measure) : decimalOrTime(condition.max, change(condition, 'max')))
                        );
                        if (!isTime) {
                            attributes.append(
                                checkbox(__('Exclude the lower boundary', 'codeon-shipping-rules'), condition.min_exclusive, change(condition, 'min_exclusive')),
                                checkbox(__('Exclude the upper boundary', 'codeon-shipping-rules'), condition.max_exclusive, change(condition, 'max_exclusive'))
                            );
                        }
                    } else if (Object.hasOwn(selectionLabels, type)) {
                        attributes.append(field(__('Comparison', 'codeon-shipping-rules'), select(condition.operator || 'any', {
                            any: __('Matches any value', 'codeon-shipping-rules'),
                            all: __('Matches all values', 'codeon-shipping-rules'),
                            none: __('Matches none of the values', 'codeon-shipping-rules'),
                            only: __('Matches only these values', 'codeon-shipping-rules'),
                        }, change(condition, 'operator'))));
                        if (type === 'product_field') {
                            attributes.append(field(__('Product field key', 'codeon-shipping-rules'),
                                input(condition.field, change(condition, 'field'), { required: true })));
                        }
                        const prepared = Array.isArray(condition.values) ? condition.values.map(String) : [];
                        if (type === 'weekday' || type === 'stock_status') {
                            const options = type === 'weekday' ? weekdays : {
                                instock: __('In stock', 'codeon-shipping-rules'),
                                outofstock: __('Out of stock', 'codeon-shipping-rules'),
                                onbackorder: __('On backorder', 'codeon-shipping-rules'),
                            };
                            const selector = select('', options);
                            selector.multiple = true;
                            for (const option of selector.options) {
                                option.selected = prepared.includes(option.value);
                            }
                            selector.addEventListener('change', () => {
                                condition.values = Array.from(selector.selectedOptions, option => option.value);
                                markDirty();
                            });
                            attributes.append(field(__('Values', 'codeon-shipping-rules'), selector,
                                __('Select one or more values.', 'codeon-shipping-rules')));
                            return;
                        }
                        const values = input(prepared.join(', '), value => {
                            condition.values = value.split(',').map(part => part.trim()).filter(Boolean);
                            markDirty();
                        });
                        attributes.append(field(__('Values', 'codeon-shipping-rules'), values, valuesHint(type)));
                        if (type === 'product' || type === 'variation') {
                            attributes.append(productSearch(product => {
                                const ids = new Set((condition.values || []).map(String));
                                ids.add(String(product.id));
                                condition.values = Array.from(ids);
                                values.value = condition.values.join(', ');
                                markDirty();
                            }));
                        }
                    }
                }
                drawConditionFields();
                return node('div', { class: 'csr-entry csr-stack' },
                    node('div', { class: 'csr-grid' }, field(sprintf(__('Condition %d', 'codeon-shipping-rules'), index + 1), types)),
                    attributes, node('div', { class: 'csr-entry-footer' },
                        button(__('Remove condition', 'codeon-shipping-rules'), () => {
                            rule.conditions.splice(index, 1);
                            markDirty();
                            draw();
                            (list.querySelector('select') || add).focus();
                        })));
            }));
            if (!rule.conditions.length) {
                list.append(node('p', { class: 'csr-help' }, __('Without conditions, this rule always matches.', 'codeon-shipping-rules')));
            }
        }
        const add = button(__('Add condition', 'codeon-shipping-rules'), () => {
            rule.conditions.push({ type: 'always' });
            markDirty();
            draw();
            list.lastElementChild?.querySelector('select')?.focus();
        });
        draw();
        return node('fieldset', { class: 'csr-stack' }, node('legend', {}, __('Conditions', 'codeon-shipping-rules')),
            logicField, list, node('div', {}, add));
    }

    function unitFor(type) {
        if (['price', 'gross_price', 'price_before_discount', 'gross_before_discount', 'running_cost'].includes(type)) {
            return settings.currencySymbol || settings.currency;
        }
        if (['weight', 'dimensional_weight'].includes(type)) {
            return settings.weightUnit;
        }
        if (['length', 'width', 'height', 'max_dimension', 'total_dimensions'].includes(type)) {
            return settings.dimensionUnit;
        }
        return type === 'volume' ? settings.dimensionUnit + '³' : '';
    }

    function chargesEditor(rule, updateSummary) {
        const list = node('div', { class: 'csr-entries' });
        const add = button(__('Add adjustment', 'codeon-shipping-rules'), () => {
            rule.charges.push({ type: 'step', basis: 'weight', amount: '', step: '1', rounding: 'up' });
            markDirty();
            updateSummary();
            draw();
            list.lastElementChild?.querySelector('select')?.focus();
        });
        function draw() {
            list.replaceChildren(...rule.charges.map((charge, index) => {
                const extra = node('div', { class: 'csr-grid' });
                const amountLabel = node('span');
                const type = select(charge.type || 'step', {
                    fixed: __('Fixed adjustment', 'codeon-shipping-rules'),
                    step: __('Per increment', 'codeon-shipping-rules'),
                    percent: __('Percentage', 'codeon-shipping-rules'),
                }, value => {
                    charge.type = value;
                    markDirty();
                    drawFields();
                });
                const amount = field(amountLabel, decimal(charge.amount, change(charge, 'amount'), { required: true }));
                function drawFields() {
                    const isFlat = charge.type === 'fixed';
                    const isStep = charge.type === 'step';
                    amountLabel.textContent = charge.type === 'percent'
                        ? __('Percentage', 'codeon-shipping-rules') : text.amount + ' (' + (settings.currencySymbol || settings.currency) + ')';
                    const basis = field(__('Based on', 'codeon-shipping-rules'), select(charge.basis || 'weight', chargeBases, value => {
                        charge.basis = value;
                        markDirty();
                        const unit = step.querySelector('.csr-affix-unit');
                        if (unit) {
                            unit.textContent = unitFor(value);
                        }
                    }));
                    const step = field(__('Increment size', 'codeon-shipping-rules'),
                        withUnit(decimal(charge.step || '1', change(charge, 'step'), { required: true }), unitFor(charge.basis)));
                    extra.replaceChildren(...[amount, isFlat ? null : basis, isStep ? step : null, isStep ?
                        field(__('Round increments', 'codeon-shipping-rules'), select(charge.rounding || 'up', {
                            up: __('Up', 'codeon-shipping-rules'), down: __('Down', 'codeon-shipping-rules'),
                            nearest: __('To the nearest increment', 'codeon-shipping-rules'),
                        }, change(charge, 'rounding'))) : null].filter(Boolean));
                }
                drawFields();
                return node('div', { class: 'csr-entry csr-stack' },
                    node('div', { class: 'csr-grid' }, field(sprintf(__('Adjustment %d', 'codeon-shipping-rules'), index + 1), type)),
                    extra,
                    node('div', { class: 'csr-entry-footer' }, button(__('Remove adjustment', 'codeon-shipping-rules'), () => {
                        rule.charges.splice(index, 1);
                        markDirty();
                        updateSummary();
                        draw();
                        (list.querySelector('select') || add).focus();
                    })));
            }));
        }
        draw();
        return node('fieldset', { class: 'csr-stack' }, node('legend', {}, __('Price adjustments', 'codeon-shipping-rules')),
            list, node('div', {}, add));
    }

    function methodSelect(onChange = null) {
        const first = model.methods.find(method => method.native) || model.methods[0];
        const options = Object.fromEntries(model.methods.map(method => [method.id, captionForMethod(method)]));
        const id = model.methods.some(method => Number(method.id) === model.selected) ? model.selected : first?.id;
        return select(id, options, onChange);
    }

    function quoteReason(reason) {
        const labels = {
            matched: __('Matches a pricing rule', 'codeon-shipping-rules'),
            fallback: __('Uses the fallback charge', 'codeon-shipping-rules'),
            free_shipping: __('Qualifies for free shipping', 'codeon-shipping-rules'),
            passthrough: __('Keeps the provider’s original rate', 'codeon-shipping-rules'),
            hidden: __('A rule hides this rate', 'codeon-shipping-rules'),
            no_matching_rule: __('No pricing rule matched', 'codeon-shipping-rules'),
            login_required: __('Requires a signed-in customer', 'codeon-shipping-rules'),
            empty_package: __('The package contains no shippable items', 'codeon-shipping-rules'),
            calculation_error: __('The calculation could not be completed', 'codeon-shipping-rules'),
            condition_failed: __('Condition did not match', 'codeon-shipping-rules'),
            missing_measurement: __('A required measurement is missing', 'codeon-shipping-rules'),
            no_revision: __('There is no published rule set yet', 'codeon-shipping-rules'),
            disabled: __('The shipping method is disabled', 'codeon-shipping-rules'),
        };
        return labels[reason] || reason || '';
    }

    function previewQuote(title, quote, currencyCode) {
        if (!quote) {
            return section(node('h3', {}, title), node('p', { class: 'csr-help' }, __('No rates to compare.', 'codeon-shipping-rules')));
        }
        const details = Array.isArray(quote.trace) && quote.trace.length > 0 ? node('details', { class: 'csr-details' },
            node('summary', {}, __('Rule results', 'codeon-shipping-rules')),
            node('ol', { class: 'csr-trace' }, quote.trace.map((rule, index) => {
                const name = rule.label || (rule.id ? sprintf(__('Rule %d', 'codeon-shipping-rules'), index + 1) : quoteReason(rule.reason));
                const checks = (rule.conditions || []).map(condition => {
                    const label = rangeLabels[condition.type] || selectionLabels[condition.type]
                        || (condition.type === 'time' ? __('Time of day', 'codeon-shipping-rules') : __('Always', 'codeon-shipping-rules'));
                    return node('li', {}, label + ' — ' + (condition.matched
                        ? __('Matched', 'codeon-shipping-rules') : __('Skipped', 'codeon-shipping-rules')));
                });
                const count = Number(rule.line_count || 0);
                const hint = [quoteReason(rule.reason)];
                if (rule.matched && rule.scope) {
                    hint.push(scopes[rule.scope] || rule.scope,
                        sprintf(_n('%d line', '%d lines', count, 'codeon-shipping-rules'), count));
                }
                return node('li', {}, node('div', { class: 'csr-stack', style: 'gap: var(--codeon-space-1)' },
                    node('strong', {}, name), node('span', { class: 'csr-help' }, hint.join(' · ')),
                    checks.length ? node('ul', { class: 'csr-plain-list csr-help' }, checks) : null),
                    rule.contribution != null ? node('strong', {}, currency(rule.contribution, currencyCode)) : null);
            }))) : null;
        const total = quote.available ? currency(quote.cost, currencyCode) : __('Unavailable', 'codeon-shipping-rules');
        const explanation = typeof quote.reason === 'string' ? quoteReason(quote.reason) : '';
        const progress = quote.progress && (
            quote.progress.metric === 'amount'
                ? sprintf(__('%s left for free shipping', 'codeon-shipping-rules'), currency(quote.progress.remaining, currencyCode))
                : sprintf(__('%s more items for free shipping', 'codeon-shipping-rules'), quote.progress.remaining)
        );
        const totals = quote.totals;
        const changedByLimit = totals && Number(totals.calculated) !== Number(totals.bounded);
        return section(node('h3', {}, title), node('div', { class: 'csr-quote-amount' }, total),
            explanation ? node('p', { class: 'csr-help' }, explanation) : null,
            changedByLimit ? node('p', { class: 'csr-help' },
                sprintf(__('Charge adjusted from %1$s to %2$s by the configured limits.', 'codeon-shipping-rules'),
                    currency(totals.calculated, currencyCode), currency(totals.bounded, currencyCode))) : null,
            progress ? node('p', { class: 'csr-help' }, progress) : null, details);
    }

    function issueList(issues) {
        const lines = (Array.isArray(issues) ? issues : []).map(issueText).filter(Boolean);
        if (!lines.length) {
            return null;
        }
        return node('ul', {}, lines.map(line => node('li', {}, line)));
    }

    function productsInput(scenario) {
        const rows = node('ul', { class: 'csr-plain-list' });
        const search = productSearch(product => {
            const existing = scenario.products.find(item => item.id === Number(product.id));
            if (existing) {
                existing.quantity += 1;
            } else {
                scenario.products.push({ id: Number(product.id), name: product.name, quantity: 1 });
            }
            draw();
        });
        function draw() {
            rows.replaceChildren(...scenario.products.map((item, index) => node('li', { class: 'csr-history-row' },
                node('strong', {}, item.name),
                field(__('Quantity', 'codeon-shipping-rules'), input(item.quantity,
                    value => { item.quantity = Number(value); }, { type: 'number', min: '1', max: '99999', required: true })),
                button(text.remove, () => {
                    scenario.products.splice(index, 1);
                    draw();
                    search.querySelector('input').focus();
                }, { aria: sprintf(__('Remove %s', 'codeon-shipping-rules'), item.name) }))));
        }
        return node('details', { class: 'csr-details' }, node('summary', {},
            __('Use products from the catalog', 'codeon-shipping-rules')),
            node('div', { class: 'csr-stack' }, node('p', { class: 'csr-help' },
                __('When you add products, the preview uses their actual prices, quantities, and shipping attributes.', 'codeon-shipping-rules')),
            search, rows));
    }

    function mountSimulator() {
        if (!model.methods.length) {
            root.replaceChildren(heading(__('Preview a shipping rate', 'codeon-shipping-rules')), section(empty(
                text.methodUnavailable, text.noMethods, node('a', { class: 'button', href: query('methods') }, __('Go to methods', 'codeon-shipping-rules')))));
            return;
        }
        const scenario = { amount: '100', weight: '2', quantity: '2', role: 'guest', country: settings.country || '',
            state: settings.state || '', postcode: '', city: '', time: '', day: '', coupon: false, products: [],
            currency: settings.currency || '', base_rate: '', gross: '', discount: '', length: '', width: '', height: '',
            municipality: '', settlement: '' };
        const results = node('div', { class: 'csr-stack', tabindex: '-1', 'aria-live': 'polite' });
        let generation = 0;
        const reset = () => {
            generation++;
            results.replaceChildren();
        };
        const method = methodSelect(() => {
            reset();
            updateBaseRate();
        });
        const run = node('button', { type: 'submit', class: 'button button-primary' }, __('Calculate rates', 'codeon-shipping-rules'));
        const bind = key => value => { scenario[key] = value; };
        const baseRate = field(__('Provider’s original charge', 'codeon-shipping-rules'),
            decimal(scenario.base_rate, bind('base_rate')), __('Enter a current quote to test adjustments to this service.', 'codeon-shipping-rules'));
        function updateBaseRate() {
            baseRate.hidden = Boolean(model.methods.find(entry => Number(entry.id) === Number(method.value))?.native);
        }
        updateBaseRate();
        const form = node('form', { class: 'csr-stack', onsubmit: event => {
            event.preventDefault();
            busy(run, async () => {
                const expected = ++generation;
                const snapshot = Object.fromEntries(Object.entries(scenario).filter(([, value]) => value !== ''));
                snapshot.products = scenario.products.map(({ id, quantity }) => ({ id, quantity }));
                const response = await request('/simulate', 'POST', { method_id: Number(method.value), scenario: snapshot });
                if (expected !== generation) {
                    return;
                }
                const { live, draft, issues } = response;
                const changed = live && draft && (Boolean(live.available) !== Boolean(draft.available)
                    || (live.available && Number(live.cost) !== Number(draft.cost)));
                const top = changed ? (live.available && draft.available
                    ? sprintf(__('The draft changes this rate by %s.', 'codeon-shipping-rules'),
                        currency(Number(draft.cost) - Number(live.cost), snapshot.currency))
                    : draft.available ? __('The draft adds a shipping option for this package.', 'codeon-shipping-rules')
                        : __('The draft removes this shipping option for this package.', 'codeon-shipping-rules')) : '';
                results.replaceChildren(...[
                    top ? node('p', { class: 'csr-message', 'data-tone': 'info' }, top) : null,
                    node('div', { class: 'csr-quote' }, previewQuote(__('Published rate', 'codeon-shipping-rules'), live, snapshot.currency),
                        previewQuote(__('Draft rate', 'codeon-shipping-rules'), draft, snapshot.currency)),
                    issueList(issues),
                ].filter(Boolean));
                results.focus({ preventScroll: true });
            });
        } }, node('div', { class: 'csr-grid' }, field(__('Shipping method', 'codeon-shipping-rules'), method),
            field(__('Merchandise value', 'codeon-shipping-rules'), withUnit(decimal(scenario.amount, bind('amount')), settings.currencySymbol || settings.currency)),
            field(__('Package weight', 'codeon-shipping-rules'), withUnit(decimal(scenario.weight, bind('weight')), settings.weightUnit)),
            field(__('Item quantity', 'codeon-shipping-rules'), input(scenario.quantity, bind('quantity'), { type: 'number', min: '1' }))),
        productsInput(scenario),
        node('details', { class: 'csr-details' }, node('summary', {}, __('Taxes, discounts, and measurements', 'codeon-shipping-rules')),
            node('div', { class: 'csr-grid' },
                baseRate,
                field(__('Value including merchandise tax', 'codeon-shipping-rules'), withUnit(decimal(scenario.gross, bind('gross')), settings.currencySymbol || settings.currency)),
                field(__('Discount amount', 'codeon-shipping-rules'), withUnit(decimal(scenario.discount, bind('discount')), settings.currencySymbol || settings.currency)),
                field(__('Currency code', 'codeon-shipping-rules'), input(scenario.currency, bind('currency'), { maxlength: '3' })),
                field(__('Length', 'codeon-shipping-rules'), withUnit(decimal(scenario.length, bind('length')), settings.dimensionUnit)),
                field(__('Width', 'codeon-shipping-rules'), withUnit(decimal(scenario.width, bind('width')), settings.dimensionUnit)),
                field(__('Height', 'codeon-shipping-rules'), withUnit(decimal(scenario.height, bind('height')), settings.dimensionUnit))
            )),
        node('details', { class: 'csr-details' }, node('summary', {}, __('Destination, customer, and timing', 'codeon-shipping-rules')),
            node('div', { class: 'csr-grid' },
                field(__('Country code', 'codeon-shipping-rules'), input(scenario.country, bind('country'), { maxlength: '2' })),
                field(__('State or region code', 'codeon-shipping-rules'), input(scenario.state, bind('state'))),
                field(__('City', 'codeon-shipping-rules'), input(scenario.city, bind('city'))),
                field(__('Postcode', 'codeon-shipping-rules'), input(scenario.postcode, bind('postcode'))),
                field(__('Municipality', 'codeon-shipping-rules'), input(scenario.municipality, bind('municipality'))),
                field(__('Settlement', 'codeon-shipping-rules'), input(scenario.settlement, bind('settlement'))),
                field(__('Customer role', 'codeon-shipping-rules'), input(scenario.role, bind('role')),
                    __('Use a role key such as guest, customer, or wholesale_customer.', 'codeon-shipping-rules')),
                field(__('Day of the week', 'codeon-shipping-rules'),
                    select(scenario.day, { '': __('Current day', 'codeon-shipping-rules'), ...weekdays }, bind('day'))),
                field(__('Store time', 'codeon-shipping-rules'), input(scenario.time, bind('time'), { type: 'time' }),
                    __('Leave blank to use the current store time.', 'codeon-shipping-rules')),
                checkbox(__('Apply an eligible free-shipping coupon', 'codeon-shipping-rules'), scenario.coupon, bind('coupon'))
            )),
        node('div', { class: 'csr-bar-actions' }, run));
        form.addEventListener('input', reset);

        root.replaceChildren(heading(__('Preview a shipping rate', 'codeon-shipping-rules'),
            __('Compare published and draft rules for the same package. Results show pricing before WooCommerce applies checkout taxes.', 'codeon-shipping-rules')),
        node('div', { class: 'csr-narrow csr-stack' }, message, section(form), results));
    }

    function downloadFile(value, filename, type) {
        const contents = new Blob([value], { type });
        const url = URL.createObjectURL(contents);
        const anchor = node('a', { href: url, download: filename, hidden: true });
        root.append(anchor);
        anchor.click();
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    function downloadJson(value, filename) {
        downloadFile(JSON.stringify(value, null, 2) + '\n', filename, 'application/json');
    }

    function showHistoryPanel() {
        const method = methodSelect(() => { load(); });
        const history = node('div', { class: 'csr-stack', 'aria-live': 'polite' });
        let generation = 0;
        const exporting = button(__('Download JSON', 'codeon-shipping-rules'), event => {
            busy(event.currentTarget, async () => {
                const id = Number(method.value);
                const { data } = await request('/export/' + id);
                if (data == null) {
                    throw new Error(__('No settings are available to export.', 'codeon-shipping-rules'));
                }
                downloadJson(data, 'codeon-shipping-rules-' + id + '.json');
            });
        });
        const exportCsv = button(__('Download CSV tariffs', 'codeon-shipping-rules'), event => {
            busy(event.currentTarget, async () => {
                const id = Number(method.value);
                const { data } = await request('/export/' + id + '?format=csv');
                if (typeof data !== 'string' || !data) {
                    throw new Error(__('No supported tariffs are available to export.', 'codeon-shipping-rules'));
                }
                downloadFile(data, 'codeon-shipping-rules-' + id + '.csv', 'text/csv;charset=utf-8');
            });
        });
        const diagnostics = button(__('Download diagnostics', 'codeon-shipping-rules'), () => {
            downloadJson({
                methods: model.methods.map(entry => ({
                    native: Boolean(entry.native), enabled: enabled(entry),
                    has_published_rules: Number(entry.revision) > 0,
                })),
                zone_count: model.zones.length, currency: settings.currency,
                weight_unit: settings.weightUnit, dimension_unit: settings.dimensionUnit,
            }, 'codeon-shipping-diagnostics.json');
        });
        async function load() {
            const id = Number(method.value);
            if (!id) {
                return;
            }
            const expected = ++generation;
            history.replaceChildren(node('p', { class: 'csr-help', role: 'status' }, text.loading));
            try {
                const data = await request('/methods/' + id);
                if (expected !== generation) {
                    return;
                }
                const revisions = Array.isArray(data.history) ? data.history : [];
                if (!revisions.length) {
                    history.replaceChildren(node('p', { class: 'csr-help' }, __('Earlier revisions appear after you publish more than once.', 'codeon-shipping-rules')));
                    return;
                }
                history.replaceChildren(node('ul', { class: 'csr-plain-list' }, revisions.map(item => {
                    const current = Number(item.revision) === Number(data.revision);
                    const date = item.date ? new Date(item.date) : null;
                    const formattedDate = date && !Number.isNaN(date.getTime())
                        ? new Intl.DateTimeFormat(settings.locale || undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date) : '';
                    const restore = current ? node('span', { class: 'codeon-pill codeon-tone-ok' }, __('Live', 'codeon-shipping-rules'))
                        : button(__('Restore revision', 'codeon-shipping-rules'), event => {
                            if (!window.confirm(__('Restore this revision as the live rule set?', 'codeon-shipping-rules'))) {
                                return;
                            }
                            busy(event.currentTarget, async () => {
                                await request('/methods/' + id + '/rollback', 'POST', { revision: data.revision, target: item.revision });
                                await load();
                                flash(__('Revision restored.', 'codeon-shipping-rules'));
                            });
                        }, { aria: sprintf(__('Restore revision %d', 'codeon-shipping-rules'), item.revision) });
                    return node('li', { class: 'csr-history-row' }, node('span', {},
                        node('strong', {}, sprintf(__('Revision %d', 'codeon-shipping-rules'), item.revision)),
                        formattedDate ? node('span', { class: 'csr-help' }, ' · ' + formattedDate) : null), restore);
                })));
            } catch (error) {
                if (expected === generation) {
                    history.replaceChildren(node('p', { role: 'alert' }, error.message));
                }
            }
        }
        if (model.methods.length) {
            load();
        }
        return section(node('h3', {}, __('Revisions and backups', 'codeon-shipping-rules')),
            model.methods.length ? field(__('Shipping method', 'codeon-shipping-rules'), method) : node('p', { class: 'csr-help' }, text.methodUnavailable),
            node('div', { class: 'csr-bar-actions' },
                model.methods.length ? [exporting, exportCsv] : [], diagnostics), history);
    }

    function showImportPanel() {
        const format = select('json', {
            json: __('CodeOn JSON backup', 'codeon-shipping-rules'), csv: __('CSV tariff', 'codeon-shipping-rules'),
            flexible: __('Flexible Shipping export', 'codeon-shipping-rules'),
        });
        const zone = select(model.zones[0]?.id, Object.fromEntries(model.zones.map(entry => [entry.id, entry.name])));
        const destination = select('0', {
            0: __('Create disabled methods', 'codeon-shipping-rules'),
            ...Object.fromEntries(model.methods.map(entry => [entry.id, captionForMethod(entry)])),
        }, value => {
            zoneField.hidden = value !== '0';
        });
        const zoneField = field(__('Destination zone', 'codeon-shipping-rules'), zone);
        const content = node('textarea', { rows: 7, spellcheck: 'false', required: true });
        const file = node('input', { type: 'file', accept: '.json,.csv,application/json,text/csv,text/plain' });
        const noFile = __('No file chosen', 'codeon-shipping-rules');
        const fileName = node('span', { class: 'csr-help' }, noFile);
        const filePicker = node('div', { class: 'csr-file' }, file,
            node('span', { class: 'button', 'aria-hidden': 'true', onclick: () => file.click() }, __('Choose file', 'codeon-shipping-rules')), fileName);
        file.addEventListener('change', () => {
            fileName.textContent = file.files?.[0]?.name || noFile;
        });
        const preview = node('div', { class: 'csr-stack', tabindex: '-1', 'aria-live': 'polite' });
        let previewSession = 0;
        function clearPreview() {
            previewSession++;
            preview.replaceChildren();
        }
        for (const element of [format, destination, zone, content]) {
            element.addEventListener('input', clearPreview);
        }
        file.addEventListener('change', async () => {
            const selected = file.files?.[0];
            if (!selected) {
                return;
            }
            clearPreview();
            if (selected.size > 1000000) {
                flash(__('Choose a file smaller than 1 MB.', 'codeon-shipping-rules'), 'error', true);
                file.value = '';
                fileName.textContent = noFile;
                return;
            }
            try {
                content.value = await selected.text();
                if (/\.csv$/i.test(selected.name)) {
                    format.value = 'csv';
                }
                flash('');
            } catch {
                flash(__('This file could not be read. Paste its contents instead.', 'codeon-shipping-rules'), 'error', true);
            }
        });
        const validate = node('button', { type: 'submit', class: 'button button-primary', disabled: model.zones.length === 0 },
            __('Preview import', 'codeon-shipping-rules'));
        const form = node('form', { class: 'csr-stack', onsubmit: event => {
            event.preventDefault();
            clearPreview();
            const expected = previewSession;
            busy(validate, async () => {
                const payload = { format: format.value, content: content.value, zone_id: Number(zone.value) };
                const target = Number(destination.value);
                if (target) {
                    const method = await request('/methods/' + target);
                    payload.method_id = target;
                    payload.revision = method.revision;
                    payload.draft_version = method.draft_version;
                }
                const report = await request('/import', 'POST', { ...payload, apply: false });
                if (expected !== previewSession) {
                    return;
                }
                drawPreview(report, payload, expected);
            });
        } }, node('div', { class: 'csr-grid' }, field(__('File format', 'codeon-shipping-rules'), format),
            field(__('Import into', 'codeon-shipping-rules'), destination), zoneField),
        field(__('Upload a file', 'codeon-shipping-rules'), filePicker),
        field(__('Or paste export data', 'codeon-shipping-rules'), content),
        node('div', { class: 'csr-bar-actions' }, validate));

        function drawPreview(report, payload, expected) {
            const methods = Array.isArray(report.methods) ? report.methods : [];
            const topIssues = issueList(report.issues);
            const needsSingleMethod = payload.method_id && methods.length !== 1;
            const invalid = topIssues || methods.some(method => method.issues?.length) || needsSingleMethod;
            const list = node('ul', { class: 'csr-plain-list' }, methods.map((method, index) =>
                node('li', { class: 'csr-entry csr-stack' }, node('strong', {}, method.title || sprintf(__('Method %d', 'codeon-shipping-rules'), index + 1)),
                    node('span', { class: 'csr-help' }, sprintf(_n('%d rule', '%d rules', method.config?.rules?.length ?? 0,
                        'codeon-shipping-rules'), method.config?.rules?.length ?? 0)), issueList(method.issues))));
            const apply = methods.length && !invalid ? button(payload.method_id
                ? __('Update existing draft', 'codeon-shipping-rules') : __('Import drafts', 'codeon-shipping-rules'), event => {
                if (expected !== previewSession) {
                    return;
                }
                if (payload.method_id && !window.confirm(__('Replace the saved draft for this method?', 'codeon-shipping-rules'))) {
                    return;
                }
                busy(event.currentTarget, async () => {
                    const imported = await request('/import', 'POST', { ...payload, apply: true });
                    const ids = (imported.created || []).map(entry => Number(entry.id ?? entry)).filter(Boolean);
                    if (!ids.length) {
                        throw new Error((imported.issues || []).map(issueText).filter(Boolean).join(' ')
                            || __('No shipping methods could be imported.', 'codeon-shipping-rules'));
                    }
                    await refreshMethods();
                    mountTools();
                    flash(payload.method_id ? __('The existing draft was updated.', 'codeon-shipping-rules')
                        : sprintf(_n('%d disabled draft imported.', '%d disabled drafts imported.', ids.length,
                            'codeon-shipping-rules'), ids.length));
                    const shortcuts = node('div', { class: 'csr-created-actions' }, ids.map(id =>
                        node('a', { class: 'button', href: query('methods', id) },
                            sprintf(__('Open method %d', 'codeon-shipping-rules'), id))));
                    message.append(shortcuts);
                    message.focus({ preventScroll: true });
                });
            }, { primary: true }) : null;
            preview.replaceChildren(node('h3', {}, __('Import preview', 'codeon-shipping-rules')));
            if (topIssues) {
                preview.append(topIssues);
            }
            if (needsSingleMethod) {
                preview.append(node('p', { role: 'alert' }, __('Choose a single-method file to update an existing draft.', 'codeon-shipping-rules')));
            }
            if (methods.length) {
                preview.append(list);
            } else {
                preview.append(node('p', { class: 'csr-help' }, __('No shipping methods found in the supplied data.', 'codeon-shipping-rules')));
            }
            if (apply) {
                preview.append(node('div', { class: 'csr-bar-actions' }, apply));
            }
            preview.focus({ preventScroll: true });
        }

        return section(node('h3', {}, __('Import rates', 'codeon-shipping-rules')),
            node('p', { class: 'csr-help' },
                __('Review the preview before creating disabled methods or updating an existing draft.', 'codeon-shipping-rules')),
            form, preview);
    }

    function mountTools() {
        root.replaceChildren(heading(__('Shipping tools', 'codeon-shipping-rules')),
            node('div', { class: 'csr-narrow csr-stack' }, message, showImportPanel(), showHistoryPanel()));
    }

    async function start() {
        try {
            await refreshMethods();
            if (root.dataset.csrView === 'simulator') {
                mountSimulator();
            } else if (root.dataset.csrView === 'tools') {
                mountTools();
            } else {
                mountMethods();
            }
        } catch (error) {
            root.replaceChildren(section(empty(__('Shipping rules could not load', 'codeon-shipping-rules'),
                error.message || text.readFailed, button(__('Try again', 'codeon-shipping-rules'), () => {
                    root.replaceChildren(node('p', { role: 'status' }, text.loading));
                    start();
                }))));
        }
    }

    window.addEventListener('beforeunload', onUnload);
    start();
})();
