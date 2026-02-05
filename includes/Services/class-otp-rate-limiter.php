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
                    error_log("❌ check_rate_limit: Failed to set transient for {$phone}");
                    return true;
                }
                error_log("✅ Rate Limit: First attempt for {$phone} (Key: {$transient_key})");
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
                error_log("✅ Rate Limit: Time expired, reset for {$phone}");
                return true;
            }

            if ($attempts >= 3) {
                $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
                $remaining_minutes = ceil($remaining_time / 60);
                error_log("❌ Rate Limit: BLOCKED {$phone} (Attempts: {$attempts}/3, Remaining: {$remaining_minutes} min, Key: {$transient_key})");
                return false;
            }

            $data = [
                'attempts' => $attempts + 1,
                'first_attempt_time' => $first_attempt_time
            ];

            $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
            $result = set_transient($transient_key, $data, $remaining_time);

            if (!$result) {
                error_log("⚠️ check_rate_limit: Failed to update transient for {$phone}");
            }

            error_log("✅ Rate Limit: Attempt " . ($attempts + 1) . "/3 for {$phone} (Elapsed: {$elapsed_time}s, Key: {$transient_key})");
            return true;
        } catch (Exception $e) {
            error_log("❌ check_rate_limit: Exception - " . $e->getMessage());
            return true;
        }
    }

    public function check_ip_limit()
    {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (empty($ip)) {
                error_log("⚠️ IP Rate Limit: No IP detected, allowing request");
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
                    error_log("❌ check_ip_rate_limit: Failed to set transient for {$ip}");
                    return true;
                }
                error_log("✅ IP Rate Limit: First attempt from {$ip} (Key: {$transient_key})");
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
                error_log("✅ IP Rate Limit: Time expired, reset for {$ip}");
                return true;
            }

            if ($attempts >= 10) {
                $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
                $remaining_minutes = ceil($remaining_time / 60);
                error_log("❌ IP Rate Limit: BLOCKED {$ip} (Attempts: {$attempts}/10, Remaining: {$remaining_minutes} min, Key: {$transient_key})");
                return false;
            }

            $data = [
                'attempts' => $attempts + 1,
                'first_attempt_time' => $first_attempt_time
            ];

            $remaining_time = (5 * MINUTE_IN_SECONDS) - $elapsed_time;
            $result = set_transient($transient_key, $data, $remaining_time);

            if (!$result) {
                error_log("⚠️ check_ip_rate_limit: Failed to update transient for {$ip}");
            }

            error_log("✅ IP Rate Limit: Attempt " . ($attempts + 1) . "/10 from {$ip} (Elapsed: {$elapsed_time}s, Key: {$transient_key})");
            return true;
        } catch (Exception $e) {
            error_log("❌ check_ip_rate_limit: Exception - " . $e->getMessage());
            return true;
        }
    }

    public function clear_phone_limit($phone)
    {
        try {
            $transient_key = 'otp_rate_limit_' . md5($phone);
            if (delete_transient($transient_key)) {
                error_log("✅ clear_phone_rate_limit: Cleared for {$phone} (Key: {$transient_key})");
                return true;
            }
            error_log("⚠️ clear_phone_rate_limit: Nothing to clear for {$phone} (Key: {$transient_key})");
            return false;
        } catch (Exception $e) {
            error_log("❌ clear_phone_rate_limit: Exception - " . $e->getMessage());
            return false;
        }
    }

    public function clear_ip_limit($ip)
    {
        try {
            $transient_key = 'otp_ip_limit_' . md5($ip);
            if (delete_transient($transient_key)) {
                error_log("✅ clear_ip_rate_limit: Cleared for {$ip} (Key: {$transient_key})");
                return true;
            }
            error_log("⚠️ clear_ip_rate_limit: Nothing to clear for {$ip} (Key: {$transient_key})");
            return false;
        } catch (Exception $e) {
            error_log("❌ clear_ip_rate_limit: Exception - " . $e->getMessage());
            return false;
        }
    }

    public function cleanup_expired_transients()
    {
        global $wpdb;

        try {
            error_log("======================================");
            error_log("🧹 TRANSIENT CLEANUP STARTED");
            error_log("======================================");

            $deleted_timeouts = $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_timeout_otp_%'
                 AND option_value < UNIX_TIMESTAMP()"
            );

            if ($deleted_timeouts === false) {
                error_log("❌ Transient Cleanup: Database error in deleting timeouts - " . $wpdb->last_error);
            } else {
                error_log("✅ Transient Cleanup: {$deleted_timeouts} timeout entries deleted");
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
                error_log("❌ Transient Cleanup: Database error in deleting orphans - " . $wpdb->last_error);
            } else {
                error_log("✅ Transient Cleanup: {$deleted_orphans} orphan entries deleted");
            }

            error_log("======================================");
            error_log("✅ TRANSIENT CLEANUP FINISHED");
            error_log("======================================");
        } catch (Exception $e) {
            error_log("❌ cleanup_expired_transients: Exception - " . $e->getMessage());
        }
    }
}
