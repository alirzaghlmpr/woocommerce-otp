<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once OTP_VERIFIER_PATH . 'includes/Helpers/class-phone-utils.php';

class OTP_Verifier_Digits_Migrator
{
    public function migrate()
    {
        global $wpdb;

        try {
            $users_to_migrate = $wpdb->get_results("
                SELECT u.ID, m1.meta_value as digits_phone_no
                FROM {$wpdb->users} u
                INNER JOIN {$wpdb->usermeta} m1 ON u.ID = m1.user_id AND m1.meta_key = 'digits_phone_no'
                LEFT JOIN {$wpdb->usermeta} m2 ON u.ID = m2.user_id AND m2.meta_key = 'phone_number'
                WHERE m2.meta_value IS NULL
            ");

            if (empty($users_to_migrate)) {
                return [
                    'migrated' => 0,
                    'failed' => 0,
                    'errors' => [],
                    'status' => 'none'
                ];
            }

            $migrated_count = 0;
            $failed_count = 0;
            $errors = [];

            foreach ($users_to_migrate as $user) {
                $digits_phone = $user->digits_phone_no;
                $standard_phone = OTP_Verifier_Phone_Util::from_digits_format($digits_phone);

                if ($standard_phone && preg_match('/^09[0-9]{9}$/', $standard_phone)) {
                    $result = update_user_meta($user->ID, 'phone_number', $standard_phone);

                    if ($result) {
                        $migrated_count++;
                        error_log("✅ Digits Migration: User ID {$user->ID} - {$digits_phone} -> {$standard_phone}");
                    } else {
                        $failed_count++;
                        $errors[] = "User ID {$user->ID}: Failed to update meta";
                        error_log("❌ Digits Migration: Failed to update User ID {$user->ID}");
                    }
                } else {
                    $failed_count++;
                    $errors[] = "User ID {$user->ID}: Invalid phone format ({$digits_phone})";
                    error_log("❌ Digits Migration: Invalid format for User ID {$user->ID} - {$digits_phone}");
                }
            }

            return [
                'migrated' => $migrated_count,
                'failed' => $failed_count,
                'errors' => $errors,
                'status' => 'done'
            ];
        } catch (Exception $e) {
            error_log("❌ Digits Migration Exception: " . $e->getMessage());
            return [
                'migrated' => 0,
                'failed' => 0,
                'errors' => [$e->getMessage()],
                'status' => 'error'
            ];
        }
    }
}
