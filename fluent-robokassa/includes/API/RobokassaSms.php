<?php // Создание СМС уведомлений

namespace RobokassaFluentCart\API;

if (! defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Models\Order;


class RobokassaSms {

    /** @var RobokassaPayAPI */
    private $robokassa;

    /** @var RoboDataBase */
    private $dataBase;

    private $phone;
    private $message;
    private $translit;
    private $order_id;
    private $type;

    public function __construct(
        RoboDataBase    $dataBase,
        RobokassaPayAPI $roboKassa,
                        $phone,
                        $message,
                        $translit,
                        $order_id,
                        $type
    ) {
        $this->robokassa = $roboKassa;
        $this->dataBase = $dataBase;
        $this->phone = preg_replace('/[^0-9]/', '', (string)$phone);
        $this->message = (string)$message;
        $this->translit = (bool)$translit;
        $this->order_id = (int)$order_id;
        $this->type = (int)$type;
    }


    /**
     * Возвращает префикс таблиц в базе данных
     *
     * @return string
     * @throws \Exception
     */
    private function getDbPrefix(): string
    {
        global $wpdb;

        if ($wpdb instanceof \wpdb) {
            return $wpdb->prefix;
        }

        throw new \Exception('Объект типа "wpdb" не найден в глобальном пространстве имен по имени "$wpdb"');
    }


    /**
     * Сохранение результата отправки в таблицу
     *
     * @param string|array $log
     * @return void
     */
    public function recordLog($log) {
        $smsReply = null;
        if (is_string($log)) {
            $smsReply = json_decode($log, true);
        } elseif (is_array($log)) {
            $smsReply = $log;
        }

        if (empty($smsReply) || ! isset($smsReply['reply'])) {
            return;
        }

        $replyRaw = $smsReply['reply'];
        $reply = is_string($replyRaw) ? @json_decode(stripslashes($replyRaw), true) : $replyRaw;
        $status = (!empty($reply) && isset($reply['result']) && ($reply['result'] == true || $reply['result'] == 1)) ? '1' : '0';

        $table = $this->dataBase->getPrefix() . 'sms_stats';

        try {
            $this->dataBase->update(
                $table,
                array(
                    'send_time' => current_time('mysql'),
                    'status' => $status,
                    'response' => $this->robokassa->getRequest(),
                    'reply' => $this->robokassa->getReply(),
                ),
                array(
                    'order_id' => $this->order_id,
                    'type' => $this->type,
                ),
                array('%s', '%s', '%s', '%s'),
                array('%d', '%d')
            );
        } catch (\Throwable $e) {
            error_log('robokassa-sms: recordLog db update error: ' . $e->getMessage());
        }
    }

    /**
     * Проверяем существует ли уже запись об SMS для данного заказа/типа.
     * Возвращает true — если уже есть запись (не отправляем),
     * false — если записи нет (нужно отправить).
     *
     * @return bool
     * @throws \Exception
     */
    private function checkIsSended() {
        $dbPrefix = $this->getDbPrefix();
        $table = $dbPrefix . 'sms_stats';
        $countSql = "SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND type = %d";
        $messagesCount = (int)$this->dataBase->getVar($countSql, array($this->order_id, $this->type));

        // Если запись уже есть — считаем, что SMS уже планировалось/отправлялось
        if ($messagesCount >= 1) {
            return true;
        }

        // Иначе вставляем запись «-1» (пометка, что SMS ожидает отправки)
        try {
            $this->dataBase->insert(
                $table,
                array(
                    'order_id' => $this->order_id,
                    'type' => $this->type,
                    'status' => '-1',
                    'number' => $this->phone,
                    'text' => $this->message,
                    'send_time' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s')
            );
        } catch (\Throwable $e) {
            // если вставка упала — логируем, но разрешаем попытаться отправить (чтобы не терять SMS)
            error_log('robokassa-sms: insert error: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Подставляет переменные в шаблон сообщения
     *
     * @return string
     */
    private function filterSms()
    {
        $orderId = $this->order_id;

        // Загружаем заказ
        $order = Order::find($orderId);
        if (! $order) {
            return $this->message;
        }

        // Получаем ФИО
        $orderFio = '';
        if (! empty($order->customer['full_name'])) {
            $orderFio = trim((string)$order->customer['full_name']);
        } elseif (! empty($order->billing_first_name) || ! empty($order->billing_last_name)) {
            $orderFio = trim(($order->billing_first_name ?? '') . ' ' . ($order->billing_last_name ?? ''));
        }

        // Получаем адрес
        $orderAddress = '';
        $source = '';

        if (! empty($order->order_addresses) && is_iterable($order->order_addresses)) {
            foreach ($order->order_addresses as $addr) {
                $type = is_array($addr) ? ($addr['type'] ?? '') : ($addr->type ?? '');
                if ($type === 'billing') {
                    if (!empty($addr->formatted_address)) {
                        $fa = is_array($addr->formatted_address) ? $addr->formatted_address : (array)$addr->formatted_address;
                        // удаляем поля с именем
                        unset($fa['first_name'], $fa['last_name'], $fa['name'], $fa['country'], $fa['type'], $fa['full_name'], $fa['email']);
                        $orderAddress = trim(implode(', ', array_filter($fa)));
                    } else {
                        $orderAddress = trim(implode(', ', array_filter([
                            is_array($addr) ? ($addr['postcode'] ?? '') : ($addr->postcode ?? ''),
                            is_array($addr) ? ($addr['state'] ?? '') : ($addr->state ?? ''),
                            is_array($addr) ? ($addr['address_1'] ?? '') : ($addr->address_1 ?? ''),
                            is_array($addr) ? ($addr['address_2'] ?? '') : ($addr->address_2 ?? ''),
                            is_array($addr) ? ($addr['city'] ?? '') : ($addr->city ?? ''),
                            //is_array($addr) ? ($addr['country'] ?? '') : ($addr->country ?? ''),
                        ])));
                    }
                    if ($orderAddress) {
                        $source = 'order->order_addresses';
                        break;
                    }
                }
            }
        }

        // SMS-friendly обрезка
        $smsAddress = mb_strlen($orderAddress, 'UTF-8') > 40 
            ? mb_strimwidth($orderAddress, 0, 40, '...') 
            : $orderAddress;
        $smsFio = mb_strlen($orderFio, 'UTF-8') > 30 
            ? mb_strimwidth($orderFio, 0, 30, '...') 
            : $orderFio;

        // Подстановка в шаблон
        $mask1 = ['{address}', '{fio}', '{order_number}'];
        $mask2 = [$smsAddress, $smsFio, (string)$orderId];
        $final = str_replace($mask1, $mask2, $this->message);

        //error_log("[Robokassa SMS] Final message: {$final}");

        return $final;
    }


    /**
     * Транслитерация, если включена
     *
     * @param string $text
     * @return string
     */
    private function transliterate($text) {
        if (!$this->translit) {
            return $text;
        }

        // простая таблица транслитерации (можно вынести/оптимизировать)
        $from = array('А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я','а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я');
        $to   = array('A','B','V','G','D','E','E','Gh','Z','I','Y','K','L','M','N','O','P','R','S','T','U','F','H','C','Ch','Sh','Sch','','Y','','E','Yu','Ya','a','b','v','g','d','e','e','gh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','h','c','ch','sh','sch','','y','','e','yu','ya');

        return str_replace($from, $to, $text);
    }

    /**
     * Отправка SMS
     *
     * @return void
     * @throws \Exception
     */
    public function send() {
        // Подготовка сообщения
        $this->message = $this->filterSms();
        $this->message = $this->transliterate($this->message);

        try {
            // Если уже есть запись — не отправляем (избегаем дублей)
            /*
            if ($this->checkIsSended()) {
                // лог — уже отправлено/записано
                return;
            }
                */

            // Выполняем отправку через Robokassa API-объект
            $result = false;
            try {
                $result = $this->robokassa->sendSms($this->phone, $this->message);
            } catch (\Throwable $e) {
                error_log('robokassa-sms: sendSms threw: ' . $e->getMessage());
            }

            // Записываем лог результата (даже если false)
            //$this->recordLog($this->robokassa->getSendResult());
        } catch (\Throwable $e) {
            error_log('robokassa-sms: top-level send error: ' . $e->getMessage());
        }
    }
}
