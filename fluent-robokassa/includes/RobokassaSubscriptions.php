<?php // Подписки (реккурентные платежи)

namespace RobokassaFluentCart;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use RobokassaFluentCart\API\RobokassaPayAPI;


class RobokassaSubscriptions extends AbstractSubscriptionModule
{
    protected $settings;

    public function __construct($settings = null)
    {
        $this->settings = $settings;
    }

    /**
     * Получить настройку
     */
    protected function setting($key, $default = null) {
        if (is_object($this->settings) && method_exists($this->settings, 'get')) {
            try {
                return $this->settings->get($key, $default);
            } catch (\Throwable $e) {
                error_log('[robokassa] setting error: ' . $e->getMessage());
            }
        }
        return $default;
    }

    /**
     * Получить пароли Robokassa
     */
    protected function getPasses(): array
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
     * Запланировать следующий повторный платёж для подписки.
     * Вызывается из вебхука после успешного платежа.
     */
    public function scheduleNextRecurring(Subscription $subscription)
    {
        if (!$subscription || $subscription->status !== Status::SUBSCRIPTION_ACTIVE) {
            return;
        }

        $nextBilling = $subscription->next_billing_date;
        if (!$nextBilling) {
            error_log("[robokassa] scheduleNextRecurring: no next_billing_date for sub #{$subscription->id}");
            return;
        }

        $timestamp = strtotime($nextBilling);
        if ($timestamp && $timestamp > time()) {
            wp_clear_scheduled_hook('robokassa_recurring_payment', [$subscription->id]);
            wp_schedule_single_event($timestamp, 'robokassa_recurring_payment', [$subscription->id]);
            error_log("[robokassa] Scheduled recurring payment for subscription {$subscription->id} at {$nextBilling}");
        } else {
            error_log("[robokassa] scheduleNextRecurring: timestamp in past or invalid for sub #{$subscription->id}");
        }
    }

    /**
     * Обработчик крона для выполнения повторного платежа.
     * @param int $subscriptionId
     */
    public function processRecurringPayment($subscriptionId)
    {
        error_log("[robokassa] processRecurringPayment started for subscription #{$subscriptionId}");

        // 1. Получаем подписку
        $subscription = Subscription::find($subscriptionId);
        if (!$subscription || $subscription->current_payment_method !== 'robokassa') {
            error_log("[robokassa] Subscription not found or not robokassa");
            return;
        }

        // 2. Проверяем, активна ли подписка
        if ($subscription->status !== Status::SUBSCRIPTION_ACTIVE) {
            error_log("[robokassa] Subscription not active, skipping");
            return;
        }

        // 3. Получаем настройки Robokassa
        $merchant = $this->setting('robokassa_payment_MerchantLogin');
        error_log("[robokassa] merchant = " . $merchant);
        $passes = $this->getPasses();
        error_log("[robokassa] passes = " . print_r($passes, true));
        if (!$merchant || empty($passes['pass1'])) {
            error_log("[robokassa] Missing credentials");
            return;
        }

        // 4. Получаем предыдущий InvoiceID (vendor_subscription_id)
        $previousInvId = $subscription->vendor_subscription_id;
        error_log("[robokassa] previousInvId = " . $previousInvId);
        if (!$previousInvId) {
            error_log("[robokassa] No previous InvId found");
            return;
        }

        // 5. Генерируем новый InvId для этого платежа
        $newInvId = abs(crc32(wp_generate_uuid4()));

        // 6. Подготавливаем данные для транзакции (pending)
        $transactionData = [
            'payment_method'    => 'robokassa',
            'total'             => $subscription->recurring_total,
            'currency'          => $subscription->currency,
            'status'            => Status::TRANSACTION_PENDING,
            'vendor_charge_id'  => '',
            'meta'              => [
                'robokassa_invId' => $newInvId,
                'previous_invId'  => $previousInvId,
            ],
        ];

        // 7. Используем стандартный метод FluentCart для создания renewal-заказа и транзакции
        $transaction = SubscriptionService::recordRenewalPayment($transactionData, $subscription);
        if (is_wp_error($transaction)) {
            error_log("[robokassa] Failed to create renewal order: " . $transaction->get_error_message());
            return;
        }

        // 8. Получаем созданный заказ (для формирования чека)
        $renewalOrder = $transaction->order;
        if (!$renewalOrder) {
            error_log("[robokassa] Renewal order not found");
            return;
        }

        // 9. Формируем чек на основе позиций заказа
        $receipt = $this->buildReceiptFromOrder($renewalOrder, $subscription);

        // 10. Получаем данные для recurring-запроса
        $api = new RobokassaPayAPI($merchant, $passes['pass1'], $passes['pass2'], 'md5', $this->settings);
        $amount = number_format($subscription->recurring_total / 100, 2, '.', '');


        $recurringData = $api->getRecurringPaymentData(
            $newInvId,
            $previousInvId,
            $amount,
            $renewalOrder->uuid,        // UUID заказа
            $transaction->uuid,         // UUID транзакции
            $receipt,
            'Оплата подписки #' . $subscription->id
        );

        if ($this->setting('robokassa_payment_test_onoff') === 'yes') {
            $recurringData['IsTest'] = 1;
        }

        // 11. Отправляем запрос к Robokassa (без проверки ответа – как в оригинале)
        $response = wp_remote_post('https://auth.robokassa.ru/Merchant/Recurring', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'    => http_build_query($recurringData),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log("[robokassa] Recurring request failed: " . $response->get_error_message());
            return;
        }
    }


    /**
     * Строит чек для Robokassa на основе заказа и подписки.
     * (Адаптация вашей функции createRobokassaReceipt под модель Order)
     */
    protected function buildReceiptFromOrder($order, $subscription)
    {
        $receipt = [];
        $sno = $this->setting('robokassa_payment_sno');
        if ($sno != 'fckoff') {
            $receipt['sno'] = $sno;
        }

        foreach ($order->order_items as $item) {
            $current = [
                'name'     => $item->post_title,
                'quantity' => $item->quantity,
                'sum'      => number_format($item->line_total / 100, 2, '.', ''),
                'cost'     => number_format($item->unit_price / 100, 2, '.', ''),
                'payment_object' => $this->setting('robokassa_payment_paymentObject'),
                'payment_method' => $this->setting('robokassa_payment_paymentMethod'),
                'tax'      => $this->setting('robokassa_payment_tax', 'none'),
            ];
            $receipt['items'][] = $current;
        }

        // Если есть доставка
        if ($order->shipping_total > 0) {
            $shipping = [
                'name'     => 'Доставка',
                'quantity' => 1,
                'sum'      => number_format($order->shipping_total / 100, 2, '.', ''),
                'cost'     => number_format($order->shipping_total / 100, 2, '.', ''),
                'payment_object' => $this->setting('robokassa_payment_paymentObject_shipping') ?: $this->setting('robokassa_payment_paymentObject'),
                'payment_method' => $this->setting('robokassa_payment_paymentMethod'),
                'tax'      => $this->setting('robokassa_payment_tax', 'none'),
            ];
            $receipt['items'][] = $shipping;
        }

        return $receipt;
    }


    /**
     * Удалить запланированные платежи (при отмене/паузе)
     */
    public function clearScheduledRecurring($subscriptionId)
    {
        wp_clear_scheduled_hook('robokassa_recurring_payment', [$subscriptionId]);
    }


    /**
     * Приостановка подписки – очищаем крон.
     */
    public function pauseSubscription($data, $order, $subscription)
    {
        $this->clearScheduledRecurring($subscription->id);
        return parent::pauseSubscription($data, $order, $subscription);
    }


    /**
     * Отмена подписки – очищаем крон.
     */
    public function cancel($vendorSubscriptionId, $args = [])
    {
        if (!$vendorSubscriptionId) {
            return new \WP_Error('invalid_subscription', __('Invalid vendor subscription ID.', 'fluent-cart'));
        }
        $subscription = Subscription::where('vendor_subscription_id', $vendorSubscriptionId)->first();
        if ($subscription) {
            $this->clearScheduledRecurring($subscription->id);
        }
        return [
            'status' => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => current_time('mysql')
        ];
    }


    /**
     * Ручная синхронизация из админки – только логируем.
     */
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        return $subscriptionModel;
    }
}