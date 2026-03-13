(function () {
    'use strict';

    // Основной скрипт для оплаты платежей

    // Настройки
    var DEFAULT_SUBMIT_DELAY = 200;
    var DEFAULT_MANUAL_DELAY = 6000;

    // --- Вспомогательные функции ---
    function toPositiveInt(value, fallback) {
        var n = parseInt(value, 10);
        return isNaN(n) || n < 0 ? fallback : n;
    }
    function safeParseJson(text) {
        try { return JSON.parse(text); } catch (e) { return null; }
    }

    // --- 1. Логика для обычных форм (Автосабмит) ---
    function appendAndSubmitForm(form, wrapper) {
        if (!form) return;
        if (form.id && document.getElementById(form.id)) {
            var existing = document.getElementById(form.id);
            if (existing) { submitCloned(existing, wrapper); }
            return;
        }
        var clone = form.cloneNode(true);
        clone.style.display = 'none';
        clone.target = '_top';
        clone.setAttribute('data-robokassa-generated', '1');
        document.body.appendChild(clone);
        var submitDelay = toPositiveInt(wrapper && wrapper.dataset.submitDelay, DEFAULT_SUBMIT_DELAY);
        setTimeout(function () {
            try { clone.submit(); } catch (e) { console.error('robokassa submit error', e); }
        }, submitDelay);
    }
    function submitCloned(form, wrapper) {
        try {
            var clone = form.cloneNode(true);
            clone.style.display = 'none';
            clone.target = '_top';
            document.body.appendChild(clone);
            setTimeout(function () {
                try { clone.submit(); } catch (e) { console.error(e); }
            }, DEFAULT_SUBMIT_DELAY);
        } catch (e) { console.error('submitCloned error', e); }
    }
    function buildFormFromActionFields(action, fields, wrapper) {
        if (!action || typeof fields !== 'object') return;
        var signature = action + '|' + Object.keys(fields).sort().join(',');
        if (document.querySelector('form[data-robokassa-sign="' + signature + '"]')) return;
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = action;
        form.style.display = 'none';
        form.setAttribute('data-robokassa-sign', signature);
        Object.keys(fields).forEach(function (name) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = fields[name] === null ? '' : String(fields[name]);
            form.appendChild(input);
        });
        appendAndSubmitForm(form, wrapper || document.body);
    }

    // --- 2. Логика для Direct Payment ---
    var robokassaSDKLoading = false;
    function handleDirectPayment() {
        var scripts = document.querySelectorAll('script[data-robokassa-init="direct"]');
        scripts.forEach(function(script) {
            if (script.dataset.robokassaProcessed) return;
            script.dataset.robokassaProcessed = '1';
            var scriptText = script.textContent || script.innerHTML || '';
            var paramsMatch = scriptText.match(/startOp\((\{.*?\})\)/s);
            if (!paramsMatch) return;
            var params;
            try { params = JSON.parse(paramsMatch[1]); } catch (e) { return; }
            // Всегда загружаем SDK своим скриптом для контроля
            loadAndInitDirectPayment(params);
        });
    }
    function loadAndInitDirectPayment(params) {
        // Если SDK уже готов, сразу запускаем платеж
        if (window.Robo && window.Robo.directPayment && typeof window.Robo.directPayment.startOp === 'function') {
            executeDirectPayment(params);
            return;
        }
        // Если уже грузим SDK, просто добавляем параметры в ожидание
        if (robokassaSDKLoading) {
            setTimeout(function() { loadAndInitDirectPayment(params); }, 100);
            return;
        }
        robokassaSDKLoading = true;
        var script = document.createElement('script');
        script.src = 'https://auth.robokassa.ru/Merchant/PaymentForm/DirectPayment.js';
        var loaded = false;
        function onLoad() {
            if (loaded) return;
            loaded = true;
            robokassaSDKLoading = false;
            // Даем 500мс на инициализацию глобального объекта Robo
            setTimeout(function() { executeDirectPayment(params); }, 500);
        }
        script.onload = onLoad;
        script.onerror = function() {
            console.error('Robokassa DirectPayment SDK failed to load.');
            robokassaSDKLoading = false;
        };
        // Таймаут на случай зависания
        setTimeout(function() { if (!loaded) onLoad(); }, 3000);
        document.head.appendChild(script);
    }
    function executeDirectPayment(params) {
        if (window.Robo && window.Robo.directPayment && typeof window.Robo.directPayment.startOp === 'function') {
            try {
                window.Robo.directPayment.startOp(params);
            } catch (error) {
                console.error('Robokassa.directPayment.startOp error:', error);
            }
        } else {
            // Если Robo все еще нет, пробуем еще раз через 200мс
            setTimeout(function() { executeDirectPayment(params); }, 200);
        }
    }

    // --- 3. Основная функция вставки HTML и обработки ---
    function insertRobokassaHtml(response) {
        if (!response || !response.data) return;
        // Обработка action+fields (обычная форма)
        if (response.data.action && response.data.fields && typeof response.data.fields === 'object') {
            buildFormFromActionFields(response.data.action, response.data.fields, document.body);
            return;
        }
        // Обработка HTML
        if (!response.data.html) return;
        var html = response.data.html;
        var container = document.querySelector('.payment_method_robokassa') ||
                       document.querySelector('.fluentcart-checkout') ||
                       document.querySelector('.fluent-checkout') ||
                       document.body;
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var wrapper = doc.querySelector('.robokassa-redirect-wrapper');
        var formId = wrapper ? (wrapper.getAttribute('data-form-id') || '') : '';
        if (formId && container.querySelector('.robokassa-redirect-wrapper[data-form-id="' + formId + '"]')) {
            return;
        }
        container.insertAdjacentHTML('beforeend', html);
        // Немедленно запускаем поиск и обработку Direct Payment скриптов
        setTimeout(handleDirectPayment, 10);
        // Логика для обычных форм из вставленного HTML
        var insertedWrapper = container.querySelector('.robokassa-redirect-wrapper' + (formId ? '[data-form-id="' + formId + '"]' : ''));
        if (!insertedWrapper) {
            var maybeForm = container.querySelector('form#' + formId);
            if (maybeForm) appendAndSubmitForm(maybeForm, insertedWrapper);
            return;
        }
        var tpl = insertedWrapper.querySelector('template.robokassa-form-template');
        if (tpl) {
            var form = null;
            try {
                if (tpl.content && tpl.content.querySelector) {
                    var node = tpl.content.querySelector('form');
                    if (node) form = node.cloneNode(true);
                } else {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = tpl.innerHTML.trim();
                    form = tmp.querySelector('form') ? tmp.querySelector('form').cloneNode(true) : null;
                }
            } catch (e) { form = null; }
            if (form) { appendAndSubmitForm(form, insertedWrapper); return; }
        }
        var hiddenInputs = insertedWrapper.querySelectorAll('input[type="hidden"]');
        if (hiddenInputs && hiddenInputs.length) {
            var fallbackForm = document.createElement('form');
            fallbackForm.method = 'POST';
            fallbackForm.style.display = 'none';
            var action = insertedWrapper.dataset.action || insertedWrapper.getAttribute('data-action') || '';
            if (action) fallbackForm.action = action;
            hiddenInputs.forEach(function (inp) {
                var cloned = document.createElement('input');
                cloned.type = 'hidden';
                cloned.name = inp.name || inp.getAttribute('name');
                cloned.value = inp.value;
                fallbackForm.appendChild(cloned);
            });
            appendAndSubmitForm(fallbackForm, insertedWrapper);
        }
    }
    window.RobokassaInsertHtml = insertRobokassaHtml;

    // --- 4. Перехватчики AJAX (без jQuery) ---
    if (window.fetch) {
        var originalFetch = window.fetch;
        window.fetch = function() {
            return originalFetch.apply(this, arguments).then(function(response) {
                var responseClone = response.clone();
                responseClone.text().then(function(text) {
                    var json = safeParseJson(text);
                    if (json && (json.nextAction === 'robokassa' || json.data?.html)) {
                        insertRobokassaHtml(json);
                    }
                }).catch(function() {});
                return response;
            });
        };
    }
    (function() {
        var XHR = window.XMLHttpRequest;
        if (!XHR) return;
        var open = XHR.prototype.open;
        var send = XHR.prototype.send;
        XHR.prototype.open = function(method, url) {
            this._robokassaUrl = url;
            return open.apply(this, arguments);
        };
        XHR.prototype.send = function() {
            var xhr = this;
            function handleResponse() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    var url = xhr._robokassaUrl || '';
                    if (url.includes('admin-ajax.php') || url.includes('/wp-admin/admin-ajax.php')) {
                        var json = safeParseJson(xhr.responseText);
                        if (json && (json.nextAction === 'robokassa' || json.data?.html)) {
                            insertRobokassaHtml(json);
                        }
                    }
                }
            }
            xhr.addEventListener('readystatechange', handleResponse);
            return send.apply(this, arguments);
        };
    })();

    // --- 5. Инициализация и наблюдение за DOM ---
    function init() {
        // Первичная обработка скриптов Direct Payment
        setTimeout(handleDirectPayment, 100);
        // Наблюдатель для динамически добавленных элементов
        if (window.MutationObserver) {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    if (m.addedNodes.length) {
                        // Быстрая проверка на добавление релевантных элементов
                        var node = m.addedNodes[0];
                        if (node.nodeType === 1) {
                            if (node.matches && node.matches('.robokassa-redirect-wrapper')) return;
                            if (node.querySelector && node.querySelector('script[data-robokassa-init]')) {
                                setTimeout(handleDirectPayment, 50);
                            }
                        }
                    }
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 100);
    }
})();