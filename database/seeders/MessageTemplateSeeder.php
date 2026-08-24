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
        ];

        foreach ($emailTemplates as $template) {
            EmailTemplate::updateOrCreate(
                ['code' => $template['code']],
                $template
            );
        }
    }
}
