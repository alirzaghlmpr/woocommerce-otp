<?php

if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Template_Loader
{
    public function __construct()
    {
        // فقط وظیفه‌اش تشخیص و بارگذاری قالب‌هاست
        add_filter('template_include', [$this, 'override_account_template']);
    }

    /**
     * تعیین اینکه چه قالبی باید لود بشه
     */
    public function override_account_template($template)
    {
        // فقط در صفحه‌ی حساب کاربری ووکامرس فعال باشه
        if (!function_exists('is_account_page') || !is_account_page()) {
            return $template;
        }

        $settings = get_option('otp_verifier_settings', []);

        /**
         * حالت ۱: کاربر لاگین نکرده → نمایش قالب لاگین سفارشی
         */
        if (!is_user_logged_in() && !empty($settings['active_login'])) {
            return OTP_VERIFIER_PATH . 'templates/login/index.php';
        }

        /**
         * حالت ۲: کاربر لاگین کرده و پنل سفارشی فعاله → بررسی endpointها
         */
        /**
         * حالت پیش‌فرض: اجازه بده قالب ووکامرس لود بشه
         */
        return $template;
    }
}
