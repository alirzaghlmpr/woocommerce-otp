<?php
require_once OTP_VERIFIER_PATH . 'includes/class-otp-sms-gateway.php';

class SMS_Gateway_Kavenegar extends OTP_SMS_Gateway
{
    /**
     * ارسال پیامک
     *
     * @param string $to شماره موبایل
     * @param array $variables متغیرهای الگو (key-value pairs)
     * @param string|int $pattern نام الگو (template)
     * @return object { success: bool, code: string|int, message: string, raw_response: string }
     */
    public function send_sms(string $to, array $variables, string $pattern): object
    {
        error_log("======================================");
        error_log("📤 KAVENEGAR SMS SEND STARTED");
        error_log("======================================");

        try {
            error_log("ℹ️ Kavenegar: To - {$to}");
            error_log("ℹ️ Kavenegar: Template - {$pattern}");
            error_log("ℹ️ Kavenegar: Variables - " . json_encode($variables));
            error_log("ℹ️ Kavenegar: API Key - " . (empty($this->api_key) ? "EMPTY" : "SET (" . substr($this->api_key, 0, 10) . "...)"));

            // ساخت URL با API Key
            $url = 'https://api.kavenegar.com/v1/' . $this->api_key . '/verify/lookup.json';

            error_log("ℹ️ Kavenegar: Base URL - " . preg_replace('/v1\/[^\/]+\//', 'v1/***//', $url));

            // آماده‌سازی پارامترها
            $params = [
                'receptor' => $to,
                'template' => $pattern,
            ];

            // اضافه کردن توکن‌ها به پارامترها
            // کاوه‌نگار از token, token2, token3, token10, token20 پشتیبانی می‌کند
            foreach ($variables as $key => $value) {
                $params[$key] = $value;
            }

            $final_url = add_query_arg($params, $url);
            error_log("ℹ️ Kavenegar: Final URL parameters - " . json_encode($params));

            // ارسال درخواست GET
            $response = wp_remote_get($final_url, [
                'timeout' => 15,
            ]);

            // بررسی خطای وردپرس
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                error_log("❌ Kavenegar: wp_remote_get ERROR - {$error_msg}");
                error_log("======================================");

                return (object)[
                    'success' => false,
                    'code' => null,
                    'message' => $error_msg,
                    'raw_response' => null
                ];
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);

            error_log("ℹ️ Kavenegar: HTTP Status - {$http_code}");
            error_log("ℹ️ Kavenegar: Raw Response - {$raw_body}");

            $data = json_decode($raw_body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $json_error = json_last_error_msg();
                error_log("⚠️ Kavenegar: Invalid JSON response - {$json_error}");
            }

            // بررسی موفقیت بر اساس ساختار response
            $success = false;
            $code = null;
            $message = '';

            if (isset($data['return']['status'])) {
                $status = $data['return']['status'];
                error_log("ℹ️ Kavenegar: Return status - {$status}");

                if ($status == 200) {
                    // پاسخ موفق
                    $success = true;
                    $code = $data['entries'][0]['messageid'] ?? null;
                    $message = 'پیامک با موفقیت ارسال شد';
                    error_log("✅ Kavenegar: SUCCESS (Message ID: {$code})");
                } else {
                    // پاسخ خطا
                    $success = false;
                    $code = $status;
                    $message = $data['return']['message'] ?? 'خطای نامشخص در ارسال پیامک';
                    error_log("❌ Kavenegar: FAILED - Status: {$code}, Message: {$message}");
                }
            } else {
                error_log("❌ Kavenegar: Invalid response structure - missing 'return.status'");
                $success = false;
                $code = null;
                $message = 'پاسخ نامعتبر از سرور';
            }

            error_log("======================================");

            return (object)[
                'success' => $success,
                'code' => $code,
                'message' => $message,
                'raw_response' => $raw_body
            ];
        } catch (Exception $e) {
            error_log("❌ Kavenegar: EXCEPTION - " . $e->getMessage());
            error_log("❌ Stack trace: " . $e->getTraceAsString());
            error_log("======================================");

            return (object)[
                'success' => false,
                'code' => null,
                'message' => 'Exception: ' . $e->getMessage(),
                'raw_response' => null
            ];
        }
    }
}
