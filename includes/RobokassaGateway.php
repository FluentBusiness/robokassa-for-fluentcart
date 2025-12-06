<?php
/**
 * Robokassa Gateway Class
 */

namespace RobokassaFluentCart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use RobokassaFluentCart\Settings\RobokassaSettingsBase;

class RobokassaGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'robokassa';
    // slug, который зарегистрирован в FluentCart (и который должен приходить в install-addon)

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
     * Конструктор: передаём объект настроек (RobokassaSettingsBase реализует fields() и т.д.)
     */
    public function __construct()
    {
        parent::__construct(
            new RobokassaSettingsBase(),
            //new PaddleSubscriptions()
        );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ], 20 );
    }

    /**
     * Метаданные шлюза
     *
     * @return array
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
     * Подключение CSS/JS для страницы настроек в админке /wp-admin/admin.php?page=fluent-cart#/settings/payments/robokassa
     */

    public function enqueue_admin_assets() {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // серверно мы видим только page=fluent-cart (SPA hash не доступен серверу)
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

        // Опционально: минимальный inline, чтобы скрипт выполнял инициализацию только на нужном SPA-хэше
        $inline = "if (location.hash && location.hash.indexOf('/settings/payments/robokassa') !== -1) { if (typeof window.RobokassaInit === 'function') { window.RobokassaInit(); } }";
        wp_add_inline_script( 'robokassa-admin-script', $inline );
    }

    /**
     * Подключение CSS/JS на фронтенде
     */

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }



    /**
     * Регистрируем webhook, фильтры и т.д.
     */
    public function boot()
    {
        add_filter( 'fluent_cart/payment_methods/' . $this->methodSlug . '_settings', [ $this, 'getSettings' ], 10, 2 );

    }


    /**
     * Необходимая функция
     */
    public function has(string $feature): bool
    {
        return in_array($feature, $this->supportedFeatures);
    }

    /**
     * Необходимая функция. Процесс оплаты
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        
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
        // Prepare frontend data for checkout
        $paymentArgs = [];
        
        // Return data for frontend
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
        $mode = Arr::get($data, 'payment_mode', 'live');

        if ($mode == 'test') {
            //$data['test_secret_key'] = Helper::encryptKey($data['test_secret_key']);
        } else {
            //$data['live_secret_key'] = Helper::encryptKey($data['live_secret_key']);
        }

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
    
}