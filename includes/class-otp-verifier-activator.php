<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Activator
{
    public static function activate()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'otp_verifier_codes';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            phone_number varchar(15) NOT NULL,
            code varchar(10) NOT NULL,
            created_at datetime NOT NULL,
            verified tinyint(1) DEFAULT 0,
            attempt_count tinyint(2) DEFAULT 0,
            PRIMARY KEY (id),
            KEY phone_number (phone_number),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // تنظیمات پیش‌فرض افزونه
        if (!get_option('otp_verifier_settings')) {
            add_option('otp_verifier_settings', [
                'active_login'  => true,
                'gateway'       => 'melipayamak',
                'username'      => '',
                'password'      => '',
                'api_key'       => '',
                'line_number' => '',
                'otp_expire'    => 60, // مدت اعتبار به ثانیه
                'pattern' => '',
                'otp_var_name' => '',
                'otp_length' => 4,
                'login_bg_image_url' => ''
            ]);
        }
    }
}
