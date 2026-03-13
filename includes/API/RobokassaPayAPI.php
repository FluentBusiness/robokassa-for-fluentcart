<?php // Ядро создания запросов к API

namespace RobokassaFluentCart\API;

if (!defined('ABSPATH')) {
    exit;
}

use RobokassaFluentCart\API\RobokassaHelper;
use RobokassaFluentCart\API\Util;
use WP_REST_Request;
use WP_REST_Response;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;


class RobokassaPayAPI {

	/**
	 * @var string
	 */
	private $mrh_login;

	/**
	 * @var string
	 */
	private $mrh_pass1;

	/**
	 * @var string
	 */
	private $mrh_pass2;

	/**
	 * @var string
	 */
	private $method;

	/**
	 * @var string
	 */
	private $apiUrl;

	/**
	 * @var string
	 */
	private $reply = '';

	/**
	 * @var string
	 */
	private $request = '';


    /**
     * @var mixed|null FluentCart settings object or null
     */
    private $settings;

	/**
	 * @return string
	 */
	public function getReply() {
		return $this->reply;
	}

	/**
	 * @return string
	 */
	public function getRequest() {
		return $this->request;
	}

	/**
	 * @return string
	 */
	public function getSendResult() {
		return json_encode(array(
			'request' => $this->request,
			'reply' => $this->reply,
		));
	}

	/**
	 * @param string $login
	 * @param string $pass1
	 * @param string $pass2
	 * @param string $method
	 */
	public function __construct($login, $pass1, $pass2, $method = 'md5', $settings = null) {
		$this->mrh_login = $login;
		$this->mrh_pass1 = $pass1;
		$this->mrh_pass2 = $pass2;
		$this->method = $method;

        $this->settings = $settings ?: new RobokassaSettingsBase();

		$this->apiUrl = substr($_SERVER['SERVER_PROTOCOL'], 0, -4).'://auth.robokassa.ru/Merchant/WebService/Service.asmx/';
	}


    /**
     * Универсальный метод получения настройки
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
	 * @param string $mthd
	 * @param array  $data
	 *
	 * @return array
	 */
	private function sendRequest($mthd, $data) {
		return json_decode($this->parseXmlAndConvertToJson($this->apiUrl.$mthd.'?'.http_build_query($data)), true);
	}

	/**
	 * Если $receiptJson пустой (то есть имеет значение "[]") - то в формировании сигнатуры
	 * он не использоваться, а если не пустой - используем его json-представление
	 */
	private function getSignatureString($sum, $invId, $order_uuid, $transaction_uuid, $receiptJson, $recurring = false)
	{
		$outCurrency = $this->setting('robokassa_out_currency');
		$holdPaymentParam = ($this->setting('robokassa_payment_hold_onoff') == '1') ? 'true' : '';

		return \implode(
			':',
			\array_diff(
				array(
					$this->mrh_login,
					$sum,
					$invId,
					$outCurrency,
					$receiptJson,
					$holdPaymentParam,
					urlencode((Util::siteUrl('/?robokassa=result'))),
					$this->mrh_pass1,
					'shp_label=official_wordpress',
					'Shp_merchant_id=' . $this->mrh_login,
					'Shp_order_id=' . $order_uuid,
					'Shp_result_url=' . (Util::siteUrl('/?robokassa=result')),
                    'Shp_transaction_uuid=' . $transaction_uuid,
				),
				array(
					false,
					'',
					null
				)
			)
		);
	}

	/**
	 * Генерирует хеш для строки $string с помощью метода $method
	 *
	 * @param string $string
	 * @param string $method
	 *
	 * @return string
	 *
	 * @throws \Exception
	 */
	public function getSignature($string, $method = 'md5') {
		if (in_array($method, array('md5', 'ripemd160', 'sha1', 'sha256', 'sha384', 'sha512'))) {
			return strtoupper(hash($method, $string));
		}

		throw new \Exception('Wrong Signature Method');
	}

	/**
	 *
	 *
	 * @param float $sum
	 * @param int $invId
	 * @param string $invDesc
	 * @param string $test
	 * @param string $incCurrLabel
	 * @param array $receipt
	 *
	 * @param null $email
	 * @return string
	 *
	 * @throws \Exception
	 */
    public function createForm(
        $sum,
        $invId,
        $invDesc,
        $order_uuid,
        $transaction_uuid,
        $test = 'false',
        $incCurrLabel = 'all',
        $receipt = null,
        $email = null,
        $recurring = false
    ) {
        $kzUrl = 'https://auth.robokassa.kz/Merchant/Index.aspx';
        $ruUrl = 'https://auth.robokassa.ru/Merchant/Index.aspx';

        $country = $this->setting('robokassa_country_code', 'RU');
        $paymentUrl = ($country === 'KZ') ? $kzUrl : $ruUrl;

        $receiptJson = (!empty($receipt) && is_array($receipt))
            ? urlencode(json_encode($receipt, JSON_UNESCAPED_UNICODE))
            : null;

        $formData = [
            'MrchLogin'            => $this->mrh_login,
            'OutSum'               => $sum,
            'InvId'                => $invId,
            'ResultUrl2'           => urlencode(Util::siteUrl('/?robokassa=result')),
            'Desc'                 => $invDesc,
            'shp_label'            => 'official_wordpress',
            'Shp_merchant_id'      => $this->mrh_login,
            'Shp_order_id'         => $order_uuid,
            'Shp_result_url'       => Util::siteUrl('/?robokassa=result'),
            'Shp_transaction_uuid' => $transaction_uuid,
            'recurring'            => $recurring ? 'true' : '',
            'SignatureValue'       => $this->getSignature($this->getSignatureString($sum, $invId, $order_uuid, $transaction_uuid, $receiptJson)),
        ];

        if ($this->setting('robokassa_payment_hold_onoff') == 1) {
            $formData['StepByStep'] = 'true';
        }

        if ($email !== null) {
            $formData['Email'] = $email;
        }

        $culture = $this->setting('robokassa_culture', RobokassaHelper::CULTURE_AUTO);
        if ($culture !== RobokassaHelper::CULTURE_AUTO) {
            $formData['Culture'] = $culture;
        }

        if (!empty($receipt)) {
            $formData['Receipt'] = $receiptJson;
        }

        if ($test === 'true') {
            $formData['IsTest'] = 1;
        }

        //$formData['OutSumCurrency'] = $this->setting('robokassa_out_currency');

        if (!empty($incCurrLabel) && strtoupper((string)$incCurrLabel) !== 'ALL') {
            $formData['IncCurrLabel'] = $incCurrLabel;
        }

        $robokassaEnabled = $this->setting('robokassa_payment_wc_robokassa_enabled');

        switch ((string)$robokassaEnabled) {
            case '1':
            case 'yes':
                $formUrl = $paymentUrl;
                break;
            case '0':
            case 'no':
                throw new \Exception('Robokassa is disabled in store settings');
            default:
                throw new \Exception('Unexpected value for "robokassa_payment_wc_robokassa_enabled": ' . var_export($robokassaEnabled, true));
        }

        return $this->renderForm($formUrl, $formData);
    }




	/**
	 * Формирование формы оплаты
	 */
	private function renderForm($formUrl, array $formData) {
		$chosenMethod = $formData['IncCurrLabel'];

		if ($this->setting('robokassa_iframe') == '1') {
			return $this->renderIframePayment($formData);
		}

	    if ($this->isDirectPaymentMethod($chosenMethod)) {
			return $this->renderDirectPayment($chosenMethod, $formData);
		}

		return $this->renderAutoSubmitForm($formUrl, $formData);
	}

    /**
     * Формирует JavaScript-пейлоад для iframe-оплаты.
     */
    private function resolveRedirectUrlFromSettings(string $settingValue): string
    {
        if (is_numeric($settingValue)) {
            $url = get_page_link((int)$settingValue);
            return $url ?: site_url('/');
        }

        if (filter_var($settingValue, FILTER_VALIDATE_URL)) {
            return $settingValue;
        }

        // wc_success / wc_checkout / '' — без транзакции не резолвим, REST отдаст правильный URL
        return site_url('/');
    }


    /**
     * Iframe оплата
     */
    private function renderIframePayment(array $formData)
    {
        $paramsRaw = $this->buildPaymentPayload($formData);
        $paramsArr = is_string($paramsRaw) ? (json_decode($paramsRaw, true) ?: []) : (is_array($paramsRaw) ? $paramsRaw : []);

        // Считаем fallback URLs из настроек (без транзакции — её ещё нет)
        $successUrl = $this->resolveRedirectUrlFromSettings($this->setting('robokassa_payment_SuccessURL', ''));
        $failUrl    = $this->resolveRedirectUrlFromSettings($this->setting('robokassa_payment_FailURL', ''));

        $paramsArr['_receipt_url'] = '';
        $paramsArr['success_url']  = $successUrl;
        $paramsArr['fail_url']     = $failUrl;

        $paramsJson   = wp_json_encode($paramsArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $iframeSdkUrl = $this->getIframeScriptUrl();

        $out  = $this->buildRedirectNotice();
        $out .= '<script type="text/javascript">';
        $out .= 'window.RobokassaIframeUrl = ' . wp_json_encode($iframeSdkUrl) . ';';
        $out .= 'window.RobokassaParams = ' . $paramsJson . ';';
        $out .= 'window.dispatchEvent(new CustomEvent("robokassa:params", { detail: { iframeUrl: window.RobokassaIframeUrl, params: window.RobokassaParams } }));';
        $out .= '</script>';

        return $out;
    }


	/**
	 * Формирует скрипт прямого запуска партнёрских оплат.
	*/
	private function renderDirectPayment($chosenMethod, array $formData) {
		$label = $this->getDirectPaymentLabel($chosenMethod);

		if ($label !== '') {
			$formData['IncCurrLabel'] = $label;
		}

		$params = $this->buildPaymentPayload($formData);
		$script = '<script type="text/javascript" src="https://auth.robokassa.ru/Merchant/PaymentForm/DirectPayment.js" data-robokassa-sdk="direct"></script>';
		$script .= '<script type="text/javascript" data-robokassa-init="direct">';
		$script .= 'document.addEventListener("DOMContentLoaded", function(){';
		$script .= 'if (window.Robo && window.Robo.directPayment && typeof window.Robo.directPayment.startOp === "function") {';
		$script .= 'window.Robo.directPayment.startOp(' . $params . ');';
		$script .= '}';
		$script .= '});';
		$script .= '</script>';

		return $this->buildRedirectNotice() . $script;
	}


	/**
	 * Строит стандартную HTML-форму и автоматически её отправляет.
	 */
	private function renderAutoSubmitForm($formUrl, array $formData) {
		$formId = $this->generateHtmlId('robokassa-payment-form-');
		$manualId = $this->generateHtmlId('robokassa-redirect-manual-');
		$wrapper = $this->formatHtmlAttributes([
			'class' => 'robokassa-redirect-wrapper',
			'data-form-id' => $formId,
			'data-manual-id' => $manualId,
			'data-manual-delay' => '6000',
			'data-submit-delay' => '200',
            'data-action' => $formUrl,
		]);

        $formHtml = $this->buildAutoSubmitFormHtml($formUrl, $formData, $formId);

        $output  = '<div ' . $wrapper . '>';
        $output .= $this->buildRedirectNotice($manualId, $formUrl);
        $output .= '<template class="robokassa-form-template" data-form-id="' . esc_attr($formId) . '">';
        $output .= $formHtml;
        $output .= '</template>';
        $output .= '</div>';

        return $output;
	}


	/**
	 * Формирует блок уведомления с информацией о перенаправлении.
	 */
    private function buildRedirectNotice($manualId = '', $formUrl = '') {
		$messages = $this->getRedirectNoticeMessages();

		$notice = '<div class="robokassa-redirect-notice" role="status" aria-live="polite">';
		$notice .= '<p class="robokassa-redirect-title">' . esc_html($messages['title']) . '</p>';
		$notice .= '<div class="robokassa-redirect-status">';
		$notice .= '<span class="robokassa-redirect-loader" aria-hidden="true"></span>';
		$notice .= '<p class="robokassa-redirect-message">' . esc_html($messages['message']) . '</p>';
		$notice .= '</div>';

		$notice .= '</div>';

		return $notice;
	}

	/**
	 * Возвращает локализованные сообщения для блока перенаправления.
	 */
        private function getRedirectNoticeMessages() {
		$locale = function_exists('determine_locale') ? determine_locale() : get_locale();

		if (strpos((string) $locale, 'ru') === 0) {
			return [
				'title' => __('Спасибо за ваш заказ!', 'robokassa'),
				'message' => __('Пожалуйста, подождите, выполняется перенаправление на страницу оплаты.', 'robokassa'),
			];
		}

		return [
			'title' => __('Thank you for your order!', 'robokassa'),
			'message' => __('Please wait while we redirect you to the payment page.', 'robokassa'),
		];
	}

	/**
	 * Собирает HTML формы для автоперехода на оплату.
	 */
	private function buildAutoSubmitFormHtml($formUrl, array $formData, $formId) {
		$attributes = $this->formatHtmlAttributes([
			'action' => $formUrl,
			'method' => 'POST',
			'id' => $formId,
		]);
		$form = '<form ' . $attributes . '>';
		$form .= $this->buildFormInputs($formData);
		$form .= '</form>';

		return $form;
	}

	/**
	 * Формирует набор скрытых полей для отправки в Robokassa.
	 */
	private function buildFormInputs(array $formData) {
    $inputs = '';

    foreach ($formData as $inputName => $inputValue) {
        // приводим null к пустой строке, чтобы htmlspecialchars не получал null
        $val = $inputValue === null ? '' : (string) $inputValue;
        $escapedValue = htmlspecialchars($val, ENT_COMPAT, 'UTF-8');

        $inputs .= '<input type="hidden" name="' . esc_attr($inputName) . '" value="' . esc_attr($escapedValue) . '">';
    }

    return $inputs;
}


	/**
	 * Генерирует безопасный уникальный идентификатор для HTML-элементов.
	 */
	private function generateHtmlId($prefix) {
		if (function_exists('wp_unique_id')) {
			return wp_unique_id($prefix);
		}

		return $prefix . uniqid();
	}

	/**
	 * Подготавливает строку с HTML-атрибутами.
	 */
	private function formatHtmlAttributes(array $attributes) {
		$result = [];

		foreach ($attributes as $name => $value) {
			$result[] = $name . '="' . esc_attr($value) . '"';
		}

		return implode(' ', $result);
	}

	/**
	 * Определяет, является ли способ прямым подключением партнёра.
	 */
	private function isDirectPaymentMethod($chosenMethod) {
		return in_array($chosenMethod, array(
			'robokassa_podeli',
			'robokassa_credit',
			'robokassa_mokka',
			'robokassa_split',
		), true);
	}

	/**
	 * Возвращает значение параметра IncCurrLabel для партнёрских оплат.
	 */
	private function getDirectPaymentLabel($chosenMethod) {
		$labels = array(
			'robokassa_podeli' => 'Podeli',
			'robokassa_credit' => 'OTP',
			'robokassa_mokka' => 'Mokka',
			'robokassa_split' => 'YandexPaySplit',
		);

		if (isset($labels[$chosenMethod])) {
			return $labels[$chosenMethod];
		}

		return '';
	}

	/**
	 * Подготавливает данные формы к встраиванию в JavaScript.
	 */
	private function buildPaymentPayload(array $formData) {
		$payload = array();

		foreach ($formData as $inputName => $inputValue) {
			$payload[$inputName] = $inputValue;
		}

		$json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if (!is_string($json)) {
			return '{}';
		}

		return $json;
	}

	/**
	 * Возвращает URL для подключения iframe-скрипта Robokassa.
	 */
	private function getIframeScriptUrl() {
		if ($this->setting('robokassa_country_code') === 'KZ') {
			return 'https://auth.robokassa.kz/Merchant/bundle/robokassa_iframe.js';
		}

		return 'https://auth.robokassa.ru/Merchant/bundle/robokassa_iframe.js';
	}
    
	/**
	 * Отправляет СМС с помощью GET-запроса на робокассу
	 */
	public function sendSms($phone, $message) {
		$data = array(
			'login' => $this->mrh_login,
			'phone' => $phone,
			'message' => $message,
			'signature' => $this->getSignature("$this->mrh_login:$phone:$message:$this->mrh_pass1"),
		);

		$url = substr($_SERVER['SERVER_PROTOCOL'], 0, -4).'://services.robokassa.ru/SMS/?'.http_build_query($data);

		$response = file_get_contents($url);
		$parsed = json_decode($response, true);

		$this->request = $url;
		$this->reply = $response;

		return ($parsed['result'] == 1);
	}

	/**
	 * Запрашивает и парсит в массив все возможные способы оплаты для данного магазина
	 */
	public function getCurrLabels()
	{
		return $this->sendRequest('GetCurrencies', array(
			'MerchantLogin' => $this->mrh_login,
			'Language' => 'ru',
		));
	}

	/**
	 * Парсит XML в JSON
	 */
	public function parseXmlAndConvertToJson($url) {
		return json_encode(simplexml_load_string(trim(str_replace('"', "'", str_replace(array(
			"\n",
			"\r",
			"\t",
		), '', file_get_contents($url))))));
	}


    /**
	 * Функция сборки повторных платежей подписки
	 */
    public function getRecurringPaymentData($invoiceId, $parentInvoiceId, $amount, $order_uuid, $transaction_uuid, $receipt, $description = '')
    {
        // $receipt = ($this->setting('robokassa_payment_type_commission') == 'false' && $this->setting('robokassa_country_code') != 'KZ') ? $receipt : [];
            $receiptJson = (!empty($receipt) && \is_array($receipt)) ? \urlencode(\json_encode($receipt, 256)) : null;

            $data = array_filter([
                'MerchantLogin'     => $this->mrh_login,
                'InvoiceID'         => $invoiceId,
                'PreviousInvoiceID' => $parentInvoiceId,
                'Description'       => '',
                'SignatureValue'    => md5("{$this->mrh_login}:{$amount}:{$invoiceId}:{$receiptJson}:{$this->mrh_pass1}:shp_label=official_wordpress:Shp_merchant_id=" . $this->setting('robokassa_payment_MerchantLogin') . ":Shp_order_id={$order_uuid}:Shp_result_url=" . Util::siteUrl('/?robokassa=result') .":Shp_transaction_uuid={$transaction_uuid}"),
                'OutSum'            => $amount,
                'shp_label'         => 'official_wordpress',
                'Shp_merchant_id'   => $this->setting('robokassa_payment_MerchantLogin'),
                'Shp_order_id'      => $order_uuid,
                'Shp_result_url'    => Util::siteUrl('/?robokassa=result'),
                'Shp_transaction_uuid' => $transaction_uuid,
                'Receipt'           => $receiptJson
            ], function($val) {
                return $val !== null;
            });

            return $data;
    }


    /**
     * Регистрирует REST маршруты
     */
    public function registerRestRoutes()
    {
        add_action('rest_api_init', function() {
            register_rest_route('robokassa/v1', '/status', [
                'methods' => 'GET',
                'callback' => [ $this, 'restStatusHandler' ],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * REST handler: возвращает статус транзакции по invId (numeric)
     */
    public function restStatusHandler(WP_REST_Request $request)
    {
        $transactionUuid = $request->get_param('transaction_uuid');

        if (!$transactionUuid) {
            return new WP_REST_Response(['error' => 'missing_transaction_uuid'], 400);
        }

        try {
            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionUuid)
                ->latest()
                ->first();

            if (!$transaction) {
                return new WP_REST_Response(['status' => 'not_found'], 404);
            }

            $status = ($transaction->status === Status::TRANSACTION_SUCCEEDED) ? 'succeeded' : 'pending';

            $result = [
                'status'   => $status,
                'total'    => $transaction->total,
                'currency' => $transaction->currency,
            ];

            if (method_exists($transaction, 'getReceiptPageUrl')) {
                try {
                    $r = $transaction->getReceiptPageUrl();
                    if ($r) {
                        $result['receipt_url'] = $r;
                    }
                } catch (\Throwable $e) {
                    error_log('[robokassa-debug] getReceiptPageUrl error: ' . $e->getMessage());
                }
            }

            if ($status === 'succeeded') {
                $successUrl = '';
                $successSetting = $this->setting('robokassa_payment_SuccessURL', '');

                try {
                    $order = \FluentCart\App\Models\Order::find($transaction->order_id);

                    if ($order && !empty($successSetting)) {
                        switch ($successSetting) {
                            case 'wc_success':
                                $successUrl = $transaction->getReceiptPageUrl();
                                break;
                            case 'wc_checkout':
                                if (method_exists($order, 'getViewUrl')) {
                                    $successUrl = $order->getViewUrl('customer');
                                }
                                break;
                            case 'wc_payment':
                                if (method_exists($order, 'getPaymentUrl')) {
                                    $successUrl = $order->getPaymentUrl();
                                }
                                break;
                            default:
                                if (is_numeric($successSetting)) {
                                    $successUrl = get_page_link((int)$successSetting);
                                } else {
                                    $successUrl = site_url('/');
                                }
                                break;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[robokassa-debug] success_url error: ' . $e->getMessage());
                }

                if (!$successUrl) {
                    $successUrl = site_url('/');
                }

                $result['success_url'] = $successUrl;
            }

            $failSetting = $this->setting('robokassa_payment_FailURL', '');
            if (!empty($failSetting)) {
                $failUrl = '';
                if (is_numeric($failSetting)) {
                    $p = get_permalink((int)$failSetting);
                    if ($p) $failUrl = $p;
                } elseif (filter_var($failSetting, FILTER_VALIDATE_URL)) {
                    $failUrl = $failSetting;
                }
                if ($failUrl) {
                    $result['fail_url'] = $failUrl;
                }
            }

            return new WP_REST_Response($result, 200);

        } catch (\Throwable $e) {
            error_log('[robokassa-debug] restStatusHandler error: ' . $e->getMessage());
            return new WP_REST_Response(['error' => 'server_error'], 500);
        }
    }






}