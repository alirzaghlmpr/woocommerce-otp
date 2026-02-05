<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Settings_Sanitizer
{
    public function sanitize($input)
    {
        $output = [];

        $output['active_login'] = isset($input['active_login']) ? (bool)$input['active_login'] : false;
        $output['gateway']      = sanitize_text_field($input['gateway'] ?? '');
        $output['username']     = sanitize_text_field($input['username'] ?? '');
        $output['password']     = sanitize_text_field($input['password'] ?? '');
        $output['api_key']      = sanitize_text_field($input['api_key'] ?? '');
        $output['pattern']      = sanitize_text_field($input['pattern'] ?? '');
        $output['line_number']  = sanitize_text_field($input['line_number'] ?? '');
        $output['otp_var_name'] = sanitize_text_field($input['otp_var_name'] ?? '');
        $output['otp_length']   = absint($input['otp_length'] ?? 0);
        $output['otp_expire']   = absint($input['otp_expire'] ?? 120);

        $output['login_title']        = sanitize_text_field($input['login_title'] ?? 'ورود | ثبت نام');
        $output['login_button_text']  = sanitize_text_field($input['login_button_text'] ?? 'ورود یا ثبت نام');
        $output['signup_title']       = sanitize_text_field($input['signup_title'] ?? 'ایجاد حساب جدید');
        $output['signup_button_text'] = sanitize_text_field($input['signup_button_text'] ?? 'ثبت نام');
        $output['lock_demo_account']  = isset($input['lock_demo_account']) ? (bool)$input['lock_demo_account'] : false;
        $output['login_privacy_text'] = wp_kses_post($input['login_privacy_text'] ?? 'با ثبت نام و عضویت در سایت تمام قوانین و شرایط استفاده از خدمات <span class="text-main-blue-100">افراز ادیو</span> رو پذیرفته اید');
        $output['login_logo_url']     = esc_url_raw($input['login_logo_url'] ?? '');

        $output['login_logo_width']   = sanitize_text_field($input['login_logo_width'] ?? '200px');
        $output['login_logo_height']  = sanitize_text_field($input['login_logo_height'] ?? '55px');
        $output['login_bg_image_url'] = esc_url_raw($input['login_bg_image_url'] ?? '');

        $output['login_custom_css']   = strip_tags($input['login_custom_css'] ?? '');

        return $output;
    }
}
