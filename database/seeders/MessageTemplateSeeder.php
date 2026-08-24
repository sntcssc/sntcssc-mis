<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

class MessageTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $smsTemplates = [
            [
                'code' => 'otp_registration',
                'name' => 'Registration Mobile OTP',
                'category' => SmsTemplate::CATEGORY_OTP,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890123',
                'body' => 'Dear {name}, your SNT CSSC verification OTP is {otp}. Valid for {expiry} minutes. Do not share this OTP with anyone.',
                'variables' => ['name', 'otp', 'expiry', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'otp_login',
                'name' => 'Login Secure OTP',
                'category' => SmsTemplate::CATEGORY_OTP,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890124',
                'body' => 'Dear {name}, your SNT CSSC login OTP is {otp}. Valid for {expiry} minutes. Use this code to sign in securely.',
                'variables' => ['name', 'otp', 'expiry', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'otp_password_reset',
                'name' => 'Password Reset OTP',
                'category' => SmsTemplate::CATEGORY_OTP,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890125',
                'body' => 'Dear {name}, your password reset OTP for SNT CSSC is {otp}. Valid for {expiry} minutes. If you did not request this, ignore this message.',
                'variables' => ['name', 'otp', 'expiry', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'admission_confirmation',
                'name' => 'Admission Application Confirmation',
                'category' => SmsTemplate::CATEGORY_NOTIFICATION,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890126',
                'body' => 'Dear {name}, your admission application for {course} has been received (App No: {application_no}). Check portal for updates - SNT CSSC.',
                'variables' => ['name', 'course', 'application_no', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'test_schedule_notice',
                'name' => 'Mock Test Schedule Notice',
                'category' => SmsTemplate::CATEGORY_NOTICE,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890127',
                'body' => 'Notice: {test_name} is scheduled on {date} at {time}. Please be prepared. - SNT CSSC Academic Cell.',
                'variables' => ['name', 'test_name', 'date', 'time', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'promotional_broadcast',
                'name' => 'New Course Batch Announcement',
                'category' => SmsTemplate::CATEGORY_PROMOTIONAL,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890128',
                'body' => 'Admissions open for new batch of {course} at SNT CSSC. Visit {url} or contact office to enroll now.',
                'variables' => ['course', 'url', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'subscriber_welcome_sms',
                'name' => 'Subscriber Welcome & Alerts SMS',
                'category' => SmsTemplate::CATEGORY_COMMUNICATION,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890140',
                'body' => 'Welcome to {app_name}! You are subscribed to instant updates. Unsubscribe anytime: {unsubscribe_url}',
                'variables' => ['name', 'app_name', 'unsubscribe_url'],
                'status' => true,
            ],
        ];

        foreach ($smsTemplates as $template) {
            SmsTemplate::updateOrCreate(
                ['code' => $template['code']],
                $template
            );
        }

        $emailTemplates = [
            [
                'code' => 'otp_registration',
                'name' => 'Account Registration Verification OTP',
                'category' => EmailTemplate::CATEGORY_OTP,
                'subject' => '{otp} is your {app_name} Verification Code',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <div style="text-align: center; margin-bottom: 24px;">
        <h2 style="color: #111827; margin-bottom: 4px;">{app_name}</h2>
        <p style="color: #6b7280; font-size: 14px; margin-top: 0;">Email Verification</p>
    </div>
    <p style="color: #374151; font-size: 15px;">Hello <strong>{name}</strong>,</p>
    <p style="color: #374151; font-size: 15px;">Thank you for registering. Use the following One-Time Password (OTP) to verify your account:</p>
    <div style="text-align: center; margin: 28px 0;">
        <span style="display: inline-block; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #059669; background: #ecfdf5; padding: 12px 28px; border-radius: 8px; border: 1px dashed #059669;">{otp}</span>
    </div>
    <p style="color: #6b7280; font-size: 13px;">This verification code will expire in <strong>{expiry} minutes</strong>. If you did not create an account, please disregard this email.</p>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. All rights reserved.</p>
</div>',
                'variables' => ['name', 'otp', 'expiry', 'app_name', 'email'],
                'status' => true,
            ],
            [
                'code' => 'otp_login',
                'name' => 'Login Authentication Code',
                'category' => EmailTemplate::CATEGORY_OTP,
                'subject' => '{otp} is your {app_name} Login Code',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <div style="text-align: center; margin-bottom: 24px;">
        <h2 style="color: #111827; margin-bottom: 4px;">{app_name}</h2>
        <p style="color: #6b7280; font-size: 14px; margin-top: 0;">Secure Sign In</p>
    </div>
    <p style="color: #374151; font-size: 15px;">Hello <strong>{name}</strong>,</p>
    <p style="color: #374151; font-size: 15px;">You requested a one-time login code to sign into your account.</p>
    <div style="text-align: center; margin: 28px 0;">
        <span style="display: inline-block; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #2563eb; background: #eff6ff; padding: 12px 28px; border-radius: 8px; border: 1px dashed #2563eb;">{otp}</span>
    </div>
    <p style="color: #6b7280; font-size: 13px;">This code is valid for <strong>{expiry} minutes</strong>. If you did not initiate this sign in attempt, please review your account security immediately.</p>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. All rights reserved.</p>
</div>',
                'variables' => ['name', 'otp', 'expiry', 'app_name', 'email'],
                'status' => true,
            ],
            [
                'code' => 'otp_password_reset',
                'name' => 'Password Reset Security OTP',
                'category' => EmailTemplate::CATEGORY_OTP,
                'subject' => 'Password Reset OTP for {app_name}',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <div style="text-align: center; margin-bottom: 24px;">
        <h2 style="color: #111827; margin-bottom: 4px;">{app_name}</h2>
        <p style="color: #6b7280; font-size: 14px; margin-top: 0;">Password Reset Request</p>
    </div>
    <p style="color: #374151; font-size: 15px;">Hello <strong>{name}</strong>,</p>
    <p style="color: #374151; font-size: 15px;">We received a request to reset your password. Use the following code to proceed:</p>
    <div style="text-align: center; margin: 28px 0;">
        <span style="display: inline-block; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #dc2626; background: #fef2f2; padding: 12px 28px; border-radius: 8px; border: 1px dashed #dc2626;">{otp}</span>
    </div>
    <p style="color: #6b7280; font-size: 13px;">This code will expire in <strong>{expiry} minutes</strong>. If you did not request a password reset, you can safely ignore this email.</p>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. All rights reserved.</p>
</div>',
                'variables' => ['name', 'otp', 'expiry', 'app_name', 'email'],
                'status' => true,
            ],
            [
                'code' => 'admission_confirmation',
                'name' => 'Admission Confirmation Notice',
                'category' => EmailTemplate::CATEGORY_NOTIFICATION,
                'subject' => 'Application Received: {application_no} - {course}',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827;">Application Acknowledgment</h2>
    <p style="color: #374151; font-size: 15px;">Dear <strong>{name}</strong>,</p>
    <p style="color: #374151; font-size: 15px;">Your application for <strong>{course}</strong> has been received with Application Reference <strong>#{application_no}</strong>.</p>
    <p style="color: #374151; font-size: 15px;">Our admissions desk will verify your submission. You can track your status anytime on the student dashboard.</p>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. All rights reserved.</p>
</div>',
                'variables' => ['name', 'course', 'application_no', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'general_notice',
                'name' => 'Official Notice & Communication',
                'category' => EmailTemplate::CATEGORY_NOTICE,
                'subject' => 'Notice: {notice_title}',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827;">{notice_title}</h2>
    <p style="color: #374151; font-size: 15px;">Dear <strong>{name}</strong>,</p>
    <div style="color: #374151; font-size: 14px; line-height: 1.6; background: #f9fafb; padding: 16px; border-radius: 8px; margin: 16px 0;">
        {notice_content}
    </div>
    <p style="color: #6b7280; font-size: 13px;">Date: {date}</p>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. All rights reserved.</p>
</div>',
                'variables' => ['name', 'notice_title', 'notice_content', 'date', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'backup_completed',
                'name' => 'Database Backup Generated Notice',
                'category' => EmailTemplate::CATEGORY_SYSTEM,
                'subject' => '[{app_name}] Database Backup Generated ({size})',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827; margin-bottom: 6px;">System Backup Successful</h2>
    <p style="color: #374151; font-size: 14px;">A new system backup archive <strong>{filename}</strong> has been generated.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;">
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">File Size:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{size}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Backup Type:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{type}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Tables Dumped:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{tables_count}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Total Records:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{records_count}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">ZIP Attached:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{attached}</td></tr>
        <tr><td style="padding: 8px 0; color: #6b7280;">Generated At:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{date}</td></tr>
    </table>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{download_url}" style="background: #0284c7; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;">Open Backup Dashboard</a>
    </div>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. Automated System Notification.</p>
</div>',
                'variables' => ['app_name', 'filename', 'size', 'type', 'tables_count', 'records_count', 'attached', 'date', 'download_url'],
                'status' => true,
            ],
            [
                'code' => 'backup_failed',
                'name' => 'Database Backup Failure Alert',
                'category' => EmailTemplate::CATEGORY_SYSTEM,
                'subject' => '[URGENT] [{app_name}] Database Backup Failed',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #fee2e2; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #b91c1c; margin-bottom: 6px;">Database Backup Failed</h2>
    <p style="color: #374151; font-size: 14px;">An automated or manual database backup attempt encountered an unexpected error.</p>
    <div style="background: #fef2f2; border: 1px solid #fca5a5; padding: 14px; border-radius: 8px; margin: 16px 0; color: #991b1b; font-family: monospace; font-size: 12px;">
        {error_message}
    </div>
    <p style="color: #6b7280; font-size: 13px;">Trigger: <strong>{trigger_type}</strong> | Timestamp: <strong>{date}</strong></p>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. Automated System Alert.</p>
</div>',
                'variables' => ['app_name', 'error_message', 'trigger_type', 'date'],
                'status' => true,
            ],
            [
                'code' => 'backup_scheduled_report',
                'name' => 'Database Backup Health & Status Report',
                'category' => EmailTemplate::CATEGORY_SYSTEM,
                'subject' => '[{app_name}] Periodic Backup Health Report',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827; margin-bottom: 6px;">Database Backup Health Report</h2>
    <p style="color: #374151; font-size: 14px;">Here is the current operational summary of your database backups and disk volume.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;">
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Total Stored Backups:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{total_backups}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Total Storage Used:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{total_storage}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Latest Backup Archive:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{latest_backup}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Latest Backup Date:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{latest_date}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Automated Schedule:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{schedule_status}</td></tr>
        <tr><td style="padding: 8px 0; color: #6b7280;">Report Generated:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{report_date}</td></tr>
    </table>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{dashboard_url}" style="background: #0284c7; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;">Manage Backups</a>
    </div>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. Automated System Report.</p>
</div>',
                'variables' => ['app_name', 'total_backups', 'total_storage', 'latest_backup', 'latest_date', 'latest_status', 'schedule_status', 'report_date', 'dashboard_url'],
                'status' => true,
            ],
            [
                'code' => 'subscriber_welcome_email',
                'name' => 'Subscriber Welcome & Confirmation Email',
                'category' => EmailTemplate::CATEGORY_COMMUNICATION,
                'subject' => 'Welcome to {app_name} Updates & Newsletter',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827; margin-bottom: 6px;">Welcome to {app_name}!</h2>
    <p style="color: #374151; font-size: 14px;">Hello {name},</p>
    <p style="color: #374151; font-size: 14px;">Thank you for subscribing to the {app_name} newsletter and updates list. You will receive the latest announcements regarding admissions, mock test schedules, lecture updates, and civil services examination insights.</p>
    <div style="background: #f0fdf4; border-left: 4px solid #16a34a; padding: 14px; margin: 18px 0; font-size: 13px; color: #166534;">
        <strong>Subscription Active:</strong> Your email <code>{email}</code> is confirmed.
    </div>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 11px; text-align: center;">
        &copy; {app_name}. If you wish to stop receiving these updates, you can <a href="{unsubscribe_url}" style="color: #6b7280; text-decoration: underline;">unsubscribe here</a>.
    </p>
</div>',
                'variables' => ['name', 'email', 'phone', 'app_name', 'unsubscribe_url'],
                'status' => true,
            ],
        ];

        foreach ($emailTemplates as $template) {
            EmailTemplate::updateOrCreate(
                ['code' => $template['code']],
                $template
            );
        }
    }
}
