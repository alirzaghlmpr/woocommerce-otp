<?php
require_once OTP_VERIFIER_PATH . 'includes/class-otp-sms-gateway.php';

class SMS_Gateway_SMSIR extends OTP_SMS_Gateway
{
    /**
     * ارسال پیامک
     *
     * @param string $to شماره موبایل
     * @param array $variables متغیرهای الگو (key-value pairs)
     * @param string|int $pattern شناسه الگو (pattern_code)
     * @return object { success: bool, code: string|int, message: string, raw_response: string }
     */
    public function send_sms(string $to, array $variables, string $pattern): object
    {
        error_log("======================================");
        error_log("📤 SMS.IR SMS SEND STARTED");
        error_log("======================================");

        try {
            // اگر base_url تنظیم نشده، از مقدار پیش‌فرض استفاده می‌کنیم
            $base_url = $this->base_url ?: 'https://api.sms.ir/v1/send/verify';

            error_log("ℹ️ SMSIR: Endpoint - {$base_url}");
            error_log("ℹ️ SMSIR: To - {$to}");
            error_log("ℹ️ SMSIR: Template ID - {$pattern}");
            error_log("ℹ️ SMSIR: Variables - " . json_encode($variables));
            error_log("ℹ️ SMSIR: API Key - " . (empty($this->api_key) ? "EMPTY" : "SET (" . substr($this->api_key, 0, 10) . "...)"));

            // ساخت آرایه پارامترها برای الگوی sms.ir
            $parameters = [];
            foreach ($variables as $key => $value) {
                $parameters[] = [
                    'name' => $key,
                    'value' => $value
                ];
            }

            error_log("ℹ️ SMSIR: Parameters array - " . json_encode($parameters));

            // ساخت body درخواست
            $body = [
                'mobile' => $to,
                'templateId' => (int)$pattern,
                'parameters' => $parameters
            ];

            $json_body = json_encode($body);
            error_log("ℹ️ SMSIR: Request body - {$json_body}");

            // ارسال درخواست POST
            $response = wp_remote_post($base_url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'X-API-KEY' => $this->api_key
                ],
                'body' => $json_body,
                'timeout' => 15,
            ]);

            // بررسی خطای وردپرس
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                error_log("❌ SMSIR: wp_remote_post ERROR - {$error_msg}");
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
            $raw_body = trim($raw_body);

            error_log("ℹ️ SMSIR: HTTP Status - {$http_code}");
            error_log("ℹ️ SMSIR: Raw Response - {$raw_body}");

            // تلاش برای پارس کردن JSON
            $decoded = json_decode($raw_body);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $json_error = json_last_error_msg();
                error_log("⚠️ SMSIR: Invalid JSON response - {$json_error}");
            }

            // بررسی موفقیت بر اساس ساختار response
            $success = false;
            $code = null;
            $message = '';

            if ($decoded && isset($decoded->status)) {
                error_log("ℹ️ SMSIR: Parsed - status={$decoded->status}, message=" . ($decoded->message ?? 'NULL'));

                if ($decoded->status === 1) {
                    // پاسخ موفق
                    $success = true;
                    $code = $decoded->data->messageId ?? null;
                    $message = $decoded->message ?? 'پیامک با موفقیت ارسال شد';
                    error_log("✅ SMSIR: SUCCESS (Message ID: {$code})");
                } else {
                    // پاسخ ناموفق
                    $success = false;
                    $code = $decoded->status ?? null;
                    $message = $decoded->message ?? 'خطای نامشخص در ارسال پیامک';
                    error_log("❌ SMSIR: FAILED - Status: {$code}, Message: {$message}");
                }
            } else {
                // پاسخ نامعتبر
                $success = false;
                $code = null;
                $message = 'پاسخ نامعتبر از سرور: ' . $raw_body;
                error_log("❌ SMSIR: Invalid response structure");
            }

            error_log("======================================");

            return (object)[
                'success' => $success,
                'code' => $code,
                'message' => $message,
                'raw_response' => $raw_body
            ];
        } catch (Exception $e) {
            error_log("❌ SMSIR: EXCEPTION - " . $e->getMessage());
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
