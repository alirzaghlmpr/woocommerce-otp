/* globals jQuery, otp_checkout_config */
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
    const OTP_LENGTH = parseInt(config.otp_length || 6, 10);
    const RESEND_COOLDOWN = parseInt(config.expire || 120, 10);
    const MSG = config.messages || {};

    let verifiedPhone = config.verified_phone || "";

    function normalizePhone(phone) {
      phone = (phone || "").toString().trim().replace(/[^0-9]/g, "");
      if (phone.indexOf("98") === 0 && phone.length === 12) {
        phone = "0" + phone.slice(2);
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

    function sendOtp(phone, $btn, onSuccess, onError) {
      $btn.prop("disabled", true);
      $.ajax({
        url: AJAX_URL,
        type: "POST",
        dataType: "json",
        data: { action: "otp_checkout_send", security: NONCE, phone: phone },
        timeout: 15000,
      })
        .done(function (res) {
          if (res && res.success) {
            onSuccess();
          } else {
            onError((res && res.data && res.data.message) || MSG.sms_failed || "خطا در ارسال کد.");
          }
        })
        .fail(function (jqXHR, textStatus) {
          onError(textStatus === "timeout" ? "زمان درخواست تمام شد." : "خطا در ارتباط با سرور.");
        })
        .always(function () {
          $btn.prop("disabled", false);
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
          if (cred && cred.code) {
            onCode(cred.code);
          }
        })
        .catch(function () {
          // Aborted / unsupported / no matching SMS - manual entry still works.
        });
      return controller;
    }

    function verifyOtp(phone, code, onSuccess, onError) {
      $.ajax({
        url: AJAX_URL,
        type: "POST",
        dataType: "json",
        data: { action: "otp_checkout_verify", security: NONCE, phone: phone, otp: code },
        timeout: 15000,
      })
        .done(function (res) {
          if (res && res.success) {
            onSuccess();
          } else {
            onError((res && res.data && res.data.message) || MSG.otp_invalid || "کد اشتباه است.");
          }
        })
        .fail(function () {
          onError("خطا در ارتباط با سرور.");
        });
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
      const $codeInput = $("#otp-checkout-code");
      const $verifyBtn = $("#otp-checkout-verify-btn");
      const $resendBtn = $("#otp-checkout-resend-btn");
      const $status = $("#otp-checkout-status");
      const $msg = $("#otp-checkout-msg");
      const $timer = $('<span class="otp-checkout-timer"></span>').insertAfter($resendBtn);

      let lastSentPhone = "";
      let webOtpController = null;

      function startWebOtp() {
        if (webOtpController) webOtpController.abort();
        webOtpController = requestWebOtp(function (code) {
          const digits = code.replace(/\D/g, "").slice(0, OTP_LENGTH);
          if (!digits) return;
          $codeInput.val(digits);
          $verifyBtn.trigger("click");
        });
      }

      function stopWebOtp() {
        if (webOtpController) {
          webOtpController.abort();
          webOtpController = null;
        }
      }

      function showMsg(text) {
        $msg.text(text).removeClass("otp-checkout-hidden");
        setTimeout(function () {
          $msg.addClass("otp-checkout-hidden");
        }, 5000);
      }

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
          showMsg(MSG.invalid_phone || "شماره موبایل معتبر نیست.");
          $billingPhone.trigger("focus");
          return;
        }
        lastSentPhone = phone;
        sendOtp(
          phone,
          $sendBtn,
          function () {
            $codeRow.removeClass("otp-checkout-hidden");
            $codeInput.val("").trigger("focus");
            startCooldown($resendBtn, $timer);
            startWebOtp();
          },
          showMsg
        );
      });

      $resendBtn.on("click", function () {
        if ($resendBtn.prop("disabled") || !lastSentPhone) return;
        sendOtp(
          lastSentPhone,
          $resendBtn,
          function () {
            startCooldown($resendBtn, $timer);
            startWebOtp();
          },
          showMsg
        );
      });

      $verifyBtn.on("click", function () {
        const code = ($codeInput.val() || "").trim();
        if (code.length < OTP_LENGTH) {
          showMsg("لطفاً کد کامل را وارد کنید.");
          return;
        }
        $verifyBtn.prop("disabled", true);
        verifyOtp(
          lastSentPhone,
          code,
          function () {
            stopWebOtp();
            markVerified(lastSentPhone);
          },
          function (message) {
            showMsg(message);
            $verifyBtn.prop("disabled", false);
          }
        );
      });

      $codeInput.on("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          $verifyBtn.trigger("click");
        }
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
    function initGateMode() {
      const $gate = $("#otp-checkout-gate");
      if (!$gate.length) return;

      const $phoneInput = $("#otp-checkout-gate-phone");
      const $sendBtn = $("#otp-checkout-gate-send-btn");
      const $codeRow = $("#otp-checkout-gate-code-row");
      const $codeInput = $("#otp-checkout-gate-code");
      const $verifyBtn = $("#otp-checkout-gate-verify-btn");
      const $resendBtn = $("#otp-checkout-gate-resend-btn");
      const $msg = $("#otp-checkout-gate-msg");
      const $timer = $('<span class="otp-checkout-timer"></span>').insertAfter($resendBtn);

      if (config.default_phone) {
        $phoneInput.val(config.default_phone);
      }

      let lastSentPhone = "";
      let webOtpController = null;

      function startWebOtp() {
        if (webOtpController) webOtpController.abort();
        webOtpController = requestWebOtp(function (code) {
          const digits = code.replace(/\D/g, "").slice(0, OTP_LENGTH);
          if (!digits) return;
          $codeInput.val(digits);
          $verifyBtn.trigger("click");
        });
      }

      function stopWebOtp() {
        if (webOtpController) {
          webOtpController.abort();
          webOtpController = null;
        }
      }

      function showMsg(text) {
        $msg.text(text).removeClass("otp-checkout-hidden");
      }

      $sendBtn.on("click", function () {
        const phone = normalizePhone($phoneInput.val());
        if (!validPhone(phone)) {
          showMsg(MSG.invalid_phone || "شماره موبایل معتبر نیست.");
          return;
        }
        lastSentPhone = phone;
        sendOtp(
          phone,
          $sendBtn,
          function () {
            $codeRow.removeClass("otp-checkout-hidden");
            $codeInput.val("").trigger("focus");
            startCooldown($resendBtn, $timer);
            startWebOtp();
            $msg.addClass("otp-checkout-hidden");
          },
          showMsg
        );
      });

      $resendBtn.on("click", function () {
        if ($resendBtn.prop("disabled") || !lastSentPhone) return;
        sendOtp(
          lastSentPhone,
          $resendBtn,
          function () {
            startCooldown($resendBtn, $timer);
            startWebOtp();
          },
          showMsg
        );
      });

      $verifyBtn.on("click", function () {
        const code = ($codeInput.val() || "").trim();
        if (code.length < OTP_LENGTH) {
          showMsg("لطفاً کد کامل را وارد کنید.");
          return;
        }
        $verifyBtn.prop("disabled", true);
        verifyOtp(
          lastSentPhone,
          code,
          function () {
            stopWebOtp();
            verifiedPhone = lastSentPhone;
            $("#otp-checkout-gate-style").remove();
            $gate.remove();

            const $billingPhone = $("#billing_phone");
            if ($billingPhone.length) {
              $billingPhone.val(lastSentPhone).prop("readonly", true).trigger("change");
            }
          },
          function (message) {
            showMsg(message);
            $verifyBtn.prop("disabled", false);
          }
        );
      });

      $codeInput.on("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          $verifyBtn.trigger("click");
        }
      });
    }

    if (MODE === "gate") {
      initGateMode();
    } else {
      initInlineMode();
    }
  });
})(window.jQuery);
