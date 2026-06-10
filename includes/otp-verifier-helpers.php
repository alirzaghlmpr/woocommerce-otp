<?php

/**
 * OTP Verifier Helper Functions
 */
if (!defined('ABSPATH')) exit;

/**
 * لاگ امن: فقط زمانی که WP_DEBUG فعال است می‌نویسد.
 * جلوگیری از پر شدن لاگ سرور در محیط Production و نشت اطلاعات.
 */
if (!function_exists('otp_verifier_log')) {
    function otp_verifier_log($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }
    }
}

/**
 * ماسک کردن شماره موبایل برای لاگ‌ها (PII).
 * مثال: 09123456789 -> 0912****89
 */
if (!function_exists('otp_verifier_mask_phone')) {
    function otp_verifier_mask_phone($phone)
    {
        $phone = (string) $phone;
        $len = strlen($phone);

        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($phone, 0, 4) . str_repeat('*', $len - 6) . substr($phone, -2);
    }
}

/**
 * هش کردن کد OTP قبل از ذخیره در دیتابیس.
 * از hash_hmac با salt اختصاصی وردپرس استفاده می‌کنیم تا کد خام هرگز ذخیره نشود.
 */
if (!function_exists('otp_verifier_hash_code')) {
    function otp_verifier_hash_code($code)
    {
        return hash_hmac('sha256', (string) $code, wp_salt('auth'));
    }
}

/**
 * ایجاد کلاس درگاه پیامکی به صورت پویا بر اساس نام و تنظیمات.
 *
 * @param string $gateway_name نام درگاه (مانند kavenegar)
 * @param array $config تنظیمات درگاه شامل username, api_key و غیره
 * @return object نمونه درگاه ساخته شده (باید از کلاس SMS_Gateway_Abstract ارث برده باشد)
 * @throws Exception اگر فایل یا کلاس درگاه یافت نشود.
 */
function otp_verifier_get_sms_gateway(string $gateway_name, array $config)
{
    // تبدیل نام درگاه به نام کلاس (مثلاً melipayamak به SMS_Gateway_MeliPayamak)
    $class_name_part = str_replace('-', '', ucwords($gateway_name, '-'));
    $class_name = 'SMS_Gateway_' . $class_name_part;

    // مسیر فایل مورد انتظار: .../sms-gateways/class-sms-gateway-melipayamak.php
    // فرض بر این است که ثابت OTP_VERIFIER_PATH در جای دیگری تعریف شده است.
    $file_path = OTP_VERIFIER_PATH . "includes/sms-gateways/class-sms-gateway-{$gateway_name}.php";

    if (!file_exists($file_path)) {
        throw new Exception("فایل درگاه {$gateway_name} یافت نشد.");
    }

    require_once $file_path;

    if (!class_exists($class_name)) {
        throw new Exception("کلاس درگاه {$class_name} تعریف نشده است.");
    }

    return new $class_name($config);
}
