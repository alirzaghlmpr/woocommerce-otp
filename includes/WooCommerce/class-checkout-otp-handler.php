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
        // اگر شماره موبایل تنظیم شده باشد که تایید شود، اما فیلد billing_phone
        // از فرم چک‌اوت حذف/غیرفعال شده باشد (بعضی سایت‌ها این کار را می‌کنند)،
        // بدون این فیلد نه ویجت inline جایی برای اتصال دارد و نه، مهم‌تر، حالت
        // gate می‌تواند شماره‌ی تایید‌شده را submit کند (چون JS مقدار را روی
        // #billing_phone می‌گذارد؛ اگر این فیلد در DOM نباشد، آن مقدار هرگز پست
        // نمی‌شود و enforce_verification سفارش را رد می‌کند حتی بعد از تایید
        // موفق). پس همیشه فیلد را دوباره اضافه می‌کنیم اگر غایب باشد.
        add_filter('woocommerce_checkout_fields', [$this, 'ensure_billing_phone_field_exists']);
        // billing_phone is type="tel" in WooCommerce core - این فیلتر روی HTML
        // نهایی‌ساخته‌شده‌ی هر فیلد اجرا می‌شود (نه description که از wp_kses_post
        // عبور می‌کند و ممکن است <button>/<input> ویجت را حذف کند)، پس با append
        // کردن به همان رشته، ویجت دقیقاً به‌صورت یک sibling بعد از خود فیلد شماره
        // موبایل (نه انتهای کل فرم صورتحساب) چاپ می‌شود.
        add_filter('woocommerce_form_field_tel', [$this, 'inject_inline_widget_after_phone_field'], 10, 4);
        add_action('woocommerce_before_checkout_form', [$this, 'render_gate_overlay'], 5);
        add_action('woocommerce_checkout_process', [$this, 'enforce_verification']);

        otp_verifier_log('✅ OTP_Verifier_Checkout_Handler: هوک‌های تایید موبایل در تسویه‌حساب ثبت شدند (enabled=' . ($this->is_enabled() ? 'true' : 'false') . ', mode=' . $this->get_mode() . ')');
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

    /**
     * آیا برای کاربر فعلی، تایید شماره موبایل در تسویه‌حساب لازم است؟
     * به‌طور پیش‌فرض کاربران وارد شده (logged-in) از این الزام معاف هستند -
     * چون حساب‌شان از قبل احراز هویت شده. با تنظیم
     * checkout_verify_require_logged_in می‌توان این معافیت را غیرفعال کرد تا
     * کاربران وارد شده هم مثل مهمان‌ها ملزم به تایید شوند.
     * این تابع تنها منبع تصمیم‌گیری است - هم برای رندر UI (ویجت/گیت) و هم
     * برای enforce_verification سمت سرور - تا هرگز حالتی پیش نیاید که UI
     * پنهان باشد ولی سرور همچنان سفارش را مسدود کند.
     */
    private function requires_verification_for_current_user()
    {
        if (!$this->is_enabled()) {
            return false;
        }
        if (is_user_logged_in()) {
            $settings = get_option('otp_verifier_settings', []);
            return !empty($settings['checkout_verify_require_logged_in']);
        }
        return true;
    }

    private function get_mode()
    {
        $settings = get_option('otp_verifier_settings', []);
        $mode = $settings['checkout_verify_mode'] ?? 'inline';
        return in_array($mode, ['inline', 'gate'], true) ? $mode : 'inline';
    }

    /**
     * اگر تایید شماره موبایل در تسویه‌حساب لازم است اما فیلد billing_phone از
     * فرم حذف/غیرفعال شده (مثلاً توسط تم یا یک افزونه‌ی دیگر)، آن را دوباره
     * اضافه می‌کنیم - و چون تایید اجباری است، آن را required هم می‌کنیم (حتی
     * اگر از قبل با required=false وجود داشته باشد) تا برچسب فیلد با رفتار
     * واقعی چک‌اوت (نمی‌توانی بدون آن سفارش ثبت کنی) همخوانی داشته باشد.
     */
    public function ensure_billing_phone_field_exists($fields)
    {
        if (!$this->requires_verification_for_current_user()) {
            return $fields;
        }

        if (empty($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone'] = [
                'label'        => __('Phone', 'woocommerce'),
                'type'         => 'tel',
                'required'     => true,
                'class'        => ['form-row-wide'],
                'validate'     => ['phone'],
                'autocomplete' => 'tel',
                'priority'     => 100,
            ];
            otp_verifier_log('ℹ️ Checkout OTP: فیلد billing_phone در فرم چک‌اوت موجود نبود - چون تایید شماره فعال است، دوباره اضافه شد.');
        } else {
            $fields['billing']['billing_phone']['required'] = true;
        }

        return $fields;
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
        if (!$this->requires_verification_for_current_user() || !function_exists('is_checkout') || !is_checkout()) {
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

    private function checkout_color()
    {
        $settings = get_option('otp_verifier_settings', []);
        $color = $settings['checkout_verify_color'] ?? '#2d264b';
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#2d264b';
    }

    /**
     * حالت «داخل فرم»: ویجت مستقیماً بعد از فیلد استاندارد شماره موبایل ووکامرس
     * (billing_phone) رندر می‌شود - نه انتهای کل فرم صورتحساب. فیلد جدیدی
     * اضافه نمی‌شود، همان billing_phone استفاده می‌شود.
     */
    public function inject_inline_widget_after_phone_field($field, $key, $args, $value)
    {
        if ($key !== 'billing_phone' || !$this->requires_verification_for_current_user() || $this->get_mode() !== 'inline' || $this->is_block_checkout()) {
            return $field;
        }

        ob_start();
        $this->render_inline_widget_markup();
        return $field . ob_get_clean();
    }

    private function render_inline_widget_markup()
    {
?>
        <div id="otp-checkout-inline" class="otp-checkout-widget" style="--otp-checkout-color: <?php echo esc_attr($this->checkout_color()); ?>;">
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
     * حالت «قفل کامل» (به‌صورت یک مرحله/Step، نه پاپ‌آپ روی فرم): پیش از نمایش
     * فرم تسویه‌حساب، ابتدا این مرحله‌ی تایید شماره موبایل نمایش داده می‌شود و
     * فرم اصلی (form.woocommerce-checkout) کاملاً مخفی است - نه فقط blur/غیرفعال.
     * مخفی‌سازی از طریق یک <style> اینلاین انجام می‌شود (نه کلاس/JS) تا فرم حتی
     * برای یک لحظه هم قبل از اجرای جاوااسکریپت دیده نشود (بدون پرش/فلش تصویری).
     * توجه مهم: سلکتور باید حتماً محدود به تگ form باشد (form.woocommerce-checkout)
     * نه فقط .woocommerce-checkout - چون خود ووکامرس همین کلاس را روی تگ body هم
     * اضافه می‌کند (wc_body_class در wc-template-functions.php) و اگر سلکتور به
     * فرم محدود نشود، کل صفحه (body) مخفی و سفید می‌شود.
     * پس از تایید موفق، JS همین تگ <style> را کامل حذف می‌کند تا فرم مثل مرحله
     * بعدی یک ویزارد نمایان شود. اگر شماره‌ای از قبل در این session تایید شده،
     * اصلاً رندر نمی‌شود.
     */
    public function render_gate_overlay()
    {
        if (!$this->requires_verification_for_current_user() || $this->get_mode() !== 'gate' || $this->is_block_checkout()) {
            return;
        }
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (!empty($this->get_verified_phone())) {
            return;
        }
?>
        <style id="otp-checkout-gate-style">form.woocommerce-checkout{display:none;}</style>
        <div id="otp-checkout-gate" class="otp-checkout-gate-step" style="--otp-checkout-color: <?php echo esc_attr($this->checkout_color()); ?>;">
            <h3 class="otp-checkout-gate-step__title">تایید شماره موبایل</h3>
            <p class="otp-checkout-gate-step__desc">برای مشاهده و تکمیل فرم تسویه‌حساب، لطفاً ابتدا شماره موبایل خود را تایید کنید.</p>

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
        if (!$this->requires_verification_for_current_user()) {
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
