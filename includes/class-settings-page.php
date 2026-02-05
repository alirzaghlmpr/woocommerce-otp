<?php
if (!defined('ABSPATH')) exit;

require_once OTP_VERIFIER_PATH . 'includes/Admin/class-settings-sanitizer.php';
require_once OTP_VERIFIER_PATH . 'includes/Admin/class-digits-migrator.php';
require_once OTP_VERIFIER_PATH . 'includes/Admin/class-sms-test-sender.php';
class OTP_Verifier_Settings_Page
{
    private $option_name = 'otp_verifier_settings';
    private $sanitizer;
    private $digits_migrator;
    private $sms_tester;

    public function __construct()
    {
        $this->sanitizer = new OTP_Verifier_Settings_Sanitizer();
        $this->digits_migrator = new OTP_Verifier_Digits_Migrator();
        $this->sms_tester = new OTP_Verifier_Sms_Test_Sender();

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'OTP Verifier',
            'OTP Verifier',
            'manage_options',
            'otp-verifier',
            [$this, 'create_settings_page'],
            'dashicons-smartphone',
            3
        );
    }

    public function register_settings()
    {
        register_setting(
            $this->option_name,
            $this->option_name,
            [$this, 'sanitize']
        );
    }

    // پاکسازی و اعتبارسنجی ورودی‌ها
    public function sanitize($input)
    {
        return $this->sanitizer->sanitize($input);
    }

    public function create_settings_page()
    {
        $settings = get_option($this->option_name);

        // هندل مهاجرت کاربران Digits
        if (isset($_POST['otp_verifier_migrate_digits'])) {
            $result = $this->digits_migrator->migrate();

            if ($result['status'] === 'none') {
                echo '<div class="notice notice-info"><p>✅ هیچ کاربری برای مهاجرت یافت نشد. همه کاربران قبلاً مهاجرت داده شده‌اند.</p></div>';
            } elseif ($result['status'] === 'error') {
                echo '<div class="notice notice-error"><p>❌ خطای سیستمی در مهاجرت: ' . esc_html($result['errors'][0] ?? 'خطای ناشناخته') . '</p></div>';
            } else {
                if ($result['migrated'] > 0) {
                    echo '<div class="notice notice-success"><p>✅ مهاجرت موفقیت‌آمیز: ' . $result['migrated'] . ' کاربر با موفقیت مهاجرت داده شدند.</p></div>';
                }
                if ($result['failed'] > 0) {
                    echo '<div class="notice notice-error"><p>❌ خطا در مهاجرت ' . $result['failed'] . ' کاربر. جزئیات بیشتر در لاگ سیستم.</p>';
                    if (!empty($result['errors'])) {
                        echo '<ul>';
                        foreach (array_slice($result['errors'], 0, 5) as $error) {
                            echo '<li>' . esc_html($error) . '</li>';
                        }
                        if (count($result['errors']) > 5) {
                            echo '<li>... و ' . (count($result['errors']) - 5) . ' خطای دیگر</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</div>';
                }
            }
        }

        // هندل ارسال تست پنل
        if (isset($_POST['otp_verifier_test_sms'])) {
            $test_number = sanitize_text_field($_POST['otp_verifier_test_number'] ?? '');

            if (!empty($test_number)) {
                $result = $this->sms_tester->send_test($settings, $test_number);
                if ($result['success']) {
                    echo '<div class="notice notice-success"><p>' . esc_html($result['message']) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html($result['message']) . '</p></div>';
                }
            }
        }
?>
        <div class="wrap">
            <h1>تنظیمات OTP Verifier</h1>
            <form method="post" action="options.php">
                <?php settings_fields($this->option_name); ?>
                <table class="form-table">
                    <tr>
                        <th>فعال‌سازی جایگزینی صفحه ورود</th>
                        <td>
                            <input type="checkbox"
                                name="<?php echo esc_attr($this->option_name); ?>[active_login]"
                                value="1" <?php checked($settings['active_login'], 1); ?>>
                        </td>
                    </tr>
                    <tr>
                        <th>الزامات مشخصات درگاه ها:</th>
                        <td>
                            <p>فراز اس ام اس : نام کاربری ، رمز عبور ، کلید ای پی ای ، کد پترن ، نام متغییر پترن ، خط ارسال کننده
                            </p>
                            <p>ملی پیامک : نام کاربری ، رمز عبور ، کلید ای پی ای (جای رمز عبور میتوانید کلید ای پی ای هم وارد کنید) ، کد پترن
                            </p>
                            <p>اس ام اس ای ار : کلید ای پی ای ، کد پترن ، نام متغییر پترن
                            </p>
                            <p>کاوه نگار : کلید ای پی ای ، کد پترن ، نام متغییر پترن</p>
                        </td>
                    </tr>
                    <tr>
                        <th>انتخاب درگاه پیامکی</th>
                        <td>
                            <select name="<?php echo esc_attr($this->option_name); ?>[gateway]">
                                <option value="melipayamak" <?php selected($settings['gateway'], 'melipayamak'); ?>>ملی پیامک</option>
                                <option value="farazsms" <?php selected($settings['gateway'], 'farazsms'); ?>>فراز اس‌ام‌اس</option>
                                <option value="kavenegar" <?php selected($settings['gateway'], 'kavenegar'); ?>>کاوه‌نگار</option>
                                <option value="smsir" <?php selected($settings['gateway'], 'smsir'); ?>>SMS.ir</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>نام کاربری</th>
                        <td>
                            <input type="text"
                                name="<?php echo esc_attr($this->option_name); ?>[username]"
                                value="<?php echo esc_attr($settings['username']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>رمز عبور / Password</th>
                        <td>
                            <input type="text"
                                style="width:40%;"
                                name="<?php echo esc_attr($this->option_name); ?>[password]"
                                value="<?php echo esc_attr($settings['password']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>کلید API</th>
                        <td>
                            <input type="text"
                                style="width:40%;"
                                name="<?php echo esc_attr($this->option_name); ?>[api_key]"
                                value="<?php echo esc_attr($settings['api_key']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>مدت اعتبار OTP (ثانیه)</th>
                        <td>
                            <input min='60' type="number"
                                name="<?php echo esc_attr($this->option_name); ?>[otp_expire]"
                                value="<?php echo esc_attr($settings['otp_expire']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>پترن</th>
                        <td>
                            <input type="text"
                                name="<?php echo esc_attr($this->option_name); ?>[pattern]"
                                value="<?php echo esc_attr($settings['pattern']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>اسم متغییر پترن</th>
                        <td>
                            <input type="text"
                                name="<?php echo esc_attr($this->option_name); ?>[otp_var_name]"
                                value="<?php echo esc_attr($settings['otp_var_name']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>خط ارسال کننده</th>
                        <td>
                            <input type="text"
                                name="<?php echo esc_attr($this->option_name); ?>[line_number]"
                                value="<?php echo esc_attr($settings['line_number']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>طول عدد(تعداد ارقام)</th>
                        <td>
                            <input type="number" min='4'
                                name="<?php echo esc_attr($this->option_name); ?>[otp_length]"
                                value="<?php echo esc_attr($settings['otp_length']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2">
                            <hr style="margin: 10px 0;">
                            <h2>تنظیمات صفحه ورود (Login Page)</h2>
                        </th>
                    </tr>
                    <tr>
                        <th>عنوان صفحه ورود</th>
                        <td>
                            <input type="text"
                                style="width:40%;"
                                name="<?php echo esc_attr($this->option_name); ?>[login_title]"
                                value="<?php echo esc_attr($settings['login_title'] ?? 'ورود | ثبت نام'); ?>">
                            <p class="description">عنوان اصلی صفحه ورود (ورود | ثبت نام).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>متن دکمه ورود</th>
                        <td>
                            <input type="text"
                                style="width:40%;"
                                name="<?php echo esc_attr($this->option_name); ?>[login_button_text]"
                                value="<?php echo esc_attr($settings['login_button_text'] ?? 'ورود یا ثبت نام'); ?>">
                            <p class="description">متن روی دکمه اصلی صفحه ورود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>عنوان بخش ثبت نام</th>
                        <td>
                            <input type="text"
                                style="width:40%;"
                                name="<?php echo esc_attr($this->option_name); ?>[signup_title]"
                                value="<?php echo esc_attr($settings['signup_title'] ?? 'ایجاد حساب جدید'); ?>">
                            <p class="description">عنوان نمایش داده شده در بخش ثبت نام.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>متن دکمه ثبت نام</th>
                        <td>
                            <input type="text"
                                style="width:40%;"
                                name="<?php echo esc_attr($this->option_name); ?>[signup_button_text]"
                                value="<?php echo esc_attr($settings['signup_button_text'] ?? 'ثبت نام'); ?>">
                            <p class="description">متن دکمه در فرم ثبت نام/ارسال کد.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>قفل کردن حساب دمو (demo/demo)</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr($this->option_name); ?>[lock_demo_account]"
                                    value="1" <?php checked($settings['lock_demo_account'] ?? false, 1); ?>>
                                فعال‌سازی قفل حساب دمو برای جلوگیری از ویرایش مشخصات
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>متن قوانین و حریم خصوصی</th>
                        <td>
                            <textarea
                                name="<?php echo esc_attr($this->option_name); ?>[login_privacy_text]"
                                rows="3"
                                style="width: 100%; max-width: 600px;"><?php echo esc_textarea($settings['login_privacy_text'] ?? 'با ثبت نام و عضویت در سایت تمام قوانین و شرایط استفاده از خدمات <span class="text-main-blue-100">افراز ادیو</span> رو پذیرفته اید'); ?></textarea>
                            <p class="description">متن قوانین زیر دکمه. می‌توانید از تگ‌های ساده HTML مانند &lt;span&gt; استفاده کنید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>آدرس لوگو صفحه ورود (URL)</th>
                        <td>
                            <input type="url"
                                style="width:70%;"
                                name="<?php echo esc_attr($this->option_name); ?>[login_logo_url]"
                                value="<?php echo esc_attr($settings['login_logo_url'] ?? ''); ?>"
                                placeholder="مثلاً: https://yoursite.com/logo.png">
                            <p class="description">آدرس کامل (URL) تصویر لوگو برای نمایش در صفحه(ابعاد پیشنهادی 200 در 55) ورود/ثبت نام. اگر خالی باشد، لوگوی پیش‌فرض نمایش داده می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>ابعاد لوگو صفحه ورود</th>
                        <td class="flex gap-4 items-center">
                            <label>عرض: <input type="text" name="<?php echo esc_attr($this->option_name); ?>[login_logo_width]" value="<?php echo esc_attr($settings['login_logo_width'] ?? '200px'); ?>" placeholder="مثلا 200px" style="width: 100px;"></label>
                            <span style="margin: 0 10px;">x</span>
                            <label>ارتفاع: <input type="text" name="<?php echo esc_attr($this->option_name); ?>[login_logo_height]" value="<?php echo esc_attr($settings['login_logo_height'] ?? '55px'); ?>" placeholder="مثلا 55px" style="width: 100px;"></label>
                        </td>
                    </tr>
                    <tr>
                        <th>آدرس تصویر پس‌زمینه صفحه ورود (URL)</th>
                        <td>
                            <input type="url"
                                style="width:70%; direction:ltr;"
                                name="<?php echo esc_attr($this->option_name); ?>[login_bg_image_url]"
                                value="<?php echo esc_attr($settings['login_bg_image_url'] ?? ''); ?>"
                                placeholder="https://example.com/background.jpg">
                            <p class="description">فقط آدرس تصویر را وارد کنید (آپلود انجام نمی‌شود). اگر خالی باشد، تصویر پیش‌فرض استفاده می‌شود.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>CSS اختصاصی صفحه ورود</th>
                        <td>
                            <textarea name="<?php echo esc_attr($this->option_name); ?>[login_custom_css]" rows="5" style="width: 100%; direction:ltr; font-family:monospace;" placeholder=".my-class { color: red; }"><?php echo esc_textarea($settings['login_custom_css'] ?? ''); ?></textarea>
                            <p class="description">کدهای CSS را بدون تگ &lt;style&gt; وارد کنید.</p>
                        </td>
                    </tr>

                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>تست پنل پیامک</h2>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th>شماره موبایل تست</th>
                        <td><input type="text" name="otp_verifier_test_number" value="" placeholder="مثلاً 0912xxxxxxx"></td>
                    </tr>
                    <p>برای تست درگاه عدد 1234 به شماره وارد شده ارسال میشود</p>

                </table>
                <?php submit_button('ارسال پیامک تست', 'secondary', 'otp_verifier_test_sms'); ?>
            </form>

            <hr>
            <h2>🔄 مهاجرت کاربران از Digits</h2>
            <form method="post" onsubmit="return confirm('آیا مطمئن هستید که می‌خواهید کاربران Digits را مهاجرت دهید؟');">
                <table class="form-table">
                    <tr>
                        <td colspan="2">
                            <p><strong>درباره این ابزار:</strong></p>
                            <p>اگر قبلاً از افزونه Digits برای ورود با شماره موبایل استفاده می‌کردید، این ابزار به شما کمک می‌کند تا شماره‌های موبایل کاربران قدیمی را به فرمت OTP Verifier مهاجرت دهید.</p>
                            <p>این ابزار تمام کاربرانی که دارای <code>digits_phone_no</code> هستند را پیدا کرده و شماره‌های آنها را به <code>phone_number</code> (با صفر اول) تبدیل می‌کند.</p>
                            <p><strong>⚠️ توجه:</strong> این عملیات فقط یک بار لازم است. کاربرانی که بعداً با OTP Verifier ثبت‌نام کنند، به صورت خودکار مهاجرت خواهند شد.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('🔄 شروع مهاجرت کاربران Digits', 'primary', 'otp_verifier_migrate_digits'); ?>
            </form>
        </div>
<?php
    }
}
