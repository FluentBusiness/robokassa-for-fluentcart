<?php
/**
 * Plugin Name: Robokassa for FluentCart
 * Plugin URI:  https://fluentbusiness.pro
 * Description: Robokassa payment gateway integration for FluentCart (built-in style).
 * Version:     0.1.0
 * Author:      FluentBusiness
 * Text Domain: fluent-robokassa
 */

defined( 'ABSPATH' ) || exit;

define( 'FLUENT_ROBOKASSA_VERSION', '0.1.0' );
define( 'FLUENT_ROBOKASSA_PLUGIN_FILE', __FILE__ );
define( 'FLUENT_ROBOKASSA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLUENT_ROBOKASSA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Проверка установлен ли плагин FluentCart на сайте
 */
function robokassa_fc_check_dependencies() {
    if ( ! defined( 'FLUENTCART_VERSION' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e( 'Robokassa for FluentCart', 'fluent-robokassa' ); ?></strong>
                <?php esc_html_e( 'requires FluentCart to be installed and activated.', 'fluent-robokassa' ); ?></p>
            </div>
            <?php
        } );
        return false;
    }

    // опционально: требование минимальной версии плагина
    if ( defined( 'FLUENTCART_VERSION' ) && version_compare( FLUENTCART_VERSION, '1.2.5', '<' ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong><?php esc_html_e( 'Robokassa for FluentCart', 'fluent-robokassa' ); ?></strong>
                <?php esc_html_e( 'requires FluentCart version 1.2.5 or higher.', 'fluent-robokassa' ); ?></p>
            </div>
            <?php
        } );
        return false;
    }

    return true;
}

/**
 * Автозагрузка папки includes
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'RobokassaFluentCart\\';
    $base_dir = FLUENT_ROBOKASSA_PLUGIN_DIR . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/**
 * Регистрация платежного модуля
 */
add_action( 'plugins_loaded', function() {
    if ( ! robokassa_fc_check_dependencies() ) {
        return;
    }

    add_action( 'fluent_cart/register_payment_methods', function ( $data = null ) {
        if ( class_exists( '\\RobokassaFluentCart\\RobokassaGateway' ) ) {
            \RobokassaFluentCart\RobokassaGateway::register();
        }
    }, 5 );
}, 20 );

/**
 * Активация / деактивация плагина
 */
function robokassa_fc_on_activation() {
    if ( ! robokassa_fc_check_dependencies() ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'Robokassa for FluentCart requires FluentCart to be installed and activated.', 'fluent-robokassa' ), esc_html__( 'Plugin Activation Error', 'fluent-robokassa' ), [ 'back_link' => true ] );
    }
    add_option( 'fluent_robokassa_installed_at', current_time( 'timestamp' ) );
}
register_activation_hook( __FILE__, 'robokassa_fc_on_activation' );

function robokassa_fc_on_deactivation() {
    delete_transient( 'fluent_robokassa_api_status' );
}
register_deactivation_hook( __FILE__, 'robokassa_fc_on_deactivation' );
