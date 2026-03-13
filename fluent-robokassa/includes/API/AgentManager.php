<?php
// Управляет полями агентского признака товаров Robokassa.

namespace RobokassaFluentCart\API;

if (! defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;


class AgentManager {

    /** @var array */
    private $metaKeys = array(
        'type'   => '_robokassa_agent_type',
        'name'   => '_robokassa_agent_supplier_name',
        'inn'    => '_robokassa_agent_supplier_inn',
        'phones' => '_robokassa_agent_supplier_phones',
    );

    /** @var array */
    private $agentTypes = array(
        ''                     => 'Не указан',
        'bank_paying_agent'    => 'Банковский платежный агент',
        'bank_paying_subagent' => 'Банковский платежный субагент',
        'paying_agent'         => 'Платежный агент',
        'paying_subagent'      => 'Платежный субагент',
        'attorney'             => 'Поверенный',
        'commission_agent'     => 'Комиссионер',
        'another'              => 'Другой тип агента',
    );

    /** @var string */
    private $optionKey = 'robokassa_payment_agent_fields_enabled';

    protected $settings;
    protected $logger;

    public function __construct($settings = null, $logger = null)
    {
        $this->settings = $settings;
        $this->logger   = is_callable($logger) ? $logger : null;
    }

    /**
     * Записывает отладочное сообщение в лог.
     */
    protected function log($msg, $data = [])
    {
        if (is_callable($this->logger)) {
            try {
                call_user_func($this->logger, $msg, $data);
                return;
            } catch (\Throwable $e) {
                // fallback
            }
        }
        error_log('robokassa: ' . $msg . ' - ' . json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Универсальный метод получения настройки.
     */
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
     * Проверяет, включена ли поддержка агентских товаров.
     */
    private function isEnabled(): bool
    {
        return $this->setting($this->optionKey, 'no') === 'yes';
    }

    /**
     * Возвращает код страны магазина.
     */
    private function getStoreCountryCode(): string
    {
        return (string) $this->setting('robokassa_country_code', 'RU');
    }

    /**
     * Добавляет виджет агентских полей в карточку товара в админке.
     */
    public function addProductAgentWidget(array $widgets, array $product): array
    {
        if (!$this->isEnabled() || $this->getStoreCountryCode() === 'KZ') {
            return $widgets;
        }

        $productId    = (int) ($product['product_id'] ?? 0);
        $productModel = Product::find($productId);
        $values       = $this->getMetaValues($productModel);

        $typeOptions = [];
        foreach ($this->agentTypes as $code => $label) {
            $typeOptions[] = ['value' => $code, 'label' => $label];
        }

        $widgets[] = [
            'title'     => __('Робокасса', 'fluent-robokassa'),
            'sub_title' => __('Агентский признак', 'fluent-robokassa'),
            'type'      => 'form',
            'form_name' => 'robokassa_agent_form',
            'name'      => 'robokassa_agent',
            'schema'    => [
                'type' => [
                    'wrapperClass' => 'col-span-2 flex items-start flex-col',
                    'type'         => 'select',
                    'label'        => __('Тип агента', 'fluent-robokassa'),
                    'options'      => $typeOptions,
                    'description'  => __('Выберите признак агента для передачи в Робокассу.', 'fluent-robokassa'),
                ],
                'name' => [
                    'wrapperClass' => 'col-span-2 flex items-start flex-col',
                    'type'         => 'text',
                    'label'        => __('Наименование поставщика', 'fluent-robokassa'),
                ],
                'inn' => [
                    'wrapperClass' => 'col-span-2 flex items-start flex-col',
                    'type'         => 'text',
                    'label'        => __('ИНН поставщика', 'fluent-robokassa'),
                ],
                'phones' => [
                    'wrapperClass' => 'col-span-2 flex items-start flex-col',
                    'type'         => 'text',
                    'label'        => __('Телефон поставщика', 'fluent-robokassa'),
                    'description'  => __('Перечислите телефоны через запятую.', 'fluent-robokassa'),
                ],
            ],
            'values' => [
                'type'   => $values['type'],
                'name'   => $values['name'],
                'inn'    => $values['inn'],
                'phones' => $values['phones'],
            ],
        ];

        return $widgets;
    }

    /**
     * Сохраняет агентские поля при обновлении товара (fluent_cart/product_updated).
     */
    public function handleProductUpdated(array $data): void
    {
        $product   = $data['product'] ?? null;
        $productId = (int) ($product->ID ?? 0);

        if (!$productId || !$product) {
            return;
        }

        $formData = $data['data']['metaValue']['robokassa_agent_form'] ?? null;

        if ($formData === null) {
            return;
        }

        foreach (['type', 'name', 'inn', 'phones'] as $field) {
            $value = isset($formData[$field]) ? sanitize_text_field($formData[$field]) : '';
            $product->updateProductMeta($this->metaKeys[$field], $value);
        }

        $this->log('agent_meta_saved', ['product_id' => $productId, 'data' => $formData]);
    }

    /**
     * Возвращает данные агентского признака для позиции заказа FluentCart.
     */
    public function getItemAgentData(OrderItem $item): array
    {
        if (!$this->isEnabled()) {
            return array();
        }

        $productId = $item->post_id;
        if (!$productId) {
            return array();
        }

        return $this->buildAgentPayloadById((int) $productId);
    }

    /**
     * Формирует данные агентского признака по ID товара.
     */
    public function buildAgentPayloadById(int $productId): array
    {
        if (!$this->isEnabled()) {
            return array();
        }

        $product = Product::find($productId);
        $values  = $this->getMetaValues($product);
        $phones  = $this->preparePhones($values['phones']);

        if (!$this->isPayloadComplete($values['type'], $values['name'], $values['inn'], $phones)) {
            return array();
        }

        return array(
            'agent_info'    => array('type' => $values['type']),
            'supplier_info' => array(
                'name'   => $values['name'],
                'inn'    => $values['inn'],
                'phones' => $phones,
            ),
        );
    }

    // -------------------------------------------------------------------------
    // Приватные вспомогательные методы
    // -------------------------------------------------------------------------

    /**
     * Возвращает агентские мета-значения через Product модель.
     */
    private function getMetaValues(?Product $product): array
    {
        $result = ['type' => '', 'name' => '', 'inn' => '', 'phones' => ''];

        if (!$product) {
            return $result;
        }

        foreach ($this->metaKeys as $field => $metaKey) {
            $result[$field] = trim((string) $product->getProductMeta($metaKey, null, ''));
        }

        return $result;
    }

    /**
     * Преобразует текст телефонов в массив.
     */
    private function preparePhones(string $value): array
    {
        if ($value === '') {
            return array();
        }

        $rawPhones = preg_split('/[,;\r\n]+/', $value);

        if (!is_array($rawPhones)) {
            return array();
        }

        $phones = array();
        foreach ($rawPhones as $phone) {
            $clean = trim($phone);
            if ($clean !== '') {
                $phones[] = $clean;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * Проверяет заполненность обязательных полей агентского признака.
     */
    private function isPayloadComplete(string $type, string $name, string $inn, array $phones): bool
    {
        if ($type === '' || !isset($this->agentTypes[$type])) {
            return false;
        }

        return $name !== '' && $inn !== '' && !empty($phones);
    }
}