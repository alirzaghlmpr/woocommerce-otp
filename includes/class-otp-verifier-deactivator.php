<?php

if (!defined('ABSPATH')) exit;

require_once OTP_VERIFIER_PATH . 'includes/class-otp-handler.php';

class OTP_Verifier_Deactivator
{

    public static function deactivate()
    {
        if (class_exists('OTP_Verifier_Handler')) {
            OTP_Verifier_Handler::cleanup_cron();

            error_log("✅ OTP_Verifier_Deactivator: Plugin deactivated. Cron jobs stopped.");
        } else {
            error_log("❌ OTP_Verifier_Deactivator: Handler class not found during deactivation.");
        }
    }
}
