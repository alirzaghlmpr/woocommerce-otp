/* globals jQuery, Swal, otp_ajax */
(function ($) {
  "use strict";

  $(function () {
    // ==================== DOM Elements ====================
    const $loginStep = $("#loginStep");
    const $signupStep = $("#signupStep");
    const $phoneLoginStep = $("#phoneLoginStep");
    const $otpStep = $("#otpStep");
    const $signupPhoneInput = $("#signupPhoneInput");
    const $signupForm = $("#signupForm");
    const $passwordLoginForm = $("#passwordLoginForm");
    const $phoneLoginForm = $("#phoneLoginForm");
    const $loginUsername = $("#loginUsername");
    const $loginPassword = $("#loginPassword");
    const $signupUsername = $("#signupUsername");
    const $signupPassword = $("#signupPassword");
    const $toggleLoginPassword = $("#toggleLoginPassword");
    const $toggleSignupPassword = $("#toggleSignupPassword");
    const $loginPasswordEye = $("#loginPasswordEye");
    const $signupPasswordEye = $("#signupPasswordEye");
    const $toggleToSignup = $("#toggleToSignup");
    const $toggleToLogin = $("#toggleToLogin");
    const $toggleToPhoneLogin = $("#toggleToPhoneLogin");
    const $togglePhoneToLogin = $("#togglePhoneToLogin");
    const $phoneLoginInput = $("#phoneLoginInput");
    const $otpInputsContainer = $("#otpInputs");
    const $verifyOtpBtn = $("#verifyOtpBtn");
    const $backBtn = $("#backBtn");
    const $otpInfo = $("#otpInfo");
    const $resendBtn = $("#resendBtn");
    const $resendTimer = $("#resendTimer");
    const $otpMsg = $("#otpMsg");

    // ==================== Settings from PHP ====================
    const OTP_LENGTH = parseInt(otp_ajax.otp_length || 6, 10);
    const RESEND_COOLDOWN = parseInt(otp_ajax.expire || 120, 10);
    const AJAX_URL = otp_ajax.ajaxurl || "/wp-admin/admin-ajax.php";
    const NONCE = otp_ajax.nonce || "";
    const MSG = otp_ajax.messages || {};

    // ==================== State ====================
    let resendInterval = null;
    let lastPhone = "";
    let verifyAttempts = 0;
    const MAX_VERIFY_ATTEMPTS = 5;
    let signupPayload = { username: "", password: "" };
    let lastFlow = "signup"; // signup | phone
    const ASSETS_URL = otp_ajax.assets_url || "";
    const eyeOpen = ASSETS_URL + "images/svg/eye-login-page.svg";
    const eyeClosed = ASSETS_URL + "images/svg/eye-close-login-page.svg";

    function showFlow(flow) {
      $loginStep.addClass("otp-hidden");
      $signupStep.addClass("otp-hidden");
      $phoneLoginStep.addClass("otp-hidden");
      $otpStep.addClass("otp-hidden");

      if (flow === "login") $loginStep.removeClass("otp-hidden");
      else if (flow === "signup") $signupStep.removeClass("otp-hidden");
      else if (flow === "phone") $phoneLoginStep.removeClass("otp-hidden");
      else if (flow === "otp") $otpStep.removeClass("otp-hidden");
    }

    function togglePassword($input, $eyeImg) {
      const currentType = $input.attr("type") === "password" ? "text" : "password";
      $input.attr("type", currentType);
      $eyeImg.attr("src", currentType === "password" ? eyeClosed : eyeOpen);
    }

    // ==================== Helper Functions ====================

    /**
     * نرمال‌سازی شماره موبایل
     */
    function normalizePhone(phone) {
      phone = (phone || "")
        .toString()
        .trim()
        .replace(/[\s\-۰-۹]/g, function (c) {
          // تبدیل اعداد فارسی به انگلیسی
          const persianDigits = "۰۱۲۳۴۵۶۷۸۹";
          const index = persianDigits.indexOf(c);
          return index !== -1 ? index.toString() : c;
        });

      // حذف کاراکترهای غیر عددی
      phone = phone.replace(/[^0-9+]/g, "");

      // تبدیل +98 به 0
      if (phone.indexOf("+98") === 0) {
        phone = "0" + phone.slice(3);
      } else if (phone.indexOf("98") === 0 && phone.length === 12) {
        phone = "0" + phone.slice(2);
      }

      return phone;
    }

    /**
     * اعتبارسنجی شماره موبایل ایرانی
     */
    function validPhone(phone) {
      phone = normalizePhone(phone);
      // فرمت: 09xxxxxxxxx (11 رقم)
      return /^09[0-9]{9}$/.test(phone);
    }

    /**
     * مسک کردن شماره موبایل (0912***4567)
     */
    function maskPhone(phone) {
      if (phone.length !== 11) return phone;
      // [تغییر ۲] اصلاح برش برای نمایش استاندارد LTR در متون RTL: 0912***4567
      return phone.slice(0, 4) + "***" + phone.slice(-4);
    }

    /**
     * [جدید] پوشش‌دهی رشته LTR برای نمایش صحیح درون متن RTL
     */
    function ltrWrap(text) {
      // استفاده از تگ span با direction: ltr برای تضمین نمایش صحیح شماره
      return `<span style="direction: ltr; display: inline-block; unicode-bidi: embed;">${text}</span>`;
    }

    /**
     * نمایش پیغام با SweetAlert2
     */
    function showSwal(icon, title, text, duration = 2500, htmlContent = false) {
      Swal.fire({
        position: "top-end",
        customClass: { popup: "swal-rect" },
        icon: icon,
        title: title || "",
        text: !htmlContent ? text : "",
        html: htmlContent ? text : "",
        showConfirmButton: false,
        timer: duration,
        toast: true,
      });
    }

    /**
     * نمایش خطا در زیر فرم OTP
     */
    function showOtpError(message) {
      $otpMsg.text(message).removeClass("otp-hidden");
      setTimeout(() => $otpMsg.addClass("otp-hidden").text(""), 5000);
    }

    /**
     * فرمت زمان (120 ثانیه -> 2:00)
     */
    function formatTime(seconds) {
      if (seconds > 59) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m}:${String(s).padStart(2, "0")}`;
      }
      return `${seconds} ثانیه`;
    }

    /**
     * شروع تایمر Cooldown برای دکمه ارسال مجدد
     */
    function startResendCooldown(phoneNumber, serverExpireTime = null) {
      clearInterval(resendInterval);
      $resendBtn.prop("disabled", true).text("ارسال مجدد");

      let expireTime;

      if (serverExpireTime) {
        // اگر سرور زمان انقضا داده، از اون استفاده کن
        expireTime = serverExpireTime;
      } else {
        // در غیر این صورت از تنظیمات استفاده کن
        expireTime = Date.now() + RESEND_COOLDOWN * 1000;
      }

      // ذخیره در localStorage برای حفظ timer بعد از refresh
      localStorage.setItem(`otp_timer_${phoneNumber}`, String(expireTime));

      function tick() {
        const saved = parseInt(
          localStorage.getItem(`otp_timer_${phoneNumber}`) || "0",
          10
        );
        const remaining = Math.ceil((saved - Date.now()) / 1000);

        if (remaining <= 0) {
          clearInterval(resendInterval);
          $resendBtn.prop("disabled", false).text("ارسال مجدد");
          $resendTimer.text("");
          localStorage.removeItem(`otp_timer_${phoneNumber}`);
        } else {
          $resendTimer.text(formatTime(remaining));
        }
      }

      tick();
      resendInterval = setInterval(tick, 1000);
    }

    /**
     * متوقف کردن Cooldown
     */
    function stopResendCooldown(phoneNumber) {
      clearInterval(resendInterval);
      $resendBtn.prop("disabled", true);
      $resendTimer.text("");
      if (phoneNumber) {
        localStorage.removeItem(`otp_timer_${phoneNumber}`);
      }
    }

    /**
     * ساخت input های OTP
     */
    function createOtpInputs() {
      $otpInputsContainer.empty();

      for (let i = 0; i < OTP_LENGTH; i++) {
        const $input = $("<input>", {
          type: "text",
          inputmode: "numeric",
          pattern: "[0-9]*",
          maxlength: 1,
          autocomplete: "one-time-code",
          "data-index": i,
          class: "otp-input",
          "aria-label": `رقم ${i + 1} از ${OTP_LENGTH}`,
        });
        $otpInputsContainer.append($input);
      }

      attachOtpEvents();
    }

    /**
     * اتصال event handler ها به input های OTP
     */
    function attachOtpEvents() {
      const $inputs = $otpInputsContainer.find("input");

      // پاک کردن خطا وقتی کاربر تایپ می‌کنه
      $inputs.on("input", function () {
        $otpMsg.addClass("otp-hidden").text("");
      });

      $inputs.on("keydown", function (e) {
        const $this = $(this);
        const idx = parseInt($this.data("index"), 10);
        const key = e.key;

        // Backspace
        if (key === "Backspace") {
          if (!$this.val() && idx > 0) {
            $inputs
              .eq(idx - 1)
              .focus()
              .val("");
          } else {
            $this.val("");
          }
          e.preventDefault();
          return;
        }

        // Enter - تلاش برای verify
        if (key === "Enter") {
          $verifyOtpBtn.trigger("click");
          e.preventDefault();
          return;
        }

        // عدد وارد شده
        if (/^[0-9]$/.test(key)) {
          $this.val(key);
          setTimeout(function () {
            if (idx < $inputs.length - 1) {
              $inputs.eq(idx + 1).focus();
            } else {
              // اگر آخرین کاراکتر بود، خودکار verify کن
              $verifyOtpBtn.trigger("click");
            }
          }, 10);
          e.preventDefault();
          return;
        }

        // جلوگیری از ورود کاراکترهای غیرعددی
        if (key.length === 1 && !/^[0-9]$/.test(key)) {
          e.preventDefault();
          return;
        }

        // ArrowLeft
        if (key === "ArrowLeft" && idx > 0) {
          $inputs.eq(idx - 1).focus();
          e.preventDefault();
          return;
        }

        // ArrowRight
        if (key === "ArrowRight" && idx < $inputs.length - 1) {
          $inputs.eq(idx + 1).focus();
          e.preventDefault();
          return;
        }
      });

      // Paste support - کپی کل کد یکجا
      $inputs.on("paste", function (e) {
        e.preventDefault();
        const pasted = (
          e.originalEvent.clipboardData || window.clipboardData
        ).getData("text");

        const digits = pasted.replace(/\D/g, "").slice(0, OTP_LENGTH);
        const startIdx = parseInt($(this).data("index"), 10);

        for (let i = 0; i < digits.length; i++) {
          if (startIdx + i < $inputs.length) {
            $inputs.eq(startIdx + i).val(digits[i]);
          }
        }

        // فوکوس روی آخرین input پر شده یا اولین خالی
        const lastFilledIdx = Math.min(
          startIdx + digits.length - 1,
          $inputs.length - 1
        );
        $inputs.eq(lastFilledIdx).focus();

        // اگر همه پر شد، خودکار verify کن
        if (digits.length === OTP_LENGTH) {
          setTimeout(() => $verifyOtpBtn.trigger("click"), 300);
        }
      });
    }

    // ==================== AJAX Functions ====================

    /**
     * ارسال OTP
     */
    function sendOtp(phone, flow = "signup", isResend = false) {
      const btnText = isResend ? $resendBtn.text() : null;
      lastFlow = flow;

      if (isResend) {
        $resendBtn.prop("disabled", true).text("در حال ارسال...");
      }

      return $.ajax({
        url: AJAX_URL,
        type: "POST",
        dataType: "json",
        data: {
          action: "send_otp",
          security: NONCE,
          phone: phone,
          login_only: flow === "phone" ? 1 : 0,
          username: flow === "signup" ? signupPayload.username : "",
        },
        timeout: 15000, // 15 second timeout
      })
        .done(function (response) {
          if (response && response.success) {
            // **[تغییر ۳] استفاده از ltrWrap برای نمایش صحیح شماره در پیغام‌ها**
            const maskedPhoneHtml = ltrWrap(maskPhone(phone));

            const message =
              response.data?.message ||
              MSG.otp_sent ||
              `کد تایید برای شماره ${maskedPhoneHtml} ارسال شد`; // استفاده از متغیر HTML

            if (isResend) {
              showSwal(
                "success",
                "ارسال مجدد",
                `کد تایید برای شماره ${maskedPhoneHtml} ارسال شد`,
                2000,
                true
              );
            } else {
              // ✅ فقط بعد از موفقیت، UI رو تغییر بده
              showFlow("otp");
              createOtpInputs();

              // **[تغییر ۴] تنظیم متن با شماره مسک شده (استفاده از .html)**
              $otpInfo.html(`کد تایید برای شماره ${maskedPhoneHtml} ارسال شد`); // استفاده از .html به جای .text

              // Focus روی اولین input
              setTimeout(() => {
                $otpInputsContainer.find("input").first().focus();
              }, 100);

              // نمایش پیام موفقیت (که از message جدید استفاده می‌کند)
              showSwal(
                "success",
                "ارسال شد",
                `کد تایید برای شماره ${maskedPhoneHtml} ارسال شد`,
                2000,
                true
              );
            }

            // شروع تایمر
            startResendCooldown(phone);

            // ریست تعداد تلاش‌های verify
            verifyAttempts = 0;
          } else {
            // مدیریت خطاها
            const errorMsg =
              response?.data?.message || response?.message || "خطا در ارسال کد";

            if (isResend) {
              showSwal("error", "خطا", errorMsg);
              $resendBtn.prop("disabled", false).text(btnText);
            } else {
              showSwal("error", "خطا", errorMsg);
              // برگشت به مرحله شماره موبایل
              showFlow(lastFlow);
            }
          }
        })
        .fail(function (jqXHR, textStatus) {
          let errorMsg = "خطا در ارتباط با سرور";

          if (textStatus === "timeout") {
            errorMsg = "زمان درخواست تمام شد. لطفاً دوباره تلاش کنید.";
          } else if (jqXHR.status === 429) {
            errorMsg =
              MSG.rate_limit ||
              "تعداد درخواست‌های شما بیش از حد است. لطفاً کمی صبر کنید.";
          }

          showSwal("error", "خطا", errorMsg);

          if (isResend) {
            $resendBtn.prop("disabled", false).text(btnText);
          } else {
            showFlow(lastFlow);
          }
        });
    }

    /**
     * تایید OTP
     */
    function verifyOtp(phone, code) {
      $verifyOtpBtn.prop("disabled", true).text("در حال بررسی...");
      verifyAttempts++;

      return $.ajax({
        url: AJAX_URL,
        type: "POST",
        dataType: "json",
        data: {
          action: "verify_otp",
          security: NONCE,
          phone: phone,
          otp: code,
          username: signupPayload.username,
          password: signupPayload.password,
          login_only: lastFlow === "phone" ? 1 : 0,
        },
        timeout: 15000,
      })
        .done(function (response) {
          if (response && response.success) {
            // موفقیت
            showSwal(
              "success",
              "ورود موفق",
              MSG.otp_verified || "ورود با موفقیت انجام شد!",
              1500
            );

            // پاک کردن timer از localStorage
            localStorage.removeItem(`otp_timer_${phone}`);

            // Redirect
            const redirect = response.data?.redirect || window.location.href;
            setTimeout(() => {
              window.location.href = redirect;
            }, 1000);
          } else {
            // خطا
            const errorMsg =
              response?.data?.message ||
              response?.message ||
              "کد اشتباه یا منقضی شده است.";

            showSwal("error", "خطا", errorMsg);
            showOtpError(errorMsg);

            // نمایش تعداد تلاش‌های باقی‌مانده
            const remaining = MAX_VERIFY_ATTEMPTS - verifyAttempts;
            if (remaining > 0 && verifyAttempts < MAX_VERIFY_ATTEMPTS) {
              setTimeout(() => {
                showOtpError(`تعداد تلاش باقی‌مانده: ${remaining}`);
              }, 2000);
            }

            // اگر به حداکثر رسید
            if (verifyAttempts >= MAX_VERIFY_ATTEMPTS) {
              showSwal(
                "error",
                "خطا",
                "تعداد تلاش‌های شما به حداکثر رسید. لطفاً کد جدید دریافت کنید.",
                3000
              );
              setTimeout(() => {
                $otpStep.addClass("otp-hidden");
                $signupStep.removeClass("otp-hidden");
                stopResendCooldown(phone);
              }, 3000);
            } else {
              // پاک کردن input ها برای تلاش مجدد
              $otpInputsContainer.find("input").val("").first().focus();
            }

            $verifyOtpBtn.prop("disabled", false).text("تایید کد و ورود");
          }
        })
        .fail(function (jqXHR, textStatus) {
          let errorMsg = "خطا در ارتباط با سرور";

          if (textStatus === "timeout") {
            errorMsg = "زمان درخواست تمام شد. لطفاً دوباره تلاش کنید.";
          }

          showSwal("error", "خطا", errorMsg);
          showOtpError(errorMsg);
          $verifyOtpBtn.prop("disabled", false).text("تایید کد و ورود");
        });
    }

    // ==================== Event Handlers ====================

    /**
     * ورود با نام کاربری / ایمیل و رمز
     */
    $passwordLoginForm.on("submit", function (e) {
      e.preventDefault();
      const username = ($loginUsername.val() || "").trim();
      const password = $loginPassword.val() || "";

      if (!username || !password) {
        showSwal("error", "ورود نامعتبر", "نام کاربری و رمز عبور را وارد کنید.");
        return;
      }

      const $submitBtn = $passwordLoginForm.find('button[type="submit"]');
      const originalBtnText = $submitBtn.text();
      $submitBtn.prop("disabled", true).text("در حال ورود...");

      $.ajax({
        url: AJAX_URL,
        type: "POST",
        dataType: "json",
        data: {
          action: "otp_password_login",
          security: NONCE,
          username: username,
          password: password,
        },
      })
        .done(function (response) {
          if (response && response.success) {
            showSwal("success", "ورود موفق", response.data?.message || "ورود انجام شد.");
            const redirect = response.data?.redirect || window.location.href;
            setTimeout(() => (window.location.href = redirect), 800);
          } else {
            const msg =
              response?.data?.message ||
              response?.message ||
              "نام کاربری یا رمز عبور اشتباه است.";
            showSwal("error", "خطا", msg);
          }
        })
        .fail(function () {
          showSwal("error", "خطا", "خطا در ارتباط با سرور. دوباره تلاش کنید.");
        })
        .always(function () {
          $submitBtn.prop("disabled", false).text(originalBtnText);
        });
    });

    /**
     * ارسال فرم ثبت‌نام (شماره + نام کاربری + رمز)
     */
    $signupForm.on("submit", function (e) {
      e.preventDefault();

      const username = ($signupUsername.val() || "").trim();
      const password = $signupPassword.val() || "";
      const rawPhone = $signupPhoneInput.val();
      const normalizedPhone = normalizePhone(rawPhone);

      if (!username || username.length < 3) {
        showSwal("error", "نام کاربری", "نام کاربری باید حداقل ۳ کاراکتر باشد.");
        $signupUsername.focus();
        return;
      }

      if (!password || password.length < 6) {
        showSwal("error", "رمز عبور", "رمز عبور باید حداقل ۶ کاراکتر باشد.");
        $signupPassword.focus();
        return;
      }

      // اعتبارسنجی
      if (!validPhone(normalizedPhone)) {
        showSwal(
          "error",
          "شماره نامعتبر",
          MSG.invalid_phone || "شماره موبایل معتبر نیست. فرمت صحیح: 09xxxxxxxxx"
        );
        $signupPhoneInput.addClass("otp-input-error").focus();
        setTimeout(() => $signupPhoneInput.removeClass("otp-input-error"), 2000);
        return;
      }

      lastPhone = normalizedPhone;
      signupPayload = { username: username, password: password };

      const $submitBtn = $signupForm.find('button[type="submit"]');
      const originalBtnText = $submitBtn.text();
      $submitBtn.prop("disabled", true).text("در حال ارسال...");

      // ارسال OTP
      sendOtp(lastPhone, "signup", false).always(function () {
        $submitBtn.prop("disabled", false).text(originalBtnText);
      });
    });

    /**
     * ارسال فرم ورود با موبایل (بدون ساخت حساب جدید)
     */
    $phoneLoginForm.on("submit", function (e) {
      e.preventDefault();

      const rawPhone = $phoneLoginInput.val();
      const normalizedPhone = normalizePhone(rawPhone);

      if (!validPhone(normalizedPhone)) {
        showSwal(
          "error",
          "شماره نامعتبر",
          MSG.invalid_phone || "شماره موبایل معتبر نیست. فرمت صحیح: 09xxxxxxxxx"
        );
        $phoneLoginInput.addClass("otp-input-error").focus();
        setTimeout(() => $phoneLoginInput.removeClass("otp-input-error"), 2000);
        return;
      }

      lastPhone = normalizedPhone;
      signupPayload = { username: "", password: "" };

      const $submitBtn = $phoneLoginForm.find('button[type="submit"]');
      const originalBtnText = $submitBtn.text();
      $submitBtn.prop("disabled", true).text("در حال ارسال...");

      sendOtp(lastPhone, "phone", false).always(function () {
        $submitBtn.prop("disabled", false).text(originalBtnText);
      });
    });

    /**
     * دکمه ارسال مجدد
     */
    $resendBtn.on("click", function () {
      if (!lastPhone || $(this).prop("disabled")) return;
      sendOtp(lastPhone, true);
    });

    /**
     * دکمه تایید OTP
     */
    $verifyOtpBtn.on("click", function () {
      if ($(this).prop("disabled")) return;

      const code = $otpInputsContainer
        .find("input")
        .map(function () {
          return $(this).val();
        })
        .get()
        .join("");

      if (code.length < OTP_LENGTH) {
        showSwal("warning", "کد ناقص", "لطفاً کد کامل را وارد کنید.");
        showOtpError("لطفاً همه خانه‌ها را پر کنید.");
        $otpInputsContainer.find("input").first().focus();
        return;
      }

      verifyOtp(lastPhone, code);
    });

    /**
     * دکمه بازگشت
     */
    $backBtn.on("click", function () {
      showFlow(lastFlow);
      stopResendCooldown(lastPhone);
      verifyAttempts = 0;
      $otpMsg.addClass("otp-hidden").text("");
    });

    /**
     * سوئیچ بین ورود و ثبت‌نام
     */
    $toggleToSignup.on("click", function (e) {
      e.preventDefault();
      showFlow("signup");
      $signupUsername.focus();
    });

    $toggleToLogin.on("click", function (e) {
      e.preventDefault();
      showFlow("login");
      stopResendCooldown(lastPhone);
      $loginUsername.focus();
    });

    $toggleToPhoneLogin.on("click", function (e) {
      e.preventDefault();
      showFlow("phone");
      $phoneLoginInput.focus();
    });

    $togglePhoneToLogin.on("click", function (e) {
      e.preventDefault();
      showFlow("login");
      stopResendCooldown(lastPhone);
      $loginUsername.focus();
    });

    // Password eye toggles
    $toggleLoginPassword.on("click", function () {
      togglePassword($loginPassword, $loginPasswordEye);
    });

    $toggleSignupPassword.on("click", function () {
      togglePassword($signupPassword, $signupPasswordEye);
    });

    // ==================== Initialize ====================

    /**
     * بررسی timer فعال در localStorage هنگام بارگذاری
     */
    (function resumeTimer() {
      const phoneValue = normalizePhone(
        $signupPhoneInput.val() || $phoneLoginInput.val()
      );
      if (!phoneValue) return;

      const saved = localStorage.getItem(`otp_timer_${phoneValue}`);
      if (!saved) return;

      const expireTime = parseInt(saved, 10);
      const remaining = Math.ceil((expireTime - Date.now()) / 1000);

      if (remaining > 0) {
        lastPhone = phoneValue;
        // اگر تایمر فعال هست، نشون بده که OTP قبلاً ارسال شده
        console.log("Active OTP timer found, resuming...");
        // می‌تونی اینجا UI رو به حالت OTP ببری اگر لازمه
      } else {
        localStorage.removeItem(`otp_timer_${phoneValue}`);
      }
    })();

    /**
     * Auto-fill شماره موبایل از URL parameter (اختیاری)
     */
    (function autoFillPhone() {
      const urlParams = new URLSearchParams(window.location.search);
      const phoneParam = urlParams.get("phone");
      if (phoneParam) {
        const normalized = normalizePhone(phoneParam);
        $signupPhoneInput.val(normalized);
        $phoneLoginInput.val(normalized);
      }
    })();
  }); // document ready
})(jQuery);
