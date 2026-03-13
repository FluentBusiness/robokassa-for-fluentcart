<?php // Виджет на странице товара

namespace RobokassaFluentCart;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\Product;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Cart;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\ProductVariation;


class RobokassaPaymentWidget
{
    protected $settings;
    protected $logger;

    public function __construct($settings = null, $logger = null)
    {
        $this->settings = $settings;
        $this->logger   = is_callable($logger) ? $logger : null;

        add_action('wp_enqueue_scripts', [$this, 'enqueueRobokassaScripts']);
        add_action('init', [$this, 'registerRobokassaEndpoint']);
        add_action('parse_request', [$this, 'handleRobokassaEndpoint']);
    }

    protected function log($msg, $data = [])    {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $msg, $data);
            return;
        }
        error_log('robokassa: ' . $msg . ' - ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    protected function setting($key, $default = null)
    {
        if (is_object($this->settings) && method_exists($this->settings, 'get')) {
            try {
                return $this->settings->get($key, $default);
            } catch (\Throwable $e) {
                $this->log('setting_get_exception', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }
        return $default;
    }

    /**
     * Возвращает значение из списка допустимых вариантов
     */
    protected function getChoiceOption($option_name, array  $allowed, $default)
    {
        $value = sanitize_text_field($this->setting($option_name, $default));
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Возвращает булеву настройку в формате 'true'/'false'
     */
    protected function getBooleanOption($option_name, $default)
    {
        $value = $this->setting($option_name, $default);
        return $value === 'false' ? 'false' : 'true';
    }

    /**
     * Конвертирует настройку yes/no в строку 'true'/'false'
     */
    protected function getBooleanYesNoOption($option_name, $default)
    {
        $value = $this->setting($option_name, $default);
        return $value === 'no' ? 'false' : 'true';
    }

    /**
     * Возвращает настройку yes/no без преобразования
     */
    protected function getYesNoOption($option_name, $default)
    {
        $value = $this->setting($option_name, $default);
        return $value === 'no' ? 'no' : 'yes';
    }

    /**
     * Регистрирует кастомный endpoint для добавления товара в корзину
     */
    public function registerRobokassaEndpoint()
    {
        add_rewrite_endpoint('robokassa-add-to-cart', EP_ROOT);
    }

    /**
     * Обрабатывает GET-запрос добавления товара в корзину и редиректит на чекаут
     */
    public function handleRobokassaEndpoint()
    {

        if (isset($_GET['robokassa_action']) && $_GET['robokassa_action'] === 'add_to_cart') {
          
            $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
            $quantity = isset($_GET['quantity']) ? intval($_GET['quantity']) : 1;
            
            if ($product_id > 0) {
                $cart_added = $this->addToFluentCart($product_id, $quantity);
                
                if ($cart_added) {
                    $storeSettings = new StoreSettings();
                    $checkout_page_base_url = $storeSettings->getCheckoutPage();
                
                    // Получаем payment_method прямо из GET (если нет — fallback 'robokassa')
                    $raw_method = $_GET['payment_method'] ?? 'robokassa';

                    // Защита от header-injection
                    $raw_method = str_replace(array("\r", "\n"), '', $raw_method);

                    // WP-санитизация и нормализация в нижний регистр
                    $selectedMethod = strtolower( sanitize_text_field( wp_unslash( $raw_method ) ) );

                    // Маппинг/валидатор (ключи в нижнем регистре)
                    $allowedMethods = [
                        'yandexpaysplit' => 'yandexpaysplit',
                        'podeli'         => 'podeli',
                        'otp'            => 'otp',
                        'mokka'          => 'mokka',
                        'robokassa'      => 'robokassa'
                    ];

                    $urlParam = $allowedMethods[$selectedMethod] ?? 'robokassa';

                    $final_redirect_url = add_query_arg([
                        'payment_method' => $urlParam
                    ], $checkout_page_base_url);
                    
                    if (!headers_sent()) {
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        header('Location: ' . $final_redirect_url);
                        exit;
                    } else {
                        $this->log('robokassa_endpoint_fallback_js_redirect', ['url' => $final_redirect_url]);
                        echo "<script>window.location.href = '" . esc_url($final_redirect_url) . "';</script>";
                        exit;
                    }
                } else {
                    $this->log('robokassa_endpoint_failed_to_add_to_cart', ['product_id' => $product_id]);
                    wp_die('Ошибка: Не удалось добавить товар в корзину.');
                }
            } else {
                $this->log('robokassa_endpoint_invalid_product_id', ['product_id' => $product_id]);
                wp_die('Неверный ID товара');
            }
        }
    }

    /**
     * Добавляет товар в корзину FluentCart, создавая её при необходимости
     */
    protected function addToFluentCart($product_id, $quantity = 1)
    {

        try {
            $cart = CartHelper::getCart();

            // Если корзина не найдена, создаем новую
            if (!$cart) {
              
                $new_cart_hash = wp_generate_uuid4(); // Генерируем уникальный хеш
                $customer_id = get_current_user_id(); // ID текущего пользователя, 0 если не залогинен
                $customer_email = '';
                if ($customer_id) {
                    $user_info = get_userdata($customer_id);
                    $customer_email = $user_info ? $user_info->user_email : '';
                }

                // Получаем валюту через StoreSettings
                $storeSettings = new StoreSettings();
                $currency = $storeSettings->get('currency', 'RYB'); // Получаем валюту из настроек, по умолчанию RYB

                $cart = Cart::create([
                    'cart_hash'   => $new_cart_hash,
                    'customer_id' => $customer_id,
                    'email'       => $customer_email,
                    'status'      => 'active',
                    'currency'    => $currency,
                ]);

                if ($cart) {
                    setcookie('fct_cart_hash', $new_cart_hash, [
                        'expires'  => time() + DAY_IN_SECONDS * 30,
                        'path'     => '/',
                        'domain'   => COOKIE_DOMAIN,
                        'secure'   => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                    $_COOKIE['fct_cart_hash'] = $new_cart_hash;
                    $this->log('addToFluentCart_new_cart_created_and_cookie_set', ['cart_hash' => $new_cart_hash]);
                } else {
                    $this->log('addToFluentCart_failed_to_create_new_cart', []);
                    return false;
                }
            }

            $product = Product::find($product_id);

            $item_object_id = $product->detail->id;

            $product_variation_model = ProductVariation::where('post_id', $product_id)->first();

            // Если товар простой, FluentCart все равно может создать для него запись ProductVariation.
            // Если это вариативный товар, и мы хотим добавить конкретную вариацию,
            // то нужно будет найти ее по другим параметрам (например, атрибутам).
            // Для простоты, пока ищем по product_id, что должно найти дефолтную/единственную вариацию.

            if (!$product_variation_model) {
                $this->log('Robokassa Debug: ProductVariation model not found for product_id', ['product_id' => $product_id]);
                return false;
            }

            $item_object_id = $product_variation_model->id;
            $item_variation_id = $product_variation_model->id;


            if (!$product) {
                $this->log('addToFluentCart_product_not_found', ['product_id' => $product_id]);
                return false;
            }
            
            $mediaUrl = $product->thumbnail;
            if (empty($mediaUrl)) {
                $mediaUrl = '/wp-content/plugins/fluent-cart/assets/images/placeholder.svg';
            }

            // Определение view_url
            $item_view_url = get_permalink($product_id);
            $item_view_url = add_query_arg('selected', $item_object_id, $item_view_url);

            // sold_individually: yes или no
            $sold_individually = $product->detail->other_info['sold_individually'] ?? null;
            $is_limit_one_sale = ($sold_individually == 'yes'); 
            if ($is_limit_one_sale) {
                $quantity = 1;
            }


            $item_data = [
                'id'          => $item_object_id,
                'post_id'          => $product_id,
                'fulfillment_type' => $product->fulfillment_type ?? 'digital',
                'other_info'       => [
                    'description'      => $product->detail->description ?? '',
                    'payment_type'     => $product->detail->payment_type ?? 'onetime',
                    'times'            => $product->detail->times ?? '',
                    'repeat_interval'  => $product->detail->repeat_interval ?? '',
                    'trial_days'       => $product->detail->trial_days ?? '',
                    'billing_summary'  => $product->detail->billing_summary ?? '',
                    'manage_setup_fee' => $product->detail->manage_setup_fee ?? 'no',
                    'signup_fee_name'  => $product->detail->signup_fee_name ?? '',
                    'signup_fee'       => $product->detail->signup_fee ?? 0,
                    'setup_fee_per_item' => $product->detail->setup_fee_per_item ?? 'no',
                    'installment'      => $product->detail->installment ?? 'no',
                ],
                'quantity'         => $quantity,
                'price'            => $product_variation_model->price ?? $product->detail->min_price ?? 0, // Берем цену из вариации
                'unit_price'       => $product_variation_model->price ?? $product->detail->min_price ?? 0, // Берем цену из вариации
                'line_total'       => ($product_variation_model->price ?? $product->detail->min_price ?? 0) * $quantity,
                'subtotal'         => ($product_variation_model->price ?? $product->detail->min_price ?? 0) * $quantity,
                'object_id'        => $item_object_id,
                'title'            => $product->post_title ?? '',
                'post_title'       => $product->post_title ?? '',
                'coupon_discount'  => 0,
                'cost'             => $product->cost ?? 0,
                'featured_media'   => $mediaUrl,
                //'view_url'         => get_permalink($product_id),
                'view_url'         => $item_view_url,
                'variation_type'   => $product->variation_type ?? 'simple',
                'bundle_child_ids' => $product->bundle_child_ids ?? [],
                'child_variants'   => $product->child_variants ?? [],
                'variation_id'     => $item_variation_id,
            ];
            

            $existing_item_index = null;
            $items_in_cart = $cart->cart_data;

            if (!empty($items_in_cart)) {
                foreach ($items_in_cart as $index => $item) {
                    // Сравниваем object_id, так как это уникальный идентификатор элемента корзины
                    if (isset($item['object_id']) && $item['object_id'] == $item_object_id) {
                        $existing_item_index = $index;
                        break;
                    }
                }
            }
            
            if ($existing_item_index !== null) {
                // Если товар уже есть в корзине, обновляем количество
                $existing_item_data = $items_in_cart[$existing_item_index]; // Получаем полные данные существующего элемента
                $current_quantity = $existing_item_data['quantity'] ?? 0;
                $new_quantity = $current_quantity + $quantity;

                // Обновляем количество и пересчитываем итоги в существующих данных
                if ($is_limit_one_sale && $new_quantity > 1) {
                    $new_quantity = 1;
                }
                $existing_item_data['quantity'] = $new_quantity;
                $existing_item_data['line_total'] = ($existing_item_data['unit_price'] ?? 0) * $new_quantity;
                //$existing_item_data['subtotal'] = ($existing_item_data['unit_price'] ?? 0) * $new_quantity;

                // Передаем обновленные полные данные обратно в addItem с индексом
                $cart->addItem($existing_item_data, $existing_item_index);
            } else {
                // Если товара нет в корзине, добавляем новый
                $cart->addItem($item_data);
            }


            return true;
        } catch (\Exception $e) {
            $this->log('addToFluentCart_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;        }
    }

    /**
     * Подключает JS-скрипты виджета Robokassa на странице товара
     */
    public function enqueueRobokassaScripts()
    {
        if (!is_singular(FluentProducts::CPT_NAME)) {
            return;
        }

        if (wp_doing_ajax()) {
            return;
        }

        $settings = $this->getWidgetSettings();
        if (!$settings['enabled']) {
            return;
        }

        wp_enqueue_script(
            'robokassa-badge-widget',
            'https://auth.robokassa.ru/merchant/bundle/robokassa-iframe-badge.js',
            [],
            null,
            true
        );

        wp_enqueue_script(
            'robokassa-fluentcart-widget',
            FLUENT_ROBOKASSA_PLUGIN_URL . 'assets/js/robokassa-fluentcart-widget.js',
            ['robokassa-badge-widget'],
            '1.0.0',
            true
        );

        $widget_data = $this->getWidgetRenderData();
        if ($widget_data) {
            wp_localize_script(
                'robokassa-fluentcart-widget',
                'robokassaFluentCartWidget',
                $widget_data
            );
        }
    }

    /**
     * Собирает данные для передачи в JS-виджет на странице товара
     */
    protected function getWidgetRenderData()
    {
        $settings = $this->getWidgetSettings();
        if (!$settings['enabled']) {
            return false;
        }

        $product_id = get_the_ID();
        if (!$product_id) {
            return false;
        }

        $product = Product::find($product_id);
        if (!$product) {
            return false;
        }

        // Не показываем виджет для подписочных товаров
        $variation = ProductVariation::where('post_id', $product_id)->first();
        if ($variation && $variation->payment_type === 'subscription') {
            return false;
        }

        $amount = $product->detail->min_price / 100;
        if ($amount <= 0) {
            return false;
        }

        $signature_data = $this->getSignatureData($amount);
        if (!$signature_data) {
            return false;
        }

        $url_to_php_handler = $this->prepareCheckoutUrl($product_id);
        $attributes = $this->prepareAttributes($settings, $signature_data, $url_to_php_handler);

        $fluent_cart_nonce = '';
        if (function_exists('wp_create_nonce')) {
            $fluent_cart_nonce = wp_create_nonce('wp_rest');
        }

        $data_to_localize = [
            'enabled' => true,
            'component' => $settings['component'],
            'attributes' => $attributes,
            'productId' => $product_id,
            'checkoutUrl' => $url_to_php_handler,
            'settings' => $settings,
            'fluentCartNonce' => $fluent_cart_nonce,
        ];

        return $data_to_localize;
    }

    /**
     * Возвращает настройки отображения виджета из конфига плагина
     */
    protected function getWidgetSettings()
    {
        $settings = [
            'enabled' => $this->getBooleanYesNoOption('robokassa_widget_enabled', 'no') === 'true',
            'component' => $this->getChoiceOption('robokassa_widget_component', ['widget', 'badge'], 'widget'),
            'theme' => $this->getChoiceOption('robokassa_widget_theme', ['light', 'dark'], 'light'),
            'size' => $this->getChoiceOption('robokassa_widget_size', ['s', 'm'], 'm'),
            'show_logo' => $this->getYesNoOption('robokassa_widget_show_logo', 'yes'),
            'type' => $this->getChoiceOption('robokassa_widget_type', ['', 'bnpl', 'credit'], ''),
            'border_radius' => sanitize_text_field($this->setting('robokassa_widget_border_radius', '')),
            'has_second_line' => $this->getBooleanYesNoOption('robokassa_widget_has_second_line', 'no'),
            'description_position' => $this->getChoiceOption('robokassa_widget_description_position', ['left', 'right'], 'left'),
            'color_scheme' => $this->getChoiceOption('robokassa_widget_color_scheme', ['primary', 'secondary', 'accent', ''], ''),
        ];

        if ($this->setting('robokassa_country_code', 'RU') === 'KZ') {
            $settings['enabled'] = false;
        }

        return $settings;
    }

    /**
     * Формирует подпись и параметры для инициализации виджета Robokassa
     */
    protected function getSignatureData($amount)
    {
        $merchant = sanitize_text_field($this->setting('robokassa_payment_MerchantLogin'));
        if (!$merchant) return null;

        $test_mode = $this->setting('robokassa_payment_test_onoff') === 'yes';
        $pass = $test_mode ? $this->setting('robokassa_payment_testshoppass1') : $this->setting('robokassa_payment_shoppass1');
        $pass = is_string($pass) ? $pass : '';
        if (!$pass) return null;

        $out_sum = number_format((float)$amount, 2, '.', '');
        $signature = md5(sprintf('%s:%s::%s', $merchant, $out_sum, $pass));

        return [
            'merchantLogin' => $merchant,
            'outSum' => $out_sum,
            'signature' => $signature,
        ];
    }

    /**
     * Формирует URL для редиректа на чекаут с нужным товаром
     */
    protected function prepareCheckoutUrl($product_id)
    {
        $url_to_handler = add_query_arg([
            'robokassa_action' => 'add_to_cart',
            'product_id' => $product_id,
            'quantity' => 1
        ], home_url('/'));
        
        return $url_to_handler;
    }

    /**
     * Собирает итоговый массив атрибутов для виджета в зависимости от типа компонента
     */
    protected function prepareAttributes(array $settings, array $signature, $checkout_url)
    {
        $attributes = $this->prepareCommonAttributes($settings, $signature);

        if ($settings['component'] === 'widget') {
            return $this->applyWidgetAttributes($attributes, $settings, $checkout_url);
        }

        return $this->applyBadgeAttributes($attributes, $settings);
    }

    /**
     * Формирует базовые атрибуты, общие для всех типов компонентов
     */
    protected function prepareCommonAttributes(array $settings, array $signature)
    {
        $attributes = [
            'outSum' => $signature['outSum'],
            'merchantLogin' => $signature['merchantLogin'],
            'signature' => $signature['signature'],
            'theme' => $settings['theme'],
            'size' => $settings['size'],
            'mode' => 'checkout',
            'oncheckout' => 'robokassaWidgetHandleCheckout',
        ];

        if ($settings['show_logo'] === 'no') {
            $attributes['showLogo'] = 'false';
        }

        if ($settings['type'] !== '') {
            $attributes['type'] = $settings['type'];
        }

        return $attributes;
    }

    /**
     * Добавляет атрибуты, специфичные для компонента widget
     */
    protected function applyWidgetAttributes(array $attributes, array $settings, $checkout_url)
    {
        if ($settings['border_radius'] !== '') {
            $attributes['borderRadius'] = $settings['border_radius'];
        }

        if ($settings['has_second_line'] === 'true') {
            $attributes['hasSecondLine'] = 'true';
        }

        $attributes['descriptionPosition'] = $settings['description_position'];
        $attributes['checkoutUrl'] = $checkout_url;

        return $attributes;
    }

    protected function applyBadgeAttributes(array $attributes, array  $settings)
    {
        if ($settings['color_scheme'] !== '') {
            $attributes['colorScheme'] = $settings['color_scheme'];
        }

        return $attributes;
    }



}
