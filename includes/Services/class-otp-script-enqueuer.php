<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Script_Enqueuer
{
    public function setup_scripts()
    {
        try {
            $settings = get_option('otp_verifier_settings', []);
            $active_login = isset($settings['active_login']) ? (bool) $settings['active_login'] : false;

            otp_verifier_log("ℹ️ setup_scripts: active_login = " . ($active_login ? 'true' : 'false'));

            if (!$active_login) {
                otp_verifier_log("ℹ️ setup_scripts: Login disabled, skipping script enqueue");
                return;
            }

            if (function_exists('is_account_page') && is_account_page() && !is_user_logged_in()) {
                otp_verifier_log("✅ setup_scripts: Using direct script tags in login template (no wp_head/wp_footer)");
            } else {
                otp_verifier_log("ℹ️ setup_scripts: Not on account page or user logged in");
            }
        } catch (Exception $e) {
            otp_verifier_log("❌ setup_scripts: Exception - " . $e->getMessage());
        }
    }

    public function enqueue_scripts_in_footer()
    {
        try {
            wp_enqueue_script('jquery');

            wp_enqueue_script(
                'otp-login-script-sweetalert',
                OTP_VERIFIER_URL . 'templates/assets/js/sweetalert2.min.js',
                ['jquery'],
                OTP_VERIFIER_VERSION,
                true
            );

            wp_enqueue_script(
                'otp-login-script',
                OTP_VERIFIER_URL . 'templates/assets/js/auth-login.js',
                ['jquery', 'otp-login-script-sweetalert'],
                OTP_VERIFIER_VERSION,
                true
            );

            $settings = get_option('otp_verifier_settings', []);
            $expire   = isset($settings['otp_expire']) ? absint($settings['otp_expire']) : 120;
            $otp_len  = isset($settings['otp_length']) ? absint($settings['otp_length']) : 6;

            // مسیر admin-ajax را نسبی (بدون دامنه) می‌فرستیم تا درخواست همیشه به
            // همان مبدائی (origin) ارسال شود که صفحه از آن باز شده است. این کار از
            // خطای cross-origin زمانی که سایت با چند آدرس (localhost و IP شبکه)
            // در دسترس است جلوگیری می‌کند.
            $ajax_url  = admin_url('admin-ajax.php');
            $ajax_path = wp_parse_url($ajax_url, PHP_URL_PATH);

            wp_localize_script('otp-login-script', 'otp_ajax', [
                'ajaxurl'    => $ajax_path ? $ajax_path : $ajax_url,
                'nonce'      => wp_create_nonce('otp_login_nonce'),
                'expire'     => $expire,
                'otp_length' => $otp_len,
                'assets_url' => OTP_VERIFIER_URL . 'templates/assets/',
                'messages'   => [
                    'invalid_phone'  => 'شماره موبایل معتبر نیست.',
                    'otp_sent'       => 'کد تایید ارسال شد.',
                    'otp_invalid'    => 'کد وارد شده صحیح نیست یا منقضی شده.',
                    'otp_verified'   => 'ورود با موفقیت انجام شد!',
                    'rate_limit'     => 'تعداد درخواست‌های شما بیش از حد است. لطفاً کمی صبر کنید.',
                    'sms_failed'     => 'خطا در ارسال پیامک. لطفاً دوباره تلاش کنید.',
                ]
            ]);

            otp_verifier_log("✅ enqueue_scripts_in_footer: Scripts enqueued successfully (expire: {$expire}s, length: {$otp_len})");
        } catch (Exception $e) {
            otp_verifier_log("❌ enqueue_scripts_in_footer: Exception - " . $e->getMessage());
        }
    }
}
