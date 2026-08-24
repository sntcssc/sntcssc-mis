<?php

namespace Database\Seeders;

use App\Models\CronJob;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Models\TicketCannedResponse;
use App\Models\TicketCategory;
use Illuminate\Database\Seeder;

class TicketSystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Default Categories
        $categories = [
            [
                'name' => 'Technical Support',
                'slug' => 'technical-support',
                'description' => 'Website, portal logins, password issues, video player or mock test technical errors.',
                'icon' => 'cpu',
                'color_badge' => 'indigo',
                'default_priority' => 'medium',
                'sla_response_hours' => 12,
                'sla_resolution_hours' => 48,
                'sort_order' => 1,
            ],
            [
                'name' => 'Admissions & Enrollments',
                'slug' => 'admissions-enrollments',
                'description' => 'Candidate entrance exams, admission forms, selection lists, and registration status.',
                'icon' => 'user-plus',
                'color_badge' => 'emerald',
                'default_priority' => 'high',
                'sla_response_hours' => 6,
                'sla_resolution_hours' => 24,
                'sort_order' => 2,
            ],
            [
                'name' => 'Courses & Academic Queries',
                'slug' => 'courses-academic-queries',
                'description' => 'Syllabus inquiries, batch timings, study materials, faculty guidance, and lecture schedules.',
                'icon' => 'book-open',
                'color_badge' => 'blue',
                'default_priority' => 'medium',
                'sla_response_hours' => 24,
                'sla_resolution_hours' => 72,
                'sort_order' => 3,
            ],
            [
                'name' => 'Fee & Payment Verification',
                'slug' => 'fee-payment-verification',
                'description' => 'Online payment confirmations, receipts, refund requests, and fee installment queries.',
                'icon' => 'credit-card',
                'color_badge' => 'amber',
                'default_priority' => 'high',
                'sla_response_hours' => 6,
                'sla_resolution_hours' => 24,
                'sort_order' => 4,
            ],
            [
                'name' => 'General Inquiries',
                'slug' => 'general-inquiries',
                'description' => 'General institutional queries, office timings, campus facilities, and feedback.',
                'icon' => 'help-circle',
                'color_badge' => 'zinc',
                'default_priority' => 'low',
                'sla_response_hours' => 24,
                'sla_resolution_hours' => 72,
                'sort_order' => 5,
            ],
        ];

        foreach ($categories as $cat) {
            TicketCategory::updateOrCreate(['slug' => $cat['slug']], $cat);
        }

        // 2. Default Canned Responses / Macros
        $canned = [
            [
                'title' => 'Greeting & Acknowledgment',
                'shortcut' => '/ack',
                'content' => "Hello {user_name},\n\nThank you for contacting {app_name} Support regarding '{ticket_subject}' (Ticket #{ticket_number}). Our support team has logged your inquiry and is currently reviewing it.\n\nWe will provide an update shortly.\n\nWarm regards,\n{agent_name}",
            ],
            [
                'title' => 'Payment Screenshot Request',
                'shortcut' => '/fee-info',
                'content' => "Dear {user_name},\n\nTo help us verify your transaction for Ticket #{ticket_number}, could you kindly reply with your payment reference / UTR number or an attached transaction screenshot?\n\nThank you,\n{agent_name} - Accounts Desk",
            ],
            [
                'title' => 'Issue Resolved Notice',
                'shortcut' => '/resolved',
                'content' => "Dear {user_name},\n\nWe have marked your ticket #{ticket_number} as resolved. If you require further assistance or if anything remains unclear, please feel free to reply directly to reopen this ticket.\n\nThank you for choosing {app_name}!\n\nBest regards,\n{agent_name}",
            ],
        ];

        foreach ($canned as $c) {
            TicketCannedResponse::updateOrCreate(['shortcut' => $c['shortcut']], $c);
        }

        // 3. Email Templates for Tickets
        $emailTemplates = [
            [
                'code' => 'ticket_created_user',
                'name' => 'Ticket Created Confirmation (User)',
                'category' => EmailTemplate::CATEGORY_SUPPORT,
                'subject' => '[{ticket_number}] Support Ticket Logged: {ticket_subject}',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827; margin-bottom: 6px;">Support Ticket Logged</h2>
    <p style="color: #374151; font-size: 14px;">Dear {user_name},</p>
    <p style="color: #374151; font-size: 14px;">Your support ticket <strong>#{ticket_number}</strong> has been logged into our system.</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 13px;">
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Subject:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{ticket_subject}</td></tr>
        <tr style="border-bottom: 1px solid #f3f4f6;"><td style="padding: 8px 0; color: #6b7280;">Category:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{category}</td></tr>
        <tr><td style="padding: 8px 0; color: #6b7280;">Priority:</td><td style="padding: 8px 0; font-weight: 600; color: #111827;">{priority}</td></tr>
    </table>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{ticket_url}" style="background: #2563eb; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;">View Ticket Status</a>
    </div>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;" />
    <p style="color: #9ca3af; font-size: 12px; text-align: center;">&copy; {app_name}. Automated Support Notification.</p>
</div>',
                'variables' => ['user_name', 'ticket_number', 'ticket_subject', 'category', 'priority', 'ticket_url', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'ticket_created_staff',
                'name' => 'New Ticket Alert (Staff)',
                'category' => EmailTemplate::CATEGORY_SUPPORT,
                'subject' => '[ASSIGNED] [{ticket_number}] New Support Ticket: {ticket_subject}',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827; margin-bottom: 6px;">New Ticket Assigned</h2>
    <p style="color: #374151; font-size: 14px;">Hello {staff_name},</p>
    <p style="color: #374151; font-size: 14px;">A new support ticket <strong>#{ticket_number}</strong> from <strong>{user_name}</strong> has been assigned to your queue.</p>
    <p style="color: #374151; font-size: 13px;">Subject: <strong>{ticket_subject}</strong> | Priority: <strong>{priority}</strong></p>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{ticket_url}" style="background: #0284c7; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;">Open Ticket Desk</a>
    </div>
</div>',
                'variables' => ['staff_name', 'ticket_number', 'ticket_subject', 'user_name', 'priority', 'ticket_url', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'ticket_replied_user',
                'name' => 'Ticket Response (User)',
                'category' => EmailTemplate::CATEGORY_SUPPORT,
                'subject' => '[{ticket_number}] New Reply: {ticket_subject}',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827; margin-bottom: 6px;">New Response on Ticket #{ticket_number}</h2>
    <p style="color: #374151; font-size: 14px;">Dear {user_name},</p>
    <p style="color: #374151; font-size: 14px;"><strong>{staff_name}</strong> has replied to your support ticket:</p>
    <div style="background: #f9fafb; border-left: 4px solid #2563eb; padding: 14px; margin: 16px 0; font-size: 13px; color: #1f2937;">
        {reply_excerpt}
    </div>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{ticket_url}" style="background: #2563eb; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;">View Full Thread</a>
    </div>
</div>',
                'variables' => ['user_name', 'staff_name', 'ticket_number', 'ticket_subject', 'reply_excerpt', 'ticket_url', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'ticket_status_changed',
                'name' => 'Ticket Status Update (User)',
                'category' => EmailTemplate::CATEGORY_SUPPORT,
                'subject' => '[{ticket_number}] Status Updated to {new_status}',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #111827; margin-bottom: 6px;">Ticket Status Changed</h2>
    <p style="color: #374151; font-size: 14px;">Dear {user_name},</p>
    <p style="color: #374151; font-size: 14px;">The status of your support ticket <strong>#{ticket_number}</strong> ({ticket_subject}) has been updated from <strong>{old_status}</strong> to <strong>{new_status}</strong>.</p>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{ticket_url}" style="background: #2563eb; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;">Review Ticket</a>
    </div>
</div>',
                'variables' => ['user_name', 'ticket_number', 'ticket_subject', 'old_status', 'new_status', 'ticket_url', 'app_name'],
                'status' => true,
            ],
            [
                'code' => 'ticket_sla_warning',
                'name' => 'Ticket SLA Breach Warning (Staff)',
                'category' => EmailTemplate::CATEGORY_SUPPORT,
                'subject' => '[SLA WARNING] [{ticket_number}] {breach_type} Breached',
                'body' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #fee2e2; border-radius: 12px; background: #ffffff;">
    <h2 style="color: #b91c1c; margin-bottom: 6px;">SLA Breach Alert</h2>
    <p style="color: #374151; font-size: 14px;">Hello {staff_name},</p>
    <p style="color: #374151; font-size: 14px;">Support ticket <strong>#{ticket_number}</strong> has exceeded its target <strong>{breach_type}</strong> deadline.</p>
    <p style="color: #374151; font-size: 13px;">Subject: <strong>{ticket_subject}</strong> | Priority: <strong>{priority}</strong></p>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{ticket_url}" style="background: #b91c1c; color: #ffffff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600;">Address Ticket Now</a>
    </div>
</div>',
                'variables' => ['staff_name', 'ticket_number', 'ticket_subject', 'breach_type', 'priority', 'ticket_url', 'app_name'],
                'status' => true,
            ],
        ];

        foreach ($emailTemplates as $tmpl) {
            EmailTemplate::updateOrCreate(['code' => $tmpl['code']], $tmpl);
        }

        // 4. SMS Templates for Tickets
        $smsTemplates = [
            [
                'code' => 'ticket_created_sms',
                'name' => 'Ticket Created SMS',
                'category' => SmsTemplate::CATEGORY_SUPPORT,
                'sender_id' => 'SNTCSS',
                'dlt_template_id' => '1007161234567890130',
                'body' => 'Dear {user_name}, your support ticket #{ticket_number} has been logged. Track: {ticket_url} - {app_name}',
                'variables' => ['user_name', 'ticket_number', 'ticket_url', 'app_name'],
                'status' => true,
            ],
        ];

        foreach ($smsTemplates as $smsTmpl) {
            SmsTemplate::updateOrCreate(['code' => $smsTmpl['code']], $smsTmpl);
        }

        // 5. Cron Job for SLA monitoring
        CronJob::updateOrCreate(
            ['command' => 'app:tickets:check-sla'],
            [
                'name' => 'Support Tickets SLA Monitor',
                'description' => 'Monitors open support tickets and triggers warnings for response and resolution SLA breaches.',
                'expression' => '*/15 * * * *',
                'is_active' => true,
            ]
        );
    }
}
