(function () {
  'use strict';
    var ajaxurl = '/wp-admin/admin-ajax.php';

    // Отображение и работа кнопки Холдирования в админке

    async function tryInsert() {
        const group = document.querySelector('.fct-single-order-wrapper .single-page-header .fct-btn-group');
        if (!group) { 
            return; 
        }
        if (group.querySelector('.hold-accept')) { 
            return; 
        }

        const hasHoldStatus = document.querySelector('.single-page-header-status-wrap .badge.warning');
        const hasWaitingPayment = document.querySelector('.fct-card.fct-order-payment-card .fct-card-header-title .badge.warning');
        const hasSuccessTransaction = document.querySelector('.fct-transaction-details .badge.success');

        if (!hasHoldStatus || !hasWaitingPayment || !hasSuccessTransaction) {
            return;
        }

        const hashMatch = window.location.hash.match(/\/orders\/(\d+)\/view/);
        if (!hashMatch) {
            return;
        }

        const firstBtn = group.querySelector('button');
        if (!firstBtn) { 
            return; 
        }

        const btn = firstBtn.cloneNode(true);
        btn.className = '';
        btn.classList.add('el-button', 'hold-accept');
        btn.removeAttribute('disabled');
        btn.removeAttribute('aria-disabled');

        const span = btn.querySelector('span');
        if (span) span.textContent = 'Холдирование';

        group.insertBefore(btn, firstBtn);
    }

    // Клик через делегирование — orderId читается из hash в момент нажатия
    document.addEventListener('click', async function(e) {

        const btn = e.target.closest('.hold-accept');
        if (!btn) return;

        e.preventDefault();
        e.stopPropagation();
        if (btn.classList.contains('is-loading')) {
            return;
        }

        const hashMatch = window.location.hash.match(/\/orders\/(\d+)\/view/);
        const orderId = hashMatch ? hashMatch[1] : null;

        if (!orderId) {
            console.error('[hold] orderId not found in hash:', window.location.hash);
            return;
        }

        const span = btn.querySelector('span');
        const originalText = span ? span.textContent : '';

        btn.classList.add('is-loading');
        btn.setAttribute('disabled', 'disabled');
        if (span) span.textContent = 'Обработка...';

        try {
            const body = new URLSearchParams({
                action: 'hold_accept_order',
                order_id: orderId
            });

            const res = await fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                credentials: 'same-origin'
            });

            const data = await res.json();
            setTimeout(() => { window.location.reload(); }, 2000);

        } catch (err) {
            console.error('[hold] Error hold_accept_order:', err);
        } finally {
            btn.classList.remove('is-loading');
            btn.removeAttribute('disabled');
            if (span) span.textContent = originalText;
        }
    }, true);

    const observer = new MutationObserver(tryInsert);
    observer.observe(document.body, { childList: true, subtree: true });

    window.addEventListener('hashchange', tryInsert, false);

    tryInsert();




    // Скрытие полей при выборе Казахстана
    function normalize(s) {
    return (s || '').toString().replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function findRowByLabel(labelText) {
    var rows = document.querySelectorAll('.fct-setting-row');
    var need = normalize(labelText);
    for (var i = 0; i < rows.length; i++) {
        var lbl = rows[i].querySelector('.setting-label');
        if (lbl && normalize(lbl.textContent).indexOf(need) !== -1) return rows[i];
        var alt = rows[i].querySelector('p, label, .el-select__selected-item, .fct-settings-description');
        if (alt && normalize(alt.textContent).indexOf(need) !== -1) return rows[i];
    }
    return null;
    }

    function collectRowsBetweenLabels(startLabel, endLabel, includeBounds) {
    var startRow = findRowByLabel(startLabel);
    var endRow   = findRowByLabel(endLabel);
    if (!startRow || !endRow) return [];

    var container = startRow.closest('.fct_live_mode, .el-tab-pane') || startRow.parentElement;
    var all = Array.from(container.querySelectorAll('.fct-setting-row'));
    var si = all.indexOf(startRow);
    var ei = all.indexOf(endRow);
    if (si === -1 || ei === -1) return [];
    if (si > ei) { var tmp = si; si = ei; ei = tmp; }

    var from = includeBounds ? si : si + 1;
    var to   = includeBounds ? ei + 1 : ei;
    return all.slice(from, to);
    }

    function collectRowsBetweenLabelVariants(startVariants, endVariants, includeBounds) {
    var starts = Array.isArray(startVariants) ? startVariants : [startVariants];
    var ends   = Array.isArray(endVariants) ? endVariants : [endVariants];
    for (var i = 0; i < starts.length; i++) {
        for (var j = 0; j < ends.length; j++) {
        var res = collectRowsBetweenLabels(starts[i], ends[j], includeBounds);
        if (res && res.length) return res;
        }
    }
    return [];
    }

    function readCountry() {
    var countryRow = findRowByLabel('Страна магазина') || findRowByLabel('Country of the store') || findRowByLabel('country');
    if (!countryRow) return '';
    var ph = countryRow.querySelector('.el-select__selected-item .el-select__placeholder span, .el-select__selected-item span');
    if (ph && ph.textContent.trim()) return ph.textContent.trim();
    var inp = countryRow.querySelector('input.el-select__input, input.el-input__inner');
    if (inp && inp.value) return inp.value.trim();
    var sel = countryRow.querySelector('select');
    if (sel && sel.value) return sel.value;
    return (countryRow.textContent || '').trim();
    }

    function readSno() {
    var snoRow = findRowByLabel('Система налогообложения') || findRowByLabel('Taxation system');
    if (!snoRow) return '';
    var ph = snoRow.querySelector('.el-select__placeholder span');
    if (ph) return ph.textContent.trim().toLowerCase();
    return '';
    }

    function setRowsVisible(rows, visible) {
    rows.forEach(function(r) { r.style.display = visible ? '' : 'none'; });
    }

    function setRowVisible(label, visible) {
    var row = findRowByLabel(label);
    if (row) row.style.display = visible ? '' : 'none';
    }

    function setRowAndNextVisible(label, visible) {
    var row = findRowByLabel(label);
    if (!row) return;
    row.style.display = visible ? '' : 'none';
    var next = row.nextElementSibling;
    if (next && next.classList.contains('fct-setting-row')) {
        next.style.display = visible ? '' : 'none';
    }
    }

    // --- фискализация ---
    function updateFiscalVisibility() {
    var countryText = (readCountry() || '').toLowerCase();
    var isKZ = countryText.includes('kz') || countryText.includes('казахстан');

    // Только для RU — скрыть при KZ
    var ruOnlyLabels = [
        'Taxation system',                'Система налогообложения',
        'Calculation method indicator',   'Признак способа расчёта',
        'Calculation subject indicator',  'Признак предмета расчёта для товаров/услуг',
        'Invoice item indicator',         'Признак предмета расчёта для товаров/услуг (второй чек)',
        'Item of payment for delivery',   'Признак предмета расчёта для доставки',
    ];
    ruOnlyLabels.forEach(function(label) { setRowVisible(label, !isKZ); });

    if (isKZ) {
        // KZ — всегда показываем
        setRowVisible('Source of tax rate',        true);
        setRowVisible('Источник налоговой ставки', true);
        setRowVisible('Tax rate',                  true);
        setRowVisible('Налоговая ставка',          true);
    } else {
        // RU — только для ОСН и УСН доходы/доходы-расходы
        var sno = readSno();
        var shouldShowTax = (
        sno.includes('общая') ||
        sno.includes('осн')   ||
        sno.includes('упрощ') // покрывает "упрощённая сн (доходы)" и "упрощённая сн (доходы-расходы)"
        );
        setRowVisible('Source of tax rate',        shouldShowTax);
        setRowVisible('Источник налоговой ставки', shouldShowTax);
        setRowVisible('Tax rate',                  shouldShowTax);
        setRowVisible('Налоговая ставка',          shouldShowTax);
    }
    }

    // --- агентский блок ---
    function updateAgencyVisibility() {
    var rows = collectRowsBetweenLabelVariants(
        ['Agency Products', 'Агентские товары'],
        ['Deferred payments', 'Отложенные платежи'],
        true
    );
    if (!rows.length) return;

    var countryText = (readCountry() || '').toLowerCase();
    var isKZ = countryText.includes('kz') || countryText.includes('казахстан');
    setRowsVisible(rows, !isKZ);
    }

    // --- остальные поля для KZ ---
    function updateKzSpecificRows() {
    var countryText = (readCountry() || '').toLowerCase();
    var isKZ = countryText.includes('kz') || countryText.includes('казахстан');
    var showRU = !isKZ;

    setRowVisible('Status for automatic issuance of the second check', showRU);
    setRowVisible('Статус для автоматического выбивания второго чека', showRU);

    setRowAndNextVisible('Deferred payments', showRU);
    setRowAndNextVisible('Отложенные платежи', showRU);

    setRowVisible('Additional payment methods', showRU);
    setRowVisible('Дополнительные способы оплаты', showRU);

    [
        'Installment or credit options', 'Рассрочка или кредит',
        'Robokassa X Share',             'Robokassa Х Подели',       // кириллическая Х
        'Robokassa X Mokka',
        'Robokassa X Yandex Split',      'Robokassa X Яндекс Сплит', // латинская X
    ].forEach(function(label) {
        setRowAndNextVisible(label, showRU);
    });
    }

    // --- вкладка "Виджет и бейдж" ---
    function hideWidgetTabForKZ() {
    var countryText = (readCountry() || '').toLowerCase();
    var isKZ = countryText.indexOf('kz') !== -1 || countryText.indexOf('казахстан') !== -1;

    var tab = document.querySelector('#tab-widget');
    if (tab) tab.style.display = isKZ ? 'none' : '';
    // pane не трогаем — FluentCart управляет им сам
    }

    function updateAll() {
    updateFiscalVisibility();
    updateAgencyVisibility();
    updateKzSpecificRows();
    hideWidgetTabForKZ();
    }

    // --- init + observers ---
    function init() {
    [100, 400, 900, 1400].forEach(t => setTimeout(updateAll, t));

    document.addEventListener('click', updateAll, true);
    document.addEventListener('input', updateAll, true);
    document.addEventListener('change', updateAll, true);

    var mo = new MutationObserver(updateAll);
    mo.observe(document.body, { childList: true, subtree: true });

    var attempts = 0;
    var iv = setInterval(() => {
        updateAll();
        attempts++;
        if (attempts > 20) clearInterval(iv);
    }, 300);

    window.__robokassa_update = updateAll;
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

})();
