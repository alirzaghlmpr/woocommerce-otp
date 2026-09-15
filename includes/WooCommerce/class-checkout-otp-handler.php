<?php
if (!defined('ABSPATH')) exit;

require_once OTP_VERIFIER_PATH . 'includes/class-otp-handler.php';
require_once OTP_VERIFIER_PATH . 'includes/otp-verifier-helpers.php';
require_once OTP_VERIFIER_PATH . 'includes/Services/class-otp-rate-limiter.php';
require_once OTP_VERIFIER_PATH . 'includes/Helpers/class-phone-utils.php';

/**
 * تایید شماره موبایل در تسویه‌حساب ووکامرس (Checkout).
 *
 * فقط برای چک‌اوت کلاسیک (شورت‌کد [woocommerce_checkout]) ساخته شده است.
 * چک‌اوت مبتنی بر بلوک‌های ووکامرس (Cart & Checkout Blocks) از این هوک‌های
 * کلاسیک استفاده نمی‌کند و نیاز به یکپارچه‌سازی جداگانه با Store API دارد؛
 * در صورت تشخیص بلوک، این کلاس غیرفعال می‌ماند و در لاگ هشدار ثبت می‌کند.
 */
class OTP_Verifier_Checkout_Handler
{
    const SESSION_KEY = 'otp_verifier_checkout_verified_phone';

    /** @var OTP_Verifier_Handler|null */
    private $otp_handler = null;
    private $rate_limiter;

    public function __construct()
    {
        $this->rate_limiter = new OTP_Verifier_Rate_Limiter();

        add_action('wp_ajax_otp_checkout_send', [$this, 'handle_send_otp']);
        add_action('wp_ajax_nopriv_otp_checkout_send', [$this, 'handle_send_otp']);
        add_action('wp_ajax_otp_checkout_verify', [$this, 'handle_verify_otp']);
        add_action('wp_ajax_nopriv_otp_checkout_verify', [$this, 'handle_verify_otp']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('woocommerce_after_checkout_billing_form', [$this, 'render_inline_widget']);
        add_action('woocommerce_before_checkout_form', [$this, 'render_gate_overlay'], 5);
        add_action('woocommerce_checkout_process', [$this, 'enforce_verification']);
    }

    /**
     * ساخت تنبل (lazy) هندلر اصلی OTP. برای جلوگیری از ثبت تکراری هوک کرون
     * پاکسازی (otp_verifier_otp_cleanup) در هر بارگذاری صفحه، این آبجکت فقط
     * وقتی واقعاً برای ارسال/تایید کد لازم است ساخته می‌شود.
     */
    private function get_otp_handler()
    {
        if ($this->otp_handler === null) {
            $this->otp_handler = new OTP_Verifier_Handler();
        }
        return $this->otp_handler;
    }

    private function is_enabled()
    {
        $settings = get_option('otp_verifier_settings', []);
        return !empty($settings['checkout_verify_enabled']);
    }

    private function get_mode()
    {
        $settings = get_option('otp_verifier_settings', []);
        $mode = $settings['checkout_verify_mode'] ?? 'inline';
        return in_array($mode, ['inline', 'gate'], true) ? $mode : 'inline';
    }

    private function get_verified_phone()
    {
        if (function_exists('WC') && WC()->session) {
            return (string) WC()->session->get(self::SESSION_KEY, '');
        }
        return '';
    }

    private function set_verified_phone($phone)
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_KEY, $phone);
        }
    }

    /**
     * آیا صفحه تسویه‌حساب فعلی از چک‌اوت بلوکی (React) استفاده می‌کند؟
     * هوک‌های کلاسیک این کلاس روی چک‌اوت بلوکی اجرا نمی‌شوند.
     */
    private function is_block_checkout()
    {
        if (!function_exists('wc_get_page_id') || !function_exists('has_block')) {
            return false;
        }
        $checkout_page_id = wc_get_page_id('checkout');
        return $checkout_page_id && has_block('woocommerce/checkout', $checkout_page_id);
    }

    private function default_checkout_phone()
    {
        if (function_exists('WC') && WC()->customer) {
            $phone = WC()->customer->get_billing_phone();
            if ($phone) {
                return $phone;
            }
        }
        if (is_user_logged_in()) {
            $phone = get_user_meta(get_current_user_id(), 'phone_number', true);
            if ($phone) {
                return $phone;
            }
        }
        return '';
    }

    public function enqueue_assets()
    {
        if (!$this->is_enabled() || !function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
            return;
        }
        if ($this->is_block_checkout()) {
            otp_verifier_log('⚠️ Checkout OTP: چک‌اوت بلوکی (WooCommerce Blocks) تشخیص داده شد - هوک‌های کلاسیک اجرا نمی‌شوند، قابلیت غیرفعال است.');
            return;
        }

        wp_enqueue_style(
            'otp-checkout-style',
            OTP_VERIFIER_URL . 'templates/assets/css/checkout-otp.css',
            [],
            OTP_VERIFIER_VERSION
        );

        wp_enqueue_script(
            'otp-checkout-script',
            OTP_VERIFIER_URL . 'templates/assets/js/checkout-otp.js',
            ['jquery'],
            OTP_VERIFIER_VERSION,
            true
        );

        $settings = get_option('otp_verifier_settings', []);
        $otp_length = isset($settings['otp_length']) ? absint($settings['otp_length']) : 6;
        $otp_expire = isset($settings['otp_expire']) ? absint($settings['otp_expire']) : 120;

        wp_localize_script('otp-checkout-script', 'otp_checkout_config', [
            'ajaxurl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('otp_checkout_nonce'),
            'mode'           => $this->get_mode(),
            'otp_length'     => $otp_length,
            'expire'         => $otp_expire,
            'verified_phone' => $this->get_verified_phone(),
            'default_phone'  => $this->default_checkout_phone(),
            'messages'       => [
                'invalid_phone' => 'شماره موبایل معتبر نیست. فرمت صحیح: 09xxxxxxxxx',
                'otp_sent'      => 'کد تایید ارسال شد.',
                'otp_invalid'   => 'کد وارد شده صحیح نیست یا منقضی شده.',
                'otp_verified'  => 'شماره موبایل با موفقیت تایید شد.',
                'rate_limit'    => 'تعداد درخواست‌های شما بیش از حد است. لطفاً کمی صبر کنید.',
                'sms_failed'    => 'خطا در ارسال پیامک. لطفاً دوباره تلاش کنید.',
            ],
        ]);
    }

    /**
     * حالت «داخل فرم»: ویجت کنار فیلد استاندارد شماره موبایل ووکامرس رندر می‌شود
     * (بدون افزودن فیلد جدید - از همان billing_phone استفاده می‌شود).
     */
    public function render_inline_widget()
    {
        if (!$this->is_enabled() || $this->get_mode() !== 'inline' || $this->is_block_checkout()) {
            return;
        }
?>
        <div id="otp-checkout-inline" class="otp-checkout-widget">
            <div class="otp-checkout-widget__row">
                <button type="button" id="otp-checkout-send-btn" class="otp-checkout-btn">ارسال کد تایید شماره موبایل</button>
                <span id="otp-checkout-status" class="otp-checkout-status"></span>
            </div>
            <div id="otp-checkout-code-row" class="otp-checkout-widget__row otp-checkout-hidden">
                <input type="text" inputmode="numeric" pattern="[0-9]*" id="otp-checkout-code" class="otp-checkout-code-input" placeholder="کد تایید" maxlength="6" autocomplete="one-time-code">
                <button type="button" id="otp-checkout-verify-btn" class="otp-checkout-btn">تایید</button>
                <button type="button" id="otp-checkout-resend-btn" class="otp-checkout-link" disabled>ارسال مجدد</button>
            </div>
            <p id="otp-checkout-msg" class="otp-checkout-msg otp-checkout-hidden"></p>
        </div>
<?php
    }

    /**
     * حالت «قفل کامل»: پیش از نمایش فرم تسویه‌حساب، پنجره تایید شماره موبایل
     * نمایش داده می‌شود. اگر شماره‌ای از قبل در این session تایید شده، رندر نمی‌شود.
     */
    public function render_gate_overlay()
    {
        if (!$this->is_enabled() || $this->get_mode() !== 'gate' || $this->is_block_checkout()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (!empty($this->get_verified_phone())) {
            return;
        }
?>
        <div id="otp-checkout-gate" class="otp-checkout-gate">
            <div class="otp-checkout-gate__card">
                <h3 class="otp-checkout-gate__title">تایید شماره موبایل</h3>
                <p class="otp-checkout-gate__desc">برای مشاهده و تکمیل فرم تسویه‌حساب، لطفاً ابتدا شماره موبایل خود را تایید کنید.</p>

                <div id="otp-checkout-gate-phone-row" class="otp-checkout-widget__row">
                    <input type="tel" inputmode="numeric" maxlength="11" id="otp-checkout-gate-phone" class="otp-checkout-code-input" placeholder="شماره موبایل">
                    <button type="button" id="otp-checkout-gate-send-btn" class="otp-checkout-btn">ارسال کد</button>
                </div>

                <div id="otp-checkout-gate-code-row" class="otp-checkout-widget__row otp-checkout-hidden">
                    <input type="text" inputmode="numeric" pattern="[0-9]*" id="otp-checkout-gate-code" class="otp-checkout-code-input" placeholder="کد تایید" maxlength="6" autocomplete="one-time-code">
                    <button type="button" id="otp-checkout-gate-verify-btn" class="otp-checkout-btn">تایید</button>
                    <button type="button" id="otp-checkout-gate-resend-btn" class="otp-checkout-link" disabled>ارسال مجدد</button>
                </div>

                <p id="otp-checkout-gate-msg" class="otp-checkout-msg otp-checkout-hidden"></p>
            </div>
        </div>
<?php
    }

    public function handle_send_otp()
    {
        try {
            if (!check_ajax_referer('otp_checkout_nonce', 'security', false)) {
                wp_send_json_error(['message' => 'درخواست نامعتبر است.']);
                return;
            }
            if (!$this->is_enabled()) {
                wp_send_json_error(['message' => 'این قابلیت فعال نیست.']);
                return;
            }

            $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
            $phone = OTP_Verifier_Phone_Util::sanitize_iranian_phone($phone);

            if (!$phone) {
                wp_send_json_error(['message' => 'شماره موبایل معتبر نیست. فرمت صحیح: 09xxxxxxxxx']);
                return;
            }

            if (!$this->rate_limiter->check_phone_limit($phone)) {
                wp_send_json_error(['message' => 'شما بیش از حد مجاز درخواست ارسال کرده‌اید. لطفاً 5 دقیقه صبر کنید.']);
                return;
            }
            if (!$this->rate_limiter->check_ip_limit()) {
                wp_send_json_error(['message' => 'تعداد درخواست‌های شما از این IP بیش از حد است.']);
                return;
            }

            $otp = $this->get_otp_handler()->generate_otp($phone);
            if (!$otp) {
                wp_send_json_error(['message' => 'خطا در تولید کد تایید.']);
                return;
            }

            $settings = get_option('otp_verifier_settings', []);
            $gateway_name = $settings['gateway'] ?? 'melipayamak';
            $gateway = otp_verifier_get_sms_gateway($gateway_name, $settings);

            $sms_data = $gateway_name === 'melipayamak' ? [$otp] : [$settings['otp_var_name'] => $otp];
            $sms_result = $gateway->send_sms($phone, $sms_data, $settings['pattern']);

            if (!$sms_result || !$sms_result->success) {
                otp_verifier_log('❌ checkout handle_send_otp: SMS failed for ' . otp_verifier_mask_phone($phone));
                wp_send_json_error(['message' => 'خطا در ارسال پیامک. لطفاً دوباره تلاش کنید.']);
                return;
            }

            wp_send_json_success(['message' => 'کد تایید ارسال شد.']);
        } catch (Exception $e) {
            otp_verifier_log('❌ checkout handle_send_otp: EXCEPTION - ' . $e->getMessage());
            wp_send_json_error(['message' => 'خطای سیستمی رخ داده است.']);
        }
    }

    public function handle_verify_otp()
    {
        try {
            if (!check_ajax_referer('otp_checkout_nonce', 'security', false)) {
                wp_send_json_error(['message' => 'درخواست نامعتبر است.']);
                return;
            }
            if (!$this->is_enabled()) {
                wp_send_json_error(['message' => 'این قابلیت فعال نیست.']);
                return;
            }

            $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
            $phone = OTP_Verifier_Phone_Util::sanitize_iranian_phone($phone);
            $code  = isset($_POST['otp']) ? sanitize_text_field(wp_unslash($_POST['otp'])) : '';

            if (!$phone) {
                wp_send_json_error(['message' => 'شماره موبایل معتبر نیست.']);
                return;
            }
            if (empty($code)) {
                wp_send_json_error(['message' => 'کد تایید وارد نشده است.']);
                return;
            }

            $is_valid = $this->get_otp_handler()->verify_otp($phone, $code);
            if (!$is_valid) {
                wp_send_json_error(['message' => 'کد تایید اشتباه یا منقضی شده است.']);
                return;
            }

            $this->set_verified_phone($phone);

            wp_send_json_success([
                'message' => 'شماره موبایل با موفقیت تایید شد.',
                'phone'   => $phone,
            ]);
        } catch (Exception $e) {
            otp_verifier_log('❌ checkout handle_verify_otp: EXCEPTION - ' . $e->getMessage());
            wp_send_json_error(['message' => 'خطای سیستمی رخ داده است.']);
        }
    }

    /**
     * اجرای سمت سرور - صرف‌نظر از حالت (inline/gate)، سفارش فقط وقتی ثبت می‌شود
     * که شماره تایید شده در session با شماره موبایل ارسالی فرم یکسان باشد.
     * این تنها لایه واقعی امنیتی است؛ UI فقط برای تجربه کاربری است.
     */
    public function enforce_verification()
    {
        if (!$this->is_enabled()) {
            return;
        }

        $billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        $billing_phone = OTP_Verifier_Phone_Util::sanitize_iranian_phone($billing_phone);

        if (!$billing_phone) {
            wc_add_notice('شماره موبایل وارد شده معتبر نیست.', 'error');
            return;
        }

        $verified_phone = $this->get_verified_phone();

        if (empty($verified_phone) || !hash_equals((string) $verified_phone, (string) $billing_phone)) {
            wc_add_notice('لطفاً شماره موبایل خود را تایید کنید. اگر شماره موبایل را تغییر داده‌اید، باید دوباره تایید شود.', 'error');
        }
    }
}
