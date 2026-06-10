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
