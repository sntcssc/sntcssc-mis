<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BookFine;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FeeSchedule;
use App\Models\FeeType;
use App\Models\HostelAllocation;
use App\Models\LibraryDeposit;
use App\Models\PaymentTransaction;
use App\Models\Setting;
use App\Models\StudentFeeInvoice;
use App\Models\StudentLedger;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Payment\FeePaymentService;
use App\Services\Payment\FeeReminderService;
use App\Services\Payment\FeeScheduleService;
use App\Services\Payment\Gateways\CCAVenueGateway;
use App\Services\Payment\LibraryDepositService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\ReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentApiController extends Controller
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager
    ) {}

    /**
     * Resolve the active user context (via bearer/Sanctum, session, or API key owner).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
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
     * Locate student user by ID, UUID, student_id, URN, phone, or email.
     */
    protected function findStudent(string $identifier): ?User
    {
        return User::where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('phone', $identifier);
            } else {
                $q->where('uuid', $identifier)
                    ->orWhere('student_id', $identifier)
                    ->orWhere('urn', $identifier)
                    ->orWhere('email', $identifier);
            }
        })->first();
    }

    /**
     * Locate invoice by numeric ID or invoice_no.
     */
    protected function findInvoice(string $identifier): ?StudentFeeInvoice
    {
        return StudentFeeInvoice::where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('invoice_no', $identifier);
            } else {
                $q->where('invoice_no', $identifier);
            }
        })->first();
    }

    /**
     * Locate transaction by numeric ID, transaction_no, or receipt_no.
     */
    protected function findTransaction(string $identifier): ?PaymentTransaction
    {
        return PaymentTransaction::where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('transaction_no', $identifier)
                    ->orWhere('receipt_no', $identifier);
            } else {
                $q->where('transaction_no', $identifier)
                    ->orWhere('receipt_no', $identifier);
            }
        })->first();
    }

    /**
     * Locate fee schedule by numeric ID or schedule_no.
     */
    protected function findSchedule(string $identifier): ?FeeSchedule
    {
        return FeeSchedule::where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('schedule_no', $identifier);
            } else {
                $q->where('schedule_no', $identifier);
            }
        })->first();
    }

    /**
     * Locate fee type by numeric ID or code.
     */
    protected function findFeeType(string $identifier): ?FeeType
    {
        return FeeType::where(function (Builder $q) use ($identifier) {
            if (is_numeric($identifier)) {
                $q->where('id', (int) $identifier)
                    ->orWhere('code', $identifier);
            } else {
                $q->where('code', $identifier);
            }
        })->first();
    }

    // =========================================================================
    // 1. Executive Telemetry & Deep Analytics
    // =========================================================================

    /**
     * 1.1 Executive Fee Collection Dashboard Telemetry.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        $totalCollected = (float) PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESSFUL)->sum('amount');
        $totalInvoiced = (float) StudentFeeInvoice::whereNotIn('status', [StudentFeeInvoice::STATUS_CANCELLED])->sum('total_amount');
        $totalPendingBalance = (float) StudentFeeInvoice::whereNotIn('status', [StudentFeeInvoice::STATUS_PAID, StudentFeeInvoice::STATUS_WAIVED, StudentFeeInvoice::STATUS_CANCELLED])->sum('balance_amount');

        $overdueQuery = StudentFeeInvoice::whereNotIn('status', [StudentFeeInvoice::STATUS_PAID, StudentFeeInvoice::STATUS_WAIVED, StudentFeeInvoice::STATUS_CANCELLED])
            ->whereDate('due_date', '<', $today);
        $totalOverdueAmount = (float) $overdueQuery->sum('balance_amount');
        $overdueCount = $overdueQuery->count();

        $todayCollected = (float) PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESSFUL)
            ->whereDate('created_at', $today)
            ->sum('amount');

        $monthCollected = (float) PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESSFUL)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('amount');

        $invoicesCount = StudentFeeInvoice::count();
        $paidInvoicesCount = StudentFeeInvoice::where('status', StudentFeeInvoice::STATUS_PAID)->count();
        $pendingInvoicesCount = StudentFeeInvoice::whereIn('status', [StudentFeeInvoice::STATUS_PENDING, StudentFeeInvoice::STATUS_PARTIAL])->count();

        $totalTransactionsCount = PaymentTransaction::count();
        $successfulTxnCount = PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESSFUL)->count();
        $underReviewTxnCount = PaymentTransaction::where('status', PaymentTransaction::STATUS_UNDER_REVIEW)->count();

        $efficiency = $totalInvoiced > 0 ? round(($totalCollected / $totalInvoiced) * 100, 2) : 0.00;

        // Payment method distribution
        $methodDistribution = PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESSFUL)
            ->select('payment_method', DB::raw('count(*) as count'), DB::raw('sum(amount) as total_amount'))
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->payment_method,
                'count' => (int) $r->count,
                'total_amount' => (float) $r->total_amount,
            ]);

        // Monthly trends (last 6 months)
        $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
        $driver = DB::connection()->getDriverName();
        $monthSql = match ($driver) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            'sqlsrv' => "FORMAT(created_at, 'yyyy-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };

        $monthlyTrends = PaymentTransaction::where('status', PaymentTransaction::STATUS_SUCCESSFUL)
            ->where('created_at', '>=', $sixMonthsAgo)
            ->select(
                DB::raw("{$monthSql} as month"),
                DB::raw('sum(amount) as collected_amount'),
                DB::raw('count(*) as transaction_count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($m) => [
                'month' => $m->month,
                'collected_amount' => (float) $m->collected_amount,
                'transaction_count' => (int) $m->transaction_count,
            ]);

        return $this->success([
            'telemetry' => [
                'total_collected' => $totalCollected,
                'total_invoiced' => $totalInvoiced,
                'total_pending_balance' => $totalPendingBalance,
                'total_overdue_amount' => $totalOverdueAmount,
                'collection_efficiency_percentage' => $efficiency,
                'today_collection' => $todayCollected,
                'this_month_collection' => $monthCollected,
            ],
            'invoices_summary' => [
                'total' => $invoicesCount,
                'paid' => $paidInvoicesCount,
                'pending' => $pendingInvoicesCount,
                'overdue' => $overdueCount,
            ],
            'transactions_summary' => [
                'total' => $totalTransactionsCount,
                'successful' => $successfulTxnCount,
                'under_review' => $underReviewTxnCount,
            ],
            'payment_method_distribution' => $methodDistribution,
            'monthly_collection_trends' => $monthlyTrends,
        ]);
    }

    /**
     * 1.2 Granular Collection Analytics by Fee Type, Course, Category Concession & Ledgers.
     */
    public function statistics(Request $request): JsonResponse
    {
        // Fee Type Collections
        $feeTypeStats = FeeType::withCount('invoices')
            ->get()
            ->map(function (FeeType $ft) {
                $collected = (float) PaymentTransaction::where('fee_type_id', $ft->id)
                    ->where('status', PaymentTransaction::STATUS_SUCCESSFUL)
                    ->sum('amount');
                $invoiced = (float) StudentFeeInvoice::where('fee_type_id', $ft->id)
                    ->whereNotIn('status', [StudentFeeInvoice::STATUS_CANCELLED])
                    ->sum('total_amount');

                return [
                    'id' => $ft->id,
                    'code' => $ft->code,
                    'name' => $ft->name,
                    'category' => $ft->category,
                    'frequency' => $ft->frequency,
                    'is_refundable' => (bool) $ft->is_refundable,
                    'total_invoiced' => $invoiced,
                    'total_collected' => $collected,
                    'invoices_count' => $ft->invoices_count,
                ];
            });

        // Course Wise Collection Analytics
        $courseStats = Course::where('status', 'active')->get()->map(function (Course $c) {
            $invoiced = (float) StudentFeeInvoice::whereHas('enrollment', fn ($e) => $e->where('course_id', $c->id))
                ->whereNotIn('status', [StudentFeeInvoice::STATUS_CANCELLED])
                ->sum('total_amount');
            $collected = (float) PaymentTransaction::whereHas('invoice.enrollment', fn ($e) => $e->where('course_id', $c->id))
                ->where('status', PaymentTransaction::STATUS_SUCCESSFUL)
                ->sum('amount');

            return [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'standard_fee' => (float) $c->fee,
                'reserved_fee' => (float) ($c->fee_reserved ?? $c->fee),
                'total_invoiced' => $invoiced,
                'total_collected' => $collected,
                'recovery_rate' => $invoiced > 0 ? round(($collected / $invoiced) * 100, 2) : 0.00,
            ];
        });

        // Batch Wise Collection Analytics
        $batchStats = Batch::where('status', 'active')->get()->map(function (Batch $b) {
            $invoiced = (float) StudentFeeInvoice::whereHas('enrollment', fn ($e) => $e->where('batch_id', $b->id))
                ->whereNotIn('status', [StudentFeeInvoice::STATUS_CANCELLED])
                ->sum('total_amount');
            $collected = (float) PaymentTransaction::whereHas('invoice.enrollment', fn ($e) => $e->where('batch_id', $b->id))
                ->where('status', PaymentTransaction::STATUS_SUCCESSFUL)
                ->sum('amount');

            return [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'academic_year' => $b->academic_year,
                'total_invoiced' => $invoiced,
                'total_collected' => $collected,
                'recovery_rate' => $invoiced > 0 ? round(($collected / $invoiced) * 100, 2) : 0.00,
            ];
        });

        // General vs Reserved Category Concession Impact Analysis
        $categoryBreakdown = Enrollment::where('status', 'active')
            ->join('student_profiles', 'enrollments.user_id', '=', 'student_profiles.user_id')
            ->select('student_profiles.category', DB::raw('count(enrollments.id) as enrollments_count'))
            ->groupBy('student_profiles.category')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category ?: 'General (UR)',
                'active_enrollments' => (int) $r->enrollments_count,
            ]);

        // Ledger Summary
        $ledgerDebits = (float) StudentLedger::where('entry_type', StudentLedger::ENTRY_DEBIT)->sum('amount');
        $ledgerCredits = (float) StudentLedger::where('entry_type', StudentLedger::ENTRY_CREDIT)->sum('amount');
        $netReceivable = max(0.00, $ledgerDebits - $ledgerCredits);

        return $this->success([
            'fee_types' => $feeTypeStats,
            'courses' => $courseStats,
            'batches' => $batchStats,
            'social_category_concession' => $categoryBreakdown,
            'ledger_totals' => [
                'total_debits' => $ledgerDebits,
                'total_credits' => $ledgerCredits,
                'net_system_receivable' => $netReceivable,
            ],
        ]);
    }

    // =========================================================================
    // 2. Fee Types Catalog & Configuration (CRUD)
    // =========================================================================

    /**
     * 2.1 List Fee Types.
     */
    public function feeTypes(Request $request): JsonResponse
    {
        $query = FeeType::query()->withCount(['schedules', 'invoices']);

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('frequency')) {
            $query->where('frequency', $request->input('frequency'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('is_refundable')) {
            $query->where('is_refundable', $request->boolean('is_refundable'));
        }

        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $feeTypes = $query->orderBy('name')->get();

        return $this->success($feeTypes->map(fn (FeeType $ft) => $this->formatFeeType($ft)));
    }

    /**
     * 2.2 Create New Fee Type.
     */
    public function storeFeeType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:fee_types,code',
            'name' => 'required|string|max:100',
            'category' => 'required|string|in:academic,library,admission,miscellaneous',
            'frequency' => 'required|string|in:monthly,one_time,quarterly,yearly,weekly',
            'default_amount' => 'required|numeric|min:0',
            'is_refundable' => 'boolean',
            'status' => 'nullable|string|in:active,inactive',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid fee type attributes', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        try {
            $feeType = DB::transaction(function () use ($request, $actor) {
                $model = FeeType::create([
                    'code' => strtolower(trim($request->input('code'))),
                    'name' => trim($request->input('name')),
                    'category' => $request->input('category'),
                    'frequency' => $request->input('frequency'),
                    'default_amount' => (float) $request->input('default_amount'),
                    'is_refundable' => $request->boolean('is_refundable', false),
                    'status' => $request->input('status', 'active'),
                    'description' => $request->input('description'),
                    'created_by' => $actor?->id,
                ]);

                AuditLogService::log(
                    event: 'fee_type_created',
                    description: "Created fee type {$model->name} ({$model->code}) with default amount ₹{$model->default_amount}",
                    auditable: $model,
                    userId: $actor?->id
                );

                return $model;
            });

            return $this->success($this->formatFeeType($feeType), null, 201);
        } catch (Throwable $e) {
            return $this->error('FEE_TYPE_CREATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 2.3 Show Fee Type Dossier.
     */
    public function showFeeType(string $identifier): JsonResponse
    {
        $feeType = $this->findFeeType($identifier);

        if (! $feeType) {
            return $this->error('FEE_TYPE_NOT_FOUND', "Fee type '{$identifier}' not found", 404);
        }

        $feeType->loadCount(['schedules', 'invoices']);

        return $this->success($this->formatFeeType($feeType, true));
    }

    /**
     * 2.4 Update Fee Type.
     */
    public function updateFeeType(string $identifier, Request $request): JsonResponse
    {
        $feeType = $this->findFeeType($identifier);

        if (! $feeType) {
            return $this->error('FEE_TYPE_NOT_FOUND', "Fee type '{$identifier}' not found", 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'category' => 'sometimes|required|string|in:academic,library,admission,miscellaneous',
            'frequency' => 'sometimes|required|string|in:monthly,one_time,quarterly,yearly,weekly',
            'default_amount' => 'sometimes|required|numeric|min:0',
            'is_refundable' => 'boolean',
            'status' => 'sometimes|required|string|in:active,inactive',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid fee type attributes', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        try {
            DB::transaction(function () use ($feeType, $request, $actor) {
                $feeType->update($request->only([
                    'name',
                    'category',
                    'frequency',
                    'default_amount',
                    'is_refundable',
                    'status',
                    'description',
                ]) + ['updated_by' => $actor?->id]);

                AuditLogService::log(
                    event: 'fee_type_updated',
                    description: "Updated fee type {$feeType->name} ({$feeType->code})",
                    auditable: $feeType,
                    userId: $actor?->id
                );
            });

            return $this->success($this->formatFeeType($feeType->fresh()));
        } catch (Throwable $e) {
            return $this->error('FEE_TYPE_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 2.5 Soft Delete Fee Type (with safety dependency guard).
     */
    public function destroyFeeType(string $identifier, Request $request): JsonResponse
    {
        $feeType = $this->findFeeType($identifier);

        if (! $feeType) {
            return $this->error('FEE_TYPE_NOT_FOUND', "Fee type '{$identifier}' not found", 404);
        }

        // Active dependency safety guard
        if ($feeType->schedules()->exists() || $feeType->invoices()->exists()) {
            return $this->error(
                'ACTIVE_DEPENDENCY_PREVENTED',
                "Fee type '{$feeType->name}' cannot be deleted because fee schedules or student invoices are linked to it. Please deactivate its status instead.",
                409
            );
        }

        $actor = $this->resolveUser($request);

        try {
            DB::transaction(function () use ($feeType, $actor) {
                $name = $feeType->name;
                $feeType->update(['deleted_by' => $actor?->id]);
                $feeType->delete();

                AuditLogService::log(
                    event: 'fee_type_deleted',
                    description: "Soft deleted fee type {$name}",
                    auditable: $feeType,
                    userId: $actor?->id
                );
            });

            return $this->success(['message' => "Fee type '{$feeType->name}' soft-deleted successfully."]);
        } catch (Throwable $e) {
            return $this->error('FEE_TYPE_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 3. Fee Schedules & Batch Invoicing (CRUD & Lifecycle)
    // =========================================================================

    /**
     * 3.1 List Fee Schedules.
     */
    public function schedules(Request $request): JsonResponse
    {
        $query = FeeSchedule::with(['feeType', 'batch', 'course'])->withCount('invoices');

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->input('batch_id'));
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->input('course_id'));
        }

        if ($request->filled('fee_type_id')) {
            $query->where('fee_type_id', $request->input('fee_type_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->input('academic_year'));
        }

        if ($request->filled('period_month')) {
            $query->where('period_month', (int) $request->input('period_month'));
        }

        if ($request->filled('period_year')) {
            $query->where('period_year', (int) $request->input('period_year'));
        }

        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('schedule_no', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginator = $query->latest('id')->paginate($perPage);

        return $this->success(
            collect($paginator->items())->map(fn (FeeSchedule $s) => $this->formatFeeSchedule($s)),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    /**
     * 3.2 Initiate Batch Fee Schedule (Generates student invoices with category concession & ledger debits).
     */
    public function storeSchedule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'batch_id' => 'required|exists:batches,id',
            'course_id' => 'required|exists:courses,id',
            'fee_type_id' => 'required|exists:fee_types,id',
            'title' => 'required|string|max:150',
            'due_date' => 'required|date',
            'grace_period_days' => 'nullable|integer|min:0|max:60',
            'late_fee_amount' => 'nullable|numeric|min:0',
            'academic_year' => 'nullable|string|max:20',
            'period_month' => 'nullable|integer|between:1,12',
            'period_year' => 'nullable|integer|min:2020|max:2040',
            'billing_cycle' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid schedule parameters', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        try {
            $schedule = FeeScheduleService::initiateBatchCourseFeeSchedule($request->all(), $actor?->id);

            return $this->success(
                $this->formatFeeSchedule($schedule->load(['feeType', 'batch', 'course'])),
                ['message' => "Fee schedule {$schedule->schedule_no} created with {$schedule->total_targeted_students} targeted student invoices."],
                201
            );
        } catch (ValidationException $e) {
            return $this->error('SCHEDULE_VALIDATION_ERROR', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('SCHEDULE_INITIATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 3.3 Show Fee Schedule Dossier with Invoice Breakdown.
     */
    public function showSchedule(string $identifier): JsonResponse
    {
        $schedule = $this->findSchedule($identifier);

        if (! $schedule) {
            return $this->error('SCHEDULE_NOT_FOUND', "Fee schedule '{$identifier}' not found", 404);
        }

        $schedule->load(['feeType', 'batch', 'course', 'creator']);

        $invoicesBreakdown = [
            'total' => $schedule->invoices()->count(),
            'paid' => $schedule->invoices()->where('status', StudentFeeInvoice::STATUS_PAID)->count(),
            'partial' => $schedule->invoices()->where('status', StudentFeeInvoice::STATUS_PARTIAL)->count(),
            'pending' => $schedule->invoices()->where('status', StudentFeeInvoice::STATUS_PENDING)->count(),
            'overdue' => $schedule->invoices()->where('status', StudentFeeInvoice::STATUS_OVERDUE)->count(),
            'waived' => $schedule->invoices()->where('status', StudentFeeInvoice::STATUS_WAIVED)->count(),
            'cancelled' => $schedule->invoices()->where('status', StudentFeeInvoice::STATUS_CANCELLED)->count(),
        ];

        return $this->success(array_merge(
            $this->formatFeeSchedule($schedule),
            ['invoices_breakdown' => $invoicesBreakdown]
        ));
    }

    /**
     * 3.4 Send Bulk Fee Reminders for Schedule.
     */
    public function sendScheduleReminders(string $identifier, Request $request): JsonResponse
    {
        $schedule = $this->findSchedule($identifier);

        if (! $schedule) {
            return $this->error('SCHEDULE_NOT_FOUND', "Fee schedule '{$identifier}' not found", 404);
        }

        $actor = $this->resolveUser($request);

        try {
            $channels = $request->input('channels', ['database']);
            $customMessage = $request->input('message');

            $result = FeeReminderService::sendScheduleBulkReminders(
                schedule: $schedule,
                channels: is_array($channels) ? $channels : [$channels],
                customMessage: $customMessage,
                sentBy: $actor?->id
            );

            return $this->success($result, ['message' => "Bulk reminders dispatched for schedule {$schedule->schedule_no}."]);
        } catch (Throwable $e) {
            return $this->error('BULK_REMINDER_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 3.5 Revert Uncollected Invoices for Schedule.
     */
    public function revertSchedule(string $identifier, Request $request): JsonResponse
    {
        $schedule = $this->findSchedule($identifier);

        if (! $schedule) {
            return $this->error('SCHEDULE_NOT_FOUND', "Fee schedule '{$identifier}' not found", 404);
        }

        $reason = $request->input('reason', 'Administrative schedule reversion');
        $actor = $this->resolveUser($request);

        try {
            $result = FeeScheduleService::revertSchedule($schedule, $reason, $actor?->id);

            return $this->success($result, ['message' => "Schedule {$schedule->schedule_no} successfully reverted."]);
        } catch (ValidationException $e) {
            return $this->error('SCHEDULE_REVERT_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('SCHEDULE_REVERT_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 3.6 Soft Delete Fee Schedule.
     */
    public function destroySchedule(string $identifier, Request $request): JsonResponse
    {
        $schedule = $this->findSchedule($identifier);

        if (! $schedule) {
            return $this->error('SCHEDULE_NOT_FOUND', "Fee schedule '{$identifier}' not found", 404);
        }

        $actor = $this->resolveUser($request);

        try {
            $result = FeeScheduleService::deleteSchedule($schedule, $actor?->id);

            return $this->success($result, ['message' => "Fee schedule {$schedule->schedule_no} deleted successfully."]);
        } catch (ValidationException $e) {
            return $this->error('SCHEDULE_DELETE_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('SCHEDULE_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 4. Student Fee Invoices (CRUD & Lifecycle)
    // =========================================================================

    /**
     * 4.1 Search and Paginate Student Fee Invoices.
     */
    public function invoices(Request $request): JsonResponse
    {
        $query = StudentFeeInvoice::with(['user.studentProfile', 'feeType', 'feeSchedule', 'enrollment.course', 'enrollment.batch']);

        if ($request->filled('student_identifier')) {
            $student = $this->findStudent($request->input('student_identifier'));
            if ($student) {
                $query->where('user_id', $student->id);
            } else {
                $query->where('student_id', $request->input('student_identifier'));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('fee_type_id')) {
            $query->where('fee_type_id', $request->input('fee_type_id'));
        }

        if ($request->filled('course_id')) {
            $query->whereHas('enrollment', fn ($e) => $e->where('course_id', $request->input('course_id')));
        }

        if ($request->filled('batch_id')) {
            $query->whereHas('enrollment', fn ($e) => $e->where('batch_id', $request->input('batch_id')));
        }

        if ($request->filled('period_month')) {
            $query->where('period_month', (int) $request->input('period_month'));
        }

        if ($request->filled('period_year')) {
            $query->where('period_year', (int) $request->input('period_year'));
        }

        if ($request->filled('overdue_only') && $request->boolean('overdue_only')) {
            $query->whereNotIn('status', [StudentFeeInvoice::STATUS_PAID, StudentFeeInvoice::STATUS_WAIVED, StudentFeeInvoice::STATUS_CANCELLED])
                ->whereDate('due_date', '<', Carbon::today());
        }

        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('invoice_no', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginator = $query->latest('id')->paginate($perPage);

        return $this->success(
            collect($paginator->items())->map(fn (StudentFeeInvoice $inv) => $this->formatInvoice($inv)),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    /**
     * 4.2 Issue Individual / Ad-hoc Fee Invoice for a Student.
     */
    public function storeInvoice(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_identifier' => 'required|string',
            'fee_type_id' => 'required|exists:fee_types,id',
            'title' => 'required|string|max:150',
            'amount' => 'required|numeric|min:0.01',
            'discount_amount' => 'nullable|numeric|min:0',
            'late_fee_amount' => 'nullable|numeric|min:0',
            'due_date' => 'required|date',
            'period_month' => 'nullable|integer|between:1,12',
            'period_year' => 'nullable|integer|min:2020|max:2040',
            'description' => 'nullable|string|max:500',
            'enrollment_id' => 'nullable|exists:enrollments,id',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid invoice attributes', 422, $validator->errors()->toArray());
        }

        $student = $this->findStudent($request->input('student_identifier'));
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$request->input('student_identifier')}' not found", 404);
        }

        $feeType = FeeType::findOrFail($request->input('fee_type_id'));
        $actor = $this->resolveUser($request);

        // Guard: Prevent duplicate tuition fee invoice for the same month/year
        if ($feeType->code === 'tuition') {
            $month = (int) ($request->input('period_month') ?? date('n'));
            $year = (int) ($request->input('period_year') ?? date('Y'));

            $existing = StudentFeeInvoice::where('user_id', $student->id)
                ->where('fee_type_id', $feeType->id)
                ->where('period_month', $month)
                ->where('period_year', $year)
                ->whereNotIn('status', [StudentFeeInvoice::STATUS_CANCELLED])
                ->exists();

            if ($existing) {
                return $this->error(
                    'DUPLICATE_TUITION_INVOICE',
                    "A tuition fee invoice already exists for Student {$student->name} for month {$month}/{$year}.",
                    409
                );
            }
        }

        $amount = (float) $request->input('amount');
        $discount = (float) ($request->input('discount_amount') ?? 0.00);
        $lateFee = (float) ($request->input('late_fee_amount') ?? 0.00);
        $totalAmount = max(0.00, ($amount - $discount) + $lateFee);

        try {
            $invoice = DB::transaction(function () use ($request, $student, $feeType, $amount, $discount, $lateFee, $totalAmount, $actor) {
                $inv = StudentFeeInvoice::create([
                    'invoice_no' => StudentFeeInvoice::generateInvoiceNo(),
                    'user_id' => $student->id,
                    'student_id' => $student->student_id,
                    'enrollment_id' => $request->input('enrollment_id') ?? $student->activeEnrollments()->first()?->id,
                    'fee_type_id' => $feeType->id,
                    'title' => trim($request->input('title')),
                    'description' => $request->input('description'),
                    'period_month' => $request->input('period_month'),
                    'period_year' => $request->input('period_year'),
                    'amount' => $amount,
                    'discount_amount' => $discount,
                    'late_fee_amount' => $lateFee,
                    'total_amount' => $totalAmount,
                    'paid_amount' => 0.00,
                    'balance_amount' => $totalAmount,
                    'due_date' => $request->input('due_date'),
                    'status' => StudentFeeInvoice::STATUS_PENDING,
                    'created_by' => $actor?->id,
                ]);

                // Record invoice debit in student ledger
                StudentLedger::recordDebit(
                    userId: $student->id,
                    amount: $totalAmount,
                    referenceType: StudentLedger::REF_INVOICE,
                    referenceId: $inv->id,
                    description: "Fee Invoice {$inv->invoice_no} ({$inv->title})",
                    studentId: $student->student_id,
                    createdBy: $actor?->id
                );

                AuditLogService::log(
                    event: 'fee_invoice_issued',
                    description: "Issued fee invoice {$inv->invoice_no} of ₹{$totalAmount} to {$student->name} (Student ID: {$student->student_id})",
                    auditable: $inv,
                    userId: $actor?->id
                );

                return $inv;
            });

            return $this->success($this->formatInvoice($invoice->load(['user.studentProfile', 'feeType', 'enrollment.course'])), null, 201);
        } catch (Throwable $e) {
            return $this->error('INVOICE_CREATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 4.3 Show Single Invoice Dossier with Attached Transactions.
     */
    public function showInvoice(string $identifier): JsonResponse
    {
        $invoice = $this->findInvoice($identifier);

        if (! $invoice) {
            return $this->error('INVOICE_NOT_FOUND', "Invoice '{$identifier}' not found", 404);
        }

        $invoice->load([
            'user.studentProfile',
            'feeType',
            'feeSchedule',
            'enrollment.course',
            'enrollment.batch',
            'transactions',
        ]);

        return $this->success($this->formatInvoice($invoice, true));
    }

    /**
     * 4.4 Update Invoice Due Date, Discount, Late Fee or Description.
     */
    public function updateInvoice(string $identifier, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($identifier);

        if (! $invoice) {
            return $this->error('INVOICE_NOT_FOUND', "Invoice '{$identifier}' not found", 404);
        }

        if ($invoice->isPaid()) {
            return $this->error('INVOICE_ALREADY_PAID', 'Paid invoices cannot be modified.', 400);
        }

        $validator = Validator::make($request->all(), [
            'due_date' => 'sometimes|required|date',
            'discount_amount' => 'nullable|numeric|min:0',
            'late_fee_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid update attributes', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        try {
            DB::transaction(function () use ($invoice, $request, $actor) {
                $oldTotal = (float) $invoice->total_amount;

                $discount = $request->has('discount_amount') ? (float) $request->input('discount_amount') : (float) $invoice->discount_amount;
                $lateFee = $request->has('late_fee_amount') ? (float) $request->input('late_fee_amount') : (float) $invoice->late_fee_amount;
                $newTotal = max(0.00, ((float) $invoice->amount - $discount) + $lateFee);
                $newBalance = max(0.00, $newTotal - (float) $invoice->paid_amount);

                $invoice->update([
                    'due_date' => $request->input('due_date', $invoice->due_date),
                    'discount_amount' => $discount,
                    'late_fee_amount' => $lateFee,
                    'total_amount' => $newTotal,
                    'balance_amount' => $newBalance,
                    'description' => $request->input('description', $invoice->description),
                    'updated_by' => $actor?->id,
                ]);

                // If total changed, adjust ledger accordingly
                $diff = $newTotal - $oldTotal;
                if (abs($diff) >= 0.01) {
                    if ($diff > 0) {
                        StudentLedger::recordDebit(
                            userId: $invoice->user_id,
                            amount: $diff,
                            referenceType: StudentLedger::REF_ADJUSTMENT,
                            referenceId: $invoice->id,
                            description: "Invoice {$invoice->invoice_no} adjustment (increased liability)",
                            studentId: $invoice->student_id,
                            createdBy: $actor?->id
                        );
                    } else {
                        StudentLedger::recordCredit(
                            userId: $invoice->user_id,
                            amount: abs($diff),
                            referenceType: StudentLedger::REF_ADJUSTMENT,
                            referenceId: $invoice->id,
                            description: "Invoice {$invoice->invoice_no} adjustment (discount/waiver credited)",
                            studentId: $invoice->student_id,
                            createdBy: $actor?->id
                        );
                    }
                }

                AuditLogService::log(
                    event: 'fee_invoice_updated',
                    description: "Updated invoice {$invoice->invoice_no}. Old Total: ₹{$oldTotal}, New Total: ₹{$newTotal}",
                    auditable: $invoice,
                    userId: $actor?->id
                );
            });

            return $this->success($this->formatInvoice($invoice->fresh()));
        } catch (Throwable $e) {
            return $this->error('INVOICE_UPDATE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 4.5 Cancel Unpaid / Partial Fee Invoice and Reverse Ledger Liability.
     */
    public function cancelInvoice(string $identifier, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($identifier);

        if (! $invoice) {
            return $this->error('INVOICE_NOT_FOUND', "Invoice '{$identifier}' not found", 404);
        }

        $reason = $request->input('reason', 'Administrative fee invoice cancellation');
        $actor = $this->resolveUser($request);

        try {
            $cancelled = FeePaymentService::cancelInvoice($invoice, $reason, $actor?->id);

            return $this->success($this->formatInvoice($cancelled), ['message' => "Invoice {$cancelled->invoice_no} cancelled successfully."]);
        } catch (ValidationException $e) {
            return $this->error('INVOICE_CANCEL_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('INVOICE_CANCEL_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 4.6 Soft Delete Uncollected Fee Invoice.
     */
    public function destroyInvoice(string $identifier, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($identifier);

        if (! $invoice) {
            return $this->error('INVOICE_NOT_FOUND', "Invoice '{$identifier}' not found", 404);
        }

        $actor = $this->resolveUser($request);

        try {
            FeePaymentService::deleteInvoice($invoice, $actor?->id);

            return $this->success(['message' => "Invoice {$invoice->invoice_no} deleted successfully."]);
        } catch (ValidationException $e) {
            return $this->error('INVOICE_DELETE_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('INVOICE_DELETE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 4.7 Send Individual Invoice Reminder.
     */
    public function sendInvoiceReminder(string $identifier, Request $request): JsonResponse
    {
        $invoice = $this->findInvoice($identifier);

        if (! $invoice) {
            return $this->error('INVOICE_NOT_FOUND', "Invoice '{$identifier}' not found", 404);
        }

        $actor = $this->resolveUser($request);

        try {
            $channels = $request->input('channels', ['database']);
            $customMessage = $request->input('message');

            $result = FeeReminderService::sendInvoiceReminder(
                invoice: $invoice,
                channels: is_array($channels) ? $channels : [$channels],
                customMessage: $customMessage,
                sentBy: $actor?->id
            );

            return $this->success($result, ['message' => "Reminder sent for invoice {$invoice->invoice_no}."]);
        } catch (Throwable $e) {
            return $this->error('INVOICE_REMINDER_FAILED', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 5. Payment Transactions & Counter Collection
    // =========================================================================

    /**
     * 5.1 Search and Paginate Payment Transactions.
     */
    public function transactions(Request $request): JsonResponse
    {
        $query = PaymentTransaction::with(['user.studentProfile', 'invoice', 'feeType', 'verifier']);

        if ($request->filled('student_identifier')) {
            $student = $this->findStudent($request->input('student_identifier'));
            if ($student) {
                $query->where('user_id', $student->id);
            } else {
                $query->where('student_id', $request->input('student_identifier'));
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->input('gateway'));
        }

        if ($request->filled('is_offline')) {
            $query->where('is_offline', $request->boolean('is_offline'));
        }

        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('transaction_no', 'like', "%{$search}%")
                    ->orWhere('receipt_no', 'like', "%{$search}%")
                    ->orWhere('offline_reference_id', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginator = $query->latest('id')->paginate($perPage);

        return $this->success(
            collect($paginator->items())->map(fn (PaymentTransaction $tx) => $this->formatTransaction($tx)),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    /**
     * 5.2 Direct Manual / Counter Fee Collection (Cash, POS Terminal Card, Cheque, DD, Counter UPI, NEFT).
     */
    public function collectPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_identifier' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,card,pos,cheque,dd,upi,static_qr,neft,imps,bank_transfer',
            'invoice_id' => 'nullable|exists:student_fee_invoices,id',
            'fee_type_id' => 'nullable|exists:fee_types,id',
            'fee_type_code' => 'nullable|string',
            'reference_no' => 'nullable|string|max:100',
            'payment_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:500',
            'period_month' => 'nullable|integer|between:1,12',
            'period_year' => 'nullable|integer|min:2020|max:2040',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid collection parameters', 422, $validator->errors()->toArray());
        }

        $student = $this->findStudent($request->input('student_identifier'));
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$request->input('student_identifier')}' not found", 404);
        }

        $actor = $this->resolveUser($request);

        $data = $request->all();
        $data['user_id'] = $student->id;
        $data['student_id'] = $student->student_id;

        try {
            $transaction = FeePaymentService::recordManualCollection($data, $actor?->id);

            return $this->success(
                $this->formatTransaction($transaction->load(['user', 'invoice', 'feeType'])),
                ['message' => "Payment collected successfully. Receipt No: {$transaction->receipt_no}"],
                201
            );
        } catch (ValidationException $e) {
            return $this->error('COLLECTION_VALIDATION_ERROR', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('COLLECTION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 5.3 Submit Offline Student Payment for Review.
     */
    public function recordOfflinePayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_identifier' => 'required|string',
            'invoice_id' => 'nullable|exists:student_fee_invoices,id',
            'fee_type_id' => 'nullable|exists:fee_types,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:upi,neft,imps,bank_transfer,cheque,dd',
            'offline_reference_id' => 'required|string|max:100',
            'offline_payment_date' => 'required|date',
            'offline_payment_time' => 'nullable|string|max:20',
            'user_remarks' => 'nullable|string|max:500',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid offline payment submission', 422, $validator->errors()->toArray());
        }

        $student = $this->findStudent($request->input('student_identifier'));
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$request->input('student_identifier')}' not found", 404);
        }

        $actor = $this->resolveUser($request);

        $data = $request->all();
        $data['user_id'] = $student->id;
        $data['student_id'] = $student->student_id;

        $proofFile = $request->file('proof_file');

        try {
            $transaction = FeePaymentService::recordOfflinePayment($data, $proofFile, $actor?->id);

            return $this->success(
                $this->formatTransaction($transaction->load(['user', 'invoice', 'feeType'])),
                ['message' => "Offline payment {$transaction->transaction_no} submitted for review."],
                201
            );
        } catch (ValidationException $e) {
            return $this->error('OFFLINE_PAYMENT_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('OFFLINE_PAYMENT_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 5.4 Review and Verify / Reject Offline Payment.
     */
    public function verifyOfflinePayment(string $identifier, Request $request): JsonResponse
    {
        $transaction = $this->findTransaction($identifier);

        if (! $transaction) {
            return $this->error('TRANSACTION_NOT_FOUND', "Transaction '{$identifier}' not found", 404);
        }

        $validator = Validator::make($request->all(), [
            'approved' => 'required|boolean',
            'admin_remarks' => 'nullable|string|max:500',
            'updated_fields' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid review payload', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        try {
            $verified = FeePaymentService::verifyOfflinePayment(
                transaction: $transaction,
                approved: $request->boolean('approved'),
                verifiedBy: $actor?->id ?? 1,
                adminRemarks: $request->input('admin_remarks'),
                updatedFields: $request->input('updated_fields')
            );

            $msg = $verified->status === PaymentTransaction::STATUS_SUCCESSFUL
                ? "Payment verified successfully. Receipt: {$verified->receipt_no}"
                : 'Payment submission rejected.';

            return $this->success($this->formatTransaction($verified), ['message' => $msg]);
        } catch (ValidationException $e) {
            return $this->error('VERIFICATION_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('VERIFICATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 5.5 Show Payment Transaction Dossier.
     */
    public function showTransaction(string $identifier): JsonResponse
    {
        $transaction = $this->findTransaction($identifier);

        if (! $transaction) {
            return $this->error('TRANSACTION_NOT_FOUND', "Transaction '{$identifier}' not found", 404);
        }

        $transaction->load(['user.studentProfile', 'invoice', 'feeType', 'verifier']);

        return $this->success($this->formatTransaction($transaction));
    }

    /**
     * 5.6 Stream / Download Official PDF Payment Receipt.
     */
    public function downloadReceipt(string $identifier, Request $request): Response
    {
        $transaction = $this->findTransaction($identifier);

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => "Transaction '{$identifier}' not found"],
            ], 404);
        }

        if ($transaction->status !== PaymentTransaction::STATUS_SUCCESSFUL) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'RECEIPT_NOT_AVAILABLE', 'message' => 'Receipt is only issued for confirmed and cleared payments.'],
            ], 400);
        }

        $actor = $this->resolveUser($request);
        if ($actor && ! $actor->can('payments.view') && ! $actor->hasRole('Super Administrator') && $actor->id !== $transaction->user_id) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You are not authorized to view this receipt.'],
            ], 403);
        }

        return ReceiptService::download($transaction);
    }

    // =========================================================================
    // 6. Online Payment Gateway Checkout Flow (Mobile / Web App)
    // =========================================================================

    /**
     * 6.1 Create Online Payment Order for Invoice.
     */
    public function createCheckoutOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:student_fee_invoices,id',
            'gateway' => 'nullable|string|in:razorpay,phonepe,ccavenue',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid checkout parameters', 422, $validator->errors()->toArray());
        }

        $invoice = StudentFeeInvoice::with(['user', 'feeType'])->findOrFail($request->input('invoice_id'));

        if ($invoice->isPaid()) {
            return $this->error('INVOICE_ALREADY_PAID', 'This invoice has already been fully paid.', 400);
        }

        $driver = $request->input('gateway', 'razorpay');

        try {
            $gateway = $this->gatewayManager->getGateway($driver);

            $transaction = PaymentTransaction::create([
                'transaction_no' => PaymentTransaction::generateTransactionNo(),
                'invoice_id' => $invoice->id,
                'user_id' => $invoice->user_id,
                'student_id' => $invoice->student_id,
                'fee_type_id' => $invoice->fee_type_id,
                'amount' => (float) $invoice->balance_amount,
                'currency' => 'INR',
                'gateway' => $driver,
                'payment_method' => 'online',
                'status' => PaymentTransaction::STATUS_PENDING,
                'is_offline' => false,
                'created_by' => $invoice->user_id,
            ]);

            $orderData = $gateway->createOrder($transaction, [
                'customer_name' => $invoice->user?->name,
                'customer_email' => $invoice->user?->email,
                'customer_phone' => $invoice->user?->phone,
            ]);

            return $this->success([
                'transaction_no' => $transaction->transaction_no,
                'gateway' => $driver,
                'amount' => (float) $transaction->amount,
                'currency' => 'INR',
                'order' => $orderData,
                'invoice' => [
                    'id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'title' => $invoice->title,
                    'balance_amount' => (float) $invoice->balance_amount,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error('CHECKOUT_INITIATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 6.2 Verify Online Gateway Payment Completion (Callback / Webhook / App Return).
     */
    public function verifyCheckoutPayment(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_identifier' => 'required|string',
            'gateway_payment_id' => 'required|string',
            'gateway_signature' => 'nullable|string',
            'payload' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid payment verification payload', 422, $validator->errors()->toArray());
        }

        $transaction = $this->findTransaction($request->input('transaction_identifier'));

        if (! $transaction) {
            return $this->error('TRANSACTION_NOT_FOUND', "Transaction '{$request->input('transaction_identifier')}' not found", 404);
        }

        if ($transaction->status === PaymentTransaction::STATUS_SUCCESSFUL) {
            return $this->success($this->formatTransaction($transaction), ['message' => 'Payment has already been confirmed.']);
        }

        try {
            $completed = FeePaymentService::completeOnlinePayment(
                transaction: $transaction,
                gatewayPaymentId: $request->input('gateway_payment_id'),
                signature: $request->input('gateway_signature'),
                responsePayload: $request->input('payload')
            );

            return $this->success($this->formatTransaction($completed), [
                'message' => "Online payment verified successfully. Receipt: {$completed->receipt_no}",
            ]);
        } catch (ValidationException $e) {
            return $this->error('PAYMENT_VERIFICATION_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('PAYMENT_VERIFICATION_FAILED', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 6.5 CCAvenue Omnichannel Integration & Programmatic Operations API
    // =========================================================================

    /**
     * 6.5.1 Initiate CCAvenue Checkout for Mobile, Web, or Desktop POS Clients.
     */
    public function initiateCcavenueCheckout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'invoice_id' => 'nullable|integer|exists:student_fee_invoices,id',
            'transaction_id' => 'nullable|integer|exists:payment_transactions,id',
            'amount' => 'nullable|numeric|min:1',
            'currency' => 'nullable|string|size:3',
            'redirect_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
            'notes' => 'nullable|string|max:500',
            'payment_type' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid checkout initiation payload', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);
        $transaction = null;

        if ($request->filled('transaction_id')) {
            $transaction = PaymentTransaction::find($request->input('transaction_id'));
        } elseif ($request->filled('invoice_id')) {
            $invoice = StudentFeeInvoice::find($request->input('invoice_id'));
            if (! $invoice) {
                return $this->error('INVOICE_NOT_FOUND', 'Invoice not found', 404);
            }
            if ($invoice->isPaid()) {
                return $this->error('INVOICE_ALREADY_PAID', 'This fee invoice has already been fully settled.', 422);
            }

            $user = $user ?? $invoice->student;
            $amount = $request->filled('amount') ? (float) $request->input('amount') : (float) $invoice->balance_amount;

            $transaction = PaymentTransaction::create([
                'transaction_no' => PaymentTransaction::generateTransactionNo(),
                'user_id' => $user?->id ?? $invoice->user_id ?? $invoice->student_id,
                'student_id' => $invoice->student_id,
                'invoice_id' => $invoice->id,
                'fee_type_id' => $invoice->fee_type_id,
                'payment_category' => $invoice->feeType?->category ?? 'academic',
                'amount' => $amount,
                'currency' => $request->input('currency', 'INR'),
                'payment_method' => 'online',
                'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
                'status' => PaymentTransaction::STATUS_PENDING,
                'notes' => $request->input('notes', 'Omnichannel CCAvenue API Checkout initiation'),
            ]);
        } elseif ($request->filled('amount') && $user) {
            $amount = (float) $request->input('amount');
            $paymentType = $request->input('payment_type', 'tuition_fee');

            $transaction = PaymentTransaction::create([
                'transaction_no' => PaymentTransaction::generateTransactionNo(),
                'user_id' => $user->id,
                'student_id' => $user->student_id ?? null,
                'amount' => $amount,
                'currency' => $request->input('currency', 'INR'),
                'payment_method' => 'online',
                'gateway' => PaymentTransaction::GATEWAY_CCAVENUE,
                'status' => PaymentTransaction::STATUS_PENDING,
                'notes' => $request->input('notes', "Omnichannel CCAvenue API Payment: {$paymentType}"),
            ]);
        } else {
            return $this->error('INVALID_REQUEST', 'Provide either invoice_id, transaction_id, or an authenticated user with amount.', 422);
        }

        if (! $transaction) {
            return $this->error('TRANSACTION_CREATION_FAILED', 'Failed to resolve or initialize payment transaction.', 500);
        }

        try {
            $gateway = new CCAVenueGateway;
            $orderData = $gateway->createOrder($transaction, [
                'redirect_url' => $request->input('redirect_url', route('payments.ccavenue.response')),
                'cancel_url' => $request->input('cancel_url', route('payments.ccavenue.cancel')),
            ]);

            return $this->success([
                'transaction_id' => $transaction->id,
                'transaction_no' => $transaction->transaction_no,
                'order_id' => $orderData['order_id'],
                'amount' => (float) $transaction->amount,
                'currency' => $orderData['currency'],
                'gateway' => 'ccavenue',
                'checkout_url' => $orderData['checkout_url'],
                'access_code' => $orderData['access_code'],
                'enc_request' => $orderData['enc_request'],
                'billing_data' => $orderData['billing_data'] ?? $orderData['billing'] ?? [],
                'redirect_url' => $request->input('redirect_url', route('payments.ccavenue.response')),
                'cancel_url' => $request->input('cancel_url', route('payments.ccavenue.cancel')),
                'status' => $transaction->status,
            ], [
                'message' => 'CCAvenue checkout initiated successfully.',
            ]);
        } catch (Throwable $e) {
            return $this->error('GATEWAY_INITIALIZATION_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * 6.5.2 Decrypt & Process CCAvenue Callback / Server Webhook.
     */
    public function handleCcavenueCallback(Request $request): JsonResponse
    {
        $gateway = new CCAVenueGateway;
        $responseData = [];

        if ($request->filled('encResp')) {
            $decrypted = $gateway->decrypt((string) $request->input('encResp'));
            if (! $decrypted) {
                return $this->error('DECRYPTION_FAILED', 'Unable to decrypt CCAvenue encrypted response.', 422);
            }
            $responseData = $gateway->parseResponse($decrypted);
        } else {
            $responseData = $request->all();
        }

        $orderId = $responseData['order_id'] ?? null;
        $trackingId = $responseData['tracking_id'] ?? null;
        $merchantParam1 = $responseData['merchant_param1'] ?? null;
        $merchantParam4 = $responseData['merchant_param4'] ?? null;

        $transaction = null;
        if (! empty($merchantParam4) && is_numeric($merchantParam4)) {
            $transaction = PaymentTransaction::find((int) $merchantParam4);
        }
        if (! $transaction && ! empty($merchantParam1)) {
            $transaction = PaymentTransaction::where('transaction_no', $merchantParam1)->first()
                ?? (is_numeric($merchantParam1) ? PaymentTransaction::find((int) $merchantParam1) : null);
        }
        if (! $transaction && ! empty($orderId)) {
            $cleanOrderId = preg_replace('/^CC_/', '', (string) $orderId);
            $cleanOrderId = str_replace('_', '-', $cleanOrderId);
            $transaction = PaymentTransaction::where('gateway_order_id', $orderId)->first()
                ?? PaymentTransaction::where('transaction_no', $orderId)->first()
                ?? PaymentTransaction::where('transaction_no', $cleanOrderId)->first();
        }

        if (! $transaction) {
            return $this->error('TRANSACTION_NOT_FOUND', 'Could not associate CCAvenue response with any transaction.', 404, [
                'order_id' => $orderId,
                'tracking_id' => $trackingId,
            ]);
        }

        $orderStatus = strtoupper((string) ($responseData['order_status'] ?? ''));
        $chargedAmount = isset($responseData['amount']) ? (float) $responseData['amount'] : (float) $transaction->amount;
        $expectedAmount = (float) $transaction->amount;

        if ($orderStatus === 'SUCCESS') {
            if ($transaction->status === PaymentTransaction::STATUS_SUCCESSFUL) {
                return $this->success($this->formatTransaction($transaction), [
                    'message' => 'Transaction was already verified as successful.',
                ]);
            }

            if ($chargedAmount < ($expectedAmount - 0.01)) {
                $mismatchNotes = sprintf(
                    'Underpayment received via CCAvenue: Expected ₹%s, received ₹%s (Deficit: ₹%s). Order status marked Under Review for audit verification.',
                    number_format($expectedAmount, 2),
                    number_format($chargedAmount, 2),
                    number_format($expectedAmount - $chargedAmount, 2)
                );
                FeePaymentService::handleAmountMismatch($transaction, $responseData, $mismatchNotes);

                return $this->success($this->formatTransaction($transaction->fresh()), [
                    'status' => 'under_review',
                    'message' => 'Amount mismatch detected. Payment placed under review.',
                ]);
            }

            $completed = FeePaymentService::completeOnlinePaymentWithCharges($transaction, $responseData);

            return $this->success($this->formatTransaction($completed), [
                'status' => 'completed',
                'message' => 'Payment completed and reconciled successfully.',
            ]);
        }

        if (in_array($orderStatus, ['ABORTED', 'FAILURE', 'CANCELLED'])) {
            $transaction->update([
                'status' => PaymentTransaction::STATUS_FAILED,
                'gateway_tracking_id' => $trackingId ?: $transaction->gateway_tracking_id,
                'admin_remarks' => "Payment {$orderStatus} reported via CCAvenue API: ".($responseData['status_message'] ?? 'No failure description supplied'),
            ]);

            return $this->error('PAYMENT_TERMINATED', "Payment was {$orderStatus} by gateway or user.", 400, [
                'order_status' => $orderStatus,
                'status_message' => $responseData['status_message'] ?? null,
            ]);
        }

        return $this->success($this->formatTransaction($transaction), [
            'status' => $transaction->status,
            'gateway_status' => $orderStatus,
            'message' => "Payment status is {$orderStatus}.",
        ]);
    }

    /**
     * 6.5.3 Synchronize Payment Transaction Status via CCAvenue Order Status Tracker.
     */
    public function syncCcavenueStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transaction_identifier' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Transaction identifier required', 422, $validator->errors()->toArray());
        }

        $transaction = $this->findTransaction($request->input('transaction_identifier'));

        if (! $transaction) {
            return $this->error('TRANSACTION_NOT_FOUND', "Transaction '{$request->input('transaction_identifier')}' not found", 404);
        }

        $result = FeePaymentService::syncWithCCAvenue($transaction);

        return $this->success([
            'sync_result' => $result,
            'transaction' => $this->formatTransaction($transaction->fresh()),
        ], [
            'message' => $result['message'] ?? 'Synchronization completed.',
        ]);
    }

    /**
     * 6.5.4 Process Full or Partial Refund for a Transaction.
     */
    public function refundTransaction(Request $request, string $identifier): JsonResponse
    {
        $actor = $this->resolveUser($request);

        if ($actor && ! $actor->can('payments.refund') && ! $actor->hasRole('Super Administrator')) {
            return $this->error('FORBIDDEN', 'You do not have permission to process payment refunds.', 403);
        }

        $transaction = $this->findTransaction($identifier);

        if (! $transaction) {
            return $this->error('TRANSACTION_NOT_FOUND', "Transaction '{$identifier}' not found", 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'reference_no' => 'nullable|string|max:100',
            'attempt_gateway_api' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid refund parameters', 422, $validator->errors()->toArray());
        }

        $amount = (float) $request->input('amount');
        $refundable = $transaction->getRefundableAmount();

        if ($amount > ($refundable + 0.001)) {
            return $this->error('REFUND_AMOUNT_EXCEEDED', "Requested refund of ₹{$amount} exceeds eligible refundable balance of ₹{$refundable}.", 422);
        }

        try {
            $refunded = FeePaymentService::refundPayment(
                transaction: $transaction,
                amount: $amount,
                reason: (string) $request->input('reason'),
                refundRefNo: $request->input('reference_no'),
                refundedBy: $actor?->id,
                attemptGatewayApi: $request->boolean('attempt_gateway_api', true)
            );

            return $this->success($this->formatTransaction($refunded), [
                'message' => 'Refund of ₹'.number_format($amount, 2).' recorded successfully.',
            ]);
        } catch (ValidationException $e) {
            return $this->error('REFUND_REJECTED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('REFUND_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 6.5.5 Reconcile Payment Transaction Status (Admin Manual Reconcile & Audit Override).
     */
    public function reconcileTransaction(Request $request, string $identifier): JsonResponse
    {
        $actor = $this->resolveUser($request);

        if ($actor && ! $actor->can('payments.reconcile') && ! $actor->hasRole('Super Administrator')) {
            return $this->error('FORBIDDEN', 'You do not have permission to reconcile payment records.', 403);
        }

        $transaction = $this->findTransaction($identifier);

        if (! $transaction) {
            return $this->error('TRANSACTION_NOT_FOUND', "Transaction '{$identifier}' not found", 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:completed,failed,under_review,cancelled',
            'gateway_tracking_id' => 'nullable|string|max:100',
            'gateway_bank_ref_no' => 'nullable|string|max:100',
            'gateway_payment_mode' => 'nullable|string|max:50',
            'gateway_charged_amount' => 'nullable|numeric|min:0',
            'gateway_fee' => 'nullable|numeric|min:0',
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid reconciliation payload', 422, $validator->errors()->toArray());
        }

        try {
            $updated = FeePaymentService::updatePaymentStatus(
                transaction: $transaction,
                newStatus: (string) $request->input('status'),
                details: [
                    'gateway_tracking_id' => $request->input('gateway_tracking_id'),
                    'gateway_bank_ref_no' => $request->input('gateway_bank_ref_no'),
                    'gateway_payment_mode' => $request->input('gateway_payment_mode'),
                    'gateway_charged_amount' => $request->input('gateway_charged_amount'),
                    'gateway_fee' => $request->input('gateway_fee'),
                    'admin_remarks' => $request->input('reason'),
                ],
                updatedBy: $actor?->id ?? 1
            );

            return $this->success($this->formatTransaction($updated), [
                'message' => "Transaction status reconciled to {$updated->status} successfully.",
            ]);
        } catch (ValidationException $e) {
            return $this->error('RECONCILIATION_REJECTED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('RECONCILIATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 6.5.6 Retrieve CCAvenue Gateway Configuration & Surcharge/Billing Policies.
     */
    public function getCcavenueConfig(): JsonResponse
    {
        $gateway = new CCAVenueGateway;

        $merchantId = (string) (Setting::get('payment.ccavenue_merchant_id') ?: Setting::get('ccavenue_merchant_id') ?: '');
        $accessCode = (string) (Setting::get('payment.ccavenue_access_code') ?: Setting::get('ccavenue_access_code') ?: '');
        $mode = (string) (Setting::get('payment.ccavenue_mode') ?: Setting::get('ccavenue_mode') ?: 'test');
        $billingPageEnabled = (bool) (Setting::get('payment.ccavenue_billing_page_enabled', false));

        return $this->success([
            'gateway' => 'ccavenue',
            'merchant_id' => $merchantId,
            'access_code' => ! empty($accessCode) ? substr($accessCode, 0, 4).'****' : null,
            'mode' => $mode,
            'checkout_url' => $gateway->getCheckoutUrl(),
            'currency' => 'INR',
            'billing_page_step_enabled' => $billingPageEnabled,
            'six_month_penalty_warning' => 'If billing information step is removed by CCAvenue, backend must supply country, telephone, and email on every transaction to prevent automatic 6-month reactivation.',
            'fallback_billing_defaults' => [
                'country' => (string) (Setting::get('payment.ccavenue_default_billing_country') ?: Setting::get('ccavenue_default_country') ?: 'India'),
                'state' => (string) (Setting::get('payment.ccavenue_default_billing_state') ?: Setting::get('ccavenue_default_state') ?: 'West Bengal'),
                'city' => (string) (Setting::get('payment.ccavenue_default_billing_city') ?: Setting::get('ccavenue_default_city') ?: 'Kolkata'),
                'zip_code' => (string) (Setting::get('payment.ccavenue_default_billing_zip') ?: Setting::get('ccavenue_default_zip') ?: '700106'),
                'telephone' => (string) (Setting::get('payment.ccavenue_default_billing_tel') ?: Setting::get('ccavenue_default_phone') ?: '9999999999'),
                'email' => (string) (Setting::get('payment.ccavenue_default_billing_email') ?: Setting::get('ccavenue_default_email') ?: config('mail.from.address', 'info@sntcssc.in')),
            ],
            'endpoints' => [
                'initiate' => route('api.v1.payments.ccavenue.initiate'),
                'callback' => route('api.v1.payments.ccavenue.callback'),
                'sync' => route('api.v1.payments.ccavenue.sync'),
                'status_tracker' => $gateway->getApiUrl(),
            ],
        ]);
    }

    /**
     * 6.5.7 Test CCAvenue AES Encryption & Decryption Handshake.
     */
    public function testCcavenueEncryption(Request $request): JsonResponse
    {
        $gateway = new CCAVenueGateway;
        $plaintext = $request->input('plaintext', 'amount=100.00&currency=INR&order_id=TEST_12345');

        $encrypted = $gateway->encrypt($plaintext);
        $decrypted = $encrypted ? $gateway->decrypt($encrypted) : null;
        $matches = ($plaintext === $decrypted);

        return $this->success([
            'plaintext_input' => $plaintext,
            'encrypted_hex' => $encrypted,
            'decrypted_output' => $decrypted,
            'encryption_healthy' => $matches,
            'cipher_algorithm' => 'AES-128-CBC',
        ], [
            'status' => $matches ? 'pass' : 'fail',
            'message' => $matches ? 'CCAvenue AES-128-CBC encryption and decryption verified.' : 'Encryption handshake failure. Check Working Key.',
        ]);
    }

    // =========================================================================
    // 7. Student Self-Service Feed (Mobile App & Student Web Portal)
    // =========================================================================

    /**
     * 7.1 Authenticated Student's Personal Invoices Feed.
     */
    public function myInvoices(Request $request): JsonResponse
    {
        $student = $this->resolveUser($request);

        if (! $student) {
            return $this->error('UNAUTHENTICATED', 'Student session or credentials required', 401);
        }

        $invoices = StudentFeeInvoice::with(['feeType', 'enrollment.course', 'enrollment.batch'])
            ->where('user_id', $student->id)
            ->latest('id')
            ->get();

        return $this->success($invoices->map(fn (StudentFeeInvoice $inv) => $this->formatInvoice($inv)));
    }

    /**
     * 7.2 Authenticated Student's Personal Transactions & Receipts.
     */
    public function myTransactions(Request $request): JsonResponse
    {
        $student = $this->resolveUser($request);

        if (! $student) {
            return $this->error('UNAUTHENTICATED', 'Student session or credentials required', 401);
        }

        $transactions = PaymentTransaction::with(['invoice', 'feeType'])
            ->where('user_id', $student->id)
            ->latest('id')
            ->get();

        return $this->success($transactions->map(fn (PaymentTransaction $tx) => $this->formatTransaction($tx)));
    }

    /**
     * 7.3 Authenticated Student's Financial Ledger Statement.
     */
    public function myLedger(Request $request): JsonResponse
    {
        $student = $this->resolveUser($request);

        if (! $student) {
            return $this->error('UNAUTHENTICATED', 'Student session or credentials required', 401);
        }

        return $this->buildStudentLedgerResponse($student);
    }

    /**
     * 7.4 Student Self-Submits Offline Payment with Bank Reference.
     */
    public function myOfflinePayment(Request $request): JsonResponse
    {
        $student = $this->resolveUser($request);

        if (! $student) {
            return $this->error('UNAUTHENTICATED', 'Student session or credentials required', 401);
        }

        $validator = Validator::make($request->all(), [
            'invoice_id' => 'required|exists:student_fee_invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:upi,neft,imps,bank_transfer',
            'offline_reference_id' => 'required|string|max:100',
            'offline_payment_date' => 'required|date',
            'offline_payment_time' => 'nullable|string|max:20',
            'user_remarks' => 'nullable|string|max:500',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid offline submission', 422, $validator->errors()->toArray());
        }

        $invoice = StudentFeeInvoice::where('id', $request->input('invoice_id'))
            ->where('user_id', $student->id)
            ->first();

        if (! $invoice) {
            return $this->error('INVOICE_NOT_FOUND', 'Invoice does not belong to your account', 404);
        }

        $data = $request->all();
        $data['user_id'] = $student->id;
        $data['student_id'] = $student->student_id;
        $data['fee_type_id'] = $invoice->fee_type_id;

        $proofFile = $request->file('proof_file');

        try {
            $transaction = FeePaymentService::recordOfflinePayment($data, $proofFile, $student->id);

            return $this->success(
                $this->formatTransaction($transaction),
                ['message' => "Offline payment {$transaction->transaction_no} submitted for review."],
                201
            );
        } catch (ValidationException $e) {
            return $this->error('SUBMISSION_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('SUBMISSION_FAILED', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 8. Financial Ledgers & Clearance Verification API
    // =========================================================================

    /**
     * 8.1 System-Wide Student Ledgers Search (Administrative & Auditing).
     */
    public function ledgers(Request $request): JsonResponse
    {
        $query = StudentLedger::with('user');

        if ($request->filled('student_identifier')) {
            $student = $this->findStudent($request->input('student_identifier'));
            if ($student) {
                $query->where('user_id', $student->id);
            }
        }

        if ($request->filled('entry_type')) {
            $query->where('entry_type', $request->input('entry_type'));
        }

        if ($request->filled('reference_type')) {
            $query->where('reference_type', $request->input('reference_type'));
        }

        if ($search = $request->input('search')) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('ledger_no', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $paginator = $query->latest('id')->paginate($perPage);

        return $this->success(
            collect($paginator->items())->map(fn (StudentLedger $l) => $this->formatLedgerEntry($l)),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    /**
     * 8.2 Comprehensive Student Financial Ledger Statement.
     */
    public function studentLedger(string $studentId, Request $request): JsonResponse
    {
        $student = $this->findStudent($studentId);

        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$studentId}' not found", 404);
        }

        return $this->buildStudentLedgerResponse($student);
    }

    /**
     * 8.3 Student Institutional Financial Clearance API.
     * Evaluates Tuition, Library Dues/Fines, Hostel Rent, and Examination Fees.
     */
    public function studentClearance(string $studentId, Request $request): JsonResponse
    {
        $student = $this->findStudent($studentId);

        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$studentId}' not found", 404);
        }

        // 1. Pending / Overdue Fee Invoices
        $pendingInvoices = StudentFeeInvoice::where('user_id', $student->id)
            ->whereNotIn('status', [StudentFeeInvoice::STATUS_PAID, StudentFeeInvoice::STATUS_WAIVED, StudentFeeInvoice::STATUS_CANCELLED])
            ->get();

        $tuitionDues = (float) $pendingInvoices->where('feeType.code', 'tuition')->sum('balance_amount');
        $otherInvoiceDues = (float) $pendingInvoices->where('feeType.code', '!=', 'tuition')->sum('balance_amount');

        // 2. Library Unpaid Fines
        $libraryFinesDues = (float) BookFine::where('user_id', $student->id)
            ->whereIn('status', [BookFine::STATUS_PENDING, BookFine::STATUS_PARTIALLY_PAID])
            ->sum(DB::raw('payable_amount - paid_amount'));

        // 3. Hostel Outstanding
        $hostelDues = (float) HostelAllocation::where('user_id', $student->id)
            ->where('status', 'active')
            ->whereNotNull('rent_balance')
            ->sum('rent_balance');

        $totalOutstanding = $tuitionDues + $otherInvoiceDues + $libraryFinesDues + $hostelDues;
        $isCleared = $totalOutstanding <= 0.00;

        $duesList = [];
        if ($tuitionDues > 0) {
            $duesList[] = [
                'module' => 'tuition',
                'description' => 'Unpaid Academic Tuition Fees',
                'amount' => $tuitionDues,
                'invoices_count' => $pendingInvoices->where('feeType.code', 'tuition')->count(),
            ];
        }
        if ($otherInvoiceDues > 0) {
            $duesList[] = [
                'module' => 'academic_other',
                'description' => 'Miscellaneous / Admission Invoices',
                'amount' => $otherInvoiceDues,
                'invoices_count' => $pendingInvoices->where('feeType.code', '!=', 'tuition')->count(),
            ];
        }
        if ($libraryFinesDues > 0) {
            $duesList[] = [
                'module' => 'library',
                'description' => 'Unpaid Library Overdue Fines',
                'amount' => $libraryFinesDues,
            ];
        }
        if ($hostelDues > 0) {
            $duesList[] = [
                'module' => 'hostel',
                'description' => 'Hostel Accommodation Rent Arrears',
                'amount' => $hostelDues,
            ];
        }

        return $this->success([
            'student' => [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'is_cleared' => $isCleared,
            'total_outstanding_amount' => $totalOutstanding,
            'dues_breakdown' => $duesList,
            'clearance_token' => $isCleared ? hash('sha256', "CLEARANCE-{$student->id}-".now()->toDateString()) : null,
            'verified_at' => now()->toIso8601String(),
        ]);
    }

    // =========================================================================
    // 9. Library Security Deposits Management
    // =========================================================================

    /**
     * 9.1 List Library Security Deposits.
     */
    public function libraryDeposits(Request $request): JsonResponse
    {
        $query = LibraryDeposit::with(['user.studentProfile', 'paymentTransaction']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('student_identifier')) {
            $student = $this->findStudent($request->input('student_identifier'));
            if ($student) {
                $query->where('user_id', $student->id);
            }
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $paginator = $query->latest('id')->paginate($perPage);

        return $this->success(
            collect($paginator->items())->map(fn (LibraryDeposit $d) => $this->formatLibraryDeposit($d)),
            [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ]
        );
    }

    /**
     * 9.2 Record Direct Library Security Deposit.
     */
    public function storeLibraryDeposit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'student_identifier' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:cash,card,pos,upi,static_qr,neft,cheque,dd',
            'payment_reference' => 'nullable|string|max:100',
            'deposit_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid deposit parameters', 422, $validator->errors()->toArray());
        }

        $student = $this->findStudent($request->input('student_identifier'));
        if (! $student) {
            return $this->error('STUDENT_NOT_FOUND', "Student '{$request->input('student_identifier')}' not found", 404);
        }

        $actor = $this->resolveUser($request);

        $data = $request->all();
        $data['user_id'] = $student->id;
        $data['student_id'] = $student->student_id;

        try {
            $deposit = LibraryDepositService::createDirectDeposit($data, $actor?->id ?? 1);

            return $this->success($this->formatLibraryDeposit($deposit), ['message' => 'Library security deposit recorded successfully.'], 201);
        } catch (Throwable $e) {
            return $this->error('DEPOSIT_CREATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 9.3 Request Refund for Library Deposit.
     */
    public function requestDepositRefund(string $id, Request $request): JsonResponse
    {
        $deposit = LibraryDeposit::find($id);

        if (! $deposit) {
            return $this->error('DEPOSIT_NOT_FOUND', "Deposit #{$id} not found", 404);
        }

        $validator = Validator::make($request->all(), [
            'bank_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50',
            'ifsc_code' => 'required|string|max:20',
            'account_holder_name' => 'required|string|max:100',
            'upi_id' => 'nullable|string|max:100',
            'refund_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid bank account details', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);

        try {
            $updated = LibraryDepositService::requestRefund(
                deposit: $deposit,
                requestedBy: $actor?->id ?? $deposit->user_id,
                bankDetails: $request->all()
            );

            return $this->success($this->formatLibraryDeposit($updated), ['message' => 'Deposit refund requested successfully.']);
        } catch (ValidationException $e) {
            return $this->error('REFUND_REQUEST_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('REFUND_REQUEST_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 9.4 Approve Refund for Library Deposit.
     */
    public function approveDepositRefund(string $id, Request $request): JsonResponse
    {
        $deposit = LibraryDeposit::find($id);

        if (! $deposit) {
            return $this->error('DEPOSIT_NOT_FOUND', "Deposit #{$id} not found", 404);
        }

        $actor = $this->resolveUser($request);

        try {
            $approvedAmount = $request->has('approved_amount') ? (float) $request->input('approved_amount') : null;
            $updated = LibraryDepositService::approveRefund(
                deposit: $deposit,
                reviewedBy: $actor?->id ?? 1,
                approvedAmount: $approvedAmount,
                adminNotes: $request->input('admin_notes')
            );

            return $this->success($this->formatLibraryDeposit($updated), ['message' => 'Deposit refund approved.']);
        } catch (ValidationException $e) {
            return $this->error('APPROVAL_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('APPROVAL_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 9.5 Reject Refund for Library Deposit.
     */
    public function rejectDepositRefund(string $id, Request $request): JsonResponse
    {
        $deposit = LibraryDeposit::find($id);

        if (! $deposit) {
            return $this->error('DEPOSIT_NOT_FOUND', "Deposit #{$id} not found", 404);
        }

        $actor = $this->resolveUser($request);

        try {
            $updated = LibraryDepositService::rejectRefund(
                deposit: $deposit,
                reviewedBy: $actor?->id ?? 1,
                rejectionNotes: $request->input('rejection_notes', 'Refund criteria not fulfilled')
            );

            return $this->success($this->formatLibraryDeposit($updated), ['message' => 'Deposit refund rejected.']);
        } catch (ValidationException $e) {
            return $this->error('REJECTION_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('REJECTION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * 9.6 Record Disbursement & Settle Library Deposit.
     */
    public function disburseDepositRefund(string $id, Request $request): JsonResponse
    {
        $deposit = LibraryDeposit::find($id);

        if (! $deposit) {
            return $this->error('DEPOSIT_NOT_FOUND', "Deposit #{$id} not found", 404);
        }

        $validator = Validator::make($request->all(), [
            'transaction_ref' => 'required|string|max:100',
            'admin_notes' => 'nullable|string|max:500',
            'proof_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid disbursement attributes', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $proofFile = $request->file('proof_file');

        try {
            $updated = LibraryDepositService::processDisbursement(
                deposit: $deposit,
                processedBy: $actor?->id ?? 1,
                transactionRef: $request->input('transaction_ref'),
                proofFile: $proofFile,
                adminNotes: $request->input('admin_notes')
            );

            return $this->success($this->formatLibraryDeposit($updated), ['message' => 'Refund disbursed and deposit settled.']);
        } catch (ValidationException $e) {
            return $this->error('DISBURSEMENT_DENIED', $e->getMessage(), 422, $e->errors());
        } catch (Throwable $e) {
            return $this->error('DISBURSEMENT_FAILED', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 10. Multi-Platform Export & Bulk Reconciliation
    // =========================================================================

    /**
     * 10.1 Export Transactions as Structured JSON Dataset.
     */
    public function exportTransactions(Request $request): JsonResponse
    {
        $query = PaymentTransaction::with(['user.studentProfile', 'feeType', 'invoice']);

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $transactions = $query->latest('id')->limit(5000)->get();

        return $this->success([
            'count' => $transactions->count(),
            'exported_at' => now()->toIso8601String(),
            'records' => $transactions->map(fn (PaymentTransaction $tx) => $this->formatTransaction($tx)),
        ]);
    }

    /**
     * 10.2 Bulk Reconcile Transactions Against Bank UTR / Settlement Feed.
     */
    public function importReconciliation(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entries' => 'required|array|min:1',
            'entries.*.reference_id' => 'required|string',
            'entries.*.status' => 'required|string|in:successful,rejected',
            'entries.*.admin_remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid reconciliation payload', 422, $validator->errors()->toArray());
        }

        $actor = $this->resolveUser($request);
        $entries = $request->input('entries');

        $reconciled = 0;
        $failed = 0;
        $errors = [];

        foreach ($entries as $entry) {
            $ref = trim($entry['reference_id']);
            $txn = PaymentTransaction::where('offline_reference_id', $ref)
                ->orWhere('gateway_payment_id', $ref)
                ->orWhere('transaction_no', $ref)
                ->first();

            if (! $txn) {
                $failed++;
                $errors[] = "Reference '{$ref}' did not match any transaction.";

                continue;
            }

            if ($txn->status === PaymentTransaction::STATUS_SUCCESSFUL && $entry['status'] === 'successful') {
                $reconciled++;

                continue;
            }

            try {
                if ($txn->is_offline && $txn->status === PaymentTransaction::STATUS_UNDER_REVIEW) {
                    FeePaymentService::verifyOfflinePayment(
                        transaction: $txn,
                        approved: $entry['status'] === 'successful',
                        verifiedBy: $actor?->id ?? 1,
                        adminRemarks: $entry['admin_remarks'] ?? 'Bank automated reconciliation'
                    );
                    $reconciled++;
                } else {
                    $failed++;
                    $errors[] = "Transaction {$txn->transaction_no} is not under review.";
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = "Transaction {$txn->transaction_no}: {$e->getMessage()}";
            }
        }

        return $this->success([
            'total_entries' => count($entries),
            'reconciled' => $reconciled,
            'failed' => $failed,
            'errors' => $errors,
        ], ['message' => "Reconciliation processed. {$reconciled} reconciled, {$failed} skipped/failed."]);
    }

    // =========================================================================
    // Formatters & Helper Builders
    // =========================================================================

    protected function formatFeeType(FeeType $ft, bool $includeDetails = false): array
    {
        $data = [
            'id' => $ft->id,
            'code' => $ft->code,
            'name' => $ft->name,
            'category' => $ft->category,
            'frequency' => $ft->frequency,
            'default_amount' => (float) $ft->default_amount,
            'is_refundable' => (bool) $ft->is_refundable,
            'status' => $ft->status,
            'description' => $ft->description,
            'created_at' => $ft->created_at?->toIso8601String(),
        ];

        if ($includeDetails) {
            $data['schedules_count'] = $ft->schedules_count ?? $ft->schedules()->count();
            $data['invoices_count'] = $ft->invoices_count ?? $ft->invoices()->count();
        }

        return $data;
    }

    protected function formatFeeSchedule(FeeSchedule $s): array
    {
        return [
            'id' => $s->id,
            'schedule_no' => $s->schedule_no,
            'title' => $s->title,
            'academic_year' => $s->academic_year,
            'period_month' => $s->period_month,
            'period_month_name' => $s->month_name,
            'period_year' => $s->period_year,
            'billing_cycle' => $s->billing_cycle,
            'due_date' => $s->due_date?->toDateString(),
            'grace_period_days' => (int) $s->grace_period_days,
            'late_fee_amount' => (float) $s->late_fee_amount,
            'status' => $s->status,
            'total_targeted_students' => (int) $s->total_targeted_students,
            'total_invoiced_amount' => (float) $s->total_invoiced_amount,
            'total_collected_amount' => (float) $s->total_collected_amount,
            'collection_percentage' => $s->total_invoiced_amount > 0 ? round(($s->total_collected_amount / $s->total_invoiced_amount) * 100, 2) : 0.00,
            'fee_type' => $s->feeType ? [
                'id' => $s->feeType->id,
                'name' => $s->feeType->name,
                'code' => $s->feeType->code,
            ] : null,
            'course' => $s->course ? [
                'id' => $s->course->id,
                'name' => $s->course->name,
                'code' => $s->course->code,
            ] : null,
            'batch' => $s->batch ? [
                'id' => $s->batch->id,
                'name' => $s->batch->name,
                'code' => $s->batch->code,
            ] : null,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    protected function formatInvoice(StudentFeeInvoice $inv, bool $includeDetails = false): array
    {
        $data = [
            'id' => $inv->id,
            'invoice_no' => $inv->invoice_no,
            'student_id' => $inv->student_id,
            'student_name' => $inv->user?->name,
            'title' => $inv->title,
            'period_month' => $inv->period_month,
            'period_month_name' => $inv->month_name,
            'period_year' => $inv->period_year,
            'amount' => (float) $inv->amount,
            'discount_amount' => (float) $inv->discount_amount,
            'late_fee_amount' => (float) $inv->late_fee_amount,
            'total_amount' => (float) $inv->total_amount,
            'paid_amount' => (float) $inv->paid_amount,
            'balance_amount' => (float) $inv->balance_amount,
            'due_date' => $inv->due_date?->toDateString(),
            'status' => $inv->effective_status,
            'paid_at' => $inv->paid_at?->toIso8601String(),
            'fee_type' => $inv->feeType ? [
                'id' => $inv->feeType->id,
                'name' => $inv->feeType->name,
                'code' => $inv->feeType->code,
            ] : null,
            'course' => $inv->enrollment?->course ? [
                'id' => $inv->enrollment->course->id,
                'name' => $inv->enrollment->course->name,
                'code' => $inv->enrollment->course->code,
            ] : null,
            'batch' => $inv->enrollment?->batch ? [
                'id' => $inv->enrollment->batch->id,
                'name' => $inv->enrollment->batch->name,
                'code' => $inv->enrollment->batch->code,
            ] : null,
            'created_at' => $inv->created_at?->toIso8601String(),
        ];

        if ($includeDetails) {
            $data['transactions'] = $inv->transactions->map(fn (PaymentTransaction $tx) => $this->formatTransaction($tx));
            $data['student_profile'] = [
                'email' => $inv->user?->email,
                'phone' => $inv->user?->phone,
                'social_category' => $inv->user?->studentProfile?->category,
            ];
        }

        return $data;
    }

    protected function formatTransaction(PaymentTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'transaction_no' => $tx->transaction_no,
            'receipt_no' => $tx->receipt_no,
            'receipt_url' => $tx->receipt_no ? route('api.v1.payments.receipt.download', ['receiptNo' => $tx->receipt_no]) : null,
            'invoice_id' => $tx->invoice_id,
            'invoice_no' => $tx->invoice?->invoice_no,
            'student_id' => $tx->student_id,
            'student_name' => $tx->user?->name,
            'amount' => (float) $tx->amount,
            'currency' => $tx->currency,
            'gateway' => $tx->gateway,
            'payment_method' => $tx->payment_method,
            'status' => $tx->status,
            'is_offline' => (bool) $tx->is_offline,
            'gateway_charged_amount' => $tx->gateway_charged_amount !== null ? (float) $tx->gateway_charged_amount : (float) $tx->amount,
            'gateway_fee' => (float) $tx->gateway_fee,
            'gateway_tax' => (float) $tx->gateway_tax,
            'gateway_tracking_id' => $tx->gateway_tracking_id,
            'gateway_bank_ref_no' => $tx->gateway_bank_ref_no,
            'gateway_payment_mode' => $tx->gateway_payment_mode,
            'gateway_card_name' => $tx->gateway_card_name,
            'amount_mismatch' => (bool) $tx->amount_mismatch,
            'amount_mismatch_notes' => $tx->amount_mismatch_notes,
            'refund_amount' => (float) $tx->refund_amount,
            'refund_reason' => $tx->refund_reason,
            'refund_reference_no' => $tx->refund_reference_no,
            'refund_status' => $tx->refund_status,
            'refunded_at' => $tx->refunded_at?->toIso8601String(),
            'refunded_by_name' => $tx->refundedBy?->name,
            'billing_country' => $tx->billing_country,
            'billing_tel' => $tx->billing_tel,
            'billing_email' => $tx->billing_email,
            'offline_reference_id' => $tx->offline_reference_id,
            'offline_payment_date' => $tx->offline_payment_date?->toDateString(),
            'user_remarks' => $tx->user_remarks,
            'admin_remarks' => $tx->admin_remarks,
            'verified_at' => $tx->verified_at?->toIso8601String(),
            'verifier_name' => $tx->verifier?->name,
            'created_at' => $tx->created_at?->toIso8601String(),
        ];
    }

    protected function formatLedgerEntry(StudentLedger $l): array
    {
        return [
            'id' => $l->id,
            'ledger_no' => $l->ledger_no,
            'student_id' => $l->student_id,
            'student_name' => $l->user?->name,
            'entry_type' => $l->entry_type,
            'amount' => (float) $l->amount,
            'balance_after' => (float) $l->balance_after,
            'reference_type' => $l->reference_type,
            'reference_id' => $l->reference_id,
            'description' => $l->description,
            'transaction_date' => $l->transaction_date?->toDateString(),
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }

    protected function formatLibraryDeposit(LibraryDeposit $d): array
    {
        return [
            'id' => $d->id,
            'deposit_no' => $d->deposit_no,
            'student_id' => $d->student_id,
            'student_name' => $d->user?->name,
            'amount' => (float) $d->amount,
            'status' => $d->status,
            'deposit_date' => $d->deposit_date?->toDateString(),
            'refund_details' => $d->refund_requested_at ? [
                'requested_at' => $d->refund_requested_at?->toIso8601String(),
                'bank_name' => $d->bank_name,
                'account_number' => $d->account_number,
                'ifsc_code' => $d->ifsc_code,
                'account_holder_name' => $d->account_holder_name,
                'upi_id' => $d->upi_id,
                'refund_approved_amount' => (float) $d->refund_approved_amount,
                'refund_processed_at' => $d->refund_processed_at?->toIso8601String(),
                'refund_transaction_ref' => $d->refund_transaction_ref,
            ] : null,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    protected function buildStudentLedgerResponse(User $student): JsonResponse
    {
        $ledgers = StudentLedger::where('user_id', $student->id)
            ->orderBy('id', 'asc')
            ->get();

        $totalDebits = (float) $ledgers->where('entry_type', StudentLedger::ENTRY_DEBIT)->sum('amount');
        $totalCredits = (float) $ledgers->where('entry_type', StudentLedger::ENTRY_CREDIT)->sum('amount');
        $netBalance = max(0.00, $totalDebits - $totalCredits);

        return $this->success([
            'student' => [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name' => $student->name,
                'email' => $student->email,
            ],
            'summary' => [
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'net_liability_balance' => $netBalance,
            ],
            'entries' => $ledgers->map(fn (StudentLedger $l) => $this->formatLedgerEntry($l)),
        ]);
    }
}
