<?php

/**
 * Plugin Name: OTP Verifier
 * Plugin URI:  https://webioo.ir/
 * Description: ورود و ثبت‌نام با شماره تلفن و کد تایید (OTP) برای ووکامرس
 * Version:     1.3.1
 * Author:      alireza gholampour
 * Text Domain: otp-verifier
 */

if (! defined('ABSPATH')) {
    exit; // امنیت: جلوگیری از دسترسی مستقیم
}

/**
 * 🔹 تعریف ثابت‌های پایه
 */
define('OTP_VERIFIER_VERSION', '1.3.1');
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
require_once OTP_VERIFIER_PATH . 'includes/WooCommerce/class-checkout-otp-handler.php';

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

    // توجه: عمداً بدون گارد class_exists('WooCommerce') در همین‌جا. این تابع
    // مستقیماً (بدون هوک) در پایین همین فایل صدا زده می‌شود، در حالی که ترتیب
    // بارگذاری پلاگین‌ها بر اساس ترتیب فعال‌سازی است نه الفبا - یعنی ممکن است
    // این پلاگین قبل از خود ووکامرس لود شود و در آن لحظه class_exists('WooCommerce')
    // هنوز false باشد، و در نتیجه این هندلر هرگز ساخته نشود (باگ خاموش که هیچ
    // هوکی از تایید موبایل در چک‌اوت ثبت نمی‌شود). سازنده‌ی OTP_Verifier_Checkout_Handler
    // فقط add_action ثبت می‌کند و هیچ تابع ووکامرسی را مستقیماً صدا نمی‌زند، پس ساختن
    // آن همیشه بی‌خطر است؛ هوک‌های مخصوص ووکامرس (woocommerce_checkout_process و...)
    // در هر صورت فقط زمانی واقعاً اجرا می‌شوند که خود ووکامرس فعال باشد و آن‌ها را صدا بزند.
    new OTP_Verifier_Checkout_Handler();
}
otp_verifier_run();
