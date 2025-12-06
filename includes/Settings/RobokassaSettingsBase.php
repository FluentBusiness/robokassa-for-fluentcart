<?php
/**
 * Robokassa Settings Class (полный набор полей)
 */

namespace RobokassaFluentCart\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class RobokassaSettingsBase extends BaseGatewaySettings
{

    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_robokassa';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        if (is_array($settings)) {
            $settings = Arr::mergeMissingValues($settings, $defaults);
        }

        $this->settings = $settings;
    }

    /**
     * Дефолтные значения настроек
     */
    
    public static function getDefaults()
    {
        return [
            'is_active'        => 'no',
            'payment_mode'     => 'live',
            'test_mode'        => 'yes',
            'debug_mode'       => 'yes',

            'robokassa_payment_wc_robokassa_enabled' => 'no',
            'RobokassaOrderPageTitle_robokassa' => '',
            'RobokassaOrderPageDescription_robokassa' => '',
            'robokassa_payment_MerchantLogin' => '',
            'robokassa_payment_shoppass1' => '',
            'robokassa_payment_shoppass2' => '',
            'robokassa_payment_test_onoff' => '',
            'robokassa_payment_testshoppass1' => '',
            'robokassa_payment_testshoppass2' => '',
            'robokassa_payment_sno' => 'fckoff',
            'robokassa_payment_tax' => 'none',
            'robokassa_payment_tax_source' => '',
            'robokassa_payment_agent_fields_enabled' => '',
            'robokassa_payment_who_commission' => '',
            'robokassa_payment_size_commission' => '',
            'robokassa_payment_paytype' => '',
            'robokassa_payment_SuccessURL' => '',
            'robokassa_payment_FailURL' => '',
            'robokassa_payment_paymentMethod' => '',
            'robokassa_payment_paymentObject' => '',
            'robokassa_payment_second_check_paymentObject' => '',
            'robokassa_payment_paymentObject_shipping' => '',
            'robokassa_patyment_markup' => '',
            'robokassa_culture' => 'ru',
            'robokassa_iframe' => '0',
            'robokassa_country_code' => 'RU',
            'robokassa_out_currency' => '',
            'robokassa_agreement_text' => 'Я даю согласие на регулярные списания, на <a href="%s">обработку персональных данных</a> и принимаю условия <a
href="%s">публичной оферты</a>',
            'robokassa_agreement_pd_link' => '',
            'robokassa_agreement_oferta_link' => '',
            'robokassa_payment_hold_onoff' => '',
            'robokassa_payment_order_status_after_payment' => '',
            'robokassa_payment_order_status_for_second_check' => '',
            'robokassa_payment_method_credit_enabled' => 'yes',
            'robokassa_payment_method_podeli_enabled' => 'yes',
            'robokassa_payment_method_mokka_enabled' => 'yes',
            'robokassa_payment_method_split_enabled' => 'yes',
        ];
    }


    /**
     * Возвращаем поля в формате FluentCart (tabs, notice, html_attr и т.д.)
     */
    public function getFields(): array
    {
        $resultUrl  = site_url('/?robokassa=result');
        $successUrl = site_url('/?robokassa=success');
        $failUrl    = site_url('/?robokassa=fail');

        $notice_topHtml = '<div class="fct-col">'
            . '<p class="setting-label">ResultURL</p><div class="el-input"><code id="robokassa_result_url">' . esc_html($resultUrl) . '</code></div>'
            . '</div>'
            . '<div class="fct-col">'
            . '<p class="setting-label">SuccessURL</p><div class="el-input"><code id="robokassa_success_url">' . esc_html($successUrl) . '</code></div>'
            . '</div>'
            . '<div class="fct-col">'
            . '<p class="setting-label">FailURL</p><div class="el-input"><code id="robokassa_fail_url">' . esc_html($failUrl) . '</code></div>'
            . '</div>';


        /*
        error_log(
    'Robokassa: $this->settings type = ' . gettype($this->settings)
    . ' / dump: ' . print_r($this->settings, true)
);
        */

        $settings = is_array($this->settings) ? $this->settings : $this->settings->get();

        $htmlList = '<ul style="margin:0;padding-left:18px;">';
        foreach ($settings as $key => $value) {
            $val = is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $htmlList .= "<li><strong>{$key}</strong>: {$val}</li>";
        }
        $htmlList .= '</ul>';


        $notice_robokassa_online = '<p>Для передачи данных в чек используйте арендованную <a href="https://robokassa.com/online-check/robokassa-online/" target="_blank" rel="noopener noreferrer">Робокасса Онлайн</a>. С решением «Робочеки» агентский признак не будет передан в чек.</p><p>После включения в карточке товара появится блок «Агентский товар Robokassa». Заполните тип агента, наименование, ИНН и телефоны, чтобы данные попали в чек.</p>';

        $notice_payment_hold = '<p>Данная <a href="https://docs.robokassa.ru/holding/" target="_blank" rel="noopener noreferrer">услуга</a> доступна только по предварительному согласованию.</p><p>Функционал доступен только при использовании банковских карт.</p><p><a href="https://docs.robokassa.ru/media/guides/hold_woocommerce.pdf" target="_blank" rel="noopener noreferrer">Инструкция по настройке</a></p>';

        return [
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => 'Основные',
                        'value'  => 'live',
                        'schema' => [
                            'notice-top-title' => [
                                'type'  => 'notice',
                                'label' => '',
                                'value' => '<p>API-ключи и параметры подключение магазина</p><h3>Помощь и инструкция по установке</h3><p>1. Введите данные API в разделе «Основные настройки».</p><p>2. В личном кабинете Robokassa укажите следующие URL-адреса для уведомлений:</p>',
                            ],
                            'notice-top-html' => [
                                'type'  => 'notice',
                                'label' => '',
                                'value' => $notice_topHtml,
                            ],
                            'notice-top-bot' => [
                                'type'  => 'notice',
                                'label' => '',
                                'value' => '<p>Метод отсылки данных - POST</p><p>Алгоритм расчёта хеша - MD5</p><p>После этого заполните логин и пароли магазина в полях ниже</p>',
                            ],
                            'notice-main-settings' => [
                                'type'  => 'notice',
                                'value' => '<h3>Основные настройки</h3>',
                            ],
                            'robokassa_payment_wc_robokassa_enabled' => [
                                'type'    => 'enable',
                                'label' => 'Оплата через Робокассу',
                                'default' => 'no',
                            ],
                            'RobokassaOrderPageTitle_robokassa' => [
                                'value'       => '',
                                'label'       => 'Заголовок на странице оформления заказа',
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'RobokassaOrderPageDescription_robokassa' => [
                                'value'       => '',
                                'label'       => 'Описание на странице оформления заказа',
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'robokassa_country_code' => [
                                'type'    => 'select',
                                'label'   => 'Страна магазина',
                                'options' => [
                                    ['value' => 'RU', 'label' => 'Россия'],
                                    ['value' => 'KZ', 'label' => 'Казахстан'],
                                ],
                            ],
                            'robokassa_payment_MerchantLogin' => [
                                'value'       => '',
                                'label'       => 'Идентификатор магазина',
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'robokassa_payment_shoppass1' => [
                                'value'       => '',
                                'label'       => 'Пароль магазина #1',
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                            'robokassa_payment_shoppass2' => [
                                'value'       => '',
                                'label'       => 'Пароль магазина #2',
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                            'robokassa_culture' => [
                                'type'    => 'select',
                                'label'   => 'Язык интерфейса робокассы',
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, \RobokassaFluentCart\RobokassaHelper::$culture),
                            ],
                            'robokassa_iframe' => [
                                'type'    => 'select',
                                'label'   => 'Включить iframe',
                                'options' => [
                                    ['value' => '0', 'label' => 'Отключено'],
                                    ['value' => '1', 'label' => 'Включено'],
                                ],
                            ],
                            'notice-iframe' => [
                                'type'  => 'notice',
                                'value' => 'При включённом iframe, способов оплаты меньше, чем в обычной платежной странице - только карты, Apple и Samsung pay, Qiwi. incurlabel работает, но ограничено.',
                            ],


                            'notice-dop-oplata' => [
                                'type'  => 'notice',
                                'value' => '<h3>Дополнительные способы оплаты</h3><p>Выберите партнёрские решения Robokassa, которые нужно показывать клиентам при оформлении заказа.</p>',
                            ],
                            'notice-robokassa_payment_method_credit' => [
                                'type'  => 'notice',
                                'value' => 'Рассрочка или кредит. Способ оплаты недоступен для магазина.</p>',
                            ],
                            'robokassa_payment_method_credit_enabled' => [
                                'type'    => 'checkbox',
                                'label' => 'Отображать способ оплаты',
                                'default' => 'yes',
                                'description' => ''
                            ],
                            'notice-robokassa_payment_method_podeli' => [
                                'type'  => 'notice',
                                'value' => 'Robokassa Х Подели. Способ оплаты недоступен для магазина.</p>',
                            ],
                            'robokassa_payment_method_podeli_enabled' => [
                                'type'    => 'checkbox',
                                'label' => 'Отображать способ оплаты',
                                'default' => 'yes',
                                'description' => ''
                            ],
                            'notice-robokassa_payment_method_mokka' => [
                                'type'  => 'notice',
                                'value' => 'Robokassa X Mokka. Способ оплаты недоступен для магазина.</p>',
                            ],
                            'robokassa_payment_method_mokka_enabled' => [
                                'type'    => 'checkbox',
                                'label' => 'Отображать способ оплаты',
                                'default' => 'yes',
                                'description' => ''
                            ],
                            'notice-robokassa_payment_method_split' => [
                                'type'  => 'notice',
                                'value' => 'Robokassa X Яндекс Сплит. Способ оплаты недоступен для магазина.</p>',
                            ],
                            'robokassa_payment_method_split_enabled' => [
                                'type'    => 'checkbox',
                                'label' => 'Отображать способ оплаты',
                                'default' => 'yes',
                                'description' => ''
                            ],


                            'notice-subscriptions' => [
                                'type'  => 'notice',
                                'value' => '<h3>Настройки для FluentCart Subscriptions</h3>',
                            ],
                            'robokassa_agreement_text' => [
                                'value'       => '',
                                'label'       => 'Текст согласия с правилами на списания по подписке',
                                'type'        => 'text',
                                'placeholder' => 'Я даю согласие на регулярные списания, на <a href="%s">обработку персональных данных</a> и принимаю условия <a
href="%s">публичной оферты</a>',
                            ],
                            'robokassa_agreement_pd_link' => [
                                'value'       => '',
                                'label'       => 'Ссылка на согласие на обработку ПД',
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'robokassa_agreement_oferta_link' => [
                                'value'       => '',
                                'label'       => 'Ссылка на оферту',
                                'type'        => 'text',
                                'placeholder' => '',
                            ],


                            'notice-fiskalisation' => [
                                'type'  => 'notice',
                                'value' => '<h3>Фискализация</h3>',
                            ],
                            'robokassa_payment_sno' => [
                                'type'    => 'select',
                                'label'   => 'Система налогообложения',
                                'options' => [
                                    ['value' => 'fckoff', 'label' => 'Не передавать'],
                                    ['value' => 'osn', 'label' => 'Общая СН'],
                                    ['value' => 'usn_income', 'label' => 'Упрощенная СН (доходы)'],
                                    ['value' => 'usn_income_outcome', 'label' => 'Упрощенная СН (доходы минус расходы)'],
                                    ['value' => 'envd', 'label' => 'Единый налог на вмененный доход'],
                                    ['value' => 'esn', 'label' => 'Единый сельскохозяйственный налог'],
                                    ['value' => 'patent', 'label' => 'Патентная СН'],
                                ],
                            ],
                            'robokassa_payment_paymentMethod' => [
                                'type'    => 'select',
                                'label'   => 'Признак способа расчёта',
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, \RobokassaFluentCart\RobokassaHelper::$paymentMethods),
                            ],
                            'robokassa_payment_paymentObject' => [
                                'type'    => 'select',
                                'label'   => 'Признак предмета расчёта для товаров/услуг',
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, \RobokassaFluentCart\RobokassaHelper::$paymentObjects),
                            ],
                            'robokassa_payment_second_check_paymentObject' => [
                                'type'    => 'select',
                                'label'   => 'Признак предмета расчёта для товаров/услуг (второй чек)',
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, \RobokassaFluentCart\RobokassaHelper::$paymentObjects),
                                'description' => 'Если параметр не выбран, используется значение из поля «Признак предмета расчёта для товаров/услуг».'
                            ],
                            'robokassa_payment_paymentObject_shipping' => [
                                'type'    => 'select',
                                'label'   => 'Признак предмета расчёта для доставки',
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, \RobokassaFluentCart\RobokassaHelper::$paymentObjects),
                            ],
                            'robokassa_payment_tax_source' => [
                                'type'    => 'select',
                                'label'   => 'Источник налоговой ставки',
                                'options' => [
                                    ['value' => 'global', 'label' => 'Использовать ставку из настроек плагина'],
                                    ['value' => 'product', 'label' => 'Использовать ставку из карточки товара (при наличии)'],
                                ],
                                'description' => 'При выборе варианта с карточкой товара ставка из настроек плагина будет использоваться как значение по умолчанию.'
                            ],
                            'robokassa_payment_tax' => [
                                'type'    => 'select',
                                'label'   => 'Налоговая ставка',
                                'options' => [
                                    ['value' => 'none',   'label' => 'Не передавать'],
                                    ['value' => 'none',   'label' => 'Без НДС'],
                                    ['value' => 'vat0',   'label' => 'НДС по ставке 0%'],
                                    ['value' => 'vat10',  'label' => 'НДС чека по ставке 10%'],
                                    ['value' => 'vat20',  'label' => 'НДС чека по ставке 20%'],
                                    ['value' => 'vat110', 'label' => 'НДС чека по расчётной ставке 10/110'],
                                    ['value' => 'vat118', 'label' => 'НДС чека по расчётной ставке 20/120'],
                                    ['value' => 'vat5',   'label' => 'НДС по ставке 5%'],
                                    ['value' => 'vat7',   'label' => 'НДС по ставке 7%'],
                                    ['value' => 'vat105', 'label' => 'НДС чека по расчётной ставке 5/105'],
                                    ['value' => 'vat107', 'label' => 'НДС чека по расчётной ставке 7/107'],
                                    ['value' => 'vat8',   'label' => 'НДС чека по ставке 8% (Казахстан)'],
                                    ['value' => 'vat12',  'label' => 'НДС чека по ставке 12% (Казахстан)'],
                                ],
                            ],


                            'notice-status' => [
                                'type'  => 'notice',
                                'value' => '<h3>Настройка статусов заказа</h3>',
                            ],
                            'robokassa_payment_order_status_after_payment' => [
                                'type'    => 'select',
                                'label'   => 'Статус заказа после оплаты',
                                'options' => array_map(fn($k, $v) => ['value' => $k, 'label' => $v], array_keys(\FluentCart\App\Helpers\Status::getEditableOrderStatuses()), \FluentCart\App\Helpers\Status::getEditableOrderStatuses()),
                                'description' => 'Этот статус будет присвоен заказу после успешной оплаты через Робокассу. Применяется только для обычных платежей (не отложенных).'
                            ],
                            'robokassa_payment_order_status_for_second_check' => [
                                'type'    => 'select',
                                'label'   => 'Статус для автоматического выбивания второго чека',
                                'options' => array_map(fn($k, $v) => ['value' => $k, 'label' => $v], array_keys(\FluentCart\App\Helpers\Status::getEditableOrderStatuses()), \FluentCart\App\Helpers\Status::getEditableOrderStatuses()),
                                'description' => 'Выберите статус, при котором будет автоматически выбиваться второй чек (если этот статус применен к заказу)..'
                            ],



                            'notice-another' => [
                                'type'  => 'notice',
                                'value' => '<h3>Прочие настройки</h3><p>Агентские товары</p>',
                            ],
                            'robokassa_payment_agent_fields_enabled' => [
                                'type'    => 'checkbox',
                                'label' => 'Продаете ли вы агентский товар?',
                                'default' => 'no',
                                'description' => ''
                            ],
                            'notice-agent' => [
                                'type'  => 'notice',
                                'label' => '',
                                'value' => $notice_robokassa_online,
                            ],
                            'robokassa_payment_hold_onoff' => [
                                'type'    => 'select',
                                'label'   => 'Отложенные платежи',
                                'options' => [
                                    ['value' => '0', 'label' => 'Отключено'],
                                    ['value' => '1', 'label' => 'Включено'],
                                ],
                                'description' => ''
                            ],
                            'notice-payment-hold' => [
                                'type'  => 'notice',
                                'label' => '',
                                'value' => $notice_payment_hold,
                            ],
                            'robokassa_payment_SuccessURL' => [
                                'type'    => 'select',
                                'label'   => 'Страница успеха платежа',
                                'options' => array_merge(
                                    [
                                        ['value' => 'wc_success', 'label' => 'Страница "Заказ принят" от FluentCart'],
                                        ['value' => 'wc_checkout', 'label' => 'Страница оформления заказа от FluentCart'],
                                    ],
                                    array_map(fn($page) => ['value' => $page->ID, 'label' => $page->post_title], get_pages())
                                ),
                                'description' => 'Эту страницу увидит покупатель, когда оплатит заказ'
                            ],
                            'robokassa_payment_FailURL' => [
                                'type'    => 'select',
                                'label'   => 'Страница отказа',
                                'options' => array_merge(
                                    [
                                        ['value' => 'wc_checkout', 'label' => 'Страница оформления заказа от FluentCart'],
                                        ['value' => 'wc_payment', 'label' => 'Страница оплаты заказа от FluentCart'],
                                    ],
                                    array_map(fn($page) => ['value' => $page->ID, 'label' => $page->post_title], get_pages())
                                ),
                                'description' => 'Эту страницу увидит покупатель, если что-то пойдет не так: например, если ему не хватит денег на карте'
                            ],

                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => 'Тестовое',
                        'value'  => 'test',
                        'schema' => [
                            'notice-test-settings' => [
                                'type'  => 'notice',
                                'value' => '<h3>Настройки тестового соединения</h3>',
                            ],
                            'robokassa_payment_test_onoff' => [
                                'type'    => 'enable',
                                'label' => 'Тестовый режим',
                                'default' => 'no',
                            ],
                            'robokassa_payment_testshoppass1' => [
                                'value'       => '',
                                'label'       => 'Тестовый пароль магазина #1',
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                            'robokassa_payment_testshoppass2' => [
                                'value'       => '',
                                'label'       => 'Тестовый пароль магазина #2',
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => 'Виджет и бейдж',
                        'value'  => 'widget',
                        'schema' => [
                            
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => 'Оповещение',
                        'value'  => 'sms',
                        'schema' => [
                            
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => 'Регистрация',
                        'value'  => 'reg',
                        'schema' => [
                            
                        ],
                    ],
                ]
            ],

            /*
            'hr' => [
                'type'  => 'notice',
                'value' => '<hr>',
            ],
            'test_mode' => [
                'type'    => 'enable',
                'label' => __('Test Mode', 'fluent-robokassa'),
                'default' => 'yes',
                'description' => __('Enable test mode for development', 'fluent-robokassa')
            ],
            'debug_mode' => [
                'type'    => 'checkbox',
                'label' => __('Debug Mode', 'fluent-robokassa'),
                'default' => 'yes',
                'description' => __('Enable debug logging', 'fluent-robokassa')
            ],
            'params' => [
                'type'  => 'html_attr',
                'value' => '<div class="fc-gateway-params-info"><h4>Текущие параметры:</h4>' . $htmlList . '</div>',
            ]
            */
        
        ];
    }


    /**
    * Функция для проверки активирован ли метод оплаты
    */

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }


    /**
    * Функция для получения значений переменных
    */

    public function get($key = '')
    {
        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $this->settings;
    }

    /**
    * Функция для возврата состояния заказа?
    */

    public function getMode()
    {
        // return store mode
        return (new StoreSettings)->get('order_mode');
    }

}
