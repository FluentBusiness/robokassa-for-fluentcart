<?php // Класс для обработки ответов

namespace RobokassaFluentCart\Webhook;

if (! defined( 'ABSPATH' ) ) {
    exit;
}

use FluentCart\App\Models\Order;
use FluentCart\App\Events\Order\OrderPaid;
use FluentCart\App\Events\Order\OrderStatusUpdated;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use RobokassaFluentCart\API\Util;


class RobokassaWebhookHandler
{
    protected $settings;
    protected $subscriptions;
    protected $logger;
    protected ?Order $order = null;


    public function __construct($settings = null, $subscriptions = null, $logger = null)
    {
        $this->settings = $settings;
        $this->subscriptions = $subscriptions;
        $this->logger   = is_callable($logger) ? $logger : null;
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

    /**
     * Универсальный метод получения настройки:
     */
    protected function setting($key, $default = null) {
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
     * Определяем страницу редиректа после оплаты
     */
    protected function robokassa_payment_get_success_fail_url($name, $order)
    {
        $transaction = OrderTransaction::where('order_id', $order->id)->latest()->first();
        switch ($name) {
            case 'wc_success':
                if ($transaction) {
                    return $transaction->getReceiptPageUrl();
                }
                break;
            case 'wc_checkout':
                if ($order && method_exists($order, 'getViewUrl')) {
                    return $order->getViewUrl('customer');
                }
                break;
            case 'wc_payment':
                if ($order && method_exists($order, 'getPaymentUrl')) {
                    return $order->getPaymentUrl();
                }
                break;
            default:
                if (is_numeric($name)) {
                    return get_page_link((int) $name);
                }
                return site_url('/');
        }
        return site_url('/');
    }


    /**
     * Вспомогательная функция, для быстрого доступа к паролям.
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
     * Вспомогательная функция, для нормализации статуса заказа после оплаты
     */
    protected function normalizePaidOrderStatus($rawStatus)
    {
        $allowed = [
            Status::ORDER_PROCESSING,
            Status::ORDER_COMPLETED,
        ];

        $status = strtolower((string) $rawStatus);

        // убираем wc- если вдруг прилетит
        $status = preg_replace('/^wc-/', '', $status);

        if (!in_array($status, $allowed, true)) {
            return Status::ORDER_PROCESSING;
        }

        return $status;
    }


    /*
     * Вспомогательная функция, для управляемого завершения заказа с нужным статусом
     */

    public function Robo_syncOrderStatuses(Order $order, OrderTransaction $latestTransaction = null)
    {
        $this->order = $order;

        $rawStatus = $this->setting('robokassa_payment_order_status_after_payment');
        $finalStatus = $this->normalizePaidOrderStatus($rawStatus);

        $successStatuses = Status::getTransactionSuccessStatuses();

        $transactionPaidTotal = OrderTransaction::query()
            ->where('order_id', $this->order->id)
            ->whereIn('status', $successStatuses)
            ->sum('total');

        $refundedTotal = OrderTransaction::query()
            ->where('order_id', $this->order->id)
            ->where('status', Status::TRANSACTION_REFUNDED)
            ->sum('total');

        $isFullyPaid = $this->order->total_amount <= ($transactionPaidTotal - $refundedTotal);

        $orderPaymentStatus = $this->order->payment_status;
        if ($isFullyPaid) {
            $orderPaymentStatus = Status::PAYMENT_PAID;
        } else if ($refundedTotal) {
            $orderPaymentStatus = Status::PAYMENT_PARTIALLY_REFUNDED;
        }

        $orderStatus = $this->order->status;

        if ($orderPaymentStatus === Status::PAYMENT_PAID) {
            $orderStatus = $finalStatus;
        } 

        $oldOrderStatus = $this->order->status;
        $oldPaymentStatus = $this->order->payment_status;

        $this->order->status = $orderStatus;
        $this->order->payment_status = $orderPaymentStatus;
        $this->order->total_paid = $transactionPaidTotal;
        $this->order->total_refund = $refundedTotal;
        $this->order->save();

        if (($this->order->type === 'renewal') || ($oldPaymentStatus != $this->order->payment_status && $this->order->payment_status == Status::PAYMENT_PAID)) {
            if (!$latestTransaction) {
                $latestTransaction = OrderTransaction::query()
                    ->where('order_id', $this->order->id)
                    ->orderBy('id', 'desc')
                    ->first();
            }

            $relatedCart = Cart::query()->where('order_id', $this->order->id)
                ->where('stage', '!=', 'completed')
                ->first();

            if ($relatedCart) {
                $relatedCart->stage = 'completed';
                $relatedCart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
                $relatedCart->save();

                do_action('fluent_cart/cart_completed', [
                    'cart'  => $relatedCart,
                    'order' => $this->order,
                ]);

                $onSuccessActions = Arr::get($relatedCart->checkout_data, '__on_success_actions__', []);

                if ($onSuccessActions) {
                    foreach ($onSuccessActions as $onSuccessAction) {
                        $onSuccessAction = (string)$onSuccessAction;
                        if (has_action($onSuccessAction)) {
                            do_action($onSuccessAction, [
                                'cart'        => $relatedCart,
                                'order'       => $this->order,
                                'transaction' => $latestTransaction
                            ]);
                        }
                    }
                }
            }

            (new OrderPaid($this->order, $this->order->customer, $latestTransaction))->dispatch();
        }

        if ($oldOrderStatus != $this->order->status) {
            $actionActivity = [
                'title'   => __('Order status updated', 'fluent-cart'),
                'content' => sprintf(
                    __('Order status has been updated from %1$s to %2$s', 'fluent-cart'), $oldOrderStatus, $this->order->status)
            ];
            (new OrderStatusUpdated($this->order, $oldOrderStatus, $this->order->status, true, $actionActivity, 'order_status'))->dispatch();
        }

        return $this->order;
    }


    /**
     * Функция для холдирования
     */
    protected function completeCartForHold(Order $order, OrderTransaction $transaction)
    {
        $cart = Cart::query()
            ->where('order_id', $order->id)
            ->where('stage', '!=', 'completed')
            ->first();

        if (! $cart) {
            return;
        }

        $cart->stage = 'completed';
        $cart->completed_at = DateTime::now()->format('Y-m-d H:i:s');
        $cart->save();

        do_action('fluent_cart/cart_completed', [
            'cart'  => $cart,
            'order' => $order,
        ]);
    }


    /**
     * Обработка Robokassa, в ответах возвращаем номер заказа
     */
    public function robokassa_payment_wp_robokassa_checkPayment()
    {
        if (! isset($_REQUEST['robokassa'])) {
            return;
        }

        $merchant = $this->setting('robokassa_payment_MerchantLogin');
        $passes = $this->getPasses();
        $pass1 = $passes['pass1'];
        $pass2 = $passes['pass2'];

        $outSum = isset($_REQUEST['OutSum']) ? $_REQUEST['OutSum'] : null;
        $invId = isset($_REQUEST['InvId']) ? $_REQUEST['InvId'] : null;
        $order_uuid  = isset($_REQUEST['Shp_order_id']) ? $_REQUEST['Shp_order_id'] : null;
        $transaction_uuid  = isset($_REQUEST['Shp_transaction_uuid']) ? $_REQUEST['Shp_transaction_uuid'] : null;

        $returner = '';

        if ($_REQUEST['robokassa'] === 'result') {
            $sig = isset($_REQUEST['SignatureValue']) ? (string) $_REQUEST['SignatureValue'] : '';

            $order = Order::where('uuid', $order_uuid)->first();
            $transaction = OrderTransaction::where('uuid', $transaction_uuid)->first();
            $transactionId = (int)$transaction->id ?? null;            

            if ($outSum !== null && $invId !== null && $sig !== '') {
                $outSum = str_replace(',', '.', (string)$outSum);

                $resultUrl = Util::siteUrl('/?robokassa=result');
                $parts = [
                    $outSum,
                    $invId,
                    $pass2,
                    'shp_label=official_wordpress',
                    'Shp_merchant_id=' . $merchant,
                    'Shp_order_id=' . $order_uuid,
                    'Shp_result_url=' . $resultUrl,
                    'Shp_transaction_uuid=' . $transaction_uuid,
                ];
                $crc_confirm = strtoupper(md5(implode(':', $parts)));

                if ($crc_confirm === strtoupper($sig)) {
                    if ($transaction->status === Status::TRANSACTION_SUCCEEDED) {
                        return $order;
                    }

                    $amountCents = $transaction->total;
                    if (is_numeric($outSum)) {
                        $amountCents = (int) round((float) $outSum * 100);
                    }

                    $note = 'Robokassa: Заказ успешно оплачен!';

                    $paymentMethodType = sanitize_text_field($_REQUEST['IncCurrLabel'] ?? 'robokassa');

                    $robokassaMeta['robokassa_invId'] = $invId;

                    $transaction->fill([
                        'status'              => Status::TRANSACTION_SUCCEEDED,
                        'payment_method_type' => $paymentMethodType,
                        'vendor_charge_id'    => $transaction->id,
                        'meta'                => $robokassaMeta,
                    ]);
                    $transaction->save();

                    try {
                        $this->Robo_syncOrderStatuses($order, $transaction);
                    } catch (\Throwable $e) {

                        $this->notifyRobokassaError($e, 'warning');

                        try {
                            (new StatusHelper($order))->syncOrderStatuses($transaction);
                        } catch (\Throwable $e2) {
                            $this->notifyRobokassaError($e2, 'critical');
                        }
                    }
                
                    $order->update([
                        'note' => $note,
                    ]);

                    $subscriptionModel = $transaction->subscription;
                    if (!$subscriptionModel) {
                        $subscriptionModel = Subscription::where('parent_order_id', $order->id)->first();
                    }

                    if (!$subscriptionModel) {
                        error_log('FluentCart Robokassa: No subscription found for order or transaction. Order UUID: ' . ($order_uuid ?? 'n/a'));
                    } else {
                        $isFirstPayment = empty($subscriptionModel->vendor_subscription_id) || $subscriptionModel->status === Status::SUBSCRIPTION_PENDING;

                        $subscriptionUpdateArgs = [
                            'current_payment_method' => 'robokassa',
                            'recurring_total'        => $amountCents,
                            'vendor_customer_id'     => $subscriptionModel->customer_id,
                        ];

                        if ($isFirstPayment) {
                            $subscriptionUpdateArgs['vendor_subscription_id'] = $invId;
                            $subscriptionUpdateArgs['status'] = Status::SUBSCRIPTION_ACTIVE;
                        } else {
                            $subscriptionUpdateArgs['status'] = Status::SUBSCRIPTION_ACTIVE;
                            $subscriptionUpdateArgs['next_billing_date'] = $subscriptionModel->guessNextBillingDate(true);
                        }

                        $updatedSubscription = SubscriptionService::syncSubscriptionStates(
                            $subscriptionModel,
                            $subscriptionUpdateArgs
                        );

                        if ($updatedSubscription->status === Status::SUBSCRIPTION_ACTIVE) {
                            if ($this->subscriptions && method_exists($this->subscriptions, 'scheduleNextRecurring')) {
                                $this->subscriptions->scheduleNextRecurring($updatedSubscription);
                            }
                        } else {
                            error_log('FluentCart Robokassa: Subscription ' . $subscriptionModel->id . ' status after sync: ' . $updatedSubscription->status);
                        }
                    }

                    $returner = 'OK' . $_REQUEST['InvId'];

                    try {
                        if ($this->setting('robokassa_payment_sms1_enabled') === 'yes') {
                            $order_id = (int)$transaction->order_id;

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

                            global $wpdb;
                            $dbWrapper = new \RobokassaFluentCart\API\RoboDataBase($wpdb);

                            $api = new \RobokassaFluentCart\API\RobokassaPayAPI($merchant, $pass1, $pass2, 'md5', $this->settings);

                            $sms = new \RobokassaFluentCart\API\RobokassaSms(
                                $dbWrapper,
                                $api,
                                $phone,
                                $this->setting('robokassa_payment_sms1_text', ''),
                                ($this->setting('robokassa_payment_sms_translit') === 'yes'),
                                $order_id,
                                1
                            );

                            $sms->send();
                        }
                    } catch (\Throwable $e) {
                        $this->log('sms1_send_failed', ['error' => $e->getMessage()]);
                    }

                } else {
                    $this->log('crc_mismatch', ['computed' => $crc_confirm ?? null, 'received' => $sig ?? null]);
                }
            }

            if ($returner === '') {
                $holdOn = (int) $this->setting('robokassa_payment_hold_onoff', 0) === 1;
                if ($holdOn) {

                    $rawInput = @file_get_contents('php://input');
                    if (is_string($rawInput) && trim($rawInput) !== '') {
                        $tokenParts = explode('.', trim($rawInput));
                        if (count($tokenParts) === 3) {
                            $b64urlDecode = function($input) {
                                $input = strtr($input, '-_', '+/');
                                $pad = strlen($input) % 4;
                                if ($pad) { $input .= str_repeat('=', 4 - $pad); }
                                return base64_decode($input);
                            };

                            $payloadRaw = $b64urlDecode($tokenParts[1]);
                            $payload = json_decode($payloadRaw, true);

                            if (is_array($payload) && !empty($payload['data']['state'])) {
                                
                                $state = strtoupper((string)$payload['data']['state']);
                                $invFromPayload = $payload['data']['invId'] ?? null;

                                if ($invFromPayload && is_numeric($invFromPayload)) {
                                    $invNumeric = (int)$invFromPayload;

                                    $transaction = OrderTransaction::whereRaw(
                                        "JSON_EXTRACT(meta, '$.robokassa_invId') = ?",
                                        [$invNumeric]
                                    )->first();

                                    $amountCents = $transaction->total;
                                    if (is_numeric($outSum)) {
                                        $amountCents = (int) round((float) $outSum * 100);
                                    }

                                    $orderId = $transaction->order_id;
                                    $order = $transaction->order;

                                    $order = ($transaction && ! empty($transaction->order_id)) ? Order::query()->find((int)$transaction->order_id) : null;
                                    if (!$order) {
                                        $order = Order::query()->find($invNumeric);
                                    }
                                    if (!$order) {
                                        http_response_code(400);
                                        return;
                                    }

                                    if ($state === 'HOLD') {
                                        $date_in_five_days = wp_date('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS);

                                        $robokassaMeta['robokassa_invId'] = $invNumeric;

                                        $transaction->fill([
                                            'status'              => Status::TRANSACTION_SUCCEEDED,
                                            'payment_method_type' => 'robokassa',
                                            'vendor_charge_id'    => $transaction->id,
                                            'meta'                => $robokassaMeta,
                                        ]);
                                        $transaction->save();

                                        $order->updateMeta('_fluentcart_status_before_cancel', 'on-hold');

                                        $order->update([
                                            'note' => 'Robokassa: Платеж успешно подтвержден. Он ожидает подтверждения до '. $date_in_five_days .', после чего автоматически отменится',
                                            'status' => Status::ORDER_ON_HOLD
                                        ]);

                                        $this->completeCartForHold($order, $transaction);

                                        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
                                            $hook = 'robokassa_cancel_payment_event';
                                            if (! wp_next_scheduled($hook, [ (int)$order->id ])) {
                                                wp_schedule_single_event(strtotime('+5 days'), $hook, [ (int)$order->id ]);
                                            }
                                        }

                                        http_response_code(200);
                                        return;
                                    }

                                    if ($state === 'OK') {
                                        $order->update([
                                            'note' => 'Robokassa: Платеж успешно подтвержден',
                                            'payment_status' => Status::PAYMENT_PAID,
                                        ]);

                                        $this->Robo_syncOrderStatuses($order, $transaction);

                                        $subscriptionModel = $transaction->subscription;
                                        if (!$subscriptionModel) {
                                            $subscriptionModel = Subscription::where('parent_order_id', $order->id)->first();
                                        }

                                        if (!$subscriptionModel) {
                                            error_log('FluentCart Robokassa: No subscription found for order or transaction. Order UUID: ' . ($order_uuid ?? 'n/a'));
                                        } else {
                                            $isFirstPayment = empty($subscriptionModel->vendor_subscription_id) || $subscriptionModel->status === Status::SUBSCRIPTION_PENDING;

                                            $subscriptionUpdateArgs = [
                                                'current_payment_method' => 'robokassa',
                                                'recurring_total'        => $amountCents,
                                                'vendor_customer_id'     => $subscriptionModel->customer_id,
                                            ];

                                            if ($isFirstPayment) {
                                                $subscriptionUpdateArgs['vendor_subscription_id'] = $invNumeric;
                                                $subscriptionUpdateArgs['status'] = Status::SUBSCRIPTION_ACTIVE;
                                            } else {
                                                $subscriptionUpdateArgs['status'] = Status::SUBSCRIPTION_ACTIVE;
                                                $subscriptionUpdateArgs['next_billing_date'] = $subscriptionModel->guessNextBillingDate(true);
                                            }

                                            $updatedSubscription = SubscriptionService::syncSubscriptionStates(
                                                $subscriptionModel,
                                                $subscriptionUpdateArgs
                                            );

                                            if ($updatedSubscription->status === Status::SUBSCRIPTION_ACTIVE) {
                                                if ($this->subscriptions && method_exists($this->subscriptions, 'scheduleNextRecurring')) {
                                                    $this->subscriptions->scheduleNextRecurring($updatedSubscription);
                                                }
                                            } else {
                                                error_log('FluentCart Robokassa: Subscription ' . $subscriptionModel->id . ' status after sync: ' . $updatedSubscription->status);
                                            }
                                        }

                                        http_response_code(200);
                                        return;
                                    }
                                }
                            }
                        }
                    }
                    $returner = 'BAD SIGN';
                }
            }

            
        } 

        if (isset($_REQUEST['robokassa']) && $_REQUEST['robokassa'] == 'success') {
            if (wp_doing_ajax()) {
                return;
            }

            $order = Order::where('uuid', $order_uuid)->first();
            header('Location: ' . $this->robokassa_payment_get_success_fail_url($this->setting('robokassa_payment_SuccessURL'), $order));
            die;
        }

        if (isset($_REQUEST['robokassa']) && $_REQUEST['robokassa'] == 'fail') {
            $order = Order::where('uuid', $order_uuid)->first();
            header('Location: ' . $this->robokassa_payment_get_success_fail_url($this->setting('robokassa_payment_FailURL'), $order));
            die;
        }

        echo $returner;
        die;
    }


}