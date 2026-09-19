<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once OTP_VERIFIER_PATH . 'includes/Admin/class-meta-migrator.php';

/**
 * صفحهٔ «مهاجرت کاربران»: پیدا کردن کلید متا → پیش‌نمایش (بدون تغییر) → تایید و اجرا (+ امکان بازگردانی).
 * همهٔ عملیات از طریق AJAX و با nonce و دسترسی manage_options انجام می‌شود.
 */
class OTP_Verifier_Migration_Page
{
    const SLUG = 'otp-verifier-migration';
    const NONCE_ACTION = 'otp_verifier_migration';

    private $migrator;

    public function __construct()
    {
        $this->migrator = new OTP_Verifier_Meta_Migrator();

        add_action('admin_menu', [$this, 'add_menu'], 20);
        foreach (['scan', 'find', 'preview', 'run', 'undo'] as $action) {
            add_action('wp_ajax_otp_verifier_mig_' . $action, [$this, 'ajax_' . $action]);
        }
    }

    public function add_menu()
    {
        add_submenu_page(
            'otp-verifier',
            'مهاجرت کاربران از افزونه‌ی قبلی',
            'مهاجرت کاربران',
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    // ------------------------------------------------------------------ AJAX

    private function guard()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز یا نشست منقضی شده؛ صفحه را دوباره باز کنید.'], 403);
        }
    }

    private function requested_key()
    {
        $key = $this->migrator->sanitize_key_name($_POST['key'] ?? '');
        if ($key === false) {
            wp_send_json_error(['message' => 'نام کلید متا معتبر نیست.']);
        }

        return $key;
    }

    public function ajax_scan()
    {
        $this->guard();
        wp_send_json_success(['html' => $this->render_candidates($this->migrator->scan_candidate_keys())]);
    }

    public function ajax_find()
    {
        $this->guard();
        $result = $this->migrator->find_keys_by_number(wp_unslash($_POST['number'] ?? ''));
        if ($result === false) {
            wp_send_json_error(['message' => 'شماره‌ی وارد شده یک شماره موبایل معتبر ایران نیست.']);
        }
        wp_send_json_success(['html' => $this->render_found($result)]);
    }

    public function ajax_preview()
    {
        $this->guard();
        $analysis = $this->migrator->analyze($this->requested_key());

        wp_send_json_success([
            'html'     => $this->render_preview($analysis),
            'key'      => $analysis['key'],
            'total'    => $analysis['total'],
            'ready'    => $analysis['counts']['ready'],
            'complete' => $analysis['complete'],
            'marked'   => $analysis['marked'],
        ]);
    }

    public function ajax_run()
    {
        $this->guard();
        if (($_POST['backup'] ?? '') !== '1') {
            wp_send_json_error(['message' => 'ابتدا تایید کنید که از دیتابیس نسخه پشتیبان گرفته‌اید.']);
        }

        wp_send_json_success($this->migrator->migrate_batch($this->requested_key(), absint($_POST['after'] ?? 0)));
    }

    public function ajax_undo()
    {
        $this->guard();
        wp_send_json_success($this->migrator->undo_batch($this->requested_key()));
    }

    // ------------------------------------------------------------------ نمایش نتیجه‌ها (HTML امن‌شده)

    private function status_labels()
    {
        return [
            'ready'               => 'آماده‌ی مهاجرت (شماره‌ی جدید ثبت می‌شود)',
            'already_same'        => 'قبلاً ثبت شده / شماره یکسان است (بدون تغییر)',
            'has_other'           => 'کاربر از قبل شماره‌ی دیگری دارد (دست نمی‌خورد)',
            'conflict_existing'   => 'این شماره برای کاربر دیگری ثبت شده است (رد می‌شود)',
            'duplicate_in_source' => 'شماره‌ی تکراری بین چند کاربر (فقط اولین کاربر می‌گیرد)',
            'invalid'             => 'شماره‌ی نامعتبر (رد می‌شود)',
        ];
    }

    private function format_labels()
    {
        return [
            'standard'     => '09121234567 (استاندارد)',
            'without_zero' => '9121234567 (بدون صفر اول)',
            'country_code' => '+98912… / 98912… / 0098912… (با کد کشور)',
            'formatted'    => 'با فاصله، خط تیره یا ارقام فارسی',
        ];
    }

    private function render_candidates(array $list)
    {
        if (!$list) {
            return '<p>کلیدی که مقدارهایش شبیه شماره موبایل باشد پیدا نشد. از «جستجو با یک شماره‌ی مشخص» استفاده کنید.</p>';
        }

        $html = '<table class="widefat striped"><thead><tr><th>کلید متا</th><th>تعداد کاربران</th><th>شباهت به شماره موبایل</th><th>نمونه</th><th></th></tr></thead><tbody>';
        foreach ($list as $row) {
            $html .= '<tr><td><code>' . esc_html($row['key']) . '</code></td>'
                . '<td>' . esc_html(number_format_i18n($row['total'])) . '</td>'
                . '<td>' . esc_html($row['match']) . '٪</td>'
                . '<td><code dir="ltr">' . esc_html($row['example']) . '</code></td>'
                . '<td><button type="button" class="button otp-mig-use-key" data-key="' . esc_attr($row['key']) . '">پیش‌نمایش این کلید</button></td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function render_found(array $result)
    {
        $html = '';

        if ($result['keys']) {
            $html .= '<table class="widefat striped"><thead><tr><th>کلید متا</th><th>تعداد رکورد مشابه</th><th>مقدار ذخیره‌شده (ماسک‌شده)</th><th>نمونه کاربر</th><th></th></tr></thead><tbody>';
            foreach ($result['keys'] as $row) {
                $html .= '<tr><td><code>' . esc_html($row['key']) . '</code></td>'
                    . '<td>' . esc_html(number_format_i18n($row['total'])) . '</td>'
                    . '<td><code dir="ltr">' . esc_html($row['example']) . '</code></td>'
                    . '<td>#' . esc_html($row['user_id']) . '</td>'
                    . '<td><button type="button" class="button otp-mig-use-key" data-key="' . esc_attr($row['key']) . '">پیش‌نمایش این کلید</button></td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<p>این شماره در هیچ کلید user meta ذخیره نشده است.</p>';
        }

        if ($result['in_user_login'] > 0) {
            $html .= '<p><strong>توجه:</strong> شماره‌ی وارد شده در «نام کاربری» ' . esc_html(number_format_i18n($result['in_user_login']))
                . ' حساب هم دیده شد. اگر افزونه‌ی قبلی شماره را به‌عنوان نام کاربری ثبت می‌کرده، کلید متایی وجود ندارد که با این ابزار مهاجرت شود.</p>';
        }

        return $html;
    }

    private function render_preview(array $a)
    {
        $labels = $this->status_labels();
        $html = '<h3>نتیجه برای کلید <code>' . esc_html($a['key']) . '</code></h3>';

        $messages = [
            'empty'          => 'هیچ کاربری با مقدار غیرخالی برای این کلید پیدا نشد؛ نام کلید را بررسی کنید.',
            'unverified_key' => 'این کلید (billing/shipping) معمولاً شماره‌ای است که خود مشتری در فرم پرداخت وارد کرده و تایید نشده است. اگر کسی شماره‌ی دیگری را وارد کرده باشد، صاحب آن شماره می‌تواند با OTP وارد حساب او شود. فقط کلیدی را مهاجرت دهید که شماره در آن با کد تایید فعال شده باشد.',
            'many_invalid'   => 'بیش از ۳۰٪ مقدارها شماره موبایل معتبر نیستند؛ مطمئن شوید کلید درست را انتخاب کرده‌اید.',
        ];
        foreach ($a['warnings'] as $code) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html($messages[$code]) . '</p></div>';
        }
        if (!$a['complete']) {
            $html .= '<div class="notice notice-error inline"><p>تعداد رکوردها به حدی زیاد است که بررسی در زمان مجاز کامل نشد؛ برای امنیت، اجرا غیرفعال است.</p></div>';
        }

        $html .= '<p>تعداد کاربران دارای این کلید: <strong>' . esc_html(number_format_i18n($a['total'])) . '</strong></p>';

        $html .= '<table class="widefat striped" style="max-width:720px"><tbody>';
        foreach ($labels as $status => $label) {
            $style = $status === 'ready' ? ' style="font-weight:600"' : '';
            $html .= '<tr' . $style . '><td>' . esc_html($label) . '</td><td>' . esc_html(number_format_i18n($a['counts'][$status])) . '</td></tr>';
        }
        $html .= '</tbody></table>';

        if ($a['formats']) {
            $html .= '<h4>قالب‌های شماره که تشخیص داده شد (همه به 09xxxxxxxxx تبدیل می‌شوند)</h4><ul style="list-style:disc;margin-right:20px">';
            foreach ($this->format_labels() as $code => $label) {
                if (!empty($a['formats'][$code])) {
                    $html .= '<li>' . esc_html($label) . ': <strong>' . esc_html(number_format_i18n($a['formats'][$code])) . '</strong></li>';
                }
            }
            $html .= '</ul>';
        }

        foreach ($labels as $status => $label) {
            if (empty($a['samples'][$status])) {
                continue;
            }
            $html .= '<h4>نمونه: ' . esc_html($label) . '</h4><table class="widefat striped" style="max-width:720px"><thead><tr><th>کاربر</th><th>مقدار فعلی</th><th>شماره‌ی استاندارد</th><th></th></tr></thead><tbody>';
            foreach ($a['samples'][$status] as $item) {
                $note = '';
                if ($item['other_user']) {
                    $note = 'کاربر #' . $item['other_user'] . ($item['other_login'] !== '' ? ' (' . $item['other_login'] . ')' : '');
                }
                $html .= '<tr><td>#' . esc_html($item['user_id']) . ($item['login'] !== '' ? ' (' . esc_html($item['login']) . ')' : '') . '</td>'
                    . '<td><code dir="ltr">' . esc_html($item['raw']) . '</code></td>'
                    . '<td><code dir="ltr">' . esc_html($item['phone'] === false ? '—' : $item['phone']) . '</code></td>'
                    . '<td>' . esc_html($note) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if ($a['marked'] > 0) {
            $html .= '<p>' . esc_html(number_format_i18n($a['marked'])) . ' کاربر قبلاً با همین ابزار از این کلید مهاجرت داده شده‌اند (امکان بازگردانی پایین صفحه فعال است).</p>';
        }

        return $html;
    }

    // ------------------------------------------------------------------ صفحه

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $config = ['ajaxurl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(self::NONCE_ACTION)];
?>
        <style>
            .otp-mig-card { background: #fff; border: 1px solid #ccd0d4; padding: 12px 20px 20px; margin: 16px 0; max-width: 980px; }
            .otp-mig-card h2 { margin-top: 8px; }
            .otp-mig-out { margin-top: 12px; }
            .otp-mig-out .notice.inline { margin: 8px 0; }
            #otp-mig-progress { width: 100%; max-width: 480px; height: 22px; }
        </style>
        <div class="wrap">
            <h1>مهاجرت کاربران از افزونه‌ی OTP قبلی</h1>
            <p>با این ابزار شماره موبایل کاربران از یک کلید user meta (که افزونه‌ی قبلی استفاده می‌کرد) به فیلد این افزونه (<code>phone_number</code>) کپی می‌شود تا کاربران قبلی با همان شماره وارد شوند.</p>
            <p><strong>این ابزار فقط «اضافه» می‌کند:</strong> متای قبلی حذف یا تغییر نمی‌کند، شماره‌ی ثبت‌شده‌ی هیچ کاربری بازنویسی نمی‌شود و اگر یک شماره برای چند کاربر باشد فقط به اولین کاربر می‌رسد. هیچ تغییری بدون پیش‌نمایش و تایید شما انجام نمی‌شود.</p>

            <div class="otp-mig-card">
                <h2>۱) پیدا کردن کلید متا</h2>
                <p>اگر نام کلید را می‌دانید مستقیم به مرحله‌ی ۲ بروید. در غیر این صورت یکی از دو روش زیر را استفاده کنید (هر دو فقط می‌خوانند):</p>
                <p>
                    <button type="button" class="button" id="otp-mig-scan-btn">اسکن خودکار کلیدهایی که شبیه شماره موبایل‌اند</button>
                </p>
                <div id="otp-mig-scan-out" class="otp-mig-out"></div>
                <p>
                    <label for="otp-mig-find-number">یا شماره‌ی یک کاربر قدیمی را که می‌شناسید (مثلاً شماره‌ی خودتان) وارد کنید تا ببینید در کدام کلید ذخیره شده:</label><br>
                    <input type="text" id="otp-mig-find-number" dir="ltr" placeholder="09123456789" class="regular-text">
                    <button type="button" class="button" id="otp-mig-find-btn">جستجو</button>
                </p>
                <div id="otp-mig-find-out" class="otp-mig-out"></div>
            </div>

            <div class="otp-mig-card">
                <h2>۲) پیش‌نمایش</h2>
                <p>
                    <input type="text" id="otp-mig-key" dir="ltr" placeholder="مثلاً digits_phone_no" class="regular-text">
                    <button type="button" class="button button-primary" id="otp-mig-preview-btn">پیش‌نمایش (بدون هیچ تغییری)</button>
                </p>
                <div id="otp-mig-preview-out" class="otp-mig-out"></div>
            </div>

            <div class="otp-mig-card" id="otp-mig-run" style="display:none">
                <h2>۳) تایید و اجرا</h2>
                <p><label><input type="checkbox" id="otp-mig-backup"> از دیتابیس (به‌خصوص جدول <code>usermeta</code>) نسخه‌ی پشتیبان گرفته‌ام</label></p>
                <p><button type="button" class="button button-primary" id="otp-mig-start-btn" disabled>شروع مهاجرت</button></p>
                <progress id="otp-mig-progress" value="0" max="1" style="display:none"></progress>
                <div id="otp-mig-report" class="otp-mig-out"></div>
            </div>

            <div class="otp-mig-card" id="otp-mig-undo" style="display:none">
                <h2>بازگردانی</h2>
                <p>شماره‌هایی که همین ابزار از این کلید ثبت کرده برداشته می‌شود؛ شماره‌هایی که این ابزار ثبت نکرده (مثلاً کاربرانی که خودشان با این افزونه ثبت‌نام کردند) دست نمی‌خورند. متای قبلی هیچ‌وقت تغییر نکرده است.</p>
                <p><button type="button" class="button" id="otp-mig-undo-btn">بازگردانی مهاجرت این کلید</button></p>
                <div id="otp-mig-undo-report" class="otp-mig-out"></div>
            </div>
        </div>

        <script>
            jQuery(function($) {
                var cfg = <?php echo wp_json_encode($config); ?>;
                var state = null; // نتیجه‌ی آخرین پیش‌نمایش موفق
                var busy = false;

                function post(action, data) {
                    return $.ajax({
                        url: cfg.ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: $.extend({ action: 'otp_verifier_mig_' + action, nonce: cfg.nonce }, data),
                        timeout: 120000
                    });
                }

                function notice(type, text) {
                    return $('<div class="notice inline"></div>').addClass('notice-' + type).append($('<p></p>').text(text));
                }

                function errorText(xhr) {
                    var r = xhr && xhr.responseJSON;
                    return (r && r.data && r.data.message) || 'خطا در ارتباط با سرور. دوباره تلاش کنید.';
                }

                function setBusy(on) {
                    busy = on;
                    $('#otp-mig-scan-btn, #otp-mig-find-btn, #otp-mig-preview-btn, #otp-mig-undo-btn').prop('disabled', on);
                    $('#otp-mig-start-btn').prop('disabled', on || !$('#otp-mig-backup').is(':checked'));
                }

                function hideActions() {
                    state = null;
                    $('#otp-mig-run, #otp-mig-undo').hide();
                }

                function simpleSearch(action, data, $btn, $out) {
                    if (busy) return;
                    setBusy(true);
                    $out.empty();
                    post(action, data).done(function(res) {
                        if (res.success) { $out.html(res.data.html); } else { $out.empty().append(notice('error', res.data.message)); }
                    }).fail(function(xhr) {
                        $out.empty().append(notice('error', errorText(xhr)));
                    }).always(function() { setBusy(false); });
                }

                function preview(keepReport) {
                    var key = $.trim($('#otp-mig-key').val());
                    var $out = $('#otp-mig-preview-out');
                    if (!key) { $out.empty().append(notice('error', 'نام کلید متا را وارد کنید.')); return $.Deferred().resolve().promise(); }
                    hideActions();
                    if (!keepReport) { $('#otp-mig-report, #otp-mig-undo-report').empty(); }
                    $out.empty().append(notice('info', 'در حال بررسی کاربران…'));
                    return post('preview', { key: key }).done(function(res) {
                        if (!res.success) { $out.empty().append(notice('error', res.data.message)); return; }
                        $out.html(res.data.html);
                        state = res.data;
                        if (state.ready > 0 && state.complete) {
                            $('#otp-mig-backup').prop('checked', false);
                            $('#otp-mig-progress').hide();
                            $('#otp-mig-run').show();
                        }
                        if (state.marked > 0) { $('#otp-mig-undo').show(); }
                    }).fail(function(xhr) {
                        $out.empty().append(notice('error', errorText(xhr)));
                    });
                }

                $('#otp-mig-scan-btn').on('click', function() {
                    simpleSearch('scan', {}, $(this), $('#otp-mig-scan-out'));
                });
                $('#otp-mig-find-btn').on('click', function() {
                    simpleSearch('find', { number: $('#otp-mig-find-number').val() }, $(this), $('#otp-mig-find-out'));
                });
                $(document).on('click', '.otp-mig-use-key', function() {
                    $('#otp-mig-key').val($(this).data('key'));
                    $('#otp-mig-preview-btn').trigger('click');
                    $('html, body').animate({ scrollTop: $('#otp-mig-key').offset().top - 80 }, 200);
                });
                $('#otp-mig-key').on('input', hideActions);
                $('#otp-mig-preview-btn').on('click', function() {
                    if (busy) return;
                    setBusy(true);
                    preview(false).always(function() { setBusy(false); });
                });
                $('#otp-mig-backup').on('change', function() {
                    $('#otp-mig-start-btn').prop('disabled', busy || !this.checked);
                });

                $('#otp-mig-start-btn').on('click', function() {
                    if (busy || !state || !$('#otp-mig-backup').is(':checked')) return;
                    if (!window.confirm('شماره‌ی ' + state.ready + ' کاربر برای کلید «' + state.key + '» به phone_number اضافه می‌شود. ادامه می‌دهید؟')) return;

                    var key = state.key, total = state.total; // ویرایش کادر کلید در حین اجرا نباید اجرا را خراب کند
                    var acc = { processed: 0, migrated: 0, failed: 0 };
                    var $bar = $('#otp-mig-progress').attr('max', Math.max(total, 1)).val(0).show();
                    var $report = $('#otp-mig-report').empty();
                    setBusy(true);

                    (function next(after) {
                        post('run', { key: key, after: after, backup: 1 }).done(function(res) {
                            if (!res.success) { $report.append(notice('error', res.data.message)); setBusy(false); return; }
                            var d = res.data;
                            acc.processed += d.processed; acc.migrated += d.migrated; acc.failed += d.failed;
                            $bar.attr('max', Math.max(total, acc.processed)).val(acc.processed);
                            if (!d.done) { next(d.last_id); return; }
                            $report.empty().append(notice(acc.failed ? 'warning' : 'success',
                                'مهاجرت تمام شد: ' + acc.migrated + ' کاربر مهاجرت داده شد' + (acc.failed ? '، ' + acc.failed + ' مورد با خطا مواجه شد (جزئیات در لاگ)' : '') + '.'));
                            preview(true).always(function() { setBusy(false); });
                        }).fail(function(xhr) {
                            $report.append(notice('error', errorText(xhr) + ' می‌توانید دوباره «شروع مهاجرت» را بزنید؛ کاربرانِ انجام‌شده دوباره تغییر نمی‌کنند.'));
                            setBusy(false);
                        });
                    })(0);
                });

                $('#otp-mig-undo-btn').on('click', function() {
                    if (busy || !state) return;
                    if (!window.confirm('شماره‌هایی که این ابزار از کلید «' + state.key + '» ثبت کرده برداشته می‌شود. ادامه می‌دهید؟')) return;
                    var key = state.key;
                    var removed = 0;
                    var $report = $('#otp-mig-undo-report').empty();
                    setBusy(true);
                    (function next() {
                        post('undo', { key: key }).done(function(res) {
                            if (!res.success) { $report.append(notice('error', res.data.message)); setBusy(false); return; }
                            removed += res.data.removed;
                            if (!res.data.done) { next(); return; }
                            $report.empty().append(notice('success', 'بازگردانی انجام شد: شماره‌ی ' + removed + ' کاربر برداشته شد.'));
                            preview(true).always(function() { setBusy(false); });
                        }).fail(function(xhr) {
                            $report.append(notice('error', errorText(xhr)));
                            setBusy(false);
                        });
                    })();
                });
            });
        </script>
<?php
    }
}
