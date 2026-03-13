<?php // Утилиты общего назначения для плагина Robokassa

namespace RobokassaFluentCart\API;

if (! defined('ABSPATH')) {
    exit;
}


class Util {

    /**
     * Безопасный site_url() с принудительным HTTPS
     */
    public static function siteUrl(string $path = '', string $scheme = 'https'): string {
        $url = site_url($path, $scheme);
        return preg_replace('#^http://#', 'https://', $url);
    }


    public static function resultUrl(): string {
        // сохраняем старое поведение по умолчанию
        return self::siteUrl('/?robokassa=result');
    }

}
