(function () {
    'use strict';

    // Отображение виджета на странице товара


    let widgetInserted = false;
    let widgetInitialized = false;
    let lastPaymentMethod = null;

    // ================== DOM READY ==================
    function onReady(cb) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', cb);
        } else {
            cb();
        }
    }

    // ================== INSERT WIDGET ==================
    function insertRobokassaWidget() {
        if (widgetInserted) return;

        if (typeof window.robokassaFluentCartWidget === 'undefined' || !robokassaFluentCartWidget.enabled) {
            return;
        }

        const data = robokassaFluentCartWidget;
        const container = document.querySelector('.fct-product-buttons-wrap');
        if (!container) return;

        if (document.querySelector('robokassa-widget, robokassa-badge')) {
            widgetInserted = true;
            return;
        }

        const tag = data.component === 'badge' ? 'robokassa-badge' : 'robokassa-widget';
        const attrs = { ...data.attributes, id: `${tag}-${data.productId}` };

        let attrStr = '';
        for (const k in attrs) {
            if (attrs[k] !== null && attrs[k] !== '') {
                attrStr += ` ${k}="${attrs[k]}"`;
            }
        }

        container.insertAdjacentHTML(
            'beforebegin',
            `<div class="robokassa-widget-wrapper">
                <${tag}${attrStr}></${tag}>
            </div>`
        );

        widgetInserted = true;
        setTimeout(initRobokassaWidget, 100);
    }

    // ================== INIT ROBOKASSA ==================
    function initRobokassaWidget() {
        if (widgetInitialized) return;

        if (typeof window.initRobokassaBadges === 'function') {
            window.initRobokassaBadges();
            widgetInitialized = true;
            return;
        }

        if (window.Robokassa && window.Robokassa.initWidgets) {
            window.Robokassa.initWidgets();
            widgetInitialized = true;
            return;
        }

        let tries = 0;
        const timer = setInterval(() => {
            tries++;
            if (typeof window.initRobokassaBadges === 'function') {
                window.initRobokassaBadges();
                widgetInitialized = true;
                clearInterval(timer);
            }
            if (tries > 10) clearInterval(timer);
        }, 300);
    }

    // ================== IFRAME MESSAGE ==================
    window.addEventListener('message', function (event) {
        // origin-check — оставляем, но гибко: 'robokassa' в origin
        try {
            if (!event.origin || !event.origin.includes('robokassa')) return;
        } catch (e) {
            return;
        }

        const d = event.data;
        if (!d) return;

        // берем первое непустое значение и сохраняем его (trim)
        let candidate = null;
        if (typeof d === 'object') {
            if (d.paymentMethod != null) candidate = d.paymentMethod;
            else if (d.payment_method != null) candidate = d.payment_method;
            else if (d.payload && d.payload.payment_method != null) candidate = d.payload.payment_method;
            else if (d.redirectUrl) {
                // попытка вытащить из redirectUrl, если есть
                try {
                    const tmp = new URL(d.redirectUrl, window.location.origin);
                    const pm = tmp.searchParams.get('payment_method');
                    if (pm) candidate = pm;
                } catch (e) {
                    const m = String(d.redirectUrl).match(/payment_method=([^&]+)/i);
                    if (m && m[1]) candidate = decodeURIComponent(m[1]);
                }
            }
        } else if (typeof d === 'string') {
            const m = d.match(/payment_method=([^&]+)/i);
            if (m && m[1]) candidate = decodeURIComponent(m[1]);
        }

        if (candidate != null) {
            const trimmed = String(candidate).trim();
            if (trimmed !== '') {
                lastPaymentMethod = trimmed;
                console.log('Robokassa payment_method from iframe:', lastPaymentMethod);
            }
        }
    });

    // helper: возвращает первое непустое строковое значение
    function firstNonEmpty(...vals) {
        for (let v of vals) {
            if (v == null) continue;
            v = String(v).trim();
            if (v !== '') return v;
        }
        return null;
    }

    // ================== CHECKOUT ==================
    window.robokassaWidgetHandleCheckout = function (payload) {

        // Получаем candidate из payload (если есть) — учитываем пустые строки
        const payloadCandidate = firstNonEmpty(
            payload && payload.payment_method,
            payload && payload.paymentMethod,
            payload && payload.payload && payload.payload.payment_method
        );

        // Выбираем: payloadCandidate -> lastPaymentMethod -> 'robokassa'
        const method = payloadCandidate || lastPaymentMethod || 'robokassa';

        const baseUrl =
            window.robokassaFluentCartWidget?.checkoutUrl ||
            (payload && payload.redirectUrl) ||
            window.location.href;

        try {
            const url = new URL(baseUrl, window.location.origin);
            url.searchParams.set('payment_method', method);
            window.location.href = url.toString();
        } catch (e) {
            // в редких случаях baseUrl может быть некорректным: fallback — простая конкатенация
            const sep = baseUrl.includes('?') ? '&' : '?';
            const final = baseUrl + sep + 'payment_method=' + encodeURIComponent(method);
            console.log('Robokassa redirect (fallback) →', final);
            window.location.href = final;
        }

        return false;
    };

    // ================== BOOTSTRAP ==================
    onReady(() => setTimeout(insertRobokassaWidget, 800));

    window.addEventListener('load', () => {
        if (!widgetInserted) {
            setTimeout(insertRobokassaWidget, 1500);
        }
    });

    new MutationObserver(() => {
        if (!widgetInserted && document.querySelector('.fct-product-buttons-wrap')) {
            insertRobokassaWidget();
        }
    }).observe(document.body, { childList: true, subtree: true });

})();