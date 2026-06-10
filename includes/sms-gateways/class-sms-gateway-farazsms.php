<?php

require_once OTP_VERIFIER_PATH . 'includes/class-otp-sms-gateway.php';

class SMS_Gateway_FarazSMS extends OTP_SMS_Gateway
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
        otp_verifier_log("======================================");
        otp_verifier_log("📤 FARAZSMS SMS SEND STARTED");
        otp_verifier_log("======================================");

        try {
            // اگر base_url تنظیم نشده، از مقدار پیش‌فرض استفاده می‌کنیم
            $base_url = $this->base_url ?: 'https://ippanel.com/patterns/pattern';

            // ساخت آرایه شماره‌های دریافت‌کننده
            $to_array = [$to];

            otp_verifier_log("ℹ️ FarazSMS: Endpoint - {$base_url}");
            otp_verifier_log("ℹ️ FarazSMS: To - {$to}");
            otp_verifier_log("ℹ️ FarazSMS: Pattern Code - {$pattern}");
            otp_verifier_log("ℹ️ FarazSMS: Line Number - {$this->line_number}");
            otp_verifier_log("ℹ️ FarazSMS: Variables - " . json_encode($variables));
            otp_verifier_log("ℹ️ FarazSMS: Username - " . (empty($this->username) ? "EMPTY" : "SET"));

            // ساخت URL با پارامترهای GET
            $url = $base_url . '?username=' . urlencode($this->username)
                . '&password=' . urlencode($this->password)
                . '&from=' . urlencode($this->line_number)
                . '&to=' . urlencode(json_encode($to_array))
                . '&input_data=' . urlencode(json_encode($variables))
                . '&pattern_code=' . urlencode($pattern);

            otp_verifier_log("ℹ️ FarazSMS: Request URL - " . preg_replace('/password=[^&]+/', 'password=***', $url));

            // ارسال درخواست POST
            $response = wp_remote_post($url, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => $variables,
                'timeout' => 15,
            ]);

            // بررسی خطای وردپرس
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                otp_verifier_log("❌ FarazSMS: wp_remote_post ERROR - {$error_msg}");
                otp_verifier_log("======================================");

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

            otp_verifier_log("ℹ️ FarazSMS: HTTP Status - {$http_code}");
            otp_verifier_log("ℹ️ FarazSMS: Raw Response - {$raw_body}");

            // بررسی موفقیت بر اساس ساختار response
            // اگر body فقط عدد باشد → موفق
            // اگر body حاوی متن فارسی یا پیام خطا باشد → ناموفق
            $success = false;
            $code = null;
            $message = '';

            if (is_numeric($raw_body) && strlen($raw_body) > 5) {
                // پاسخ عددی بلند → موفقیت (شناسه پیامک)
                $success = true;
                $code = $raw_body;
                $message = 'پیامک با موفقیت ارسال شد';
                otp_verifier_log("✅ FarazSMS: SUCCESS (Message ID: {$code})");
            } else {
                // پاسخ متنی → خطا
                $success = false;
                $code = null;
                $message = $raw_body; // متن خطا
                otp_verifier_log("❌ FarazSMS: FAILED - {$message}");
            }

            otp_verifier_log("======================================");

            return (object)[
                'success' => $success,
                'code' => $code,
                'message' => $message,
                'raw_response' => $raw_body
            ];
        } catch (Exception $e) {
            otp_verifier_log("❌ FarazSMS: EXCEPTION - " . $e->getMessage());
            otp_verifier_log("❌ Stack trace: " . $e->getTraceAsString());
            otp_verifier_log("======================================");

            return (object)[
                'success' => false,
                'code' => null,
                'message' => 'Exception: ' . $e->getMessage(),
                'raw_response' => null
            ];
        }
    }
}
