<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier
{
    public function __construct()
    {
        // می‌تونیم فایل‌های جانبی یا helperها رو اینجا include کنیم
    }

    public function run()
    {
        // hookها و فانکشن‌های اصلی اینجا ثبت می‌شن
        add_action('init', [$this, 'init_hooks']);
        add_action('after_switch_theme', [$this, 'flush_rewrite']); // ری‌فلش ری‌راوایت‌ها بعد از فعال‌سازی
    }

    /**
     * flush rewrite rules بعد از تغییر تنظیمات یا فعالسازی
     */
    public function flush_rewrite()
    {
        flush_rewrite_rules();
    }

    /**
     * سایر hookها (فعلاً فقط تست)
     */
    public function init_hooks()
    {
        new OTP_Verifier_Settings_Page();

        // اضافه کردن ستون شماره تلفن به لیست کاربران
        add_filter('manage_users_columns', [$this, 'add_phone_column']);
        add_filter('manage_users_custom_column', [$this, 'show_phone_column_content'], 10, 3);
        add_filter('manage_users_sortable_columns', [$this, 'make_phone_column_sortable']);
        add_action('pre_get_users', [$this, 'phone_column_orderby']);
    }

    /**
     * اضافه کردن ستون شماره تلفن به جدول کاربران
     */
    public function add_phone_column($columns)
    {
        $columns['phone_number'] = 'شماره تلفن';
        return $columns;
    }

    /**
     * نمایش محتوای ستون شماره تلفن
     */
    public function show_phone_column_content($value, $column_name, $user_id)
    {
        if ($column_name === 'phone_number') {
            // ابتدا بررسی phone_number
            $phone = get_user_meta($user_id, 'phone_number', true);

            // اگر نبود، بررسی digits_phone_no
            if (empty($phone)) {
                $digits_phone = get_user_meta($user_id, 'digits_phone_no', true);
                if (!empty($digits_phone)) {
                    // تبدیل فرمت Digits به استاندارد
                    $phone = $this->convert_digits_to_standard($digits_phone);
                    if ($phone) {
                        return esc_html($phone) . ' <span style="color: #999; font-size: 11px;">(Digits)</span>';
                    }
                }
            }

            return !empty($phone) ? esc_html($phone) : '<span style="color: #ccc;">—</span>';
        }

        return $value;
    }

    /**
     * قابلیت مرتب‌سازی برای ستون شماره تلفن
     */
    public function make_phone_column_sortable($columns)
    {
        $columns['phone_number'] = 'phone_number';
        return $columns;
    }

    /**
     * منطق مرتب‌سازی ستون شماره تلفن
     */
    public function phone_column_orderby($query)
    {
        if (!is_admin()) {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'phone_number') {
            $query->set('meta_key', 'phone_number');
            $query->set('orderby', 'meta_value');
        }
    }

    /**
     * تبدیل شماره Digits به فرمت استاندارد ایرانی
     */
    private function convert_digits_to_standard($digits_phone)
    {
        // حذف کاراکترهای غیرعددی
        $digits_phone = preg_replace('/[^0-9]/', '', $digits_phone);

        // اگر با 98 شروع می‌شه، حذفش کن و 0 اضافه کن
        if (substr($digits_phone, 0, 2) === '98') {
            $phone = '0' . substr($digits_phone, 2);
        }
        // اگر با 0 شروع می‌شه، همون رو برگردون
        elseif (substr($digits_phone, 0, 1) === '0') {
            $phone = $digits_phone;
        }
        // اگر هیچکدوم نبود، 0 اضافه کن
        else {
            $phone = '0' . $digits_phone;
        }

        // اعتبارسنجی فرمت نهایی
        if (preg_match('/^09[0-9]{9}$/', $phone)) {
            return $phone;
        }

        return false;
    }

}
