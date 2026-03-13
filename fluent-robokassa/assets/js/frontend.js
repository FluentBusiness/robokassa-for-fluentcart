(function () {
    'use strict';

    // Своя кнопка отмены подписки в ЛК
    document.addEventListener('DOMContentLoaded', function () {

        function replaceButton(button) {
            if (!button || button.classList.contains('robobtn')) {
                return;
            }

            button.classList.add('robobtn');
            button.classList.remove('x-small');

            const svg = button.querySelector('svg');
            if (svg) {
                svg.remove();
            }

            button.textContent = 'Отменить подписку';

            // === Inline стили с !important ===
            button.style.setProperty('width', '150px', 'important');
            button.style.setProperty('border', '2px solid #000', 'important');
            button.style.setProperty('border-radius', '6px', 'important');
            button.style.setProperty('display', 'inline-flex', 'important');
            button.style.setProperty('justify-content', 'center', 'important');
            button.style.setProperty('align-items', 'center', 'important');
            button.style.setProperty('cursor', 'pointer', 'important');
        }

        function scan() {
            const button = document.querySelector(
                '.fct-customer-dashboard-single-subcription-wrap .fct-single-order-body .right-content .icon-button'
            );

            if (button) {
                replaceButton(button);
            }
        }

        // первый запуск
        scan();

        // наблюдатель за SPA-перерисовками
        const observer = new MutationObserver(function () {
            scan();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

    });



})();
