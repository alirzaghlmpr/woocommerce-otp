<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once OTP_VERIFIER_PATH . 'includes/otp-verifier-helpers.php';

class OTP_Verifier_Sms_Test_Sender
{
    public function send_test($settings, $test_number)
    {
        if (empty($test_number)) {
            return [
                'success' => false,
                'message' => 'شماره موبایل تست معتبر نیست.'
            ];
        }

        try {
            $gateway_name = $settings['gateway'] ?? '';
            $config = [
                'username' => $settings['username'] ?? '',
                'password' => $settings['password'] ?? '',
                'api_key'  => $settings['api_key'] ?? '',
                'pattern'  => $settings['pattern'] ?? '',
                'line_number' => $settings['line_number'] ?? '',
                'otp_var_name' => $settings['otp_var_name'] ?? '',
            ];

            $gateway = otp_verifier_get_sms_gateway($gateway_name, $config);

            if ($gateway_name === 'melipayamak') {
                $sms_data = ['1234'];
            } else {
                $sms_data = [$config['otp_var_name'] => "1234"];
            }

            $result = $gateway->send_sms($test_number, $sms_data, $config['pattern']);
            if ($result->success) {
                return [
                    'success' => true,
                    'message' => '✅ پیامک تست با موفقیت ارسال شد.'
                ];
            }

            $error_message = $result->message ?: 'خطای ناشناخته در ارسال پیامک';
            return [
                'success' => false,
                'message' => '❌ ارسال پیامک تست با خطا مواجه شد: ' . $error_message
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '❌ خطا: ' . $e->getMessage()
            ];
        }
    }
}
