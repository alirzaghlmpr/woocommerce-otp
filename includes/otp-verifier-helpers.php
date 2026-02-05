<?php

/**
 * OTP Verifier Helper Functions
 */
if (!defined('ABSPATH')) exit;

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
