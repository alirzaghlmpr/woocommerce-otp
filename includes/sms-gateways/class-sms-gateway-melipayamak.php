<?php


require_once OTP_VERIFIER_PATH . 'includes/class-otp-sms-gateway.php';

class SMS_Gateway_MeliPayamak extends OTP_SMS_Gateway
{
    /**
     * ارسال پیامک
     *
     * @param string $to شماره موبایل
     * @param array $variables متغیرهای الگو
     * @param string|int $pattern شناسه الگو (bodyId)
     * @return object { success: bool, code: string|int, message: string, raw_response: string }
     */
    public function send_sms(string $to, array $variables, string $pattern): object
    {
        otp_verifier_log("======================================");
        otp_verifier_log("📤 MELIPAYAMAK SMS SEND STARTED");
        otp_verifier_log("======================================");

        try {
            $endpoint = $this->base_url ?: 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
            $text = implode(';', $variables);

            otp_verifier_log("ℹ️ MeliPayamak: Endpoint - {$endpoint}");
            otp_verifier_log("ℹ️ MeliPayamak: To - {$to}");
            otp_verifier_log("ℹ️ MeliPayamak: Pattern (bodyId) - {$pattern}");
            otp_verifier_log("ℹ️ MeliPayamak: Text - {$text}");
            otp_verifier_log("ℹ️ MeliPayamak: Username - " . (empty($this->username) ? "EMPTY" : "SET"));

            $body = [
                'username' => $this->username,
                'password' => $this->password,
                'text'     => $text,
                'to'       => $to,
                'bodyId'   => $pattern,
            ];

            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'body' => $body,
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                otp_verifier_log("❌ MeliPayamak: wp_remote_post ERROR - {$error_msg}");
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

            otp_verifier_log("ℹ️ MeliPayamak: HTTP Status - {$http_code}");
            otp_verifier_log("ℹ️ MeliPayamak: Raw Response - {$raw_body}");

            // تلاش برای تبدیل JSON پاسخ
            $data = json_decode($raw_body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $json_error = json_last_error_msg();
                otp_verifier_log("⚠️ MeliPayamak: Invalid JSON response - {$json_error}");
                otp_verifier_log("======================================");

                return (object)[
                    'success' => false,
                    'code' => null,
                    'message' => 'Invalid JSON response: ' . $json_error,
                    'raw_response' => $raw_body
                ];
            }

            // بررسی موفقیت: recId عدد طولانی یا RetStatus = 1
            $success = false;
            $code = $data['Value'] ?? null;
            $message = $data['StrRetStatus'] ?? '';

            otp_verifier_log("ℹ️ MeliPayamak: Parsed - Value={$code}, RetStatus=" . ($data['RetStatus'] ?? 'NULL') . ", StrRetStatus={$message}");

            if (isset($code) && is_numeric($code) && strlen($code) > 15) {
                $success = true;
                otp_verifier_log("✅ MeliPayamak: SUCCESS (long numeric Value)");
            } elseif (!empty($data['RetStatus']) && intval($data['RetStatus']) === 1) {
                $success = true;
                otp_verifier_log("✅ MeliPayamak: SUCCESS (RetStatus = 1)");
            } else {
                otp_verifier_log("❌ MeliPayamak: FAILED");
            }

            otp_verifier_log("======================================");

            return (object)[
                'success' => $success,
                'code' => $code,
                'message' => $message,
                'raw_response' => $raw_body
            ];
        } catch (Exception $e) {
            otp_verifier_log("❌ MeliPayamak: EXCEPTION - " . $e->getMessage());
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
