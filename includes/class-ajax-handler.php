<?php
if (!defined('ABSPATH')) exit;

require_once OTP_VERIFIER_PATH . 'includes/class-otp-handler.php';
require_once OTP_VERIFIER_PATH . 'includes/otp-verifier-helpers.php';
require_once OTP_VERIFIER_PATH . 'includes/Services/class-otp-rate-limiter.php';
require_once OTP_VERIFIER_PATH . 'includes/Services/class-otp-script-enqueuer.php';
require_once OTP_VERIFIER_PATH . 'includes/Helpers/class-phone-utils.php';

class OTP_AJAX_Handler
{
    private $otp_handler;
    private $rate_limiter;
    private $script_enqueuer;

    public function __construct()
    {
        try {
            $this->otp_handler = new OTP_Verifier_Handler();
            $this->rate_limiter = new OTP_Verifier_Rate_Limiter();
            $this->script_enqueuer = new OTP_Verifier_Script_Enqueuer();
            error_log("✅ OTP_AJAX_Handler: Handler initialized successfully");
        } catch (Exception $e) {
            error_log("❌ OTP_AJAX_Handler: Failed to initialize handler - " . $e->getMessage());
            return;
        }

        add_action('wp_ajax_nopriv_send_otp', [$this, 'handle_send_otp']);
        add_action('wp_ajax_send_otp', [$this, 'handle_send_otp']);
        add_action('wp_ajax_nopriv_verify_otp', [$this, 'handle_verify_otp']);
        add_action('wp_ajax_verify_otp', [$this, 'handle_verify_otp']);
        add_action('wp_ajax_nopriv_otp_password_login', [$this, 'handle_password_login']);
        add_action('wp_ajax_otp_password_login', [$this, 'handle_password_login']);

        add_action('wp', [$this, 'setup_scripts']);

        // ✅ پاکسازی transient های منقضی شده
        add_action('otp_verifier_cleanup_cron', [$this, 'cleanup_expired_transients']);

        // ✅ راه‌اندازی Cron برای پاکسازی خودکار
        if (!wp_next_scheduled('otp_verifier_cleanup_cron')) {
            $scheduled = wp_schedule_event(time(), 'hourly', 'otp_verifier_cleanup_cron');
            if ($scheduled === false) {
                error_log("❌ Rate Limit Cleanup Cron: Failed to schedule");
            } else {
                error_log("✅ Rate Limit Cleanup Cron: Scheduled successfully");
            }
        }
    }

    /**
     * ✅ تنظیم اسکریپت‌ها فقط اگر Login اختصاصی فعال باشه
     */
    public function setup_scripts()
    {
        $this->script_enqueuer->setup_scripts();
    }

    public function enqueue_scripts_in_footer()
    {
        $this->script_enqueuer->enqueue_scripts_in_footer();
    }

    /**
     * ✅ اعتبارسنجی شماره موبایل ایرانی
     */
    private function validate_iranian_phone($phone)
    {
        $phone = OTP_Verifier_Phone_Util::sanitize_iranian_phone($phone);
        if ($phone) {
            error_log("✅ validate_iranian_phone: Valid phone - {$phone}");
        }
        return $phone;
    }

    /**
     * ✅ تبدیل شماره موبایل به فرمت Digits (بدون صفر اول + کد کشور)
     * مثال: 09123456789 -> 989123456789
     */
    private function convert_to_digits_format($phone)
    {
        $phone = OTP_Verifier_Phone_Util::to_digits_format($phone);
        error_log("ℹ️ convert_to_digits_format: Converted to Digits format - {$phone}");
        return $phone;
    }

    /**
     * ✅ تبدیل شماره Digits به فرمت استاندارد ایرانی (با صفر اول)
     * مثال: 989123456789 -> 09123456789
     */
    private function convert_from_digits_format($digits_phone)
    {
        $phone = OTP_Verifier_Phone_Util::from_digits_format($digits_phone);
        error_log("ℹ️ convert_from_digits_format: Converted from Digits format - {$phone}");
        return $phone;
    }

    /**
     * ✅ Rate Limiting برای شماره (3 OTP در 5 دقیقه)
     */
    private function check_rate_limit($phone)
    {
        return $this->rate_limiter->check_phone_limit($phone);
    }

    /**
     * ✅ Rate Limiting برای IP (10 درخواست در 5 دقیقه)
     */
    private function check_ip_rate_limit()
    {
        return $this->rate_limiter->check_ip_limit();
    }

    /**
     * ✅ پاکسازی Transient های منقضی شده (هر 1 ساعت یکبار)
     */
    public function cleanup_expired_transients()
    {
        $this->rate_limiter->cleanup_expired_transients();
    }

    /**
     * ✅ ورود با نام کاربری/ایمیل و رمز عبور
     */
    public function handle_password_login()
    {
        try {
            if (!check_ajax_referer('otp_login_nonce', 'security', false)) {
                wp_send_json_error(['message' => 'درخواست نامعتبر است.']);
                return;
            }

            $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';

            if (empty($username) || empty($password)) {
                wp_send_json_error(['message' => 'نام کاربری و رمز عبور الزامی است.']);
                return;
            }

            $user = wp_authenticate($username, $password);

            if (is_wp_error($user)) {
                wp_send_json_error(['message' => 'نام کاربری یا رمز عبور اشتباه است.']);
                return;
            }

            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID);

            $redirect = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : home_url('/');

            wp_send_json_success([
                'message' => 'ورود با موفقیت انجام شد.',
                'redirect' => $redirect
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'خطای سیستمی رخ داده است.']);
        }
    }

    /**
     * ✅ ارسال OTP
     */
    public function handle_send_otp()
    {
        error_log("======================================");
        error_log("📤 SEND OTP REQUEST STARTED");
        error_log("======================================");

        try {
            // بررسی nonce
            if (!check_ajax_referer('otp_login_nonce', 'security', false)) {
                error_log("❌ handle_send_otp: Invalid nonce");
                wp_send_json_error(['message' => 'درخواست نامعتبر است.']);
                return;
            }

            $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            $login_only = !empty($_POST['login_only']);
            $raw_username = isset($_POST['username']) ? wp_unslash($_POST['username']) : '';
            $username = sanitize_user($raw_username, true);
            error_log("ℹ️ handle_send_otp: Raw phone input - {$phone}");

            $phone = $this->validate_iranian_phone($phone);
            if (!$phone) {
                error_log("❌ handle_send_otp: Phone validation failed");
                wp_send_json_error([
                    'message' => 'شماره موبایل معتبر نیست. فرمت صحیح: 09xxxxxxxxx'
                ]);
                return;
            }

            // اگر در حالت ثبت‌نام هستیم و نام کاربری ارسال شده اما بعد از sanitize خالی شده
            if (!$login_only && !empty($raw_username) && empty($username)) {
                wp_send_json_error(['message' => 'نام کاربری وارد شده معتبر نیست. لطفاً از حروف و اعداد لاتین استفاده کنید.']);
                return;
            }

            if (!$login_only && !empty($username)) {
                $existing_user = get_user_by('login', $username);
                if ($existing_user) {
                    $existing_phone = get_user_meta($existing_user->ID, 'phone_number', true);
                    $existing_digits = get_user_meta($existing_user->ID, 'digits_phone_no', true);
                    $digits_phone = $this->convert_to_digits_format($phone);

                    // اگر نام کاربری قبلاً با شماره دیگری ثبت شده است، خطا بده
                    if (!empty($existing_phone) && $existing_phone !== $phone) {
                        wp_send_json_error(['message' => 'این نام کاربری قبلاً با شماره دیگری ثبت شده است.']);
                        return;
                    }
                    if (!empty($existing_digits) && $existing_digits !== $digits_phone) {
                        wp_send_json_error(['message' => 'این نام کاربری قبلاً با شماره دیگری ثبت شده است.']);
                        return;
                    }
                }
            }

            // جلوگیری از ارسال کد برای شماره‌ای که قبلاً با نام کاربری دیگری ثبت شده
            if (!$login_only) {
                $existing_phone_user = get_users([
                    'meta_key' => 'phone_number',
                    'meta_value' => $phone,
                    'number' => 1,
                    'count_total' => false
                ]);

                if (empty($existing_phone_user)) {
                    $digits_phone = $this->convert_to_digits_format($phone);
                    $existing_phone_user = get_users([
                        'meta_key' => 'digits_phone_no',
                        'meta_value' => $digits_phone,
                        'number' => 1,
                        'count_total' => false
                    ]);
                }

                if (!empty($existing_phone_user)) {
                    $existing_login = $existing_phone_user[0]->user_login;
                    // اگر کاربری یافت شد و نام کاربری ارسالی متفاوت است، خطا بده
                    if (empty($username) || $existing_login !== $username) {
                        wp_send_json_error(['message' => 'این شماره قبلاً با نام کاربری دیگری ثبت شده است.']);
                        return;
                    }
                }
            }

            // اگر فقط برای ورود با موبایل است، باید حسابی با این شماره وجود داشته باشد
            if ($login_only) {
                $existing_user = get_users([
                    'meta_key' => 'phone_number',
                    'meta_value' => $phone,
                    'number' => 1,
                    'count_total' => false
                ]);

                if (empty($existing_user)) {
                    // بررسی فرمت Digits
                    $digits_phone = $this->convert_to_digits_format($phone);
                    $existing_user = get_users([
                        'meta_key' => 'digits_phone_no',
                        'meta_value' => $digits_phone,
                        'number' => 1,
                        'count_total' => false
                    ]);
                }

                if (empty($existing_user)) {
                    wp_send_json_error(['message' => 'حسابی با این شماره یافت نشد.']);
                    return;
                }
            }

            // ✅ Rate Limiting
            if (!$this->check_rate_limit($phone)) {
                error_log("❌ handle_send_otp: Rate limit exceeded for {$phone}");
                wp_send_json_error([
                    'message' => 'شما بیش از حد مجاز درخواست ارسال کرده‌اید. لطفاً 5 دقیقه صبر کنید.'
                ]);
                return;
            }

            if (!$this->check_ip_rate_limit()) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                error_log("❌ handle_send_otp: IP rate limit exceeded for {$ip}");
                wp_send_json_error([
                    'message' => 'تعداد درخواست‌های شما از این IP بیش از حد است.'
                ]);
                return;
            }

            $otp = $this->otp_handler->generate_otp($phone);
            if (!$otp) {
                error_log("❌ handle_send_otp: Failed to generate OTP for {$phone}");
                wp_send_json_error(['message' => 'خطا در تولید کد تایید.']);
                return;
            }

            error_log("✅ handle_send_otp: OTP generated - Phone: {$phone}, Code: {$otp}");

            // ارسال پیامک
            $settings = get_option('otp_verifier_settings', []);
            $gateway_name = $settings['gateway'] ?? 'melipayamak';
            $gateway = otp_verifier_get_sms_gateway($gateway_name, $settings);

            error_log("ℹ️ handle_send_otp: Using gateway - {$gateway_name}");

            $sms_result = null;
            $sms_data = [];
            if ($gateway_name === 'melipayamak') {
                $sms_data = [$otp];
            } else {
                $sms_data = [$settings['otp_var_name'] => $otp];
            }


            $sms_result = $gateway->send_sms($phone, $sms_data, $settings['pattern']);

            if (!$sms_result) {
                error_log("❌ handle_send_otp: Unknown gateway or no result - {$gateway_name}");
                wp_send_json_error(['message' => 'درگاه پیامکی پیکربندی نشده است.']);
                return;
            }

            if (!$sms_result->success) {
                error_log("❌ handle_send_otp: SMS failed - Gateway: {$gateway_name}, Phone: {$phone}");
                error_log("❌ SMS Error Details: " . print_r($sms_result, true));
                wp_send_json_error([
                    'message' => 'خطا در ارسال پیامک. لطفاً دوباره تلاش کنید.'
                ]);
                return;
            }

            error_log("✅ handle_send_otp: SMS sent successfully - Gateway: {$gateway_name}, Phone: {$phone}");
            error_log("======================================");

            wp_send_json_success([
                'message' => 'کد تایید به شماره ' . substr($phone, 0, 4) . '***' . substr($phone, -2) . ' ارسال شد.'
            ]);
        } catch (Exception $e) {
            error_log("❌ handle_send_otp: EXCEPTION - " . $e->getMessage());
            error_log("❌ Stack trace: " . $e->getTraceAsString());
            error_log("======================================");
            wp_send_json_error(['message' => 'خطای سیستمی رخ داده است.']);
        }
    }

    /**
     * ✅ بررسی OTP و ورود/ثبت نام کاربر
     */
    public function handle_verify_otp()
    {
        error_log("======================================");
        error_log("🔐 VERIFY OTP REQUEST STARTED");
        error_log("======================================");

        try {
            // بررسی nonce
            if (!check_ajax_referer('otp_login_nonce', 'security', false)) {
                error_log("❌ handle_verify_otp: Invalid nonce");
                wp_send_json_error(['message' => 'درخواست نامعتبر است.']);
                return;
            }

            $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            $otp_code = isset($_POST['otp']) ? sanitize_text_field($_POST['otp']) : '';
            $raw_username = isset($_POST['username']) ? wp_unslash($_POST['username']) : '';
            $username = sanitize_user($raw_username, true);
            $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $login_only = !empty($_POST['login_only']);

            error_log("ℹ️ handle_verify_otp: Raw input - Phone: {$phone}, OTP: {$otp_code}");

            $phone = $this->validate_iranian_phone($phone);
            if (!$phone) {
                error_log("❌ handle_verify_otp: Invalid phone number");
                wp_send_json_error(['message' => 'شماره موبایل معتبر نیست.']);
                return;
            }

            // اگر نام کاربری ارسال شده اما بعد از sanitize خالی شد، خطا بده
            if (!empty($raw_username) && empty($username)) {
                wp_send_json_error(['message' => 'نام کاربری وارد شده معتبر نیست. لطفاً از حروف و اعداد لاتین استفاده کنید.']);
                return;
            }

            // بررسی اینکه اگر نام کاربری وجود دارد، با شماره فعلی یکسان باشد
            $existing_user_by_username = (!empty($username)) ? get_user_by('login', $username) : false;
            if ($existing_user_by_username) {
                $existing_phone_for_username = get_user_meta($existing_user_by_username->ID, 'phone_number', true);
                if (!empty($existing_phone_for_username) && $existing_phone_for_username !== $phone) {
                    wp_send_json_error(['message' => 'این نام کاربری قبلاً با شماره دیگری ثبت شده است.']);
                    return;
                }
            }

            if (empty($otp_code)) {
                error_log("❌ handle_verify_otp: Empty OTP code");
                wp_send_json_error(['message' => 'کد تایید وارد نشده است.']);
                return;
            }

            $is_valid = $this->otp_handler->verify_otp($phone, $otp_code);

            if (!$is_valid) {
                error_log("❌ handle_verify_otp: OTP verification failed - Phone: {$phone}, Code: {$otp_code}");
                wp_send_json_error(['message' => 'کد تایید اشتباه یا منقضی شده است.']);
                return;
            }

            error_log("✅ handle_verify_otp: OTP verified successfully - Phone: {$phone}");

            // بررسی کاربر موجود با phone_number
            $user = get_users([
                'meta_key' => 'phone_number',
                'meta_value' => $phone,
                'number' => 1,
                'count_total' => false
            ]);

            if (!empty($user)) {
                $user_id = $user[0]->ID;

                // اگر نام کاربری ورودی با کاربر موجود همخوانی ندارد، اجازه نده
                if (!empty($username) && $username !== $user[0]->user_login) {
                    wp_send_json_error(['message' => 'این شماره قبلاً با نام کاربری دیگری ثبت شده است.']);
                    return;
                }

                error_log("✅ handle_verify_otp: Existing user found with phone_number - Phone: {$phone}, User ID: {$user_id}, Username: {$user[0]->user_login}");
            } else {
                // بررسی کاربران Digits (شماره بدون صفر اول و با کد کشور)
                $digits_phone = $this->convert_to_digits_format($phone);
                error_log("ℹ️ handle_verify_otp: Searching for Digits user - Digits format: {$digits_phone}");

                $digits_user = get_users([
                    'meta_key' => 'digits_phone_no',
                    'meta_value' => $digits_phone,
                    'number' => 1,
                    'count_total' => false
                ]);

                if (!empty($digits_user)) {
                    $user_id = $digits_user[0]->ID;
                    error_log("✅ handle_verify_otp: Existing Digits user found - Digits Phone: {$digits_phone}, User ID: {$user_id}, Username: {$digits_user[0]->user_login}");

                    // مهاجرت داده از Digits به فرمت جدید
                    $meta_result = update_user_meta($user_id, 'phone_number', $phone);
                    if ($meta_result) {
                        error_log("✅ handle_verify_otp: Migrated Digits user - Added phone_number meta: {$phone} for User ID: {$user_id}");
                    } else {
                        error_log("⚠️ handle_verify_otp: Failed to migrate Digits user - User ID: {$user_id}");
                    }
                    if (!empty($username) && $username !== $digits_user[0]->user_login) {
                        wp_send_json_error(['message' => 'این شماره قبلاً با نام کاربری دیگری ثبت شده است.']);
                        return;
                    }
                } else {
                    if ($login_only) {
                        error_log("❌ handle_verify_otp: Login-only flow, user not found for phone {$phone}");
                        wp_send_json_error(['message' => 'حسابی با این شماره یافت نشد.']);
                        return;
                    }

                    // کاربر جدید - ایجاد حساب
                    error_log("ℹ️ handle_verify_otp: No existing user found (checked both phone_number and digits_phone_no), creating new account - Phone: {$phone}");

                    $new_username = !empty($username) ? $username : $phone;

                    // اگر نام کاربری تکراری بود
                    if (username_exists($new_username)) {
                        error_log("❌ handle_verify_otp: Username already exists - {$new_username}");
                        wp_send_json_error(['message' => 'این نام کاربری قبلاً ثبت شده است.']);
                        return;
                    }

                    // اگر شماره موبایل برای کاربر دیگری ثبت شده باشد (چک دوباره برای اطمینان)
                    $existing_phone_user = get_users([
                        'meta_key' => 'phone_number',
                        'meta_value' => $phone,
                        'number' => 1,
                        'count_total' => false
                    ]);
                    if (!empty($existing_phone_user)) {
                        wp_send_json_error(['message' => 'این شماره قبلاً ثبت شده است.']);
                        return;
                    }

                    $user_password = !empty($password) ? $password : wp_generate_password(16, true, true);
                    $user_email = is_email($raw_username) ? sanitize_email($raw_username) : $phone . '@example.com';

                    $user_id = wp_create_user(
                        $new_username,
                        $user_password,
                        $user_email
                    );

                    if (is_wp_error($user_id)) {
                        error_log("❌ handle_verify_otp: User creation FAILED - Phone: {$phone}, Error: " . $user_id->get_error_message());
                        wp_send_json_error(['message' => 'خطا در ایجاد حساب کاربری.']);
                        return;
                    }

                    // تنظیم نقش کاربر به customer (برای ووکامرس)
                    $user = new WP_User($user_id);
                    $user->set_role('customer');
                    error_log("✅ handle_verify_otp: User role set to 'customer' for User ID: {$user_id}");

                    // ذخیره شماره تلفن
                    $meta_result = update_user_meta($user_id, 'phone_number', $phone);
                    if (!$meta_result) {
                        error_log("⚠️ handle_verify_otp: Failed to update phone_number meta for User ID: {$user_id}");
                    }

                    error_log("✅ handle_verify_otp: New user created successfully - Phone: {$phone}, User ID: {$user_id}, Role: customer");
                }
            }

            // لاگین کاربر
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);

            error_log("✅ handle_verify_otp: User logged in successfully - User ID: {$user_id}, Phone: {$phone}");
            error_log("======================================");

            $redirect = function_exists('wc_get_page_permalink')
                ? wc_get_page_permalink('myaccount')
                : home_url('/');

            wp_send_json_success([
                'message' => 'ورود موفقیت‌آمیز بود.',
                'redirect' => $redirect
            ]);
        } catch (Exception $e) {
            error_log("❌ handle_verify_otp: EXCEPTION - " . $e->getMessage());
            error_log("❌ Stack trace: " . $e->getTraceAsString());
            error_log("======================================");
            wp_send_json_error(['message' => 'خطای سیستمی رخ داده است.']);
        }
    }

    /**
     * ✅ پاکسازی دستی Rate Limit یک شماره (برای دیباگ)
     */
    public function clear_phone_rate_limit($phone)
    {
        return $this->rate_limiter->clear_phone_limit($phone);
    }

    /**
     * ✅ پاکسازی دستی Rate Limit یک IP (برای دیباگ)
     */
    public function clear_ip_rate_limit($ip)
    {
        return $this->rate_limiter->clear_ip_limit($ip);
    }
}
