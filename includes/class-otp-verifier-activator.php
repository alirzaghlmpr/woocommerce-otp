<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Activator
{
    /**
     * نام آپشنی که نسخه‌ی ساختار دیتابیس را نگه می‌دارد.
     */
    const DB_VERSION_OPTION = 'otp_verifier_db_version';

    /**
     * ساخت/به‌روزرسانی جدول با dbDelta.
     * dbDelta علاوه بر ساخت، ستون‌های تغییر یافته (مثل بزرگ‌تر شدن code) را نیز ALTER می‌کند.
     */
    public static function create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'otp_verifier_codes';
        $charset_collate = $wpdb->get_charset_collate();

        // code به‌صورت هش (HMAC-SHA256 = ۶۴ کاراکتر) ذخیره می‌شود، پس varchar(255).
        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            phone_number varchar(15) NOT NULL,
            code varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            verified tinyint(1) DEFAULT 0,
            attempt_count tinyint(2) DEFAULT 0,
            PRIMARY KEY (id),
            KEY phone_number (phone_number),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, OTP_VERIFIER_VERSION);
    }

    public static function activate()
    {
        self::create_table();

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

    /**
     * اجرا در هر بار بارگذاری: اگر نسخه‌ی دیتابیس قدیمی باشد (مثلاً افزونه با
     * آپلود فایل به‌روزرسانی شده و فعال‌سازی مجدد انجام نشده)، ساختار را مهاجرت می‌دهد.
     * این کار از Truncate شدن هش در ستون کوچک قدیمی (varchar(10)) جلوگیری می‌کند.
     */
    public static function maybe_upgrade()
    {
        if (get_option(self::DB_VERSION_OPTION) !== OTP_VERIFIER_VERSION) {
            self::create_table();
        }
    }
}
