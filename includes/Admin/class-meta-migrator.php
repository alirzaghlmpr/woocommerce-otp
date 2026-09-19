<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once OTP_VERIFIER_PATH . 'includes/Helpers/class-phone-utils.php';

/**
 * مهاجرت شماره موبایل کاربران از یک یا چند user meta (مثلاً افزونه‌های OTP قبلی) به phone_number.
 *
 * ایمن برای سایت زنده:
 * - فقط «اضافه» می‌کند؛ متای قبلی را حذف/تغییر نمی‌دهد و phone_number هیچ کاربری را بازنویسی نمی‌کند.
 * - پیش‌نمایش (analyze) و اجرای واقعی (migrate_batch) از یک منطق طبقه‌بندی مشترک استفاده می‌کنند،
 *   پس آنچه در پیش‌نمایش دیده می‌شود همان چیزی است که اجرا می‌شود.
 * - با چند کلید: ترتیب کلیدها = اولویت. برای هر کاربر «اولین شماره‌ی معتبر» طبق همین ترتیب انتخاب می‌شود
 *   (مقدار نامعتبر در کلید اول، کاربر را به کلید بعدی می‌سپارد) و اختلاف شماره‌ها بین کلیدها گزارش می‌شود.
 * - هر شماره فقط به یک کاربر می‌رسد (شماره‌ی کلید با اولویت بالاتر، بعد رکورد قدیمی‌تر)، تا ورود با شماره مبهم نشود.
 * - رکوردهای نوشته‌شده علامت‌گذاری می‌شوند تا بشود کل مهاجرت را برگرداند (undo_batch).
 */
class OTP_Verifier_Meta_Migrator
{
    const TARGET_KEY = 'phone_number';
    const MARKER_KEY = '_otp_verifier_migrated_from';

    const MAX_KEYS = 10;
    const ANALYZE_CHUNK = 1000;
    const RUN_CHUNK = 200;
    const SAMPLE_LIMIT = 8;
    const ANALYZE_TIME_BUDGET = 40; // seconds

    /** وضعیت‌های ممکن برای هر کاربر، به ترتیب اولویت نمایش */
    const STATUSES = ['ready', 'already_same', 'has_other', 'conflict_existing', 'duplicate_in_source', 'invalid'];

    /**
     * نام یک کلید متا را پاکسازی می‌کند؛ اگر قابل قبول نباشد false برمی‌گرداند.
     */
    public function sanitize_key_name($key)
    {
        $key = sanitize_text_field(wp_unslash((string) $key));

        if ($key === '' || strlen($key) > 255 || $key === self::TARGET_KEY || $key === self::MARKER_KEY) {
            return false;
        }

        return $key;
    }

    /**
     * فهرست کلیدها (جدا شده با کاما، فاصله یا خط جدید) → آرایه‌ی بدون تکرار با همان ترتیب (= اولویت).
     * اگر حتی یکی نامعتبر باشد یا فهرست خالی/خیلی طولانی باشد false برمی‌گرداند.
     */
    public function sanitize_keys($input)
    {
        $parts = preg_split('/[\s,;،]+/u', (string) $input, -1, PREG_SPLIT_NO_EMPTY);
        $keys = [];

        foreach ((array) $parts as $part) {
            $key = $this->sanitize_key_name($part);
            if ($key === false) {
                return false;
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        if (!$keys || count($keys) > self::MAX_KEYS) {
            return false;
        }

        return $keys;
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

            $values = $this->sample_values($row->meta_key, (int) $row->total);
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
     * همهٔ کاربران دارای این کلیدها را بررسی و طبقه‌بندی می‌کند، بدون هیچ نوشتنی.
     * ترتیب $keys اولویت است: کلید اول مهم‌ترین (معتبرترین) منبع شماره است.
     */
    public function analyze(array $keys)
    {
        $this->raise_limits();
        $started = microtime(true);

        $counts = array_fill_keys(self::STATUSES, 0);
        $samples = array_fill_keys(self::STATUSES, []);
        $samples['conflicting'] = [];
        $by_key = [];
        foreach ($keys as $key) {
            $by_key[$key] = ['users' => 0, 'ready' => 0];
        }
        $formats = [];
        $claimed = [];
        $seen = [];
        $total = 0;
        $rows_scanned = 0;
        $nonstandard = 0;
        $multi = 0;
        $conflicting = 0;
        $complete = true;

        foreach ($keys as $key) {
            $after = 0;
            while (true) {
                $rows = $this->fetch_chunk($key, $after, self::ANALYZE_CHUNK);
                if (!$rows) {
                    break;
                }
                $rows_scanned += count($rows);
                $after = (int) end($rows)->umeta_id;

                foreach ($this->classify_rows($rows, $keys, $key, $claimed, $seen) as $item) {
                    if ($item['status'] === 'extra_row') {
                        continue;
                    }
                    $total++;
                    $counts[$item['status']]++;
                    $by_key[$item['key']]['users']++;
                    if ($item['status'] === 'ready') {
                        $by_key[$item['key']]['ready']++;
                    }
                    $nonstandard += (int) $item['nonstandard'];
                    $multi += (int) $item['multi'];

                    if ($item['conflicting']) {
                        $conflicting++;
                        if (count($samples['conflicting']) < self::SAMPLE_LIMIT) {
                            $samples['conflicting'][] = $item;
                        }
                    }
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
                    break 2;
                }
            }
        }

        // کلیدهای billing/shipping شماره‌ی تاییدنشده‌ی خود مشتری‌اند؛ می‌گوییم چند کاربر «فقط» از آن‌ها شماره می‌گیرند
        $unverified = [];
        foreach ($keys as $key) {
            if ($this->is_unverified_key($key) && $by_key[$key]['users'] > 0) {
                $unverified[$key] = $by_key[$key]['users'];
            }
        }

        return [
            'keys'        => $keys,
            'total'       => $total,
            'rows'        => $rows_scanned,
            'counts'      => $counts,
            'by_key'      => $by_key,
            'multi_key'   => ['users' => $multi, 'conflicting' => $conflicting],
            'formats'     => $formats,
            'samples'     => $this->attach_logins($samples),
            'complete'    => $complete,
            'marked'      => $this->count_marked($keys),
            'nonstandard_existing' => $nonstandard,
            'unverified'  => $unverified,
            'warnings'    => $this->warnings($total, $counts, $nonstandard, $unverified),
        ];
    }

    // ------------------------------------------------------------------ اجرا

    /**
     * یک دستهٔ کوچک از کلید شماره $key_index را (رکوردهای بعد از umeta_id = $after_id) مهاجرت می‌دهد.
     * قابل تکرار و قابل ادامه است. خروجی: مکان‌نمای بعدی (next_key_index, last_id) و done.
     */
    public function migrate_batch(array $keys, $key_index, $after_id, $limit = self::RUN_CHUNK)
    {
        $this->raise_limits();

        $key_index = (int) $key_index;
        $limit = (int) $limit;
        $result = [
            'processed' => 0, 'migrated' => 0, 'failed' => 0, 'counts' => [],
            'next_key_index' => $key_index, 'last_id' => (int) $after_id, 'done' => true,
        ];
        if (!isset($keys[$key_index])) {
            return $result;
        }

        $key = $keys[$key_index];
        $rows = $this->fetch_chunk($key, (int) $after_id, $limit);
        $claimed = [];
        $seen = [];

        foreach ($this->classify_rows($rows, $keys, $key, $claimed, $seen) as $item) {
            $result['counts'][$item['status']] = ($result['counts'][$item['status']] ?? 0) + 1;

            if ($item['status'] !== 'ready') {
                continue;
            }

            if (update_user_meta($item['user_id'], self::TARGET_KEY, $item['phone'])) {
                update_user_meta($item['user_id'], self::MARKER_KEY, $item['key']);
                $result['migrated']++;
                otp_verifier_log("✅ Meta Migration [{$item['key']}]: User ID {$item['user_id']} -> " . otp_verifier_mask_phone($item['phone']));
            } else {
                $result['failed']++;
                otp_verifier_log("❌ Meta Migration [{$item['key']}]: failed to write phone_number for User ID {$item['user_id']}");
            }
        }

        $result['processed'] = count($rows);
        $last_id = $rows ? (int) end($rows)->umeta_id : (int) $after_id;

        if (count($rows) < $limit) {          // این کلید تمام شد؛ برو سراغ کلید بعدی
            $result['next_key_index'] = $key_index + 1;
            $result['last_id'] = 0;
            $result['done'] = !isset($keys[$key_index + 1]);
        } else {
            $result['last_id'] = $last_id;
            $result['done'] = false;
        }

        return $result;
    }

    /**
     * تعداد کاربرانی که همین ابزار (از این کلیدها) برایشان phone_number ثبت کرده است.
     */
    public function count_marked(array $keys)
    {
        global $wpdb;

        if (!$keys) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN (" . implode(',', array_fill(0, count($keys), '%s')) . ')',
            array_merge([self::MARKER_KEY], $keys)
        ));
    }

    /**
     * phone_number کاربرانی را که همین ابزار (از این کلیدها) ثبت کرده برمی‌دارد؛ شماره‌هایی که ابزار ثبت نکرده دست نمی‌خورند.
     */
    public function undo_batch(array $keys, $limit = self::RUN_CHUNK)
    {
        global $wpdb;
        $this->raise_limits();

        if (!$keys) {
            return ['removed' => 0, 'done' => true];
        }

        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value IN (" . implode(',', array_fill(0, count($keys), '%s')) . ') LIMIT %d',
            array_merge([self::MARKER_KEY], $keys, [(int) $limit])
        ));

        foreach ($user_ids as $user_id) {
            delete_user_meta((int) $user_id, self::TARGET_KEY);
            delete_user_meta((int) $user_id, self::MARKER_KEY);
            otp_verifier_log("↩️ Meta Migration: reverted User ID {$user_id}");
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

    /**
     * نمونه‌ی مقدارهای یک کلید برای تشخیص «شبیه شماره موبایل بودن».
     * کلیدهای کوچک کامل خوانده می‌شوند؛ برای کلیدهای بزرگ نمونه در کل رکوردها پخش می‌شود (نه فقط چند رکورد اول/آخر که ممکن است
     * قدیمی، تست یا نامعتبر باشند)، وگرنه یک کلیدِ درست می‌توانست به‌خاطر نمونه‌ی بد کنار گذاشته شود.
     */
    private function sample_values($meta_key, $total)
    {
        global $wpdb;

        if ($total > 200) {
            $step = max(2, (int) ceil($total / 60));
            $values = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' AND MOD(umeta_id, %d) = 0 LIMIT 80",
                $meta_key,
                $step
            ));
            if (count($values) >= 10) {
                return $values;
            }
        }

        return $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 200",
            $meta_key
        ));
    }

    private function is_unverified_key($key)
    {
        return (bool) preg_match('/^(billing|shipping)_/i', $key);
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
     * برای هر کاربر مشخص می‌کند شمارهٔ او از کدام کلید گرفته می‌شود (اولین کلیدِ دارای شمارهٔ معتبر به ترتیب اولویت؛
     * اگر هیچ‌کدام معتبر نباشد، اولین کلیدِ دارای مقدار) و در کلیدهای دیگر چه مقدارهایی دارد.
     * فقط از روی دیتابیس محاسبه می‌شود، پس بین دسته‌های جداگانه‌ی اجرا هم همان نتیجه را می‌دهد.
     *
     * @return array user_id => [key, raw, phone(string|false), others[], multi(bool), conflicting(bool)]
     */
    private function plan_users(array $user_ids, array $keys)
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
             WHERE meta_value <> ''
               AND meta_key IN (" . implode(',', array_fill(0, count($keys), '%s')) . ')
               AND user_id IN (' . implode(',', array_fill(0, count($user_ids), '%d')) . ')
             ORDER BY umeta_id ASC',
            array_merge($keys, $user_ids)
        ));

        $found = [];
        foreach ((array) $rows as $row) {
            $uid = (int) $row->user_id;
            if (!isset($found[$uid][$row->meta_key])) {
                $found[$uid][$row->meta_key] = (string) $row->meta_value;
            }
        }

        $plans = [];
        foreach ($user_ids as $uid) {
            $entries = [];
            foreach ($keys as $key) {
                if (isset($found[$uid][$key])) {
                    $raw = $found[$uid][$key];
                    $entries[] = ['key' => $key, 'raw' => $raw, 'phone' => OTP_Verifier_Phone_Util::normalize_iranian_mobile($raw)];
                }
            }
            if (!$entries) {
                continue;
            }

            $winner = $entries[0];
            foreach ($entries as $entry) {
                if ($entry['phone'] !== false) {
                    $winner = $entry;
                    break;
                }
            }

            $others = [];
            $multi = false;
            $conflicting = false;
            foreach ($entries as $entry) {
                if ($entry['key'] === $winner['key']) {
                    continue;
                }
                $others[] = $entry;
                if ($winner['phone'] !== false && $entry['phone'] !== false) {
                    $multi = true;
                    if ($entry['phone'] !== $winner['phone']) {
                        $conflicting = true;
                    }
                }
            }

            $plans[$uid] = ['key' => $winner['key'], 'raw' => $winner['raw'], 'phone' => $winner['phone'],
                            'others' => $others, 'multi' => $multi, 'conflicting' => $conflicting];
        }

        return $plans;
    }

    /**
     * طبقه‌بندی یک دسته رکورد از کلید $row_key. $claimed و $seen بین دسته‌های یک اجرا مشترک‌اند.
     * رکوردی که کلیدش «منبع انتخاب‌شده‌ی» آن کاربر نباشد (یا رکورد تکراری) extra_row است و شمرده نمی‌شود.
     *
     * @return array[] هر آیتم: umeta_id, user_id, key, raw, phone, status, other_user, nonstandard, multi, conflicting, others
     */
    private function classify_rows(array $rows, array $keys, $row_key, array &$claimed, array &$seen)
    {
        $user_ids = [];
        foreach ($rows as $row) {
            $user_ids[(int) $row->user_id] = true;
        }
        $user_ids = array_keys($user_ids);
        if (!$user_ids) {
            return [];
        }

        $plans = $this->plan_users($user_ids, $keys);

        $phones = [];
        foreach ($plans as $plan) {
            if ($plan['key'] === $row_key && $plan['phone'] !== false) {
                $phones[$plan['phone']] = true;
            }
        }
        $own = $this->existing_phones_by_user($user_ids);
        $owners = $this->owners_of_phones(array_keys($phones));

        $items = [];
        foreach ($rows as $row) {
            $user_id = (int) $row->user_id;
            if (!isset($plans[$user_id])) {
                continue;
            }
            $plan = $plans[$user_id];

            $item = [
                'umeta_id'    => (int) $row->umeta_id,
                'user_id'     => $user_id,
                'key'         => $plan['key'],
                'raw'         => $plan['raw'],
                'phone'       => $plan['phone'],
                'status'      => '',
                'other_user'  => 0,
                'nonstandard' => false,
                'multi'       => $plan['multi'],
                'conflicting' => $plan['conflicting'],
                'others'      => $plan['others'],
            ];

            if ($plan['key'] !== $row_key || isset($seen[$user_id])) {
                $item['status'] = 'extra_row';
                $items[] = $item;
                continue;
            }
            $seen[$user_id] = true;

            $phone = $plan['phone'];
            if ($phone === false) {
                $item['status'] = 'invalid';
            } elseif (isset($own[$user_id])) {
                if (OTP_Verifier_Phone_Util::normalize_iranian_mobile($own[$user_id]) === $phone) {
                    $item['status'] = 'already_same';
                    $item['nonstandard'] = ($own[$user_id] !== $phone); // همان شماره، اما مثلاً بدون صفر اول ذخیره شده
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
             WHERE meta_key = %s AND meta_value <> '' AND user_id IN (" . implode(',', array_fill(0, count($user_ids), '%d')) . ')',
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

    /**
     * @return array phone => user_id (کسی که همین الان این شماره را به‌عنوان phone_number دارد)
     * شماره با هر قالب رایج ذخیره‌شده (09…، 9…، 98…، +98…، 0098…) پیدا می‌شود؛ وگرنه یک نفر می‌توانست دو حساب با یک شماره داشته باشد.
     */
    private function owners_of_phones(array $phones)
    {
        global $wpdb;

        if (!$phones) {
            return [];
        }

        $wanted = [];
        $variants = [];
        foreach ($phones as $phone) {
            $phone = (string) $phone;
            $tail = substr($phone, 1); // 9xxxxxxxxx
            $wanted[$phone] = true;
            foreach ([$phone, $tail, '98' . $tail, '+98' . $tail, '0098' . $tail] as $variant) {
                $variants[$variant] = true;
            }
        }
        $variants = array_map('strval', array_keys($variants));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value IN (" . implode(',', array_fill(0, count($variants), '%s')) . ')
             ORDER BY umeta_id ASC',
            array_merge([self::TARGET_KEY], $variants)
        ));

        $map = [];
        foreach ((array) $rows as $row) {
            $normalized = OTP_Verifier_Phone_Util::normalize_iranian_mobile($row->meta_value);
            if ($normalized !== false && isset($wanted[$normalized]) && !isset($map[$normalized])) {
                $map[$normalized] = (int) $row->user_id;
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

    private function warnings($total, array $counts, $nonstandard, array $unverified)
    {
        $warnings = [];

        if ($total === 0) {
            $warnings[] = 'empty';
        }
        if ($unverified) {
            $warnings[] = 'unverified_keys';
        }
        if ($total > 0 && $counts['invalid'] / $total > 0.3) {
            $warnings[] = 'many_invalid';
        }
        if ($nonstandard > 0) {
            $warnings[] = 'nonstandard_existing';
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
            "SELECT ID, user_login FROM {$wpdb->users} WHERE ID IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')',
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
