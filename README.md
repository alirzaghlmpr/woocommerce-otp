# OTP Verifier

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
- SweetAlert2 feedback messages
- User‑friendly OTP input flow
- AJAX-powered verification
- Replaces WooCommerce **/my-account** login/signup with the OTP UI

## Technical Features
- Phone-based rate limiting (3 OTP requests per 5 minutes)
- IP-based rate limiting (10 requests per 5 minutes)
- Brute‑force protection with max OTP verify attempts (5 tries)
- OTP length capped between 4 and 6 digits
- Automatic cleanup via WP‑Cron (OTP table + transient cleanup)
- Custom database table for OTP codes
- OOP, class‑based architecture

## Supported SMS Gateways
- FarazSMS
- MelliPayamak
- SMS.ir
- Kavehnegar

## One‑Click Migration
Migrate users and phone numbers from the Digits plugin with a single click.

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

## License
GPL-2.0-or-later
