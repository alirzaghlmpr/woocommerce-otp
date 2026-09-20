/* globals jQuery, Swal, otp_checkout_config */
(function ($) {
  "use strict";

  if (!$) {
    console.error("OTP Verifier: jQuery was not loaded before checkout-otp.js");
    return;
  }

  $(function () {
    const config = window.otp_checkout_config || {};
    const AJAX_URL = config.ajaxurl || "/wp-admin/admin-ajax.php";
    const NONCE = config.nonce || "";
    const MODE = config.mode || "inline";
    // The server always issues 4-6 digit codes; keep the client in the same range.
    const OTP_LENGTH = Math.min(6, Math.max(4, parseInt(config.otp_length || 6, 10) || 6));
    const RESEND_COOLDOWN = parseInt(config.expire || 120, 10);
    // Same limit the server enforces: after 5 wrong codes it deletes the code, so a new one is needed.
    const MAX_VERIFY_ATTEMPTS = 5;
    const MSG = config.messages || {};

    let verifiedPhone = config.verified_phone || "";

    // Persian/Arabic-Indic digits -> ASCII, everything else non-numeric dropped.
    function toAsciiDigits(value) {
      return String(value == null ? "" : value)
        .replace(/[۰-۹]/g, function (c) {
          return String(c.charCodeAt(0) - 0x06f0);
        })
        .replace(/[٠-٩]/g, function (c) {
          return String(c.charCodeAt(0) - 0x0660);
        })
        .replace(/\D/g, "");
    }

    // 09xxxxxxxxx from whatever the customer typed: Persian digits, +98 / 0098 / 98 prefixes, a missing leading zero.
    function normalizePhone(phone) {
      phone = toAsciiDigits(phone);
      if (phone.indexOf("0098") === 0) {
        phone = "0" + phone.slice(4);
      } else if (phone.indexOf("98") === 0 && phone.length === 12) {
        phone = "0" + phone.slice(2);
      } else if (phone.length === 10 && phone.charAt(0) === "9") {
        phone = "0" + phone;
      }
      return phone;
    }

    function validPhone(phone) {
      return /^09[0-9]{9}$/.test(phone);
    }

    function formatTime(seconds) {
      if (seconds > 59) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + ":" + String(s).padStart(2, "0");
      }
      return seconds + " ثانیه";
    }

    // 0912***4567
    function maskPhone(phone) {
      return phone.length === 11 ? phone.slice(0, 4) + "***" + phone.slice(-4) : phone;
    }

    // شماره چپ‌به‌راست تا داخل متن راست‌چین به‌هم نریزد (ورودی فقط رقم و * است، پس امن برای HTML)
    function ltrWrap(text) {
      return '<span style="direction: ltr; display: inline-block; unicode-bidi: embed;">' + text + "</span>";
    }

    // "کد تایید برای شماره 0912***4567 ارسال شد"
    function sentText(phone) {
      return "کد تایید برای شماره " + ltrWrap(maskPhone(phone)) + " ارسال شد";
    }

    /**
     * همان اعلان (toast) صفحهٔ ورود. اگر SweetAlert2 بارگذاری نشده باشد (مثلاً یک افزونه/قالب دیگر آن را
     * حذف کرده)، فقط چیزی نمایش داده نمی‌شود و پیام درون‌خطی زیر فرم همچنان دیده می‌شود.
     */
    function showSwal(icon, title, text, duration, htmlContent) {
      if (typeof window.Swal === "undefined") return;
      window.Swal.fire({
        position: "top-end",
        customClass: { popup: "otp-checkout-swal" },
        icon: icon,
        title: title || "",
        text: !htmlContent ? text : "",
        html: htmlContent ? text : "",
        showConfirmButton: false,
        timer: duration || 2500,
        toast: true,
      });
    }

    // Red border for a moment on a rejected phone field (login page: .otp-input-error)
    function flashInputError($input) {
      $input.addClass("otp-checkout-input--error");
      setTimeout(function () {
        $input.removeClass("otp-checkout-input--error");
      }, 2000);
    }

    function ajaxErrorMessage(jqXHR, textStatus) {
      if (textStatus === "timeout") {
        return "زمان درخواست تمام شد. لطفاً دوباره تلاش کنید.";
      }
      if (jqXHR && jqXHR.status === 429) {
        return MSG.rate_limit || "تعداد درخواست‌های شما بیش از حد است. لطفاً کمی صبر کنید.";
      }
      return "خطا در ارتباط با سرور.";
    }

    function startCooldown($resendBtn, $timer) {
      $resendBtn.data("interval") && clearInterval($resendBtn.data("interval"));
      $resendBtn.prop("disabled", true);
      let remaining = RESEND_COOLDOWN;
      $timer.text(formatTime(remaining));
      const interval = setInterval(function () {
        remaining -= 1;
        if (remaining <= 0) {
          clearInterval(interval);
          $resendBtn.prop("disabled", false);
          $timer.text("");
        } else {
          $timer.text(formatTime(remaining));
        }
      }, 1000);
      $resendBtn.data("interval", interval);
    }

    function stopCooldown($resendBtn, $timer) {
      $resendBtn.data("interval") && clearInterval($resendBtn.data("interval"));
      $resendBtn.prop("disabled", true);
      $timer.text("");
    }

    /**
     * ارسال کد. مثل صفحهٔ ورود: تا پایان درخواست دکمه غیرفعال است و متنش «در حال ارسال...» می‌شود،
     * و بعد به متن قبلی برمی‌گردد. دکمه پیش از صدا زدن onSuccess/onError برمی‌گردد (نه بعد از آن)، تا
     * کاری که onSuccess با دکمه می‌کند (مثلاً غیرفعال کردن «ارسال مجدد» برای شمارش معکوس) از بین نرود.
     */
    function sendOtp(phone, $btn, onSuccess, onError) {
      const originalText = $btn.text();
      $btn.prop("disabled", true).text("در حال ارسال...");

      function restore() {
        $btn.prop("disabled", false).text(originalText);
      }

      $.ajax({
        url: AJAX_URL,
        type: "POST",
        dataType: "json",
        data: { action: "otp_checkout_send", security: NONCE, phone: phone },
        timeout: 15000,
      })
        .done(function (res) {
          restore();
          if (res && res.success) {
            onSuccess();
          } else {
            onError((res && res.data && res.data.message) || MSG.sms_failed || "خطا در ارسال کد.");
          }
        })
        .fail(function (jqXHR, textStatus) {
          restore();
          onError(ajaxErrorMessage(jqXHR, textStatus));
        });
    }

    // onError(message, isNetworkError): network errors don't count as a wrong attempt
    function verifyOtp(phone, code, $btn, onSuccess, onError) {
      const originalText = $btn.text();
      $btn.prop("disabled", true).text("در حال بررسی...");

      function restore() {
        $btn.prop("disabled", false).text(originalText);
      }

      $.ajax({
        url: AJAX_URL,
        type: "POST",
        dataType: "json",
        data: { action: "otp_checkout_verify", security: NONCE, phone: phone, otp: code },
        timeout: 15000,
      })
        .done(function (res) {
          restore();
          if (res && res.success) {
            onSuccess();
          } else {
            onError((res && res.data && res.data.message) || MSG.otp_invalid || "کد اشتباه است.", false);
          }
        })
        .fail(function (jqXHR, textStatus) {
          restore();
          onError(ajaxErrorMessage(jqXHR, textStatus), true);
        });
    }

    /**
     * WebOTP API: روی Chrome/Android، وقتی پیامکی که متنش با `@domain #code`
     * ختم می‌شود دریافت شود، مرورگر کد را مستقیم به این Promise می‌دهد. برای
     * اینکه فرمت مطابقت کند، پترن پیامکی OTP در تنظیمات باید با این پسوند
     * (دامنه سایت شما) ختم شود - این بخش را باید در پنل درگاه پیامکی تنظیم کنید.
     * روی مرورگرهایی که این API را ندارند (از جمله iOS Safari)، این تابع کاری
     * انجام نمی‌دهد و attribute استاندارد autocomplete="one-time-code" جایگزین می‌شود.
     */
    function requestWebOtp(onCode) {
      if (!("OTPCredential" in window) || !navigator.credentials) {
        return null;
      }
      const controller = new AbortController();
      navigator.credentials
        .get({ otp: { transport: ["sms"] }, signal: controller.signal })
        .then(function (cred) {
          console.log("[OTP Verifier] Checkout WebOTP resolved:", cred);
          const digits = toAsciiDigits(cred && cred.code).slice(0, OTP_LENGTH);
          if (digits) {
            onCode(digits);
          } else {
            console.warn("[OTP Verifier] Checkout WebOTP resolved with no usable code.", cred);
          }
        })
        .catch(function (err) {
          // Aborted on purpose (restart / success) is normal; anything else is worth
          // a console line so a silent failure is at least visible in DevTools.
          if (err && err.name === "AbortError") return;
          console.warn("[OTP Verifier] Checkout WebOTP request failed:", err);
        });
      return controller;
    }

    /**
     * WebOTP فقط پیامک‌هایی را می‌بیند که «بعد از» get() برسند، و سرور تنها وقتی جواب
     * می‌دهد که درگاه پیامک را پذیرفته باشد - پس پیامک می‌تواند زودتر از پاسخ سرور برسد.
     * بنابراین: begin() را قبل از درخواست ارسال صدا بزنید، و بعد از پاسخ sent() یا failed().
     * اگر کد قبل از پاسخ رسیده باشد، بعد از نمایش ردیف کد وارد می‌شود.
     */
    function createWebOtpSession(fillCode) {
      let controller = null;
      let sending = false;
      let earlyCode = "";

      function stop() {
        if (controller) {
          controller.abort();
          controller = null;
        }
      }

      return {
        begin: function () {
          stop();
          sending = true;
          earlyCode = "";
          controller = requestWebOtp(function (digits) {
            if (sending) {
              earlyCode = digits;
              return;
            }
            fillCode(digits);
          });
        },
        sent: function () {
          sending = false;
          if (earlyCode) {
            const digits = earlyCode;
            earlyCode = "";
            fillCode(digits);
          }
        },
        failed: function () {
          sending = false;
          earlyCode = "";
          stop();
        },
        stop: stop,
      };
    }

    /**
     * کادر کد تایید به‌صورت چند خانه (مثل صفحهٔ ورود). همهٔ راه‌های ورود کد (تایپ، Paste، Autofill مرورگر،
     * پیشنهاد کیبورد و WebOTP) از یک تابع رد می‌شوند و ارقام را بین خانه‌ها پخش می‌کنند.
     * فقط خانهٔ اول autocomplete="one-time-code" دارد و همهٔ خانه‌ها maxlength = طول کد هستند؛ با maxlength=1
     * کدی که یک‌جا وارد می‌شود (Autofill / پیشنهاد کیبورد) فقط رقم اولش را نگه می‌داشت.
     * با پر شدن همهٔ خانه‌ها onComplete صدا زده می‌شود؛ set() (مثلاً از WebOTP) خودش تایید را صدا نمی‌زند.
     */
    function createCodeBoxes($container, options) {
      const opts = options || {};
      let completeTimer = null;

      $container.empty();
      for (let i = 0; i < OTP_LENGTH; i++) {
        $("<input>", {
          type: "text",
          inputmode: "numeric",
          pattern: "[0-9]*",
          maxlength: OTP_LENGTH,
          autocomplete: i === 0 ? "one-time-code" : "off",
          "data-index": i,
          class: "otp-checkout-box",
          "aria-label": "رقم " + (i + 1) + " از " + OTP_LENGTH,
        }).appendTo($container);
      }
      const $boxes = $container.find("input");

      function value() {
        return toAsciiDigits(
          $boxes
            .map(function () {
              return this.value;
            })
            .get()
            .join("")
        ).slice(0, OTP_LENGTH);
      }

      // Put digits in the boxes starting at startIdx; a complete code always replaces everything.
      function apply(startIdx, raw, applyOptions) {
        const o = applyOptions || {};
        let digits = toAsciiDigits(raw);
        if (!digits) return;

        if (digits.length >= $boxes.length) {
          startIdx = 0;
        }
        digits = digits.slice(0, $boxes.length - startIdx);
        for (let i = 0; i < digits.length; i++) {
          $boxes.eq(startIdx + i).val(digits[i]);
        }
        $boxes.eq(Math.min(startIdx + digits.length, $boxes.length - 1)).trigger("focus");

        const reachedEnd = startIdx + digits.length >= $boxes.length;
        const complete = !$boxes.filter(function () {
          return !this.value;
        }).length;
        if (o.autoComplete !== false && reachedEnd && complete && opts.onComplete) {
          clearTimeout(completeTimer); // the same code can arrive through two paths (WebOTP + autofill)
          completeTimer = setTimeout(opts.onComplete, 150);
        }
      }

      $boxes.on("input", function (e) {
        const idx = parseInt($(this).data("index"), 10);
        let digits = toAsciiDigits(this.value);

        // A digit typed into an already-filled box overwrites it instead of appending.
        if (digits.length === 2) {
          const typed = toAsciiDigits(e.originalEvent && e.originalEvent.data);
          if (typed.length === 1) digits = typed;
        }
        if (!digits) {
          $(this).val("");
          return;
        }
        apply(idx, digits);
      });

      // Some autofill implementations only dispatch "change" after writing the value.
      $boxes.on("change", function () {
        const digits = toAsciiDigits(this.value);
        if (digits.length > 1) {
          apply(parseInt($(this).data("index"), 10), digits);
        }
      });

      $boxes.on("keydown", function (e) {
        const idx = parseInt($(this).data("index"), 10);

        if (e.key === "Backspace") {
          if (!this.value && idx > 0) {
            $boxes.eq(idx - 1).trigger("focus").val("");
          } else {
            this.value = "";
          }
          e.preventDefault();
        } else if (e.key === "Enter") {
          e.preventDefault();
          if (opts.onEnter) opts.onEnter();
        } else if (e.key === "ArrowLeft" && idx > 0) {
          $boxes.eq(idx - 1).trigger("focus");
          e.preventDefault();
        } else if (e.key === "ArrowRight" && idx < $boxes.length - 1) {
          $boxes.eq(idx + 1).trigger("focus");
          e.preventDefault();
        }
      });

      $boxes.on("paste", function (e) {
        e.preventDefault();
        const pasted = (e.originalEvent.clipboardData || window.clipboardData).getData("text");
        apply(parseInt($(this).data("index"), 10), pasted);
      });

      return {
        get: value,
        set: function (digits) {
          apply(0, digits, { autoComplete: false });
        },
        clear: function () {
          $boxes.val("");
        },
        focus: function () {
          $boxes.first().trigger("focus");
        },
      };
    }

    /**
     * همهٔ کارهای «بعد از ارسال کد» که در هر دو حالت (قفل کامل / داخل فرم) یکی است، مثل مرحلهٔ OTP صفحهٔ ورود:
     * ارسال (و ارسال مجدد) با متن «در حال ارسال...» و اعلان، خانه‌های کد، شمارش معکوس، تایید با متن
     * «در حال بررسی...»، اعلان + پیام زیر فرم برای خطا، و حداکثر ۵ تلاش.
     * هر حالت فقط می‌گوید بعد از ارسال چه چیزی نمایش داده شود (afterSent)، بعد از تایید چه شود
     * (onVerified) و وقتی تلاش‌ها تمام شد چه شود (onExhausted).
     * o: { $info, $boxes, $resendBtn, $verifyBtn, $msg, onVerified(phone), onExhausted() }
     */
    function createCodeStep(o) {
      const $timer = $('<span class="otp-checkout-timer"></span>').insertAfter(o.$resendBtn);
      let phone = "";
      let attempts = 0;
      let msgTimer = null;

      const codes = createCodeBoxes(o.$boxes, {
        onComplete: verify,
        onEnter: verify,
      });

      const webOtp = createWebOtpSession(function (digits) {
        console.log("[OTP Verifier] Checkout WebOTP autofilling code and auto-verifying.");
        codes.set(digits);
        verify();
      });

      function hideMsg() {
        clearTimeout(msgTimer);
        o.$msg.addClass("otp-checkout-hidden").text("");
      }

      // Inline message under the form (also what the customer sees if the toast library is missing)
      function showMsg(text) {
        clearTimeout(msgTimer);
        o.$msg.text(text).removeClass("otp-checkout-hidden");
        msgTimer = setTimeout(hideMsg, 5000);
      }

      function notifyError(title, text) {
        showSwal("error", title, text);
        showMsg(text);
      }

      // Leaves the code step: stop listening for the SMS, stop the countdown, forget typed digits and attempts.
      function reset() {
        webOtp.stop();
        stopCooldown(o.$resendBtn, $timer);
        attempts = 0;
        codes.clear();
        hideMsg();
      }

      function send(number, $btn, isResend, afterSent) {
        // Listen for the SMS BEFORE asking the server to send it - see createWebOtpSession.
        webOtp.begin();
        sendOtp(
          number,
          $btn,
          function () {
            phone = number;
            attempts = 0;
            hideMsg();
            if (afterSent) afterSent();
            o.$info.html(sentText(number));
            codes.clear();
            codes.focus();
            startCooldown(o.$resendBtn, $timer);
            showSwal("success", isResend ? "ارسال مجدد" : "ارسال شد", sentText(number), 2000, true);
            webOtp.sent();
          },
          function (message) {
            webOtp.failed();
            notifyError("خطا", message);
          }
        );
      }

      function verify() {
        if (o.$verifyBtn.prop("disabled") || !phone) return;
        const code = codes.get();
        if (code.length < OTP_LENGTH) {
          showSwal("warning", "کد ناقص", "لطفاً کد کامل را وارد کنید.");
          showMsg("لطفاً همه خانه‌ها را پر کنید.");
          codes.focus();
          return;
        }

        verifyOtp(
          phone,
          code,
          o.$verifyBtn,
          function () {
            const verified = phone;
            webOtp.stop();
            stopCooldown(o.$resendBtn, $timer);
            hideMsg();
            showSwal("success", "تایید شد", MSG.otp_verified || "شماره موبایل با موفقیت تایید شد.", 2000);
            o.onVerified(verified);
          },
          function (message, isNetworkError) {
            if (isNetworkError) {
              notifyError("خطا", message);
              return;
            }

            attempts++;
            const remaining = MAX_VERIFY_ATTEMPTS - attempts;
            if (remaining > 0) {
              showSwal("error", "خطا", message);
              showMsg(message + " (تلاش باقی‌مانده: " + remaining + ")");
              codes.clear();
              codes.focus();
              return;
            }

            // Out of attempts: the server dropped the code, so a new one is needed. Give the message a moment, then go back.
            const exhausted = "تعداد تلاش‌های شما به حداکثر رسید. لطفاً کد جدید دریافت کنید.";
            showSwal("error", "خطا", exhausted, 3000);
            showMsg(exhausted);
            o.$verifyBtn.prop("disabled", true);
            setTimeout(function () {
              o.$verifyBtn.prop("disabled", false);
              reset();
              o.onExhausted();
            }, 3000);
          }
        );
      }

      o.$verifyBtn.on("click", verify);

      o.$resendBtn.on("click", function () {
        if (o.$resendBtn.prop("disabled") || !phone) return;
        send(phone, o.$resendBtn, true);
      });

      return {
        send: send,
        reset: reset,
        showMsg: showMsg,
        notifyError: notifyError,
      };
    }

    // Universal safety net: block classic-checkout submission (Enter key, or
    // any trigger) unless the currently-typed billing phone matches the phone
    // verified via AJAX. The authoritative check is still server-side in
    // woocommerce_checkout_process; this is just UX/defense-in-depth.
    $(document.body).on("checkout_place_order", function () {
      const currentPhone = normalizePhone($("#billing_phone").val());
      if (!verifiedPhone || verifiedPhone !== currentPhone) {
        return false;
      }
      return true;
    });

    // ===================== Inline mode =====================
    function initInlineMode() {
      const $widget = $("#otp-checkout-inline");
      if (!$widget.length) return;

      const $billingPhone = $("#billing_phone");
      const $sendBtn = $("#otp-checkout-send-btn");
      const $codeRow = $("#otp-checkout-code-row");
      const $status = $("#otp-checkout-status");

      const step = createCodeStep({
        $info: $("#otp-checkout-info"),
        $boxes: $("#otp-checkout-code"),
        $resendBtn: $("#otp-checkout-resend-btn"),
        $verifyBtn: $("#otp-checkout-verify-btn"),
        $msg: $("#otp-checkout-msg"),
        onVerified: markVerified,
        onExhausted: function () {
          $codeRow.addClass("otp-checkout-hidden");
        },
      });

      function applyPlaceOrderLock() {
        const $placeOrder = $("#place_order");
        if (!$placeOrder.length) return;
        const currentPhone = normalizePhone($billingPhone.val());
        const isVerified = !!verifiedPhone && verifiedPhone === currentPhone;
        $placeOrder.prop("disabled", !isVerified);
      }

      function markVerified(phone) {
        verifiedPhone = phone;
        $status.text("✔ شماره تایید شد").addClass("otp-checkout-status--verified");
        $codeRow.addClass("otp-checkout-hidden");
        $sendBtn.text("تغییر / ارسال مجدد کد").prop("disabled", false);
        applyPlaceOrderLock();
      }

      function markUnverified() {
        verifiedPhone = "";
        $status.text("").removeClass("otp-checkout-status--verified");
        $sendBtn.text("ارسال کد تایید شماره موبایل");
        applyPlaceOrderLock();
      }

      // Changing the billing phone after verifying invalidates the previous
      // verification - prevents "verify my number, then type someone else's".
      $billingPhone.on("input change", function () {
        const current = normalizePhone($billingPhone.val());
        if (verifiedPhone && current !== verifiedPhone) {
          markUnverified();
        }
      });

      $sendBtn.on("click", function () {
        const phone = normalizePhone($billingPhone.val());
        if (!validPhone(phone)) {
          const invalid = MSG.invalid_phone || "شماره موبایل معتبر نیست.";
          showSwal("error", "شماره نامعتبر", invalid);
          step.showMsg(invalid);
          flashInputError($billingPhone);
          $billingPhone.trigger("focus");
          return;
        }
        step.send(phone, $sendBtn, false, function () {
          $codeRow.removeClass("otp-checkout-hidden");
        });
      });

      // WooCommerce replaces #order_review (which contains #place_order) via
      // AJAX whenever checkout totals refresh - re-apply the lock each time.
      $(document.body).on("updated_checkout", applyPlaceOrderLock);

      if (verifiedPhone && normalizePhone($billingPhone.val()) === verifiedPhone) {
        markVerified(verifiedPhone);
      } else {
        applyPlaceOrderLock();
      }
    }

    // ===================== Gate mode =====================
    // This is a step shown before checkout, not a popup: PHP already hides
    // form.woocommerce-checkout via an inline <style> tag before this script
    // even runs (no flash of the form), and on success we just remove that
    // tag - no body-locking classes or aria-hidden juggling needed here.
    // Like the login page: the phone step is replaced by the code step (and the
    // back arrow brings the phone step back).
    function initGateMode() {
      const $gate = $("#otp-checkout-gate");
      if (!$gate.length) return;

      const $phoneRow = $("#otp-checkout-gate-phone-row");
      const $phoneInput = $("#otp-checkout-gate-phone");
      const $sendBtn = $("#otp-checkout-gate-send-btn");
      const $codeRow = $("#otp-checkout-gate-code-row");
      const $backBtn = $("#otp-checkout-gate-back-btn");

      function showPhoneStep() {
        $codeRow.addClass("otp-checkout-hidden");
        $phoneRow.removeClass("otp-checkout-hidden");
        $gate.removeClass("otp-checkout-gate-step--code");
        $phoneInput.trigger("focus");
      }

      function showCodeStep() {
        $phoneRow.addClass("otp-checkout-hidden");
        $codeRow.removeClass("otp-checkout-hidden");
        $gate.addClass("otp-checkout-gate-step--code");
      }

      const step = createCodeStep({
        $info: $("#otp-checkout-gate-info"),
        $boxes: $("#otp-checkout-gate-code"),
        $resendBtn: $("#otp-checkout-gate-resend-btn"),
        $verifyBtn: $("#otp-checkout-gate-verify-btn"),
        $msg: $("#otp-checkout-gate-msg"),
        onVerified: function (phone) {
          verifiedPhone = phone;
          $("#otp-checkout-gate-style").remove();
          $gate.remove();

          const $billingPhone = $("#billing_phone");
          if ($billingPhone.length) {
            $billingPhone.val(phone).prop("readonly", true).trigger("change");
          }
        },
        onExhausted: showPhoneStep,
      });

      if (config.default_phone) {
        $phoneInput.val(config.default_phone);
      }

      function submitPhone() {
        const phone = normalizePhone($phoneInput.val());
        if (!validPhone(phone)) {
          const invalid = MSG.invalid_phone || "شماره موبایل معتبر نیست.";
          showSwal("error", "شماره نامعتبر", invalid);
          step.showMsg(invalid);
          flashInputError($phoneInput);
          $phoneInput.trigger("focus");
          return;
        }
        step.send(phone, $sendBtn, false, showCodeStep);
      }

      $phoneInput.on("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          submitPhone();
        }
      });

      $sendBtn.on("click", submitPhone);

      // «ویرایش شماره»: برگشت به مرحلهٔ شماره (مثل دکمهٔ بازگشت صفحهٔ ورود)
      $backBtn.on("click", function () {
        step.reset();
        showPhoneStep();
      });
    }

    if (MODE === "gate") {
      initGateMode();
    } else {
      initInlineMode();
    }
  });
})(window.jQuery);
