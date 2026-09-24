(function () {
    'use strict';

    var config = window.RanauRussianPost || {};
    var DOMESTIC_METHOD = 'ranau_russian_post';
    var MODE_OPS = 'ops';
    var MODE_ADDRESS = 'address';
    var runtime = window.RanauRussianPostDeliveryRuntimeV1;
    var machines = {};
    var initialSelection = config.selection && config.selection.postcode ? config.selection : null;
    var state = {
        mode: initialSelection && initialSelection.delivery_mode === MODE_ADDRESS ? MODE_ADDRESS : MODE_OPS,
        selection: initialSelection,
        quote: config.quote && config.quote.ok ? config.quote : null,
        address: initialSelection && (initialSelection.customer_address || initialSelection.address) ? (initialSelection.customer_address || initialSelection.address) : '',
        addressDirty: false,
        saving: false,
        map: null,
        mapObject: null,
        mapReady: false,
        modal: null,
        points: [],
        pointsRequestSeq: 0,
        pointsAbortController: null,
        renderQueued: false
    };

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function cssEscape(value) {
        return window.CSS && CSS.escape ? CSS.escape(String(value || '')) : String(value || '').replace(/["\\]/g, '\\$&');
    }

    function setText(node, value) {
        if (node && node.textContent !== value) {
            node.textContent = value;
        }
    }

    function setAttribute(node, name, value) {
        if (node && node.getAttribute(name) !== value) {
            node.setAttribute(name, value);
        }
    }

    function toggleClass(node, name, enabled) {
        if (node && node.classList.contains(name) !== enabled) {
            node.classList.toggle(name, enabled);
        }
    }

    function isReady() {
        return Boolean(state.selection && state.selection.committed && state.selection.delivery_mode === state.mode && state.selection.postcode && state.quote && state.quote.ok);
    }

    function selectedRateId() {
        var selected = document.querySelector('input[type="radio"][value^="' + DOMESTIC_METHOD + '"]:checked, input.shipping_method[value^="' + DOMESTIC_METHOD + '"]:checked');
        return selected ? String(selected.value || '') : '';
    }

    function deliveryContext() {
        return {
            country: currentFieldValue(['#shipping-country', '#shipping_country', 'select[name="shipping_country"]']) || 'RU',
            region: currentCheckoutState(),
            city: currentCheckoutCity(),
            postcode: currentFieldValue(['#shipping-postcode', '#shipping_postcode', 'input[name="shipping_postcode"]']),
            packageFingerprint: String(config.packageFingerprint || '')
        };
    }

    function beginCalculation(selection) {
        var rateId = selectedRateId();
        if (!runtime || !rateId) {
            throw new Error('delivery_state_unavailable');
        }
        if (!machines[rateId]) {
            machines[rateId] = runtime.createDeliveryStateMachine({rateId: rateId});
        }
        var machine = machines[rateId];
        machine.setAvailable(true, deliveryContext());
        var token = machine.beginCalculation(selection);
        return {
            rateId: rateId,
            machine: machine,
            token: token,
            bridge: runtime.createCheckoutBridge({
                machine: machine,
                namespace: config.storeApiNamespace,
                environment: window
            })
        };
    }

    function commitCalculation(calculation, payload) {
        var commit = payload && payload.commit ? payload.commit : {};
        if (!calculation || !calculation.token || !commit.commit_token) {
            return Promise.reject(new Error('delivery_commit_missing'));
        }

        if (!(window.wc && window.wc.blocksCheckout && typeof window.wc.blocksCheckout.extensionCartUpdate === 'function')) {
            var accepted = calculation.machine.commit(calculation.token, payload.quote);
            if (!accepted.accepted || selectedRateId() !== calculation.rateId) {
                return Promise.reject(new Error('delivery_response_stale'));
            }
            return fetch(config.restUrl + '/commit', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || ''},
                body: JSON.stringify(commit)
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('delivery_commit_failed');
                }
                if (window.jQuery) {
                    window.jQuery(document.body).trigger('update_checkout');
                }
                return {accepted: true, checkoutUpdated: true, transport: 'classic'};
            });
        }

        return calculation.bridge.commitCalculation({
            selectedRateId: selectedRateId(),
            token: calculation.token,
            quote: payload.quote,
            packageFingerprint: String(commit.package_fingerprint || config.packageFingerprint || ''),
            commitToken: String(commit.commit_token || ''),
            data: commit
        }).then(function (result) {
            if (!result.accepted || !result.checkoutUpdated) {
                throw new Error(result.reason || 'delivery_commit_failed');
            }
            return result;
        });
    }

    function selectorHtml(rateValue) {
        return [
            '<div class="ranau-russian-post-for-woocommerce-selector ranau-russian-post-for-woocommerce-unified-selector ranau-russian-post-for-woocommerce-blocks-selector" data-ranau-russian-post-for-woocommerce-rate="' + escapeHtml(rateValue) + '" data-ranau-russian-post-for-woocommerce-method="' + DOMESTIC_METHOD + '" data-ranau-delivery-method="' + DOMESTIC_METHOD + '" data-ranau-delivery-complete="0">',
            unifiedInnerHtml(),
            '</div>'
        ].join('');
    }

    function unifiedInnerHtml() {
        return [
            '<div class="ranau-russian-post-for-woocommerce-selector__head"><div><strong>ПОЧТА РОССИИ</strong><span>Получение в отделении или доставка по точному адресу.</span></div></div>',
            '<div class="ranau-russian-post-for-woocommerce-mode" role="radiogroup" aria-label="Способ доставки Почтой России">',
            '<button type="button" class="ranau-russian-post-for-woocommerce-mode-button" data-mode="' + MODE_OPS + '">В отделение</button>',
            '<button type="button" class="ranau-russian-post-for-woocommerce-mode-button" data-mode="' + MODE_ADDRESS + '">По адресу</button>',
            '</div>',
            '<div class="ranau-russian-post-for-woocommerce-pane ranau-russian-post-for-woocommerce-pane-ops">',
            '<div class="ranau-russian-post-for-woocommerce-selected" aria-live="polite">ОПС пока не выбрано</div>',
            '<button type="button" class="ranau-russian-post-for-woocommerce-open">Выбрать ОПС</button>',
            '<button type="button" class="ranau-russian-post-for-woocommerce-clear" hidden>Сбросить выбор</button>',
            '</div>',
            '<div class="ranau-russian-post-for-woocommerce-pane ranau-russian-post-for-woocommerce-pane-address">',
            '<button type="button" class="ranau-russian-post-for-woocommerce-ems-map">Выбрать на карте</button>',
            '<div class="ranau-russian-post-for-woocommerce-ems-map-shell" hidden><div class="ranau-russian-post-for-woocommerce-ems-map-canvas"></div><small>Кликните по карте или перетащите метку, затем подтвердите адрес.</small></div>',
            '<label class="ranau-russian-post-for-woocommerce-ems-address"><span>Адрес доставки</span><input type="text" autocomplete="shipping street-address" placeholder="Город, улица, дом, квартира"></label>',
            '<button type="button" class="ranau-russian-post-for-woocommerce-ems-save">Подтвердить адрес и рассчитать</button>',
            '<div class="ranau-russian-post-for-woocommerce-ems-status" aria-live="polite"></div>',
            '</div>'
        ].join('');
    }

    function currentCenter() {
        var center = window.RanauDeliveryCenter && window.RanauDeliveryCenter.get ? window.RanauDeliveryCenter.get() : null;
        return center && center.lat && center.lon ? center : null;
    }

    function summaryText() {
        if (state.mode === MODE_ADDRESS) {
            if (state.saving) {
                return 'Проверяем адрес и рассчитываем доставку';
            }
            if (state.addressDirty) {
                return 'Адрес изменен. Подтвердите его еще раз.';
            }
            if (isReady()) {
                return [state.selection.postcode, state.selection.customer_address || state.selection.address, Number(state.quote.price).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' ₽'].filter(Boolean).join(' · ');
            }
            if (state.quote && state.quote.message) {
                return String(state.quote.message);
            }
            return 'Введите адрес или выберите точку на карте.';
        }
        if (!state.selection || state.selection.delivery_mode !== MODE_OPS || !state.selection.postcode) {
            return 'ОПС пока не выбрано';
        }
        var price = state.quote && state.quote.ok ? Number(state.quote.price).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' ₽' : 'Стоимость пока недоступна';
        return [state.selection.postcode, state.selection.address || state.selection.title, price].filter(Boolean).join(' · ');
    }

    function updateSelector(selector) {
        if (!selector.innerHTML) {
            selector.innerHTML = unifiedInnerHtml();
        }
        selector.setAttribute('data-ranau-delivery-complete', isReady() ? '1' : '0');
        selector.setAttribute('data-ranau-russian-post-for-woocommerce-mode', state.mode);
        selector.querySelectorAll('.ranau-russian-post-for-woocommerce-mode-button').forEach(function (button) {
            var active = button.getAttribute('data-mode') === state.mode;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        var opsPane = selector.querySelector('.ranau-russian-post-for-woocommerce-pane-ops');
        var addressPane = selector.querySelector('.ranau-russian-post-for-woocommerce-pane-address');
        if (opsPane) {
            opsPane.hidden = state.mode !== MODE_OPS;
        }
        if (addressPane) {
            addressPane.hidden = state.mode !== MODE_ADDRESS;
        }
        setText(selector.querySelector('.ranau-russian-post-for-woocommerce-selected'), summaryText());
        setText(selector.querySelector('.ranau-russian-post-for-woocommerce-open'), state.selection && state.selection.delivery_mode === MODE_OPS && state.selection.postcode ? 'Изменить ОПС' : 'Выбрать ОПС');
        var clear = selector.querySelector('.ranau-russian-post-for-woocommerce-clear');
        if (clear) {
            clear.hidden = !(state.selection && state.selection.postcode);
        }
        var input = selector.querySelector('.ranau-russian-post-for-woocommerce-ems-address input');
        if (input && document.activeElement !== input && !input.value) {
            input.value = state.address || '';
        }
        var save = selector.querySelector('.ranau-russian-post-for-woocommerce-ems-save');
        if (save) {
            save.disabled = state.saving;
            setText(save, state.saving ? 'Рассчитываем...' : 'Подтвердить адрес и рассчитать');
        }
        var status = selector.querySelector('.ranau-russian-post-for-woocommerce-ems-status');
        setText(status, summaryText());
        toggleClass(status, 'is-error', state.addressDirty || Boolean(state.quote && state.quote.ok === false));
    }

    function renderSelectors() {
        state.renderQueued = false;
        document.querySelectorAll('.ranau-russian-post-for-woocommerce-blocks-selector').forEach(function (selector) {
            var value = selector.getAttribute('data-ranau-russian-post-for-woocommerce-rate') || '';
            var radio = document.querySelector('input[type="radio"][value="' + cssEscape(value) + '"]');
            if (!radio || !radio.checked) {
                selector.remove();
            }
        });
        document.querySelectorAll('input[type="radio"][value^="' + DOMESTIC_METHOD + '"]').forEach(function (radio) {
            if (radio.value.indexOf('ranau_russian_post_world') === 0) {
                return;
            }
            var option = radio.closest('label.wc-block-components-radio-control__option, label');
            if (!option || !radio.checked) {
                return;
            }
            var selector = document.querySelector('.ranau-russian-post-for-woocommerce-blocks-selector[data-ranau-russian-post-for-woocommerce-rate="' + cssEscape(radio.value) + '"]');
            if (!selector) {
                option.insertAdjacentHTML('afterend', selectorHtml(radio.value));
                selector = option.nextElementSibling;
            }
            updateSelector(selector);
        });
        document.querySelectorAll('[data-ranau-russian-post-for-woocommerce-classic]').forEach(function (selector) {
            var row = selector.closest('li, tr') || document;
            var radio = row.querySelector('input.shipping_method[value^="' + DOMESTIC_METHOD + '"]');
            toggleClass(selector, 'is-active', Boolean(radio && radio.checked));
            updateSelector(selector);
        });
        refreshRateLabel();
    }

    function scheduleRender() {
        if (!state.renderQueued) {
            state.renderQueued = true;
            window.requestAnimationFrame(renderSelectors);
        }
    }

    function pendingText() {
        return state.mode === MODE_ADDRESS ? 'Рассчитаем после адреса' : 'Рассчитаем после выбора ОПС';
    }

    function refreshRateLabel() {
        document.querySelectorAll('input[type="radio"][value^="' + DOMESTIC_METHOD + '"]').forEach(function (radio) {
            if (radio.value.indexOf('ranau_russian_post_world') === 0) {
                return;
            }
            var option = radio.closest('label.wc-block-components-radio-control__option, label');
            var target = option ? option.querySelector('.wc-block-components-radio-control__secondary-label') : null;
            if (target) {
                setAttribute(target, 'data-ranau-russian-post-for-woocommerce-rate-label', isReady() ? Number(state.quote.price).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' ₽' : pendingText());
            }
        });
        var checked = document.querySelector('input[type="radio"][value^="' + DOMESTIC_METHOD + '"]:checked');
        document.querySelectorAll('.wp-block-woocommerce-checkout-order-summary-shipping-block .wc-block-components-totals-item__value').forEach(function (target) {
            if (!checked || checked.value.indexOf('ranau_russian_post_world') === 0) {
                toggleClass(target, 'ranau-russian-post-for-woocommerce-order-summary-value', false);
                target.removeAttribute('data-ranau-russian-post-for-woocommerce-order-summary-value');
                return;
            }
            toggleClass(target, 'ranau-russian-post-for-woocommerce-order-summary-value', true);
            setAttribute(target, 'data-ranau-russian-post-for-woocommerce-order-summary-value', isReady() ? Number(state.quote.price).toLocaleString('ru-RU', {maximumFractionDigits: 2}) + ' ₽' : pendingText());
        });
    }

    function refreshCheckout(data) {
        if (window.wc && wc.blocksCheckout && wc.blocksCheckout.extensionCartUpdate) {
            return Promise.resolve(wc.blocksCheckout.extensionCartUpdate({namespace: config.storeApiNamespace, data: data}));
        } else if (window.jQuery) {
            window.jQuery(document.body).trigger('update_checkout');
        }
        return Promise.resolve();
    }

    function clearServerSelection() {
        var url = state.mode === MODE_ADDRESS ? '/address' : '/selection';
        return fetch(config.restUrl + url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || ''},
            body: JSON.stringify({method: DOMESTIC_METHOD, clear: true})
        }).catch(function () { return null; });
    }

    function switchMode(mode) {
        if (mode !== MODE_OPS && mode !== MODE_ADDRESS) {
            return;
        }
        state.mode = mode;
        state.selection = null;
        state.quote = null;
        state.addressDirty = false;
        clearServerSelection().then(function () {
            refreshCheckout({method: DOMESTIC_METHOD, delivery_mode: mode, postcode: '', point_id: '', address: ''});
            scheduleRender();
        });
        scheduleRender();
    }

    function ensureModal() {
        if (state.modal) {
            return state.modal;
        }
        var modal = document.createElement('div');
        modal.className = 'ranau-russian-post-for-woocommerce-modal';
        modal.innerHTML = '<div class="ranau-russian-post-for-woocommerce-dialog" role="dialog" aria-modal="true" aria-label="Выберите ОПС Почты России"><div class="ranau-russian-post-for-woocommerce-toolbar"><strong>Выберите ОПС Почты России</strong><button type="button" class="ranau-russian-post-for-woocommerce-close" aria-label="Закрыть">×</button></div><form class="ranau-russian-post-for-woocommerce-search"><input name="postcode" inputmode="numeric" pattern="[0-9]{6}" placeholder="Индекс получателя"><button type="submit">Найти</button></form><div class="ranau-russian-post-for-woocommerce-status" aria-live="polite"></div><div class="ranau-russian-post-for-woocommerce-points"></div><small class="ranau-russian-post-for-woocommerce-attribution">Ближайшие точки: <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">© OpenStreetMap contributors</a>. Выбранное ОПС подтверждается справочником Почты России.</small></div>';
        document.body.appendChild(modal);
        state.modal = modal;
        return modal;
    }

    function openModal() {
        state.mode = MODE_OPS;
        var modal = ensureModal();
        modal.classList.add('is-open');
        document.body.classList.add('ranau-russian-post-for-woocommerce-modal-open');
        loadPoints('');
        scheduleRender();
    }

    function closeModal() {
        if (state.modal) {
            state.modal.classList.remove('is-open');
        }
        document.body.classList.remove('ranau-russian-post-for-woocommerce-modal-open');
    }

    function loadPoints(postcode) {
        var modal = ensureModal();
        var params = new URLSearchParams();
        var center = currentCenter();
        var requestSeq = state.pointsRequestSeq + 1;
        var fetchOptions = {credentials: 'same-origin', headers: {'X-WP-Nonce': config.restNonce || ''}};
        state.pointsRequestSeq = requestSeq;
        if (state.pointsAbortController && typeof state.pointsAbortController.abort === 'function') {
            state.pointsAbortController.abort();
        }
        if (window.AbortController) {
            state.pointsAbortController = new AbortController();
            fetchOptions.signal = state.pointsAbortController.signal;
        } else {
            state.pointsAbortController = null;
        }
        if (postcode) {
            params.set('postcode', postcode);
        } else if (center) {
            params.set('lat', center.lat);
            params.set('lon', center.lon);
        }
        setText(modal.querySelector('.ranau-russian-post-for-woocommerce-status'), 'Загружаем ОПС');
        fetch(config.restUrl + '/points?' + params.toString(), fetchOptions)
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (requestSeq !== state.pointsRequestSeq) {
                    return;
                }
                state.points = payload.points || [];
                renderPoints();
            })
            .catch(function (error) {
                if (requestSeq !== state.pointsRequestSeq || (error && error.name === 'AbortError')) {
                    return;
                }
                setText(modal.querySelector('.ranau-russian-post-for-woocommerce-status'), 'Не удалось загрузить список ОПС. Укажите индекс вручную.');
            });
    }

    function renderPoints() {
        var modal = ensureModal();
        var list = modal.querySelector('.ranau-russian-post-for-woocommerce-points');
        setText(modal.querySelector('.ranau-russian-post-for-woocommerce-status'), state.points.length ? '' : 'ОПС не найдены. Попробуйте индекс получателя.');
        list.innerHTML = state.points.map(function (point) {
            return '<button type="button" class="ranau-russian-post-for-woocommerce-point" data-postcode="' + escapeHtml(point.postcode) + '"><strong>' + escapeHtml(point.postcode) + '</strong><span>' + escapeHtml(point.address || point.title) + '</span></button>';
        }).join('');
    }

    function saveSelection(postcode) {
        state.mode = MODE_OPS;
        var calculation;
        try {
            calculation = beginCalculation({delivery_mode: MODE_OPS, postcode: String(postcode || '')});
        } catch (error) {
            setText(ensureModal().querySelector('.ranau-russian-post-for-woocommerce-status'), 'Сначала выберите способ доставки Почтой России.');
            return;
        }
        fetch(config.restUrl + '/selection', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || ''},
            body: JSON.stringify({method: DOMESTIC_METHOD, postcode: postcode})
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload.message || 'selection_failed');
                }
                return payload;
            });
        }).then(function (payload) {
            publishCity(payload.selection);
            syncCheckoutAddress(payload.selection);
            return commitCalculation(calculation, payload).then(function () {
                payload.selection.committed = true;
                state.selection = payload.selection;
                state.quote = payload.quote;
            });
        }).then(function () {
            closeModal();
            renderSelectors();
            document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {
                detail: {method: DOMESTIC_METHOD, complete: isReady()}
            }));
        }).catch(function (error) {
            setText(ensureModal().querySelector('.ranau-russian-post-for-woocommerce-status'), error.message || 'Не удалось сохранить ОПС');
        });
    }

    function publishCity(point) {
        if (!point || !point.lat || !point.lon) {
            return;
        }
        var center = {lat: Number(point.lat), lon: Number(point.lon), zoom: 13, source: 'russian_post_ops', label: point.title || point.postcode || ''};
        if (window.RanauDeliveryCenter && typeof window.RanauDeliveryCenter.set === 'function') {
            window.RanauDeliveryCenter.set(center);
        } else {
            document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryCenterChanged', {detail: {center: center}}));
        }
    }

    function currentFieldValue(selectors) {
        for (var index = 0; index < selectors.length; index += 1) {
            var field = document.querySelector(selectors[index]);
            if (!field) {
                continue;
            }
            var value = 'value' in field ? field.value : field.textContent;
            value = String(value || '').trim();
            if (value) {
                return value;
            }
        }
        return '';
    }

    function currentCheckoutCity() {
        return currentFieldValue([
            '#shipping-city',
            '#shipping_city',
            'input[name="shipping_city"]',
            'input[autocomplete="address-level2"]',
            '#billing-city'
        ]);
    }

    function currentCheckoutState() {
        return currentFieldValue([
            '#shipping-state',
            '#shipping_state',
            'input[name="shipping_state"]'
        ]);
    }

    function checkoutAddressData(selection) {
        var deliveryMode = selection && selection.delivery_mode ? selection.delivery_mode : state.mode;
        var preserveCheckoutLocation = deliveryMode === MODE_OPS;
        return {
            address_1: selection.customer_address || selection.address || '',
            city: selection.city || (preserveCheckoutLocation ? currentCheckoutCity() : ''),
            state: selection.state || (preserveCheckoutLocation ? currentCheckoutState() : ''),
            postcode: selection.postcode || '',
            country: 'RU'
        };
    }

    function setFieldValue(field, value) {
        value = String(value || '');
        if (!field || field.value === value) {
            return;
        }
        var descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
        if (descriptor && descriptor.set) {
            descriptor.set.call(field, value);
        } else {
            field.value = value;
        }
        field.dispatchEvent(new Event('input', {bubbles: true}));
        field.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function syncCheckoutAddress(selection) {
        var data = checkoutAddressData(selection || {});
        var eventAddress = Object.assign({}, data);
        if (!eventAddress.city) {
            delete eventAddress.city;
        }
        if (!eventAddress.state) {
            delete eventAddress.state;
        }
        var shipping = {
            provider: 'russian_post',
            method: DOMESTIC_METHOD,
            delivery_mode: (selection && selection.delivery_mode) || state.mode,
            point_id: (selection && (selection.id || selection.postcode)) || '',
            office_id: (selection && selection.id) || '',
            office_name: (selection && selection.title) || '',
            office_address: (selection && selection.address) || '',
            address: eventAddress
        };
        [
            ['#shipping-address_1', 'address_1'],
            ['#shipping_address_1', 'address_1'],
            ['input[name="shipping_address_1"]', 'address_1'],
            ['input[autocomplete="shipping street-address"]:not(.ranau-russian-post-for-woocommerce-ems-address input)', 'address_1'],
            ['#shipping-city', 'city'],
            ['#shipping_city', 'city'],
            ['input[name="shipping_city"]', 'city'],
            ['input[autocomplete="address-level2"]', 'city'],
            ['#shipping-postcode', 'postcode'],
            ['#shipping_postcode', 'postcode'],
            ['input[name="shipping_postcode"]', 'postcode'],
            ['input[autocomplete="postal-code"]', 'postcode'],
            ['#shipping-state', 'state'],
            ['#shipping_state', 'state'],
            ['input[name="shipping_state"]', 'state']
        ].forEach(function (pair) {
            if ((pair[1] === 'city' || pair[1] === 'state') && !data[pair[1]]) {
                return;
            }
            document.querySelectorAll(pair[0]).forEach(function (field) {
                setFieldValue(field, data[pair[1]]);
            });
        });
        document.dispatchEvent(new CustomEvent('ranauCheckout:checkoutAddressUpdate', {detail: {shipping: shipping, selection: selection, address: eventAddress}}));
    }

    function selectedAddressRoot(node) {
        return node ? node.closest('.ranau-russian-post-for-woocommerce-unified-selector') : document.querySelector('.ranau-russian-post-for-woocommerce-unified-selector');
    }

    function markAddressDirty(input) {
        state.mode = MODE_ADDRESS;
        state.address = String(input.value || '').trim();
        if (!state.selection && !state.quote) {
            scheduleRender();
            return;
        }
        state.selection = null;
        state.quote = null;
        state.addressDirty = true;
        clearServerSelection().then(function () {
            refreshCheckout({method: DOMESTIC_METHOD, delivery_mode: MODE_ADDRESS, postcode: '', address: ''});
            scheduleRender();
        });
        scheduleRender();
    }

    function saveAddress(root, coords) {
        state.mode = MODE_ADDRESS;
        var input = root ? root.querySelector('.ranau-russian-post-for-woocommerce-ems-address input') : null;
        var address = input ? String(input.value || '').trim() : state.address;
        if (!address && !coords) {
            state.quote = {ok: false, message: 'Укажите адрес доставки.'};
            scheduleRender();
            return;
        }
        state.saving = true;
        state.addressDirty = false;
        scheduleRender();
        var calculation;
        try {
            calculation = beginCalculation({delivery_mode: MODE_ADDRESS, address: address, coordinates: coords || null});
        } catch (error) {
            state.saving = false;
            state.quote = {ok: false, message: 'Сначала выберите способ доставки Почтой России.'};
            scheduleRender();
            return;
        }
        fetch(config.restUrl + '/address', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || ''},
            body: JSON.stringify({method: DOMESTIC_METHOD, address: coords ? '' : address, lat: coords && coords.lat, lon: coords && coords.lon})
        }).then(function (response) {
            return response.json().then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload.message || 'address_failed');
                }
                return payload;
            });
        }).then(function (payload) {
            syncCheckoutAddress(payload.selection);
            return commitCalculation(calculation, payload).then(function () {
                payload.selection.committed = true;
                state.selection = payload.selection;
                state.quote = payload.quote;
                state.address = payload.selection && (payload.selection.customer_address || payload.selection.address) ? (payload.selection.customer_address || payload.selection.address) : address;
                state.saving = false;
                if (input) {
                    input.value = state.address;
                }
            });
        }).then(function () {
            scheduleRender();
            document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {detail: {method: DOMESTIC_METHOD, complete: isReady()}}));
        }).catch(function (error) {
            state.saving = false;
            state.selection = null;
            state.quote = {ok: false, message: error.message || 'Не удалось подтвердить адрес.'};
            scheduleRender();
        });
    }

    function ensureAddressMap(root) {
        if (!root || state.mapReady || !config.yandexMapApiKey) {
            if (root && !config.yandexMapApiKey) {
                setText(root.querySelector('.ranau-russian-post-for-woocommerce-ems-status'), 'Карта сейчас недоступна, но адрес можно ввести текстом.');
            }
            return;
        }
        var shell = root.querySelector('.ranau-russian-post-for-woocommerce-ems-map-shell');
        var canvas = root.querySelector('.ranau-russian-post-for-woocommerce-ems-map-canvas');
        if (!shell || !canvas) {
            return;
        }
        shell.hidden = false;
        loadYandexMaps(function () {
            window.ymaps.ready(function () {
                var center = currentCenter() || {lat: 55.755864, lon: 37.617698, zoom: 11};
                state.map = new window.ymaps.Map(canvas, {center: [Number(center.lat), Number(center.lon)], zoom: Number(center.zoom || 11), controls: ['zoomControl']});
                state.mapObject = new window.ymaps.Placemark([Number(center.lat), Number(center.lon)], {}, {draggable: true});
                state.map.geoObjects.add(state.mapObject);
                state.map.events.add('click', function (event) {
                    var coords = event.get('coords');
                    state.mapObject.geometry.setCoordinates(coords);
                    saveAddress(root, {lat: coords[0], lon: coords[1]});
                });
                state.mapObject.events.add('dragend', function () {
                    var coords = state.mapObject.geometry.getCoordinates();
                    saveAddress(root, {lat: coords[0], lon: coords[1]});
                });
                state.mapReady = true;
            });
        });
    }

    function loadYandexMaps(done) {
        if (window.ymaps && window.ymaps.ready) {
            done();
            return;
        }
        var existing = document.querySelector('script[data-ranau-russian-post-for-woocommerce-yandex-map]');
        if (existing) {
            existing.addEventListener('load', done, {once: true});
            return;
        }
        var script = document.createElement('script');
        script.src = 'https://api-maps.yandex.ru/2.1/?lang=ru_RU&apikey=' + encodeURIComponent(config.yandexMapApiKey || '');
        script.async = true;
        script.setAttribute('data-ranau-russian-post-for-woocommerce-yandex-map', '1');
        script.addEventListener('load', done, {once: true});
        script.addEventListener('error', function () {
            document.querySelectorAll('.ranau-russian-post-for-woocommerce-ems-status').forEach(function (status) {
                setText(status, 'Карта не загрузилась, но адрес можно ввести текстом.');
            });
        }, {once: true});
        document.head.appendChild(script);
    }

    function clearSelection() {
        clearServerSelection().then(function () {
            state.selection = null;
            state.quote = null;
            state.addressDirty = false;
            refreshCheckout({method: DOMESTIC_METHOD, delivery_mode: state.mode, postcode: '', point_id: '', address: ''});
            renderSelectors();
        }).catch(function () { return null; });
    }

    document.addEventListener('click', function (event) {
        var modeButton = event.target.closest('.ranau-russian-post-for-woocommerce-mode-button');
        if (modeButton) {
            switchMode(modeButton.getAttribute('data-mode') || MODE_OPS);
        } else if (event.target.closest('.ranau-russian-post-for-woocommerce-open')) {
            openModal();
        } else if (event.target.closest('.ranau-russian-post-for-woocommerce-ems-map')) {
            state.mode = MODE_ADDRESS;
            ensureAddressMap(selectedAddressRoot(event.target));
            scheduleRender();
        } else if (event.target.closest('.ranau-russian-post-for-woocommerce-ems-save')) {
            saveAddress(selectedAddressRoot(event.target), null);
        } else if (event.target.closest('.ranau-russian-post-for-woocommerce-clear')) {
            clearSelection();
        } else if (event.target.closest('.ranau-russian-post-for-woocommerce-close')) {
            closeModal();
        } else {
            var point = event.target.closest('.ranau-russian-post-for-woocommerce-point');
            if (point) {
                saveSelection(point.getAttribute('data-postcode') || '');
            } else if (state.modal && event.target === state.modal) {
                closeModal();
            }
        }
    });
    document.addEventListener('submit', function (event) {
        if (event.target.matches('.ranau-russian-post-for-woocommerce-search')) {
            event.preventDefault();
            loadPoints((new FormData(event.target).get('postcode') || '').toString());
        }
    });
    document.addEventListener('input', function (event) {
        if (event.target && event.target.matches('.ranau-russian-post-for-woocommerce-ems-address input')) {
            markAddressDirty(event.target);
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target && event.target.matches('.ranau-russian-post-for-woocommerce-ems-address input')) {
            event.preventDefault();
            saveAddress(selectedAddressRoot(event.target), null);
        }
        if (event.key === 'Escape') {
            closeModal();
        }
    });
    document.addEventListener('change', function (event) {
        if (event.target && event.target.matches('input[type="radio"], input.shipping_method')) {
            scheduleRender();
        }
    });
    document.addEventListener('ranauCheckout:cityContextChanged', function () {
        state.selection = null;
        state.quote = null;
        state.address = '';
        state.addressDirty = false;
        closeModal();
        scheduleRender();
        document.dispatchEvent(new CustomEvent('ranauCheckout:deliveryStateChanged', {
            detail: {method: DOMESTIC_METHOD, complete: false}
        }));
    });
    document.addEventListener('updated_checkout', scheduleRender);
    document.addEventListener('wc-blocks_added_to_cart', scheduleRender);
    window.setInterval(scheduleRender, 750);
    scheduleRender();
}());
