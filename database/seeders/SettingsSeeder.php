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
            ['key' => 'system.app_name', 'value' => 'SNT CSSC MIS', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'System app name'],
            ['key' => 'system.app_version', 'value' => '1.0.0', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'App version'],
            ['key' => 'system.developed_by', 'value' => 'SNT CSSC IT Team', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developed by'],
            ['key' => 'system.developer_contact', 'value' => 'dev@sntcssc.in', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developer contact'],
            ['key' => 'system.developer_github', 'value' => 'https://github.com/sntcssc', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developer GitHub'],
            ['key' => 'system.developer_website', 'value' => 'https://sntcssc.in', 'group' => 'system', 'type' => Setting::TYPE_STRING, 'label' => 'Developer website'],
            ['key' => 'system.max_upload_size', 'value' => 10, 'group' => 'system', 'type' => Setting::TYPE_NUMBER, 'label' => 'Max upload size (MB)'],
            ['key' => 'system.session_lifetime', 'value' => 120, 'group' => 'system', 'type' => Setting::TYPE_NUMBER, 'label' => 'Session lifetime (minutes)'],
            ['key' => 'system.cache_driver', 'value' => 'file', 'group' => 'system', 'type' => Setting::TYPE_SELECT, 'label' => 'Cache driver', 'options' => ['file' => 'File', 'redis' => 'Redis', 'database' => 'Database']],

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
            ['key' => 'payment.driver', 'value' => 'razorpay', 'group' => 'payment', 'type' => Setting::TYPE_SELECT, 'label' => 'Active payment gateway', 'options' => ['razorpay' => 'Razorpay', 'phonepe' => 'PhonePe']],
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
        ];
    }
}
