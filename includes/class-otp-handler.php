<?php
if (!defined('ABSPATH')) exit;

require_once OTP_VERIFIER_PATH . 'includes/Services/class-otp-repository.php';

class OTP_Verifier_Handler
{
    private $table;
    private $max_verify_attempts = 5;
    private $repo;

    private function get_wp_timezone()
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $tz_string = get_option('timezone_string');
        if (!empty($tz_string)) {
            try {
                return new DateTimeZone($tz_string);
            } catch (Exception $e) {
                // Ignore and fallback to gmt_offset.
            }
        }

        $offset = (float) get_option('gmt_offset', 0);
        $hours = (int) $offset;
        $minutes = (int) round(abs($offset - $hours) * 60);
        $sign = $offset >= 0 ? '+' : '-';
        $tz_offset = sprintf('%s%02d:%02d', $sign, abs($hours), $minutes);

        return new DateTimeZone($tz_offset);
    }

    private function get_wp_now_datetime()
    {
        return new DateTimeImmutable('now', $this->get_wp_timezone());
    }

    private function parse_wp_local_mysql_datetime($datetime_string)
    {
        $datetime = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $datetime_string,
            $this->get_wp_timezone()
        );

        if ($datetime instanceof DateTimeImmutable) {
            return (int) $datetime->format('U');
        }

        $fallback = strtotime((string) $datetime_string);
        return $fallback === false ? 0 : (int) $fallback;
    }

    private function format_timestamp_for_log($timestamp)
    {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return 'invalid';
        }

        if (function_exists('wp_date')) {
            return wp_date('Y-m-d H:i:s', $timestamp, $this->get_wp_timezone());
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'otp_verifier_codes';
        $this->repo = new OTP_Verifier_Otp_Repository();

        error_log("✅ OTP_Verifier_Handler: Initialized with table - {$this->table}");

        // ✅ راه‌اندازی WP Cron برای پاکسازی خودکار هر 10 دقیقه
        add_action('otp_verifier_cleanup_cron', [$this, 'delete_expired_otps']);

        // ✅ ثبت Cron Schedule سفارشی (هر 10 دقیقه)
        add_filter('cron_schedules', [$this, 'add_custom_cron_schedule']);

        if (!wp_next_scheduled('otp_verifier_cleanup_cron')) {
            $scheduled = wp_schedule_event(time(), 'every_10_minutes', 'otp_verifier_cleanup_cron');
            if ($scheduled === false) {
                error_log("❌ OTP Cron: Failed to schedule cleanup (every 10 minutes)");
            } else {
                error_log("✅ OTP Cron: Scheduled successfully (every 10 minutes)");
            }
        } else {
            $next_run = wp_next_scheduled('otp_verifier_cleanup_cron');
            error_log("ℹ️ OTP Cron: Already scheduled, next run at " . date('Y-m-d H:i:s', $next_run));
        }
    }

    /**
     * ✅ اضافه کردن schedule سفارشی: هر 10 دقیقه
     */
    public function add_custom_cron_schedule($schedules)
    {
        if (!isset($schedules['every_10_minutes'])) {
            $schedules['every_10_minutes'] = [
                'interval' => 600, // 10 دقیقه = 600 ثانیه
                'display'  => __('هر 10 دقیقه یکبار')
            ];
            error_log("✅ add_custom_cron_schedule: Registered 'every_10_minutes' schedule (600s)");
        }
        return $schedules;
    }

    /**
     * ✅ تولید OTP جدید
     */
    public function generate_otp($phone_number)
    {
        try {
            $settings = get_option('otp_verifier_settings', []);
            $length = isset($settings['otp_length']) ? absint($settings['otp_length']) : 6;

            if ($length > 6) {
                error_log("⚠️ generate_otp: Length {$length} exceeds max, capping to 6");
                $length = 6;
            }
            if ($length < 4) {
                error_log("⚠️ generate_otp: Length {$length} below min, setting to 4");
                $length = 4;
            }

            // ✅ حذف OTP های قبلی این شماره
            $deleted = $this->repo->delete_by_phone($phone_number);

            if ($deleted === false) {
                global $wpdb;
                error_log("❌ generate_otp: Failed to delete old OTPs for {$phone_number} - DB Error: {$wpdb->last_error}");
            } elseif ($deleted > 0) {
                error_log("ℹ️ generate_otp: Deleted {$deleted} old OTP(s) for {$phone_number}");
            }

            $min = pow(10, $length - 1);
            $max = pow(10, $length) - 1;
            $otp = wp_rand($min, $max);

            $created_at = $this->get_wp_now_datetime()->format('Y-m-d H:i:s');
            $insert_result = $this->repo->insert_otp($phone_number, $otp, $created_at);

            if ($insert_result === false) {
                global $wpdb;
                error_log("❌ generate_otp: INSERT FAILED for {$phone_number} - DB Error: {$wpdb->last_error}");
                return false;
            }

            global $wpdb;
            $insert_id = $wpdb->insert_id;
            error_log("✅ generate_otp: SUCCESS - Phone: {$phone_number}, Code: {$otp}, Length: {$length}, Insert ID: {$insert_id}");

            return $otp;
        } catch (Exception $e) {
            error_log("❌ generate_otp: EXCEPTION - " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ بررسی و اعتبارسنجی OTP
     */
    public function verify_otp($phone_number, $otp)
    {
        error_log("======================================");
        error_log("🔍 OTP VERIFICATION STARTED");
        error_log("======================================");
        error_log("ℹ️ verify_otp: Phone: {$phone_number}, Entered OTP: {$otp}");

        try {
            // ✅ پاکسازی کدهای منقضی قبل از بررسی
            $cleaned = $this->delete_expired_otps();
            error_log("ℹ️ verify_otp: Pre-verification cleanup removed {$cleaned} expired OTP(s)");

            $row = $this->repo->get_latest_unverified($phone_number);

            global $wpdb;
            if ($wpdb->last_error) {
                error_log("❌ verify_otp: Database error in SELECT - {$wpdb->last_error}");
            }

            if (!$row) {
                error_log("❌ verify_otp: No active OTP found for {$phone_number}");
                error_log("======================================");
                return false;
            }

            error_log("✅ verify_otp: Found OTP record - ID: {$row->id}, Code: {$row->code}, Created: {$row->created_at}, Attempts: {$row->attempt_count}/{$this->max_verify_attempts}");

            // ✅ بررسی تعداد تلاش‌های اشتباه
            if ($row->attempt_count >= $this->max_verify_attempts) {
                error_log("❌ verify_otp: Max attempts reached ({$this->max_verify_attempts}) for {$phone_number} - Deleting OTP");
                $this->delete_otp($row->id);
                error_log("======================================");
                return false;
            }

            // ✅ بررسی انقضا (با محاسبه دقیق زمان)
            $settings = get_option('otp_verifier_settings', []);
            $expire_seconds = isset($settings['otp_expire']) ? absint($settings['otp_expire']) : 120;

            $created_timestamp = $this->parse_wp_local_mysql_datetime($row->created_at);
            $current_timestamp = (int) $this->get_wp_now_datetime()->format('U');
            $age_seconds = $current_timestamp - $created_timestamp;

            if ($created_timestamp <= 0) {
                error_log("❌ verify_otp: Invalid created_at format for OTP ID {$row->id} ({$row->created_at})");
                $this->delete_otp($row->id);
                error_log("======================================");
                return false;
            }

            if ($age_seconds < 0) {
                error_log("⚠️ verify_otp: Negative age detected ({$age_seconds}s). Clock mismatch suspected; forcing age to 0.");
                $age_seconds = 0;
            }

            error_log("🕐 verify_otp: Time check - Created: " . $this->format_timestamp_for_log($created_timestamp) .
                ", Current: " . $this->format_timestamp_for_log($current_timestamp) .
                ", Age: {$age_seconds}s, Expire threshold: {$expire_seconds}s");

            if ($age_seconds > $expire_seconds) {
                error_log("❌ verify_otp: OTP EXPIRED - Age {$age_seconds}s > Threshold {$expire_seconds}s for {$phone_number}");
                $this->delete_otp($row->id);
                error_log("======================================");
                return false;
            }

            // ✅ بررسی صحت کد
            if ($row->code !== $otp) {
                $new_attempts = $row->attempt_count + 1;
                $remaining = $this->max_verify_attempts - $new_attempts;

                $update_result = $this->repo->update_attempt_count($row->id, $new_attempts);

                if ($update_result === false) {
                    error_log("❌ verify_otp: Failed to update attempt_count - DB Error: {$wpdb->last_error}");
                } else {
                    error_log("ℹ️ verify_otp: Updated attempt_count to {$new_attempts}");
                }

                error_log("❌ verify_otp: WRONG CODE - Expected: {$row->code}, Got: {$otp}, Attempts: {$new_attempts}/{$this->max_verify_attempts}, Remaining: {$remaining}");

                // ✅ اگه به حداکثر رسید، حذفش کن
                if ($new_attempts >= $this->max_verify_attempts) {
                    error_log("🗑️ verify_otp: Max attempts reached after wrong code - Deleting OTP ID: {$row->id}");
                    $this->delete_otp($row->id);
                }

                error_log("======================================");
                return false;
            }

            // ✅ موفق - حذف OTP
            $this->delete_otp($row->id);
            error_log("✅ verify_otp: OTP VERIFIED SUCCESSFULLY - Phone: {$phone_number}, Code: {$otp}");
            error_log("======================================");

            return true;
        } catch (Exception $e) {
            error_log("❌ verify_otp: EXCEPTION - " . $e->getMessage());
            error_log("❌ Stack trace: " . $e->getTraceAsString());
            error_log("======================================");
            return false;
        }
    }

    /**
     * ✅ حذف OTP خاص
     */
    public function delete_otp($id)
    {
        try {
            $deleted = $this->repo->delete_by_id($id);

            if ($deleted === false) {
                global $wpdb;
                error_log("❌ delete_otp: Failed to delete OTP ID: {$id} - DB Error: {$wpdb->last_error}");
                return false;
            }

            if ($deleted > 0) {
                error_log("🗑️ delete_otp: Successfully deleted OTP ID: {$id}");
            } else {
                error_log("⚠️ delete_otp: No OTP found with ID: {$id} (already deleted?)");
            }

            return $deleted;
        } catch (Exception $e) {
            error_log("❌ delete_otp: EXCEPTION - " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ حذف OTP های منقضی‌شده (صدا زده می‌شه توسط WP Cron هر 10 دقیقه)
     */
    /**
     * ✅ نسخه اصلاح شده: محاسبه زمان انقضا با ساعت وردپرس (PHP)
     * جلوگیری از تداخل تایم‌زون سرور و وردپرس
     */
    public function delete_expired_otps()
    {
        try {
            $settings = get_option('otp_verifier_settings', []);
            $expire = isset($settings['otp_expire']) ? absint($settings['otp_expire']) : 120;

            // ✅ تغییر مهم: محاسبه زمان قطع (Cutoff) در PHP بر اساس ساعت وردپرس
            // به جای اینکه بسپاریم به MySQL، خودمان حساب می‌کنیم که "چه زمانی" مرز انقضاست
            $now = $this->get_wp_now_datetime();
            $cutoff_time = $now->sub(new DateInterval('PT' . $expire . 'S'))->format('Y-m-d H:i:s');

            error_log("======================================");
            error_log("🗑️ OTP CLEANUP STARTED (Timezone Fix)");
            error_log("======================================");
            error_log("ℹ️ delete_expired_otps: Expire threshold: {$expire}s");
            error_log("ℹ️ delete_expired_otps: Deleting codes created before: {$cutoff_time}");

            // ✅ پیدا کردن کدهای منقضی (شرط ساده‌تر: created_at کوچکتر از زمان cutoff)
            // دستور TIMESTAMPDIFF رو حذف کردیم چون باعث تداخل میشه
            global $wpdb;
            $expired_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, phone_number, code, created_at, verified
             FROM {$this->table} 
             WHERE created_at < %s",
                $cutoff_time
            ));

            if ($wpdb->last_error) {
                error_log("❌ delete_expired_otps: Database error in SELECT - {$wpdb->last_error}");
            }

            if (empty($expired_rows)) {
                error_log("ℹ️ delete_expired_otps: No expired codes found");
                error_log("======================================");
                return 0;
            }

            error_log("🗑️ delete_expired_otps: Found " . count($expired_rows) . " expired OTP(s)");

            // ✅ حذف دسته‌جمعی با شرط زمانی PHP
            $deleted = $this->repo->delete_expired_before($cutoff_time);

            if ($deleted === false) {
                error_log("❌ delete_expired_otps: Failed to delete expired OTPs - DB Error: {$wpdb->last_error}");
                error_log("======================================");
                return 0;
            }

            error_log("✅ delete_expired_otps: COMPLETE - {$deleted} expired code(s) deleted");
            error_log("======================================");

            return $deleted;
        } catch (Exception $e) {
            error_log("❌ delete_expired_otps: EXCEPTION - " . $e->getMessage());
            return 0;
        }
    }

    /**
     * ✅ حذف همه OTP های یک شماره
     */
    public function delete_user_otps($phone_number)
    {
        try {
            $deleted = $this->repo->delete_by_phone($phone_number);

            if ($deleted === false) {
                global $wpdb;
                error_log("❌ delete_user_otps: Failed to delete OTPs for {$phone_number} - DB Error: {$wpdb->last_error}");
                return false;
            }

            if ($deleted > 0) {
                error_log("🗑️ delete_user_otps: Deleted {$deleted} OTP(s) for {$phone_number}");
            } else {
                error_log("ℹ️ delete_user_otps: No OTPs found for {$phone_number}");
            }

            return $deleted;
        } catch (Exception $e) {
            error_log("❌ delete_user_otps: EXCEPTION - " . $e->getMessage());
            return false;
        }
    }

    public function get_otp_length()
    {
        $settings = get_option('otp_verifier_settings', []);
        $length = isset($settings['otp_length']) ? absint($settings['otp_length']) : 6;
        $final_length = min(max($length, 4), 6);

        if ($length !== $final_length) {
            error_log("ℹ️ get_otp_length: Adjusted from {$length} to {$final_length} (range: 4-6)");
        }

        return $final_length;
    }

    public function get_otp_expire()
    {
        $settings = get_option('otp_verifier_settings', []);
        $expire = isset($settings['otp_expire']) ? absint($settings['otp_expire']) : 120;
        error_log("ℹ️ get_otp_expire: {$expire} seconds");
        return $expire;
    }

    public function get_max_attempts()
    {
        return $this->max_verify_attempts;
    }

    public function get_remaining_attempts($phone_number)
    {
        try {
            $attempt_count = $this->repo->get_attempt_count($phone_number);
            global $wpdb;
            if ($wpdb->last_error) {
                error_log("❌ get_remaining_attempts: Database error - {$wpdb->last_error}");
            }

            if ($attempt_count === null) {
                error_log("ℹ️ get_remaining_attempts: No active OTP for {$phone_number}, returning max attempts ({$this->max_verify_attempts})");
                return $this->max_verify_attempts;
            }

            $remaining = max(0, $this->max_verify_attempts - $attempt_count);
            error_log("ℹ️ get_remaining_attempts: Phone {$phone_number} has {$remaining} attempts remaining (used: {$attempt_count}/{$this->max_verify_attempts})");

            return $remaining;
        } catch (Exception $e) {
            error_log("❌ get_remaining_attempts: EXCEPTION - " . $e->getMessage());
            return $this->max_verify_attempts;
        }
    }

    public function can_attempt($phone_number)
    {
        $remaining = $this->get_remaining_attempts($phone_number);
        $can_attempt = $remaining > 0;

        error_log("ℹ️ can_attempt: Phone {$phone_number} - " . ($can_attempt ? "CAN attempt ({$remaining} left)" : "CANNOT attempt (0 left)"));

        return $can_attempt;
    }

    public function get_statistics()
    {
        try {
            $total_codes = $this->repo->count_total();
            $verified_codes = $this->repo->count_verified();
            $expire_seconds = $this->get_otp_expire();
            $cutoff_time = $this->get_wp_now_datetime()
                ->sub(new DateInterval('PT' . $expire_seconds . 'S'))
                ->format('Y-m-d H:i:s');
            $expired_codes = $this->repo->count_expired($cutoff_time);

            global $wpdb;
            if ($wpdb->last_error) {
                error_log("❌ get_statistics: Database error - {$wpdb->last_error}");
            }

            $stats = [
                'total' => (int) $total_codes,
                'verified' => (int) $verified_codes,
                'expired' => (int) $expired_codes,
                'active' => max(0, (int) $total_codes - (int) $expired_codes)
            ];

            error_log("📊 get_statistics: Total={$stats['total']}, Verified={$stats['verified']}, Expired={$stats['expired']}, Active={$stats['active']}");

            return $stats;
        } catch (Exception $e) {
            error_log("❌ get_statistics: EXCEPTION - " . $e->getMessage());
            return [
                'total' => 0,
                'verified' => 0,
                'expired' => 0,
                'active' => 0
            ];
        }
    }

    /**
     * ✅ Cleanup در هنگام deactivation پلاگین
     */
    public static function cleanup_cron()
    {
        try {
            $timestamp = wp_next_scheduled('otp_verifier_cleanup_cron');

            if ($timestamp) {
                $unscheduled = wp_unschedule_event($timestamp, 'otp_verifier_cleanup_cron');

                if ($unscheduled) {
                    error_log("✅ cleanup_cron: OTP Cron unscheduled successfully (was scheduled for " . date('Y-m-d H:i:s', $timestamp) . ")");
                } else {
                    error_log("❌ cleanup_cron: Failed to unschedule OTP Cron");
                }
            } else {
                error_log("ℹ️ cleanup_cron: No OTP Cron was scheduled");
            }
        } catch (Exception $e) {
            error_log("❌ cleanup_cron: EXCEPTION - " . $e->getMessage());
        }
    }

    public static function drop_table()
    {
        global $wpdb;

        try {
            $table_name = $wpdb->prefix . 'otp_verifier_codes';

            $result = $wpdb->query("DROP TABLE IF EXISTS $table_name");

            if ($result === false) {
                error_log("❌ drop_table: Failed to drop table {$table_name} - DB Error: {$wpdb->last_error}");
            } else {
                error_log("✅ drop_table: Table {$table_name} dropped successfully");
            }
        } catch (Exception $e) {
            error_log("❌ drop_table: EXCEPTION - " . $e->getMessage());
        }
    }
}
