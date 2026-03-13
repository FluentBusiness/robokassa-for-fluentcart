<?php // Управляет налоговыми ставками товаров

namespace RobokassaFluentCart\API;

if (! defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Product;


class TaxManager {

    /** @var string */
    private $metaKey = '_robokassa_tax_rate';

    /** @var array */
    private $knownTaxes = array(
        'none', 'vat0', 'vat10', 'vat20', 'vat110', 'vat118', 'vat120',
        'vat5', 'vat7', 'vat105', 'vat107', 'vat8', 'vat12'
    );

    /** @var array */
    private $taxLabels = array(
        'none'   => 'Без НДС',
        'vat0'   => 'НДС по ставке 0%',
        'vat10'  => 'НДС чека по ставке 10%',
        'vat20'  => 'НДС чека по ставке 20%',
        'vat110' => 'НДС чека по расчётной ставке 10/110',
        'vat118' => 'НДС чека по расчётной ставке 20/120',
        'vat5'   => 'НДС по ставке 5%',
        'vat7'   => 'НДС по ставке 7%',
        'vat105' => 'НДС чека по расчётной ставке 5/105',
        'vat107' => 'НДС чека по расчётной ставке 7/107',
        'vat8'   => 'НДС чека по ставке 8% (Казахстан)',
        'vat12'  => 'НДС чека по ставке 12% (Казахстан)',
    );

    /** @var array */
    private $taxRates = array(
        'none'   => 0,
        'vat0'   => 0,
        'vat5'   => 5,
        'vat7'   => 7,
        'vat8'   => 8,
        'vat10'  => 10,
        'vat12'  => 12,
        'vat20'  => 20,
        'vat105' => 5 / 105,
        'vat107' => 7 / 107,
        'vat110' => 10 / 110,
        'vat120' => 20 / 120,
    );

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
     * Возвращает нормализованное значение налоговой ставки.
     */
    public function normalizeTaxCode($tax): string
    {
        if ($tax === 'vat118') {
            return 'vat120';
        }

        if (!in_array($tax, $this->knownTaxes, true)) {
            return 'none';
        }

        return $tax;
    }

    /**
     * Подготавливает значение налоговой ставки для отображения в админке.
     */
    public function prepareTaxValueForDisplay($tax): string
    {
        if ($tax === 'vat120') {
            return 'vat118';
        }

        return $tax;
    }

    /**
     * Возвращает налоговую ставку по умолчанию.
     */
    public function getDefaultTax(): string
    {
        $tax = $this->setting('robokassa_payment_tax');

        if ($tax === false || $tax === '') {
            return 'none';
        }

        return $this->normalizeTaxCode((string) $tax);
    }

    /**
     * Возвращает список подписей ставок для админки.
     */
    public function getTaxLabels(): array
    {
        return $this->taxLabels;
    }

    /**
     * Вычисляет сумму налога для указанного чека.
     */
    public function calculateTaxSum($tax, $amount): float
    {
        $code = $this->normalizeTaxCode((string) $tax);
        $rate = isset($this->taxRates[$code]) ? (float) $this->taxRates[$code] : 0.0;

        return round(($amount * $rate) / 100, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Возвращает налог для позиции заказа FluentCart.
     */
    public function getItemTax(OrderItem $item): string
    {
        $defaultTax = $this->getDefaultTax();
        $mode       = $this->setting('robokassa_payment_tax_source') ?: 'global';

        if ($mode !== 'product') {
            return $defaultTax;
        }

        $productId = $item->post_id;
        if (!$productId) {
            return $defaultTax;
        }

        $product = Product::find($productId);
        if (!$product) {
            return $defaultTax;
        }

        $tax = $product->getProductMeta($this->metaKey, null, null);

        if ($tax === null || $tax === '') {
            return $defaultTax;
        }

        return $this->normalizeTaxCode((string) $tax);
    }

    /**
     * Сохраняет налог при обновлении товара (fluent_cart/product_updated).
     */
    public function handleProductUpdated(array $data): void
    {
        $product   = $data['product'] ?? null;
        $productId = (int) ($product->ID ?? 0);

        if (!$productId || !$product) {
            return;
        }

        $tax = $data['data']['metaValue']['robokassa_tax_form']['tax'] ?? null;

        if ($tax === null) {
            return;
        }

        $product->updateProductMeta($this->metaKey, sanitize_text_field($tax));

        $this->log("tax_meta_saved", ['product_id' => $productId, 'tax' => $tax]);
    }

    /**
     * Добавляет виджет выбора налога в карточку товара в админке.
     */
    public function addProductTaxWidget(array $widgets, array $product): array
    {
        $productId    = (int) ($product['product_id'] ?? 0);
        $productModel = Product::find($productId);

        $savedTaxValue = $this->getDefaultTax();

        if ($productModel) {
            $tax = $productModel->getProductMeta($this->metaKey, null, null);
            if ($tax !== null && $tax !== '') {
                $savedTaxValue = (string) $tax;
            }
        }

        $savedTaxValue = $this->prepareTaxValueForDisplay($savedTaxValue);

        $vueOptions = [['value' => '', 'label' => __('Использовать настройку по умолчанию', 'fluent-robokassa')]];
        foreach ($this->getTaxLabels() as $code => $label) {
            $vueOptions[] = ['value' => $code, 'label' => $label];
        }

        $widgets[] = [
            'title'     => __('Робокасса', 'fluent-robokassa'),
            'sub_title' => __('Выбор налоговой ставки', 'fluent-robokassa'),
            'type'      => 'form',
            'form_name' => 'robokassa_tax_form',
            'name'      => 'robokassa_tax',
            'schema'    => [
                'tax' => [
                    'wrapperClass' => 'col-span-2 flex items-start flex-col',
                    'type'         => 'select',
                    'label'        => __('Выбор налоговой ставки', 'fluent-robokassa'),
                    'options'      => $vueOptions,
                    'description'  => 'Выберите налоговую ставку для передачи в Робокассу. Если значение не указано, будет использована ставка из общих настроек плагина.',
                ],
            ],
            'values' => [
                'tax' => $savedTaxValue,
            ],
        ];

        return $widgets;
    }
}