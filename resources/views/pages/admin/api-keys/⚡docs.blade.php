<?php

use App\Models\ApiKey;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('API Developer Documentation & Console - SNTCSSC MIS')] class extends Component
{
    public string $activeSnippetTab = 'curl'; // curl, flutter, react_native, python, csharp, electron

    public string $activeScopeTab = 'all';

    // Interactive Sandbox state
    public string $selectedApiKeyId = '';

    public string $sandboxKeyInput = '';

    public string $sandboxMethod = 'GET';

    public string $sandboxEndpoint = '/api/v1/ping';

    public string $sandboxPayload = "{\n  \"message\": \"Hello from API Console\"\n}";

    public string $sandboxCategoryFilter = 'all';

    public ?int $sandboxStatusCode = null;

    public ?float $sandboxDurationMs = null;

    public ?array $sandboxHeaders = null;

    public ?array $sandboxResponse = null;

    public bool $isExecuting = false;

    #[Computed]
    public function availableKeys()
    {
        return ApiKey::active()->orderBy('name')->get(['id', 'name', 'key_prefix', 'environment']);
    }

    #[Computed]
    public function scopesCatalog(): array
    {
        return ApiKeyService::getAvailableScopes();
    }

    public function updatedSelectedApiKeyId(): void
    {
        if ($this->selectedApiKeyId) {
            $key = ApiKey::find($this->selectedApiKeyId);
            $this->sandboxKeyInput = $key ? $key->key_prefix : '';
        }
    }

    public function selectPreset(string $method, string $endpoint, ?string $payload = null): void
    {
        $this->sandboxMethod = strtoupper($method);

        // Replace route path parameters with testable mock values if clicked from reference table
        $normalizedEndpoint = str_replace(
            ['{id}', '{identifier}', '{key}', '{group}', '{fileId}', '{course}', '{batch}', '{token}', '{uuid}', '{receiptNo}', '{messageId}', '{attachmentId}', '{versionId}', '{activityId}'],
            ['1', '1', 'general.site_name', 'general', '1', '1', '1', 'test_sample_token', 'sample-uuid-001', 'RCPT-2026-0001', '1', '1', '1', '1'],
            $endpoint
        );

        $this->sandboxEndpoint = $normalizedEndpoint;

        if ($payload !== null) {
            $this->sandboxPayload = $payload;
        } elseif (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            if (str_contains($endpoint, 'captcha/logs/prune')) {
                $this->sandboxPayload = "{\n  \"days\": 30,\n  \"force\": false\n}";
            } elseif (str_contains($endpoint, 'captcha/refresh')) {
                $this->sandboxPayload = "{\n  \"action\": \"login\",\n  \"old_token\": \"sample_test_token\"\n}";
            } elseif (str_contains($endpoint, 'captcha/verify')) {
                $this->sandboxPayload = "{\n  \"action\": \"api\",\n  \"token\": \"sample_test_token\",\n  \"answer\": \"7B9KX\"\n}";
            } elseif (str_contains($endpoint, 'search/preferences')) {
                $this->sandboxPayload = "{\n  \"default_fullscreen\": \"fullscreen\",\n  \"search_history_enabled\": true,\n  \"quick_actions_enabled\": true\n}";
            } elseif (str_contains($endpoint, 'communications/send-email')) {
                $this->sandboxPayload = "{\n  \"email\": \"aspirant@example.com\",\n  \"subject\": \"UPSC Civil Services Foundation 2026 Orientation\",\n  \"content\": \"<p>Dear {name}, welcome to SNTCSSC. Your preliminary orientation session is scheduled for Monday.</p>\",\n  \"variables\": {\n    \"name\": \"Arjun Das\"\n  },\n  \"queue\": false\n}";
            } elseif (str_contains($endpoint, 'communications/send-sms')) {
                $this->sandboxPayload = "{\n  \"phone\": \"+919876543210\",\n  \"content\": \"Dear {name}, your CSAT Mock Test 4 seating is confirmed at Hall B. - SNTCSSC\",\n  \"sender_id\": \"SNTCSS\",\n  \"dlt_template_id\": \"1107161829304918234\",\n  \"variables\": {\n    \"name\": \"Pooja Roy\"\n  },\n  \"queue\": false\n}";
            } elseif (str_contains($endpoint, 'communications/send-bulk')) {
                $this->sandboxPayload = "{\n  \"title\": \"Mains 2026 Preparation Workshop Notice\",\n  \"channel\": \"email\",\n  \"recipient_type\": \"all_users\",\n  \"subject\": \"Special Mains Answer Writing Workshop\",\n  \"content\": \"<p>Dear {name}, join tomorrow's live essay critique session at 11:00 AM.</p>\",\n  \"immediate\": true\n}";
            } elseif (str_contains($endpoint, 'communications/logs/bulk-resend')) {
                $this->sandboxPayload = "{\n  \"log_ids\": [1, 2]\n}";
            } elseif (str_contains($endpoint, 'communications/logs/purge')) {
                $this->sandboxPayload = "{\n  \"days_older_than\": 90,\n  \"status\": \"failed\"\n}";
            } elseif (str_contains($endpoint, 'communications/campaigns') && str_contains($endpoint, '/dispatch')) {
                $this->sandboxPayload = "{\n  \"async\": false\n}";
            } elseif (str_contains($endpoint, 'communications/campaigns')) {
                $this->sandboxPayload = "{\n  \"title\": \"Civil Services Prelims 2026 Final Sprint\",\n  \"channel\": \"email\",\n  \"recipient_type\": \"all_users\",\n  \"subject\": \"Prelims Sprint Strategy Announcement\",\n  \"content\": \"<p>Dear {name}, review the final revision schedule now available in the portal.</p>\"\n}";
            } elseif (str_contains($endpoint, 'communications/subscribers/bulk-action')) {
                $this->sandboxPayload = "{\n  \"action\": \"add_tag\",\n  \"subscriber_ids\": [1],\n  \"tag\": \"upsc_2026_aspirant\"\n}";
            } elseif (str_contains($endpoint, 'communications/subscribers/import')) {
                $this->sandboxPayload = "{\n  \"subscribers\": [\n    {\n      \"name\": \"Ananya Sen\",\n      \"email\": \"ananya.sen@example.com\",\n      \"phone\": \"+919830112233\",\n      \"tags\": [\"webinar_lead\"]\n    }\n  ]\n}";
            } elseif (str_contains($endpoint, 'communications/subscribers') && str_contains($endpoint, '/unsubscribe')) {
                $this->sandboxPayload = "{\n  \"reason\": \"Requested removal via support desk\"\n}";
            } elseif (str_contains($endpoint, 'communications/subscribers')) {
                $this->sandboxPayload = "{\n  \"name\": \"Siddharth Ghosh\",\n  \"email\": \"siddharth.ghosh@example.com\",\n  \"phone\": \"+919876543210\",\n  \"country_code\": \"+91\",\n  \"type\": \"both\",\n  \"source\": \"course_inquiry\",\n  \"tags\": [\"upsc_2026\", \"prelims_crash_course\"]\n}";
            } elseif (str_contains($endpoint, 'communications/public/subscribe')) {
                $this->sandboxPayload = "{\n  \"email\": \"aspirant.lead@gmail.com\",\n  \"phone\": \"+919876500000\",\n  \"name\": \"Sourav Mondal\",\n  \"source\": \"welcome_page\",\n  \"tags\": [\"public_lead\"]\n}";
            } elseif (str_contains($endpoint, 'communications/public/unsubscribe')) {
                $this->sandboxPayload = "{\n  \"email\": \"aspirant.lead@gmail.com\",\n  \"reason\": \"No longer interested\"\n}";
            } elseif (str_contains($endpoint, 'communications/templates/email') && str_contains($endpoint, '/preview')) {
                $this->sandboxPayload = "{\n  \"variables\": {\n    \"name\": \"John Doe\",\n    \"batch_name\": \"Civil Services 2026 Batch A\"\n  }\n}";
            } elseif (str_contains($endpoint, 'communications/templates/email')) {
                $this->sandboxPayload = "{\n  \"code\": \"prelims_hall_ticket_alert\",\n  \"name\": \"Prelims Hall Ticket Release Alert\",\n  \"category\": \"notice\",\n  \"subject\": \"Important: Download Your Hall Ticket, {name}\",\n  \"body\": \"<p>Dear {name}, your Hall Ticket for {batch_name} is now available on your dashboard.</p>\",\n  \"variables\": [\"name\", \"batch_name\"],\n  \"status\": true\n}";
            } elseif (str_contains($endpoint, 'communications/templates/sms') && str_contains($endpoint, '/preview')) {
                $this->sandboxPayload = "{\n  \"variables\": {\n    \"name\": \"Jane Doe\",\n    \"date\": \"15 Nov 2026\"\n  }\n}";
            } elseif (str_contains($endpoint, 'communications/templates/sms')) {
                $this->sandboxPayload = "{\n  \"code\": \"mock_exam_alert\",\n  \"name\": \"Mock Exam Reminder Alert\",\n  \"category\": \"notification\",\n  \"sender_id\": \"SNTCSS\",\n  \"dlt_template_id\": \"1107161829304918235\",\n  \"body\": \"Dear {name}, Mock Exam starts at 10:00 AM on {date}. Report 15m early. - SNTCSSC\",\n  \"variables\": [\"name\", \"date\"],\n  \"status\": true\n}";
            } elseif (str_contains($endpoint, 'tickets/categories')) {
                $this->sandboxPayload = "{\n  \"name\": \"Admissions & Enrollment\",\n  \"description\": \"Course admissions, entrance tests and batch enrollment inquiries\",\n  \"icon\": \"user-check\",\n  \"color_badge\": \"blue\",\n  \"default_priority\": \"medium\",\n  \"sla_response_hours\": 24,\n  \"sla_resolution_hours\": 72\n}";
            } elseif (str_contains($endpoint, 'tickets/canned-responses')) {
                $this->sandboxPayload = "{\n  \"title\": \"Fee Receipt Verification\",\n  \"shortcut\": \"/receipt-verify\",\n  \"content\": \"Dear {user_name},\\n\\nYour payment has been successfully verified. Receipt #{ticket_number} is available on your student portal.\\n\\nRegards,\\n{agent_name}\"\n}";
            } elseif (str_contains($endpoint, 'tickets/bulk-action')) {
                $this->sandboxPayload = "{\n  \"action\": \"status\",\n  \"ticket_ids\": [1],\n  \"value\": \"in_progress\"\n}";
            } elseif (str_contains($endpoint, 'tickets') && str_contains($endpoint, '/messages')) {
                $this->sandboxPayload = "{\n  \"message\": \"Thank you for contacting support. We are actively investigating your issue and will update you shortly.\",\n  \"type\": \"public_reply\",\n  \"new_status\": \"in_progress\"\n}";
            } elseif (str_contains($endpoint, 'tickets') && str_contains($endpoint, '/rate')) {
                $this->sandboxPayload = "{\n  \"rating\": 5,\n  \"feedback\": \"Prompt and helpful support! Issue resolved within a few hours.\"\n}";
            } elseif (str_contains($endpoint, 'tickets') && str_contains($endpoint, '/status')) {
                $this->sandboxPayload = "{\n  \"status\": \"resolved\",\n  \"reason\": \"Issue resolved following student verification.\"\n}";
            } elseif (str_contains($endpoint, 'tickets') && str_contains($endpoint, '/priority')) {
                $this->sandboxPayload = "{\n  \"priority\": \"high\"\n}";
            } elseif (str_contains($endpoint, 'tickets') && str_contains($endpoint, '/assign')) {
                $this->sandboxPayload = "{\n  \"assigned_to_user_id\": 1\n}";
            } elseif (str_contains($endpoint, 'tickets')) {
                $this->sandboxPayload = "{\n  \"subject\": \"Unable to access Civil Services Mock Test 4\",\n  \"description\": \"I am enrolled in Batch A and attempting to start the Mock Examination, but the system shows enrollment pending.\",\n  \"category_id\": 1,\n  \"priority\": \"high\",\n  \"source\": \"portal\"\n}";
            } elseif (str_contains($endpoint, 'study-materials/types')) {
                $this->sandboxPayload = "{\n  \"name\": \"Current Affairs Capsule\",\n  \"code\": \"current_affairs_sample\",\n  \"icon\": \"book-open\",\n  \"badge_color\": \"emerald\",\n  \"is_public\": true\n}";
            } elseif (str_contains($endpoint, 'study-materials') && str_contains($endpoint, 'track-view')) {
                $this->sandboxPayload = "{\n  \"platform\": \"mobile_app\",\n  \"device\": \"Flutter Android 14\"\n}";
            } elseif (str_contains($endpoint, 'study-materials') && str_contains($endpoint, 'share-link')) {
                $this->sandboxPayload = "{\n  \"expires_in_days\": 7,\n  \"password\": \"StudySecret2026\"\n}";
            } elseif (str_contains($endpoint, 'study-materials')) {
                $this->sandboxPayload = "{\n  \"title\": \"UPSC CSAT Analytical Reasoning Handout 01\",\n  \"course_id\": 1,\n  \"material_type\": \"handout\",\n  \"subject\": \"General Studies\",\n  \"topic\": \"Reading Comprehension\",\n  \"status\": \"published\",\n  \"access_level\": \"enrolled_only\"\n}";
            } elseif (str_contains($endpoint, 'settings/version-check')) {
                $this->sandboxPayload = "{\n  \"platform\": \"android\",\n  \"current_version\": \"1.0.0\",\n  \"build_number\": 10\n}";
            } elseif (str_contains($endpoint, 'settings/storage/signed-upload-url')) {
                $this->sandboxPayload = "{\n  \"filename\": \"upsc-mains-syllabus.pdf\",\n  \"content_type\": \"application/pdf\",\n  \"size_bytes\": 1048576,\n  \"directory\": \"materials\"\n}";
            } elseif (str_contains($endpoint, 'settings/batch')) {
                $this->sandboxPayload = "{\n  \"general.site_name\": \"SNT CSSC\",\n  \"appearance.primary_color\": \"#10b981\",\n  \"localization.timezone\": \"Asia/Kolkata\"\n}";
            } elseif (str_contains($endpoint, 'settings/maintenance')) {
                $this->sandboxPayload = "{\n  \"enabled\": false,\n  \"message\": \"System maintenance in progress.\",\n  \"allowed_ips\": [\"127.0.0.1\"]\n}";
            } elseif (str_contains($endpoint, 'settings') && str_ends_with(rtrim($endpoint, '/'), '/settings')) {
                $this->sandboxPayload = "{\n  \"key\": \"custom.app_banner\",\n  \"group\": \"general\",\n  \"type\": \"string\",\n  \"label\": \"App Banner Announcement\",\n  \"value\": \"Welcome Aspirants\",\n  \"status\": true\n}";
            } elseif (str_contains($endpoint, 'settings')) {
                $this->sandboxPayload = "{\n  \"value\": \"SNT CSSC MIS\",\n  \"status\": true\n}";
            } elseif (str_contains($endpoint, 'classes/settings/reminders')) {
                $this->sandboxPayload = "{\n  \"enabled\": true,\n  \"lead_time_minutes\": 60,\n  \"channels\": [\"whatsapp\", \"email\", \"sms\"],\n  \"template\": \"Dear {student_name}, your class on {subject} ({course_name}) begins at {class_time}. Mode: {delivery_mode}. Venue/Link: {venue_or_link}.\"\n}";
            } elseif (str_contains($endpoint, 'classes') && str_contains($endpoint, '/complete')) {
                $this->sandboxPayload = "{\n  \"completion_notes\": \"Lecture completed successfully on topic Freedom Struggle.\"\n}";
            } elseif (str_contains($endpoint, 'classes') && str_contains($endpoint, '/send-reminder')) {
                $this->sandboxPayload = "{\n  \"channels\": [\"whatsapp\", \"email\"],\n  \"custom_message\": \"Lecture commences on time. Please keep bare acts ready.\"\n}";
            } elseif (str_contains($endpoint, 'faculties') || str_contains($endpoint, 'classes/faculties')) {
                $this->sandboxPayload = "{\n  \"name\": \"Dr. Amit Roy\",\n  \"email\": \"amit.roy@sntcssc.org\",\n  \"phone\": \"+919830012345\",\n  \"designation\": \"Professor\",\n  \"department\": \"Ancient History\",\n  \"faculty_type\": \"regular\",\n  \"specialization\": \"Vedic Culture & Archaeology\",\n  \"qualification\": \"Ph.D., UGC-NET\",\n  \"bio\": \"Senior faculty with 20+ years guiding Civil Services aspirants.\",\n  \"subjects\": [\"Ancient History\", \"Art & Culture\"],\n  \"status\": \"active\"\n}";
            } elseif (str_contains($endpoint, 'classes/course-plans')) {
                $this->sandboxPayload = "{\n  \"title\": \"UPSC Prelims Comprehensive Annual Plan 2026-27\",\n  \"batch_id\": 1,\n  \"course_id\": 1,\n  \"plan_type\": \"annual\",\n  \"start_date\": \"2026-07-01\",\n  \"end_date\": \"2027-04-30\",\n  \"total_lectures_planned\": 120,\n  \"topics_covered\": \"History, Polity, Geography, Economy, Science & Tech\",\n  \"version\": \"1.0\",\n  \"status\": \"published\"\n}";
            } elseif (str_contains($endpoint, 'academic-calendars') && str_contains($endpoint, 'set-current')) {
                $this->sandboxPayload = '{}';
            } elseif (str_contains($endpoint, 'academic-calendars') && str_contains($endpoint, 'versions')) {
                $this->sandboxPayload = "{\n  \"version_code\": \"v2.0\",\n  \"title\": \"Academic Calendar 2026-2027 (Revised Edition)\",\n  \"changelog_summary\": \"Updated winter session exam slots and revised institutional holidays.\",\n  \"approved_by\": \"Academic Council & Governing Body\",\n  \"effective_date\": \"2026-11-15\"\n}";
            } elseif (str_contains($endpoint, 'academic-calendars') && str_contains($endpoint, 'activities')) {
                $this->sandboxPayload = "{\n  \"title\": \"UPSC Prelims Strategy & Revision Camp\",\n  \"category\": \"academic_event\",\n  \"start_date\": \"2026-12-10\",\n  \"end_date\": \"2026-12-12\",\n  \"target_audience\": \"all_students\",\n  \"venue_or_mode\": \"Main Auditorium & Online Stream\",\n  \"description\": \"Comprehensive 3-day marathon lecture and question discussion series.\",\n  \"status\": \"confirmed\",\n  \"is_tentative\": false\n}";
            } elseif (str_contains($endpoint, 'academic-calendars')) {
                $this->sandboxPayload = "{\n  \"session_year\": \"2027-2028\",\n  \"academic_year\": 2027,\n  \"title\": \"Institutional Academic Calendar 2027-2028\",\n  \"start_date\": \"2027-07-01\",\n  \"end_date\": \"2028-06-30\",\n  \"total_working_days\": 245,\n  \"total_instructional_days\": 195,\n  \"status\": \"draft\",\n  \"is_current\": false,\n  \"remarks\": \"Proposed annual academic plan approved by Governing Body.\"\n}";
            } elseif (str_contains($endpoint, 'documents') && str_contains($endpoint, 'set-current')) {
                $this->sandboxPayload = '{}';
            } elseif (str_contains($endpoint, 'documents') && str_contains($endpoint, 'categories')) {
                $this->sandboxPayload = "{\n  \"name\": \"Administrative Circulars & Gazettes\",\n  \"description\": \"Official notifications, office orders, and policy directives.\",\n  \"is_active\": true\n}";
            } elseif (str_contains($endpoint, 'documents') && str_contains($endpoint, 'versions')) {
                $this->sandboxPayload = "{\n  \"version_code\": \"v2.0\",\n  \"changelog_notes\": \"Revised policy document after Governing Council review. Updated annexure tables.\",\n  \"set_as_current\": true\n}";
            } elseif (str_contains($endpoint, 'documents') && $method === 'PUT') {
                $this->sandboxPayload = "{\n  \"title\": \"UPSC Civil Services Foundation Course Regulations (Updated)\",\n  \"category_id\": 1,\n  \"purpose\": \"Academic and Administrative Guidelines for 2026-2027\",\n  \"description\": \"Comprehensive operating manual and regulatory syllabus framework.\",\n  \"note\": \"Applicable to all enrolled students and course coordinators.\",\n  \"remarks\": \"Approved by Governing Body.\"\n}";
            } elseif (str_contains($endpoint, 'documents') && $method === 'POST') {
                $this->sandboxPayload = "{\n  \"title\": \"UPSC Civil Services Foundation Course Regulations\",\n  \"category_id\": 1,\n  \"purpose\": \"Academic Guidelines 2026-2027\",\n  \"description\": \"Comprehensive operating manual and regulatory syllabus framework.\",\n  \"note\": \"Confidential internal circulation.\",\n  \"remarks\": \"First edition draft.\"\n}";
            } elseif (str_contains($endpoint, 'classes') && str_contains($endpoint, '/reschedule')) {
                $this->sandboxPayload = "{\n  \"class_date\": \"2026-11-20\",\n  \"start_time\": \"14:00:00\",\n  \"end_time\": \"16:00:00\",\n  \"reschedule_reason\": \"Symposium scheduling conflict\"\n}";
            } elseif (str_contains($endpoint, 'classes') && str_contains($endpoint, '/cancel')) {
                $this->sandboxPayload = "{\n  \"cancel_reason\": \"National holiday observance\"\n}";
            } elseif (str_contains($endpoint, 'classes') && str_contains($endpoint, '/recording')) {
                $this->sandboxPayload = "{\n  \"recording_url\": \"https://zoom.us/rec/share/sample-recording-link\",\n  \"recording_password\": \"Passcode2026\",\n  \"recording_notes\": \"PYQ discussion 2020-2025\",\n  \"recording_status\": \"available\"\n}";
            } elseif (str_contains($endpoint, 'classes')) {
                $this->sandboxPayload = "{\n  \"batch_id\": 1,\n  \"course_id\": 1,\n  \"class_type\": \"regular\",\n  \"class_date\": \"2026-11-15\",\n  \"start_time\": \"10:00:00\",\n  \"end_time\": \"12:00:00\",\n  \"subject\": \"Modern Indian History\",\n  \"topics\": \"Freedom Struggle 1919-1947\",\n  \"delivery_mode\": \"online\",\n  \"platform\": \"Zoom\",\n  \"meeting_link\": \"https://zoom.us/j/1234567890\",\n  \"faculty_ids\": [1]\n}";
            } elseif (str_contains($endpoint, 'classrooms')) {
                $this->sandboxPayload = "{\n  \"name\": \"Smart Lecture Hall A\",\n  \"room_code\": \"CR-HALL-A\",\n  \"building\": \"Main Academic Block\",\n  \"floor\": \"1st Floor\",\n  \"capacity\": 80,\n  \"room_type\": \"lecture_hall\",\n  \"status\": \"active\",\n  \"facilities\": [\"Projector & Smart Board\", \"Air Conditioned\", \"High-speed Wi-Fi\"]\n}";
            } elseif (str_contains($endpoint, 'partner-institutes')) {
                $this->sandboxPayload = "{\n  \"name\": \"Apex IAS Academy\",\n  \"code\": \"INST-APEX-01\",\n  \"description\": \"Affiliated coaching partner for mock test interviews\",\n  \"website_url\": \"https://apexias.example.com\",\n  \"email\": \"director@apexias.example.com\",\n  \"mobile\": \"+919876543210\",\n  \"status\": \"active\",\n  \"start_date\": \"2026-01-01\",\n  \"end_date\": \"2027-12-31\",\n  \"official_name\": \"Dr. S. K. Roy\",\n  \"official_designation\": \"Director\",\n  \"official_contact\": \"+919876500000\",\n  \"official_email\": \"skroy@apexias.example.com\"\n}";
            } elseif (str_contains($endpoint, 'faculty/classes') && str_contains($endpoint, '/recording')) {
                $this->sandboxPayload = "{\n  \"recording_url\": \"https://zoom.us/rec/share/faculty-session-record\",\n  \"recording_password\": \"Passcode2026\",\n  \"recording_notes\": \"Syllabus Unit 4 Completion\",\n  \"recording_status\": \"available\"\n}";
            } elseif (str_contains($endpoint, 'faculty/classes') && str_contains($endpoint, '/reschedule')) {
                $this->sandboxPayload = "{\n  \"new_date\": \"2026-11-25\",\n  \"start_time\": \"10:00:00\",\n  \"end_time\": \"12:00:00\",\n  \"reason\": \"Attending academic conference\"\n}";
            } elseif (str_contains($endpoint, 'mock-interviews') && str_contains($endpoint, '/evaluate')) {
                $this->sandboxPayload = "{\n  \"marks_obtained\": 195,\n  \"max_marks\": 275,\n  \"feedback\": \"Excellent clarity on Indian foreign policy and constitutional ethics. Strong posture.\",\n  \"status\": \"completed\"\n}";
            } elseif (str_contains($endpoint, 'mock-interviews') && str_contains($endpoint, '/recording')) {
                $this->sandboxPayload = "{\n  \"recording_url\": \"https://zoom.us/rec/share/sample-mock-interview-session-recording\",\n  \"recording_password\": \"UpscMock2026\"\n}";
            } elseif (str_contains($endpoint, 'mock-interviews') && str_contains($endpoint, '/invitations/send')) {
                $this->sandboxPayload = "{\n  \"channels\": [\"email\", \"sms\"],\n  \"recipient_type\": \"all\"\n}";
            } elseif (str_contains($endpoint, 'mock-interviews') && str_contains($endpoint, '/invitations/templates')) {
                $this->sandboxPayload = "{\n  \"chairman_template\": \"Dear {chairman_name}, You are cordially invited to chair the UPSC Mock Interview Board Session #{session_no} on {session_date}.\",\n  \"member_template\": \"Dear {member_name}, You are invited as a Board Panelist Member for Session #{session_no} on {session_date}.\",\n  \"student_template\": \"Dear {student_name}, Your UPSC Mock Interview is scheduled for {session_date} from {start_time} to {end_time} ({duration_minutes} mins). Mode: {interview_mode}.\"\n}";
            } elseif (str_contains($endpoint, 'mock-interviews') && str_contains($endpoint, '/candidates') && ! str_contains($endpoint, '/reorder')) {
                $this->sandboxPayload = "{\n  \"student_id\": 1,\n  \"interview_mode\": \"online\",\n  \"platform\": \"Zoom\",\n  \"meeting_link\": \"https://zoom.us/j/9876543210\",\n  \"meeting_password\": \"Mock2026\",\n  \"start_time\": \"10:00:00\",\n  \"end_time\": \"10:30:00\",\n  \"order_index\": 1,\n  \"status\": \"scheduled\"\n}";
            } elseif (str_contains($endpoint, 'mock-interviews') && str_contains($endpoint, '/members')) {
                $this->sandboxPayload = "{\n  \"user_id\": 1,\n  \"role\": \"board_member\",\n  \"order_index\": 2,\n  \"status\": \"confirmed\"\n}";
            } elseif (str_contains($endpoint, 'mock-interviews') && str_contains($endpoint, '/sessions')) {
                $this->sandboxPayload = "{\n  \"academic_year\": 2026,\n  \"session_no\": \"SESSION-2026-001\",\n  \"session_date\": \"2026-11-20\",\n  \"start_time\": \"09:30:00\",\n  \"end_time\": \"17:30:00\",\n  \"venue_address\": \"Conference Hall A, Administrative Block, SNTCSSC Salt Lake\",\n  \"status\": \"scheduled\",\n  \"instructions\": \"Formal Western or Indian attire required. Carry 3 copies of DAF.\"\n}";
            } elseif (str_contains($endpoint, 'information-sheets/bulk') || str_contains($endpoint, 'information-sheets/download')) {
                $this->sandboxPayload = "{\n  \"ids\": [1, 2],\n  \"include_documents\": true\n}";
            } elseif (str_contains($endpoint, 'ccavenue/initiate')) {
                $this->sandboxPayload = "{\n  \"invoice_id\": 1,\n  \"amount\": 1000.00,\n  \"redirect_url\": \"https://app.sntcssc.in/payments/callback\",\n  \"cancel_url\": \"https://app.sntcssc.in/payments/cancel\"\n}";
            } elseif (str_contains($endpoint, 'ccavenue/callback')) {
                $this->sandboxPayload = "{\n  \"encResp\": \"4886c852179cd45a4543c124239854db...\"\n}";
            } elseif (str_contains($endpoint, 'ccavenue/sync-status')) {
                $this->sandboxPayload = "{\n  \"transaction_identifier\": \"TXN-2026-00001\"\n}";
            } elseif (str_contains($endpoint, 'ccavenue/test-encryption')) {
                $this->sandboxPayload = "{\n  \"plaintext\": \"order_id=TEST_HANDSHAKE_01&amount=500.00&currency=INR\"\n}";
            } elseif (str_contains($endpoint, 'transactions') && str_contains($endpoint, '/refund')) {
                $this->sandboxPayload = "{\n  \"amount\": 500.00,\n  \"reason\": \"Student duplicate payment refund request\",\n  \"reference_no\": \"REF-DEBIT-VOUCHER-01\",\n  \"attempt_gateway_api\": false\n}";
            } elseif (str_contains($endpoint, 'transactions') && str_contains($endpoint, '/reconcile')) {
                $this->sandboxPayload = "{\n  \"status\": \"completed\",\n  \"gateway_tracking_id\": \"CC_RECON_998877\",\n  \"gateway_bank_ref_no\": \"HDFC_RECON_1122\",\n  \"gateway_payment_mode\": \"Net Banking\",\n  \"gateway_charged_amount\": 1000.00,\n  \"gateway_fee\": 0.00,\n  \"reason\": \"Verified against bank settlement statement\"\n}";
            } else {
                $this->sandboxPayload = "{\n  \"example\": true\n}";
            }
        }
    }

    public function executeSandboxRequest(): void
    {
        $this->isExecuting = true;
        $startTime = microtime(true);

        try {
            $key = $this->selectedApiKeyId ? ApiKey::find($this->selectedApiKeyId) : ApiKey::active()->first();
            if (! $key && auth()->check()) {
                $key = new ApiKey([
                    'name' => 'Console Auto-Simulator',
                    'key_prefix' => 'sntc_auto',
                    'type' => 'developer_console',
                    'environment' => 'sandbox',
                    'status' => 'active',
                    'scopes' => ['*'],
                    'user_id' => auth()->id(),
                ]);
            }
            $method = strtoupper($this->sandboxMethod);
            $isGetOrHead = in_array($method, ['GET', 'HEAD'], true);
            $parsedPayload = $isGetOrHead ? [] : (json_decode($this->sandboxPayload, true) ?: []);

            $subRequest = Request::create(
                $this->sandboxEndpoint,
                $method,
                $parsedPayload,
                [],
                [],
                [
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_CONTENT_TYPE' => 'application/json',
                    'HTTP_USER_AGENT' => 'SNTCSSC-Developer-Console/1.0',
                    'REMOTE_ADDR' => '127.0.0.1',
                ],
                $isGetOrHead ? null : $this->sandboxPayload
            );

            if ($key) {
                $subRequest->attributes->set('api_key', $key);
            }

            if (auth()->check()) {
                $subRequest->setUserResolver(fn () => auth()->user());
            }

            $response = app()->handle($subRequest);

            $this->sandboxDurationMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->sandboxStatusCode = $response->getStatusCode();
            $this->sandboxHeaders = [
                'content_type' => $response->headers->get('Content-Type'),
                'rate_limit_limit' => $response->headers->get('X-RateLimit-Limit') ?? '60',
                'rate_limit_remaining' => $response->headers->get('X-RateLimit-Remaining') ?? '59',
            ];
            $this->sandboxResponse = json_decode($response->getContent(), true) ?: ['raw' => $response->getContent()];
        } catch (Throwable $e) {
            $this->sandboxDurationMs = round((microtime(true) - $startTime) * 1000, 2);
            $this->sandboxStatusCode = 500;
            $this->sandboxResponse = [
                'success' => false,
                'error' => [
                    'code' => 'EXECUTION_EXCEPTION',
                    'message' => $e->getMessage(),
                ],
            ];
        } finally {
            $this->isExecuting = false;
        }
    }

    // Interactive Guide Viewer Modal state
    public bool $showGuideModal = false;

    public string $guideTitle = '';

    public string $guideHtmlContent = '';

    public function openGuide(string $guideType): void
    {
        if ($guideType === 'classes' || $guideType === 'routine' || $guideType === 'class_routine' || $guideType === 'course_plans' || $guideType === 'classrooms' || $guideType === 'partner_institutes' || $guideType === 'faculty' || $guideType === 'faculties') {
            $path = file_exists(base_path('docs/integrations/class-routine-and-faculty-api-guide.md'))
                ? base_path('docs/integrations/class-routine-and-faculty-api-guide.md')
                : base_path('docs/integrations/classes-and-partner-institutes-api-guide.md');
            $this->guideTitle = 'Class Routine, Faculty Master, Course Plans, Classrooms & Partner Coaching Institutes REST API — Enterprise Multi-Platform Integration Guide';
        } elseif ($guideType === 'academics') {
            $path = base_path('docs/integrations/course-and-academics-management-api.md');
            $this->guideTitle = 'Course & Academics Management System REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'hostel') {
            $path = base_path('docs/integrations/hostel-management-api.md');
            $this->guideTitle = 'Hostel Management REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'study_materials' || $guideType === 'materials' || $guideType === 'study_material') {
            $path = file_exists(base_path('docs/integrations/study-material-management-api.md'))
                ? base_path('docs/integrations/study-material-management-api.md')
                : base_path('EXTERNAL_STUDY_MATERIALS_API_INTEGRATION_GUIDE.md');
            $this->guideTitle = 'Dynamic Study Material Management System — Multi-Platform Integration Guide';
        } elseif ($guideType === 'settings' || $guideType === 'system_settings' || $guideType === 'application_settings') {
            $path = base_path('docs/integrations/application-system-settings-api.md');
            $this->guideTitle = 'Application System Settings REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'library') {
            $path = base_path('docs/integrations/library-management-api.md');
            $this->guideTitle = 'Comprehensive Library Management System REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'examination') {
            $path = base_path('docs/integrations/examination-management-api.md');
            $this->guideTitle = 'Examination Management REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'student_tests') {
            $path = base_path('docs/integrations/student-tests-and-performance-api.md');
            $this->guideTitle = 'Student Tests & Performance REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'optional_subjects') {
            $path = base_path('docs/integrations/optional-subjects-and-id-cards-api.md');
            $this->guideTitle = 'Optional Subjects Management & Change Request REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'student_id_cards') {
            $path = base_path('docs/integrations/optional-subjects-and-id-cards-api.md');
            $this->guideTitle = 'Student Identity Cards & Verification REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'students' || $guideType === 'enrollments') {
            $path = base_path('docs/integrations/student-management-and-enrollment-api.md');
            $this->guideTitle = 'Student Management & Enrollment System REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'ccavenue' || $guideType === 'payment_gateway' || $guideType === 'gateway') {
            $path = base_path('docs/integrations/ccavenue-payment-gateway-api.md');
            $this->guideTitle = 'CCAvenue Payment Gateway, Surcharge Reconciliation & Refund API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'payments' || $guideType === 'fees') {
            $path = base_path('docs/integrations/fee-and-payment-collection-api.md');
            $this->guideTitle = 'Fee & Payment Collection System REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'leaves_attendance' || $guideType === 'attendance' || $guideType === 'leaves') {
            $path = base_path('docs/integrations/leave-and-attendance-management-api.md');
            $this->guideTitle = 'Leave & Attendance Management System REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'users' || $guideType === 'rbac' || $guideType === 'access' || $guideType === 'access_control') {
            $path = base_path('docs/integrations/users-and-access-management-api.md');
            $this->guideTitle = 'Users & Access Management (RBAC) System REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'chat' || $guideType === 'live_chat' || $guideType === 'calls' || $guideType === 'meetings') {
            $path = base_path('docs/integrations/live-chat-and-calls-api.md');
            $this->guideTitle = 'Realtime Live Chat, WebRTC Calling & Online Meetings REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'tickets' || $guideType === 'support' || $guideType === 'helpdesk') {
            $path = base_path('docs/integrations/support-tickets-and-helpdesk-api.md');
            $this->guideTitle = 'Support Tickets & Helpdesk System REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'communications' || $guideType === 'marketing' || $guideType === 'campaigns' || $guideType === 'subscribers') {
            $path = base_path('docs/integrations/communications-and-marketing-api.md');
            $this->guideTitle = 'Communications & Marketing Module REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'search' || $guideType === 'global_search' || $guideType === 'fullscreen') {
            $path = base_path('docs/integrations/global-search-and-fullscreen-api.md');
            $this->guideTitle = 'Global Search & Viewport Preferences REST API — Multi-Platform Integration Guide';
        } elseif ($guideType === 'mock_interviews' || $guideType === 'mock_interview' || $guideType === 'mock_interview_management') {
            $path = base_path('docs/integrations/mock-interview-management-api.md');
            $this->guideTitle = 'UPSC Mock Interview Management System REST API — Enterprise Multi-Platform Integration Guide';
        } elseif ($guideType === 'academic_calendar' || $guideType === 'academic_calendars' || $guideType === 'calendar') {
            $path = base_path('docs/integrations/academic-calendar-and-version-control-api.md');
            $this->guideTitle = 'Academic Calendar & Version Control System REST API — Enterprise Multi-Platform Integration Guide';
        } elseif ($guideType === 'documents' || $guideType === 'document' || $guideType === 'document_management' || $guideType === 'vault') {
            $path = base_path('docs/integrations/document-management-and-version-control-api.md');
            $this->guideTitle = 'Soft Copies Document Management & Version Control System REST API — Enterprise Multi-Platform Integration Guide';
        } elseif ($guideType === 'captcha' || $guideType === 'bot_defense' || $guideType === 'bot' || $guideType === 'recaptcha') {
            $path = base_path('docs/integrations/captcha-and-bot-defense-api.md');
            $this->guideTitle = 'Enterprise CAPTCHA & Bot Defense REST API — Multi-Platform Integration Guide';
        } else {
            return;
        }

        if (file_exists($path)) {
            $markdown = file_get_contents($path);
            $this->guideHtmlContent = Str::markdown($markdown);
            $this->showGuideModal = true;
        }
    }

    public function closeGuideModal(): void
    {
        $this->showGuideModal = false;
        $this->guideHtmlContent = '';
    }

    #[Computed]
    public function endpointsList(): array
    {
        $all = [
            // Core & Diagnostics
            ['category' => 'system', 'method' => 'GET', 'path' => '/api/v1/ping', 'scope' => 'ping', 'description' => 'Basic connectivity test, server time, active scopes, and key rate limits.'],
            ['category' => 'system', 'method' => 'GET', 'path' => '/api/v1/app/info', 'scope' => 'read:system', 'description' => 'Institution identity, active academic session, and supported mobile modules.'],
            ['category' => 'system', 'method' => 'POST', 'path' => '/api/v1/echo', 'scope' => 'ping', 'description' => 'Echo payload inspector for debugging external client transmissions.'],

            // Course and Academics Management System (29 Comprehensive Routes)
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/dashboard', 'scope' => 'read:academics', 'description' => 'Executive telemetry: total courses, active/upcoming batches, section load, overall capacity utilization.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/statistics', 'scope' => 'read:academics', 'description' => 'Deep analytics: course-wise capacity vs enrolled, batch occupancy rates, admissions trends, and section loads.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/courses', 'scope' => 'read:academics', 'description' => 'Search and paginate courses with category, status, fee type, sections, and admit-once filters.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academics/courses', 'scope' => 'write:academics', 'description' => 'Create course offering with fee structure, reserved fees, admit-once-only rules, and section flags.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/courses/{identifier}', 'scope' => 'read:academics', 'description' => 'Detailed course dossier with linked batches, sections, fee breakdown, and enrolled counts by ID or code.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/academics/courses/{identifier}', 'scope' => 'write:academics', 'description' => 'Update course attributes, fees, duration, rules, or transition status with audit log.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/academics/courses/{identifier}', 'scope' => 'write:academics', 'description' => 'Soft delete course (safe deletion guard: blocked if active student enrollments exist).'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/batches', 'scope' => 'read:academics', 'description' => 'List academic batches filtered by year, status (upcoming/active/completed), or course_id.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academics/batches', 'scope' => 'write:academics', 'description' => 'Create academic batch intake with timeline dates, capacity limits, and course mappings.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/batches/{identifier}', 'scope' => 'read:academics', 'description' => 'Detailed batch dossier with course offerings, sections, timeline, and seat utilization by ID or code.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/academics/batches/{identifier}', 'scope' => 'write:academics', 'description' => 'Update academic batch attributes, capacity, or synchronized course offerings.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/academics/batches/{identifier}', 'scope' => 'write:academics', 'description' => 'Soft delete batch (safe deletion guard: blocked if active student enrollments exist).'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/batches/{batchId}/courses', 'scope' => 'read:academics', 'description' => 'List course offerings in batch with offering-specific capacity, fee, and enrolled counts.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academics/batches/{batchId}/courses', 'scope' => 'write:academics', 'description' => 'Attach course offering to batch with customized capacity, fee, dates, and remarks.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/academics/batches/{batchId}/courses/{courseId}', 'scope' => 'write:academics', 'description' => 'Update batch course offering capacity, fee, or timeline terms.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/academics/batches/{batchId}/courses/{courseId}', 'scope' => 'write:academics', 'description' => 'Detach course offering from batch with active enrollment guard.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/sections', 'scope' => 'read:academics', 'description' => 'Query classroom sections with batch, course, room number, timetable, capacity, and vacancy.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academics/sections', 'scope' => 'write:academics', 'description' => 'Create classroom section with room number, capacity, and schedule timing.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/sections/{id}', 'scope' => 'read:academics', 'description' => 'Classroom section profile with student occupancy rate and scheduling info.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/academics/sections/{id}', 'scope' => 'write:academics', 'description' => 'Update section room number, capacity, timing, or status.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/academics/sections/{id}', 'scope' => 'write:academics', 'description' => 'Soft delete section (blocked if active students are assigned).'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/academic-years', 'scope' => 'read:academics', 'description' => 'Catalog of distinct academic sessions with batch and student enrollment metrics.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/calendar', 'scope' => 'read:academics', 'description' => 'Institutional academic calendar, working day rules, holidays, and batch milestones.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/courses/export', 'scope' => 'read:academics', 'description' => 'Export courses catalog as structured JSON dataset.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academics/courses/import', 'scope' => 'write:academics', 'description' => 'Bulk import courses via JSON array with automatic upsert.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/batches/export', 'scope' => 'read:academics', 'description' => 'Export batches dataset as structured JSON.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academics/batches/import', 'scope' => 'write:academics', 'description' => 'Bulk import batches via JSON array with automatic upsert.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/catalog', 'scope' => 'Public / Optional', 'description' => 'Public course discovery feed: active courses, fees, and open batches for mobile/web apps.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academics/catalog/{identifier}', 'scope' => 'Public / Optional', 'description' => 'Public single course outline and open intake batches for mobile app prospects.'],

            // Classes, Routine & Lecture Scheduling (28 Routes)
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes', 'scope' => 'read:academics', 'description' => 'List scheduled classes with batch, course, section, mode (offline/online/hybrid), and date filters.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes', 'scope' => 'write:academics', 'description' => 'Schedule a new class lecture with meeting credentials, assigned teacher, and classroom.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/calendar', 'scope' => 'read:academics', 'description' => 'Weekly matrix routine timetable grouped by calendar date and day of week for mobile/web views.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/export', 'scope' => 'read:academics', 'description' => 'Export scheduled class routine to formatted Excel (.xlsx), CSV, or PDF.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/import', 'scope' => 'write:academics', 'description' => 'Bulk import classes and schedules from uploaded Excel (.xlsx) or CSV template.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/settings/reminders', 'scope' => 'read:academics', 'description' => 'Get automated class reminder alert configuration (lead time, enabled channels, template).'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/classes/settings/reminders', 'scope' => 'write:academics', 'description' => 'Update automated class reminder settings (WhatsApp, SMS, Email, Telegram).'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/reports/faculty-workload', 'scope' => 'read:academics', 'description' => 'Faculty workload analytics report: assigned, conducted, hours taught, and completion rate.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/reports/course-coverage', 'scope' => 'read:academics', 'description' => 'Course syllabus coverage report: planned vs conducted vs remaining lectures.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/reports/classroom-utilization', 'scope' => 'read:academics', 'description' => 'Classroom occupancy and equipment utilization analytics dataset.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/faculties', 'scope' => 'read:academics', 'description' => 'List active faculty members with linked partner institute and employment type.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/faculties', 'scope' => 'write:academics', 'description' => 'Quick-register new faculty with department, specialization, and institute reference.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/course-plans', 'scope' => 'read:academics', 'description' => 'List annual, monthly, or weekly course plans and syllabus roadmaps.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/course-plans', 'scope' => 'write:academics', 'description' => 'Publish a comprehensive course plan with planned lectures count and soft copy.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/course-plans/{id}', 'scope' => 'read:academics', 'description' => 'Get course plan dossier with attached syllabus document and schedule links.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/classes/course-plans/{id}', 'scope' => 'write:academics', 'description' => 'Update course plan details, targets, or status (published/under revision).'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/classes/course-plans/{id}', 'scope' => 'write:academics', 'description' => 'Soft delete a course plan roadmap.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/course-plans/{id}/restore', 'scope' => 'write:academics', 'description' => 'Restore a soft-deleted course plan roadmap.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/course-plans/{id}/progress', 'scope' => 'read:academics', 'description' => 'Syllabus progress and lecture completion analytics vs planned quota.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classes/{id}', 'scope' => 'read:academics', 'description' => 'Get full class session details, attendance stats, and meeting/recording URLs.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/classes/{id}', 'scope' => 'write:academics', 'description' => 'Update class schedule details, topic, delivery mode, or assigned faculty.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/classes/{id}', 'scope' => 'write:academics', 'description' => 'Soft delete a scheduled class with audit logging.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/{id}/restore', 'scope' => 'write:academics', 'description' => 'Restore a soft-deleted class schedule.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/{id}/complete', 'scope' => 'write:academics', 'description' => 'Mark class lecture as completed and conclude session.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/{id}/reschedule', 'scope' => 'write:academics', 'description' => 'Reschedule class date and time with mandatory audit justification reason.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/{id}/cancel', 'scope' => 'write:academics', 'description' => 'Cancel class session with reason and auto-update routine analytics.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/{id}/recording', 'scope' => 'write:academics', 'description' => 'Publish cloud recording URL, password, and notes for student on-demand replay.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classes/{id}/send-reminder', 'scope' => 'write:academics', 'description' => 'Trigger on-demand multi-channel reminder alert to enrolled cohort.'],

            // Faculty Master Management (11 Routes)
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculties', 'scope' => 'read:academics', 'description' => 'List faculty master directory with department, partner institute, and employment type filters.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/faculties', 'scope' => 'write:academics', 'description' => 'Register a faculty member with photo, CV, qualification, subjects, and institute.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculties/workload-stats', 'scope' => 'read:academics', 'description' => 'Global faculty teaching workload leaderboard, hours taught, and completion rates.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculties/export', 'scope' => 'read:academics', 'description' => 'Export faculty master directory to Excel, CSV, or PDF.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/faculties/import', 'scope' => 'write:academics', 'description' => 'Bulk import faculty directory from uploaded spreadsheet or JSON array.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculties/{id}', 'scope' => 'read:academics', 'description' => 'Comprehensive faculty dossier with bio, photo, CV, assigned classes, and workload.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/faculties/{id}', 'scope' => 'write:academics', 'description' => 'Update faculty profile, qualifications, department, or replace photo/CV.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/faculties/{id}', 'scope' => 'write:academics', 'description' => 'Soft delete faculty member with audit logging.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/faculties/{id}/restore', 'scope' => 'write:academics', 'description' => 'Restore soft-deleted faculty member.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculties/{id}/schedule', 'scope' => 'read:academics', 'description' => 'Timetable of lectures assigned to this faculty member with date filters.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculties/{id}/workload', 'scope' => 'read:academics', 'description' => 'Specific faculty teaching workload: conducted hours, upcoming classes, completion rate.'],

            // Classrooms Master Management (7 Routes)
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classrooms', 'scope' => 'read:academics', 'description' => 'List smart lecture halls and classrooms with capacity, building, and equipment.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classrooms', 'scope' => 'write:academics', 'description' => 'Register classroom hall with floor, capacity, equipment facilities, and course bindings.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classrooms/{id}', 'scope' => 'read:academics', 'description' => 'Classroom dossier with facilities checklist and recent scheduled classes.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/classrooms/{id}', 'scope' => 'write:academics', 'description' => 'Update classroom capacity, status, or equipment facilities.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/classrooms/{id}', 'scope' => 'write:academics', 'description' => 'Soft delete classroom record.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/classrooms/{id}/restore', 'scope' => 'write:academics', 'description' => 'Restore soft-deleted classroom record.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/classrooms/{id}/schedule', 'scope' => 'read:academics', 'description' => 'Timetable schedule of sessions booked in this room to check room availability.'],

            // Partner Coaching Institutes (7 Routes)
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/partner-institutes', 'scope' => 'read:academics', 'description' => 'List affiliated coaching institutes with MoU dates, official contact, and faculty count.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/partner-institutes', 'scope' => 'write:academics', 'description' => 'Register a partner institute with MoU dates, nodal official contact, and status.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/partner-institutes/{id}', 'scope' => 'read:academics', 'description' => 'Partner institute dossier with affiliated faculties list and validity details.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/partner-institutes/{id}', 'scope' => 'write:academics', 'description' => 'Update partner institute profile, renewed MoU dates, or liaison contacts.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/partner-institutes/{id}', 'scope' => 'write:academics', 'description' => 'Soft delete partner institute record.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/partner-institutes/{id}/restore', 'scope' => 'write:academics', 'description' => 'Restore soft-deleted partner institute record.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/partner-institutes/{id}/faculties', 'scope' => 'read:academics', 'description' => 'Get all faculty members affiliated with this partner coaching institute.'],

            // Academic Calendar & Version Control System (15 Routes)
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academic-calendars', 'scope' => 'read:academics', 'description' => 'List and search academic sessions/calendars with status, current version, activity counts, and year filters.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academic-calendars/current', 'scope' => 'read:academics', 'description' => 'Retrieve the active institutional academic calendar with its latest published version and month-wise activities.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academic-calendars/{id}', 'scope' => 'read:academics', 'description' => 'Detailed academic calendar session dossier with version history, monthly activities summary, and working days.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academic-calendars', 'scope' => 'write:academics', 'description' => 'Create a new institutional academic calendar session with working days, date range, and optional initial PDF document upload.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/academic-calendars/{id}', 'scope' => 'write:academics', 'description' => 'Update academic calendar session metadata, title, working/instructional days, date bounds, or status with audit logging.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/academic-calendars/{id}', 'scope' => 'write:academics', 'description' => 'Soft delete an academic calendar session (safety check: blocked if designated as the active institutional calendar).'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academic-calendars/{id}/set-current', 'scope' => 'write:academics', 'description' => 'Set this academic calendar as the active current session across the institution and all student portals.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academic-calendars/{id}/versions', 'scope' => 'read:academics', 'description' => 'List full version history and revision vault for an academic calendar with changelogs, approver details, and file stats.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academic-calendars/{id}/versions', 'scope' => 'write:academics', 'description' => 'Upload a revised calendar document version (PDF/Word/Excel) preserving past versions with changelog and approval metadata.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academic-calendars/{id}/versions/{versionId}/download', 'scope' => 'read:academics', 'description' => 'Stream and download a specific historical or current revision document file with download counter incrementation.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/academic-calendars/{id}/activities', 'scope' => 'read:academics', 'description' => 'Query month-wise institutional activities, exams, holidays, and milestones with category, month, and audience filters.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/academic-calendars/{id}/activities', 'scope' => 'write:academics', 'description' => 'Create a scheduled activity, holiday, entrance test, or academic event in the institute calendar.'],
            ['category' => 'academics', 'method' => 'PUT', 'path' => '/api/v1/academic-calendars/{id}/activities/{activityId}', 'scope' => 'write:academics', 'description' => 'Update activity scheduling dates, title, venue, target audience, or tentative/confirmed status.'],
            ['category' => 'academics', 'method' => 'DELETE', 'path' => '/api/v1/academic-calendars/{id}/activities/{activityId}', 'scope' => 'write:academics', 'description' => 'Soft delete an activity entry from the calendar with audit log.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/academic-calendar', 'scope' => 'read:academics / Student Auth', 'description' => 'Student self-service: access active academic calendar, downloadable revision documents, and upcoming monthly institute activities.'],

            // Student Self-Service Routine & Vault (5 Routes)
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/classes', 'scope' => 'Student Auth', 'description' => 'Student self-service: personal class schedule for enrolled courses/batches (today, upcoming, history).'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/routine', 'scope' => 'Student Auth', 'description' => 'Student self-service: alias for personal routine timetable.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/classes/{id}', 'scope' => 'Student Auth', 'description' => 'Student self-service: single lecture detail with 1-click meeting join link, classroom, recording.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/course-plans', 'scope' => 'Student Auth', 'description' => 'Student self-service: published course plans & syllabus documents for enrolled courses.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/routine/download', 'scope' => 'Student Auth', 'description' => 'Student self-service: download personalized timetable as PDF or Excel.'],

            // Faculty Self-Service Operations (6 Routes)
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculty/my-schedule', 'scope' => 'Faculty Auth', 'description' => 'Faculty self-service: personal timetable of lectures assigned to the logged-in teacher.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculty/my-profile', 'scope' => 'Faculty Auth', 'description' => 'Faculty self-service: teacher profile dossier and workload analytics.'],
            ['category' => 'academics', 'method' => 'GET', 'path' => '/api/v1/faculty/workload', 'scope' => 'Faculty Auth', 'description' => 'Faculty self-service: personal teaching hours and lecture completion stats.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/faculty/classes/{id}/recording', 'scope' => 'Faculty Auth', 'description' => 'Faculty self-service: publish cloud recording URL and passcode for assigned lecture.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/faculty/classes/{id}/complete', 'scope' => 'Faculty Auth', 'description' => 'Faculty self-service: mark assigned class lecture as completed.'],
            ['category' => 'academics', 'method' => 'POST', 'path' => '/api/v1/faculty/classes/{id}/reschedule', 'scope' => 'Faculty Auth', 'description' => 'Faculty self-service: reschedule assigned class date and slot with reason.'],

            // Student Management & Admissions System (29 Routes)
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/dashboard', 'scope' => 'read:students', 'description' => 'Executive student metrics: total students, active enrollments, category & gender distribution, pending requests.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/statistics', 'scope' => 'read:students', 'description' => 'Admissions telemetry: batch capacity utilization, multi-year intake trends, and fee status summary.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/verify-identity', 'scope' => 'read:students', 'description' => 'Hardware/kiosk scanner sub-second identity verification for campus turnstiles, RFID readers, and gates.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students', 'scope' => 'read:students', 'description' => 'Search and paginate students with multi-attribute filtering (course, batch, category, status).'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students', 'scope' => 'write:students', 'description' => 'Register new student with auto 9-digit Lifetime Unique ID, OTR profile, and optional initial course admission.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/{identifier}', 'scope' => 'read:students', 'description' => 'Complete 360-degree student dossier (profile, addresses, qualifications, employment, documents, enrollments).'],
            ['category' => 'students', 'method' => 'PUT', 'path' => '/api/v1/students/{identifier}', 'scope' => 'write:students', 'description' => 'Update student basic details, contact info, category, and demographic profile attributes.'],
            ['category' => 'students', 'method' => 'DELETE', 'path' => '/api/v1/students/{identifier}', 'scope' => 'write:students', 'description' => 'Safely soft delete student record with audit logging and deletion tracking.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/{identifier}/addresses', 'scope' => 'read:students', 'description' => 'List candidate permanent and correspondence addresses.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/{identifier}/addresses', 'scope' => 'write:students', 'description' => 'Add or update candidate permanent or correspondence address.'],
            ['category' => 'students', 'method' => 'DELETE', 'path' => '/api/v1/students/{identifier}/addresses/{id}', 'scope' => 'write:students', 'description' => 'Delete candidate address record.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/{identifier}/educations', 'scope' => 'read:students', 'description' => 'List candidate academic qualifications history (10th, 12th, Graduation, Post-Graduation).'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/{identifier}/educations', 'scope' => 'write:students', 'description' => 'Add academic qualification record with board, stream, passing year, and marks/CGPA.'],
            ['category' => 'students', 'method' => 'DELETE', 'path' => '/api/v1/students/{identifier}/educations/{id}', 'scope' => 'write:students', 'description' => 'Remove academic qualification record.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/{identifier}/documents', 'scope' => 'read:students', 'description' => 'List candidate verification documents and approval statuses.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/{identifier}/documents', 'scope' => 'write:students', 'description' => 'Upload or attach verification document (caste cert, photo, signature, ID proof).'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/{identifier}/documents/{id}/verify', 'scope' => 'write:students', 'description' => 'Administrative action: verify or reject candidate uploaded document with notes.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/batches', 'scope' => 'read:students', 'description' => 'Query academic batches with offerings, intake year, and active enrollment counts.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/batches', 'scope' => 'write:students', 'description' => 'Create new academic batch intake offering.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/courses', 'scope' => 'read:students', 'description' => 'Query courses with fee schedules, category, and admit-once-only admission restrictions.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/courses', 'scope' => 'write:students', 'description' => 'Create new course offering with admission rules and section configuration.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/sections', 'scope' => 'read:students', 'description' => 'Query sections filtered by batch & course with room number, timetable, capacity, and vacancy.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/sections', 'scope' => 'write:students', 'description' => 'Create new classroom section for batch and course.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/profile-requests', 'scope' => 'read:students', 'description' => 'Administrative inspection queue of candidate-submitted profile correction requests.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/profile-requests', 'scope' => 'write:students', 'description' => 'Submit candidate profile correction request with diff payload and justification.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/profile-requests/{id}/review', 'scope' => 'write:students', 'description' => 'Administrative action: approve request with atomic record merge or reject with notes.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/import', 'scope' => 'write:students', 'description' => 'Bulk import candidates via JSON array or uploaded CSV/XLSX file.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/export', 'scope' => 'read:students', 'description' => 'Export candidate registry dataset as JSON with query filters.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/my-profile', 'scope' => 'Student Auth', 'description' => 'Student self-service: personal profile dossier and profile completeness progress.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/information-sheets', 'scope' => 'read:students', 'description' => 'Query candidate summary cards and metadata for detailed information sheet generation with pagination and filters.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/{identifier}/information-sheet', 'scope' => 'read:students', 'description' => 'Complete detailed student information sheet payload (identity, education, employment, UPSC, documents, enrollments) by ID, UUID, URN, or Student ID.'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/information-sheets/bulk', 'scope' => 'read:students', 'description' => 'Bulk fetch complete detailed information sheet payloads for multiple candidate IDs (up to 100 students).'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/students/{identifier}/information-sheet/download', 'scope' => 'read:students,download:students', 'description' => 'Stream or download official PDF information sheet with optional document annexures (inline or attachment).'],
            ['category' => 'students', 'method' => 'POST', 'path' => '/api/v1/students/information-sheets/download', 'scope' => 'read:students,download:students', 'description' => 'Stream or download compiled multi-student batch PDF information sheets for specified student IDs.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/information-sheet', 'scope' => 'Student Auth', 'description' => 'Student self-service: retrieve candidate own comprehensive information sheet JSON dossier.'],
            ['category' => 'students', 'method' => 'GET', 'path' => '/api/v1/student/information-sheet/download', 'scope' => 'Student Auth', 'description' => 'Student self-service: download candidate own official PDF information sheet.'],

            // Course Enrollments & Admission Lifecycle (12 Routes)
            ['category' => 'enrollments', 'method' => 'GET', 'path' => '/api/v1/enrollments', 'scope' => 'read:students', 'description' => 'Search and paginate course enrollments with course, batch, section, and fee status filters.'],
            ['category' => 'enrollments', 'method' => 'POST', 'path' => '/api/v1/enrollments', 'scope' => 'write:students', 'description' => 'Admit candidate into course with policy check (admit-once, duplicate batch, section capacity) & auto sequential enrollment #.'],
            ['category' => 'enrollments', 'method' => 'GET', 'path' => '/api/v1/enrollments/{identifier}', 'scope' => 'read:students', 'description' => 'Single enrollment dossier with student info, course, batch, section, and fee invoices.'],
            ['category' => 'enrollments', 'method' => 'PUT', 'path' => '/api/v1/enrollments/{identifier}', 'scope' => 'write:students', 'description' => 'Update enrollment roll number, section, fee status, fee amount, status, or remarks.'],
            ['category' => 'enrollments', 'method' => 'DELETE', 'path' => '/api/v1/enrollments/{identifier}', 'scope' => 'write:students', 'description' => 'Soft delete enrollment with audit logging and deletion tracking.'],
            ['category' => 'enrollments', 'method' => 'POST', 'path' => '/api/v1/enrollments/{identifier}/transfer-section', 'scope' => 'write:students', 'description' => 'Transfer candidate to a different section within the same batch/course with capacity validation.'],
            ['category' => 'enrollments', 'method' => 'POST', 'path' => '/api/v1/enrollments/{identifier}/complete', 'scope' => 'write:students', 'description' => 'Mark course enrollment as completed (Graduation / Alumni workflow).'],
            ['category' => 'enrollments', 'method' => 'POST', 'path' => '/api/v1/enrollments/{identifier}/discontinue', 'scope' => 'write:students', 'description' => 'Withdraw candidate from course with mandatory justification.'],
            ['category' => 'enrollments', 'method' => 'POST', 'path' => '/api/v1/enrollments/{identifier}/reinstate', 'scope' => 'write:students', 'description' => 'Reinstate previously suspended/discontinued enrollment with audit trail.'],
            ['category' => 'enrollments', 'method' => 'POST', 'path' => '/api/v1/enrollments/import', 'scope' => 'write:students', 'description' => 'Bulk import course admissions via JSON array or spreadsheet.'],
            ['category' => 'enrollments', 'method' => 'GET', 'path' => '/api/v1/enrollments/export', 'scope' => 'read:students', 'description' => 'Export enrollments dataset as JSON with query filters.'],
            ['category' => 'enrollments', 'method' => 'GET', 'path' => '/api/v1/student/my-enrollments', 'scope' => 'Student Auth', 'description' => 'Student self-service: list currently enrolled and completed courses with timetable and fees.'],

            // Dynamic Study Materials (Comprehensive Multi-Platform & Admin API - 27 Routes)
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials', 'scope' => 'read:materials', 'description' => 'Browse academic study materials, lecture handouts, and video recordings with pagination.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/study-materials', 'scope' => 'write:materials', 'description' => 'Create study material with course, batch associations, access levels, and file uploads.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/{id}', 'scope' => 'read:materials', 'description' => 'Retrieve detailed study material dossier with file downloads, access rules, and creator profile.'],
            ['category' => 'materials', 'method' => 'PUT', 'path' => '/api/v1/study-materials/{id}', 'scope' => 'write:materials', 'description' => 'Update study material metadata, syllabus topic, access levels, pins, and replace files.'],
            ['category' => 'materials', 'method' => 'DELETE', 'path' => '/api/v1/study-materials/{id}', 'scope' => 'write:materials', 'description' => 'Safely soft delete study material and linked batch enrollments.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/study-materials/{id}/files', 'scope' => 'write:materials', 'description' => 'Upload and attach multiple binary files (PDF, MP4, DOCX) to study material.'],
            ['category' => 'materials', 'method' => 'DELETE', 'path' => '/api/v1/study-materials/{id}/files/{fileId}', 'scope' => 'write:materials', 'description' => 'Remove a specific file attachment from study material.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/types', 'scope' => 'read:materials', 'description' => 'Catalog of dynamic material types (Current Affairs, PIB Sandhan, Handouts, E-Books) with counts.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/study-materials/types', 'scope' => 'write:materials', 'description' => 'Create custom dynamic study material type category.'],
            ['category' => 'materials', 'method' => 'PUT', 'path' => '/api/v1/study-materials/types/{id}', 'scope' => 'write:materials', 'description' => 'Update dynamic material type name, badge color, icon, or public visibility.'],
            ['category' => 'materials', 'method' => 'DELETE', 'path' => '/api/v1/study-materials/types/{id}', 'scope' => 'write:materials', 'description' => 'Delete custom material type category.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/study-materials/{id}/track-view', 'scope' => 'read:materials', 'description' => 'Record mobile, desktop, or web client view telemetry and access logs.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/{id}/download-token', 'scope' => 'download:materials', 'description' => 'Generate time-limited signed binary download token for client app download managers.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/download/{token}', 'scope' => 'Public Stream', 'description' => 'Stream binary file attachment via signed token without revealing server storage path.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/my-materials', 'scope' => 'read:materials', 'description' => 'Personalized student feed: fetch materials for currently enrolled courses and batches.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/courses/{course}', 'scope' => 'read:materials', 'description' => 'Fetch study materials grouped under a specific course offering.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/batches/{batch}', 'scope' => 'read:materials', 'description' => 'Fetch study materials assigned to a specific batch intake.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/study-materials/{id}/share-link', 'scope' => 'write:materials', 'description' => 'Generate or update public tokenized share link with password and expiration.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/statistics', 'scope' => 'read:materials', 'description' => 'Real-time repository statistics, storage consumption, and download KPI metrics.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/study-materials/{id}/download-zip', 'scope' => 'download:materials', 'description' => 'Download all file attachments of a study material bundled into a single ZIP archive for offline client storage.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/external/study-materials/types', 'scope' => 'read:materials', 'description' => 'Public material types (Current Affairs, PIB Sandhan) with counts.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/external/study-materials', 'scope' => 'read:materials', 'description' => 'Public study materials feed for external websites and landing pages.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/external/study-materials/{identifier}', 'scope' => 'read:materials', 'description' => 'Public single study material outline with lead capture rules.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/external/study-materials/{id}/download-request', 'scope' => 'download:materials', 'description' => 'Initiate lead capture download request with smart OTP verification.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/external/study-materials/verify-otp-and-download', 'scope' => 'download:materials', 'description' => 'Verify OTP and retrieve signed download link for public leads.'],
            ['category' => 'materials', 'method' => 'POST', 'path' => '/api/v1/external/study-materials/resend-otp', 'scope' => 'download:materials', 'description' => 'Resend download verification OTP code to applicant email/mobile.'],
            ['category' => 'materials', 'method' => 'GET', 'path' => '/api/v1/external/study-materials/download/{token}', 'scope' => 'Public Stream', 'description' => 'Stream verified external binary file attachment via secure OTP token.'],

            // Fee & Payment Collection System (43 Comprehensive Routes)
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/dashboard', 'scope' => 'read:payments', 'description' => 'Executive telemetry: total collected, pending balance, overdue counts, monthly revenue trends.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/statistics', 'scope' => 'read:payments', 'description' => 'Granular analytics: collections by fee type, course, batch, caste concession, and ledgers.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/fee-types', 'scope' => 'read:payments', 'description' => 'List fee types with category, frequency, default amount, and refundable flags.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/fee-types', 'scope' => 'write:payments', 'description' => 'Create a new institutional fee type with category rules.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/fee-types/{id}', 'scope' => 'read:payments', 'description' => 'Single fee type dossier with attached schedules and invoices count.'],
            ['category' => 'payments', 'method' => 'PUT', 'path' => '/api/v1/payments/fee-types/{id}', 'scope' => 'write:payments', 'description' => 'Update fee type attributes, amount, frequency, or active status.'],
            ['category' => 'payments', 'method' => 'DELETE', 'path' => '/api/v1/payments/fee-types/{id}', 'scope' => 'write:payments', 'description' => 'Soft delete fee type (safely blocked if active schedules or invoices exist).'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/schedules', 'scope' => 'read:payments', 'description' => 'Search and paginate cohort fee schedules by batch, course, or period.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/schedules', 'scope' => 'write:payments', 'description' => 'Initiate batch fee schedule; generates student invoices with category concession & ledger debits.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/schedules/{id}', 'scope' => 'read:payments', 'description' => 'Schedule dossier with targeted students count, invoice breakdown, and total collected.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/schedules/{id}/reminders', 'scope' => 'write:payments', 'description' => 'Bulk dispatch reminders (database, SMS, email) for pending schedule invoices.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/schedules/{id}/revert', 'scope' => 'write:payments', 'description' => 'Revert uncollected invoices for schedule and reverse student ledger liabilities.'],
            ['category' => 'payments', 'method' => 'DELETE', 'path' => '/api/v1/payments/schedules/{id}', 'scope' => 'write:payments', 'description' => 'Delete uncollected fee schedule and associated invoices.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/invoices', 'scope' => 'read:payments', 'description' => 'Search, filter, and paginate student fee invoices with multi-attribute criteria.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/invoices', 'scope' => 'write:payments', 'description' => 'Issue an individual ad-hoc invoice for a student with automatic ledger debit.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/invoices/{identifier}', 'scope' => 'read:payments', 'description' => 'Single invoice profile with student info, course, breakdown, and payments list.'],
            ['category' => 'payments', 'method' => 'PUT', 'path' => '/api/v1/payments/invoices/{identifier}', 'scope' => 'write:payments', 'description' => 'Update invoice due date, discount, late fee, or remarks with ledger adjustment.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/invoices/{identifier}/cancel', 'scope' => 'write:payments', 'description' => 'Cancel unpaid/partial invoice and credit/release student ledger liability.'],
            ['category' => 'payments', 'method' => 'DELETE', 'path' => '/api/v1/payments/invoices/{identifier}', 'scope' => 'write:payments', 'description' => 'Soft delete uncollected invoice (blocked if payments exist).'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/invoices/{identifier}/remind', 'scope' => 'write:payments', 'description' => 'Send reminder notification for a specific student invoice.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/transactions', 'scope' => 'read:payments', 'description' => 'Search, filter, and paginate payment transactions across methods and statuses.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/transactions/collect', 'scope' => 'write:payments', 'description' => 'Direct manual/counter fee collection (Cash, POS Card, Cheque, DD, UPI, NEFT) with instant receipt.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/transactions/offline', 'scope' => 'write:payments', 'description' => 'Submit offline payment slip with bank UTR reference for administrative review.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/transactions/{id}/verify', 'scope' => 'write:payments', 'description' => 'Review and approve or decline offline student payment submission.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/transactions/{identifier}', 'scope' => 'read:payments', 'description' => 'Single transaction profile by ID, transaction number, or receipt number.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/transactions/{identifier}/receipt', 'scope' => 'read:payments,download:receipts', 'description' => 'Stream or download official PDF payment receipt.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/receipts/{receiptNo}/download', 'scope' => 'Optional API Key', 'description' => 'Direct streaming download of printable PDF receipt.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/checkout/order', 'scope' => 'read:payments,write:payments', 'description' => 'Initiate online gateway payment order (Razorpay / PhonePe / CCAvenue).'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/checkout/verify', 'scope' => 'write:payments', 'description' => 'Verify and settle online gateway payment callback / webhook.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/my-invoices', 'scope' => 'read:payments / Student Auth', 'description' => 'Authenticated student\'s fee invoices feed.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/my-transactions', 'scope' => 'read:payments / Student Auth', 'description' => 'Authenticated student\'s payment history and receipts.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/my-ledger', 'scope' => 'read:payments / Student Auth', 'description' => 'Authenticated student\'s personal running ledger statement.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/my-offline-payment', 'scope' => 'write:payments / Student Auth', 'description' => 'Student self-submits offline bank reference and transaction slip.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/ledgers', 'scope' => 'read:payments', 'description' => 'System-wide student ledgers log (debits, credits, adjustments).'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/students/{studentId}/ledger', 'scope' => 'read:payments', 'description' => 'Comprehensive financial ledger statement for a specific student.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/students/{studentId}/clearance', 'scope' => 'read:payments', 'description' => 'Student financial clearance check across tuition, library, hostel, exam dues.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/library-deposits', 'scope' => 'read:payments', 'description' => 'Query library security deposits by status (active, refund_requested, approved, refunded).'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/library-deposits', 'scope' => 'write:payments', 'description' => 'Record direct library security deposit on admission.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/library-deposits/{id}/request-refund', 'scope' => 'write:payments', 'description' => 'Request deposit refund with student bank account details.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/library-deposits/{id}/approve-refund', 'scope' => 'write:payments', 'description' => 'Staff approval of library deposit refund (requires librarian clearance).'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/library-deposits/{id}/reject-refund', 'scope' => 'write:payments', 'description' => 'Staff rejection of refund with reason notes.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/library-deposits/{id}/disburse', 'scope' => 'write:payments', 'description' => 'Record bank UTR disbursement reference and settle deposit.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/export', 'scope' => 'read:payments', 'description' => 'Export transactions dataset as structured JSON.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/reconciliation/import', 'scope' => 'write:payments', 'description' => 'Bulk reconcile transactions against bank settlement UTR feed.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/ccavenue/initiate', 'scope' => 'read:payments,write:payments', 'description' => 'Omnichannel CCAvenue checkout initiation for Mobile (WebView/Flutter), Web SPA, or Desktop POS clients.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/ccavenue/callback', 'scope' => 'Public / Gateway Webhook', 'description' => 'CCAvenue encrypted AES-128-CBC callback handler with automatic surcharge bifurcation and amount mismatch review.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/ccavenue/sync-status', 'scope' => 'read:payments,write:payments', 'description' => 'Real-time order status query against CCAvenue Order Status Tracker API for dropped or pending transactions.'],
            ['category' => 'payments', 'method' => 'GET', 'path' => '/api/v1/payments/gateways/ccavenue/config', 'scope' => 'read:payments', 'description' => 'Public gateway configuration, test/live mode, 6-month penalty warning, and fallback billing address defaults.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/gateways/ccavenue/test-encryption', 'scope' => 'write:payments', 'description' => 'Interactive diagnostic AES-128-CBC encryption and decryption test using configured merchant keys.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/transactions/{identifier}/refund', 'scope' => 'write:payments (payments.refund)', 'description' => 'Programmatic full/partial refund with student ledger debit and invoice balance reopening.'],
            ['category' => 'payments', 'method' => 'POST', 'path' => '/api/v1/payments/transactions/{identifier}/reconcile', 'scope' => 'write:payments (payments.reconcile)', 'description' => 'Administrative manual reconciliation and audit status override with tracking ID and bank reference.'],

            // Examination Management Module (21 Comprehensive Routes)
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/dashboard', 'scope' => 'read:examinations', 'description' => 'Executive exam dashboard metrics: upcoming test dates, candidate counts, and venue utilization.'],
            ['category' => 'examinations', 'method' => 'POST', 'path' => '/api/v1/examinations/verify-admit-card', 'scope' => 'read:examinations', 'description' => 'Fast barcode / QR token / roll number verification for invigilators and exam gatekeepers.'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations', 'scope' => 'read:examinations', 'description' => 'List and filter examinations by status (draft/published), academic year, or date range.'],
            ['category' => 'examinations', 'method' => 'POST', 'path' => '/api/v1/examinations', 'scope' => 'write:examinations', 'description' => 'Schedule a new examination with exam code, dates, duration, fees, and instructions.'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/{id}', 'scope' => 'read:examinations', 'description' => 'Detailed examination dossier with mapped centres, candidate statistics, and admit card status.'],
            ['category' => 'examinations', 'method' => 'PUT', 'path' => '/api/v1/examinations/{id}', 'scope' => 'write:examinations', 'description' => 'Update examination schedule, duration, instructions, fees, or transition status.'],
            ['category' => 'examinations', 'method' => 'DELETE', 'path' => '/api/v1/examinations/{id}', 'scope' => 'write:examinations', 'description' => 'Archive or delete an examination schedule.'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/centers', 'scope' => 'read:examinations', 'description' => 'List examination centers across districts with contact persons, rooms, and capacities.'],
            ['category' => 'examinations', 'method' => 'POST', 'path' => '/api/v1/examinations/centers', 'scope' => 'write:examinations', 'description' => 'Register a new examination venue or regional institutional center.'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/centers/{id}', 'scope' => 'read:examinations', 'description' => 'Detailed center profile with list of examination halls, floors, and capacities.'],
            ['category' => 'examinations', 'method' => 'PUT', 'path' => '/api/v1/examinations/centers/{id}', 'scope' => 'write:examinations', 'description' => 'Update examination center details, address, capacity, or active status.'],
            ['category' => 'examinations', 'method' => 'DELETE', 'path' => '/api/v1/examinations/centers/{id}', 'scope' => 'write:examinations', 'description' => 'Remove or deactivate an examination center.'],
            ['category' => 'examinations', 'method' => 'POST', 'path' => '/api/v1/examinations/centers/{id}/rooms', 'scope' => 'write:examinations', 'description' => 'Add an examination room or hall to an exam center with seating capacity.'],
            ['category' => 'examinations', 'method' => 'DELETE', 'path' => '/api/v1/examinations/centers/{centerId}/rooms/{roomId}', 'scope' => 'write:examinations', 'description' => 'Delete an examination hall or room from a center (blocked if candidate seats are assigned).'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/{id}/seating-plan', 'scope' => 'read:examinations', 'description' => 'Interactive visual seating plan matrix across centers and halls for an exam.'],
            ['category' => 'examinations', 'method' => 'POST', 'path' => '/api/v1/examinations/{id}/allocate-seats', 'scope' => 'write:examinations', 'description' => 'Bulk allocate seats and generate roll numbers for registered candidates.'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/{id}/candidates', 'scope' => 'read:examinations', 'description' => 'Query candidate roll numbers, assigned center, seat numbers, and attendance status.'],
            ['category' => 'examinations', 'method' => 'POST', 'path' => '/api/v1/examinations/{id}/attendance', 'scope' => 'write:examinations', 'description' => 'Invigilator mobile/tablet attendance verification: mark candidate present, absent, or disqualified.'],
            ['category' => 'examinations', 'method' => 'POST', 'path' => '/api/v1/examinations/{id}/results', 'scope' => 'write:examinations', 'description' => 'Upload examination scores, merit ranks, and qualifying pass/fail statuses.'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/my-exams', 'scope' => 'read:examinations', 'description' => 'Student self-service: list registered exams, roll numbers, test dates, and reporting times.'],
            ['category' => 'examinations', 'method' => 'GET', 'path' => '/api/v1/examinations/{id}/admit-card', 'scope' => 'download:admit_cards', 'description' => 'Generate and stream official student admit card dataset with encrypted QR token.'],

            // Hostel Management Module (38 Comprehensive Routes)
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/dashboard', 'scope' => 'read:hostel', 'description' => 'Global hostel statistics, bed occupancy, total capacities, and policies.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/visual-board', 'scope' => 'read:hostel', 'description' => 'Interactive visual room board (Buildings -> Floors -> Rooms -> Beds -> Residents).'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/buildings', 'scope' => 'read:hostel', 'description' => 'List all hostel buildings with floor counts, capacities, and gender segregation.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/buildings', 'scope' => 'write:hostel', 'description' => 'Create a new hostel building with gender and address specifications.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/buildings/{id}', 'scope' => 'read:hostel', 'description' => 'Retrieve single building profile with all associated floors and active rooms.'],
            ['category' => 'hostel', 'method' => 'PUT', 'path' => '/api/v1/hostel/buildings/{id}', 'scope' => 'write:hostel', 'description' => 'Update hostel building details, description, or active status.'],
            ['category' => 'hostel', 'method' => 'DELETE', 'path' => '/api/v1/hostel/buildings/{id}', 'scope' => 'write:hostel', 'description' => 'Safely delete a building (blocked if active resident allocations exist).'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/rooms/available', 'scope' => 'read:hostel', 'description' => 'Lightweight vacancy lookup returning rooms with available beds count for mobile/web selectors.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/rooms', 'scope' => 'read:hostel', 'description' => 'List and filter rooms by building, floor, type, status, and fee.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/rooms', 'scope' => 'write:hostel', 'description' => 'Create a room with capacity, amenities, and auto-generate numbered beds.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/rooms/{id}', 'scope' => 'read:hostel', 'description' => 'Detailed room profile with child bed statuses and current residents.'],
            ['category' => 'hostel', 'method' => 'PUT', 'path' => '/api/v1/hostel/rooms/{id}', 'scope' => 'write:hostel', 'description' => 'Update room attributes, monthly fee, status, or amenity checklist.'],
            ['category' => 'hostel', 'method' => 'DELETE', 'path' => '/api/v1/hostel/rooms/{id}', 'scope' => 'write:hostel', 'description' => 'Delete room and child beds (prevented if occupied by active residents).'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/beds/{id}/status', 'scope' => 'write:hostel', 'description' => 'Update bed operational status (available, maintenance, reserved) with room synchronization.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/allocations', 'scope' => 'read:hostel', 'description' => 'Query resident allocations with search, building, and form compliance filters.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/allocations', 'scope' => 'write:hostel', 'description' => 'Allocate a student to a vacant bed with start/end dates and remarks.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/allocations/{id}', 'scope' => 'read:hostel', 'description' => 'Complete allocation dossier with room, bed, student biodata, and compliance log.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/allocations/{id}/transfer', 'scope' => 'write:hostel', 'description' => 'Transfer an active resident to a different room/bed with audit logging and history tracking.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/allocations/{id}/extend', 'scope' => 'write:hostel', 'description' => 'Extend stay duration for an active resident allocation with reason.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/allocations/{id}/vacate', 'scope' => 'write:hostel', 'description' => 'Vacate a resident, release bed back to available, and record checkout remarks.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/allocations/{id}/verify-form', 'scope' => 'write:hostel', 'description' => 'Verify physical declaration form submission signed by student/parent.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/verify-resident', 'scope' => 'read:hostel', 'description' => 'Security gate and reception kiosk resident verification by URN, Student ID, Email, or QR token.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/caretakers', 'scope' => 'read:hostel', 'description' => 'List caretakers and wardens with building assignments, emergency shifts, and phone numbers.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/caretakers/{id}', 'scope' => 'read:hostel', 'description' => 'Retrieve detailed single caretaker profile.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/caretakers', 'scope' => 'write:hostel', 'description' => 'Register a new hostel caretaker or shift supervisor.'],
            ['category' => 'hostel', 'method' => 'PUT', 'path' => '/api/v1/hostel/caretakers/{id}', 'scope' => 'write:hostel', 'description' => 'Update caretaker shift, contact phone, or primary status.'],
            ['category' => 'hostel', 'method' => 'DELETE', 'path' => '/api/v1/hostel/caretakers/{id}', 'scope' => 'write:hostel', 'description' => 'Soft delete and archive a caretaker record.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/extension-requests', 'scope' => 'read:hostel', 'description' => 'List student stay extension requests with status filters.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/extension-requests/{id}/review', 'scope' => 'write:hostel', 'description' => 'Warden review: single-click approval or rejection of student stay extension.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/catalog', 'scope' => 'Public / Resident', 'description' => 'Public/resident catalog of hostel buildings, room categories, amenities, rules & schedules.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/my-accommodation', 'scope' => 'read:hostel', 'description' => 'Student self-service: view assigned room, bed, stay validity, and roommates.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/my-accommodation/roommates', 'scope' => 'Resident Auth', 'description' => 'Resident self-service: list current roommates residing in the same allocated room.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/my-accommodation/extension-request', 'scope' => 'Resident Auth', 'description' => 'Student self-service: submit a formal request for stay extension.'],
            ['category' => 'hostel', 'method' => 'POST', 'path' => '/api/v1/hostel/my-accommodation/upload-form', 'scope' => 'Resident Auth', 'description' => 'Student self-service: upload counter-signed physical declaration form scan.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/my-accommodation/extension-requests', 'scope' => 'Resident Auth', 'description' => 'Student self-service: view status and history of stay extension requests.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/policies', 'scope' => 'read:hostel', 'description' => 'Fetch institutional hostel rules, max stay durations, and extension limits.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/form/download', 'scope' => 'download:hostel_forms', 'description' => 'Generate and stream official pre-filled PDF allocation form with student details.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/reports/occupancy', 'scope' => 'read:hostel', 'description' => 'Occupancy analytics, capacity utilization, and breakdown by building/floor.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/reports/compliance', 'scope' => 'read:hostel', 'description' => 'Physical form compliance statistics and audit list of missing documents.'],
            ['category' => 'hostel', 'method' => 'GET', 'path' => '/api/v1/hostel/reports/expiring', 'scope' => 'read:hostel', 'description' => 'Predictive stay-expiry monitoring for residents finishing in next 7/15/30 days.'],

            // Student Tests & Examinations Performance Management (13 Comprehensive Routes)
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/dashboard', 'scope' => 'read:tests', 'description' => 'Executive test dashboard KPIs: mock tests held, candidate turnout rate, average score, and top rankers.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests', 'scope' => 'read:tests', 'description' => 'List and filter UPSC mock examinations by batch, course, section, stage (prelims/mains), and type.'],
            ['category' => 'student_tests', 'method' => 'POST', 'path' => '/api/v1/student-tests', 'scope' => 'write:tests', 'description' => 'Schedule a new examination with multi-subject composition, max marks, schedule, and notifications.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/rankers', 'scope' => 'read:tests', 'description' => 'Global aggregate Leaderboard across all completed examinations with configurable rank limit.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/my-tests', 'scope' => 'read:tests', 'description' => 'Student personal feed: attended examinations, score breakdowns, solution downloads, and trajectory.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/participation-stats', 'scope' => 'read:tests', 'description' => 'Attendance analytics: 100% test attendees, zero-attendance candidates, and max/least active students.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/results/{resultId}/marksheet', 'scope' => 'read:tests', 'description' => 'Retrieve detailed candidate marksheet, evaluator feedback, and official verified PDF download link.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/{id}', 'scope' => 'read:tests', 'description' => 'Detailed mock test dossier: subject-wise questions, total marks, instructions, and performance stats.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/{id}/results', 'scope' => 'read:tests', 'description' => 'Query candidate results roster with roll numbers, attendance, MCQ right/wrong/blank, and merit ranks.'],
            ['category' => 'student_tests', 'method' => 'POST', 'path' => '/api/v1/student-tests/{id}/results', 'scope' => 'write:tests', 'description' => 'Batch score recording with automatic recalculation of merit rankings and percentiles.'],
            ['category' => 'student_tests', 'method' => 'POST', 'path' => '/api/v1/student-tests/{id}/import-scores', 'scope' => 'write:tests', 'description' => 'Multipart file upload (.csv, .xlsx) to bulk import scores for OMR systems or external grading tools.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/{id}/leaderboard', 'scope' => 'read:tests', 'description' => 'Test-specific Leaderboard & Top Rankers podium with candidate photos, marks, and percentiles.'],
            ['category' => 'student_tests', 'method' => 'GET', 'path' => '/api/v1/student-tests/{id}/section-comparison', 'scope' => 'read:tests', 'description' => 'Section-wise comparative performance breakdown (students count, attendance, average score, pass rate).'],

            // Optional Subjects Management (9 Routes)
            ['category' => 'optional_subjects', 'method' => 'GET', 'path' => '/api/v1/catalog/optional-subjects', 'scope' => 'Public / Student', 'description' => 'Browse official catalog of 48 UPSC optional subjects with search and category filters.'],
            ['category' => 'optional_subjects', 'method' => 'GET', 'path' => '/api/v1/optional-subjects', 'scope' => 'read:optional_subjects', 'description' => 'Administrative catalog listing with pagination, status filters, and active indicators.'],
            ['category' => 'optional_subjects', 'method' => 'GET', 'path' => '/api/v1/optional-subjects/categories', 'scope' => 'read:optional_subjects', 'description' => 'Distinct UPSC optional subject categories (Humanities, Sciences, Literatures) with counts.'],
            ['category' => 'optional_subjects', 'method' => 'GET', 'path' => '/api/v1/optional-subjects/allocations', 'scope' => 'read:optional_subjects', 'description' => 'Query student optional subject allocations filtered by batch, course, section, or unassigned.'],
            ['category' => 'optional_subjects', 'method' => 'POST', 'path' => '/api/v1/optional-subjects/quick-update', 'scope' => 'write:optional_subjects', 'description' => 'In-place administrative quick update of student optional subject with reason tracking.'],
            ['category' => 'optional_subjects', 'method' => 'GET', 'path' => '/api/v1/optional-subjects/requests', 'scope' => 'read:optional_subjects', 'description' => 'Query student change request approval queue with pending, approved, and rejected filters.'],
            ['category' => 'optional_subjects', 'method' => 'POST', 'path' => '/api/v1/optional-subjects/requests/{id}/review', 'scope' => 'write:optional_subjects', 'description' => 'Approve or reject student optional subject change request and sync student records.'],
            ['category' => 'optional_subjects', 'method' => 'GET', 'path' => '/api/v1/student/optional-subject', 'scope' => 'Student Auth', 'description' => 'Student self-service: retrieve current optional subject assignment and change request history.'],
            ['category' => 'optional_subjects', 'method' => 'POST', 'path' => '/api/v1/student/optional-subject/request', 'scope' => 'Student Auth', 'description' => 'Student self-service: submit a formal change request with academic justifications.'],

            // Student Identity Cards (6 Routes)
            ['category' => 'student_id_cards', 'method' => 'GET', 'path' => '/api/v1/student-id-cards/settings', 'scope' => 'read:id_cards', 'description' => 'Retrieve institution ID card layout parameters, validity session, and student download availability.'],
            ['category' => 'student_id_cards', 'method' => 'GET', 'path' => '/api/v1/student-id-cards/{identifier}/payload', 'scope' => 'read:id_cards', 'description' => 'Full digital identity card payload (student profile, academics, barcode URI, QR URI, terms) for kiosk/app verification.'],
            ['category' => 'student_id_cards', 'method' => 'GET', 'path' => '/api/v1/student-id-cards/{identifier}/download', 'scope' => 'download:id_cards', 'description' => 'Stream official printable double-sided PDF student identity card (CR80, 300 DPI, cut marks).'],
            ['category' => 'student_id_cards', 'method' => 'GET', 'path' => '/api/v1/student/id-card/settings', 'scope' => 'Public / Student', 'description' => 'Student self-service: check whether ID card download is currently active in admin settings.'],
            ['category' => 'student_id_cards', 'method' => 'GET', 'path' => '/api/v1/student/id-card/payload', 'scope' => 'Student Auth', 'description' => 'Student self-service: retrieve personal digital identity card credentials and QR code.'],
            ['category' => 'student_id_cards', 'method' => 'GET', 'path' => '/api/v1/student/id-card/download', 'scope' => 'Student Auth', 'description' => 'Student self-service: download printable PDF card (honors admin download permission toggle).'],

            // Library & Circulation Management System (34 Comprehensive Routes)
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/dashboard', 'scope' => 'read:library', 'description' => 'Executive library KPIs, active loans, overdue counts, fine totals, and today circulation counters.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/statistics', 'scope' => 'read:library', 'description' => 'Reading telemetry, category-wise book distribution, and top 10 most borrowed books.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/books', 'scope' => 'read:library', 'description' => 'Search and paginate books catalog with category, publisher, author, material type, and availability filters.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/books', 'scope' => 'write:library', 'description' => 'Create new book title in catalog with multi-author associations, pricing, and DDC classification.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/books/{identifier}', 'scope' => 'read:library', 'description' => 'Detailed book dossier by ID, slug, or ISBN with copies summary and shelf locations.'],
            ['category' => 'library', 'method' => 'PUT', 'path' => '/api/v1/library/books/{identifier}', 'scope' => 'write:library', 'description' => 'Update book catalog title, pricing, edition, description, and author assignments.'],
            ['category' => 'library', 'method' => 'DELETE', 'path' => '/api/v1/library/books/{identifier}', 'scope' => 'write:library', 'description' => 'Soft delete book title from catalog (blocked if physical copies are currently checked out).'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/books/{identifier}/copies', 'scope' => 'read:library', 'description' => 'List all physical accession copies of a book title with barcodes, shelf locations, and conditions.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/books/{identifier}/copies', 'scope' => 'write:library', 'description' => 'Accession new physical copy with barcode, condition, acquisition date, and purchase cost.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/copies/{identifier}', 'scope' => 'read:library', 'description' => 'Rapid physical copy lookup by barcode, accession number, RFID EPC tag, or ID (under 20ms for scanners).'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/copies/{identifier}/status', 'scope' => 'write:library', 'description' => 'Quick update physical copy condition or operational status (available, maintenance, damaged, weeded).'],
            ['category' => 'library', 'method' => 'DELETE', 'path' => '/api/v1/library/copies/{identifier}', 'scope' => 'write:library', 'description' => 'Soft delete physical copy (blocked if currently checked out).'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/issues', 'scope' => 'read:library', 'description' => 'Query circulation loans with search, status (issued, returned, overdue), and overdue filters.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/issues', 'scope' => 'write:library', 'description' => 'Checkout book copy to member with security deposit limits validation and bypass authorization.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/issues/{id}/return', 'scope' => 'write:library', 'description' => 'Checkin returned book, assess daily overdue fine, record condition change, and fulfill holds queue.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/issues/{id}/renew', 'scope' => 'write:library', 'description' => 'Librarian direct renewal of active book loan (extends due date, enforces max renewal limits).'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/issues/{id}/request-renewal', 'scope' => 'Member Auth', 'description' => 'Student or member self-service: submit a formal loan renewal request.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/issues/{id}/review-renewal', 'scope' => 'write:library', 'description' => 'Librarian review: approve or reject student loan renewal request with reason tracking.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/in-house/issue', 'scope' => 'write:library', 'description' => 'Issue copies for in-house reading room study session (bypasses deposit check, due today).'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/in-house/return', 'scope' => 'write:library', 'description' => 'Return in-house reading copy or complete day-use session token.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/reservations', 'scope' => 'read:library', 'description' => 'Query book reservations and waiting list queue with status filters.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/reservations', 'scope' => 'write:library', 'description' => 'Place hold reservation for member on an unavailable book title.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/reservations/{id}/cancel', 'scope' => 'write:library', 'description' => 'Cancel hold reservation and release any held physical copy back to circulation.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/fines', 'scope' => 'read:library', 'description' => 'List overdue fines with pending, partial, paid, and waived status filters.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/fines/{id}/pay', 'scope' => 'write:library', 'description' => 'Record fine payment (cash, UPI, card, bank transfer) with receipt notes.'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/fines/{id}/waive', 'scope' => 'write:library', 'description' => 'Authorize audited fine waiver with mandatory administrative justification.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/members', 'scope' => 'read:library', 'description' => 'Query registered library members with active loan counts, deposit overrides, and status.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/members/{identifier}', 'scope' => 'read:library', 'description' => 'Detailed member profile with active loans, deposit ledger, fines, and clearance status.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/clearance/check/{identifier}', 'scope' => 'read:library', 'description' => 'Check member clearance eligibility (verifies active_loans == 0 and unpaid_fines == 0).'],
            ['category' => 'library', 'method' => 'POST', 'path' => '/api/v1/library/clearance/issue', 'scope' => 'write:library', 'description' => 'Issue official Library Clearance (No Dues) Certificate for security deposit refund.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/members/{id}/card/download', 'scope' => 'download:library_cards', 'description' => 'Stream official printable double-sided PDF library card with Code 128 barcode & QR code.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/catalog', 'scope' => 'Public / Student', 'description' => 'Public & student searchable book catalog with availability counters.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/my-account', 'scope' => 'Student Auth', 'description' => 'Student self-service: personal library account, security deposit amount, active loans.'],
            ['category' => 'library', 'method' => 'GET', 'path' => '/api/v1/library/my-loans', 'scope' => 'Student Auth', 'description' => 'Student self-service: list currently borrowed books with due dates and days remaining.'],

            // Biometric Attendance
            ['category' => 'biometric', 'method' => 'POST', 'path' => '/api/v1/biometric/punch', 'scope' => 'write:biometric', 'description' => 'Push raw biometric machine punches for student and faculty attendance.'],
            ['category' => 'biometric', 'method' => 'POST', 'path' => '/api/v1/biometric/heartbeat', 'scope' => 'write:biometric', 'description' => 'Device status heartbeat for biometric IoT time clocks.'],

            // Comprehensive Attendance Management System (38 Routes)
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/dashboard', 'scope' => 'read:attendance', 'description' => 'Executive attendance telemetry: present rates, absentees, active leaves, online devices, and live punch feed.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/statistics', 'scope' => 'read:attendance', 'description' => 'Granular attendance analytics: course-wise, batch-wise, and department breakdown with percentage.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance', 'scope' => 'read:attendance', 'description' => 'Search and paginate master attendance register with multi-criteria filters (date, status, course, batch).'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/mark', 'scope' => 'write:attendance', 'description' => 'Mark single attendance entry with check-in/out timestamps, working hours, and remarks.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/bulk-mark', 'scope' => 'write:attendance', 'description' => 'Rapid cohort bulk marking for an entire classroom section or staff department.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/{id}', 'scope' => 'read:attendance', 'description' => 'Retrieve single attendance dossier with linked user, course, biometric device, and audit history.'],
            ['category' => 'attendance', 'method' => 'PUT', 'path' => '/api/v1/attendance/{id}', 'scope' => 'write:attendance', 'description' => 'Update attendance status, timestamps, or remarks with automatic working hours recalculation.'],
            ['category' => 'attendance', 'method' => 'DELETE', 'path' => '/api/v1/attendance/{id}', 'scope' => 'write:attendance', 'description' => 'Soft delete attendance record (guarded: cannot delete entries synced from approved leaves).'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/matrix', 'scope' => 'read:attendance', 'description' => 'Query monthly attendance matrix across all active students/staff for a given year & month.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/users/{userId}/matrix', 'scope' => 'read:attendance', 'description' => 'Comprehensive 1..31 day monthly matrix for a user with total working days, attended days, and shortage flag.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/defaulters', 'scope' => 'read:attendance', 'description' => 'List candidates falling below institutional threshold (default 75%) with shortfall days count.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/defaulters/notify', 'scope' => 'write:attendance', 'description' => 'Dispatch bulk shortage warnings across Email, SMS, WhatsApp, and database channels.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/clock-in', 'scope' => 'write:attendance', 'description' => 'Mobile / Web self clock-in with GPS coordinates, accuracy, and device metadata.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/clock-out', 'scope' => 'write:attendance', 'description' => 'Mobile / Web self clock-out with automatic total working duration calculation.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/my-attendance', 'scope' => 'read:attendance', 'description' => 'Authenticated student/staff personal attendance history and monthly summary.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/punch', 'scope' => 'write:biometric', 'description' => 'Sub-second campus gate turnstile / RFID scanner punch verification and ingestion.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/biometric/batch-punch', 'scope' => 'write:biometric', 'description' => 'Bulk punch ingestion for offline hardware buffers and network reconnection sync.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/biometric/sync', 'scope' => 'write:biometric', 'description' => 'Trigger on-demand processing of pending raw biometric logs into attendance records.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/devices', 'scope' => 'read:attendance', 'description' => 'List physical biometric machines with IP, port, serial, location, and heartbeat status.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/devices', 'scope' => 'write:attendance', 'description' => 'Register new biometric hardware device with auto-generated device token.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/devices/{id}', 'scope' => 'read:attendance', 'description' => 'Device dossier with recent logs and status telemetry.'],
            ['category' => 'attendance', 'method' => 'PUT', 'path' => '/api/v1/attendance/devices/{id}', 'scope' => 'write:attendance', 'description' => 'Update biometric device IP, location, or online status.'],
            ['category' => 'attendance', 'method' => 'DELETE', 'path' => '/api/v1/attendance/devices/{id}', 'scope' => 'write:attendance', 'description' => 'Soft delete biometric device.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/devices/{id}/regenerate-token', 'scope' => 'write:attendance', 'description' => 'Regenerate API authorization token for hardware device push.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/calendars', 'scope' => 'read:attendance', 'description' => 'List institutional working calendars and shift configurations.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/calendars', 'scope' => 'write:attendance', 'description' => 'Create working calendar with expected start/end times, grace minutes, and weekend rules.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/calendars/{id}', 'scope' => 'read:attendance', 'description' => 'Show calendar rules and linked batches/departments.'],
            ['category' => 'attendance', 'method' => 'PUT', 'path' => '/api/v1/attendance/calendars/{id}', 'scope' => 'write:attendance', 'description' => 'Update working calendar rules and shift hours.'],
            ['category' => 'attendance', 'method' => 'DELETE', 'path' => '/api/v1/attendance/calendars/{id}', 'scope' => 'write:attendance', 'description' => 'Soft delete working calendar.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/holidays', 'scope' => 'read:attendance', 'description' => 'Query institutional holidays and academic off-days by year, month, or target type.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/holidays', 'scope' => 'write:attendance', 'description' => 'Add new holiday (Govt holiday, institution holiday, weekly test, vacation).'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/holidays/{id}', 'scope' => 'read:attendance', 'description' => 'Show single holiday details.'],
            ['category' => 'attendance', 'method' => 'PUT', 'path' => '/api/v1/attendance/holidays/{id}', 'scope' => 'write:attendance', 'description' => 'Update holiday date, title, or target audience.'],
            ['category' => 'attendance', 'method' => 'DELETE', 'path' => '/api/v1/attendance/holidays/{id}', 'scope' => 'write:attendance', 'description' => 'Soft delete holiday.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/export/csv', 'scope' => 'download:attendance', 'description' => 'Stream complete monthly attendance register as CSV spreadsheet.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/export/pdf', 'scope' => 'download:attendance', 'description' => 'Stream printable landscape PDF attendance register.'],
            ['category' => 'attendance', 'method' => 'POST', 'path' => '/api/v1/attendance/import', 'scope' => 'write:attendance', 'description' => 'Bulk import attendance register from uploaded CSV or Excel spreadsheet.'],
            ['category' => 'attendance', 'method' => 'GET', 'path' => '/api/v1/attendance/sample-csv', 'scope' => 'read:attendance', 'description' => 'Download sample CSV attendance import template.'],

            // Leave Management System (24 Comprehensive Routes)
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/dashboard', 'scope' => 'read:leaves', 'description' => 'Executive telemetry: active leaves today, pending reviews, approved this month, category distribution.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/statistics', 'scope' => 'read:leaves', 'description' => 'Granular leave statistics by category, user type, and monthly trends.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/categories', 'scope' => 'read:leaves', 'description' => 'List leave categories (Casual, Medical, Academic, Duty, Maternity) with annual quotas and rules.'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/categories', 'scope' => 'write:leaves', 'description' => 'Create new dynamic leave category with quota, color branding, and approval rules.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/categories/{id}', 'scope' => 'read:leaves', 'description' => 'Show single leave category dossier.'],
            ['category' => 'leaves', 'method' => 'PUT', 'path' => '/api/v1/leaves/categories/{id}', 'scope' => 'write:leaves', 'description' => 'Update category attributes, annual quotas, or active status.'],
            ['category' => 'leaves', 'method' => 'DELETE', 'path' => '/api/v1/leaves/categories/{id}', 'scope' => 'write:leaves', 'description' => 'Soft delete category (guarded: blocked if active applications exist).'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/allocations', 'scope' => 'read:leaves', 'description' => 'Query user quota allocations by year, user ID, or category.'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/allocations', 'scope' => 'write:leaves', 'description' => 'Assign or bulk initialize quota allocation for a user for a calendar year.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/allocations/{id}', 'scope' => 'read:leaves', 'description' => 'Show single quota allocation profile.'],
            ['category' => 'leaves', 'method' => 'PUT', 'path' => '/api/v1/leaves/allocations/{id}', 'scope' => 'write:leaves', 'description' => 'Adjust allocated, used, or carried-forward days.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/users/{userId}/balances', 'scope' => 'read:leaves', 'description' => 'Comprehensive balance sheet for a user showing total, used, pending, and remaining days per category.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/applications', 'scope' => 'read:leaves', 'description' => 'Search, filter, and paginate leave applications with status, date, and user filters.'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/applications', 'scope' => 'write:leaves', 'description' => 'Submit new leave application with date range, half-day toggles, and document attachment.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/applications/{id}', 'scope' => 'read:leaves', 'description' => 'Single application dossier with applicant details, proofs, approver remarks, and synced attendances.'],
            ['category' => 'leaves', 'method' => 'PUT', 'path' => '/api/v1/leaves/applications/{id}', 'scope' => 'write:leaves', 'description' => 'Update pending application details (dates, reason, emergency contact).'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/applications/{id}/approve', 'scope' => 'write:leaves', 'description' => 'Approve leave application: converts pending to used quota, updates status, and auto-syncs attendance records.'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/applications/{id}/reject', 'scope' => 'write:leaves', 'description' => 'Reject leave with mandatory remarks; restores pending days to user quota.'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/applications/{id}/cancel', 'scope' => 'write:leaves', 'description' => 'Cancel leave application; restores quota and removes auto-synced attendance records.'],
            ['category' => 'leaves', 'method' => 'DELETE', 'path' => '/api/v1/leaves/applications/{id}', 'scope' => 'write:leaves', 'description' => 'Soft delete leave application.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/my-balances', 'scope' => 'read:leaves', 'description' => 'Authenticated student/staff category-wise quota balances for the current year.'],
            ['category' => 'leaves', 'method' => 'GET', 'path' => '/api/v1/leaves/my-applications', 'scope' => 'read:leaves', 'description' => 'Authenticated candidates submitted leave applications feed.'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/apply', 'scope' => 'write:leaves', 'description' => 'Authenticated candidate self-submits a leave application with attachments.'],
            ['category' => 'leaves', 'method' => 'POST', 'path' => '/api/v1/leaves/my-applications/{id}/cancel', 'scope' => 'write:leaves', 'description' => 'Authenticated candidate cancels their own pending leave request.'],

            // Users & Access Management (RBAC) System (33 Comprehensive Routes)
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/users/dashboard', 'scope' => 'read:users', 'description' => 'Executive telemetry: total user population, active/pending/locked counts, role distribution, and recent logins.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/users/statistics', 'scope' => 'read:users', 'description' => 'In-depth access analytics: monthly user registrations, department headcounts, role allocations, and security lockouts.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/users', 'scope' => 'read:users', 'description' => 'Search and paginate institutional users with status, role, department, query search, and trashed filters.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users', 'scope' => 'write:users', 'description' => 'Register institutional user with auto-generated 14-digit URN, credentials, roles, and profile metadata.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/users/{identifier}', 'scope' => 'read:users', 'description' => 'Retrieve complete user dossier by ID, UUID, URN, email, student ID, or employee ID.'],
            ['category' => 'users', 'method' => 'PUT', 'path' => '/api/v1/users/{identifier}', 'scope' => 'write:users', 'description' => 'Update user attributes, contact info, department, biometric PIN, RFID card, or roles with audit log.'],
            ['category' => 'users', 'method' => 'DELETE', 'path' => '/api/v1/users/{identifier}', 'scope' => 'write:users', 'description' => 'Soft delete user account (safe deletion guard: blocked for self-deletion and last Super Admin).'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users/{identifier}/restore', 'scope' => 'write:users', 'description' => 'Restore a previously soft-deleted user account and re-activate system access.'],
            ['category' => 'users', 'method' => 'DELETE', 'path' => '/api/v1/users/{identifier}/force', 'scope' => 'write:users', 'description' => 'Permanently purge user account and wipe associated profile records (destructive action).'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users/{identifier}/status', 'scope' => 'write:users', 'description' => 'Update account status (active, pending, suspended, blocked) with mandatory administrative justification.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users/{identifier}/unlock', 'scope' => 'write:users', 'description' => 'Clear failed login attempts counter and immediately lift security lockout lock.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users/{identifier}/reset-password', 'scope' => 'write:users', 'description' => 'Issue a temporary auto-generated password or dispatch password reset notification.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users/{identifier}/sync-roles', 'scope' => 'manage:roles', 'description' => 'Synchronize or assign Spatie roles to user with safeguard preventing stripping last Super Admin.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/users/{identifier}/audit-logs', 'scope' => 'read:users', 'description' => 'Query chronological security, authorization, and administrative audit logs for a specific user.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/users/export', 'scope' => 'read:users', 'description' => 'Stream complete user directory as structured JSON dataset with query filters.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users/import', 'scope' => 'write:users', 'description' => 'Bulk ingest users via JSON array payload or uploaded CSV/Excel spreadsheet.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/users/bulk-status', 'scope' => 'write:users', 'description' => 'Batch update account statuses (activate, suspend, block) across a list of user IDs.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/roles', 'scope' => 'manage:roles', 'description' => 'Query system roles with assigned user counts and linked permissions catalog.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/roles', 'scope' => 'manage:roles', 'description' => 'Create new custom institutional role with description and assigned permissions.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/roles/{identifier}', 'scope' => 'manage:roles', 'description' => 'Retrieve role details, attached permissions, and assigned user members by ID or name.'],
            ['category' => 'users', 'method' => 'PUT', 'path' => '/api/v1/roles/{identifier}', 'scope' => 'manage:roles', 'description' => 'Update role display name or description (protected system roles guarded).'],
            ['category' => 'users', 'method' => 'DELETE', 'path' => '/api/v1/roles/{identifier}', 'scope' => 'manage:roles', 'description' => 'Delete custom role (guarded: blocked if role is assigned to active users or is a core system role).'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/roles/{identifier}/clone', 'scope' => 'manage:roles', 'description' => 'Duplicate an existing role and its permission matrix to a new role name.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/roles/{identifier}/sync-permissions', 'scope' => 'manage:roles', 'description' => 'Bulk synchronize and replace permissions matrix assigned to a role.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/permissions', 'scope' => 'manage:permissions', 'description' => 'Browse complete system permissions catalog filtered by module or keyword search.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/permissions/modules', 'scope' => 'manage:permissions', 'description' => 'Hierarchical catalog of permissions grouped by functional system modules.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/permissions', 'scope' => 'manage:permissions', 'description' => 'Register a new system permission under a specific functional module.'],
            ['category' => 'users', 'method' => 'PUT', 'path' => '/api/v1/permissions/{identifier}', 'scope' => 'manage:permissions', 'description' => 'Update permission metadata or module classification.'],
            ['category' => 'users', 'method' => 'DELETE', 'path' => '/api/v1/permissions/{identifier}', 'scope' => 'manage:permissions', 'description' => 'Delete custom permission (guarded: blocked if permission is actively assigned to roles).'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/user/me', 'scope' => 'read:users', 'description' => 'Authenticated user self-profile, assigned roles, permissions matrix, and session metadata.'],
            ['category' => 'users', 'method' => 'PUT', 'path' => '/api/v1/user/profile', 'scope' => 'write:users', 'description' => 'Authenticated user update own profile details and contact information.'],
            ['category' => 'users', 'method' => 'POST', 'path' => '/api/v1/user/change-password', 'scope' => 'write:users', 'description' => 'Authenticated user change own account password with current password verification.'],
            ['category' => 'users', 'method' => 'GET', 'path' => '/api/v1/security/audit-logs', 'scope' => 'read:users', 'description' => 'Master institutional security audit trail with event, user, IP, and timestamp filtering.'],

            // Realtime Live Chat, WebRTC Calling & Online Meetings (47 Routes)
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/status', 'scope' => 'read:chat', 'description' => 'WebSockets (Reverb), WebRTC active calls, and online meeting telemetry.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/calls/ice-servers', 'scope' => 'call:chat', 'description' => 'Retrieve STUN/TURN ICE server credentials for WebRTC peer audio/video connections.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/conversations', 'scope' => 'read:chat', 'description' => 'Browse and paginate active conversations (direct, group, channel) with unread counters.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/conversations/direct', 'scope' => 'write:chat', 'description' => 'Initiate or retrieve direct 1-on-1 conversation with another user.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/conversations/group', 'scope' => 'write:chat', 'description' => 'Create group conversation with title, description, and initial participants.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/conversations/channel', 'scope' => 'write:chat', 'description' => 'Create announcement or broadcast channel with granular posting rights.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/conversations/{id}', 'scope' => 'read:chat', 'description' => 'Conversation profile with participant roster, permissions, and pinned messages.'],
            ['category' => 'chat', 'method' => 'PUT', 'path' => '/api/v1/chat/conversations/{id}', 'scope' => 'write:chat', 'description' => 'Update conversation title, description, or broadcast settings (admin action).'],
            ['category' => 'chat', 'method' => 'DELETE', 'path' => '/api/v1/chat/conversations/{id}', 'scope' => 'write:chat', 'description' => 'Soft delete or leave conversation thread with audit logging.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/conversations/{id}/read', 'scope' => 'write:chat', 'description' => 'Mark all messages in conversation as read and dispatch real-time receipt events.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/conversations/{id}/typing', 'scope' => 'write:chat', 'description' => 'Broadcast real-time typing indicator status to conversation participants.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/conversations/{id}/participants', 'scope' => 'write:chat', 'description' => 'Add participant to group or channel with role (admin, moderator, member).'],
            ['category' => 'chat', 'method' => 'DELETE', 'path' => '/api/v1/chat/conversations/{id}/participants/{userId}', 'scope' => 'write:chat', 'description' => 'Remove participant from group or channel conversation.'],
            ['category' => 'chat', 'method' => 'PUT', 'path' => '/api/v1/chat/conversations/{id}/participants/{userId}/role', 'scope' => 'write:chat', 'description' => 'Update member role (admin, moderator, member) in group or channel.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/conversations/{id}/messages', 'scope' => 'read:chat', 'description' => 'Paginated message history with attachments, reactions, and reply threads.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/conversations/{id}/messages', 'scope' => 'write:chat', 'description' => 'Send text message with optional binary file attachments (up to 25MB) and reply-to ID.'],
            ['category' => 'chat', 'method' => 'PUT', 'path' => '/api/v1/chat/messages/{messageId}', 'scope' => 'write:chat', 'description' => 'Edit sent message content within allowable time window.'],
            ['category' => 'chat', 'method' => 'DELETE', 'path' => '/api/v1/chat/messages/{messageId}', 'scope' => 'write:chat', 'description' => 'Delete message for me or delete for everyone (admin/author action).'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/messages/{messageId}/pin', 'scope' => 'write:chat', 'description' => 'Pin message to the header of the conversation.'],
            ['category' => 'chat', 'method' => 'DELETE', 'path' => '/api/v1/chat/messages/{messageId}/pin', 'scope' => 'write:chat', 'description' => 'Unpin message from the conversation.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/messages/{messageId}/reaction', 'scope' => 'write:chat', 'description' => 'Toggle emoji reaction (like, heart, laugh) on message.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/broadcast', 'scope' => 'write:chat', 'description' => 'Bulk personalized broadcast message dispatch to target cohorts or batches.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/initiate', 'scope' => 'call:chat', 'description' => 'Initiate 1-on-1 WebRTC audio or video call session with ringing dispatch.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/group-initiate', 'scope' => 'call:chat', 'description' => 'Initiate group audio or video call session in conversation.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/{callUuid}/signal', 'scope' => 'call:chat', 'description' => 'Relay WebRTC signaling message (SDP offer, answer, ICE candidate, mode switch).'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/{callUuid}/accept', 'scope' => 'call:chat', 'description' => 'Accept incoming audio or video call session.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/{callUuid}/reject', 'scope' => 'call:chat', 'description' => 'Reject or decline incoming call with reason code (busy, declined).'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/{callUuid}/join', 'scope' => 'call:chat', 'description' => 'Join an active ongoing group call session.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/{callUuid}/leave', 'scope' => 'call:chat', 'description' => 'Leave active group call without terminating for other participants.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/calls/{callUuid}/end', 'scope' => 'call:chat', 'description' => 'Terminate call session for all participants and log duration.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/calls/{callUuid}/status', 'scope' => 'call:chat', 'description' => 'Query live call session status, active participant roster, and duration.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/calls/history', 'scope' => 'call:chat', 'description' => 'Query user call logs with duration, missed call status, and timestamps.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/meetings', 'scope' => 'meeting:chat', 'description' => 'Query scheduled, upcoming, and archived video conference meetings.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings', 'scope' => 'meeting:chat', 'description' => 'Create instant meeting or schedule virtual classroom with passcode and waiting room.'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/meetings/{uuid}', 'scope' => 'meeting:chat', 'description' => 'Single meeting dossier with host details, settings, and participant roster.'],
            ['category' => 'chat', 'method' => 'PUT', 'path' => '/api/v1/chat/meetings/{uuid}', 'scope' => 'meeting:chat', 'description' => 'Update meeting attributes, schedule, passcode, or security restrictions.'],
            ['category' => 'chat', 'method' => 'DELETE', 'path' => '/api/v1/chat/meetings/{uuid}', 'scope' => 'meeting:chat', 'description' => 'Cancel scheduled meeting and dispatch email/push notifications to attendees.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/join', 'scope' => 'meeting:chat', 'description' => 'Join meeting room: validates passcode, assigns role or waiting room status.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/signal', 'scope' => 'meeting:chat', 'description' => 'Relay WebRTC meeting signaling tracks (offer, answer, candidate, screen-share).'],
            ['category' => 'chat', 'method' => 'GET', 'path' => '/api/v1/chat/meetings/{uuid}/sync', 'scope' => 'meeting:chat', 'description' => 'Sync active meeting state, participants, hand raises, and active speakers.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/participants/{participantId}/admit', 'scope' => 'meeting:chat', 'description' => 'Host action: admit attendee from waiting room into active meeting.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/participants/{participantId}/deny', 'scope' => 'meeting:chat', 'description' => 'Host action: deny attendee admission from waiting room.'],
            ['category' => 'chat', 'method' => 'PUT', 'path' => '/api/v1/chat/meetings/{uuid}/participants/{participantId}/role', 'scope' => 'meeting:chat', 'description' => 'Update attendee role (assign co-host or attendee) in meeting.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/restrictions', 'scope' => 'meeting:chat', 'description' => 'Host controls: lock meeting, mute all, disable screen share or chat.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/reaction', 'scope' => 'meeting:chat', 'description' => 'Send floating emoji reaction (applause, heart, thumbs up) in meeting.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/invite', 'scope' => 'meeting:chat', 'description' => 'Bulk dispatch email calendar invitations to prospective attendees.'],
            ['category' => 'chat', 'method' => 'POST', 'path' => '/api/v1/chat/meetings/{uuid}/end', 'scope' => 'meeting:chat', 'description' => 'Host action: terminate meeting for all participants.'],

            // Application System Settings Module (30 Comprehensive Routes)
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/public', 'scope' => 'Public Bootstrap', 'description' => 'Fast public client bootstrap: application identity, logo/favicon, branding theme, localization, contact, campus, and feature flags for mobile/web/desktop apps.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/bootstrap/{platform}', 'scope' => 'Public Bootstrap', 'description' => 'Platform-tailored bootstrap payload (mobile, web, desktop, kiosk) with update policies, push configs, theme tokens, and deep links.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/version-check', 'scope' => 'Public Bootstrap', 'description' => 'Cross-platform app version verification returning update_available, update_required, download URL, and release notes.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/features', 'scope' => 'Public Bootstrap', 'description' => 'Categorized client feature toggles (communication, academics, campus facilities, payments, cloud storage, system).'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/groups', 'scope' => 'read:settings', 'description' => 'Catalog of distinct setting groups (general, appearance, localization, system, email, sms, payment, chat, library, hostel, attendance, seo) with active item counts and titles.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/statistics', 'scope' => 'read:settings', 'description' => 'Settings telemetry: total, active, inactive, soft-deleted, secrets count, cache status, and server environment metadata.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings', 'scope' => 'read:settings', 'description' => 'Query and paginate settings with filters for group, type, status, and search string (secrets masked by default).'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/groups/{group}', 'scope' => 'read:settings', 'description' => 'Retrieve all settings for a specific group as both a key-value dictionary and itemized collection.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/{key}', 'scope' => 'read:settings', 'description' => 'Retrieve single setting profile with cast typed value, options, and file URL by unique key.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings', 'scope' => 'write:settings', 'description' => 'Create a new dynamic system setting with type validation, status, options, and audit log.'],
            ['category' => 'settings', 'method' => 'PUT', 'path' => '/api/v1/settings/{key}', 'scope' => 'write:settings', 'description' => 'Update setting value, label, options, or active status with automatic cache invalidation.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/batch', 'scope' => 'write:settings', 'description' => 'Atomic batch update of multiple settings in a single transaction with audit trails.'],
            ['category' => 'settings', 'method' => 'DELETE', 'path' => '/api/v1/settings/{key}', 'scope' => 'write:settings', 'description' => 'Safely soft delete setting with deleted_by user tracking and audit log.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/{key}/restore', 'scope' => 'write:settings', 'description' => 'Restore a soft deleted setting and re-enable it in active cache.'],
            ['category' => 'settings', 'method' => 'DELETE', 'path' => '/api/v1/settings/{key}/force', 'scope' => 'manage:settings', 'description' => 'Permanently purge setting from database with administrative audit logging.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/cache/flush', 'scope' => 'manage:settings', 'description' => 'Forcibly flush application settings cache to reload fresh database state across all workers.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/reset', 'scope' => 'manage:settings', 'description' => 'Reset a setting, group, or all settings to factory seed defaults in a transaction.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/export/bundle', 'scope' => 'manage:settings', 'description' => 'Export active settings bundle as structured JSON (secrets redacted for security).'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/import/bundle', 'scope' => 'manage:settings', 'description' => 'Import settings JSON payload, upserting or creating missing rows with audit trails.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/assets', 'scope' => 'write:settings', 'description' => 'Upload branding asset file (logo, favicon, OG image) using FileUploadService and link to setting.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/test-email', 'scope' => 'manage:settings', 'description' => 'Send diagnostic test email to verify SMTP mail driver parameters.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/test-sms', 'scope' => 'manage:settings', 'description' => 'Send diagnostic test SMS to verify SMS gateway and DLT credentials.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/maintenance', 'scope' => 'manage:settings', 'description' => 'Toggle maintenance mode status and configure custom message and IP allowlists.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/storage/overview', 'scope' => 'read:settings', 'description' => 'Retrieve real-time storage disk telemetry, active driver (local/S3), cloud provider, and file categorization breakdown.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/test-storage', 'scope' => 'manage:settings', 'description' => 'Execute live read/write/delete connection diagnostic probe on Amazon S3 or S3-compatible cloud storage.'],
            ['category' => 'settings', 'method' => 'GET', 'path' => '/api/v1/settings/storage/files', 'scope' => 'read:settings', 'description' => 'Browse and paginate stored files on local public disk or remote S3 cloud bucket.'],
            ['category' => 'settings', 'method' => 'DELETE', 'path' => '/api/v1/settings/storage/files', 'scope' => 'manage:settings', 'description' => 'Permanently delete an uploaded file from local or cloud storage disk with audit logging.'],
            ['category' => 'settings', 'method' => 'POST', 'path' => '/api/v1/settings/storage/signed-upload-url', 'scope' => 'write:settings', 'description' => 'Pre-flight direct cloud S3 upload authorization or backend proxy endpoint resolution for client files.'],

            // Support Tickets & Helpdesk System (31 Endpoints)
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/dashboard', 'scope' => 'read:tickets', 'description' => 'Support desk KPI dashboard: total, active, priority breakdown, SLA breaches, CSAT rating, and recent activity.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/statistics', 'scope' => 'read:tickets', 'description' => 'In-depth ticket analytics: priority distribution, monthly trend, channels, and satisfaction breakdown.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/export', 'scope' => 'read:tickets', 'description' => 'Export filtered tickets dataset as structured JSON array for BI analytics or reporting.'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/check-sla', 'scope' => 'manage:tickets', 'description' => 'Automated SLA milestone scan: detect response/resolution breaches, update flags, and dispatch escalation alerts.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/categories', 'scope' => 'read:tickets', 'description' => 'List active support categories with SLA hours, priority defaults, icons, badge colors, and ticket counts.'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/categories', 'scope' => 'manage:tickets', 'description' => 'Create a new support ticket category with customized SLA response and resolution hours.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/categories/{id}', 'scope' => 'read:tickets', 'description' => 'Retrieve single category dossier with default assignee and SLA configuration.'],
            ['category' => 'tickets', 'method' => 'PUT', 'path' => '/api/v1/tickets/categories/{id}', 'scope' => 'manage:tickets', 'description' => 'Update ticket category metadata, SLA parameters, default assignee, or active status.'],
            ['category' => 'tickets', 'method' => 'DELETE', 'path' => '/api/v1/tickets/categories/{id}', 'scope' => 'manage:tickets', 'description' => 'Safely soft delete category (defensively blocked if active tickets are linked).'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/canned-responses', 'scope' => 'read:tickets', 'description' => 'Catalog of staff canned response macros and quick shortcuts with dynamic variable placeholders.'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/canned-responses', 'scope' => 'manage:tickets', 'description' => 'Create a new staff canned response macro for instantaneous ticket resolution.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/canned-responses/{id}', 'scope' => 'read:tickets', 'description' => 'Single canned response profile with category association and text content.'],
            ['category' => 'tickets', 'method' => 'PUT', 'path' => '/api/v1/tickets/canned-responses/{id}', 'scope' => 'manage:tickets', 'description' => 'Update canned response title, shortcut, template placeholders, or active flag.'],
            ['category' => 'tickets', 'method' => 'DELETE', 'path' => '/api/v1/tickets/canned-responses/{id}', 'scope' => 'manage:tickets', 'description' => 'Soft delete canned response macro with audit log.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/my-tickets', 'scope' => 'read:tickets', 'description' => 'Student / Aspirant self-service: retrieve own submitted tickets with unread counters and badge colors.'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/bulk-action', 'scope' => 'manage:tickets', 'description' => 'Execute bulk triage operations (status change, priority alteration, agent reassignment, or deletion) across ticket IDs.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/attachments/{attachmentId}/download', 'scope' => 'read:tickets', 'description' => 'Authorized secure stream download of ticket attachment without exposing disk path.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets', 'scope' => 'read:tickets', 'description' => 'Paginated ticket query with multi-attribute filters (status, priority, category, assignee, SLA breach, date range).'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets', 'scope' => 'write:tickets', 'description' => 'Create new support ticket with automatic SLA deadline calculation, multipart attachments, and multi-channel alerts.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/{identifier}', 'scope' => 'read:tickets', 'description' => 'Single ticket dossier with full threaded conversation, attachments, submitter profile, and SLA timers.'],
            ['category' => 'tickets', 'method' => 'PUT', 'path' => '/api/v1/tickets/{identifier}', 'scope' => 'write:tickets', 'description' => 'Update ticket subject, category, priority, or guest submitter metadata with audit logging.'],
            ['category' => 'tickets', 'method' => 'DELETE', 'path' => '/api/v1/tickets/{identifier}', 'scope' => 'write:tickets', 'description' => 'Safely soft delete support ticket with user tracking and audit logs.'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/{identifier}/restore', 'scope' => 'manage:tickets', 'description' => 'Restore a soft deleted support ticket back to active queue.'],
            ['category' => 'tickets', 'method' => 'PUT', 'path' => '/api/v1/tickets/{identifier}/status', 'scope' => 'write:tickets', 'description' => 'Transition ticket status (open, in_progress, pending_user, on_hold, resolved, closed) with milestone timestamping.'],
            ['category' => 'tickets', 'method' => 'PUT', 'path' => '/api/v1/tickets/{identifier}/priority', 'scope' => 'manage:tickets', 'description' => 'Alter ticket priority level (urgent, high, medium, low) and log system event.'],
            ['category' => 'tickets', 'method' => 'PUT', 'path' => '/api/v1/tickets/{identifier}/assign', 'scope' => 'manage:tickets', 'description' => 'Assign or reassign ticket to an agent user (or unassign) with automated staff notification.'],
            ['category' => 'tickets', 'method' => 'GET', 'path' => '/api/v1/tickets/{identifier}/messages', 'scope' => 'read:tickets', 'description' => 'Get conversation thread messages (internal staff notes defensively hidden from non-staff callers).'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/{identifier}/messages', 'scope' => 'write:tickets', 'description' => 'Post public reply or staff internal note with multipart attachments and optional status transition.'],
            ['category' => 'tickets', 'method' => 'DELETE', 'path' => '/api/v1/tickets/{identifier}/messages/{messageId}', 'scope' => 'manage:tickets', 'description' => 'Soft delete a message from conversation thread with audit record.'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/{identifier}/rate', 'scope' => 'write:tickets', 'description' => 'Submit student customer satisfaction rating (1 to 5 stars) and qualitative review feedback.'],
            ['category' => 'tickets', 'method' => 'POST', 'path' => '/api/v1/tickets/{identifier}/attachments', 'scope' => 'write:tickets', 'description' => 'Upload additional binary documents or screenshots to an existing ticket.'],

            // Communications & Marketing Module (48 Endpoints)
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/dashboard', 'scope' => 'read:communications', 'description' => 'Communications and marketing executive telemetry: dispatches, channels, success rate, campaign & subscriber counts.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/statistics', 'scope' => 'read:communications', 'description' => 'Cross-channel volume trends, failure distributions, template usage, and subscriber source acquisition analytics.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/export', 'scope' => 'read:communications', 'description' => 'Export filtered communication delivery logs as structured JSON dataset.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/send-email', 'scope' => 'write:communications', 'description' => 'Dispatch an individual or templated Email (immediate or queued) with dynamic variables and options.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/send-sms', 'scope' => 'write:communications', 'description' => 'Dispatch an individual or templated SMS (immediate or queued) with DLT template ID and sender ID.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/send-bulk', 'scope' => 'manage:communications', 'description' => 'Dispatch multi-channel broadcast messaging across an audience cohort, role, or custom list.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/logs', 'scope' => 'read:communications', 'description' => 'Paginated catalog of communication delivery logs with multi-attribute filtering (channel, status, type, dates).'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/logs/{id}', 'scope' => 'read:communications', 'description' => 'Detailed single communication delivery log record with recipient metadata and error tracing.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/logs/{id}/resend', 'scope' => 'manage:communications', 'description' => 'Resend an individual failed or past communication log item with resend counter increment.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/logs/bulk-resend', 'scope' => 'manage:communications', 'description' => 'Batch resend multiple communication logs by array of log IDs.'],
            ['category' => 'communications', 'method' => 'DELETE', 'path' => '/api/v1/communications/logs/{id}', 'scope' => 'manage:communications', 'description' => 'Soft delete a communication delivery log record with audit logging.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/logs/{id}/restore', 'scope' => 'manage:communications', 'description' => 'Restore a soft deleted communication log record back to active logs.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/logs/purge', 'scope' => 'manage:communications', 'description' => 'Purge or archive communication logs older than specified retention days.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/campaigns', 'scope' => 'read:communications', 'description' => 'Paginated catalog of marketing and notification campaigns with status and audience filters.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/campaigns', 'scope' => 'write:communications', 'description' => 'Create a new marketing campaign in draft or scheduled state with audience configuration.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/campaigns/{id}', 'scope' => 'read:communications', 'description' => 'Single campaign details, audience criteria, and real-time delivery telemetry.'],
            ['category' => 'communications', 'method' => 'PUT', 'path' => '/api/v1/communications/campaigns/{id}', 'scope' => 'write:communications', 'description' => 'Update campaign parameters (permitted only for draft or scheduled campaigns).'],
            ['category' => 'communications', 'method' => 'DELETE', 'path' => '/api/v1/communications/campaigns/{id}', 'scope' => 'manage:communications', 'description' => 'Safely soft delete a campaign record with audit trail.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/campaigns/{id}/restore', 'scope' => 'manage:communications', 'description' => 'Restore a soft deleted marketing campaign.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/campaigns/{id}/dispatch', 'scope' => 'manage:communications', 'description' => 'Trigger immediate execution or queue background asynchronous processing for a campaign.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/campaigns/{id}/cancel', 'scope' => 'manage:communications', 'description' => 'Cancel an active, queued, or scheduled campaign.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/campaigns/{id}/logs', 'scope' => 'read:communications', 'description' => 'Paginated list of delivery logs generated by a specific campaign.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/subscribers', 'scope' => 'read:communications', 'description' => 'Paginated directory of newsletter & WhatsApp marketing subscribers with tag & status filters.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/subscribers', 'scope' => 'write:communications', 'description' => 'Subscribe or reactivate a marketing contact with channel preference and tag segmentation.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/subscribers/{identifier}', 'scope' => 'read:communications', 'description' => 'Single subscriber profile dossier by numeric ID, UUID, email, or phone.'],
            ['category' => 'communications', 'method' => 'PUT', 'path' => '/api/v1/communications/subscribers/{identifier}', 'scope' => 'write:communications', 'description' => 'Update subscriber details, contact channels, status, tags, and metadata.'],
            ['category' => 'communications', 'method' => 'DELETE', 'path' => '/api/v1/communications/subscribers/{identifier}', 'scope' => 'manage:communications', 'description' => 'Soft delete a subscriber record with audit trail.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/subscribers/{identifier}/restore', 'scope' => 'manage:communications', 'description' => 'Restore a soft deleted subscriber back to active audience directory.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/subscribers/{identifier}/unsubscribe', 'scope' => 'write:communications', 'description' => 'Mark subscriber as unsubscribed with explicit reason.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/subscribers/bulk-action', 'scope' => 'manage:communications', 'description' => 'Perform bulk actions (activate, unsubscribe, add_tag, remove_tag, delete) across subscriber IDs.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/subscribers/export', 'scope' => 'read:communications', 'description' => 'Export filtered marketing subscribers directory as JSON dataset.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/subscribers/import', 'scope' => 'manage:communications', 'description' => 'Import subscriber contacts in bulk with automatic deduplication.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/public/subscribe', 'scope' => 'public', 'description' => 'Public lead subscription endpoint for landing pages, mobile onboarding, and web footers.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/public/unsubscribe', 'scope' => 'public', 'description' => 'Public one-click unsubscribe endpoint via token or verified email/phone.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/templates/email', 'scope' => 'read:communications', 'description' => 'Catalog of Email message templates with category, search, and status filters.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/templates/email', 'scope' => 'manage:communications', 'description' => 'Create a new Email message template with subject, body HTML, and placeholder variables.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/templates/email/{identifier}', 'scope' => 'read:communications', 'description' => 'Retrieve single Email message template by unique code or numeric ID.'],
            ['category' => 'communications', 'method' => 'PUT', 'path' => '/api/v1/communications/templates/email/{identifier}', 'scope' => 'manage:communications', 'description' => 'Update Email message template subject, HTML body, variables, or status.'],
            ['category' => 'communications', 'method' => 'DELETE', 'path' => '/api/v1/communications/templates/email/{identifier}', 'scope' => 'manage:communications', 'description' => 'Soft delete an Email template with audit record.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/templates/email/{identifier}/restore', 'scope' => 'manage:communications', 'description' => 'Restore a soft deleted Email template.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/templates/email/{identifier}/preview', 'scope' => 'read:communications', 'description' => 'Generate dynamic rendered preview of Email template with sample or custom test variables.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/templates/sms', 'scope' => 'read:communications', 'description' => 'Catalog of SMS message templates with category, sender ID, and status filters.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/templates/sms', 'scope' => 'manage:communications', 'description' => 'Create a new SMS message template with DLT registration ID and sender ID.'],
            ['category' => 'communications', 'method' => 'GET', 'path' => '/api/v1/communications/templates/sms/{identifier}', 'scope' => 'read:communications', 'description' => 'Retrieve single SMS message template by unique code or numeric ID.'],
            ['category' => 'communications', 'method' => 'PUT', 'path' => '/api/v1/communications/templates/sms/{identifier}', 'scope' => 'manage:communications', 'description' => 'Update SMS template body, sender ID, DLT registration ID, or status.'],
            ['category' => 'communications', 'method' => 'DELETE', 'path' => '/api/v1/communications/templates/sms/{identifier}', 'scope' => 'manage:communications', 'description' => 'Soft delete an SMS template with audit record.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/templates/sms/{identifier}/restore', 'scope' => 'manage:communications', 'description' => 'Restore a soft deleted SMS template.'],
            ['category' => 'communications', 'method' => 'POST', 'path' => '/api/v1/communications/templates/sms/{identifier}/preview', 'scope' => 'read:communications', 'description' => 'Generate dynamic rendered preview of SMS template with character count and segment calculation.'],

            // Global Search & Viewport Preferences
            ['category' => 'search', 'method' => 'GET', 'path' => '/api/v1/search/global', 'scope' => 'read:search', 'description' => 'Unified global search across navigation modules, actions, and permissible data records with role-based access filtering.'],
            ['category' => 'search', 'method' => 'GET', 'path' => '/api/v1/search/modules', 'scope' => 'read:search', 'description' => 'Hierarchical catalog of all admin modules, submenus, navigation links, and quick actions accessible to caller.'],
            ['category' => 'search', 'method' => 'GET', 'path' => '/api/v1/search/preferences', 'scope' => 'read:search', 'description' => 'Retrieve active viewport display (fullscreen) and search preferences for authenticated user and system.'],
            ['category' => 'search', 'method' => 'POST', 'path' => '/api/v1/search/preferences', 'scope' => 'manage:search', 'description' => 'Update viewport display mode (normal vs fullscreen), search history, and optional system-wide setting.'],

            // UPSC Mock Interview Management System (27 Routes)
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/dashboard', 'scope' => 'read:mock_interviews', 'description' => 'Real-time telemetry: total sessions, candidates evaluated, recordings uploaded, invitations sent, and average score.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/sessions', 'scope' => 'read:mock_interviews', 'description' => 'List and search mock interview sessions with year, status, and date range filters.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/sessions', 'scope' => 'manage:mock_interviews', 'description' => 'Create a mock interview session with date, timing, venue, instructions, and default chair.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/sessions/{id}', 'scope' => 'read:mock_interviews', 'description' => 'Detailed session dossier including board panel members, scheduled aspirants, and sequence order.'],
            ['category' => 'mock_interviews', 'method' => 'PUT', 'path' => '/api/v1/mock-interviews/sessions/{id}', 'scope' => 'manage:mock_interviews', 'description' => 'Update session date, start/end timing, venue address, status, or remarks.'],
            ['category' => 'mock_interviews', 'method' => 'DELETE', 'path' => '/api/v1/mock-interviews/sessions/{id}', 'scope' => 'manage:mock_interviews', 'description' => 'Soft delete mock interview session and cascade session members/candidates.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/sessions/{id}/members', 'scope' => 'manage:mock_interviews', 'description' => 'Assign faculty or external dignitary to the interview board panel.'],
            ['category' => 'mock_interviews', 'method' => 'DELETE', 'path' => '/api/v1/mock-interviews/sessions/{id}/members/{identifier}', 'scope' => 'manage:mock_interviews', 'description' => 'Remove board panelist appointment from session.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/sessions/{id}/candidates', 'scope' => 'manage:mock_interviews', 'description' => 'Assign student candidate to session slot with interview mode, timing, and virtual meeting link.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/candidates/{identifier}', 'scope' => 'read:mock_interviews', 'description' => 'Detailed candidate interview dossier, slot timings, marks, and evaluation feedback.'],
            ['category' => 'mock_interviews', 'method' => 'PUT', 'path' => '/api/v1/mock-interviews/candidates/{identifier}', 'scope' => 'manage:mock_interviews', 'description' => 'Update candidate slot timing, mode (online/offline), virtual platform, or status.'],
            ['category' => 'mock_interviews', 'method' => 'DELETE', 'path' => '/api/v1/mock-interviews/candidates/{identifier}', 'scope' => 'manage:mock_interviews', 'description' => 'Remove candidate from mock interview session.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/candidates/{identifier}/evaluate', 'scope' => 'write:mock_interviews', 'description' => 'Record panel scores (marks out of 275) and qualitative feedback for aspirant.'],
            ['category' => 'mock_interviews', 'method' => 'PUT', 'path' => '/api/v1/mock-interviews/candidates/{identifier}/recording', 'scope' => 'manage:mock_interviews', 'description' => 'Attach cloud recording URL (Zoom/Drive/Vimeo) and optional password for student review.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/candidates/{identifier}/dispatch-recording', 'scope' => 'manage:mock_interviews', 'description' => 'Dispatch interview recording link to student via Email, SMS, WhatsApp, and Telegram.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/sessions/{id}/auto-slots', 'scope' => 'manage:mock_interviews', 'description' => 'Automatically calculate and allocate time slots for all session candidates.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/sessions/{id}/invite', 'scope' => 'manage:mock_interviews', 'description' => 'Dispatch personalized invitations across selected channels (Email, SMS, WhatsApp, Telegram).'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/sessions/{id}/templates', 'scope' => 'read:mock_interviews', 'description' => 'Retrieve current personalized invitation templates and available placeholder variables.'],
            ['category' => 'mock_interviews', 'method' => 'PUT', 'path' => '/api/v1/mock-interviews/sessions/{id}/templates', 'scope' => 'manage:mock_interviews', 'description' => 'Update personalized invitation templates for Chairman, Board Panelists, and Aspirants.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/board-members', 'scope' => 'read:mock_interviews', 'description' => 'List and search master board members pool with role and designation filters.'],
            ['category' => 'mock_interviews', 'method' => 'POST', 'path' => '/api/v1/mock-interviews/board-members', 'scope' => 'manage:mock_interviews', 'description' => 'Create a new board member in master pool with profile and contact details.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/board-members/{id}', 'scope' => 'read:mock_interviews', 'description' => 'Show individual board member appointment profile and session history.'],
            ['category' => 'mock_interviews', 'method' => 'PUT', 'path' => '/api/v1/mock-interviews/board-members/{id}', 'scope' => 'manage:mock_interviews', 'description' => 'Update master board member details, designation, or contact info.'],
            ['category' => 'mock_interviews', 'method' => 'DELETE', 'path' => '/api/v1/mock-interviews/board-members/{id}', 'scope' => 'manage:mock_interviews', 'description' => 'Soft delete master board member from pool.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/reports', 'scope' => 'read:mock_interviews', 'description' => 'Dynamic reporting endpoint supporting student_wise, board_member_wise, and year_wise perspectives.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/mock-interviews/analytics', 'scope' => 'read:mock_interviews', 'description' => 'Macro-level telemetry: turnout rate, score distribution, mode split, and recording completion.'],
            ['category' => 'mock_interviews', 'method' => 'GET', 'path' => '/api/v1/student/mock-interviews', 'scope' => 'read:mock_interviews', 'description' => 'Student mobile/portal endpoint: View scheduled slots, queue timetable, and own video recording.'],

            // Soft Copies Document Files Management with Version Control (17 Comprehensive Routes)
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents', 'scope' => 'read:documents', 'description' => 'List and search institute document files with category, file_type, search, and date filters.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/statistics', 'scope' => 'read:documents', 'description' => 'Document vault executive telemetry: total documents, versions count, file size distribution, and mime types breakdown.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/categories', 'scope' => 'read:documents', 'description' => 'List active document categories taxonomy with document counts.'],
            ['category' => 'documents', 'method' => 'POST', 'path' => '/api/v1/documents/categories', 'scope' => 'write:documents', 'description' => 'Create a new document category taxonomy with description and slug.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/template', 'scope' => 'read:documents', 'description' => 'Download standard sample CSV template for bulk document registration.'],
            ['category' => 'documents', 'method' => 'POST', 'path' => '/api/v1/documents/import', 'scope' => 'write:documents', 'description' => 'Bulk import documents metadata from CSV or XLSX spreadsheet.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/export', 'scope' => 'read:documents', 'description' => 'Export filtered documents register in CSV, XLSX, or JSON format.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/{id}', 'scope' => 'read:documents', 'description' => 'Detailed document dossier with active version, category, and historical revisions.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/{id}/download', 'scope' => 'download:documents', 'description' => 'Stream or download the active current version file directly.'],
            ['category' => 'documents', 'method' => 'POST', 'path' => '/api/v1/documents', 'scope' => 'write:documents', 'description' => 'Register a new document with initial file binary upload (PDF, Word, Excel, PPT, Image, ZIP) and metadata.'],
            ['category' => 'documents', 'method' => 'PUT', 'path' => '/api/v1/documents/{id}', 'scope' => 'write:documents', 'description' => 'Update document metadata (title, category, purpose, description, note, remarks).'],
            ['category' => 'documents', 'method' => 'DELETE', 'path' => '/api/v1/documents/{id}', 'scope' => 'admin:documents', 'description' => 'Soft delete document with audit logs and cascade protection.'],
            ['category' => 'documents', 'method' => 'POST', 'path' => '/api/v1/documents/{id}/restore', 'scope' => 'admin:documents', 'description' => 'Restore a soft deleted document back to active repository.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/{id}/versions', 'scope' => 'read:documents', 'description' => 'Complete chronological version history list with changelogs, size, and author metadata.'],
            ['category' => 'documents', 'method' => 'POST', 'path' => '/api/v1/documents/{id}/versions', 'scope' => 'write:documents', 'description' => 'Upload revised document version file with version code, change notes, and optional set_as_current flag.'],
            ['category' => 'documents', 'method' => 'POST', 'path' => '/api/v1/documents/{id}/versions/{versionId}/set-current', 'scope' => 'admin:documents', 'description' => 'Rollback or switch current active document version to a previous revision.'],
            ['category' => 'documents', 'method' => 'GET', 'path' => '/api/v1/documents/{id}/versions/{versionId}/download', 'scope' => 'download:documents', 'description' => 'Stream download a specific historical version file securely.'],

            // Bot Defense & CAPTCHA System (8 Comprehensive Routes)
            ['category' => 'captcha', 'method' => 'GET', 'path' => '/api/v1/captcha/specs', 'scope' => 'Public / read:captcha', 'description' => 'Client configuration specifications: active driver, public site key, Google reCAPTCHA version, domain, custom settings, dev autofill status, and protected actions matrix.'],
            ['category' => 'captcha', 'method' => 'GET', 'path' => '/api/v1/captcha/generate', 'scope' => 'Public', 'description' => 'Dynamic challenge generation: issues single-use token, anti-OCR SVG vector image, phonetic speech string, and audio link.'],
            ['category' => 'captcha', 'method' => 'POST', 'path' => '/api/v1/captcha/verify', 'scope' => 'Public / verify:captcha', 'description' => 'Verify challenge solution (answer, token, or g-recaptcha-response) with instant cache eviction and telemetry logging.'],
            ['category' => 'captcha', 'method' => 'POST', 'path' => '/api/v1/captcha/refresh', 'scope' => 'Public / verify:captcha', 'description' => 'Atomic refresh: invalidates obsolete/expired challenge token and returns fresh SVG challenge in a single round-trip.'],
            ['category' => 'captcha', 'method' => 'GET', 'path' => '/api/v1/captcha/audio/{token}', 'scope' => 'Public', 'description' => 'Synthesized 16-bit PCM WAV acoustic audio readout streaming for native mobile, desktop, and accessibility screen readers (WCAG 2.2 AA).'],
            ['category' => 'captcha', 'method' => 'GET', 'path' => '/api/v1/captcha/status', 'scope' => 'Public', 'description' => 'High-level status: checks whether CAPTCHA protection is globally active, enabled drivers, and protected form modules.'],
            ['category' => 'captcha', 'method' => 'GET', 'path' => '/api/v1/captcha/diagnostics', 'scope' => 'read:captcha,manage:captcha', 'description' => 'Administrative diagnostics: driver health self-test latency, cache probe, pass rate metrics, and recent verification telemetry.'],
            ['category' => 'captcha', 'method' => 'POST', 'path' => '/api/v1/captcha/logs/prune', 'scope' => 'manage:captcha', 'description' => 'Administrative log pruning: soft/force purge verification logs older than threshold days within DB transaction.'],
        ];

        if ($this->activeScopeTab === 'all') {
            return $all;
        }

        return array_values(array_filter($all, fn ($item) => $item['category'] === $this->activeScopeTab));
    }
};
?>

<div class="space-y-8 max-w-7xl mx-auto" x-data="{ copyFeedback: '' }">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="p-2 bg-primary/10 text-primary rounded-xl">
                    <x-icon name="terminal" class="w-6 h-6" />
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-foreground tracking-tight">{{ __('API Developer Portal & Console') }}</h1>
                    <p class="text-xs sm:text-sm text-muted-foreground">{{ __('Integration specs, multi-platform SDK snippets, and real-time interactive sandbox.') }}</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.api-keys.index') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="key" class="w-4 h-4 text-primary" />
                <span>{{ __('API Keys') }}</span>
            </a>

            <a href="{{ route('admin.api-keys.logs') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="activity" class="w-4 h-4 text-emerald-500" />
                <span>{{ __('Request Logs') }}</span>
            </a>
        </div>
    </div>

    <!-- Quick Reference Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Auth Method -->
        <div class="bg-card rounded-xl border border-border p-5 shadow-sm space-y-2">
            <div class="flex items-center gap-2 text-primary font-bold text-sm">
                <x-icon name="key" class="w-4 h-4" />
                <span>{{ __('Authentication Header') }}</span>
            </div>
            <p class="text-xs text-muted-foreground leading-relaxed">
                {{ __('Pass your API key in either header with every HTTP request:') }}
            </p>
            <div class="space-y-1 font-mono text-xs">
                <div class="p-2 rounded-lg bg-muted text-foreground border border-border select-all font-semibold">
                    X-API-Key: sntc_live_...
                </div>
                <div class="text-[10px] text-muted-foreground text-center">{{ __('or') }}</div>
                <div class="p-2 rounded-lg bg-muted text-foreground border border-border select-all font-semibold">
                    Authorization: Bearer sntc_live_...
                </div>
            </div>
        </div>

        <!-- Environments -->
        <div class="bg-card rounded-xl border border-border p-5 shadow-sm space-y-2">
            <div class="flex items-center gap-2 text-emerald-600 dark:text-emerald-400 font-bold text-sm">
                <x-icon name="globe" class="w-4 h-4" />
                <span>{{ __('Base URLs & Environments') }}</span>
            </div>
            <p class="text-xs text-muted-foreground leading-relaxed">
                {{ __('Production vs Sandbox endpoints for testing before rollout:') }}
            </p>
            <div class="space-y-2 font-mono text-xs">
                <div class="p-2 rounded-lg bg-muted text-foreground border border-border select-all">
                    <span class="text-[10px] uppercase font-bold text-emerald-600 block font-sans">{{ __('Production Base') }}</span>
                    {{ url('/api/v1') }}
                </div>
                <div class="p-2 rounded-lg bg-muted text-foreground border border-border select-all">
                    <span class="text-[10px] uppercase font-bold text-amber-500 block font-sans">{{ __('Sandbox Keys') }}</span>
                    {{ __('sntc_test_...') }}
                </div>
            </div>
        </div>

        <!-- Rate Limits & Headers -->
        <div class="bg-card rounded-xl border border-border p-5 shadow-sm space-y-2">
            <div class="flex items-center gap-2 text-amber-500 font-bold text-sm">
                <x-icon name="clock" class="w-4 h-4" />
                <span>{{ __('Rate Limiting & Telemetry') }}</span>
            </div>
            <p class="text-xs text-muted-foreground leading-relaxed">
                {{ __('Responses return standard rate limiting headers to prevent threshold breaches:') }}
            </p>
            <div class="space-y-1.5 font-mono text-[11px] text-muted-foreground">
                <div class="flex justify-between py-1 border-b border-border">
                    <span class="text-foreground">X-RateLimit-Limit</span>
                    <span>60 req/min</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border">
                    <span class="text-foreground">X-RateLimit-Remaining</span>
                    <span>{{ __('Remaining calls') }}</span>
                </div>
                <div class="flex justify-between py-1">
                    <span class="text-foreground">Retry-After</span>
                    <span>{{ __('Wait seconds on 429') }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- SECTION 1: INTERACTIVE API SANDBOX & TEST PLAYGROUND           -->
    <!-- ============================================================== -->
    <div id="sandbox-playground" class="bg-card rounded-2xl border border-border shadow-sm overflow-hidden scroll-mt-6">
        <div class="px-6 py-4 border-b border-border bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <div class="p-2 bg-primary/10 text-primary rounded-xl">
                    <x-icon name="zap" class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-base font-bold text-foreground">{{ __('Interactive API Test Sandbox') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ __('Test live API endpoints right inside your browser.') }}</p>
                </div>
            </div>

            <!-- Active Key Dropdown -->
            <div class="flex items-center gap-2">
                <label class="text-xs text-muted-foreground font-medium shrink-0">{{ __('Simulate as Key:') }}</label>
                <select wire:model.live="selectedApiKeyId" class="px-3 py-1.5 text-xs rounded-lg border border-border bg-background text-foreground font-semibold">
                    <option value="">{{ __('Default / Auto-auth') }}</option>
                    @foreach($this->availableKeys as $k)
                        <option value="{{ $k->id }}">{{ $k->name }} ({{ $k->key_prefix }})</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="p-6 space-y-4">
            <!-- URL Bar -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <!-- Method -->
                <select wire:model="sandboxMethod" class="w-28 px-3 py-2.5 text-xs font-bold rounded-xl border border-border bg-muted/40 text-foreground font-mono focus:outline-none">
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                    <option value="PUT">PUT</option>
                    <option value="DELETE">DELETE</option>
                </select>

                <!-- Endpoint -->
                <div class="relative flex-1">
                    <input type="text" wire:model="sandboxEndpoint"
                           placeholder="/api/v1/ping"
                           class="w-full px-4 py-2.5 text-xs font-mono rounded-xl border border-border bg-background text-foreground font-semibold focus:outline-none focus:ring-2 focus:ring-primary/20">
                </div>

                <!-- Execute Button -->
                <button type="button" wire:click="executeSandboxRequest" wire:loading.attr="disabled"
                        class="px-5 py-2.5 text-xs font-semibold rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all flex items-center justify-center gap-2 shrink-0">
                    <x-icon name="zap" class="w-4 h-4" />
                    <span>{{ __('Execute Call') }}</span>
                </button>
            </div>

            <!-- Quick Endpoint Presets Filter & Grid -->
            <div class="space-y-2.5">
                <!-- Preset Category Filter Bar -->
                <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
                    <span class="text-muted-foreground font-semibold text-[11px] mr-1 shrink-0">{{ __('Filter Presets:') }}</span>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'all')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'all' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'bg-muted text-muted-foreground hover:text-foreground' }}">
                        {{ __('All Modules') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'academics')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'academics' ? 'bg-sky-600 text-white font-bold shadow-xs' : 'bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20' }}">
                        {{ __('Academics') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'materials')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'materials' ? 'bg-emerald-600 text-white font-bold shadow-xs' : 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20' }}">
                        {{ __('Study Materials') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'hostel')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'hostel' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'bg-primary/10 text-primary hover:bg-primary/20' }}">
                        {{ __('Hostel Management') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'examinations')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'examinations' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-indigo-500/10 text-indigo-600 hover:bg-indigo-500/20' }}">
                        {{ __('Examinations') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'student_tests')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'student_tests' ? 'bg-amber-600 text-white font-bold shadow-xs' : 'bg-amber-500/10 text-amber-600 hover:bg-amber-500/20' }}">
                        {{ __('Student Tests') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'optional_subjects')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'optional_subjects' ? 'bg-purple-600 text-white font-bold shadow-xs' : 'bg-purple-500/10 text-purple-600 hover:bg-purple-500/20' }}">
                        {{ __('Optional Subjects') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'id_cards')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'id_cards' ? 'bg-blue-600 text-white font-bold shadow-xs' : 'bg-blue-500/10 text-blue-600 hover:bg-blue-500/20' }}">
                        {{ __('ID Cards') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'library')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'library' ? 'bg-cyan-600 text-white font-bold shadow-xs' : 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20' }}">
                        {{ __('Library') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'students')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'students' ? 'bg-teal-600 text-white font-bold shadow-xs' : 'bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20' }}">
                        {{ __('Students') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'enrollments')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'enrollments' ? 'bg-emerald-600 text-white font-bold shadow-xs' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20' }}">
                        {{ __('Enrollments') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'payments')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'payments' ? 'bg-emerald-600 text-white font-bold shadow-xs' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20' }}">
                        {{ __('Fees & Payments') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'attendance')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'attendance' ? 'bg-teal-600 text-white font-bold shadow-xs' : 'bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20' }}">
                        {{ __('Attendance') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'leaves')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'leaves' ? 'bg-emerald-600 text-white font-bold shadow-xs' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20' }}">
                        {{ __('Leaves') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'users')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'users' ? 'bg-violet-600 text-white font-bold shadow-xs' : 'bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20' }}">
                        {{ __('Users & RBAC') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'chat')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'chat' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20' }}">
                        {{ __('Live Chat & Calls') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'settings')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'settings' ? 'bg-emerald-600 text-white font-bold shadow-xs' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20' }}">
                        {{ __('Settings') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'tickets')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'tickets' ? 'bg-rose-600 text-white font-bold shadow-xs' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20' }}">
                        {{ __('Support Tickets') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'communications')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'communications' ? 'bg-amber-600 text-white font-bold shadow-xs' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20' }}">
                        {{ __('Communications & Marketing') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'search')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'search' ? 'bg-primary text-primary-foreground font-bold shadow-xs' : 'bg-primary/10 text-primary hover:bg-primary/20' }}">
                        {{ __('Global Search & Preferences') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'mock_interviews')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'mock_interviews' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20' }}">
                        {{ __('Mock Interviews') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'documents')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'documents' ? 'bg-indigo-600 text-white font-bold shadow-xs' : 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20' }}">
                        {{ __('Documents & Vault') }}
                    </button>
                    <button type="button" wire:click="$set('sandboxCategoryFilter', 'captcha')"
                            class="px-2.5 py-1 rounded-lg font-medium text-[11px] transition-colors {{ $sandboxCategoryFilter === 'captcha' ? 'bg-amber-600 text-white font-bold shadow-xs' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20' }}">
                        {{ __('Bot Defense & CAPTCHA') }}
                    </button>
                </div>

                <!-- Presets Buttons Grid -->
                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                    <!-- Course and Academics Management System Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'academics')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/courses?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/courses
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/courses/1')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/courses/1
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/academics/courses', '{\n  &quot;name&quot;: &quot;WBCS Executive Foundation Programme&quot;,\n  &quot;code&quot;: &quot;WBCS-FOUND-2027&quot;,\n  &quot;category&quot;: &quot;Classroom&quot;,\n  &quot;duration&quot;: &quot;12 Months&quot;,\n  &quot;fee&quot;: 1000.00,\n  &quot;fee_type&quot;: &quot;monthly&quot;,\n  &quot;fee_reserved&quot;: 500.00,\n  &quot;capacity&quot;: 200,\n  &quot;has_sections&quot;: true,\n  &quot;status&quot;: &quot;active&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-sky-600 text-white hover:bg-sky-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /academics/courses
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/batches?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/batches
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/batches/1')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/batches/1
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/academics/batches', '{\n  &quot;name&quot;: &quot;2026-2027 Civil Services Morning Cohort&quot;,\n  &quot;code&quot;: &quot;CS-2027-M1&quot;,\n  &quot;academic_year&quot;: &quot;2026-2027&quot;,\n  &quot;status&quot;: &quot;upcoming&quot;,\n  &quot;max_students&quot;: 150\n}')"
                                class="px-2.5 py-1 rounded-lg bg-sky-600 text-white hover:bg-sky-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /academics/batches
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/batches/1/courses')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/batches/1/courses
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/sections')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/sections
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/academics/sections', '{\n  &quot;batch_id&quot;: 1,\n  &quot;course_id&quot;: 1,\n  &quot;name&quot;: &quot;Section A&quot;,\n  &quot;code&quot;: &quot;1&quot;,\n  &quot;capacity&quot;: 45,\n  &quot;room_number&quot;: &quot;Room 201&quot;,\n  &quot;timing&quot;: &quot;10:00 AM - 01:00 PM&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-sky-600 text-white hover:bg-sky-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /academics/sections
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/academic-years')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/academic-years
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/calendar')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/calendar
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/academics/catalog')"
                                class="px-2.5 py-1 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400 hover:bg-sky-500/20 font-mono text-[11px] border border-sky-500/20 font-semibold">
                            GET /academics/catalog
                        </button>
                        <!-- Class Routine & Lecture Schedules Presets -->
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/classes?today=1')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /classes?today=1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/classes/calendar')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /classes/calendar
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/classes', '{\n  &quot;batch_id&quot;: 1,\n  &quot;course_id&quot;: 1,\n  &quot;class_type&quot;: &quot;regular&quot;,\n  &quot;class_date&quot;: &quot;2026-11-20&quot;,\n  &quot;start_time&quot;: &quot;10:00:00&quot;,\n  &quot;end_time&quot;: &quot;12:00:00&quot;,\n  &quot;subject&quot;: &quot;Modern Indian History&quot;,\n  &quot;topics&quot;: &quot;Freedom Struggle 1919-1947&quot;,\n  &quot;delivery_mode&quot;: &quot;online&quot;,\n  &quot;platform&quot;: &quot;Zoom&quot;,\n  &quot;meeting_link&quot;: &quot;https://zoom.us/j/1234567890&quot;,\n  &quot;faculty_ids&quot;: [1]\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /classes
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/classes/reports/faculty-workload')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /classes/reports/faculty-workload
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/classes/settings/reminders')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /classes/settings/reminders
                        </button>
                        <!-- Faculty Master Presets -->
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/faculties?active_only=1')"
                                class="px-2.5 py-1 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400 hover:bg-purple-500/20 font-mono text-[11px] border border-purple-500/20 font-semibold">
                            GET /faculties
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/faculties/workload-stats')"
                                class="px-2.5 py-1 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400 hover:bg-purple-500/20 font-mono text-[11px] border border-purple-500/20 font-semibold">
                            GET /faculties/workload-stats
                        </button>
                        <!-- Classrooms Master Presets -->
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/classrooms?active_only=1')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            GET /classrooms
                        </button>
                        <!-- Partner Coaching Institutes Presets -->
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/partner-institutes?active_only=1')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /partner-institutes
                        </button>
                        <!-- Student Self-Service Class Routine Presets -->
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/student/classes?today=1')"
                                class="px-2.5 py-1 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 hover:bg-blue-500/20 font-mono text-[11px] border border-blue-500/20 font-semibold">
                            GET /student/classes?today=1
                        </button>
                        <!-- Faculty Self-Service Routine Presets -->
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/faculty/my-schedule?upcoming=1')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /faculty/my-schedule
                        </button>
                    @endif
                    <!-- Student Management & Admissions Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'students')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/students/verify-identity', '{\n  &quot;identifier&quot;: &quot;202610001&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /students/verify-identity
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/batches')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/batches
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/courses')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/courses
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/sections')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/sections
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/profile-requests')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/profile-requests
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/students', '{\n  &quot;name&quot;: &quot;Priya Banerjee&quot;,\n  &quot;email&quot;: &quot;priya.b@example.com&quot;,\n  &quot;phone&quot;: &quot;9830099887&quot;,\n  &quot;gender&quot;: &quot;Female&quot;,\n  &quot;category&quot;: &quot;UR&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /students
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/information-sheets?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/information-sheets
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/1/information-sheet?include_documents=1')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/{id}/information-sheet
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/students/information-sheets/bulk', '{\n  &quot;ids&quot;: [1, 2],\n  &quot;include_documents&quot;: true\n}')"
                                class="px-2.5 py-1 rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /students/information-sheets/bulk
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students/1/information-sheet/download?disposition=inline')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /students/{id}/information-sheet/download
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/students/information-sheets/download', '{\n  &quot;ids&quot;: [1],\n  &quot;include_documents&quot;: false\n}')"
                                class="px-2.5 py-1 rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /students/information-sheets/download
                        </button>
                    @endif

                    <!-- Course Enrollments Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'enrollments')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/enrollments?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /enrollments
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/enrollments', '{\n  &quot;student_identifier&quot;: &quot;1&quot;,\n  &quot;course_id&quot;: 1,\n  &quot;batch_id&quot;: 1,\n  &quot;fee_status&quot;: &quot;paid&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /enrollments
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/enrollments/1/transfer-section', '{\n  &quot;new_section_id&quot;: 2,\n  &quot;reason&quot;: &quot;Transferred to Morning Shift batch&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /enrollments/1/transfer-section
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/enrollments/1/complete')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /enrollments/1/complete
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/enrollments/1/discontinue', '{\n  &quot;reason&quot;: &quot;Candidate relocation to central civil service academy&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /enrollments/1/discontinue
                        </button>
                    @endif
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'system')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/ping')"
                                class="px-2.5 py-1 rounded-lg bg-muted text-muted-foreground hover:text-foreground font-mono text-[11px] border border-border">
                            GET /ping
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/app/info')"
                                class="px-2.5 py-1 rounded-lg bg-muted text-muted-foreground hover:text-foreground font-mono text-[11px] border border-border">
                            GET /app/info
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/courses')"
                                class="px-2.5 py-1 rounded-lg bg-muted text-muted-foreground hover:text-foreground font-mono text-[11px] border border-border">
                            GET /courses
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/students?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-muted text-muted-foreground hover:text-foreground font-mono text-[11px] border border-border">
                            GET /students
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/alumni?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-muted text-muted-foreground hover:text-foreground font-mono text-[11px] border border-border">
                            GET /alumni
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/echo', '{\n  &quot;message&quot;: &quot;Hello from Developer Console&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-muted text-muted-foreground hover:text-foreground font-mono text-[11px] border border-border">
                            POST /echo
                        </button>
                    @endif

                    <!-- Dynamic Study Materials Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'materials')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/types')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/types
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/my-materials')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/my-materials
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/courses/1')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/courses/1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/batches/1')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/batches/1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/1')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/1/download-token')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/1/download-token
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/study-materials/1/download-zip')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /study-materials/1/download-zip
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/external/study-materials?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /external/study-materials
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/study-materials', '{\n  &quot;title&quot;: &quot;Indian Polity &amp; Governance - Handout 04&quot;,\n  &quot;course_id&quot;: 1,\n  &quot;material_type&quot;: &quot;handout&quot;,\n  &quot;subject&quot;: &quot;Indian Polity&quot;,\n  &quot;topic&quot;: &quot;Fundamental Rights and Directive Principles&quot;,\n  &quot;status&quot;: &quot;published&quot;,\n  &quot;access_level&quot;: &quot;enrolled_only&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /study-materials
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/study-materials/1/track-view', '{\n  &quot;platform&quot;: &quot;mobile_app&quot;,\n  &quot;device&quot;: &quot;Flutter Android 14&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /study-materials/1/track-view
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/study-materials/types', '{\n  &quot;name&quot;: &quot;Mains Answer Keys&quot;,\n  &quot;code&quot;: &quot;mains_keys&quot;,\n  &quot;icon&quot;: &quot;file-check&quot;,\n  &quot;badge_color&quot;: &quot;emerald&quot;,\n  &quot;is_public&quot;: true\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /study-materials/types
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/study-materials/1/share-link', '{\n  &quot;expires_in_days&quot;: 7,\n  &quot;password&quot;: &quot;StudySecret2026&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /study-materials/1/share-link
                        </button>
                    @endif

                    <!-- Hostel Management Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'hostel')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/visual-board')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/visual-board
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/buildings')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/buildings
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/rooms/available')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/rooms/available
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/allocations')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/allocations
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/caretakers')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/caretakers
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/catalog')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/catalog
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/hostel/reports/occupancy')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /hostel/reports/occupancy
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/hostel/verify-resident', '{\n  &quot;identifier&quot;: &quot;HST-2026-0001&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 font-mono text-[11px] shadow-xs font-semibold">
                            POST /hostel/verify-resident
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/hostel/allocations/1/transfer', '{\n  &quot;new_hostel_bed_id&quot;: 2,\n  &quot;reason&quot;: &quot;Transferred to ground floor study wing&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            POST /hostel/allocations/1/transfer
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/hostel/beds/1/status', '{\n  &quot;status&quot;: &quot;maintenance&quot;,\n  &quot;notes&quot;: &quot;Room refurbishment and desk repair&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            POST /hostel/beds/1/status
                        </button>
                    @endif

                    <!-- Examination Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'examinations')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/examinations/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            /examinations/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/examinations')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            /examinations
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/examinations/centers')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            /examinations/centers
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/examinations/1/seating-plan')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            /examinations/seating-plan
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/examinations/1/candidates')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            /examinations/candidates
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/examinations/1/admit-card?roll_number=CSCEE-27-KOL-0001')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            /examinations/admit-card
                        </button>
                    @endif

                    <!-- Optional Subjects Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'optional_subjects')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/catalog/optional-subjects')"
                                class="px-2.5 py-1 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400 hover:bg-purple-500/20 font-mono text-[11px] border border-purple-500/20 font-semibold">
                            /catalog/optional-subjects
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/optional-subjects/categories')"
                                class="px-2.5 py-1 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400 hover:bg-purple-500/20 font-mono text-[11px] border border-purple-500/20 font-semibold">
                            /optional-subjects/categories
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/optional-subjects/allocations')"
                                class="px-2.5 py-1 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400 hover:bg-purple-500/20 font-mono text-[11px] border border-purple-500/20 font-semibold">
                            /optional-subjects/allocations
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/student/optional-subject')"
                                class="px-2.5 py-1 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400 hover:bg-purple-500/20 font-mono text-[11px] border border-purple-500/20 font-semibold">
                            /student/optional-subject
                        </button>
                    @endif

                    <!-- Student ID Cards Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'id_cards')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/student-id-cards/settings')"
                                class="px-2.5 py-1 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 hover:bg-blue-500/20 font-mono text-[11px] border border-blue-500/20 font-semibold">
                            /student-id-cards/settings
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/student/id-card/payload')"
                                class="px-2.5 py-1 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 hover:bg-blue-500/20 font-mono text-[11px] border border-blue-500/20 font-semibold">
                            /student/id-card/payload
                        </button>
                    @endif

                    <!-- Library Management Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'library')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/books?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/books
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/copies/ACC-2026-00042')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/copies/:barcode
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/issues?status=issued')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/issues
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/fines?status=pending')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/fines
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/members?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/members
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/library/clearance/check/1')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            /library/clearance/check
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/library/issues', '{\n  &quot;copy_identifier&quot;: &quot;ACC-2026-00042&quot;,\n  &quot;member_identifier&quot;: &quot;LIB-2026-0001&quot;,\n  &quot;loan_days&quot;: 15\n}')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-600 text-white hover:bg-cyan-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /library/issues
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/library/in-house/issue', '{\n  &quot;copy_identifiers&quot;: [&quot;ACC-2026-00018&quot;],\n  &quot;reader_name&quot;: &quot;Aspirant Test&quot;,\n  &quot;seat_no&quot;: &quot;Table 03&quot;,\n  &quot;due_time&quot;: &quot;17:00&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 hover:bg-cyan-500/20 font-mono text-[11px] border border-cyan-500/20 font-semibold">
                            POST /library/in-house/issue
                        </button>
                    @endif

                    <!-- Fee & Payment Collection System Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'payments')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/fee-types')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/fee-types
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/schedules?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/schedules
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/invoices?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/invoices
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/transactions?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/transactions
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/students/1/clearance')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/students/1/clearance
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/payments/library-deposits?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /payments/library-deposits
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/payments/transactions/collect', '{\n  &quot;student_identifier&quot;: &quot;1&quot;,\n  &quot;amount&quot;: 1000.00,\n  &quot;payment_method&quot;: &quot;cash&quot;,\n  &quot;remarks&quot;: &quot;Administrative counter collection&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /payments/transactions/collect
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/payments/checkout/order', '{\n  &quot;invoice_id&quot;: 1,\n  &quot;gateway&quot;: &quot;razorpay&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /payments/checkout/order
                        </button>
                    @endif

                    <!-- Attendance Management Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'attendance')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/attendance/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /attendance/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/attendance/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /attendance/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/attendance?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /attendance
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/attendance/defaulters')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /attendance/defaulters
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/attendance/devices')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /attendance/devices
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/attendance/calendars')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /attendance/calendars
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/attendance/holidays')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            GET /attendance/holidays
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/attendance/mark', '{\n  &quot;user_id&quot;: 1,\n  &quot;date&quot;: &quot;{{ now()->toDateString() }}&quot;,\n  &quot;status&quot;: &quot;present&quot;,\n  &quot;check_in_time&quot;: &quot;09:00:00&quot;,\n  &quot;remarks&quot;: &quot;Marked via API Console&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-teal-600 text-white hover:bg-teal-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /attendance/mark
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/attendance/clock-in', '{\n  &quot;device_name&quot;: &quot;Web Developer Console&quot;,\n  &quot;latitude&quot;: 22.5857,\n  &quot;longitude&quot;: 88.4154\n}')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            POST /attendance/clock-in
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/attendance/punch', '{\n  &quot;identifier&quot;: &quot;1&quot;,\n  &quot;punch_type&quot;: &quot;check_in&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400 hover:bg-teal-500/20 font-mono text-[11px] border border-teal-500/20 font-semibold">
                            POST /attendance/punch
                        </button>
                    @endif

                    <!-- Leave Management Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'leaves')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/leaves/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /leaves/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/leaves/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /leaves/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/leaves/categories')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /leaves/categories
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/leaves/allocations')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /leaves/allocations
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/leaves/applications?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /leaves/applications
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/leaves/my-balances')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /leaves/my-balances
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/leaves/applications', '{\n  &quot;user_id&quot;: 1,\n  &quot;leave_category_id&quot;: 1,\n  &quot;from_date&quot;: &quot;{{ now()->addDays(2)->toDateString() }}&quot;,\n  &quot;to_date&quot;: &quot;{{ now()->addDays(3)->toDateString() }}&quot;,\n  &quot;reason&quot;: &quot;Personal leave application&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /leaves/applications
                        </button>
                    @endif

                    <!-- Users & Access Management (RBAC) Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'users')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/users/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /users/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/users/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /users/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/users?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /users
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/users/1')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /users/1
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/users', '{\n  &quot;name&quot;: &quot;Dr. Soumen Mukherjee&quot;,\n  &quot;email&quot;: &quot;soumen.m@sntcssc.ac.in&quot;,\n  &quot;phone&quot;: &quot;9876543210&quot;,\n  &quot;employee_id&quot;: &quot;FAC-HIST-09&quot;,\n  &quot;staff_department&quot;: &quot;History &amp; Culture&quot;,\n  &quot;status&quot;: &quot;active&quot;,\n  &quot;roles&quot;: [&quot;Faculty&quot;]\n}')"
                                class="px-2.5 py-1 rounded-lg bg-violet-600 text-white hover:bg-violet-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /users
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/users/1/status', '{\n  &quot;status&quot;: &quot;active&quot;,\n  &quot;reason&quot;: &quot;Administrative activation after document verification&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            POST /users/1/status
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/users/1/unlock')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            POST /users/1/unlock
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/users/export')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /users/export
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/roles')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /roles
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/roles', '{\n  &quot;name&quot;: &quot;Academic Evaluator&quot;,\n  &quot;description&quot;: &quot;External examiner with grading and evaluation permissions&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-violet-600 text-white hover:bg-violet-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /roles
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/permissions/modules')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /permissions/modules
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/user/me')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /user/me
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/security/audit-logs?per_page=10')"
                                class="px-2.5 py-1 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 hover:bg-violet-500/20 font-mono text-[11px] border border-violet-500/20 font-semibold">
                            GET /security/audit-logs
                        </button>
                    @endif

                    <!-- Live Chat, WebRTC Calling & Online Meetings Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'chat')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/chat/status')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /chat/status
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/chat/calls/ice-servers')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /chat/calls/ice-servers
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/chat/conversations')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /chat/conversations
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/chat/conversations/1/messages')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /chat/conversations/1/messages
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/chat/conversations/direct', '{\n  &quot;recipient_id&quot;: 2\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /chat/conversations/direct
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/chat/conversations/group', '{\n  &quot;title&quot;: &quot;Mains GS-IV Ethics Study Circle&quot;,\n  &quot;description&quot;: &quot;Case studies and ethical analysis for civil services aspirants&quot;,\n  &quot;participant_ids&quot;: [2, 3]\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /chat/conversations/group
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/chat/conversations/1/messages', '{\n  &quot;body&quot;: &quot;Hello everyone, welcome to the realtime discussion!&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /chat/conversations/1/messages
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/chat/conversations/1/typing', '{\n  &quot;is_typing&quot;: true\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            POST /chat/conversations/1/typing
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/chat/conversations/1/read')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            POST /chat/conversations/1/read
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/chat/calls/initiate', '{\n  &quot;conversation_id&quot;: 1,\n  &quot;call_type&quot;: &quot;video&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /chat/calls/initiate
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/chat/calls/history')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /chat/calls/history
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/chat/meetings')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /chat/meetings
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/chat/meetings', '{\n  &quot;title&quot;: &quot;Civil Services Mentorship Session 2026&quot;,\n  &quot;meeting_type&quot;: &quot;instant&quot;,\n  &quot;waiting_room_enabled&quot;: true,\n  &quot;allow_screen_share&quot;: true\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /chat/meetings
                        </button>
                    @endif

                    <!-- Application System Settings Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'settings')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/public')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/public
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/groups')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/groups
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings?per_page=10')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/groups/general')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/groups/general
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/groups/appearance')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/groups/appearance
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/item/general.site_name')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/item/general.site_name
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/settings/batch', '{\n  &quot;general.site_tagline&quot;: &quot;Shaping future civil servants for India&quot;,\n  &quot;appearance.primary_color&quot;: &quot;#10b981&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /settings/batch
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/settings/cache/flush')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            POST /settings/cache/flush
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/export/bundle')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/export/bundle
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/settings/maintenance', '{\n  &quot;enabled&quot;: false,\n  &quot;message&quot;: &quot;System undergoing scheduled maintenance.&quot;,\n  &quot;allowed_ips&quot;: [&quot;127.0.0.1&quot;]\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /settings/maintenance
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/storage/overview')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/storage/overview
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/settings/test-storage', '{\n  &quot;provider&quot;: &quot;aws&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /settings/test-storage
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/bootstrap/mobile')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/bootstrap/mobile
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/settings/version-check', '{\n  &quot;platform&quot;: &quot;android&quot;,\n  &quot;current_version&quot;: &quot;1.0.0&quot;,\n  &quot;build_number&quot;: 10\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /settings/version-check
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/settings/features')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 font-mono text-[11px] border border-emerald-500/20 font-semibold">
                            GET /settings/features
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/settings/storage/signed-upload-url', '{\n  &quot;filename&quot;: &quot;upsc-mains-syllabus.pdf&quot;,\n  &quot;content_type&quot;: &quot;application/pdf&quot;,\n  &quot;size_bytes&quot;: 1048576,\n  &quot;directory&quot;: &quot;materials&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /settings/storage/signed-upload-url
                        </button>
                    @endif

                    <!-- Support Tickets & Helpdesk Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'tickets')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/my-tickets')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/my-tickets
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/categories')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/categories
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/canned-responses')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/canned-responses
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/1')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/1/messages')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/1/messages
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/tickets', '{\n  &quot;subject&quot;: &quot;Unable to access Mock Test 4&quot;,\n  &quot;description&quot;: &quot;I am enrolled in Batch A and attempting to start the Mock Examination, but the system shows enrollment pending.&quot;,\n  &quot;category_id&quot;: 1,\n  &quot;priority&quot;: &quot;high&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-rose-600 text-white hover:bg-rose-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /tickets
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/tickets/1/messages', '{\n  &quot;message&quot;: &quot;We have verified your enrollment. Access has been restored.&quot;,\n  &quot;type&quot;: &quot;public_reply&quot;,\n  &quot;new_status&quot;: &quot;resolved&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-rose-600 text-white hover:bg-rose-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /tickets/1/messages
                        </button>
                        <button type="button" wire:click="selectPreset('PUT', '/api/v1/tickets/1/status', '{\n  &quot;status&quot;: &quot;resolved&quot;,\n  &quot;reason&quot;: &quot;Resolved after verifying user portal profile.&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-rose-600 text-white hover:bg-rose-700 font-mono text-[11px] shadow-xs font-semibold">
                            PUT /tickets/1/status
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/tickets/1/rate', '{\n  &quot;rating&quot;: 5,\n  &quot;feedback&quot;: &quot;Extremely fast support resolution!&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-rose-600 text-white hover:bg-rose-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /tickets/1/rate
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/tickets/check-sla')"
                                class="px-2.5 py-1 rounded-lg bg-rose-600 text-white hover:bg-rose-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /tickets/check-sla
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/tickets/export')"
                                class="px-2.5 py-1 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400 hover:bg-rose-500/20 font-mono text-[11px] border border-rose-500/20 font-semibold">
                            GET /tickets/export
                        </button>
                    @endif

                    <!-- Communications & Marketing Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'communications')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/send-email', '{\n  &quot;email&quot;: &quot;aspirant@example.com&quot;,\n  &quot;subject&quot;: &quot;UPSC Foundation 2026 Orientation Notice&quot;,\n  &quot;content&quot;: &quot;<p>Dear {name}, welcome to your orientation session.</p>&quot;,\n  &quot;variables&quot;: { &quot;name&quot;: &quot;Arjun Das&quot; }\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/send-email
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/send-sms', '{\n  &quot;phone&quot;: &quot;+919876543210&quot;,\n  &quot;content&quot;: &quot;Dear {name}, Mock Test 4 seat allocated in Hall B. - SNTCSSC&quot;,\n  &quot;dlt_template_id&quot;: &quot;1107161829304918234&quot;,\n  &quot;variables&quot;: { &quot;name&quot;: &quot;Pooja Roy&quot; }\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/send-sms
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/send-bulk', '{\n  &quot;title&quot;: &quot;Mains Answer Writing Broadcast&quot;,\n  &quot;channel&quot;: &quot;email&quot;,\n  &quot;recipient_type&quot;: &quot;all_users&quot;,\n  &quot;subject&quot;: &quot;Live Mains Critique Session&quot;,\n  &quot;content&quot;: &quot;<p>Dear {name}, joining details for today live webinar.</p>&quot;,\n  &quot;immediate&quot;: true\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/send-bulk
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/logs?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/logs
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/logs/1/resend')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/logs/1/resend
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/campaigns?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/campaigns
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/campaigns', '{\n  &quot;title&quot;: &quot;Prelims 2026 Revision Strategy&quot;,\n  &quot;channel&quot;: &quot;email&quot;,\n  &quot;recipient_type&quot;: &quot;all_users&quot;,\n  &quot;subject&quot;: &quot;Prelims Revision Blueprint&quot;,\n  &quot;content&quot;: &quot;<p>Dear {name}, check your study portal for revision module.</p>&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/campaigns
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/campaigns/1/dispatch')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/campaigns/1/dispatch
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/subscribers?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/subscribers
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/subscribers', '{\n  &quot;name&quot;: &quot;Ananya Sen&quot;,\n  &quot;email&quot;: &quot;ananya.sen@example.com&quot;,\n  &quot;phone&quot;: &quot;+919830112233&quot;,\n  &quot;type&quot;: &quot;both&quot;,\n  &quot;source&quot;: &quot;course_inquiry&quot;,\n  &quot;tags&quot;: [&quot;upsc_2026&quot;]\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/subscribers
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/communications/public/subscribe', '{\n  &quot;email&quot;: &quot;public.lead@gmail.com&quot;,\n  &quot;phone&quot;: &quot;+919876543210&quot;,\n  &quot;name&quot;: &quot;Rohan Banerjee&quot;,\n  &quot;source&quot;: &quot;welcome_page&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /communications/public/subscribe
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/templates/email')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/templates/email
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/templates/sms')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/templates/sms
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/communications/export')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /communications/export
                        </button>
                    @endif

                    <!-- Global Search & Viewport Preferences Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'search')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/search/global?q=civil&limit=5')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /search/global?q=civil
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/search/modules')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /search/modules
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/search/preferences')"
                                class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 font-mono text-[11px] border border-primary/20 font-semibold">
                            GET /search/preferences
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/search/preferences', '{\n  &quot;default_fullscreen&quot;: &quot;fullscreen&quot;,\n  &quot;search_history_enabled&quot;: true,\n  &quot;quick_actions_enabled&quot;: true\n}')"
                                class="px-2.5 py-1 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 font-mono text-[11px] shadow-xs font-semibold">
                            POST /search/preferences
                        </button>
                    @endif

                    <!-- UPSC Mock Interview Management Interactive Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'mock_interviews')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/mock-interviews/dashboard')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /mock-interviews/dashboard
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/mock-interviews/analytics')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /mock-interviews/analytics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/mock-interviews/reports?perspective=student_wise')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /mock-interviews/reports
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/mock-interviews/sessions?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /mock-interviews/sessions
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/mock-interviews/sessions', '{\n  &quot;academic_year&quot;: 2026,\n  &quot;session_no&quot;: &quot;SESSION-2026-001&quot;,\n  &quot;session_date&quot;: &quot;2026-11-20&quot;,\n  &quot;start_time&quot;: &quot;09:30:00&quot;,\n  &quot;end_time&quot;: &quot;17:30:00&quot;,\n  &quot;venue_address&quot;: &quot;Main Conference Hall A, SNTCSSC Salt Lake&quot;,\n  &quot;status&quot;: &quot;scheduled&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /mock-interviews/sessions
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/mock-interviews/sessions/1')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /mock-interviews/sessions/1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/mock-interviews/board-members')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /mock-interviews/board-members
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/mock-interviews/candidates/1/evaluate', '{\n  &quot;marks_obtained&quot;: 198,\n  &quot;max_marks&quot;: 275,\n  &quot;feedback&quot;: &quot;Exemplary leadership skills and articulate answers on administrative ethics.&quot;,\n  &quot;status&quot;: &quot;completed&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /mock-interviews/candidates/1/evaluate
                        </button>
                        <button type="button" wire:click="selectPreset('PUT', '/api/v1/mock-interviews/candidates/1/recording', '{\n  &quot;recording_url&quot;: &quot;https://zoom.us/rec/share/sample-mock-interview-session-recording&quot;,\n  &quot;recording_password&quot;: &quot;Mock2026&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            PUT /mock-interviews/candidates/1/recording
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/mock-interviews/sessions/1/templates')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /mock-interviews/sessions/1/templates
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/student/mock-interviews')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /student/mock-interviews
                        </button>
                    @endif

                    <!-- Soft Copies Document Management & Version Control Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'documents')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents?per_page=5')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents/statistics')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents/statistics
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents/categories')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents/categories
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/documents/categories', '{\n  &quot;name&quot;: &quot;Administrative Circulars &amp; Gazettes&quot;,\n  &quot;description&quot;: &quot;Official notifications and circular orders.&quot;,\n  &quot;is_active&quot;: true\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /documents/categories
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents/template')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents/template
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents/export?format=json')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents/export
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents/1')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents/1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents/1/download')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents/1/download
                        </button>
                        <button type="button" wire:click="selectPreset('PUT', '/api/v1/documents/1', '{\n  &quot;title&quot;: &quot;UPSC Civil Services Foundation Course Regulations (Updated)&quot;,\n  &quot;purpose&quot;: &quot;Academic and Administrative Guidelines for 2026-2027&quot;,\n  &quot;note&quot;: &quot;Updated guidelines for all students and faculties.&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            PUT /documents/1
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/documents/1/versions')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-500/20 font-mono text-[11px] border border-indigo-500/20 font-semibold">
                            GET /documents/1/versions
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/documents/1/versions/1/set-current')"
                                class="px-2.5 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /documents/1/versions/1/set-current
                        </button>
                    @endif

                    <!-- Bot Defense & CAPTCHA System Presets -->
                    @if($sandboxCategoryFilter === 'all' || $sandboxCategoryFilter === 'captcha')
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/captcha/specs')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /captcha/specs
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/captcha/generate?action=login')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /captcha/generate
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/captcha/verify', '{\n  &quot;action&quot;: &quot;api&quot;,\n  &quot;token&quot;: &quot;sample_test_token&quot;,\n  &quot;answer&quot;: &quot;7B9KX&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /captcha/verify
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/captcha/refresh', '{\n  &quot;action&quot;: &quot;login&quot;,\n  &quot;old_token&quot;: &quot;sample_test_token&quot;\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /captcha/refresh
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/captcha/audio/sample_test_token?format=json')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /captcha/audio/{token}
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/captcha/status')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /captcha/status
                        </button>
                        <button type="button" wire:click="selectPreset('GET', '/api/v1/captcha/diagnostics')"
                                class="px-2.5 py-1 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 hover:bg-amber-500/20 font-mono text-[11px] border border-amber-500/20 font-semibold">
                            GET /captcha/diagnostics
                        </button>
                        <button type="button" wire:click="selectPreset('POST', '/api/v1/captcha/logs/prune', '{\n  &quot;days&quot;: 30,\n  &quot;force&quot;: false\n}')"
                                class="px-2.5 py-1 rounded-lg bg-amber-600 text-white hover:bg-amber-700 font-mono text-[11px] shadow-xs font-semibold">
                            POST /captcha/logs/prune
                        </button>
                    @endif
                </div>
            </div>

            @if(in_array($sandboxMethod, ['POST', 'PUT', 'PATCH', 'DELETE']))
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Request Body (JSON)') }}</label>
                    <textarea wire:model="sandboxPayload" rows="3"
                              class="w-full px-3 py-2 text-xs font-mono rounded-xl border border-border bg-background text-foreground focus:outline-none"></textarea>
                </div>
            @endif

            <!-- Sandbox Results Display -->
            @if($sandboxResponse !== null)
                <div class="mt-4 pt-4 border-t border-border space-y-3">
                    <div class="flex items-center justify-between text-xs">
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-foreground">{{ __('HTTP Response:') }}</span>
                            <span class="px-2.5 py-0.5 rounded-full font-mono font-bold text-xs {{ $sandboxStatusCode >= 200 && $sandboxStatusCode < 300 ? 'bg-emerald-500/10 text-emerald-600' : 'bg-destructive/10 text-destructive' }}">
                                {{ $sandboxStatusCode }}
                            </span>
                            @if($sandboxDurationMs !== null)
                                <span class="text-muted-foreground font-mono text-[11px]">⏱ {{ $sandboxDurationMs }} ms</span>
                            @endif
                        </div>

                        @if($sandboxHeaders)
                            <div class="hidden sm:flex items-center gap-2 text-muted-foreground text-[11px] font-mono">
                                <span>Limit: {{ $sandboxHeaders['rate_limit_limit'] }}</span>
                                <span>•</span>
                                <span>Remaining: {{ $sandboxHeaders['rate_limit_remaining'] }}</span>
                            </div>
                        @endif
                    </div>

                    <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-xs overflow-x-auto max-h-80 border border-border leading-relaxed select-all"><code>{{ json_encode($sandboxResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                </div>
            @endif
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- SECTION 2: MULTI-PLATFORM CODE SNIPPETS                        -->
    <!-- ============================================================== -->
    <div class="bg-card rounded-2xl border border-border shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-border bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-foreground">{{ __('Integration Code Snippets') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Ready-to-use boilerplate for mobile apps, desktop clients, and backend scripts.') }}</p>
            </div>

            <!-- Tab Buttons -->
            <div class="flex flex-wrap items-center gap-1.5 p-1 bg-muted/60 rounded-xl border border-border text-xs">
                <button type="button" wire:click="$set('activeSnippetTab', 'flutter')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $activeSnippetTab === 'flutter' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    Flutter (Dart)
                </button>
                <button type="button" wire:click="$set('activeSnippetTab', 'react_native')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $activeSnippetTab === 'react_native' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    React Native (JS)
                </button>
                <button type="button" wire:click="$set('activeSnippetTab', 'electron')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $activeSnippetTab === 'electron' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    Electron / Node
                </button>
                <button type="button" wire:click="$set('activeSnippetTab', 'csharp')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $activeSnippetTab === 'csharp' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    C# (.NET)
                </button>
                <button type="button" wire:click="$set('activeSnippetTab', 'python')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $activeSnippetTab === 'python' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    Python
                </button>
                <button type="button" wire:click="$set('activeSnippetTab', 'curl')"
                        class="px-3 py-1.5 rounded-lg font-medium transition-colors {{ $activeSnippetTab === 'curl' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    cURL
                </button>
            </div>
        </div>

        <div class="p-6">
            @php
                $baseUrl = url('/api/v1');
            @endphp

            <!-- FLUTTER DART -->
            @if($activeSnippetTab === 'flutter')
                <div class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('Mobile application client implementation using the standard ') }}<code class="font-mono text-primary font-semibold">http</code>{{ __(' Dart package:') }}
                    </p>
                    <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-xs overflow-x-auto border border-border leading-relaxed"><code>import 'dart:convert';
import 'package:http/http.dart' as http;

class SntcsscApiClient {
  static const String baseUrl = '{{ $baseUrl }}';
  final String apiKey;

  SntcsscApiClient({required this.apiKey});

  // Diagnostic ping
  Future&lt;Map&lt;String, dynamic&gt;&gt; ping() async {
    final response = await http.get(
      Uri.parse('$baseUrl/ping'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    return jsonDecode(response.body);
  }

  // 1. Hostel: Live Visual Room Board (Buildings -> Floors -> Rooms -> Beds)
  Future&lt;List&lt;dynamic&gt;&gt; getHostelVisualBoard({int? buildingId}) async {
    final query = buildingId != null ? '?building_id=$buildingId' : '';
    final response = await http.get(
      Uri.parse('$baseUrl/hostel/visual-board$query'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    final data = jsonDecode(response.body);
    return data['data'];
  }

  // 2. Hostel: Gatekeeper Barcode/URN Resident Verification (IoT & Kiosks)
  Future&lt;Map&lt;String, dynamic&gt;&gt; verifyResident({String? barcode, String? urn}) async {
    final response = await http.post(
      Uri.parse('$baseUrl/hostel/verify-resident'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({
        if (barcode != null) 'barcode': barcode,
        if (urn != null) 'urn': urn,
      }),
    );
    return jsonDecode(response.body);
  }

  // 3. Hostel: Inter-Bed Resident Transfer
  Future&lt;Map&lt;String, dynamic&gt;&gt; transferResident({
    required int allocationId,
    required int targetBedId,
    String? reason,
  }) async {
    final response = await http.post(
      Uri.parse('$baseUrl/hostel/allocations/$allocationId/transfer'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({
        'target_bed_id': targetBedId,
        'reason': reason ?? 'Relocation requested',
      }),
    );
    return jsonDecode(response.body);
  }

  // 4. Dynamic Study Materials: Personalized Feed
  Future&lt;List&lt;dynamic&gt;&gt; getMyStudyMaterials({String? search, String? type}) async {
    final query = search != null ? '?search=$search' : '';
    final response = await http.get(
      Uri.parse('$baseUrl/study-materials/my-materials$query'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    final data = jsonDecode(response.body);
    return data['data'];
  }

  // 5. Dynamic Study Materials: Signed Stream Token & Reading Telemetry
  Future&lt;String&gt; getMaterialDownloadToken(int materialId) async {
    final response = await http.get(
      Uri.parse('$baseUrl/study-materials/$materialId/download-token'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    final data = jsonDecode(response.body);
    return data['data']['download_url'];
  }

  Future&lt;void&gt; trackMaterialView(int materialId, {int? durationSeconds}) async {
    await http.post(
      Uri.parse('$baseUrl/study-materials/$materialId/track-view'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({'duration_seconds': durationSeconds ?? 60}),
    );
  }

  // 6. Examination Module: Invigilator QR Code Attendance Scanner
  Future&lt;Map&lt;String, dynamic&gt;&gt; markExamAttendance({
    required int examId,
    required String rollNumber,
    required String status, // 'present', 'absent', 'disqualified'
  }) async {
    final response = await http.post(
      Uri.parse('$baseUrl/examinations/$examId/attendance'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({
        'roll_number': rollNumber,
        'attendance_status': status,
        'remarks': 'Verified via Mobile QR Camera Scanner',
      }),
    );
    final data = jsonDecode(response.body);
    return data['data'];
  }

  // 7. Library Management: Barcode Scan & Quick Issue/Loan
  Future&lt;Map&lt;String, dynamic&gt;&gt; issueBookCopy({
    required String barcode,
    required int libraryMemberId,
    int? days,
  }) async {
    final response = await http.post(
      Uri.parse('$baseUrl/library/issues'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({
        'barcode': barcode,
        'library_member_id': libraryMemberId,
        'days': days ?? 14,
      }),
    );
    return jsonDecode(response.body);
  }

  // 8. Library Management: Physical Accession Barcode Search
  Future&lt;Map&lt;String, dynamic&gt;&gt; lookupBookByBarcode(String barcode) async {
    final response = await http.get(
      Uri.parse('$baseUrl/library/copies/$barcode'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    return jsonDecode(response.body);
  }

  // 9. Student Management: Instant Turnstile &amp; Scanner Identity Verification
  Future&lt;Map&lt;String, dynamic&gt;&gt; verifyStudentIdentity(String identifier) async {
    final response = await http.post(
      Uri.parse('$baseUrl/students/verify-identity'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({'identifier': identifier}),
    );
    return jsonDecode(response.body);
  }

  // 10. Student Management: Candidate Admission into Course &amp; Batch
  Future&lt;Map&lt;String, dynamic&gt;&gt; enrollCandidate({
    required String studentIdentifier,
    required int courseId,
    required int batchId,
    int? sectionId,
  }) async {
    final response = await http.post(
      Uri.parse('$baseUrl/enrollments'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({
        'student_identifier': studentIdentifier,
        'course_id': courseId,
        'batch_id': batchId,
        'section_id': sectionId,
        'fee_status': 'paid',
      }),
    );
    return jsonDecode(response.body);
  }

  // 11. Academics: Public Course Catalog & Offering Details
  Future&lt;List&lt;dynamic&gt;&gt; getCourseCatalog({String? search}) async {
    final query = search != null ? '?search=$search' : '';
    final response = await http.get(
      Uri.parse('$baseUrl/academics/catalog$query'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    final data = jsonDecode(response.body);
    return data['data'];
  }

  // 12. Academics: Real-Time Capacity & Dashboard Telemetry
  Future&lt;Map&lt;String, dynamic&gt;&gt; getAcademicDashboard() async {
    final response = await http.get(
      Uri.parse('$baseUrl/academics/dashboard'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    final data = jsonDecode(response.body);
    return data['data'];
  }

  // 13. Fees & Payments: Counter Manual Collection & Instant Receipt
  Future&lt;Map&lt;String, dynamic&gt;&gt; collectCounterPayment({
    required String studentIdentifier,
    required double amount,
    required String paymentMethod,
    int? invoiceId,
    String? remarks,
  }) async {
    final response = await http.post(
      Uri.parse('$baseUrl/payments/transactions/collect'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({
        'student_identifier': studentIdentifier,
        'amount': amount,
        'payment_method': paymentMethod,
        if (invoiceId != null) 'invoice_id': invoiceId,
        if (remarks != null) 'remarks': remarks,
      }),
    );
    return jsonDecode(response.body);
  }

  // 14. Fees & Payments: Institutional Financial Clearance Verification
  Future&lt;Map&lt;String, dynamic&gt;&gt; checkStudentClearance(String studentId) async {
    final response = await http.get(
      Uri.parse('$baseUrl/payments/students/$studentId/clearance'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    final data = jsonDecode(response.body);
    return data['data'];
  }

  // 14. Attendance: Mobile Clock-In with GPS Coordinates
  Future&lt;Map&lt;String, dynamic&gt;&gt; clockIn({double? latitude, double? longitude}) async {
    final response = await http.post(
      Uri.parse('$baseUrl/attendance/clock-in'),
      headers: {'X-API-Key': apiKey, 'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: jsonEncode({'latitude': latitude, 'longitude': longitude, 'device_name': 'Flutter Mobile App'}),
    );
    return jsonDecode(response.body);
  }

  // 15. Leave Management: Submit Leave Application
  Future&lt;Map&lt;String, dynamic&gt;&gt; applyForLeave({
    required int categoryId,
    required String fromDate,
    required String toDate,
    required String reason,
  }) async {
    final response = await http.post(
      Uri.parse('$baseUrl/leaves/apply'),
      headers: {'X-API-Key': apiKey, 'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: jsonEncode({
        'leave_category_id': categoryId,
        'from_date': fromDate,
        'to_date': toDate,
        'reason': reason,
      }),
    );
    return jsonDecode(response.body);
  }

  // 16. Users & Access Management: Fetch Current User Profile & RBAC Matrix
  Future&lt;Map&lt;String, dynamic&gt;&gt; getCurrentUser() async {
    final response = await http.get(
      Uri.parse('$baseUrl/user/me'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    return jsonDecode(response.body);
  }

  // 17. Users & Access Management: Query Directory & Unlock User
  Future&lt;Map&lt;String, dynamic&gt;&gt; getUsers({String? search, String? role, int page = 1}) async {
    final query = [
      if (search != null) 'search=${Uri.encodeComponent(search)}',
      if (role != null) 'role=${Uri.encodeComponent(role)}',
      'page=$page',
    ].join('&');
    final response = await http.get(
      Uri.parse('$baseUrl/users?$query'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    return jsonDecode(response.body);
  }

  Future&lt;Map&lt;String, dynamic&gt;&gt; unlockUser(dynamic identifier) async {
    final response = await http.post(
      Uri.parse('$baseUrl/users/$identifier/unlock'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    return jsonDecode(response.body);
  }

  // 18. Live Chat: Get Active Conversations & Messages
  Future&lt;List&lt;dynamic&gt;&gt; getChatConversations() async {
    final response = await http.get(
      Uri.parse('$baseUrl/chat/conversations'),
      headers: {'X-API-Key': apiKey, 'Accept': 'application/json'},
    );
    final data = jsonDecode(response.body);
    return data['data'];
  }

  Future&lt;Map&lt;String, dynamic&gt;&gt; sendChatMessage(int conversationId, String message) async {
    final response = await http.post(
      Uri.parse('$baseUrl/chat/conversations/$conversationId/messages'),
      headers: {'X-API-Key': apiKey, 'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: jsonEncode({'body': message}),
    );
    return jsonDecode(response.body)['data'];
  }

  // 19. WebRTC Calls: Initiate Call & Exchange Signals
  Future&lt;Map&lt;String, dynamic&gt;&gt; initiateWebRtcCall(int conversationId, {String type = 'video'}) async {
    final response = await http.post(
      Uri.parse('$baseUrl/chat/calls/initiate'),
      headers: {'X-API-Key': apiKey, 'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: jsonEncode({'conversation_id': conversationId, 'call_type': type}),
    );
    return jsonDecode(response.body)['data'];
  }

  // 20. Online Meetings: Join Video Conference
  Future&lt;Map&lt;String, dynamic&gt;&gt; joinOnlineMeeting(String uuid, {String? passcode}) async {
    final response = await http.post(
      Uri.parse('$baseUrl/chat/meetings/$uuid/join'),
      headers: {'X-API-Key': apiKey, 'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: jsonEncode({'passcode': passcode}),
    );
    return jsonDecode(response.body)['data'];
  }
}</code></pre>
                </div>

            <!-- REACT NATIVE -->
            @elseif($activeSnippetTab === 'react_native')
                <div class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('Cross-platform Mobile application client implementation using standard ') }}<code class="font-mono text-primary font-semibold">fetch</code>{{ __(' or Axios:') }}
                    </p>
                    <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-xs overflow-x-auto border border-border leading-relaxed"><code>const API_BASE = '{{ $baseUrl }}';
const API_KEY = 'sntc_live_YOUR_GENERATED_SECRET_KEY';

// 1. Hostel: Fetch Live Visual Room Board
export async function fetchHostelVisualBoard(buildingId = null) {
  const url = buildingId
    ? `${API_BASE}/hostel/visual-board?building_id=${buildingId}`
    : `${API_BASE}/hostel/visual-board`;

  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'X-API-Key': API_KEY,
      'Accept': 'application/json',
    },
  });
  const result = await response.json();
  return result.data;
}

// 2. Hostel: Gatekeeper Scanner Verification (ID Barcode or URN)
export async function verifyHostelResident(barcodeOrUrn) {
  const isBarcode = barcodeOrUrn.startsWith('BC') || barcodeOrUrn.length > 12;
  const response = await fetch(`${API_BASE}/hostel/verify-resident`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(isBarcode ? { barcode: barcodeOrUrn } : { urn: barcodeOrUrn }),
  });
  return await response.json();
}

// 3. Hostel: Transfer Resident Bed
export async function transferResidentBed(allocationId, targetBedId, reason = 'Room relocation requested') {
  const response = await fetch(`${API_BASE}/hostel/allocations/${allocationId}/transfer`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ target_bed_id: targetBedId, reason }),
  });
  return await response.json();
}

// 4. Dynamic Study Materials: Fetch Stream Download Token
export async function getMaterialDownloadToken(materialId) {
  const response = await fetch(`${API_BASE}/study-materials/${materialId}/download-token`, {
    method: 'GET',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  return await response.json();
}

// 5. Dynamic Study Materials: Report Reading Telemetry
export async function trackMaterialReading(materialId, durationSeconds = 120) {
  return await fetch(`${API_BASE}/study-materials/${materialId}/track-view`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ duration_seconds: durationSeconds }),
  });
}

// 6. Examination Module: Fetch Student Digital Admit Card
export async function getCandidateAdmitCard(examId, rollNumber) {
  const response = await fetch(`${API_BASE}/examinations/${examId}/admit-card?roll_number=${rollNumber}`, {
    method: 'GET',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  return await response.json();
}

// 7. Library Management: Fast Barcode/RFID Scan Loan Issue
export async function issueBookCopy(barcode, libraryMemberId, days = 14) {
  const response = await fetch(`${API_BASE}/library/issues`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ barcode, library_member_id: libraryMemberId, days }),
  });
  return await response.json();
}

// 8. Library Management: Book Copy Return
export async function returnBookCopy(issueId, remarks = 'Returned at circulation desk') {
  const response = await fetch(`${API_BASE}/library/issues/${issueId}/return`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ remarks }),
  });
  return await response.json();
}

// 9. Library Management: Student Self-Service Current Loans
export async function getMyLibraryLoans() {
  const response = await fetch(`${API_BASE}/library/my-loans`, {
    method: 'GET',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  return await response.json();
}

// 10. Student Management: Instant Kiosk / Turnstile Identity Verification
export async function verifyStudentIdentity(identifier) {
  const response = await fetch(`${API_BASE}/students/verify-identity`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ identifier }),
  });
  return await response.json();
}

// 11. Student Management: Candidate Course Admission
export async function enrollCandidate(studentIdentifier, courseId, batchId, sectionId = null) {
  const response = await fetch(`${API_BASE}/enrollments`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      student_identifier: studentIdentifier,
      course_id: courseId,
      batch_id: batchId,
      section_id: sectionId,
      fee_status: 'paid',
    }),
  });
  return await response.json();
}

// 11. Academics: Public Course Catalog
export async function fetchCourseCatalog(search = null) {
  const url = search ? `${API_BASE}/academics/catalog?search=${encodeURIComponent(search)}` : `${API_BASE}/academics/catalog`;
  const response = await fetch(url, {
    method: 'GET',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  const result = await response.json();
  return result.data;
}

// 12. Academics: Executive Telemetry & Capacity Stats
export async function fetchAcademicStats() {
  const response = await fetch(`${API_BASE}/academics/statistics`, {
    method: 'GET',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  const result = await response.json();
  return result.data;
}

// 13. Fees & Payments: Direct Counter Fee Collection
export async function collectCounterPayment(paymentData) {
  const response = await fetch(`${API_BASE}/payments/transactions/collect`, {
    method: 'POST',
    headers: {
      'X-API-Key': API_KEY,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(paymentData),
  });
  return await response.json();
}

// 14. Fees & Payments: Multi-Department Institutional Clearance
export async function checkStudentClearance(studentId) {
  const response = await fetch(`${API_BASE}/payments/students/${studentId}/clearance`, {
    method: 'GET',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  const result = await response.json();
  return result.data;
}

// 15. Attendance: Mobile Clock-Out
export async function clockOut() {
  const response = await fetch(`${API_BASE}/attendance/clock-out`, {
    method: 'POST',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  return await response.json();
}

// 16. Leave Management: Fetch Personal Leave Balances
export async function fetchMyLeaveBalances(year = null) {
  const url = year ? `${API_BASE}/leaves/my-balances?year=${year}` : `${API_BASE}/leaves/my-balances`;
  const response = await fetch(url, {
    method: 'GET',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  const result = await response.json();
  return result.data;
}

// 17. Users & Access Management: Self Profile & User Directory
export async function fetchCurrentUser() {
  const response = await fetch(`${API_BASE}/user/me`, {
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  return await response.json();
}

export async function fetchUsersList(page = 1, search = '') {
  const query = new URLSearchParams({ page: String(page), ...(search ? { search } : {}) });
  const response = await fetch(`${API_BASE}/users?${query}`, {
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  return await response.json();
}

export async function unlockUserAccount(identifier) {
  const response = await fetch(`${API_BASE}/users/${identifier}/unlock`, {
    method: 'POST',
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  return await response.json();
}

// 18. Live Chat & Calls: Fetch Conversations & Send Message
export async function fetchChatConversations() {
  const response = await fetch(`${API_BASE}/chat/conversations`, {
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  const result = await response.json();
  return result.data;
}

export async function sendChatMessage(conversationId, body) {
  const response = await fetch(`${API_BASE}/chat/conversations/${conversationId}/messages`, {
    method: 'POST',
    headers: { 'X-API-Key': API_KEY, 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify({ body }),
  });
  return await response.json();
}

// 19. WebRTC Calls: Initiate Call & ICE Servers
export async function getIceServers() {
  const response = await fetch(`${API_BASE}/chat/calls/ice-servers`, {
    headers: { 'X-API-Key': API_KEY, 'Accept': 'application/json' },
  });
  const result = await response.json();
  return result.data.ice_servers;
}</code></pre>
                </div>

            <!-- ELECTRON / NODE -->
            @elseif($activeSnippetTab === 'electron')
                <div class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('Desktop application client for Windows, macOS, or Linux using Electron or Node.js:') }}
                    </p>
                    <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-xs overflow-x-auto border border-border leading-relaxed"><code>const axios = require('axios');

const sntcsscApi = axios.create({
  baseURL: '{{ $baseUrl }}',
  headers: {
    'X-API-Key': process.env.SNTCSSC_API_KEY,
    'Accept': 'application/json',
  },
  timeout: 10000,
});

// 1. Get Live Hostel Dashboard & Capacity
async function getHostelOverview() {
  const { data } = await sntcsscApi.get('/hostel/dashboard');
  return data.data;
}

// 2. Hostel Gatekeeper Turnstile: Verify Resident Barcode
async function verifyGateResident(barcode) {
  const { data } = await sntcsscApi.post('/hostel/verify-resident', { barcode });
  return data;
}

// 3. Bed Maintenance: Update Bed Status
async function updateBedStatus(bedId, status, notes) {
  const { data } = await sntcsscApi.post(`/hostel/beds/${bedId}/status`, { status, notes });
  return data;
}

// 4. Vacate a resident and release bed
async function vacateResident(allocationId, vacateDate, reason) {
  const { data } = await sntcsscApi.post(`/hostel/allocations/${allocationId}/vacate`, {
    vacate_date: vacateDate,
    reason: reason,
  });
  return data;
}

// 5. Dynamic Study Materials: Cache Synchronization
async function syncOfflineStudyMaterials() {
  const { data: materials } = await sntcsscApi.get('/study-materials', {
    params: { is_active: 1, per_page: 100 },
  });
  return materials.data;
}

// 6. Library Management: Physical Barcode Lookup (USB/HID Barcode Scanner)
async function lookupLibraryCopy(barcode) {
  const { data } = await sntcsscApi.get(`/library/copies/${encodeURIComponent(barcode)}`);
  return data.data;
}

// 7. Library Management: In-House Reading Room Fast Checkout
async function issueInHouseReading(copyBarcode, libraryMemberId) {
  const { data } = await sntcsscApi.post('/library/in-house/issue', {
    barcode: copyBarcode,
    library_member_id: libraryMemberId,
    purpose: 'Daily reference reading',
  });
  return data;
}

// 8. Library Management: Batch Synchronize OPAC Catalog
async function syncOfflineOpacCatalog() {
  const { data } = await sntcsscApi.get('/library/catalog', {
    params: { per_page: 250 },
  });
  return data.data;
}

// 9. Student Management: Scanner Identity Verification
async function verifyStudentIdentity(barcodeOrId) {
  const { data } = await sntcsscApi.post('/students/verify-identity', {
    identifier: barcodeOrId,
  });
  return data.data;
}

// 10. Student Management: Candidate Course Admission
async function enrollCandidate(studentIdentifier, courseId, batchId, sectionId = null) {
  const { data } = await sntcsscApi.post('/enrollments', {
    student_identifier: studentIdentifier,
    course_id: courseId,
    batch_id: batchId,
    section_id: sectionId,
    fee_status: 'paid',
  });
  return data.data;
}

// 12. Academics: Course Catalog & Academic Calendar
async function fetchCourseCatalog(search = null) {
  const { data } = await sntcsscApi.get('/academics/catalog', { params: { search } });
  return data.data;
}

async function fetchAcademicCalendar(year = null) {
  const { data } = await sntcsscApi.get('/academics/calendar', { params: { year } });
  return data.data;
}

// 13. Fees & Payments: Direct POS Collection & Instant Receipt
async function collectPosPayment(paymentData) {
  const { data } = await sntcsscApi.post('/payments/transactions/collect', paymentData);
  return data.data;
}

// 14. Fees & Payments: Student Clearance Check
async function verifyStudentClearance(studentId) {
  const { data } = await sntcsscApi.get(`/payments/students/${studentId}/clearance`);
  return data.data;
}

// 15. Users & Access: User Directory & Role Assignment
async function fetchUserDirectory(params = {}) {
  const { data } = await sntcsscApi.get('/users', { params });
  return data.data;
}

async function createUserAccount(userPayload) {
  const { data } = await sntcsscApi.post('/users', userPayload);
  return data.data;
}

async function syncUserRoles(userId, roles) {
  const { data } = await sntcsscApi.post(`/users/${userId}/sync-roles`, { roles });
  return data.data;
}

// 16. Live Chat & Desktop Calls: Service Status & ICE Server Config
async function getChatStatus() {
  const { data } = await sntcsscApi.get('/chat/status');
  return data.data;
}

async function getIceServers() {
  const { data } = await sntcsscApi.get('/chat/calls/ice-servers');
  return data.data.ice_servers;
}

module.exports = {
  sntcsscApi,
  getHostelOverview,
  verifyGateResident,
  updateBedStatus,
  vacateResident,
  syncOfflineStudyMaterials,
  lookupLibraryCopy,
  issueInHouseReading,
  syncOfflineOpacCatalog,
  verifyStudentIdentity,
  enrollCandidate,
  fetchCourseCatalog,
  fetchAcademicCalendar,
  collectPosPayment,
  verifyStudentClearance,
  fetchUserDirectory,
  createUserAccount,
  syncUserRoles,
  getChatStatus,
  getIceServers,
};</code></pre>
                </div>

            <!-- C# .NET -->
            @elseif($activeSnippetTab === 'csharp')
                <div class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('Windows Desktop Application (.NET 8+ / C#) using HttpClient:') }}
                    </p>
                    <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-xs overflow-x-auto border border-border leading-relaxed"><code>using System;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

public class SntcsscApiClient
{
    private static readonly HttpClient client = new HttpClient();
    private const string BaseUrl = "{{ $baseUrl }}";

    // 1. Query Real-Time Hostel Occupancy Analytics
    public static async Task&lt;string&gt; GetOccupancyReport(string apiKey)
    {
        var request = new HttpRequestMessage(HttpMethod.Get, $"{BaseUrl}/hostel/reports/occupancy");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        response.EnsureSuccessStatusCode();
        return await response.Content.ReadAsStringAsync();
    }

    // 2. Query Live Visual Room Board Tree
    public static async Task&lt;string&gt; GetVisualBoard(string apiKey, int? buildingId = null)
    {
        string endpoint = buildingId.HasValue ? $"{BaseUrl}/hostel/visual-board?building_id={buildingId}" : $"{BaseUrl}/hostel/visual-board";
        var request = new HttpRequestMessage(HttpMethod.Get, endpoint);
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 3. Hostel Gate: Turnstile Barcode Verification (Kiosk / Scanner)
    public static async Task&lt;string&gt; VerifyResident(string apiKey, string barcode)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, $"{BaseUrl}/hostel/verify-resident");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");
        request.Content = new StringContent(
            JsonSerializer.Serialize(new { barcode = barcode }),
            Encoding.UTF8,
            "application/json"
        );

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 4. Dynamic Study Materials: Catalog Query
    public static async Task&lt;string&gt; GetStudyMaterialsCatalog(string apiKey, string search = null)
    {
        string endpoint = string.IsNullOrEmpty(search)
            ? $"{BaseUrl}/study-materials"
            : $"{BaseUrl}/study-materials?search={Uri.EscapeDataString(search)}";

        var request = new HttpRequestMessage(HttpMethod.Get, endpoint);
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 5. Library Management: Hardware RFID / Barcode Scanner Fast Loan
    public static async Task&lt;string&gt; IssueBookBarcode(string apiKey, string barcode, int memberId, int days = 14)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, $"{BaseUrl}/library/issues");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");
        request.Content = new StringContent(
            JsonSerializer.Serialize(new { barcode = barcode, library_member_id = memberId, days = days }),
            Encoding.UTF8,
            "application/json"
        );

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 6. Library Management: Check Member Clearance Eligibility
    public static async Task&lt;string&gt; CheckLibraryClearance(string apiKey, int memberId)
    {
        var request = new HttpRequestMessage(HttpMethod.Get, $"{BaseUrl}/library/clearance/check/{memberId}");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 7. Student Management: Campus Turnstile &amp; Gatekeeper Scanner Verification
    public static async Task&lt;string&gt; VerifyStudentIdentity(string apiKey, string identifier)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, $"{BaseUrl}/students/verify-identity");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");
        request.Content = new StringContent(
            JsonSerializer.Serialize(new { identifier = identifier }),
            Encoding.UTF8,
            "application/json"
        );

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 8. Academics: Course Catalog & Academic Calendar
    public static async Task&lt;string&gt; GetCourseCatalog(string apiKey, string search = null)
    {
        var url = string.IsNullOrEmpty(search) ? $"{BaseUrl}/academics/catalog" : $"{BaseUrl}/academics/catalog?search={search}";
        var request = new HttpRequestMessage(HttpMethod.Get, url);
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 9. Fees & Payments: Direct Counter POS Collection
    public static async Task&lt;string&gt; CollectPayment(string apiKey, string studentId, decimal amount, string method = "pos_card")
    {
        var payload = JsonSerializer.Serialize(new
        {
            student_identifier = studentId,
            amount = amount,
            payment_method = method,
            remarks = "Collected via POS Desktop Terminal"
        });

        var request = new HttpRequestMessage(HttpMethod.Post, $"{BaseUrl}/payments/transactions/collect")
        {
            Content = new StringContent(payload, Encoding.UTF8, "application/json")
        };
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 10. Fees & Payments: Institutional Financial Clearance Check
    public static async Task&lt;string&gt; CheckClearance(string apiKey, string studentId)
    {
        var request = new HttpRequestMessage(HttpMethod.Get, $"{BaseUrl}/payments/students/{studentId}/clearance");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 11. Attendance: Gate Turnstile Sub-Second Punch Ingestion
    public static async Task&lt;string&gt; RecordGatePunch(string apiKey, string scannedCardOrPin)
    {
        var payload = JsonSerializer.Serialize(new
        {
            identifier = scannedCardOrPin,
            punch_type = "check_in"
        });

        var request = new HttpRequestMessage(HttpMethod.Post, $"{BaseUrl}/attendance/punch")
        {
            Content = new StringContent(payload, Encoding.UTF8, "application/json")
        };
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    // 12. Users & Access: User Directory Search & Account Unlock
    public static async Task&lt;string&gt; GetUsers(string apiKey, string search = "")
    {
        var request = new HttpRequestMessage(HttpMethod.Get, $"{BaseUrl}/users?search={Uri.EscapeDataString(search)}");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }

    public static async Task&lt;string&gt; UnlockUser(string apiKey, string identifier)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, $"{BaseUrl}/users/{identifier}/unlock");
        request.Headers.Add("X-API-Key", apiKey);
        request.Headers.Add("Accept", "application/json");

        HttpResponseMessage response = await client.SendAsync(request);
        return await response.Content.ReadAsStringAsync();
    }
}</code></pre>
                </div>

            <!-- PYTHON -->
            @elseif($activeSnippetTab === 'python')
                <div class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('Python client for automated synchronizations, scripts, and microservices:') }}
                    </p>
                    <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-xs overflow-x-auto border border-border leading-relaxed"><code>import requests

BASE_URL = "{{ $baseUrl }}"
API_KEY = "sntc_live_YOUR_GENERATED_SECRET_KEY"

session = requests.Session()
session.headers.update({
    "X-API-Key": API_KEY,
    "Accept": "application/json",
})

# 1. Fetch Real-Time Visual Room Board &amp; Occupancy
board_res = session.get(f"{BASE_URL}/hostel/visual-board")
board = board_res.json()
print("Buildings Found:", len(board.get("data", [])))

occupancy = session.get(f"{BASE_URL}/hostel/reports/occupancy").json()
print("Total Capacity:", occupancy['data']['total_capacity'], "| Occupied:", occupancy['data']['total_occupied'])

# 2. Hostel Gatekeeper: Verify Student at Turnstile / Barcode Scanner
def verify_resident(barcode=None, urn=None):
    payload = {}
    if barcode:
        payload["barcode"] = barcode
    elif urn:
        payload["urn"] = urn
    resp = session.post(f"{BASE_URL}/hostel/verify-resident", json=payload)
    return resp.json()

# 3. Dynamic Study Materials: Batch Downloader &amp; Telemetry
def fetch_and_track_material(material_id: int):
    token_resp = session.get(f"{BASE_URL}/study-materials/{material_id}/download-token").json()
    download_url = token_resp["data"]["download_url"]
    file_bytes = session.get(download_url).content
    print(f"Downloaded Material #{material_id}: {len(file_bytes)} bytes")

    # Record telemetry
    session.post(
        f"{BASE_URL}/study-materials/{material_id}/track-view",
        json={"duration_seconds": 180}
    )

# 4. Library Management: Barcode Scanner Loan & Circulation Return
def issue_library_book(barcode: str, member_id: int, days: int = 14):
    resp = session.post(
        f"{BASE_URL}/library/issues",
        json={"barcode": barcode, "library_member_id": member_id, "days": days}
    )
    return resp.json()

def return_library_book(issue_id: int, condition: str = "good"):
    resp = session.post(
        f"{BASE_URL}/library/issues/{issue_id}/return",
        json={"condition_on_return": condition, "remarks": "Returned via self-service kiosk"}
    )
    return resp.json()

# 5. Library Management: Real-Time Statistics
library_stats = session.get(f"{BASE_URL}/library/statistics").json()
print("Library Stats:", library_stats.get("data", {}))

# 6. Student Management: Instant Scanner Verification &amp; Admissions
def verify_student_identity(identifier: str):
    return session.post(
        f"{BASE_URL}/students/verify-identity",
        json={"identifier": identifier}
    ).json()

def enroll_candidate(student_id: str, course_id: int, batch_id: int):
    return session.post(
        f"{BASE_URL}/enrollments",
        json={"student_identifier": student_id, "course_id": course_id, "batch_id": batch_id, "fee_status": "paid"}
    ).json()

# 7. Course & Academics: Real-Time Catalog & Academic Calendar
def get_course_catalog(search: str = None):
    params = {"search": search} if search else {}
    return session.get(f"{BASE_URL}/academics/catalog", params=params).json()

def get_academic_dashboard():
    return session.get(f"{BASE_URL}/academics/dashboard").json()

# 8. Fees & Payments: Telemetry & Counter Collection
def get_payment_dashboard():
    return session.get(f"{BASE_URL}/payments/dashboard").json()

def collect_counter_fee(student_id: str, amount: float, method: str = "cash"):
    return session.post(
        f"{BASE_URL}/payments/transactions/collect",
        json={"student_identifier": student_id, "amount": amount, "payment_method": method}
    ).json()

def check_student_clearance(student_id: str):
    return session.get(f"{BASE_URL}/payments/students/{student_id}/clearance").json()

# 9. Attendance & Leaves: Sync Hardware Punches & Apply Leave
def push_biometric_punches(punches, device_serial="DEV-MAIN-01"):
    return session.post(
        f"{BASE_URL}/attendance/biometric/batch-punch",
        json={"device_serial": device_serial, "punches": punches}
    ).json()

def apply_for_leave(user_id: int, category_id: int, from_date: str, to_date: str, reason: str):
    return session.post(
        f"{BASE_URL}/leaves/applications",
        json={"user_id": user_id, "leave_category_id": category_id, "from_date": from_date, "to_date": to_date, "reason": reason}
    ).json()

# 10. Users & Access Management: Query Directory & Unlock Account
def get_users_list(search: str = None, role: str = None, page: int = 1):
    params = {"page": page}
    if search:
        params["search"] = search
    if role:
        params["role"] = role
    return session.get(f"{BASE_URL}/users", params=params).json()

def unlock_user_account(user_id: int):
    return session.post(f"{BASE_URL}/users/{user_id}/unlock").json()

def get_current_user_profile():
    return session.get(f"{BASE_URL}/user/me").json()</code></pre>
                </div>

            <!-- CURL -->
            @elseif($activeSnippetTab === 'curl')
                <div class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('Standard cURL terminal commands for quick command-line verification:') }}
                    </p>
                    <pre class="p-4 bg-muted/60 text-foreground rounded-xl font-mono text-xs overflow-x-auto border border-border leading-relaxed"><code># 1. Connectivity Ping
curl -X GET "{{ $baseUrl }}/ping" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 2. Hostel Management - Overview &amp; Stats
curl -X GET "{{ $baseUrl }}/hostel/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 3. Hostel Management - Live Visual Room Board
curl -X GET "{{ $baseUrl }}/hostel/visual-board" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 4. Hostel Management - Gatekeeper Resident Verification (Barcode / QR)
curl -X POST "{{ $baseUrl }}/hostel/verify-resident" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"barcode": "BC-2026-CS-0082"}'

# 5. Hostel Management - Bed Status Maintenance Update
curl -X POST "{{ $baseUrl }}/hostel/beds/1/status" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"status": "maintenance", "notes": "Desk and wardrobe repair"}'

# 6. Hostel Management - Transfer Resident to Target Bed
curl -X POST "{{ $baseUrl }}/hostel/allocations/1/transfer" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"target_bed_id": 4, "reason": "Ground floor relocation requested"}'

# 7. Stream Pre-Filled PDF Allocation Form
curl -X GET "{{ $baseUrl }}/hostel/form/download?allocation_id=1" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/pdf" \
  --output allocation_form.pdf

# 8. Dynamic Study Materials - Catalog &amp; Signed Streaming Token
curl -X GET "{{ $baseUrl }}/study-materials/types" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

curl -X GET "{{ $baseUrl }}/study-materials/1/download-token" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 9. Dynamic Study Materials - Client Telemetry View Tracking
curl -X POST "{{ $baseUrl }}/study-materials/1/track-view" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"duration_seconds": 180}'

# 10. Examination Module - Real-Time Dashboard &amp; Visual Seating Plan
curl -X GET "{{ $baseUrl }}/examinations/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

curl -X GET "{{ $baseUrl }}/examinations/1/seating-plan" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 11. Examination Module - Mark Candidate Present (Mobile Invigilator)
curl -X POST "{{ $baseUrl }}/examinations/1/attendance" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"roll_number": "CSCEE-27-KOL-0001", "attendance_status": "present"}'

# 12. Student Tests &amp; Performance - Dashboard &amp; Top Rankers Leaderboard
curl -X GET "{{ $baseUrl }}/student-tests/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

curl -X GET "{{ $baseUrl }}/student-tests/rankers?limit=10" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 13. Library Management - Real-Time Dashboard &amp; Statistics
curl -X GET "{{ $baseUrl }}/library/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 14. Library Management - Fast Barcode / RFID Copy Lookup
curl -X GET "{{ $baseUrl }}/library/copies/LIB-2026-00042" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 15. Library Management - Issue Circulation Book Loan
curl -X POST "{{ $baseUrl }}/library/issues" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"barcode": "LIB-2026-00042", "library_member_id": 1, "days": 14}'

# 16. Library Management - In-House Reading Room Check-in
curl -X POST "{{ $baseUrl }}/library/in-house/issue" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"barcode": "LIB-2026-00042", "library_member_id": 1}'

# 17. Library Management - Stream High-Res Membership Card PDF
curl -X GET "{{ $baseUrl }}/library/members/1/card/download" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/pdf" \
  --output member_card.pdf

# 18. Student Management - Executive Telemetry
curl -X GET "{{ $baseUrl }}/students/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 19. Student Management - Instant Scanner Identity Verification
curl -X POST "{{ $baseUrl }}/students/verify-identity" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"identifier": "202610001"}'

# 20. Course Enrollments - Admit Student
curl -X POST "{{ $baseUrl }}/enrollments" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"student_identifier": "1", "course_id": 1, "batch_id": 1, "fee_status": "paid"}'

# 21. Course & Academics - Real-Time Dashboard & Telemetry
curl -X GET "{{ $baseUrl }}/academics/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 22. Course & Academics - Public Course Discovery Catalog
curl -X GET "{{ $baseUrl }}/academics/catalog" \
  -H "Accept: application/json"

# 23. Course & Academics - Create Course Offering
curl -X POST "{{ $baseUrl }}/academics/courses" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name": "WBCS Executive Foundation 2027", "code": "WBCS-2027", "duration_weeks": 48, "status": "active"}'

# 24. Fee & Payment Collection - Executive Telemetry
curl -X GET "{{ $baseUrl }}/payments/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 25. Fee & Payment Collection - Direct Counter Fee Collection
curl -X POST "{{ $baseUrl }}/payments/transactions/collect" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"student_identifier": "1", "amount": 1000.00, "payment_method": "cash", "remarks": "Counter desk receipt"}'

# 26. Fee & Payment Collection - Multi-Department Financial Clearance
curl -X GET "{{ $baseUrl }}/payments/students/1/clearance" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 27. Attendance Management - Executive Telemetry & Live Punch Feed
curl -X GET "{{ $baseUrl }}/attendance/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 28. Attendance Management - Campus Gate Turnstile Sub-Second Punch
curl -X POST "{{ $baseUrl }}/attendance/punch" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"identifier": "202610001", "punch_type": "check_in"}'

# 29. Attendance Management - Mobile GPS Self Clock-In
curl -X POST "{{ $baseUrl }}/attendance/clock-in" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"device_name": "Flutter Mobile", "latitude": 22.5857, "longitude": 88.4154}'

# 30. Leave Management - Executive Dashboard
curl -X GET "{{ $baseUrl }}/leaves/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 31. Leave Management - Submit Application with Dates & Reason
curl -X POST "{{ $baseUrl }}/leaves/applications" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"user_id": 1, "leave_category_id": 1, "from_date": "2026-11-05", "to_date": "2026-11-06", "reason": "Family urgent event"}'

# 32. Users & Access Management - Executive Telemetry
curl -X GET "{{ $baseUrl }}/users/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 33. Users & Access Management - Register New User
curl -X POST "{{ $baseUrl }}/users" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"name": "Dr. Soumen Mukherjee", "email": "soumen.m@sntcssc.ac.in", "phone": "9876543210", "roles": ["Faculty"]}'

# 34. Users & Access Management - Account Unlock
curl -X POST "{{ $baseUrl }}/users/1/unlock" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 35. Live Chat & Calls - System Status & ICE Server Credentials
curl -X GET "{{ $baseUrl }}/chat/status" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

curl -X GET "{{ $baseUrl }}/chat/calls/ice-servers" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 36. Live Chat - Send Realtime Message in Conversation
curl -X POST "{{ $baseUrl }}/chat/conversations/1/messages" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"body": "Hello candidates, please join the live session."}'

# 37. WebRTC Audio/Video Calling - Initiate Call & Signaling Relay
curl -X POST "{{ $baseUrl }}/chat/calls/initiate" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"conversation_id": 1, "call_type": "video"}'

# 38. Dedicated Online Meetings - Create Virtual Classroom & Video Conference
curl -X POST "{{ $baseUrl }}/chat/meetings" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title": "Civil Services Prelims Masterclass", "meeting_type": "scheduled", "start_time": "2026-11-05 10:00:00", "duration_minutes": 90, "waiting_room_enabled": true}'</code></pre>
                </div>
            @endif
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- SECTION 3: ENDPOINTS & SCOPES CATALOG                          -->
    <!-- ============================================================== -->
    <div class="bg-card rounded-2xl border border-border shadow-sm overflow-hidden space-y-4">
        <div class="px-6 py-4 border-b border-border bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-foreground">{{ __('RESTful API Endpoints & Scopes Matrix') }}</h2>
                <p class="text-xs text-muted-foreground">{{ __('Complete reference of routes, required scopes, HTTP methods, and descriptions.') }}</p>
            </div>

            <!-- Category Filter Tabs -->
            <div class="flex flex-wrap items-center gap-1.5 p-1 bg-muted/60 rounded-xl border border-border text-xs">
                <button type="button" wire:click="$set('activeScopeTab', 'all')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'all' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('All Endpoints') }}
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'students')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'students' ? 'bg-card text-teal-600 dark:text-teal-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Students & Admissions') }} (29)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'enrollments')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'enrollments' ? 'bg-card text-emerald-600 dark:text-emerald-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Course Enrollments') }} (12)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'examinations')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'examinations' ? 'bg-card text-indigo-600 dark:text-indigo-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Examinations') }} (21)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'optional_subjects')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'optional_subjects' ? 'bg-card text-purple-600 dark:text-purple-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Optional Subjects') }} (9)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'student_id_cards')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'student_id_cards' ? 'bg-card text-blue-600 dark:text-blue-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Student ID Cards') }} (6)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'student_tests')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'student_tests' ? 'bg-card text-amber-600 dark:text-amber-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Student Tests & Performance') }} (13)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'materials')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'materials' ? 'bg-card text-emerald-600 dark:text-emerald-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Study Materials') }} (27)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'payments')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'payments' ? 'bg-card text-emerald-600 dark:text-emerald-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Fees & Payments') }} (50)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'library')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'library' ? 'bg-card text-cyan-600 dark:text-cyan-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Library Management') }} (34)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'hostel')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'hostel' ? 'bg-card text-primary font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Hostel Management') }} (38)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'academics')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'academics' ? 'bg-card text-sky-600 dark:text-sky-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Course & Academics') }} (29)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'attendance')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'attendance' ? 'bg-card text-teal-600 dark:text-teal-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Attendance Management') }} (38)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'leaves')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'leaves' ? 'bg-card text-emerald-600 dark:text-emerald-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Leave Management') }} (24)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'users')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'users' ? 'bg-card text-violet-600 dark:text-violet-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Users & Access') }} (33)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'system')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'system' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Diagnostics') }}
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'biometric')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'biometric' ? 'bg-card text-foreground font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Biometrics') }}
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'chat')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'chat' ? 'bg-card text-indigo-600 dark:text-indigo-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Live Chat & Calls') }} (47)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'settings')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'settings' ? 'bg-card text-emerald-600 dark:text-emerald-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Application Settings') }} (30)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'tickets')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'tickets' ? 'bg-card text-rose-600 dark:text-rose-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Support Tickets') }} (31)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'communications')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'communications' ? 'bg-card text-amber-600 dark:text-amber-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Communications & Marketing') }} (48)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'search')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'search' ? 'bg-card text-primary font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Global Search & Preferences') }} (4)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'documents')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'documents' ? 'bg-card text-indigo-600 dark:text-indigo-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Document Vault') }} (17)
                </button>
                <button type="button" wire:click="$set('activeScopeTab', 'captcha')"
                        class="px-2.5 py-1 rounded-lg font-medium transition-colors {{ $activeScopeTab === 'captcha' ? 'bg-card text-amber-600 dark:text-amber-400 font-bold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                    {{ __('Bot Defense & CAPTCHA') }} (8)
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-border bg-muted/40 text-muted-foreground font-semibold">
                        <th class="py-3 px-4">{{ __('Method') }}</th>
                        <th class="py-3 px-4">{{ __('Endpoint Path') }}</th>
                        <th class="py-3 px-4">{{ __('Required Scope') }}</th>
                        <th class="py-3 px-4">{{ __('Description') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($this->endpointsList as $ep)
                        @php
                            $badgeClasses = match($ep['method']) {
                                'GET' => 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
                                'POST' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                                'PUT' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                                'DELETE' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                                default => 'bg-muted text-muted-foreground',
                            };
                        @endphp
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold font-mono {{ $badgeClasses }}">{{ $ep['method'] }}</span>
                            </td>
                            <td class="py-3 px-4 font-mono font-bold text-foreground">{{ $ep['path'] }}</td>
                            <td class="py-3 px-4 font-mono text-xs">
                                <span class="px-1.5 py-0.5 rounded bg-muted border border-border text-muted-foreground">{{ $ep['scope'] }}</span>
                            </td>
                            <td class="py-3 px-4 text-muted-foreground leading-relaxed">{{ $ep['description'] }}</td>
                            <td class="py-3 px-4 text-right whitespace-nowrap">
                                <button type="button"
                                        wire:click="selectPreset('{{ $ep['method'] }}', '{{ $ep['path'] }}')"
                                        onclick="window.scrollTo({ top: document.getElementById('sandbox-playground')?.offsetTop - 80 || 0, behavior: 'smooth' })"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-[11px] font-semibold rounded-lg bg-primary/10 text-primary hover:bg-primary hover:text-primary-foreground transition-all shadow-2xs">
                                    <x-icon name="zap" class="w-3 h-3" />
                                    <span>{{ __('Test in Sandbox') }}</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-muted-foreground text-xs">
                                {{ __('No endpoints match the selected category.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================== -->
    <!-- SECTION 4: INTEGRATION GUIDES & DOCUMENTATION                  -->
    <!-- ============================================================== -->
    <div class="bg-card rounded-2xl border border-border shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-border bg-muted/20 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <div class="p-2 bg-primary/10 text-primary rounded-xl">
                    <x-icon name="book-open" class="w-5 h-5" />
                </div>
                <div>
                    <h2 class="text-base font-bold text-foreground">{{ __('Official Architecture & Integration Guides') }}</h2>
                    <p class="text-xs text-muted-foreground">{{ __('Step-by-step developer manuals with sequence diagrams, SDK recipes, and schemas.') }}</p>
                </div>
            </div>
        </div>

        <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            <!-- Student Management & Enrollment System Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-teal-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-500/10 text-teal-600 dark:text-teal-400 border border-teal-500/20">
                            {{ __('Students & Admissions • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">42 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400">
                            <x-icon name="graduation-cap" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Student Management & Enrollment System REST API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Full integration specification for Student Mobile Apps (Flutter/React Native), Admissions Desk Kiosks (Electron), Campus Turnstiles & Gates (C# .NET), and Student Web Portals. Covers 9-digit Lifetime IDs, admission restrictions (admit-once, duplicate batch check, section capacity), OTR student dossier, profile correction requests, and turnstile identity verification.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/student-management-and-enrollment-api.md</span>
                    <button type="button" wire:click="openGuide('students')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-teal-600 text-white hover:bg-teal-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Optional Subjects Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-purple-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20">
                            {{ __('Optional Subjects • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">9 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400">
                            <x-icon name="book" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Optional Subjects & Change Requests API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Full integration specification for Student Mobile Apps, Academic Mentoring Portals, and Admin Consoles. Covers UPSC 48-subject catalog, cohort allocations, quick update workflows, and formal change requests lifecycle.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/optional-subjects-and-id-cards-api.md</span>
                    <button type="button" wire:click="openGuide('optional_subjects')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Student Identity Cards Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-blue-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20">
                            {{ __('Identity Cards • QR / Barcode') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">6 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400">
                            <x-icon name="credit-card" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Student Identity Cards & QR Verification API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Integration guide for Campus Turnstiles, Gatekeeper Kiosks (C# / Python), and Mobile Apps. Covers digital card payloads, Code 128 barcode URIs, 2D QR validation tokens, and printable double-sided PDF downloads.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/optional-subjects-and-id-cards-api.md</span>
                    <button type="button" wire:click="openGuide('student_id_cards')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Student Tests & Performance Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-amber-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20">
                            {{ __('Student Tests • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">13 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <x-icon name="award" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Student Tests & Performance API Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Full integration specification for Student Mobile Apps (Flutter/React Native), Faculty Scoring Tablets, Automated OMR Scanners (Python), and Leaderboard Kiosks (Electron). Covers composite papers, UPSC 1/3rd negative scoring, ranks, and PDF marksheets.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/student-tests-and-performance-api.md</span>
                    <button type="button" wire:click="openGuide('student_tests')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Examination Management Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-indigo-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                            {{ __('Examinations • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">19 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            <x-icon name="clipboard" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Examination Management REST API Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Full integration guide for mobile apps (invigilator QR attendance scanner, student admit card viewer), desktop hall management kiosks, and web portals. Covers multi-center seating plans, hall ticket generation, and result evaluations.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/examination-management-api.md</span>
                    <button type="button" wire:click="openGuide('examination')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Dynamic Study Materials Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-emerald-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                            {{ __('Study Materials • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">27 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-600">
                            <x-icon name="book" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Dynamic Study Material System Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Complete developer guide for mobile offline caching, streaming downloads via signed tokens, dynamic category types, client reading telemetry, and public lead capture with Smart OTP verification.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/study-material-management-api.md</span>
                    <button type="button" wire:click="openGuide('study_materials')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Library Management Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-cyan-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border border-cyan-500/20">
                            {{ __('Library Module • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">34 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-cyan-500/10 text-cyan-600 dark:text-cyan-400">
                            <x-icon name="library" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Comprehensive Library Management REST API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Full integration specification for Student Mobile Apps (Flutter/React Native), Circulation Desk Desktop Apps (Electron with USB barcode/RFID scanners), Turnstiles (C# .NET), and OPAC web portals. Covers cataloging, barcode loans, renewals, in-house reading room, fines waiver, clearance certificates, and printable cards.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/library-management-api.md</span>
                    <button type="button" wire:click="openGuide('library')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-cyan-600 text-white hover:bg-cyan-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Hostel Management Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-primary/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-primary/10 text-primary border border-primary/20">
                            {{ __('Hostel Module • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">38 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-primary/10 text-primary">
                            <x-icon name="bed" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Hostel Management REST API Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Full integration specification for mobile apps (Flutter/React Native), web portals (React/Vue), and 24/7 lobby kiosks (Python/Electron). Covers visual room boards, student check-in, signed PDF declaration forms, and stay extensions.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/hostel-management-api.md</span>
                    <button type="button" wire:click="openGuide('hostel')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Class Routine, Faculty Master, Course Plans, Classrooms & Partner Institutes Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-indigo-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                            {{ __('Class Routine & Faculty Master • Enterprise') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">64 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            <x-icon name="calendar" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Class Routine & Faculty Master REST API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Turnkey integration manual for lecture scheduling, recurring routines, online meeting platforms (Zoom/Meet/Teams), lecture recordings, faculty master directory, teaching workload telemetry, classrooms master, affiliated partner coaching institutes, and student/faculty self-service mobile portals.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/class-routine-and-faculty-api-guide.md</span>
                    <button type="button" wire:click="openGuide('classes')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Course & Academics Management Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-sky-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-500/20">
                            {{ __('Academics & Courses • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">29 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400">
                            <x-icon name="school" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Course & Academics Management REST API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Full integration specification for Student Mobile Apps, Academic Portals, Admission Kiosks, and Scheduling Services. Covers course catalogs, batch lifecycle management, classroom section capacities, academic calendar schedules, offering linkage, and soft-delete safeguards.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/course-and-academics-management-api.md</span>
                    <button type="button" wire:click="openGuide('academics')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-sky-600 text-white hover:bg-sky-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Fee & Payment Collection System Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-emerald-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 border border-emerald-500/20">
                            {{ __('Fees & Payments • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">43 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-600">
                            <x-icon name="indian-rupee" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Fee & Payment Collection System REST API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Complete enterprise integration specification for POS counter collection (C#/.NET, Electron), student mobile apps (Flutter, React Native), online payment gateways (Razorpay, PhonePe), printable PDF receipts, offline slip reviews, cohort fee schedules with caste concessions, library security deposits, and multi-department clearance checks.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/fee-and-payment-collection-api.md</span>
                    <button type="button" wire:click="openGuide('payments')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- CCAvenue Payment Gateway & Surcharge Reconciliation API Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-indigo-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-500/10 text-indigo-600 border border-indigo-500/20">
                            {{ __('CCAvenue Gateway • Omnichannel') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">7 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600">
                            <x-icon name="credit-card" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('CCAvenue Payment Gateway & Surcharge API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Turnkey integration specification for CCAvenue Web Integration Kit (AES-128-CBC), mobile app WebViews (Flutter/React Native), desktop POS kiosks, gateway convenience fee surcharge bifurcation, underpayment audit hold, dropped session auto-reconciliation, mandatory 6-month penalty billing fallback enforcement, and programmatic refund execution.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/ccavenue-payment-gateway-api.md</span>
                    <button type="button" wire:click="openGuide('ccavenue')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Leave & Attendance Management Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-teal-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-500/10 text-teal-600 border border-teal-500/20">
                            {{ __('Leave & Attendance • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">62 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-teal-500/10 text-teal-600">
                            <x-icon name="calendar" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Leave & Attendance Management System REST API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Turnkey integration guide for mobile GPS clock-in/out (Flutter, React Native), sub-second gate turnstile ingestion (C# .NET, RFID, facial recognition), dynamic leave categories, quota balance tracking, automatic attendance synchronization on leave approval, and defaulters (< 75%) warning digests.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/leave-and-attendance-management-api.md</span>
                    <button type="button" wire:click="openGuide('leaves_attendance')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-teal-600 text-white hover:bg-teal-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Users & Access Management (RBAC) Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-violet-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-violet-500/10 text-violet-600 dark:text-violet-400 border border-violet-500/20">
                            {{ __('Users & RBAC • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">33 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400">
                            <x-icon name="users" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Users & Access Management (RBAC) REST API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Enterprise-grade turnkey integration guide for user lifecycle, automated 14-digit URN generation, Spatie role & permission hierarchies, account lockouts and unlocks, password resets, bulk status operations, and security audit logs.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/users-and-access-management-api.md</span>
                    <button type="button" wire:click="openGuide('users')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-violet-600 text-white hover:bg-violet-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Live Chat, WebRTC Calling & Online Meetings Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-indigo-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 border border-indigo-500/20">
                            {{ __('Live Chat & Calls • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">47 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            <x-icon name="message-square" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Realtime Live Chat, WebRTC & Meetings Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Turnkey integration manual for WebSockets (Reverb), P2P and group WebRTC audio/video calling, virtual classrooms with waiting rooms, mobile push alerting, and file attachments.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/live-chat-and-calls-api.md</span>
                    <button type="button" wire:click="openGuide('chat')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Application System Settings Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-emerald-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                            {{ __('System Settings • Future-Ready') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">30 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <x-icon name="sliders" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Application System Settings Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Enterprise REST architecture for mobile splash bootstrap, cross-platform theming, localization, atomic batch updates, soft deletion, secret masking, and diagnostic telemetries.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/application-system-settings-api.md</span>
                    <button type="button" wire:click="openGuide('settings')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Support Tickets & Helpdesk Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-rose-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20">
                            {{ __('Helpdesk • Multi-Platform') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">31 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400">
                            <x-icon name="life-buoy" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Support Tickets & Helpdesk System Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Turnkey integration guide for cross-platform student queries (Flutter, React Native, Electron), dynamic SLA calculation, breach detection, canned responses, internal staff notes, and 5-star CSAT rating audits.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/support-tickets-and-helpdesk-api.md</span>
                    <button type="button" wire:click="openGuide('tickets')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-rose-600 text-white hover:bg-rose-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Communications & Marketing System Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-amber-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            {{ __('Broadcast • Campaigns • CRM') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">48 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <x-icon name="megaphone" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Communications & Marketing Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Turnkey integration guide for cross-platform messaging (Flutter, React Native, Electron), multi-channel email/SMS dispatches, marketing campaigns, lead capture CRM, and DLT template preview.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/communications-and-marketing-api.md</span>
                    <button type="button" wire:click="openGuide('communications')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-600 text-white hover:bg-amber-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Global Search & Viewport Preferences Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-primary/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary/10 text-primary">
                            {{ __('Omnisearch • Command Palette • Viewport') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">4 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-primary/10 text-primary">
                            <x-icon name="search" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Global Search & Viewport Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Turnkey integration guide for cross-platform unified search, command palettes (Flutter, React Native, Electron), role-based entity querying, and automated fullscreen mode synchronization.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/global-search-and-fullscreen-api.md</span>
                    <button type="button" wire:click="openGuide('search')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- UPSC Mock Interview Management Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-indigo-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            {{ __('5-Member Board • Timetable Queue • Video Recordings') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">27 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            <x-icon name="user-check" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('UPSC Mock Interview Management Integration Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Enterprise-grade turnkey integration guide for 5-member interview boards (Chairman + 4 Panelists), candidate scheduling queue with slot allocation, multi-channel personalized invitations (Email, SMS, WhatsApp, Telegram), video recording sync with peer privacy isolation, and student mobile portal feeds.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/mock-interview-management-api.md</span>
                    <button type="button" wire:click="openGuide('mock_interviews')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Academic Calendar & Version Control Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-amber-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            {{ __('Academics • Version Vault • Month Planning') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">15 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <x-icon name="calendar" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Academic Calendar & Version Control System API Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Enterprise blueprint for year-wise institute academic calendars, version-controlled document vault (preserving past PDF/Word revisions with changelogs and approvals), month-wise activity planning, multi-format export/import (PDF, Excel, CSV), and multi-platform client synchronization (Flutter, React Native, Electron, .NET).') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/academic-calendar-and-version-control-api.md</span>
                    <button type="button" wire:click="openGuide('academic_calendar')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-600 text-white hover:bg-amber-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Soft Copies Document Files Management & Version Control Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-indigo-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            {{ __('Document Vault • Version Control • Revisions') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">17 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            <x-icon name="file-text" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Soft Copies Document Files Management & Version Control Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Enterprise blueprint for institutional soft copies management (PDF, Word, Excel, PPT, Images, ZIP), immutable revision vault with one-click rollbacks, streaming downloads, bulk import/export, and multi-platform client integration (Flutter, React Native, Electron, .NET, Python).') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/document-management-and-version-control-api.md</span>
                    <button type="button" wire:click="openGuide('documents')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>

            <!-- Bot Defense & CAPTCHA System Guide Card -->
            <div class="rounded-xl border border-border bg-card hover:border-amber-500/40 transition-all p-5 flex flex-col justify-between space-y-4 shadow-sm">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            {{ __('Bot Defense • reCAPTCHA • Custom SVG • Audio WAV') }}
                        </span>
                        <span class="text-[11px] text-muted-foreground font-mono">8 Endpoints</span>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                            <x-icon name="shield-check" class="w-5 h-5" />
                        </div>
                        <h3 class="text-sm font-bold text-foreground">{{ __('Enterprise CAPTCHA & Bot Defense System Guide') }}</h3>
                    </div>
                    <p class="text-xs text-muted-foreground leading-relaxed">
                        {{ __('Enterprise blueprint for multi-platform bot mitigation: Google reCAPTCHA (v2 Checkbox/Invisible, v3 Score) and Custom anti-OCR SVG challenges, acoustic 16-bit PCM WAV audio readouts for WCAG 2.2 AA accessibility, client bootstrap specs, atomic refresh, and telemetry integration across Flutter, React Native, iOS, Android, Electron, and .NET.') }}
                    </p>
                </div>

                <div class="pt-3 border-t border-border flex items-center justify-between">
                    <span class="text-[11px] text-muted-foreground font-mono truncate max-w-[170px]">docs/integrations/captcha-and-bot-defense-api.md</span>
                    <button type="button" wire:click="openGuide('captcha')"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-amber-600 text-white hover:bg-amber-700 shadow-sm transition-all shrink-0">
                        <x-icon name="book-open" class="w-3.5 h-3.5" />
                        <span>{{ __('Read Guide in Console') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Interactive In-App Guide Viewer Modal -->
    @if($showGuideModal)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card w-full max-w-5xl rounded-2xl border border-border shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-border bg-muted/30 flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-2.5">
                        <div class="p-2 bg-primary/10 text-primary rounded-xl">
                            <x-icon name="book-open" class="w-5 h-5" />
                        </div>
                        <div>
                            <h3 class="text-sm sm:text-base font-bold text-foreground">{{ $guideTitle }}</h3>
                            <p class="text-[11px] text-muted-foreground">{{ __('Interactive Markdown documentation rendered directly from source.') }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="closeGuideModal"
                            class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="p-6 sm:p-8 overflow-y-auto text-xs sm:text-sm text-foreground space-y-4 leading-relaxed prose dark:prose-invert max-w-none">
                    {!! $guideHtmlContent !!}
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-3 border-t border-border bg-muted/20 flex items-center justify-between shrink-0">
                    <span class="text-xs text-muted-foreground">{{ __('SNTCSSC MIS Developer Platform') }}</span>
                    <button type="button" wire:click="closeGuideModal"
                            class="px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-all">
                        {{ __('Close Documentation') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
