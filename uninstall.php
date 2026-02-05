<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!current_user_can('activate_plugins')) {
    return;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-otp-handler.php';

delete_option('otp_verifier_settings');
error_log("✅ Uninstall: Deleted option 'otp_verifier_settings'.");

if (class_exists('OTP_Verifier_Handler')) {
    OTP_Verifier_Handler::drop_table();

    OTP_Verifier_Handler::cleanup_cron();

    global $wpdb;

    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_otp\_rate\_limit\_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_otp\_rate\_limit\_%'");

    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_otp\_ip\_limit\_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_otp\_ip\_limit\_%'");

    error_log("✅ Uninstall: Database table and all related transient keys deleted.");
} else {
    error_log("❌ Uninstall: OTP_Verifier_Handler class not found.");
}
