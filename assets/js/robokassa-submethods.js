(function () {
    'use strict';

    // Поддержка дополнительных методов оплаты на странице оформления заказа

    var cfg = window.fluentRobokassaConfig || {};
    cfg.restUrl = cfg.restUrl || '/wp-json/robokassa/v1/aliases';
    cfg.labels = cfg.labels || { title: 'Выберите способ оплаты', standardTitle: 'Стандарт (Robokassa)' };
    cfg.debug = !!cfg.debug;

    var URL_MAP = {
        'yandexpaysplit': 'robokassa_split',
        'podeli': 'robokassa_podeli',
        'otp': 'robokassa_credit',
        'mokka': 'robokassa_mokka',
        'robokassa': 'robokassa'
    };

    var ALIAS_MAP = {
        'yandexpaysplit': 'robokassa_split',
        'podeli': 'robokassa_podeli',
        'otp': 'robokassa_credit',
        'mokka': 'robokassa_mokka'
    };

    var SELECTED_SUBMETHOD_KEY = 'robokassa_selected_submethod';
    var currentSubmethod = null;

    function $q(s, ctx) { return (ctx || document).querySelector(s); }
    function $qa(s, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(s)); }

    function getContainer() {
        return $q('.fluent-cart-checkout_embed_payment_container_robokassa');
    }

    function saveSelectedSubmethod(gatewayId) {
        if (!gatewayId) return;
        try { localStorage.setItem(SELECTED_SUBMETHOD_KEY, gatewayId); } catch (e) { console.warn(e); }
        try { sessionStorage.setItem(SELECTED_SUBMETHOD_KEY, gatewayId); } catch (e) {}
        currentSubmethod = gatewayId;
        if (cfg.debug) console.log('[robokassa] saveSelectedSubmethod ->', gatewayId);
    }

    // helper: проверяет есть ли в URL параметр payment_method
    function hasPaymentMethodInUrl() {
        try {
            var params = new URLSearchParams(window.location.search);
            return params.get('payment_method') !== null;
        } catch (e) {
            return false;
        }
    }

    // изменённая функция: если в URL НЕТ payment_method — НЕ читаем localStorage/sessionStorage
    function restoreSelectedSubmethod() {
        var fromUrl = getSubmethodFromURL();
        if (fromUrl) {
            saveSelectedSubmethod(fromUrl);
            if (cfg.debug) console.log('[robokassa] restoreSelectedSubmethod -> from URL:', fromUrl);
            return fromUrl;
        }

        // если в URL нет payment_method, игнорируем local/session и возвращаем null
        if (!hasPaymentMethodInUrl()) {
            // выводим консольную подсказку (всегда, чтобы вы видели что сработало)
            //console.info('[robokassa] No payment_method in URL — ignoring saved selection and using default.');
            return null;
        }

        try {
            var fromLocal = localStorage.getItem(SELECTED_SUBMETHOD_KEY);
            if (fromLocal) {
                currentSubmethod = fromLocal;
                if (cfg.debug) console.log('[robokassa] restoreSelectedSubmethod -> from localStorage:', fromLocal);
                return fromLocal;
            }
        } catch (e) {}

        try {
            var fromSession = sessionStorage.getItem(SELECTED_SUBMETHOD_KEY);
            if (fromSession) {
                currentSubmethod = fromSession;
                if (cfg.debug) console.log('[robokassa] restoreSelectedSubmethod -> from sessionStorage:', fromSession);
                return fromSession;
            }
        } catch (e) {}

        return null;
    }

    function getSubmethodFromURL() {
        try {
            var urlParams = new URLSearchParams(window.location.search);

            var directParam = urlParams.get('robokassa_gateway');
            if (directParam && URL_MAP[directParam]) return URL_MAP[directParam];

            var paymentMethod = urlParams.get('payment_method');
            if (paymentMethod && URL_MAP[paymentMethod]) return URL_MAP[paymentMethod];

            if (urlParams.get('source') === 'robokassa_widget') return 'robokassa';
        } catch (e) { console.warn('Ошибка парсинга URL:', e); }
        return null;
    }

    function getOrderAmount() {
        var els = document.querySelectorAll('[data-fluent-cart-checkout-estimated-total]');
        if (!els.length) return null;
        var el = els[els.length - 1];
        var text = el.textContent.trim();
        if (cfg.debug) console.log('[robokassa] raw amount text:', text);

        // убираем всё кроме цифр, точек и запятых
        var cleaned = text.replace(/[^\d.,]/g, '');
        if (!cleaned) return null;

        var amount;

        var hasDot   = cleaned.indexOf('.') !== -1;
        var hasComma = cleaned.indexOf(',') !== -1;

        if (hasDot && hasComma) {
            // определяем что идёт последним — это дробный разделитель
            var lastDot   = cleaned.lastIndexOf('.');
            var lastComma = cleaned.lastIndexOf(',');
            if (lastDot > lastComma) {
                // формат: 5,001.00 — запятая разделитель тысяч
                amount = parseFloat(cleaned.replace(/,/g, ''));
            } else {
                // формат: 5.001,00 — точка разделитель тысяч
                amount = parseFloat(cleaned.replace(/\./g, '').replace(',', '.'));
            }
        } else if (hasComma) {
            // только запятая — либо дробная (1,50) либо тысячи (1,000)
            var parts = cleaned.split(',');
            if (parts.length === 2 && parts[1].length <= 2) {
                // 1,50 — дробная часть
                amount = parseFloat(cleaned.replace(',', '.'));
            } else {
                // 1,000 — разделитель тысяч
                amount = parseFloat(cleaned.replace(/,/g, ''));
            }
        } else if (hasDot) {
            // только точка — либо дробная (1.50) либо тысячи (1.000)
            var parts = cleaned.split('.');
            if (parts.length === 2 && parts[1].length <= 2) {
                // 1.50 — дробная часть
                amount = parseFloat(cleaned);
            } else {
                // 1.000 — разделитель тысяч
                amount = parseFloat(cleaned.replace(/\./g, ''));
            }
        } else {
            // просто цифры
            amount = parseFloat(cleaned);
        }

        if (cfg.debug) console.log('[robokassa] parsed amount:', amount);
        return (!isNaN(amount) && amount > 0) ? amount : null;
    }

    function setupFluentCartListeners() {
        var events = [
            'fluentcart_checkout_updated',
            'fluentcart_shipping_updated',
            'fluentcart_coupon_applied',
            'fluentcart_cart_updated'
        ];
        events.forEach(function (eventName) {
            document.addEventListener(eventName, function () {
                var activeBtn = document.querySelector('.robokassa-submethod__btn.active');
                if (activeBtn) {
                    var gatewayId = activeBtn.getAttribute('data-mapped');
                    saveSelectedSubmethod(gatewayId);
                }
                setTimeout(function () { loadAndRender(); }, 100);
            });
        });
    }

    function renderAliases(container, aliases) {
        if (!container) return;
        if (!Array.isArray(aliases) || aliases.length === 0) { container.innerHTML = ''; return; }

        var root = document.createElement('div');
        root.className = 'robokassa-submethods';

        var title = document.createElement('div');
        title.className = 'robokassa-submethods__title';
        title.textContent = cfg.labels.title;
        root.appendChild(title);

        var description = document.createElement('div');
        description.className = 'robokassa-submethods__description';
        description.textContent = cfg.labels.description;
        root.appendChild(description);

        var list = document.createElement('div');
        list.className = 'robokassa-submethods__list';

        var savedSubmethod = restoreSelectedSubmethod();
        var hasSavedSelection = false;

        aliases.forEach(function (a) {
            if (!a || !a.Alias) return;
            if (a.allowed === false) return;

            var rawAlias = String(a.Alias || '').trim();
            var normalized = normalizeKey(rawAlias);
            var mapped = ALIAS_MAP[normalized] || 'robokassa';
            var isStandard = !!a.is_standard || mapped === 'robokassa';

            var label = document.createElement('label');
            label.className = 'robokassa-submethod';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'robokassa-submethod__btn';
            btn.setAttribute('data-raw-alias', rawAlias);
            btn.setAttribute('data-mapped', mapped);
            btn.setAttribute('data-standard', isStandard ? '1' : '0');

            var titleText = a.Title || rawAlias;
            if (isStandard && (titleText === rawAlias || /^standard$/i.test(normalized))) {
                titleText = cfg.labels.standardTitle;
            }

            var span = document.createElement('span');
            span.className = 'robokassa-submethod__title';
            span.textContent = titleText;
            btn.appendChild(span);

            label.appendChild(btn);
            list.appendChild(label);

            if (savedSubmethod && savedSubmethod === mapped) {
                btn.classList.add('active');
                hasSavedSelection = true;
            }

            btn.addEventListener('click', function (ev) {
                ev.preventDefault();
                clearActive(list);
                btn.classList.add('active');
                saveSelectedSubmethod(mapped);
                setAliasValue(container, mapped);
            });
        });

        root.appendChild(list);
        container.innerHTML = '';
        container.appendChild(root);

        if (!hasSavedSelection) {
            var standardBtn = container.querySelector('.robokassa-submethod__btn[data-standard="1"]');
            var firstBtn = standardBtn || container.querySelector('.robokassa-submethod__btn');
            if (firstBtn) {
                clearActive(container);
                firstBtn.classList.add('active');
                var mapped = firstBtn.getAttribute('data-mapped');
                saveSelectedSubmethod(mapped);
                setAliasValue(container, mapped);
            }
        }

        notifyFluentCart();
    }

    function ensureAliasInput(form) {
        if (!form) return null;
        var existing = form.querySelector('input[name="robokassa_alias"]');
        if (existing) return existing;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'robokassa_alias';
        input.value = 'robokassa';
        form.appendChild(input);
        return input;
    }

    function normalizeKey(raw) {
        if (!raw) return '';
        return String(raw).toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    function setAliasValue(container, mappedGatewayId) {
        if (!container) return;
        var form = container.closest('form') || document.querySelector('form');
        if (!form) return;
        var input = ensureAliasInput(form);
        if (!input) return;
        input.value = mappedGatewayId || 'robokassa';
        if (cfg.debug) console.log('[robokassa] robokassa_alias set ->', input.value);
    }

    function clearActive(listRoot) {
        if (!listRoot) return;
        $qa('.robokassa-submethod__btn', listRoot).forEach(function (b) {
            b.classList.remove('active');
        });
    }

    function notifyFluentCart() {
        var input = document.getElementById('fluent_cart_payment_method_robokassa');
        if (input) {
            input.checked = true;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function loadAndRender() {
        var container = getContainer();
        if (!container) return;

        var params = [];

        // fct_cart_hash для "купить сразу"
        try {
            var cartHash = new URLSearchParams(window.location.search).get('fct_cart_hash');
            if (cartHash) params.push('fct_cart_hash=' + encodeURIComponent(cartHash));
        } catch (e) {}

        // сумма из DOM как fallback
        var amount = getOrderAmount();
        if (amount !== null) {
            params.push('amount=' + amount);
        }

        var restUrl = cfg.restUrl + (params.length ? '?' + params.join('&') : '');

        if (cfg.debug) console.log('[robokassa] loadAndRender url:', restUrl, 'amount:', amount);

        fetch(restUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('REST ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data && data.page_title) cfg.labels.title = data.page_title;
                if (data && data.page_description) cfg.labels.description = data.page_description;
                renderAliases(container, Array.isArray(data.aliases) ? data.aliases : []);
                setTimeout(notifyFluentCart, 100);
            })
            .catch(function (err) {
                log('[robokassa] aliases load failed', err);
                renderAliases(container, [{
                    Alias: 'STANDARD',
                    Title: cfg.labels.standardTitle,
                    allowed: true,
                    is_standard: true
                }]);
            });
    }

    function log() {
        if (!cfg.debug) return;
        try { console.log.apply(console, arguments); } catch (e) { }
    }

    // waitForElement helper
    function waitForElement(selector, timeout) {
        timeout = timeout || 5000;
        return new Promise(function (resolve) {
            var el = document.querySelector(selector);
            if (el) return resolve(el);
            var t = 0;
            var iv = setInterval(function () {
                t += 200;
                el = document.querySelector(selector);
                if (el) {
                    clearInterval(iv);
                    return resolve(el);
                }
                if (t >= timeout) {
                    clearInterval(iv);
                    return resolve(null);
                }
            }, 200);
        });
    }

    // robust activation & selection
    function activateRobokassaAndSelectSubmethodFromUrl() {
        try {
            var params = new URLSearchParams(window.location.search);
            var pmRaw = params.get('payment_method');
            if (!pmRaw) return Promise.resolve(false);

            var pm = String(pmRaw).trim();
            if (pm === '') return Promise.resolve(false);

            console.info('[robokassa] URL param payment_method detected ->', pm);

            return waitForElement('.fct_payment_method_robokassa', 5000).then(function (robokassaWrapper) {
                if (!robokassaWrapper) {
                    console.warn('[robokassa] wrapper not found for activation');
                    return false;
                }

                $qa('.fct_payment_method_wrapper').forEach(function (el) {
                    el.classList.remove('active');
                    var embedWrap = el.querySelector('.fluent-cart-checkout_embed_payment_wrapper');
                    if (embedWrap) embedWrap.classList.remove('active');
                });

                robokassaWrapper.classList.add('active');
                var robokassaInput = document.getElementById('fluent_cart_payment_method_robokassa');
                if (robokassaInput) {
                    try {
                        robokassaInput.checked = true;
                        robokassaInput.setAttribute('checked', 'true');
                        robokassaInput.setAttribute('aria-checked', 'true');
                        robokassaInput.dispatchEvent(new Event('change', { bubbles: true }));
                    } catch (e) { /* ignore */ }
                }

                var embed = robokassaWrapper.querySelector('.fluent-cart-checkout_embed_payment_wrapper');
                if (embed) embed.classList.add('active');

                // wait for submethods, then select matching
                return waitForElement('.robokassa-submethod__btn', 5000).then(function (btn) {
                    if (!btn) return true;

                    var mappedCandidate = URL_MAP[pm] || null;
                    var found = null;
                    var buttons = $qa('.robokassa-submethod__btn', getContainer());
                    var pmUpper = pm.toUpperCase();

                    for (var i = 0; i < buttons.length; i++) {
                        var b = buttons[i];
                        var raw = (b.getAttribute('data-raw-alias') || '').toUpperCase();
                        var mapped = b.getAttribute('data-mapped') || '';

                        if (raw === pmUpper) { found = b; break; }
                        if (mappedCandidate && mapped === mappedCandidate) { found = b; break; }
                    }

                    if (found) {
                        try {
                            found.click();
                            console.info('[robokassa] selected submethod by URL param ->', found.getAttribute('data-mapped') || found.getAttribute('data-raw-alias'));
                        } catch (e) {
                            var listRoot = getContainer().querySelector('.robokassa-submethods__list') || getContainer();
                            clearActive(listRoot);
                            found.classList.add('active');
                            setAliasValue(getContainer(), found.getAttribute('data-mapped'));
                        }
                    } else {
                        console.info('[robokassa] no matching submethod for param:', pm);
                    }

                    return true;
                });
            });
        } catch (e) {
            console.error('[robokassa] activateRobokassaAndSelectSubmethodFromUrl error', e);
            return Promise.resolve(false);
        }
    }

    // BOOT
    (function boot() {
        // Сначала активируем Robokassa если в URL есть payment_method
        activateRobokassaAndSelectSubmethodFromUrl().finally(function () {
            // затем восстанавливаем выбор — но теперь restoreSelectedSubmethod игнорирует localStorage если в URL нет payment_method
            restoreSelectedSubmethod();

            setupFluentCartListeners();

            var tries = 0, maxTries = 40;
            var t = setInterval(function () {
                var c = getContainer();
                if (c || tries >= maxTries) {
                    clearInterval(t);
                    if (c) loadAndRender();
                }
                tries++;
            }, 200);
        });
    })();

    (function observeCheckout() {
        function start() {
            var target = document.body;
            if (!target || target.nodeType !== 1) return;

            var last = null;
            var obs = new MutationObserver(function () {
                try {
                    var c = getContainer();
                    if (!c) { last = null; return; }
                    if (c !== last) {
                        last = c;
                        setTimeout(function () { loadAndRender(); }, 50);
                    }
                } catch (e) { log(e); }
            });
            obs.observe(target, { childList: true, subtree: true });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    })();

    window.robokassaSubmethods = {
        reload: loadAndRender,
        saveSelection: saveSelectedSubmethod,
        getCurrentSelection: function () { return currentSubmethod; },
        setSelection: function (gatewayId) {
            saveSelectedSubmethod(gatewayId);
            loadAndRender();
        },
        mapping: ALIAS_MAP,
        urlMapping: URL_MAP
    };

})();