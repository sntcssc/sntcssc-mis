<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Actions\Support\UrnGenerator;
use App\Concerns\Auditable;
use App\Concerns\HasTeams;
use App\Services\FileUploadService;
use App\Services\UserStudentSyncService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $uuid
 * @property string|null $urn
 * @property string $name
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $whatsapp_no
 * @property Carbon|null $dob
 * @property string|null $gender
 * @property string|null $tenth_roll
 * @property string|null $id_type
 * @property string|null $id_number
 * @property string|null $designation
 * @property string $status
 * @property string|null $avatar
 * @property-read string|null $avatar_url
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_team_id
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_untill
 * @property int|null $deleted_by
 * @property int|null $updated_by
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team|null $currentTeam
 * @property-read Collection<int, Team> $ownedTeams
 * @property-read Collection<int, Membership> $teamMemberships
 * @property-read Collection<int, Team> $teams
 * @property-read User|null $deletedByUser
 * @property-read User|null $updatedByUser
 * @property-read User|null $createdByUser
 */
#[Fillable([
    'uuid',
    'urn',
    'student_id',
    'name',
    'first_name',
    'last_name',
    'email',
    'phone',
    'whatsapp_no',
    'dob',
    'gender',
    'tenth_roll',
    'id_type',
    'id_number',
    'designation',
    'status',
    'avatar',
    'password',
    'phone_verified_at',
    'email_verified_at',
    'current_team_id',
    'last_login_at',
    'last_login_ip',
    'failed_login_attempts',
    'locked_untill',
    'deleted_by',
    'updated_by',
    'created_by',
    'biometric_pin',
    'rfid_card_no',
    'employee_id',
    'staff_department',
    'preferences',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRoles, HasTeams, Notifiable, PasskeyAuthenticatable, SoftDeletes, TwoFactorAuthenticatable {
        HasTeams::teams insteadof HasRoles;
        HasRoles::teams as permissionTeams;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }

            if (empty($user->urn)) {
                $user->urn = UrnGenerator::generate();
            }

            if (empty($user->name) && (! empty($user->first_name) || ! empty($user->last_name))) {
                $user->name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            } elseif (! empty($user->name) && empty($user->first_name)) {
                $parts = explode(' ', trim($user->name), 2);
                $user->first_name = $parts[0] ?? '';
                $user->last_name = $parts[1] ?? '';
            }

            if (empty($user->status)) {
                $user->status = 'active';
            }

            if (auth()->check() && empty($user->created_by)) {
                $user->created_by = auth()->id();
            }
        });

        static::updating(function (User $user) {
            if (auth()->check() && ! $user->isDirty('updated_by')) {
                $user->updated_by = auth()->id();
            }

            if ($user->isDirty(['first_name', 'last_name']) && ! $user->isDirty('name')) {
                $user->name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
            } elseif ($user->isDirty('name') && ! $user->isDirty(['first_name', 'last_name'])) {
                $parts = explode(' ', trim($user->name), 2);
                $user->first_name = $parts[0] ?? '';
                $user->last_name = $parts[1] ?? '';
            }
        });

        static::saved(function (User $user) {
            UserStudentSyncService::syncFromUser($user);
        });

        static::deleting(function (User $user) {
            if (auth()->check() && ! $user->isForceDeleting() && empty($user->deleted_by)) {
                $user->deleted_by = auth()->id();
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'dob' => 'date',
            'last_login_at' => 'datetime',
            'locked_untill' => 'datetime',
            'failed_login_attempts' => 'integer',
            'preferences' => 'array',
        ];
    }

    /**
     * Retrieve a specific user UI or system preference.
     */
    public function getPreference(string $key, mixed $default = null): mixed
    {
        $prefs = $this->preferences ?? [];

        return data_get($prefs, $key, $default);
    }

    /**
     * Store or update a specific user UI or system preference.
     */
    public function setPreference(string $key, mixed $value): void
    {
        $prefs = $this->preferences ?? [];
        data_set($prefs, $key, $value);
        $this->preferences = $prefs;
        $this->save();
    }

    /**
     * Mutator to safely handle Date of Birth attribute (converts empty string to null).
     */
    public function setDobAttribute(mixed $value): void
    {
        if (empty($value)) {
            $this->attributes['dob'] = null;
        } elseif ($value instanceof \DateTimeInterface) {
            $this->attributes['dob'] = $value->format('Y-m-d');
        } else {
            try {
                $this->attributes['dob'] = Carbon::parse($value)->format('Y-m-d');
            } catch (\Throwable) {
                $this->attributes['dob'] = null;
            }
        }
    }

    /**
     * Mutators for nullable string attributes to ensure empty strings are stored as null.
     */
    public function setWhatsappNoAttribute(mixed $value): void
    {
        $this->attributes['whatsapp_no'] = is_string($value) && trim($value) === '' ? null : $value;
    }

    public function setTenthRollAttribute(mixed $value): void
    {
        $this->attributes['tenth_roll'] = is_string($value) && trim($value) === '' ? null : $value;
    }

    public function setIdTypeAttribute(mixed $value): void
    {
        $this->attributes['id_type'] = is_string($value) && trim($value) === '' ? null : $value;
    }

    public function setIdNumberAttribute(mixed $value): void
    {
        $this->attributes['id_number'] = is_string($value) && trim($value) === '' ? null : $value;
    }

    public function setGenderAttribute(mixed $value): void
    {
        $this->attributes['gender'] = is_string($value) && trim($value) === '' ? null : $value;
    }

    public function setDesignationAttribute(mixed $value): void
    {
        $this->attributes['designation'] = is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * Generate next Unique Registration Number using UrnGenerator.
     */
    public static function generateNextUrn(): string
    {
        return UrnGenerator::generate();
    }

    /**
     * Creator relationship.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Updater relationship.
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Deleter relationship.
     */
    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Internal App Notifications relationship.
     *
     * @return HasMany<AppNotification>
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'user_id');
    }

    /**
     * Count unread app notifications.
     */
    public function unreadAppNotificationsCount(): int
    {
        return $this->appNotifications()->unread()->count();
    }

    /**
     * Count unread live chat messages for user.
     */
    public function unreadChatMessagesCount(): int
    {
        return ChatMessageStatus::where('user_id', $this->id)
            ->where('is_read', false)
            ->where('is_deleted_for_me', false)
            ->whereHas('message', fn ($q) => $q->where('is_deleted_for_everyone', false))
            ->count();
    }

    /**
     * Determine whether the user is an administrator (Super Administrator or Administrator).
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['Super Administrator', 'Administrator', 'Super Admin', 'Admin']);
    }

    /**
     * Count open tickets for user or system if staff.
     */
    public function openTicketsCount(): int
    {
        if ($this->hasAnyRole(['Super Administrator', 'Administrator', 'Staff'])) {
            return Ticket::whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_PENDING_USER])->count();
        }

        return Ticket::where('user_id', $this->id)
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS, Ticket::STATUS_PENDING_USER])
            ->count();
    }

    /**
     * Determine if user account is locked.
     */
    public function isLocked(): bool
    {
        return ! is_null($this->locked_untill) && $this->locked_untill->isFuture();
    }

    /**
     * Lock user account until given time or default 30 minutes.
     */
    public function lockAccount(?\DateTimeInterface $until = null): bool
    {
        return $this->forceFill([
            'locked_untill' => $until ?? now()->addMinutes(30),
            'status' => 'locked',
        ])->save();
    }

    /**
     * Unlock user account and reset failed login attempts.
     */
    public function unlockAccount(): bool
    {
        return $this->forceFill([
            'locked_untill' => null,
            'failed_login_attempts' => 0,
            'status' => $this->status === 'locked' ? 'active' : $this->status,
        ])->save();
    }

    /**
     * Record a successful login.
     */
    public function recordLogin(?string $ip = null): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip ?? request()->ip(),
            'failed_login_attempts' => 0,
            'locked_untill' => null,
        ])->save();
    }

    /**
     * Record a failed login attempt with auto-lockout threshold.
     */
    public function recordFailedLogin(int $maxAttempts = 5, int $lockoutMinutes = 30): void
    {
        $attempts = $this->failed_login_attempts + 1;
        $updates = ['failed_login_attempts' => $attempts];

        if ($attempts >= $maxAttempts) {
            $updates['locked_untill'] = now()->addMinutes($lockoutMinutes);
            $updates['status'] = 'locked';
        }

        $this->forceFill($updates)->save();
    }

    /**
     * Get badge color representation of status.
     */
    public function statusBadgeColor(): string
    {
        if ($this->isLocked()) {
            return 'rose';
        }

        return match (strtolower((string) $this->status)) {
            'active' => 'emerald',
            'inactive' => 'zinc',
            'suspended' => 'amber',
            'invited' => 'sky',
            'pending' => 'indigo',
            default => 'secondary',
        };
    }

    /**
     * Determine if the user has verified their phone number.
     */
    public function hasVerifiedPhone(): bool
    {
        return ! is_null($this->phone_verified_at);
    }

    /**
     * Mark the given user's phone as verified.
     */
    public function markPhoneAsVerified(): bool
    {
        return $this->forceFill([
            'phone_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name ?: ($this->first_name.' '.$this->last_name), true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : ($initials ?: 'U');
    }

    /**
     * Get the public URL to the user's profile avatar.
     */
    public function avatarUrl(): ?string
    {
        if (empty($this->avatar)) {
            return null;
        }

        return FileUploadService::url($this->avatar);
    }

    /**
     * Get the public URL to the user's profile avatar (attribute accessor).
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatarUrl();
    }

    /**
     * Check if the user is currently online.
     */
    public function isOnline(): bool
    {
        if ($this->id === auth()->id()) {
            return true;
        }

        if (Cache::has('user-online-'.$this->id)) {
            return true;
        }

        if ($this->last_login_at && $this->last_login_at->gt(now()->subMinutes(15))) {
            return true;
        }

        return false;
    }

    /**
     * Get human readable last seen or online status string.
     */
    public function lastSeenText(): string
    {
        if ($this->isOnline()) {
            return __('Online');
        }

        if ($this->last_login_at) {
            return __('Last seen :time', ['time' => $this->last_login_at->shortRelativeDiffForHumans()]);
        }

        return __('Offline');
    }

    /* ----------------------------------------------------------------- *
     *  Student Dossier & Enrollment Relationships
     * ----------------------------------------------------------------- */

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function activeEnrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->where('status', 'active');
    }

    public function activeEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)->where('status', 'active')->latestOfMany();
    }

    public function faculty(): HasOne
    {
        return $this->hasOne(Faculty::class);
    }

    public function mockInterviewCandidates(): HasMany
    {
        return $this->hasMany(MockInterviewCandidate::class);
    }

    public function interviewBoardMember(): HasOne
    {
        return $this->hasOne(InterviewBoardMember::class);
    }

    public function studentTestResults(): HasMany
    {
        return $this->hasMany(StudentTestResult::class);
    }

    public function feeInvoices(): HasMany
    {
        return $this->hasMany(StudentFeeInvoice::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function studentLedgers(): HasMany
    {
        return $this->hasMany(StudentLedger::class);
    }

    public function ledgers(): HasMany
    {
        return $this->studentLedgers();
    }

    public function libraryDeposits(): HasMany
    {
        return $this->hasMany(LibraryDeposit::class);
    }

    public function activeLibraryDeposit(): ?LibraryDeposit
    {
        return $this->libraryDeposits()
            ->whereIn('status', [LibraryDeposit::STATUS_ACTIVE, LibraryDeposit::STATUS_REFUND_REQUESTED, LibraryDeposit::STATUS_REFUND_APPROVED])
            ->latest('id')
            ->first();
    }

    public function activeLibraryDeposits(): HasMany
    {
        return $this->libraryDeposits()
            ->whereIn('status', [LibraryDeposit::STATUS_ACTIVE, LibraryDeposit::STATUS_REFUND_REQUESTED, LibraryDeposit::STATUS_REFUND_APPROVED]);
    }

    public function totalActiveLibraryDepositAmount(): float
    {
        return (float) $this->libraryDeposits()
            ->where('status', LibraryDeposit::STATUS_ACTIVE)
            ->sum('amount');
    }

    public function totalHeldLibraryDepositAmount(): float
    {
        return (float) $this->libraryDeposits()
            ->whereIn('status', [LibraryDeposit::STATUS_ACTIVE, LibraryDeposit::STATUS_REFUND_REQUESTED, LibraryDeposit::STATUS_REFUND_APPROVED])
            ->sum('amount');
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class)->latest();
    }

    public function leaveAllocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderByDesc('date');
    }

    public function biometricLogs(): HasMany
    {
        return $this->hasMany(BiometricLog::class)->orderByDesc('punch_time');
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function alumniProfile(): HasOne
    {
        return $this->hasOne(Alumni::class, 'user_id');
    }

    public function assignedStudyMaterials(): BelongsToMany
    {
        return $this->belongsToMany(StudyMaterial::class, 'study_material_students')
            ->withTimestamps();
    }

    public function studyMaterialAccessLogs(): HasMany
    {
        return $this->hasMany(StudyMaterialAccessLog::class);
    }

    public function studentAddresses(): HasMany
    {
        return $this->hasMany(StudentAddress::class);
    }

    public function studentEducations(): HasMany
    {
        return $this->hasMany(StudentEducation::class);
    }

    public function studentEmployments(): HasMany
    {
        return $this->hasMany(StudentEmployment::class);
    }

    public function studentOtherDetails(): HasOne
    {
        return $this->hasOne(StudentOtherDetail::class);
    }

    public function studentDocuments(): HasMany
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function profileUpdateRequests(): HasMany
    {
        return $this->hasMany(ProfileUpdateRequest::class);
    }

    public function courseWithdrawalRequests(): HasMany
    {
        return $this->hasMany(CourseWithdrawalRequest::class)->latest();
    }

    public function studentDisciplinaryActions(): HasMany
    {
        return $this->hasMany(StudentDisciplinaryAction::class)->latest();
    }

    public function activeDisciplinaryAction(): ?StudentDisciplinaryAction
    {
        return $this->studentDisciplinaryActions()
            ->active()
            ->whereNull('course_id')
            ->first();
    }

    public function isBanned(): bool
    {
        if ($this->status === 'banned') {
            return true;
        }

        return $this->studentDisciplinaryActions()
            ->where('status', 'active')
            ->whereIn('action_type', ['ban', 'lifetime_ban'])
            ->whereNull('course_id')
            ->exists();
    }

    public function isSuspended(): bool
    {
        if ($this->status === 'suspended') {
            return true;
        }

        return $this->studentDisciplinaryActions()
            ->where('status', 'active')
            ->whereIn('action_type', ['suspension', 'temporary_suspension'])
            ->whereNull('course_id')
            ->exists();
    }

    public function isBarredFromCourse(int $courseId): bool
    {
        if ($this->isBanned() || $this->isSuspended()) {
            return true;
        }

        return $this->studentDisciplinaryActions()
            ->active()
            ->where('course_id', $courseId)
            ->exists();
    }

    public function upscAttempts(): HasMany
    {
        return $this->hasMany(UpscAttempt::class)->orderBy('exam_year', 'desc');
    }

    public function getPermanentAddressAttribute(): ?StudentAddress
    {
        return $this->studentAddresses->firstWhere('address_type', 'Permanent');
    }

    public function getCorrespondenceAddressAttribute(): ?StudentAddress
    {
        return $this->studentAddresses->firstWhere('address_type', 'Correspondence')
            ?? $this->permanent_address;
    }

    public function isStudent(): bool
    {
        if ($this->hasRole(['Super Administrator', 'Administrator', 'Admissions Officer', 'Faculty', 'Accountant', 'Staff', 'Librarian'])) {
            return false;
        }

        if ($this->hasRole('Student')) {
            return true;
        }

        return ! empty($this->student_id) || $this->enrollments()->exists() || $this->studentProfile()->exists();
    }

    /**
     * Calculate student profile completeness across the 7 mandatory dossier sections.
     *
     * @return array{
     *     is_complete: bool,
     *     percentage: int,
     *     completed_count: int,
     *     total_count: int,
     *     sections: array<string, array{title: string, is_complete: bool, missing: array<string>, has_pending_request: bool, pending_request_no: ?string}>,
     *     incomplete_sections: array<string>
     * }
     */
    public function getProfileCompleteness(): array
    {
        $profile = $this->relationLoaded('studentProfile') ? $this->studentProfile : $this->studentProfile()->first();
        $addresses = $this->relationLoaded('studentAddresses') ? $this->studentAddresses : $this->studentAddresses()->get();
        $educations = $this->relationLoaded('studentEducations') ? $this->studentEducations : $this->studentEducations()->get();
        $employments = $this->relationLoaded('studentEmployments') ? $this->studentEmployments : $this->studentEmployments()->get();
        $other = $this->relationLoaded('studentOtherDetails') ? $this->studentOtherDetails : $this->studentOtherDetails()->first();
        $documents = $this->relationLoaded('studentDocuments') ? $this->studentDocuments : $this->studentDocuments()->where('is_active', true)->get();
        $pendingRequests = $this->relationLoaded('profileUpdateRequests')
            ? $this->profileUpdateRequests->where('status', 'pending')
            : $this->profileUpdateRequests()->where('status', 'pending')->get();

        $sections = [];

        // 1. Account & Identity
        $accountMissing = [];
        if (empty($this->first_name)) {
            $accountMissing[] = __('First Name');
        }
        if (empty($this->phone)) {
            $accountMissing[] = __('Phone Number');
        }
        if (empty($this->gender)) {
            $accountMissing[] = __('Gender');
        }
        if (empty($this->dob)) {
            $accountMissing[] = __('Date of Birth');
        }
        $pendingAcc = $pendingRequests->firstWhere('section', 'account');
        $sections['account'] = [
            'title' => __('Account & Identity'),
            'label' => __('Account & Identity'),
            'is_complete' => empty($accountMissing),
            'missing' => $accountMissing,
            'has_pending' => $pendingAcc !== null,
            'has_pending_request' => $pendingAcc !== null,
            'pending_request_no' => $pendingAcc?->request_no,
        ];

        // 2. Profile & Family
        $profileMissing = [];
        if (! $profile) {
            $profileMissing[] = __('Profile details record');
        } else {
            if (empty($profile->father_name)) {
                $profileMissing[] = __("Father's Name");
            }
            if (empty($profile->mother_name)) {
                $profileMissing[] = __("Mother's Name");
            }
            if (empty($profile->category)) {
                $profileMissing[] = __('Category');
            }
            if ($profile->distance === null || $profile->distance === '') {
                $profileMissing[] = __('Distance to Institute');
            }
            if (! in_array($profile->category, ['UR', '', null], true) && empty($profile->cert_no)) {
                $profileMissing[] = __('Caste Certificate Number');
            }
            if ($profile->is_pwbd && empty($profile->pwbd_type)) {
                $profileMissing[] = __('Disability Type');
            }
        }
        $pendingProf = $pendingRequests->firstWhere('section', 'profile');
        $sections['profile'] = [
            'title' => __('Profile & Family'),
            'label' => __('Profile & Family'),
            'is_complete' => empty($profileMissing),
            'missing' => $profileMissing,
            'has_pending' => $pendingProf !== null,
            'has_pending_request' => $pendingProf !== null,
            'pending_request_no' => $pendingProf?->request_no,
        ];

        // 3. Addresses
        $addressMissing = [];
        $permAddress = $addresses->firstWhere('address_type', 'Permanent');
        if (! $permAddress) {
            $addressMissing[] = __('Permanent Address');
        } else {
            if (empty($permAddress->state)) {
                $addressMissing[] = __('State');
            }
            if (empty($permAddress->district)) {
                $addressMissing[] = __('District');
            }
            if (empty($permAddress->address_line1)) {
                $addressMissing[] = __('Address Line 1');
            }
            if (empty($permAddress->pin_code)) {
                $addressMissing[] = __('PIN Code');
            }
        }
        $pendingAddr = $pendingRequests->firstWhere('section', 'address');
        $sections['address'] = [
            'title' => __('Addresses'),
            'label' => __('Addresses'),
            'is_complete' => empty($addressMissing),
            'missing' => $addressMissing,
            'has_pending' => $pendingAddr !== null,
            'has_pending_request' => $pendingAddr !== null,
            'pending_request_no' => $pendingAddr?->request_no,
        ];

        // 4. Qualifications
        $educationMissing = [];
        if ($educations->isEmpty()) {
            $educationMissing[] = __('At least one academic qualification record');
        }
        $pendingEdu = $pendingRequests->firstWhere('section', 'education');
        $sections['education'] = [
            'title' => __('Qualifications'),
            'label' => __('Qualifications'),
            'is_complete' => empty($educationMissing),
            'missing' => $educationMissing,
            'has_pending' => $pendingEdu !== null,
            'has_pending_request' => $pendingEdu !== null,
            'pending_request_no' => $pendingEdu?->request_no,
        ];

        // 5. Employment (Required if student is currently a working professional, optional otherwise)
        $isWorkingProfessional = (bool) ($profile?->is_working_professional);
        $pendingEmp = $pendingRequests->firstWhere('section', 'employment');

        if ($isWorkingProfessional) {
            $empMissing = [];
            if ($employments->isEmpty()) {
                $empMissing[] = __('At least one employment record is required for working professionals');
            }
            $sections['employment'] = [
                'title' => __('Employment History'),
                'label' => __('Employment History (Required)'),
                'is_complete' => empty($empMissing),
                'is_optional' => false,
                'is_working_professional' => true,
                'missing' => $empMissing,
                'has_pending' => $pendingEmp !== null,
                'has_pending_request' => $pendingEmp !== null,
                'pending_request_no' => $pendingEmp?->request_no,
            ];
        } else {
            $sections['employment'] = [
                'title' => __('Employment History'),
                'label' => __('Employment History (Optional)'),
                'is_complete' => true,
                'is_optional' => true,
                'is_working_professional' => false,
                'missing' => [],
                'has_pending' => $pendingEmp !== null,
                'has_pending_request' => $pendingEmp !== null,
                'pending_request_no' => $pendingEmp?->request_no,
            ];
        }

        // 6. UPSC Details
        $upscMissing = [];
        if (! $other) {
            $upscMissing[] = __('UPSC CSE summary record');
        } else {
            if (empty($other->optional_subject)) {
                $upscMissing[] = __('Optional Subject');
            }
            if (empty($other->year_of_appearing)) {
                $upscMissing[] = __('Target Exam Year');
            }
        }
        $pendingUpsc = $pendingRequests->firstWhere('section', 'upsc');
        $sections['upsc'] = [
            'title' => __('UPSC Details'),
            'label' => __('UPSC Details'),
            'is_complete' => empty($upscMissing),
            'missing' => $upscMissing,
            'has_pending' => $pendingUpsc !== null,
            'has_pending_request' => $pendingUpsc !== null,
            'pending_request_no' => $pendingUpsc?->request_no,
        ];

        // 7. Uploaded Documents
        $documentMissing = [];
        $hasPhoto = $documents->where('is_active', true)->where('type', 'Photo')->isNotEmpty();
        $hasSignature = $documents->where('is_active', true)->where('type', 'Signature')->isNotEmpty();
        $totalDocs = $documents->where('is_active', true)->count();

        if (! $hasPhoto && $totalDocs < 2) {
            $documentMissing[] = __('Candidate Photo');
        }
        if (! $hasSignature && $totalDocs < 2) {
            $documentMissing[] = __('Candidate Signature');
        }
        $pendingDoc = $pendingRequests->firstWhere('section', 'documents');
        $sections['documents'] = [
            'title' => __('Uploaded Documents'),
            'label' => __('Uploaded Documents'),
            'is_complete' => empty($documentMissing),
            'missing' => $documentMissing,
            'has_pending' => $pendingDoc !== null,
            'has_pending_request' => $pendingDoc !== null,
            'pending_request_no' => $pendingDoc?->request_no,
        ];

        $completedCount = 0;
        $incompleteSections = [];
        foreach ($sections as $key => $sec) {
            if ($sec['is_complete']) {
                $completedCount++;
            } else {
                $incompleteSections[] = $key;
            }
        }

        $totalCount = count($sections);
        $percentage = $totalCount > 0 ? (int) round(($completedCount / $totalCount) * 100) : 0;

        return [
            'is_complete' => $completedCount === $totalCount,
            'percentage' => $percentage,
            'completed_count' => $completedCount,
            'total_count' => $totalCount,
            'sections' => $sections,
            'incomplete_sections' => $incompleteSections,
        ];
    }

    public function libraryMember(): HasOne
    {
        return $this->hasOne(LibraryMember::class, 'user_id');
    }

    public function libraryIssues(): HasMany
    {
        return $this->hasMany(BookIssue::class, 'user_id');
    }

    public function libraryReservations(): HasMany
    {
        return $this->hasMany(BookReservation::class, 'user_id');
    }

    public function libraryFines(): HasMany
    {
        return $this->hasMany(BookFine::class, 'user_id');
    }

    public function hostelAllocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class, 'user_id');
    }

    public function activeHostelAllocation(): HasOne
    {
        return $this->hasOne(HostelAllocation::class, 'user_id')
            ->whereIn('status', ['active', 'extended'])
            ->latestOfMany();
    }

    public function hostelExtensionRequests(): HasMany
    {
        return $this->hasMany(HostelExtensionRequest::class, 'user_id');
    }
}
