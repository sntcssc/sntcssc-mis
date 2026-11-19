<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationLog;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CommunicationService;
use App\Services\EmailService;
use App\Services\SmsService;
use App\Services\SubscriberService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CommunicationApiController extends Controller
{
    public function __construct(
        protected CommunicationService $communicationService,
        protected SubscriberService $subscriberService
    ) {}

    /**
     * Resolve the active authenticated user context.
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Determine if caller has administrative communications privileges.
     */
    protected function isStaff(Request $request): bool
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey) {
            if ($apiKey->hasScope('manage:communications') || $apiKey->hasScope('*')) {
                return true;
            }
        }

        $user = $this->resolveUser($request);
        if ($user) {
            if ($user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('communications.send') || $user->can('subscribers.manage')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Standard JSON success envelope.
     *
     * @param  array<string, mixed>|null  $meta
     */
    protected function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Standard JSON error envelope.
     *
     * @param  array<string, mixed>|null  $details
     */
    protected function error(string $code, string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    /* ----------------------------------------------------------------- *
     *  1. Executive Telemetry, Dashboard & Analytics
     * ----------------------------------------------------------------- */

    /**
     * Real-time operational communications and marketing metrics dashboard.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $totalLogs = CommunicationLog::count();
            $sentLogs = CommunicationLog::whereIn('status', [CommunicationLog::STATUS_SENT, CommunicationLog::STATUS_DELIVERED])->count();
            $failedLogs = CommunicationLog::where('status', CommunicationLog::STATUS_FAILED)->count();
            $pendingLogs = CommunicationLog::where('status', CommunicationLog::STATUS_PENDING)->count();

            $emailLogs = CommunicationLog::where('channel', CommunicationLog::CHANNEL_EMAIL)->count();
            $smsLogs = CommunicationLog::where('channel', CommunicationLog::CHANNEL_SMS)->count();

            $deliveryRate = $totalLogs > 0 ? round(($sentLogs / $totalLogs) * 100, 2) : 100.0;

            // Campaigns metrics
            $totalCampaigns = CommunicationCampaign::count();
            $activeCampaigns = CommunicationCampaign::whereIn('status', [CommunicationCampaign::STATUS_PROCESSING, CommunicationCampaign::STATUS_SCHEDULED])->count();
            $completedCampaigns = CommunicationCampaign::where('status', CommunicationCampaign::STATUS_COMPLETED)->count();

            // Subscriber telemetry
            $totalSubscribers = Subscriber::count();
            $activeSubscribers = Subscriber::active()->count();
            $unsubscribedCount = Subscriber::unsubscribed()->count();
            $emailSubscribers = Subscriber::active()->whereIn('type', [Subscriber::TYPE_EMAIL, Subscriber::TYPE_BOTH])->whereNotNull('email')->count();
            $whatsappSubscribers = Subscriber::active()->whereIn('type', [Subscriber::TYPE_WHATSAPP, Subscriber::TYPE_BOTH])->whereNotNull('phone')->count();

            // Recent 5 logs
            $recentLogs = CommunicationLog::with(['user:id,name,email,phone', 'sender:id,name'])
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn ($log) => $this->transformLogSummary($log));

            return $this->success([
                'communications' => [
                    'total_dispatches' => $totalLogs,
                    'delivered_count' => $sentLogs,
                    'failed_count' => $failedLogs,
                    'pending_count' => $pendingLogs,
                    'delivery_success_rate' => $deliveryRate,
                    'channels' => [
                        'email' => $emailLogs,
                        'sms' => $smsLogs,
                    ],
                    'service_status' => [
                        'email_enabled' => EmailService::isEnabled(),
                        'sms_enabled' => SmsService::isEnabled(),
                        'sms_driver' => SmsService::driver(),
                    ],
                ],
                'campaigns' => [
                    'total' => $totalCampaigns,
                    'active_or_scheduled' => $activeCampaigns,
                    'completed' => $completedCampaigns,
                ],
                'subscribers' => [
                    'total' => $totalSubscribers,
                    'active' => $activeSubscribers,
                    'unsubscribed' => $unsubscribedCount,
                    'email_channel' => $emailSubscribers,
                    'whatsapp_channel' => $whatsappSubscribers,
                ],
                'recent_dispatches' => $recentLogs,
            ]);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::dashboard failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('TELEMETRY_FETCH_FAILED', 'Failed to retrieve communications telemetry: '.$e->getMessage(), 500);
        }
    }

    /**
     * Cross-channel volume trends, failure distributions, and delivery metrics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $days = min(max((int) $request->input('days', 30), 7), 90);
            $startDate = Carbon::now()->subDays($days)->startOfDay();

            // Daily volumes for the past N days
            $dailyStats = CommunicationLog::where('created_at', '>=', $startDate)
                ->select(
                    DB::raw('DATE(created_at) as log_date'),
                    DB::raw('COUNT(*) as total'),
                    DB::raw('SUM(CASE WHEN channel = "email" THEN 1 ELSE 0 END) as email_count'),
                    DB::raw('SUM(CASE WHEN channel = "sms" THEN 1 ELSE 0 END) as sms_count'),
                    DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed_count'),
                    DB::raw('SUM(CASE WHEN status IN ("sent", "delivered") THEN 1 ELSE 0 END) as successful_count')
                )
                ->groupBy('log_date')
                ->orderBy('log_date', 'asc')
                ->get();

            // Channel volume breakdown
            $channelBreakdown = CommunicationLog::select('channel', DB::raw('COUNT(*) as count'))
                ->groupBy('channel')
                ->pluck('count', 'channel')
                ->toArray();

            // Top failure causes
            $topErrors = CommunicationLog::where('status', CommunicationLog::STATUS_FAILED)
                ->whereNotNull('error_message')
                ->select('error_message', DB::raw('COUNT(*) as count'))
                ->groupBy('error_message')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'error_message' => Str::limit($row->error_message, 100),
                    'occurrences' => $row->count,
                ]);

            // Template usage distribution
            $templateUsage = CommunicationLog::whereNotNull('template_code')
                ->select('template_code', DB::raw('COUNT(*) as count'))
                ->groupBy('template_code')
                ->orderByDesc('count')
                ->limit(8)
                ->get();

            // Subscriber acquisition breakdown by source
            $subscriberSources = Subscriber::select('source', DB::raw('COUNT(*) as count'))
                ->groupBy('source')
                ->pluck('count', 'source')
                ->toArray();

            return $this->success([
                'timeframe_days' => $days,
                'daily_trends' => $dailyStats,
                'channel_breakdown' => $channelBreakdown,
                'top_failures' => $topErrors,
                'template_usage' => $templateUsage,
                'subscriber_sources' => $subscriberSources,
            ]);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::statistics failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STATISTICS_FETCH_FAILED', 'Failed to retrieve statistical breakdown: '.$e->getMessage(), 500);
        }
    }

    /**
     * Export communication delivery logs as structured JSON dataset.
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $query = CommunicationLog::with(['user:id,name,email,phone', 'sender:id,name'])
                ->latest();

            if ($request->filled('channel')) {
                $query->where('channel', $request->channel);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $limit = min((int) $request->input('limit', 1000), 5000);
            $logs = $query->limit($limit)->get()->map(fn ($log) => $this->transformLogDetail($log));

            return $this->success($logs, [
                'exported_count' => $logs->count(),
                'generated_at' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::export failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('EXPORT_FAILED', 'Failed to export communication logs: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  2. Communication Delivery Logs Management
     * ----------------------------------------------------------------- */

    /**
     * Paginated list of communication dispatch logs with multidimensional filters.
     */
    public function logs(Request $request): JsonResponse
    {
        try {
            $query = CommunicationLog::with(['user:id,name,email,phone', 'sender:id,name', 'campaign:id,title']);

            if ($request->boolean('with_trashed') && $this->isStaff($request)) {
                $query->withTrashed();
            }

            // Search filter (recipient, recipient_name, subject, content)
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('recipient', 'like', "%{$search}%")
                        ->orWhere('recipient_name', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            }

            if ($request->filled('channel')) {
                $query->where('channel', $request->channel);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('template_code')) {
                $query->where('template_code', $request->template_code);
            }
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            if ($request->filled('campaign_id')) {
                $query->where('campaign_id', $request->campaign_id);
            }
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
            $paginator = $query->latest('id')->paginate($perPage);

            return $this->success(
                collect($paginator->items())->map(fn ($log) => $this->transformLogDetail($log)),
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            );
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::logs failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('LOGS_FETCH_FAILED', 'Failed to retrieve communication logs: '.$e->getMessage(), 500);
        }
    }

    /**
     * Detailed single communication delivery log record.
     */
    public function showLog(Request $request, int $id): JsonResponse
    {
        try {
            $log = CommunicationLog::withTrashed()
                ->with(['user', 'sender:id,name,email', 'campaign:id,title,status'])
                ->find($id);

            if (! $log) {
                return $this->error('NOT_FOUND', "Communication log #{$id} was not found.", 404);
            }

            return $this->success($this->transformLogDetail($log));
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::showLog failed for log #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('LOG_FETCH_FAILED', 'Failed to retrieve log details: '.$e->getMessage(), 500);
        }
    }

    /**
     * Resend an individual communication log item.
     */
    public function resendLog(Request $request, int $id): JsonResponse
    {
        try {
            $log = CommunicationLog::find($id);
            if (! $log) {
                return $this->error('NOT_FOUND', "Communication log #{$id} was not found.", 404);
            }

            $user = $this->resolveUser($request);
            $result = $this->communicationService->resend($log, $user?->id);

            if (! $result['success']) {
                return $this->error('RESEND_FAILED', $result['message'], 422, [
                    'log_id' => $log->id,
                    'error' => $result['log']->error_message,
                ]);
            }

            return $this->success([
                'message' => $result['message'],
                'log' => $this->transformLogDetail($result['log']),
            ]);
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::resendLog failed for log #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('RESEND_EXECUTION_FAILED', 'Failed to execute message resend: '.$e->getMessage(), 500);
        }
    }

    /**
     * Resend multiple communication logs in batch.
     */
    public function bulkResendLogs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'log_ids' => 'required|array|min:1|max:100',
            'log_ids.*' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $user = $this->resolveUser($request);
            $result = $this->communicationService->bulkResend($request->log_ids, $user?->id);

            return $this->success($result);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::bulkResendLogs failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('BULK_RESEND_FAILED', 'Failed to process bulk resend: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete a communication delivery log record.
     */
    public function destroyLog(Request $request, int $id): JsonResponse
    {
        try {
            $log = CommunicationLog::find($id);
            if (! $log) {
                return $this->error('NOT_FOUND', "Communication log #{$id} was not found.", 404);
            }

            $user = $this->resolveUser($request);

            DB::beginTransaction();
            $log->delete();

            AuditLogService::log(
                event: 'communication_log_deleted',
                description: "Soft deleted {$log->channel} log #{$log->id} to '{$log->recipient}'.",
                auditable: $log,
                userId: $user?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Communication log #{$id} moved to trash successfully.",
                'log_id' => $id,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::destroyLog failed for log #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('LOG_DELETE_FAILED', 'Failed to delete communication log: '.$e->getMessage(), 500);
        }
    }

    /**
     * Restore a soft deleted communication log record.
     */
    public function restoreLog(Request $request, int $id): JsonResponse
    {
        try {
            $log = CommunicationLog::onlyTrashed()->find($id);
            if (! $log) {
                return $this->error('NOT_FOUND', "Trashed communication log #{$id} was not found.", 404);
            }

            $user = $this->resolveUser($request);

            DB::beginTransaction();
            $log->restore();

            AuditLogService::log(
                event: 'communication_log_restored',
                description: "Restored {$log->channel} log #{$log->id} to '{$log->recipient}' from trash.",
                auditable: $log,
                userId: $user?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Communication log #{$id} restored successfully.",
                'log' => $this->transformLogDetail($log),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::restoreLog failed for log #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('LOG_RESTORE_FAILED', 'Failed to restore communication log: '.$e->getMessage(), 500);
        }
    }

    /**
     * Bulk purge or archive older communication logs.
     */
    public function purgeLogs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days_older_than' => 'required|integer|min:30',
            'channel' => 'nullable|in:email,sms',
            'status' => 'nullable|in:sent,delivered,failed',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $cutoff = Carbon::now()->subDays((int) $request->days_older_than)->endOfDay();
            $user = $this->resolveUser($request);

            DB::beginTransaction();
            $query = CommunicationLog::where('created_at', '<=', $cutoff);

            if ($request->filled('channel')) {
                $query->where('channel', $request->channel);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $count = $query->count();
            $query->delete(); // Soft deletes

            AuditLogService::log(
                event: 'communication_logs_purged',
                description: "Purged {$count} communication logs older than {$request->days_older_than} days.",
                newValues: [
                    'purged_count' => $count,
                    'cutoff_date' => $cutoff->toDateString(),
                    'channel' => $request->channel,
                    'status' => $request->status,
                ],
                userId: $user?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Successfully purged {$count} communication log records.",
                'purged_count' => $count,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('CommunicationApiController::purgeLogs failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('LOGS_PURGE_FAILED', 'Failed to purge communication logs: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  3. Direct Composing & Quick Dispatch Services
     * ----------------------------------------------------------------- */

    /**
     * Dispatch an individual or templated Email.
     */
    public function sendEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:user_id|nullable|email',
            'user_id' => 'nullable|exists:users,id',
            'subject' => 'required_without:template_code|nullable|string|max:255',
            'content' => 'required_without:template_code|nullable|string',
            'template_code' => 'nullable|string|exists:email_templates,code',
            'variables' => 'nullable|array',
            'options' => 'nullable|array',
            'queue' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $user = null;
            $email = $request->email;

            if ($request->filled('user_id')) {
                $user = User::find($request->user_id);
                $email = $email ?: $user?->email;
            }

            if (! $email) {
                return $this->error('MISSING_RECIPIENT', 'A valid email address or user with email is required.', 422);
            }

            $subject = $request->subject;
            $content = $request->content;
            $variables = (array) $request->input('variables', []);

            // Hydrate from EmailTemplate if code supplied
            if ($request->filled('template_code')) {
                $template = EmailTemplate::active()->where('code', $request->template_code)->first();
                if (! $template) {
                    return $this->error('TEMPLATE_INACTIVE', "Email template '{$request->template_code}' is inactive or not found.", 422);
                }
                $subject = $subject ?: $template->subject;
                $content = $content ?: $template->body;
            }

            $actor = $this->resolveUser($request);

            if ($request->boolean('queue')) {
                $this->communicationService->queueEmail(
                    email: $email,
                    subject: $subject,
                    htmlBody: $content,
                    options: (array) $request->input('options', []),
                    user: $user,
                    templateCode: $request->template_code,
                    variables: $variables,
                    sentBy: $actor?->id
                );

                return $this->success([
                    'message' => "Email to '{$email}' has been queued for background delivery.",
                    'queued' => true,
                    'recipient' => $email,
                ], null, 202);
            }

            $log = $this->communicationService->logAndSendEmail(
                email: $email,
                subject: $subject,
                htmlBody: $content,
                options: (array) $request->input('options', []),
                user: $user,
                templateCode: $request->template_code,
                variables: $variables,
                sentBy: $actor?->id
            );

            AuditLogService::log(
                event: 'communication_direct_email_sent',
                description: "Direct email dispatched to '{$email}' - Status: {$log->status}",
                auditable: $log,
                userId: $actor?->id
            );

            return $this->success([
                'message' => $log->isDelivered() ? 'Email dispatched successfully.' : 'Email dispatch attempted but failed.',
                'log' => $this->transformLogDetail($log),
            ], null, $log->isDelivered() ? 200 : 422);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::sendEmail failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('EMAIL_DISPATCH_FAILED', 'Failed to dispatch email: '.$e->getMessage(), 500);
        }
    }

    /**
     * Dispatch an individual or templated SMS.
     */
    public function sendSms(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required_without:user_id|nullable|string',
            'user_id' => 'nullable|exists:users,id',
            'content' => 'required_without:template_code|nullable|string',
            'template_code' => 'nullable|string|exists:sms_templates,code',
            'dlt_template_id' => 'nullable|string',
            'sender_id' => 'nullable|string',
            'variables' => 'nullable|array',
            'queue' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $user = null;
            $phone = $request->phone;

            if ($request->filled('user_id')) {
                $user = User::find($request->user_id);
                $phone = $phone ?: $user?->phone;
            }

            if (! $phone) {
                return $this->error('MISSING_RECIPIENT', 'A valid phone number or user with phone is required.', 422);
            }

            $content = $request->content;
            $dltTemplateId = $request->dlt_template_id;
            $senderId = $request->sender_id;
            $variables = (array) $request->input('variables', []);

            // Hydrate from SmsTemplate if code supplied
            if ($request->filled('template_code')) {
                $template = SmsTemplate::active()->where('code', $request->template_code)->first();
                if (! $template) {
                    return $this->error('TEMPLATE_INACTIVE', "SMS template '{$request->template_code}' is inactive or not found.", 422);
                }
                $content = $content ?: $template->body;
                $dltTemplateId = $dltTemplateId ?: $template->dlt_template_id;
                $senderId = $senderId ?: $template->sender_id;
            }

            $actor = $this->resolveUser($request);

            if ($request->boolean('queue')) {
                $this->communicationService->queueSms(
                    phone: $phone,
                    message: $content,
                    options: ['dlt_template_id' => $dltTemplateId, 'sender_id' => $senderId],
                    user: $user,
                    templateCode: $request->template_code,
                    variables: $variables,
                    sentBy: $actor?->id
                );

                return $this->success([
                    'message' => "SMS to '{$phone}' has been queued for background delivery.",
                    'queued' => true,
                    'recipient' => $phone,
                ], null, 202);
            }

            $log = $this->communicationService->logAndSendSms(
                phone: $phone,
                message: $content,
                dltTemplateId: $dltTemplateId,
                senderId: $senderId,
                user: $user,
                templateCode: $request->template_code,
                variables: $variables,
                sentBy: $actor?->id
            );

            AuditLogService::log(
                event: 'communication_direct_sms_sent',
                description: "Direct SMS dispatched to '{$phone}' - Status: {$log->status}",
                auditable: $log,
                userId: $actor?->id
            );

            return $this->success([
                'message' => $log->isDelivered() ? 'SMS dispatched successfully.' : 'SMS dispatch attempted but failed.',
                'log' => $this->transformLogDetail($log),
            ], null, $log->isDelivered() ? 200 : 422);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::sendSms failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SMS_DISPATCH_FAILED', 'Failed to dispatch SMS: '.$e->getMessage(), 500);
        }
    }

    /**
     * Dispatch multi-channel broadcast messaging across an audience or custom list.
     */
    public function sendBulk(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'channel' => 'required|in:email,sms,both',
            'recipient_type' => 'required|in:individual,role,all_users,custom_list',
            'recipient_role' => 'required_if:recipient_type,role|nullable|string',
            'recipient_ids' => 'required_if:recipient_type,individual,custom_list|nullable|array',
            'subject' => 'nullable|string|max:255',
            'content' => 'required_without:template_code|nullable|string',
            'template_code' => 'nullable|string',
            'immediate' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $campaign = CommunicationCampaign::create([
                'title' => $request->title,
                'channel' => $request->channel,
                'recipient_type' => $request->recipient_type,
                'recipient_role' => $request->recipient_role,
                'recipient_ids' => $request->recipient_ids,
                'template_code' => $request->template_code,
                'subject' => $request->subject,
                'content' => $request->content ?: '',
                'status' => CommunicationCampaign::STATUS_DRAFT,
                'created_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'communication_campaign_created',
                description: "Created bulk campaign '{$campaign->title}' for {$campaign->recipient_type} audience.",
                auditable: $campaign,
                userId: $actor?->id
            );
            DB::commit();

            if ($request->boolean('immediate', true)) {
                $dispatchResult = $this->communicationService->dispatchCampaign($campaign, $actor?->id);

                return $this->success([
                    'message' => $dispatchResult['message'],
                    'campaign' => $this->transformCampaign($campaign->fresh()),
                    'dispatch_result' => $dispatchResult,
                ]);
            }

            return $this->success([
                'message' => "Campaign '{$campaign->title}' created in draft state.",
                'campaign' => $this->transformCampaign($campaign),
            ], null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('CommunicationApiController::sendBulk failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('BULK_DISPATCH_FAILED', 'Failed to create/dispatch bulk communication: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  4. Marketing Campaigns Management
     * ----------------------------------------------------------------- */

    /**
     * Paginated list of campaigns with status and audience filters.
     */
    public function campaigns(Request $request): JsonResponse
    {
        try {
            $query = CommunicationCampaign::with(['creator:id,name,email', 'updater:id,name']);

            if ($request->boolean('with_trashed') && $this->isStaff($request)) {
                $query->withTrashed();
            }

            if ($request->filled('channel')) {
                $query->where('channel', $request->channel);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('recipient_type')) {
                $query->where('recipient_type', $request->recipient_type);
            }
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%");
                });
            }

            $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
            $paginator = $query->latest('id')->paginate($perPage);

            return $this->success(
                collect($paginator->items())->map(fn ($campaign) => $this->transformCampaign($campaign)),
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            );
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::campaigns failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGNS_FETCH_FAILED', 'Failed to retrieve campaigns list: '.$e->getMessage(), 500);
        }
    }

    /**
     * Create a new marketing/communication campaign.
     */
    public function storeCampaign(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'channel' => 'required|in:email,sms,both',
            'recipient_type' => 'required|in:individual,role,all_users,custom_list',
            'recipient_role' => 'required_if:recipient_type,role|nullable|string',
            'recipient_ids' => 'required_if:recipient_type,individual,custom_list|nullable|array',
            'template_code' => 'nullable|string',
            'subject' => 'nullable|string|max:255',
            'content' => 'required|string',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $actor = $this->resolveUser($request);
            $status = $request->filled('scheduled_at') ? CommunicationCampaign::STATUS_SCHEDULED : CommunicationCampaign::STATUS_DRAFT;

            DB::beginTransaction();
            $campaign = CommunicationCampaign::create([
                'title' => $request->title,
                'channel' => $request->channel,
                'recipient_type' => $request->recipient_type,
                'recipient_role' => $request->recipient_role,
                'recipient_ids' => $request->recipient_ids,
                'template_code' => $request->template_code,
                'subject' => $request->subject,
                'content' => $request->content,
                'status' => $status,
                'scheduled_at' => $request->scheduled_at,
                'created_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'campaign_created',
                description: "Created campaign #{$campaign->id} '{$campaign->title}' ({$campaign->status}).",
                auditable: $campaign,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Campaign '{$campaign->title}' created successfully.",
                'campaign' => $this->transformCampaign($campaign),
            ], null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('CommunicationApiController::storeCampaign failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_CREATE_FAILED', 'Failed to create campaign: '.$e->getMessage(), 500);
        }
    }

    /**
     * Single campaign details and delivery telemetry.
     */
    public function showCampaign(Request $request, int $id): JsonResponse
    {
        try {
            $campaign = CommunicationCampaign::withTrashed()
                ->with(['creator:id,name,email', 'updater:id,name', 'logs' => fn ($q) => $q->latest()->limit(10)])
                ->find($id);

            if (! $campaign) {
                return $this->error('NOT_FOUND', "Campaign #{$id} was not found.", 404);
            }

            return $this->success($this->transformCampaign($campaign, true));
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::showCampaign failed for #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_FETCH_FAILED', 'Failed to retrieve campaign details: '.$e->getMessage(), 500);
        }
    }

    /**
     * Update campaign configuration (only draft or scheduled).
     */
    public function updateCampaign(Request $request, int $id): JsonResponse
    {
        try {
            $campaign = CommunicationCampaign::find($id);
            if (! $campaign) {
                return $this->error('NOT_FOUND', "Campaign #{$id} was not found.", 404);
            }

            if (! in_array($campaign->status, [CommunicationCampaign::STATUS_DRAFT, CommunicationCampaign::STATUS_SCHEDULED], true)) {
                return $this->error('CAMPAIGN_LOCKED', "Campaigns in '{$campaign->status}' status cannot be modified.", 422);
            }

            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|required|string|max:255',
                'channel' => 'sometimes|required|in:email,sms,both',
                'recipient_type' => 'sometimes|required|in:individual,role,all_users,custom_list',
                'recipient_role' => 'nullable|string',
                'recipient_ids' => 'nullable|array',
                'template_code' => 'nullable|string',
                'subject' => 'nullable|string|max:255',
                'content' => 'sometimes|required|string',
                'scheduled_at' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
            }

            $actor = $this->resolveUser($request);
            $oldValues = $campaign->only(['title', 'channel', 'status', 'scheduled_at']);

            DB::beginTransaction();
            $campaign->fill($request->only([
                'title', 'channel', 'recipient_type', 'recipient_role',
                'recipient_ids', 'template_code', 'subject', 'content', 'scheduled_at',
            ]));

            if ($request->filled('scheduled_at')) {
                $campaign->status = CommunicationCampaign::STATUS_SCHEDULED;
            }

            $campaign->updated_by = $actor?->id;
            $campaign->save();

            AuditLogService::log(
                event: 'campaign_updated',
                description: "Updated campaign #{$campaign->id} '{$campaign->title}'.",
                auditable: $campaign,
                oldValues: $oldValues,
                newValues: $campaign->only(['title', 'channel', 'status', 'scheduled_at']),
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Campaign '{$campaign->title}' updated successfully.",
                'campaign' => $this->transformCampaign($campaign),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::updateCampaign failed for #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_UPDATE_FAILED', 'Failed to update campaign: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete a campaign.
     */
    public function destroyCampaign(Request $request, int $id): JsonResponse
    {
        try {
            $campaign = CommunicationCampaign::find($id);
            if (! $campaign) {
                return $this->error('NOT_FOUND', "Campaign #{$id} was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $campaign->delete();

            AuditLogService::log(
                event: 'campaign_deleted',
                description: "Soft deleted campaign #{$campaign->id} '{$campaign->title}'.",
                auditable: $campaign,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Campaign #{$id} moved to trash successfully.",
                'campaign_id' => $id,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::destroyCampaign failed for #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_DELETE_FAILED', 'Failed to delete campaign: '.$e->getMessage(), 500);
        }
    }

    /**
     * Restore a soft deleted campaign.
     */
    public function restoreCampaign(Request $request, int $id): JsonResponse
    {
        try {
            $campaign = CommunicationCampaign::onlyTrashed()->find($id);
            if (! $campaign) {
                return $this->error('NOT_FOUND', "Trashed campaign #{$id} was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $campaign->restore();

            AuditLogService::log(
                event: 'campaign_restored',
                description: "Restored campaign #{$campaign->id} '{$campaign->title}' from trash.",
                auditable: $campaign,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Campaign #{$id} restored successfully.",
                'campaign' => $this->transformCampaign($campaign),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::restoreCampaign failed for #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_RESTORE_FAILED', 'Failed to restore campaign: '.$e->getMessage(), 500);
        }
    }

    /**
     * Immediately execute or trigger campaign dispatch.
     */
    public function dispatchCampaign(Request $request, int $id): JsonResponse
    {
        try {
            $campaign = CommunicationCampaign::find($id);
            if (! $campaign) {
                return $this->error('NOT_FOUND', "Campaign #{$id} was not found.", 404);
            }

            if ($campaign->status === CommunicationCampaign::STATUS_PROCESSING) {
                return $this->error('CAMPAIGN_BUSY', 'This campaign is currently being processed.', 422);
            }

            $actor = $this->resolveUser($request);

            if ($request->boolean('async')) {
                $this->communicationService->queueCampaign($campaign, $actor?->id);

                return $this->success([
                    'message' => "Campaign #{$id} queued for background asynchronous processing.",
                    'queued' => true,
                    'campaign_id' => $id,
                ], null, 202);
            }

            $result = $this->communicationService->dispatchCampaign($campaign, $actor?->id);

            return $this->success([
                'message' => $result['message'],
                'campaign' => $this->transformCampaign($campaign->fresh()),
                'stats' => [
                    'sent' => $result['sent'],
                    'failed' => $result['failed'],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::dispatchCampaign failed for #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_DISPATCH_FAILED', 'Failed to execute campaign dispatch: '.$e->getMessage(), 500);
        }
    }

    /**
     * Cancel an active or scheduled campaign.
     */
    public function cancelCampaign(Request $request, int $id): JsonResponse
    {
        try {
            $campaign = CommunicationCampaign::find($id);
            if (! $campaign) {
                return $this->error('NOT_FOUND', "Campaign #{$id} was not found.", 404);
            }

            if ($campaign->status === CommunicationCampaign::STATUS_COMPLETED) {
                return $this->error('CAMPAIGN_COMPLETED', 'Completed campaigns cannot be cancelled.', 422);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $campaign->status = CommunicationCampaign::STATUS_CANCELLED;
            $campaign->updated_by = $actor?->id;
            $campaign->save();

            AuditLogService::log(
                event: 'campaign_cancelled',
                description: "Cancelled campaign #{$campaign->id} '{$campaign->title}'.",
                auditable: $campaign,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Campaign #{$id} cancelled successfully.",
                'campaign' => $this->transformCampaign($campaign),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::cancelCampaign failed for #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_CANCEL_FAILED', 'Failed to cancel campaign: '.$e->getMessage(), 500);
        }
    }

    /**
     * Paginated list of delivery logs generated by a specific campaign.
     */
    public function campaignLogs(Request $request, int $id): JsonResponse
    {
        try {
            $campaign = CommunicationCampaign::find($id);
            if (! $campaign) {
                return $this->error('NOT_FOUND', "Campaign #{$id} was not found.", 404);
            }

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $paginator = CommunicationLog::where('campaign_id', $id)
                ->with(['user:id,name,email,phone'])
                ->latest('id')
                ->paginate($perPage);

            return $this->success(
                collect($paginator->items())->map(fn ($log) => $this->transformLogDetail($log)),
                [
                    'campaign_id' => $id,
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            );
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::campaignLogs failed for #{$id}: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('CAMPAIGN_LOGS_FAILED', 'Failed to retrieve campaign logs: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  5. Marketing Subscribers Management
     * ----------------------------------------------------------------- */

    /**
     * Paginated catalog of email & WhatsApp marketing subscribers.
     */
    public function subscribers(Request $request): JsonResponse
    {
        try {
            $query = Subscriber::query();

            if ($request->boolean('with_trashed') && $this->isStaff($request)) {
                $query->withTrashed();
            }

            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('source')) {
                $query->where('source', $request->source);
            }
            if ($tag = $request->input('tag')) {
                $query->tagged($tag);
            }

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $paginator = $query->latest('id')->paginate($perPage);

            return $this->success(
                collect($paginator->items())->map(fn ($sub) => $this->transformSubscriber($sub)),
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ]
            );
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::subscribers failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBERS_FETCH_FAILED', 'Failed to retrieve subscribers: '.$e->getMessage(), 500);
        }
    }

    /**
     * Subscribe or register a marketing lead contact.
     */
    public function storeSubscriber(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:email,whatsapp,both',
            'email' => 'required_without:phone|nullable|email|max:190',
            'phone' => 'required_without:email|nullable|string|max:30',
            'country_code' => 'nullable|string|max:10',
            'name' => 'nullable|string|max:150',
            'source' => 'nullable|string|max:50',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $actor = $this->resolveUser($request);
            $payload = array_merge($request->all(), [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $subscriber = $this->subscriberService->subscribe($payload, $actor);

            return $this->success([
                'message' => 'Subscriber registered successfully.',
                'subscriber' => $this->transformSubscriber($subscriber),
            ], null, 201);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::storeSubscriber failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_CREATE_FAILED', 'Failed to register subscriber: '.$e->getMessage(), 500);
        }
    }

    /**
     * Single subscriber profile dossier.
     */
    public function showSubscriber(Request $request, string $identifier): JsonResponse
    {
        try {
            $subscriber = $this->findSubscriber($identifier, true);
            if (! $subscriber) {
                return $this->error('NOT_FOUND', "Subscriber '{$identifier}' was not found.", 404);
            }

            return $this->success($this->transformSubscriber($subscriber));
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::showSubscriber failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_FETCH_FAILED', 'Failed to retrieve subscriber profile: '.$e->getMessage(), 500);
        }
    }

    /**
     * Update an existing subscriber profile.
     */
    public function updateSubscriber(Request $request, string $identifier): JsonResponse
    {
        try {
            $subscriber = $this->findSubscriber($identifier);
            if (! $subscriber) {
                return $this->error('NOT_FOUND', "Subscriber '{$identifier}' was not found.", 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'nullable|string|max:150',
                'email' => 'nullable|email|max:190',
                'phone' => 'nullable|string|max:30',
                'country_code' => 'nullable|string|max:10',
                'type' => 'nullable|in:email,whatsapp,both',
                'status' => 'nullable|in:active,unsubscribed,bounced,pending_verification',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:50',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
            }

            $actor = $this->resolveUser($request);
            $this->subscriberService->updateSubscriber($subscriber, $request->all(), $actor);

            return $this->success([
                'message' => 'Subscriber profile updated successfully.',
                'subscriber' => $this->transformSubscriber($subscriber->fresh()),
            ]);
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::updateSubscriber failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_UPDATE_FAILED', 'Failed to update subscriber: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete a subscriber record.
     */
    public function destroySubscriber(Request $request, string $identifier): JsonResponse
    {
        try {
            $subscriber = $this->findSubscriber($identifier);
            if (! $subscriber) {
                return $this->error('NOT_FOUND', "Subscriber '{$identifier}' was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $subscriber->deleted_by = $actor?->id;
            $subscriber->save();
            $subscriber->delete();

            AuditLogService::log(
                event: 'subscriber_deleted',
                description: "Soft deleted subscriber #{$subscriber->id} ({$subscriber->email}/{$subscriber->phone}).",
                auditable: $subscriber,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => 'Subscriber record moved to trash.',
                'identifier' => $identifier,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::destroySubscriber failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_DELETE_FAILED', 'Failed to delete subscriber: '.$e->getMessage(), 500);
        }
    }

    /**
     * Restore a soft deleted subscriber.
     */
    public function restoreSubscriber(Request $request, string $identifier): JsonResponse
    {
        try {
            $subscriber = $this->findSubscriber($identifier, true);
            if (! $subscriber || ! $subscriber->trashed()) {
                return $this->error('NOT_FOUND', "Trashed subscriber '{$identifier}' was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $subscriber->restore();
            $subscriber->deleted_by = null;
            $subscriber->updated_by = $actor?->id;
            $subscriber->save();

            AuditLogService::log(
                event: 'subscriber_restored',
                description: "Restored subscriber #{$subscriber->id} ({$subscriber->email}/{$subscriber->phone}) from trash.",
                auditable: $subscriber,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => 'Subscriber restored successfully.',
                'subscriber' => $this->transformSubscriber($subscriber),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::restoreSubscriber failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_RESTORE_FAILED', 'Failed to restore subscriber: '.$e->getMessage(), 500);
        }
    }

    /**
     * Unsubscribe a contact with explicit reason.
     */
    public function unsubscribeSubscriber(Request $request, string $identifier): JsonResponse
    {
        try {
            $subscriber = $this->findSubscriber($identifier);
            if (! $subscriber) {
                return $this->error('NOT_FOUND', "Subscriber '{$identifier}' was not found.", 404);
            }

            $actor = $this->resolveUser($request);
            $reason = $request->input('reason', 'Administrative update');

            DB::beginTransaction();
            $subscriber->update([
                'status' => Subscriber::STATUS_UNSUBSCRIBED,
                'unsubscribed_at' => Carbon::now(),
                'unsubscribe_reason' => $reason,
                'updated_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'subscriber_unsubscribed_admin',
                description: "Unsubscribed #{$subscriber->id} ({$subscriber->email}/{$subscriber->phone}). Reason: {$reason}",
                auditable: $subscriber,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => 'Contact unsubscribed successfully.',
                'subscriber' => $this->transformSubscriber($subscriber),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::unsubscribeSubscriber failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_UNSUBSCRIBE_FAILED', 'Failed to unsubscribe contact: '.$e->getMessage(), 500);
        }
    }

    /**
     * Bulk actions across multiple subscriber IDs (activate, unsubscribe, add_tag, remove_tag, delete).
     */
    public function bulkSubscriberAction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:activate,unsubscribe,add_tag,remove_tag,delete',
            'subscriber_ids' => 'required|array|min:1|max:500',
            'subscriber_ids.*' => 'required|integer',
            'tag' => 'required_if:action,add_tag,remove_tag|nullable|string|max:50',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $actor = $this->resolveUser($request);
            $ids = $request->subscriber_ids;
            $action = $request->action;
            $tag = $request->tag;
            $affected = 0;

            DB::beginTransaction();
            $subscribers = Subscriber::whereIn('id', $ids)->get();

            foreach ($subscribers as $subscriber) {
                switch ($action) {
                    case 'activate':
                        $subscriber->update([
                            'status' => Subscriber::STATUS_ACTIVE,
                            'unsubscribed_at' => null,
                            'unsubscribe_reason' => null,
                            'updated_by' => $actor?->id,
                        ]);
                        $affected++;
                        break;

                    case 'unsubscribe':
                        $subscriber->update([
                            'status' => Subscriber::STATUS_UNSUBSCRIBED,
                            'unsubscribed_at' => Carbon::now(),
                            'unsubscribe_reason' => $request->input('reason', 'Bulk action'),
                            'updated_by' => $actor?->id,
                        ]);
                        $affected++;
                        break;

                    case 'add_tag':
                        if ($tag) {
                            $tags = array_values(array_unique(array_merge($subscriber->tags ?? [], [$tag])));
                            $subscriber->update(['tags' => $tags, 'updated_by' => $actor?->id]);
                            $affected++;
                        }
                        break;

                    case 'remove_tag':
                        if ($tag) {
                            $tags = array_values(array_filter($subscriber->tags ?? [], fn ($t) => $t !== $tag));
                            $subscriber->update(['tags' => $tags, 'updated_by' => $actor?->id]);
                            $affected++;
                        }
                        break;

                    case 'delete':
                        $subscriber->update(['deleted_by' => $actor?->id]);
                        $subscriber->delete();
                        $affected++;
                        break;
                }
            }

            AuditLogService::log(
                event: 'subscribers_bulk_action',
                description: "Bulk action '{$action}' performed on {$affected} subscribers.",
                newValues: ['action' => $action, 'count' => $affected, 'tag' => $tag],
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Bulk {$action} executed successfully on {$affected} record(s).",
                'affected_count' => $affected,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('CommunicationApiController::bulkSubscriberAction failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('BULK_ACTION_FAILED', 'Failed to execute bulk subscriber action: '.$e->getMessage(), 500);
        }
    }

    /**
     * Export subscribers as structured JSON dataset.
     */
    public function exportSubscribers(Request $request): JsonResponse
    {
        try {
            $query = Subscriber::latest('id');

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('source')) {
                $query->where('source', $request->source);
            }
            if ($tag = $request->input('tag')) {
                $query->tagged($tag);
            }

            $limit = min((int) $request->input('limit', 2000), 10000);
            $subscribers = $query->limit($limit)->get()->map(fn ($sub) => $this->transformSubscriber($sub));

            return $this->success($subscribers, [
                'count' => $subscribers->count(),
                'generated_at' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::exportSubscribers failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_EXPORT_FAILED', 'Failed to export subscribers: '.$e->getMessage(), 500);
        }
    }

    /**
     * Import an array of subscribers in bulk with automatic deduplication.
     */
    public function importSubscribers(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subscribers' => 'required|array|min:1|max:500',
            'subscribers.*.email' => 'required_without:subscribers.*.phone|nullable|email',
            'subscribers.*.phone' => 'required_without:subscribers.*.email|nullable|string',
            'subscribers.*.name' => 'nullable|string',
            'subscribers.*.type' => 'nullable|in:email,whatsapp,both',
            'subscribers.*.source' => 'nullable|string',
            'subscribers.*.tags' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $actor = $this->resolveUser($request);
            $imported = 0;
            $failed = 0;
            $errors = [];

            foreach ($request->subscribers as $index => $item) {
                try {
                    $item['source'] = $item['source'] ?? Subscriber::SOURCE_IMPORT;
                    $item['ip_address'] = $request->ip();
                    $item['user_agent'] = $request->userAgent();
                    $this->subscriberService->subscribe($item, $actor);
                    $imported++;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'index' => $index,
                        'email' => $item['email'] ?? null,
                        'phone' => $item['phone'] ?? null,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $this->success([
                'message' => "Import complete. {$imported} subscribers imported/updated, {$failed} failed.",
                'imported_count' => $imported,
                'failed_count' => $failed,
                'failures' => $errors,
            ]);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::importSubscribers failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIBER_IMPORT_FAILED', 'Failed to import subscribers: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  6. Public Marketing Lead Capture & One-Click Unsubscribe
     * ----------------------------------------------------------------- */

    /**
     * Cross-platform public lead subscription (landing page, mobile app onboarding, newsletter footers).
     */
    public function publicSubscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:phone|nullable|email|max:190',
            'phone' => 'required_without:email|nullable|string|max:30',
            'country_code' => 'nullable|string|max:10',
            'name' => 'nullable|string|max:150',
            'source' => 'nullable|string|max:50',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $payload = array_merge($request->all(), [
                'source' => $request->input('source', Subscriber::SOURCE_WELCOME),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $subscriber = $this->subscriberService->subscribe($payload);

            return $this->success([
                'message' => 'Thank you for subscribing! Your preferences have been saved.',
                'subscribed' => true,
                'uuid' => $subscriber->uuid,
            ], null, 201);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::publicSubscribe failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SUBSCRIPTION_FAILED', 'Failed to process subscription: '.$e->getMessage(), 500);
        }
    }

    /**
     * One-click public unsubscribe handler.
     */
    public function publicUnsubscribe(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required_without:email|nullable|string|max:64',
            'email' => 'required_without:token|nullable|email',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $reason = $request->input('reason', 'Public unsubscribe request');
            $subscriber = null;

            if ($token = $request->input('token')) {
                $subscriber = $this->subscriberService->unsubscribe($token, $reason);
            } elseif ($email = $request->input('email')) {
                $subscriber = Subscriber::where('email', strtolower(trim($email)))->first();
                if ($subscriber) {
                    $subscriber->update([
                        'status' => Subscriber::STATUS_UNSUBSCRIBED,
                        'unsubscribed_at' => Carbon::now(),
                        'unsubscribe_reason' => $reason,
                    ]);
                }
            }

            if (! $subscriber) {
                return $this->error('NOT_FOUND', 'Subscription record not found or invalid token.', 404);
            }

            return $this->success([
                'message' => 'You have been successfully unsubscribed from further marketing communications.',
                'unsubscribed' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::publicUnsubscribe failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('UNSUBSCRIBE_FAILED', 'Failed to unsubscribe contact: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  7. Email Message Templates Management
     * ----------------------------------------------------------------- */

    /**
     * Catalog of Email message templates with category & status filters.
     */
    public function emailTemplates(Request $request): JsonResponse
    {
        try {
            $query = EmailTemplate::with(['creator:id,name', 'editor:id,name']);

            if ($request->boolean('with_trashed') && $this->isStaff($request)) {
                $query->withTrashed();
            }

            if ($request->filled('category')) {
                $query->where('category', $request->category);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->boolean('status'));
            }
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%");
                });
            }

            $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
            $paginator = $query->orderBy('name')->paginate($perPage);

            return $this->success(
                collect($paginator->items())->map(fn ($tmpl) => $this->transformEmailTemplate($tmpl)),
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'categories' => EmailTemplate::CATEGORIES,
                ]
            );
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::emailTemplates failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATES_FETCH_FAILED', 'Failed to retrieve email templates: '.$e->getMessage(), 500);
        }
    }

    /**
     * Store a new Email message template.
     */
    public function storeEmailTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:email_templates,code|regex:/^[a-zA-Z0-9_\-\.]+$/',
            'name' => 'required|string|max:100',
            'category' => 'required|string|in:'.implode(',', array_keys(EmailTemplate::CATEGORIES)),
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'variables' => 'nullable|array',
            'variables.*' => 'string',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $template = EmailTemplate::create([
                'code' => Str::slug($request->code, '_'),
                'name' => $request->name,
                'category' => $request->category,
                'subject' => $request->subject,
                'body' => $request->body,
                'variables' => $request->variables,
                'status' => $request->boolean('status', true),
                'created_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'email_template_created',
                description: "Created email template '{$template->code}' ({$template->name}).",
                auditable: $template,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Email template '{$template->name}' created successfully.",
                'template' => $this->transformEmailTemplate($template),
            ], null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('CommunicationApiController::storeEmailTemplate failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_CREATE_FAILED', 'Failed to create email template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Show single Email message template by ID or code.
     */
    public function showEmailTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findEmailTemplate($identifier, true);
            if (! $template) {
                return $this->error('NOT_FOUND', "Email template '{$identifier}' was not found.", 404);
            }

            return $this->success($this->transformEmailTemplate($template));
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::showEmailTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_FETCH_FAILED', 'Failed to retrieve email template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Update an existing Email message template.
     */
    public function updateEmailTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findEmailTemplate($identifier);
            if (! $template) {
                return $this->error('NOT_FOUND', "Email template '{$identifier}' was not found.", 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:100',
                'category' => 'sometimes|required|string|in:'.implode(',', array_keys(EmailTemplate::CATEGORIES)),
                'subject' => 'sometimes|required|string|max:255',
                'body' => 'sometimes|required|string',
                'variables' => 'nullable|array',
                'variables.*' => 'string',
                'status' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
            }

            $actor = $this->resolveUser($request);
            $oldValues = $template->only(['name', 'category', 'subject', 'status']);

            DB::beginTransaction();
            $template->fill($request->only(['name', 'category', 'subject', 'body', 'variables', 'status']));
            $template->updated_by = $actor?->id;
            $template->save();

            AuditLogService::log(
                event: 'email_template_updated',
                description: "Updated email template '{$template->code}'.",
                auditable: $template,
                oldValues: $oldValues,
                newValues: $template->only(['name', 'category', 'subject', 'status']),
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Email template '{$template->name}' updated successfully.",
                'template' => $this->transformEmailTemplate($template),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::updateEmailTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_UPDATE_FAILED', 'Failed to update email template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete an Email template.
     */
    public function destroyEmailTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findEmailTemplate($identifier);
            if (! $template) {
                return $this->error('NOT_FOUND', "Email template '{$identifier}' was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $template->deleted_by = $actor?->id;
            $template->save();
            $template->delete();

            AuditLogService::log(
                event: 'email_template_deleted',
                description: "Soft deleted email template '{$template->code}'.",
                auditable: $template,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Email template '{$template->code}' moved to trash successfully.",
                'identifier' => $identifier,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::destroyEmailTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_DELETE_FAILED', 'Failed to delete email template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Restore a soft deleted Email template.
     */
    public function restoreEmailTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findEmailTemplate($identifier, true);
            if (! $template || ! $template->trashed()) {
                return $this->error('NOT_FOUND', "Trashed email template '{$identifier}' was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $template->restore();
            $template->deleted_by = null;
            $template->updated_by = $actor?->id;
            $template->save();

            AuditLogService::log(
                event: 'email_template_restored',
                description: "Restored email template '{$template->code}' from trash.",
                auditable: $template,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "Email template '{$template->code}' restored successfully.",
                'template' => $this->transformEmailTemplate($template),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::restoreEmailTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_RESTORE_FAILED', 'Failed to restore email template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Preview dynamic rendered output of an Email template with test variables.
     */
    public function previewEmailTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findEmailTemplate($identifier, true);
            if (! $template) {
                return $this->error('NOT_FOUND', "Email template '{$identifier}' was not found.", 404);
            }

            $variables = (array) $request->input('variables', [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'otp' => '482910',
                'batch_name' => 'UPSC Civil Services 2026 Batch A',
            ]);

            $renderedSubject = $this->communicationService->personalizeString($template->subject, $variables);
            $renderedBody = $this->communicationService->personalizeString($template->body, $variables);

            return $this->success([
                'template_code' => $template->code,
                'name' => $template->name,
                'variables_supplied' => $variables,
                'rendered_subject' => $renderedSubject,
                'rendered_body' => $renderedBody,
            ]);
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::previewEmailTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('PREVIEW_FAILED', 'Failed to render email template preview: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  8. SMS Message Templates Management
     * ----------------------------------------------------------------- */

    /**
     * Catalog of SMS message templates with category & status filters.
     */
    public function smsTemplates(Request $request): JsonResponse
    {
        try {
            $query = SmsTemplate::with(['creator:id,name', 'editor:id,name']);

            if ($request->boolean('with_trashed') && $this->isStaff($request)) {
                $query->withTrashed();
            }

            if ($request->filled('category')) {
                $query->where('category', $request->category);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->boolean('status'));
            }
            if ($request->filled('sender_id')) {
                $query->where('sender_id', $request->sender_id);
            }
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('sender_id', 'like', "%{$search}%")
                        ->orWhere('dlt_template_id', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%");
                });
            }

            $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
            $paginator = $query->orderBy('name')->paginate($perPage);

            return $this->success(
                collect($paginator->items())->map(fn ($tmpl) => $this->transformSmsTemplate($tmpl)),
                [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'categories' => SmsTemplate::CATEGORIES,
                ]
            );
        } catch (Throwable $e) {
            Log::error('CommunicationApiController::smsTemplates failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATES_FETCH_FAILED', 'Failed to retrieve SMS templates: '.$e->getMessage(), 500);
        }
    }

    /**
     * Store a new SMS message template.
     */
    public function storeSmsTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:sms_templates,code|regex:/^[a-zA-Z0-9_\-\.]+$/',
            'name' => 'required|string|max:100',
            'category' => 'required|string|in:'.implode(',', array_keys(SmsTemplate::CATEGORIES)),
            'sender_id' => 'nullable|string|max:20',
            'dlt_template_id' => 'nullable|string|max:100',
            'body' => 'required|string',
            'variables' => 'nullable|array',
            'variables.*' => 'string',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $template = SmsTemplate::create([
                'code' => Str::slug($request->code, '_'),
                'name' => $request->name,
                'category' => $request->category,
                'sender_id' => $request->sender_id,
                'dlt_template_id' => $request->dlt_template_id,
                'body' => $request->body,
                'variables' => $request->variables,
                'status' => $request->boolean('status', true),
                'created_by' => $actor?->id,
            ]);

            AuditLogService::log(
                event: 'sms_template_created',
                description: "Created SMS template '{$template->code}' ({$template->name}).",
                auditable: $template,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "SMS template '{$template->name}' created successfully.",
                'template' => $this->transformSmsTemplate($template),
            ], null, 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('CommunicationApiController::storeSmsTemplate failed: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_CREATE_FAILED', 'Failed to create SMS template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Show single SMS message template by ID or code.
     */
    public function showSmsTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findSmsTemplate($identifier, true);
            if (! $template) {
                return $this->error('NOT_FOUND', "SMS template '{$identifier}' was not found.", 404);
            }

            return $this->success($this->transformSmsTemplate($template));
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::showSmsTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_FETCH_FAILED', 'Failed to retrieve SMS template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Update an existing SMS message template.
     */
    public function updateSmsTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findSmsTemplate($identifier);
            if (! $template) {
                return $this->error('NOT_FOUND', "SMS template '{$identifier}' was not found.", 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:100',
                'category' => 'sometimes|required|string|in:'.implode(',', array_keys(SmsTemplate::CATEGORIES)),
                'sender_id' => 'nullable|string|max:20',
                'dlt_template_id' => 'nullable|string|max:100',
                'body' => 'sometimes|required|string',
                'variables' => 'nullable|array',
                'variables.*' => 'string',
                'status' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
            }

            $actor = $this->resolveUser($request);
            $oldValues = $template->only(['name', 'category', 'sender_id', 'dlt_template_id', 'status']);

            DB::beginTransaction();
            $template->fill($request->only(['name', 'category', 'sender_id', 'dlt_template_id', 'body', 'variables', 'status']));
            $template->updated_by = $actor?->id;
            $template->save();

            AuditLogService::log(
                event: 'sms_template_updated',
                description: "Updated SMS template '{$template->code}'.",
                auditable: $template,
                oldValues: $oldValues,
                newValues: $template->only(['name', 'category', 'sender_id', 'dlt_template_id', 'status']),
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "SMS template '{$template->name}' updated successfully.",
                'template' => $this->transformSmsTemplate($template),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::updateSmsTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_UPDATE_FAILED', 'Failed to update SMS template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete an SMS template.
     */
    public function destroySmsTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findSmsTemplate($identifier);
            if (! $template) {
                return $this->error('NOT_FOUND', "SMS template '{$identifier}' was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $template->deleted_by = $actor?->id;
            $template->save();
            $template->delete();

            AuditLogService::log(
                event: 'sms_template_deleted',
                description: "Soft deleted SMS template '{$template->code}'.",
                auditable: $template,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "SMS template '{$template->code}' moved to trash successfully.",
                'identifier' => $identifier,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::destroySmsTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_DELETE_FAILED', 'Failed to delete SMS template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Restore a soft deleted SMS template.
     */
    public function restoreSmsTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findSmsTemplate($identifier, true);
            if (! $template || ! $template->trashed()) {
                return $this->error('NOT_FOUND', "Trashed SMS template '{$identifier}' was not found.", 404);
            }

            $actor = $this->resolveUser($request);

            DB::beginTransaction();
            $template->restore();
            $template->deleted_by = null;
            $template->updated_by = $actor?->id;
            $template->save();

            AuditLogService::log(
                event: 'sms_template_restored',
                description: "Restored SMS template '{$template->code}' from trash.",
                auditable: $template,
                userId: $actor?->id
            );
            DB::commit();

            return $this->success([
                'message' => "SMS template '{$template->code}' restored successfully.",
                'template' => $this->transformSmsTemplate($template),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("CommunicationApiController::restoreSmsTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('TEMPLATE_RESTORE_FAILED', 'Failed to restore SMS template: '.$e->getMessage(), 500);
        }
    }

    /**
     * Preview dynamic rendered output of an SMS template with test variables.
     */
    public function previewSmsTemplate(Request $request, string $identifier): JsonResponse
    {
        try {
            $template = $this->findSmsTemplate($identifier, true);
            if (! $template) {
                return $this->error('NOT_FOUND', "SMS template '{$identifier}' was not found.", 404);
            }

            $variables = (array) $request->input('variables', [
                'name' => 'Jane Aspirant',
                'otp' => '592014',
                'date' => Carbon::now()->format('d M Y'),
                'time' => Carbon::now()->format('h:i A'),
            ]);

            $renderedBody = $this->communicationService->personalizeString($template->body, $variables);

            return $this->success([
                'template_code' => $template->code,
                'name' => $template->name,
                'sender_id' => $template->sender_id,
                'dlt_template_id' => $template->dlt_template_id,
                'variables_supplied' => $variables,
                'rendered_body' => $renderedBody,
                'character_count' => mb_strlen($renderedBody),
            ]);
        } catch (Throwable $e) {
            Log::error("CommunicationApiController::previewSmsTemplate failed for '{$identifier}': ".$e->getMessage(), ['exception' => $e]);

            return $this->error('PREVIEW_FAILED', 'Failed to render SMS template preview: '.$e->getMessage(), 500);
        }
    }

    /* ----------------------------------------------------------------- *
     *  Internal Resolvers and Transformers
     * ----------------------------------------------------------------- */

    protected function findSubscriber(string $identifier, bool $withTrashed = false): ?Subscriber
    {
        $query = $withTrashed ? Subscriber::withTrashed() : Subscriber::query();

        if (is_numeric($identifier)) {
            return $query->where('id', (int) $identifier)->first();
        }

        if (Str::isUuid($identifier)) {
            return $query->where('uuid', $identifier)->first();
        }

        return $query->where('email', strtolower($identifier))
            ->orWhere('phone', $identifier)
            ->first();
    }

    protected function findEmailTemplate(string $identifier, bool $withTrashed = false): ?EmailTemplate
    {
        $query = $withTrashed ? EmailTemplate::withTrashed() : EmailTemplate::query();

        if (is_numeric($identifier)) {
            return $query->where('id', (int) $identifier)->first();
        }

        return $query->where('code', $identifier)->first();
    }

    protected function findSmsTemplate(string $identifier, bool $withTrashed = false): ?SmsTemplate
    {
        $query = $withTrashed ? SmsTemplate::withTrashed() : SmsTemplate::query();

        if (is_numeric($identifier)) {
            return $query->where('id', (int) $identifier)->first();
        }

        return $query->where('code', $identifier)->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformLogSummary(CommunicationLog $log): array
    {
        return [
            'id' => $log->id,
            'channel' => $log->channel,
            'type' => $log->type,
            'recipient' => $log->recipient,
            'recipient_name' => $log->recipient_name,
            'subject' => $log->subject,
            'status' => $log->status,
            'sent_at' => $log->sent_at?->toIso8601String(),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformLogDetail(CommunicationLog $log): array
    {
        return [
            'id' => $log->id,
            'channel' => $log->channel,
            'type' => $log->type,
            'recipient' => $log->recipient,
            'recipient_name' => $log->recipient_name,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
                'phone' => $log->user->phone,
            ] : null,
            'template_code' => $log->template_code,
            'subject' => $log->subject,
            'content' => $log->content,
            'variables' => $log->variables,
            'metadata' => $log->metadata,
            'status' => $log->status,
            'error_message' => $log->error_message,
            'resend_count' => $log->resend_count,
            'last_resent_at' => $log->last_resent_at?->toIso8601String(),
            'sent_at' => $log->sent_at?->toIso8601String(),
            'delivered_at' => $log->delivered_at?->toIso8601String(),
            'failed_at' => $log->failed_at?->toIso8601String(),
            'sender' => $log->sender ? [
                'id' => $log->sender->id,
                'name' => $log->sender->name,
            ] : null,
            'campaign' => $log->campaign ? [
                'id' => $log->campaign->id,
                'title' => $log->campaign->title,
            ] : null,
            'created_at' => $log->created_at?->toIso8601String(),
            'updated_at' => $log->updated_at?->toIso8601String(),
            'deleted_at' => $log->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformCampaign(CommunicationCampaign $campaign, bool $includeLogs = false): array
    {
        $payload = [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'channel' => $campaign->channel,
            'recipient_type' => $campaign->recipient_type,
            'recipient_role' => $campaign->recipient_role,
            'recipient_ids' => $campaign->recipient_ids,
            'template_code' => $campaign->template_code,
            'subject' => $campaign->subject,
            'content' => $campaign->content,
            'status' => $campaign->status,
            'total_recipients' => $campaign->total_recipients,
            'sent_count' => $campaign->sent_count,
            'failed_count' => $campaign->failed_count,
            'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
            'sent_at' => $campaign->sent_at?->toIso8601String(),
            'creator' => $campaign->creator ? [
                'id' => $campaign->creator->id,
                'name' => $campaign->creator->name,
            ] : null,
            'updater' => $campaign->updater ? [
                'id' => $campaign->updater->id,
                'name' => $campaign->updater->name,
            ] : null,
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
            'deleted_at' => $campaign->deleted_at?->toIso8601String(),
        ];

        if ($includeLogs && $campaign->relationLoaded('logs')) {
            $payload['recent_logs'] = $campaign->logs->map(fn ($l) => $this->transformLogSummary($l));
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformSubscriber(Subscriber $subscriber): array
    {
        return [
            'id' => $subscriber->id,
            'uuid' => $subscriber->uuid,
            'type' => $subscriber->type,
            'type_label' => $subscriber->typeLabel(),
            'name' => $subscriber->name,
            'email' => $subscriber->email,
            'country_code' => $subscriber->country_code,
            'phone' => $subscriber->phone,
            'formatted_phone' => $subscriber->formattedPhone(),
            'status' => $subscriber->status,
            'status_label' => $subscriber->statusLabel(),
            'source' => $subscriber->source,
            'tags' => $subscriber->tags ?? [],
            'subscribed_at' => $subscriber->subscribed_at?->toIso8601String(),
            'unsubscribed_at' => $subscriber->unsubscribed_at?->toIso8601String(),
            'unsubscribe_reason' => $subscriber->unsubscribe_reason,
            'metadata' => $subscriber->metadata,
            'created_at' => $subscriber->created_at?->toIso8601String(),
            'updated_at' => $subscriber->updated_at?->toIso8601String(),
            'deleted_at' => $subscriber->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformEmailTemplate(EmailTemplate $template): array
    {
        return [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'category' => $template->category,
            'category_label' => EmailTemplate::CATEGORIES[$template->category] ?? ucfirst($template->category),
            'subject' => $template->subject,
            'body' => $template->body,
            'variables' => $template->variables ?? [],
            'status' => (bool) $template->status,
            'creator' => $template->creator ? ['id' => $template->creator->id, 'name' => $template->creator->name] : null,
            'editor' => $template->editor ? ['id' => $template->editor->id, 'name' => $template->editor->name] : null,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'deleted_at' => $template->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformSmsTemplate(SmsTemplate $template): array
    {
        return [
            'id' => $template->id,
            'code' => $template->code,
            'name' => $template->name,
            'category' => $template->category,
            'category_label' => SmsTemplate::CATEGORIES[$template->category] ?? ucfirst($template->category),
            'sender_id' => $template->sender_id,
            'dlt_template_id' => $template->dlt_template_id,
            'body' => $template->body,
            'variables' => $template->variables ?? [],
            'status' => (bool) $template->status,
            'creator' => $template->creator ? ['id' => $template->creator->id, 'name' => $template->creator->name] : null,
            'editor' => $template->editor ? ['id' => $template->editor->id, 'name' => $template->editor->name] : null,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'deleted_at' => $template->deleted_at?->toIso8601String(),
        ];
    }
}
