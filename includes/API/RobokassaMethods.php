<?php // Выводит дополнительные способы оплаты

namespace RobokassaFluentCart\API;

if (! defined('ABSPATH')) {
    exit;
}

use WP_REST_Request;
use WP_REST_Response;
use FluentCart\App\Helpers\CartHelper;


class RobokassaMethods
{
    private $settings;
    private $api; // RobokassaPayAPI|null
    private $logger;
    private $cacheTtl = 300; // в секундах

    public function __construct($settings = null, $api = null, $logger = null)
    {
        $this->settings = $settings;
        $this->api = $api;
        $this->logger = $logger;
    }

    protected function log($msg, $data = [])
    {
        if (is_callable($this->logger)) {
            try {
                call_user_func($this->logger, $msg, $data);
                return;
            } catch (\Throwable $e) {

            }
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
     * Получает API объект унифицированным способом
     */
    private function getApi(): ?RobokassaPayAPI
    {
        if ($this->api !== null) {
            return $this->api;
        }
        
        return $this->createApiFromSettings();
    }

    /**
     * Создает API из настроек тем же способом, что и в других местах
     */
    private function createApiFromSettings(): ?RobokassaPayAPI
    {
        try {
            // Используем те же ключи настроек, что и в других местах
            $merchant = $this->setting('robokassa_payment_MerchantLogin');
            $passes = $this->getRobokassaPasses();
            $pass1 = $passes['pass1'] ?? '';
            $pass2 = $passes['pass2'] ?? '';
            
            // Создаем API с теми же параметрами
            return new RobokassaPayAPI(
                $merchant,
                $pass1,
                $pass2,
                'md5',
                $this->settings
            );
            
        } catch (\Throwable $e) {
            $this->log('api_creation_error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Вспомогательная функция, для быстрого доступа к паролям.
     */
    private function getRobokassaPasses(): array
    {
        $isTest = $this->setting('robokassa_payment_test_onoff') === 'yes';

        if ($isTest) {
            return [
                'pass1' => $this->setting('robokassa_payment_testshoppass1'),
                'pass2' => $this->setting('robokassa_payment_testshoppass2'),
            ];
        }

        return [
            'pass1' => $this->setting('robokassa_payment_shoppass1'),
            'pass2' => $this->setting('robokassa_payment_shoppass2'),
        ];
    }


    /**
     * Возвращает список алиасов способов оплаты, обновляя их из API при устаревании кэша
     */
    public function getAliases(): array
    {
        $cached = get_transient('fluent_robokassa_aliases_v1');
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $path = defined('FLUENT_ROBOKASSA_PLUGIN_DIR')
            ? rtrim(FLUENT_ROBOKASSA_PLUGIN_DIR, '/') . '/data/currencies.json'
            : __DIR__ . '/data/currencies.json';

        $fileExists = file_exists($path);
        $shouldUpdateFromApi = false;
        
        if (!$fileExists) {
            $shouldUpdateFromApi = true;
        } else {
            $fileMTime = filemtime($path);
            $fileAge = time() - $fileMTime;
            
            if ($fileAge > 24 * 3600) {
                $shouldUpdateFromApi = true;
            } else {
                $raw = @file_get_contents($path);
                $data = json_decode($raw, true);
                if (!is_array($data) || empty($data)) {
                    $shouldUpdateFromApi = true;
                }
            }
        }

        if ($shouldUpdateFromApi) {
            $api = $this->getApi();
            if ($api && method_exists($api, 'getCurrLabels')) {
                try {
                    $raw = $api->getCurrLabels();
                    if ($raw) {
                        $arr = json_decode(json_encode($raw), true);
                        if (is_array($arr) && !empty($arr)) {
                            $aliases = $this->collectAliasesFromCurrencies($arr);
                            
                            // Сохраняем в файл
                            try {
                                $dir = dirname($path);
                                if (!file_exists($dir)) {
                                    wp_mkdir_p($dir);
                                }
                                
                                @file_put_contents(
                                    $path, 
                                    json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                );
                            } catch (\Throwable $e) {
                                $this->log('file_write_error', ['error' => $e->getMessage()]);
                            }
                            
                            set_transient('fluent_robokassa_aliases_v1', $aliases, $this->cacheTtl);
                            return $aliases;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->log('getCurrLabels_error', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($fileExists) {
            $raw = @file_get_contents($path);
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                $aliases = $this->collectAliasesFromCurrencies($data);
                set_transient('fluent_robokassa_aliases_v1', $aliases, $this->cacheTtl);
                return $aliases;
            }
        }

        return [];
    }


    /**
     * Возвращает массив объектов, которые отправляем фронту.
     * Каждый объект содержит:
     * - Alias, Title, gateway_id, allowed (bool), is_standard (bool), MinValue/MaxValue (если есть)
     */
    public function getAliasesList($amount = null): array
    {
        $map = $this->getAliases();

        $list = [];

        // STANDARD (универсальный — показывает базовые способы внутри формы Robokassa)
        $list[] = [
            'Alias' => 'STANDARD',
            'Title' => $this->getStandardMethodTitle(),
            'gateway_id' => 'robokassa',
            'MinValue' => null,
            'MaxValue' => null,
            'allowed' => true,
            'is_standard' => true,
        ];

        $optional = $this->getOptionalMethodsConfig();

        // Проверка на Казахстан
        $countryCode = $this->setting('robokassa_country_code');
        $disableByCountry = ($countryCode === 'KZ');

        $holdCode = $this->setting('robokassa_payment_hold_onoff');
        $disableByHold = ((int) $holdCode === 1);

        foreach ($optional as $cfg) {
            $aliasRaw = isset($cfg['alias']) ? strtoupper(trim($cfg['alias'])) : '';
            $gatewayId = isset($cfg['gateway_id']) ? $cfg['gateway_id'] : null;
            $optionName = isset($cfg['option']) ? $cfg['option'] : '';

            if ($aliasRaw === '') continue;
            if ($disableByCountry) continue;
            if ($disableByHold) continue;

            if ($optionName !== '') {
                $optVal = $this->setting($optionName, 'yes');
                if ($optVal === 'no') {
                    continue;
                }
            }

            if (!isset($map[$aliasRaw])) {
                continue;
            }

            $allowed = true;
            if ($amount !== null && is_numeric($amount)) {
                $allowed = $this->isAmountAllowedForAlias($aliasRaw, (float)$amount);
            }

            $obj = [
                'Alias' => $aliasRaw,
                'Title' => $cfg['title'] ?? ($gatewayId ?? $aliasRaw),
                'gateway_id' => $gatewayId ?: 'robokassa',
                'allowed' => (bool)$allowed,
                'is_standard' => false,
            ];

            if (!empty($map[$aliasRaw]['MinValue'])) $obj['MinValue'] = (string)$map[$aliasRaw]['MinValue'];
            if (!empty($map[$aliasRaw]['MaxValue'])) $obj['MaxValue'] = (string)$map[$aliasRaw]['MaxValue'];

            $list[] = $obj;
        }

        return $list;
    }


    /**
     * Регистрирует REST-маршрут для получения списка способов оплаты
     */
    public function registerRestRoutes()
    {
        add_action('rest_api_init', function() {
            register_rest_route('robokassa/v1', '/aliases', [
                'methods' => 'GET',
                'callback' => [ $this, 'restAliasesHandler' ],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * Обрабатывает REST-запрос и возвращает список доступных способов оплаты с учётом корзины
     */
    public function restAliasesHandler(WP_REST_Request $request)
    {
        try {
            $cartTotalCents = null;
            $amountRub = null;
            $hasSubscription = false;

            // Пробуем получить корзину через fct_cart_hash
            try {
                $cartHash = $request->get_param('fct_cart_hash');
                if ($cartHash) {
                    $cart = \FluentCart\App\Models\Cart::find($cartHash);
                    if ($cart) {
                        $cartTotalCents = $cart->getEstimatedTotal();
                        if ($cartTotalCents !== null && is_numeric($cartTotalCents)) {
                            $amountRub = ((float)$cartTotalCents) / 100.0;
                        }

                        $cartDataRaw = $cart->cart_data ?? null;
                        $cartItems = is_string($cartDataRaw) ? json_decode($cartDataRaw, true) : $cartDataRaw;
                        if (is_array($cartItems)) {
                            foreach ($cartItems as $item) {
                                $variationId = $item['variation_id'] ?? $item['id'] ?? null;
                                if ($variationId) {
                                    $variation = \FluentCart\App\Models\ProductVariation::find($variationId);
                                    if ($variation && $variation->payment_type === 'subscription') {
                                        $hasSubscription = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->log('cart_retrieval_error', ['error' => $e->getMessage()]);
            }

            // Fallback: сумма напрямую из JS-параметра
            if ($amountRub === null) {
                $amountParam = $request->get_param('amount');
                if ($amountParam !== null && is_numeric($amountParam)) {
                    $amountRub = (float)$amountParam;
                }
            }

            $pageTitle = $this->setting('RobokassaOrderPageTitle_robokassa');
            $pageDesc  = $this->setting('RobokassaOrderPageDescription_robokassa');

            $list = $this->getAliasesList($amountRub);

            if ($hasSubscription) {
                $list = array_values(array_filter($list, function($item) {
                    return !empty($item['is_standard']);
                }));
            }

            if (empty($list)) {
                $list = [[
                    'Alias'      => 'STANDARD',
                    'Title'      => $this->getStandardMethodTitle(),
                    'gateway_id' => 'robokassa',
                    'allowed'    => true,
                    'is_standard' => true,
                ]];
            }

            return new WP_REST_Response([
                'status'           => 'success',
                'page_title'       => $pageTitle,
                'page_description' => $pageDesc,
                'aliases'          => array_values($list),
            ], 200);

        } catch (\Throwable $e) {
            $this->log('rest_aliases_exception', ['error' => $e->getMessage()]);
            return new WP_REST_Response(['status' => 'error', 'message' => 'server_error'], 500);
        }
    }

    /**
     * Проверяет, попадает ли сумма в допустимый диапазон для указанного алиаса
     */
    public function isAmountAllowedForAlias(string $alias, $amount = null): bool
    {
        $alias = strtoupper(trim((string)$alias));
        if ($alias === '') return false;

        $map = $this->getAliases();
        if (!isset($map[$alias])) {
            return true;
        }

        if ($amount === null) return true;
        if (!is_numeric($amount)) return false;

        $details = $map[$alias];

        if (!empty($details['MaxValue'])) {
            $max = $this->normalizeAmountValue($details['MaxValue']);
            if ($max > 0 && $amount > $max) return false;
        }
        if (!empty($details['MinValue'])) {
            $min = $this->normalizeAmountValue($details['MinValue']);
            if ($min > 0 && $amount < $min) return false;
        }

        return true;
    }

    
    /**
     * Извлекает алиасы с лимитами из сырых данных API или файла currencies.json
     */
    private function collectAliasesFromCurrencies($currencies): array
    {
        $data = is_array($currencies) ? $currencies : json_decode(json_encode($currencies), true);
        if (!is_array($data)) {
            return [];
        }

        $aliases = [];

        $isSimple = true;
        foreach ($data as $k => $v) {
            if (!is_array($v) || !isset($v['Alias'])) {
                $isSimple = false;
                break;
            }
        }
        if ($isSimple) {
            foreach ($data as $k => $details) {
                $alias = $this->normalizeAliasKey($k, $details);
                if ($alias === '') continue;
                $aliases[$alias] = $this->prepareAliasDetails($alias, $details);
            }
            ksort($aliases);
            return $aliases;
        }

        $groups = $data['Groups']['Group'] ?? [];
        foreach ($this->wrapList($groups) as $group) {
            if (!is_array($group)) continue;
            $items = $group['Items']['Currency'] ?? [];
            foreach ($this->wrapList($items) as $currency) {
                $attributes = $currency['@attributes'] ?? [];
                if (!is_array($attributes)) continue;
                $this->registerAliasInto($aliases, $attributes);
            }
        }

        ksort($aliases);
        return $aliases;
    }

    /**
     * Оборачивает одиночный элемент в массив для единообразной итерации
     */
    private function wrapList($value)
    {
        if (!is_array($value)) return [];
        if (array_keys($value) === range(0, count($value) - 1)) {
            return $value;
        }
        return [$value];
    }

    /**
     * Добавляет или обновляет алиас с лимитами в общий массив
     */
    private function registerAliasInto(array &$aliases, array $attributes)
    {
        $alias = strtoupper(trim((string)$attributes['Alias'] ?? ''));
        if ($alias === '') return;
        if (!isset($aliases[$alias])) {
            $aliases[$alias] = ['Alias' => $alias];
        }
        if (isset($attributes['MinValue']) && $attributes['MinValue'] !== '') {
            $aliases[$alias]['MinValue'] = (string)$attributes['MinValue'];
        }
        if (isset($attributes['MaxValue']) && $attributes['MaxValue'] !== '') {
            $aliases[$alias]['MaxValue'] = (string)$attributes['MaxValue'];
        }
    }

    /**
     * Возвращает нормализованный ключ алиаса в верхнем регистре
     */
    private function normalizeAliasKey($key, $details = null)
    {
        if (is_array($details) && isset($details['Alias'])) {
            $value = $details['Alias'];
        } else {
            $value = $key;
        }
        return strtoupper(trim((string)$value));
    }

    /**
     * Формирует массив деталей алиаса с лимитами минимальной и максимальной суммы
     */
    private function prepareAliasDetails($alias, $details)
    {
        $details = is_array($details) ? $details : [];
        $result = ['Alias' => $alias];
        if (isset($details['MinValue']) && $details['MinValue'] !== '') {
            $result['MinValue'] = (string)$details['MinValue'];
        }
        if (isset($details['MaxValue']) && $details['MaxValue'] !== '') {
            $result['MaxValue'] = (string)$details['MaxValue'];
        }
        return $result;
    }

    /**
     * Приводит строковое значение суммы к числу с плавающей точкой
     */
    private function normalizeAmountValue($val)
    {
        if (!is_string($val) && !is_numeric($val)) return 0.0;
        $normalized = preg_replace('/[^0-9.,]/', '', (string)$val);
        $normalized = str_replace(' ', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
        return (float)$normalized;
    }

    /**
     * Возвращает отображаемое название стандартного метода оплаты
     */
    private function getStandardMethodTitle()
    {
        return 'Robokassa';
    }

    /**
     * Возвращает конфигурацию дополнительных методов оплаты (рассрочка, Подели, Mokka, Яндекс Сплит)
     */
    private function getOptionalMethodsConfig(): array
    {
        if (function_exists('robokassa_get_optional_payment_methods_config')) {
            try {
                return robokassa_get_optional_payment_methods_config();
            } catch (\Throwable $e) {
                // fallback
            }
        }

        return [
            [
                'class' => 'payment_robokassa_pay_method_request_credit',
                'gateway_id' => 'robokassa_credit',
                'option' => 'robokassa_payment_method_credit_enabled',
                'alias' => 'OTP',
                'title' => 'Рассрочка или кредит',
            ],
            [
                'class' => 'payment_robokassa_pay_method_request_podeli',
                'gateway_id' => 'robokassa_podeli',
                'option' => 'robokassa_payment_method_podeli_enabled',
                'alias' => 'Podeli',
                'title' => 'Подели',
            ],
            [
                'class' => 'payment_robokassa_pay_method_request_mokka',
                'gateway_id' => 'robokassa_mokka',
                'option' => 'robokassa_payment_method_mokka_enabled',
                'alias' => 'Mokka',
                'title' => 'Mokka',
            ],
            [
                'class' => 'payment_robokassa_pay_method_request_split',
                'gateway_id' => 'robokassa_split',
                'option' => 'robokassa_payment_method_split_enabled',
                'alias' => 'YandexPaySplit',
                'title' => 'Яндекс Сплит',
            ],
        ];
    }
}
