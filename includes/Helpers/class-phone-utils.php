<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Phone_Util
{
    public static function sanitize_iranian_phone($phone)
    {
        $original = $phone;
        $phone = preg_replace('/[^0-9]/', '', (string) $phone);

        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            otp_verifier_log("❌ sanitize_iranian_phone: Invalid format - Original: {$original}, Cleaned: {$phone}");
            return false;
        }

        return $phone;
    }

    /**
     * ارقام فارسی/عربی را به انگلیسی تبدیل می‌کند و هر کاراکتر غیرعددی را حذف می‌کند.
     */
    public static function to_ascii_digits($value)
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];

        return preg_replace('/[^0-9]/', '', strtr((string) $value, $map));
    }

    /**
     * هر قالب رایج شماره موبایل ایران (09xxxxxxxxx، 9xxxxxxxxx، +989xxxxxxxxx، 00989xxxxxxxxx،
     * با ارقام فارسی، فاصله یا خط تیره) را به فرمت استاندارد 09xxxxxxxxx برمی‌گرداند.
     * اگر مقدار یک شماره موبایل معتبر نباشد false برمی‌گرداند.
     */
    public static function normalize_iranian_mobile($value)
    {
        if (!is_scalar($value)) {
            return false;
        }

        $digits = self::to_ascii_digits($value);

        if (preg_match('/^09[0-9]{9}$/', $digits)) {
            return $digits;
        }
        if (preg_match('/^9[0-9]{9}$/', $digits)) {
            return '0' . $digits;
        }
        if (preg_match('/^989[0-9]{9}$/', $digits)) {
            return '0' . substr($digits, 2);
        }
        if (preg_match('/^00989[0-9]{9}$/', $digits)) {
            return '0' . substr($digits, 4);
        }

        return false;
    }

    /**
     * Convert standard phone to Digits format (e.g., 09123456789 -> 989123456789)
     */
    public static function to_digits_format($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $phone);

        if (substr($phone, 0, 1) === '0') {
            return '98' . substr($phone, 1);
        }

        if (substr($phone, 0, 2) !== '98') {
            return '98' . $phone;
        }

        return $phone;
    }

    /**
     * Convert Digits format to standard Iranian format (e.g., 989123456789 -> 09123456789)
     */
    public static function from_digits_format($digits_phone)
    {
        $digits_phone = preg_replace('/[^0-9]/', '', (string) $digits_phone);

        if (substr($digits_phone, 0, 2) === '98') {
            return '0' . substr($digits_phone, 2);
        }

        if (substr($digits_phone, 0, 1) === '0') {
            return $digits_phone;
        }

        return '0' . $digits_phone;
    }
}
