<?php
if (!defined('ABSPATH')) {
    exit;
}

class OTP_Verifier_Otp_Repository
{
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'otp_verifier_codes';
    }

    public function delete_by_phone($phone_number)
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['phone_number' => $phone_number], ['%s']);
    }

    public function insert_otp($phone_number, $otp, $created_at)
    {
        global $wpdb;
        return $wpdb->insert(
            $this->table,
            [
                'phone_number'  => $phone_number,
                'code'          => $otp,
                'created_at'    => $created_at,
                'verified'      => 0,
                'attempt_count' => 0
            ],
            ['%s', '%s', '%s', '%d', '%d']
        );
    }

    public function get_latest_unverified($phone_number)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE phone_number = %s AND verified = 0 ORDER BY created_at DESC LIMIT 1",
            $phone_number
        ));
    }

    public function update_attempt_count($id, $attempt_count)
    {
        global $wpdb;
        return $wpdb->update(
            $this->table,
            ['attempt_count' => $attempt_count],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }

    public function delete_by_id($id)
    {
        global $wpdb;
        return $wpdb->delete($this->table, ['id' => $id], ['%d']);
    }

    public function delete_expired_before($cutoff_time)
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE created_at < %s",
            $cutoff_time
        ));
    }

    public function get_attempt_count($phone_number)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT attempt_count FROM {$this->table} WHERE phone_number = %s AND verified = 0 ORDER BY created_at DESC LIMIT 1",
            $phone_number
        ));
        return $row ? (int) $row->attempt_count : null;
    }

    public function count_total()
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }

    public function count_verified()
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE verified = 1");
    }

    public function count_expired($cutoff_time)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE created_at < %s",
            $cutoff_time
        ));
    }
}
