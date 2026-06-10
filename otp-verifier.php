<?php

/**
 * Plugin Name: OTP Verifier
 * Plugin URI:  https://webioo.ir/
 * Description: ورود و ثبت‌نام با شماره تلفن و کد تایید (OTP) برای ووکامرس
 * Version:     1.1.0
 * Author:      alireza gholampour
 * Text Domain: otp-verifier
 */

if (! defined('ABSPATH')) {
    exit; // امنیت: جلوگیری از دسترسی مستقیم
}

/**
 * 🔹 تعریف ثابت‌های پایه
 */
define('OTP_VERIFIER_VERSION', '1.1.0');
define('OTP_VERIFIER_PATH', plugin_dir_path(__FILE__));
define('OTP_VERIFIER_URL', plugin_dir_url(__FILE__));

/**
 * 🔹 لود فایل‌های مورد نیاز
 */
require_once OTP_VERIFIER_PATH . 'includes/otp-verifier-helpers.php';
require_once OTP_VERIFIER_PATH . 'includes/class-otp-verifier-activator.php';
require_once OTP_VERIFIER_PATH . 'includes/class-otp-verifier-deactivator.php';
require_once OTP_VERIFIER_PATH . 'includes/class-otp-verifier.php';
require_once OTP_VERIFIER_PATH . 'includes/class-settings-page.php';
require_once OTP_VERIFIER_PATH . 'includes/class-template-loader.php';
require_once OTP_VERIFIER_PATH . 'includes/class-ajax-handler.php';

/**
 * 🔹 فعال‌سازی / غیرفعال‌سازی افزونه
 */
function otp_verifier_activate()
{
    OTP_Verifier_Activator::activate();
}
register_activation_hook(__FILE__, 'otp_verifier_activate');

function otp_verifier_deactivate()
{
    OTP_Verifier_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'otp_verifier_deactivate');

/**
 * 🔹 مهاجرت ساختار دیتابیس هنگام به‌روزرسانی افزونه بدون فعال‌سازی مجدد
 */
add_action('admin_init', ['OTP_Verifier_Activator', 'maybe_upgrade']);

/**
 * 🔹 اجرای افزونه
 */
function otp_verifier_run()
{
    $plugin = new OTP_Verifier();
    $plugin->run();

    new OTP_Verifier_Template_Loader();
    new OTP_AJAX_Handler();
}
otp_verifier_run();
