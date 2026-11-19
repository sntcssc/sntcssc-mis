<?php

use App\Concerns\RenamesUploads;
use App\Helpers\CategoryData;
use App\Helpers\CourseData;
use App\Helpers\LocationData;
use App\Helpers\UpscData;
use App\Models\AuditLog;
use App\Models\CourseWithdrawalRequest;
use App\Models\Enrollment;
use App\Models\OptionalSubject;
use App\Models\OptionalSubjectChangeRequest;
use App\Models\ProfileUpdateRequest;
use App\Models\Setting;
use App\Models\StudentAddress;
use App\Models\StudentDisciplinaryAction;
use App\Models\StudentDocument;
use App\Models\StudentEducation;
use App\Models\StudentEmployment;
use App\Models\StudentOtherDetail;
use App\Models\StudentProfile;
use App\Models\UpscAttempt;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\StudentIdCardService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Student Dashboard')] class extends Component {
    use RenamesUploads, WithFileUploads, WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'account'; // account, academics, profile, address, education, employment, upsc, documents, requests

    // Change Request Modal State
    public string $requestModalSection = ''; // account, profile, address, education, employment, upsc, documents
    public string $requestReason = '';
    public $requestProofFile = null;

    // Item ID for modular multi-record updates (education, employment, etc.)
    public ?int $selectedItemId = null;

    // View Request Diff Modal State
    public ?int $viewingRequestId = null;
    public ?ProfileUpdateRequest $viewingRequest = null;

    // Withdraw Request Confirmation State
    public ?int $withdrawingRequestId = null;
    public ?ProfileUpdateRequest $withdrawingRequest = null;

    // Course Withdrawal / Discontinuation State
    public ?int $withdrawalEnrollmentId = null;
    public ?Enrollment $withdrawalEnrollment = null;
    public array $withdrawalForm = [
        'request_type' => 'withdrawal',
        'reason_category' => 'personal',
        'reason_description' => '',
    ];
    public $withdrawalDocument = null;

    // Optional Subject Change Request State
    public bool $showOptionalSubjectChangeModal = false;
    public string $requestedOptionalSubject = '';
    public string $optionalChangeReason = '';

    // Working Professional Status
    public bool $isWorkingProfessional = false;

    // Form states for change requests
    public array $accountRequest = [
        'first_name' => '',
        'last_name' => '',
        'phone' => '',
        'whatsapp_no' => '',
        'dob' => '',
        'gender' => 'male',
        'tenth_roll' => '',
        'id_type' => 'Aadhaar',
        'id_number' => '',
    ];

    public array $profileRequest = [
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
        'is_working_professional' => false,
        'father_occupation' => '',
        'mother_occupation' => '',
        'family_income' => '',
        'highest_edu' => 'Graduation',
        'school_language' => 'English',
        'upsc_community' => '',
        'activities' => '',
        'hobbies' => '',
    ];

    public array $addressRequest = [
        'permanent_state' => 'West Bengal',
        'permanent_district' => '',
        'permanent_subdistrict' => '',
        'permanent_address_line1' => '',
        'permanent_address_line2' => '',
        'permanent_post_office' => '',
        'permanent_police_station' => '',
        'permanent_pin_code' => '',
        'same_as_permanent' => false,
        'correspondence_state' => 'West Bengal',
        'correspondence_district' => '',
        'correspondence_subdistrict' => '',
        'correspondence_address_line1' => '',
        'correspondence_address_line2' => '',
        'correspondence_post_office' => '',
        'correspondence_police_station' => '',
        'correspondence_pin_code' => '',
    ];

    public array $educationRequest = [
        'education_id' => null,
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

    public array $employmentRequest = [
        'employment_id' => null,
        'employer_name' => '',
        'designation' => '',
        'nature_of_employment' => 'Full-time',
        'location' => '',
        'from_date' => '',
        'to_date' => '',
        'is_current' => false,
        'description' => '',
    ];

    public array $upscRequest = [
        'attempts_already_given' => 0,
        'attempts_left' => null,
        'optional_subject' => '',
        'year_of_appearing' => null,
        'add_attempt_record' => false,
        'attempt_id' => null,
        'exam_year' => '',
        'roll_number' => '',
        'prelims_cleared' => false,
        'mains_cleared' => false,
        'interview_cleared' => false,
    ];

    public array $documentRequest = [
        'document_type' => 'Photo',
        'custom_document_type' => '',
        'document_file' => null,
        'file_url' => '',
        'description' => '',
    ];

    public function updatedProfileRequestCategory($value): void
    {
        $this->profileRequest['subcaste'] = '';
        if (! in_array($value, ['OBC', 'OBCA', 'OBCB'], true)) {
            $this->profileRequest['creamy_layer'] = '';
        }
    }

    public function updatedAddressRequestPermanentState($value): void
    {
        $districts = LocationData::getDistrictsByState($value);
        if (! in_array($this->addressRequest['permanent_district'] ?? '', $districts, true)) {
            $this->addressRequest['permanent_district'] = $districts[0] ?? '';
        }
    }

    public function updatedAddressRequestCorrespondenceState($value): void
    {
        $districts = LocationData::getDistrictsByState($value);
        if (! in_array($this->addressRequest['correspondence_district'] ?? '', $districts, true)) {
            $this->addressRequest['correspondence_district'] = $districts[0] ?? '';
        }
    }

    #[Computed]
    public function completeness(): array
    {
        return $this->student->getProfileCompleteness();
    }

    #[Computed]
    public function allowIncompleteAccess(): bool
    {
        return (bool) Setting::get(
            'system.allow_incomplete_student_dashboard',
            Setting::get('students.allow_incomplete_dashboard', true)
        );
    }

    public function mount(): void
    {
        // Enforce valid active tab
        if (! in_array($this->activeTab, ['account', 'academics', 'profile', 'address', 'education', 'employment', 'upsc', 'documents', 'requests'], true)) {
            $this->activeTab = 'account';
        }

        $this->isWorkingProfessional = (bool) ($this->student->studentProfile?->is_working_professional);
    }

    #[Computed]
    public function student(): User
    {
        return User::with([
            'studentProfile',
            'studentAddresses',
            'studentEducations',
            'studentEmployments',
            'studentOtherDetails',
            'upscAttempts',
            'studentDocuments',
            'activeEnrollments.batch',
            'activeEnrollments.course',
            'activeEnrollments.section',
            'activeEnrollments.pendingWithdrawalRequest',
            'enrollments.batch',
            'enrollments.course',
            'enrollments.section',
            'enrollments.pendingWithdrawalRequest',
            'profileUpdateRequests' => fn ($q) => $q->latest(),
        ])->findOrFail(auth()->id());
    }

    #[Computed]
    public function permanentAddress(): ?StudentAddress
    {
        return $this->student->studentAddresses->firstWhere('address_type', 'Permanent');
    }

    #[Computed]
    public function correspondenceAddress(): ?StudentAddress
    {
        return $this->student->studentAddresses->firstWhere('address_type', 'Correspondence')
            ?? $this->permanentAddress;
    }

    #[Computed]
    public function pendingRequestsCount(): int
    {
        return $this->student->profileUpdateRequests->where('status', 'pending')->count();
    }

    #[Computed]
    public function activeEnrollmentsList()
    {
        return $this->student->activeEnrollments;
    }

    #[Computed]
    public function allEnrollmentsList()
    {
        return $this->student->enrollments;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function openRequestModal(string $section, ?int $itemId = null): void
    {
        $this->requestModalSection = $section;
        $this->requestReason = '';
        $this->requestProofFile = null;
        $this->selectedItemId = $itemId;

        $user = $this->student;
        $profile = $user->studentProfile;
        $permAddress = $this->permanentAddress;
        $corrAddress = $this->correspondenceAddress;
        $other = $user->studentOtherDetails;

        if ($section === 'account') {
            $this->accountRequest = [
                'first_name' => $user->first_name ?? '',
                'last_name' => $user->last_name ?? '',
                'phone' => $user->phone ?? '',
                'whatsapp_no' => $user->whatsapp_no ?? '',
                'dob' => $user->dob ? $user->dob->format('Y-m-d') : '',
                'gender' => $user->gender ?? 'male',
                'tenth_roll' => $user->tenth_roll ?? '',
                'id_type' => $user->id_type ?? 'Aadhaar',
                'id_number' => $user->id_number ?? '',
            ];
        } elseif ($section === 'profile') {
            $this->profileRequest = [
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
                'pwd_percentage' => $profile?->pwd_percentage ?? 0,
                'pwbd_cert_no' => $profile?->pwbd_cert_no ?? '',
                'pwbd_issued_date' => $profile?->pwbd_issued_date ? $profile->pwbd_issued_date->format('Y-m-d') : '',
                'pwbd_issued_by' => $profile?->pwbd_issued_by ?? '',
                'occupation' => $profile?->occupation ?? '',
                'is_working_professional' => (bool) ($profile?->is_working_professional ?? false),
                'father_occupation' => $profile?->father_occupation ?? '',
                'mother_occupation' => $profile?->mother_occupation ?? '',
                'family_income' => $profile?->family_income ? (string) $profile->family_income : '',
                'highest_edu' => $profile?->highest_edu ?? 'Graduation',
                'school_language' => $profile?->school_language ?? 'English',
                'upsc_community' => $profile?->upsc_community ?? '',
                'activities' => $profile?->activities ?? '',
                'hobbies' => $profile?->hobbies ?? '',
            ];
        } elseif ($section === 'address') {
            $isSame = false;
            if ($permAddress && $corrAddress && $permAddress->id !== $corrAddress->id) {
                $isSame = ($permAddress->address_line1 === $corrAddress->address_line1 && $permAddress->pin_code === $corrAddress->pin_code);
            }
            $this->addressRequest = [
                'permanent_state' => $permAddress->state ?? 'West Bengal',
                'permanent_district' => $permAddress->district ?? '',
                'permanent_subdistrict' => $permAddress->subdistrict ?? '',
                'permanent_address_line1' => $permAddress->address_line1 ?? '',
                'permanent_address_line2' => $permAddress->address_line2 ?? '',
                'permanent_post_office' => $permAddress->post_office ?? '',
                'permanent_police_station' => $permAddress->police_station ?? '',
                'permanent_pin_code' => $permAddress->pin_code ?? '',
                'same_as_permanent' => $isSame,
                'correspondence_state' => $corrAddress->state ?? 'West Bengal',
                'correspondence_district' => $corrAddress->district ?? '',
                'correspondence_subdistrict' => $corrAddress->subdistrict ?? '',
                'correspondence_address_line1' => $corrAddress->address_line1 ?? '',
                'correspondence_address_line2' => $corrAddress->address_line2 ?? '',
                'correspondence_post_office' => $corrAddress->post_office ?? '',
                'correspondence_police_station' => $corrAddress->police_station ?? '',
                'correspondence_pin_code' => $corrAddress->pin_code ?? '',
            ];
        } elseif ($section === 'education') {
            if ($itemId) {
                $edu = StudentEducation::where('user_id', $user->id)->findOrFail($itemId);
                $this->educationRequest = [
                    'education_id' => $edu->id,
                    'level' => $edu->level ?? '10th',
                    'board_university' => $edu->board_university ?? '',
                    'institute' => $edu->institute ?? '',
                    'stream_subject' => $edu->stream_subject ?? '',
                    'passing_year' => $edu->passing_year,
                    'total_marks' => $edu->total_marks,
                    'marks_obtained' => $edu->marks_obtained,
                    'percentage' => $edu->percentage ? (string) $edu->percentage : '',
                    'cgpa' => $edu->cgpa ? (string) $edu->cgpa : '',
                    'division' => $edu->division ?? '',
                    'completion_status' => $edu->completion_status ?? 'Completed',
                    'is_highest' => (bool) $edu->is_highest,
                ];
            } else {
                $this->educationRequest = [
                    'education_id' => null,
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
            }
        } elseif ($section === 'employment') {
            if ($itemId) {
                $emp = StudentEmployment::where('user_id', $user->id)->findOrFail($itemId);
                $this->employmentRequest = [
                    'employment_id' => $emp->id,
                    'employer_name' => $emp->employer_name ?? '',
                    'designation' => $emp->designation ?? '',
                    'nature_of_employment' => $emp->nature_of_employment ?? 'Full-time',
                    'location' => $emp->location ?? '',
                    'from_date' => $emp->from_date ? $emp->from_date->format('Y-m-d') : '',
                    'to_date' => $emp->to_date ? $emp->to_date->format('Y-m-d') : '',
                    'is_current' => (bool) $emp->is_current,
                    'description' => $emp->description ?? '',
                ];
            } else {
                $this->employmentRequest = [
                    'employment_id' => null,
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
        } elseif ($section === 'upsc') {
            $this->upscRequest = [
                'attempts_already_given' => $other->attempts_already_given ?? 0,
                'attempts_left' => $other->attempts_left ?? null,
                'optional_subject' => $other->optional_subject ?? '',
                'year_of_appearing' => $other->year_of_appearing ?? null,
                'add_attempt_record' => false,
                'attempt_id' => null,
                'exam_year' => '',
                'roll_number' => '',
                'prelims_cleared' => false,
                'mains_cleared' => false,
                'interview_cleared' => false,
            ];
        } elseif ($section === 'documents') {
            $this->documentRequest = [
                'document_type' => 'Photo',
                'custom_document_type' => '',
                'document_file' => null,
                'description' => '',
            ];
        }

        $this->dispatch('modal-open', name: 'student-request-modal');
    }

    public function submitCorrectionRequest(): void
    {
        $this->validate([
            'requestReason' => ['required', 'string', 'min:5', 'max:1000'],
            'requestProofFile' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'requestReason.required' => __('Please provide a reason or justification for this change request.'),
            'requestReason.min' => __('Justification must be at least 5 characters.'),
        ]);

        $user = $this->student;
        $profile = $user->studentProfile;
        $section = $this->requestModalSection;
        $proofPath = null;

        if ($this->requestProofFile) {
            $fileName = $this->generateFileName('proof_'.$section, $this->requestProofFile, $user->id);
            $proofPath = $this->requestProofFile->storeAs('profile_proofs/'.$user->id, $fileName, 'public');
        }

        $payload = [];
        $currentData = [];

        if ($section === 'account') {
            $this->validate([
                'accountRequest.first_name' => ['required', 'string', 'max:100'],
                'accountRequest.last_name' => ['nullable', 'string', 'max:100'],
                'accountRequest.phone' => ['required', 'string', 'max:20'],
                'accountRequest.whatsapp_no' => ['nullable', 'string', 'max:20'],
                'accountRequest.dob' => ['nullable', 'date'],
                'accountRequest.gender' => ['required', 'in:male,female,other'],
                'accountRequest.tenth_roll' => ['nullable', 'string', 'max:100'],
                'accountRequest.id_type' => ['nullable', 'string', 'max:50'],
                'accountRequest.id_number' => ['nullable', 'string', 'max:100'],
            ]);

            $fullName = trim($this->accountRequest['first_name'].' '.($this->accountRequest['last_name'] ?? ''));
            $payload = array_merge($this->accountRequest, ['name' => $fullName]);
            $currentData = [
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'whatsapp_no' => $user->whatsapp_no,
                'dob' => $user->dob ? $user->dob->format('Y-m-d') : null,
                'gender' => $user->gender,
                'tenth_roll' => $user->tenth_roll,
                'id_type' => $user->id_type,
                'id_number' => $user->id_number,
            ];
        } elseif ($section === 'profile') {
            $this->validate([
                'profileRequest.father_name' => ['nullable', 'string', 'max:150'],
                'profileRequest.mother_name' => ['nullable', 'string', 'max:150'],
                'profileRequest.category' => ['required', 'string', 'max:30'],
                'profileRequest.distance' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
                'profileRequest.subcaste' => ['nullable', 'string', 'max:150'],
                'profileRequest.creamy_layer' => ['nullable', 'string', 'max:20'],
                'profileRequest.cert_no' => ['nullable', 'string', 'max:100'],
                'profileRequest.issued_date' => ['nullable', 'date'],
                'profileRequest.issued_by' => ['nullable', 'string', 'max:150'],
                'profileRequest.is_pwbd' => ['boolean'],
                'profileRequest.pwbd_type' => ['nullable', 'string', 'max:150'],
                'profileRequest.pwd_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
                'profileRequest.pwbd_cert_no' => ['nullable', 'string', 'max:100'],
                'profileRequest.pwbd_issued_date' => ['nullable', 'date'],
                'profileRequest.pwbd_issued_by' => ['nullable', 'string', 'max:150'],
                'profileRequest.occupation' => ['nullable', 'string', 'max:150'],
                'profileRequest.is_working_professional' => ['boolean'],
                'profileRequest.father_occupation' => ['nullable', 'string', 'max:150'],
                'profileRequest.mother_occupation' => ['nullable', 'string', 'max:150'],
                'profileRequest.family_income' => ['nullable', 'numeric', 'min:0'],
                'profileRequest.highest_edu' => ['nullable', 'string', 'max:100'],
                'profileRequest.school_language' => ['nullable', 'string', 'max:50'],
                'profileRequest.upsc_community' => ['nullable', 'string', 'max:150'],
                'profileRequest.activities' => ['nullable', 'string', 'max:1000'],
                'profileRequest.hobbies' => ['nullable', 'string', 'max:1000'],
            ]);

            $payload = $this->profileRequest;
            $currentData = [
                'father_name' => $profile?->father_name ?? null,
                'mother_name' => $profile?->mother_name ?? null,
                'category' => $profile?->category ?? 'UR',
                'distance' => $profile?->distance !== null ? (string) $profile->distance : null,
                'subcaste' => $profile?->subcaste ?? null,
                'creamy_layer' => $profile?->creamy_layer ?? null,
                'cert_no' => $profile?->cert_no ?? null,
                'issued_date' => $profile?->issued_date ? $profile->issued_date->format('Y-m-d') : null,
                'issued_by' => $profile?->issued_by ?? null,
                'is_pwbd' => (bool) ($profile?->is_pwbd ?? false),
                'pwbd_type' => $profile?->pwbd_type ?? null,
                'pwd_percentage' => $profile?->pwd_percentage ?? 0,
                'pwbd_cert_no' => $profile?->pwbd_cert_no ?? null,
                'pwbd_issued_date' => $profile?->pwbd_issued_date ? $profile->pwbd_issued_date->format('Y-m-d') : null,
                'pwbd_issued_by' => $profile?->pwbd_issued_by ?? null,
                'occupation' => $profile?->occupation ?? null,
                'is_working_professional' => (bool) ($profile?->is_working_professional ?? false),
                'father_occupation' => $profile?->father_occupation ?? null,
                'mother_occupation' => $profile?->mother_occupation ?? null,
                'family_income' => $profile?->family_income ? (string) $profile->family_income : null,
                'highest_edu' => $profile?->highest_edu ?? null,
                'school_language' => $profile?->school_language ?? null,
                'upsc_community' => $profile?->upsc_community ?? null,
                'activities' => $profile?->activities ?? null,
                'hobbies' => $profile?->hobbies ?? null,
            ];
        } elseif ($section === 'address') {
            $this->validate([
                'addressRequest.permanent_state' => ['required', 'string', 'max:100'],
                'addressRequest.permanent_district' => ['required', 'string', 'max:100'],
                'addressRequest.permanent_subdistrict' => ['nullable', 'string', 'max:100'],
                'addressRequest.permanent_post_office' => ['nullable', 'string', 'max:100'],
                'addressRequest.permanent_police_station' => ['nullable', 'string', 'max:100'],
                'addressRequest.permanent_pin_code' => ['required', 'string', 'max:15'],
                'addressRequest.permanent_address_line1' => ['required', 'string', 'max:255'],
                'addressRequest.permanent_address_line2' => ['nullable', 'string', 'max:255'],
                'addressRequest.same_as_permanent' => ['boolean'],
                'addressRequest.correspondence_state' => ['nullable', 'string', 'max:100'],
                'addressRequest.correspondence_district' => ['nullable', 'string', 'max:100'],
                'addressRequest.correspondence_subdistrict' => ['nullable', 'string', 'max:100'],
                'addressRequest.correspondence_post_office' => ['nullable', 'string', 'max:100'],
                'addressRequest.correspondence_police_station' => ['nullable', 'string', 'max:100'],
                'addressRequest.correspondence_pin_code' => ['nullable', 'string', 'max:15'],
                'addressRequest.correspondence_address_line1' => ['nullable', 'string', 'max:255'],
                'addressRequest.correspondence_address_line2' => ['nullable', 'string', 'max:255'],
            ]);

            if ($this->addressRequest['same_as_permanent']) {
                $this->addressRequest['correspondence_state'] = $this->addressRequest['permanent_state'];
                $this->addressRequest['correspondence_district'] = $this->addressRequest['permanent_district'];
                $this->addressRequest['correspondence_subdistrict'] = $this->addressRequest['permanent_subdistrict'];
                $this->addressRequest['correspondence_address_line1'] = $this->addressRequest['permanent_address_line1'];
                $this->addressRequest['correspondence_address_line2'] = $this->addressRequest['permanent_address_line2'];
                $this->addressRequest['correspondence_post_office'] = $this->addressRequest['permanent_post_office'];
                $this->addressRequest['correspondence_police_station'] = $this->addressRequest['permanent_police_station'];
                $this->addressRequest['correspondence_pin_code'] = $this->addressRequest['permanent_pin_code'];
            }

            $payload = $this->addressRequest;
            $perm = $this->permanentAddress;
            $corr = $this->correspondenceAddress;
            $currentData = [
                'permanent_state' => $perm->state ?? null,
                'permanent_district' => $perm->district ?? null,
                'permanent_subdistrict' => $perm->subdistrict ?? null,
                'permanent_address_line1' => $perm->address_line1 ?? null,
                'permanent_address_line2' => $perm->address_line2 ?? null,
                'permanent_post_office' => $perm->post_office ?? null,
                'permanent_police_station' => $perm->police_station ?? null,
                'permanent_pin_code' => $perm->pin_code ?? null,
                'correspondence_state' => $corr->state ?? null,
                'correspondence_district' => $corr->district ?? null,
                'correspondence_subdistrict' => $corr->subdistrict ?? null,
                'correspondence_address_line1' => $corr->address_line1 ?? null,
                'correspondence_address_line2' => $corr->address_line2 ?? null,
                'correspondence_post_office' => $corr->post_office ?? null,
                'correspondence_police_station' => $corr->police_station ?? null,
                'correspondence_pin_code' => $corr->pin_code ?? null,
            ];
        } elseif ($section === 'education') {
            $this->validate([
                'educationRequest.level' => ['required', 'string', 'max:50'],
                'educationRequest.board_university' => ['nullable', 'string', 'max:150'],
                'educationRequest.institute' => ['nullable', 'string', 'max:150'],
                'educationRequest.stream_subject' => ['nullable', 'string', 'max:100'],
                'educationRequest.passing_year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 5)],
                'educationRequest.total_marks' => ['nullable', 'integer', 'min:0'],
                'educationRequest.marks_obtained' => ['nullable', 'integer', 'min:0'],
                'educationRequest.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'educationRequest.cgpa' => ['nullable', 'numeric', 'min:0', 'max:10'],
                'educationRequest.division' => ['nullable', 'string', 'max:50'],
                'educationRequest.completion_status' => ['nullable', 'string', 'max:50'],
                'educationRequest.is_highest' => ['boolean'],
            ]);

            $payload = $this->educationRequest;
            if ($this->selectedItemId) {
                $edu = StudentEducation::where('user_id', $user->id)->find($this->selectedItemId);
                if ($edu) {
                    $currentData = [
                        'education_id' => $edu->id,
                        'level' => $edu->level,
                        'board_university' => $edu->board_university,
                        'institute' => $edu->institute,
                        'stream_subject' => $edu->stream_subject,
                        'passing_year' => $edu->passing_year,
                        'total_marks' => $edu->total_marks,
                        'marks_obtained' => $edu->marks_obtained,
                        'percentage' => $edu->percentage ? (string) $edu->percentage : null,
                        'cgpa' => $edu->cgpa ? (string) $edu->cgpa : null,
                        'division' => $edu->division,
                        'completion_status' => $edu->completion_status,
                        'is_highest' => (bool) $edu->is_highest,
                    ];
                }
            } else {
                $currentData = ['education_record' => 'New Record Request'];
            }
        } elseif ($section === 'employment') {
            $isWorking = (bool) ($this->student->studentProfile?->is_working_professional);

            if ($isWorking) {
                $this->validate([
                    'employmentRequest.employer_name' => ['required', 'string', 'max:150'],
                    'employmentRequest.designation' => ['required', 'string', 'max:100'],
                    'employmentRequest.nature_of_employment' => ['required', 'string', 'max:50'],
                    'employmentRequest.location' => ['nullable', 'string', 'max:100'],
                    'employmentRequest.from_date' => ['required', 'date'],
                    'employmentRequest.to_date' => ['nullable', 'date'],
                    'employmentRequest.is_current' => ['boolean'],
                    'employmentRequest.description' => ['nullable', 'string', 'max:1000'],
                ]);
            } else {
                $this->validate([
                    'employmentRequest.employer_name' => ['required', 'string', 'max:150'],
                    'employmentRequest.designation' => ['nullable', 'string', 'max:100'],
                    'employmentRequest.nature_of_employment' => ['nullable', 'string', 'max:50'],
                    'employmentRequest.location' => ['nullable', 'string', 'max:100'],
                    'employmentRequest.from_date' => ['nullable', 'date'],
                    'employmentRequest.to_date' => ['nullable', 'date'],
                    'employmentRequest.is_current' => ['boolean'],
                    'employmentRequest.description' => ['nullable', 'string', 'max:1000'],
                ]);
            }

            $payload = $this->employmentRequest;
            if ($this->selectedItemId) {
                $emp = StudentEmployment::where('user_id', $user->id)->find($this->selectedItemId);
                if ($emp) {
                    $currentData = [
                        'employment_id' => $emp->id,
                        'employer_name' => $emp->employer_name,
                        'designation' => $emp->designation,
                        'nature_of_employment' => $emp->nature_of_employment,
                        'location' => $emp->location,
                        'from_date' => $emp->from_date ? $emp->from_date->format('Y-m-d') : null,
                        'to_date' => $emp->to_date ? $emp->to_date->format('Y-m-d') : null,
                        'is_current' => (bool) $emp->is_current,
                        'description' => $emp->description,
                    ];
                }
            } else {
                $currentData = ['employment_record' => 'New Record Request'];
            }
        } elseif ($section === 'upsc') {
            $this->validate([
                'upscRequest.optional_subject' => ['nullable', 'string', 'max:100'],
                'upscRequest.attempts_already_given' => ['nullable', 'integer', 'min:0', 'max:20'],
                'upscRequest.attempts_left' => ['nullable', 'integer', 'min:0', 'max:20'],
                'upscRequest.year_of_appearing' => ['nullable', 'integer', 'min:2000', 'max:'.(date('Y') + 10)],
                'upscRequest.add_attempt_record' => ['boolean'],
                'upscRequest.exam_year' => ['nullable', 'integer', 'min:1990', 'max:'.(date('Y') + 1)],
                'upscRequest.roll_number' => ['nullable', 'string', 'max:50'],
                'upscRequest.prelims_cleared' => ['boolean'],
                'upscRequest.mains_cleared' => ['boolean'],
                'upscRequest.interview_cleared' => ['boolean'],
            ]);

            $payload = $this->upscRequest;
            $other = $user->studentOtherDetails;
            $currentData = [
                'optional_subject' => $other->optional_subject ?? null,
                'attempts_already_given' => $other->attempts_already_given ?? 0,
                'attempts_left' => $other->attempts_left ?? null,
                'year_of_appearing' => $other->year_of_appearing ?? null,
            ];
        } elseif ($section === 'documents') {
            $this->validate([
                'documentRequest.document_type' => ['required', 'string', 'max:100'],
                'documentRequest.custom_document_type' => ['nullable', 'string', 'max:100'],
                'documentRequest.document_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
                'documentRequest.file_url' => ['nullable', 'url', 'max:1000'],
                'documentRequest.description' => ['nullable', 'string', 'max:500'],
            ]);

            if (! $this->documentRequest['document_file'] && empty($this->documentRequest['file_url'])) {
                $this->addError('documentRequest.document_file', __('Please upload a file or provide an external document URL.'));

                return;
            }

            $docType = $this->documentRequest['document_type'];
            if ($docType === 'Custom') {
                $docType = trim($this->documentRequest['custom_document_type']) ?: 'Custom Document';
            }

            $docFilePath = null;
            $docFileName = null;
            if ($this->documentRequest['document_file']) {
                $docFile = $this->documentRequest['document_file'];
                $docFileName = $this->generateFileName($docType, $docFile, $user->id);
                $docFilePath = $docFile->storeAs('student_documents/'.$user->id, $docFileName, 'public');
            }

            $payload = [
                'type' => $docType,
                'document_type' => $docType,
                'file_name' => $docFileName,
                'file_path' => $docFilePath,
                'file_url' => $this->documentRequest['file_url'] ?: null,
                'description' => $this->documentRequest['description'] ?? '',
            ];
            $currentData = [
                'document_request' => 'New Document: '.$docType,
            ];
        }

        // Compute changes diff
        $changes = [];
        foreach ($payload as $key => $val) {
            $old = $currentData[$key] ?? null;
            if ((string) $val !== (string) $old) {
                $changes[$key] = [
                    'from' => $old,
                    'to' => $val,
                ];
            }
        }

        DB::beginTransaction();
        try {
            // Create ProfileUpdateRequest
            $req = ProfileUpdateRequest::create([
                'user_id' => $user->id,
                'section' => $section,
                'payload' => $payload,
                'current_data' => $currentData,
                'changes' => $changes,
                'reason' => $this->requestReason,
                'document_proof' => $proofPath,
                'status' => 'pending',
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'profile_update_requested',
                'auditable_type' => ProfileUpdateRequest::class,
                'auditable_id' => $req->id,
                'description' => "Student {$user->name} ({$user->student_id}) requested correction for section: {$section}",
                'new_values' => $changes,
            ]);

            DB::commit();

            Toast::dispatch($this, 'success', __('Profile correction request #:no has been submitted for admin approval.', ['no' => $req->request_no]));
            $this->dispatch('modal-close', name: 'student-request-modal');
            $this->activeTab = 'requests';
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('An error occurred while submitting your request. Please try again.'));
        }
    }

    public function updateWorkingProfessionalStatus(bool $isWorking): void
    {
        $this->isWorkingProfessional = $isWorking;
        $user = auth()->user();

        DB::beginTransaction();
        try {
            StudentProfile::updateOrCreate(
                ['user_id' => $user->id],
                ['is_working_professional' => $isWorking]
            );

            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'working_professional_status_updated',
                'auditable_type' => StudentProfile::class,
                'description' => "Student {$user->name} ({$user->student_id}) updated working professional status to: " . ($isWorking ? 'Yes (Working Professional)' : 'No (Full-time Student / Aspirant)'),
            ]);

            DB::commit();

            unset($this->student, $this->completeness);

            $msg = $isWorking
                ? __('Status updated: Marked as a working professional. Employment history is now required.')
                : __('Status updated: Marked as a full-time student / aspirant. Employment history is optional.');
            Toast::dispatch($this, 'success', $msg);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('Failed to update working professional status. Please try again.'));
        }
    }

    public function openViewDiff(int $id): void
    {
        $this->viewingRequestId = $id;
        $this->viewingRequest = ProfileUpdateRequest::where('user_id', auth()->id())->findOrFail($id);
        $this->dispatch('modal-open', name: 'view-diff-modal');
    }

    public function openWithdrawConfirmation(int $id): void
    {
        $this->withdrawingRequestId = $id;
        $this->withdrawingRequest = ProfileUpdateRequest::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->find($id);

        if (! $this->withdrawingRequest) {
            Toast::dispatch($this, 'error', __('Correction request not found or cannot be withdrawn.'));

            return;
        }

        $this->dispatch('modal-open', name: 'confirm-withdraw-modal');
    }

    public function cancelWithdraw(): void
    {
        $this->withdrawingRequestId = null;
        $this->withdrawingRequest = null;
        $this->dispatch('modal-close', name: 'confirm-withdraw-modal');
    }

    public function executeWithdrawRequest(): void
    {
        if (! $this->withdrawingRequestId) {
            return;
        }

        DB::beginTransaction();
        try {
            $req = ProfileUpdateRequest::where('user_id', auth()->id())
                ->where('status', 'pending')
                ->lockForUpdate()
                ->findOrFail($this->withdrawingRequestId);

            $user = auth()->user();

            AuditLog::create([
                'user_id' => $user->id,
                'event' => 'profile_update_withdrawn',
                'auditable_type' => ProfileUpdateRequest::class,
                'auditable_id' => $req->id,
                'description' => "Student {$user->name} ({$user->student_id}) withdrew correction request #{$req->request_no} for section: {$req->section}",
            ]);

            $req->delete();

            DB::commit();

            Toast::dispatch($this, 'success', __('Correction request withdrawn successfully.'));
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            Toast::dispatch($this, 'error', __('An error occurred while withdrawing the request. Please try again.'));
        } finally {
            $this->cancelWithdraw();
        }
    }

    public function withdrawRequest(int $id): void
    {
        $this->openWithdrawConfirmation($id);
    }

    public function openWithdrawalModal(int $enrollmentId): void
    {
        $this->withdrawalEnrollmentId = $enrollmentId;
        $this->withdrawalEnrollment = Enrollment::with(['course', 'batch'])->where('user_id', auth()->id())->findOrFail($enrollmentId);
        $this->withdrawalForm = [
            'request_type' => 'withdrawal',
            'reason_category' => 'personal',
            'reason_description' => '',
        ];
        $this->withdrawalDocument = null;
        $this->dispatch('modal-open', name: 'course-withdrawal-modal');
    }

    public function submitWithdrawalRequest(): void
    {
        $this->validate([
            'withdrawalForm.request_type' => 'required|in:withdrawal,discontinuation',
            'withdrawalForm.reason_category' => 'required|string|max:100',
            'withdrawalForm.reason_description' => 'required|string|min:10|max:1500',
            'withdrawalDocument' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        if (! $this->withdrawalEnrollmentId) {
            return;
        }

        $enr = Enrollment::where('user_id', auth()->id())->findOrFail($this->withdrawalEnrollmentId);

        $existingPending = CourseWithdrawalRequest::where('enrollment_id', $enr->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            Toast::dispatch($this, 'error', __('A withdrawal request for this course is already pending review.'));
            return;
        }

        DB::beginTransaction();
        try {
            $docPath = null;
            if ($this->withdrawalDocument) {
                $docPath = $this->withdrawalDocument->store('withdrawal_documents/'.auth()->id(), 'public');
            }

            $req = CourseWithdrawalRequest::create([
                'student_id' => auth()->id(),
                'course_id' => $enr->course_id,
                'batch_id' => $enr->batch_id,
                'enrollment_id' => $enr->id,
                'request_type' => $this->withdrawalForm['request_type'],
                'reason_category' => $this->withdrawalForm['reason_category'],
                'reason_description' => $this->withdrawalForm['reason_description'],
                'supporting_document_path' => $docPath,
                'status' => 'pending',
                'requested_at' => now(),
            ]);

            AuditLog::record(
                action: 'course_withdrawal.submitted',
                details: [
                    'request_id' => $req->id,
                    'request_number' => $req->request_number,
                    'course_id' => $enr->course_id,
                    'course_name' => $enr->course?->name,
                    'batch_name' => $enr->batch?->name,
                    'reason_category' => $this->withdrawalForm['reason_category'],
                ],
                userId: auth()->id(),
                type: 'course_withdrawal',
                status: 'success'
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Your course withdrawal request (:no) has been submitted for administrative review.', [
                'no' => $req->request_number,
            ]));

            $this->closeWithdrawalModal();
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to submit withdrawal request: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function closeWithdrawalModal(): void
    {
        $this->withdrawalEnrollmentId = null;
        $this->withdrawalEnrollment = null;
        $this->withdrawalDocument = null;
        $this->resetValidation();
        $this->dispatch('modal-close', name: 'course-withdrawal-modal');
    }

    public function clearWithdrawalDocument(): void
    {
        $this->withdrawalDocument = null;
    }

    #[Computed]
    public function courseWithdrawalHistory()
    {
        return CourseWithdrawalRequest::with(['course', 'batch', 'enrollment', 'reviewer'])
            ->where('user_id', auth()->id())
            ->orderByDesc('id')
            ->get();
    }

    public function cancelWithdrawalRequest(int $requestId): void
    {
        $req = CourseWithdrawalRequest::where('user_id', auth()->id())
            ->where('status', 'pending')
            ->findOrFail($requestId);

        DB::beginTransaction();
        try {
            $req->update([
                'status' => 'withdrawn',
                'admin_notes' => 'Cancelled by student on '.now()->format('d M Y, h:i A'),
            ]);

            AuditLog::record(
                action: 'course_withdrawal.cancelled_by_student',
                details: [
                    'request_id' => $req->id,
                    'request_number' => $req->request_number,
                ],
                userId: auth()->id(),
                type: 'course_withdrawal',
                status: 'success'
            );

            DB::commit();
            Toast::dispatch($this, 'success', __('Withdrawal request cancelled successfully.'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', __('Failed to cancel request: :msg', ['msg' => $e->getMessage()]));
        }
    }
    public function openOptionalSubjectChangeModal(): void
    {
        $this->requestedOptionalSubject = '';
        $this->optionalChangeReason = '';
        $this->resetValidation();
        $this->showOptionalSubjectChangeModal = true;
    }

    public function submitOptionalSubjectChangeRequest(): void
    {
        $other = $this->student->studentOtherDetails;
        $currentSubject = $other?->optional_subject;

        $this->validate([
            'requestedOptionalSubject' => ['required', 'string', 'max:150'],
            'optionalChangeReason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        if ($currentSubject && $currentSubject === $this->requestedOptionalSubject) {
            $this->addError('requestedOptionalSubject', __('The requested subject must be different from your currently selected optional subject.'));
            return;
        }

        $existingPending = OptionalSubjectChangeRequest::where('user_id', $this->student->id)
            ->pending()
            ->first();

        if ($existingPending) {
            Toast::dispatch($this, 'warning', __('You already have a pending change request (:no) under review.', ['no' => $existingPending->request_no]));
            $this->showOptionalSubjectChangeModal = false;
            return;
        }

        $subModel = OptionalSubject::where('name', $this->requestedOptionalSubject)->first();

        $req = OptionalSubjectChangeRequest::create([
            'request_no' => OptionalSubjectChangeRequest::generateRequestNumber(),
            'user_id' => $this->student->id,
            'current_subject' => $currentSubject,
            'requested_subject' => $this->requestedOptionalSubject,
            'optional_subject_id' => $subModel?->id,
            'reason' => $this->optionalChangeReason,
            'status' => OptionalSubjectChangeRequest::STATUS_PENDING,
            'created_by' => $this->student->id,
        ]);

        AuditLogService::log(
            event: 'optional_subject_change_requested',
            description: "Student submitted optional subject change request #{$req->request_no} for '{$this->requestedOptionalSubject}'.",
            auditable: $req
        );

        $this->showOptionalSubjectChangeModal = false;
        Toast::dispatch($this, 'success', __('Your optional subject change request (:no) has been submitted for administrative review.', ['no' => $req->request_no]));
    }

    #[Computed]
    public function pendingOptionalSubjectRequest(): ?OptionalSubjectChangeRequest
    {
        return OptionalSubjectChangeRequest::where('user_id', $this->student->id)
            ->pending()
            ->latest('id')
            ->first();
    }

    #[Computed]
    public function optionalSubjectRequestsList()
    {
        return OptionalSubjectChangeRequest::where('user_id', $this->student->id)
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function activeOptionalSubjects()
    {
        return OptionalSubject::active()->orderBy('name')->get();
    }

    #[Computed]
    public function isIdCardDownloadEnabled(): bool
    {
        return StudentIdCardService::isDownloadEnabledForStudents();
    }
}; ?>

<div class="space-y-6">
    <!-- Official Institutional Disciplinary Sanction Notice (if banned, suspended, or course barred) -->
    @php
        $activeSanction = $this->student->activeDisciplinaryAction();
    @endphp
    @if($activeSanction || $this->student->isBanned() || $this->student->isSuspended() || session('disciplinary_warning'))
        <div class="p-4 bg-rose-500/10 border-2 border-rose-500/40 rounded-xl flex items-start gap-3.5 text-xs text-rose-900 dark:text-rose-200 shadow-sm">
            <x-icon name="alert-octagon" class="w-6 h-6 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
            <div class="flex-1 space-y-1.5">
                <div class="font-bold text-sm text-rose-800 dark:text-rose-300 flex items-center gap-2 flex-wrap">
                    <span>{{ __('Official Institutional Disciplinary Sanction Active') }}</span>
                    @if($activeSanction)
                        <x-ui.badge variant="destructive" class="text-[10px]">
                            {{ $activeSanction->isLifetime() ? __('Lifetime Ban') : ucfirst(str_replace('_', ' ', $activeSanction->action_type)) }}
                        </x-ui.badge>
                        <span class="font-mono text-[11px] text-muted-foreground">{{ __('Order #: ') }}{{ $activeSanction->order_number }}</span>
                    @endif
                </div>
                @if($activeSanction)
                    <p class="leading-relaxed font-medium">
                        <strong>{{ __('Grounds:') }}</strong> {{ ucfirst(str_replace('_', ' ', $activeSanction->reason_category)) }} &bull;
                        {{ $activeSanction->findings }}
                    </p>
                    <div class="text-[11px] text-rose-700/80 dark:text-rose-400/80">
                        {{ __('Effective Date:') }} {{ $activeSanction->start_date ? $activeSanction->start_date->format('d M Y') : 'Immediate' }}
                        @if($activeSanction->isLifetime())
                            &bull; <strong>{{ __('Duration: Lifetime / Indefinite Blacklist') }}</strong>
                        @elseif($activeSanction->end_date)
                            &bull; {{ __('Sanction End Date:') }} {{ $activeSanction->end_date->format('d M Y') }}
                        @endif
                    </div>
                @elseif(session('disciplinary_warning'))
                    <p class="leading-relaxed">{{ session('disciplinary_warning') }}</p>
                @endif
                <p class="text-[11px] text-rose-800 dark:text-rose-300 pt-1 border-t border-rose-200 dark:border-rose-900/40">
                    {{ __('Your student portal and academic learning privileges are restricted under this administrative order. For appeals, review petitions, or official clarifications, please contact the SNTCSSC Administration & Admissions office.') }}
                </p>
            </div>
        </div>
    @endif

    <!-- Mandatory Incomplete Profile Warning Flash (if redirected by middleware) -->
    @if(session('profile_incomplete_warning'))
        <div class="p-4 bg-amber-500/10 border-2 border-amber-500/40 rounded-xl flex items-start gap-3.5 text-xs text-amber-900 dark:text-amber-200 shadow-sm animate-pulse">
            <x-icon name="alert-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
            <div class="flex-1 space-y-1">
                <div class="font-bold text-sm text-amber-800 dark:text-amber-300 flex items-center gap-2">
                    <span>{{ __('Action Required: Profile Incomplete') }}</span>
                    <x-ui.badge variant="warning" class="text-[10px]">{{ __('Access Restricted') }}</x-ui.badge>
                </div>
                <p class="leading-relaxed">{{ session('profile_incomplete_warning') }}</p>
                <p class="text-[11px] text-amber-700/80 dark:text-amber-400/80">
                    {{ __('Please review and submit details for the highlighted sections below. Once complete or reviewed by an administrator, full portal access will be granted.') }}
                </p>
            </div>
        </div>
    @endif

    <!-- Top Welcome & Status Banner -->
    <div class="bg-card border border-border rounded-xl p-5 sm:p-6 shadow-xs relative overflow-hidden">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="w-16 h-16 rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-primary text-xl font-bold uppercase shadow-inner">
                        {{ substr($this->student->first_name ?: $this->student->name, 0, 1) }}{{ substr($this->student->last_name ?: '', 0, 1) }}
                    </div>
                    <span class="absolute bottom-0 right-0 w-4 h-4 rounded-full bg-emerald-500 border-2 border-card" title="{{ __('Active Account') }}"></span>
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-xl sm:text-2xl font-bold text-foreground">
                            {{ $this->student->name }}
                        </h1>
                        <x-ui.badge variant="secondary" class="font-mono text-xs">
                            {{ $this->student->student_id ?: __('ID: Pending') }}
                        </x-ui.badge>
                        <x-ui.badge variant="outline" class="font-mono text-xs gap-1" title="{{ __('Universal Registration Number') }}">
                            <span class="text-muted-foreground">{{ __('URN:') }}</span>
                            @if(!empty($this->student->urn))
                                <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                            @else
                                <span class="text-muted-foreground/70 italic text-[11px]">{{ __('Not Assigned') }}</span>
                            @endif
                        </x-ui.badge>
                        <x-ui.badge variant="success" class="text-xs">
                            {{ __('Student') }}
                        </x-ui.badge>
                    </div>
                    <p class="text-xs sm:text-sm text-muted-foreground mt-0.5">
                        {{ $this->student->email }} • {{ $this->student->phone ?: __('No phone') }}
                    </p>
                </div>
            </div>

            <!-- Quick Action & Request Status Badges -->
            <div class="flex flex-wrap items-center gap-2 self-start md:self-auto">
                <a
                    href="{{ route('student.id-card.index') }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-card text-foreground hover:bg-muted text-xs font-semibold shadow-2xs transition-colors"
                >
                    <x-icon name="id-card" class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" />
                    <span>{{ __('Student ID Card') }}</span>
                </a>

                @if($this->pendingRequestsCount > 0)
                    <button
                        type="button"
                        wire:click="setTab('requests')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 text-xs font-semibold hover:bg-amber-500/20 transition-colors cursor-pointer"
                    >
                        <span class="h-2 w-2 rounded-full bg-amber-500 animate-ping"></span>
                        {{ __(':count Request(s) Under Review', ['count' => $this->pendingRequestsCount]) }}
                    </button>
                @endif
                <x-ui.button size="sm" variant="outline" wire:click="setTab('requests')" class="gap-1.5 text-xs">
                    <x-icon name="clock" class="w-3.5 h-3.5 text-muted-foreground" />
                    {{ __('Request History') }}
                </x-ui.button>
            </div>
        </div>

        <!-- Academic & Enrollment Pill Highlights -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mt-6 pt-5 border-t border-border/60">
            <div class="p-2.5 rounded-lg bg-muted/40 border border-border/50">
                <div class="text-[11px] font-medium text-muted-foreground">{{ __('Category') }}</div>
                <div class="text-sm font-bold text-foreground mt-0.5">
                    {{ $this->student->studentProfile?->category ?: 'UR' }}
                    @if($this->student->studentProfile?->is_pwbd)
                        <span class="text-xs text-amber-600 font-semibold">(PwBD)</span>
                    @endif
                </div>
            </div>

            <button
                type="button"
                wire:click="setTab('academics')"
                class="p-2.5 rounded-lg bg-muted/40 border border-border/50 text-left hover:bg-muted/70 transition-colors cursor-pointer group"
                title="{{ __('View Academic Enrolments & Course Details') }}"
            >
                <div class="text-[11px] font-medium text-muted-foreground flex items-center justify-between">
                    <span>{{ __('Active Enrollments') }}</span>
                    <x-icon name="arrow-up-right" class="w-3 h-3 text-muted-foreground group-hover:text-primary transition-colors" />
                </div>
                <div class="text-sm font-bold text-foreground mt-0.5">
                    {{ $this->activeEnrollmentsList->count() }} {{ __('Course(s)') }}
                </div>
            </button>

            <!-- Optional Subject Highlight Pill -->
            @php $otherHighlight = $this->student->studentOtherDetails; @endphp
            <div class="p-2.5 rounded-lg bg-purple-500/10 border border-purple-500/20 col-span-2 sm:col-span-1">
                <div class="text-[11px] font-semibold text-purple-700 dark:text-purple-300 flex items-center justify-between">
                    <span>{{ __('Optional Subject') }}</span>
                    <button
                        type="button"
                        wire:click="openOptionalSubjectChangeModal"
                        class="text-[10px] text-purple-600 dark:text-purple-400 hover:underline font-bold"
                        title="{{ __('Request to update or change optional subject') }}"
                    >
                        {{ __('Change') }}
                    </button>
                </div>
                <div class="text-xs font-bold text-foreground mt-0.5 truncate" title="{{ $otherHighlight?->optional_subject ?: __('Not Selected') }}">
                    {{ $otherHighlight?->optional_subject ?: __('Not Selected') }}
                </div>
            </div>

            <div class="p-2.5 rounded-lg bg-muted/40 border border-border/50">
                <div class="text-[11px] font-medium text-muted-foreground">{{ __('Qualifications') }}</div>
                <div class="text-sm font-bold text-foreground mt-0.5">
                    {{ $this->student->studentEducations->count() }} {{ __('Recorded') }}
                </div>
            </div>

            <div class="p-2.5 rounded-lg bg-muted/40 border border-border/50">
                <div class="text-[11px] font-medium text-muted-foreground">{{ __('Uploaded Documents') }}</div>
                <div class="text-sm font-bold text-foreground mt-0.5">
                    {{ $this->student->studentDocuments->count() }} {{ __('Files') }}
                </div>
            </div>
        </div>
    </div>

    <!-- ACTIVE COURSE ENROLMENTS SHOWCASE (TOP OF DASHBOARD) -->
    @php
        $activeEnrs = $this->activeEnrollmentsList;
    @endphp
    <div class="bg-card border border-border rounded-xl p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-border">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                    <x-icon name="graduation-cap" class="w-5 h-5" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-base font-bold text-foreground">{{ __('Active Course Enrolments') }}</h3>
                        <x-ui.badge variant="success" class="text-xs font-mono font-bold">
                            {{ $activeEnrs->count() }} {{ __('Enrolled') }}
                        </x-ui.badge>
                    </div>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Your ongoing courses, batch schedules, cohort allocations, and student identity details.') }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a
                    href="{{ route('student.study-materials.index') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-background hover:bg-muted text-xs font-semibold text-foreground transition-colors shadow-2xs group"
                >
                    <x-icon name="folder-open" class="w-3.5 h-3.5 text-primary" />
                    <span>{{ __('Study Materials') }}</span>
                    <x-icon name="arrow-right" class="w-3.5 h-3.5 text-muted-foreground group-hover:translate-x-0.5 transition-transform" />
                </a>

                <a
                    href="{{ route('student.academics.index') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-background hover:bg-muted text-xs font-semibold text-foreground transition-colors shadow-2xs group"
                >
                    <x-icon name="book-open" class="w-3.5 h-3.5 text-primary" />
                    <span>{{ __('Academics & Enrolment Hub') }}</span>
                    <x-icon name="arrow-right" class="w-3.5 h-3.5 text-muted-foreground group-hover:translate-x-0.5 transition-transform" />
                </a>
            </div>
        </div>

        @if($activeEnrs->isEmpty())
            <div class="py-6 px-4 text-center rounded-lg bg-muted/20 border border-dashed border-border/80">
                <x-icon name="info" class="w-6 h-6 text-muted-foreground/70 mx-auto mb-1.5" />
                <p class="text-xs font-semibold text-foreground">{{ __('No active course enrolments assigned yet') }}</p>
                <p class="text-[11px] text-muted-foreground max-w-md mx-auto mt-0.5">
                    {{ __('Once your course admission is finalized or assigned by the administration, your active course schedules, student ID, and section details will be showcased here.') }}
                </p>
                <div class="mt-3 flex items-center justify-center gap-2">
                    <a href="{{ route('student.academics.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:underline">
                        <span>{{ __('View complete academic history') }}</span>
                        <x-icon name="arrow-right" class="w-3 h-3" />
                    </a>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 {{ $activeEnrs->count() > 1 ? 'lg:grid-cols-2' : '' }} gap-4">
                @foreach($activeEnrs as $enrollment)
                    @php
                        $course = $enrollment->course;
                        $batch = $enrollment->batch;
                        $section = $enrollment->section;
                        $isFreeCourse = ($course && $course->isFree()) || $enrollment->fee_type === 'free' || (float)$enrollment->fee_amount === 0.0;
                        $pendingWth = $enrollment->pendingWithdrawalRequest;
                        $displayStudentId = $enrollment->student_id ?: ($this->student->student_id ?: __('Pending'));
                    @endphp
                    <div class="p-4 rounded-xl border {{ $isFreeCourse ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-border/80 bg-muted/15' }} space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h4 class="text-sm font-bold text-foreground">{{ $course?->name ?? __('Academic Course') }}</h4>
                                    @if($course?->code)
                                        <x-ui.badge variant="secondary" class="font-mono text-[10px]">{{ $course->code }}</x-ui.badge>
                                    @endif
                                    @if($isFreeCourse)
                                        <x-ui.badge variant="success" class="text-[10px]">
                                            {{ __('Free / Sponsored') }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 text-xs mt-1.5 flex-wrap">
                                    @if(!empty($this->student->urn))
                                        <x-ui.badge variant="outline" class="font-mono text-[11px] gap-1" title="{{ __('Universal Registration Number') }}">
                                            <span class="text-muted-foreground">{{ __('URN:') }}</span>
                                            <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                                        </x-ui.badge>
                                    @endif
                                    <x-ui.badge variant="secondary" class="font-mono text-xs gap-1">
                                        <span class="text-muted-foreground">{{ __('Student ID:') }}</span>
                                        <span class="font-bold text-primary">{{ $displayStudentId }}</span>
                                    </x-ui.badge>
                                    <span class="text-muted-foreground">
                                        {{ __('Enrollment No:') }} <strong class="font-mono text-foreground">{{ $enrollment->enrollment_no }}</strong>
                                    </span>
                                </div>
                            </div>
                            <x-ui.badge variant="success" class="text-[10px] font-semibold uppercase">
                                {{ __('Active') }}
                            </x-ui.badge>
                        </div>

                        <!-- Mini Highlights Grid -->
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs pt-1">
                            <div class="p-2 rounded-lg bg-card border border-border/60">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Batch & Year') }}</span>
                                <span class="font-semibold text-foreground truncate block mt-0.5">{{ $batch?->name ?? '—' }}</span>
                                <span class="text-[10px] text-muted-foreground">{{ $batch?->academic_year ?? date('Y') }}</span>
                            </div>
                            <div class="p-2 rounded-lg bg-card border border-border/60">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Section / Cohort') }}</span>
                                <span class="font-semibold text-foreground truncate block mt-0.5">{{ $section?->name ?? __('Unassigned') }}</span>
                                <span class="text-[10px] text-muted-foreground">
                                    @if($section && $section->room_no) {{ __('Room: ') }}{{ $section->room_no }} @else {{ __('Standard Cohort') }} @endif
                                </span>
                            </div>
                            <div class="p-2 rounded-lg bg-card border border-border/60 col-span-2 sm:col-span-1">
                                <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Fee / Dues') }}</span>
                                <span class="font-semibold {{ $isFreeCourse || $enrollment->fee_status === 'paid' ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }} block mt-0.5">
                                    @if($isFreeCourse)
                                        {{ __('Exempted (Free)') }}
                                    @elseif($enrollment->fee_status === 'paid')
                                        {{ __('Fully Paid') }}
                                    @else
                                        {{ ucfirst($enrollment->fee_status ?? 'Pending') }}
                                    @endif
                                </span>
                                <span class="text-[10px] text-muted-foreground">
                                    {{ $isFreeCourse ? '₹0.00' : '₹'.number_format((float) $enrollment->fee_amount, 2) }}
                                </span>
                            </div>
                        </div>

                        <!-- Footer Actions -->
                        <div class="pt-2 border-t border-border/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                            <div class="flex items-center gap-2">
                                <a
                                    href="{{ route('student.study-materials.index', ['course' => $course?->id]) }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-primary/10 hover:bg-primary/20 text-primary font-semibold text-xs transition-colors"
                                    title="{{ __('Access lecture notes, PDFs & materials for this course') }}"
                                >
                                    <x-icon name="folder-open" class="w-3.5 h-3.5" />
                                    <span>{{ __('Course Materials') }}</span>
                                </a>

                                <span class="text-[11px] text-muted-foreground hidden sm:inline">&bull;</span>
                                <span class="text-[11px] text-muted-foreground">
                                    {{ __('Enrolled on: :date', ['date' => $enrollment->enrolled_at ? $enrollment->enrolled_at->format('d M Y') : $enrollment->created_at->format('d M Y')]) }}
                                </span>
                            </div>

                            <div class="flex items-center gap-2 self-end sm:self-auto">
                                @if($pendingWth)
                                    <div class="flex items-center gap-1.5 text-amber-700 dark:text-amber-300 text-[11px] font-medium">
                                        <x-icon name="clock" class="w-3.5 h-3.5 text-amber-600 shrink-0" />
                                        <span>{{ __('Exit request pending review: :no', ['no' => $pendingWth->request_number]) }}</span>
                                    </div>
                                    <x-ui.button size="xs" variant="outline" wire:click="cancelWithdrawalRequest({{ $pendingWth->id }})" class="text-xs text-rose-600 border-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/30">
                                        {{ __('Cancel Request') }}
                                    </x-ui.button>
                                @elseif($enrollment->canRequestWithdrawal())
                                    <x-ui.button size="xs" variant="outline" wire:click="openWithdrawalModal({{ $enrollment->id }})" class="text-xs gap-1 text-amber-600 hover:text-amber-700 border-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                                        <x-icon name="user-minus" class="w-3 h-3" />
                                        {{ __('Request Withdrawal') }}
                                    </x-ui.button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- PENDING FEES & DUES ALERT BANNER -->
    @php
        $pendingStudentInvoices = auth()->check()
            ? \App\Models\StudentFeeInvoice::where('user_id', auth()->id())
                ->whereIn('status', [\App\Models\StudentFeeInvoice::STATUS_PENDING, \App\Models\StudentFeeInvoice::STATUS_PARTIAL, \App\Models\StudentFeeInvoice::STATUS_OVERDUE, 'partially_paid'])
                ->with(['feeType', 'enrollment.course'])
                ->get()
            : collect();
        $totalDuesAmount = $pendingStudentInvoices->sum(fn ($i) => max(0, (float) ($i->balance_amount ?? ($i->total_amount - $i->paid_amount))));
    @endphp

    @if($pendingStudentInvoices->isNotEmpty())
        <div class="p-4 bg-gradient-to-r from-amber-500/15 via-rose-500/10 to-primary/10 border border-amber-500/30 rounded-2xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 shadow-sm">
            <div class="flex items-start gap-3.5">
                <div class="p-2.5 rounded-xl bg-amber-500/20 text-amber-600 dark:text-amber-400 shrink-0">
                    <x-icon name="wallet" class="w-6 h-6 animate-pulse" />
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h4 class="text-sm font-bold text-foreground">
                            {{ __('Pending Fee Payment Alert') }}
                        </h4>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-600 dark:text-rose-400 border border-rose-500/30">
                            {{ $pendingStudentInvoices->count() }} {{ __('Dues Active') }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('You have outstanding fees totaling') }} <strong class="text-foreground">₹{{ number_format($totalDuesAmount, 2) }}</strong>. 
                        {{ __('Please pay before the due date to avoid late payment surcharges and ensure uninterrupted academic services.') }}
                    </p>
                </div>
            </div>
            <a
                href="{{ route('student.payments.index') }}"
                wire:navigate
                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-primary text-primary-foreground text-xs font-semibold hover:bg-primary/90 transition shadow-xs shrink-0 cursor-pointer"
            >
                <x-icon name="credit-card" class="w-4 h-4" />
                {{ __('Pay Dues Online / Offline') }}
                <x-icon name="arrow-right" class="w-3.5 h-3.5" />
            </a>
        </div>
    @endif

    <!-- PROFILE COMPLETENESS STATUS CARD -->
    @php $comp = $this->completeness; @endphp
    <div class="p-4 sm:p-5 rounded-xl border {{ $comp['is_complete'] ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-card border-border shadow-xs' }} space-y-3">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl {{ $comp['is_complete'] ? 'bg-emerald-500/10 text-emerald-600' : 'bg-primary/10 text-primary' }} flex items-center justify-center font-bold text-sm shrink-0">
                    @if($comp['is_complete'])
                        <x-icon name="check" class="w-5 h-5 text-emerald-600" />
                    @else
                        <span class="font-mono text-xs font-black">{{ $comp['percentage'] }}%</span>
                    @endif
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-bold text-foreground">{{ __('Profile Completion Status') }}</span>
                        @if($comp['is_complete'])
                            <x-ui.badge variant="success" class="text-xs">{{ __('100% Completed') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="warning" class="text-xs font-mono">{{ $comp['percentage'] }}% ({{ $comp['completed_count'] }}/{{ $comp['total_count'] }} {{ __('Sections') }})</x-ui.badge>
                        @endif
                    </div>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        @if($comp['is_complete'])
                            {{ __('All 7 required student profile sections have been recorded and verified.') }}
                        @else
                            {{ __('Complete all 7 sections below or submit correction requests to fulfill academic compliance.') }}
                            @if(! $this->allowIncompleteAccess)
                                <span class="text-amber-600 dark:text-amber-400 font-semibold block sm:inline mt-0.5 sm:mt-0">
                                    • {{ __('Strict profile completion policy enforced by administration.') }}
                                </span>
                            @endif
                        @endif
                    </p>
                </div>
            </div>

            @if(! $this->allowIncompleteAccess && ! $comp['is_complete'])
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20 text-xs font-semibold shrink-0">
                    <x-icon name="shield" class="w-3.5 h-3.5 text-amber-500" />
                    {{ __('Portal Routes Restricted') }}
                </span>
            @endif
        </div>

        <!-- Visual Progress Bar -->
        <div class="w-full bg-muted/60 h-2 rounded-full overflow-hidden border border-border/40">
            <div
                class="h-full transition-all duration-500 {{ $comp['is_complete'] ? 'bg-emerald-500' : 'bg-primary' }}"
                style="width: {{ $comp['percentage'] }}%"
            ></div>
        </div>

        <!-- 7 Section Checklist Pills -->
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 pt-1">
            @foreach($comp['sections'] as $secKey => $secInfo)
                @php
                    $isSecDone = !empty($secInfo['is_complete']);
                    $hasSecPending = !empty($secInfo['has_pending']) || !empty($secInfo['has_pending_request']);
                    $secLabel = $secInfo['label'] ?? $secInfo['title'] ?? ucfirst($secKey);
                @endphp
                <button
                    type="button"
                    wire:click="setTab('{{ $secKey }}')"
                    class="p-2 rounded-lg border text-left transition-all cursor-pointer {{ $isSecDone ? 'bg-emerald-500/5 border-emerald-500/20 hover:bg-emerald-500/10' : ($hasSecPending ? 'bg-amber-500/10 border-amber-500/30' : 'bg-muted/40 border-border hover:bg-muted/60') }}"
                >
                    <div class="flex items-center justify-between text-[11px] font-semibold gap-1">
                        <span class="truncate {{ $isSecDone ? 'text-emerald-700 dark:text-emerald-400' : 'text-foreground' }}">
                            {{ $secLabel }}
                        </span>
                        @if($isSecDone)
                            <x-icon name="check" class="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                        @elseif($hasSecPending)
                            <span class="text-[9px] px-1 py-0.2 rounded bg-amber-500/20 text-amber-700 dark:text-amber-300 font-bold shrink-0" title="{{ __('Request Under Review') }}">Rev</span>
                        @else
                            <span class="w-2 h-2 rounded-full bg-amber-500 shrink-0"></span>
                        @endif
                    </div>
                    <div class="text-[10px] text-muted-foreground mt-0.5 truncate">
                        @if($secKey === 'employment' && empty($secInfo['is_working_professional']))
                            <span class="text-emerald-600 dark:text-emerald-400">{{ __('Optional') }}</span>
                        @elseif($isSecDone)
                            <span class="text-emerald-600 dark:text-emerald-400">{{ __('Complete') }}</span>
                        @elseif($hasSecPending)
                            <span class="text-amber-600 dark:text-amber-400 font-medium">{{ __('In Review') }}</span>
                        @else
                            <span class="text-rose-600 dark:text-rose-400 font-medium">{{ __('Incomplete') }}</span>
                        @endif
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    <!-- Strict Verification Notice Bar -->
    <div class="p-3.5 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 rounded-xl flex items-start sm:items-center gap-3 text-xs text-amber-800 dark:text-amber-300">
        <x-icon name="shield-check" class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5 sm:mt-0" />
        <div class="flex-1">
            <strong>{{ __('Official Profile Verification System:') }}</strong>
            {{ __('To preserve academic record integrity, all modifications require administrator review. Click "Request Correction" in any section below to submit changes with justification and optional proof.') }}
        </div>
    </div>

    <!-- 7 Main Details Navigation Tabs + Requests History -->
    <div class="border-b border-border">
        <nav class="flex space-x-1 sm:space-x-2 overflow-x-auto pb-1" aria-label="Student Dossier Tabs">
            <!-- Tab: Academics & Enrolments -->
            <button
                type="button"
                wire:click="setTab('academics')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'academics' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="graduation-cap" class="w-4 h-4" />
                <span>{{ __('Academics & Enrolments') }}</span>
                @if($this->activeEnrollmentsList->count() > 0)
                    <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-primary/10 text-primary font-mono font-bold">{{ $this->activeEnrollmentsList->count() }}</span>
                @endif
            </button>

            <!-- Tab 1: Account & Identity -->
            <button
                type="button"
                wire:click="setTab('account')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'account' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="user" class="w-4 h-4" />
                <span>{{ __('1. Account & Identity') }}</span>
                @if(!empty($comp['sections']['account']['is_complete']))
                    <x-icon name="check" class="w-3 h-3 text-emerald-600 ml-0.5" />
                @elseif(!empty($comp['sections']['account']['has_pending']) || !empty($comp['sections']['account']['has_pending_request']))
                    <span class="ml-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-700 font-bold">Review</span>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 ml-0.5"></span>
                @endif
            </button>

            <!-- Tab 2: Profile & Family -->
            <button
                type="button"
                wire:click="setTab('profile')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'profile' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="users" class="w-4 h-4" />
                <span>{{ __('2. Profile & Family') }}</span>
                @if(!empty($comp['sections']['profile']['is_complete']))
                    <x-icon name="check" class="w-3 h-3 text-emerald-600 ml-0.5" />
                @elseif(!empty($comp['sections']['profile']['has_pending']) || !empty($comp['sections']['profile']['has_pending_request']))
                    <span class="ml-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-700 font-bold">Review</span>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 ml-0.5"></span>
                @endif
            </button>

            <!-- Tab 3: Addresses -->
            <button
                type="button"
                wire:click="setTab('address')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'address' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="map-pin" class="w-4 h-4" />
                <span>{{ __('3. Addresses') }}</span>
                @if(!empty($comp['sections']['address']['is_complete']))
                    <x-icon name="check" class="w-3 h-3 text-emerald-600 ml-0.5" />
                @elseif(!empty($comp['sections']['address']['has_pending']) || !empty($comp['sections']['address']['has_pending_request']))
                    <span class="ml-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-700 font-bold">Review</span>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 ml-0.5"></span>
                @endif
            </button>

            <!-- Tab 4: Qualifications -->
            <button
                type="button"
                wire:click="setTab('education')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'education' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="graduation-cap" class="w-4 h-4" />
                <span>{{ __('4. Qualifications') }}</span>
                @if($this->student->studentEducations->count() > 0)
                    <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-muted font-mono">{{ $this->student->studentEducations->count() }}</span>
                @endif
                @if(!empty($comp['sections']['education']['is_complete']))
                    <x-icon name="check" class="w-3 h-3 text-emerald-600 ml-0.5" />
                @elseif(!empty($comp['sections']['education']['has_pending']) || !empty($comp['sections']['education']['has_pending_request']))
                    <span class="ml-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-700 font-bold">Review</span>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 ml-0.5"></span>
                @endif
            </button>

            <!-- Tab 5: Employment -->
            @php $isWorkingTab = (bool) ($this->student->studentProfile?->is_working_professional); @endphp
            <button
                type="button"
                wire:click="setTab('employment')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'employment' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="briefcase" class="w-4 h-4" />
                <span>{{ $isWorkingTab ? __('5. Employment (Required)') : __('5. Employment (Optional)') }}</span>
                @if($this->student->studentEmployments->count() > 0)
                    <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-muted font-mono">{{ $this->student->studentEmployments->count() }}</span>
                @endif
                @if(!empty($comp['sections']['employment']['is_complete']))
                    <x-icon name="check" class="w-3 h-3 text-emerald-600 ml-0.5" />
                @elseif(!empty($comp['sections']['employment']['has_pending']) || !empty($comp['sections']['employment']['has_pending_request']))
                    <span class="ml-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-700 font-bold">Review</span>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 ml-0.5"></span>
                @endif
            </button>

            <!-- Tab 6: UPSC Details -->
            <button
                type="button"
                wire:click="setTab('upsc')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'upsc' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="award" class="w-4 h-4" />
                <span>{{ __('6. UPSC Details') }}</span>
                @if(!empty($comp['sections']['upsc']['is_complete']))
                    <x-icon name="check" class="w-3 h-3 text-emerald-600 ml-0.5" />
                @elseif(!empty($comp['sections']['upsc']['has_pending']) || !empty($comp['sections']['upsc']['has_pending_request']))
                    <span class="ml-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-700 font-bold">Review</span>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 ml-0.5"></span>
                @endif
            </button>

            <!-- Tab 7: Uploaded Documents -->
            <button
                type="button"
                wire:click="setTab('documents')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'documents' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="folder" class="w-4 h-4" />
                <span>{{ __('7. Uploaded Documents') }}</span>
                @if($this->student->studentDocuments->count() > 0)
                    <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-muted font-mono">{{ $this->student->studentDocuments->count() }}</span>
                @endif
                @if(!empty($comp['sections']['documents']['is_complete']))
                    <x-icon name="check" class="w-3 h-3 text-emerald-600 ml-0.5" />
                @elseif(!empty($comp['sections']['documents']['has_pending']) || !empty($comp['sections']['documents']['has_pending_request']))
                    <span class="ml-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-700 font-bold">Review</span>
                @else
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 ml-0.5"></span>
                @endif
            </button>

            <!-- Tab 8: Requests History -->
            <button
                type="button"
                wire:click="setTab('requests')"
                class="px-3 py-2 text-xs font-semibold border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 cursor-pointer {{ $activeTab === 'requests' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
            >
                <x-icon name="clock" class="w-4 h-4" />
                <span>{{ __('Change Requests') }}</span>
                @if($this->pendingRequestsCount > 0)
                    <span class="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-amber-500 text-white font-mono font-bold">{{ $this->pendingRequestsCount }}</span>
                @endif
            </button>
        </nav>
    </div>

    <!-- TAB 1: Account & Identity -->
    <div class="{{ $activeTab === 'account' ? 'block' : 'hidden' }} space-y-4">
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 mb-4 border-b border-border">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                            <x-icon name="user" class="w-5 h-5 text-primary" />
                            {{ __('Account & Identity Details') }}
                        </h3>
                        <x-ui.badge variant="outline" class="font-mono text-xs gap-1" title="{{ __('Universal Registration Number') }}">
                            <span class="text-muted-foreground">{{ __('URN:') }}</span>
                            @if(!empty($this->student->urn))
                                <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                            @else
                                <span class="text-muted-foreground/70 italic text-[11px]">{{ __('Not Assigned') }}</span>
                            @endif
                        </x-ui.badge>
                    </div>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Your primary authentication identity and official student credentials.') }}
                    </p>
                </div>
                <x-ui.button size="sm" variant="outline" wire:click="openRequestModal('account')" class="gap-1.5 text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                    <x-icon name="edit" class="w-3.5 h-3.5" />
                    {{ __('Request Correction') }}
                </x-ui.button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 text-xs">
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Full Name') }}</span>
                    <span class="text-sm font-semibold text-foreground block mt-1">{{ $this->student->name }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('First Name') }}</span>
                    <span class="text-sm font-semibold text-foreground block mt-1">{{ $this->student->first_name ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Last Name') }}</span>
                    <span class="text-sm font-semibold text-foreground block mt-1">{{ $this->student->last_name ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Email Address') }}</span>
                    <div class="flex items-center gap-1.5 mt-1">
                        <span class="text-sm font-semibold text-foreground">{{ $this->student->email }}</span>
                        @if($this->student->email_verified_at)
                            <x-ui.badge variant="success" class="text-[10px] py-0">{{ __('Verified') }}</x-ui.badge>
                        @endif
                    </div>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Primary Mobile Phone') }}</span>
                    <span class="text-sm font-semibold text-foreground block mt-1">{{ $this->student->phone ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('WhatsApp Number') }}</span>
                    <span class="text-sm font-semibold text-foreground block mt-1">{{ $this->student->whatsapp_no ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Date of Birth') }}</span>
                    <span class="text-sm font-semibold text-foreground block mt-1">
                        {{ $this->student->dob ? $this->student->dob->format('d M Y') : '—' }}
                    </span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Gender') }}</span>
                    <span class="text-sm font-semibold text-foreground capitalize block mt-1">{{ $this->student->gender ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Lifetime Student ID') }}</span>
                    <span class="text-sm font-mono font-bold text-primary block mt-1">{{ $this->student->student_id ?: __('Pending Generation') }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Universal Reg. No (URN)') }}</span>
                    <span class="text-sm font-mono font-bold text-foreground block mt-1">{{ $this->student->urn ?: __('Not Assigned') }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('10th Board Roll No') }}</span>
                    <span class="text-sm font-mono font-semibold text-foreground block mt-1">{{ $this->student->tenth_roll ?: '—' }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Photo ID Type') }}</span>
                    <span class="text-sm font-semibold text-foreground block mt-1">{{ $this->student->id_type ?: 'Aadhaar' }}</span>
                </div>
                <div>
                    <span class="text-muted-foreground font-medium block">{{ __('Photo ID Document Number') }}</span>
                    <span class="text-sm font-mono font-semibold text-foreground block mt-1">{{ $this->student->id_number ?: '—' }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 2: Profile & Family -->
    <div class="{{ $activeTab === 'profile' ? 'block' : 'hidden' }} space-y-4">
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="users" class="w-5 h-5 text-primary" />
                        {{ __('Profile & Family Background') }}
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Parental details, reservation category, distance to institute, and PwBD benchmark disability status.') }}
                    </p>
                </div>
                <x-ui.button size="sm" variant="outline" wire:click="openRequestModal('profile')" class="gap-1.5 text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                    <x-icon name="edit" class="w-3.5 h-3.5" />
                    {{ __('Request Correction') }}
                </x-ui.button>
            </div>

            @php $profile = $this->student->studentProfile; @endphp

            <div class="space-y-6 text-xs">
                <!-- Section A: Parents & Category -->
                <div>
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ __('Family & Category') }}</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __("Father's Name") }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile?->father_name ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __("Mother's Name") }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile?->mother_name ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Social Category') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">
                                <x-ui.badge variant="secondary">{{ $profile?->category ?: 'UR' }}</x-ui.badge>
                                @if($profile?->subcaste)
                                    <span class="text-muted-foreground ml-1">({{ $profile->subcaste }})</span>
                                @endif
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Distance to Institute') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">
                                {{ $profile?->distance !== null ? $profile->distance.' KM' : '—' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Creamy Layer Status') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile?->creamy_layer ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Caste Certificate No & Date') }}</span>
                            <span class="text-sm font-mono font-semibold text-foreground block mt-1">
                                {{ $profile?->cert_no ?: '—' }}
                                @if($profile?->issued_date)
                                    <span class="text-muted-foreground text-xs font-sans">({{ $profile->issued_date->format('d M Y') }})</span>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Section B: Disability Status (PwBD) -->
                <div class="pt-4 border-t border-border">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3 flex items-center gap-1.5">
                        <x-icon name="shield" class="w-3.5 h-3.5 text-primary" />
                        {{ __('Person with Benchmark Disability (PwBD) Details') }}
                    </h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('PwBD Candidate?') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">
                                @if($profile?->is_pwbd)
                                    <x-ui.badge variant="warning">{{ __('Yes, PwBD Candidate') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">{{ __('No') }}</x-ui.badge>
                                @endif
                            </span>
                        </div>
                        @if($profile?->is_pwbd)
                            <div>
                                <span class="text-muted-foreground font-medium block">{{ __('Disability Type') }}</span>
                                <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile->pwbd_type ?: '—' }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground font-medium block">{{ __('Disability Percentage') }}</span>
                                <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile->pwd_percentage ? $profile->pwd_percentage.'%' : '—' }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground font-medium block">{{ __('Certificate No') }}</span>
                                <span class="text-sm font-mono font-semibold text-foreground block mt-1">{{ $profile->pwbd_cert_no ?: '—' }}</span>
                            </div>
                            <div>
                                <span class="text-muted-foreground font-medium block">{{ __('Certificate Issue Date') }}</span>
                                <span class="text-sm font-semibold text-foreground block mt-1">
                                    {{ $profile->pwbd_issued_date ? $profile->pwbd_issued_date->format('d M Y') : '—' }}
                                </span>
                            </div>
                            <div>
                                <span class="text-muted-foreground font-medium block">{{ __('Issuing Authority') }}</span>
                                <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile->pwbd_issued_by ?: '—' }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Section C: Socio-Economic & Language -->
                <div class="pt-4 border-t border-border">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ __('Socio-Economic & Education Medium') }}</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Annual Family Income') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">
                                {{ $profile?->family_income ? '₹'.number_format($profile->family_income, 2) : '—' }}
                            </span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __("Father's Occupation") }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile?->father_occupation ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __("Mother's Occupation") }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile?->mother_occupation ?: '—' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Highest Education') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile?->highest_edu ?: 'Graduation' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Schooling Medium / Language') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">{{ $profile?->school_language ?: 'English' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Extra Activities / Hobbies') }}</span>
                            <span class="text-sm text-foreground block mt-1">{{ $profile?->hobbies ?: ($profile?->activities ?: '—') }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground font-medium block">{{ __('Working Professional Status') }}</span>
                            <span class="text-sm font-semibold text-foreground block mt-1">
                                @if($profile?->is_working_professional)
                                    <x-ui.badge variant="warning">{{ __('Yes (Working Professional)') }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="secondary">{{ __('No (Full-time Student / Aspirant)') }}</x-ui.badge>
                                @endif
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 3: Addresses -->
    <div class="{{ $activeTab === 'address' ? 'block' : 'hidden' }} space-y-4">
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="map-pin" class="w-5 h-5 text-primary" />
                        {{ __('Residential & Correspondence Addresses') }}
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Your registered permanent domicile and postal correspondence addresses.') }}
                    </p>
                </div>
                <x-ui.button size="sm" variant="outline" wire:click="openRequestModal('address')" class="gap-1.5 text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                    <x-icon name="edit" class="w-3.5 h-3.5" />
                    {{ __('Request Correction') }}
                </x-ui.button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Permanent Address Card -->
                <div class="p-4 rounded-xl border border-border bg-muted/20 space-y-3 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
                            <x-icon name="map-pin" class="w-4 h-4 text-emerald-600" />
                            {{ __('Permanent Address (Domicile)') }}
                        </span>
                        <x-ui.badge variant="success" class="text-[10px]">{{ __('Primary') }}</x-ui.badge>
                    </div>

                    @php $perm = $this->permanentAddress; @endphp
                    @if($perm)
                        <div class="space-y-1.5 leading-relaxed text-foreground">
                            <div><strong class="text-muted-foreground">{{ __('Street/Line 1:') }}</strong> {{ $perm->address_line1 }}</div>
                            @if($perm->address_line2)
                                <div><strong class="text-muted-foreground">{{ __('Line 2:') }}</strong> {{ $perm->address_line2 }}</div>
                            @endif
                            <div><strong class="text-muted-foreground">{{ __('Post Office:') }}</strong> {{ $perm->post_office ?: '—' }} • <strong class="text-muted-foreground">{{ __('Police Station:') }}</strong> {{ $perm->police_station ?: '—' }}</div>
                            <div><strong class="text-muted-foreground">{{ __('Subdistrict/Block:') }}</strong> {{ $perm->subdistrict ?: '—' }}</div>
                            <div><strong class="text-muted-foreground">{{ __('District:') }}</strong> {{ $perm->district }} • <strong class="text-muted-foreground">{{ __('State:') }}</strong> {{ $perm->state }}</div>
                            <div><strong class="text-muted-foreground">{{ __('PIN Code:') }}</strong> <span class="font-mono font-bold">{{ $perm->pin_code }}</span></div>
                        </div>
                    @else
                        <p class="text-muted-foreground italic py-3">{{ __('No permanent address on file.') }}</p>
                    @endif
                </div>

                <!-- Correspondence Address Card -->
                <div class="p-4 rounded-xl border border-border bg-muted/20 space-y-3 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
                            <x-icon name="mail" class="w-4 h-4 text-primary" />
                            {{ __('Correspondence Address (Mailing)') }}
                        </span>
                        <x-ui.badge variant="secondary" class="text-[10px]">{{ __('Postal') }}</x-ui.badge>
                    </div>

                    @php $corr = $this->correspondenceAddress; @endphp
                    @if($corr)
                        <div class="space-y-1.5 leading-relaxed text-foreground">
                            <div><strong class="text-muted-foreground">{{ __('Street/Line 1:') }}</strong> {{ $corr->address_line1 }}</div>
                            @if($corr->address_line2)
                                <div><strong class="text-muted-foreground">{{ __('Line 2:') }}</strong> {{ $corr->address_line2 }}</div>
                            @endif
                            <div><strong class="text-muted-foreground">{{ __('Post Office:') }}</strong> {{ $corr->post_office ?: '—' }} • <strong class="text-muted-foreground">{{ __('Police Station:') }}</strong> {{ $corr->police_station ?: '—' }}</div>
                            <div><strong class="text-muted-foreground">{{ __('Subdistrict/Block:') }}</strong> {{ $corr->subdistrict ?: '—' }}</div>
                            <div><strong class="text-muted-foreground">{{ __('District:') }}</strong> {{ $corr->district }} • <strong class="text-muted-foreground">{{ __('State:') }}</strong> {{ $corr->state }}</div>
                            <div><strong class="text-muted-foreground">{{ __('PIN Code:') }}</strong> <span class="font-mono font-bold">{{ $corr->pin_code }}</span></div>
                        </div>
                    @else
                        <p class="text-muted-foreground italic py-3">{{ __('Same as Permanent Address.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 4: Qualifications -->
    <div class="{{ $activeTab === 'education' ? 'block' : 'hidden' }} space-y-4">
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="graduation-cap" class="w-5 h-5 text-primary" />
                        {{ __('Educational Qualifications') }}
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Academic background records from secondary school to university degrees.') }}
                    </p>
                </div>
                <x-ui.button size="sm" variant="outline" wire:click="openRequestModal('education')" class="gap-1.5 text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                    <x-icon name="plus-circle" class="w-3.5 h-3.5" />
                    {{ __('Request Add Qualification') }}
                </x-ui.button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                        <tr>
                            <th class="px-3 py-2.5">{{ __('Level') }}</th>
                            <th class="px-3 py-2.5">{{ __('Board / University') }}</th>
                            <th class="px-3 py-2.5">{{ __('Institute') }}</th>
                            <th class="px-3 py-2.5">{{ __('Stream / Subject') }}</th>
                            <th class="px-3 py-2.5">{{ __('Year') }}</th>
                            <th class="px-3 py-2.5">{{ __('Score / %') }}</th>
                            <th class="px-3 py-2.5">{{ __('Division') }}</th>
                            <th class="px-3 py-2.5 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-foreground">
                        @forelse($this->student->studentEducations as $edu)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-3 py-2.5 font-semibold text-foreground">
                                    {{ $edu->level }}
                                    @if($edu->is_highest)
                                        <x-ui.badge variant="success" class="text-[9px] ml-1">{{ __('Highest') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">{{ $edu->board_university ?: '—' }}</td>
                                <td class="px-3 py-2.5">{{ $edu->institute ?: '—' }}</td>
                                <td class="px-3 py-2.5">{{ $edu->stream_subject ?: '—' }}</td>
                                <td class="px-3 py-2.5 font-mono">{{ $edu->passing_year ?: '—' }}</td>
                                <td class="px-3 py-2.5 font-semibold">
                                    {{ $edu->percentage ? $edu->percentage.'%' : ($edu->cgpa ? $edu->cgpa.' CGPA' : '—') }}
                                </td>
                                <td class="px-3 py-2.5">{{ $edu->division ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    <x-ui.button size="xs" variant="ghost" wire:click="openRequestModal('education', {{ $edu->id }})" class="gap-1 text-xs text-amber-600 hover:text-amber-700">
                                        <x-icon name="edit" class="w-3 h-3" />
                                        {{ __('Request Update') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-6 text-center text-muted-foreground">
                                    {{ __('No educational records found. Click "Request Add Qualification" to submit a record.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 5: Employment -->
    <div class="{{ $activeTab === 'employment' ? 'block' : 'hidden' }} space-y-4">
        <!-- Working Professional Status Selector Card -->
        @php
            $isWorking = (bool) ($this->student->studentProfile?->is_working_professional);
        @endphp
        <div class="bg-card border border-border rounded-xl p-4 sm:p-5 shadow-xs space-y-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-border pb-3">
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-foreground">{{ __('Are you currently a working professional?') }}</span>
                        @if($isWorking)
                            <x-ui.badge variant="warning" class="text-[10px]">{{ __('Employment Required') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="secondary" class="text-[10px]">{{ __('Employment Optional') }}</x-ui.badge>
                        @endif
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Specify your current employment status. If you are currently working, employment records are required for your profile dossier.') }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                <!-- Option 1: Not a working professional -->
                <button
                    type="button"
                    wire:click="updateWorkingProfessionalStatus(false)"
                    class="p-3.5 rounded-xl border text-left transition-all cursor-pointer flex items-start gap-3 {{ ! $isWorking ? 'bg-primary/5 border-primary ring-1 ring-primary/40 shadow-xs' : 'bg-muted/30 border-border hover:bg-muted/50' }}"
                >
                    <div class="w-5 h-5 rounded-full border flex items-center justify-center shrink-0 mt-0.5 {{ ! $isWorking ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40' }}">
                        @if(! $isWorking)
                            <div class="w-2 h-2 rounded-full bg-white"></div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            <span>{{ __('No — Full-time Student / Aspirant') }}</span>
                        </div>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            {{ __('Employment history is optional. You do not need to add any work experience to reach 100% profile completeness.') }}
                        </p>
                    </div>
                </button>

                <!-- Option 2: Yes, working professional -->
                <button
                    type="button"
                    wire:click="updateWorkingProfessionalStatus(true)"
                    class="p-3.5 rounded-xl border text-left transition-all cursor-pointer flex items-start gap-3 {{ $isWorking ? 'bg-primary/5 border-primary ring-1 ring-primary/40 shadow-xs' : 'bg-muted/30 border-border hover:bg-muted/50' }}"
                >
                    <div class="w-5 h-5 rounded-full border flex items-center justify-center shrink-0 mt-0.5 {{ $isWorking ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40' }}">
                        @if($isWorking)
                            <div class="w-2 h-2 rounded-full bg-white"></div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs font-bold text-foreground flex items-center gap-1.5">
                            <span>{{ __('Yes — Currently Employed / Working Professional') }}</span>
                        </div>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            {{ __('Employment history is required. Please record at least one current or previous employment experience.') }}
                        </p>
                    </div>
                </button>
            </div>
        </div>

        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="briefcase" class="w-5 h-5 text-primary" />
                        {{ __('Employment History') }}
                        @if($isWorking)
                            <span class="text-xs font-semibold text-amber-600 dark:text-amber-400">({{ __('Required') }})</span>
                        @else
                            <span class="text-xs font-normal text-muted-foreground">({{ __('Optional') }})</span>
                        @endif
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Past and present professional experience, internships, or public sector service.') }}
                    </p>
                </div>
                <x-ui.button size="sm" variant="outline" wire:click="openRequestModal('employment')" class="gap-1.5 text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                    <x-icon name="plus-circle" class="w-3.5 h-3.5" />
                    {{ $isWorking ? __('Add Employment Record (Required)') : __('Add Employment Record (Optional)') }}
                </x-ui.button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                        <tr>
                            <th class="px-3 py-2.5">{{ __('Employer / Org') }}</th>
                            <th class="px-3 py-2.5">{{ __('Designation') }}</th>
                            <th class="px-3 py-2.5">{{ __('Nature') }}</th>
                            <th class="px-3 py-2.5">{{ __('Location') }}</th>
                            <th class="px-3 py-2.5">{{ __('Period') }}</th>
                            <th class="px-3 py-2.5 text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-foreground">
                        @forelse($this->student->studentEmployments as $emp)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-3 py-2.5 font-semibold text-foreground">
                                    {{ $emp->employer_name }}
                                    @if($emp->is_current)
                                        <x-ui.badge variant="success" class="text-[9px] ml-1">{{ __('Current') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5">{{ $emp->designation ?: '—' }}</td>
                                <td class="px-3 py-2.5">{{ $emp->nature_of_employment ?: 'Full-time' }}</td>
                                <td class="px-3 py-2.5">{{ $emp->location ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-muted-foreground">
                                    {{ $emp->from_date ? $emp->from_date->format('M Y') : '—' }} –
                                    {{ $emp->is_current ? __('Present') : ($emp->to_date ? $emp->to_date->format('M Y') : '—') }}
                                </td>
                                <td class="px-3 py-2.5 text-right">
                                    <x-ui.button size="xs" variant="ghost" wire:click="openRequestModal('employment', {{ $emp->id }})" class="gap-1 text-xs text-amber-600 hover:text-amber-700">
                                        <x-icon name="edit" class="w-3 h-3" />
                                        {{ __('Request Update') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-6 text-center text-muted-foreground">
                                    <div class="flex flex-col items-center justify-center space-y-1 py-1">
                                        <x-icon name="briefcase" class="w-6 h-6 text-muted-foreground/40 mb-1" />
                                        <p class="font-medium text-foreground">{{ __('No employment history recorded.') }}</p>
                                        @if($isWorking)
                                            <p class="text-xs text-amber-600 dark:text-amber-400 max-w-md font-medium">
                                                {{ __('As a working professional, please add at least one employment record to complete your profile.') }}
                                            </p>
                                        @else
                                            <p class="text-xs text-muted-foreground max-w-md">
                                                {{ __('Employment details are optional. If you do not have prior employment or work experience, no action is required.') }}
                                            </p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 6: UPSC Details -->
    <div class="{{ $activeTab === 'upsc' ? 'block' : 'hidden' }} space-y-4">
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="award" class="w-5 h-5 text-primary" />
                        {{ __('UPSC Civil Services Examination Details') }}
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Target appearing year, optional subject, and previous UPSC CSE attempts log.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.button size="sm" variant="primary" wire:click="openOptionalSubjectChangeModal" class="gap-1.5 text-xs bg-purple-600 hover:bg-purple-700 text-white font-semibold shadow-2xs">
                        <x-icon name="arrow-path" class="w-3.5 h-3.5" />
                        {{ __('Change Optional Subject') }}
                    </x-ui.button>
                    <x-ui.button size="sm" variant="outline" wire:click="openRequestModal('upsc')" class="gap-1.5 text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                        <x-icon name="edit" class="w-3.5 h-3.5" />
                        {{ __('Request Correction') }}
                    </x-ui.button>
                </div>
            </div>

            @if($this->pendingOptionalSubjectRequest)
                <div class="mb-5 p-4 rounded-xl border border-amber-300 dark:border-amber-800/60 bg-amber-50 dark:bg-amber-950/20 flex items-start gap-3">
                    <span class="p-2 rounded-lg bg-amber-500/10 text-amber-600 shrink-0 mt-0.5">
                        <x-icon name="clock" class="w-5 h-5" />
                    </span>
                    <div class="text-xs space-y-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <strong class="font-bold text-amber-900 dark:text-amber-200">{{ __('Optional Subject Change Request Under Review') }}</strong>
                            <span class="font-mono text-[10px] px-2 py-0.5 rounded bg-amber-200/60 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300 font-bold">
                                {{ $this->pendingOptionalSubjectRequest->request_no }}
                            </span>
                        </div>
                        <p class="text-amber-800 dark:text-amber-300">
                            {{ __('You have requested to change optional subject from ":from" to ":to".', ['from' => $this->pendingOptionalSubjectRequest->current_subject ?: __('None'), 'to' => $this->pendingOptionalSubjectRequest->requested_subject]) }}
                        </p>
                        <p class="text-[11px] text-amber-700/80 dark:text-amber-400/80 italic">
                            {{ __('Reason: ') }}{{ $this->pendingOptionalSubjectRequest->reason }}
                        </p>
                    </div>
                </div>
            @endif

            @php $other = $this->student->studentOtherDetails; @endphp

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 p-4 rounded-xl bg-muted/20 border border-border mb-6 text-xs">
                <div class="p-3 rounded-lg bg-purple-500/10 border border-purple-500/20">
                    <div class="flex items-center justify-between">
                        <span class="text-purple-700 dark:text-purple-300 font-semibold block">{{ __('Selected Optional Subject') }}</span>
                        <button type="button" wire:click="openOptionalSubjectChangeModal" class="text-[10px] text-purple-600 dark:text-purple-400 hover:underline font-bold">
                            {{ __('Change') }}
                        </button>
                    </div>
                    <span class="text-sm font-bold text-foreground block mt-1.5">{{ $other?->optional_subject ?: 'Not Selected' }}</span>
                </div>
                <div class="p-3 rounded-lg bg-card border border-border">
                    <span class="text-muted-foreground font-medium block">{{ __('Attempts Already Given') }}</span>
                    <span class="text-sm font-bold text-foreground block mt-1.5">{{ $other?->attempts_already_given ?? 0 }}</span>
                </div>
                <div class="p-3 rounded-lg bg-card border border-border">
                    <span class="text-muted-foreground font-medium block">{{ __('Attempts Left') }}</span>
                    <span class="text-sm font-bold text-foreground block mt-1.5">{{ $other?->attempts_left ?? '—' }}</span>
                </div>
                <div class="p-3 rounded-lg bg-card border border-border">
                    <span class="text-muted-foreground font-medium block">{{ __('Target Exam Year') }}</span>
                    <span class="text-sm font-bold text-foreground block mt-1.5">{{ $other?->year_of_appearing ?: '—' }}</span>
                </div>
            </div>

            <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ __('UPSC CSE Attempt History Records') }}</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                        <tr>
                            <th class="px-3 py-2.5">{{ __('Exam Year') }}</th>
                            <th class="px-3 py-2.5">{{ __('Roll Number') }}</th>
                            <th class="px-3 py-2.5 text-center">{{ __('Prelims Cleared') }}</th>
                            <th class="px-3 py-2.5 text-center">{{ __('Mains Cleared') }}</th>
                            <th class="px-3 py-2.5 text-center">{{ __('Interview Cleared') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-foreground">
                        @forelse($this->student->upscAttempts as $attempt)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-3 py-2.5 font-bold font-mono text-foreground">{{ $attempt->exam_year }}</td>
                                <td class="px-3 py-2.5 font-mono">{{ $attempt->roll_number ?: '—' }}</td>
                                <td class="px-3 py-2.5 text-center">
                                    <x-ui.badge :variant="$attempt->prelims_cleared ? 'success' : 'secondary'" class="text-[10px]">
                                        {{ $attempt->prelims_cleared ? __('Cleared') : __('No') }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-3 py-2.5 text-center">
                                    <x-ui.badge :variant="$attempt->mains_cleared ? 'success' : 'secondary'" class="text-[10px]">
                                        {{ $attempt->mains_cleared ? __('Cleared') : __('No') }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-3 py-2.5 text-center">
                                    <x-ui.badge :variant="$attempt->interview_cleared ? 'success' : 'secondary'" class="text-[10px]">
                                        {{ $attempt->interview_cleared ? __('Selected / Cleared') : __('No') }}
                                    </x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">
                                    {{ __('No past UPSC attempts logged.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Optional Subject Change Requests History -->
            @if($this->optionalSubjectRequestsList->isNotEmpty())
                <div class="mt-8 pt-6 border-t border-border">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-3 flex items-center gap-2">
                        <x-icon name="arrow-path" class="w-4 h-4 text-purple-600" />
                        <span>{{ __('My Optional Subject Change Requests') }}</span>
                    </h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                                <tr>
                                    <th class="px-3 py-2.5">{{ __('Request # / Date') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Current Subject') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Requested Subject') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Reason') }}</th>
                                    <th class="px-3 py-2.5 text-center">{{ __('Status') }}</th>
                                    <th class="px-3 py-2.5">{{ __('Admin Remarks') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border text-foreground">
                                @foreach($this->optionalSubjectRequestsList as $req)
                                    <tr class="hover:bg-muted/30 transition-colors">
                                        <td class="px-3 py-2.5 font-mono font-bold text-foreground">
                                            {{ $req->request_no }}
                                            <div class="text-[10px] text-muted-foreground font-normal">{{ $req->created_at->format('d M Y') }}</div>
                                        </td>
                                        <td class="px-3 py-2.5 text-muted-foreground line-through">{{ $req->current_subject ?: __('None') }}</td>
                                        <td class="px-3 py-2.5 font-bold text-purple-600 dark:text-purple-400">{{ $req->requested_subject }}</td>
                                        <td class="px-3 py-2.5 text-muted-foreground max-w-xs truncate" title="{{ $req->reason }}">{{ $req->reason }}</td>
                                        <td class="px-3 py-2.5 text-center">
                                            @if($req->status === 'pending')
                                                <x-ui.badge variant="warning" class="text-[10px]">{{ __('Pending Review') }}</x-ui.badge>
                                            @elseif($req->status === 'approved')
                                                <x-ui.badge variant="success" class="text-[10px]">{{ __('Approved') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge variant="destructive" class="text-[10px]">{{ __('Rejected') }}</x-ui.badge>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-muted-foreground text-[11px]">{{ $req->admin_remarks ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- TAB 7: Uploaded Documents -->
    <div class="{{ $activeTab === 'documents' ? 'block' : 'hidden' }} space-y-4">
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="folder" class="w-5 h-5 text-primary" />
                        {{ __('Uploaded Documents & Certificates') }}
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Official scanned certificates, photo identification, and marks verification records.') }}
                    </p>
                </div>
                <x-ui.button size="sm" variant="outline" wire:click="openRequestModal('documents')" class="gap-1.5 text-xs text-amber-600 dark:text-amber-400 border-amber-500/30 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                    <x-icon name="upload" class="w-3.5 h-3.5" />
                    {{ __('Request Document Upload') }}
                </x-ui.button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($this->student->studentDocuments as $doc)
                    <div class="p-4 rounded-xl border border-border bg-card hover:border-primary/40 transition-colors flex flex-col justify-between">
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="p-2 rounded-lg bg-primary/10 text-primary">
                                        <x-icon name="file-text" class="w-4 h-4" />
                                    </span>
                                    <div>
                                        <h4 class="font-semibold text-xs text-foreground">{{ $doc->type ?: ($doc->document_type ?: __('Document')) }}</h4>
                                        <span class="text-[10px] text-muted-foreground block truncate max-w-[140px]">{{ $doc->file_name }}</span>
                                    </div>
                                </div>
                                <x-ui.badge :variant="$doc->verified ? 'success' : 'warning'" class="text-[10px]">
                                    {{ $doc->verified ? __('Verified') : __('Pending') }}
                                </x-ui.badge>
                            </div>

                            @if($doc->description)
                                <p class="text-[11px] text-muted-foreground mt-2 line-clamp-2">{{ $doc->description }}</p>
                            @endif
                        </div>

                        <div class="mt-4 pt-3 border-t border-border/60 flex items-center justify-between text-[11px]">
                            <span class="text-muted-foreground">{{ $doc->created_at?->format('d M Y') }}</span>
                            @if($doc->file_path)
                                <a
                                    href="{{ Storage::url($doc->file_path) }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                                >
                                    <x-icon name="external-link" class="w-3.5 h-3.5" />
                                    {{ __('View File') }}
                                </a>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-8 text-center text-muted-foreground">
                        <x-icon name="folder" class="w-8 h-8 text-muted-foreground/50 mx-auto mb-2" />
                        <p class="text-xs">{{ __('No documents uploaded yet.') }}</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- TAB 8: Requests History -->
    <div class="{{ $activeTab === 'requests' ? 'block' : 'hidden' }} space-y-4">
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="clock" class="w-5 h-5 text-primary" />
                        {{ __('My Profile Correction Requests') }}
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Real-time tracking of all change requests submitted to administrators.') }}
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase tracking-wider font-semibold">
                        <tr>
                            <th class="px-3 py-2.5">{{ __('Request #') }}</th>
                            <th class="px-3 py-2.5">{{ __('Section') }}</th>
                            <th class="px-3 py-2.5">{{ __('Date Submitted') }}</th>
                            <th class="px-3 py-2.5">{{ __('Status') }}</th>
                            <th class="px-3 py-2.5">{{ __('Reason') }}</th>
                            <th class="px-3 py-2.5">{{ __('Admin Decision Notes') }}</th>
                            <th class="px-3 py-2.5 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border text-foreground">
                        @forelse($this->student->profileUpdateRequests as $req)
                            @php
                                $statusVariant = match($req->status) {
                                    'approved' => 'success',
                                    'rejected' => 'destructive',
                                    'withdrawn' => 'outline',
                                    default => 'warning',
                                };
                            @endphp
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-3 py-2.5 font-mono font-bold text-foreground">{{ $req->request_no }}</td>
                                <td class="px-3 py-2.5 capitalize font-semibold">
                                    <x-ui.badge variant="secondary" class="text-[10px]">{{ $req->section }}</x-ui.badge>
                                </td>
                                <td class="px-3 py-2.5 text-muted-foreground">{{ $req->created_at->format('d M Y, h:i A') }}</td>
                                <td class="px-3 py-2.5">
                                    <x-ui.badge :variant="$statusVariant" class="capitalize text-[10px]">
                                        {{ ucfirst($req->status) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-3 py-2.5 max-w-xs truncate text-muted-foreground" title="{{ $req->reason }}">
                                    {{ $req->reason }}
                                </td>
                                <td class="px-3 py-2.5 max-w-xs truncate text-muted-foreground">
                                    {{ $req->admin_notes ?: '—' }}
                                </td>
                                <td class="px-3 py-2.5 text-right space-x-1">
                                    <x-ui.button size="xs" variant="ghost" wire:click="openViewDiff({{ $req->id }})" class="gap-1 text-xs">
                                        <x-icon name="eye" class="w-3 h-3" />
                                        {{ __('View Details') }}
                                    </x-ui.button>
                                    @if($req->status === 'pending')
                                        <x-ui.button size="xs" variant="destructive" wire:click="openWithdrawConfirmation({{ $req->id }})" class="text-xs">
                                            {{ __('Withdraw') }}
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-muted-foreground">
                                    <x-icon name="check-circle" class="w-6 h-6 text-emerald-500 mx-auto mb-1.5" />
                                    {{ __('You have not submitted any profile correction requests yet.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB: Academics & Enrolments -->
    <div class="{{ $activeTab === 'academics' ? 'block' : 'hidden' }} space-y-4">
        <!-- Student Identity & Cohort Details Card -->
        <div class="bg-card border border-border rounded-xl p-5 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 mb-4 border-b border-border">
                <div>
                    <h3 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="graduation-cap" class="w-5 h-5 text-primary" />
                        {{ __('Academic Enrolment & Student Identity') }}
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Your active course enrollments, cohort section allocation, and fee payment details.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <x-ui.badge variant="outline" class="font-mono text-xs gap-1" title="{{ __('Universal Registration Number') }}">
                        <span class="text-muted-foreground">{{ __('URN:') }}</span>
                        @if(!empty($this->student->urn))
                            <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                        @else
                            <span class="text-muted-foreground/70 italic text-[11px]">{{ __('Not Assigned') }}</span>
                        @endif
                    </x-ui.badge>
                    <x-ui.badge variant="secondary" class="font-mono text-xs">
                        {{ __('Student ID: ') }} <span class="font-bold text-primary">{{ $this->student->student_id ?: __('Pending Assignment') }}</span>
                    </x-ui.badge>
                </div>
            </div>

            <!-- Student ID Format / Cohort Explainer Bar -->
            <div class="p-3.5 bg-muted/40 border border-border rounded-lg text-xs space-y-2">
                <div class="flex items-center gap-2 font-semibold text-foreground">
                    <x-icon name="info" class="w-4 h-4 text-primary shrink-0" />
                    <span>{{ __('Student ID Specification (9-Digit Cohort Format):') }}</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                    <div class="bg-card p-2.5 rounded border border-border/60">
                        <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Academic Year (4 digits)') }}</span>
                        <span class="font-mono font-bold text-sm text-foreground">
                            {{ $this->student->student_id ? substr($this->student->student_id, 0, 4) : date('Y') }}
                        </span>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('Enrollment Year') }}</span>
                    </div>
                    <div class="bg-card p-2.5 rounded border border-border/60">
                        <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Cohort / Section (1 digit)') }}</span>
                        <span class="font-mono font-bold text-sm text-primary">
                            {{ $this->student->student_id && strlen($this->student->student_id) >= 5 ? substr($this->student->student_id, 4, 1) : '1' }}
                        </span>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('1=Sec A, 2=Sec B, 0=Unassigned') }}</span>
                    </div>
                    <div class="bg-card p-2.5 rounded border border-border/60">
                        <span class="text-[10px] uppercase font-bold text-muted-foreground block">{{ __('Roll Sequence (4 digits)') }}</span>
                        <span class="font-mono font-bold text-sm text-foreground">
                            {{ $this->student->student_id && strlen($this->student->student_id) >= 9 ? substr($this->student->student_id, 5, 4) : '0001' }}
                        </span>
                        <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('Unique Student Roll Index') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enrolled Courses -->
        @php
            $enrollments = $this->allEnrollmentsList;
        @endphp

        @if($enrollments->isEmpty())
            <div class="bg-card border border-border rounded-xl p-8 text-center shadow-xs">
                <div class="w-12 h-12 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto mb-3">
                    <x-icon name="graduation-cap" class="w-6 h-6" />
                </div>
                <h4 class="text-base font-semibold text-foreground">{{ __('No Course Enrolments Found') }}</h4>
                <p class="text-xs text-muted-foreground max-w-md mx-auto mt-1">
                    {{ __('You are not currently enrolled in any academic courses. Once your admission application is processed or an administrator assigns a course, your batch, section, and fee details will appear here.') }}
                </p>
                <div class="mt-4 flex items-center justify-center gap-2">
                    <a href="{{ route('tickets.create') }}" wire:navigate class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground text-xs font-semibold hover:bg-primary/90 transition-colors">
                        <x-icon name="life-buoy" class="w-3.5 h-3.5" />
                        {{ __('Contact Admissions Desk') }}
                    </a>
                </div>
            </div>
        @else
            <div class="space-y-4">
                @foreach($enrollments as $enrollment)
                    @php
                        $course = $enrollment->course;
                        $batch = $enrollment->batch;
                        $section = $enrollment->section;
                        $isFreeCourse = ($course && $course->isFree()) || $enrollment->fee_type === 'free' || (float)$enrollment->fee_amount === 0.0;
                    @endphp
                    <div class="bg-card border {{ $isFreeCourse ? 'border-emerald-500/30' : 'border-border' }} rounded-xl p-5 shadow-xs space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 pb-3 border-b border-border">
                            <div>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h4 class="text-base font-bold text-foreground">{{ $course?->name ?? __('Unknown Course') }}</h4>
                                    @if($course?->code)
                                        <x-ui.badge variant="secondary" class="font-mono text-xs">{{ $course->code }}</x-ui.badge>
                                    @endif
                                    @if($course?->category)
                                        <x-ui.badge variant="outline" class="text-xs">
                                            {{ \App\Helpers\CourseData::getCategoryLabel($course->category) }}
                                        </x-ui.badge>
                                    @endif
                                    @if($isFreeCourse)
                                        <x-ui.badge variant="success" class="text-xs">
                                            {{ __('Free of Cost / No Fee') }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 text-xs text-muted-foreground mt-1.5 flex-wrap">
                                    @if(!empty($this->student->urn))
                                        <x-ui.badge variant="outline" class="font-mono text-xs gap-1" title="{{ __('Universal Registration Number') }}">
                                            <span class="text-muted-foreground">{{ __('URN:') }}</span>
                                            <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                                        </x-ui.badge>
                                    @endif
                                    <x-ui.badge variant="secondary" class="font-mono text-xs gap-1">
                                        <span class="text-muted-foreground">{{ __('Student ID:') }}</span>
                                        <span class="font-bold text-primary">{{ $enrollment->student_id ?: ($this->student->student_id ?: __('Pending')) }}</span>
                                    </x-ui.badge>
                                    <span>
                                        {{ __('Enrollment No:') }} <strong class="font-mono text-foreground">{{ $enrollment->enrollment_no }}</strong>
                                    </span>
                                    <span>&bull;</span>
                                    <span>
                                        {{ __('Enrolled on:') }} {{ $enrollment->enrolled_at ? $enrollment->enrolled_at->format('d M Y') : $enrollment->created_at->format('d M Y') }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.badge :variant="$enrollment->status === 'active' ? 'success' : ($enrollment->status === 'completed' ? 'secondary' : 'warning')" class="text-xs capitalize">
                                    {{ $enrollment->status }}
                                </x-ui.badge>
                            </div>
                        </div>

                        <!-- Grid Details -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                            <!-- Batch -->
                            <div class="p-3 rounded-lg bg-muted/30 border border-border/50">
                                <span class="text-muted-foreground font-medium block">{{ __('Academic Batch') }}</span>
                                <span class="text-sm font-bold text-foreground block mt-0.5">{{ $batch?->name ?? '—' }}</span>
                                <span class="text-[11px] text-muted-foreground block mt-0.5">
                                    {{ __('Year: ') }}{{ $batch?->academic_year ?? '—' }} · {{ $course?->duration_months ? $course->duration_months . ' ' . __('Months') : ($course?->duration_weeks ? $course->duration_weeks . ' ' . __('Weeks') : '—') }}
                                </span>
                            </div>

                            <!-- Section -->
                            <div class="p-3 rounded-lg bg-muted/30 border border-border/50">
                                <span class="text-muted-foreground font-medium block">{{ __('Assigned Section & Cohort') }}</span>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="text-sm font-bold text-foreground">{{ $section?->name ?? __('Unassigned') }}</span>
                                    @if($section)
                                        <x-ui.badge variant="primary" class="text-[10px] font-mono py-0">
                                            {{ __('ID Code: ') }}{{ $section->id_code ?: ($section->name === 'Section B' ? '2' : '1') }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <span class="text-[11px] text-muted-foreground block mt-0.5">
                                    @if($section)
                                        {{ __('Capacity: ') }}{{ $section->capacity }} {{ __('seats') }}
                                        @if($section->room_no) · {{ __('Room: ') }}{{ $section->room_no }} @endif
                                    @else
                                        {{ __('Awaiting section allocation') }}
                                    @endif
                                </span>
                            </div>

                            <!-- Fee Structure -->
                            <div class="p-3 rounded-lg {{ $isFreeCourse ? 'bg-emerald-500/10 border border-emerald-500/20' : 'bg-muted/30 border border-border/50' }}">
                                <span class="text-muted-foreground font-medium block">{{ __('Fee Structure') }}</span>
                                <div class="mt-0.5">
                                    @if($isFreeCourse)
                                        <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">₹0.00</span>
                                        <span class="text-[11px] text-emerald-700/80 dark:text-emerald-400/80 block mt-0.5">
                                            {{ __('100% Free / Sponsored') }}
                                        </span>
                                    @else
                                        <span class="text-sm font-bold text-foreground">₹{{ number_format((float) $enrollment->fee_amount, 2) }}</span>
                                        <span class="text-[11px] text-muted-foreground block mt-0.5 capitalize">
                                            {{ $course?->fee_type ? str_replace('_', ' ', $course->fee_type) : __('Standard Fee') }}
                                            ({{ $this->student->studentProfile?->category ?: 'UR' }} {{ __('Rate') }})
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <!-- Fee Status -->
                            <div class="p-3 rounded-lg bg-muted/30 border border-border/50">
                                <span class="text-muted-foreground font-medium block">{{ __('Payment Status') }}</span>
                                <div class="mt-0.5 flex items-center gap-1.5">
                                    @if($isFreeCourse || $enrollment->fee_status === 'exempted')
                                        <x-ui.badge variant="success" class="text-xs">
                                            {{ __('Exempted (Zero Fee)') }}
                                        </x-ui.badge>
                                    @elseif($enrollment->fee_status === 'paid')
                                        <x-ui.badge variant="success" class="text-xs">
                                            {{ __('Fully Paid') }}
                                        </x-ui.badge>
                                    @elseif($enrollment->fee_status === 'partial')
                                        <x-ui.badge variant="warning" class="text-xs">
                                            {{ __('Partially Paid') }}
                                        </x-ui.badge>
                                    @else
                                        <x-ui.badge variant="destructive" class="text-xs">
                                            {{ __('Pending Payment') }}
                                        </x-ui.badge>
                                    @endif
                                </div>
                                <span class="text-[11px] text-muted-foreground block mt-0.5">
                                    @if($isFreeCourse)
                                        {{ __('No student charges apply') }}
                                    @elseif($enrollment->fee_status === 'paid')
                                        {{ __('Receipt cleared') }}
                                    @else
                                        {{ __('Contact accounts desk') }}
                                    @endif
                                </span>
                            </div>
                        </div>

                        @if($course?->description)
                            <div class="text-xs text-muted-foreground pt-1 border-t border-border/40">
                                {{ $course->description }}
                            </div>
                        @endif

                        @php
                            $pendingWth = $enrollment->pendingWithdrawalRequest;
                        @endphp

                        @if($pendingWth)
                            <div class="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-300 dark:border-amber-800 rounded-lg flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 text-xs">
                                <div class="flex items-center gap-2 text-amber-900 dark:text-amber-200">
                                    <x-icon name="clock" class="w-4 h-4 text-amber-600 shrink-0" />
                                    <div>
                                        <strong>{{ __('Withdrawal / Discontinuation Requested:') }}</strong>
                                        <span class="font-mono font-bold">{{ $pendingWth->request_number }}</span> &bull; {{ $pendingWth->getReasonLabel() }}
                                        <span class="text-muted-foreground text-[11px]">({{ ($pendingWth->requested_at ?? $pendingWth->created_at)?->format('d M Y') }})</span>
                                    </div>
                                </div>
                                <x-ui.button size="xs" variant="outline" wire:click="cancelWithdrawalRequest({{ $pendingWth->id }})" class="text-xs text-rose-600 border-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/30">
                                    {{ __('Cancel Request') }}
                                </x-ui.button>
                            </div>
                        @elseif($enrollment->isWithdrawn() || $enrollment->isDiscontinued() || $enrollment->isCancelled())
                            <div class="p-3 bg-rose-50/70 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/40 rounded-lg text-xs space-y-1">
                                <div class="font-bold text-rose-700 dark:text-rose-400 flex items-center gap-1.5">
                                    <x-icon name="alert-circle" class="w-4 h-4" />
                                    <span>{{ __('Course Status: :status', ['status' => ucfirst($enrollment->status)]) }}</span>
                                </div>
                                <p class="text-muted-foreground">
                                    {{ $enrollment->cancellation_reason ?: __('Discontinued / cancelled under official institutional record.') }}
                                    @if($enrollment->discontinued_at)
                                        &bull; {{ is_string($enrollment->discontinued_at) ? \Carbon\Carbon::parse($enrollment->discontinued_at)->format('d M Y') : $enrollment->discontinued_at?->format('d M Y') }}
                                    @endif
                                </p>
                            </div>
                        @elseif($enrollment->canRequestWithdrawal())
                            <div class="flex items-center justify-between pt-2 border-t border-border/40">
                                <div class="text-[11px] text-muted-foreground">
                                    {{ __('Need to exit or discontinue from this course programme?') }}
                                </div>
                                <x-ui.button size="xs" variant="outline" wire:click="openWithdrawalModal({{ $enrollment->id }})" class="text-xs gap-1.5 text-amber-600 hover:text-amber-700 border-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/30">
                                    <x-icon name="user-minus" class="w-3.5 h-3.5" />
                                    {{ __('Request Course Withdrawal / Discontinuation') }}
                                </x-ui.button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <!-- COURSE WITHDRAWAL & DISCONTINUATION HISTORY -->
        @php
            $withdrawalHistory = $this->courseWithdrawalHistory;
        @endphp

        <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden mt-6">
            <div class="p-5 border-b border-border flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h4 class="text-base font-semibold text-foreground flex items-center gap-2">
                        <x-icon name="history" class="w-5 h-5 text-amber-600" />
                        {{ __('Course Withdrawal & Discontinuation History') }}
                    </h4>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Chronicle of submitted course exit requests, grounds, and administrative review decisions.') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <x-ui.badge variant="secondary" class="font-mono text-xs">
                        {{ $withdrawalHistory->count() }} {{ __('Record(s)') }}
                    </x-ui.badge>
                    <a href="{{ route('student.academics.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:underline">
                        <span>{{ __('Academics Hub') }}</span>
                        <x-icon name="arrow-right" class="w-3 h-3" />
                    </a>
                </div>
            </div>

            @if($withdrawalHistory->isEmpty())
                <div class="p-8 text-center text-xs text-muted-foreground">
                    <x-icon name="file-check" class="w-7 h-7 text-muted-foreground/60 mx-auto mb-2" />
                    <p class="font-semibold text-foreground">{{ __('No Withdrawal or Discontinuation Requests Filed') }}</p>
                    <p class="text-[11px] mt-0.5">{{ __('You have not submitted any exit requests. Your course enrollments remain in good standing.') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-muted/50 border-b border-border text-muted-foreground uppercase text-[10px] font-semibold tracking-wider">
                            <tr>
                                <th class="py-3 px-4">{{ __('Request #') }}</th>
                                <th class="py-3 px-4">{{ __('Type') }}</th>
                                <th class="py-3 px-4">{{ __('Course & Batch') }}</th>
                                <th class="py-3 px-4">{{ __('Stated Grounds') }}</th>
                                <th class="py-3 px-4">{{ __('Submitted On') }}</th>
                                <th class="py-3 px-4">{{ __('Status') }}</th>
                                <th class="py-3 px-4">{{ __('Admin Notes / Remarks') }}</th>
                                <th class="py-3 px-4 text-right">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($withdrawalHistory as $item)
                                <tr class="hover:bg-muted/30 transition-colors">
                                    <td class="py-3.5 px-4 font-mono font-bold text-foreground whitespace-nowrap">
                                        {{ $item->request_number }}
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap">
                                        <x-ui.badge :variant="$item->type === 'withdrawal' ? 'destructive' : 'warning'" class="text-[10px] uppercase font-bold">
                                            {{ ucfirst($item->type) }}
                                        </x-ui.badge>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="font-semibold text-foreground">{{ $item->course?->name ?? '—' }}</div>
                                        <div class="text-[11px] text-muted-foreground">{{ $item->batch?->name ?? '—' }}</div>
                                    </td>
                                    <td class="py-3.5 px-4 max-w-xs">
                                        <div class="font-medium text-foreground">{{ $item->getReasonLabel() }}</div>
                                        <div class="text-[11px] text-muted-foreground line-clamp-2 mt-0.5">{{ $item->reason_details }}</div>
                                        @if($item->supporting_document)
                                            <a
                                                href="{{ \Illuminate\Support\Facades\Storage::url($item->supporting_document) }}"
                                                target="_blank"
                                                class="inline-flex items-center gap-1 text-[11px] text-primary hover:underline mt-1 font-medium"
                                            >
                                                <x-icon name="paperclip" class="w-3 h-3" />
                                                <span>{{ __('View Attached Proof') }}</span>
                                            </a>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap text-muted-foreground">
                                        {{ ($item->requested_at ?? $item->created_at)?->format('d M Y') }}
                                        <span class="block text-[10px] text-muted-foreground/80">{{ ($item->requested_at ?? $item->created_at)?->format('h:i A') }}</span>
                                    </td>
                                    <td class="py-3.5 px-4 whitespace-nowrap">
                                        @if($item->status === 'pending')
                                            <x-ui.badge variant="warning" class="text-xs">
                                                <x-icon name="clock" class="w-3 h-3 mr-1" />
                                                {{ __('Pending Review') }}
                                            </x-ui.badge>
                                        @elseif($item->status === 'approved')
                                            <x-ui.badge variant="success" class="text-xs">
                                                <x-icon name="check-circle" class="w-3 h-3 mr-1" />
                                                {{ __('Approved') }}
                                            </x-ui.badge>
                                        @elseif($item->status === 'rejected')
                                            <x-ui.badge variant="destructive" class="text-xs">
                                                <x-icon name="x-circle" class="w-3 h-3 mr-1" />
                                                {{ __('Rejected') }}
                                            </x-ui.badge>
                                        @elseif($item->status === 'withdrawn')
                                            <x-ui.badge variant="secondary" class="text-xs">
                                                {{ __('Cancelled by Student') }}
                                            </x-ui.badge>
                                        @else
                                            <x-ui.badge variant="secondary" class="text-xs capitalize">
                                                {{ $item->status }}
                                            </x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 text-muted-foreground max-w-xs">
                                        @if($item->admin_notes)
                                            <div class="text-[11px] p-2 rounded bg-muted/40 border border-border/50 text-foreground">
                                                {{ $item->admin_notes }}
                                            </div>
                                            @if($item->reviewer)
                                                <span class="text-[10px] text-muted-foreground block mt-0.5">
                                                    {{ __('Reviewed by: :name', ['name' => $item->reviewer->name]) }}
                                                    @if($item->reviewed_at)
                                                        &bull; {{ $item->reviewed_at->format('d M Y') }}
                                                    @endif
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-muted-foreground/60 italic">{{ __('Awaiting review remarks') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3.5 px-4 text-right whitespace-nowrap">
                                        @if($item->status === 'pending')
                                            <x-ui.button
                                                size="xs"
                                                variant="outline"
                                                wire:click="cancelWithdrawalRequest({{ $item->id }})"
                                                wire:confirm="{{ __('Are you sure you want to cancel this course exit request?') }}"
                                                class="text-xs text-rose-600 border-rose-300 hover:bg-rose-50 dark:hover:bg-rose-950/30"
                                            >
                                                {{ __('Cancel') }}
                                            </x-ui.button>
                                        @else
                                            <span class="text-muted-foreground/50 text-[11px]">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <!-- STUDENT CHANGE REQUEST MODAL -->
    <x-ui.modal name="student-request-modal" max-width="max-w-2xl" :title="__('Request Profile Correction / Update')" :description="__('Modifications will be inspected by an administrator before being applied to your records.')">
        <form wire:submit="submitCorrectionRequest" class="space-y-4">
            <!-- Alert Info -->
            <div class="p-3 bg-muted/40 border border-border rounded-lg text-xs text-muted-foreground flex items-center gap-2">
                <x-icon name="info" class="w-4 h-4 text-primary shrink-0" />
                <span>
                    {{ __('You are submitting an update request for section:') }}
                    <strong class="text-foreground capitalize font-semibold">{{ $requestModalSection }}</strong>
                </span>
            </div>

            <!-- SECTION SPECIFIC FORM FIELDS -->

            <!-- 1. Account & Identity Fields -->
            @if($requestModalSection === 'account')
                <div class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('First Name') }} *</label>
                            <input type="text" wire:model="accountRequest.first_name" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                            @error('accountRequest.first_name') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Last Name') }}</label>
                            <input type="text" wire:model="accountRequest.last_name" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Phone Number') }} *</label>
                            <input type="text" wire:model="accountRequest.phone" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                            @error('accountRequest.phone') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('WhatsApp Number') }}</label>
                            <input type="text" wire:model="accountRequest.whatsapp_no" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Date of Birth') }}</label>
                            <input type="date" wire:model="accountRequest.dob" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Gender') }}</label>
                            <select wire:model="accountRequest.gender" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="male">{{ __('Male') }}</option>
                                <option value="female">{{ __('Female') }}</option>
                                <option value="other">{{ __('Other') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('10th Roll No') }}</label>
                            <input type="text" wire:model="accountRequest.tenth_roll" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('ID Document Type') }}</label>
                            <select wire:model="accountRequest.id_type" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="Aadhaar">{{ __('Aadhaar') }}</option>
                                <option value="Passport">{{ __('Passport') }}</option>
                                <option value="Voter ID">{{ __('Voter ID') }}</option>
                                <option value="PAN Card">{{ __('PAN Card') }}</option>
                                <option value="Driving License">{{ __('Driving License') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('ID Document Number') }}</label>
                            <input type="text" wire:model="accountRequest.id_number" class="w-full h-9 rounded-md border border-input bg-transparent px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>
                </div>
            @endif

            <!-- 2. Profile & Family Fields -->
            @if($requestModalSection === 'profile')
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __("Father's Name") }} *</label>
                            <input type="text" wire:model="profileRequest.father_name" placeholder="e.g. Debabrata Sen" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                            @error('profileRequest.father_name') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __("Mother's Name") }} *</label>
                            <input type="text" wire:model="profileRequest.mother_name" placeholder="e.g. Mita Sen" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                            @error('profileRequest.mother_name') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Social Category') }} *</label>
                            <select wire:model.live="profileRequest.category" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required>
                                <option value="">{{ __('-- Select Category --') }}</option>
                                @foreach(\App\Helpers\CategoryData::getCategoryOptions() as $catCode => $catLabel)
                                    <option value="{{ $catCode }}">{{ $catLabel }}</option>
                                @endforeach
                            </select>
                            @error('profileRequest.category') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        @php $subcastes = \App\Helpers\CategoryData::getSubcastes($profileRequest['category'] ?? ''); @endphp
                        @if(!empty($subcastes))
                            <div>
                                <label class="text-xs font-medium text-foreground">{{ __('Subcaste / Community') }}</label>
                                <select wire:model="profileRequest.subcaste" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                    <option value="">{{ __('-- Select Subcaste --') }}</option>
                                    @foreach($subcastes as $sc)
                                        <option value="{{ $sc }}">{{ $sc }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <div>
                                <label class="text-xs font-medium text-foreground">{{ __('Subcaste / Community') }}</label>
                                <input type="text" wire:model="profileRequest.subcaste" placeholder="e.g. Rajbanshi, Santhal..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                            </div>
                        @endif

                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Distance to Institute (KM)') }} *</label>
                            <input type="number" step="0.01" min="0" wire:model="profileRequest.distance" placeholder="e.g. 15.50" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                            @error('profileRequest.distance') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    @if(in_array($profileRequest['category'] ?? '', ['OBC', 'OBCA', 'OBCB'], true))
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 bg-muted/20 border border-border rounded-lg">
                            <div>
                                <label class="text-xs font-medium text-foreground">{{ __('Creamy Layer Status (OBC only) *') }}</label>
                                <select wire:model="profileRequest.creamy_layer" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                    <option value="">{{ __('-- Select Creamy / Non-Creamy --') }}</option>
                                    <option value="NCL">{{ __('Non-Creamy Layer (NCL)') }}</option>
                                    <option value="CL">{{ __('Creamy Layer (CL)') }}</option>
                                </select>
                            </div>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Caste Certificate Number') }}</label>
                            <input type="text" wire:model="profileRequest.cert_no" placeholder="e.g. WB/SC/2021/1049" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Certificate Issue Date') }}</label>
                            <input type="date" wire:model="profileRequest.issued_date" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Issuing Authority') }}</label>
                            <input type="text" wire:model="profileRequest.issued_by" placeholder="e.g. SDO Barrackpore" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Annual Family Income (₹)') }}</label>
                            <input type="number" step="1000" wire:model="profileRequest.family_income" placeholder="e.g. 250000" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Candidate Occupation') }}</label>
                            <input type="text" wire:model="profileRequest.occupation" placeholder="e.g. Student / Employed" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Currently Working Professional?') }}</label>
                            <select wire:model="profileRequest.is_working_professional" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option :value="false">{{ __('No (Student / Aspirant)') }}</option>
                                <option :value="true">{{ __('Yes (Working Professional)') }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __("Father's Occupation") }}</label>
                            <input type="text" wire:model="profileRequest.father_occupation" placeholder="e.g. Govt Service / Farmer" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __("Mother's Occupation") }}</label>
                            <input type="text" wire:model="profileRequest.mother_occupation" placeholder="e.g. Homemaker / Teacher" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Highest Qualification') }}</label>
                            <select wire:model="profileRequest.highest_edu" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="10th">10th / Secondary (Madhyamik)</option>
                                <option value="12th">12th / Higher Secondary (HS)</option>
                                <option value="Graduation">Graduation (Bachelor's)</option>
                                <option value="Post Graduation">Post-Graduation (Master's)</option>
                                <option value="PhD">M.Phil / Ph.D / Doctorate</option>
                                <option value="Diploma">Diploma / Vocational</option>
                                <option value="Other">Other Academic Level</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('Medium / School Language') }}</label>
                            <select wire:model="profileRequest.school_language" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="Bengali">Bengali</option>
                                <option value="English">English</option>
                                <option value="Hindi">Hindi</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="text-xs font-medium text-foreground">{{ __('UPSC Community Representation') }}</label>
                            <input type="text" wire:model="profileRequest.upsc_community" placeholder="e.g. General / SC" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-medium text-foreground">{{ __('Activities & Achievements') }}</label>
                            <input type="text" wire:model="profileRequest.activities" placeholder="Sports, cultural events, awards..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-foreground">{{ __('Hobbies & Interests') }}</label>
                        <input type="text" wire:model="profileRequest.hobbies" placeholder="Reading non-fiction, chess, debating..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                    </div>

                    <!-- PwBD Candidate Toggle & Fields -->
                    <div class="p-3 rounded-lg border border-border bg-muted/20 space-y-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" wire:model.live="profileRequest.is_pwbd" class="rounded border-input text-primary shadow-xs" />
                            <span class="text-xs font-semibold text-foreground">{{ __('Is Person with Benchmark Disability (PwBD)?') }}</span>
                        </label>

                        @if($profileRequest['is_pwbd'])
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 pt-2 border-t border-border">
                                <div>
                                    <label class="text-xs font-medium text-foreground">{{ __('Disability Type') }} *</label>
                                    <select wire:model="profileRequest.pwbd_type" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="">-- {{ __('Select Type') }} --</option>
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
                                        <option value="MD">Multiple Disabilities</option>
                                        <option value="Other">Other Disability</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-foreground">{{ __('Percentage (%)') }}</label>
                                    <input type="number" min="0" max="100" wire:model="profileRequest.pwd_percentage" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-foreground">{{ __('Certificate Number') }}</label>
                                    <input type="text" wire:model="profileRequest.pwbd_cert_no" placeholder="e.g. WB/PWD/2022/8821" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-foreground">{{ __('Certificate Issue Date') }}</label>
                                    <input type="date" wire:model="profileRequest.pwbd_issued_date" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="text-xs font-medium text-foreground">{{ __('Issued By / Medical Board Authority') }}</label>
                                    <input type="text" wire:model="profileRequest.pwbd_issued_by" placeholder="e.g. District Medical Board, SSKM Hospital" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- 3. Address Fields -->
            @if($requestModalSection === 'address')
                <div class="space-y-4">
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 mb-2.5 flex items-center gap-1.5">
                            <x-icon name="map-pin" class="w-3.5 h-3.5" />
                            {{ __('Permanent Address (Domicile)') }}
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                            <div class="space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('State') }} *</label>
                                <select wire:model.live="addressRequest.permanent_state" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required>
                                    <option value="">{{ __('-- Select State --') }}</option>
                                    @foreach(\App\Helpers\LocationData::getStateNames() as $st)
                                        <option value="{{ $st }}">{{ $st }}</option>
                                    @endforeach
                                </select>
                                @error('addressRequest.permanent_state') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('District') }} *</label>
                                <select wire:model="addressRequest.permanent_district" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required>
                                    <option value="">{{ __('-- Select District --') }}</option>
                                    @foreach(\App\Helpers\LocationData::getDistrictsByState($addressRequest['permanent_state'] ?? 'West Bengal') as $dist)
                                        <option value="{{ $dist }}">{{ $dist }}</option>
                                    @endforeach
                                </select>
                                @error('addressRequest.permanent_district') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('PIN Code') }} *</label>
                                <input type="text" wire:model="addressRequest.permanent_pin_code" placeholder="700064" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                                @error('addressRequest.permanent_pin_code') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('Subdistrict / Block / Tehsil') }}</label>
                                <input type="text" wire:model="addressRequest.permanent_subdistrict" placeholder="e.g. Bidhannagar" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('Post Office') }}</label>
                                <input type="text" wire:model="addressRequest.permanent_post_office" placeholder="e.g. Sealdah P.O." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                            </div>

                            <div class="space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('Police Station') }}</label>
                                <input type="text" wire:model="addressRequest.permanent_police_station" placeholder="e.g. Electronic Complex P.S." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                            </div>

                            <div class="sm:col-span-2 md:col-span-3 space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('Address Line 1') }} *</label>
                                <input type="text" wire:model="addressRequest.permanent_address_line1" placeholder="House/Flat No, Building, Street, Road..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                                @error('addressRequest.permanent_address_line1') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                            </div>

                            <div class="sm:col-span-2 md:col-span-3 space-y-1">
                                <label class="text-xs font-medium text-foreground">{{ __('Address Line 2 (Optional)') }}</label>
                                <input type="text" wire:model="addressRequest.permanent_address_line2" placeholder="Landmark, Apartment/Floor details..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-border">
                        <label class="flex items-center gap-2 mb-3 cursor-pointer">
                            <input type="checkbox" wire:model.live="addressRequest.same_as_permanent" class="rounded border-input text-primary shadow-xs" />
                            <span class="text-xs font-semibold text-foreground">{{ __('Correspondence address is the same as permanent') }}</span>
                        </label>

                        @if(! $addressRequest['same_as_permanent'])
                            <h4 class="text-xs font-bold uppercase tracking-wider text-primary mb-2.5 flex items-center gap-1.5">
                                <x-icon name="mail" class="w-3.5 h-3.5" />
                                {{ __('Correspondence Address (Mailing)') }}
                            </h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('State') }}</label>
                                    <select wire:model.live="addressRequest.correspondence_state" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="">{{ __('-- Select State --') }}</option>
                                        @foreach(\App\Helpers\LocationData::getStateNames() as $st)
                                            <option value="{{ $st }}">{{ $st }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('District') }}</label>
                                    <select wire:model="addressRequest.correspondence_district" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="">{{ __('-- Select District --') }}</option>
                                        @foreach(\App\Helpers\LocationData::getDistrictsByState($addressRequest['correspondence_state'] ?? 'West Bengal') as $dist)
                                            <option value="{{ $dist }}">{{ $dist }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('PIN Code') }}</label>
                                    <input type="text" wire:model="addressRequest.correspondence_pin_code" placeholder="700064" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Subdistrict / Block') }}</label>
                                    <input type="text" wire:model="addressRequest.correspondence_subdistrict" placeholder="e.g. Bidhannagar" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Post Office') }}</label>
                                    <input type="text" wire:model="addressRequest.correspondence_post_office" placeholder="e.g. Salt Lake P.O." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Police Station') }}</label>
                                    <input type="text" wire:model="addressRequest.correspondence_police_station" placeholder="e.g. Bidhannagar North P.S." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>

                                <div class="sm:col-span-2 md:col-span-3 space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Address Line 1') }}</label>
                                    <input type="text" wire:model="addressRequest.correspondence_address_line1" placeholder="House/Flat No, Street, Road..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>

                                <div class="sm:col-span-2 md:col-span-3 space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Address Line 2 (Optional)') }}</label>
                                    <input type="text" wire:model="addressRequest.correspondence_address_line2" placeholder="Landmark, Apartment/Floor details..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- 4. Qualifications / Education Fields -->
            @if($requestModalSection === 'education')
                <div class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Education Level') }} *</label>
                            <select wire:model="educationRequest.level" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required>
                                <option value="10th">10th / Secondary (Madhyamik)</option>
                                <option value="12th">12th / Higher Secondary (HS)</option>
                                <option value="Graduation">Graduation (Bachelor's)</option>
                                <option value="Post Graduation">Post-Graduation (Master's)</option>
                                <option value="PhD">M.Phil / Ph.D / Doctorate</option>
                                <option value="Diploma">Diploma / Vocational</option>
                                <option value="Other">Other Academic Level</option>
                            </select>
                            @error('educationRequest.level') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Board / University') }}</label>
                            <input type="text" wire:model="educationRequest.board_university" placeholder="e.g. Calcutta University / CBSE" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('School / College / Institute') }}</label>
                            <input type="text" wire:model="educationRequest.institute" placeholder="e.g. Presidency College" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="space-y-1 sm:col-span-2">
                            <label class="text-xs font-medium text-foreground">{{ __('Stream / Major Subject') }}</label>
                            <input type="text" wire:model="educationRequest.stream_subject" placeholder="e.g. History / Computer Science" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Passing Year') }}</label>
                            <input type="number" min="1950" max="{{ date('Y') + 5 }}" wire:model="educationRequest.passing_year" placeholder="{{ date('Y') }}" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Completion Status') }}</label>
                            <select wire:model="educationRequest.completion_status" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="Completed">{{ __('Completed') }}</option>
                                <option value="Pursuing">{{ __('Pursuing / Ongoing') }}</option>
                                <option value="Appeared">{{ __('Final Year Appeared') }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Total Marks') }}</label>
                            <input type="number" min="0" wire:model="educationRequest.total_marks" placeholder="e.g. 1000" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Marks Obtained') }}</label>
                            <input type="number" min="0" wire:model="educationRequest.marks_obtained" placeholder="e.g. 785" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Percentage (%)') }}</label>
                            <input type="number" step="0.01" min="0" max="100" wire:model="educationRequest.percentage" placeholder="e.g. 78.50" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('CGPA (out of 10)') }}</label>
                            <input type="number" step="0.01" min="0" max="10" wire:model="educationRequest.cgpa" placeholder="e.g. 8.40" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Division / Grade') }}</label>
                            <select wire:model="educationRequest.division" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="">{{ __('-- Select Division / Grade --') }}</option>
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

                        <div class="flex items-center pt-5">
                            <label class="flex items-center gap-2 cursor-pointer text-xs">
                                <input type="checkbox" wire:model="educationRequest.is_highest" class="rounded border-input text-primary shadow-xs" />
                                <span class="font-medium text-foreground">{{ __("Mark as candidate's highest academic qualification") }}</span>
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            <!-- 5. Employment Fields -->
            @if($requestModalSection === 'employment')
                <div class="space-y-3">
                    @php $isWorkingModal = (bool) ($this->student->studentProfile?->is_working_professional); @endphp
                    <div class="p-2.5 rounded-lg border text-xs {{ $isWorkingModal ? 'bg-amber-500/10 border-amber-500/30 text-amber-800 dark:text-amber-200' : 'bg-muted/40 border-border text-muted-foreground' }}">
                        @if($isWorkingModal)
                            <span class="font-semibold">{{ __('Working Professional Profile:') }}</span>
                            {{ __('Employer, designation, nature of work, and start date are required.') }}
                        @else
                            <span class="font-semibold">{{ __('Non-Working / Student Profile:') }}</span>
                            {{ __('Employment history is optional. Only provide details if you have past work or internship experience.') }}
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Employer / Organization Name') }} *</label>
                            <input type="text" wire:model="employmentRequest.employer_name" placeholder="e.g. State Bank of India / TCS" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                            @error('employmentRequest.employer_name') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Designation / Role') }} {{ $isWorkingModal ? '*' : '' }}</label>
                            <input type="text" wire:model="employmentRequest.designation" placeholder="e.g. Assistant Manager" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" {{ $isWorkingModal ? 'required' : '' }} />
                            @error('employmentRequest.designation') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Nature of Employment') }} {{ $isWorkingModal ? '*' : '' }}</label>
                            <select wire:model="employmentRequest.nature_of_employment" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" {{ $isWorkingModal ? 'required' : '' }}>
                                <option value="Full-time">Full-time Regular</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract / Fixed-Term</option>
                                <option value="Govt / PSU">Govt / PSU Service</option>
                                <option value="Self-employed">Self-employed / Business</option>
                                <option value="Internship">Internship</option>
                                <option value="Other">Other</option>
                            </select>
                            @error('employmentRequest.nature_of_employment') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Work Location') }}</label>
                            <input type="text" wire:model="employmentRequest.location" placeholder="e.g. Kolkata / New Delhi" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('From Date') }} {{ $isWorkingModal ? '*' : '' }}</label>
                            <input type="date" wire:model="employmentRequest.from_date" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" {{ $isWorkingModal ? 'required' : '' }} />
                            @error('employmentRequest.from_date') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('To Date (Leave blank if currently working)') }}</label>
                            <input type="date" wire:model="employmentRequest.to_date" :disabled="(bool) $employmentRequest['is_current']" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="space-y-2 pt-1">
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input type="checkbox" wire:model="employmentRequest.is_current" class="rounded border-input text-primary shadow-xs" />
                            <span class="font-medium text-foreground">{{ __('Candidate is currently working in this role/organization') }}</span>
                        </label>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Job Responsibilities / Description') }}</label>
                            <textarea wire:model="employmentRequest.description" rows="2" placeholder="Brief overview of duties and responsibilities..." class="w-full rounded-md border border-input bg-card p-2 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring"></textarea>
                        </div>
                    </div>
                </div>
            @endif

            <!-- 6. UPSC Details Fields -->
            @if($requestModalSection === 'upsc')
                <div class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="space-y-1 sm:col-span-2">
                            <label class="text-xs font-medium text-foreground">{{ __('Optional Subject Selected') }}</label>
                            <select wire:model="upscRequest.optional_subject" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                <option value="">{{ __('-- Select UPSC Optional Subject --') }}</option>
                                @foreach(\App\Helpers\UpscData::getOptionalSubjects() as $subj)
                                    <option value="{{ $subj }}">{{ $subj }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Target Exam Year') }}</label>
                            <input type="number" min="2000" max="{{ date('Y') + 10 }}" wire:model="upscRequest.year_of_appearing" placeholder="e.g. {{ date('Y') }}" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Attempts Given') }}</label>
                            <input type="number" min="0" max="20" wire:model="upscRequest.attempts_already_given" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Attempts Remaining') }}</label>
                            <input type="number" min="0" max="20" wire:model="upscRequest.attempts_left" placeholder="e.g. 6" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                        </div>
                    </div>

                    <!-- Optional sub-attempt record -->
                    <div class="p-3 bg-muted/20 border border-border rounded-xl space-y-3">
                        <label class="flex items-center gap-2 cursor-pointer text-xs">
                            <input type="checkbox" wire:model.live="upscRequest.add_attempt_record" class="rounded border-input text-primary shadow-xs" />
                            <span class="font-semibold text-foreground">{{ __('Submit / Link an individual UPSC CSE Attempt Record') }}</span>
                        </label>

                        @if(!empty($upscRequest['add_attempt_record']))
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 pt-2 border-t border-border">
                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Exam Year *') }}</label>
                                    <input type="number" min="1990" max="{{ date('Y') + 1 }}" wire:model="upscRequest.exam_year" placeholder="{{ date('Y') }}" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Roll Number') }}</label>
                                    <input type="text" wire:model="upscRequest.roll_number" placeholder="e.g. 0812345" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Prelims Cleared?') }}</label>
                                    <select wire:model="upscRequest.prelims_cleared" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="0">{{ __('No') }}</option>
                                        <option value="1">{{ __('Yes') }}</option>
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Mains Cleared?') }}</label>
                                    <select wire:model="upscRequest.mains_cleared" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="0">{{ __('No') }}</option>
                                        <option value="1">{{ __('Yes') }}</option>
                                    </select>
                                </div>

                                <div class="space-y-1">
                                    <label class="text-xs font-medium text-foreground">{{ __('Interview Cleared?') }}</label>
                                    <select wire:model="upscRequest.interview_cleared" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                        <option value="0">{{ __('No') }}</option>
                                        <option value="1">{{ __('Yes') }}</option>
                                    </select>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- 7. Document Upload Fields -->
            @if($requestModalSection === 'documents')
                <div class="space-y-3">
                    <div class="space-y-1">
                        <label class="text-xs font-medium text-foreground">{{ __('Document Type *') }}</label>
                        <select wire:model.live="documentRequest.document_type" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required>
                            <option value="Photo">{{ __('Candidate Photo') }}</option>
                            <option value="Signature">{{ __('Candidate Signature') }}</option>
                            <option value="10th Admit Card">{{ __('10th Admit Card / Age Proof') }}</option>
                            <option value="10th Marksheet">{{ __('10th / Secondary Marksheet') }}</option>
                            <option value="12th Marksheet">{{ __('12th / Higher Secondary Marksheet') }}</option>
                            <option value="Graduation Certificate">{{ __('Graduation Certificate / Degree') }}</option>
                            <option value="Caste Certificate">{{ __('Caste Certificate (SC/ST/OBC)') }}</option>
                            <option value="PwBD Certificate">{{ __('PwBD Benchmark Disability Certificate') }}</option>
                            <option value="Income Certificate">{{ __('Income Certificate') }}</option>
                            <option value="Aadhaar Card">{{ __('Aadhaar / Photo ID Document') }}</option>
                            <option value="Custom">{{ __('Other / Custom Document Type') }}</option>
                        </select>
                    </div>

                    @if($documentRequest['document_type'] === 'Custom')
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Custom Document Type Name *') }}</label>
                            <input type="text" wire:model="documentRequest.custom_document_type" placeholder="e.g. Migration Certificate, Residential Proof..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" required />
                            @error('documentRequest.custom_document_type') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Option A: Upload File (PDF, JPG, PNG up to 10MB)') }}</label>
                            <input type="file" wire:model="documentRequest.document_file" class="w-full text-xs file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90" />
                            @error('documentRequest.document_file') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>

                        <div class="space-y-1">
                            <label class="text-xs font-medium text-foreground">{{ __('Option B: External / OTR Document URL') }}</label>
                            <input type="url" wire:model="documentRequest.file_url" placeholder="https://drive.google.com/... or OTR link" class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                            @error('documentRequest.file_url') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-medium text-foreground">{{ __('Description / Note (Optional)') }}</label>
                        <input type="text" wire:model="documentRequest.description" placeholder="Brief note about this document..." class="w-full h-9 rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring" />
                    </div>
                </div>
            @endif

            <!-- MANDATORY JUSTIFICATION & SUPPORTING PROOF -->
            <div class="pt-3 border-t border-border space-y-3">
                <div>
                    <label class="text-xs font-semibold text-foreground flex items-center justify-between">
                        <span>{{ __('Reason / Justification for Change') }} *</span>
                        <span class="text-[10px] text-muted-foreground">{{ __('Required for Admin Approval') }}</span>
                    </label>
                    <textarea
                        wire:model="requestReason"
                        rows="3"
                        placeholder="{{ __('Please explain why this update is needed (e.g. typo correction, updated address, changed contact number, new educational certificate)...') }}"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-xs shadow-xs outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        required
                    ></textarea>
                    @error('requestReason') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                </div>

                @if($requestModalSection !== 'documents')
                    <div>
                        <label class="text-xs font-medium text-foreground block">
                            {{ __('Supporting Document Proof (Optional, Recommended)') }}
                        </label>
                        <input type="file" wire:model="requestProofFile" class="w-full text-xs file:mr-4 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80" />
                        <span class="text-[10px] text-muted-foreground block mt-0.5">{{ __('Attach Govt ID, Mark Sheet, or Certificate backing your request.') }}</span>
                        @error('requestProofFile') <span class="text-rose-500 text-[11px]">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            <!-- MODAL BUTTONS -->
            <div class="flex items-center justify-between pt-4 border-t border-border">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('student-request-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" class="gap-1.5 bg-primary text-primary-foreground">
                    <x-icon name="send" class="w-3.5 h-3.5" />
                    {{ __('Submit Request to Admin') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- VIEW DIFF MODAL -->
    <x-ui.modal name="view-diff-modal" max-width="max-w-2xl" :title="__('Correction Request Details')" :description="__('Inspect requested updates and administrator decisions.')">
        @if($viewingRequest)
            <div class="space-y-4 text-xs">
                <div class="flex items-center justify-between p-3 rounded-lg bg-muted/40 border border-border">
                    <div>
                        <span class="font-mono font-bold text-foreground text-sm">{{ $viewingRequest->request_no }}</span>
                        <div class="text-[11px] text-muted-foreground">{{ $viewingRequest->created_at->format('d M Y, h:i A') }}</div>
                    </div>
                    @php
                        $badgeVar = match($viewingRequest->status) {
                            'approved' => 'success',
                            'rejected' => 'destructive',
                            default => 'warning',
                        };
                    @endphp
                    <x-ui.badge :variant="$badgeVar" class="capitalize">
                        {{ ucfirst($viewingRequest->status) }}
                    </x-ui.badge>
                </div>

                <!-- Justification -->
                <div class="p-3 rounded-lg border border-border bg-card space-y-1">
                    <span class="font-semibold text-foreground">{{ __('My Reason / Justification:') }}</span>
                    <p class="text-muted-foreground leading-relaxed">{{ $viewingRequest->reason }}</p>
                </div>

                <!-- Admin Notes -->
                @if($viewingRequest->admin_notes)
                    <div class="p-3 rounded-lg border border-border bg-amber-50/40 dark:bg-amber-950/20 space-y-1">
                        <span class="font-semibold text-amber-800 dark:text-amber-300">{{ __('Administrator Review Notes:') }}</span>
                        <p class="text-amber-900 dark:text-amber-200 leading-relaxed">{{ $viewingRequest->admin_notes }}</p>
                    </div>
                @endif

                <!-- Supporting Proof Link -->
                @if($viewingRequest->document_proof)
                    <div class="p-3 rounded-lg border border-border bg-muted/30 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-icon name="paperclip" class="w-4 h-4 text-primary" />
                            <span class="font-medium text-foreground">{{ __('Supporting Proof Attached') }}</span>
                        </div>
                        <a href="{{ Storage::url($viewingRequest->document_proof) }}" target="_blank" class="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                            <x-icon name="external-link" class="w-3.5 h-3.5" />
                            {{ __('View File') }}
                        </a>
                    </div>
                @endif

                <!-- Proposed Changes Diff Table -->
                <div>
                    <h4 class="font-semibold uppercase tracking-wider text-muted-foreground mb-2">{{ __('Proposed Changes Diff') }}</h4>
                    <div class="border border-border rounded-lg overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-muted/50 border-b border-border text-muted-foreground font-semibold">
                                <tr>
                                    <th class="p-2.5 w-1/3">{{ __('Field') }}</th>
                                    <th class="p-2.5 w-1/3 text-rose-600 dark:text-rose-400">{{ __('Current') }}</th>
                                    <th class="p-2.5 w-1/3 text-emerald-600 dark:text-emerald-400">{{ __('Proposed') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @forelse($viewingRequest->payload as $field => $newVal)
                                    @php $oldVal = $viewingRequest->current_data[$field] ?? '—'; @endphp
                                    <tr>
                                        <td class="p-2.5 font-semibold capitalize text-foreground">{{ str_replace('_', ' ', $field) }}</td>
                                        <td class="p-2.5 bg-rose-50/50 dark:bg-rose-950/20 text-rose-700 dark:text-rose-400">
                                            {{ is_array($oldVal) ? json_encode($oldVal) : ($oldVal ?: '—') }}
                                        </td>
                                        <td class="p-2.5 bg-emerald-50/50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400 font-semibold">
                                            {{ is_array($newVal) ? json_encode($newVal) : ($newVal ?: '—') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="p-3 text-center text-muted-foreground">{{ __('No field changes recorded.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex justify-end pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('view-diff-modal')">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- CONFIRM WITHDRAW REQUEST MODAL -->
    <x-ui.modal name="confirm-withdraw-modal" max-width="max-w-lg" :title="__('Confirm Withdrawal')">
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-100 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0">
                    <x-icon name="alert-triangle" class="w-5 h-5" />
                </div>
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-foreground">
                        {{ __('Are you sure you want to withdraw this correction request?') }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ __('Once withdrawn, this request will be cancelled and administrators will no longer review it. This action cannot be undone.') }}
                    </p>
                </div>
            </div>

            @if($withdrawingRequest)
                <div class="p-3 bg-muted/40 border border-border rounded-lg text-xs space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Request No:') }}</span>
                        <span class="font-mono font-bold text-foreground">{{ $withdrawingRequest->request_no }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Section:') }}</span>
                        <span class="capitalize font-semibold text-foreground">{{ $withdrawingRequest->section }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Date Submitted:') }}</span>
                        <span class="text-foreground">{{ $withdrawingRequest->created_at->format('d M Y, h:i A') }}</span>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2 pt-2 border-t border-border">
                <x-ui.button variant="outline" type="button" wire:click="cancelWithdraw">
                    {{ __('Cancel') }}
                </x-ui.button>

                <x-ui.button variant="destructive" type="button" wire:click="executeWithdrawRequest">
                    <x-icon name="trash-2" class="w-4 h-4 mr-1.5" />
                    {{ __('Withdraw Request') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Course Withdrawal / Discontinuation Request Modal (Enhanced Modern UI/UX) -->
    <x-ui.modal
        name="course-withdrawal-modal"
        max-width="max-w-xl"
        :title="__('Request Course Withdrawal or Discontinuation')"
        :description="__('Submit a formal request to withdraw or discontinue from your enrolled course.')"
    >
        @if($withdrawalEnrollment)
            <form wire:submit="submitWithdrawalRequest" class="space-y-4">
                <!-- Course & Enrollment Identifier Card -->
                <div class="p-3.5 bg-muted/40 rounded-xl border border-border space-y-1.5">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <span class="font-bold text-sm text-foreground">
                            {{ $withdrawalEnrollment->course?->name }} ({{ $withdrawalEnrollment->course?->code }})
                        </span>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            @if(!empty($this->student->urn))
                                <x-ui.badge variant="outline" class="font-mono text-[11px] gap-1" title="{{ __('Universal Registration Number') }}">
                                    <span class="text-muted-foreground">{{ __('URN:') }}</span>
                                    <span class="font-bold text-foreground">{{ $this->student->urn }}</span>
                                </x-ui.badge>
                            @endif
                            <x-ui.badge variant="secondary" class="font-mono text-[11px]">
                                {{ __('Student ID: ') }}<strong>{{ $withdrawalEnrollment->student_id ?: $this->student->student_id }}</strong>
                            </x-ui.badge>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-muted-foreground flex-wrap">
                        <span>{{ __('Batch:') }} <strong>{{ $withdrawalEnrollment->batch?->name }}</strong></span>
                        <span>&bull;</span>
                        <span>{{ __('Enrollment No:') }} <strong class="font-mono text-primary">{{ $withdrawalEnrollment->enrollment_no }}</strong></span>
                        @if($withdrawalEnrollment->section)
                            <span>&bull;</span>
                            <span>{{ __('Section:') }} <strong>{{ $withdrawalEnrollment->section->name }}</strong></span>
                        @endif
                    </div>
                </div>

                <!-- Request Nature Selection: Interactive Choice Cards -->
                <div class="space-y-1.5">
                    <label class="text-xs font-semibold text-foreground block">
                        {{ __('Choose Request Nature *') }}
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button
                            type="button"
                            wire:click="$set('withdrawalForm.request_type', 'withdrawal')"
                            class="p-3 rounded-lg border text-left transition-all cursor-pointer {{ ($withdrawalForm['request_type'] ?? '') === 'withdrawal' ? 'border-destructive bg-destructive/5 ring-1 ring-destructive' : 'border-border bg-card hover:bg-muted/40' }}"
                        >
                            <div class="flex items-center justify-between font-semibold text-xs text-foreground">
                                <span>{{ __('Voluntary Withdrawal') }}</span>
                                <x-icon name="{{ ($withdrawalForm['request_type'] ?? '') === 'withdrawal' ? 'check-circle' : 'circle' }}" class="w-4 h-4 {{ ($withdrawalForm['request_type'] ?? '') === 'withdrawal' ? 'text-destructive' : 'text-muted-foreground' }}" />
                            </div>
                            <p class="text-[11px] text-muted-foreground mt-1 leading-snug">
                                {{ __('Completely exit the course programme and permanently release your seat.') }}
                            </p>
                        </button>

                        <button
                            type="button"
                            wire:click="$set('withdrawalForm.request_type', 'discontinuation')"
                            class="p-3 rounded-lg border text-left transition-all cursor-pointer {{ ($withdrawalForm['request_type'] ?? '') === 'discontinuation' ? 'border-amber-500 bg-amber-500/5 ring-1 ring-amber-500' : 'border-border bg-card hover:bg-muted/40' }}"
                        >
                            <div class="flex items-center justify-between font-semibold text-xs text-foreground">
                                <span>{{ __('Academic Discontinuation') }}</span>
                                <x-icon name="{{ ($withdrawalForm['request_type'] ?? '') === 'discontinuation' ? 'check-circle' : 'circle' }}" class="w-4 h-4 {{ ($withdrawalForm['request_type'] ?? '') === 'discontinuation' ? 'text-amber-500' : 'text-muted-foreground' }}" />
                            </div>
                            <p class="text-[11px] text-muted-foreground mt-1 leading-snug">
                                {{ __('Discontinue studies due to medical conditions, employment appointment, or outstation transfer.') }}
                            </p>
                        </button>
                    </div>
                    @error('withdrawalForm.request_type')
                        <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Primary Reason Category -->
                <div class="space-y-1">
                    <label class="text-xs font-semibold text-foreground block">{{ __('Primary Reason Category *') }}</label>
                    <select
                        wire:model="withdrawalForm.reason_category"
                        class="h-9 w-full rounded-md border border-input bg-card text-foreground px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        required
                    >
                        <option value="health_medical">{{ __('Medical / Health Concerns') }}</option>
                        <option value="employment_selection">{{ __('Selected in Employment / Job Joining') }}</option>
                        <option value="upsc_selection">{{ __('Selected in UPSC / Other Exam') }}</option>
                        <option value="relocation">{{ __('Family Relocation / Outstation Movement') }}</option>
                        <option value="financial">{{ __('Financial Constraints / Circumstances') }}</option>
                        <option value="personal">{{ __('Personal / Family Grounds') }}</option>
                        <option value="academic_difficulty">{{ __('Schedule Conflict / Academic Load') }}</option>
                        <option value="other">{{ __('Other Specific Grounds') }}</option>
                    </select>
                    @error('withdrawalForm.reason_category')
                        <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Detailed Narrative Justification -->
                <div class="space-y-1">
                    <x-ui.textarea
                        wire:model="withdrawalForm.reason_description"
                        label="{{ __('Detailed Justification / Statement *') }}"
                        placeholder="{{ __('Please describe why you wish to withdraw or discontinue from this course...') }}"
                        rows="3"
                        :error="$errors->first('withdrawalForm.reason_description')"
                        required
                    />
                    <div class="flex justify-between text-[10px] text-muted-foreground pt-0.5">
                        <span>{{ __('Minimum 10 characters required.') }}</span>
                        <span>{{ strlen($withdrawalForm['reason_description'] ?? '') }}/1500</span>
                    </div>
                </div>

                <!-- Supporting Document Upload with Progress Indicator -->
                <div class="space-y-1.5 p-3 rounded-lg border border-border/80 bg-muted/20">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-foreground">
                            {{ __('Supporting Document (Optional)') }}
                        </label>
                        <span class="text-[10px] text-muted-foreground">{{ __('PDF, JPG, PNG up to 10MB') }}</span>
                    </div>

                    <input
                        type="file"
                        wire:model="withdrawalDocument"
                        accept=".pdf,.png,.jpg,.jpeg"
                        class="h-9 w-full rounded-md border border-input bg-card text-foreground px-3 text-xs file:mr-2 file:border-0 file:bg-transparent file:text-xs file:font-semibold"
                    />

                    <!-- Upload State -->
                    <div wire:loading wire:target="withdrawalDocument" class="text-xs text-primary font-medium flex items-center gap-2 pt-1">
                        <x-icon name="loader-2" class="w-4 h-4 animate-spin text-primary shrink-0" />
                        <span>{{ __('Uploading document proof, please wait...') }}</span>
                    </div>

                    @if($withdrawalDocument)
                        <div class="flex items-center justify-between text-xs text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 p-2 rounded border border-emerald-500/20 mt-1">
                            <span class="flex items-center gap-1.5 truncate">
                                <x-icon name="check-circle" class="w-4 h-4 shrink-0" />
                                <span class="truncate font-medium">{{ $withdrawalDocument->getClientOriginalName() }}</span>
                            </span>
                            <button type="button" wire:click="clearWithdrawalDocument" class="text-rose-600 hover:text-rose-700 text-xs font-bold shrink-0 ml-2">
                                {{ __('Remove') }}
                            </button>
                        </div>
                    @endif

                    @error('withdrawalDocument')
                        <p class="text-xs text-destructive mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Advisory Notice -->
                <div class="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 rounded-lg text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2">
                    <x-icon name="alert-triangle" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                    <span>{{ __('Once approved by administration, your course enrollment will be marked as Withdrawn/Discontinued and your seat allocation released.') }}</span>
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <x-ui.button variant="outline" type="button" wire:click="closeWithdrawalModal">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="submit" variant="destructive" wire:loading.attr="disabled" class="gap-1.5">
                        <span wire:loading.remove wire:target="submitWithdrawalRequest">{{ __('Submit Request') }}</span>
                        <span wire:loading wire:target="submitWithdrawalRequest" class="inline-flex items-center gap-1.5">
                            <x-icon name="loader-2" class="w-3.5 h-3.5 animate-spin" />
                            {{ __('Submitting...') }}
                        </span>
                    </x-ui.button>
                </div>
            </form>
        @else
            <div class="p-6 text-center text-xs text-muted-foreground">
                <x-icon name="loader-2" class="w-6 h-6 animate-spin text-primary mx-auto mb-2" />
                <p>{{ __('Loading enrollment details...') }}</p>
            </div>
        @endif
    </x-ui.modal>

    <!-- OPTIONAL SUBJECT CHANGE REQUEST MODAL -->
    @if($showOptionalSubjectChangeModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="arrow-path" class="w-5 h-5 text-purple-600" />
                            {{ __('Request Optional Subject Change') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Submit formal request to administrative board to update your selected UPSC optional subject.') }}
                        </p>
                    </div>
                    <button type="button" wire:click="$set('showOptionalSubjectChangeModal', false)" class="text-zinc-400 hover:text-zinc-600">
                        <x-icon name="x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <div class="space-y-4 text-xs">
                    <!-- Current Selection Display -->
                    <div class="p-3.5 rounded-xl border border-purple-200 dark:border-purple-800/60 bg-purple-50/50 dark:bg-purple-950/20 flex items-center justify-between">
                        <div>
                            <span class="text-zinc-500 block text-[11px]">{{ __('Current Selected Subject') }}</span>
                            <span class="font-bold text-purple-700 dark:text-purple-300 text-sm">
                                {{ $this->student->studentOtherDetails?->optional_subject ?: __('None / Not Selected') }}
                            </span>
                        </div>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300">
                            {{ __('Active') }}
                        </span>
                    </div>

                    <!-- New Desired Subject Selection -->
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 block">
                            {{ __('Requested New Optional Subject *') }}
                        </label>
                        <select
                            wire:model="requestedOptionalSubject"
                            class="h-10 w-full rounded-md border border-input bg-card px-3 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option value="">{{ __('-- Select Desired Optional Subject --') }}</option>
                            @foreach($this->activeOptionalSubjects as $sub)
                                <option value="{{ $sub->name }}">{{ $sub->name }} ({{ $sub->category }})</option>
                            @endforeach
                        </select>
                        @error('requestedOptionalSubject')
                            <p class="text-xs text-rose-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Narrative Reason for Change -->
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 block">
                            {{ __('Reason for Change / Statement of Justification *') }}
                        </label>
                        <textarea
                            wire:model="optionalChangeReason"
                            rows="4"
                            class="w-full rounded-md border border-input bg-card p-3 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            placeholder="{{ __('Please describe why you wish to change your optional subject (e.g., academic background, alignment with graduate degree, guidance from faculty)...') }}"
                        ></textarea>
                        @error('optionalChangeReason')
                            <p class="text-xs text-rose-500 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Standing Advisory Notice -->
                    <div class="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 rounded-lg text-[11px] text-amber-800 dark:text-amber-300 flex items-start gap-2">
                        <x-icon name="information-circle" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                        <span>{{ __('Once submitted, your request is reviewed by the academic coordinator. Upon administrative approval, your student record and classroom optional batches will update automatically.') }}</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <x-ui.button type="button" wire:click="$set('showOptionalSubjectChangeModal', false)" variant="outline" size="sm" class="text-xs">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="submitOptionalSubjectChangeRequest" variant="primary" size="sm" class="gap-1.5 text-xs font-semibold bg-purple-600 hover:bg-purple-700 text-white" wire:loading.attr="disabled">
                        <x-icon name="paper-airplane" class="w-3.5 h-3.5" />
                        <span wire:loading.remove>{{ __('Submit Request') }}</span>
                        <span wire:loading>{{ __('Submitting...') }}</span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
