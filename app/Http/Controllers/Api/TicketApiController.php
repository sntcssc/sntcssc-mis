<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCannedResponse;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TicketApiController extends Controller
{
    public function __construct(
        protected TicketService $ticketService
    ) {}

    /**
     * Resolve the active authenticated user context (via Sanctum/token, session, or API key owner).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Check if the authenticated actor has support staff / administrative privileges.
     */
    protected function isStaff(Request $request): bool
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey) {
            if ($apiKey->hasScope('manage:tickets') || $apiKey->hasScope('*')) {
                return true;
            }
        }

        $user = $this->resolveUser($request);
        if ($user) {
            if ($user->hasRole('Super Administrator') || $user->hasRole('Administrator') || $user->can('tickets.manage') || $user->can('tickets.view') || $user->can('tickets.reply')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Standard JSON success response envelope.
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
     * Standard JSON error response envelope.
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

    /**
     * Find a ticket by ID, UUID, or ticket_number.
     */
    protected function findTicket(string $identifier, bool $withTrashed = false): ?Ticket
    {
        $query = $withTrashed ? Ticket::withTrashed() : Ticket::query();

        if (is_numeric($identifier)) {
            return $query->where('id', (int) $identifier)->first();
        }

        if (Str::isUuid($identifier)) {
            return $query->where('uuid', $identifier)->first();
        }

        return $query->where('ticket_number', $identifier)->first();
    }

    /**
     * 1. Support Tickets Dashboard & Telemetry Overview
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $isStaff = $this->isStaff($request);
            $user = $this->resolveUser($request);

            $query = Ticket::query();
            if (! $isStaff && $user) {
                $query->where('user_id', $user->id);
            }

            $total = (clone $query)->count();
            $open = (clone $query)->where('status', Ticket::STATUS_OPEN)->count();
            $inProgress = (clone $query)->where('status', Ticket::STATUS_IN_PROGRESS)->count();
            $pendingUser = (clone $query)->where('status', Ticket::STATUS_PENDING_USER)->count();
            $onHold = (clone $query)->where('status', Ticket::STATUS_ON_HOLD)->count();
            $resolved = (clone $query)->where('status', Ticket::STATUS_RESOLVED)->count();
            $closed = (clone $query)->where('status', Ticket::STATUS_CLOSED)->count();

            $activeCount = $open + $inProgress + $pendingUser + $onHold;
            $urgentHigh = (clone $query)->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS])
                ->whereIn('priority', [Ticket::PRIORITY_URGENT, Ticket::PRIORITY_HIGH])
                ->count();

            $slaBreachedResponse = (clone $query)->where('is_sla_response_breached', true)->count();
            $slaBreachedResolution = (clone $query)->where('is_sla_resolution_breached', true)->count();

            $averageSatisfaction = (float) ((clone $query)->whereNotNull('satisfaction_rating')->avg('satisfaction_rating') ?? 0);
            $totalRated = (clone $query)->whereNotNull('satisfaction_rating')->count();

            $categories = TicketCategory::active()
                ->withCount(['tickets' => function ($q) use ($isStaff, $user) {
                    if (! $isStaff && $user) {
                        $q->where('user_id', $user->id);
                    }
                }])
                ->get(['id', 'name', 'slug', 'color_badge', 'icon', 'default_priority']);

            $recentTickets = (clone $query)->with(['category:id,name,color_badge', 'user:id,name,email'])
                ->latest('last_reply_at')
                ->limit(5)
                ->get(['id', 'ticket_number', 'uuid', 'subject', 'status', 'priority', 'category_id', 'user_id', 'guest_name', 'last_reply_at', 'created_at']);

            return $this->success([
                'counts' => [
                    'total' => $total,
                    'active' => $activeCount,
                    'open' => $open,
                    'in_progress' => $inProgress,
                    'pending_user' => $pendingUser,
                    'on_hold' => $onHold,
                    'resolved' => $resolved,
                    'closed' => $closed,
                    'urgent_high' => $urgentHigh,
                ],
                'sla' => [
                    'response_breaches' => $slaBreachedResponse,
                    'resolution_breaches' => $slaBreachedResolution,
                    'total_breaches' => $slaBreachedResponse + $slaBreachedResolution,
                ],
                'satisfaction' => [
                    'average' => round($averageSatisfaction, 2),
                    'total_ratings' => $totalRated,
                ],
                'categories' => $categories,
                'recent_activity' => $recentTickets,
            ]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@dashboard error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->error('DASHBOARD_ERROR', 'Failed to retrieve ticket dashboard telemetry: '.$e->getMessage(), 500);
        }
    }

    /**
     * 2. Comprehensive Statistics & Analytics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $isStaff = $this->isStaff($request);
            $user = $this->resolveUser($request);

            $query = Ticket::query();
            if (! $isStaff && $user) {
                $query->where('user_id', $user->id);
            }

            // Priority distribution
            $priorities = (clone $query)
                ->select('priority', DB::raw('count(*) as count'))
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray();

            // Status distribution
            $statuses = (clone $query)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();

            // Source breakdown
            $sources = (clone $query)
                ->select('source', DB::raw('count(*) as count'))
                ->groupBy('source')
                ->pluck('count', 'source')
                ->toArray();

            // Monthly volume trend (past 6 months)
            $driver = DB::connection()->getDriverName();
            $monthSql = match ($driver) {
                'sqlite' => "strftime('%Y-%m', created_at)",
                'pgsql' => "to_char(created_at, 'YYYY-MM')",
                'sqlsrv' => "FORMAT(created_at, 'yyyy-MM')",
                default => 'DATE_FORMAT(created_at, "%Y-%m")',
            };

            $monthlyTrend = (clone $query)
                ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
                ->select(DB::raw("{$monthSql} as month"), DB::raw('count(*) as count'))
                ->groupBy('month')
                ->orderBy('month', 'asc')
                ->pluck('count', 'month')
                ->toArray();

            // Satisfaction rating breakdown (1 to 5 stars)
            $ratingsBreakdown = (clone $query)
                ->whereNotNull('satisfaction_rating')
                ->select('satisfaction_rating', DB::raw('count(*) as count'))
                ->groupBy('satisfaction_rating')
                ->pluck('count', 'satisfaction_rating')
                ->toArray();

            return $this->success([
                'priorities' => [
                    Ticket::PRIORITY_URGENT => $priorities[Ticket::PRIORITY_URGENT] ?? 0,
                    Ticket::PRIORITY_HIGH => $priorities[Ticket::PRIORITY_HIGH] ?? 0,
                    Ticket::PRIORITY_MEDIUM => $priorities[Ticket::PRIORITY_MEDIUM] ?? 0,
                    Ticket::PRIORITY_LOW => $priorities[Ticket::PRIORITY_LOW] ?? 0,
                ],
                'statuses' => $statuses,
                'sources' => $sources,
                'monthly_trend' => $monthlyTrend,
                'ratings' => [
                    '5_stars' => $ratingsBreakdown[5] ?? 0,
                    '4_stars' => $ratingsBreakdown[4] ?? 0,
                    '3_stars' => $ratingsBreakdown[3] ?? 0,
                    '2_stars' => $ratingsBreakdown[2] ?? 0,
                    '1_star' => $ratingsBreakdown[1] ?? 0,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@statistics error: '.$e->getMessage());

            return $this->error('STATISTICS_ERROR', 'Failed to retrieve ticket statistics: '.$e->getMessage(), 500);
        }
    }

    /**
     * 3. Paginated Ticket List with Full Search and Filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $isStaff = $this->isStaff($request);
            $user = $this->resolveUser($request);

            $query = Ticket::with([
                'category:id,name,slug,color_badge,icon',
                'user:id,name,email',
                'assignedTo:id,name,email',
                'lastReplyBy:id,name',
            ])->withCount(['messages', 'attachments']);

            // Non-staff callers can only query their own tickets
            if (! $isStaff) {
                if (! $user) {
                    return $this->error('UNAUTHENTICATED', 'Authentication is required to query tickets.', 401);
                }
                $query->where('user_id', $user->id);
            } elseif ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->input('user_id'));
            }

            // Text search
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('guest_name', 'like', "%{$search}%")
                        ->orWhere('guest_email', 'like', "%{$search}%");
                });
            }

            // Status filter
            if ($status = $request->input('status')) {
                if ($status === 'active' || $status === 'open_all') {
                    $query->open();
                } elseif ($status === 'closed_all') {
                    $query->closed();
                } else {
                    $query->where('status', $status);
                }
            }

            // Priority filter
            if ($priority = $request->input('priority')) {
                $query->where('priority', $priority);
            }

            // Category filter
            if ($categoryId = $request->input('category_id')) {
                $query->where('category_id', $categoryId);
            }

            // Assignee filter (staff only)
            if ($isStaff && $request->filled('assigned_to_user_id')) {
                $assignee = $request->input('assigned_to_user_id');
                if ($assignee === 'unassigned' || $assignee === 'none' || $assignee === '0') {
                    $query->whereNull('assigned_to_user_id');
                } else {
                    $query->where('assigned_to_user_id', (int) $assignee);
                }
            }

            // SLA breach filter
            if ($sla = $request->input('sla')) {
                if ($sla === 'breached') {
                    $query->where(function (Builder $q) {
                        $q->where('is_sla_response_breached', true)
                            ->orWhere('is_sla_resolution_breached', true);
                    });
                } elseif ($sla === 'response_breached') {
                    $query->where('is_sla_response_breached', true);
                } elseif ($sla === 'resolution_breached') {
                    $query->where('is_sla_resolution_breached', true);
                } elseif ($sla === 'due_today') {
                    $todayEnd = now()->endOfDay();
                    $query->open()->where(function (Builder $q) use ($todayEnd) {
                        $q->whereBetween('first_response_due_at', [now(), $todayEnd])
                            ->orWhereBetween('resolution_due_at', [now(), $todayEnd]);
                    });
                }
            }

            // Date range filter
            if ($dateFrom = $request->input('date_from')) {
                $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo = $request->input('date_to')) {
                $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'last_reply_at');
            $sortDirection = strtolower($request->input('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

            $allowedSorts = ['id', 'ticket_number', 'created_at', 'last_reply_at', 'priority', 'status', 'first_response_due_at', 'resolution_due_at'];
            if (in_array($sortBy, $allowedSorts, true)) {
                $query->orderBy($sortBy, $sortDirection);
            } else {
                $query->orderBy('last_reply_at', 'desc');
            }

            $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
            $paginator = $query->paginate($perPage);

            return $this->success($paginator->items(), [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@index error: '.$e->getMessage());

            return $this->error('INDEX_ERROR', 'Failed to retrieve tickets: '.$e->getMessage(), 500);
        }
    }

    /**
     * 4. Student / User Self-Service: My Tickets
     */
    public function myTickets(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            if (! $user) {
                return $this->error('UNAUTHENTICATED', 'Authentication required for user tickets.', 401);
            }

            $query = Ticket::forUser($user)
                ->with([
                    'category:id,name,slug,color_badge,icon',
                    'assignedTo:id,name',
                ])
                ->withCount(['messages' => function ($q) {
                    $q->where('type', TicketMessage::TYPE_PUBLIC_REPLY);
                }, 'attachments']);

            if ($status = $request->input('status')) {
                if ($status === 'active') {
                    $query->open();
                } elseif ($status === 'closed') {
                    $query->closed();
                } else {
                    $query->where('status', $status);
                }
            }

            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $perPage = min(50, max(1, (int) $request->input('per_page', 10)));
            $paginator = $query->latest('last_reply_at')->paginate($perPage);

            return $this->success($paginator->items(), [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@myTickets error: '.$e->getMessage());

            return $this->error('MY_TICKETS_ERROR', 'Failed to retrieve personal tickets: '.$e->getMessage(), 500);
        }
    }

    /**
     * 5. Create a New Support Ticket
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:10'],
            'category_id' => ['nullable', 'exists:ticket_categories,id'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_email' => ['nullable', 'email', 'max:150'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'source' => ['nullable', 'string', 'in:portal,email,admin,api,mobile_app,desktop_app'],
            'metadata' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,webp,zip'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Ticket validation failed.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $isStaff = $this->isStaff($request);

            $data = $validator->validated();

            // Set source
            if (empty($data['source'])) {
                $data['source'] = $request->header('X-Platform', Ticket::SOURCE_API);
            }

            // Staff can submit on behalf of specific user
            if ($isStaff && $request->filled('user_id')) {
                $data['user_id'] = (int) $request->input('user_id');
            } elseif ($user) {
                $data['user_id'] = $user->id;
            }

            /** @var array<UploadedFile> $uploadedFiles */
            $uploadedFiles = $request->file('attachments', []);

            $ticket = $this->ticketService->createTicket(
                data: $data,
                creator: $user,
                uploadedFiles: is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles]
            );

            DB::commit();

            $ticket->load([
                'category',
                'user:id,name,email',
                'assignedTo:id,name,email',
                'messages.attachments',
            ]);

            return $this->success($ticket, [
                'message' => 'Support ticket created successfully.',
                'ticket_number' => $ticket->ticket_number,
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@store error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->error('STORE_ERROR', 'Failed to create support ticket: '.$e->getMessage(), 500);
        }
    }

    /**
     * 6. Show Single Ticket Dossier & Conversation Thread
     */
    public function show(string $identifier, Request $request): JsonResponse
    {
        try {
            $ticket = $this->findTicket($identifier);

            if (! $ticket) {
                return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
            }

            $user = $this->resolveUser($request);
            $isStaff = $this->isStaff($request);

            // Authorization: non-staff can only view their own ticket
            if (! $isStaff) {
                if (! $user || $ticket->user_id !== $user->id) {
                    return $this->error('FORBIDDEN', 'You do not have permission to view this ticket.', 403);
                }
            }

            $messagesQuery = $ticket->messages()->with(['user:id,name,email', 'attachments']);
            if (! $isStaff) {
                // Hide internal staff notes from regular users
                $messagesQuery->where('type', '!=', TicketMessage::TYPE_INTERNAL_NOTE);
            }

            $messages = $messagesQuery->get();

            $ticket->setRelation('messages', $messages);
            $ticket->load([
                'category',
                'user:id,name,email,phone',
                'assignedTo:id,name,email',
                'lastReplyBy:id,name',
                'attachments.uploader:id,name',
            ]);

            return $this->success($ticket, [
                'is_staff_view' => $isStaff,
                'can_reply' => $ticket->isOpen() || $isStaff,
            ]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@show error: '.$e->getMessage());

            return $this->error('SHOW_ERROR', 'Failed to retrieve ticket dossier: '.$e->getMessage(), 500);
        }
    }

    /**
     * 7. Update Ticket Metadata (Subject, Category, Priority, Guest Info)
     */
    public function update(string $identifier, Request $request): JsonResponse
    {
        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $user = $this->resolveUser($request);
        $isStaff = $this->isStaff($request);

        if (! $isStaff && (! $user || $ticket->user_id !== $user->id)) {
            return $this->error('FORBIDDEN', 'You do not have permission to modify this ticket.', 403);
        }

        $validator = Validator::make($request->all(), [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:ticket_categories,id'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_email' => ['nullable', 'email', 'max:150'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $oldValues = $ticket->only(['subject', 'category_id', 'priority', 'guest_name', 'guest_email']);
            $data = $validator->validated();

            // Non-staff cannot change priority
            if (! $isStaff && isset($data['priority'])) {
                unset($data['priority']);
            }

            $ticket->update($data);

            AuditLogService::log(
                event: 'ticket_updated',
                description: "Updated ticket #{$ticket->ticket_number} metadata.",
                oldValues: $oldValues,
                newValues: $ticket->only(array_keys($data)),
                userId: $user?->id
            );

            DB::commit();

            return $this->success($ticket->fresh(['category', 'assignedTo']), [
                'message' => 'Ticket updated successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@update error: '.$e->getMessage());

            return $this->error('UPDATE_ERROR', 'Failed to update ticket: '.$e->getMessage(), 500);
        }
    }

    /**
     * 8. Soft Delete a Support Ticket
     */
    public function destroy(string $identifier, Request $request): JsonResponse
    {
        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $user = $this->resolveUser($request);
        $isStaff = $this->isStaff($request);

        if (! $isStaff && (! $user || $ticket->user_id !== $user->id)) {
            return $this->error('FORBIDDEN', 'You do not have permission to delete this ticket.', 403);
        }

        DB::beginTransaction();
        try {
            $this->ticketService->deleteTicket($ticket, $user);
            DB::commit();

            return $this->success([
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'deleted' => true,
            ], ['message' => "Ticket #{$ticket->ticket_number} has been soft-deleted."]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@destroy error: '.$e->getMessage());

            return $this->error('DESTROY_ERROR', 'Failed to delete ticket: '.$e->getMessage(), 500);
        }
    }

    /**
     * 9. Restore a Soft-Deleted Ticket
     */
    public function restore(string $identifier, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can restore deleted tickets.', 403);
        }

        $ticket = $this->findTicket($identifier, true);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        if (! $ticket->trashed()) {
            return $this->error('ALREADY_ACTIVE', 'This ticket is not deleted.', 400);
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $ticket->restore();
            $ticket->update(['deleted_by' => null]);

            AuditLogService::log(
                event: 'ticket_restored',
                description: "Restored support ticket #{$ticket->ticket_number}.",
                userId: $user?->id
            );

            DB::commit();

            return $this->success($ticket->fresh(), [
                'message' => "Ticket #{$ticket->ticket_number} restored successfully.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@restore error: '.$e->getMessage());

            return $this->error('RESTORE_ERROR', 'Failed to restore ticket: '.$e->getMessage(), 500);
        }
    }

    /**
     * 10. Update Ticket Status (Open, In Progress, Pending User, On Hold, Resolved, Closed)
     */
    public function updateStatus(string $identifier, Request $request): JsonResponse
    {
        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:open,in_progress,pending_user,on_hold,resolved,closed'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid status.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);
        $isStaff = $this->isStaff($request);
        $newStatus = $request->input('status');

        // Customer can only close/resolve or reopen their own ticket
        if (! $isStaff) {
            if (! $user || $ticket->user_id !== $user->id) {
                return $this->error('FORBIDDEN', 'Permission denied.', 403);
            }
            if (! in_array($newStatus, [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED, Ticket::STATUS_OPEN], true)) {
                return $this->error('FORBIDDEN', 'End users may only resolve, close, or reopen tickets.', 403);
            }
        }

        DB::beginTransaction();
        try {
            $oldStatus = $ticket->status;
            $success = $this->ticketService->updateStatus(
                ticket: $ticket,
                newStatus: $newStatus,
                actor: $user,
                reason: $request->input('reason')
            );

            if (! $success) {
                DB::rollBack();

                return $this->error('STATUS_UPDATE_FAILED', 'Could not update ticket status.', 400);
            }

            DB::commit();

            return $this->success($ticket->fresh(), [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'message' => "Ticket status updated to '{$newStatus}'.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@updateStatus error: '.$e->getMessage());

            return $this->error('STATUS_ERROR', 'Failed to update ticket status: '.$e->getMessage(), 500);
        }
    }

    /**
     * 11. Update Ticket Priority
     */
    public function updatePriority(string $identifier, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only support staff can alter ticket priority.', 403);
        }

        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'priority' => ['required', 'string', 'in:low,medium,high,urgent'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid priority value.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $newPriority = $request->input('priority');
            $oldPriority = $ticket->priority;

            $this->ticketService->updatePriority($ticket, $newPriority, $user);

            DB::commit();

            return $this->success($ticket->fresh(), [
                'old_priority' => $oldPriority,
                'new_priority' => $newPriority,
                'message' => "Ticket priority changed from '{$oldPriority}' to '{$newPriority}'.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@updatePriority error: '.$e->getMessage());

            return $this->error('PRIORITY_ERROR', 'Failed to update priority: '.$e->getMessage(), 500);
        }
    }

    /**
     * 12. Assign or Reassign Ticket to Agent User
     */
    public function assign(string $identifier, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only support staff can assign tickets.', 403);
        }

        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid assignee ID.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $actor = $this->resolveUser($request);
            $assigneeId = $request->input('assigned_to_user_id');
            $assignee = $assigneeId ? User::find($assigneeId) : null;

            $this->ticketService->assignTicket($ticket, $assignee, $actor);

            DB::commit();

            return $this->success($ticket->fresh(['assignedTo']), [
                'assigned_to' => $assignee?->name ?? 'Unassigned',
                'message' => $assignee ? "Ticket assigned to {$assignee->name}." : 'Ticket unassigned.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@assign error: '.$e->getMessage());

            return $this->error('ASSIGN_ERROR', 'Failed to assign ticket: '.$e->getMessage(), 500);
        }
    }

    /**
     * 13. Get Messages Thread for a Ticket
     */
    public function messages(string $identifier, Request $request): JsonResponse
    {
        try {
            $ticket = $this->findTicket($identifier);
            if (! $ticket) {
                return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
            }

            $user = $this->resolveUser($request);
            $isStaff = $this->isStaff($request);

            if (! $isStaff && (! $user || $ticket->user_id !== $user->id)) {
                return $this->error('FORBIDDEN', 'Permission denied.', 403);
            }

            $query = $ticket->messages()->with(['user:id,name,email', 'attachments'])->orderBy('created_at', 'asc');
            if (! $isStaff) {
                $query->where('type', '!=', TicketMessage::TYPE_INTERNAL_NOTE);
            }

            $messages = $query->get();

            return $this->success($messages, [
                'ticket_number' => $ticket->ticket_number,
                'total_messages' => $messages->count(),
            ]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@messages error: '.$e->getMessage());

            return $this->error('MESSAGES_ERROR', 'Failed to retrieve messages: '.$e->getMessage(), 500);
        }
    }

    /**
     * 14. Post Reply or Internal Staff Note to Ticket
     */
    public function storeMessage(string $identifier, Request $request): JsonResponse
    {
        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $user = $this->resolveUser($request);
        $isStaff = $this->isStaff($request);

        if (! $isStaff && (! $user || $ticket->user_id !== $user->id)) {
            return $this->error('FORBIDDEN', 'Permission denied.', 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'min:2'],
            'type' => ['nullable', 'in:public_reply,internal_note'],
            'new_status' => ['nullable', 'in:open,in_progress,pending_user,on_hold,resolved,closed'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,webp,zip'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();

        // Non-staff callers cannot post internal notes
        if (! $isStaff && ($data['type'] ?? '') === TicketMessage::TYPE_INTERNAL_NOTE) {
            return $this->error('FORBIDDEN', 'Only staff can post internal notes.', 403);
        }

        DB::beginTransaction();
        try {
            /** @var array<UploadedFile> $uploadedFiles */
            $uploadedFiles = $request->file('attachments', []);

            $message = $this->ticketService->replyTicket(
                ticket: $ticket,
                data: $data,
                sender: $user,
                uploadedFiles: is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles]
            );

            DB::commit();

            $message->load(['user:id,name,email', 'attachments']);

            return $this->success($message, [
                'ticket_status' => $ticket->fresh()->status,
                'message' => 'Message posted successfully.',
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@storeMessage error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->error('REPLY_ERROR', 'Failed to post message: '.$e->getMessage(), 500);
        }
    }

    /**
     * 15. Delete a Message from Thread
     */
    public function deleteMessage(string $identifier, int $messageId, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can delete messages.', 403);
        }

        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $message = TicketMessage::where('ticket_id', $ticket->id)->where('id', $messageId)->first();
        if (! $message) {
            return $this->error('MESSAGE_NOT_FOUND', "Message #{$messageId} was not found on ticket.", 404);
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $message->delete();

            AuditLogService::log(
                event: 'ticket_message_deleted',
                description: "Deleted message #{$messageId} from ticket #{$ticket->ticket_number}.",
                userId: $user?->id
            );

            DB::commit();

            return $this->success(['id' => $messageId, 'deleted' => true], [
                'message' => 'Message deleted successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@deleteMessage error: '.$e->getMessage());

            return $this->error('DELETE_MESSAGE_ERROR', 'Failed to delete message: '.$e->getMessage(), 500);
        }
    }

    /**
     * 16. Submit Customer Satisfaction Rating (1-5 Stars + Feedback)
     */
    public function rate(string $identifier, Request $request): JsonResponse
    {
        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $user = $this->resolveUser($request);
        $isStaff = $this->isStaff($request);

        if (! $isStaff && (! $user || $ticket->user_id !== $user->id)) {
            return $this->error('FORBIDDEN', 'Permission denied.', 403);
        }

        $validator = Validator::make($request->all(), [
            'rating' => ['required', 'integer', 'between:1,5'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid rating value (must be 1 to 5).', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $this->ticketService->rateTicket(
                ticket: $ticket,
                rating: (int) $request->input('rating'),
                feedback: $request->input('feedback'),
                user: $user
            );

            DB::commit();

            return $this->success($ticket->fresh(), [
                'message' => 'Thank you for your feedback! Rating recorded.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@rate error: '.$e->getMessage());

            return $this->error('RATING_ERROR', 'Failed to submit satisfaction rating: '.$e->getMessage(), 500);
        }
    }

    /**
     * 17. Upload File Attachments to Existing Ticket
     */
    public function uploadAttachments(string $identifier, Request $request): JsonResponse
    {
        $ticket = $this->findTicket($identifier);
        if (! $ticket) {
            return $this->error('TICKET_NOT_FOUND', "Ticket '{$identifier}' was not found.", 404);
        }

        $user = $this->resolveUser($request);
        $isStaff = $this->isStaff($request);

        if (! $isStaff && (! $user || $ticket->user_id !== $user->id)) {
            return $this->error('FORBIDDEN', 'Permission denied.', 403);
        }

        $validator = Validator::make($request->all(), [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:25600', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,webp,zip'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid file upload.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            /** @var array<UploadedFile> $files */
            $files = $request->file('files', []);
            $savedAttachments = [];

            foreach ($files as $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }

                $originalName = $file->getClientOriginalName();
                $mime = $file->getMimeType();
                $size = $file->getSize();
                $ext = strtolower($file->getClientOriginalExtension());

                if (in_array($ext, ['php', 'exe', 'bat', 'sh', 'js', 'phtml', 'cmd'], true)) {
                    continue;
                }

                $hashName = (string) Str::uuid().'.'.$ext;
                $relativeDir = "tickets/{$ticket->ticket_number}";
                $path = $file->storeAs($relativeDir, $hashName, 'local');

                $attachment = TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'uploaded_by_user_id' => $user?->id,
                    'filename' => $hashName,
                    'original_filename' => $originalName,
                    'mime_type' => $mime,
                    'size_bytes' => $size,
                    'disk' => 'local',
                    'path' => $path,
                ]);

                $savedAttachments[] = $attachment;
            }

            AuditLogService::log(
                event: 'ticket_attachments_uploaded',
                description: 'Uploaded '.count($savedAttachments)." attachments to ticket #{$ticket->ticket_number}.",
                userId: $user?->id
            );

            DB::commit();

            return $this->success($savedAttachments, [
                'count' => count($savedAttachments),
                'message' => count($savedAttachments).' files attached successfully.',
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@uploadAttachments error: '.$e->getMessage());

            return $this->error('UPLOAD_ERROR', 'Failed to upload attachments: '.$e->getMessage(), 500);
        }
    }

    /**
     * 18. Secure Stream Download of Ticket Attachment
     */
    public function downloadAttachment(int $attachmentId, Request $request): StreamedResponse|JsonResponse|BinaryFileResponse
    {
        try {
            $attachment = TicketAttachment::with('ticket')->find($attachmentId);
            if (! $attachment || ! $attachment->ticket) {
                return $this->error('ATTACHMENT_NOT_FOUND', 'Attachment not found.', 404);
            }

            $user = $this->resolveUser($request);
            $isStaff = $this->isStaff($request);

            // Authorization
            if (! $isStaff) {
                if (! $user || $attachment->ticket->user_id !== $user->id) {
                    return $this->error('FORBIDDEN', 'Access to attachment denied.', 403);
                }
            }

            if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
                return $this->error('FILE_NOT_ON_DISK', 'The requested file could not be located on storage.', 404);
            }

            AuditLogService::log(
                event: 'ticket_attachment_downloaded',
                description: "Downloaded attachment '{$attachment->original_filename}' from ticket #{$attachment->ticket->ticket_number}.",
                userId: $user?->id
            );

            return Storage::disk($attachment->disk)->download(
                $attachment->path,
                $attachment->original_filename,
                ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream']
            );
        } catch (Throwable $e) {
            Log::error('TicketApiController@downloadAttachment error: '.$e->getMessage());

            return $this->error('DOWNLOAD_ERROR', 'Failed to download attachment: '.$e->getMessage(), 500);
        }
    }

    /**
     * 19. Bulk Operations across Multiple Tickets (Status, Priority, Assign, Delete)
     */
    public function bulkAction(Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can execute bulk ticket actions.', 403);
        }

        $validator = Validator::make($request->all(), [
            'action' => ['required', 'string', 'in:status,priority,assign,delete'],
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'value' => ['nullable'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Bulk action validation failed.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $actor = $this->resolveUser($request);
            $action = $request->input('action');
            $ticketIds = $request->input('ticket_ids');
            $value = $request->input('value');

            $affectedCount = $this->ticketService->bulkAction($action, $ticketIds, $value, $actor);

            DB::commit();

            return $this->success([
                'action' => $action,
                'affected_tickets' => $affectedCount,
            ], ['message' => "Bulk action '{$action}' applied to {$affectedCount} tickets."]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@bulkAction error: '.$e->getMessage());

            return $this->error('BULK_ACTION_ERROR', 'Failed to execute bulk action: '.$e->getMessage(), 500);
        }
    }

    /**
     * 20. Export Tickets Dataset (JSON stream)
     */
    public function export(Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can export ticket datasets.', 403);
        }

        try {
            $query = Ticket::with(['category:id,name', 'user:id,name,email', 'assignedTo:id,name,email']);

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }
            if ($priority = $request->input('priority')) {
                $query->where('priority', $priority);
            }
            if ($categoryId = $request->input('category_id')) {
                $query->where('category_id', $categoryId);
            }

            $tickets = $query->latest('created_at')->limit(1000)->get()->map(function (Ticket $t) {
                return [
                    'ticket_number' => $t->ticket_number,
                    'subject' => $t->subject,
                    'category' => $t->category?->name ?? 'General',
                    'status' => $t->status,
                    'priority' => $t->priority,
                    'submitter_name' => $t->submitterName(),
                    'submitter_email' => $t->submitterEmail(),
                    'assignee' => $t->assignedTo?->name ?? 'Unassigned',
                    'source' => $t->source,
                    'sla_response_breached' => (bool) $t->is_sla_response_breached,
                    'sla_resolution_breached' => (bool) $t->is_sla_resolution_breached,
                    'satisfaction_rating' => $t->satisfaction_rating,
                    'created_at' => $t->created_at?->toISOString(),
                    'resolved_at' => $t->resolved_at?->toISOString(),
                ];
            });

            return $this->success($tickets, [
                'total_exported' => $tickets->count(),
                'generated_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@export error: '.$e->getMessage());

            return $this->error('EXPORT_ERROR', 'Failed to export tickets: '.$e->getMessage(), 500);
        }
    }

    /**
     * 21. Check SLA Breaches
     */
    public function checkSla(Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can run SLA breach audits.', 403);
        }

        try {
            $breaches = $this->ticketService->checkSlaBreaches();

            return $this->success([
                'newly_breached_count' => $breaches,
                'audited_at' => now()->toIso8601String(),
            ], ['message' => "SLA scan complete. {$breaches} new breaches flagged and alerted."]);
        } catch (Throwable $e) {
            Log::error('TicketApiController@checkSla error: '.$e->getMessage());

            return $this->error('SLA_AUDIT_ERROR', 'SLA audit failed: '.$e->getMessage(), 500);
        }
    }

    /**
     * 22. List Ticket Categories
     */
    public function categories(Request $request): JsonResponse
    {
        try {
            $query = TicketCategory::with('defaultAssignee:id,name,email');

            if ($request->boolean('active_only', true)) {
                $query->active();
            }

            $categories = $query->ordered()->withCount('tickets')->get();

            return $this->success($categories);
        } catch (Throwable $e) {
            Log::error('TicketApiController@categories error: '.$e->getMessage());

            return $this->error('CATEGORIES_ERROR', 'Failed to retrieve categories: '.$e->getMessage(), 500);
        }
    }

    /**
     * 23. Create Ticket Category
     */
    public function storeCategory(Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can create categories.', 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:ticket_categories,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color_badge' => ['nullable', 'string', 'max:30'],
            'default_priority' => ['nullable', 'in:low,medium,high,urgent'],
            'sla_response_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'sla_resolution_hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
            'default_assigned_user_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $data = $validator->validated();
            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['name']);
            }

            $category = TicketCategory::create($data);

            AuditLogService::log(
                event: 'ticket_category_created',
                description: "Created ticket category '{$category->name}'.",
                newValues: $category->toArray(),
                userId: $user?->id
            );

            DB::commit();

            return $this->success($category, ['message' => 'Ticket category created successfully.'], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('TicketApiController@storeCategory error: '.$e->getMessage());

            return $this->error('STORE_CATEGORY_ERROR', 'Failed to create category: '.$e->getMessage(), 500);
        }
    }

    /**
     * 24. Show Ticket Category
     */
    public function showCategory(int $id, Request $request): JsonResponse
    {
        try {
            $category = TicketCategory::with(['defaultAssignee:id,name,email'])->withCount('tickets')->find($id);
            if (! $category) {
                return $this->error('CATEGORY_NOT_FOUND', 'Ticket category not found.', 404);
            }

            return $this->success($category);
        } catch (Throwable $e) {
            return $this->error('SHOW_CATEGORY_ERROR', 'Failed to retrieve category: '.$e->getMessage(), 500);
        }
    }

    /**
     * 25. Update Ticket Category
     */
    public function updateCategory(int $id, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can update categories.', 403);
        }

        $category = TicketCategory::find($id);
        if (! $category) {
            return $this->error('CATEGORY_NOT_FOUND', 'Ticket category not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:ticket_categories,slug,'.$id],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color_badge' => ['nullable', 'string', 'max:30'],
            'default_priority' => ['nullable', 'in:low,medium,high,urgent'],
            'sla_response_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'sla_resolution_hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
            'default_assigned_user_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $oldValues = $category->toArray();
            $data = $validator->validated();

            $category->update($data);

            AuditLogService::log(
                event: 'ticket_category_updated',
                description: "Updated ticket category '{$category->name}'.",
                oldValues: $oldValues,
                newValues: $category->toArray(),
                userId: $user?->id
            );

            DB::commit();

            return $this->success($category->fresh(), ['message' => 'Ticket category updated.']);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('UPDATE_CATEGORY_ERROR', 'Failed to update category: '.$e->getMessage(), 500);
        }
    }

    /**
     * 26. Delete Ticket Category
     */
    public function destroyCategory(int $id, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can delete categories.', 403);
        }

        $category = TicketCategory::withCount('tickets')->find($id);
        if (! $category) {
            return $this->error('CATEGORY_NOT_FOUND', 'Ticket category not found.', 404);
        }

        if ($category->tickets_count > 0) {
            return $this->error('CATEGORY_IN_USE', "Category cannot be deleted because it is attached to {$category->tickets_count} tickets.", 422);
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $category->delete();

            AuditLogService::log(
                event: 'ticket_category_deleted',
                description: "Deleted ticket category '{$category->name}'.",
                userId: $user?->id
            );

            DB::commit();

            return $this->success(['id' => $id, 'deleted' => true], ['message' => 'Category deleted.']);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DELETE_CATEGORY_ERROR', 'Failed to delete category: '.$e->getMessage(), 500);
        }
    }

    /**
     * 27. List Canned Responses
     */
    public function cannedResponses(Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can view canned responses.', 403);
        }

        try {
            $query = TicketCannedResponse::with('category:id,name');

            if ($request->boolean('active_only', true)) {
                $query->active();
            }

            if ($categoryId = $request->input('category_id')) {
                $query->where('category_id', $categoryId);
            }

            $responses = $query->orderBy('title')->get();

            return $this->success($responses);
        } catch (Throwable $e) {
            return $this->error('CANNED_RESPONSES_ERROR', 'Failed to retrieve canned responses: '.$e->getMessage(), 500);
        }
    }

    /**
     * 28. Create Canned Response
     */
    public function storeCannedResponse(Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can create canned responses.', 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:120'],
            'shortcut' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'exists:ticket_categories,id'],
            'content' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $data = $validator->validated();
            $data['created_by'] = $user?->id;

            $canned = TicketCannedResponse::create($data);

            AuditLogService::log(
                event: 'canned_response_created',
                description: "Created canned response '{$canned->title}'.",
                newValues: $canned->toArray(),
                userId: $user?->id
            );

            DB::commit();

            return $this->success($canned, ['message' => 'Canned response created successfully.'], 201);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('STORE_CANNED_ERROR', 'Failed to create canned response: '.$e->getMessage(), 500);
        }
    }

    /**
     * 29. Show Canned Response
     */
    public function showCannedResponse(int $id, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can view canned responses.', 403);
        }

        $canned = TicketCannedResponse::with('category:id,name')->find($id);
        if (! $canned) {
            return $this->error('CANNED_NOT_FOUND', 'Canned response not found.', 404);
        }

        return $this->success($canned);
    }

    /**
     * 30. Update Canned Response
     */
    public function updateCannedResponse(int $id, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can update canned responses.', 403);
        }

        $canned = TicketCannedResponse::find($id);
        if (! $canned) {
            return $this->error('CANNED_NOT_FOUND', 'Canned response not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'shortcut' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'exists:ticket_categories,id'],
            'content' => ['sometimes', 'required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $oldValues = $canned->toArray();
            $data = $validator->validated();

            $canned->update($data);

            AuditLogService::log(
                event: 'canned_response_updated',
                description: "Updated canned response '{$canned->title}'.",
                oldValues: $oldValues,
                newValues: $canned->toArray(),
                userId: $user?->id
            );

            DB::commit();

            return $this->success($canned->fresh(), ['message' => 'Canned response updated.']);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('UPDATE_CANNED_ERROR', 'Failed to update canned response: '.$e->getMessage(), 500);
        }
    }

    /**
     * 31. Delete Canned Response
     */
    public function destroyCannedResponse(int $id, Request $request): JsonResponse
    {
        if (! $this->isStaff($request)) {
            return $this->error('FORBIDDEN', 'Only staff can delete canned responses.', 403);
        }

        $canned = TicketCannedResponse::find($id);
        if (! $canned) {
            return $this->error('CANNED_NOT_FOUND', 'Canned response not found.', 404);
        }

        DB::beginTransaction();
        try {
            $user = $this->resolveUser($request);
            $canned->delete();

            AuditLogService::log(
                event: 'canned_response_deleted',
                description: "Deleted canned response '{$canned->title}'.",
                userId: $user?->id
            );

            DB::commit();

            return $this->success(['id' => $id, 'deleted' => true], ['message' => 'Canned response deleted.']);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('DELETE_CANNED_ERROR', 'Failed to delete canned response: '.$e->getMessage(), 500);
        }
    }
}
