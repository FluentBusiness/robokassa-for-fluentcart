<?php

namespace RobokassaFluentCart\API;

if (! defined('ABSPATH')) {
    exit;
}


class RobokassaHelper
{
    /** @var string предоплата 100%. Полная предварительная оплата до момента передачи предмета расчета */
    const PAYMENT_METHOD_FULL_PREPAYMENT = 'full_prepayment';
    /** @var string предоплата. Частичная предварительная оплата до момента передачи предмета расчета */
    const PAYMENT_METHOD_PREPAYMENT = 'prepayment';
    /** @var string аванс */
    const PAYMENT_METHOD_ADVANCE = 'advance';
    /** @var string полный расчет. Полная оплата, в том числе с учетом аванса (предварительной оплаты) в момент передачи предмета расчета */
    const PAYMENT_METHOD_FULL_PAYMENT = 'full_payment';
    /** @var string частичный расчет и кредит. Частичная оплата предмета расчета в момент его передачи с последующей оплатой в кредит */
    const PAYMENT_METHOD_PARTIAL_PAYMENT = 'partial_payment';
    /** @var string передача в кредит. Передача предмета расчета без его оплаты в момент его передачи с последующей оплатой в кредит */
    const PAYMENT_METHOD_CREDIT = 'credit';
    /** @var string оплата кредита. Оплата предмета расчета после его передачи с оплатой в кредит (оплата кредита) */
    const PAYMENT_METHOD_CREDIT_PAYMENT = 'credit_payment';
    /** @var string Язык общения с клиентом - не передавать */
    const CULTURE_AUTO = '';
    /** @var string Язык общения с клиентом - русский */
    const CULTURE_RU = 'ru';
    /** @var string Язык общения с клиентом - английский */
    const CULTURE_EN = 'en';

    /** @var array $culture Ящык общения с клиентов */
    public static function getCulture() {
        return [
            [
                'title' => __('Detect automatically by IP', 'fluent-robokassa'),
                'code'  => self::CULTURE_AUTO,
            ],
            [
                'title' => __('Russian', 'fluent-robokassa'),
                'code'  => self::CULTURE_RU,
            ],
            [
                'title' => __('English', 'fluent-robokassa'),
                'code'  => self::CULTURE_EN,
            ],
        ];
    }


    /** @var array $paymentMethod Признак способа расчёта */
    public static function getPaymentMethods() {
        return [
            self::PAYMENT_METHOD_FULL_PREPAYMENT => [
                'title' => __('Full prepayment 100%', 'fluent-robokassa'),
                'code'  => self::PAYMENT_METHOD_FULL_PREPAYMENT,
            ],
            self::PAYMENT_METHOD_PREPAYMENT => [
                'title' => __('Partial prepayment', 'fluent-robokassa'),
                'code'  => self::PAYMENT_METHOD_PREPAYMENT,
            ],
            self::PAYMENT_METHOD_ADVANCE => [
                'title' => __('Advance', 'fluent-robokassa'),
                'code'  => self::PAYMENT_METHOD_ADVANCE,
            ],
            self::PAYMENT_METHOD_FULL_PAYMENT => [
                'title' => __('Full payment', 'fluent-robokassa'),
                'code'  => self::PAYMENT_METHOD_FULL_PAYMENT,
            ],
            self::PAYMENT_METHOD_PARTIAL_PAYMENT => [
                'title' => __('Partial payment and credit', 'fluent-robokassa'),
                'code'  => self::PAYMENT_METHOD_PARTIAL_PAYMENT,
            ],
            self::PAYMENT_METHOD_CREDIT => [
                'title' => __('Credit transfer', 'fluent-robokassa'),
                'code'  => self::PAYMENT_METHOD_CREDIT,
            ],
            self::PAYMENT_METHOD_CREDIT_PAYMENT => [
                'title' => __('Credit payment', 'fluent-robokassa'),
                'code'  => self::PAYMENT_METHOD_CREDIT_PAYMENT,
            ],
        ];
    }


    /** @var string товар. О реализуемом товаре, за исключением подакцизного товара (наименование и иные сведения, описывающие товар) */
    const PAYMENT_OBJECT_COMMODITY = 'commodity';
    /** @var string подакцизный товар. О реализуемом подакцизном товаре (наименование и иные сведения, описывающие товар) */
    const PAYMENT_OBJECT_EXCISE = 'excise';
    /** @var string работа. О выполняемой работе (наименование и иные сведения, описывающие работу) */
    const PAYMENT_OBJECT_JOB = 'job';
    /** @var string услуга. Об оказываемой услуге (наименование и иные сведения, описывающие услугу) */
    const PAYMENT_OBJECT_SERVICE = 'service';
    /** @var string ставка азартной игры. О приеме ставок при осуществлении деятельности по проведению азартных игр */
    const PAYMENT_OBJECT_GAMBLING_BET = 'gambling_bet';
    /** @var string выигрыш азартной игры. О выплате денежных средств в виде выигрыша при осуществлении деятельности по проведению азартных игр */
    const PAYMENT_OBJECT_GAMBLING_PRIZE = 'gambling_prize';
    /** @var string лотерейный билет. О приеме денежных средств при реализации лотерейных билетов, электронных лотерейных билетов, приеме лотерейных ставок при осуществлении деятельности по проведению лотерей */
    const PAYMENT_OBJECT_LOTTERY = 'lottery';
    /** @var string  выигрыш лотереи. О выплате денежных средств в виде выигрыша при осуществлении деятельности по проведению лотерей */
    const PAYMENT_OBJECT_LOTTERY_PRIZE = 'lottery_prize';
    /** @var string предоставление результатов интеллектуальной деятельности. О предоставлении прав на использование результатов интеллектуальной деятельности или средств индивидуализации */
    const PAYMENT_OBJECT_INTELLECTUAL_ACTIVITY = 'intellectual_activity';
    /** @var string платеж. Об авансе, задатке, предоплате, кредите, взносе в счет оплаты, пени, штрафе, вознаграждении, бонусе и ином аналогичном предмете расчета */
    const PAYMENT_OBJECT_PAYMENT = 'payment';
    /** @var string агентское вознаграждение. О вознаграждении пользователя, являющегося платежным агентом (субагентом), банковским платежным агентом (субагентом), комиссионером, поверенным или иным агентом */
    const PAYMENT_OBJECT_AGENT_COMMISSION = 'agent_commission';
    /** @var string составной предмет расчета. О предмете расчета, состоящем из предметов, каждому из которых может быть присвоено значение выше перечисленных признаков */
    const PAYMENT_OBJECT_COMPOSITE = 'composite';
    /** @var string иной предмет расчета. О предмете расчета, не относящемуся к выше перечисленным предметам расчета */
    const PAYMENT_OBJECT_ANOTHER = 'another';

    /** @var array $paymentObject Признак предмета расчёта */
    public static function getPaymentObjects() {
        return [
            self::PAYMENT_OBJECT_COMMODITY => [
                'title' => __('Commodity', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_COMMODITY,
            ],
            self::PAYMENT_OBJECT_EXCISE => [
                'title' => __('Excise goods', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_EXCISE,
            ],
            self::PAYMENT_OBJECT_JOB => [
                'title' => __('Job', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_JOB,
            ],
            self::PAYMENT_OBJECT_SERVICE => [
                'title' => __('Service', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_SERVICE,
            ],
            self::PAYMENT_OBJECT_GAMBLING_BET => [
                'title' => __('Gambling bet', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_GAMBLING_BET,
            ],
            self::PAYMENT_OBJECT_GAMBLING_PRIZE => [
                'title' => __('Gambling prize', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_GAMBLING_PRIZE,
            ],
            self::PAYMENT_OBJECT_LOTTERY => [
                'title' => __('Lottery ticket', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_LOTTERY,
            ],
            self::PAYMENT_OBJECT_LOTTERY_PRIZE => [
                'title' => __('Lottery prize', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_LOTTERY_PRIZE,
            ],
            self::PAYMENT_OBJECT_INTELLECTUAL_ACTIVITY => [
                'title' => __('Provision of intellectual activity results', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_INTELLECTUAL_ACTIVITY,
            ],
            self::PAYMENT_OBJECT_PAYMENT => [
                'title' => __('Payment', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_PAYMENT,
            ],
            self::PAYMENT_OBJECT_AGENT_COMMISSION => [
                'title' => __('Agent commission', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_AGENT_COMMISSION,
            ],
            self::PAYMENT_OBJECT_COMPOSITE => [
                'title' => __('Composite payment object', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_COMPOSITE,
            ],
            self::PAYMENT_OBJECT_ANOTHER => [
                'title' => __('Another payment object', 'fluent-robokassa'),
                'code'  => self::PAYMENT_OBJECT_ANOTHER,
            ],
        ];
    }

}