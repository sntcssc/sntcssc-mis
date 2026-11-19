<?php

use App\Concerns\RenamesUploads;
use App\Enums\TeamRole;
use App\Helpers\CategoryData;
use App\Helpers\CourseData;
use App\Helpers\LocationData;
use App\Helpers\UpscData;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ProfileUpdateRequest;
use App\Models\Section;
use App\Models\StudentAddress;
use App\Models\StudentDisciplinaryAction;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\Team;
use App\Models\UpscAttempt;
use App\Models\User;
use App\Services\AdmissionRestrictionService;
use App\Services\StudentIdGeneratorService;
use App\Services\StudentImportExportService;
use App\Services\UserStudentSyncService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Student Management')] class extends Component {
    use RenamesUploads;
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'category')]
    public string $categoryFilter = '';

    #[Url(as: 'gender')]
    public string $genderFilter = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'section')]
    public string $sectionFilter = '';

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';
    public int $perPage = 10;

    // View Dossier state
    public ?int $viewingStudentId = null;
    public ?User $viewingStudent = null;
    public string $activeDossierTab = 'basic'; // basic, addresses, education, employment, other, documents, enrollments, requests

    // Create / Edit Student Form State
    public ?int $editingStudentId = null;
    public $avatarFile = null;
    public string $activeFormTab = 'basic'; // basic, address, education, other, documents, enrollment
    public array $form = [
        'name' => '',
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'whatsapp_no' => '',
        'dob' => '',
        'gender' => 'male',
        'student_id' => '', // Lifetime unique student ID (max 10 digits)
        'tenth_roll' => '',
        'id_type' => 'Aadhaar',
        'id_number' => '',
        'status' => 'active',
        // Profile Fields
        'father_name' => '',
        'mother_name' => '',
        'category' => 'UR',
        'distance' => '',
        'subcaste' => '',
        'creamy_layer' => '',
        'cert_no' => '',
        'issued_date' => '',
        'issued_by' => '',
        'is_pwbd' => false,
        'pwbd_type' => '',
        'pwd_percentage' => 0,
        'pwbd_cert_no' => '',
        'pwbd_issued_date' => '',
        'pwbd_issued_by' => '',
        'occupation' => '',
        'father_occupation' => '',
        'mother_occupation' => '',
        'family_income' => '',
        'highest_edu' => 'Graduation',
        'school_language' => 'English',
        'upsc_community' => '',
        // Address Fields
        'state' => 'West Bengal',
        'district' => '',
        'subdistrict' => '',
        'post_office' => '',
        'police_station' => '',
        'address_line1' => '',
        'address_line2' => '',
        'pin_code' => '',
        // Initial Enrollment Fields (Optional)
        'enroll_batch_id' => '',
        'enroll_course_id' => '',
        'enroll_section_id' => '',
        'enroll_roll_no' => '',
        'enroll_application_no' => '',
        'enroll_fee_status' => 'paid',
    ];

    // Modular Section Form States for Direct/Independent Edits
    public array $userForm = [
        'name' => '',
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'whatsapp_no' => '',
        'dob' => '',
        'gender' => 'male',
        'student_id' => '',
        'tenth_roll' => '',
        'id_type' => 'Aadhaar',
        'id_number' => '',
        'status' => 'active',
    ];

    public array $profileForm = [
        'father_name' => '',
        'mother_name' => '',
        'category' => 'UR',
        'distance' => '',
        'subcaste' => '',
        'creamy_layer' => '',
        'cert_no' => '',
        'issued_date' => '',
        'issued_by' => '',
        'is_pwbd' => false,
        'pwbd_type' => '',
        'pwd_percentage' => 0,
        'pwbd_cert_no' => '',
        'pwbd_issued_date' => '',
        'pwbd_issued_by' => '',
        'is_working_professional' => false,
        'occupation' => '',
        'father_occupation' => '',
        'mother_occupation' => '',
        'family_income' => '',
        'highest_edu' => 'Graduation',
        'school_language' => 'English',
        'upsc_community' => '',
        'activities' => '',
        'hobbies' => '',
    ];

    public array $addressForm = [
        'selected_type' => 'Permanent',
        'state' => 'West Bengal',
        'district' => '',
        'subdistrict' => '',
        'address_line1' => '',
        'address_line2' => '',
        'post_office' => '',
        'police_station' => '',
        'pin_code' => '',
    ];

    public array $educationForm = [
        'editing_id' => null,
        'level' => '10th',
        'board_university' => '',
        'institute' => '',
        'stream_subject' => '',
        'passing_year' => null,
        'total_marks' => null,
        'marks_obtained' => null,
        'percentage' => '',
        'cgpa' => '',
        'division' => '',
        'completion_status' => 'Completed',
        'is_highest' => false,
    ];

    public array $employmentForm = [
        'editing_id' => null,
        'employer_name' => '',
        'designation' => '',
        'nature_of_employment' => 'Full-time',
        'location' => '',
        'from_date' => '',
        'to_date' => '',
        'is_current' => false,
        'description' => '',
    ];

    public array $otherForm = [
        'attempts_already_given' => 0,
        'attempts_left' => null,
        'optional_subject' => '',
        'year_of_appearing' => null,
    ];

    public array $attemptForm = [
        'editing_id' => null,
        'exam_year' => '',
        'roll_number' => '',
        'prelims_cleared' => false,
        'mains_cleared' => false,
        'interview_cleared' => false,
    ];

    public array $documentForm = [
        'document_type' => 'Photo',
        'custom_document_type' => '',
        'file' => null,
        'file_url' => '',
        'description' => '',
    ];

    // Quick Enroll Modal State
    public ?int $quickEnrollStudentId = null;
    public ?User $quickEnrollStudent = null;
    public array $quickEnrollForm = [
        'batch_id' => '',
        'application_no' => '',
        'course_id' => '',
        'section_id' => '',
        'roll_no' => '',
        'fee_amount' => '0.00',
        'fee_status' => 'paid',
        'override_restrictions' => false,
    ];

    // Disciplinary Modal State
    public ?int $disciplineStudentId = null;
    public ?User $disciplineStudent = null;
    public array $disciplineForm = [
        'action_type' => 'temporary_suspension',
        'reason_category' => 'misconduct',
        'order_number' => '',
        'order_date' => '',
        'start_date' => '',
        'end_date' => '',
        'is_lifetime' => false,
        'course_id' => '',
        'findings' => '',
    ];
    public $disciplineOrderCopy = null;

    // Lift Sanction Modal State
    public ?int $liftSanctionStudentId = null;
    public ?User $liftSanctionStudent = null;
    public ?StudentDisciplinaryAction $activeSanctionToLift = null;
    public array $liftSanctionForm = [
        'revocation_reason' => '',
        'revocation_order_no' => '',
    ];

    // Revert / Reinstate Student Enrollment Modal State
    public ?int $reinstateEnrollmentId = null;
    public ?Enrollment $reinstateEnrollment = null;
    public array $reinstateForm = [
        'order_number' => '',
        'reason' => '',
        'effective_date' => '',
        'activate_user' => true,
    ];

    // Import State
    public $importFile = null;
    public ?array $importSummary = null;

    // Delete State
    public ?int $deleteStudentId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedGenderFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBatchFilter(): void
    {
        $this->sectionFilter = '';
        $this->resetPage();
    }

    public function updatedCourseFilter(): void
    {
        $this->sectionFilter = '';
        $this->resetPage();
    }

    public function updatedSectionFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total_students' => User::whereHas('roles', fn ($q) => $q->where('name', 'Student'))
                ->orWhereNotNull('student_id')
                ->orWhereHas('enrollments')
                ->count(),
            'active_students' => User::where('status', 'active')
                ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'Student'))->orWhereNotNull('student_id')->orWhereHas('enrollments'))
                ->count(),
            'total_enrolled' => Enrollment::where('status', 'active')->distinct('user_id')->count('user_id'),
            'lifetime_ids' => User::whereNotNull('student_id')->where('student_id', '!=', '')->count(),
            'pending_requests' => ProfileUpdateRequest::where('status', 'pending')->count(),
        ];
    }

    #[Computed]
    public function batches()
    {
        return Batch::where('status', '!=', 'archived')->orderBy('name')->get();
    }

    #[Computed]
    public function courses()
    {
        return Course::where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function sections()
    {
        return Section::query()
            ->when($this->batchFilter, fn ($q) => $q->where('batch_id', (int) $this->batchFilter))
            ->when($this->courseFilter, fn ($q) => $q->where('course_id', (int) $this->courseFilter))
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function quickEnrollSections()
    {
        if (! $this->quickEnrollForm['batch_id'] || ! $this->quickEnrollForm['course_id']) {
            return collect();
        }

        $batchId = (int) $this->quickEnrollForm['batch_id'];
        $courseId = (int) $this->quickEnrollForm['course_id'];

        return Section::where('batch_id', $batchId)
            ->where(function ($q) use ($courseId) {
                $q->whereNull('course_id')
                    ->orWhere('course_id', $courseId);
            })
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function students()
    {
        return User::query()
            ->with([
                'studentProfile',
                'studentAddresses',
                'activeEnrollments.course',
                'activeEnrollments.batch',
                'activeEnrollments.section',
                'profileUpdateRequests' => fn ($q) => $q->where('status', 'pending'),
                'studentDisciplinaryActions' => fn ($q) => $q->active(),
            ])
            ->where(function ($query) {
                $query->whereHas('roles', fn ($q) => $q->where('name', 'Student'))
                    ->orWhereNotNull('student_id')
                    ->orWhereHas('enrollments');
            })
            ->when($this->search, function (Builder $query) {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('student_id', 'like', $term)
                        ->orWhere('urn', 'like', $term)
                        ->orWhere('tenth_roll', 'like', $term)
                        ->orWhere('id_number', 'like', $term);
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->genderFilter, fn ($q) => $q->where('gender', $this->genderFilter))
            ->when($this->categoryFilter, function ($query) {
                $query->whereHas('studentProfile', fn ($q) => $q->where('category', $this->categoryFilter));
            })
            ->when($this->batchFilter, function ($query) {
                $query->whereHas('enrollments', fn ($q) => $q->where('batch_id', (int) $this->batchFilter));
            })
            ->when($this->courseFilter, function ($query) {
                $query->whereHas('enrollments', fn ($q) => $q->where('course_id', (int) $this->courseFilter));
            })
            ->when($this->sectionFilter, function ($query) {
                $query->whereHas('enrollments', fn ($q) => $q->where('section_id', (int) $this->sectionFilter));
            })
            ->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    #[Computed]
    public function currentEditingStudent(): ?User
    {
        if (! $this->editingStudentId) {
            return null;
        }

        return User::with([
            'studentProfile',
            'studentAddresses',
            'studentEducations' => fn ($q) => $q->orderBy('passing_year', 'desc'),
            'studentEmployments' => fn ($q) => $q->orderBy('from_date', 'desc'),
            'studentOtherDetails',
            'studentDocuments' => fn ($q) => $q->orderBy('created_at', 'desc'),
            'upscAttempts' => fn ($q) => $q->orderBy('exam_year', 'desc'),
        ])->find($this->editingStudentId);
    }

    public function updatedUserFormFirstName($value): void
    {
        $this->userForm['name'] = trim(($value ?? '').' '.($this->userForm['last_name'] ?? ''));
    }

    public function updatedUserFormLastName($value): void
    {
        $this->userForm['name'] = trim(($this->userForm['first_name'] ?? '').' '.($value ?? ''));
    }

    public function updatedFormFirstName($value): void
    {
        $this->form['name'] = trim(($value ?? '').' '.($this->form['last_name'] ?? ''));
    }

    public function updatedFormLastName($value): void
    {
        $this->form['name'] = trim(($this->form['first_name'] ?? '').' '.($value ?? ''));
    }

    public function updatedAddressFormState($value): void
    {
        $validDistricts = \App\Helpers\LocationData::getDistrictsByState($value);
        if (! empty($this->addressForm['district']) && ! in_array($this->addressForm['district'], $validDistricts)) {
            $this->addressForm['district'] = '';
        }
    }

    public function updatedFormCategory($value): void
    {
        $subcastes = \App\Helpers\CategoryData::getSubcastes($value ?? '');
        if (! empty($this->form['subcaste']) && ! in_array($this->form['subcaste'], $subcastes, true)) {
            $this->form['subcaste'] = '';
        }
    }

    public function updatedFormState($value): void
    {
        $validDistricts = \App\Helpers\LocationData::getDistrictsByState($value);
        if (! empty($this->form['district']) && ! in_array($this->form['district'], $validDistricts)) {
            $this->form['district'] = '';
        }
    }

    public function updatedEducationFormTotalMarks(): void
    {
        $this->calculateEducationPercentage();
    }

    public function updatedEducationFormMarksObtained(): void
    {
        $this->calculateEducationPercentage();
    }

    protected function calculateEducationPercentage(): void
    {
        $total = is_numeric($this->educationForm['total_marks'] ?? null) ? (float) $this->educationForm['total_marks'] : 0;
        $obtained = is_numeric($this->educationForm['marks_obtained'] ?? null) ? (float) $this->educationForm['marks_obtained'] : 0;

        if ($total > 0 && $obtained >= 0) {
            $this->educationForm['percentage'] = (string) round(($obtained / $total) * 100, 2);
        }
    }

    /**
     * Compare form values with model attributes and return only changed fields.
     */
    protected function getDirtyFields(\Illuminate\Database\Eloquent\Model $model, array $formValues, array $casts = []): array
    {
        $dirty = [];
        foreach ($formValues as $key => $newValue) {
            $oldValue = $model->getAttribute($key);

            if (is_string($newValue)) {
                $newValue = trim($newValue);
                if ($newValue === '') {
                    $newValue = null;
                }
            }

            $type = $casts[$key] ?? null;

            if ($type === 'bool' || $type === 'boolean') {
                $normNew = (bool) $newValue;
                $normOld = (bool) $oldValue;
                if ($normNew !== $normOld) {
                    $dirty[$key] = $normNew;
                }
            } elseif ($type === 'int' || $type === 'integer') {
                $normNew = $newValue !== null && $newValue !== '' ? (int) $newValue : null;
                $normOld = $oldValue !== null ? (int) $oldValue : null;
                if ($normNew !== $normOld) {
                    $dirty[$key] = $normNew;
                }
            } elseif ($type === 'float' || $type === 'decimal') {
                $normNew = $newValue !== null && $newValue !== '' ? round((float) $newValue, 2) : null;
                $normOld = $oldValue !== null ? round((float) $oldValue, 2) : null;
                if ($normNew !== $normOld) {
                    $dirty[$key] = $normNew;
                }
            } elseif ($type === 'date') {
                $formattedOld = $oldValue instanceof \Illuminate\Support\Carbon ? $oldValue->format('Y-m-d') : ($oldValue ? substr((string) $oldValue, 0, 10) : null);
                $formattedNew = $newValue ? substr((string) $newValue, 0, 10) : null;
                if ($formattedNew !== $formattedOld) {
                    $dirty[$key] = $formattedNew;
                }
            } else {
                $normOld = $oldValue === null ? '' : trim((string) $oldValue);
                $normNew = $newValue === null ? '' : trim((string) $newValue);
                if ($normOld !== $normNew) {
                    $dirty[$key] = $newValue;
                }
            }
        }

        return $dirty;
    }

    public function createStudent(): void
    {
        $this->create();
    }

    public function create(): void
    {
        $this->editingStudentId = null;
        $this->activeFormTab = 'basic';
        $this->form = [
            'name' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'whatsapp_no' => '',
            'dob' => '',
            'gender' => 'male',
            'student_id' => '',
            'tenth_roll' => '',
            'id_type' => 'Aadhaar',
            'id_number' => '',
            'status' => 'active',
            'father_name' => '',
            'mother_name' => '',
            'category' => 'UR',
            'distance' => '',
            'subcaste' => '',
            'creamy_layer' => '',
            'cert_no' => '',
            'issued_date' => '',
            'issued_by' => '',
            'is_pwbd' => false,
            'pwbd_type' => '',
            'pwd_percentage' => 0,
            'pwbd_cert_no' => '',
            'pwbd_issued_date' => '',
            'pwbd_issued_by' => '',
            'occupation' => '',
            'father_occupation' => '',
            'mother_occupation' => '',
            'family_income' => '',
            'highest_edu' => 'Graduation',
            'school_language' => 'English',
            'upsc_community' => '',
            'state' => 'West Bengal',
            'district' => '',
            'subdistrict' => '',
            'post_office' => '',
            'police_station' => '',
            'address_line1' => '',
            'address_line2' => '',
            'pin_code' => '',
            'enroll_batch_id' => '',
            'enroll_course_id' => '',
            'enroll_section_id' => '',
            'enroll_roll_no' => '',
        'enroll_application_no' => '',
            'enroll_fee_status' => 'paid',
        ];
        $this->dispatch('modal-open', name: 'student-form-modal');
    }

    public function edit(int $id, string $tab = 'basic'): void
    {
        $student = User::with([
            'studentProfile',
            'studentAddresses',
            'studentEducations',
            'studentEmployments',
            'studentOtherDetails',
            'studentDocuments',
            'upscAttempts',
        ])->findOrFail($id);

        $this->editingStudentId = $student->id;
        $this->avatarFile = null;
        $this->activeFormTab = in_array($tab, ['basic', 'profile', 'address', 'education', 'employment', 'other', 'documents'], true) ? $tab : 'basic';

        // Populate User / Account Form
        $this->userForm = [
            'name' => $student->name ?? '',
            'first_name' => $student->first_name ?? '',
            'last_name' => $student->last_name ?? '',
            'email' => $student->email ?? '',
            'phone' => $student->phone ?? '',
            'whatsapp_no' => $student->whatsapp_no ?? '',
            'dob' => $student->dob ? $student->dob->format('Y-m-d') : '',
            'gender' => $student->gender ?? 'male',
            'student_id' => $student->student_id ?? '',
            'tenth_roll' => $student->tenth_roll ?? '',
            'id_type' => $student->id_type ?? 'Aadhaar',
            'id_number' => $student->id_number ?? '',
            'status' => $student->status ?? 'active',
        ];

        // Populate Profile Dossier Form
        $profile = $student->studentProfile;
        $this->profileForm = [
            'father_name' => $profile?->father_name ?? '',
            'mother_name' => $profile?->mother_name ?? '',
            'category' => $profile?->category ?? 'UR',
            'distance' => $profile?->distance !== null ? (string) $profile->distance : '',
            'subcaste' => $profile?->subcaste ?? '',
            'creamy_layer' => $profile?->creamy_layer ?? '',
            'cert_no' => $profile?->cert_no ?? '',
            'issued_date' => $profile?->issued_date ? $profile->issued_date->format('Y-m-d') : '',
            'issued_by' => $profile?->issued_by ?? '',
            'is_pwbd' => (bool) ($profile?->is_pwbd ?? false),
            'pwbd_type' => $profile?->pwbd_type ?? '',
            'pwd_percentage' => (int) ($profile?->pwd_percentage ?? 0),
            'pwbd_cert_no' => $profile?->pwbd_cert_no ?? '',
            'pwbd_issued_date' => $profile?->pwbd_issued_date ? $profile->pwbd_issued_date->format('Y-m-d') : '',
            'pwbd_issued_by' => $profile?->pwbd_issued_by ?? '',
            'is_working_professional' => (bool) ($profile?->is_working_professional ?? false),
            'occupation' => $profile?->occupation ?? '',
            'father_occupation' => $profile?->father_occupation ?? '',
            'mother_occupation' => $profile?->mother_occupation ?? '',
            'family_income' => $profile?->family_income !== null ? (string) $profile->family_income : '',
            'highest_edu' => $profile?->highest_edu ?? 'Graduation',
            'school_language' => $profile?->school_language ?? 'English',
            'upsc_community' => $profile?->upsc_community ?? '',
            'activities' => $profile?->activities ?? '',
            'hobbies' => $profile?->hobbies ?? '',
        ];

        // Populate Address Form (defaults to Permanent)
        $this->loadAddressForm('Permanent');

        // Reset Education, Employment, Attempt & Document Forms
        $this->resetEducationForm();
        $this->resetEmploymentForm();
        $this->resetAttemptForm();
        $this->resetDocumentForm();

        // Populate Other UPSC details
        $other = $student->studentOtherDetails;
        $this->otherForm = [
            'attempts_already_given' => (int) ($other?->attempts_already_given ?? 0),
            'attempts_left' => $other?->attempts_left !== null ? (int) $other->attempts_left : null,
            'optional_subject' => $other?->optional_subject ?? '',
            'year_of_appearing' => $other?->year_of_appearing !== null ? (int) $other->year_of_appearing : null,
        ];

        // Also populate backward-compatible $this->form
        $permAddress = $student->studentAddresses->firstWhere('address_type', 'Permanent');
        $this->form = array_merge($this->form, $this->userForm, [
            'father_name' => $this->profileForm['father_name'],
            'mother_name' => $this->profileForm['mother_name'],
            'category' => $this->profileForm['category'],
            'distance' => $this->profileForm['distance'],
            'subcaste' => $this->profileForm['subcaste'],
            'creamy_layer' => $this->profileForm['creamy_layer'],
            'cert_no' => $this->profileForm['cert_no'],
            'issued_date' => $this->profileForm['issued_date'],
            'issued_by' => $this->profileForm['issued_by'],
            'is_pwbd' => $this->profileForm['is_pwbd'],
            'pwbd_type' => $this->profileForm['pwbd_type'],
            'pwd_percentage' => $this->profileForm['pwd_percentage'],
            'pwbd_cert_no' => $this->profileForm['pwbd_cert_no'],
            'pwbd_issued_date' => $this->profileForm['pwbd_issued_date'],
            'pwbd_issued_by' => $this->profileForm['pwbd_issued_by'],
            'occupation' => $this->profileForm['occupation'],
            'father_occupation' => $this->profileForm['father_occupation'],
            'mother_occupation' => $this->profileForm['mother_occupation'],
            'family_income' => $this->profileForm['family_income'],
            'highest_edu' => $this->profileForm['highest_edu'],
            'school_language' => $this->profileForm['school_language'],
            'upsc_community' => $this->profileForm['upsc_community'],
            'state' => $permAddress?->state ?? 'West Bengal',
            'district' => $permAddress?->district ?? '',
            'subdistrict' => $permAddress?->subdistrict ?? '',
            'post_office' => $permAddress?->post_office ?? '',
            'police_station' => $permAddress?->police_station ?? '',
            'address_line1' => $permAddress?->address_line1 ?? '',
            'address_line2' => $permAddress?->address_line2 ?? '',
            'pin_code' => $permAddress?->pin_code ?? '',
        ]);

        $this->dispatch('modal-close', name: 'student-dossier-modal');
        $this->dispatch('modal-open', name: 'student-form-modal');
    }

    public function loadAddressForm(string $type): void
    {
        $this->addressForm['selected_type'] = $type;
        $addr = $this->editingStudentId
            ? StudentAddress::where('user_id', $this->editingStudentId)->where('address_type', $type)->first()
            : null;

        $this->addressForm['state'] = $addr?->state ?? 'West Bengal';
        $this->addressForm['district'] = $addr?->district ?? '';
        $this->addressForm['subdistrict'] = $addr?->subdistrict ?? '';
        $this->addressForm['address_line1'] = $addr?->address_line1 ?? '';
        $this->addressForm['address_line2'] = $addr?->address_line2 ?? '';
        $this->addressForm['post_office'] = $addr?->post_office ?? '';
        $this->addressForm['police_station'] = $addr?->police_station ?? '';
        $this->addressForm['pin_code'] = $addr?->pin_code ?? '';
    }

    public function switchAddressType(string $type): void
    {
        $this->loadAddressForm($type);
    }

    public function copyPermanentAddress(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $perm = StudentAddress::where('user_id', $this->editingStudentId)->where('address_type', 'Permanent')->first();
        if ($perm) {
            $this->addressForm['state'] = $perm->state;
            $this->addressForm['district'] = $perm->district;
            $this->addressForm['subdistrict'] = $perm->subdistrict;
            $this->addressForm['address_line1'] = $perm->address_line1;
            $this->addressForm['address_line2'] = $perm->address_line2;
            $this->addressForm['post_office'] = $perm->post_office;
            $this->addressForm['police_station'] = $perm->police_station;
            $this->addressForm['pin_code'] = $perm->pin_code;
            Toast::dispatch($this, 'info', __('Copied Permanent Address values. Click "Save Address" to apply.'));
        } else {
            Toast::dispatch($this, 'warning', __('No Permanent Address found to copy.'));
        }
    }

    public function removeStudentAvatar(): void
    {
        if ($this->editingStudentId) {
            $student = User::findOrFail($this->editingStudentId);
            if ($student->avatar && \Illuminate\Support\Facades\Storage::disk('public')->exists($student->avatar)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($student->avatar);
            }
            $student->avatar = null;
            $student->save();
            $this->avatarFile = null;
            Toast::dispatch($this, 'info', __('Student profile photo removed.'));
            $this->dispatch('modal-close', name: 'student-avatar-remove-modal');
        } else {
            $this->avatarFile = null;
        }
    }

    public function updateBasicInfo(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $student = User::findOrFail($this->editingStudentId);

        $this->validate([
            'userForm.name' => 'required|string|max:150',
            'userForm.email' => 'required|email|max:150|unique:users,email,'.$student->id,
            'userForm.phone' => 'nullable|string|max:25',
            'userForm.whatsapp_no' => 'nullable|string|max:25',
            'userForm.dob' => 'nullable|date',
            'userForm.gender' => 'required|in:male,female,others,other',
            'userForm.student_id' => 'nullable|string|max:10|unique:users,student_id,'.$student->id,
            'userForm.tenth_roll' => 'nullable|string|max:50',
            'userForm.id_type' => 'nullable|string|max:50',
            'userForm.id_number' => 'nullable|string|max:100',
            'userForm.status' => 'required|in:active,inactive,suspended,invited,pending',
        ]);

        $firstName = $this->userForm['first_name'] ?: explode(' ', trim($this->userForm['name']))[0];
        $lastName = $this->userForm['last_name'] ?: (explode(' ', trim($this->userForm['name']), 2)[1] ?? '');
        $fullName = trim($firstName.' '.$lastName) ?: $this->userForm['name'];

        $valuesToCompare = [
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName ?: null,
            'email' => strtolower($this->userForm['email']),
            'phone' => $this->userForm['phone'] ?: null,
            'whatsapp_no' => $this->userForm['whatsapp_no'] ?: null,
            'dob' => $this->userForm['dob'] ?: null,
            'gender' => $this->userForm['gender'] === 'other' ? 'others' : $this->userForm['gender'],
            'student_id' => $this->userForm['student_id'] ? substr(trim($this->userForm['student_id']), 0, 10) : null,
            'tenth_roll' => $this->userForm['tenth_roll'] ?: null,
            'id_type' => $this->userForm['id_type'] ?: null,
            'id_number' => $this->userForm['id_number'] ?: null,
            'status' => $this->userForm['status'],
        ];

        $dirty = $this->getDirtyFields($student, $valuesToCompare, [
            'dob' => 'date',
        ]);

        if (empty($dirty) && ! $this->avatarFile) {
            Toast::dispatch($this, 'info', __('No changes detected in Account details.'));
            return;
        }

        DB::beginTransaction();
        try {
            if (! empty($dirty)) {
                $student->fill($dirty);
                $student->save();
            }

            $avatarUpdated = false;
            if ($this->avatarFile) {
                $this->validate(['avatarFile' => 'image|mimes:png,jpg,jpeg,webp|max:5120']);
                if ($student->avatar && \Illuminate\Support\Facades\Storage::disk('public')->exists($student->avatar)) {
                    \Illuminate\Support\Facades\Storage::disk('public')->delete($student->avatar);
                }
                $avatarName = $this->generateFileName('avatar', $this->avatarFile, $student->id);
                $avatarPath = $this->avatarFile->storeAs('avatars', $avatarName, 'public');
                $student->avatar = $avatarPath;
                $student->save();
                $this->avatarFile = null;
                $avatarUpdated = true;
            }
            DB::commit();

            unset($this->currentEditingStudent);

            if (! empty($dirty) && $avatarUpdated) {
                Toast::dispatch($this, 'success', __('Updated Student photo and :count field(s) in Account (:fields).', [
                    'count' => count($dirty),
                    'fields' => implode(', ', array_keys($dirty)),
                ]));
            } elseif ($avatarUpdated) {
                Toast::dispatch($this, 'success', __('Student profile photo updated successfully.'));
            } else {
                Toast::dispatch($this, 'success', __('Updated :count field(s) in Student Account (:fields).', [
                    'count' => count($dirty),
                    'fields' => implode(', ', array_keys($dirty)),
                ]));
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to update Account details: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function updateProfileDetails(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $this->validate([
            'profileForm.father_name' => 'nullable|string|max:150',
            'profileForm.mother_name' => 'nullable|string|max:150',
            'profileForm.category' => 'nullable|string|max:30',
            'profileForm.distance' => 'nullable|numeric|min:0|max:9999.99',
            'profileForm.subcaste' => 'nullable|string|max:150',
            'profileForm.creamy_layer' => 'nullable|string|in:CL,NCL',
            'profileForm.cert_no' => 'nullable|string|max:100',
            'profileForm.issued_date' => 'nullable|date',
            'profileForm.issued_by' => 'nullable|string|max:150',
            'profileForm.is_pwbd' => 'boolean',
            'profileForm.pwbd_type' => 'nullable|string|max:150',
            'profileForm.pwd_percentage' => 'nullable|integer|min:0|max:100',
            'profileForm.pwbd_cert_no' => 'nullable|string|max:100',
            'profileForm.pwbd_issued_date' => 'nullable|date',
            'profileForm.pwbd_issued_by' => 'nullable|string|max:150',
            'profileForm.is_working_professional' => 'boolean',
            'profileForm.occupation' => 'nullable|string|max:150',
            'profileForm.father_occupation' => 'nullable|string|max:150',
            'profileForm.mother_occupation' => 'nullable|string|max:150',
            'profileForm.family_income' => 'nullable|numeric|min:0',
            'profileForm.highest_edu' => 'nullable|string|max:150',
            'profileForm.school_language' => 'nullable|string|max:100',
            'profileForm.upsc_community' => 'nullable|string|max:150',
            'profileForm.activities' => 'nullable|string',
            'profileForm.hobbies' => 'nullable|string',
        ]);

        $profile = StudentProfile::firstOrNew(['user_id' => $this->editingStudentId]);

        $valuesToCompare = [
            'father_name' => $this->profileForm['father_name'] ?: null,
            'mother_name' => $this->profileForm['mother_name'] ?: null,
            'category' => $this->profileForm['category'] ?: null,
            'distance' => $this->profileForm['distance'] !== '' && $this->profileForm['distance'] !== null ? (float) $this->profileForm['distance'] : null,
            'subcaste' => $this->profileForm['subcaste'] ?: null,
            'creamy_layer' => $this->profileForm['creamy_layer'] ?: null,
            'cert_no' => $this->profileForm['cert_no'] ?: null,
            'issued_date' => $this->profileForm['issued_date'] ?: null,
            'issued_by' => $this->profileForm['issued_by'] ?: null,
            'is_pwbd' => (bool) ($this->profileForm['is_pwbd'] ?? $this->profileForm['is_pwd'] ?? false),
            'pwbd_type' => ($this->profileForm['pwbd_type'] ?? $this->profileForm['pwd_type'] ?? '') ?: null,
            'pwd_percentage' => (int) ($this->profileForm['pwd_percentage'] ?? 0),
            'pwbd_cert_no' => $this->profileForm['pwbd_cert_no'] ?: null,
            'pwbd_issued_date' => $this->profileForm['pwbd_issued_date'] ?: null,
            'pwbd_issued_by' => $this->profileForm['pwbd_issued_by'] ?: null,
            'is_working_professional' => (bool) ($this->profileForm['is_working_professional'] ?? false),
            'occupation' => $this->profileForm['occupation'] ?: null,
            'father_occupation' => $this->profileForm['father_occupation'] ?: null,
            'mother_occupation' => $this->profileForm['mother_occupation'] ?: null,
            'family_income' => $this->profileForm['family_income'] !== '' && $this->profileForm['family_income'] !== null ? (float) $this->profileForm['family_income'] : null,
            'highest_edu' => $this->profileForm['highest_edu'] ?: null,
            'school_language' => $this->profileForm['school_language'] ?: null,
            'upsc_community' => $this->profileForm['upsc_community'] ?: null,
            'activities' => $this->profileForm['activities'] ?: null,
            'hobbies' => $this->profileForm['hobbies'] ?: null,
        ];

        if (! $profile->exists) {
            $dataToSave = array_filter($valuesToCompare, fn ($v) => $v !== null && $v !== '');
            DB::beginTransaction();
            try {
                $profile->fill($dataToSave);
                $profile->save();
                DB::commit();
                unset($this->currentEditingStudent);
                Toast::dispatch($this, 'success', __('Saved new Student Profile Dossier.'));
            } catch (\Throwable $e) {
                DB::rollBack();
                Toast::dispatch($this, 'error', __('Failed to save profile: :msg', ['msg' => $e->getMessage()]));
            }
            return;
        }

        $dirty = $this->getDirtyFields($profile, $valuesToCompare, [
            'is_pwbd' => 'bool',
            'pwd_percentage' => 'int',
            'is_working_professional' => 'bool',
            'family_income' => 'float',
            'distance' => 'float',
            'issued_date' => 'date',
            'pwbd_issued_date' => 'date',
        ]);

        if (empty($dirty)) {
            Toast::dispatch($this, 'info', __('No changes detected in Student Profile.'));
            return;
        }

        DB::beginTransaction();
        try {
            $profile->fill($dirty);
            $profile->save();
            DB::commit();
            unset($this->currentEditingStudent);

            Toast::dispatch($this, 'success', __('Updated :count field(s) in Student Profile (:fields).', [
                'count' => count($dirty),
                'fields' => implode(', ', array_keys($dirty)),
            ]));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to update Profile: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function updateAddressDetails(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $type = $this->addressForm['selected_type'] ?? 'Permanent';

        $this->validate([
            'addressForm.state' => 'nullable|string|max:100',
            'addressForm.district' => 'nullable|string|max:100',
            'addressForm.subdistrict' => 'nullable|string|max:100',
            'addressForm.address_line1' => 'nullable|string',
            'addressForm.address_line2' => 'nullable|string',
            'addressForm.post_office' => 'nullable|string|max:100',
            'addressForm.police_station' => 'nullable|string|max:100',
            'addressForm.pin_code' => 'nullable|string|max:15',
        ]);

        $addr = StudentAddress::firstOrNew([
            'user_id' => $this->editingStudentId,
            'address_type' => $type,
        ]);

        $valuesToCompare = [
            'state' => $this->addressForm['state'] ?: null,
            'district' => $this->addressForm['district'] ?: null,
            'subdistrict' => $this->addressForm['subdistrict'] ?: null,
            'address_line1' => $this->addressForm['address_line1'] ?: null,
            'address_line2' => $this->addressForm['address_line2'] ?: null,
            'post_office' => $this->addressForm['post_office'] ?: null,
            'police_station' => $this->addressForm['police_station'] ?: null,
            'pin_code' => $this->addressForm['pin_code'] ?: null,
        ];

        if (! $addr->exists) {
            $dataToSave = array_filter($valuesToCompare, fn ($v) => $v !== null && $v !== '');
            DB::beginTransaction();
            try {
                $addr->fill($dataToSave);
                $addr->save();
                DB::commit();
                Toast::dispatch($this, 'success', __('Saved :type address.', ['type' => $type]));
            } catch (\Throwable $e) {
                DB::rollBack();
                Toast::dispatch($this, 'error', __('Failed to save address: :msg', ['msg' => $e->getMessage()]));
            }
            return;
        }

        $dirty = $this->getDirtyFields($addr, $valuesToCompare);

        if (empty($dirty)) {
            Toast::dispatch($this, 'info', __(':type address has no changes.', ['type' => $type]));
            return;
        }

        DB::beginTransaction();
        try {
            $addr->fill($dirty);
            $addr->save();
            DB::commit();

            Toast::dispatch($this, 'success', __('Successfully updated :type address (:fields).', [
                'type' => $type,
                'fields' => implode(', ', array_keys($dirty)),
            ]));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to update :type address: :msg', ['type' => $type, 'msg' => $e->getMessage()]));
        }
    }

    public function resetEducationForm(): void
    {
        $this->educationForm = [
            'editing_id' => null,
            'level' => 'Graduation',
            'board_university' => '',
            'institute' => '',
            'stream_subject' => '',
            'passing_year' => null,
            'total_marks' => null,
            'marks_obtained' => null,
            'percentage' => '',
            'cgpa' => '',
            'division' => '',
            'completion_status' => 'Completed',
            'is_highest' => false,
        ];
    }

    public function editEducation(int $id): void
    {
        $edu = StudentEducation::where('user_id', $this->editingStudentId)->findOrFail($id);
        $this->educationForm = [
            'editing_id' => $edu->id,
            'level' => $edu->level,
            'board_university' => $edu->board_university ?? '',
            'institute' => $edu->institute ?? '',
            'stream_subject' => $edu->stream_subject ?? '',
            'passing_year' => $edu->passing_year,
            'total_marks' => $edu->total_marks,
            'marks_obtained' => $edu->marks_obtained,
            'percentage' => $edu->percentage !== null ? (string) $edu->percentage : '',
            'cgpa' => $edu->cgpa !== null ? (string) $edu->cgpa : '',
            'division' => $edu->division ?? '',
            'completion_status' => $edu->completion_status ?? 'Completed',
            'is_highest' => (bool) $edu->is_highest,
        ];
    }

    public function saveEducation(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $this->validate([
            'educationForm.level' => 'required|string|max:100',
            'educationForm.board_university' => 'nullable|string|max:200',
            'educationForm.institute' => 'nullable|string|max:200',
            'educationForm.stream_subject' => 'nullable|string|max:200',
            'educationForm.passing_year' => 'nullable|integer|min:1950|max:'.(date('Y') + 5),
            'educationForm.total_marks' => 'nullable|integer|min:0',
            'educationForm.marks_obtained' => 'nullable|integer|min:0',
            'educationForm.percentage' => 'nullable|numeric|min:0|max:100',
            'educationForm.cgpa' => 'nullable|numeric|min:0|max:10',
            'educationForm.division' => 'nullable|string|max:50',
            'educationForm.completion_status' => 'nullable|string|max:50',
            'educationForm.is_highest' => 'boolean',
        ]);

        $totalMarks = $this->educationForm['total_marks'] !== null && $this->educationForm['total_marks'] !== '' ? (int) $this->educationForm['total_marks'] : null;
        $marksObtained = $this->educationForm['marks_obtained'] !== null && $this->educationForm['marks_obtained'] !== '' ? (int) $this->educationForm['marks_obtained'] : null;

        $calcPercentage = null;
        if ($totalMarks && $totalMarks > 0 && $marksObtained !== null) {
            $calcPercentage = round(($marksObtained / $totalMarks) * 100, 2);
        } elseif ($this->educationForm['percentage'] !== null && $this->educationForm['percentage'] !== '') {
            $calcPercentage = (float) $this->educationForm['percentage'];
        }

        $valuesToCompare = [
            'level' => $this->educationForm['level'],
            'board_university' => $this->educationForm['board_university'] ?: null,
            'institute' => $this->educationForm['institute'] ?: null,
            'stream_subject' => $this->educationForm['stream_subject'] ?: null,
            'passing_year' => $this->educationForm['passing_year'] ? (int) $this->educationForm['passing_year'] : null,
            'total_marks' => $totalMarks,
            'marks_obtained' => $marksObtained,
            'percentage' => $calcPercentage,
            'cgpa' => $this->educationForm['cgpa'] !== null && $this->educationForm['cgpa'] !== '' ? (float) $this->educationForm['cgpa'] : null,
            'division' => $this->educationForm['division'] ?: null,
            'completion_status' => $this->educationForm['completion_status'] ?: 'Completed',
            'is_highest' => (bool) $this->educationForm['is_highest'],
        ];

        DB::beginTransaction();
        try {
            if ($this->educationForm['editing_id']) {
                $edu = StudentEducation::where('user_id', $this->editingStudentId)->findOrFail($this->educationForm['editing_id']);
                $dirty = $this->getDirtyFields($edu, $valuesToCompare, [
                    'passing_year' => 'int',
                    'total_marks' => 'int',
                    'marks_obtained' => 'int',
                    'percentage' => 'float',
                    'cgpa' => 'float',
                    'is_highest' => 'bool',
                ]);

                if (empty($dirty)) {
                    Toast::dispatch($this, 'info', __('No changes detected in this qualification record.'));
                    DB::rollBack();
                    return;
                }

                $edu->fill($dirty);
                $edu->save();
                Toast::dispatch($this, 'success', __('Updated qualification: :level (:fields)', [
                    'level' => $edu->level,
                    'fields' => implode(', ', array_keys($dirty)),
                ]));
            } else {
                StudentEducation::create(array_merge($valuesToCompare, ['user_id' => $this->editingStudentId]));
                Toast::dispatch($this, 'success', __('Added new qualification: :level', ['level' => $valuesToCompare['level']]));
            }

            DB::commit();
            $this->resetEducationForm();
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save qualification: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function deleteEducation(int $id): void
    {
        DB::beginTransaction();
        try {
            $edu = StudentEducation::where('user_id', $this->editingStudentId)->findOrFail($id);
            $level = $edu->level;
            $edu->delete();
            DB::commit();

            Toast::dispatch($this, 'success', __('Deleted qualification: :level', ['level' => $level]));
            if ($this->educationForm['editing_id'] === $id) {
                $this->resetEducationForm();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to delete qualification: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function resetEmploymentForm(): void
    {
        $this->employmentForm = [
            'editing_id' => null,
            'employer_name' => '',
            'designation' => '',
            'nature_of_employment' => 'Full-time',
            'location' => '',
            'from_date' => '',
            'to_date' => '',
            'is_current' => false,
            'description' => '',
        ];
    }

    public function editEmployment(int $id): void
    {
        $emp = StudentEmployment::where('user_id', $this->editingStudentId)->findOrFail($id);
        $this->employmentForm = [
            'editing_id' => $emp->id,
            'employer_name' => $emp->employer_name ?? '',
            'designation' => $emp->designation ?? '',
            'nature_of_employment' => $emp->nature_of_employment ?? 'Full-time',
            'location' => $emp->location ?? '',
            'from_date' => $emp->from_date ? $emp->from_date->format('Y-m-d') : '',
            'to_date' => $emp->to_date ? $emp->to_date->format('Y-m-d') : '',
            'is_current' => (bool) $emp->is_current,
            'description' => $emp->description ?? '',
        ];
    }

    public function saveEmployment(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $isWorkingProfessional = (bool) ($this->currentEditingStudent?->studentProfile?->is_working_professional ?? ($this->profileForm['is_working_professional'] ?? false));

        $this->validate([
            'employmentForm.employer_name' => 'required|string|max:200',
            'employmentForm.designation' => $isWorkingProfessional ? 'required|string|max:150' : 'nullable|string|max:150',
            'employmentForm.nature_of_employment' => $isWorkingProfessional ? 'required|string|max:100' : 'nullable|string|max:100',
            'employmentForm.location' => 'nullable|string|max:150',
            'employmentForm.from_date' => $isWorkingProfessional ? 'required|date' : 'nullable|date',
            'employmentForm.to_date' => 'nullable|date',
            'employmentForm.is_current' => 'boolean',
            'employmentForm.description' => 'nullable|string',
        ]);

        $valuesToCompare = [
            'employer_name' => $this->employmentForm['employer_name'],
            'designation' => $this->employmentForm['designation'] ?: null,
            'nature_of_employment' => $this->employmentForm['nature_of_employment'] ?: null,
            'location' => $this->employmentForm['location'] ?: null,
            'from_date' => $this->employmentForm['from_date'] ?: null,
            'to_date' => empty($this->employmentForm['is_current']) ? ($this->employmentForm['to_date'] ?: null) : null,
            'is_current' => (bool) $this->employmentForm['is_current'],
            'description' => $this->employmentForm['description'] ?: null,
        ];

        DB::beginTransaction();
        try {
            if ($this->employmentForm['editing_id']) {
                $emp = StudentEmployment::where('user_id', $this->editingStudentId)->findOrFail($this->employmentForm['editing_id']);
                $dirty = $this->getDirtyFields($emp, $valuesToCompare, [
                    'from_date' => 'date',
                    'to_date' => 'date',
                    'is_current' => 'bool',
                ]);

                if (empty($dirty)) {
                    Toast::dispatch($this, 'info', __('No changes detected in employment record.'));
                    DB::rollBack();
                    return;
                }

                $emp->fill($dirty);
                $emp->save();
                Toast::dispatch($this, 'success', __('Updated employment: :emp (:fields)', [
                    'emp' => $emp->employer_name,
                    'fields' => implode(', ', array_keys($dirty)),
                ]));
            } else {
                StudentEmployment::create(array_merge($valuesToCompare, ['user_id' => $this->editingStudentId]));
                Toast::dispatch($this, 'success', __('Added employment record: :emp', ['emp' => $valuesToCompare['employer_name']]));
            }

            DB::commit();
            unset($this->currentEditingStudent);
            $this->resetEmploymentForm();
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save employment: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function setStudentWorkingProfessionalStatus(bool $status): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        DB::beginTransaction();
        try {
            $profile = StudentProfile::firstOrCreate(['user_id' => $this->editingStudentId]);
            $oldStatus = (bool) ($profile->is_working_professional ?? false);
            $profile->is_working_professional = $status;
            $profile->save();

            $this->profileForm['is_working_professional'] = $status;

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'updated_student_working_professional_status',
                'auditable_type' => StudentProfile::class,
                'auditable_id' => $profile->id,
                'old_values' => ['is_working_professional' => $oldStatus],
                'new_values' => ['is_working_professional' => $status],
            ]);

            DB::commit();
            unset($this->currentEditingStudent);
            Toast::dispatch($this, 'success', $status
                ? __('Student marked as currently a Working Professional. Employment is now required.')
                : __('Student marked as Full-time Student / Aspirant. Employment is now optional.'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to update status: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function deleteEmployment(int $id): void
    {
        DB::beginTransaction();
        try {
            $emp = StudentEmployment::where('user_id', $this->editingStudentId)->findOrFail($id);
            $name = $emp->employer_name;
            $emp->delete();
            DB::commit();
            unset($this->currentEditingStudent);

            Toast::dispatch($this, 'success', __('Deleted employment: :emp', ['emp' => $name]));
            if ($this->employmentForm['editing_id'] === $id) {
                $this->resetEmploymentForm();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to delete employment: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function updateOtherDetails(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $this->validate([
            'otherForm.attempts_already_given' => 'required|integer|min:0|max:20',
            'otherForm.attempts_left' => 'nullable|integer|min:0|max:20',
            'otherForm.optional_subject' => 'nullable|string|max:150',
            'otherForm.year_of_appearing' => 'nullable|integer|min:2000|max:2040',
        ]);

        $other = StudentOtherDetail::firstOrNew(['user_id' => $this->editingStudentId]);

        $valuesToCompare = [
            'attempts_already_given' => (int) $this->otherForm['attempts_already_given'],
            'attempts_left' => $this->otherForm['attempts_left'] !== null && $this->otherForm['attempts_left'] !== '' ? (int) $this->otherForm['attempts_left'] : null,
            'optional_subject' => $this->otherForm['optional_subject'] ?: null,
            'year_of_appearing' => $this->otherForm['year_of_appearing'] !== null && $this->otherForm['year_of_appearing'] !== '' ? (int) $this->otherForm['year_of_appearing'] : null,
        ];

        if (! $other->exists) {
            $dataToSave = array_filter($valuesToCompare, fn ($v) => $v !== null && $v !== '');
            DB::beginTransaction();
            try {
                $other->fill($dataToSave);
                $other->save();
                DB::commit();

                $attemptsCount = UpscAttempt::where('user_id', $this->editingStudentId)->count();
                if ($valuesToCompare['attempts_already_given'] > 0 && $attemptsCount === 0) {
                    Toast::dispatch($this, 'warning', __('Please record the candidate\'s :count previous UPSC attempt(s) in the table below.', ['count' => $valuesToCompare['attempts_already_given']]));
                } else {
                    Toast::dispatch($this, 'success', __('Saved UPSC Examination details.'));
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                Toast::dispatch($this, 'error', __('Failed to save UPSC details: :msg', ['msg' => $e->getMessage()]));
            }
            return;
        }

        $dirty = $this->getDirtyFields($other, $valuesToCompare, [
            'attempts_already_given' => 'int',
            'attempts_left' => 'int',
            'year_of_appearing' => 'int',
        ]);

        if (empty($dirty)) {
            $attemptsCount = UpscAttempt::where('user_id', $this->editingStudentId)->count();
            if ($valuesToCompare['attempts_already_given'] > 0 && $attemptsCount === 0) {
                Toast::dispatch($this, 'warning', __('Candidate has :count UPSC attempt(s) specified. Please add corresponding attempt records below.', ['count' => $valuesToCompare['attempts_already_given']]));
            } else {
                Toast::dispatch($this, 'info', __('No changes detected in UPSC Examination details.'));
            }
            return;
        }

        DB::beginTransaction();
        try {
            $other->fill($dirty);
            $other->save();
            DB::commit();

            Toast::dispatch($this, 'success', __('Updated :count field(s) in UPSC Examination details (:fields).', [
                'count' => count($dirty),
                'fields' => implode(', ', array_keys($dirty)),
            ]));

            $attemptsCount = UpscAttempt::where('user_id', $this->editingStudentId)->count();
            if ($valuesToCompare['attempts_already_given'] > 0 && $attemptsCount === 0) {
                Toast::dispatch($this, 'warning', __('Please ensure all :count previous UPSC attempt records are added below.', ['count' => $valuesToCompare['attempts_already_given']]));
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to update UPSC details: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function resetAttemptForm(): void
    {
        $this->attemptForm = [
            'editing_id' => null,
            'exam_year' => (int) date('Y'),
            'roll_number' => '',
            'prelims_cleared' => false,
            'mains_cleared' => false,
            'interview_cleared' => false,
        ];
    }

    public function editAttempt(int $id): void
    {
        $attempt = UpscAttempt::where('user_id', $this->editingStudentId)->findOrFail($id);
        $this->attemptForm = [
            'editing_id' => $attempt->id,
            'exam_year' => $attempt->exam_year,
            'roll_number' => $attempt->roll_number ?? '',
            'prelims_cleared' => (bool) $attempt->prelims_cleared,
            'mains_cleared' => (bool) $attempt->mains_cleared,
            'interview_cleared' => (bool) $attempt->interview_cleared,
        ];
    }

    public function saveAttempt(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $this->validate([
            'attemptForm.exam_year' => 'required|integer|min:1990|max:'.(date('Y') + 1),
            'attemptForm.roll_number' => 'nullable|string|max:50',
            'attemptForm.prelims_cleared' => 'boolean',
            'attemptForm.mains_cleared' => 'boolean',
            'attemptForm.interview_cleared' => 'boolean',
        ]);

        $valuesToCompare = [
            'exam_year' => (int) $this->attemptForm['exam_year'],
            'roll_number' => $this->attemptForm['roll_number'] ?: null,
            'prelims_cleared' => (bool) $this->attemptForm['prelims_cleared'],
            'mains_cleared' => (bool) $this->attemptForm['mains_cleared'],
            'interview_cleared' => (bool) $this->attemptForm['interview_cleared'],
        ];

        DB::beginTransaction();
        try {
            if ($this->attemptForm['editing_id']) {
                $attempt = UpscAttempt::where('user_id', $this->editingStudentId)->findOrFail($this->attemptForm['editing_id']);
                $dirty = $this->getDirtyFields($attempt, $valuesToCompare, [
                    'exam_year' => 'int',
                    'prelims_cleared' => 'bool',
                    'mains_cleared' => 'bool',
                    'interview_cleared' => 'bool',
                ]);

                if (empty($dirty)) {
                    Toast::dispatch($this, 'info', __('No changes detected in this UPSC attempt record.'));
                    DB::rollBack();
                    return;
                }

                $attempt->fill($dirty);
                $attempt->save();
                Toast::dispatch($this, 'success', __('Updated UPSC attempt: Year :year', ['year' => $attempt->exam_year]));
            } else {
                UpscAttempt::create(array_merge($valuesToCompare, ['user_id' => $this->editingStudentId]));
                Toast::dispatch($this, 'success', __('Added UPSC attempt: Year :year', ['year' => $valuesToCompare['exam_year']]));
            }

            DB::commit();
            $this->resetAttemptForm();
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to save UPSC attempt: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function deleteAttempt(int $id): void
    {
        DB::beginTransaction();
        try {
            $attempt = UpscAttempt::where('user_id', $this->editingStudentId)->findOrFail($id);
            $year = $attempt->exam_year;
            $attempt->delete();
            DB::commit();

            Toast::dispatch($this, 'success', __('Deleted UPSC attempt for year :year', ['year' => $year]));
            if ($this->attemptForm['editing_id'] === $id) {
                $this->resetAttemptForm();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to delete attempt: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function resetDocumentForm(): void
    {
        $this->documentForm = [
            'document_type' => 'Photo',
            'custom_document_type' => '',
            'file' => null,
            'file_url' => '',
            'description' => '',
        ];
    }

    public function saveDocument(): void
    {
        if (! $this->editingStudentId) {
            return;
        }

        $rules = [
            'documentForm.document_type' => 'required|string|max:100',
            'documentForm.file' => 'nullable|file|max:10240',
            'documentForm.file_url' => 'nullable|url|max:500',
            'documentForm.description' => 'nullable|string|max:255',
        ];

        if (($this->documentForm['document_type'] ?? '') === 'Custom') {
            $rules['documentForm.custom_document_type'] = 'required|string|min:2|max:100';
        }

        $this->validate($rules);

        if (empty($this->documentForm['file']) && empty(trim($this->documentForm['file_url'] ?? ''))) {
            $this->addError('documentForm.file', __('Please upload a file or provide a valid external document URL.'));
            return;
        }

        $effectiveType = ($this->documentForm['document_type'] === 'Custom')
            ? trim($this->documentForm['custom_document_type'])
            : $this->documentForm['document_type'];

        DB::beginTransaction();
        try {
            $filePath = null;
            $fileName = null;
            $fileUrl = trim($this->documentForm['file_url'] ?? '') ?: null;

            if ($this->documentForm['file']) {
                $file = $this->documentForm['file'];
                $fileName = $this->generateFileName($effectiveType, $file, $this->editingStudentId);
                $filePath = $file->storeAs('student_documents/'.$this->editingStudentId, $fileName, 'public');
            } elseif ($fileUrl) {
                $fileName = basename(parse_url($fileUrl, PHP_URL_PATH)) ?: $effectiveType;
            }

            StudentDocument::create([
                'user_id' => $this->editingStudentId,
                'type' => $effectiveType,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_url' => $fileUrl,
                'is_active' => true,
                'verified' => true,
                'verified_at' => now(),
                'uploaded_at' => now(),
            ]);

            DB::commit();
            $this->resetDocumentForm();
            Toast::dispatch($this, 'success', __('Document uploaded / linked successfully.'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to upload/link document: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function deleteDocument(int $id): void
    {
        DB::beginTransaction();
        try {
            $doc = StudentDocument::where('user_id', $this->editingStudentId)->findOrFail($id);
            if ($doc->file_path && Storage::disk('public')->exists($doc->file_path)) {
                Storage::disk('public')->delete($doc->file_path);
            }
            $type = $doc->document_type;
            $doc->delete();
            DB::commit();

            Toast::dispatch($this, 'success', __('Deleted document: :type', ['type' => $type]));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to delete document: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function viewDossier(int $id): void
    {
        $this->viewingStudentId = $id;
        $this->activeDossierTab = 'basic';
        $this->viewingStudent = User::with([
            'studentProfile',
            'studentAddresses',
            'studentEducations',
            'studentEmployments',
            'studentOtherDetails',
            'studentDocuments',
            'enrollments.batch',
            'enrollments.course',
            'enrollments.section',
            'profileUpdateRequests' => fn ($q) => $q->latest(),
        ])->findOrFail($id);

        $this->dispatch('modal-open', name: 'student-dossier-modal');
    }

    public function saveStudent(): void
    {
        if ($this->editingStudentId) {
            $this->updateBasicInfo();
            $this->updateProfileDetails();
            $this->updateAddressDetails();
            $this->dispatch('modal-close', name: 'student-form-modal');
            return;
        }

        $this->validate([
            'form.name' => 'required|string|max:150',
            'form.email' => 'required|email|max:150|unique:users,email',
            'form.phone' => 'nullable|string|max:25',
            'form.student_id' => 'nullable|string|max:10|unique:users,student_id',
            'form.dob' => 'nullable|date',
            'form.gender' => 'required|in:male,female,others',
            'form.category' => 'nullable|string|max:30',
        ]);

        DB::beginTransaction();
        try {
            $firstName = $this->form['first_name'] ?: explode(' ', trim($this->form['name']))[0];
            $lastName = $this->form['last_name'] ?: (explode(' ', trim($this->form['name']), 2)[1] ?? '');
            $fullName = trim($firstName.' '.$lastName) ?: $this->form['name'];

            $userFields = [
                'name' => $fullName,
                'first_name' => $firstName,
                'last_name' => $lastName ?: null,
                'email' => strtolower($this->form['email']),
                'phone' => $this->form['phone'] ?: null,
                'whatsapp_no' => $this->form['whatsapp_no'] ?: null,
                'dob' => $this->form['dob'] ?: null,
                'gender' => $this->form['gender'],
                'tenth_roll' => $this->form['tenth_roll'] ?: null,
                'id_type' => $this->form['id_type'] ?: null,
                'id_number' => $this->form['id_number'] ?: null,
                'status' => $this->form['status'] ?? 'active',
                'password' => Hash::make(Str::random(12)),
            ];

            if (! empty($this->form['student_id'])) {
                $userFields['student_id'] = substr(trim($this->form['student_id']), 0, 10);
            }

            $user = User::create($userFields);

            if ($this->avatarFile) {
                $this->validate(['avatarFile' => 'image|mimes:png,jpg,jpeg,webp|max:5120']);
                $avatarName = $this->generateFileName('avatar', $this->avatarFile, $user->id);
                $avatarPath = $this->avatarFile->storeAs('avatars', $avatarName, 'public');
                $user->avatar = $avatarPath;
                $user->save();
                $this->avatarFile = null;
            }
            $targetTeam = auth()->user()?->currentTeam
                ?? auth()->user()?->personalTeam()
                ?? Team::where('slug', 'sntcssc')->first()
                ?? Team::where('slug', 'super-admins-team')->first()
                ?? Team::first();

            if ($targetTeam) {
                $user->assignToTeam($targetTeam, TeamRole::Member);
            } else {
                $user->ensureCurrentTeam();
            }
            $user->assignRole('Student');

            if (empty($user->student_id)) {
                $user->student_id = StudentIdGeneratorService::generateForStudent($user);
                $user->saveQuietly();
            }

            UserStudentSyncService::syncStudent(
                $user,
                [],
                [
                    'father_name' => $this->form['father_name'] ?: null,
                    'mother_name' => $this->form['mother_name'] ?: null,
                    'dob' => $user->dob,
                    'gender' => $user->gender,
                    'category' => $this->form['category'] ?: null,
                    'distance' => $this->form['distance'] !== '' && $this->form['distance'] !== null ? (float) $this->form['distance'] : null,
                    'subcaste' => $this->form['subcaste'] ?: null,
                    'creamy_layer' => $this->form['creamy_layer'] ?: null,
                    'cert_no' => $this->form['cert_no'] ?: null,
                    'issued_date' => $this->form['issued_date'] ?: null,
                    'issued_by' => $this->form['issued_by'] ?: null,
                    'is_pwbd' => (bool) $this->form['is_pwbd'],
                    'pwbd_type' => $this->form['pwbd_type'] ?: null,
                    'pwd_percentage' => (int) $this->form['pwd_percentage'],
                    'pwbd_cert_no' => $this->form['pwbd_cert_no'] ?: null,
                    'pwbd_issued_date' => $this->form['pwbd_issued_date'] ?: null,
                    'pwbd_issued_by' => $this->form['pwbd_issued_by'] ?: null,
                    'occupation' => $this->form['occupation'] ?: null,
                    'father_occupation' => $this->form['father_occupation'] ?: null,
                    'mother_occupation' => $this->form['mother_occupation'] ?: null,
                    'family_income' => $this->form['family_income'] ? (float) $this->form['family_income'] : null,
                    'highest_edu' => $this->form['highest_edu'] ?: null,
                    'school_language' => $this->form['school_language'] ?: null,
                    'upsc_community' => $this->form['upsc_community'] ?: null,
                ],
                [
                    'state' => $this->form['state'] ?: 'West Bengal',
                    'district' => $this->form['district'] ?: null,
                    'subdistrict' => $this->form['subdistrict'] ?: null,
                    'post_office' => $this->form['post_office'] ?: null,
                    'police_station' => $this->form['police_station'] ?: null,
                    'address_line1' => $this->form['address_line1'] ?: null,
                    'address_line2' => $this->form['address_line2'] ?: null,
                    'pin_code' => $this->form['pin_code'] ?: null,
                ]
            );

            // Optional Immediate Enrollment
            if (! empty($this->form['enroll_batch_id']) && ! empty($this->form['enroll_course_id'])) {
                $batch = Batch::findOrFail((int) $this->form['enroll_batch_id']);
                $course = Course::findOrFail((int) $this->form['enroll_course_id']);
                $section = ! empty($this->form['enroll_section_id']) ? Section::find((int) $this->form['enroll_section_id']) : null;

                // Enforce restriction checks
                AdmissionRestrictionService::enforceEligibility($user, $course, $batch, $section);

                // Auto-generate student unique ID if missing
                if (empty($user->student_id)) {
                    StudentIdGeneratorService::generateForStudent($user, $course, $section, $batch->academic_year);
                }

                Enrollment::create([
                    'user_id' => $user->id,
                    'application_no' => ! empty($this->form['enroll_application_no']) ? trim($this->form['enroll_application_no']) : null,
                    'batch_id' => $batch->id,
                    'course_id' => $course->id,
                    'section_id' => $section?->id,
                    'roll_no' => $this->form['enroll_roll_no'] ?: null,
                    'enrolled_at' => now(),
                    'fee_amount' => $course->fee,
                    'fee_status' => $this->form['enroll_fee_status'],
                    'status' => 'active',
                ]);
            }

            DB::commit();
            Toast::dispatch($this, 'success', __('Student record for :name registered successfully.', ['name' => $user->name]));
            $this->dispatch('modal-close', name: 'student-form-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Error saving student: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openQuickEnroll(int $userId): void
    {
        $this->quickEnrollStudentId = $userId;
        $this->quickEnrollStudent = User::with(['enrollments', 'studentProfile'])->findOrFail($userId);

        $defaultBatch = Batch::where('status', 'active')->first() ?? Batch::first();
        $defaultCourse = Course::where('status', 'active')->first();

        $category = $this->quickEnrollStudent?->studentProfile?->category ?? 'UR';
        $fee = $defaultCourse ? CourseData::calculateFee($defaultCourse, $category) : 0.00;
        $isFree = $defaultCourse ? ($defaultCourse->isFree() || $fee == 0.0) : false;

        $this->quickEnrollForm = [
            'batch_id' => $defaultBatch ? (string) $defaultBatch->id : '',
            'course_id' => $defaultCourse ? (string) $defaultCourse->id : '',
            'section_id' => '',
            'roll_no' => '',
            'fee_amount' => (string) $fee,
            'fee_status' => $isFree ? 'exempted' : 'paid',
            'override_restrictions' => false,
        ];

        $this->dispatch('modal-open', name: 'quick-enroll-modal');
    }

    public function saveQuickEnroll(): void
    {
        $this->validate([
            'quickEnrollForm.batch_id' => 'required|exists:batches,id',
            'quickEnrollForm.course_id' => 'required|exists:courses,id',
            'quickEnrollForm.fee_amount' => 'required|numeric|min:0',
            'quickEnrollForm.fee_status' => 'required|in:pending,partial,paid,exempted',
        ]);

        $student = User::findOrFail($this->quickEnrollStudentId);
        $batch = Batch::findOrFail((int) $this->quickEnrollForm['batch_id']);
        $course = Course::findOrFail((int) $this->quickEnrollForm['course_id']);
        $section = ! empty($this->quickEnrollForm['section_id']) ? Section::find((int) $this->quickEnrollForm['section_id']) : null;

        // Restriction check
        $check = AdmissionRestrictionService::checkEligibility(
            $student,
            $course,
            $batch,
            $section,
            (bool) $this->quickEnrollForm['override_restrictions']
        );

        if (! $check['allowed']) {
            Toast::dispatch($this, 'error', $check['reason']);
            return;
        }

        DB::beginTransaction();
        try {
            // Auto-generate or maintain lifetime student ID
            if (empty($student->student_id)) {
                $generatedId = StudentIdGeneratorService::generateForStudent($student, $course, $section, $batch->academic_year);
            } else {
                $generatedId = $student->student_id;
            }

            Enrollment::create([
                'user_id' => $student->id,
                'application_no' => ! empty($this->quickEnrollForm['application_no']) ? trim($this->quickEnrollForm['application_no']) : null,
                'batch_id' => $batch->id,
                'course_id' => $course->id,
                'section_id' => $section?->id,
                'roll_no' => $this->quickEnrollForm['roll_no'] ?: null,
                'enrolled_at' => now(),
                'fee_amount' => (float) $this->quickEnrollForm['fee_amount'],
                'fee_status' => $this->quickEnrollForm['fee_status'],
                'status' => 'active',
            ]);

            DB::commit();
            Toast::dispatch($this, 'success', __('Student :name successfully enrolled into :course! (ID: :id)', [
                'name' => $student->name,
                'course' => $course->code,
                'id' => $generatedId,
            ]));
            $this->dispatch('modal-close', name: 'quick-enroll-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Enrollment failed: :err', ['err' => $e->getMessage()]));
        }
    }

    public function selectForDelete(int $id): void
    {
        $this->deleteStudentId = $id;
        $this->dispatch('modal-open', name: 'student-delete-modal');
    }

    public function deleteSelected(): void
    {
        if (! $this->deleteStudentId) {
            return;
        }

        $user = User::findOrFail($this->deleteStudentId);
        $user->delete();

        Toast::dispatch($this, 'success', __('Student :name soft-deleted successfully.', ['name' => $user->name]));
        $this->deleteStudentId = null;
        $this->dispatch('modal-close', name: 'student-delete-modal');
    }

    public function toggleDocumentVerified(int $documentId): void
    {
        $doc = StudentDocument::findOrFail($documentId);
        $doc->verified = ! $doc->verified;
        $doc->verified_by = $doc->verified ? auth()->id() : null;
        $doc->verified_at = $doc->verified ? now() : null;
        $doc->save();

        Toast::dispatch($this, 'success', __('Document verification status updated.'));
        if ($this->viewingStudentId) {
            $this->viewDossier($this->viewingStudentId);
        }
    }

    public function openDisciplineModal(int $studentId): void
    {
        $this->disciplineStudentId = $studentId;
        $this->disciplineStudent = User::with(['studentProfile', 'activeEnrollments.course'])->findOrFail($studentId);
        $this->disciplineForm = [
            'action_type' => 'temporary_suspension',
            'reason_category' => 'misconduct',
            'order_number' => 'ORD-'.date('Y').'-'.rand(1000, 9999),
            'order_date' => date('Y-m-d'),
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+30 days')),
            'is_lifetime' => false,
            'course_id' => '',
            'findings' => '',
        ];
        $this->disciplineOrderCopy = null;
        $this->dispatch('modal-open', name: 'student-discipline-modal');
    }

    public function updatedDisciplineFormActionType(string $val): void
    {
        if ($val === 'lifetime_ban') {
            $this->disciplineForm['is_lifetime'] = true;
            $this->disciplineForm['end_date'] = '';
        } else {
            $this->disciplineForm['is_lifetime'] = false;
            if (empty($this->disciplineForm['end_date'])) {
                $this->disciplineForm['end_date'] = date('Y-m-d', strtotime('+30 days'));
            }
        }
    }

    public function saveDisciplinaryAction(): void
    {
        $this->validate([
            'disciplineForm.action_type' => 'required|in:warning,temporary_suspension,lifetime_ban,course_disbarment,admission_cancelled',
            'disciplineForm.reason_category' => 'required|string|max:100',
            'disciplineForm.order_number' => 'required|string|max:100',
            'disciplineForm.order_date' => 'required|date',
            'disciplineForm.start_date' => 'required|date',
            'disciplineForm.end_date' => 'nullable|date|after_or_equal:disciplineForm.start_date',
            'disciplineForm.is_lifetime' => 'boolean',
            'disciplineForm.course_id' => 'nullable|exists:courses,id',
            'disciplineForm.findings' => 'required|string|min:10|max:2000',
            'disciplineOrderCopy' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if (! $this->disciplineStudentId) {
            return;
        }

        DB::beginTransaction();
        try {
            $student = User::findOrFail($this->disciplineStudentId);
            $orderCopyPath = null;
            if ($this->disciplineOrderCopy) {
                $orderCopyPath = $this->disciplineOrderCopy->store('disciplinary_orders', 'public');
            }

            $actionType = $this->disciplineForm['action_type'];
            $isLifetime = (bool) ($this->disciplineForm['is_lifetime'] ?? ($actionType === 'lifetime_ban'));

            $action = StudentDisciplinaryAction::create([
                'student_id' => $student->id,
                'course_id' => ! empty($this->disciplineForm['course_id']) ? (int) $this->disciplineForm['course_id'] : null,
                'action_type' => $actionType,
                'reason_category' => $this->disciplineForm['reason_category'],
                'order_number' => $this->disciplineForm['order_number'],
                'order_date' => $this->disciplineForm['order_date'],
                'start_date' => $this->disciplineForm['start_date'],
                'end_date' => $isLifetime ? null : ($this->disciplineForm['end_date'] ?: null),
                'is_lifetime' => $isLifetime,
                'findings' => $this->disciplineForm['findings'],
                'order_copy_path' => $orderCopyPath,
                'status' => 'active',
                'created_by' => auth()->id(),
            ]);

            // Update user status if major sanction
            if ($actionType === 'lifetime_ban') {
                $student->update(['status' => 'banned']);
            } elseif ($actionType === 'temporary_suspension') {
                $student->update(['status' => 'suspended']);
            }

            // If course disbarment or cancellation, update the linked enrollment(s)
            if (! empty($this->disciplineForm['course_id'])) {
                $enr = Enrollment::where('user_id', $student->id)
                    ->where('course_id', (int) $this->disciplineForm['course_id'])
                    ->whereIn('status', ['active', 'suspended'])
                    ->first();
                if ($enr) {
                    $enr->update([
                        'status' => ($actionType === 'course_disbarment' ? 'suspended' : 'cancelled'),
                        'cancellation_reason' => 'Disciplinary Action Order #'.$this->disciplineForm['order_number'].': '.$this->disciplineForm['findings'],
                        'discontinued_at' => now(),
                        'discontinued_by' => auth()->id(),
                    ]);
                }
            }

            AuditLog::record(
                action: 'student.disciplinary_action_imposed',
                details: [
                    'action_id' => $action->id,
                    'action_code' => $action->action_code,
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'action_type' => $actionType,
                    'reason_category' => $this->disciplineForm['reason_category'],
                    'order_number' => $this->disciplineForm['order_number'],
                    'is_lifetime' => $isLifetime,
                ],
                userId: auth()->id(),
                type: 'disciplinary',
                status: 'success'
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Disciplinary sanction (:type) imposed on student :name.', [
                'type' => ucfirst(str_replace('_', ' ', $actionType)),
                'name' => $student->name,
            ]));

            $this->disciplineStudentId = null;
            $this->disciplineStudent = null;
            $this->disciplineOrderCopy = null;
            $this->dispatch('modal-close', name: 'student-discipline-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to impose disciplinary action: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function openLiftSanctionModal(int $studentId): void
    {
        $this->liftSanctionStudentId = $studentId;
        $this->liftSanctionStudent = User::findOrFail($studentId);
        $this->activeSanctionToLift = StudentDisciplinaryAction::where('user_id', $studentId)
            ->where('status', 'active')
            ->latest()
            ->first();
        $this->liftSanctionForm = [
            'revocation_reason' => '',
            'revocation_order_no' => 'REV-'.date('Y').'-'.rand(1000, 9999),
        ];
        $this->dispatch('modal-open', name: 'student-lift-sanction-modal');
    }

    public function liftSanction(): void
    {
        $this->validate([
            'liftSanctionForm.revocation_reason' => 'required|string|min:5|max:1000',
            'liftSanctionForm.revocation_order_no' => 'required|string|max:100',
        ]);

        if (! $this->activeSanctionToLift) {
            return;
        }

        DB::beginTransaction();
        try {
            $sanction = $this->activeSanctionToLift;
            $student = User::findOrFail($sanction->user_id ?? $sanction->student_id);

            $sanction->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by' => auth()->id(),
                'revocation_reason' => 'Order #'.$this->liftSanctionForm['revocation_order_no'].': '.trim($this->liftSanctionForm['revocation_reason']),
            ]);

            // Check if student has other active sanctions
            $hasOtherActive = StudentDisciplinaryAction::where('user_id', $student->id)
                ->where('status', 'active')
                ->where('id', '!=', $sanction->id)
                ->exists();

            if (! $hasOtherActive && in_array($student->status, ['banned', 'suspended'])) {
                $student->update(['status' => 'active']);
            }

            AuditLog::record(
                action: 'student.disciplinary_sanction_lifted',
                details: [
                    'action_id' => $sanction->id,
                    'action_code' => $sanction->action_code,
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'revocation_order_no' => $this->liftSanctionForm['revocation_order_no'],
                    'reason' => $this->liftSanctionForm['revocation_reason'],
                ],
                userId: auth()->id(),
                type: 'disciplinary',
                status: 'success'
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Disciplinary sanction lifted for :name.', ['name' => $student->name]));
            $this->liftSanctionStudentId = null;
            $this->liftSanctionStudent = null;
            $this->activeSanctionToLift = null;
            $this->dispatch('modal-close', name: 'student-lift-sanction-modal');
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to lift sanction: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function openReinstateModal(int $enrollmentId): void
    {
        $this->reinstateEnrollmentId = $enrollmentId;
        $this->reinstateEnrollment = Enrollment::with(['user.studentProfile', 'course', 'batch', 'section'])->findOrFail($enrollmentId);
        $this->reinstateForm = [
            'order_number' => 'REIN-'.date('Y').'-'.rand(1000, 9999),
            'reason' => '',
            'effective_date' => now()->format('Y-m-d'),
            'activate_user' => true,
        ];
        $this->dispatch('modal-open', name: 'student-reinstate-enrollment-modal');
    }

    public function closeReinstateModal(): void
    {
        $this->reinstateEnrollmentId = null;
        $this->reinstateEnrollment = null;
        $this->reinstateForm = [
            'order_number' => '',
            'reason' => '',
            'effective_date' => '',
            'activate_user' => true,
        ];
        $this->dispatch('modal-close', name: 'student-reinstate-enrollment-modal');
    }

    public function executeReinstate(): void
    {
        if (! auth()->user()?->can('enrollments.reinstate') && ! auth()->user()?->can('students.edit') && ! auth()->user()?->hasRole('Super Administrator')) {
            Toast::dispatch($this, 'error', __('Unauthorized: You lack permission to reinstate student enrollments.'));
            return;
        }

        $this->validate([
            'reinstateForm.reason' => 'required|string|min:5|max:1000',
            'reinstateForm.order_number' => 'nullable|string|max:100',
        ]);

        if (! $this->reinstateEnrollmentId) {
            return;
        }

        DB::beginTransaction();
        try {
            $enr = Enrollment::with('user')->findOrFail($this->reinstateEnrollmentId);

            $enr->reinstate(
                reason: $this->reinstateForm['reason'],
                adminId: auth()->id(),
                activateUser: (bool) ($this->reinstateForm['activate_user'] ?? true),
                orderNumber: $this->reinstateForm['order_number'] ?: null
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Enrollment :no for :name systematically reinstated to active.', [
                'no' => $enr->enrollment_no,
                'name' => $enr->user?->name,
            ]));

            // Refresh viewing student if dossier modal is currently open
            if ($this->viewingStudentId) {
                $this->viewingStudent = User::with([
                    'studentProfile',
                    'studentAddresses',
                    'studentEducations' => fn ($q) => $q->orderBy('passing_year', 'desc'),
                    'studentEmployments' => fn ($q) => $q->orderBy('from_date', 'desc'),
                    'studentOtherDetails',
                    'studentDocuments',
                    'enrollments.batch',
                    'enrollments.course',
                    'enrollments.section',
                    'profileUpdateRequests' => fn ($q) => $q->latest(),
                ])->find($this->viewingStudentId);
            }

            $this->closeReinstateModal();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('Failed to reinstate student: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function processImport(): void
    {
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        $path = $this->importFile->getRealPath();
        $res = StudentImportExportService::importStudents($path);
        $this->importSummary = $res;

        if ($res['success'] > 0) {
            Toast::dispatch($this, 'success', __('Successfully imported :count students.', ['count' => $res['success']]));
        } else {
            Toast::dispatch($this, 'error', __('Import completed with :count errors.', ['count' => $res['failed']]));
        }
    }

    public function downloadTemplate(string $format = 'csv')
    {
        return StudentImportExportService::downloadStudentTemplate($format);
    }

    public function export(string $format = 'csv')
    {
        $students = User::with([
            'studentProfile',
            'studentAddresses',
            'activeEnrollments.batch',
            'activeEnrollments.course',
            'activeEnrollments.section',
        ])
        ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'Student'))->orWhereNotNull('student_id')->orWhereHas('enrollments'))
        ->get();

        $headers = ['Lifetime Student ID', 'URN', 'Student Name', 'Email', 'Phone', 'Gender', 'Category', 'Father Name', 'State', 'District', 'Active Batch', 'Active Course', 'Section', 'Status'];
        $rows = [];

        foreach ($students as $s) {
            $latestEnr = $s->activeEnrollments->first();
            $permAddr = $s->studentAddresses->firstWhere('address_type', 'Permanent');

            $rows[] = [
                $s->student_id ?? 'Not Assigned',
                $s->urn ?? 'N/A',
                $s->name,
                $s->email,
                $s->phone ?? 'N/A',
                ucfirst($s->gender ?? 'N/A'),
                $s->studentProfile?->category ?? 'N/A',
                $s->studentProfile?->father_name ?? 'N/A',
                $permAddr?->state ?? 'N/A',
                $permAddr?->district ?? 'N/A',
                $latestEnr?->batch?->name ?? 'None',
                $latestEnr?->course?->code ?? 'None',
                $latestEnr?->section?->name ?? 'N/A',
                ucfirst($s->status),
            ];
        }

        $title = 'UPSC Candidates Master Dossier Report';

        return match (strtolower($format)) {
            'pdf' => StudentImportExportService::exportPdf($title, $headers, $rows, 'Comprehensive Student Master Registry'),
            'xlsx' => StudentImportExportService::exportExcel('students-master-registry', $headers, $rows),
            default => StudentImportExportService::exportCsv('students-master-registry', $headers, $rows),
        };
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->categoryFilter = '';
        $this->genderFilter = '';
        $this->batchFilter = '';
        $this->courseFilter = '';
        $this->sectionFilter = '';
        $this->resetPage();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
                <x-icon name="users" class="w-7 h-7 text-primary" />
                {{ __('Student Management') }}
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Comprehensive UPSC student registry with lifetime unique student IDs, profile dossiers, address & education history, and enrollment records.') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Detailed Information Sheets Generator -->
            <a
                href="{{ route('admin.students.information-sheet') }}"
                class="inline-flex items-center gap-1.5 h-9 px-3 text-xs font-semibold rounded-lg border border-input bg-card hover:bg-muted text-foreground transition shadow-xs"
            >
                <x-icon name="file-text" class="h-3.5 w-3.5 text-primary"/>
                {{ __('Information Sheets') }}
            </a>

            <!-- Import Button -->
            <x-ui.button variant="outline" size="sm" wire:click="$dispatch('modal-open', { name: 'student-import-modal' })" class="h-9 gap-1.5">
                <x-icon name="upload" class="h-3.5 w-3.5"/>
                {{ __('Import') }}
            </x-ui.button>

            <!-- Export Dropdown -->
            <x-ui.dropdown width="w-48" align="end">
                <x-slot:trigger>
                    <x-ui.button variant="outline" size="sm" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-3.5 w-3.5"/>
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground ml-0.5"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('Export CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Export Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.separator/>
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Report') }}</x-ui.dropdown.item>
            </x-ui.dropdown>

            @can('students.create')
            <x-ui.button variant="default" size="sm" wire:click="create" class="h-9 gap-1.5">
                <x-icon name="plus" class="h-3.5 w-3.5"/>
                {{ __('Add Student') }}
            </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Students') }}</span>
                <span class="p-2 rounded-lg bg-sky-50 dark:bg-sky-950/50 text-sky-600 dark:text-sky-400">
                    <x-icon name="users" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ $this->stats['total_students'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Registered candidate base') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Active Enrolled') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="badge-check" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ $this->stats['total_enrolled'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Under ongoing batches') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider">{{ __('Lifetime Student IDs') }}</span>
                <span class="p-2 rounded-lg bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400">
                    <x-icon name="hash" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400 mt-2">{{ $this->stats['lifetime_ids'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('10-digit permanent IDs') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Correction Requests') }}</span>
                <span class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="edit" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ $this->stats['pending_requests'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Pending admin review') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-violet-600 dark:text-violet-400 uppercase tracking-wider">{{ __('Active Status') }}</span>
                <span class="p-2 rounded-lg bg-violet-50 dark:bg-violet-950/50 text-violet-600 dark:text-violet-400">
                    <x-icon name="shield-check" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-violet-600 dark:text-violet-400 mt-2">{{ $this->stats['active_students'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Eligible for institute access') }}</div>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-4 bg-card border border-border rounded-xl shadow-xs space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-7 gap-3">
            <div class="md:col-span-2 relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by Student ID, name, email, phone, URN...') }}"
                    class="h-9 w-full rounded-md border border-input bg-transparent pl-8 pr-8 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select wire:model.live="batchFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Batches') }}</option>
                @foreach($this->batches as $b)
                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="courseFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Courses') }}</option>
                @foreach($this->courses as $c)
                    <option value="{{ $c->id }}">{{ $c->code }} - {{ $c->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="sectionFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Sections') }}</option>
                @foreach($this->sections as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}@if($s->code) ({{ $s->code }})@endif</option>
                @endforeach
            </select>

            <select wire:model.live="categoryFilter" class="h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                <option value="">{{ __('All Categories') }}</option>
                <option value="UR">UR / General</option>
                <option value="SC">SC</option>
                <option value="ST">ST</option>
                <option value="OBCA">OBC-A</option>
                <option value="OBCB">OBC-B</option>
                <option value="EWS">EWS</option>
            </select>

            <div class="flex items-center justify-end">
                @if($search || $batchFilter || $courseFilter || $sectionFilter || $categoryFilter || $statusFilter || $genderFilter)
                    <x-ui.button variant="ghost" size="sm" wire:click="resetFilters" class="h-9 gap-1.5 text-xs">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                        {{ __('Reset') }}
                    </x-ui.button>
                @endif
            </div>
        </div>
    </div>

    <!-- Students Data Table -->
    <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm border-collapse">
                <thead class="bg-muted/50 border-b border-border text-xs text-muted-foreground uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('student_id')">
                            <div class="flex items-center gap-1">
                                {{ __('Lifetime Student ID') }}
                                @if($sortField === 'student_id')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3 h-3 text-foreground" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3 cursor-pointer select-none" wire:click="sortBy('name')">
                            <div class="flex items-center gap-1">
                                {{ __('Student Name & Contact') }}
                                @if($sortField === 'name')
                                    <x-icon name="{{ $sortDirection === 'asc' ? 'chevron-up' : 'chevron-down' }}" class="w-3 h-3 text-foreground" />
                                @endif
                            </div>
                        </th>
                        <th class="px-4 py-3">{{ __('Category & Gender') }}</th>
                        <th class="px-4 py-3">{{ __('Active Enrollments') }}</th>
                        <th class="px-4 py-3">{{ __('Permanent State') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-foreground">
                    @forelse($this->students as $student)
                        <tr class="hover:bg-muted/40 transition-colors">
                            <td class="px-4 py-3">
                                @if($student->student_id)
                                    <div class="font-mono font-bold text-primary bg-primary/10 px-2 py-0.5 rounded text-xs inline-block">
                                        {{ $student->student_id }}
                                    </div>
                                @else
                                    <span class="text-xs text-muted-foreground italic">{{ __('Not Generated') }}</span>
                                @endif
                                @if($student->urn)
                                    <div class="text-[10px] text-muted-foreground font-mono mt-0.5">URN: {{ $student->urn }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2.5">
                                    <x-ui.avatar :name="$student->name" :initials="$student->initials()" :src="$student->avatarUrl()" size="size-8 text-xs shrink-0" />
                                    <div class="min-w-0">
                                        <div class="font-bold text-foreground flex items-center gap-1.5">
                                            <span class="truncate">{{ $student->name }}</span>
                                    @if($student->profileUpdateRequests->isNotEmpty())
                                        <span class="w-2 h-2 rounded-full bg-amber-500" title="{{ __('Has pending profile correction request') }}"></span>
                                    @endif
                                        </div>
                                        <div class="text-xs text-muted-foreground truncate">
                                            {{ $student->email }} · {{ $student->phone ?? 'No phone' }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-medium text-foreground">
                                    {{ $student->studentProfile?->category ?? 'UR' }}
                                    @if($student->studentProfile?->is_pwbd)
                                        <span class="text-[10px] text-amber-600 font-bold ml-1">(PwBD)</span>
                                    @endif
                                </div>
                                <div class="text-muted-foreground capitalize">{{ $student->gender ?? 'others' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1 max-w-xs">
                                    @forelse($student->activeEnrollments as $enr)
                                        <div class="text-xs flex items-center gap-1">
                                            <x-ui.badge variant="secondary" size="sm">{{ $enr->course?->code }}</x-ui.badge>
                                            <span class="text-muted-foreground text-[11px]">{{ $enr->batch?->name }}</span>
                                            @if($enr->section)
                                                <span class="text-[10px] text-muted-foreground">({{ $enr->section->name }})</span>
                                            @endif
                                        </div>
                                    @empty
                                        <span class="text-xs text-muted-foreground italic">{{ __('No active courses') }}</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                {{ $student->permanent_address?->state ?? 'West Bengal' }}
                                @if($student->permanent_address?->district)
                                    <div class="text-[10px] text-muted-foreground">{{ $student->permanent_address->district }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $activeSanction = $student->studentDisciplinaryActions->first();
                                @endphp
                                @if($activeSanction)
                                    <div class="space-y-0.5">
                                        <x-ui.badge variant="destructive" size="sm">
                                            @if($activeSanction->isLifetime())
                                                {{ __('Banned (Lifetime)') }}
                                            @elseif($activeSanction->action_type === 'temporary_suspension')
                                                {{ __('Suspended') }}
                                            @elseif($activeSanction->action_type === 'course_disbarment')
                                                {{ __('Course Barred') }}
                                            @else
                                                {{ ucfirst(str_replace('_', ' ', $activeSanction->action_type)) }}
                                            @endif
                                        </x-ui.badge>
                                        <div class="text-[10px] text-muted-foreground font-mono">
                                            {{ $activeSanction->order_number }}
                                        </div>
                                    </div>
                                @else
                                    <x-ui.badge variant="{{ $student->status === 'active' ? 'success' : 'secondary' }}" size="sm">
                                        {{ ucfirst($student->status) }}
                                    </x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <!-- Quick Enroll Action -->
                                    <button
                                        type="button"
                                        wire:click="openQuickEnroll({{ $student->id }})"
                                        class="p-1 rounded-md text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 dark:hover:bg-indigo-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Enroll in Course') }}"
                                    >
                                        <x-icon name="plus-circle" class="w-4 h-4" />
                                    </button>

                                    <!-- View Complete Dossier -->
                                    <button
                                        type="button"
                                        wire:click="viewDossier({{ $student->id }})"
                                        class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted cursor-pointer transition-colors"
                                        title="{{ __('View Student Dossier') }}"
                                    >
                                        <x-icon name="eye" class="w-4 h-4" />
                                    </button>
                                    <!-- Detailed Information Sheet -->
                                    <a
                                        href="{{ route('admin.students.information-sheet', ['student_id' => $student->id]) }}"
                                        class="p-1 rounded-md text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Generate Detailed Information Sheet') }}"
                                    >
                                        <x-icon name="file-text" class="w-4 h-4" />
                                    </a>

                                    <!-- Download Student ID Card -->
                                    <a
                                        href="{{ route('student.id-card.download', ['student' => $student->id]) }}"
                                        target="_blank"
                                        class="p-1 rounded-md text-blue-600 hover:text-blue-700 hover:bg-blue-50 dark:hover:bg-blue-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Download Student ID Card') }}"
                                    >
                                        <x-icon name="id-card" class="w-4 h-4" />
                                    </a>

                                    @can('students.discipline')
                                    <button
                                        type="button"
                                        wire:click="openDisciplineModal({{ $student->id }})"
                                        class="p-1 rounded-md text-amber-600 hover:text-amber-700 hover:bg-amber-50 dark:hover:bg-amber-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Impose Disciplinary Sanction / Ban') }}"
                                    >
                                        <x-icon name="shield-alert" class="w-4 h-4" />
                                    </button>
                                    @endcan

                                    @if($activeSanction)
                                        @can('students.lift_ban')
                                        <button
                                            type="button"
                                            wire:click="openLiftSanctionModal({{ $student->id }})"
                                            class="p-1 rounded-md text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 cursor-pointer transition-colors"
                                            title="{{ __('Lift Disciplinary Sanction') }}"
                                        >
                                            <x-icon name="shield-check" class="w-4 h-4" />
                                        </button>
                                        @endcan
                                    @endif

                                    @can('students.edit')
                                    <button
                                        type="button"
                                        wire:click="edit({{ $student->id }})"
                                        class="p-1 rounded-md text-sky-600 hover:text-sky-700 hover:bg-sky-50 dark:hover:bg-sky-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Edit Student Profile') }}"
                                    >
                                        <x-icon name="edit" class="w-4 h-4" />
                                    </button>
                                    @endcan

                                    @can('students.delete')
                                    <button
                                        type="button"
                                        wire:click="selectForDelete({{ $student->id }})"
                                        class="p-1 rounded-md text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 cursor-pointer transition-colors"
                                        title="{{ __('Delete Student') }}"
                                    >
                                        <x-icon name="trash-2" class="w-4 h-4" />
                                    </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <x-icon name="users" class="w-8 h-8 text-muted-foreground/60" />
                                    <span class="text-base font-medium">{{ __('No students found matching your criteria') }}</span>
                                    <span class="text-xs text-muted-foreground">{{ __('Try clearing filters or search by name / student ID.') }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-border">
            {{ $this->students->links() }}
        </div>
    </div>

    <!-- Create / Edit Student Modal -->
    <x-ui.modal
        name="student-form-modal"
        max-width="max-w-4xl"
        :title="$editingStudentId ? __('Edit Student Dossier: :name', ['name' => $this->currentEditingStudent?->name ?? $userForm['name']]) : __('Register New Student')"
        :description="$editingStudentId ? __('Modular candidate dossier management. Update individual sections independently; only modified fields are reflected in the database.') : __('Complete candidate profile dossier including basic details, permanent address, and optional initial course enrollment.')"
    >
        @if($editingStudentId)
            <!-- Modular Student Dossier Editor (Direct & Independent Section Saves) -->
            <div class="space-y-4">
                <!-- Candidate Quick Summary Header -->
                @if($this->currentEditingStudent)
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 bg-muted/40 border border-border rounded-xl">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :name="$this->currentEditingStudent->name" :initials="$this->currentEditingStudent->initials()" :src="$this->currentEditingStudent->avatarUrl()" size="size-10 text-sm shrink-0" />
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-sm text-foreground">{{ $this->currentEditingStudent->name }}</span>
                                    <x-ui.badge variant="{{ $this->currentEditingStudent->status === 'active' ? 'success' : 'secondary' }}" size="sm">
                                        {{ ucfirst($this->currentEditingStudent->status) }}
                                    </x-ui.badge>
                                </div>
                                <div class="text-xs text-muted-foreground flex items-center gap-2 mt-0.5">
                                    <span>{{ $this->currentEditingStudent->email }}</span>
                                    <span>·</span>
                                    <span>{{ $this->currentEditingStudent->phone ?? 'No phone' }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="text-right">
                            <span class="text-[10px] text-muted-foreground uppercase font-semibold block">{{ __('Lifetime Student ID') }}</span>
                            <span class="font-mono font-bold text-sm text-primary">{{ $this->currentEditingStudent->student_id ?? 'Pending' }}</span>
                        </div>
                    </div>
                @endif

                <!-- Section Navigation Tabs -->
                <div class="flex flex-wrap border-b border-border text-xs gap-1">
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'basic')"
                        class="px-3.5 py-2 font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeFormTab === 'basic' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="user" class="w-3.5 h-3.5" />
                        {{ __('1. Account & Identity') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'profile')"
                        class="px-3.5 py-2 font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeFormTab === 'profile' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="file-text" class="w-3.5 h-3.5" />
                        {{ __('2. Profile & Family') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'address')"
                        class="px-3.5 py-2 font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeFormTab === 'address' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="map-pin" class="w-3.5 h-3.5" />
                        {{ __('3. Addresses') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'education')"
                        class="px-3.5 py-2 font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeFormTab === 'education' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="graduation-cap" class="w-3.5 h-3.5" />
                        {{ __('4. Qualifications') }}
                        @if($this->currentEditingStudent && $this->currentEditingStudent->studentEducations->count() > 0)
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-primary/10 text-primary font-bold">
                                {{ $this->currentEditingStudent->studentEducations->count() }}
                            </span>
                        @endif
                    </button>
                    @php
                        $isStudentWorkingHeader = (bool) ($this->currentEditingStudent?->studentProfile?->is_working_professional ?? ($profileForm['is_working_professional'] ?? false));
                    @endphp
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'employment')"
                        class="px-3.5 py-2 font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeFormTab === 'employment' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="briefcase" class="w-3.5 h-3.5" />
                        {{ $isStudentWorkingHeader ? __('5. Employment (Required)') : __('5. Employment (Optional)') }}
                        @if($this->currentEditingStudent && $this->currentEditingStudent->studentEmployments->count() > 0)
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-primary/10 text-primary font-bold">
                                {{ $this->currentEditingStudent->studentEmployments->count() }}
                            </span>
                        @endif
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'other')"
                        class="px-3.5 py-2 font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeFormTab === 'other' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="award" class="w-3.5 h-3.5" />
                        {{ __('6. UPSC Details') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'documents')"
                        class="px-3.5 py-2 font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeFormTab === 'documents' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        <x-icon name="folder" class="w-3.5 h-3.5" />
                        {{ __('7. Documents & OTR') }}
                        @if($this->currentEditingStudent && $this->currentEditingStudent->studentDocuments->count() > 0)
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] bg-primary/10 text-primary font-bold">
                                {{ $this->currentEditingStudent->studentDocuments->count() }}
                            </span>
                        @endif
                    </button>
                </div>

                <!-- SECTION 1: Account & Identity (users table) -->
                <div class="{{ $activeFormTab === 'basic' ? 'block' : 'hidden' }} space-y-4">
                    <!-- Student Photo / Avatar Management -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-3.5 bg-muted/30 border border-border rounded-xl">
                        <div class="flex items-center gap-3.5">
                            <div class="relative">
                                @if ($avatarFile)
                                    <img src="{{ $avatarFile->temporaryUrl() }}" alt="Preview" class="size-16 rounded-full object-cover border-2 border-primary shadow-xs" />
                                @elseif ($this->currentEditingStudent?->avatarUrl())
                                    <img src="{{ $this->currentEditingStudent->avatarUrl() }}" alt="{{ $this->currentEditingStudent->name }}" class="size-16 rounded-full object-cover border-2 border-border shadow-xs" />
                                @else
                                    <div class="size-16 rounded-full bg-primary/10 text-primary font-bold text-lg flex items-center justify-center border-2 border-border">
                                        {{ $this->currentEditingStudent?->initials() ?? 'ST' }}
                                    </div>
                                @endif
                                <div wire:loading wire:target="avatarFile" class="absolute inset-0 bg-background/80 rounded-full flex items-center justify-center">
                                    <x-icon name="loader-2" class="w-5 h-5 animate-spin text-primary" />
                                </div>
                            </div>
                            <div class="space-y-1">
                                <h4 class="text-xs font-semibold text-foreground">{{ __('Profile Photo / Avatar') }}</h4>
                                <p class="text-[11px] text-muted-foreground">{{ __('JPG, PNG or WEBP (Max 5MB). File will be renamed using RenamesUploads convention.') }}</p>
                                @error('avatarFile') <span class="text-xs text-destructive block">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="cursor-pointer">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-input bg-card hover:bg-muted text-foreground transition-colors shadow-xs">
                                    <x-icon name="upload" class="w-3.5 h-3.5" />
                                    {{ ($avatarFile || $this->currentEditingStudent?->avatar) ? __('Replace Photo') : __('Upload Photo') }}
                                </span>
                                <input type="file" wire:model="avatarFile" accept="image/png,image/jpeg,image/jpg,image/webp" class="sr-only" />
                            </label>
                            @if ($avatarFile)
                                <button type="button" wire:click="updateBasicInfo" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 transition-colors shadow-xs cursor-pointer">
                                    <x-icon name="check" class="w-3.5 h-3.5" />
                                    {{ __('Save Photo') }}
                                </button>
                                <button type="button" wire:click="$set('avatarFile', null)" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer">
                                    {{ __('Cancel') }}
                                </button>
                            @elseif ($this->currentEditingStudent?->avatar)
                                <button type="button" x-data x-on:click="$store.modals.open('student-avatar-remove-modal')" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-lg text-destructive hover:bg-destructive/10 transition-colors cursor-pointer">
                                    <x-icon name="trash-2" class="w-3.5 h-3.5" />
                                    {{ __('Remove Photo') }}
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <x-ui.input
                            wire:model.live="userForm.first_name"
                            label="{{ __('First Name') }}"
                            placeholder="{{ __('e.g. Rahul') }}"
                        />

                        <x-ui.input
                            wire:model.live="userForm.last_name"
                            label="{{ __('Last Name') }}"
                            placeholder="{{ __('e.g. Sharma') }}"
                        />

                        <x-ui.input
                            wire:model="userForm.name"
                            label="{{ __('Full Name * (Auto-filled)') }}"
                            placeholder="{{ __('Candidate Full Name') }}"
                            required
                        />

                        <x-ui.input
                            wire:model="userForm.email"
                            type="email"
                            label="{{ __('Email Address *') }}"
                            placeholder="{{ __('candidate@example.com') }}"
                            required
                        />

                        <x-ui.input
                            wire:model="userForm.phone"
                            label="{{ __('Mobile Number') }}"
                            placeholder="{{ __('e.g. 9830012345') }}"
                        />

                        <x-ui.input
                            wire:model="userForm.whatsapp_no"
                            label="{{ __('WhatsApp Number') }}"
                            placeholder="{{ __('e.g. 9830012345') }}"
                        />

                        <x-ui.input
                            wire:model="userForm.dob"
                            type="date"
                            label="{{ __('Date of Birth') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Gender *') }}</label>
                            <select wire:model="userForm.gender" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                                <option value="male">{{ __('Male') }}</option>
                                <option value="female">{{ __('Female') }}</option>
                                <option value="others">{{ __('Others') }}</option>
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Account Status *') }}</label>
                            <select wire:model="userForm.status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                                <option value="active">{{ __('Active') }}</option>
                                <option value="inactive">{{ __('Inactive') }}</option>
                                <option value="suspended">{{ __('Suspended') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                            </select>
                        </div>

                        <x-ui.input
                            wire:model="userForm.student_id"
                            label="{{ __('Lifetime Student ID (Max 10 chars)') }}"
                            placeholder="{{ __('e.g. 2026CC0001') }}"
                            maxlength="10"
                        />

                        <x-ui.input
                            wire:model="userForm.tenth_roll"
                            label="{{ __('10th / Secondary Roll No') }}"
                            placeholder="{{ __('e.g. 104523/2018') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Photo ID Type') }}</label>
                            <select wire:model="userForm.id_type" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="Aadhaar">Aadhaar Card</option>
                                <option value="Voter ID">Voter ID</option>
                                <option value="Passport">Passport</option>
                                <option value="PAN Card">PAN Card</option>
                                <option value="Driving License">Driving License</option>
                                <option value="Other">Other Official ID</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2 md:col-span-3">
                            <x-ui.input
                                wire:model="userForm.id_number"
                                label="{{ __('Photo ID / Aadhaar Number') }}"
                                placeholder="{{ __('e.g. 1234 5678 9012') }}"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <span class="text-xs text-muted-foreground">
                            {{ __('Only changed fields in the Account table will be updated.') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                                {{ __('Done / Close') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="default" wire:click="updateBasicInfo" class="gap-1.5">
                                <x-icon name="save" class="w-4 h-4"/>
                                {{ __('Update Account Details') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: Profile & Family (student_profiles table) -->
                <div class="{{ $activeFormTab === 'profile' ? 'block' : 'hidden' }} space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <x-ui.input
                            wire:model="profileForm.father_name"
                            label="{{ __('Father\'s Name') }}"
                            placeholder="{{ __('e.g. Debabrata Sen') }}"
                        />

                        <x-ui.input
                            wire:model="profileForm.mother_name"
                            label="{{ __('Mother\'s Name') }}"
                            placeholder="{{ __('e.g. Mita Sen') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Social Category') }}</label>
                            <select wire:model.live="profileForm.category" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Category --') }}</option>
                                @foreach(\App\Helpers\CategoryData::getCategoryOptions() as $catCode => $catLabel)
                                    <option value="{{ $catCode }}">{{ $catLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if(in_array($profileForm['category'] ?? '', ['OBC', 'OBCA', 'OBCB'], true))
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Creamy Layer Status (OBC only) *') }}</label>
                                <select wire:model="profileForm.creamy_layer" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                    <option value="">{{ __('-- Select Creamy / Non-Creamy --') }}</option>
                                    <option value="NCL">{{ __('Non-Creamy Layer (NCL)') }}</option>
                                    <option value="CL">{{ __('Creamy Layer (CL)') }}</option>
                                </select>
                            </div>
                        @endif

                        <x-ui.input
                            type="number"
                            step="0.01"
                            min="0"
                            wire:model="profileForm.distance"
                            label="{{ __('Distance from Home to Institute (KM)') }}"
                            placeholder="{{ __('e.g. 15.5') }}"
                        />

                        @php $subcastes = \App\Helpers\CategoryData::getSubcastes($profileForm['category'] ?? ''); @endphp
                        @if(!empty($subcastes))
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Subcaste') }}</label>
                                <select wire:model="profileForm.subcaste" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                    <option value="">{{ __('-- Select Subcaste --') }}</option>
                                    @foreach($subcastes as $sc)
                                        <option value="{{ $sc }}">{{ $sc }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <x-ui.input
                                wire:model="profileForm.subcaste"
                                label="{{ __('Subcaste') }}"
                                placeholder="{{ __('e.g. Rajbanshi, Santhal...') }}"
                            />
                        @endif

                        <x-ui.input
                            wire:model="profileForm.cert_no"
                            label="{{ __('Caste Certificate Number') }}"
                            placeholder="{{ __('e.g. WB/SC/2021/1049') }}"
                        />

                        <x-ui.input
                            wire:model="profileForm.issued_date"
                            type="date"
                            label="{{ __('Certificate Issued Date') }}"
                        />

                        <x-ui.input
                            wire:model="profileForm.issued_by"
                            label="{{ __('Issuing Authority') }}"
                            placeholder="{{ __('e.g. SDO Barrackpore') }}"
                        />

                        <x-ui.input
                            wire:model="profileForm.family_income"
                            type="number"
                            label="{{ __('Annual Family Income (INR)') }}"
                            placeholder="{{ __('e.g. 250000') }}"
                        />

                        <x-ui.input
                            wire:model="profileForm.occupation"
                            label="{{ __('Candidate Occupation') }}"
                            placeholder="{{ __('e.g. Student / Employed') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Currently a Working Professional?') }}</label>
                            <div class="flex items-center gap-4 pt-1">
                                <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer text-foreground">
                                    <input type="radio" value="0" wire:model.boolean="profileForm.is_working_professional" class="text-primary border-border focus:ring-primary" />
                                    <span>{{ __('No (Full-time Student / Aspirant)') }}</span>
                                </label>
                                <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer text-foreground">
                                    <input type="radio" value="1" wire:model.boolean="profileForm.is_working_professional" class="text-primary border-border focus:ring-primary" />
                                    <span>{{ __('Yes (Employed / Working)') }}</span>
                                </label>
                            </div>
                            <p class="text-[11px] text-muted-foreground">
                                {{ __('If marked as Yes, employment records are strictly required for profile completeness.') }}
                            </p>
                        </div>

                        <x-ui.input
                            wire:model="profileForm.father_occupation"
                            label="{{ __('Father\'s Occupation') }}"
                            placeholder="{{ __('e.g. Govt Service / Farmer') }}"
                        />

                        <x-ui.input
                            wire:model="profileForm.mother_occupation"
                            label="{{ __('Mother\'s Occupation') }}"
                            placeholder="{{ __('e.g. Homemaker / Teacher') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Highest Qualification') }}</label>
                            <select wire:model="profileForm.highest_edu" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Highest Qualification --') }}</option>
                                <option value="10th">10th / Secondary (Madhyamik)</option>
                                <option value="12th">12th / Higher Secondary (HS)</option>
                                <option value="Graduation">Graduation (Bachelor's)</option>
                                <option value="Post Graduation">Post-Graduation (Master's)</option>
                                <option value="PhD">M.Phil / Ph.D / Doctorate</option>
                                <option value="Diploma">Diploma / Vocational</option>
                                <option value="Other">Other Academic Level</option>
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Medium / School Language') }}</label>
                            <select wire:model="profileForm.school_language" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Language --') }}</option>
                                <option value="Bengali">Bengali</option>
                                <option value="English">English</option>
                                <option value="Hindi">Hindi</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>

                        <x-ui.input
                            wire:model="profileForm.upsc_community"
                            label="{{ __('UPSC Community Representation') }}"
                            placeholder="{{ __('e.g. General / SC') }}"
                        />

                        <!-- PwBD Block -->
                        <div class="sm:col-span-2 md:col-span-3 p-3 bg-muted/30 border border-border rounded-lg space-y-3">
                            <label class="flex items-center gap-2 cursor-pointer text-xs">
                                <input
                                    type="checkbox"
                                    wire:model.live="profileForm.is_pwbd"
                                    class="rounded border-input text-primary focus:ring-ring"
                                />
                                <span class="font-semibold text-foreground">{{ __('Person with Benchmark Disability (PwBD)') }}</span>
                            </label>

                            @if($profileForm['is_pwbd'])
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 pt-2">
                                    <div class="space-y-1">
                                        <label class="text-xs font-semibold text-foreground">{{ __('Disability Type *') }}</label>
                                        <select wire:model="profileForm.pwbd_type" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                            <option value="">-- Select Type --</option>
                                            <optgroup label="Physical Disabilities">
                                                <option value="VI">Visual Impairment (Blindness and Low Vision)</option>
                                                <option value="HI">Hearing Impairment (Deaf and Hard of Hearing)</option>
                                                <option value="LD">Locomotor Disability (including Cerebral Palsy, Leprosy Cured, Dwarfism, Acid Attack Victims, Muscular Dystrophy)</option>
                                            </optgroup>
                                            <optgroup label="Intellectual/Developmental Disabilities">
                                                <option value="ID">Intellectual Disability</option>
                                                <option value="ASD">Autism Spectrum Disorder</option>
                                                <option value="SLD">Specific Learning Disability</option>
                                                <option value="MI">Mental Illness</option>
                                            </optgroup>
                                            <option value="MD">Multiple Disabilities (two or more of the above categories)</option>
                                        </select>
                                    </div>

                                    <x-ui.input
                                        wire:model="profileForm.pwd_percentage"
                                        type="number"
                                        min="0"
                                        max="100"
                                        label="{{ __('Disability Percentage (%)') }}"
                                        placeholder="{{ __('e.g. 40') }}"
                                    />

                                    <x-ui.input
                                        wire:model="profileForm.pwbd_cert_no"
                                        label="{{ __('Disability Certificate Number') }}"
                                        placeholder="{{ __('e.g. WB/PWD/2022/8821') }}"
                                    />

                                    <x-ui.input
                                        wire:model="profileForm.pwbd_issued_date"
                                        type="date"
                                        label="{{ __('Certificate Issue Date') }}"
                                    />

                                    <div class="sm:col-span-2">
                                        <x-ui.input
                                            wire:model="profileForm.pwbd_issued_by"
                                            label="{{ __('Issued By / Medical Board Authority') }}"
                                            placeholder="{{ __('e.g. District Medical Board, SSKM Hospital') }}"
                                        />
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="sm:col-span-2 md:col-span-3">
                            <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Activities & Achievements') }}</label>
                            <textarea
                                wire:model="profileForm.activities"
                                rows="2"
                                placeholder="{{ __('Sports, cultural events, awards, social initiatives...') }}"
                                class="w-full rounded-md border border-input bg-card p-2 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            ></textarea>
                        </div>

                        <div class="sm:col-span-2 md:col-span-3">
                            <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Hobbies & Interests') }}</label>
                            <textarea
                                wire:model="profileForm.hobbies"
                                rows="2"
                                placeholder="{{ __('Reading non-fiction, chess, debating...') }}"
                                class="w-full rounded-md border border-input bg-card p-2 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            ></textarea>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <span class="text-xs text-muted-foreground">
                            {{ __('Only changed profile columns will be written to the database.') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                                {{ __('Done / Close') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="default" wire:click="updateProfileDetails" class="gap-1.5">
                                <x-icon name="save" class="w-4 h-4"/>
                                {{ __('Update Profile Details') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: Addresses (student_addresses table) -->
                <div class="{{ $activeFormTab === 'address' ? 'block' : 'hidden' }} space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-2 bg-muted/40 rounded-lg border border-border">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-foreground mr-1">{{ __('Address Type:') }}</span>
                            <button
                                type="button"
                                wire:click="switchAddressType('Permanent')"
                                class="px-3 py-1 rounded-md text-xs font-medium cursor-pointer transition-colors {{ ($addressForm['selected_type'] ?? 'Permanent') === 'Permanent' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
                            >
                                {{ __('Permanent Address') }}
                            </button>
                            <button
                                type="button"
                                wire:click="switchAddressType('Correspondence')"
                                class="px-3 py-1 rounded-md text-xs font-medium cursor-pointer transition-colors {{ ($addressForm['selected_type'] ?? '') === 'Correspondence' ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
                            >
                                {{ __('Correspondence Address') }}
                            </button>
                        </div>

                        @if(($addressForm['selected_type'] ?? '') === 'Correspondence')
                            <x-ui.button size="xs" variant="outline" wire:click="copyPermanentAddress" class="gap-1 text-xs">
                                <x-icon name="copy" class="w-3.5 h-3.5" />
                                {{ __('Copy from Permanent Address') }}
                            </x-ui.button>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('State') }}</label>
                            <select wire:model.live="addressForm.state" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select State --') }}</option>
                                @foreach(\App\Helpers\LocationData::getStateNames() as $st)
                                    <option value="{{ $st }}">{{ $st }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('District') }}</label>
                            <select wire:model="addressForm.district" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select District --') }}</option>
                                @foreach(\App\Helpers\LocationData::getDistrictsByState($addressForm['state'] ?? 'West Bengal') as $dist)
                                    <option value="{{ $dist }}">{{ $dist }}</option>
                                @endforeach
                            </select>
                        </div>

                        <x-ui.input
                            wire:model="addressForm.subdistrict"
                            label="{{ __('Subdistrict / Block / Tehsil') }}"
                            placeholder="{{ __('e.g. Bidhannagar') }}"
                        />

                        <x-ui.input
                            wire:model="addressForm.post_office"
                            label="{{ __('Post Office') }}"
                            placeholder="{{ __('e.g. Sealdah P.O.') }}"
                        />

                        <x-ui.input
                            wire:model="addressForm.police_station"
                            label="{{ __('Police Station') }}"
                            placeholder="{{ __('e.g. Electronic Complex P.S.') }}"
                        />

                        <x-ui.input
                            wire:model="addressForm.pin_code"
                            label="{{ __('PIN Code') }}"
                            placeholder="{{ __('700064') }}"
                        />

                        <div class="sm:col-span-2 md:col-span-3">
                            <x-ui.input
                                wire:model="addressForm.address_line1"
                                label="{{ __('Address Line 1') }}"
                                placeholder="{{ __('House no, building, street, lane...') }}"
                            />
                        </div>

                        <div class="sm:col-span-2 md:col-span-3">
                            <x-ui.input
                                wire:model="addressForm.address_line2"
                                label="{{ __('Address Line 2 (Optional)') }}"
                                placeholder="{{ __('Landmark, apartment details...') }}"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <span class="text-xs text-muted-foreground">
                            {{ __('Saving updates only the selected address type without modifying other records.') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                                {{ __('Done / Close') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="default" wire:click="updateAddressDetails" class="gap-1.5">
                                <x-icon name="save" class="w-4 h-4"/>
                                {{ __('Update :type Address', ['type' => $addressForm['selected_type'] ?? 'Permanent']) }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: Qualifications / Education (student_educations table) -->
                <div class="{{ $activeFormTab === 'education' ? 'block' : 'hidden' }} space-y-4">
                    <!-- Existing Qualifications List -->
                    <div class="border border-border rounded-xl overflow-hidden">
                        <div class="bg-muted/40 p-2.5 px-3 border-b border-border flex items-center justify-between">
                            <span class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <x-icon name="graduation-cap" class="w-4 h-4 text-primary" />
                                {{ __('Academic Qualifications On Record') }}
                            </span>
                            @if($educationForm['editing_id'])
                                <button type="button" wire:click="resetEducationForm" class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer">
                                    <x-icon name="plus" class="w-3.5 h-3.5" />
                                    {{ __('+ Switch to Add New') }}
                                </button>
                            @endif
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-muted/30 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                                    <tr>
                                        <th class="p-2.5">{{ __('Level') }}</th>
                                        <th class="p-2.5">{{ __('Institute / Board') }}</th>
                                        <th class="p-2.5">{{ __('Stream / Subject') }}</th>
                                        <th class="p-2.5">{{ __('Year') }}</th>
                                        <th class="p-2.5">{{ __('Score') }}</th>
                                        <th class="p-2.5">{{ __('Status') }}</th>
                                        <th class="p-2.5 text-right">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @if($this->currentEditingStudent)
                                        @forelse($this->currentEditingStudent->studentEducations as $edu)
                                            <tr class="{{ $educationForm['editing_id'] === $edu->id ? 'bg-primary/5 font-medium' : '' }}">
                                                <td class="p-2.5 font-semibold text-foreground">
                                                    {{ $edu->level }}
                                                    @if($edu->is_highest)
                                                        <span class="ml-1 text-[10px] text-primary bg-primary/10 px-1.5 py-0.5 rounded-full font-bold">Highest</span>
                                                    @endif
                                                </td>
                                                <td class="p-2.5 text-foreground">{{ $edu->board_university ?? $edu->institute ?? '—' }}</td>
                                                <td class="p-2.5 text-foreground">{{ $edu->stream_subject ?? '—' }}</td>
                                                <td class="p-2.5 text-foreground">{{ $edu->passing_year ?? '—' }}</td>
                                                <td class="p-2.5 font-mono text-foreground">
                                                    {{ $edu->percentage ? $edu->percentage.'%' : ($edu->cgpa ? 'CGPA: '.$edu->cgpa : '—') }}
                                                </td>
                                                <td class="p-2.5 text-foreground">{{ $edu->completion_status ?? 'Completed' }}</td>
                                                <td class="p-2.5 text-right">
                                                    <div class="flex items-center justify-end gap-1">
                                                        <button
                                                            type="button"
                                                            wire:click="editEducation({{ $edu->id }})"
                                                            class="p-1 rounded text-primary hover:bg-primary/10 cursor-pointer"
                                                            title="{{ __('Edit this qualification') }}"
                                                        >
                                                            <x-icon name="edit" class="w-3.5 h-3.5" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            wire:click="deleteEducation({{ $edu->id }})"
                                                            wire:confirm="{{ __('Are you sure you want to remove this qualification record?') }}"
                                                            class="p-1 rounded text-destructive hover:bg-destructive/10 cursor-pointer"
                                                            title="{{ __('Delete this qualification') }}"
                                                        >
                                                            <x-icon name="trash" class="w-3.5 h-3.5" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7" class="p-4 text-center text-muted-foreground">
                                                    {{ __('No qualification records added yet. Fill in the form below to add one.') }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add / Edit Qualification Sub-form -->
                    <div class="p-3 bg-muted/20 border border-border rounded-xl space-y-3">
                        <div class="flex items-center justify-between border-b border-border pb-2">
                            <span class="text-xs font-bold text-foreground">
                                {{ $educationForm['editing_id'] ? __('Editing Qualification Record') : __('Add New Qualification Record') }}
                            </span>
                            @if($educationForm['editing_id'])
                                <button type="button" wire:click="resetEducationForm" class="text-xs text-muted-foreground hover:text-foreground cursor-pointer">
                                    {{ __('Cancel Edit') }}
                                </button>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Level *') }}</label>
                                <select wire:model="educationForm.level" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                                    <option value="">Select Level</option>
                                    <option value="10th">10th / Secondary (Madhyamik)</option>
                                    <option value="12th">12th / Higher Secondary (HS)</option>
                                    <option value="Graduation">Graduation (Bachelor's)</option>
                                    <option value="Post Graduation">Post-Graduation (Master's)</option>
                                    <option value="PhD">M.Phil / Ph.D / Doctorate</option>
                                    <option value="Diploma">Diploma / Vocational</option>
                                    <option value="Other">Other Academic Level</option>
                                </select>
                            </div>

                            <x-ui.input
                                wire:model="educationForm.board_university"
                                label="{{ __('Board / University') }}"
                                placeholder="{{ __('e.g. Calcutta University / CBSE') }}"
                            />

                            <x-ui.input
                                wire:model="educationForm.institute"
                                label="{{ __('Institute / College Name') }}"
                                placeholder="{{ __('e.g. Presidency College') }}"
                            />

                            <x-ui.input
                                wire:model="educationForm.stream_subject"
                                label="{{ __('Stream / Major Subject') }}"
                                placeholder="{{ __('e.g. History / Computer Science') }}"
                            />

                            <x-ui.input
                                wire:model="educationForm.passing_year"
                                type="number"
                                min="1950"
                                max="{{ date('Y') + 5 }}"
                                label="{{ __('Year of Passing') }}"
                                placeholder="{{ __('e.g. 2022') }}"
                            />

                            <x-ui.input
                                wire:model.live="educationForm.total_marks"
                                type="number"
                                min="0"
                                label="{{ __('Total Marks') }}"
                                placeholder="{{ __('e.g. 1000') }}"
                            />

                            <x-ui.input
                                wire:model.live="educationForm.marks_obtained"
                                type="number"
                                min="0"
                                label="{{ __('Marks Obtained') }}"
                                placeholder="{{ __('e.g. 785') }}"
                            />

                            <x-ui.input
                                wire:model="educationForm.percentage"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                label="{{ __('Percentage (%) (Auto)') }}"
                                placeholder="{{ __('e.g. 78.50') }}"
                            />

                            <x-ui.input
                                wire:model="educationForm.cgpa"
                                type="number"
                                step="0.01"
                                min="0"
                                max="10"
                                label="{{ __('CGPA (out of 10)') }}"
                                placeholder="{{ __('e.g. 8.40') }}"
                            />

                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Division / Grade') }}</label>
                                <select wire:model="educationForm.division" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                    <option value="">{{ __('-- Select Division --') }}</option>
                                    <option value="1st Division / Class">{{ __('1st Division / Class') }}</option>
                                    <option value="2nd Division / Class">{{ __('2nd Division / Class') }}</option>
                                    <option value="3rd Division / Class">{{ __('3rd Division / Class') }}</option>
                                    <option value="Distinction">{{ __('Distinction') }}</option>
                                    <option value="Pass">{{ __('Pass') }}</option>
                                    <option value="Grade A+">Grade A+</option>
                                    <option value="Grade A">Grade A</option>
                                    <option value="Grade B">Grade B</option>
                                    <option value="Grade C">Grade C</option>
                                </select>
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Completion Status') }}</label>
                                <select wire:model="educationForm.completion_status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                    <option value="Completed">{{ __('Completed') }}</option>
                                    <option value="Pursuing">{{ __('Pursuing / Ongoing') }}</option>
                                    <option value="Appeared">{{ __('Final Year Appeared') }}</option>
                                </select>
                            </div>

                            <div class="sm:col-span-2 md:col-span-4 flex items-center justify-between pt-1">
                                <label class="flex items-center gap-2 cursor-pointer text-xs">
                                    <input
                                        type="checkbox"
                                        wire:model="educationForm.is_highest"
                                        class="rounded border-input text-primary focus:ring-ring"
                                    />
                                    <span class="font-medium text-foreground">{{ __('Mark this qualification as candidate\'s highest degree') }}</span>
                                </label>

                                <x-ui.button type="button" variant="default" size="sm" wire:click="saveEducation" class="gap-1.5">
                                    <x-icon name="save" class="w-4 h-4"/>
                                    {{ $educationForm['editing_id'] ? __('Update This Qualification') : __('Add Qualification') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3 border-t border-border">
                        <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                            {{ __('Done / Close') }}
                        </x-ui.button>
                    </div>
                </div>

                <!-- SECTION 5: Employment History (student_employments table) -->
                <div class="{{ $activeFormTab === 'employment' ? 'block' : 'hidden' }} space-y-4">
                    @php
                        $isStudentWorking = (bool) ($this->currentEditingStudent?->studentProfile?->is_working_professional ?? ($profileForm['is_working_professional'] ?? false));
                    @endphp

                    <!-- Working Professional Status Selector Card -->
                    <div class="rounded-xl border p-4 transition-all {{ $isStudentWorking ? 'bg-primary/5 border-primary/30' : 'bg-muted/20 border-border' }}">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-foreground">{{ __('Is this student currently a Working Professional?') }}</span>
                                    <x-ui.badge variant="{{ $isStudentWorking ? 'primary' : 'secondary' }}" class="text-[10px]">
                                        {{ $isStudentWorking ? __('Working Professional (Required)') : __('Full-time Student / Aspirant (Optional)') }}
                                    </x-ui.badge>
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    @if($isStudentWorking)
                                        <span class="text-primary font-medium">{{ __('Employment section is strictly required.') }}</span> {{ __('At least one employment record is required for full profile completeness.') }}
                                    @else
                                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">{{ __('Employment section is optional.') }}</span> {{ __('Students without work experience can reach 100% profile completeness without employment records.') }}
                                    @endif
                                </p>
                            </div>
                            <div class="inline-flex rounded-lg border border-border p-1 bg-background shrink-0">
                                <button
                                    type="button"
                                    wire:click="setStudentWorkingProfessionalStatus(false)"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors cursor-pointer {{ ! $isStudentWorking ? 'bg-secondary text-foreground shadow-xs font-bold' : 'text-muted-foreground hover:text-foreground' }}"
                                >
                                    {{ __('No (Student)') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="setStudentWorkingProfessionalStatus(true)"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors cursor-pointer {{ $isStudentWorking ? 'bg-primary text-primary-foreground shadow-xs font-bold' : 'text-muted-foreground hover:text-foreground' }}"
                                >
                                    {{ __('Yes (Working)') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Existing Employment List -->
                    <div class="border border-border rounded-xl overflow-hidden">
                        <div class="bg-muted/40 p-2.5 px-3 border-b border-border flex items-center justify-between">
                            <span class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <x-icon name="briefcase" class="w-4 h-4 text-primary" />
                                {{ $isStudentWorking ? __('Employment History On Record (Required)') : __('Employment History On Record (Optional)') }}
                            </span>
                            @if($employmentForm['editing_id'])
                                <button type="button" wire:click="resetEmploymentForm" class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer">
                                    <x-icon name="plus" class="w-3.5 h-3.5" />
                                    {{ __('+ Switch to Add New') }}
                                </button>
                            @endif
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-muted/30 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                                    <tr>
                                        <th class="p-2.5">{{ __('Employer') }}</th>
                                        <th class="p-2.5">{{ __('Designation') }}</th>
                                        <th class="p-2.5">{{ __('Nature') }}</th>
                                        <th class="p-2.5">{{ __('Location') }}</th>
                                        <th class="p-2.5">{{ __('Period') }}</th>
                                        <th class="p-2.5 text-right">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @if($this->currentEditingStudent)
                                        @forelse($this->currentEditingStudent->studentEmployments as $emp)
                                            <tr class="{{ $employmentForm['editing_id'] === $emp->id ? 'bg-primary/5 font-medium' : '' }}">
                                                <td class="p-2.5 font-semibold text-foreground">{{ $emp->employer_name }}</td>
                                                <td class="p-2.5 text-foreground">{{ $emp->designation ?? '—' }}</td>
                                                <td class="p-2.5 text-foreground">{{ $emp->nature_of_employment ?? 'Full-time' }}</td>
                                                <td class="p-2.5 text-foreground">{{ $emp->location ?? '—' }}</td>
                                                <td class="p-2.5 text-foreground">
                                                    {{ $emp->from_date ? $emp->from_date->format('M Y') : '—' }} to
                                                    {{ $emp->is_current ? 'Present' : ($emp->to_date ? $emp->to_date->format('M Y') : '—') }}
                                                </td>
                                                <td class="p-2.5 text-right">
                                                    <div class="flex items-center justify-end gap-1">
                                                        <button
                                                            type="button"
                                                            wire:click="editEmployment({{ $emp->id }})"
                                                            class="p-1 rounded text-primary hover:bg-primary/10 cursor-pointer"
                                                            title="{{ __('Edit this employment') }}"
                                                        >
                                                            <x-icon name="edit" class="w-3.5 h-3.5" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            wire:click="deleteEmployment({{ $emp->id }})"
                                                            wire:confirm="{{ __('Are you sure you want to remove this employment record?') }}"
                                                            class="p-1 rounded text-destructive hover:bg-destructive/10 cursor-pointer"
                                                            title="{{ __('Delete this employment') }}"
                                                        >
                                                            <x-icon name="trash" class="w-3.5 h-3.5" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="p-4 text-center text-muted-foreground">
                                                    @if($isStudentWorking)
                                                        <div class="py-2 space-y-1">
                                                            <p class="font-medium text-destructive">{{ __('No employment history recorded.') }}</p>
                                                            <p class="text-[11px]">{{ __('Because this student is marked as a working professional, at least one employment record is required for complete dossier status.') }}</p>
                                                        </div>
                                                    @else
                                                        {{ __('No employment history recorded. Employment is optional for students without work experience.') }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforelse
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add / Edit Employment Sub-form -->
                    <div class="p-3 bg-muted/20 border border-border rounded-xl space-y-3">
                        <div class="flex items-center justify-between border-b border-border pb-2">
                            <span class="text-xs font-bold text-foreground">
                                {{ $employmentForm['editing_id'] ? __('Editing Employment Record') : __('Add New Employment Record') }}
                            </span>
                            @if($employmentForm['editing_id'])
                                <button type="button" wire:click="resetEmploymentForm" class="text-xs text-muted-foreground hover:text-foreground cursor-pointer">
                                    {{ __('Cancel Edit') }}
                                </button>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                            <x-ui.input
                                wire:model="employmentForm.employer_name"
                                label="{{ __('Employer / Organization Name *') }}"
                                placeholder="{{ __('e.g. State Bank of India / TCS') }}"
                                required
                            />

                            <x-ui.input
                                wire:model="employmentForm.designation"
                                label="{{ $isStudentWorking ? __('Designation / Role *') : __('Designation / Role') }}"
                                placeholder="{{ __('e.g. Assistant Manager') }}"
                                :required="$isStudentWorking"
                            />

                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">
                                    {{ $isStudentWorking ? __('Nature of Employment *') : __('Nature of Employment') }}
                                </label>
                                <select wire:model="employmentForm.nature_of_employment" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                    <option value="Full-time">Full-time Regular</option>
                                    <option value="Part-time">Part-time</option>
                                    <option value="Contract">Contract / Fixed-Term</option>
                                    <option value="Govt / PSU">Govt / PSU Service</option>
                                    <option value="Self-employed">Self-employed / Business</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <x-ui.input
                                wire:model="employmentForm.location"
                                label="{{ __('Work Location') }}"
                                placeholder="{{ __('e.g. Kolkata / New Delhi') }}"
                            />

                            <x-ui.input
                                wire:model="employmentForm.from_date"
                                type="date"
                                label="{{ $isStudentWorking ? __('From Date *') : __('From Date') }}"
                                :required="$isStudentWorking"
                            />

                            <x-ui.input
                                wire:model="employmentForm.to_date"
                                type="date"
                                label="{{ __('To Date (Leave blank if currently working)') }}"
                                :disabled="(bool) $employmentForm['is_current']"
                            />

                            <div class="sm:col-span-2 md:col-span-3">
                                <label class="flex items-center gap-2 cursor-pointer text-xs mb-3">
                                    <input
                                        type="checkbox"
                                        wire:model="employmentForm.is_current"
                                        class="rounded border-input text-primary focus:ring-ring"
                                    />
                                    <span class="font-medium text-foreground">{{ __('Candidate is currently working in this organization') }}</span>
                                </label>

                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Job Responsibilities / Description') }}</label>
                                <textarea
                                    wire:model="employmentForm.description"
                                    rows="2"
                                    placeholder="{{ __('Brief description of role and key achievements...') }}"
                                    class="w-full rounded-md border border-input bg-card p-2 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                ></textarea>
                            </div>

                            <div class="sm:col-span-2 md:col-span-3 flex justify-end pt-1">
                                <x-ui.button type="button" variant="default" size="sm" wire:click="saveEmployment" class="gap-1.5">
                                    <x-icon name="save" class="w-4 h-4"/>
                                    {{ $employmentForm['editing_id'] ? __('Update This Employment') : __('Add Employment Record') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3 border-t border-border">
                        <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                            {{ __('Done / Close') }}
                        </x-ui.button>
                    </div>
                </div>

                <!-- SECTION 6: Other UPSC Details (student_other_details & upsc_attempts tables) -->
                <div class="{{ $activeFormTab === 'other' ? 'block' : 'hidden' }} space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                        <x-ui.input
                            wire:model.live="otherForm.attempts_already_given"
                            type="number"
                            min="0"
                            max="20"
                            label="{{ __('UPSC CSE Attempts Already Given *') }}"
                            placeholder="{{ __('0') }}"
                            required
                        />

                        <x-ui.input
                            wire:model="otherForm.attempts_left"
                            type="number"
                            min="0"
                            max="20"
                            label="{{ __('Attempts Remaining') }}"
                            placeholder="{{ __('e.g. 6') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Optional Subject Selected') }}</label>
                            <select wire:model="otherForm.optional_subject" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Optional Subject --') }}</option>
                                @foreach(\App\Helpers\UpscData::getOptionalSubjects() as $subj)
                                    <option value="{{ $subj }}">{{ $subj }}</option>
                                @endforeach
                            </select>
                        </div>

                        <x-ui.input
                            wire:model="otherForm.year_of_appearing"
                            type="number"
                            min="2000"
                            max="2040"
                            label="{{ __('Target Year of Appearing') }}"
                            placeholder="{{ __('e.g. 2026') }}"
                        />
                    </div>

                    <div class="flex items-center justify-between pt-2 pb-3 border-b border-border">
                        <span class="text-xs text-muted-foreground">
                            {{ __('Update basic UPSC attempt counter and target year above.') }}
                        </span>
                        <x-ui.button type="button" variant="default" size="sm" wire:click="updateOtherDetails" class="gap-1.5">
                            <x-icon name="save" class="w-4 h-4"/>
                            {{ __('Save UPSC Summary Details') }}
                        </x-ui.button>
                    </div>

                    <!-- UPSC Attempts History Section -->
                    <div class="space-y-3 pt-2">
                        @if((int)($otherForm['attempts_already_given'] ?? 0) > 0)
                            <div class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-xs text-amber-700 dark:text-amber-300 flex items-start gap-2">
                                <x-icon name="alert-circle" class="w-4 h-4 mt-0.5 shrink-0" />
                                <div>
                                    <span class="font-semibold">{{ __('Mandatory Attempt History Required:') }}</span>
                                    {{ __('Candidate has indicated :count attempt(s) already given. Please add individual attempt records (Exam Year, Roll No, and Clearance stages) in the table below.', ['count' => $otherForm['attempts_already_given']]) }}
                                </div>
                            </div>
                        @endif

                        <!-- Existing Attempts Table -->
                        <div class="border border-border rounded-xl overflow-hidden">
                            <div class="bg-muted/40 p-2.5 px-3 border-b border-border flex items-center justify-between">
                                <span class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                    <x-icon name="award" class="w-4 h-4 text-primary" />
                                    {{ __('UPSC CSE Attempts History Records') }}
                                </span>
                                @if($attemptForm['editing_id'])
                                    <button type="button" wire:click="resetAttemptForm" class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer">
                                        <x-icon name="plus" class="w-3.5 h-3.5" />
                                        {{ __('+ Switch to Add New') }}
                                    </button>
                                @endif
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-muted/30 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                                        <tr>
                                            <th class="p-2.5">{{ __('Exam Year') }}</th>
                                            <th class="p-2.5">{{ __('Roll Number') }}</th>
                                            <th class="p-2.5">{{ __('Prelims Cleared') }}</th>
                                            <th class="p-2.5">{{ __('Mains Cleared') }}</th>
                                            <th class="p-2.5">{{ __('Interview Cleared') }}</th>
                                            <th class="p-2.5 text-right">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @if($this->currentEditingStudent)
                                            @forelse($this->currentEditingStudent->upscAttempts as $att)
                                                <tr class="{{ $attemptForm['editing_id'] === $att->id ? 'bg-primary/5 font-medium' : '' }}">
                                                    <td class="p-2.5 font-bold text-foreground font-mono">{{ $att->exam_year }}</td>
                                                    <td class="p-2.5 font-mono text-foreground">{{ $att->roll_number ?? '—' }}</td>
                                                    <td class="p-2.5">
                                                        <x-ui.badge variant="{{ $att->prelims_cleared ? 'success' : 'secondary' }}" size="sm">
                                                            {{ $att->prelims_cleared ? __('Yes') : __('No') }}
                                                        </x-ui.badge>
                                                    </td>
                                                    <td class="p-2.5">
                                                        <x-ui.badge variant="{{ $att->mains_cleared ? 'success' : 'secondary' }}" size="sm">
                                                            {{ $att->mains_cleared ? __('Yes') : __('No') }}
                                                        </x-ui.badge>
                                                    </td>
                                                    <td class="p-2.5">
                                                        <x-ui.badge variant="{{ $att->interview_cleared ? 'success' : 'secondary' }}" size="sm">
                                                            {{ $att->interview_cleared ? __('Yes') : __('No') }}
                                                        </x-ui.badge>
                                                    </td>
                                                    <td class="p-2.5 text-right">
                                                        <div class="flex items-center justify-end gap-1">
                                                            <button
                                                                type="button"
                                                                wire:click="editAttempt({{ $att->id }})"
                                                                class="p-1 rounded text-primary hover:bg-primary/10 cursor-pointer"
                                                                title="{{ __('Edit this attempt') }}"
                                                            >
                                                                <x-icon name="edit" class="w-3.5 h-3.5" />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                wire:click="deleteAttempt({{ $att->id }})"
                                                                wire:confirm="{{ __('Are you sure you want to remove this attempt record?') }}"
                                                                class="p-1 rounded text-destructive hover:bg-destructive/10 cursor-pointer"
                                                                title="{{ __('Delete this attempt') }}"
                                                            >
                                                                <x-icon name="trash" class="w-3.5 h-3.5" />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="p-4 text-center text-muted-foreground">
                                                        {{ __('No attempt records added yet. Fill in the form below to add a previous UPSC attempt.') }}
                                                    </td>
                                                </tr>
                                            @endforelse
                                        @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Add / Edit UPSC Attempt Sub-form -->
                        <div class="p-3 bg-muted/20 border border-border rounded-xl space-y-3">
                            <div class="flex items-center justify-between border-b border-border pb-2">
                                <span class="text-xs font-bold text-foreground">
                                    {{ $attemptForm['editing_id'] ? __('Editing UPSC Attempt Record') : __('Add Previous UPSC Attempt Record') }}
                                </span>
                                @if($attemptForm['editing_id'])
                                    <button type="button" wire:click="resetAttemptForm" class="text-xs text-muted-foreground hover:text-foreground cursor-pointer">
                                        {{ __('Cancel Edit') }}
                                    </button>
                                @endif
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3">
                                <x-ui.input
                                    wire:model="attemptForm.exam_year"
                                    type="number"
                                    min="1990"
                                    max="{{ date('Y') + 1 }}"
                                    label="{{ __('Exam Year *') }}"
                                    placeholder="{{ date('Y') }}"
                                    required
                                />

                                <x-ui.input
                                    wire:model="attemptForm.roll_number"
                                    label="{{ __('Roll Number') }}"
                                    placeholder="{{ __('e.g. 0812345') }}"
                                />

                                <div class="space-y-1">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Prelims Cleared? *') }}</label>
                                    <select wire:model="attemptForm.prelims_cleared" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                        <option value="0">{{ __('No') }}</option>
                                        <option value="1">{{ __('Yes') }}</option>
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Mains Cleared? *') }}</label>
                                    <select wire:model="attemptForm.mains_cleared" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                        <option value="0">{{ __('No') }}</option>
                                        <option value="1">{{ __('Yes') }}</option>
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Interview Cleared? *') }}</label>
                                    <select wire:model="attemptForm.interview_cleared" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                        <option value="0">{{ __('No') }}</option>
                                        <option value="1">{{ __('Yes') }}</option>
                                    </select>
                                </div>

                                <div class="sm:col-span-2 md:col-span-5 flex justify-end pt-1">
                                    <x-ui.button type="button" variant="default" size="sm" wire:click="saveAttempt" class="gap-1.5">
                                        <x-icon name="save" class="w-4 h-4"/>
                                        {{ $attemptForm['editing_id'] ? __('Update This Attempt Record') : __('Save Attempt Record') }}
                                    </x-ui.button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3 border-t border-border">
                        <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                            {{ __('Done / Close') }}
                        </x-ui.button>
                    </div>
                </div>

                <!-- SECTION 7: Student Documents & OTR Links (student_documents table) -->
                <div class="{{ $activeFormTab === 'documents' ? 'block' : 'hidden' }} space-y-4">
                    <!-- Existing Documents Table -->
                    <div class="border border-border rounded-xl overflow-hidden">
                        <div class="bg-muted/40 p-2.5 px-3 border-b border-border flex items-center justify-between">
                            <span class="text-xs font-bold text-foreground flex items-center gap-1.5">
                                <x-icon name="folder" class="w-4 h-4 text-primary" />
                                {{ __('Student Documents & OTR File Records') }}
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-muted/30 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                                    <tr>
                                        <th class="p-2.5">{{ __('Document Type') }}</th>
                                        <th class="p-2.5">{{ __('Source Type') }}</th>
                                        <th class="p-2.5">{{ __('File Name / Target') }}</th>
                                        <th class="p-2.5">{{ __('Remarks') }}</th>
                                        <th class="p-2.5">{{ __('Uploaded / Linked') }}</th>
                                        <th class="p-2.5 text-right">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @if($this->currentEditingStudent)
                                        @forelse($this->currentEditingStudent->studentDocuments as $doc)
                                            <tr>
                                                <td class="p-2.5 font-bold text-foreground">{{ $doc->document_type }}</td>
                                                <td class="p-2.5">
                                                    @if($doc->file_url)
                                                        <x-ui.badge variant="secondary" size="sm" class="font-mono">
                                                            {{ __('External / OTR URL') }}
                                                        </x-ui.badge>
                                                    @else
                                                        <x-ui.badge variant="outline" size="sm">
                                                            {{ __('Uploaded File') }}
                                                        </x-ui.badge>
                                                    @endif
                                                </td>
                                                <td class="p-2.5">
                                                    @php $docUrl = $doc->file_url ?: ($doc->file_path ? Storage::url($doc->file_path) : '#'); @endphp
                                                    <a href="{{ $docUrl }}" target="_blank" class="text-primary hover:underline font-mono text-[11px] inline-flex items-center gap-1">
                                                        <span>{{ $doc->file_name ?? basename($docUrl) }}</span>
                                                        <x-icon name="external-link" class="w-3 h-3" />
                                                    </a>
                                                </td>
                                                <td class="p-2.5 text-muted-foreground">{{ $doc->description ?? '—' }}</td>
                                                <td class="p-2.5 text-muted-foreground">{{ $doc->created_at ? $doc->created_at->format('d M Y') : '—' }}</td>
                                                <td class="p-2.5 text-right">
                                                    <button
                                                        type="button"
                                                        wire:click="deleteDocument({{ $doc->id }})"
                                                        wire:confirm="{{ __('Are you sure you want to remove this document record?') }}"
                                                        class="p-1 rounded text-destructive hover:bg-destructive/10 cursor-pointer"
                                                        title="{{ __('Delete document') }}"
                                                    >
                                                        <x-icon name="trash" class="w-3.5 h-3.5" />
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="p-4 text-center text-muted-foreground">
                                                    {{ __('No documents attached yet. Upload a file or provide an OTR document URL below.') }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add / Upload Document Sub-form -->
                    <div class="p-3 bg-muted/20 border border-border rounded-xl space-y-3">
                        <span class="text-xs font-bold text-foreground block border-b border-border pb-2">
                            {{ __('Upload Document or Attach External / OTR File URL') }}
                        </span>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Document Type *') }}</label>
                                <select wire:model.live="documentForm.document_type" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                                    <option value="Photo">{{ __('Candidate Photo') }}</option>
                                    <option value="Signature">{{ __('Candidate Signature') }}</option>
                                    <option value="10th Admit Card">{{ __('10th Admit Card / Proof of Age') }}</option>
                                    <option value="10th Marksheet">{{ __('10th / Secondary Marksheet') }}</option>
                                    <option value="12th Marksheet">{{ __('12th / Higher Secondary Marksheet') }}</option>
                                    <option value="Graduation Marksheet">{{ __('Graduation Degree / Marksheet') }}</option>
                                    <option value="Caste Certificate">{{ __('Caste Certificate (SC/ST/OBC)') }}</option>
                                    <option value="PwBD Certificate">{{ __('PwBD Disability Certificate') }}</option>
                                    <option value="EWS Certificate">{{ __('EWS Income & Asset Certificate') }}</option>
                                    <option value="Aadhaar Card">{{ __('Aadhaar / Photo ID Document') }}</option>
                                    <option value="Custom">{{ __('Custom / Other Document Type') }}</option>
                                </select>
                            </div>

                            @if(($documentForm['document_type'] ?? '') === 'Custom')
                                <div class="space-y-1">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Custom Document Name *') }}</label>
                                    <input
                                        type="text"
                                        wire:model="documentForm.custom_document_type"
                                        placeholder="{{ __('e.g. Migration Certificate, Residential Proof, etc.') }}"
                                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        required
                                    />
                                    @error('documentForm.custom_document_type') <span class="text-[11px] text-destructive block">{{ $message }}</span> @enderror
                                </div>
                            @endif

                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Option A: Upload File') }}</label>
                                <input
                                    type="file"
                                    wire:model="documentForm.file"
                                    class="h-9 w-full rounded-md border border-input bg-card px-3 py-1.5 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                @error('documentForm.file') <span class="text-[11px] text-destructive block">{{ $message }}</span> @enderror
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Option B: External / OTR Document URL') }}</label>
                                <input
                                    type="url"
                                    wire:model="documentForm.file_url"
                                    placeholder="https://otr.example.com/docs/file.pdf"
                                    class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                @error('documentForm.file_url') <span class="text-[11px] text-destructive block">{{ $message }}</span> @enderror
                            </div>

                            <div class="sm:col-span-2 md:col-span-3">
                                <x-ui.input
                                    wire:model="documentForm.description"
                                    label="{{ __('Description / Remarks (Optional)') }}"
                                    placeholder="{{ __('e.g. SDO Barrackpore issued certificate copy') }}"
                                />
                            </div>

                            <div class="sm:col-span-2 md:col-span-3 flex justify-end pt-1">
                                <x-ui.button type="button" variant="default" size="sm" wire:click="saveDocument" class="gap-1.5">
                                    <x-icon name="upload" class="w-4 h-4"/>
                                    {{ __('Attach / Upload Document') }}
                                </x-ui.button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-3 border-t border-border">
                        <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                            {{ __('Done / Close') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        @else
            <!-- New Student Registration Form (Create Mode) -->
            <form wire:submit="saveStudent" class="space-y-4">
                <div class="flex border-b border-border text-xs">
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'basic')"
                        class="px-4 py-2 font-semibold border-b-2 transition-colors cursor-pointer {{ $activeFormTab === 'basic' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('1. Basic & Identity') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'address')"
                        class="px-4 py-2 font-semibold border-b-2 transition-colors cursor-pointer {{ $activeFormTab === 'address' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('2. Address & Family') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('activeFormTab', 'enrollment')"
                        class="px-4 py-2 font-semibold border-b-2 transition-colors cursor-pointer {{ $activeFormTab === 'enrollment' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                    >
                        {{ __('3. Initial Course Enrollment (Optional)') }}
                    </button>
                </div>

                <!-- Tab 1: Basic & Identity -->
                <div class="{{ $activeFormTab === 'basic' ? 'block' : 'hidden' }} space-y-3">
                    <!-- Student Photo / Avatar Upload -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-3.5 bg-muted/30 border border-border rounded-xl">
                        <div class="flex items-center gap-3.5">
                            <div class="relative">
                                @if ($avatarFile)
                                    <img src="{{ $avatarFile->temporaryUrl() }}" alt="Preview" class="size-16 rounded-full object-cover border-2 border-primary shadow-xs" />
                                @else
                                    <div class="size-16 rounded-full bg-primary/10 text-primary font-bold text-lg flex items-center justify-center border-2 border-border">
                                        <x-icon name="user" class="w-7 h-7 text-muted-foreground" />
                                    </div>
                                @endif
                                <div wire:loading wire:target="avatarFile" class="absolute inset-0 bg-background/80 rounded-full flex items-center justify-center">
                                    <x-icon name="loader-2" class="w-5 h-5 animate-spin text-primary" />
                                </div>
                            </div>
                            <div class="space-y-1">
                                <h4 class="text-xs font-semibold text-foreground">{{ __('Profile Photo / Avatar (Optional)') }}</h4>
                                <p class="text-[11px] text-muted-foreground">{{ __('JPG, PNG or WEBP (Max 5MB). Renamed according to system standards upon save.') }}</p>
                                @error('avatarFile') <span class="text-xs text-destructive block">{{ $message }}</span> @enderror
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <label class="cursor-pointer">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-input bg-card hover:bg-muted text-foreground transition-colors shadow-xs">
                                    <x-icon name="upload" class="w-3.5 h-3.5" />
                                    {{ $avatarFile ? __('Change Photo') : __('Upload Photo') }}
                                </span>
                                <input type="file" wire:model="avatarFile" accept="image/png,image/jpeg,image/jpg,image/webp" class="sr-only" />
                            </label>
                            @if ($avatarFile)
                                <button type="button" wire:click="$set('avatarFile', null)" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                                    {{ __('Remove') }}
                                </button>
                            @endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <x-ui.input
                            wire:model.live="form.first_name"
                            label="{{ __('First Name') }}"
                            placeholder="{{ __('e.g. Rahul') }}"
                        />

                        <x-ui.input
                            wire:model.live="form.last_name"
                            label="{{ __('Last Name') }}"
                            placeholder="{{ __('e.g. Sharma') }}"
                        />

                        <x-ui.input
                            wire:model="form.name"
                            label="{{ __('Full Name * (Auto-filled)') }}"
                            placeholder="{{ __('Candidate Full Name') }}"
                            required
                        />

                        <x-ui.input
                            wire:model="form.email"
                            type="email"
                            label="{{ __('Email Address *') }}"
                            placeholder="{{ __('candidate@example.com') }}"
                            required
                        />

                        <x-ui.input
                            wire:model="form.phone"
                            label="{{ __('Mobile Number') }}"
                            placeholder="{{ __('e.g. 9830012345') }}"
                        />

                        <x-ui.input
                            wire:model="form.whatsapp_no"
                            label="{{ __('WhatsApp Number') }}"
                            placeholder="{{ __('e.g. 9830012345') }}"
                        />

                        <x-ui.input
                            wire:model="form.dob"
                            type="date"
                            label="{{ __('Date of Birth') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Gender *') }}</label>
                            <select wire:model="form.gender" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                                <option value="male">{{ __('Male') }}</option>
                                <option value="female">{{ __('Female') }}</option>
                                <option value="others">{{ __('Others') }}</option>
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Category') }}</label>
                            <select wire:model.live="form.category" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Category --') }}</option>
                                @foreach(\App\Helpers\CategoryData::getCategoryOptions() as $catCode => $catLabel)
                                    <option value="{{ $catCode }}">{{ $catLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if(in_array($form['category'] ?? '', ['OBC', 'OBCA', 'OBCB'], true))
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Creamy Layer Status (OBC only) *') }}</label>
                                <select wire:model="form.creamy_layer" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                    <option value="">{{ __('-- Select Creamy / Non-Creamy --') }}</option>
                                    <option value="NCL">{{ __('Non-Creamy Layer (NCL)') }}</option>
                                    <option value="CL">{{ __('Creamy Layer (CL)') }}</option>
                                </select>
                            </div>
                        @endif

                        @php $createSubcastes = \App\Helpers\CategoryData::getSubcastes($form['category'] ?? ''); @endphp
                        @if(!empty($createSubcastes))
                            <div class="space-y-1">
                                <label class="text-xs font-semibold text-foreground">{{ __('Subcaste') }}</label>
                                <select wire:model="form.subcaste" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                    <option value="">{{ __('-- Select Subcaste --') }}</option>
                                    @foreach($createSubcastes as $sc)
                                        <option value="{{ $sc }}">{{ $sc }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <x-ui.input
                                wire:model="form.subcaste"
                                label="{{ __('Subcaste') }}"
                                placeholder="{{ __('e.g. Rajbanshi, Mahishya...') }}"
                            />
                        @endif

                        @if(($form['category'] ?? 'UR') !== 'UR')
                            <x-ui.input
                                wire:model="form.cert_no"
                                label="{{ __('Category Certificate Number') }}"
                                placeholder="{{ __('e.g. SC/WB/2021/1045') }}"
                            />

                            <x-ui.input
                                wire:model="form.issued_date"
                                type="date"
                                label="{{ __('Certificate Issue Date') }}"
                            />

                            <x-ui.input
                                wire:model="form.issued_by"
                                label="{{ __('Issuing Authority') }}"
                                placeholder="{{ __('e.g. SDO, Alipore') }}"
                            />
                        @endif

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('UPSC Community Representation') }}</label>
                            <select wire:model="form.upsc_community" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Community --') }}</option>
                                <option value="General">{{ __('General') }}</option>
                                <option value="EWS">{{ __('EWS') }}</option>
                                <option value="OBC">{{ __('OBC') }}</option>
                                <option value="SC">{{ __('SC') }}</option>
                                <option value="ST">{{ __('ST') }}</option>
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Highest Qualification') }}</label>
                            <select wire:model="form.highest_edu" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Highest Qualification --') }}</option>
                                <option value="10th">10th / Secondary (Madhyamik)</option>
                                <option value="12th">12th / Higher Secondary (HS)</option>
                                <option value="Graduation">Graduation (Bachelor's)</option>
                                <option value="Post Graduation">Post-Graduation (Master's)</option>
                                <option value="PhD">M.Phil / Ph.D / Doctorate</option>
                                <option value="Diploma">Diploma / Vocational</option>
                                <option value="Other">Other Academic Level</option>
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Medium / School Language') }}</label>
                            <select wire:model="form.school_language" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select Language --') }}</option>
                                <option value="Bengali">Bengali</option>
                                <option value="English">English</option>
                                <option value="Hindi">Hindi</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>

                        <x-ui.input
                            wire:model="form.student_id"
                            label="{{ __('Lifetime Student ID (Max 10 digits)') }}"
                            placeholder="{{ __('Auto-generated if left blank') }}"
                            maxlength="10"
                        />

                        <x-ui.input
                            wire:model="form.tenth_roll"
                            label="{{ __('10th / Secondary Roll No') }}"
                            placeholder="{{ __('e.g. 104523/2018') }}"
                        />

                        <x-ui.input
                            wire:model="form.id_number"
                            label="{{ __('Aadhaar / Photo ID Number') }}"
                            placeholder="{{ __('e.g. 1234 5678 9012') }}"
                        />
                    </div>
                </div>

                <!-- Tab 2: Address & Family -->
                <div class="{{ $activeFormTab === 'address' ? 'block' : 'hidden' }} space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <x-ui.input
                            wire:model="form.father_name"
                            label="{{ __('Father\'s Name') }}"
                            placeholder="{{ __('e.g. Debabrata Sen') }}"
                        />

                        <x-ui.input
                            wire:model="form.mother_name"
                            label="{{ __('Mother\'s Name') }}"
                            placeholder="{{ __('e.g. Mita Sen') }}"
                        />

                        <x-ui.input
                            wire:model="form.family_income"
                            type="number"
                            label="{{ __('Annual Family Income (INR)') }}"
                            placeholder="{{ __('e.g. 250000') }}"
                        />

                        <x-ui.input
                            wire:model="form.father_occupation"
                            label="{{ __('Father\'s Occupation') }}"
                            placeholder="{{ __('e.g. Retired Govt. Servant, Teacher...') }}"
                        />

                        <x-ui.input
                            wire:model="form.mother_occupation"
                            label="{{ __('Mother\'s Occupation') }}"
                            placeholder="{{ __('e.g. Homemaker, Professor...') }}"
                        />

                        <x-ui.input
                            wire:model="form.occupation"
                            label="{{ __('Candidate\'s Occupation') }}"
                            placeholder="{{ __('e.g. Student, Software Engineer...') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('State') }}</label>
                            <select wire:model.live="form.state" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select State --') }}</option>
                                @foreach(\App\Helpers\LocationData::getStateNames() as $st)
                                    <option value="{{ $st }}">{{ $st }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('District') }}</label>
                            <select wire:model="form.district" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Select District --') }}</option>
                                @foreach(\App\Helpers\LocationData::getDistrictsByState($form['state'] ?? 'West Bengal') as $dist)
                                    <option value="{{ $dist }}">{{ $dist }}</option>
                                @endforeach
                            </select>
                        </div>

                        <x-ui.input
                            wire:model="form.subdistrict"
                            label="{{ __('Subdistrict / Block / Tehsil') }}"
                            placeholder="{{ __('e.g. Bidhannagar') }}"
                        />

                        <x-ui.input
                            wire:model="form.post_office"
                            label="{{ __('Post Office') }}"
                            placeholder="{{ __('e.g. Sealdah P.O.') }}"
                        />

                        <x-ui.input
                            wire:model="form.police_station"
                            label="{{ __('Police Station') }}"
                            placeholder="{{ __('e.g. Salt Lake North P.S.') }}"
                        />

                        <x-ui.input
                            wire:model="form.pin_code"
                            label="{{ __('PIN Code') }}"
                            placeholder="{{ __('700064') }}"
                        />

                        <x-ui.input
                            type="number"
                            step="0.01"
                            min="0"
                            wire:model="form.distance"
                            label="{{ __('Distance to Institute (KM)') }}"
                            placeholder="{{ __('e.g. 15.5') }}"
                        />

                        <div class="sm:col-span-2 md:col-span-3">
                            <x-ui.input
                                wire:model="form.address_line1"
                                label="{{ __('Permanent Address Line 1') }}"
                                placeholder="{{ __('House no, Premise name, Street name...') }}"
                            />
                        </div>

                        <div class="sm:col-span-2 md:col-span-3">
                            <x-ui.input
                                wire:model="form.address_line2"
                                label="{{ __('Permanent Address Line 2 (Optional)') }}"
                                placeholder="{{ __('Apartment, Landmark, Floor...') }}"
                            />
                        </div>

                        <!-- PwBD Block in Create Form -->
                        <div class="sm:col-span-2 md:col-span-3 p-3 bg-muted/30 border border-border rounded-lg space-y-3">
                            <label class="flex items-center gap-2 cursor-pointer text-xs">
                                <input
                                    type="checkbox"
                                    wire:model.live="form.is_pwbd"
                                    class="rounded border-input text-primary focus:ring-ring"
                                />
                                <span class="font-medium text-foreground">{{ __('Person with Benchmark Disability (PwBD)') }}</span>
                            </label>

                            @if(!empty($form['is_pwbd']))
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 pt-2">
                                    <div class="space-y-1">
                                        <label class="text-xs font-semibold text-foreground">{{ __('Disability Type *') }}</label>
                                        <select wire:model="form.pwbd_type" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                            <option value="">-- Select Type --</option>
                                            <optgroup label="Physical Disabilities">
                                                <option value="VI">Visual Impairment (Blindness and Low Vision)</option>
                                                <option value="HI">Hearing Impairment (Deaf and Hard of Hearing)</option>
                                                <option value="LD">Locomotor Disability (including Cerebral Palsy, Leprosy Cured, Dwarfism, Acid Attack Victims, Muscular Dystrophy)</option>
                                            </optgroup>
                                            <optgroup label="Intellectual/Developmental Disabilities">
                                                <option value="ID">Intellectual Disability</option>
                                                <option value="ASD">Autism Spectrum Disorder</option>
                                                <option value="SLD">Specific Learning Disability</option>
                                                <option value="MI">Mental Illness</option>
                                            </optgroup>
                                            <option value="MD">Multiple Disabilities (two or more of the above categories)</option>
                                        </select>
                                    </div>

                                    <x-ui.input
                                        wire:model="form.pwd_percentage"
                                        type="number"
                                        min="0"
                                        max="100"
                                        label="{{ __('Disability Percentage (%)') }}"
                                        placeholder="{{ __('e.g. 40') }}"
                                    />

                                    <x-ui.input
                                        wire:model="form.pwbd_cert_no"
                                        label="{{ __('Disability Certificate Number') }}"
                                        placeholder="{{ __('e.g. WB/PWD/2022/8821') }}"
                                    />

                                    <x-ui.input
                                        wire:model="form.pwbd_issued_date"
                                        type="date"
                                        label="{{ __('Certificate Issue Date') }}"
                                    />

                                    <div class="sm:col-span-2">
                                        <x-ui.input
                                            wire:model="form.pwbd_issued_by"
                                            label="{{ __('Issued By / Medical Authority') }}"
                                            placeholder="{{ __('e.g. District Medical Board, SSKM Hospital') }}"
                                        />
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Initial Course Enrollment (Only for Create) -->
                <div class="{{ $activeFormTab === 'enrollment' ? 'block' : 'hidden' }} space-y-3">
                    <div class="p-3 bg-primary/10 border border-primary/20 rounded-lg text-xs text-primary">
                        {{ __('Optionally enroll this student immediately into a course. The student\'s lifetime unique 10-digit ID will be generated automatically following institutional rules.') }}
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Select Batch') }}</label>
                            <select wire:model="form.enroll_batch_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('No Immediate Enrollment') }}</option>
                                @foreach($this->batches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Select Course') }}</label>
                            <select wire:model="form.enroll_course_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('Select Course Programme') }}</option>
                                @foreach($this->courses as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                @endforeach
                            </select>
                        </div>

                        <x-ui.input
                            wire:model="form.enroll_roll_no"
                            label="{{ __('Roll Number (Optional)') }}"
                            placeholder="{{ __('e.g. CC-2026-A-01') }}"
                        />

                        <x-ui.input
                            wire:model="form.enroll_application_no"
                            label="{{ __('Application No. (Optional)') }}"
                            placeholder="{{ __('e.g. APP-2026-001') }}"
                        />

                        <div class="space-y-1">
                            <label class="text-xs font-semibold text-foreground">{{ __('Fee Status') }}</label>
                            <select wire:model="form.enroll_fee_status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="paid">{{ __('Paid') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="partial">{{ __('Partial') }}</option>
                                <option value="exempted">{{ __('Exempted') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-form-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="default">
                        {{ __('Register Student') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>

    <!-- Student Complete Dossier Modal -->
    <x-ui.modal
        name="student-dossier-modal"
        max-width="max-w-4xl"
        :title="$viewingStudent ? $viewingStudent->name : __('Student Dossier')"
        :description="__('Candidate Master Record & Enrollment History')"
    >
        @if($viewingStudent)
            <div class="space-y-6">
                <!-- Header Dossier Card -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-4">
                    <div class="flex items-center gap-3">
                        <x-ui.avatar :name="$viewingStudent->name" :initials="$viewingStudent->initials()" :src="$viewingStudent->avatarUrl()" size="size-12 text-base shrink-0" />
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-bold text-foreground">{{ $viewingStudent->name }}</h3>
                                <x-ui.badge variant="{{ $viewingStudent->status === 'active' ? 'success' : 'secondary' }}" size="sm">
                                    {{ ucfirst($viewingStudent->status) }}
                                </x-ui.badge>
                            </div>
                            <div class="text-xs text-muted-foreground flex items-center gap-2 mt-0.5">
                                <span>{{ $viewingStudent->email }}</span>
                                <span>·</span>
                                <span>{{ $viewingStudent->phone ?? 'No phone' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="text-right">
                        <div class="text-xs text-muted-foreground uppercase font-semibold">{{ __('Permanent Student ID') }}</div>
                        <div class="text-xl font-mono font-bold text-primary">
                            {{ $viewingStudent->student_id ?? 'Not Assigned' }}
                        </div>
                        @if($viewingStudent->urn)
                            <div class="text-[10px] text-muted-foreground font-mono">URN: {{ $viewingStudent->urn }}</div>
                        @endif
                    </div>
                </div>

                <!-- Tab Navigation in Dossier -->
                <div class="flex flex-wrap gap-2 border-b border-border pb-2 text-xs">
                    @foreach([
                        'basic' => __('Basic Details'),
                        'addresses' => __('Addresses'),
                        'education' => __('Qualifications'),
                        'employment' => __('Employment'),
                        'other' => __('UPSC Details'),
                        'documents' => __('Documents'),
                        'enrollments' => __('Enrollment History'),
                        'requests' => __('Correction Requests'),
                    ] as $tabKey => $tabLabel)
                        <button
                            type="button"
                            wire:click="$set('activeDossierTab', '{{ $tabKey }}')"
                            class="px-3 py-1.5 rounded-lg font-medium transition-colors cursor-pointer {{ $activeDossierTab === $tabKey ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
                        >
                            {{ $tabLabel }}
                        </button>
                    @endforeach
                </div>

                <!-- Tab: Basic Details -->
                @if($activeDossierTab === 'basic')
                    <div class="space-y-3">
                        <div class="flex items-center justify-between pb-1 border-b border-border">
                            <span class="text-xs font-bold text-foreground">{{ __('Candidate & Family Profile') }}</span>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="edit({{ $viewingStudent->id }}, 'basic')"
                                    class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                                >
                                    <x-icon name="edit" class="w-3.5 h-3.5" />
                                    {{ __('Edit Account Info') }}
                                </button>
                                <span class="text-muted-foreground">·</span>
                                <button
                                    type="button"
                                    wire:click="edit({{ $viewingStudent->id }}, 'profile')"
                                    class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                                >
                                    <x-icon name="edit" class="w-3.5 h-3.5" />
                                    {{ __('Edit Profile & Family') }}
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 bg-muted/40 rounded-xl text-xs">
                            <div>
                                <span class="text-muted-foreground">{{ __('Date of Birth') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $viewingStudent->dob ? $viewingStudent->dob->format('d M Y') : '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Gender') }}</span>
                                <div class="font-semibold capitalize mt-0.5 text-foreground">{{ $viewingStudent->gender ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Category') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">
                                    {{ $viewingStudent->studentProfile?->category ?? 'UR' }}
                                    @if($viewingStudent->studentProfile?->creamy_layer)
                                        <span class="text-[11px] text-muted-foreground">({{ $viewingStudent->studentProfile->creamy_layer }})</span>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Subcaste') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $viewingStudent->studentProfile?->subcaste ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Father\'s Name') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $viewingStudent->studentProfile?->father_name ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Mother\'s Name') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $viewingStudent->studentProfile?->mother_name ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Family Income') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">₹{{ number_format($viewingStudent->studentProfile?->family_income ?? 0, 2) }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Medium / School Language') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $viewingStudent->studentProfile?->school_language ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Highest Qualification') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $viewingStudent->studentProfile?->highest_edu ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Distance to Institute') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $viewingStudent->studentProfile?->distance !== null ? $viewingStudent->studentProfile->distance . ' KM' : '—' }}</div>
                            </div>
                            <div class="sm:col-span-3">
                                <span class="text-muted-foreground">{{ __('PwBD Status') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">
                                    @if($viewingStudent->studentProfile?->is_pwbd)
                                        <span class="text-primary font-bold">{{ __('Yes') }} ({{ $viewingStudent->studentProfile->pwbd_type ?? 'N/A' }} - {{ $viewingStudent->studentProfile->pwd_percentage }}%)</span>
                                        @if($viewingStudent->studentProfile->pwbd_cert_no)
                                            <span class="text-muted-foreground font-mono text-[11px]"> · Cert: {{ $viewingStudent->studentProfile->pwbd_cert_no }}</span>
                                        @endif
                                        @if($viewingStudent->studentProfile->pwbd_issued_by)
                                            <span class="text-muted-foreground text-[11px]"> · Issued By: {{ $viewingStudent->studentProfile->pwbd_issued_by }}</span>
                                        @endif
                                    @else
                                        {{ __('No') }}
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Tab: Addresses -->
                @if($activeDossierTab === 'addresses')
                    <div class="space-y-3">
                        <div class="flex items-center justify-between pb-1 border-b border-border">
                            <span class="text-xs font-bold text-foreground">{{ __('Registered Addresses') }}</span>
                            <button
                                type="button"
                                wire:click="edit({{ $viewingStudent->id }}, 'address')"
                                class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                            >
                                <x-icon name="edit" class="w-3.5 h-3.5" />
                                {{ __('Edit Addresses') }}
                            </button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                            <div class="p-3 border border-border rounded-lg bg-card">
                                <span class="font-bold text-primary uppercase text-[11px] block mb-2">{{ __('Permanent Address') }}</span>
                                @if($viewingStudent->permanent_address)
                                    <p class="text-foreground leading-relaxed">
                                        {{ $viewingStudent->permanent_address->formatted_address }}
                                    </p>
                                @else
                                    <p class="text-muted-foreground italic">{{ __('No permanent address on file.') }}</p>
                                @endif
                            </div>
                            <div class="p-3 border border-border rounded-lg bg-card">
                                <span class="font-bold text-indigo-600 dark:text-indigo-400 uppercase text-[11px] block mb-2">{{ __('Correspondence Address') }}</span>
                                @if($viewingStudent->correspondence_address)
                                    <p class="text-foreground leading-relaxed">
                                        {{ $viewingStudent->correspondence_address->formatted_address }}
                                    </p>
                                @else
                                    <p class="text-muted-foreground italic">{{ __('Same as permanent address.') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                <!-- Tab: Education -->
                @if($activeDossierTab === 'education')
                    <div class="space-y-3">
                        <div class="flex items-center justify-between pb-1 border-b border-border">
                            <span class="text-xs font-bold text-foreground">{{ __('Academic Qualifications') }}</span>
                            <button
                                type="button"
                                wire:click="edit({{ $viewingStudent->id }}, 'education')"
                                class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                            >
                                <x-icon name="edit" class="w-3.5 h-3.5" />
                                {{ __('Manage Qualifications') }}
                            </button>
                        </div>
                        <div class="border border-border rounded-lg overflow-hidden text-xs">
                            <table class="w-full text-left">
                                <thead class="bg-muted/50 border-b border-border">
                                    <tr>
                                        <th class="p-2.5">{{ __('Level') }}</th>
                                        <th class="p-2.5">{{ __('Institute / Board') }}</th>
                                        <th class="p-2.5">{{ __('Subject / Stream') }}</th>
                                        <th class="p-2.5">{{ __('Year') }}</th>
                                        <th class="p-2.5">{{ __('Score / %') }}</th>
                                        <th class="p-2.5">{{ __('Division') }}</th>
                                        <th class="p-2.5">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse($viewingStudent->studentEducations as $edu)
                                        <tr>
                                            <td class="p-2.5 font-semibold text-foreground">
                                                {{ $edu->level }}
                                                @if($edu->is_highest)
                                                    <span class="ml-1 text-[10px] text-primary bg-primary/10 px-1.5 py-0.5 rounded-full font-bold">Highest</span>
                                                @endif
                                            </td>
                                            <td class="p-2.5 text-foreground">{{ $edu->board_university ?? $edu->institute }}</td>
                                            <td class="p-2.5 text-foreground">{{ $edu->stream_subject ?? '—' }}</td>
                                            <td class="p-2.5 text-foreground">{{ $edu->passing_year ?? '—' }}</td>
                                            <td class="p-2.5 font-mono font-medium text-foreground">
                                                {{ $edu->percentage ? $edu->percentage.'%' : ($edu->cgpa ? 'CGPA: '.$edu->cgpa : '—') }}
                                                @if($edu->marks_obtained && $edu->total_marks)
                                                    <span class="text-[10px] text-muted-foreground block font-normal">({{ $edu->marks_obtained }} / {{ $edu->total_marks }})</span>
                                                @endif
                                            </td>
                                            <td class="p-2.5 text-foreground">{{ $edu->division ?? '—' }}</td>
                                            <td class="p-2.5 text-foreground">{{ $edu->completion_status ?? 'Completed' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="p-4 text-center text-muted-foreground">{{ __('No academic records listed.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <!-- Tab: Employment -->
                @if($activeDossierTab === 'employment')
                    <div class="space-y-3">
                        <div class="flex items-center justify-between pb-1 border-b border-border">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-foreground">{{ __('Work & Professional Experience') }}</span>
                                @php
                                    $isViewingWorking = (bool) ($viewingStudent->studentProfile?->is_working_professional ?? false);
                                @endphp
                                <x-ui.badge variant="{{ $isViewingWorking ? 'primary' : 'secondary' }}" class="text-[10px]">
                                    {{ $isViewingWorking ? __('Working Professional (Required)') : __('Non-Working / Full-time Student (Optional)') }}
                                </x-ui.badge>
                            </div>
                            <button
                                type="button"
                                wire:click="edit({{ $viewingStudent->id }}, 'employment')"
                                class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                            >
                                <x-icon name="edit" class="w-3.5 h-3.5" />
                                {{ __('Manage Employment') }}
                            </button>
                        </div>
                        <div class="border border-border rounded-lg overflow-hidden text-xs">
                            <table class="w-full text-left">
                                <thead class="bg-muted/50 border-b border-border">
                                    <tr>
                                        <th class="p-2.5">{{ __('Employer') }}</th>
                                        <th class="p-2.5">{{ __('Designation') }}</th>
                                        <th class="p-2.5">{{ __('Nature') }}</th>
                                        <th class="p-2.5">{{ __('Period') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse($viewingStudent->studentEmployments as $emp)
                                        <tr>
                                            <td class="p-2.5 font-semibold text-foreground">{{ $emp->employer_name }}</td>
                                            <td class="p-2.5 text-foreground">{{ $emp->designation }}</td>
                                            <td class="p-2.5 text-foreground">{{ $emp->nature_of_employment ?? 'Full-time' }}</td>
                                            <td class="p-2.5 text-foreground">
                                                {{ $emp->from_date ? $emp->from_date->format('M Y') : '—' }} to {{ $emp->is_current ? 'Present' : ($emp->to_date ? $emp->to_date->format('M Y') : '—') }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="p-4 text-center text-muted-foreground">
                                                {{ $isViewingWorking ? __('No employment history recorded. Required for working professional status.') : __('No employment history recorded. (Optional)') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <!-- Tab: Other UPSC Details -->
                @if($activeDossierTab === 'other')
                    @php $other = $viewingStudent->studentOtherDetails; @endphp
                    <div class="space-y-4">
                        <div class="flex items-center justify-between pb-1 border-b border-border">
                            <span class="text-xs font-bold text-foreground">{{ __('Civil Services Examination Details') }}</span>
                            <button
                                type="button"
                                wire:click="edit({{ $viewingStudent->id }}, 'other')"
                                class="text-xs text-primary hover:underline font-semibold flex items-center gap-1 cursor-pointer"
                            >
                                <x-icon name="edit" class="w-3.5 h-3.5" />
                                {{ __('Edit UPSC Details') }}
                            </button>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 bg-muted/40 rounded-xl text-xs">
                            <div>
                                <span class="text-muted-foreground">{{ __('Attempts Given') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $other?->attempts_already_given ?? 0 }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Attempts Left') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $other?->attempts_left ?? 'N/A' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Optional Subject') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $other?->optional_subject ?? 'Not Selected' }}</div>
                            </div>
                            <div>
                                <span class="text-muted-foreground">{{ __('Year of Appearing') }}</span>
                                <div class="font-semibold mt-0.5 text-foreground">{{ $other?->year_of_appearing ?? '—' }}</div>
                            </div>
                        </div>

                        <!-- Attempts History Table in Dossier -->
                        <div class="border border-border rounded-lg overflow-hidden text-xs">
                            <div class="bg-muted/40 p-2.5 px-3 border-b border-border font-bold text-foreground flex items-center gap-1.5">
                                <x-icon name="award" class="w-4 h-4 text-primary" />
                                {{ __('Candidate UPSC CSE Attempts Log') }}
                            </div>
                            <table class="w-full text-left">
                                <thead class="bg-muted/50 border-b border-border">
                                    <tr>
                                        <th class="p-2.5">{{ __('Exam Year') }}</th>
                                        <th class="p-2.5">{{ __('Roll Number') }}</th>
                                        <th class="p-2.5">{{ __('Prelims Cleared') }}</th>
                                        <th class="p-2.5">{{ __('Mains Cleared') }}</th>
                                        <th class="p-2.5">{{ __('Interview Cleared') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @forelse($viewingStudent->upscAttempts as $att)
                                        <tr>
                                            <td class="p-2.5 font-bold font-mono text-foreground">{{ $att->exam_year }}</td>
                                            <td class="p-2.5 font-mono text-foreground">{{ $att->roll_number ?? '—' }}</td>
                                            <td class="p-2.5">
                                                <x-ui.badge variant="{{ $att->prelims_cleared ? 'success' : 'secondary' }}" size="sm">
                                                    {{ $att->prelims_cleared ? __('Yes') : __('No') }}
                                                </x-ui.badge>
                                            </td>
                                            <td class="p-2.5">
                                                <x-ui.badge variant="{{ $att->mains_cleared ? 'success' : 'secondary' }}" size="sm">
                                                    {{ $att->mains_cleared ? __('Yes') : __('No') }}
                                                </x-ui.badge>
                                            </td>
                                            <td class="p-2.5">
                                                <x-ui.badge variant="{{ $att->interview_cleared ? 'success' : 'secondary' }}" size="sm">
                                                    {{ $att->interview_cleared ? __('Yes') : __('No') }}
                                                </x-ui.badge>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="p-3 text-center text-muted-foreground">{{ __('No individual UPSC attempt records logged.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <!-- Tab: Documents -->
                @if($activeDossierTab === 'documents')
                    <div class="border border-border rounded-lg overflow-hidden text-xs">
                        <table class="w-full text-left">
                            <thead class="bg-muted/50 border-b border-border">
                                <tr>
                                    <th class="p-2.5">{{ __('Document Type') }}</th>
                                    <th class="p-2.5">{{ __('Source Type') }}</th>
                                    <th class="p-2.5">{{ __('File Name / Link') }}</th>
                                    <th class="p-2.5">{{ __('Verification') }}</th>
                                    <th class="p-2.5 text-right">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($viewingStudent->studentDocuments as $doc)
                                    <tr>
                                        <td class="p-2.5 font-semibold text-foreground capitalize">{{ str_replace('_', ' ', $doc->document_type ?? $doc->type) }}</td>
                                        <td class="p-2.5">
                                            @if($doc->file_url)
                                                <x-ui.badge variant="secondary" size="sm" class="font-mono">
                                                    {{ __('External / OTR URL') }}
                                                </x-ui.badge>
                                            @else
                                                <x-ui.badge variant="outline" size="sm">
                                                    {{ __('Uploaded File') }}
                                                </x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="p-2.5">
                                            @php $targetUrl = $doc->file_url ?: ($doc->file_path ? Storage::url($doc->file_path) : '#'); @endphp
                                            <a href="{{ $targetUrl }}" target="_blank" class="text-primary hover:underline font-mono text-[11px] inline-flex items-center gap-1">
                                                <span>{{ $doc->file_name ?? basename($targetUrl) }}</span>
                                                <x-icon name="external-link" class="w-3 h-3" />
                                            </a>
                                        </td>
                                        <td class="p-2.5">
                                            @if($doc->verified)
                                                <x-ui.badge variant="success" size="sm">{{ __('Verified') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="warning" size="sm">{{ __('Unverified') }}</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="p-2.5 text-right">
                                            <button
                                                type="button"
                                                wire:click="toggleDocumentVerified({{ $doc->id }})"
                                                class="text-xs text-primary hover:underline cursor-pointer"
                                            >
                                                {{ $doc->verified ? __('Mark Unverified') : __('Mark Verified') }}
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="p-4 text-center text-muted-foreground">{{ __('No uploaded or linked documents on record.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

                <!-- Tab: Complete Chronological Enrollment History -->
                @if($activeDossierTab === 'enrollments')
                    <div class="border border-border rounded-lg overflow-hidden text-xs">
                        <table class="w-full text-left">
                            <thead class="bg-muted/50 border-b border-border">
                                <tr>
                                    <th class="p-2.5">{{ __('Enrollment #') }}</th>
                                    <th class="p-2.5">{{ __('Batch') }}</th>
                                    <th class="p-2.5">{{ __('Course Programme') }}</th>
                                    <th class="p-2.5">{{ __('Section') }}</th>
                                    <th class="p-2.5">{{ __('Roll #') }}</th>
                                    <th class="p-2.5">{{ __('Fee Status') }}</th>
                                    <th class="p-2.5">{{ __('Status') }}</th>
                                    <th class="p-2.5 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($viewingStudent->enrollments as $enr)
                                    <tr>
                                        <td class="p-2.5 font-mono font-medium text-foreground">
                                            <div>{{ $enr->enrollment_no }}</div>
                                            @if($enr->application_no)
                                                <div class="text-[10px] text-muted-foreground font-normal">App: {{ $enr->application_no }}</div>
                                            @endif
                                        </td>
                                        <td class="p-2.5 font-semibold text-foreground">{{ $enr->batch?->name }}</td>
                                        <td class="p-2.5 text-foreground">{{ $enr->course?->name }} ({{ $enr->course?->code }})</td>
                                        <td class="p-2.5 text-foreground">{{ $enr->section?->name ?? '—' }}</td>
                                        <td class="p-2.5 font-mono text-foreground">{{ $enr->roll_no ?? '—' }}</td>
                                        <td class="p-2.5 capitalize font-medium text-foreground">{{ $enr->fee_status }}</td>
                                        <td class="p-2.5">
                                            @php
                                                $enrBadgeVariant = match($enr->status) {
                                                    'active' => 'success',
                                                    'completed' => 'primary',
                                                    'withdrawn' => 'warning',
                                                    'discontinued', 'cancelled', 'suspended', 'dropped' => 'destructive',
                                                    default => 'secondary',
                                                };
                                            @endphp
                                            <div class="space-y-0.5">
                                                <x-ui.badge variant="{{ $enrBadgeVariant }}" size="sm">
                                                    {{ ucfirst($enr->status) }}
                                                </x-ui.badge>
                                                @if($enr->reinstated_at)
                                                    <div class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">
                                                        {{ __('Reinstated') }} {{ $enr->reinstated_at->format('d M Y') }}
                                                    </div>
                                                @endif
                                                @if($enr->cancellation_reason && ! $enr->reinstated_at)
                                                    <div class="text-[10px] text-muted-foreground truncate max-w-[130px]" title="{{ $enr->cancellation_reason }}">
                                                        {{ $enr->cancellation_reason }}
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="p-2.5 text-right">
                                            @if($enr->canBeReinstated())
                                                @if(auth()->user()?->can('enrollments.reinstate') || auth()->user()?->can('students.edit'))
                                                    <button
                                                        type="button"
                                                        wire:click="openReinstateModal({{ $enr->id }})"
                                                        class="inline-flex items-center gap-1 px-2 py-1 rounded bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800 text-[11px] font-medium transition-colors cursor-pointer"
                                                        title="{{ __('Revert Discontinuation / Reinstate Student to Course') }}"
                                                    >
                                                        <x-icon name="rotate-ccw" class="w-3 h-3" />
                                                        <span>{{ __('Reinstate') }}</span>
                                                    </button>
                                                @endif
                                            @else
                                                <span class="text-[11px] text-muted-foreground">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="p-4 text-center text-muted-foreground">{{ __('No enrollment history found for this student.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

                <!-- Tab: Profile Correction Requests -->
                @if($activeDossierTab === 'requests')
                    <div class="border border-border rounded-lg overflow-hidden text-xs">
                        <table class="w-full text-left">
                            <thead class="bg-muted/50 border-b border-border">
                                <tr>
                                    <th class="p-2.5">{{ __('Request #') }}</th>
                                    <th class="p-2.5">{{ __('Section') }}</th>
                                    <th class="p-2.5">{{ __('Reason') }}</th>
                                    <th class="p-2.5">{{ __('Requested Date') }}</th>
                                    <th class="p-2.5">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($viewingStudent->profileUpdateRequests as $req)
                                    <tr>
                                        <td class="p-2.5 font-mono font-medium text-foreground">{{ $req->request_no }}</td>
                                        <td class="p-2.5 capitalize text-foreground">{{ $req->section }}</td>
                                        <td class="p-2.5 text-muted-foreground">{{ $req->reason }}</td>
                                        <td class="p-2.5 text-muted-foreground">{{ $req->created_at->format('d M Y') }}</td>
                                        <td class="p-2.5">
                                            @php
                                                $reqBadgeVariant = match($req->status) {
                                                    'approved' => 'success',
                                                    'rejected' => 'destructive',
                                                    default => 'warning',
                                                };
                                            @endphp
                                            <x-ui.badge variant="{{ $reqBadgeVariant }}" size="sm">{{ ucfirst($req->status) }}</x-ui.badge>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="p-4 text-center text-muted-foreground">{{ __('No profile correction requests submitted.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="flex items-center justify-between pt-2 border-t border-border">
                    @if($viewingStudent)
                        <a
                            href="{{ route('admin.students.information-sheet', ['student_id' => $viewingStudent->id]) }}"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-md bg-emerald-600 hover:bg-emerald-700 text-white transition-colors shadow-xs"
                        >
                            <x-icon name="file-text" class="w-3.5 h-3.5"/>
                            {{ __('Detailed Information Sheet') }}
                        </a>
                    @else
                        <div></div>
                    @endif
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-dossier-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Quick Course Enrollment Modal -->
    <x-ui.modal
        name="quick-enroll-modal"
        max-width="max-w-lg"
        :title="__('Enroll Student in Programme')"
        :description="$quickEnrollStudent ? __('Enroll :name into an academic batch course with optional section assignment.', ['name' => $quickEnrollStudent->name]) : ''"
    >
        @if($quickEnrollStudent)
            <form wire:submit="saveQuickEnroll" class="space-y-4">
                <div class="p-3 bg-muted/40 rounded-lg text-xs flex justify-between">
                    <div>
                        <span class="text-muted-foreground block">{{ __('Candidate:') }}</span>
                        <span class="font-bold text-foreground">{{ $quickEnrollStudent->name }}</span>
                    </div>
                    <div class="text-right">
                        <span class="text-muted-foreground block">{{ __('Student ID:') }}</span>
                        <span class="font-mono font-bold text-primary">{{ $quickEnrollStudent->student_id ?? 'Will Auto-Generate' }}</span>
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Target Batch *') }}</label>
                    <select wire:model.live="quickEnrollForm.batch_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        <option value="">{{ __('Select Academic Batch') }}</option>
                        @foreach($this->batches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                        @endforeach
                    </select>
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Target Course *') }}</label>
                    <select wire:model.live="quickEnrollForm.course_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                        <option value="">{{ __('Select Course Programme') }}</option>
                        @foreach($this->courses as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                        @endforeach
                    </select>
                </div>

                <!-- Optional Section Selection -->
                @if($this->quickEnrollSections->isNotEmpty())
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">{{ __('Section Assignment (Optional)') }}</label>
                        <select wire:model="quickEnrollForm.section_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                            <option value="">{{ __('No Section / General Cohort') }}</option>
                            @foreach($this->quickEnrollSections as $sec)
                                <option value="{{ $sec->id }}">
                                    {{ $sec->name }} (Code: {{ $sec->code }}, Cap: {{ $sec->enrolled_count }}/{{ $sec->capacity }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input
                        wire:model="quickEnrollForm.roll_no"
                        label="{{ __('Roll Number (Optional)') }}"
                        placeholder="{{ __('e.g. CC-2026-A-01') }}"
                    />

                    <x-ui.input
                        wire:model="quickEnrollForm.application_no"
                        label="{{ __('Application No. (Optional)') }}"
                        placeholder="{{ __('e.g. APP-2026-001') }}"
                    />
                </div>

                <div>
                    <x-ui.input
                        wire:model="quickEnrollForm.fee_amount"
                        type="number"
                        step="0.01"
                        label="{{ __('Course Fee (INR) *') }}"
                        required
                    />
                </div>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Fee Status *') }}</label>
                    <select wire:model="quickEnrollForm.fee_status" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <option value="paid">{{ __('Paid') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="partial">{{ __('Partial') }}</option>
                        <option value="exempted">{{ __('Exempted (Scholarship / Reserved)') }}</option>
                    </select>
                </div>

                @can('students.edit')
                <label class="flex items-start gap-2 pt-1 cursor-pointer text-xs">
                    <input
                        type="checkbox"
                        wire:model="quickEnrollForm.override_restrictions"
                        class="mt-0.5 rounded border-input text-amber-600 focus:ring-amber-500"
                    />
                    <span class="text-muted-foreground">
                        {{ __('Administrator Override: Bypass admission restrictions if candidate previously enrolled.') }}
                    </span>
                </label>
                @endcan

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('quick-enroll-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="default">
                        {{ __('Confirm Enrollment') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>

    <!-- Student Import Modal -->
    <x-ui.modal
        name="student-import-modal"
        max-width="max-w-lg"
        :title="__('Bulk Import Students')"
        :description="__('Upload candidate registry files in Excel (.xlsx) or CSV format.')"
    >
        <div class="space-y-4">
            <div class="p-3 bg-muted/40 rounded-lg text-xs space-y-2 border border-border">
                <span class="font-bold text-foreground block">{{ __('Download Sample Template:') }}</span>
                <div class="flex gap-2">
                    <x-ui.button size="xs" variant="outline" wire:click="downloadTemplate('csv')" class="gap-1">
                        <x-icon name="file-text" class="w-3.5 h-3.5"/>
                        {{ __('CSV Template') }}
                    </x-ui.button>
                    <x-ui.button size="xs" variant="outline" wire:click="downloadTemplate('xlsx')" class="gap-1">
                        <x-icon name="file-spreadsheet" class="w-3.5 h-3.5"/>
                        {{ __('Excel (.xlsx) Template') }}
                    </x-ui.button>
                </div>
            </div>

            <div class="space-y-1">
                <label class="text-xs font-semibold text-foreground">{{ __('Choose File *') }}</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".csv,.xlsx,.xls,.txt"
                    class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs file:mr-2 file:border-0 file:bg-transparent file:text-xs file:font-medium"
                />
            </div>

            @if($importSummary)
                <div class="p-3 rounded-lg border text-xs {{ $importSummary['failed'] > 0 ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-300' : 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-300' }}">
                    <div class="font-bold">
                        {{ __('Import Results: :success created/updated, :failed failed.', ['success' => $importSummary['success'], 'failed' => $importSummary['failed']]) }}
                    </div>
                    @if(!empty($importSummary['errors']))
                        <ul class="mt-2 list-disc list-inside space-y-1 text-rose-600 dark:text-rose-400 max-h-32 overflow-y-auto">
                            @foreach(array_slice($importSummary['errors'], 0, 5) as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-import-modal')">
                    {{ __('Close') }}
                </x-ui.button>
                <x-ui.button variant="default" wire:click="processImport">
                    {{ __('Run Import') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Student Remove Photo Confirmation Modal -->
    <x-ui.modal
        name="student-avatar-remove-modal"
        max-width="max-w-md"
        :title="__('Remove Student Profile Photo')"
        :description="__('Remove this candidate\'s profile photo and revert to standard monogram initials.')"
    >
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-destructive/10 text-destructive shrink-0">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ __('Are you sure you want to remove this profile photo? The image file will be permanently deleted from server storage.') }}
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-avatar-remove-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" type="button" wire:click="removeStudentAvatar">
                    <x-icon name="trash-2" class="w-4 h-4 mr-1" />
                    {{ __('Remove Photo') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Student Delete Modal -->
    <x-ui.modal
        name="student-delete-modal"
        max-width="max-w-md"
        :title="__('Soft Delete Student')"
        :description="__('Archive student profile and preserve academic audit history.')"
    >
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-full bg-destructive/10 text-destructive">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ __('Are you sure you want to delete this student record? The student\'s unique ID and past enrollment records will be retained in history.') }}
                </p>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-delete-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" wire:click="deleteSelected">
                    {{ __('Delete Student') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Disciplinary Action Modal -->
    <x-ui.modal
        name="student-discipline-modal"
        max-width="max-w-xl"
        :title="__('Disciplinary Sanction & Ban Management')"
        :description="__('Execute formal institute disciplinary order, ban, or course disbarment with official findings.')"
    >
        @if($disciplineStudent)
            <form wire:submit="saveDisciplinaryAction" class="space-y-4">
                <div class="p-3 bg-muted/40 rounded-lg text-xs space-y-1 border border-border">
                    <div class="flex items-center justify-between font-semibold">
                        <span class="text-foreground font-bold">{{ $disciplineStudent->name }}</span>
                        <span class="font-mono text-primary">ID: {{ $disciplineStudent->student_id ?: 'Pending' }}</span>
                    </div>
                    <div class="text-muted-foreground">
                        {{ $disciplineStudent->email }} &bull; {{ $disciplineStudent->phone ?? 'No phone' }}
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">{{ __('Sanction Type *') }}</label>
                        <select wire:model.live="disciplineForm.action_type" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                            <option value="temporary_suspension">{{ __('Temporary Suspension (Defined Period)') }}</option>
                            <option value="lifetime_ban">{{ __('Lifetime Ban / Permanent Blacklist') }}</option>
                            <option value="course_disbarment">{{ __('Course Disbarment (Specific Programme)') }}</option>
                            <option value="admission_cancelled">{{ __('Admission Revocation / Cancelled') }}</option>
                            <option value="warning">{{ __('Official Formal Warning') }}</option>
                        </select>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-foreground">{{ __('Reason Category *') }}</label>
                        <select wire:model="disciplineForm.reason_category" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50" required>
                            <option value="misconduct">{{ __('Gross Misconduct / Behavioral Breach') }}</option>
                            <option value="malpractice">{{ __('Academic Dishonesty / Exam Malpractice') }}</option>
                            <option value="attendance_shortage">{{ __('Chronic Absenteeism / Below Mandatory Attendance') }}</option>
                            <option value="fee_default">{{ __('Non-Payment of Course Fees') }}</option>
                            <option value="indiscipline">{{ __('Classroom Disruption / Non-compliance') }}</option>
                            <option value="fake_documents">{{ __('Falsification of Admission Documents') }}</option>
                            <option value="criminal_charges">{{ __('Legal / Criminal Proceeding') }}</option>
                            <option value="other">{{ __('Other Institutional Ground') }}</option>
                        </select>
                    </div>

                    <x-ui.input
                        wire:model="disciplineForm.order_number"
                        label="{{ __('Disciplinary Order Number *') }}"
                        placeholder="{{ __('e.g. ORD-2026-1049') }}"
                        required
                    />

                    <x-ui.input
                        wire:model="disciplineForm.order_date"
                        type="date"
                        label="{{ __('Order Issue Date *') }}"
                        required
                    />

                    <x-ui.input
                        wire:model="disciplineForm.start_date"
                        type="date"
                        label="{{ __('Sanction Effective From *') }}"
                        required
                    />

                    @if($disciplineForm['action_type'] !== 'lifetime_ban')
                        <x-ui.input
                            wire:model="disciplineForm.end_date"
                            type="date"
                            label="{{ __('Sanction End Date (Leave blank if open)') }}"
                        />
                    @else
                        <div class="p-2.5 bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900 rounded-md text-xs text-rose-700 dark:text-rose-400 flex items-center gap-2">
                            <x-icon name="ban" class="w-4 h-4 shrink-0" />
                            <span>{{ __('Indefinite Lifetime Sanction: No end date applies.') }}</span>
                        </div>
                    @endif

                    @if($disciplineStudent->activeEnrollments->isNotEmpty())
                        <div class="sm:col-span-2 space-y-1">
                            <label class="text-xs font-semibold text-foreground">
                                {{ __('Specific Course Scope') }}
                                <span class="text-muted-foreground font-normal">({{ __('Optional — leaves all courses if blank') }})</span>
                            </label>
                            <select wire:model="disciplineForm.course_id" class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
                                <option value="">{{ __('-- Entire Institute / All Programmes --') }}</option>
                                @foreach($disciplineStudent->activeEnrollments as $enr)
                                    <option value="{{ $enr->course_id }}">
                                        {{ $enr->course?->code }} - {{ $enr->course?->name }} ({{ $enr->batch?->name }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>

                <x-ui.textarea
                    wire:model="disciplineForm.findings"
                    label="{{ __('Inquiry Findings & Order Justification *') }}"
                    placeholder="{{ __('Record committee findings, evidence, breach details, and sanctions issued...') }}"
                    rows="3"
                    required
                />

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground">{{ __('Upload Signed Order Document (Optional, PDF / Image max 10MB)') }}</label>
                    <input
                        type="file"
                        wire:model="disciplineOrderCopy"
                        accept=".pdf,.png,.jpg,.jpeg"
                        class="h-9 w-full rounded-md border border-input bg-card px-3 text-xs file:mr-2 file:border-0 file:bg-transparent file:text-xs file:font-medium"
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-discipline-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="destructive">
                        {{ __('Confirm Disciplinary Order') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>

    <!-- Lift Sanction Modal -->
    <x-ui.modal
        name="student-lift-sanction-modal"
        max-width="max-w-lg"
        :title="__('Lift Disciplinary Sanction / Ban')"
        :description="__('Revoke an active sanction with formal competent authority order.')"
    >
        @if($liftSanctionStudent && $activeSanctionToLift)
            <form wire:submit="liftSanction" class="space-y-4">
                <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-lg text-xs space-y-1.5">
                    <div class="flex items-center justify-between font-bold text-amber-900 dark:text-amber-200">
                        <span>{{ $liftSanctionStudent->name }}</span>
                        <span class="font-mono text-primary">{{ $activeSanctionToLift->action_code }}</span>
                    </div>
                    <div class="text-amber-800 dark:text-amber-300">
                        <strong>{{ __('Sanction:') }}</strong> {{ ucfirst(str_replace('_', ' ', $activeSanctionToLift->action_type)) }}
                        &bull; <strong>{{ __('Order #:') }}</strong> {{ $activeSanctionToLift->order_number }}
                    </div>
                    <p class="text-amber-700 dark:text-amber-400 italic">"{{ $activeSanctionToLift->findings }}"</p>
                </div>

                <div class="space-y-3">
                    <x-ui.input
                        wire:model="liftSanctionForm.revocation_order_no"
                        label="{{ __('Revocation Order / Reference Number *') }}"
                        placeholder="{{ __('e.g. REV-2026-102') }}"
                        required
                    />

                    <x-ui.textarea
                        wire:model="liftSanctionForm.revocation_reason"
                        label="{{ __('Reason for Revocation / Clearance Notes *') }}"
                        placeholder="{{ __('State the basis for lifting the ban (e.g. apology accepted, penalty served, appeal upheld, cleared by enquiry committee)...') }}"
                        rows="3"
                        required
                    />
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-lift-sanction-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="default">
                        {{ __('Lift Sanction & Restore Eligibility') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>

    <!-- Revert / Reinstate Student Enrollment Modal -->
    <x-ui.modal
        name="student-reinstate-enrollment-modal"
        max-width="max-w-lg"
        :title="__('Reinstate Student to Active Course Enrollment')"
        :description="__('Systematically revert course withdrawal or discontinuation and restore student to active standing.')"
    >
        @if($reinstateEnrollment)
            <form wire:submit="executeReinstate" class="space-y-4">
                <div class="p-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs space-y-1.5">
                    <div class="flex items-center justify-between font-bold text-emerald-900 dark:text-emerald-200">
                        <span>{{ $reinstateEnrollment->user?->name }}</span>
                        <span class="font-mono text-emerald-700 dark:text-emerald-400">{{ $reinstateEnrollment->enrollment_no }}</span>
                    </div>
                    <div class="text-emerald-800 dark:text-emerald-300">
                        <strong>{{ __('Course:') }}</strong> {{ $reinstateEnrollment->course?->name }} ({{ $reinstateEnrollment->course?->code }})
                        &bull; <strong>{{ __('Batch:') }}</strong> {{ $reinstateEnrollment->batch?->name }}
                        @if($reinstateEnrollment->section)
                            &bull; <strong>{{ __('Section:') }}</strong> {{ $reinstateEnrollment->section->name }}
                        @endif
                    </div>
                    <div class="flex items-center gap-2 pt-1">
                        <span class="text-muted-foreground">{{ __('Current State:') }}</span>
                        <x-ui.badge variant="destructive" size="sm">
                            {{ ucfirst($reinstateEnrollment->status) }}
                        </x-ui.badge>
                        @if($reinstateEnrollment->discontinued_at)
                            <span class="text-[11px] text-muted-foreground">({{ __('Since') }} {{ $reinstateEnrollment->discontinued_at->format('d M Y') }})</span>
                        @endif
                    </div>
                    @if($reinstateEnrollment->cancellation_reason)
                        <div class="p-2 bg-background/80 rounded border border-emerald-100 dark:border-emerald-900 text-[11px] text-muted-foreground mt-1">
                            <strong>{{ __('Previous Cancellation / Withdrawal Grounds:') }}</strong>
                            <p class="italic text-foreground mt-0.5">{{ $reinstateEnrollment->cancellation_reason }}</p>
                        </div>
                    @endif
                </div>

                <div class="space-y-3">
                    <x-ui.input
                        wire:model="reinstateForm.order_number"
                        label="{{ __('Reinstatement Order / Decision Reference # (Optional)') }}"
                        placeholder="{{ __('e.g. ORD-REINSTATE-2026-004') }}"
                    />

                    <x-ui.textarea
                        wire:model="reinstateForm.reason"
                        label="{{ __('Administrative Reinstatement Grounds / Notes *') }}"
                        placeholder="{{ __('Specify the reason for reinstatement (e.g. medical recovery verified, student resumed classes with Director approval, withdrawal was mistakenly processed)...') }}"
                        rows="3"
                        required
                    />

                    <div class="p-2.5 bg-muted/40 rounded-lg border border-border">
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input
                                type="checkbox"
                                wire:model="reinstateForm.activate_user"
                                class="rounded border-input text-primary focus:ring-ring"
                            />
                            <span class="font-medium text-foreground">
                                {{ __('Also restore student account status to "Active" (if currently inactive or discontinued)') }}
                            </span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-reinstate-enrollment-modal')">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="default" class="bg-emerald-600 hover:bg-emerald-700 text-white">
                        <x-icon name="rotate-ccw" class="w-4 h-4 mr-1.5" />
                        {{ __('Confirm Reinstatement to Active') }}
                    </x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>
</div>
