<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Attendance extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LATE = 'late';

    public const STATUS_HALF_DAY = 'half_day';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_HOLIDAY = 'holiday';

    public const STATUS_WEEKLY_OFF = 'weekly_off';

    public const SOURCE_MANUAL_SINGLE = 'manual_single';

    public const SOURCE_MANUAL_BULK = 'manual_bulk';

    public const SOURCE_BIOMETRIC_DEVICE = 'biometric_device';

    public const SOURCE_RFID_CARD = 'rfid_card';

    public const SOURCE_MOBILE_QR = 'mobile_qr';

    public const SOURCE_SYSTEM_AUTO_LEAVE = 'system_auto_leave';

    public const SOURCE_IMPORT = 'import';

    protected $fillable = [
        'attendance_no',
        'user_id',
        'date',
        'user_type',
        'course_id',
        'batch_id',
        'section_id',
        'status',
        'check_in_time',
        'check_out_time',
        'working_hours',
        'late_minutes',
        'overtime_minutes',
        'entry_source',
        'leave_application_id',
        'biometric_device_id',
        'marked_by',
        'remarks',
        'notification_sent',
        'notification_sent_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'working_hours' => 'decimal:2',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'notification_sent' => 'boolean',
            'notification_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Attendance $attendance) {
            if (empty($attendance->attendance_no)) {
                $attendance->attendance_no = static::generateAttendanceNumber();
            }
        });
    }

    public static function generateAttendanceNumber(): string
    {
        $year = Carbon::now()->format('Y');
        $prefix = "ATT-{$year}-";
        $latest = static::withTrashed()
            ->where('attendance_no', 'like', "{$prefix}%")
            ->orderByDesc('id')
            ->first();

        $sequence = 1;
        if ($latest && preg_match('/ATT-\d{4}-(\d+)/', $latest->attendance_no, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }

    public function biometricDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(AttendanceNotificationLog::class, 'attendance_id');
    }

    public function scopeForStaff(Builder $query): Builder
    {
        return $query->where('user_type', 'staff');
    }

    public function scopeForStudent(Builder $query): Builder
    {
        return $query->where('user_type', 'student');
    }

    public function scopePresent(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PRESENT, self::STATUS_LATE, self::STATUS_HALF_DAY]);
    }

    public function scopeAbsent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ABSENT);
    }

    public function scopeOnLeave(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ON_LEAVE);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_PRESENT => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            self::STATUS_LATE => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
            self::STATUS_HALF_DAY => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
            self::STATUS_ON_LEAVE => 'bg-sky-500/10 text-sky-600 dark:text-sky-400 border-sky-500/20',
            self::STATUS_HOLIDAY => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
            self::STATUS_WEEKLY_OFF => 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border-zinc-500/20',
            default => 'bg-red-500/10 text-red-600 dark:text-red-400 border-red-500/20',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PRESENT => __('Present'),
            self::STATUS_ABSENT => __('Absent'),
            self::STATUS_LATE => __('Late'),
            self::STATUS_HALF_DAY => __('Half Day'),
            self::STATUS_ON_LEAVE => __('On Leave'),
            self::STATUS_HOLIDAY => __('Holiday'),
            self::STATUS_WEEKLY_OFF => __('Weekly Off'),
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
}
