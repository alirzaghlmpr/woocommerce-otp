<?php

/**
 * Template: OTP Login Page
 * برای override صفحه my-account ووکامرس
 */
if (! defined('ABSPATH')) {
    exit;
}

// Fetch dynamic settings
$settings = get_option('otp_verifier_settings', []);
$login_title = $settings['login_title'] ?? 'ورود | ثبت نام';
$login_button_text = $settings['login_button_text'] ?? 'ورود یا ثبت نام';
$signup_title = $settings['signup_title'] ?? 'ایجاد حساب جدید';
$signup_button_text = $settings['signup_button_text'] ?? 'ثبت نام';
$login_privacy_text = $settings['login_privacy_text'] ?? 'با ثبت نام و عضویت در سایت تمام قوانین و شرایط استفاده از خدمات <span class="otp-link">افراز ادیو</span> رو پذیرفته اید';

// Get logo URL, defaulting to the plugin's SVG if empty
$default_logo_url = OTP_VERIFIER_URL . 'templates/assets/images/svg/site-logo.svg';
$login_logo_url = !empty($settings['login_logo_url']) ? esc_url($settings['login_logo_url']) : $default_logo_url;
// --> اضافه کردن این خطوط:
$login_logo_width = $settings['login_logo_width'] ?? '200px';
$login_logo_height = $settings['login_logo_height'] ?? '55px';
$login_custom_css = $settings['login_custom_css'] ?? '';
$login_bg_image_url = $settings['login_bg_image_url'] ?? '';

$body_styles = [];
if (!empty($login_bg_image_url)) {
    $body_styles[] = "background-image: url('" . esc_url($login_bg_image_url) . "');";
    $body_styles[] = 'background-size: cover;';
    $body_styles[] = 'background-position: center;';
    $body_styles[] = 'background-repeat: no-repeat;';
}

$body_style_attr = !empty($body_styles) ? ' style="' . esc_attr(implode(' ', $body_styles)) . '"' : '';

$otp_expire = isset($settings['otp_expire']) ? absint($settings['otp_expire']) : 120;
$otp_length = isset($settings['otp_length']) ? absint($settings['otp_length']) : 6;
$otp_frontend_config = [
    'ajaxurl'    => admin_url('admin-ajax.php'),
    'nonce'      => wp_create_nonce('otp_login_nonce'),
    'expire'     => $otp_expire,
    'otp_length' => $otp_length,
    'assets_url' => OTP_VERIFIER_URL . 'templates/assets/',
    'messages'   => [
        'invalid_phone'  => 'شماره موبایل معتبر نیست.',
        'otp_sent'       => 'کد تایید ارسال شد.',
        'otp_invalid'    => 'کد وارد شده صحیح نیست یا منقضی شده.',
        'otp_verified'   => 'ورود با موفقیت انجام شد!',
        'rate_limit'     => 'تعداد درخواست‌های شما بیش از حد است. لطفاً کمی صبر کنید.',
        'sms_failed'     => 'خطا در ارسال پیامک. لطفاً دوباره تلاش کنید.',
    ],
];

$otp_frontend_config_json = wp_json_encode(
    $otp_frontend_config,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
?>

<!doctype html>
<html lang="fa_IR" dir="rtl">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>ورود / عضویت</title>

    <link rel="stylesheet" href="<?php echo OTP_VERIFIER_URL . 'templates/assets/css/output.css'; ?>">
    <link rel="stylesheet" href="<?php echo OTP_VERIFIER_URL . 'templates/assets/css/sweetalert2.min.css'; ?>">
    <?php if (!empty($login_custom_css)) : ?>
        <style>
            <?php echo $login_custom_css; ?>
        </style>
    <?php endif; ?>
</head>

<body class="otp-page auth-login"<?php echo $body_style_attr; ?>>
    <section class="otp-hero">
        <div class="otp-card">
            <div class="otp-card__header">
                <a href="<?php echo esc_url(home_url('/')); ?>">
                    <img class="otp-logo"
                        style="width: <?php echo esc_attr($login_logo_width); ?>; height: <?php echo esc_attr($login_logo_height); ?>;"
                        src="<?php echo $login_logo_url; ?>"
                        alt="Logo">
                </a>
            </div>

            <div id="loginStep" class="otp-step">
                <h1 class="otp-title"><?php echo esc_html($login_title); ?></h1>
                <form id="passwordLoginForm" class="otp-form">
                    <div class="otp-field">
                        <input dir="rtl" id="loginUsername" name="username" type="text" autocomplete="username"
                            class="otp-input-text"
                            placeholder="نام کاربری یا ایمیل" />
                        <img class="otp-field__icon" src="<?php echo OTP_VERIFIER_URL . "templates/assets/images/svg/username-login-page.svg"; ?>" alt="">
                    </div>
                    <div class="otp-field">
                        <input dir="rtl" id="loginPassword" name="password" type="password" autocomplete="current-password"
                            class="otp-input-text"
                            placeholder="رمز عبور" />
                        <button type="button" id="toggleLoginPassword" class="otp-icon-button">
                            <img id="loginPasswordEye" src="<?php echo OTP_VERIFIER_URL . "templates/assets/images/svg/eye-close-login-page.svg"; ?>" alt="" class="otp-icon">
                        </button>
                    </div>
                    <button type="submit" class="otp-button">ورود</button>
                </form>
                <p class="otp-links">
                    <a id="toggleToSignup" class="otp-link">آیا اکانت ندارید؟ ثبت نام</a>
                    <a id="toggleToPhoneLogin" class="otp-link">ورود با شماره موبایل</a>
                </p>
                <?php if (!empty($login_privacy_text)) : ?>
                    <p class="otp-privacy"><?php echo $login_privacy_text; ?></p>
                <?php endif; ?>
            </div>

            <div id="signupStep" class="otp-step otp-hidden">
                <h2 class="otp-title otp-title--sm"><?php echo esc_html($signup_title); ?></h2>
                <form id="signupForm" class="otp-form">
                    <div class="otp-field">
                        <input dir="rtl" id="signupUsername" type="text" autocomplete="username"
                            class="otp-input-text"
                            placeholder="نام کاربری" />
                        <img class="otp-field__icon" src="<?php echo OTP_VERIFIER_URL . "templates/assets/images/svg/username-login-page.svg"; ?>" alt="">
                    </div>
                    <div class="otp-field">
                        <input dir="rtl" id="signupPassword" type="password" autocomplete="new-password"
                            class="otp-input-text"
                            placeholder="رمز عبور" />
                        <button type="button" id="toggleSignupPassword" class="otp-icon-button">
                            <img id="signupPasswordEye" src="<?php echo OTP_VERIFIER_URL . "templates/assets/images/svg/eye-close-login-page.svg"; ?>" alt="" class="otp-icon">
                        </button>
                    </div>
                    <div class="otp-field">
                        <input dir="rtl" id="signupPhoneInput" type="tel" inputmode="numeric" maxlength="11"
                            class="otp-input-text"
                            placeholder="شماره موبایل" />
                        <img class="otp-field__icon" src="<?php echo OTP_VERIFIER_URL . "templates/assets/images/svg/phone.svg"; ?>" alt="">
                    </div>
                    <button type="submit" class="otp-button"><?php echo esc_html($signup_button_text); ?></button>
                </form>
                <?php if (!empty($login_privacy_text)) : ?>
                    <p class="otp-privacy"><?php echo $login_privacy_text; ?></p>
                <?php endif; ?>
                <p class="otp-footnote">
                    <a id="toggleToLogin" class="otp-link">حساب دارید؟ ورود</a>
                </p>
            </div>

            <div id="phoneLoginStep" class="otp-step otp-hidden">
                <h2 class="otp-title otp-title--sm">ورود با شماره موبایل</h2>
                <form id="phoneLoginForm" class="otp-form">
                    <div class="otp-field">
                        <input dir="rtl" id="phoneLoginInput" type="tel" inputmode="numeric" maxlength="11"
                            class="otp-input-text"
                            placeholder="شماره موبایل" />
                        <img class="otp-field__icon" src="<?php echo OTP_VERIFIER_URL . "templates/assets/images/svg/phone.svg"; ?>" alt="">
                    </div>
                    <button type="submit" class="otp-button"><?php echo esc_html($login_button_text); ?></button>
                </form> <?php if (!empty($login_privacy_text)) : ?>
                    <p class="otp-privacy"><?php echo $login_privacy_text; ?></p>
                <?php endif; ?>
                <p class="otp-footnote">
                    <a id="togglePhoneToLogin" class="otp-link">ورود با نام کاربری و رمز</a>
                </p>
            </div>

            <div id="otpStep" class="otp-step otp-hidden">
                <div class="otp-row">
                    <p id="otpInfo" class="otp-info">کد تایید برای شماره ۰۹۱۲۳۴۵۶۷۸۹ ارسال شد</p>
                    <button id="backBtn" type="button" class="otp-back">
                        <img src="<?php echo OTP_VERIFIER_URL . "templates/assets/images/svg/arrow-left-auth-login-2.svg"; ?>" alt="">
                    </button>
                </div>

                <div id="otpInputs" class="otp-inputs"></div>

                <div class="otp-row otp-row--spaced">
                    <button id="resendBtn" class="otp-link otp-link--disabled" disabled>ارسال مجدد</button>
                    <span id="resendTimer" class="otp-timer"></span>
                </div>

                <button id="verifyOtpBtn" class="otp-button">تایید کد و ورود</button>
                <p id="otpMsg" class="otp-error otp-hidden"></p>
            </div>

        </div>
    </section>
    <script src="<?php echo esc_url(includes_url('js/jquery/jquery.min.js')); ?>"></script>
    <script src="<?php echo esc_url(OTP_VERIFIER_URL . 'templates/assets/js/sweetalert2.min.js?ver=' . OTP_VERIFIER_VERSION); ?>"></script>
    <script>
        window.otp_ajax = <?php echo $otp_frontend_config_json ? $otp_frontend_config_json : '{}'; ?>;
    </script>
    <script src="<?php echo esc_url(OTP_VERIFIER_URL . 'templates/assets/js/auth-login.js?ver=' . OTP_VERIFIER_VERSION); ?>"></script>

</body>

</html>
