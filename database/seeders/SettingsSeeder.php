<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the settings table. Idempotent: re-running updates labels/types and
     * re-applies defaults only for settings whose value is still null.
     */
    public function run(): void
    {
        foreach ($this->settings() as $definition) {
            $this->seedSetting($definition);
        }
    }

    protected function seedSetting(array $definition): void
    {
        $setting = Setting::withTrashed()->firstWhere('key', $definition['key']);

        $attributes = collect($definition)
            ->except(['value'])
            ->all();

        if ($setting) {
            $setting->fill($attributes);

            // Preserve values that were already configured (only fill blanks
            // so re-seeding never clobbers environment-specific data).
            if ($setting->getOriginal('value') === null) {
                $setting->type = $definition['type'];
                $setting->value = $definition['value'] ?? null;
            }
        } else {
            $setting = new Setting($attributes);
            $setting->type = $definition['type'];
            $setting->value = $definition['value'] ?? null;
        }

        $setting->status ??= true;
        $setting->save();

        if ($setting->trashed()) {
            $setting->restore();
        }
    }

    /**
     * @return array<int, array{key: string, value: mixed, group: string, type: string, label: string, options?: array}>
     */
    protected function settings(): array
    {
        return [
            /* ------------------------------------------------------- *
             *  General
             * ------------------------------------------------------- */
            ['key' => 'general.site_name', 'value' => 'SNT CSSC', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Site name'],
            ['key' => 'general.site_tagline', 'value' => 'Shaping future civil servants', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Site tagline'],
            ['key' => 'general.site_description', 'value' => 'SNT CSSC — Student Management Information System for admissions, enrollments, batches and fee management.', 'group' => 'general', 'type' => Setting::TYPE_TEXT, 'label' => 'Site description'],
            ['key' => 'general.app_name', 'value' => 'SNT CSSC MIS', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Application name'],
            ['key' => 'general.title', 'value' => 'SNT CSSC MIS', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Page title suffix'],
            ['key' => 'general.site_logo', 'value' => null, 'group' => 'general', 'type' => Setting::TYPE_IMAGE, 'label' => 'Site logo'],
            ['key' => 'general.site_favicon', 'value' => null, 'group' => 'general', 'type' => Setting::TYPE_IMAGE, 'label' => 'Site favicon'],
            ['key' => 'general.site_campus', 'value' => 'Main Campus', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Campus'],
            ['key' => 'general.site_email', 'value' => 'info@sntcssc.in', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Contact email'],
            ['key' => 'general.site_mobile', 'value' => '+91 90000 00000', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Contact mobile'],
            ['key' => 'general.site_phone', 'value' => '033 0000 0000', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Contact phone'],
            ['key' => 'general.site_address', 'value' => 'SNT CSSC, Main Campus, Kolkata, West Bengal, India', 'group' => 'general', 'type' => Setting::TYPE_TEXT, 'label' => 'Address'],
            ['key' => 'general.site_timing', 'value' => '10:00 AM – 6:00 PM', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Office timing'],
            ['key' => 'general.site_open_days', 'value' => 'Monday – Saturday', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Open days'],
            ['key' => 'general.copyright_text', 'value' => '© :year SNT CSSC. All rights reserved.', 'group' => 'general', 'type' => Setting::TYPE_STRING, 'label' => 'Copyright text'],

            /* ------------------------------------------------------- *
             *  Appearance
             * ------------------------------------------------------- */
            ['key' => 'appearance.logo', 'value' => null, 'group' => 'appearance', 'type' => Setting::TYPE_IMAGE, 'label' => 'Dashboard logo'],
            ['key' => 'appearance.icon', 'value' => null, 'group' => 'appearance', 'type' => Setting::TYPE_IMAGE, 'label' => 'Dashboard icon (favicon / tile)'],
            ['key' => 'appearance.primary_color', 'value' => '#10b981', 'group' => 'appearance', 'type' => Setting::TYPE_STRING, 'label' => 'Primary accent color'],
            ['key' => 'appearance.dark_mode', 'value' => 'system', 'group' => 'appearance', 'type' => Setting::TYPE_SELECT, 'label' => 'Default dark mode', 'options' => ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark']],
            ['key' => 'appearance.sidebar_theme', 'value' => 'default', 'group' => 'appearance', 'type' => Setting::TYPE_SELECT, 'label' => 'Sidebar theme', 'options' => ['default' => 'Default', 'dark' => 'Dark', 'light' => 'Light']],
            ['key' => 'appearance.font_family', 'value' => 'Inter', 'group' => 'appearance', 'type' => Setting::TYPE_STRING, 'label' => 'Font family'],
            ['key' => 'appearance.custom_css', 'value' => null, 'group' => 'appearance', 'type' => Setting::TYPE_TEXT, 'label' => 'Custom CSS'],

            /* ------------------------------------------------------- *
             *  SEO
             * ------------------------------------------------------- */
            ['key' => 'seo.meta_title', 'value' => 'SNT CSSC MIS — Student Management Information System', 'group' => 'seo', 'type' => Setting::TYPE_STRING, 'label' => 'Meta title'],
            ['key' => 'seo.meta_description', 'value' => 'Admissions, enrollments, batches, tests and fee management for SNT CSSC coaching programmes.', 'group' => 'seo', 'type' => Setting::TYPE_TEXT, 'label' => 'Meta description'],
            ['key' => 'seo.meta_keywords', 'value' => 'sntcssc, mis, admissions, coaching, civil services, ssc', 'group' => 'seo', 'type' => Setting::TYPE_TEXT, 'label' => 'Meta keywords (comma separated)'],
            ['key' => 'seo.canonical_url', 'value' => null, 'group' => 'seo', 'type' => Setting::TYPE_STRING, 'label' => 'Canonical URL'],
            ['key' => 'seo.og_image', 'value' => null, 'group' => 'seo', 'type' => Setting::TYPE_IMAGE, 'label' => 'OG share image'],
            ['key' => 'seo.twitter_handle', 'value' => '@sntcssc', 'group' => 'seo', 'type' => Setting::TYPE_STRING, 'label' => 'Twitter handle'],
            ['key' => 'seo.robots_txt', 'value' => "User-agent: *\nAllow: /", 'group' => 'seo', 'type' => Setting::TYPE_TEXT, 'label' => 'Robots.txt'],
            ['key' => 'seo.google_analytics_id', 'value' => null, 'group' => 'seo', 'type' => Setting::TYPE_STRING, 'label' => 'Google Analytics ID'],

            /* ------------------------------------------------------- *
             *  Localization
             * ------------------------------------------------------- */
            ['key' => 'localization.language', 'value' => 'en', 'group' => 'localization', 'type' => Setting::TYPE_SELECT, 'label' => 'Default language', 'options' => ['en' => 'English', 'hi' => 'Hindi', 'bn' => 'Bengali']],
            ['key' => 'localization.fallback_language', 'value' => 'en', 'group' => 'localization', 'type' => Setting::TYPE_SELECT, 'label' => 'Fallback language', 'options' => ['en' => 'English', 'hi' => 'Hindi', 'bn' => 'Bengali']],
            ['key' => 'localization.timezone', 'value' => 'Asia/Kolkata', 'group' => 'localization', 'type' => Setting::TYPE_STRING, 'label' => 'Timezone'],
            ['key' => 'localization.date_format', 'value' => 'd M Y', 'group' => 'localization', 'type' => Setting::TYPE_SELECT, 'label' => 'Date format', 'options' => ['d M Y' => '22 Aug 2026', 'd/m/Y' => '22/08/2026', 'Y-m-d' => '2026-08-22', 'd-m-Y' => '22-08-2026', 'jS F Y' => '22nd August 2026']],
            ['key' => 'localization.time_format', 'value' => 'h:i A', 'group' => 'localization', 'type' => Setting::TYPE_SELECT, 'label' => 'Time format', 'options' => ['h:i A' => '05:30 PM', 'H:i' => '17:30']],
            ['key' => 'localization.currency_symbol', 'value' => '₹', 'group' => 'localization', 'type' => Setting::TYPE_STRING, 'label' => 'Currency symbol'],
            ['key' => 'localization.currency_code', 'value' => 'INR', 'group' => 'localization', 'type' => Setting::TYPE_STRING, 'label' => 'Currency code'],
            ['key' => 'localization.number_format', 'value' => 'indian', 'group' => 'localization', 'type' => Setting::TYPE_SELECT, 'label' => 'Number format', 'options' => ['indian' => 'Indian', 'international' => 'International']],

            /* ------------------------------------------------------- *
             *  System
             * ------------------------------------------------------- */
            ['key' => 'system.maintenance_mode', 'value' => false, 'group' => 'system', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Maintenance mode'],
            ['key' => 'system.debug_mode', 'value' => false, 'group' => 'system', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Debug mode'],
            ['key' => 'system.allow_account_deletion', 'value' => false, 'group' => 'system', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Allow Account Self-Deletion'],
            ['key' => 'system.app_name', 'value' => 'SNT CSSC MIS', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'System app name'],
            ['key' => 'system.app_version', 'value' => '1.0.0', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'App version'],
            ['key' => 'system.developed_by', 'value' => 'SNT CSSC IT Team', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developed by'],
            ['key' => 'system.developer_contact', 'value' => 'dev@sntcssc.in', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developer contact'],
            ['key' => 'system.developer_github', 'value' => 'https://github.com/sntcssc', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developer GitHub'],
            ['key' => 'system.developer_website', 'value' => 'https://sntcssc.in', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developer website'],
            ['key' => 'system.max_upload_size', 'value' => 10, 'group' => 'system', 'type' => Setting::TYPE_NUMBER, 'label' => 'Max upload size (MB)'],
            ['key' => 'system.session_lifetime', 'value' => 120, 'group' => 'system', 'type' => Setting::TYPE_NUMBER, 'label' => 'Session lifetime (minutes)'],
            ['key' => 'system.cache_driver', 'value' => 'file', 'group' => 'system', 'type' => Setting::TYPE_SELECT, 'label' => 'Cache driver', 'options' => ['file' => 'File', 'redis' => 'Redis', 'database' => 'Database']],
            ['key' => 'system.min_mobile_version', 'value' => '1.0.0', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Minimum Mobile App Version'],
            ['key' => 'system.latest_mobile_version', 'value' => '1.2.0', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Latest Mobile App Version'],
            ['key' => 'system.force_mobile_update', 'value' => false, 'group' => 'system', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Force Mobile App Update'],
            ['key' => 'system.play_store_url', 'value' => 'https://play.google.com/store/apps/details?id=in.sntcssc.mis', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Google Play Store URL'],
            ['key' => 'system.app_store_url', 'value' => 'https://apps.apple.com/app/sntcssc-mis/id123456789', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Apple App Store URL'],
            ['key' => 'system.min_desktop_version', 'value' => '1.0.0', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Minimum Desktop App Version'],
            ['key' => 'system.latest_desktop_version', 'value' => '1.1.0', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Latest Desktop App Version'],
            ['key' => 'system.desktop_update_channel', 'value' => 'stable', 'group' => 'system', 'type' => Setting::TYPE_SELECT, 'label' => 'Desktop Update Channel', 'options' => ['stable' => 'Stable', 'beta' => 'Beta', 'nightly' => 'Nightly']],

            /* ------------------------------------------------------- *
             *  SMS gateway — 2factor.in
             *  Docs: https://2factor.in/API/V1/{api-key}/SMS/{phone}/{otp}
             *  DLT : https://2factor.in/API/V1/{api-key}/ADDON_SERVICES/SEND/TSMS
             * ------------------------------------------------------- */
            ['key' => 'sms.enabled', 'value' => false, 'group' => 'sms', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable SMS sending'],
            ['key' => 'sms.driver', 'value' => 'log', 'group' => 'sms', 'type' => Setting::TYPE_SELECT, 'label' => 'SMS driver', 'options' => ['log' => 'Log (no real SMS)', '2factor' => '2factor.in']],
            ['key' => 'sms.two_factor_api_key', 'value' => null, 'group' => 'sms', 'type' => Setting::TYPE_SECRET, 'label' => '2factor.in API key'],
            ['key' => 'sms.two_factor_base_url', 'value' => 'https://2factor.in', 'group' => 'sms', 'type' => Setting::TYPE_STRING, 'label' => '2factor.in API base URL'],
            ['key' => 'sms.two_factor_sender_id', 'value' => null, 'group' => 'sms', 'type' => Setting::TYPE_STRING, 'label' => '2factor.in sender ID (DLT approved)'],
            ['key' => 'sms.two_factor_template_name', 'value' => null, 'group' => 'sms', 'type' => Setting::TYPE_STRING, 'label' => '2factor.in DLT template name'],
            ['key' => 'sms.otp_length', 'value' => 6, 'group' => 'sms', 'type' => Setting::TYPE_NUMBER, 'label' => 'OTP length'],
            ['key' => 'sms.otp_expiry_minutes', 'value' => 5, 'group' => 'sms', 'type' => Setting::TYPE_NUMBER, 'label' => 'OTP expiry (minutes)'],
            ['key' => 'sms.default_country_code', 'value' => '+91', 'group' => 'sms', 'type' => Setting::TYPE_STRING, 'label' => 'Default country code'],
            ['key' => 'sms.http_timeout', 'value' => 10, 'group' => 'sms', 'type' => Setting::TYPE_NUMBER, 'label' => 'HTTP timeout (seconds)'],
            ['key' => 'sms.http_retry_attempts', 'value' => 2, 'group' => 'sms', 'type' => Setting::TYPE_NUMBER, 'label' => 'HTTP retry attempts'],

            /* ------------------------------------------------------- *
             *  Payment gateways — Razorpay / PhonePe
             * ------------------------------------------------------- */
            ['key' => 'payment.enabled', 'value' => false, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable online payments'],
            ['key' => 'payment.driver', 'value' => 'razorpay', 'group' => 'payment', 'type' => Setting::TYPE_SELECT, 'label' => 'Active payment gateway', 'options' => ['razorpay' => 'Razorpay', 'phonepe' => 'PhonePe', 'ccavenue' => 'CCAVenue', 'offline' => 'Offline Only']],
            ['key' => 'payment.currency', 'value' => 'INR', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'Currency code'],
            ['key' => 'payment.razorpay_enabled', 'value' => false, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Razorpay enabled'],
            ['key' => 'payment.razorpay_key_id', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'Razorpay key ID'],
            ['key' => 'payment.razorpay_key_secret', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_SECRET, 'label' => 'Razorpay key secret'],
            ['key' => 'payment.razorpay_webhook_secret', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_SECRET, 'label' => 'Razorpay webhook secret'],
            ['key' => 'payment.phonepe_enabled', 'value' => false, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'PhonePe enabled'],
            ['key' => 'payment.phonepe_merchant_id', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'PhonePe merchant ID'],
            ['key' => 'payment.phonepe_salt_key', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_SECRET, 'label' => 'PhonePe salt key'],
            ['key' => 'payment.phonepe_salt_index', 'value' => 1, 'group' => 'payment', 'type' => Setting::TYPE_NUMBER, 'label' => 'PhonePe salt index'],
            ['key' => 'payment.phonepe_mode', 'value' => 'UAT', 'group' => 'payment', 'type' => Setting::TYPE_SELECT, 'label' => 'PhonePe mode', 'options' => ['UAT' => 'UAT (test)', 'LIVE' => 'Live']],
            ['key' => 'payment.ccavenue_enabled', 'value' => false, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'CCAVenue enabled'],
            ['key' => 'payment.ccavenue_merchant_id', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'CCAVenue Merchant ID'],
            ['key' => 'payment.ccavenue_access_code', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'CCAVenue Access Code'],
            ['key' => 'payment.ccavenue_working_key', 'value' => null, 'group' => 'payment', 'type' => Setting::TYPE_SECRET, 'label' => 'CCAVenue Working Key'],
            ['key' => 'payment.ccavenue_mode', 'value' => 'test', 'group' => 'payment', 'type' => Setting::TYPE_SELECT, 'label' => 'CCAVenue mode', 'options' => ['test' => 'Test (Sandbox)', 'live' => 'Live (Production)']],
            ['key' => 'payment.offline_enabled', 'value' => true, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable offline payment fallback'],
            ['key' => 'payment.offline_bank_transfer_enabled', 'value' => true, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Bank Transfer/NEFT/IMPS enabled'],
            ['key' => 'payment.offline_bank_name', 'value' => 'State Bank of India', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'Bank Name'],
            ['key' => 'payment.offline_account_name', 'value' => 'Satyendranath Tagore Civil Services Study Centre', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'Account Name'],
            ['key' => 'payment.offline_account_number', 'value' => '39824589201', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'Account Number'],
            ['key' => 'payment.offline_ifsc_code', 'value' => 'SBIN0000123', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'IFSC Code'],
            ['key' => 'payment.offline_branch', 'value' => 'Kolkata Main Branch', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'Branch Name'],
            ['key' => 'payment.offline_upi_enabled', 'value' => true, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Static UPI QR enabled'],
            ['key' => 'payment.offline_upi_id', 'value' => 'sntcssc@indianbk', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'UPI VPA Address'],
            ['key' => 'payment.offline_upi_payee_name', 'value' => 'SNTCSSC Kolkata', 'group' => 'payment', 'type' => Setting::TYPE_STRING, 'label' => 'UPI Payee Name'],
            ['key' => 'payment.offline_cash_enabled', 'value' => true, 'group' => 'payment', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'In-Person Cash Payment enabled'],
            ['key' => 'payment.offline_cash_instructions', 'value' => 'Pay cash directly at Accounts Counter, Room 102, Administrative Block between 10:00 AM and 4:00 PM on working days and collect official signed receipt.', 'group' => 'payment', 'type' => Setting::TYPE_TEXT, 'label' => 'Cash in-person instructions'],

            /* ------------------------------------------------------- *
             *  Email — SMTP / log mailer
             * ------------------------------------------------------- */
            ['key' => 'email.driver', 'value' => 'log', 'group' => 'email', 'type' => Setting::TYPE_SELECT, 'label' => 'Mail driver', 'options' => ['log' => 'Log', 'smtp' => 'SMTP']],
            ['key' => 'email.smtp_host', 'value' => null, 'group' => 'email', 'type' => Setting::TYPE_STRING, 'label' => 'SMTP host'],
            ['key' => 'email.smtp_port', 'value' => 587, 'group' => 'email', 'type' => Setting::TYPE_NUMBER, 'label' => 'SMTP port'],
            ['key' => 'email.smtp_username', 'value' => null, 'group' => 'email', 'type' => Setting::TYPE_STRING, 'label' => 'SMTP username'],
            ['key' => 'email.smtp_password', 'value' => null, 'group' => 'email', 'type' => Setting::TYPE_SECRET, 'label' => 'SMTP password'],
            ['key' => 'email.smtp_encryption', 'value' => 'tls', 'group' => 'email', 'type' => Setting::TYPE_SELECT, 'label' => 'SMTP encryption', 'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'None']],
            ['key' => 'email.from_address', 'value' => 'noreply@sntcssc.in', 'group' => 'email', 'type' => Setting::TYPE_STRING, 'label' => 'From address'],
            ['key' => 'email.from_name', 'value' => 'SNT CSSC MIS', 'group' => 'email', 'type' => Setting::TYPE_STRING, 'label' => 'From name'],
            ['key' => 'email.cc_to', 'value' => null, 'group' => 'email', 'type' => Setting::TYPE_STRING, 'label' => 'CC to'],
            ['key' => 'email.send_to', 'value' => null, 'group' => 'email', 'type' => Setting::TYPE_STRING, 'label' => 'Send test email to'],

            /* ------------------------------------------------------- *
             *  Live Chat & WebRTC Calling
             * ------------------------------------------------------- */
            ['key' => 'chat.enabled', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Live Chat'],
            ['key' => 'chat.direct_enabled', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Direct 1-on-1 Chat'],
            ['key' => 'chat.group_enabled', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Group Chat'],
            ['key' => 'chat.channel_enabled', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Broadcast Channels'],
            ['key' => 'chat.voice_call_enabled', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable WebRTC Voice Calls'],
            ['key' => 'chat.video_call_enabled', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable WebRTC Video Calls'],
            ['key' => 'chat.require_websocket_for_calls', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Require Active WebSocket for Calling'],
            ['key' => 'chat.transport_driver', 'value' => 'hybrid', 'group' => 'chat', 'type' => Setting::TYPE_SELECT, 'label' => 'Chat Realtime Transport', 'options' => ['hybrid' => 'Hybrid (Reverb WebSocket + Fallback Poll)', 'polling' => 'Livewire Polling Only (wire:poll)', 'broadcasting' => 'Broadcasting Only (Reverb WebSockets)']],
            ['key' => 'chat.poll_interval', 'value' => '3s', 'group' => 'chat', 'type' => Setting::TYPE_SELECT, 'label' => 'Chat Polling Interval', 'options' => ['3s' => '3 Seconds', '5s' => '5 Seconds', '10s' => '10 Seconds', '30s' => '30 Seconds']],
            ['key' => 'chat.webrtc_signaling_driver', 'value' => 'reverb', 'group' => 'chat', 'type' => Setting::TYPE_SELECT, 'label' => 'WebRTC Signaling Driver', 'options' => ['reverb' => 'Reverb / WebSockets Signaling', 'internal_poll' => 'Internal Polling Signal Exchange']],
            ['key' => 'chat.webrtc_stun_server', 'value' => 'stun:stun.l.google.com:19302', 'group' => 'chat', 'type' => Setting::TYPE_STRING, 'label' => 'WebRTC STUN Server'],
            ['key' => 'chat.webrtc_turn_server', 'value' => null, 'group' => 'chat', 'type' => Setting::TYPE_STRING, 'label' => 'WebRTC TURN Server (Optional)'],
            ['key' => 'chat.webrtc_turn_username', 'value' => null, 'group' => 'chat', 'type' => Setting::TYPE_STRING, 'label' => 'TURN Username'],
            ['key' => 'chat.webrtc_turn_credential', 'value' => null, 'group' => 'chat', 'type' => Setting::TYPE_SECRET, 'label' => 'TURN Credential'],
            ['key' => 'chat.max_file_size_mb', 'value' => 25, 'group' => 'chat', 'type' => Setting::TYPE_NUMBER, 'label' => 'Max Attachment Size (MB)'],
            ['key' => 'chat.allowed_file_types', 'value' => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,zip,mp3,mp4,wav', 'group' => 'chat', 'type' => Setting::TYPE_STRING, 'label' => 'Allowed File Extensions'],
            ['key' => 'chat.edit_time_limit_minutes', 'value' => 15, 'group' => 'chat', 'type' => Setting::TYPE_NUMBER, 'label' => 'Message Edit Window (Minutes)'],
            ['key' => 'chat.notify_email', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Send Email Alerts on Chat'],
            ['key' => 'chat.notify_sms', 'value' => false, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Send SMS Alerts on Chat'],
            ['key' => 'chat.notify_whatsapp', 'value' => false, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Send WhatsApp Alerts on Chat'],
            ['key' => 'chat.notify_telegram', 'value' => false, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Send Telegram Alerts on Chat'],
            ['key' => 'chat.sound_enabled', 'value' => true, 'group' => 'chat', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Play Chat Message Chimes'],

            /* ------------------------------------------------------- *
             *  Library Management & Policies
             * ------------------------------------------------------- */
            ['key' => 'library.fines_enabled', 'value' => true, 'group' => 'library', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Overdue Fine & Penalty System'],
            ['key' => 'library.fine_per_day', 'value' => 5.00, 'group' => 'library', 'type' => Setting::TYPE_NUMBER, 'label' => 'Overdue Fine Rate Per Day (INR)'],
            ['key' => 'library.default_loan_days', 'value' => 15, 'group' => 'library', 'type' => Setting::TYPE_NUMBER, 'label' => 'Default Book Loan Duration (Days)'],
            ['key' => 'library.max_books_allowed', 'value' => 3, 'group' => 'library', 'type' => Setting::TYPE_NUMBER, 'label' => 'Maximum Books Allowed Per Member'],
            ['key' => 'library.max_renewals', 'value' => 2, 'group' => 'library', 'type' => Setting::TYPE_NUMBER, 'label' => 'Maximum Allowed Book Renewals'],
            ['key' => 'library.require_security_deposit', 'value' => true, 'group' => 'library', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Require Library Security Deposit for Borrowing'],
            ['key' => 'library.allow_deposit_bypass', 'value' => true, 'group' => 'library', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Allow Librarian / Staff Security Deposit Bypass'],
            ['key' => 'library.reservation_expiry_days', 'value' => 3, 'group' => 'library', 'type' => Setting::TYPE_NUMBER, 'label' => 'Book Reservation Pickup Hold Window (Days)'],
            ['key' => 'library.reminder_days_before', 'value' => 2, 'group' => 'library', 'type' => Setting::TYPE_NUMBER, 'label' => 'Days Before Due Date to Dispatch Return Reminder'],
            ['key' => 'library.auto_reminders_enabled', 'value' => true, 'group' => 'library', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Automated Scheduled Return Reminders'],
            ['key' => 'library.reminder_channels', 'value' => json_encode(['database', 'email', 'sms']), 'group' => 'library', 'type' => Setting::TYPE_JSON, 'label' => 'Active Reminder Channels'],

            /* ------------------------------------------------------- *
             *  reCAPTCHA & Bot Defense
             * ------------------------------------------------------- */
            ['key' => 'captcha.enabled', 'value' => false, 'group' => 'captcha', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable CAPTCHA Protection'],
            ['key' => 'captcha.driver', 'value' => 'custom', 'group' => 'captcha', 'type' => Setting::TYPE_SELECT, 'label' => 'Active CAPTCHA Driver', 'options' => ['google' => 'Google reCAPTCHA', 'custom' => 'Custom CAPTCHA Service']],
            ['key' => 'captcha.on_login', 'value' => true, 'group' => 'captcha', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Protect Login Form'],
            ['key' => 'captcha.on_register', 'value' => true, 'group' => 'captcha', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Protect Registration Form'],
            ['key' => 'captcha.on_forgot_password', 'value' => true, 'group' => 'captcha', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Protect Password Reset Form'],
            ['key' => 'captcha.on_contact', 'value' => true, 'group' => 'captcha', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Protect Public Contact Form'],
            ['key' => 'captcha.google_version', 'value' => 'v2_checkbox', 'group' => 'captcha', 'type' => Setting::TYPE_SELECT, 'label' => 'Google reCAPTCHA Version', 'options' => ['v2_checkbox' => 'reCAPTCHA v2 (Checkbox)', 'v2_invisible' => 'reCAPTCHA v2 (Invisible)', 'v3' => 'reCAPTCHA v3 (Score-based)']],
            ['key' => 'captcha.google_site_key', 'value' => null, 'group' => 'captcha', 'type' => Setting::TYPE_STRING, 'label' => 'Google reCAPTCHA Site Key'],
            ['key' => 'captcha.google_secret_key', 'value' => null, 'group' => 'captcha', 'type' => Setting::TYPE_SECRET, 'label' => 'Google reCAPTCHA Secret Key'],
            ['key' => 'captcha.google_score_threshold', 'value' => 0.5, 'group' => 'captcha', 'type' => Setting::TYPE_NUMBER, 'label' => 'Google reCAPTCHA v3 Score Threshold'],
            ['key' => 'captcha.google_domain', 'value' => 'www.google.com', 'group' => 'captcha', 'type' => Setting::TYPE_SELECT, 'label' => 'Google reCAPTCHA Domain Endpoint', 'options' => ['www.google.com' => 'google.com (Default)', 'www.recaptcha.net' => 'recaptcha.net (China/Global CDN)']],
            ['key' => 'captcha.custom_char_type', 'value' => 'alphanumeric', 'group' => 'captcha', 'type' => Setting::TYPE_SELECT, 'label' => 'Custom CAPTCHA Character Mode', 'options' => ['numbers' => 'Numbers Only (0-9)', 'alphabets' => 'Alphabets Only (A-Z, a-z)', 'alphanumeric' => 'Numbers & Alphabets (Mix)', 'special_characters' => 'Alphanumeric & Special Characters (@#$%)', 'math' => 'Mathematical Problem (e.g. 7 + 5)']],
            ['key' => 'captcha.custom_length', 'value' => 5, 'group' => 'captcha', 'type' => Setting::TYPE_NUMBER, 'label' => 'Custom CAPTCHA Length'],
            ['key' => 'captcha.custom_case_sensitive', 'value' => false, 'group' => 'captcha', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Custom CAPTCHA Case Sensitive'],
            ['key' => 'captcha.custom_noise_lines', 'value' => 3, 'group' => 'captcha', 'type' => Setting::TYPE_NUMBER, 'label' => 'Custom CAPTCHA Noise Lines'],
            ['key' => 'captcha.custom_noise_dots', 'value' => 35, 'group' => 'captcha', 'type' => Setting::TYPE_NUMBER, 'label' => 'Custom CAPTCHA Noise Dots'],
            ['key' => 'captcha.custom_distortion', 'value' => true, 'group' => 'captcha', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Custom CAPTCHA Text Distortion'],
            ['key' => 'captcha.custom_expire_minutes', 'value' => 5, 'group' => 'captcha', 'type' => Setting::TYPE_NUMBER, 'label' => 'Custom CAPTCHA Expiry (Minutes)'],
            ['key' => 'captcha.custom_max_attempts', 'value' => 3, 'group' => 'captcha', 'type' => Setting::TYPE_NUMBER, 'label' => 'Custom CAPTCHA Max Failed Attempts'],

            // Storage & Cloud (S3) Settings
            ['key' => 'storage.default_disk', 'value' => 'public', 'group' => 'storage', 'type' => Setting::TYPE_SELECT, 'label' => 'Default Storage Disk', 'options' => ['public' => 'Local Public Disk', 's3' => 'Amazon S3 / S3-Compatible Cloud Storage', 'local' => 'Local Private Disk']],
            ['key' => 'storage.provider', 'value' => 'aws', 'group' => 'storage', 'type' => Setting::TYPE_SELECT, 'label' => 'Cloud Storage Provider', 'options' => ['aws' => 'Amazon Web Services (AWS S3)', 'r2' => 'Cloudflare R2', 'minio' => 'MinIO (Self-Hosted S3)', 'wasabi' => 'Wasabi Hot Cloud Storage', 'do_spaces' => 'DigitalOcean Spaces', 'backblaze' => 'Backblaze B2', 'custom' => 'Custom S3-Compatible Storage']],
            ['key' => 'storage.s3_key', 'value' => null, 'group' => 'storage', 'type' => Setting::TYPE_STRING, 'label' => 'Access Key ID'],
            ['key' => 'storage.s3_secret', 'value' => null, 'group' => 'storage', 'type' => Setting::TYPE_SECRET, 'label' => 'Secret Access Key'],
            ['key' => 'storage.s3_region', 'value' => 'us-east-1', 'group' => 'storage', 'type' => Setting::TYPE_STRING, 'label' => 'AWS Region / Datacenter'],
            ['key' => 'storage.s3_bucket', 'value' => null, 'group' => 'storage', 'type' => Setting::TYPE_STRING, 'label' => 'Bucket Name'],
            ['key' => 'storage.s3_endpoint', 'value' => null, 'group' => 'storage', 'type' => Setting::TYPE_STRING, 'label' => 'Custom Endpoint URL (MinIO / R2 / Wasabi / DO Spaces)'],
            ['key' => 'storage.s3_url', 'value' => null, 'group' => 'storage', 'type' => Setting::TYPE_STRING, 'label' => 'Custom Public / CDN Domain URL'],
            ['key' => 'storage.s3_use_path_style_endpoint', 'value' => false, 'group' => 'storage', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Use Path-Style Endpoint (Required for MinIO)'],
            ['key' => 'storage.s3_visibility', 'value' => 'public', 'group' => 'storage', 'type' => Setting::TYPE_SELECT, 'label' => 'Default Upload Visibility', 'options' => ['public' => 'Public Read', 'private' => 'Private (Signed URLs)']],
            ['key' => 'storage.max_upload_size_mb', 'value' => 25, 'group' => 'storage', 'type' => Setting::TYPE_NUMBER, 'label' => 'Max Upload Size (MB)'],
            ['key' => 'storage.allowed_file_types', 'value' => 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,svg,zip,mp4', 'group' => 'storage', 'type' => Setting::TYPE_STRING, 'label' => 'Allowed File Extensions'],
            ['key' => 'storage.auto_backup_to_s3', 'value' => false, 'group' => 'storage', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Save System Backups to S3 Cloud'],

            /* ------------------------------------------------------- *
             *  Admission Portal (OTR) API & Integration Gateway
             * ------------------------------------------------------- */
            ['key' => 'otr.enabled', 'value' => true, 'group' => 'otr', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Admission OTR Integration Feature'],
            ['key' => 'otr.base_url', 'value' => 'http://127.0.0.1:8080', 'group' => 'otr', 'type' => Setting::TYPE_STRING, 'label' => 'Admission OTR Portal Base URL'],
            ['key' => 'otr.api_key', 'value' => 'adm_live_Xxe1CHL3XgkwWUnSAWSnohpbSl7B5wnhMqOjc5Gi', 'group' => 'otr', 'type' => Setting::TYPE_SECRET, 'label' => 'Admission OTR API Key'],
            ['key' => 'otr.timeout', 'value' => 10, 'group' => 'otr', 'type' => Setting::TYPE_NUMBER, 'label' => 'Connection Request Timeout (Seconds)'],
            ['key' => 'otr.auto_sync_enabled', 'value' => false, 'group' => 'otr', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Enable Automated Scheduled Sync'],
            ['key' => 'otr.auto_sync_frequency', 'value' => 'daily', 'group' => 'otr', 'type' => Setting::TYPE_SELECT, 'label' => 'Automated Sync Frequency', 'options' => ['hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly']],
            ['key' => 'otr.default_course_id', 'value' => 1, 'group' => 'otr', 'type' => Setting::TYPE_NUMBER, 'label' => 'Default Academic Course for Imports'],
            ['key' => 'otr.default_batch_id', 'value' => 1, 'group' => 'otr', 'type' => Setting::TYPE_NUMBER, 'label' => 'Default Academic Batch for Imports'],
            ['key' => 'otr.download_documents', 'value' => false, 'group' => 'otr', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Download and Store Document Files to MIS Storage'],
            ['key' => 'otr.update_existing_profiles', 'value' => true, 'group' => 'otr', 'type' => Setting::TYPE_BOOLEAN, 'label' => 'Update Student Dossier If Candidate Already Exists'],
        ];
    }
}
