<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Rate_Limiter
{
    public function check_phone_limit($phone)
    {
        try {
            $transient_key = 'otp_rate_limit_' . md5($phone);
            $rate_limit_data = get_transient($transient_key);

            if ($rate_limit_data === false) {
                $data = [
                    'attempts' => 1,
                    'first_attempt_time' => time()
                ];
                $result = set_transient($transient_key, $data, 5 * MINUTE_IN_SECONDS);
                if (!$result) {
                    // Fail-closed: اگر نتوانیم شمارنده را ذخیره کنیم، اجازه نمی‌دهیم
                    // تا محدودیت با خراب کردن عمدی transient دور زده نشود.
                    otp_verifier_log("❌ check_rate_limit: Failed to set transient for " . otp_verifier_mask_phone($phone) . " - denying request (fail-closed)");
                    return false;
                }
                otp_verifier_log("✅ Rate Limit: First attempt for " . otp_verifier_mask_phone($phone));
                return true;
            }

            $attempts = isset($rate_limit_data['attempts']) ? $rate_limit_data['attempts'] : 0;
            $first_attempt_time = isset($rate_limit_data['first_attempt_time']) ? $rate_limit_data['first_attempt_time'] : time();

            $elapsed_time = time() - $first_attempt_time;
            if ($elapsed_time > 5 * MINUTE_IN_SECONDS) {
                delete_transient($transient_key);
                $data = [
                    'attempts' => 1,
                    'first_attempt_time' => time()
                ];
                set_transient($transient_key, $data, 5 * MINUTE_IN_SECONDS);
                otp_verifier_log("✅ Rate Limit: Time expired, reset for " . otp_verifier_mask_phone($phone) . "");
                return true;
            }

            if ($attempts >= 3) {
                $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
                $remaining_minutes = ceil($remaining_time / 60);
                otp_verifier_log("❌ Rate Limit: BLOCKED " . otp_verifier_mask_phone($phone) . " (Attempts: {$attempts}/3, Remaining: {$remaining_minutes} min)");
                return false;
            }

            $data = [
                'attempts' => $attempts + 1,
                'first_attempt_time' => $first_attempt_time
            ];

            $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
            $result = set_transient($transient_key, $data, $remaining_time);

            if (!$result) {
                // Fail-closed: اگر افزایش شمارنده ذخیره نشود، درخواست را رد می‌کنیم.
                otp_verifier_log("⚠️ check_rate_limit: Failed to update transient for " . otp_verifier_mask_phone($phone) . " - denying request (fail-closed)");
                return false;
            }

            otp_verifier_log("✅ Rate Limit: Attempt " . ($attempts + 1) . "/3 for " . otp_verifier_mask_phone($phone) . " (Elapsed: {$elapsed_time}s)");
            return true;
        } catch (Exception $e) {
            // Fail-closed روی خطا برای جلوگیری از دور زدن محدودیت نرخ.
            otp_verifier_log("❌ check_rate_limit: Exception - " . $e->getMessage());
            return false;
        }
    }

    public function check_ip_limit()
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (empty($ip)) {
                otp_verifier_log("⚠️ IP Rate Limit: No IP detected, allowing request");
                return true;
            }

            $transient_key = 'otp_ip_limit_' . md5($ip);
            $rate_limit_data = get_transient($transient_key);

            if ($rate_limit_data === false) {
                $data = [
                    'attempts' => 1,
                    'first_attempt_time' => time()
                ];
                $result = set_transient($transient_key, $data, 5 * MINUTE_IN_SECONDS);
                if (!$result) {
                    // Fail-closed: عدم امکان ذخیره‌ی شمارنده نباید به معنای عبور آزاد باشد.
                    otp_verifier_log("❌ check_ip_rate_limit: Failed to set transient for {$ip} - denying request (fail-closed)");
                    return false;
                }
                otp_verifier_log("✅ IP Rate Limit: First attempt from {$ip}");
                return true;
            }

            $attempts = isset($rate_limit_data['attempts']) ? $rate_limit_data['attempts'] : 0;
            $first_attempt_time = isset($rate_limit_data['first_attempt_time']) ? $rate_limit_data['first_attempt_time'] : time();

            $elapsed_time = time() - $first_attempt_time;
            if ($elapsed_time > 5 * MINUTE_IN_SECONDS) {
                delete_transient($transient_key);
                $data = [
                    'attempts' => 1,
                    'first_attempt_time' => time()
                ];
                set_transient($transient_key, $data, 5 * MINUTE_IN_SECONDS);
                otp_verifier_log("✅ IP Rate Limit: Time expired, reset for {$ip}");
                return true;
            }

            if ($attempts >= 10) {
                $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
                $remaining_minutes = ceil($remaining_time / 60);
                otp_verifier_log("❌ IP Rate Limit: BLOCKED {$ip} (Attempts: {$attempts}/10, Remaining: {$remaining_minutes} min, Key: {$transient_key})");
                return false;
            }

            $data = [
                'attempts' => $attempts + 1,
                'first_attempt_time' => $first_attempt_time
            ];

            $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
            $result = set_transient($transient_key, $data, $remaining_time);

            if (!$result) {
                // Fail-closed: اگر افزایش شمارنده ذخیره نشود، درخواست را رد می‌کنیم.
                otp_verifier_log("⚠️ check_ip_rate_limit: Failed to update transient for {$ip} - denying request (fail-closed)");
                return false;
            }

            otp_verifier_log("✅ IP Rate Limit: Attempt " . ($attempts + 1) . "/10 from {$ip} (Elapsed: {$elapsed_time}s)");
            return true;
        } catch (Exception $e) {
            // Fail-closed روی خطا برای جلوگیری از دور زدن محدودیت نرخ.
            otp_verifier_log("❌ check_ip_rate_limit: Exception - " . $e->getMessage());
            return false;
        }
    }

    public function clear_phone_limit($phone)
    {
        try {
            $transient_key = 'otp_rate_limit_' . md5($phone);
            if (delete_transient($transient_key)) {
                otp_verifier_log("✅ clear_phone_rate_limit: Cleared for " . otp_verifier_mask_phone($phone) . " (Key: {$transient_key})");
                return true;
            }
            otp_verifier_log("⚠️ clear_phone_rate_limit: Nothing to clear for " . otp_verifier_mask_phone($phone) . " (Key: {$transient_key})");
            return false;
        } catch (Exception $e) {
            otp_verifier_log("❌ clear_phone_rate_limit: Exception - " . $e->getMessage());
            return false;
        }
    }

    public function clear_ip_limit($ip)
    {
        try {
            $transient_key = 'otp_ip_limit_' . md5($ip);
            if (delete_transient($transient_key)) {
                otp_verifier_log("✅ clear_ip_rate_limit: Cleared for {$ip} (Key: {$transient_key})");
                return true;
            }
            otp_verifier_log("⚠️ clear_ip_rate_limit: Nothing to clear for {$ip} (Key: {$transient_key})");
            return false;
        } catch (Exception $e) {
            otp_verifier_log("❌ clear_ip_rate_limit: Exception - " . $e->getMessage());
            return false;
        }
    }

    public function cleanup_expired_transients()
    {
        global $wpdb;

        try {
            otp_verifier_log("======================================");
            otp_verifier_log("🧹 TRANSIENT CLEANUP STARTED");
            otp_verifier_log("======================================");

            $deleted_timeouts = $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_timeout_otp_%'
                 AND option_value < UNIX_TIMESTAMP()"
            );

            if ($deleted_timeouts === false) {
                otp_verifier_log("❌ Transient Cleanup: Database error in deleting timeouts - " . $wpdb->last_error);
            } else {
                otp_verifier_log("✅ Transient Cleanup: {$deleted_timeouts} timeout entries deleted");
            }

            $deleted_orphans = $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_otp_%'
                 AND option_name NOT LIKE '_transient_timeout_%'
                 AND option_value NOT IN (
                     SELECT option_name FROM {$wpdb->options}
                     WHERE option_name LIKE '_transient_timeout_otp_%'
                 )"
            );

            if ($deleted_orphans === false) {
                otp_verifier_log("❌ Transient Cleanup: Database error in deleting orphans - " . $wpdb->last_error);
            } else {
                otp_verifier_log("✅ Transient Cleanup: {$deleted_orphans} orphan entries deleted");
            }

            otp_verifier_log("======================================");
            otp_verifier_log("✅ TRANSIENT CLEANUP FINISHED");
            otp_verifier_log("======================================");
        } catch (Exception $e) {
            otp_verifier_log("❌ cleanup_expired_transients: Exception - " . $e->getMessage());
        }
    }
}
