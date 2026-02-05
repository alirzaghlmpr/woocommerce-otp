<?php
if (! defined('ABSPATH')) exit;

/**
 * کلاس پایه برای تمام درگاه‌های SMS
 */
abstract class OTP_SMS_Gateway
{
    protected string $username;
    protected string $password;
    protected string $api_key;
    protected string $pattern;
    protected string $base_url;
    protected string $line_number;
    protected string $otp_var_name;

    public function __construct(array $config = [])
    {
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->api_key  = $config['api_key'] ?? '';
        $this->pattern  = $config['pattern'] ?? '';
        $this->base_url = $config['base_url'] ?? '';
        $this->line_number = $config['line_number'] ?? '';
        $this->otp_var_name = $config['otp_var_name'] ?? '';
    }

    /**
     * متد ارسال پیامک که باید در کلاس فرزند پیاده‌سازی شود
     */
    abstract public function send_sms(string $to, array $variables, string $pattern): object;
}
