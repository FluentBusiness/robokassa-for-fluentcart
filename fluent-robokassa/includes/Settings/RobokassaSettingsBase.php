<?php // Настройки плагина в админке

namespace RobokassaFluentCart\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use RobokassaFluentCart\API\RobokassaHelper;


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


        $settings = is_array($this->settings) ? $this->settings : $this->settings->get();

        $htmlList = '<ul style="margin:0;padding-left:18px;">';
        foreach ($settings as $key => $value) {
            $val = is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
            $htmlList .= "<li><strong>{$key}</strong>: {$val}</li>";
        }
        $htmlList .= '</ul>';


        $notice_robokassa_online = __('<p>To transfer data to the receipt, use the rented <a href="https://robokassa.com/online-check/robokassa-online/" target="_blank" rel="noopener noreferrer">Robokassa Online</a>. With the "RoboCheck" solution, the agent attribute will not be transferred to the receipt.</p><p>Once enabled, the "Robokassa Agent Product" section will appear on the product card. Fill in the agent type, name, TIN, and phone numbers to ensure the data is included on the receipt.</p>', 'fluent-robokassa');

        $notice_payment_hold = __('<p>This <a href="https://docs.robokassa.ru/holding/" target="_blank" rel="noopener noreferrer">service</a> is available by prior arrangement only.</p><p>Functionality is only available when using bank cards.</p><p><a href="https://docs.robokassa.ru/media/guides/hold_woocommerce.pdf" target="_blank" rel="noopener noreferrer">Setup Instructions</a></p>', 'fluent-robokassa');


        $fluentCartStatuses = Status::getOrderStatuses();
        foreach ($fluentCartStatuses as $slug => $label) {
             $status_options[] = [
                'value' => $slug,
                'label' => $label
            ];
        }

        $status_options_after_payment = [
            [
                'value' => Status::ORDER_PROCESSING,
                'label' => __('Processing', 'fluent-cart'),
            ],
            [
                'value' => Status::ORDER_COMPLETED,
                'label' => __('Completed', 'fluent-cart'),
            ],
        ];


        return [
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Basic settings', 'fluent-robokassa'),
                        'value'  => 'live',
                        'schema' => [
                            'notice-top-title' => [
                                'type'  => 'notice',
                                'value' => __('<p>API keys and store connection parameters</p><h3>Help and installation instructions</h3><p>1. Enter your API details in the Basic Settings section.</p><p>2. In your Robokassa personal account, enter the following URLs for notifications:</p>', 'fluent-robokassa'),
                            ],
                            'notice-top-html' => [
                                'type'  => 'notice',
                                'value' => $notice_topHtml,
                            ],
                            'notice-top-bot' => [
                                'type'  => 'notice',
                                'value' => __('<p>Data sending method - POST</p><p>Hash calculation algorithm - MD5</p><p>After that, fill in the store login and passwords in the fields below</p>', 'fluent-robokassa'),
                            ],
                            'notice-main-settings' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Basic Settings</h3>', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_wc_robokassa_enabled' => [
                                'type'    => 'enable',
                                'label' => __('Payment via Robokassa', 'fluent-robokassa'),
                                'default' => 'no',
                            ],
                            'RobokassaOrderPageTitle_robokassa' => [
                                'value'       => '',
                                'label' => __('Checkout Page Header', 'fluent-robokassa'),
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'RobokassaOrderPageDescription_robokassa' => [
                                'value'       => '',
                                'label' => __('Description on the checkout page', 'fluent-robokassa'),
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'robokassa_country_code' => [
                                'type'    => 'select',
                                'label' => __('Country of the store', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 'RU', 'label' => __('Russia', 'fluent-robokassa')],
                                    ['value' => 'KZ', 'label' => __('Kazakhstan', 'fluent-robokassa')],
                                ],
                            ],
                            'robokassa_payment_MerchantLogin' => [
                                'value'       => '',
                                'label' => __('Store ID', 'fluent-robokassa'),
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'robokassa_payment_shoppass1' => [
                                'value'       => '',
                                'label' => __('Store password #1', 'fluent-robokassa'),
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                            'robokassa_payment_shoppass2' => [
                                'value'       => '',
                                'label' => __('Store password #2', 'fluent-robokassa'),
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                            'robokassa_culture' => [
                                'type'    => 'select',
                                'label' => __('Robokassa interface language', 'fluent-robokassa'),
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, RobokassaHelper::getCulture()),
                                'default' => '',
                            ],
                            'robokassa_iframe' => [
                                'type'    => 'select',
                                'label' => __('Enable iframe', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => '0', 'label' => __('Disabled', 'fluent-robokassa')],
                                    ['value' => '1', 'label' => __('Enable', 'fluent-robokassa')],
                                ],
                                'default' => '0',
                            ],
                            'notice-iframe' => [
                                'type'  => 'notice',
                                'value' => __('With iframe enabled, there are fewer payment methods than on a regular payment page—only cards, Apple and Samsung Pay, and Qiwi. Incurlabel works, but is limited.', 'fluent-robokassa'),
                            ],


                            'notice-dop-oplata' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Additional payment methods</h3><p>Select Robokassa partner solutions to display to customers when placing an order. Connected upon request in your Robokassa personal account.</p>', 'fluent-robokassa'),
                            ],
                            'notice-robokassa_payment_method_credit' => [
                                'type'  => 'notice',
                                'value' => __('Installment or credit options', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_method_credit_enabled' => [
                                'type'    => 'checkbox',
                                'label' => __('Display payment method', 'fluent-robokassa'),
                                'default' => 'yes',
                            ],
                            'notice-robokassa_payment_method_podeli' => [
                                'type'  => 'notice',
                                'value' => __('Robokassa X Share', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_method_podeli_enabled' => [
                                'type'    => 'checkbox',
                                'label' => __('Display payment method', 'fluent-robokassa'),
                                'default' => 'yes',
                            ],
                            'notice-robokassa_payment_method_mokka' => [
                                'type'  => 'notice',
                                'value' => __('Robokassa X Mokka', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_method_mokka_enabled' => [
                                'type'    => 'checkbox',
                                'label' => __('Display payment method', 'fluent-robokassa'),
                                'default' => 'yes',
                            ],
                            'notice-robokassa_payment_method_split' => [
                                'type'  => 'notice',
                                'value' => __('Robokassa X Yandex Split', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_method_split_enabled' => [
                                'type'    => 'checkbox',
                                'label' => __('Display payment method', 'fluent-robokassa'),
                                'default' => 'yes',
                            ],


                            'notice-subscriptions' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Settings for FluentCart Subscriptions</h3>', 'fluent-robokassa'),
                            ],
                            'robokassa_agreement_text' => [
                                'value'       => '',
                                'label' => __('Agreement text with the rules for subscription write-offs', 'fluent-robokassa'),
                                'type'        => 'text',
                                'placeholder' => 'Я даю согласие на регулярные списания, на <a href="%s">обработку персональных данных</a> и принимаю условия <a 
href="%s">публичной оферты</a>',
                            ],
                            'robokassa_agreement_pd_link' => [
                                'value'       => '',
                                'label' => __('Link to consent to the processing of personal data', 'fluent-robokassa'),
                                'type'        => 'text',
                                'placeholder' => '',
                            ],
                            'robokassa_agreement_oferta_link' => [
                                'value'       => '',
                                'label' => __('Link to offer', 'fluent-robokassa'),
                                'type'        => 'text',
                                'placeholder' => '',
                            ],

                            'notice-fiskalisation' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Fiscalization</h3>', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_sno' => [
                                'type'    => 'select',
                                'label' => __('Taxation system', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 'fckoff', 'label' => __('Do not transmit', 'fluent-robokassa')],
                                    ['value' => 'osn', 'label' => __('General Tax System', 'fluent-robokassa')],
                                    ['value' => 'usn_income', 'label' => __('Simplified Tax System (income)', 'fluent-robokassa')],
                                    ['value' => 'usn_income_outcome', 'label' => __('Simplified Tax System (income minus expenses)', 'fluent-robokassa')],
                                    ['value' => 'envd', 'label' => __('Single Tax on Imputed Income', 'fluent-robokassa')],
                                    ['value' => 'esn', 'label' => __('Unified Agricultural Tax', 'fluent-robokassa')],
                                    ['value' => 'patent', 'label' => __('Patent Tax System', 'fluent-robokassa')],
                                ],
                            ],
                            'robokassa_payment_paymentMethod' => [
                                'type'    => 'select',
                                'label' => __('Calculation method indicator', 'fluent-robokassa'),
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, RobokassaHelper::getPaymentMethods()),
                            ],
                            'robokassa_payment_paymentObject' => [
                                'type'    => 'select',
                                'label' => __('Calculation subject indicator for goods/services', 'fluent-robokassa'),
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, RobokassaHelper::getPaymentObjects()),
                            ],
                            'robokassa_payment_second_check_paymentObject' => [
                                'type'    => 'select',
                                'label' => __('Invoice item indicator for goods/services (second receipt)', 'fluent-robokassa'),
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, RobokassaHelper::getPaymentObjects()),
                                'description' => __('If the parameter is not selected, the value from the “Settlement subject indicator for goods/services” field is used.', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_paymentObject_shipping' => [
                                'type'    => 'select',
                                'label' => __('Item of payment for delivery', 'fluent-robokassa'),
                                'options' => array_map(function ($item) {
                                    return [
                                        'value' => $item['code'],
                                        'label' => $item['title'],
                                    ];
                                }, RobokassaHelper::getPaymentObjects()),
                            ],
                            'robokassa_payment_tax_source' => [
                                'type'    => 'select',
                                'label' => __('Source of tax rate', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 'global', 'label' => __('Use rate from plugin settings', 'fluent-robokassa')],
                                    ['value' => 'product', 'label' => __('Use rate from product card (if available)', 'fluent-robokassa')],
                                ],
                                'description' => __('When selecting the option with a product card, the rate from the plugin settings will be used as the default value.', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_tax' => [
                                'type'    => 'select',
                                'label' => __('Tax rate', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 'none',   'label' => __('Do not transmit', 'fluent-robokassa')],
                                    ['value' => 'none',   'label' => __('Without VAT', 'fluent-robokassa')],
                                    ['value' => 'vat0',   'label' => __('VAT at 0% rate', 'fluent-robokassa')],
                                    ['value' => 'vat10',  'label' => __('VAT at 10% rate', 'fluent-robokassa')],
                                    ['value' => 'vat20',  'label' => __('VAT at 20% rate', 'fluent-robokassa')],
                                    ['value' => 'vat110', 'label' => __('VAT at calculated 10/110 rate', 'fluent-robokassa')],
                                    ['value' => 'vat118', 'label' => __('VAT at calculated 20/120 rate', 'fluent-robokassa')],
                                    ['value' => 'vat5',   'label' => __('VAT at 5% rate', 'fluent-robokassa')],
                                    ['value' => 'vat7',   'label' => __('VAT at 7% rate', 'fluent-robokassa')],
                                    ['value' => 'vat105', 'label' => __('VAT at calculated 5/105 rate', 'fluent-robokassa')],
                                    ['value' => 'vat107', 'label' => __('VAT at calculated 7/107 rate', 'fluent-robokassa')],
                                    ['value' => 'vat8',   'label' => __('VAT at 8% rate (Kazakhstan)', 'fluent-robokassa')],
                                    ['value' => 'vat12',  'label' => __('VAT at 12% rate (Kazakhstan)', 'fluent-robokassa')],
                                ],
                            ],
                            'notice-status' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Setting up order statuses</h3>', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_order_status_after_payment' => [
                                'type'    => 'select',
                                'label' => __('Order status after payment', 'fluent-robokassa'),
                                'options' => $status_options_after_payment,
                                'description' => __('This status will be assigned to an order after successful payment via RoboKassa. It only applies to regular payments (not deferred payments).', 'fluent-robokassa'),
                                'default' => Status::ORDER_PROCESSING,
                            ],
                            'robokassa_payment_order_status_for_second_check' => [
                                'type'    => 'select',
                                'label' => __('Status for automatic issuance of the second check', 'fluent-robokassa'),
                                'options' => $status_options,
                                'description' => __('Select the status at which a second receipt will be automatically generated (if this status is applied to the order).', 'fluent-robokassa'),
                            ],

                            'notice-another' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Other Settings</h3>', 'fluent-robokassa'),
                            ],
                            'notice-agency' => [
                                'type'  => 'notice',
                                'value' => __('Agency Products', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_agent_fields_enabled' => [
                                'type'    => 'checkbox',
                                'label' => __('Do you sell agency products?', 'fluent-robokassa'),
                                'default' => 'no',
                            ],
                            'notice-agent' => [
                                'type'  => 'notice',
                                'value' => $notice_robokassa_online,
                            ],
                            'robokassa_payment_hold_onoff' => [
                                'type'    => 'select',
                                'label' => __('Deferred payments', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => '0', 'label' => __('Disabled', 'fluent-robokassa')],
                                    ['value' => '1', 'label' => __('Enable', 'fluent-robokassa')],
                                ],
                                'default' => '0',
                            ],
                            'notice-payment-hold' => [
                                'type'  => 'notice',
                                'value' => $notice_payment_hold,
                            ],
                            'robokassa_payment_SuccessURL' => [
                                'type'    => 'select',
                                'label' => __('Payment success page', 'fluent-robokassa'),
                                'options' => array_merge(
                                    [
                                        ['value' => '', 'label' => __('Default', 'fluent-robokassa')],
                                        ['value' => 'wc_success', 'label' => __('FluentCart "Order Receipt" page', 'fluent-robokassa')],
                                        ['value' => 'wc_checkout', 'label' => __('FluentCart "Checkout" page', 'fluent-robokassa')],
                                    ],
                                    array_map(fn($page) => ['value' => $page->ID, 'label' => $page->post_title], get_pages())
                                ),
                                'description' => __('The buyer will see this page when they pay for the order.', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_FailURL' => [
                                'type'    => 'select',
                                'label' => __('Refusal page', 'fluent-robokassa'),
                                'options' => array_merge(
                                    [
                                        ['value' => '', 'label' => __('Default', 'fluent-robokassa')],
                                        ['value' => 'wc_checkout', 'label' => __('FluentCart "Checkout" page', 'fluent-robokassa')],
                                        ['value' => 'wc_payment', 'label' => __('FluentCart "Payment" page', 'fluent-robokassa')],
                                    ],
                                    array_map(fn($page) => ['value' => $page->ID, 'label' => $page->post_title], get_pages())
                                ),
                                'description' => __('This is the page the buyer will see if something goes wrong: for example, if they dont have enough money on their card.', 'fluent-robokassa'),
                            ],

                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label' => __('Test', 'fluent-robokassa'),
                        'value'  => 'test',
                        'schema' => [
                            'notice-test-settings' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Test connection settings</h3>', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_test_onoff' => [
                                'type'    => 'enable',
                                'label' => __('Test mode', 'fluent-robokassa'),
                                'default' => 'no',
                            ],
                            'robokassa_payment_testshoppass1' => [
                                'value'       => '',
                                'label' => __('Test store password #1', 'fluent-robokassa'),
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                            'robokassa_payment_testshoppass2' => [
                                'value'       => '',
                                'label' => __('Test store password #2', 'fluent-robokassa'),
                                'type'        => 'password',
                                'placeholder' => '',
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label' => __('Widget and badge', 'fluent-robokassa'),
                        'value'  => 'widget',
                        'schema' => [
                            'notice-widget-top' => [
                                'type'  => 'notice',
                                'value' => __('<p>Appearance and behavior scenarios of storefront elements</p><p>Configure the appearance and display scenarios of Robokassa branded components on the store front.</p><h3>Robokassa Badge & Widget settings</h3>', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_enabled' => [
                                'type'    => 'enable',
                                'label' => __('Enable widget or badge', 'fluent-robokassa'),
                                'default' => 'no',
                                'description' => __('After activation the selected Robokassa component will be shown on the product page.', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_component' => [
                                'type'    => 'select',
                                'label' => __('Default component', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 'widget', 'label' => __('Widget', 'fluent-robokassa')],
                                    ['value' => 'badge', 'label' => __('Badge', 'fluent-robokassa')],
                                ],
                                'description' => __('Select the component that will be displayed on the product page.', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_theme' => [
                                'type'    => 'select',
                                'label' => __('Color theme', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 'light', 'label' => __('Light theme', 'fluent-robokassa')],
                                    ['value' => 'dark', 'label' => __('Dark theme', 'fluent-robokassa')],
                                ],
                            ],
                            'notice-widget-pro' => [
                                'type'  => 'notice',
                                'value' => __('<h3>Advanced component settings</h3>', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_size' => [
                                'type'    => 'select',
                                'label' => __('Component size', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 's', 'label' => __('s', 'fluent-robokassa')],
                                    ['value' => 'm', 'label' => __('m', 'fluent-robokassa')],
                                ],
                                'default' => 'm',
                                'description' => __('Sets the size attribute. Available options are «s» and «m».', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_show_logo' => [
                                'type'    => 'enable',
                                'label' => __('Show Robokassa logo', 'fluent-robokassa'),
                                'default' => 'no',
                                'description' => __('Controls the showLogo attribute. A value of «false» hides the logo.', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_type' => [
                                'type'    => 'select',
                                'label' => __('Offer type', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => '', 'label' => __('Display both', 'fluent-robokassa')],
                                    ['value' => 'bnpl', 'label' => __('bnpl', 'fluent-robokassa')],
                                    ['value' => 'credit', 'label' => __('credit', 'fluent-robokassa')],
                                ],
                                'default' => 'left',
                                'description' => __('The type attribute value for the Widget component. By default the attribute is not passed, which enables both offer types.', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_border_radius' => [
                                'type'    => 'text',
                                'label' => __('Border radius', 'fluent-robokassa'),
                                'placeholder' => __('For example: 12px', 'fluent-robokassa'),
                                'description' => __('The borderRadius attribute for the Widget component.', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_has_second_line' => [
                                'type'    => 'enable',
                                'label' => __('Second description line', 'fluent-robokassa'),
                                'default' => 'no',
                                'description' => __('Corresponds to hasSecondLine attribute (Only for widget & size: m).', 'fluent-robokassa'),
                            ],
                            'robokassa_widget_description_position' => [
                                'type'    => 'select',
                                'label' => __('Description position', 'fluent-robokassa'),
                                'options' => [
                                    ['value' => 'left', 'label' => __('Left', 'fluent-robokassa')],
                                    ['value' => 'right', 'label' => __('Right', 'fluent-robokassa')],
                                ],
                                'default' => 'left',
                                'description' => __('The descriptionPosition attribute value for the widget only.', 'fluent-robokassa'),
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label' => __('Notifications', 'fluent-robokassa'),
                        'value'  => 'sms',
                        'schema' => [
                            'notice-sms-top' => [
                                'type'  => 'notice',
                                'value' => __('<p>SMS scenarios and templates for customers</p><h3>SMS settings</h3><p>Manage texts and sending conditions for SMS messages to your store customers.</p><p>The message body supports the following placeholders:</p><p>{address} — delivery address.<br/>{fio} — customer full name<br/>{order_number} — order number.', 'fluent-robokassa'),
                            ],
                            'notice-sms-payment_sms_translit' => [
                                'type'  => 'notice',
                                'value' => __('SMS transliteration', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_sms_translit' => [
                                'type'    => 'checkbox',
                                'label' => __('Enable/Disable', 'fluent-robokassa'),
                                'default' => 'no',
                            ],
                            'notice-sms-payment_sms1_enabled' => [
                                'type'  => 'notice',
                                'value' => __('Notification of successful payment', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_sms1_enabled' => [
                                'type'    => 'checkbox',
                                'label' => __('Enable/Disable', 'fluent-robokassa'),
                                'default' => 'no',
                            ],
                            'robokassa_payment_sms1_text' => [
                                'type'    => 'text',
                                'label' => __('Notification text for successful payment', 'fluent-robokassa'),
                            ],
                            'notice-sms-payment_sms2_enabled' => [
                                'type'  => 'notice',
                                'value' => __('Notification of order completion', 'fluent-robokassa'),
                            ],
                            'robokassa_payment_sms2_enabled' => [
                                'type'    => 'checkbox',
                                'label' => __('Enable/Disable', 'fluent-robokassa'),
                                'default' => 'no',
                            ],
                            'robokassa_payment_sms2_text' => [
                                'type'    => 'text',
                                'label' => __('Notification text for order completion', 'fluent-robokassa'),
                            ],
                        ],
                    ],
                    [
                        'type'   => 'tab',
                        'label' => __('Registration', 'fluent-robokassa'),
                        'value'  => 'reg',
                        'schema' => [
                            'notice-reg-iframe' => [
                                'type'  => 'notice',
                                'value' => $this->getRegistrationTabHtml(),
                            ],
                        ],
                    ],
                ]
            ],

            
            'hr' => [
                'type'  => 'notice',
                'value' => '<hr>',
            ],
        
        ];
    }


    /**
    * Функция для вставки формы регистрации
    */

    private function getRegistrationTabHtml(): string
    {
        $site_url    = site_url('/');
        $result_url  = site_url('/?robokassa=result');
        $success_url = site_url('/?robokassa=success');
        $fail_url    = site_url('/?robokassa=fail');
        $callback_url = site_url('/?robokassa=registration');

        ob_start();
        ?>
        <div class="robokassa-admin-wrapper">
            <div class="robokassa-admin-container">
                <div class="robokassa-card robokassa-card--compact">
                    <h2 class="robokassa-card__title"><?php _e('Register with Robokassa', 'fluent-robokassa'); ?></h2>
                    <p class="robokassa-card__description"><?php _e('Submit an application to connect Robokassa directly from the admin panel and start accepting payments without extra steps.', 'fluent-robokassa'); ?></p>
                    <div class="robokassa-frame-wrapper">
                        <iframe
                            onload="robokassaRegistrationInit();"
                            id="robokassa-registration-frame"
                            src="https://reg2.robokassa.ru/register/wordpress"
                            title="<?php _e('Robokassa Registration', 'fluent-robokassa'); ?>"
                            height="1000"
                            style="width:100%;border:none;"
                        ></iframe>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function() {
            var robokassaRegistrationData = {
                rk_reg: true,
                site_url:     <?php echo json_encode($site_url); ?>,
                result_url:   <?php echo json_encode($result_url); ?>,
                success_url:  <?php echo json_encode($success_url); ?>,
                fail_url:     <?php echo json_encode($fail_url); ?>,
                callback_url: <?php echo json_encode($callback_url); ?>
            };

            window.robokassaRegistrationInit = function() {
                var frame = document.getElementById('robokassa-registration-frame');
                if (!frame) return;
                frame.contentWindow.postMessage(robokassaRegistrationData, '*');
            };
        })();
        </script>
        <?php
        return ob_get_clean();
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
    * Функция для возврата состояния
    */

    public function getMode()
    {
        return (new StoreSettings)->get('order_mode');
    }

}
