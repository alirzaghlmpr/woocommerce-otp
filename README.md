# OTP Verifier

![Version](https://img.shields.io/badge/Version-1.3.9-green)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue)
![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-purple)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)

A lightweight, optimized WordPress plugin for OTP-based login and signup.

## Highlights
- Very light and performance-focused for fast login/signup flows
- OTP login and registration with a clean UX
- Supports 4 popular SMS gateways: FarazSMS, MelliPayamak, SMS.ir, and Kavehnegar
- One‑click migration from Digits (migrate numbers and accounts)
- Built for WooCommerce account flow overrides

## Features
- OTP login and signup (phone-based)
- Customizable login page (logo, titles, button text, background)
- Optional color overlay on the login background image (color + opacity), for readability
- **WooCommerce checkout phone verification** — verify the customer's phone with an OTP before an order can be placed, in one of two admin-selectable modes:
  - **Inline**: a verify button next to WooCommerce's own billing‑phone field (no extra field); Place Order stays disabled until verified
  - **Gate**: a full overlay blocks the checkout form entirely until the phone is verified
  - Enforced server-side (`woocommerce_checkout_process`), not just in JS — classic (shortcode) checkout only, not WooCommerce Blocks checkout
  - Logged-in customers are exempt by default (already-authenticated accounts skip the widget/gate entirely); an "require for logged-in users too" setting extends the same requirement to them
- **WebOTP autofill** — on supported browsers (Chrome/Android), the OTP code is read directly from the incoming SMS and filled in automatically (no copy/paste), on the login/signup page and both checkout verification modes. Requires the SMS gateway's pattern/template to end with `@yourdomain.com #code` (configured on the SMS provider's panel, not in this plugin)
- SweetAlert2 feedback messages
- User‑friendly OTP input flow
- AJAX-powered verification
- Replaces WooCommerce **/my-account** login/signup with the OTP UI

## Technical Features
- Phone-based rate limiting (3 OTP requests per 5 minutes)
- IP-based rate limiting (10 requests per 5 minutes) — applied to OTP **and** password login endpoints
- Brute‑force protection with max OTP verify attempts (5 tries)
- OTP length capped between 4 and 6 digits
- Automatic cleanup via WP‑Cron — two dedicated jobs: OTP table cleanup (every 10 min) and rate-limit transient cleanup (hourly)
- Custom database table for OTP codes
- Database-version migration on update (auto‑alters schema without requiring reactivation)
- OOP, class‑based architecture (Repository pattern for storage, Strategy + Factory for SMS gateways)

## Security
- **OTP codes are never stored in plaintext** — they are hashed with HMAC-SHA256 (keyed with `wp_salt`) before being written to the database
- **Constant-time verification** with `hash_equals()` to eliminate timing side-channels
- **Fail-closed rate limiting** — if the counter cannot be persisted, the request is denied rather than allowed, so the limit cannot be bypassed
- **Gated logging** — diagnostic logs are written only when `WP_DEBUG` is enabled, never in production
- **No sensitive data in logs** — OTP codes are never logged, and phone numbers are masked (e.g. `0912****89`)
- Nonce verification on all AJAX endpoints and strict input sanitization
- OTP-only signups use a placeholder email built from the site's own domain (no reserved `example.com`)

## Supported SMS Gateways
- FarazSMS
- MelliPayamak
- SMS.ir
- Kavehnegar

## One‑Click Migration
Migrate users and phone numbers from the Digits plugin with a single click, or from any other OTP/login plugin through *OTP Verifier -> Migrate users*: pick the user meta key that holds the phone numbers (a scan and a search by a known number help you find it), review a read-only preview, then confirm. The migration is additive, batched and can be undone.

## Requirements
- WordPress (recommended: latest stable)
- WooCommerce (for My Account override)
- PHP (recommended: 7.4+)

## Installation
1. Download or clone this repository.
2. Upload the plugin folder to `wp-content/plugins/`.
3. Activate **OTP Verifier** from the WordPress admin.

## Configuration
1. Go to **OTP Verifier** settings in the WordPress admin.
2. Enter your SMS gateway credentials.
3. Customize login page text, logo, and background if desired.
4. Save settings.

## Usage
- Visit the WooCommerce My Account page to see the OTP login/signup UI.
- Users can register or log in using their phone number.

## Screenshots
Admin Panel
![Settings 1](screenshots/setting-1.png)
![Settings 2](screenshots/setting-2.png)
![Settings 3](screenshots/setting-3.png)
![Settings 4](screenshots/setting-4.png)

Login UI
![Login UI 1](screenshots/ui-1.png)
![Login UI 2](screenshots/ui-2.png)

## Roadmap
- Additional gateways
- Enhanced analytics and logging

## Changelog

### 1.3.9
- **Change:** the checkout phone-verification *flow* now works exactly like the login/signup page, in both the full-lock (gate) and the inline mode. Pressing *send code* disables the button and changes its label to "در حال ارسال..."; when the server answers, the same toast notification the login page uses (SweetAlert2, top corner) appears - "کد تایید برای شماره 0912***4567 ارسال شد" - and, in gate mode, the phone step is replaced by the code step: the info line with the masked number and a back arrow (instead of the old *edit number* text link), the code boxes, the resend link with its countdown, and the verify button (label "در حال بررسی..." while checking). Errors show as a toast plus a message under the form (invalid number - with a red border on the field -, incomplete code, wrong code with the remaining attempts, network timeout, rate limit). After 5 wrong codes (the same limit the server enforces) the customer is sent back to the phone step to request a new code. Resend shows its own "ارسال مجدد" toast.
- **Change:** phone numbers typed with Persian/Arabic digits, a `+98`/`0098` prefix or without the leading zero are now accepted by the checkout widget (they used to be rejected as invalid because the non-ASCII digits were stripped).
- **Fix:** the *resend* button was re-enabled the moment a code was sent (the loading state was restored after the countdown had started), so it looked clickable during the countdown. It now stays disabled until the countdown ends.

### 1.3.8
- **Fix:** the title and description of the checkout gate step ("تایید شماره موبایل") were only styled with a single class, so themes that style headings and paragraphs inside the checkout page (for example `.woocommerce h3`) still made them right-aligned and a different size than the login page. They are now scoped to the plugin's container and pinned, like the buttons and fields already were, so the gate step looks the same on every theme.
- **Change:** version bump so browsers reload the checkout stylesheet and script. Sites that still showed the old gray buttons / single code field after updating were being served the cached files from before 1.3.7 (the files are cache-busted by the plugin version).

### 1.3.7
- **Change:** the checkout phone-verification UI (both the gate step and the inline widget) now matches the login/signup page: a rounded card, a gray rounded phone field with the phone icon, a full-width rounded primary button, and the code entered in separate boxes (typing, paste, browser autofill, keyboard SMS suggestion and WebOTP all work). In gate mode the phone step is replaced by the code step, with a masked "code sent to 0912***4567" line, an *edit number* link, and resend with a countdown - like the login page.
- **Fix:** the button color chosen in settings was ignored on themes that style every `<button>` (the buttons showed the theme's gray). The plugin's checkout styles are now scoped to its own containers and pinned, including hover/focus, so the chosen color always applies; the text on the buttons turns dark automatically when the chosen color is very light.

### 1.3.6
- **Feature:** new admin page *OTP Verifier -> Migrate users* to bring users over from any other OTP/login plugin by the user meta key(s) it stored the phone number in. Three steps: (1) find the keys - an automatic scan lists the meta keys whose values look like Iranian mobile numbers, or you enter a phone number you already know and the tool tells you which keys store it, and you add them to a list; (2) enter one or more keys (comma separated, the order is the priority) and get a read-only preview: how many users, which number formats were detected (`09...`, `9...`, `+98...`, `0098...`, Persian digits, spaces/dashes - all converted to `09xxxxxxxxx`), and how many are ready / already migrated / already have another number / owned by someone else / duplicated between users / invalid, with samples of each; (3) tick the backup confirmation and start - the migration runs in small AJAX batches with a progress bar.
- **Multiple keys:** a site often has the number in several keys (for example `smartlogin_mobile`, `digits_phone_no`, `user_meta_mobile`, `billing_phone`). List them in priority order and every user gets the first *valid* number in that order (garbage in the first key falls through to the next). The preview shows how many users each key supplies, how many users have numbers in more than one key, and how many of those have *different* numbers (with examples). If the same number would land on several accounts it goes to the account whose number comes from the higher-priority key; a user whose chosen number is taken is skipped rather than given a lower-priority number. Cursor and batching span all keys, and *Undo* covers the whole list.
- **Safety:** the migration only adds `phone_number`; the old meta is never changed, a user's existing `phone_number` is never overwritten, a number that already belongs to another user is skipped even if that user stored it in another format (`9...`, `98...`, `+98...`), and a number shared by several source users goes to the first one only - so the same person can never end up with two accounts and phone login is never ambiguous. Numbers mixed between `09...` and `9...` in the source are all converted to `09xxxxxxxxx`; users whose existing `phone_number` is the same number in a non-standard format are reported in the preview (phone login cannot find them) but are not modified. Preview and run share the same classification code, the run can be repeated safely, and every number it writes is marked so *Undo* removes exactly those and nothing else. All actions require the `manage_options` capability and a nonce. A warning is shown for `billing_*`/`shipping_*` keys, because those numbers are typed by customers and are not verified.

### 1.3.5
- **Change:** when "login/signup with phone number only" is enabled, the login page now shows nothing but a phone-number field. The username/password login form, the classic signup form and every link to them are no longer rendered, so login and signup are a single step: enter the number, enter the code, and you are logged in - or a new account is created if none exists for that number (its username is the phone number). Previously the option only added an extra tab next to the full forms. The heading uses the configurable login title, the button uses the configurable login button text, and the privacy text is still shown if set. Server-side handling is unchanged.
- **Fix:** after too many wrong codes on the phone-number flow the page went back to the *signup* form (and to an empty card once that form is not rendered); it now returns to the step the user came from.

### 1.3.4
- **Change:** checkout verification (inline and gate modes) now verifies automatically as soon as the full code is in - typed, pasted, or filled by browser/keyboard autofill (e.g. the iOS SMS suggestion) - like the login page already did. Previously only a WebOTP-delivered code verified by itself; every other way of entering the code needed a tap on the verify button. Non-digit characters are dropped and Persian digits are converted while typing.
- **Fix:** clicking the checkout verify button and then pressing Enter (or a second click) while the request was still in flight sent a duplicate verification request; the verify handler now ignores clicks while it is disabled.

### 1.3.3
- **Change:** hardened SMS-code autofill on the login/signup OTP screen. These are real defects that were reproduced in Chrome, but they were not the cause of the original "Chrome asks permission but nothing fills" report (that was a domain mismatch, see the note below): (1) every box was `maxlength="1"` with `autocomplete="one-time-code"`, so a code inserted as one string (browser autofill, keyboard SMS suggestion) kept only its first digit - or, when the browser wrote the value programmatically, left the whole code sitting in every box; now only the first box is `one-time-code`, every box accepts a whole code, and all input paths (typing, paste, autofill, keyboard suggestion, WebOTP) go through one routine that spreads the digits over the boxes and auto-verifies when complete. (2) Digits typed on a virtual (Android) keyboard or a Persian keyboard layout were ignored because only physical-key `keydown` events were handled, and Ctrl+V was blocked; input is now handled on the `input` event. (3) WebOTP only sees SMS messages that arrive *after* `navigator.credentials.get()` is called, but it was started only after the send-code request returned - and the server answers only once the gateway accepted the message, so the SMS could arrive first and the code was never delivered. Listening now starts before the SMS is requested, and a code that arrives before the boxes are on screen is filled in as soon as they appear.
- **Fix:** checkout verification (inline and gate modes) gets the same listen-before-send behaviour and now converts Persian/Arabic-Indic digits to English digits, both for WebOTP codes and for codes typed by the user before they are sent to the server.
- **Fix:** the front-end now clamps the OTP length to 4-6 like the server does, so a larger value in settings no longer renders boxes that can never be filled.
- **Note:** SMS autofill only works when the last line of the SMS pattern is `@<host> #<code>` and `<host>` is exactly the host the site is opened on. Testing on one domain while the gateway pattern names another silently breaks autofill (Chrome ignores the message), so use a pattern for the host you are actually testing on.

### 1.3.2
- **Change:** WebOTP autofill (login/signup and both checkout verification modes) now logs to the browser console at each step - the resolved credential, why a code was rejected, when a box gets filled, when auto-verify triggers. Previously a failure inside the autofill handler was completely silent (nothing filled, no visible error), making it impossible to tell whether the WebOTP prompt itself failed, the code didn't parse, or the follow-up verify call was rejected. Check DevTools console (filter for `[OTP Verifier]`) when diagnosing an autofill that "does nothing."

### 1.3.1
- **Fix:** if the `billing_phone` field was missing from checkout entirely (removed by a theme/site config), checkout phone verification silently couldn't work - the inline widget had nothing to attach to, and in gate mode the verified phone could never actually be submitted with the order (the JS sets `#billing_phone`'s value, which is a no-op if that field doesn't exist), so orders would be rejected even after a successful verification. Now, whenever checkout verification is required, the plugin re-adds `billing_phone` to the checkout fields if it's missing, and marks it required if it exists but was optional.

### 1.3.0
- **Fix:** the inline checkout verification widget was appearing after the entire billing fields block (at the very end, past email) instead of right below the phone field. Switched from the `woocommerce_after_checkout_billing_form` action to appending onto WooCommerce's own `woocommerce_form_field_tel` filter output for `billing_phone`, which places the widget as a direct sibling immediately after that field.
- **Feature:** the checkout verification widget/step buttons and links now use an admin-configurable color (color picker in settings) instead of a hardcoded one.
- **Change:** more breathing room between fields/rows in the checkout verification widget and gate step (larger gaps, row spacing, input/button padding).

### 1.2.3
- **Fix:** checkout "gate" step showed a completely blank white page. The `<style>{display:none}` rule used to hide the checkout form until verified was scoped to `.woocommerce-checkout`, but WooCommerce itself adds that exact class name to the `<body>` tag on the checkout page (`wc_body_class()`), not just to the checkout form - so the rule was hiding the whole page, not just the form. Scoped the selector to `form.woocommerce-checkout` so it only ever matches the form element.

### 1.2.2
- **Change:** checkout "gate" mode no longer shows as a popup/modal with a dimmed, blurred checkout form behind it. It now renders as a plain step in the normal page flow — the checkout form is hidden entirely (not just dimmed) until the phone is verified, then it appears in its place, like the next step of a wizard rather than a dialog on top of the form.

### 1.2.1
- **Fix:** checkout phone verification (inline and gate modes) never activated on some sites — `OTP_Verifier_Checkout_Handler` was constructed behind a synchronous `class_exists('WooCommerce')` check at plugin-load time, which depends on plugin *activation order* (not alphabetical), so on installs where this plugin happened to load before WooCommerce, the class was silently never instantiated and none of its hooks (including the server-side `woocommerce_checkout_process` enforcement) were ever registered. The check is removed; the handler now always registers its hooks, which are themselves no-ops if WooCommerce isn't active.

### 1.2.0
- **Feature:** WooCommerce checkout phone verification — inline (next to the billing-phone field) or gate (full overlay before checkout) mode, admin-selectable, enforced server-side
- **Feature:** optional color overlay (color + opacity) on the login page background image
- **Feature:** WebOTP autofill on login/signup and both checkout verification modes — the OTP code is read from the incoming SMS automatically on supported browsers, no manual copy/paste (requires the SMS pattern to end with `@yourdomain.com #code`, set on the gateway's panel)

### 1.1.0
- **Security:** OTP codes are now hashed (HMAC-SHA256) instead of stored in plaintext
- **Security:** verification uses constant-time `hash_equals()` to prevent timing attacks
- **Security:** rate limiter is now fail-closed (denies on storage failure instead of allowing)
- **Security:** added IP rate limiting to the password-login endpoint
- **Privacy:** logging is gated behind `WP_DEBUG`; OTP codes removed from logs and phone numbers masked
- **Fix:** OTP-only signups use the site domain for placeholder emails instead of `example.com`
- **Fix:** split the single overloaded cron hook into two dedicated jobs (OTP cleanup every 10 min, transient cleanup hourly)
- **Fix:** widened the `code` column to `varchar(255)` and added automatic DB-version migration so the hash is never truncated on file-only updates

### 1.0.0
- Initial release: OTP login/signup, four SMS gateways, Digits migration, WooCommerce My Account override

## License
GPL-2.0-or-later
