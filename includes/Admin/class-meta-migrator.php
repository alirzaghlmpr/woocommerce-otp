<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once OTP_VERIFIER_PATH . 'includes/Helpers/class-phone-utils.php';

/**
 * مهاجرت شماره موبایل کاربران از هر user meta دلخواه (مثلاً افزونهٔ OTP قبلی) به phone_number.
 *
 * ایمن برای سایت زنده:
 * - فقط «اضافه» می‌کند؛ متای قبلی را حذف/تغییر نمی‌دهد و phone_number هیچ کاربری را بازنویسی نمی‌کند.
 * - پیش‌نمایش (analyze) و اجرای واقعی (migrate_batch) از یک منطق طبقه‌بندی مشترک استفاده می‌کنند،
 *   پس آنچه در پیش‌نمایش دیده می‌شود همان چیزی است که اجرا می‌شود.
 * - هر شماره فقط به یک کاربر می‌رسد (اولین رکورد)، تا ورود با شماره مبهم نشود.
 * - رکوردهای نوشته‌شده علامت‌گذاری می‌شوند تا بشود کل مهاجرت را برگرداند (undo_batch).
 */
class OTP_Verifier_Meta_Migrator
{
    const TARGET_KEY = 'phone_number';
    const MARKER_KEY = '_otp_verifier_migrated_from';

    const ANALYZE_CHUNK = 1000;
    const RUN_CHUNK = 200;
    const SAMPLE_LIMIT = 8;
    const ANALYZE_TIME_BUDGET = 40; // seconds

    /** وضعیت‌های ممکن برای هر کاربر، به ترتیب اولویت نمایش */
    const STATUSES = ['ready', 'already_same', 'has_other', 'conflict_existing', 'duplicate_in_source', 'invalid'];

    /**
     * نام کلید متا را پاکسازی می‌کند؛ اگر قابل قبول نباشد false برمی‌گرداند.
     */
    public function sanitize_key_name($key)
    {
        $key = sanitize_text_field(wp_unslash((string) $key));

        if ($key === '' || strlen($key) > 255 || $key === self::TARGET_KEY || $key === self::MARKER_KEY) {
            return false;
        }

        return $key;
    }

    // ------------------------------------------------------------------ پیدا کردن کلید

    /**
     * کلیدهای user meta که بیشتر مقدارهایشان شبیه شماره موبایل ایران است (فقط خواندن).
     */
    public function scan_candidate_keys()
    {
        global $wpdb;
        $this->raise_limits();

        $keys = $wpdb->get_results("SELECT meta_key, COUNT(*) AS total FROM {$wpdb->usermeta} GROUP BY meta_key ORDER BY total DESC LIMIT 1000");
        $found = [];

        foreach ((array) $keys as $row) {
            if ($row->meta_key === self::TARGET_KEY || $row->meta_key === self::MARKER_KEY) {
                continue;
            }

            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 40",
                $row->meta_key
            ));
            if (!$values) {
                continue;
            }

            $valid = 0;
            $example = '';
            foreach ($values as $value) {
                if (strlen($value) <= 40 && OTP_Verifier_Phone_Util::normalize_iranian_mobile($value) !== false) {
                    $valid++;
                    if ($example === '') {
                        $example = $value;
                    }
                }
            }

            $ratio = $valid / count($values);
            if ($ratio >= 0.5) {
                $found[] = [
                    'key'     => $row->meta_key,
                    'total'   => (int) $row->total,
                    'match'   => (int) round($ratio * 100),
                    'example' => $this->mask_raw($example),
                ];
            }
        }

        usort($found, function ($a, $b) {
            return [$b['match'], $b['total']] <=> [$a['match'], $a['total']];
        });

        return array_slice($found, 0, 15);
    }

    /**
     * با یک شمارهٔ مشخص (مثلاً شمارهٔ خود مدیر) پیدا می‌کند در کدام کلیدها ذخیره شده است (فقط خواندن).
     *
     * @return array|false  false = شماره معتبر نیست
     */
    public function find_keys_by_number($number)
    {
        global $wpdb;

        $phone = OTP_Verifier_Phone_Util::normalize_iranian_mobile($number);
        if ($phone === false) {
            return false;
        }
        $this->raise_limits();

        $tail = substr($phone, 1); // 9xxxxxxxxx
        $persian = strtr($tail, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, COUNT(*) AS total, MIN(meta_value) AS example, MIN(user_id) AS example_user
             FROM {$wpdb->usermeta}
             WHERE meta_value LIKE %s OR meta_value LIKE %s
             GROUP BY meta_key ORDER BY total DESC LIMIT 20",
            '%' . $wpdb->esc_like($tail) . '%',
            '%' . $wpdb->esc_like($persian) . '%'
        ));

        $keys = [];
        foreach ((array) $rows as $row) {
            if (strlen($row->example) > 60 || OTP_Verifier_Phone_Util::normalize_iranian_mobile($row->example) === false) {
                continue; // مقدارهای طولانی/سریالایز شده (مثل session_tokens) شماره‌ی خالص نیستند
            }
            $keys[] = [
                'key'     => $row->meta_key,
                'total'   => (int) $row->total,
                'example' => $this->mask_raw($row->example),
                'user_id' => (int) $row->example_user,
            ];
        }

        $in_login = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s",
            '%' . $wpdb->esc_like($tail) . '%'
        ));

        return ['keys' => $keys, 'in_user_login' => $in_login];
    }

    // ------------------------------------------------------------------ پیش‌نمایش (فقط خواندن)

    /**
     * همهٔ کاربران دارای این کلید را بررسی و طبقه‌بندی می‌کند، بدون هیچ نوشتنی.
     */
    public function analyze($meta_key)
    {
        $this->raise_limits();
        $started = microtime(true);

        $counts = array_fill_keys(self::STATUSES, 0);
        $samples = array_fill_keys(self::STATUSES, []);
        $formats = [];
        $claimed = [];
        $seen = [];
        $total = 0;
        $after = 0;
        $complete = true;

        while (true) {
            $rows = $this->fetch_chunk($meta_key, $after, self::ANALYZE_CHUNK);
            if (!$rows) {
                break;
            }
            $after = (int) end($rows)->umeta_id;

            foreach ($this->classify_rows($rows, $claimed, $seen) as $item) {
                if ($item['status'] === 'extra_row') {
                    continue;
                }
                $total++;
                $counts[$item['status']]++;

                if ($item['phone'] !== false) {
                    $label = $this->format_label($item['raw'], $item['phone']);
                    $formats[$label] = ($formats[$label] ?? 0) + 1;
                }
                if (count($samples[$item['status']]) < self::SAMPLE_LIMIT) {
                    $samples[$item['status']][] = $item;
                }
            }

            if (count($rows) < self::ANALYZE_CHUNK) {
                break;
            }
            if (microtime(true) - $started > self::ANALYZE_TIME_BUDGET) {
                $complete = false; // سایت خیلی بزرگ است؛ نتیجه ناقص است و اجرا مجاز نیست
                break;
            }
        }

        return [
            'key'      => $meta_key,
            'total'    => $total,
            'counts'   => $counts,
            'formats'  => $formats,
            'samples'  => $this->attach_logins($samples),
            'complete' => $complete,
            'marked'   => $this->count_marked($meta_key),
            'warnings' => $this->key_warnings($meta_key, $total, $counts),
        ];
    }

    // ------------------------------------------------------------------ اجرا

    /**
     * یک دستهٔ کوچک را از رکورد umeta_id بعد از $after_id مهاجرت می‌دهد. قابل تکرار و قابل ادامه است.
     */
    public function migrate_batch($meta_key, $after_id, $limit = self::RUN_CHUNK)
    {
        $this->raise_limits();

        $rows = $this->fetch_chunk($meta_key, (int) $after_id, (int) $limit);
        $claimed = [];
        $seen = [];
        $counts = [];
        $migrated = 0;
        $failed = 0;

        foreach ($this->classify_rows($rows, $claimed, $seen) as $item) {
            $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;

            if ($item['status'] !== 'ready') {
                continue;
            }

            if (update_user_meta($item['user_id'], self::TARGET_KEY, $item['phone'])) {
                update_user_meta($item['user_id'], self::MARKER_KEY, $meta_key);
                $migrated++;
                otp_verifier_log("✅ Meta Migration [{$meta_key}]: User ID {$item['user_id']} -> " . otp_verifier_mask_phone($item['phone']));
            } else {
                $failed++;
                otp_verifier_log("❌ Meta Migration [{$meta_key}]: failed to write phone_number for User ID {$item['user_id']}");
            }
        }

        return [
            'processed' => count($rows),
            'last_id'   => $rows ? (int) end($rows)->umeta_id : (int) $after_id,
            'done'      => count($rows) < (int) $limit,
            'migrated'  => $migrated,
            'failed'    => $failed,
            'counts'    => $counts,
        ];
    }

    /**
     * تعداد کاربرانی که همین ابزار برایشان phone_number ثبت کرده است.
     */
    public function count_marked($meta_key)
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
            self::MARKER_KEY,
            $meta_key
        ));
    }

    /**
     * phone_number کاربرانی را که همین ابزار (از این کلید) ثبت کرده برمی‌دارد؛ شماره‌هایی که ابزار ثبت نکرده دست نمی‌خورند.
     */
    public function undo_batch($meta_key, $limit = self::RUN_CHUNK)
    {
        global $wpdb;
        $this->raise_limits();

        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT %d",
            self::MARKER_KEY,
            $meta_key,
            (int) $limit
        ));

        foreach ($user_ids as $user_id) {
            delete_user_meta((int) $user_id, self::TARGET_KEY);
            delete_user_meta((int) $user_id, self::MARKER_KEY);
            otp_verifier_log("↩️ Meta Migration [{$meta_key}]: reverted User ID {$user_id}");
        }

        return ['removed' => count($user_ids), 'done' => count($user_ids) < (int) $limit];
    }

    // ------------------------------------------------------------------ داخلی

    private function raise_limits()
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
    }

    private function fetch_chunk($meta_key, $after_id, $limit)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT m.umeta_id, m.user_id, m.meta_value
             FROM {$wpdb->usermeta} m
             INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
             WHERE m.meta_key = %s AND m.meta_value <> '' AND m.umeta_id > %d
             ORDER BY m.umeta_id ASC
             LIMIT %d",
            $meta_key,
            (int) $after_id,
            (int) $limit
        ));
    }

    /**
     * طبقه‌بندی یک دسته رکورد. $claimed و $seen بین دسته‌های یک اجرا مشترک‌اند.
     *
     * @return array[] هر آیتم: umeta_id, user_id, raw, phone (string|false), status, other_user
     */
    private function classify_rows(array $rows, array &$claimed, array &$seen)
    {
        $user_ids = [];
        $phones = [];
        foreach ($rows as $row) {
            $user_ids[(int) $row->user_id] = true;
            $phone = OTP_Verifier_Phone_Util::normalize_iranian_mobile($row->meta_value);
            if ($phone !== false) {
                $phones[$phone] = true;
            }
        }

        $own = $this->existing_phones_by_user(array_keys($user_ids));
        $owners = $this->owners_of_phones(array_keys($phones));

        $items = [];
        foreach ($rows as $row) {
            $user_id = (int) $row->user_id;
            $item = [
                'umeta_id'   => (int) $row->umeta_id,
                'user_id'    => $user_id,
                'raw'        => (string) $row->meta_value,
                'phone'      => false,
                'status'     => '',
                'other_user' => 0,
            ];

            if (isset($seen[$user_id])) {
                $item['status'] = 'extra_row'; // رکورد تکراری متای همین کاربر
                $items[] = $item;
                continue;
            }
            $seen[$user_id] = true;

            $phone = OTP_Verifier_Phone_Util::normalize_iranian_mobile($row->meta_value);
            $item['phone'] = $phone;

            if ($phone === false) {
                $item['status'] = 'invalid';
            } elseif (isset($own[$user_id])) {
                if ($own[$user_id] === $phone) {
                    $item['status'] = 'already_same';
                    $claimed[$phone] = $user_id;
                } else {
                    $item['status'] = 'has_other';
                }
            } elseif (isset($owners[$phone]) && $owners[$phone] !== $user_id) {
                $item['status'] = 'conflict_existing';
                $item['other_user'] = $owners[$phone];
            } elseif (isset($claimed[$phone]) && $claimed[$phone] !== $user_id) {
                $item['status'] = 'duplicate_in_source';
                $item['other_user'] = $claimed[$phone];
            } else {
                $item['status'] = 'ready';
                $claimed[$phone] = $user_id;
            }

            $items[] = $item;
        }

        return $items;
    }

    /** @return array user_id => phone_number (فقط مقدارهای غیرخالی) */
    private function existing_phones_by_user(array $user_ids)
    {
        global $wpdb;

        if (!$user_ids) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value <> '' AND user_id IN (" . implode(',', array_fill(0, count($user_ids), '%d')) . ")",
            array_merge([self::TARGET_KEY], $user_ids)
        ));

        $map = [];
        foreach ((array) $rows as $row) {
            if (!isset($map[(int) $row->user_id])) {
                $map[(int) $row->user_id] = (string) $row->meta_value;
            }
        }

        return $map;
    }

    /** @return array phone => user_id (کسی که همین الان این شماره را به‌عنوان phone_number دارد) */
    private function owners_of_phones(array $phones)
    {
        global $wpdb;

        if (!$phones) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value IN (" . implode(',', array_fill(0, count($phones), '%s')) . ")",
            array_merge([self::TARGET_KEY], array_map('strval', $phones))
        ));

        $map = [];
        foreach ((array) $rows as $row) {
            if (!isset($map[$row->meta_value])) {
                $map[$row->meta_value] = (int) $row->user_id;
            }
        }

        return $map;
    }

    /** قالب مقدار ذخیره‌شده (برای گزارش تشخیص خودکار قالب) */
    private function format_label($raw, $phone)
    {
        if ($raw === $phone) {
            return 'standard';
        }

        $digits = OTP_Verifier_Phone_Util::to_ascii_digits($raw);
        if (preg_match('/^9[0-9]{9}$/', $digits)) {
            return 'without_zero';
        }
        if (preg_match('/^(0098|98)9/', $digits)) {
            return 'country_code';
        }

        return 'formatted';
    }

    private function key_warnings($meta_key, $total, array $counts)
    {
        $warnings = [];

        if ($total === 0) {
            $warnings[] = 'empty';
        }
        if (preg_match('/^(billing|shipping)_/i', $meta_key)) {
            $warnings[] = 'unverified_key';
        }
        if ($total > 0 && $counts['invalid'] / $total > 0.3) {
            $warnings[] = 'many_invalid';
        }

        return $warnings;
    }

    /** نام کاربری نمونه‌ها را برای نمایش اضافه می‌کند */
    private function attach_logins(array $samples)
    {
        global $wpdb;

        $ids = [];
        foreach ($samples as $items) {
            foreach ($items as $item) {
                $ids[$item['user_id']] = true;
                if ($item['other_user']) {
                    $ids[$item['other_user']] = true;
                }
            }
        }
        if (!$ids) {
            return $samples;
        }

        $ids = array_keys($ids);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_login FROM {$wpdb->users} WHERE ID IN (" . implode(',', array_fill(0, count($ids), '%d')) . ")",
            $ids
        ));
        $logins = [];
        foreach ((array) $rows as $row) {
            $logins[(int) $row->ID] = $row->user_login;
        }

        foreach ($samples as $status => $items) {
            foreach ($items as $i => $item) {
                $samples[$status][$i]['login'] = $logins[$item['user_id']] ?? '';
                $samples[$status][$i]['other_login'] = $item['other_user'] ? ($logins[$item['other_user']] ?? '') : '';
            }
        }

        return $samples;
    }

    /** شماره را برای نمایش در اسکن ماسک می‌کند: 6 کاراکتر اول می‌ماند، ارقام بعدی x می‌شوند */
    private function mask_raw($raw)
    {
        $raw = (string) $raw;

        return mb_substr($raw, 0, 6) . preg_replace('/[0-9۰-۹٠-٩]/u', 'x', mb_substr($raw, 6));
    }
}
