(function () {
  'use strict';

  // IFRAME оплата

  // --- Константы конфигурации ---
  var STATUS_ENDPOINT = '/wp-json/robokassa/v1/status';
  var DEFAULT_POLL_INTERVAL = 800; // ms
  var DEFAULT_POLL_TIMEOUT = 30000; // ms

  // --- Защита от повторной загрузки ---
  if (window.__robokassa_loaded) return;
  window.__robokassa_loaded = true;

  // --- Внутреннее состояние ---
  var runningPolls = {}; // invId -> { handle, startedAt }

  // --- Утилиты ---
  function norm(p) {
    if (!p) return null;
    if (typeof p === 'object') return p;
    try { return JSON.parse(p); } catch (e) { console.error('robokassa: norm parse error', e); return null; }
  }

  function ensureContainer() {
    var c = document.getElementById('robokassa-iframe-container');
    if (c) return c;
    c = document.createElement('div');
    c.id = 'robokassa-iframe-container';
    c.style.minHeight = '20px';
    var notice = document.querySelector('.robokassa-redirect-notice');
    if (notice && notice.parentNode) notice.parentNode.insertBefore(c, notice.nextSibling);
    else document.body.appendChild(c);
    return c;
  }

  function loadSdk(url, cb) {
    try {
      if (window.Robokassa && typeof window.Robokassa.StartPayment === 'function') { setTimeout(cb, 0); return; }
      var selector = 'script[data-robokassa-sdk="1"], script[src="' + url + '"]';
      var existing = document.querySelector(selector);
      if (existing) {
        if (existing.readyState && (existing.readyState === 'loaded' || existing.readyState === 'complete')) { setTimeout(cb, 20); }
        else { existing.addEventListener ? existing.addEventListener('load', cb) : existing.onload = cb; }
        return;
      }
      var s = document.createElement('script');
      s.async = true;
      s.src = url;
      s.setAttribute('data-robokassa-sdk', '1');
      s.onload = function () { setTimeout(cb, 20); };
      s.onerror = function (e) { console.error('robokassa: failed to load sdk', e); cb(); };
      (document.head || document.body || document.documentElement).appendChild(s);
    } catch (e) { console.error('robokassa: loadSdk error', e); try { cb(); } catch (_) {} }
  }

  // Выполнение inline-скриптов, если payload вставлен в <script> как текст
  function execInline() {
    document.querySelectorAll('script').forEach(function (sc) {
      try {
        if (sc.dataset && sc.dataset.robokassaProcessed) return;
        var text = (sc.textContent || sc.innerText || '').trim();
        if (text.indexOf('RobokassaParams') !== -1 || text.indexOf('RobokassaIframeUrl') !== -1) {
          var r = document.createElement('script');
          r.type = 'text/javascript';
          r.text = text;
          (document.head || document.body || document.documentElement).appendChild(r);
          r.parentNode.removeChild(r);
          sc.dataset.robokassaProcessed = '1';
        }
      } catch (e) { console.error('robokassa: execInline error', e); }
    });
  }

  if (window.MutationObserver) {
    try {
      new MutationObserver(function (muts) {
        muts.forEach(function (m) {
          Array.prototype.forEach.call(m.addedNodes, function (n) {
            if (!n) return;
            if (n.nodeType === 1 && (n.tagName.toLowerCase() === 'script' || (n.querySelectorAll && n.querySelectorAll('script').length))) {
              execInline();
            }
          });
        });
      }).observe(document.documentElement || document.body, { childList: true, subtree: true });
    } catch (e) { console.error('robokassa: MutationObserver init error', e); }
  }

  // --- REST check helper: делает несколько попыток (tries) с задержкой (delayMs) ---
  function checkStatusWithRetries(transactionUuid, tries, delayMs, cb) {
    if (!transactionUuid) { cb(null); return; }
    var attempt = 0;

    function once() {
      attempt++;
      fetch(STATUS_ENDPOINT + '?transaction_uuid=' + encodeURIComponent(invId) + '&_=' + Date.now(), { credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) throw new Error('http ' + r.status);
          return r.json();
        })
        .then(function (json) {
          if (json && json.status === 'succeeded') {
            cb(json);
            return;
          }
          if (attempt < tries) {
            setTimeout(once, delayMs);
            return;
          }
          cb(json || null);
        })
        .catch(function (err) {
          console.error('robokassa: status fetch error', err);
          if (attempt < tries) setTimeout(once, delayMs);
          else cb(null);
        });
    }

    once();
  }

  // --- Polling: работает параллельно и сразу редиректит если увидит succeeded от REST ---
  function startPolling(invId, opts) {
    if (!invId) return;
    if (runningPolls[invId]) return;

    opts = opts || {};
    var interval = typeof opts.interval === 'number' ? opts.interval : DEFAULT_POLL_INTERVAL;
    var timeout  = typeof opts.timeout  === 'number' ? opts.timeout  : DEFAULT_POLL_TIMEOUT;

    var startedAt = Date.now();
    var stopped = false;
    var handle = null;

    function stop() {
      if (stopped) return;
      stopped = true;
      if (handle) clearInterval(handle);
      delete runningPolls[invId];
    }

    function tick() {
      if (stopped) return;
      if (Date.now() - startedAt > timeout) { stop(); return; }

      fetch(STATUS_ENDPOINT + '?transaction_uuid=' + encodeURIComponent(invId) + '&_=' + Date.now(), { credentials: 'same-origin' })
        .then(function (r) {
          if (!r.ok) throw new Error('http ' + r.status);
          return r.json();
        })
        .then(function (json) {
          if (!json) return;
          if (json.status === 'succeeded') {
            stop();
            // Редирект на URL от сервера (приоритет success_url -> receipt_url)
            if (json.success_url) { window.location.href = json.success_url; return; }
            if (json.receipt_url)  { window.location.href = json.receipt_url; return; }
            // иначе — если сервер подтвердил оплату, но не дал URL, пусть страница перезагрузится и фронт обновит контент
            window.location.reload();
          }
        })
        .catch(function (err) {
          console.error('robokassa: polling fetch error for invId=' + invId, err);
        });
    }

    handle = setInterval(tick, interval);
    runningPolls[invId] = { handle: handle, startedAt: startedAt };
    tick();
  }

  function stopPolling(invId) {
    var rec = runningPolls[invId];
    if (rec && rec.handle) {
      clearInterval(rec.handle);
      delete runningPolls[invId];
    }
  }

  // экспортируем для отладки/внешнего управления
  window.__robokassa = window.__robokassa || {};
  window.__robokassa.startPolling = startPolling;
  window.__robokassa.stopPolling  = stopPolling;

  // --- Наблюдение за iframe (обработка закрытия / удаления) ---
  function waitForIframeAndAttachObserver(invId, params) {
    var attempts = 0;
    var maxAttempts = 120;
    var timer = setInterval(function () {
      attempts++;
      try {
        var iframe = document.getElementById('robokassa_iframe');
        if (iframe) {
          clearInterval(timer);
          attachCloseObserverToIframe(iframe, invId, params);
          return;
        }
      } catch (e) { console.error('robokassa: waitForIframeAndAttachObserver error', e); }
      if (attempts >= maxAttempts) { clearInterval(timer); console.warn('robokassa: iframe not found after wait'); }
    }, 100);
  }

  function attachCloseObserverToIframe(iframe, invId, params) {
    var closedByUser = false;
  try {
    var lastVis = (iframe.style && String(iframe.style.visibility || '')).toLowerCase();

    // helper: безопасно остановить глобальный polling если он запущен
    function safeStopGlobalPolling(id) {
      try { if (window.__robokassa && typeof window.__robokassa.stopPolling === 'function') window.__robokassa.stopPolling(id); } catch (_) {}
    }

    // При закрытии — делаем короткие повторные проверки статуса на сервере.
    // checkStatusWithRetries(invId, tries, delayMs, cb) — у вас уже определена выше.
    function handleFinish(invIdLocal, paramsLocal) {
        // ⬅️ ВАЖНО: крестик — сразу reload, без ожиданий
  if (closedByUser) {
    window.location.reload();
    return;
  }

      if (!invIdLocal) {
        // Если invId нет —fallback: если в params есть success_url, редиректим на него, иначе reload.
        try {
          if (paramsLocal && paramsLocal.success_url) { window.location.href = paramsLocal.success_url; return; }
        } catch (e) { /* ignore */ }
        window.location.reload();
        return;
      }

      // Остановим глобальный polling, чтобы не мешал.
      safeStopGlobalPolling(invIdLocal);

      // Попробуем несколько раз опросить REST — если увидим succeeded, делаем корректный редирект,
      // иначе — считаем, что пользователь закрыл iframe и просто reload.
      checkStatusWithRetries(invIdLocal, 10, 500, function(json) {
        try {
          if (json && json.status === 'succeeded') {
            // приоритет: success_url от REST -> receipt_url от REST -> success_url из params -> _receipt_url из params -> reload
            if (json.success_url) { window.location.href = json.success_url; return; }
            if (json.receipt_url)  { window.location.href = json.receipt_url; return; }
            if (paramsLocal && paramsLocal.success_url) { window.location.href = paramsLocal.success_url; return; }
            if (paramsLocal && paramsLocal._receipt_url) { window.location.href = paramsLocal._receipt_url; return; }
            // если success, но URL не пришёл — reload, фронт обновится
            window.location.reload();
            return;
          }
        } catch (e) {
          console.error('robokassa: redirect after status check error', e);
        }
        // не подтверждена — просто обновим страницу (поведение при крестике)
        window.location.reload();
      });
    }

    var obs = new MutationObserver(function (muts) {
      muts.forEach(function (m) {
        if (m.type === 'attributes') {
          try {
            var vis = (iframe.style && String(iframe.style.visibility || '')).toLowerCase();
            try {
              var cs = window.getComputedStyle && window.getComputedStyle(iframe);
              if (cs && cs.visibility) vis = (cs.visibility || vis).toLowerCase();
            } catch (e) { /* ignore */ }

            var src = iframe.getAttribute('src') || '';

            // детектируем закрытие: видимость стала hidden ИЛИ src пустой
            if ((lastVis === 'visible' && vis === 'hidden') || !src) {
              try { obs.disconnect(); } catch (_) {}

                // если явно скрыли iframe — считаем крестиком
  if (lastVis === 'visible' && vis === 'hidden') {
    closedByUser = true;
  }

              handleFinish(invId, params);
            }

            lastVis = vis;
          } catch (e) { console.error('robokassa: observer attributes handler error', e); }
        }
      });
    });

    obs.observe(iframe, { attributes: true, attributeFilter: ['style', 'src'] });

    // наблюдаем удаление iframe из DOM — делаем ту же логику
    var parent = iframe.parentNode;
    if (parent) {
      var pObs = new MutationObserver(function (muts) {
        muts.forEach(function (m) {
          if (m.type === 'childList' && Array.prototype.indexOf.call(m.removedNodes || [], iframe) !== -1) {
            try { pObs.disconnect(); } catch (_) {}
            try { obs.disconnect(); } catch (_) {}

            // ⬅️ ВАЖНО: удаление iframe = крестик
  closedByUser = true;

            handleFinish(invId, params);
          }
        });
      });
      pObs.observe(parent, { childList: true });
    }
  } catch (e) {
    console.error('robokassa: attachCloseObserverToIframe error', e);
    setTimeout(function () { window.location.reload(); }, 400);
  }
}


  // --- Основная логика запуска оплаты ---
  function start(url, params) {
    params = norm(params);
    if (!url || !params) { console.warn('robokassa: start called without url/params', { url: url, params: params }); return; }

    try { ensureContainer(); } catch (e) { console.error('robokassa: container error', e); }

    var invId = params.InvId || params.Shp_order_id || params.inv_id || null;

    var transactionUuid = params.Shp_transaction_uuid || null;

    loadSdk(url, function () {
      if (!window.Robokassa || typeof window.Robokassa.StartPayment !== 'function') {
        console.error('robokassa: SDK StartPayment not available after load');
        return;
      }
      try {
        window.Robokassa.StartPayment(params);
      } catch (e) {
        console.error('robokassa: StartPayment threw', e);
        return;
      }

      // наблюдатель для iframe; передаём params как fallback, invId для REST-проверок
      waitForIframeAndAttachObserver(transactionUuid, params);

      // запустим долгий polling параллельно (можно уменьшить/выключить, если не нужен)
      if (transactionUuid) startPolling(transactionUuid, { interval: DEFAULT_POLL_INTERVAL, timeout: DEFAULT_POLL_TIMEOUT });
    });
  }

  // --- Подписка на событие (bootstrap от сервера) ---
  function onParams(e) {
    var d = e && e.detail ? e.detail : {};
    start(d.iframeUrl || window.RobokassaIframeUrl, d.params || window.RobokassaParams);
  }

  window.addEventListener('robokassa:params', onParams, false);

  // Авто-инициализация, если globals уже установлены
  if (window.RobokassaIframeUrl && window.RobokassaParams) {
    start(window.RobokassaIframeUrl, window.RobokassaParams);
  }

  // --- Экспорт минимальных хелперов в глобал для отладки ---
  window.__robokassa = window.__robokassa || {};
  window.__robokassa.start = start;
  window.__robokassa.startPolling = startPolling;
  window.__robokassa.stopPolling = stopPolling;

})();