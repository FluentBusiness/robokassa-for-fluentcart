<?php // Robokassa Gateway Class

namespace RobokassaFluentCart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use RobokassaFluentCart\Settings\RobokassaSettingsBase;
use RobokassaFluentCart\API\RobokassaPayAPI;
use RobokassaFluentCart\API\AgentManager;
use FluentCart\App\Helpers\Status;


class RobokassaGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'robokassa';

    /**
     * Поддерживаемые возможности
     *
     * @var array
     */
    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions',
    ];

    /**
     * Конструктор: передаём объект настроек
     */

    public function __construct()
    {
        $settings = new RobokassaSettingsBase(); // создаём объект настроек
        parent::__construct(
            $settings,
            new RobokassaSubscriptions($settings) // передаём настройки в подписки
        );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ], 20 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ], 20 );
    }


    /**
     * Универсальный метод получения настройки:
     */
    protected function setting($key, $default = null) {
        if (is_object($this->settings) && method_exists($this->settings, 'get')) {
            try {
                return $this->settings->get($key, $default);
            } catch (\Throwable $e) {
                error_log('[robokassa-debug] setting get() exception: ' . $e->getMessage());
            }
        }
        return $default;
    }


    /**
     * Метаданные шлюза
     */
    public function meta(): array
    {
        $logo = defined( 'FLUENT_ROBOKASSA_PLUGIN_URL' ) ? FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/images/robokassa-logo.svg' : '';

        return [
            'title'              => __( 'Robokassa', 'fluent-robokassa' ),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Robokassa',
            'admin_title'        => 'Robokassa',
            'description'        => __( 'Приём платежей через Robokassa (карты, партнёры, мобильные).', 'fluent-robokassa' ),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#e51c23',
            'status'             => $this->settings ? ($this->settings->get('is_active') === 'yes') : false,
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }


    /**
     * Подключение CSS/JS для страницы настроек в админке
     */

    public function enqueue_admin_assets() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( empty( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'fluent-cart' ) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'robokassa-admin-style',
            FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/css/style.css',
            [],
            FLUENT_ROBOKASSA_VERSION
        );

        // JS (в footer)
        wp_enqueue_script(
            'robokassa-admin-script',
            FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/js/admin-fields.js',
            [ 'jquery' ],
            FLUENT_ROBOKASSA_VERSION,
            true
        );


        $inline = "if (location.hash && location.hash.indexOf('/settings/payments/robokassa') !== -1) { if (typeof window.RobokassaInit === 'function') { window.RobokassaInit(); } }";
        wp_add_inline_script( 'robokassa-admin-script', $inline );
    }


    /**
     * Подключение CSS/JS на фронтенде
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_script(
            'robokassa-frontend',
            FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            FLUENT_ROBOKASSA_VERSION,
            true
        );
    }

    /**
     * Подключение CSS/JS на фронтенде через Fluentcart
     */
    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'fluent-cart-robokassa-checkout',
                'src' => FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/js/robokassa-redirect.js',
                'version' => FLUENT_ROBOKASSA_VERSION
            ],
            [
                'handle' => 'fluent-cart-robokassa-iframe',
                'src' => FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/js/robokassa-iframe.js',
                'version' => FLUENT_ROBOKASSA_VERSION
            ],
            [
                'handle' => 'fluent-cart-robokassa-submethods',
                'src' => FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/js/robokassa-submethods.js',
                'version' => FLUENT_ROBOKASSA_VERSION
            ],
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle' => 'fluent-cart-robokassa-submethods',
                'src' => FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/css/robokassa-submethods.css',
                'version' => FLUENT_ROBOKASSA_VERSION
            ],
        ];
    }


    /**
     * Регистрируем webhook, фильтры и т.д.
     */
    public function boot()
    {
        add_filter( 'fluent_cart/payment_methods/' . $this->methodSlug . '_settings', [ $this, 'getSettings' ], 10, 2 );

        // Обработка ответов заказа от Robokassa
        if (class_exists('RobokassaFluentCart\\Webhook\\RobokassaWebhookHandler')) {
            static $handler_attached = false;
            if (!$handler_attached) {
                $handler = new \RobokassaFluentCart\Webhook\RobokassaWebhookHandler($this->settings, $this->subscriptions);
                add_action('parse_request', [$handler, 'robokassa_payment_wp_robokassa_checkPayment']);
                $handler_attached = true;
            }
        }

        // iframe-оплата
        if ($this->settings->get('robokassa_iframe') == '1') {
            $this->registerRobokassaRest();
        }

        // Методы оплаты (Кредит, яндекс.сплит и др)
        $methodsSvc = new \RobokassaFluentCart\API\RobokassaMethods($this->settings, null, [$this, 'logDebug']);
        $methodsSvc->registerRestRoutes();

        // Виджет на странице товара
        $widget = new \RobokassaFluentCart\RobokassaPaymentWidget($this->settings, [$this, 'logDebug']);


        // отправка SMS-2 при переводе заказа в completed
        add_action('fluent_cart/order_status_changed', [ $this, 'robokassa_onOrderChanged' ], 10, 1);

        // отправка 2 чека
        add_action('fluent_cart/order_status_changed', [ $this, 'robokassa_2check_status_change' ], 10, 1);
        

        // Подключение холдирования
        add_action('wp_ajax_hold_accept_order', [$this, 'handle_hold_accept_order']);
        add_action('fluent_cart/order_status_changed', [ $this, 'robokassa_handle_status_change' ], 10, 1);
        add_action('robokassa_cancel_payment_event', [ $this, 'robokassa_hold_cancel_after5' ], 10, 1);


        // Подключаем TaxManager (налоги)
        $taxManager = $this->robokassa_payment_get_tax_manager($this->settings);
        add_action('fluent_cart/product_updated', [$taxManager, 'handleProductUpdated'], 10, 1);
        add_filter('fluent_cart/widgets/single_product_page', [$taxManager, 'addProductTaxWidget'], 10, 2);


        // Регистрируем хук для повторных платежей (подписки, крон)
        add_action('robokassa_recurring_payment', [$this->subscriptions, 'processRecurringPayment']);

        // Вывод чекбокса на странице оформления заказа
        add_action('fluent_cart/before_payment_methods', [ $this, 'robokassa_before_payment_methods' ], 10, 1);
        add_filter('fluent_cart/checkout/validate_data', [ $this, 'robokassa_checkout_validate_data' ], 10, 2);
        add_action('fluent_cart/checkout/prepare_other_data', [ $this, 'robokassa_checkout_prepare_other_data' ], 10, 1);
        add_filter('fluent_cart/widgets/single_order_page', [ $this, 'robokassa_widgets_single_order_page' ], 10, 2);


        // Подключаем AgentManager (агентский признак товара)
        $agentManager = $this->robokassa_payment_get_agent_manager($this->settings);
        add_action('fluent_cart/product_updated', [$agentManager, 'handleProductUpdated'], 10, 1);
        add_filter('fluent_cart/widgets/single_product_page', [$agentManager, 'addProductAgentWidget'], 10, 2);

    }

    
    /**
     * При включенном iframe регистрируем REST
     */

    protected function registerRobokassaRest()
    {
        $passes   = $this->getRobokassaPasses();
        $merchant = $this->settings->get('robokassa_payment_MerchantLogin');

        if (!$merchant || empty($passes['pass1'])) {
            return;
        }

        $api = new \RobokassaFluentCart\API\RobokassaPayAPI(
            $merchant,
            $passes['pass1'],
            $passes['pass2'],
            'md5',
            $this->settings
        );

        if (method_exists($api, 'registerRestRoutes')) {
            $api->registerRestRoutes();
        }
    }


    /**
     * Необходимая функция
     */
    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures);
    }



    /**
     * Вспомогательная функция получения паролей
     */
    protected function getRobokassaPasses(): array
    {
        $isTest = $this->settings->get('robokassa_payment_test_onoff') === 'yes';

        if ($isTest) {
            return [
                'pass1' => $this->settings->get('robokassa_payment_testshoppass1'),
                'pass2' => $this->settings->get('robokassa_payment_testshoppass2'),
            ];
        }

        return [
            'pass1' => $this->settings->get('robokassa_payment_shoppass1'),
            'pass2' => $this->settings->get('robokassa_payment_shoppass2'),
        ];
    }

    /**
     * Необходимая функция. Процесс оплаты
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $order       = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $customer    = $order->customer ?? null;

        $passes   = $this->getRobokassaPasses();
        $merchant = $this->settings->get('robokassa_payment_MerchantLogin');

        if (! $merchant || empty($passes['pass1'])) {
            return new \WP_Error('robokassa_credentials_missing', 'Robokassa credentials are not configured');
        }

        if (! empty($transaction->status) && $transaction->status === 'succeeded') {
            $this->logDebug('transaction_already_succeeded', ['tx_id' => $transaction->id]);
            return new \WP_Error('already_paid', __('This order is already paid.', 'fluent-robokassa'));
        }

        $testRaw = $this->settings->get('robokassa_payment_test_onoff');
        $isTest  = in_array($testRaw, ['true', '1', 1, true, 'on', 'yes'], true);

        $order_id = $order->id;

        $order_uuid = $order->uuid;
        $transaction_uuid = $transaction->uuid;

        $receipt = $this->createRobokassaReceipt($order_id);

        $rb = new API\RobokassaPayAPI(
            $merchant,
            $passes['pass1'],
            $passes['pass2'],
            'md5',
            $this->settings
        );

        $sum = number_format($transaction->total / 100, 2, '.', '');

        $invId = crc32($transaction_uuid);
        if ($invId > 2147483647) {
            $invId -= 4294967296;
        }
        $invId = abs($invId);
         

        $robokassaMeta['robokassa_invId'] = $invId;
        $transaction->fill([
            'meta' => $robokassaMeta,
        ]);
        $transaction->save();

        $invDesc = sprintf(__('Order #%d', 'fluent-robokassa'), $order->id);

        $alias = 'ALL';

        if (!empty($_REQUEST['robokassa_alias'])) {
            $alias = strtoupper(sanitize_text_field($_REQUEST['robokassa_alias']));
        }

        if ($alias === 'STANDARD') {
            $alias = 'ALL';
        }

        if ($alias !== 'ALL') {
            $meta = is_array($transaction->meta) ? $transaction->meta : [];
            $meta['robokassa_alias'] = $alias;
            $transaction->fill(['meta' => $meta]);
            $transaction->save();
        }
        
        $isRecurring = !empty($paymentInstance->subscription);
        
        try {
            $html = $rb->createForm(
                $sum,
                $invId,
                $invDesc,
                $order_uuid,
                $transaction_uuid,
                $isTest ? 'true' : 'false',
                strtolower($alias),
                $receipt,
                $customer->email ?? null,
                $isRecurring
            );

            if (! is_string($html) || $html === '') {
                throw new \RuntimeException('Robokassa returned empty HTML for payment form');
            }
        } catch (\Throwable $e) {
            $this->logDebug('create_form_exception', [
                'error' => $e->getMessage(),
            ]);
            return new \WP_Error('robokassa_form_error', $e->getMessage());
        }

        return [
            'status'     => 'success',
            'nextAction' => 'robokassa',
            'data'       => [
                'html' => $html,
            ],
        ];
    }




    /**
     * Необходимая функция
     */
    public function handleIPN()
    {
    }


    /**
     * Поля страницы настроек (сами поля внутри класса RobokassaSettingsBase)
     */
    public function fields()
    {
        return $this->settings->getFields();
    }


    /**
     * Необходимая функция. Получение информации о заказе для фронтенда
     */
    public function getOrderInfo(array $data)
    {
        $paymentArgs = [];
        
        wp_send_json([
            'status' => 'success',
            'payment_args' => $paymentArgs,
            'message' => __('Order info retrieved', 'fluent-robokassa')
        ], 200);
    }


    /**
     * Валидация настроек перед сохранением
     */
    public static function validateSettings( $data ): array
    {
        return $data;
    }

    /**
     * Предобработка настроек перед сохранением (шаблон)
     */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        return $data;
    }

    /**
     * Статический регистрирующий метод (вызывается при инициализации плагина)
     */
    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod( 'robokassa', new self() );
    }


    /**
     * Логирование отладки
     */
    public function logDebug($message, $data = [])
    {
        if ($this->settings->get('debug_mode') === 'yes') {
            error_log('robokassa: ' . $message . ' - ' . json_encode($data));
        }
    }


    /**
     * Debug log Robokassa
     */
    public function robokassa_payment_DEBUG($str)
    {
        $time = time();
        $line = date('d.m.Y H:i:s', $time + 10800) . " ($time) : $str\r\n";

        $file = FLUENT_ROBOKASSA_PLUGIN_DIR . 'data/robokassa_DEBUG.txt';

        if (is_dir(dirname($file)) && is_writable(dirname($file))) {
            file_put_contents($file, $line, FILE_APPEND);
        }

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[robokassa] ' . $str);
        }
    }


    /**
     * Возвращает сервис управления налоговыми ставками.
     */
    function robokassa_payment_get_tax_manager($settings = null)
    {
        static $instance;

        if (!$instance) {
            $instance = new \RobokassaFluentCart\API\TaxManager($settings);
        }

        return $instance;
    }

    /**
     * Возвращает сервис управления агенскими полями товаров.
     */
    function robokassa_payment_get_agent_manager($settings = null)
    {
        static $instance;

        if (!$instance) {
            $instance = new \RobokassaFluentCart\API\AgentManager($settings);
        }

        return $instance;
    }

    /**
     * Вычисляет сумму налога для передачи в Робокассу.
     */
    function calculate_tax_sum($tax, $amount)
    {
        return $this->robokassa_payment_get_tax_manager()->calculateTaxSum($tax, $amount);
    }

    /**
     * Возвращает налоговую ставку по умолчанию.
     */
    function robokassa_payment_get_default_tax()
    {
        return $this->robokassa_payment_get_tax_manager()->getDefaultTax();
    }

    /**
     * Определяет налоговую ставку для позиции заказа.
     */
    function robokassa_payment_get_item_tax($item)
    {
        return $this->robokassa_payment_get_tax_manager()->getItemTax($item);
    }


    /**
     * Подготовка строки перед кодированием в base64
     */ 
    function formatSignReplace($string)
    {
        return strtr(
            $string,
            [
                '+' => '-',
                '/' => '_',
            ]
        );
    }

    /**
     * Подготовка строки после кодирования в base64
     */
    function formatSignFinish($string)
    {
        return preg_replace('/^(.*?)(=*)$/', '$1', $string);
    }

    /**
     * Подготовка товарной номенклатуры для формирования чека
     */
    function createRobokassaReceipt($order_id)
    {
        $order = \FluentCart\App\Models\Order::find($order_id);
        if (!$order) {
            $this->robokassa_payment_DEBUG("Заказ не найден\r\n");
            return;
        }

        $sno = $this->setting('robokassa_payment_sno');
        $default_tax = $this->robokassa_payment_get_default_tax();
        $country = $this->setting('robokassa_country_code');

        $receipt = array(
            'sno' => $sno,
        );

        $total_order = number_format($order->total_amount / 100, 2, '.', '');
        $total_receipt = 0;

        foreach ($order->order_items as $item) {
            $current = array();
            $current['name'] = $item->post_title;
            $current['quantity'] = $item->quantity;

            $item_sum = $item->subtotal / 100;
            $current['sum'] = number_format($item_sum, 2, '.', '');
            $current['cost'] = number_format($item_sum / $item->quantity, 2, '.', '');

            $total_receipt += $current['sum'];
            $item_tax = $this->robokassa_payment_get_item_tax($item);

            if ($country == 'RU') {
                $current['payment_object'] = $this->setting('robokassa_payment_paymentObject');
                $current['payment_method'] = $this->setting('robokassa_payment_paymentMethod');
            }

            if (($sno == 'osn') || $country == 'RU') {
                $current['tax'] = $item_tax;
            } else {
                $current['tax'] = 'none';
            }


            $agentData = $this->robokassa_payment_get_agent_manager()->getItemAgentData($item);

            if (!empty($agentData)) {
                if (isset($agentData['agent_info'])) {
                    $current['agent_info'] = $agentData['agent_info'];
                }

                if (isset($agentData['supplier_info'])) {
                    $current['supplier_info'] = $agentData['supplier_info'];
                }
            }

            $receipt['items'][] = $current;
        }


        if ((double)$order->shipping_total > 0) {
            $current = array();
            $current['name'] = 'Доставка';
            $current['quantity'] = 1;
            $current['cost'] = number_format(((float)$order->shipping_total) / 100, 2, '.', '');
            $current['sum'] = number_format(((float)$order->shipping_total) / 100, 2, '.', '');

            if ($country == 'RU') {
                $current['payment_object'] = $this->setting('robokassa_payment_paymentObject_shipping') ?: $this->setting('robokassa_payment_paymentObject');
                $current['payment_method'] = $this->setting('robokassa_payment_paymentMethod');
            }

            if (($sno == 'osn') || ($country != 'KZ')) {
                $current['tax'] = $default_tax;
            } else {
                $current['tax'] = 'none';
            }

            $receipt['items'][] = $current;
            $total_receipt += $current['cost'];
        }

        if ($total_receipt != $total_order) {
            $this->robokassa_payment_DEBUG('Robokassa: общая сумма чека (' . $total_receipt . ') НЕ совпадает с общей суммой заказа (' . $total_order . ')');
        }

        return apply_filters('wc_robokassa_receipt', $receipt);
    }


    /**
     * Проверка статуса заказа перед отправкой 2 чека
     */
    public function robokassa_2check_status_change($data)
    {
        $orderArr = $data['order'] ?? null;
        $old = $data['old_status'] ?? null;
        $new = $data['new_status'] ?? null;

        if (!$orderArr || !isset($orderArr['id'])) {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Данные заказа или ID не найдены в данных хука."
            );
            return;
        }

        $order_id = (int) $orderArr['id'];
        $order = \FluentCart\App\Models\Order::find($order_id);

        if (!$order) {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Модель FluentCart Order не найдена для ID: " . $order_id
            );
            return;
        }

        $latestSuccessfulChargeTransaction = \FluentCart\App\Models\OrderTransaction::where('order_id', $order->id)
            ->where('transaction_type', 'charge')
            ->where('status', 'succeeded')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($latestSuccessfulChargeTransaction) {
            $transaction_id   = $latestSuccessfulChargeTransaction->id;
            $transaction_uuid = $latestSuccessfulChargeTransaction->uuid;
            $vendor_charge_id = $latestSuccessfulChargeTransaction->vendor_charge_id;
            $robokassa_invId = $latestSuccessfulChargeTransaction->meta['robokassa_invId'] ?? null;

        } else {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Не найдена успешная транзакция оплаты для заказа ID "
                . $order->id
            );
        }

        $this->robokassa_2check_send($order_id, $robokassa_invId, $old, $new);
    }

    /**
     * Отправка 2 чека
     */
    function robokassa_2check_send($order_id, $robokassa_invId, $old_status, $new_status)
    {
        $payment_method = $this->setting('robokassa_payment_paymentMethod');
        $sno = $this->setting('robokassa_payment_sno');
        $default_tax = $this->robokassa_payment_get_default_tax();
        $country = $this->setting('robokassa_country_code');
        $payment_object = $this->setting('robokassa_payment_paymentObject');
        $second_check_payment_object = $this->setting('robokassa_payment_second_check_paymentObject');

        if (empty($second_check_payment_object)) {
            $second_check_payment_object = $payment_object;
        }

        if ($payment_method == 'advance' || $payment_method == 'full_prepayment' || $payment_method == 'prepayment') {
            if ($sno == 'fckoff') {
                $this->robokassa_payment_DEBUG("Robokassa: SNO is 'fckoff', exiting function");
                return;
            }

            $trigger_status = $this->setting('robokassa_payment_order_status_for_second_check');
            if (empty($trigger_status)) {
                $trigger_status = 'completed';
            }

            $trigger_status = str_replace('wc-', '', $trigger_status);

            if ($new_status != $trigger_status) {
                $this->robokassa_payment_DEBUG("Robokassa: New status ($new_status) does not match trigger status ($trigger_status), exiting function");
                return;
            }

            $order = \FluentCart\App\Models\Order::find($order_id);

            if (empty($order)) {
                $this->robokassa_payment_DEBUG("Robokassa: Order not found for order_id: $order_id, exiting function");
                return;
            }

            $customerEmail = $order->customer->email ?? null;

            $billingAddress = \FluentCart\App\Models\OrderAddress::where('order_id', $order_id)
                ->where('type', 'billing')
                ->first();
            if ($billingAddress) {
                $customerPhone = $billingAddress->phone ?? null;
                if (empty($phone) && !empty($billingAddress->meta['other_data']['phone'])) {
                    $customerPhone = $billingAddress->meta['other_data']['phone'];
                }
            }

            /** @var array $fields */
            $fields = [
                'merchantId' => $this->setting('robokassa_payment_MerchantLogin'),
                'id' => $robokassa_invId + 1,
                'originId' => $robokassa_invId,
                'operation' => 'sell',
                'sno' => $sno,
                'url' => urlencode('http://' . $_SERVER['HTTP_HOST']),
                'total' => number_format($order->total_amount / 100, 2, '.', ''),
                'items' => [],
                'client' => [
                    'email' => $customerEmail,
                    'phone' => $customerPhone,
                ],
                'payments' => [
                    [
                        'type' => 2,
                        'sum' => number_format($order->total_amount / 100, 2, '.', ''),
                    ]
                ],
                'vats' => []
            ];


            $shipping_total = $order->shipping_total;

            if ($shipping_total > 0) {
                $shipping_tax = ((isset($fields['sno']) && $fields['sno'] == 'osn') || ($country != 'KZ')) ? $default_tax : 'none';

                $products_items = [
                    'name' => 'Доставка',
                    'quantity' => 1,
                    'cost' => number_format(((float)$order->shipping_total) / 100, 2, '.', ''),
                    'sum' => number_format(((float)$order->shipping_total) / 100, 2, '.', ''),
                    'tax' => $shipping_tax,
                    'payment_method' => 'full_payment',
                    'payment_object' => $this->setting('robokassa_payment_paymentObject_shipping') ?: $payment_object,
                ];

                $fields['items'][] = $products_items;

                if ($shipping_tax !== 'none') {
                    $fields['vats'][] = ['type' => $shipping_tax, 'sum' => $this->calculate_tax_sum($shipping_tax, $shipping_total)];
                }
            }

            foreach ($order->order_items as $item) {
                $item_total = round(((float)$item->subtotal) / 100, 2);
                $item_tax_code = $this->robokassa_payment_get_item_tax($item);
                $item_tax_to_send = ((isset($fields['sno']) && $fields['sno'] == 'osn') || $country == 'RU') ? $item_tax_code : 'none';

                $products_items = [
                    'name' => $item->post_title,
                    'quantity' => $item->quantity,
                    'sum' => $item_total,
                    'tax' => $item_tax_to_send,
                    'payment_method' => 'full_payment',
                    'payment_object' => $second_check_payment_object,
                ];


                $fields['items'][] = $products_items;

                if ($item_tax_to_send !== 'none') {
                    $fields['vats'][] = ['type' => $item_tax_to_send, 'sum' => $this->calculate_tax_sum($item_tax_to_send, $item_total)];
                }
            }

            /** @var string $startupHash */
            $startupHash = $this->formatSignFinish(
                base64_encode(
                    $this->formatSignReplace(
                        json_encode($fields)
                    )
                )
            );

            $passes   = $this->getRobokassaPasses();
            $pass1 = $passes['pass1'];
            $pass2 = $passes['pass2'];

            /** @var string $sign */
            $sign = $this->formatSignFinish(
                base64_encode(
                    md5(
                        $startupHash .
                        ($pass1)
                    )
                )
            );

            $curl = curl_init('https://ws.roboxchange.com/RoboFiscal/Receipt/Attach');
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($curl, CURLOPT_POSTFIELDS, $startupHash . '.' . $sign);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($startupHash . '.' . $sign))
            );
            $result = curl_exec($curl);

            if ($result === false) {
                $this->robokassa_payment_DEBUG("Robokassa: cURL error: " . curl_error($curl));
            } else {
                $this->robokassa_payment_DEBUG("Robokassa: cURL result: " . $result);
            }

            curl_close($curl);
        } else {
            //$this->robokassa_payment_DEBUG("Robokassa: Payment method is not advance, full_prepayment, or prepayment, no action taken");
        }
    }




    /**
     * FluentCart: смена статуса заказа
     */
    public function robokassa_onOrderChanged($data)
    {
        if (
            empty($data['new_status']) ||
            $data['new_status'] !== 'completed' ||
            empty($data['order']['id'])
        ) {
            return;
        }

        $this->robokassa_payment_smsWhenCompleted((int)$data['order']['id']);
    }

    /**
     * Отправка СМС при завершении заказа
     */
    
    public function robokassa_payment_smsWhenCompleted($order_id)
    {
        $debug = '';
        
        if ($this->setting('robokassa_payment_sms2_enabled') !== 'yes') {
            return;
        }

        $order = \FluentCart\App\Models\Order::find($order_id);
        if (!$order) {
            $this->robokassa_payment_DEBUG($debug . "Заказ не найден\r\n");
            return;
        }

        $phone = '';

        $billingAddress = \FluentCart\App\Models\OrderAddress::where('order_id', $order_id)
            ->where('type', 'billing')
            ->first();
        if ($billingAddress) {
            $phone = $billingAddress->phone ?? null;
            if (empty($phone) && !empty($billingAddress->meta['other_data']['phone'])) {
                $phone = $billingAddress->meta['other_data']['phone'];
            }
        }

        if (!empty($phone)) {
            $phone = preg_replace('/\D+/', '', (string) $phone);
        }

        if (empty($phone)) {
            $this->robokassa_payment_DEBUG($debug . "Не удалось найти номер телефона для заказа ID: " . $order_id . "\r\n");
            return;
        }

        $mrhLogin = $this->setting('robokassa_payment_MerchantLogin');
        $passes   = $this->getRobokassaPasses();
		$pass1 = $passes['pass1'];
		$pass2 = $passes['pass2'];

        $message  = $this->setting('robokassa_payment_sms2_text');
        $translit = ($this->setting('robokassa_payment_sms_translit') === 'yes');

        try {
            global $wpdb;
            $dbWrapper = new \RobokassaFluentCart\API\RoboDataBase( $wpdb );

            $api = new \RobokassaFluentCart\API\RobokassaPayAPI($mrhLogin, $pass1, $pass2, 'md5', $this->settings);

            $sms = new \RobokassaFluentCart\API\RobokassaSms(
                $dbWrapper,
                $api,
                $phone,
                $message,
                $translit,
                $order_id,
                2
            );

            $sms->send();

        } catch (\Throwable $e) {
            $debug .= "SMS-2 error: {$e->getMessage()}\r\n";
        }

    }


    /**
     * FluentCart: смена статуса заказа
     */
    public function handle_hold_accept_order()
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
            return;
        }

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;

        $order = \FluentCart\App\Models\Order::find($order_id);
        if (! $order) {
            $this->logDebug("Order not found: {$order_id}");
            wp_send_json_error(['message' => 'order_not_found'], 404);
            return;
        }

        $latestSuccessfulChargeTransaction = \FluentCart\App\Models\OrderTransaction::where('order_id', $order->id)
            ->where('transaction_type', 'charge')
            ->where('status', 'succeeded')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($latestSuccessfulChargeTransaction) {
            $robokassa_invId = $latestSuccessfulChargeTransaction->meta['robokassa_invId'] ?? null;
            $OutSum = $latestSuccessfulChargeTransaction->total;
        } else {
            $this->robokassa_payment_DEBUG("No pending charge transaction for order {$order->id}");
        }

        $this->robokassa_hold_confirm($order_id, $robokassa_invId, 'on-hold', 'pending', $order, $latestSuccessfulChargeTransaction);
    }


    /**
     * Холдирование. Подтверждение платежа
     */
    function robokassa_hold_confirm($order_id, $robokassa_invId, $old_status, $new_status, $order, $latestSuccessfulChargeTransaction) {
        $option_value = $this->setting('robokassa_payment_hold_onoff');
        if (($option_value == 1)
                && $old_status === 'on-hold' && $new_status === 'pending') {

            $shipping_total = $order->shipping_total;

            $receipt_items = array();
            $default_tax = $this->robokassa_payment_get_default_tax();
            $country = $this->setting('robokassa_country_code');
            $sno = $this->setting('robokassa_payment_sno');
            $total_receipt = 0;

            foreach ($order->order_items as $item) {
                $current = array();
                $current['name'] = $item->post_title;
                $current['quantity'] = $item->quantity;

                $item_sum = $item->subtotal / 100;
                $current['sum'] = number_format($item_sum, 2, '.', '');
                $current['cost'] = number_format($item_sum / $item->quantity, 2, '.', '');

                $total_receipt += $current['sum'];

                if ($country == 'RU') {
                    $current['payment_object'] = $this->setting('robokassa_payment_paymentObject');
                    $current['payment_method'] = $this->setting('robokassa_payment_paymentMethod');
                }

                $item_tax = $this->robokassa_payment_get_item_tax($item);
                if (($sno == 'osn') || $country == 'RU') {
                    $current['tax'] = $item_tax;
                } else {
                    $current['tax'] = 'none';
                }

                $receipt_items[] = $current;
            }

            if ($shipping_total > 0) {
                $shipping_item = array(
                    'name' => 'Доставка',
                    'quantity' => 1,
                    'cost' => number_format(((float)$order->shipping_total) / 100, 2, '.', ''),
                    'sum' => number_format(((float)$order->shipping_total) / 100, 2, '.', ''),
                    'payment_method' => 'full_payment',
                    'payment_object' => $this->setting('robokassa_payment_paymentObject_shipping') ?: $this->setting('robokassa_payment_paymentObject'),
                );

                if (($sno == 'osn') || ($country != 'KZ')) {
                    $shipping_item['tax'] = $default_tax;
                } else {
                    $shipping_item['tax'] = 'none';
                }

                $receipt_items[] = $shipping_item;
            }

            $request_data = array(
                'MerchantLogin' => $this->setting('robokassa_payment_MerchantLogin'),
                'InvoiceID' => $robokassa_invId,
                'OutSum' => number_format(((float)$latestSuccessfulChargeTransaction->total) / 100, 2, '.', ''),
                'Receipt' => json_encode(array('items' => $receipt_items)),
            );

            $merchant_login = $this->setting('robokassa_payment_MerchantLogin');
            $password1 = $this->setting('robokassa_payment_shoppass1');

            $signature_value = md5("{$merchant_login}:{$request_data['OutSum']}:{$request_data['InvoiceID']}:{$request_data['Receipt']}:{$password1}");
            $request_data['SignatureValue'] = $signature_value;

            $response = wp_remote_post('https://auth.robokassa.ru/Merchant/Payment/Confirm', array(
                'body' => $request_data,
            ));
        }
    }


    /**
    * FluentCart: смена статуса заказа
    */
    public function robokassa_handle_status_change($data)
    {
        $orderArr = $data['order'] ?? null;

        if (!$orderArr || !isset($orderArr['id'])) {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Данные заказа или ID не найдены в данных хука."
            );
            return;
        }

        $old = $orderArr->getMeta('_fluentcart_status_before_cancel');
        $new = $data['new_status'] ?? null;

        $order_id = (int) $orderArr['id'];

        $order = \FluentCart\App\Models\Order::find($order_id);

        if (!$order) {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Модель FluentCart Order не найдена для ID: " . $order_id
            );
            return;
        }
        
        $transaction = \FluentCart\App\Models\OrderTransaction::where('order_id', $order->id)
            ->where('transaction_type', 'charge')
            ->where('status', 'succeeded')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($transaction) {
            $transaction_id = $transaction->id;
            $robokassa_invId = $transaction->meta['robokassa_invId'] ?? null;
            $OutSum = $transaction->total;
        } else {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Не найдена успешная транзакция оплаты для заказа ID "
                . $order->id
            );
        }

        $this->robokassa_hold_cancel($OutSum, $robokassa_invId, $old, $new, $order, $transaction);
    }

    /**
     * Холдирование. Отмена заказа
     */
    function robokassa_hold_cancel($OutSum, $robokassa_invId, $old_status, $new_status, $order, $transaction)
    {
        $option_value = $this->setting('robokassa_payment_hold_onoff');
        if (($option_value == 1)
            && $old_status === 'on-hold' && $new_status === 'canceled') {

            $request_data = array(
                'MerchantLogin' => $this->setting('robokassa_payment_MerchantLogin'),
                'InvoiceID' => $robokassa_invId,
                'OutSum' => $OutSum,
            );

            $merchant_login = $this->setting('robokassa_payment_MerchantLogin');
            $password1 = $this->setting('robokassa_payment_shoppass1');

            $signature_value = md5("{$merchant_login}::{$request_data['InvoiceID']}:{$password1}");
            $request_data['SignatureValue'] = $signature_value;

            $response = wp_remote_post('https://auth.robokassa.ru/Merchant/Payment/Cancel', array(
                'body' => $request_data,
            ));

            if (is_wp_error($response)) {
                $order->update([
                    'note' => 'Error sending payment request: ' . $response->get_error_message(),
                ]);
            } else {
                $transaction->fill([
                    'status' => Status::TRANSACTION_REFUNDED,
                ]);
                $transaction->save();

                $order->update([
                    'payment_status' => Status::PAYMENT_REFUNDED,
                    'note' => 'Robokassa: холдирование было отменено вами, либо автоматически после 5 дней ожидания',
                ]);
                $order->updateMeta('_fluentcart_status_before_cancel', 'canceled');
            }
        }
    }


    /**
     * Холдирование. Отмена заказа через 5 дней
     */
    function robokassa_hold_cancel_after5($order_id)
    {
        $order = \FluentCart\App\Models\Order::find($order_id);

        if (!$order) {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Модель FluentCart Order не найдена для ID: " . $order_id
            );
            return;
        }
        
        $transaction = \FluentCart\App\Models\OrderTransaction::where('order_id', $order->id)
            ->where('transaction_type', 'charge')
            ->where('status', 'succeeded')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($transaction) {
            $transaction_id = $transaction->id;
            $robokassa_invId = $transaction->meta['robokassa_invId'] ?? null;
            $OutSum = $transaction->total;
        } else {
            $this->robokassa_payment_DEBUG(
                "robokassa_handle_status_change: Не найдена успешная транзакция оплаты для заказа ID "
                . $order->id
            );
        }

        if ($order) {
            if ($order->status === 'on-hold') {
                $request_data = array(
                    'MerchantLogin' => $this->setting('robokassa_payment_MerchantLogin'),
                    'InvoiceID' => $robokassa_invId,
                    'OutSum' => $OutSum,
                );

                $merchant_login = $this->setting('robokassa_payment_MerchantLogin');
                $password1 = $this->setting('robokassa_payment_shoppass1');

                $signature_value = md5("{$merchant_login}::{$request_data['InvoiceID']}:{$password1}");
                $request_data['SignatureValue'] = $signature_value;

                $response = wp_remote_post('https://auth.robokassa.ru/Merchant/Payment/Cancel', array(
                    'body' => $request_data,
                ));

                if (is_wp_error($response)) {
                    $order->update([
                        'note' => 'Error sending payment request: ' . $response->get_error_message(),
                    ]);
                }

                $transaction->fill([
                    'status' => Status::TRANSACTION_REFUNDED,
                ]);
                $transaction->save();

                $order->update([
                    'note' => 'Robokassa: холдирование было отменено автоматически после 5 дней ожидания',
                    'status' => Status::ORDER_CANCELED,
                    'payment_status' => Status::PAYMENT_REFUNDED,
                ]);
                $order->updateMeta('_fluentcart_status_before_cancel', 'canceled');
            }
        }
    }



    /**
    * Проверяет, есть ли в корзине хотя бы один товар-подписка
    */
    private function cartHasSubscription($args): bool
    {
        $cart = $args['cart'] ?? null;
        if (!$cart) {
            return false;
        }

        $cartDataRaw = $cart->cart_data ?? null;
        if (!$cartDataRaw) {
            return false;
        }

        $cartItems = is_string($cartDataRaw) ? json_decode($cartDataRaw, true) : $cartDataRaw;
        if (!is_array($cartItems)) {
            return false;
        }

        foreach ($cartItems as $item) {
            $paymentType = $item['other_info']['payment_type'] ?? 'onetime';
            if ($paymentType !== 'onetime') {
                return true;
            }
        }

        return false;
    }


    /**
    * Чекбокс перед кнопкой оплаты
    */

    function robokassa_before_payment_methods($args) {
        if (!$this->cartHasSubscription($args)) {
            return;
        }

        // Поле с чекбоксом
        $termsText = sprintf(
			$this->setting('robokassa_agreement_text'),
			$this->setting('robokassa_agreement_pd_link'),
			$this->setting('robokassa_agreement_oferta_link')
        );
    ?>
        
        <div class="fct_checkout_marketing_opt_in" role="group" aria-labelledby="marketing_opt_in_label">
            <label for="marketing_opt_in" class="fct_input_label fct_input_label_checkbox">
                <input
                    data-fluent-cart-marketing-opt-in="yes"
                    type="checkbox"
                    class="fct-input fct-input-checkbox"
                    id="marketing_opt_in"
                    name="marketing_opt_in"
                    value="yes"
                    aria-label="<?php echo esc_attr($termsText); ?>"
                >
                <span><?php echo wp_kses_post($termsText); ?></span>
            </label>
        </div>

        <?php
    }


    /**
    * Валидация чекбокса перед кнопкой оплаты
    */

    function robokassa_checkout_validate_data($errors, $args){
        $hasSubscription = false;

        if (!empty($args['cart'])) {
            $hasSubscription = $this->cartHasSubscription($args);
        } elseif (!empty($args['data']['cart_hash'])) {
            $cart = \FluentCart\App\Models\Cart::find($args['data']['cart_hash']);
            if ($cart) {
                $hasSubscription = $this->cartHasSubscription(['cart' => $cart]);
            }
        }

        if (!$hasSubscription) {
            return $errors;
        }

        if(empty($args['data']['marketing_opt_in'])){
            $errors['marketing_opt_in']['required'] = __('Требуется согласие на регулярные списания', 'fluent-cart');
        }

        return $errors;
    }

    function robokassa_checkout_prepare_other_data($args){
        //Save your data here
        //$args['request_data']['marketing_opt_in']);
    }

    function robokassa_widgets_single_order_page($widgets, $order) {
        return $widgets;
    }


}