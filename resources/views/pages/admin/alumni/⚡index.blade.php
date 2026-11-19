<?php

use App\Models\Alumni;
use App\Models\AlumniCourseBatch;
use App\Models\AlumniExamination;
use App\Models\AlumniPosting;
use App\Models\AlumniServiceCategory;
use App\Models\Batch;
use App\Models\Course;
use App\Models\User;
use App\Services\Alumni\AlumniImportExportService;
use App\Services\Alumni\AlumniService;
use App\Services\Alumni\AlumniServiceCategoryService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Alumni Management')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tab')]
    public string $activeTab = 'all'; // all, upsc, wbcs, banking, featured, trashed

    #[Url(as: 'service')]
    public string $serviceTypeFilter = '';

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'year')]
    public string $yearFilter = '';

    #[Url(as: 'state')]
    public string $stateFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';
    public int $perPage = 12;
    public string $viewMode = 'grid'; // grid, table

    // Form Modal state
    public bool $showFormModal = false;
    public ?int $editingId = null;
    public string $formTab = 'personal'; // personal, service, examinations, postings, courses, bio

    public array $form = [
        'user_id' => null,
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'alternate_phone' => '',
        'gender' => 'male',
        'dob' => '',
        'passing_year' => '',
        'service_type' => 'UPSC CSE',
        'service_name' => 'IAS',
        'designation' => '',
        'cadre' => 'West Bengal',
        'all_india_rank' => '',
        'state_rank' => '',
        'exam_year' => '',
        'roll_number' => '',
        'posting_city' => '',
        'posting_district' => '',
        'posting_state' => 'West Bengal',
        'posting_country' => 'India',
        'department_ministry' => '',
        'posting_date' => '',
        'office_address' => '',
        'current_address' => '',
        'permanent_address' => '',
        'linkedin_url' => '',
        'twitter_url' => '',
        'website_url' => '',
        'short_bio' => '',
        'detailed_bio' => '',
        'success_story' => '',
        'testimonial' => '',
        'is_featured' => false,
        'is_verified' => true,
        'status' => 'active',
        'source' => 'manual',
    ];

    public $photoFile = null;
    public ?string $existingPhotoUrl = null;

    // Course Batches repeatable rows
    public array $courseBatches = [];

    // Examination Selections repeatable rows
    public array $examinations = [];

    // Postings History repeatable rows
    public array $postings = [];

    // Quick Postings History Management Modal state
    public bool $showPostingsHistoryModal = false;
    public ?int $managePostingAlumniId = null;
    public ?Alumni $managePostingAlumni = null;
    public array $quickPosting = [
        'designation' => '',
        'department_ministry' => '',
        'posting_city' => '',
        'posting_district' => '',
        'posting_state' => 'West Bengal',
        'posting_country' => 'India',
        'office_address' => '',
        'from_date' => '',
        'to_date' => '',
        'is_current' => true,
        'order_number' => '',
        'responsibilities' => '',
        'remarks' => '',
    ];

    // Dossier Modal
    public bool $showViewModal = false;
    public ?Alumni $viewingAlumni = null;

    // Convert Student Modal
    public bool $showConvertModal = false;
    public string $studentSearchQuery = '';

    // Delete Modals
    public bool $showDeleteModal = false;
    public ?int $deleteId = null;
    public bool $showForceDeleteModal = false;
    public ?int $forceDeleteId = null;

    // Import Modal
    public bool $showImportModal = false;
    public $importFile = null;
    public ?array $importSummary = null;

    // Service Categories Management Modals state
    public bool $showCategoriesModal = false;
    public bool $showCategoryFormModal = false;
    public ?int $editingCategoryId = null;
    public string $categorySearch = '';
    public array $categoryForm = [
        'name' => '',
        'code' => '',
        'short_name' => '',
        'description' => '',
        'icon' => 'award',
        'badge_color' => 'primary',
        'is_active' => true,
        'display_order' => 0,
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedServiceTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCourseFilter(): void
    {
        $this->resetPage();
    }

    public function updatedBatchFilter(): void
    {
        $this->resetPage();
    }

    public function updatedYearFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
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

    public function resetFilters(): void
    {
        $this->search = '';
        $this->activeTab = 'all';
        $this->serviceTypeFilter = '';
        $this->courseFilter = '';
        $this->batchFilter = '';
        $this->yearFilter = '';
        $this->stateFilter = '';
        $this->statusFilter = '';
        $this->resetPage();
    }

    #[Computed]
    public function stats(): array
    {
        return AlumniService::getStatistics();
    }

    #[Computed]
    public function availableCourses()
    {
        return Course::orderBy('name')->get(['id', 'name', 'code']);
    }

    #[Computed]
    public function availableBatches()
    {
        return Batch::orderByDesc('academic_year')->orderBy('name')->get(['id', 'name', 'code', 'academic_year']);
    }

    #[Computed]
    public function availableServiceTypes(): array
    {
        return Alumni::getServiceTypes();
    }

    #[Computed]
    public function allCategoriesList()
    {
        return AlumniServiceCategory::query()
            ->search($this->categorySearch)
            ->ordered()
            ->withCount(['alumni'])
            ->get();
    }

    #[Computed]
    public function studentSearchResults()
    {
        return AlumniService::searchStudentsForConversion($this->studentSearchQuery);
    }

    #[Computed]
    public function alumniList()
    {
        $query = Alumni::query()
            ->with(['courseBatches.course', 'courseBatches.batch', 'examinations', 'postings', 'creator'])
            ->search($this->search);

        if ($this->activeTab === 'trashed') {
            $query->onlyTrashed();
        } else {
            if ($this->activeTab === 'upsc') {
                $query->where('service_type', 'like', '%UPSC%');
            } elseif ($this->activeTab === 'wbcs') {
                $query->where(function ($q) {
                    $q->where('service_type', 'like', '%WBCS%')
                        ->orWhere('service_type', 'like', '%WBPS%');
                });
            } elseif ($this->activeTab === 'banking') {
                $query->where('service_type', 'like', '%Banking%');
            } elseif ($this->activeTab === 'featured') {
                $query->where('is_featured', true);
            }

            if (! empty($this->serviceTypeFilter)) {
                $query->where('service_type', $this->serviceTypeFilter);
            }

            if (! empty($this->statusFilter)) {
                $query->where('status', $this->statusFilter);
            }

            if (! empty($this->yearFilter)) {
                $year = (int) $this->yearFilter;
                $query->where(function ($q) use ($year) {
                    $q->where('exam_year', $year)
                        ->orWhere('passing_year', $year)
                        ->orWhereHas('examinations', function ($eq) use ($year) {
                            $eq->where('exam_year', $year);
                        });
                });
            }

            if (! empty($this->stateFilter)) {
                $query->where('posting_state', $this->stateFilter);
            }

            if (! empty($this->courseFilter)) {
                $cId = (int) $this->courseFilter;
                $query->whereHas('courseBatches', function ($q) use ($cId) {
                    $q->where('course_id', $cId);
                });
            }

            if (! empty($this->batchFilter)) {
                $bId = (int) $this->batchFilter;
                $query->whereHas('courseBatches', function ($q) use ($bId) {
                    $q->where('batch_id', $bId);
                });
            }
        }

        return $query->orderBy($this->sortField, $this->sortDirection)
            ->paginate($this->perPage);
    }

    public function openCreateModal(): void
    {
        $this->authorize('alumni.create');
        $this->resetErrorBag();
        $this->editingId = null;
        $this->photoFile = null;
        $this->existingPhotoUrl = null;
        $this->formTab = 'personal';

        $this->form = [
            'user_id' => null,
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'phone' => '',
            'alternate_phone' => '',
            'gender' => 'male',
            'dob' => '',
            'passing_year' => (string) now()->year,
            'service_type' => 'UPSC CSE',
            'service_name' => 'IAS',
            'designation' => '',
            'cadre' => 'West Bengal',
            'all_india_rank' => '',
            'state_rank' => '',
            'exam_year' => (string) now()->subYear()->year,
            'roll_number' => '',
            'posting_city' => '',
            'posting_district' => '',
            'posting_state' => 'West Bengal',
            'posting_country' => 'India',
            'department_ministry' => '',
            'posting_date' => now()->format('Y-m-d'),
            'office_address' => '',
            'current_address' => '',
            'permanent_address' => '',
            'linkedin_url' => '',
            'twitter_url' => '',
            'website_url' => '',
            'short_bio' => '',
            'detailed_bio' => '',
            'success_story' => '',
            'testimonial' => '',
            'is_featured' => false,
            'is_verified' => true,
            'status' => 'active',
            'source' => 'manual',
        ];

        // Default 1 blank course/batch row
        $this->courseBatches = [
            [
                'id' => null,
                'course_id' => '',
                'batch_id' => '',
                'course_name' => '',
                'batch_name' => '',
                'enrollment_no' => '',
                'roll_no' => '',
                'academic_year' => '',
                'start_year' => '',
                'completion_year' => (string) now()->year,
                'status' => 'completed',
                'remarks' => '',
            ],
        ];

        // Default 1 examination row
        $this->examinations = [
            [
                'id' => null,
                'examination_name' => '',
                'exam_year' => '',
                'service_allotted' => '',
                'all_india_rank' => '',
                'state_rank' => '',
                'roll_number' => '',
                'optional_subject' => '',
                'score_or_marks' => '',
                'category_rank' => '',
                'is_primary' => true,
                'remarks' => '',
            ],
        ];

        // Default 1 posting row
        $this->postings = [
            [
                'id' => null,
                'designation' => '',
                'department_ministry' => '',
                'posting_city' => '',
                'posting_district' => '',
                'posting_state' => 'West Bengal',
                'posting_country' => 'India',
                'office_address' => '',
                'from_date' => now()->format('Y-m-d'),
                'to_date' => '',
                'is_current' => true,
                'order_number' => '',
                'responsibilities' => '',
                'remarks' => '',
            ],
        ];

        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->authorize('alumni.edit');
        $this->resetErrorBag();
        $alumni = Alumni::with(['courseBatches', 'examinations', 'postings'])->findOrFail($id);

        $this->editingId = $alumni->id;
        $this->photoFile = null;
        $this->existingPhotoUrl = $alumni->photo_display_url;
        $this->formTab = 'personal';

        $this->form = [
            'user_id' => $alumni->user_id,
            'first_name' => $alumni->first_name,
            'last_name' => $alumni->last_name,
            'email' => $alumni->email ?? '',
            'phone' => $alumni->phone ?? '',
            'alternate_phone' => $alumni->alternate_phone ?? '',
            'gender' => $alumni->gender ?? 'male',
            'dob' => $alumni->dob ? $alumni->dob->format('Y-m-d') : '',
            'passing_year' => (string) ($alumni->passing_year ?? ''),
            'service_type' => $alumni->service_type,
            'service_name' => $alumni->service_name,
            'designation' => $alumni->designation,
            'cadre' => $alumni->cadre ?? '',
            'all_india_rank' => (string) ($alumni->all_india_rank ?? ''),
            'state_rank' => (string) ($alumni->state_rank ?? ''),
            'exam_year' => (string) ($alumni->exam_year ?? ''),
            'roll_number' => $alumni->roll_number ?? '',
            'posting_city' => $alumni->posting_city ?? '',
            'posting_district' => $alumni->posting_district ?? '',
            'posting_state' => $alumni->posting_state ?? 'West Bengal',
            'posting_country' => $alumni->posting_country ?? 'India',
            'department_ministry' => $alumni->department_ministry ?? '',
            'posting_date' => $alumni->posting_date ? $alumni->posting_date->format('Y-m-d') : '',
            'office_address' => $alumni->office_address ?? '',
            'current_address' => $alumni->current_address ?? '',
            'permanent_address' => $alumni->permanent_address ?? '',
            'linkedin_url' => $alumni->linkedin_url ?? '',
            'twitter_url' => $alumni->twitter_url ?? '',
            'website_url' => $alumni->website_url ?? '',
            'short_bio' => $alumni->short_bio ?? '',
            'detailed_bio' => $alumni->detailed_bio ?? '',
            'success_story' => $alumni->success_story ?? '',
            'testimonial' => $alumni->testimonial ?? '',
            'is_featured' => (bool) $alumni->is_featured,
            'is_verified' => (bool) $alumni->is_verified,
            'status' => $alumni->status,
            'source' => $alumni->source,
        ];

        // Course batches
        $this->courseBatches = [];
        foreach ($alumni->courseBatches as $cb) {
            $this->courseBatches[] = [
                'id' => $cb->id,
                'course_id' => $cb->course_id ? (string) $cb->course_id : '',
                'batch_id' => $cb->batch_id ? (string) $cb->batch_id : '',
                'course_name' => $cb->course_name,
                'batch_name' => $cb->batch_name,
                'enrollment_no' => $cb->enrollment_no ?? '',
                'roll_no' => $cb->roll_no ?? '',
                'academic_year' => $cb->academic_year ?? '',
                'start_year' => (string) ($cb->start_year ?? ''),
                'completion_year' => (string) ($cb->completion_year ?? ''),
                'status' => $cb->status,
                'remarks' => $cb->remarks ?? '',
            ];
        }
        if (empty($this->courseBatches)) {
            $this->addCourseBatchRow();
        }

        // Examinations
        $this->examinations = [];
        foreach ($alumni->examinations as $ex) {
            $this->examinations[] = [
                'id' => $ex->id,
                'examination_name' => $ex->examination_name,
                'exam_year' => (string) $ex->exam_year,
                'service_allotted' => $ex->service_allotted ?? '',
                'all_india_rank' => (string) ($ex->all_india_rank ?? ''),
                'state_rank' => (string) ($ex->state_rank ?? ''),
                'roll_number' => $ex->roll_number ?? '',
                'optional_subject' => $ex->optional_subject ?? '',
                'score_or_marks' => $ex->score_or_marks ?? '',
                'category_rank' => $ex->category_rank ?? '',
                'is_primary' => (bool) $ex->is_primary,
                'remarks' => $ex->remarks ?? '',
            ];
        }

        // Postings History
        $this->postings = [];
        foreach ($alumni->postings as $p) {
            $this->postings[] = [
                'id' => $p->id,
                'designation' => $p->designation,
                'department_ministry' => $p->department_ministry ?? '',
                'posting_city' => $p->posting_city ?? '',
                'posting_district' => $p->posting_district ?? '',
                'posting_state' => $p->posting_state ?? 'West Bengal',
                'posting_country' => $p->posting_country ?? 'India',
                'office_address' => $p->office_address ?? '',
                'from_date' => $p->from_date ? $p->from_date->format('Y-m-d') : '',
                'to_date' => $p->to_date ? $p->to_date->format('Y-m-d') : '',
                'is_current' => (bool) $p->is_current,
                'order_number' => $p->order_number ?? '',
                'responsibilities' => $p->responsibilities ?? '',
                'remarks' => $p->remarks ?? '',
            ];
        }

        $this->showFormModal = true;
    }

    public function addCourseBatchRow(): void
    {
        $this->courseBatches[] = [
            'id' => null,
            'course_id' => '',
            'batch_id' => '',
            'course_name' => '',
            'batch_name' => '',
            'enrollment_no' => '',
            'roll_no' => '',
            'academic_year' => '',
            'start_year' => '',
            'completion_year' => (string) now()->year,
            'status' => 'completed',
            'remarks' => '',
        ];
    }

    public function removeCourseBatchRow(int $index): void
    {
        if (isset($this->courseBatches[$index])) {
            unset($this->courseBatches[$index]);
            $this->courseBatches = array_values($this->courseBatches);
        }

        if (empty($this->courseBatches)) {
            $this->addCourseBatchRow();
        }
    }

    public function onCourseSelected(int $index): void
    {
        if (! isset($this->courseBatches[$index])) {
            return;
        }

        $courseId = $this->courseBatches[$index]['course_id'];
        if ($courseId) {
            $course = Course::find($courseId);
            if ($course) {
                $this->courseBatches[$index]['course_name'] = $course->name;
            }
        }
    }

    public function onBatchSelected(int $index): void
    {
        if (! isset($this->courseBatches[$index])) {
            return;
        }

        $batchId = $this->courseBatches[$index]['batch_id'];
        if ($batchId) {
            $batch = Batch::find($batchId);
            if ($batch) {
                $this->courseBatches[$index]['batch_name'] = $batch->name;
                if (! empty($batch->academic_year)) {
                    $this->courseBatches[$index]['academic_year'] = $batch->academic_year;
                }
            }
        }
    }

    public function addExamRow(): void
    {
        $this->examinations[] = [
            'id' => null,
            'examination_name' => 'Civil Services Examination',
            'exam_year' => (string) now()->year,
            'service_allotted' => '',
            'all_india_rank' => '',
            'state_rank' => '',
            'roll_number' => '',
            'optional_subject' => '',
            'score_or_marks' => '',
            'category_rank' => '',
            'is_primary' => count($this->examinations) === 0,
            'remarks' => '',
        ];
    }

    public function removeExamRow(int $index): void
    {
        if (isset($this->examinations[$index])) {
            unset($this->examinations[$index]);
            $this->examinations = array_values($this->examinations);
        }
        if (empty($this->examinations)) {
            $this->addExamRow();
        }
    }

    public function setPrimaryExamRow(int $index): void
    {
        foreach ($this->examinations as $i => &$exam) {
            $exam['is_primary'] = ($i === $index);
        }
    }

    public function addPostingRow(): void
    {
        $this->postings[] = [
            'id' => null,
            'designation' => $this->form['designation'] ?: 'Officer',
            'department_ministry' => '',
            'posting_city' => '',
            'posting_district' => '',
            'posting_state' => $this->form['posting_state'] ?: 'West Bengal',
            'posting_country' => 'India',
            'office_address' => '',
            'from_date' => now()->format('Y-m-d'),
            'to_date' => '',
            'is_current' => count($this->postings) === 0,
            'order_number' => '',
            'responsibilities' => '',
            'remarks' => '',
        ];
    }

    public function removePostingRow(int $index): void
    {
        if (isset($this->postings[$index])) {
            unset($this->postings[$index]);
            $this->postings = array_values($this->postings);
        }
        if (empty($this->postings)) {
            $this->addPostingRow();
        }
    }

    public function setCurrentPostingRow(int $index): void
    {
        foreach ($this->postings as $i => &$p) {
            $p['is_current'] = ($i === $index);
            if ($p['is_current']) {
                $p['to_date'] = '';
            }
        }
    }

    public function saveAlumni(): void
    {
        $this->editingId ? $this->authorize('alumni.edit') : $this->authorize('alumni.create');

        $rules = [
            'form.first_name' => 'required|string|max:100',
            'form.last_name' => 'required|string|max:100',
            'form.email' => 'nullable|email|max:150',
            'form.phone' => 'nullable|string|max:30',
            'form.service_type' => 'required|string|max:100',
            'form.service_name' => 'required|string|max:100',
            'form.designation' => 'required|string|max:150',
            'form.posting_state' => 'nullable|string|max:100',
            'form.passing_year' => 'nullable|integer|min:1970|max:2050',
            'form.exam_year' => 'nullable|integer|min:1970|max:2050',
            'form.all_india_rank' => 'nullable|integer|min:1|max:999999',
            'photoFile' => 'nullable|image|max:5120',
        ];

        $this->validate($rules);

        // Sanitize payload
        $data = $this->form;
        $data['passing_year'] = ! empty($data['passing_year']) ? (int) $data['passing_year'] : null;
        $data['exam_year'] = ! empty($data['exam_year']) ? (int) $data['exam_year'] : null;
        $data['all_india_rank'] = ! empty($data['all_india_rank']) ? (int) $data['all_india_rank'] : null;
        $data['state_rank'] = ! empty($data['state_rank']) ? (int) $data['state_rank'] : null;
        $data['dob'] = ! empty($data['dob']) ? $data['dob'] : null;
        $data['posting_date'] = ! empty($data['posting_date']) ? $data['posting_date'] : null;
        $data['email'] = ! empty($data['email']) ? trim($data['email']) : null;
        $data['phone'] = ! empty($data['phone']) ? trim($data['phone']) : null;

        // Synchronize form primary service and designation to current posting and primary exam if present
        if (! empty($this->form['designation'])) {
            foreach ($this->postings as &$p) {
                if (! empty($p['is_current'])) {
                    $p['designation'] = $this->form['designation'];
                    if (empty($p['posting_city']) && ! empty($this->form['posting_city'])) {
                        $p['posting_city'] = $this->form['posting_city'];
                    }
                    if (empty($p['posting_state']) && ! empty($this->form['posting_state'])) {
                        $p['posting_state'] = $this->form['posting_state'];
                    }
                }
            }
            unset($p);
        }

        if (! empty($this->form['service_name'])) {
            foreach ($this->examinations as &$exam) {
                if (! empty($exam['is_primary'])) {
                    $exam['service_allotted'] = $this->form['service_name'];
                    if (empty($exam['exam_year']) && ! empty($this->form['exam_year'])) {
                        $exam['exam_year'] = (string) $this->form['exam_year'];
                    }
                    if (empty($exam['all_india_rank']) && ! empty($this->form['all_india_rank'])) {
                        $exam['all_india_rank'] = (string) $this->form['all_india_rank'];
                    }
                    if (empty($exam['state_rank']) && ! empty($this->form['state_rank'])) {
                        $exam['state_rank'] = (string) $this->form['state_rank'];
                    }
                    if (empty($exam['examination_name']) && ! empty($this->form['service_type'])) {
                        $exam['examination_name'] = $this->form['service_type'];
                    }
                }
            }
            unset($exam);
        }

        try {
            if ($this->editingId) {
                $alumni = Alumni::findOrFail($this->editingId);
                AlumniService::updateAlumni($alumni, $data, $this->photoFile, $this->courseBatches, $this->examinations, $this->postings);
                Toast::dispatch($this, 'success', __("Alumni record for ':name' successfully updated.", ['name' => $alumni->full_name]));
            } else {
                $alumni = AlumniService::createAlumni($data, $this->photoFile, $this->courseBatches, $this->examinations, $this->postings);
                Toast::dispatch($this, 'success', __("Alumni record for ':name' successfully added.", ['name' => $alumni->full_name]));
            }

            $this->showFormModal = false;
            $this->photoFile = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function viewDossier(int $id): void
    {
        $this->authorize('alumni.view');
        $this->viewingAlumni = Alumni::with(['courseBatches.course', 'courseBatches.batch', 'examinations', 'postings', 'creator', 'user'])->findOrFail($id);
        $this->showViewModal = true;
    }

    public function openManagePostingsModal(int $id): void
    {
        $this->authorize('alumni.edit');
        $this->managePostingAlumniId = $id;
        $this->managePostingAlumni = Alumni::with('postings')->findOrFail($id);
        $this->quickPosting = [
            'designation' => $this->managePostingAlumni->designation,
            'department_ministry' => $this->managePostingAlumni->department_ministry ?? '',
            'posting_city' => '',
            'posting_district' => '',
            'posting_state' => $this->managePostingAlumni->posting_state ?? 'West Bengal',
            'posting_country' => 'India',
            'office_address' => '',
            'from_date' => now()->format('Y-m-d'),
            'to_date' => '',
            'is_current' => true,
            'order_number' => '',
            'responsibilities' => '',
            'remarks' => '',
        ];
        $this->showPostingsHistoryModal = true;
    }

    public function saveQuickPosting(): void
    {
        $this->authorize('alumni.edit');
        $this->validate([
            'quickPosting.designation' => 'required|string|max:150',
            'quickPosting.from_date' => 'nullable|date',
            'quickPosting.to_date' => 'nullable|date',
        ]);

        if (! $this->managePostingAlumni) {
            return;
        }

        try {
            $data = $this->quickPosting;
            $data['from_date'] = ! empty($data['from_date']) ? $data['from_date'] : null;
            $data['to_date'] = ! empty($data['to_date']) ? $data['to_date'] : null;

            AlumniService::addPosting($this->managePostingAlumni, $data);
            $this->managePostingAlumni = $this->managePostingAlumni->fresh('postings');

            Toast::dispatch($this, 'success', __('New posting record added successfully.'));
            $this->quickPosting['posting_city'] = '';
            $this->quickPosting['posting_district'] = '';
            $this->quickPosting['order_number'] = '';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function deleteQuickPosting(int $postingId): void
    {
        $this->authorize('alumni.edit');
        $posting = AlumniPosting::findOrFail($postingId);
        AlumniService::deletePosting($posting);
        if ($this->managePostingAlumni) {
            $this->managePostingAlumni = $this->managePostingAlumni->fresh('postings');
        }
        Toast::dispatch($this, 'success', __('Posting record removed.'));
    }

    public function toggleFeatured(int $id): void
    {
        $this->authorize('alumni.featured');
        $alumni = Alumni::findOrFail($id);
        $alumni->is_featured = ! $alumni->is_featured;
        $alumni->save();

        Toast::dispatch($this, 'success', __("Featured status updated for :name.", ['name' => $alumni->full_name]));
    }

    public function toggleVerified(int $id): void
    {
        $this->authorize('alumni.edit');
        $alumni = Alumni::findOrFail($id);
        $alumni->is_verified = ! $alumni->is_verified;
        $alumni->save();

        Toast::dispatch($this, 'success', __("Verification status updated for :name.", ['name' => $alumni->full_name]));
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('alumni.delete');
        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        $this->authorize('alumni.delete');
        if (! $this->deleteId) {
            return;
        }

        try {
            $alumni = Alumni::findOrFail($this->deleteId);
            $name = $alumni->full_name;
            AlumniService::deleteAlumni($alumni);

            Toast::dispatch($this, 'success', __("Alumni record for ':name' moved to trash.", ['name' => $name]));
            $this->showDeleteModal = false;
            $this->deleteId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function restoreAlumni(int $id): void
    {
        $this->authorize('alumni.restore');
        try {
            $alumni = Alumni::onlyTrashed()->findOrFail($id);
            AlumniService::restoreAlumni($alumni);

            Toast::dispatch($this, 'success', __("Alumni record for ':name' successfully restored.", ['name' => $alumni->full_name]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function confirmForceDelete(int $id): void
    {
        $this->authorize('alumni.delete');
        $this->forceDeleteId = $id;
        $this->showForceDeleteModal = true;
    }

    public function executeForceDelete(): void
    {
        $this->authorize('alumni.delete');
        if (! $this->forceDeleteId) {
            return;
        }

        try {
            $alumni = Alumni::onlyTrashed()->findOrFail($this->forceDeleteId);
            $name = $alumni->full_name;
            AlumniService::forceDeleteAlumni($alumni);

            Toast::dispatch($this, 'success', __("Alumni record for ':name' permanently purged.", ['name' => $name]));
            $this->showForceDeleteModal = false;
            $this->forceDeleteId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openConvertStudentModal(): void
    {
        $this->authorize('alumni.create');
        $this->studentSearchQuery = '';
        $this->showConvertModal = true;
    }

    public function selectStudentForConversion(int $userId): void
    {
        $this->authorize('alumni.create');
        $user = User::with(['studentProfile', 'enrollments.course', 'enrollments.batch'])->findOrFail($userId);

        $this->showConvertModal = false;
        $this->openCreateModal();

        $parts = explode(' ', trim($user->name), 2);
        $this->form['user_id'] = $user->id;
        $this->form['first_name'] = $parts[0] ?? $user->name;
        $this->form['last_name'] = $parts[1] ?? '';
        $this->form['email'] = $user->email;
        $this->form['phone'] = $user->phone ?? '';
        $this->form['gender'] = $user->studentProfile?->gender ?? $user->gender ?? 'male';
        $this->form['dob'] = $user->studentProfile?->dob ? $user->studentProfile->dob->format('Y-m-d') : ($user->dob ? $user->dob->format('Y-m-d') : '');
        $this->form['source'] = Alumni::SOURCE_STUDENT;
        $this->existingPhotoUrl = $user->avatar;

        $this->courseBatches = [];
        foreach ($user->enrollments as $enrollment) {
            $this->courseBatches[] = [
                'id' => null,
                'course_id' => (string) $enrollment->course_id,
                'batch_id' => (string) $enrollment->batch_id,
                'course_name' => $enrollment->course?->name ?? 'Course ' . $enrollment->course_id,
                'batch_name' => $enrollment->batch?->name ?? 'Batch ' . $enrollment->batch_id,
                'enrollment_no' => $enrollment->enrollment_no ?? '',
                'roll_no' => $enrollment->roll_no ?? '',
                'academic_year' => $enrollment->batch?->academic_year ?? '',
                'start_year' => $enrollment->enrolled_at ? (string) $enrollment->enrolled_at->year : '',
                'completion_year' => $enrollment->completed_at ? (string) $enrollment->completed_at->year : (string) now()->year,
                'status' => 'completed',
                'remarks' => 'Converted from active student enrollments',
            ];
        }

        if (empty($this->courseBatches)) {
            $this->addCourseBatchRow();
        }

        Toast::dispatch($this, 'info', __("Student ':name' details and enrolled courses imported. Please fill service and posting details.", ['name' => $user->name]));
    }

    public function export(string $format = 'csv')
    {
        $this->authorize('alumni.export');

        $query = Alumni::query()
            ->with(['courseBatches.course', 'courseBatches.batch', 'examinations', 'postings'])
            ->search($this->search);

        if (! empty($this->serviceTypeFilter)) {
            $query->where('service_type', $this->serviceTypeFilter);
        }

        if (! empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->activeTab === 'trashed') {
            $query->onlyTrashed();
        }

        return AlumniImportExportService::export($query, $format);
    }

    public function downloadImportTemplate()
    {
        $this->authorize('alumni.import');
        return AlumniImportExportService::downloadTemplate();
    }

    public function executeImport(): void
    {
        $this->authorize('alumni.import');
        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $result = AlumniImportExportService::importAlumni($path);

            $this->importSummary = $result;
            $this->importFile = null;

            Toast::dispatch($this, 'success', __("Import complete! :count alumni records imported successfully.", ['count' => $result['imported']]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function openCategoriesModal(): void
    {
        $this->authorize('alumni.view');
        $this->categorySearch = '';
        $this->showCategoriesModal = true;
    }

    public function openCreateCategoryModal(): void
    {
        $this->authorize('alumni.edit');
        $this->resetErrorBag();
        $this->editingCategoryId = null;
        $this->categoryForm = [
            'name' => '',
            'code' => '',
            'short_name' => '',
            'description' => '',
            'icon' => 'award',
            'badge_color' => 'primary',
            'is_active' => true,
            'display_order' => (int) (AlumniServiceCategory::max('display_order') + 1),
        ];
        $this->showCategoryFormModal = true;
    }

    public function editCategory(int $id): void
    {
        $this->authorize('alumni.edit');
        $this->resetErrorBag();
        $cat = AlumniServiceCategory::findOrFail($id);
        $this->editingCategoryId = $cat->id;
        $this->categoryForm = [
            'name' => $cat->name,
            'code' => $cat->code,
            'short_name' => $cat->short_name ?? '',
            'description' => $cat->description ?? '',
            'icon' => $cat->icon ?? 'award',
            'badge_color' => $cat->badge_color ?? 'primary',
            'is_active' => (bool) $cat->is_active,
            'display_order' => (int) $cat->display_order,
        ];
        $this->showCategoryFormModal = true;
    }

    public function saveCategory(AlumniServiceCategoryService $service): void
    {
        $this->authorize('alumni.edit');
        $this->validate([
            'categoryForm.name' => 'required|string|max:150',
            'categoryForm.code' => 'nullable|string|max:64',
            'categoryForm.short_name' => 'nullable|string|max:64',
            'categoryForm.description' => 'nullable|string|max:500',
            'categoryForm.icon' => 'nullable|string|max:50',
            'categoryForm.badge_color' => 'nullable|string|max:30',
        ]);

        try {
            if ($this->editingCategoryId) {
                $category = AlumniServiceCategory::findOrFail($this->editingCategoryId);
                $service->updateCategory($category, $this->categoryForm, auth()->user());
                Toast::dispatch($this, 'success', __('Service category updated successfully.'));
            } else {
                $created = $service->createCategory($this->categoryForm, auth()->user());
                Toast::dispatch($this, 'success', __('New service category added successfully.'));
                if ($this->showFormModal) {
                    $this->form['service_type'] = $created->code;
                }
            }

            $this->showCategoryFormModal = false;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function toggleCategoryStatus(int $id, AlumniServiceCategoryService $service): void
    {
        $this->authorize('alumni.edit');
        try {
            $cat = AlumniServiceCategory::findOrFail($id);
            $service->toggleStatus($cat, auth()->user());
            Toast::dispatch($this, 'success', __('Category status updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function deleteCategory(int $id, AlumniServiceCategoryService $service): void
    {
        $this->authorize('alumni.edit');
        try {
            $cat = AlumniServiceCategory::findOrFail($id);
            $service->deleteCategory($cat, auth()->user());
            Toast::dispatch($this, 'success', __('Service category deleted.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="p-2 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400">
                    <x-icon name="award" class="w-6 h-6" />
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">
                        {{ __('Alumni & Officer Directory') }}
                    </h1>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        {{ __('Manage civil servants, multiple examination selections, career posting histories, and enrolled course history.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Grid/Table View Switch -->
            <div class="inline-flex rounded-lg border border-border p-0.5 bg-muted/40">
                <button type="button" wire:click="$set('viewMode', 'grid')" class="p-1.5 rounded-md text-xs font-medium transition-all {{ $viewMode === 'grid' ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}" title="{{ __('Grid View') }}">
                    <x-icon name="layout-grid" class="w-4 h-4" />
                </button>
                <button type="button" wire:click="$set('viewMode', 'table')" class="p-1.5 rounded-md text-xs font-medium transition-all {{ $viewMode === 'table' ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}" title="{{ __('Table View') }}">
                    <x-icon name="list" class="w-4 h-4" />
                </button>
            </div>

            <!-- Service Categories Modal Trigger -->
            @can('alumni.view')
            <x-ui.button variant="outline" size="sm" wire:click="openCategoriesModal" class="h-9 gap-1.5 border-border hover:bg-muted text-foreground">
                <x-icon name="award" class="h-3.5 w-3.5 text-violet-600 dark:text-violet-400"/>
                {{ __('Service Categories') }}
            </x-ui.button>
            @endcan

            <!-- Export Dropdown -->
            @can('alumni.export')
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
                <x-ui.dropdown.item icon="printer" wire:click="export('pdf')">{{ __('Export PDF Directory') }}</x-ui.dropdown.item>
            </x-ui.dropdown>
            @endcan

            @can('alumni.import')
            <x-ui.button variant="outline" size="sm" wire:click="$set('showImportModal', true)" class="h-9 gap-1.5 border-violet-200 dark:border-violet-800/60 bg-violet-50 dark:bg-violet-950/40 text-violet-700 dark:text-violet-300 hover:bg-violet-100">
                <x-icon name="upload" class="h-3.5 w-3.5 text-violet-600 dark:text-violet-400"/>
                {{ __('Import Alumni') }}
            </x-ui.button>
            @endcan

            @can('alumni.create')
            <x-ui.button variant="outline" size="sm" wire:click="openConvertStudentModal" class="h-9 gap-1.5 border-emerald-200 dark:border-emerald-800/60 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100">
                <x-icon name="user-check" class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400"/>
                {{ __('Convert Student') }}
            </x-ui.button>

            <x-ui.button variant="default" size="sm" wire:click="openCreateModal" class="h-9 gap-1.5 bg-violet-600 hover:bg-violet-700 text-white shadow-xs">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('Add Alumnus') }}
            </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Statistics Overview KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-violet-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ __('Total Alumni') }}</span>
                <span class="p-2 rounded-lg bg-violet-50 dark:bg-violet-950/50 text-violet-600 dark:text-violet-400">
                    <x-icon name="users" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-foreground mt-2">{{ $this->stats['total_alumni'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Registered former students') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-emerald-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Civil Servants & Officers') }}</span>
                <span class="p-2 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400">
                    <x-icon name="medal" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">{{ $this->stats['civil_servants_count'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('IAS, IPS, IFS, WBCS, WBPS') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-amber-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Featured Achievers') }}</span>
                <span class="p-2 rounded-lg bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400">
                    <x-icon name="star" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-2">{{ $this->stats['featured_alumni'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Hall of fame spotlight') }}</div>
        </div>

        <div class="p-4 bg-card border border-border rounded-xl shadow-xs transition-all hover:border-blue-500/30">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-blue-600 dark:text-blue-400 uppercase tracking-wider">{{ __('Batches Represented') }}</span>
                <span class="p-2 rounded-lg bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400">
                    <x-icon name="layers" class="w-4 h-4" />
                </span>
            </div>
            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-2">{{ $this->stats['unique_batches_count'] }}</div>
            <div class="text-xs text-muted-foreground mt-1">{{ __('Across all study programs') }}</div>
        </div>
    </div>

    <!-- Quick Navigation & Scope Tabs -->
    <div class="border-b border-border">
        <nav class="flex space-x-4 overflow-x-auto scrollbar-none" aria-label="Tabs">
            <button
                type="button"
                wire:click="setTab('all')"
                class="py-3 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors {{ $activeTab === 'all' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border' }}"
            >
                {{ __('All Alumni') }}
                <span class="ml-1.5 py-0.5 px-2 rounded-full text-xs {{ $activeTab === 'all' ? 'bg-violet-100 dark:bg-violet-950/80 text-violet-700 dark:text-violet-300' : 'bg-muted text-muted-foreground' }}">
                    {{ $this->stats['total_alumni'] }}
                </span>
            </button>

            <button
                type="button"
                wire:click="setTab('upsc')"
                class="py-3 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors {{ $activeTab === 'upsc' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border' }}"
            >
                <span class="inline-flex items-center gap-1">
                    <x-icon name="award" class="w-3.5 h-3.5"/>
                    {{ __('UPSC CSE Officers') }}
                </span>
            </button>

            <button
                type="button"
                wire:click="setTab('wbcs')"
                class="py-3 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors {{ $activeTab === 'wbcs' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border' }}"
            >
                <span class="inline-flex items-center gap-1">
                    <x-icon name="shield" class="w-3.5 h-3.5"/>
                    {{ __('WBCS & State Services') }}
                </span>
            </button>

            <button
                type="button"
                wire:click="setTab('banking')"
                class="py-3 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors {{ $activeTab === 'banking' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border' }}"
            >
                <span class="inline-flex items-center gap-1">
                    <x-icon name="landmark" class="w-3.5 h-3.5"/>
                    {{ __('Banking & Finance') }}
                </span>
            </button>

            <button
                type="button"
                wire:click="setTab('featured')"
                class="py-3 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors {{ $activeTab === 'featured' ? 'border-amber-500 text-amber-600 dark:text-amber-400' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border' }}"
            >
                <span class="inline-flex items-center gap-1">
                    <x-icon name="star" class="w-3.5 h-3.5"/>
                    {{ __('Featured Achievers') }}
                </span>
                <span class="ml-1.5 py-0.5 px-2 rounded-full text-xs bg-amber-100 dark:bg-amber-950/80 text-amber-700 dark:text-amber-300">
                    {{ $this->stats['featured_alumni'] }}
                </span>
            </button>

            <button
                type="button"
                wire:click="setTab('trashed')"
                class="py-3 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors {{ $activeTab === 'trashed' ? 'border-rose-600 text-rose-600 dark:border-rose-400 dark:text-rose-400' : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border' }}"
            >
                <span class="inline-flex items-center gap-1">
                    <x-icon name="trash-2" class="w-3.5 h-3.5"/>
                    {{ __('Archived / Trash') }}
                </span>
            </button>
        </nav>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="p-4 bg-card border border-border rounded-xl shadow-xs space-y-3">
        <div class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-6 gap-3">
            <!-- Search Input -->
            <div class="md:col-span-2 relative">
                <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                <input
                    type="text"
                    wire:model.live.debounce.350ms="search"
                    placeholder="{{ __('Search by name, email, phone, service, rank, city, exam...') }}"
                    class="w-full pl-9 pr-3 py-1.5 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"
                />
            </div>

            <!-- Service Type Filter -->
            <div>
                <select
                    wire:model.live="serviceTypeFilter"
                    class="w-full py-1.5 px-2.5 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"
                >
                    <option value="">{{ __('All Service Types') }}</option>
                    @foreach($this->availableServiceTypes as $val => $label)
                        <option value="{{ $val }}">{{ $val }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Course Filter -->
            <div>
                <select
                    wire:model.live="courseFilter"
                    class="w-full py-1.5 px-2.5 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"
                >
                    <option value="">{{ __('All Enrolled Courses') }}</option>
                    @foreach($this->availableCourses as $course)
                        <option value="{{ $course->id }}">{{ $course->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Batch Filter -->
            <div>
                <select
                    wire:model.live="batchFilter"
                    class="w-full py-1.5 px-2.5 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"
                >
                    <option value="">{{ __('All Batches') }}</option>
                    @foreach($this->availableBatches as $batch)
                        <option value="{{ $batch->id }}">{{ $batch->name }} ({{ $batch->academic_year }})</option>
                    @endforeach
                </select>
            </div>

            <!-- Status Filter -->
            <div>
                <select
                    wire:model.live="statusFilter"
                    class="w-full py-1.5 px-2.5 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"
                >
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="retired">{{ __('Retired') }}</option>
                    <option value="deceased">{{ __('Deceased') }}</option>
                </select>
            </div>
        </div>

        <div class="flex items-center justify-between text-xs text-muted-foreground pt-1 border-t border-border">
            <div class="flex items-center gap-2">
                <span>{{ __('Found') }} <strong class="text-foreground">{{ $this->alumniList->total() }}</strong> {{ __('alumni records') }}</span>
                @if($search || $serviceTypeFilter || $courseFilter || $batchFilter || $statusFilter || $activeTab !== 'all')
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="text-violet-600 hover:text-violet-700 dark:text-violet-400 font-medium ml-2 underline"
                    >
                        {{ __('Clear all filters') }}
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <span>{{ __('Sort by:') }}</span>
                <button type="button" wire:click="sortBy('full_name')" class="hover:text-foreground transition-colors {{ $sortField === 'full_name' ? 'font-semibold text-violet-600 dark:text-violet-400' : '' }}">
                    {{ __('Name') }} {!! $sortField === 'full_name' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' !!}
                </button>
                <span>•</span>
                <button type="button" wire:click="sortBy('exam_year')" class="hover:text-foreground transition-colors {{ $sortField === 'exam_year' ? 'font-semibold text-violet-600 dark:text-violet-400' : '' }}">
                    {{ __('Exam Year') }} {!! $sortField === 'exam_year' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' !!}
                </button>
                <span>•</span>
                <button type="button" wire:click="sortBy('all_india_rank')" class="hover:text-foreground transition-colors {{ $sortField === 'all_india_rank' ? 'font-semibold text-violet-600 dark:text-violet-400' : '' }}">
                    {{ __('Rank') }} {!! $sortField === 'all_india_rank' ? ($sortDirection === 'asc' ? '↑' : '↓') : '' !!}
                </button>
            </div>
        </div>
    </div>

    <!-- Alumni Content View (Grid or Table) -->
    @if($this->alumniList->isEmpty())
        <div class="p-12 text-center bg-card border border-border rounded-xl">
            <div class="w-16 h-16 mx-auto rounded-full bg-violet-100 dark:bg-violet-950/60 flex items-center justify-center text-violet-600 dark:text-violet-400 mb-4">
                <x-icon name="award" class="w-8 h-8" />
            </div>
            <h3 class="text-lg font-semibold text-foreground">{{ __('No Alumni Records Found') }}</h3>
            <p class="text-sm text-muted-foreground max-w-md mx-auto mt-1">
                {{ __('No matching alumni were found matching your current filter criteria. Try adjusting your search term or add a new alumnus record.') }}
            </p>
            <div class="mt-5 flex items-center justify-center gap-3">
                <x-ui.button variant="outline" size="sm" wire:click="resetFilters">
                    {{ __('Reset Filters') }}
                </x-ui.button>
                @can('alumni.create')
                <x-ui.button variant="default" size="sm" wire:click="openCreateModal" class="bg-violet-600 hover:bg-violet-700 text-white">
                    <x-icon name="plus" class="w-4 h-4 mr-1"/>
                    {{ __('Add First Alumnus') }}
                </x-ui.button>
                @endcan
            </div>
        </div>
    @elseif($viewMode === 'grid')
        <!-- Cards Grid View -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach($this->alumniList as $alumnus)
                <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden flex flex-col justify-between hover:border-violet-500/40 hover:shadow-md transition-all group">
                    <div>
                        <!-- Header Banner & Avatar -->
                        <div class="relative bg-gradient-to-r from-violet-600/10 via-purple-600/10 to-indigo-600/10 dark:from-violet-950/30 dark:via-purple-950/30 dark:to-indigo-950/30 p-4 pb-2">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="relative">
                                        <img
                                            src="{{ $alumnus->photo_display_url }}"
                                            alt="{{ $alumnus->full_name }}"
                                            class="w-14 h-14 rounded-full object-cover ring-2 ring-background border border-border shadow-xs"
                                        />
                                        @if($alumnus->is_verified)
                                            <span class="absolute -bottom-0.5 -right-0.5 p-0.5 rounded-full bg-emerald-500 text-white shadow-xs" title="{{ __('Verified Alumnus') }}">
                                                <x-icon name="check" class="w-3 h-3 stroke-[3]" />
                                            </span>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-1.5">
                                            <h3 class="font-bold text-base text-foreground group-hover:text-violet-600 dark:group-hover:text-violet-400 transition-colors">
                                                {{ $alumnus->full_name }}
                                            </h3>
                                            @if($alumnus->is_featured)
                                                <span class="text-amber-500" title="{{ __('Featured Achiever') }}">
                                                    <x-icon name="star" class="w-4 h-4 fill-amber-500" />
                                                </span>
                                            @endif
                                        </div>
                                        <p class="text-xs font-semibold text-violet-700 dark:text-violet-300">
                                            {{ $alumnus->service_name }}
                                            @if($alumnus->cadre)
                                                <span class="text-muted-foreground font-normal">({{ $alumnus->cadre }})</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ $alumnus->designation }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Rank Badge -->
                                @if($alumnus->all_india_rank)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60 shadow-xs">
                                        AIR {{ $alumnus->all_india_rank }}
                                    </span>
                                @elseif($alumnus->state_rank)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-100 dark:bg-emerald-950/70 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60 shadow-xs">
                                        Rank {{ $alumnus->state_rank }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Card Body Details -->
                        <div class="p-4 space-y-3">
                            <!-- Current Posting Location & Ministry -->
                            <div class="space-y-1 text-xs">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-1.5 text-muted-foreground">
                                        <x-icon name="map-pin" class="w-3.5 h-3.5 text-violet-500 shrink-0" />
                                        <span class="truncate font-medium text-foreground">
                                            {{ $alumnus->posting_city ? $alumnus->posting_city . ', ' : '' }}{{ $alumnus->posting_state ?? 'India' }}
                                        </span>
                                    </div>

                                    @can('alumni.edit')
                                    <button
                                        type="button"
                                        wire:click="openManagePostingsModal({{ $alumnus->id }})"
                                        class="text-[11px] font-semibold text-violet-600 hover:text-violet-700 dark:text-violet-400 hover:underline flex items-center gap-0.5"
                                        title="{{ __('Manage Career Postings Timeline') }}"
                                    >
                                        <x-icon name="map-pin" class="w-3 h-3" />
                                        {{ __('Postings') }} ({{ $alumnus->postings->count() }})
                                    </button>
                                    @endcan
                                </div>

                                @if($alumnus->department_ministry)
                                    <div class="flex items-center gap-1.5 text-muted-foreground">
                                        <x-icon name="building-2" class="w-3.5 h-3.5 text-indigo-500 shrink-0" />
                                        <span class="truncate">{{ $alumnus->department_ministry }}</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Multiple Examination Selections Pills -->
                            @if($alumnus->examinations->isNotEmpty())
                                <div>
                                    <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block mb-1 flex items-center justify-between">
                                        <span>{{ __('Examination Selections') }}</span>
                                        <span class="text-violet-600 font-bold">{{ $alumnus->examinations->count() }} {{ __('Cleared') }}</span>
                                    </span>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($alumnus->examinations as $ex)
                                            <span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded bg-violet-50 dark:bg-violet-950/60 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-800/60">
                                                <x-icon name="medal" class="w-3 h-3 text-violet-500" />
                                                <span class="font-medium truncate max-w-[130px]">{{ $ex->examination_name }}</span>
                                                <span class="font-bold">({{ $ex->exam_year }})</span>
                                                @if($ex->all_india_rank)
                                                    <span class="text-[10px] bg-violet-200/60 dark:bg-violet-900/60 px-1 rounded font-bold">AIR {{ $ex->all_india_rank }}</span>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- Course & Batch Pills -->
                            <div>
                                <span class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block mb-1">
                                    {{ __('Enrolled Courses & Batches') }}
                                </span>
                                <div class="flex flex-wrap gap-1">
                                    @forelse($alumnus->courseBatches as $cb)
                                        <span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded bg-muted text-foreground border border-border/80">
                                            <x-icon name="graduation-cap" class="w-3 h-3 text-violet-500" />
                                            <span class="font-medium truncate max-w-[140px]">{{ $cb->course_name }}</span>
                                            <span class="text-muted-foreground">({{ $cb->batch_name }})</span>
                                        </span>
                                    @empty
                                        <span class="text-xs text-muted-foreground italic">{{ __('No course batch details attached') }}</span>
                                    @endforelse
                                </div>
                            </div>

                            <!-- Short Bio or Testimonial Snippet -->
                            @if($alumnus->short_bio || $alumnus->testimonial)
                                <p class="text-xs text-muted-foreground line-clamp-2 italic bg-muted/40 p-2 rounded-lg border border-border/50">
                                    "{{ $alumnus->short_bio ?: $alumnus->testimonial }}"
                                </p>
                            @endif

                            <!-- Contact details snippet -->
                            <div class="pt-2 border-t border-border flex items-center justify-between text-xs text-muted-foreground">
                                <div class="flex items-center gap-2">
                                    @if($alumnus->email)
                                        <a href="mailto:{{ $alumnus->email }}" class="hover:text-violet-600 transition-colors" title="{{ $alumnus->email }}">
                                            <x-icon name="mail" class="w-3.5 h-3.5" />
                                        </a>
                                    @endif
                                    @if($alumnus->phone)
                                        <a href="tel:{{ $alumnus->phone }}" class="hover:text-violet-600 transition-colors" title="{{ $alumnus->phone }}">
                                            <x-icon name="phone" class="w-3.5 h-3.5" />
                                        </a>
                                    @endif
                                    @if($alumnus->linkedin_url)
                                        <a href="{{ $alumnus->linkedin_url }}" target="_blank" rel="noopener" class="hover:text-blue-600 transition-colors" title="{{ __('LinkedIn Profile') }}">
                                            <x-icon name="linkedin" class="w-3.5 h-3.5" />
                                        </a>
                                    @endif
                                    @if($alumnus->twitter_url)
                                        <a href="{{ $alumnus->twitter_url }}" target="_blank" rel="noopener" class="hover:text-sky-500 transition-colors" title="{{ __('Twitter / X') }}">
                                            <x-icon name="twitter" class="w-3.5 h-3.5" />
                                        </a>
                                    @endif
                                </div>

                                <span class="text-[11px] font-medium text-muted-foreground">
                                    {{ __('Exam Year:') }} <strong class="text-foreground">{{ $alumnus->exam_year ?: ($alumnus->passing_year ?: 'N/A') }}</strong>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Footer Actions -->
                    <div class="p-3 bg-muted/30 border-t border-border flex items-center justify-between gap-1">
                        <button
                            type="button"
                            wire:click="viewDossier({{ $alumnus->id }})"
                            class="text-xs font-medium text-violet-700 dark:text-violet-400 hover:underline flex items-center gap-1"
                        >
                            <x-icon name="eye" class="w-3.5 h-3.5" />
                            {{ __('Full Dossier') }}
                        </button>

                        <div class="flex items-center gap-1">
                            @if($activeTab === 'trashed')
                                @can('alumni.restore')
                                <button
                                    type="button"
                                    wire:click="restoreAlumni({{ $alumnus->id }})"
                                    class="p-1.5 rounded hover:bg-muted text-emerald-600 transition-colors"
                                    title="{{ __('Restore Alumnus') }}"
                                >
                                    <x-icon name="rotate-ccw" class="w-4 h-4" />
                                </button>
                                @endcan
                                @can('alumni.delete')
                                <button
                                    type="button"
                                    wire:click="confirmForceDelete({{ $alumnus->id }})"
                                    class="p-1.5 rounded hover:bg-muted text-rose-600 transition-colors"
                                    title="{{ __('Purge Permanently') }}"
                                >
                                    <x-icon name="trash" class="w-4 h-4" />
                                </button>
                                @endcan
                            @else
                                @can('alumni.featured')
                                <button
                                    type="button"
                                    wire:click="toggleFeatured({{ $alumnus->id }})"
                                    class="p-1.5 rounded hover:bg-muted transition-colors {{ $alumnus->is_featured ? 'text-amber-500' : 'text-muted-foreground hover:text-amber-500' }}"
                                    title="{{ $alumnus->is_featured ? __('Unfeature') : __('Feature in Showcase') }}"
                                >
                                    <x-icon name="star" class="w-4 h-4 {{ $alumnus->is_featured ? 'fill-amber-500' : '' }}" />
                                </button>
                                @endcan

                                @can('alumni.edit')
                                <button
                                    type="button"
                                    wire:click="openEditModal({{ $alumnus->id }})"
                                    class="p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
                                    title="{{ __('Edit Alumnus') }}"
                                >
                                    <x-icon name="edit" class="w-4 h-4" />
                                </button>
                                @endcan

                                @can('alumni.delete')
                                <button
                                    type="button"
                                    wire:click="confirmDelete({{ $alumnus->id }})"
                                    class="p-1.5 rounded hover:bg-rose-50 dark:hover:bg-rose-950/50 text-muted-foreground hover:text-rose-600 transition-colors"
                                    title="{{ __('Move to Trash') }}"
                                >
                                    <x-icon name="trash-2" class="w-4 h-4" />
                                </button>
                                @endcan
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <!-- Table View -->
        <div class="bg-card border border-border rounded-xl shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-muted-foreground uppercase bg-muted/50 border-b border-border">
                        <tr>
                            <th class="px-4 py-3">{{ __('Alumnus Officer') }}</th>
                            <th class="px-4 py-3">{{ __('Service & Cadre') }}</th>
                            <th class="px-4 py-3">{{ __('Current Designation') }}</th>
                            <th class="px-4 py-3">{{ __('Posting Location') }}</th>
                            <th class="px-4 py-3">{{ __('Examinations Cleared') }}</th>
                            <th class="px-4 py-3">{{ __('Courses & Batches') }}</th>
                            <th class="px-4 py-3">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach($this->alumniList as $alumnus)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <img
                                            src="{{ $alumnus->photo_display_url }}"
                                            alt="{{ $alumnus->full_name }}"
                                            class="w-9 h-9 rounded-full object-cover border border-border"
                                        />
                                        <div>
                                            <div class="flex items-center gap-1 font-semibold text-foreground">
                                                <span>{{ $alumnus->full_name }}</span>
                                                @if($alumnus->is_featured)
                                                    <x-icon name="star" class="w-3.5 h-3.5 fill-amber-500 text-amber-500" />
                                                @endif
                                            </div>
                                            <div class="text-xs text-muted-foreground flex items-center gap-1">
                                                @if($alumnus->email)
                                                    <span>{{ $alumnus->email }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-violet-100 dark:bg-violet-950/70 text-violet-800 dark:text-violet-300">
                                        {{ $alumnus->service_name }}
                                    </span>
                                    <div class="text-xs text-muted-foreground mt-0.5">
                                        {{ $alumnus->cadre ? "Cadre: {$alumnus->cadre}" : $alumnus->service_type }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-medium text-foreground">
                                    {{ $alumnus->designation }}
                                    @if($alumnus->department_ministry)
                                        <div class="text-xs text-muted-foreground truncate max-w-[180px]">
                                            {{ $alumnus->department_ministry }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">
                                    <div class="font-medium text-foreground">{{ $alumnus->posting_city ?: '—' }}</div>
                                    <div>{{ $alumnus->posting_state ?? 'India' }}</div>
                                    @can('alumni.edit')
                                    <button
                                        type="button"
                                        wire:click="openManagePostingsModal({{ $alumnus->id }})"
                                        class="text-[10px] text-violet-600 hover:underline mt-0.5 block"
                                    >
                                        {{ __('History (:c)', ['c' => $alumnus->postings->count()]) }}
                                    </button>
                                    @endcan
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    @if($alumnus->examinations->isNotEmpty())
                                        <div class="space-y-1">
                                            @foreach($alumnus->examinations->take(2) as $ex)
                                                <div class="text-[11px] truncate max-w-[160px]">
                                                    <strong class="text-foreground">{{ $ex->examination_name }}</strong>
                                                    <span class="text-muted-foreground">({{ $ex->exam_year }})</span>
                                                    @if($ex->all_india_rank)
                                                        <span class="text-amber-600 font-bold">AIR {{ $ex->all_india_rank }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                            @if($alumnus->examinations->count() > 2)
                                                <span class="text-[10px] text-muted-foreground italic">+{{ $alumnus->examinations->count() - 2 }} more</span>
                                            @endif
                                        </div>
                                    @else
                                        <div class="text-muted-foreground">—</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <div class="max-w-[180px] truncate font-medium text-foreground">
                                        {{ $alumnus->courses_summary }}
                                    </div>
                                    <div class="max-w-[180px] truncate text-muted-foreground">
                                        {{ $alumnus->batches_summary }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $alumnus->status === 'active' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300' : 'bg-muted text-muted-foreground' }}">
                                        {{ ucfirst($alumnus->status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="inline-flex items-center gap-1">
                                        <button
                                            type="button"
                                            wire:click="viewDossier({{ $alumnus->id }})"
                                            class="p-1 rounded hover:bg-muted text-violet-600 dark:text-violet-400 transition-colors"
                                            title="{{ __('View Dossier') }}"
                                        >
                                            <x-icon name="eye" class="w-4 h-4" />
                                        </button>
                                        @if($activeTab === 'trashed')
                                            @can('alumni.restore')
                                            <button
                                                type="button"
                                                wire:click="restoreAlumni({{ $alumnus->id }})"
                                                class="p-1 rounded hover:bg-muted text-emerald-600 transition-colors"
                                                title="{{ __('Restore Alumnus') }}"
                                            >
                                                <x-icon name="rotate-ccw" class="w-4 h-4" />
                                            </button>
                                            @endcan
                                            @can('alumni.delete')
                                            <button
                                                type="button"
                                                wire:click="confirmForceDelete({{ $alumnus->id }})"
                                                class="p-1 rounded hover:bg-muted text-rose-600 transition-colors"
                                                title="{{ __('Purge Permanently') }}"
                                            >
                                                <x-icon name="trash" class="w-4 h-4" />
                                            </button>
                                            @endcan
                                        @else
                                            @can('alumni.edit')
                                            <button
                                                type="button"
                                                wire:click="openManagePostingsModal({{ $alumnus->id }})"
                                                class="p-1 rounded hover:bg-muted text-muted-foreground hover:text-violet-600 transition-colors"
                                                title="{{ __('Posting History') }}"
                                            >
                                                <x-icon name="map-pin" class="w-4 h-4" />
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openEditModal({{ $alumnus->id }})"
                                                class="p-1 rounded hover:bg-muted text-muted-foreground hover:text-foreground transition-colors"
                                                title="{{ __('Edit Alumnus') }}"
                                            >
                                                <x-icon name="edit" class="w-4 h-4" />
                                            </button>
                                            @endcan
                                            @can('alumni.delete')
                                            <button
                                                type="button"
                                                wire:click="confirmDelete({{ $alumnus->id }})"
                                                class="p-1 rounded hover:bg-muted text-muted-foreground hover:text-rose-600 transition-colors"
                                                title="{{ __('Delete Alumnus') }}"
                                            >
                                                <x-icon name="trash-2" class="w-4 h-4" />
                                            </button>
                                            @endcan
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Pagination -->
    <div class="mt-4">
        {{ $this->alumniList->links() }}
    </div>

    <!-- CREATE & EDIT ALUMNUS MODAL (MULTI-TAB) -->
    @if($showFormModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs overflow-y-auto animate-in fade-in duration-200">
            <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-4xl max-h-[92vh] flex flex-col overflow-hidden">
                <!-- Modal Header -->
                <div class="p-5 border-b border-border flex items-center justify-between bg-muted/30">
                    <div class="flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-violet-100 dark:bg-violet-950/70 text-violet-600 dark:text-violet-400">
                            <x-icon name="award" class="w-5 h-5" />
                        </span>
                        <div>
                            <h2 class="text-lg font-bold text-foreground">
                                {{ $editingId ? __('Edit Alumni Officer Dossier') : __('Register New Alumnus Record') }}
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                {{ __('Multiple examination selections, career postings timeline, and multi-course records.') }}
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('showFormModal', false)"
                        class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                    >
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Modal Form Tabs -->
                <div class="border-b border-border px-5 bg-muted/10 overflow-x-auto scrollbar-none">
                    <nav class="flex space-x-6">
                        <button
                            type="button"
                            wire:click="$set('formTab', 'personal')"
                            class="py-3 font-medium text-xs border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 {{ $formTab === 'personal' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="user" class="w-3.5 h-3.5" />
                            {{ __('Personal & Identity') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('formTab', 'service')"
                            class="py-3 font-medium text-xs border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 {{ $formTab === 'service' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="briefcase" class="w-3.5 h-3.5" />
                            {{ __('Current Service & Cadre') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('formTab', 'examinations')"
                            class="py-3 font-medium text-xs border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 {{ $formTab === 'examinations' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="medal" class="w-3.5 h-3.5" />
                            {{ __('Examinations & Selections') }}
                            <span class="py-0.2 px-1.5 rounded-full text-[10px] bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300 font-bold">
                                {{ count($examinations) }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="$set('formTab', 'postings')"
                            class="py-3 font-medium text-xs border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 {{ $formTab === 'postings' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="map-pin" class="w-3.5 h-3.5" />
                            {{ __('Career Posting History') }}
                            <span class="py-0.2 px-1.5 rounded-full text-[10px] bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">
                                {{ count($postings) }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="$set('formTab', 'courses')"
                            class="py-3 font-medium text-xs border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 {{ $formTab === 'courses' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="graduation-cap" class="w-3.5 h-3.5" />
                            {{ __('Enrolled Courses') }}
                            <span class="py-0.2 px-1.5 rounded-full text-[10px] bg-violet-100 dark:bg-violet-950 text-violet-700 dark:text-violet-300 font-bold">
                                {{ count($courseBatches) }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="$set('formTab', 'bio')"
                            class="py-3 font-medium text-xs border-b-2 whitespace-nowrap transition-colors flex items-center gap-1.5 {{ $formTab === 'bio' ? 'border-violet-600 text-violet-600 dark:border-violet-400 dark:text-violet-400' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="file-text" class="w-3.5 h-3.5" />
                            {{ __('Bio, Story & Socials') }}
                        </button>
                    </nav>
                </div>

                <!-- Modal Body Scrollable -->
                <div class="p-6 overflow-y-auto flex-1 space-y-5">
                    @if ($errors->any())
                        <div class="p-3 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-400 text-xs space-y-1">
                            <strong class="font-semibold">{{ __('Please resolve the following errors before submitting:') }}</strong>
                            <ul class="list-disc pl-5">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <!-- TAB 1: PERSONAL & IDENTITY -->
                    <div class="{{ $formTab === 'personal' ? 'space-y-4' : 'hidden' }}">
                        <div class="flex flex-col sm:flex-row items-center gap-5 p-4 rounded-xl bg-muted/30 border border-border">
                            <div class="relative shrink-0">
                                @if ($photoFile)
                                    <img src="{{ $photoFile->temporaryUrl() }}" class="w-20 h-20 rounded-full object-cover ring-2 ring-violet-500 shadow-sm" />
                                @elseif ($existingPhotoUrl)
                                    <img src="{{ $existingPhotoUrl }}" class="w-20 h-20 rounded-full object-cover ring-2 ring-border shadow-sm" />
                                @else
                                    <div class="w-20 h-20 rounded-full bg-violet-100 dark:bg-violet-950/60 flex items-center justify-center text-violet-600 dark:text-violet-400 font-bold text-xl border border-violet-200 dark:border-violet-800">
                                        <x-icon name="camera" class="w-7 h-7" />
                                    </div>
                                @endif
                            </div>
                            <div class="flex-1 space-y-1 text-center sm:text-left">
                                <label class="text-xs font-semibold text-foreground block">{{ __('Alumnus Official Photo') }}</label>
                                <p class="text-xs text-muted-foreground">{{ __('Upload a professional portrait photo (JPG, PNG, WebP up to 5MB).') }}</p>
                                <input type="file" wire:model="photoFile" accept="image/*" class="text-xs file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-violet-600 file:text-white hover:file:bg-violet-700 cursor-pointer mt-1" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('First Name') }} <span class="text-rose-500">*</span></label>
                                <input type="text" wire:model="form.first_name" placeholder="e.g. Subrata" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Last Name') }} <span class="text-rose-500">*</span></label>
                                <input type="text" wire:model="form.last_name" placeholder="e.g. Majumdar" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Email Address') }}</label>
                                <input type="email" wire:model="form.email" placeholder="officer@nic.in" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Primary Phone') }}</label>
                                <input type="text" wire:model="form.phone" placeholder="+91 98765 43210" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Alternate Phone') }}</label>
                                <input type="text" wire:model="form.alternate_phone" placeholder="Office / Landline" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Gender') }}</label>
                                <select wire:model="form.gender" class="w-full py-1.5 px-2.5 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500">
                                    <option value="male">{{ __('Male') }}</option>
                                    <option value="female">{{ __('Female') }}</option>
                                    <option value="other">{{ __('Other') }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Date of Birth') }}</label>
                                <input type="date" wire:model="form.dob" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Graduation / Passing Year') }}</label>
                                <input type="number" wire:model="form.passing_year" placeholder="2022" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Current Address') }}</label>
                                <textarea wire:model="form.current_address" rows="2" placeholder="Present official / residential address" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"></textarea>
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Permanent Address') }}</label>
                                <textarea wire:model="form.permanent_address" rows="2" placeholder="Hometown / Native address in West Bengal" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: CURRENT SERVICE & CADRE -->
                    <div class="{{ $formTab === 'service' ? 'space-y-4' : 'hidden' }}">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-xs font-semibold text-foreground">{{ __('Service Category') }} <span class="text-rose-500">*</span></label>
                                    @can('alumni.edit')
                                        <button type="button" wire:click="openCreateCategoryModal" class="text-[11px] font-semibold text-violet-600 dark:text-violet-400 hover:underline cursor-pointer">
                                            + {{ __('Add Category') }}
                                        </button>
                                    @endcan
                                </div>
                                <select wire:model="form.service_type" class="w-full py-1.5 px-2.5 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500">
                                    @foreach($this->availableServiceTypes as $val => $label)
                                        <option value="{{ $val }}">{{ $val }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Service / Cadre Name') }} <span class="text-rose-500">*</span></label>
                                <input type="text" wire:model="form.service_name" placeholder="e.g. IAS, IPS, WBCS (Exe), IFS" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>

                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Allotted Cadre') }}</label>
                                <input type="text" wire:model="form.cadre" placeholder="e.g. West Bengal, AGMUT" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Current Designation') }} <span class="text-rose-500">*</span></label>
                                <input type="text" wire:model="form.designation" placeholder="e.g. Sub-Divisional Magistrate (SDM)" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Primary All India Rank (AIR)') }}</label>
                                <input type="number" wire:model="form.all_india_rank" placeholder="e.g. 14" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('State Rank / Merit') }}</label>
                                <input type="number" wire:model="form.state_rank" placeholder="e.g. 3" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Primary Examination Year') }}</label>
                                <input type="number" wire:model="form.exam_year" placeholder="e.g. 2022" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Primary Exam Roll / Hall Ticket No') }}</label>
                                <input type="text" wire:model="form.roll_number" placeholder="Optional roll number" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                            </div>
                        </div>

                        <div class="border-t border-border pt-3">
                            <h4 class="text-xs font-bold text-foreground uppercase tracking-wider mb-3">
                                {{ __('Current Deployment Summary') }}
                            </h4>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Posting City / Subdivision') }}</label>
                                    <input type="text" wire:model="form.posting_city" placeholder="e.g. Siliguri, Asansol, Kolkata" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-foreground block mb-1">{{ __('District') }}</label>
                                    <input type="text" wire:model="form.posting_district" placeholder="e.g. Darjeeling, Paschim Bardhaman" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Posting State') }}</label>
                                    <input type="text" wire:model="form.posting_state" placeholder="e.g. West Bengal" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3">
                                <div>
                                    <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Department / Ministry') }}</label>
                                    <input type="text" wire:model="form.department_ministry" placeholder="e.g. Dept of Personnel & Administrative Reforms" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Current Posting Date') }}</label>
                                    <input type="date" wire:model="form.posting_date" class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Official Office Address') }}</label>
                                <textarea wire:model="form.office_address" rows="2" placeholder="Office of the Sub-Divisional Magistrate, Administrative Building..." class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: EXAMINATIONS & SELECTIONS (MULTIPLE YEARS SUPPORT) -->
                    <div class="{{ $formTab === 'examinations' ? 'space-y-4' : 'hidden' }}">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="text-xs font-bold text-foreground uppercase tracking-wider">
                                    {{ __('Examination Selections & Qualifications') }}
                                </h4>
                                <p class="text-xs text-muted-foreground">
                                    {{ __('Record if an alumnus cracked examinations multiple times in different years (e.g. WBCS in 2021, UPSC IRS in 2022, UPSC IAS in 2023).') }}
                                </p>
                            </div>

                            <button
                                type="button"
                                wire:click="addExamRow"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-violet-50 dark:bg-violet-950/70 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-800 hover:bg-violet-100 transition-colors"
                            >
                                <x-icon name="plus" class="w-3.5 h-3.5" />
                                {{ __('Add Another Selection') }}
                            </button>
                        </div>

                        <div class="space-y-4">
                            @foreach($examinations as $idx => $examRow)
                                <div class="p-4 rounded-xl bg-muted/20 border border-border relative space-y-3">
                                    <div class="flex items-center justify-between pb-2 border-b border-border/60">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-violet-700 dark:text-violet-400 flex items-center gap-1.5">
                                                <x-icon name="medal" class="w-3.5 h-3.5" />
                                                {{ __('Examination Selection #') }}{{ $idx + 1 }}
                                            </span>

                                            <label class="inline-flex items-center gap-1 text-xs cursor-pointer ml-3">
                                                <input
                                                    type="radio"
                                                    name="primary_exam"
                                                    wire:click="setPrimaryExamRow({{ $idx }})"
                                                    {{ !empty($examRow['is_primary']) ? 'checked' : '' }}
                                                    class="w-3.5 h-3.5 text-violet-600 focus:ring-violet-500"
                                                />
                                                <span class="text-muted-foreground text-[11px] font-semibold">{{ __('Primary Display Selection') }}</span>
                                            </label>
                                        </div>

                                        @if(count($examinations) > 1)
                                            <button
                                                type="button"
                                                wire:click="removeExamRow({{ $idx }})"
                                                class="text-xs text-rose-600 hover:text-rose-700 font-medium flex items-center gap-1"
                                            >
                                                <x-icon name="trash-2" class="w-3 h-3" />
                                                {{ __('Remove') }}
                                            </button>
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Examination Name') }} <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                wire:model="examinations.{{ $idx }}.examination_name"
                                                placeholder="e.g. UPSC CSE, WBCS (Exe), WBPS"
                                                class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            />
                                        </div>

                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Year of Selection') }} <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                type="number"
                                                wire:model="examinations.{{ $idx }}.exam_year"
                                                placeholder="e.g. 2023"
                                                class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            />
                                        </div>

                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Service Allotted') }}
                                            </label>
                                            <input
                                                type="text"
                                                wire:model="examinations.{{ $idx }}.service_allotted"
                                                placeholder="e.g. IAS, IPS, WBCS (Exe), IRS"
                                                class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            />
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('All India Rank (AIR)') }}</label>
                                            <input type="number" wire:model="examinations.{{ $idx }}.all_india_rank" placeholder="e.g. 14" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('State Rank / Merit') }}</label>
                                            <input type="number" wire:model="examinations.{{ $idx }}.state_rank" placeholder="e.g. 5" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Roll / Reg Number') }}</label>
                                            <input type="text" wire:model="examinations.{{ $idx }}.roll_number" placeholder="Roll #" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Optional Subject') }}</label>
                                            <input type="text" wire:model="examinations.{{ $idx }}.optional_subject" placeholder="e.g. Sociology, History" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Score / Written & Interview Marks') }}</label>
                                            <input type="text" wire:model="examinations.{{ $idx }}.score_or_marks" placeholder="e.g. 1024/2025" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Remarks / Notes') }}</label>
                                            <input type="text" wire:model="examinations.{{ $idx }}.remarks" placeholder="First attempt, Group A selection..." class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- TAB 4: CAREER POSTING HISTORY (FULL TIMELINE) -->
                    <div class="{{ $formTab === 'postings' ? 'space-y-4' : 'hidden' }}">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="text-xs font-bold text-foreground uppercase tracking-wider">
                                    {{ __('Career Posting History & Deployment Progression') }}
                                </h4>
                                <p class="text-xs text-muted-foreground">
                                    {{ __('Keep records updated with all career transfers, designations, departments, from date, to date, and current posting.') }}
                                </p>
                            </div>

                            <button
                                type="button"
                                wire:click="addPostingRow"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-50 dark:bg-emerald-950/70 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 transition-colors"
                            >
                                <x-icon name="plus" class="w-3.5 h-3.5" />
                                {{ __('Add Posting Entry') }}
                            </button>
                        </div>

                        <div class="space-y-4">
                            @foreach($postings as $pIdx => $pRow)
                                <div class="p-4 rounded-xl bg-muted/20 border border-border relative space-y-3">
                                    <div class="flex items-center justify-between pb-2 border-b border-border/60">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-emerald-700 dark:text-emerald-400 flex items-center gap-1.5">
                                                <x-icon name="map-pin" class="w-3.5 h-3.5" />
                                                {{ __('Posting #') }}{{ $pIdx + 1 }}: {{ $pRow['designation'] ?: __('New Posting') }}
                                            </span>

                                            <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer ml-3">
                                                <input
                                                    type="radio"
                                                    name="current_posting_radio"
                                                    wire:click="setCurrentPostingRow({{ $pIdx }})"
                                                    {{ !empty($pRow['is_current']) ? 'checked' : '' }}
                                                    class="w-3.5 h-3.5 text-emerald-600 focus:ring-emerald-500"
                                                />
                                                <span class="text-emerald-700 dark:text-emerald-300 font-bold text-[11px]">{{ __('Current Posting') }}</span>
                                            </label>
                                        </div>

                                        @if(count($postings) > 1)
                                            <button
                                                type="button"
                                                wire:click="removePostingRow({{ $pIdx }})"
                                                class="text-xs text-rose-600 hover:text-rose-700 font-medium flex items-center gap-1"
                                            >
                                                <x-icon name="trash-2" class="w-3 h-3" />
                                                {{ __('Remove') }}
                                            </button>
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Designation') }} <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                wire:model="postings.{{ $pIdx }}.designation"
                                                placeholder="e.g. Sub-Divisional Magistrate (SDM), ADM, ACP"
                                                class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                            />
                                        </div>

                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Department / Ministry') }}
                                            </label>
                                            <input
                                                type="text"
                                                wire:model="postings.{{ $pIdx }}.department_ministry"
                                                placeholder="e.g. Dept of Home & Hill Affairs, Finance Dept"
                                                class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-emerald-500"
                                            />
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Posting City / Place') }}</label>
                                            <input type="text" wire:model="postings.{{ $pIdx }}.posting_city" placeholder="e.g. Siliguri" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('District') }}</label>
                                            <input type="text" wire:model="postings.{{ $pIdx }}.posting_district" placeholder="e.g. Darjeeling" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('State') }}</label>
                                            <input type="text" wire:model="postings.{{ $pIdx }}.posting_state" placeholder="e.g. West Bengal" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('From Date (Posting Date)') }}</label>
                                            <input type="date" wire:model="postings.{{ $pIdx }}.from_date" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('To Date') }}</label>
                                            <input
                                                type="date"
                                                wire:model="postings.{{ $pIdx }}.to_date"
                                                {{ !empty($pRow['is_current']) ? 'disabled' : '' }}
                                                placeholder="{{ !empty($pRow['is_current']) ? __('Present (Current)') : '' }}"
                                                class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg disabled:bg-muted/50 disabled:text-muted-foreground"
                                            />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Govt Order / Notification No') }}</label>
                                            <input type="text" wire:model="postings.{{ $pIdx }}.order_number" placeholder="e.g. 1024-PAR(WBCS)" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- TAB 5: ENROLLED COURSES & BATCHES -->
                    <div class="{{ $formTab === 'courses' ? 'space-y-4' : 'hidden' }}">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="text-xs font-bold text-foreground uppercase tracking-wider">
                                    {{ __('Course & Batch Attendance History') }}
                                </h4>
                                <p class="text-xs text-muted-foreground">
                                    {{ __('Support multiple courses and batches. You can pick from system courses/batches or type custom past names manually.') }}
                                </p>
                            </div>

                            <button
                                type="button"
                                wire:click="addCourseBatchRow"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-violet-50 dark:bg-violet-950/70 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-800 hover:bg-violet-100 transition-colors"
                            >
                                <x-icon name="plus" class="w-3.5 h-3.5" />
                                {{ __('Add Another Course / Batch') }}
                            </button>
                        </div>

                        <div class="space-y-4">
                            @foreach($courseBatches as $index => $row)
                                <div class="p-4 rounded-xl bg-muted/20 border border-border relative space-y-3">
                                    <div class="flex items-center justify-between pb-2 border-b border-border/60">
                                        <span class="text-xs font-bold text-violet-700 dark:text-violet-400 flex items-center gap-1.5">
                                            <x-icon name="book-open" class="w-3.5 h-3.5" />
                                            {{ __('Course & Batch Entry #') }}{{ $index + 1 }}
                                        </span>

                                        @if(count($courseBatches) > 1)
                                            <button
                                                type="button"
                                                wire:click="removeCourseBatchRow({{ $index }})"
                                                class="text-xs text-rose-600 hover:text-rose-700 font-medium flex items-center gap-1"
                                            >
                                                <x-icon name="trash-2" class="w-3 h-3" />
                                                {{ __('Remove') }}
                                            </button>
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <!-- Course Selection & Manual Title -->
                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Select System Course') }}
                                            </label>
                                            <select
                                                wire:model.live="courseBatches.{{ $index }}.course_id"
                                                wire:change="onCourseSelected({{ $index }})"
                                                class="w-full py-1.5 px-2.5 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            >
                                                <option value="">{{ __('— Custom / Past Manual Course —') }}</option>
                                                @foreach($this->availableCourses as $c)
                                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Course Name / Title') }} <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                wire:model="courseBatches.{{ $index }}.course_name"
                                                placeholder="e.g. UPSC Prelims Cum Mains Comprehensive"
                                                class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            />
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <!-- Batch Selection & Manual Title -->
                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Select System Batch') }}
                                            </label>
                                            <select
                                                wire:model.live="courseBatches.{{ $index }}.batch_id"
                                                wire:change="onBatchSelected({{ $index }})"
                                                class="w-full py-1.5 px-2.5 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            >
                                                <option value="">{{ __('— Custom / Past Manual Batch —') }}</option>
                                                @foreach($this->availableBatches as $b)
                                                    <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->academic_year }})</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <div>
                                            <label class="text-xs font-medium text-foreground block mb-1">
                                                {{ __('Batch Name / Year') }} <span class="text-rose-500">*</span>
                                            </label>
                                            <input
                                                type="text"
                                                wire:model="courseBatches.{{ $index }}.batch_name"
                                                placeholder="e.g. Weekend Batch 2021-22"
                                                class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg focus:outline-none focus:ring-1 focus:ring-violet-500"
                                            />
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Enrollment No') }}</label>
                                            <input type="text" wire:model="courseBatches.{{ $index }}.enrollment_no" placeholder="ENR-..." class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Roll No') }}</label>
                                            <input type="text" wire:model="courseBatches.{{ $index }}.roll_no" placeholder="Roll #" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Completion Year') }}</label>
                                            <input type="number" wire:model="courseBatches.{{ $index }}.completion_year" placeholder="2022" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Status') }}</label>
                                            <select wire:model="courseBatches.{{ $index }}.status" class="w-full py-1 px-2 text-xs bg-background border border-border rounded-lg">
                                                <option value="completed">{{ __('Completed') }}</option>
                                                <option value="attended">{{ __('Attended') }}</option>
                                                <option value="ongoing">{{ __('Ongoing') }}</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <!-- TAB 6: BIO, STORY & SOCIAL LINKS -->
                    <div class="{{ $formTab === 'bio' ? 'space-y-4' : 'hidden' }}">
                        <div>
                            <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Short Bio / Tagline') }}</label>
                            <input type="text" wire:model="form.short_bio" placeholder="e.g. IAS Officer, 2021 Batch. Passionate about rural health governance." class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500" />
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Success Story & Preparation Journey') }}</label>
                            <textarea wire:model="form.success_story" rows="4" placeholder="Detail the officer's preparation strategy, journey at SNTCSSC, interview guidance, and tips for future aspirants..." class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"></textarea>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Institutional Testimonial / Quote') }}</label>
                            <textarea wire:model="form.testimonial" rows="2" placeholder="What the alumnus says about SNTCSSC mentorship and study environment..." class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500"></textarea>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 border-t border-border">
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('LinkedIn Profile URL') }}</label>
                                <input type="url" wire:model="form.linkedin_url" placeholder="https://linkedin.com/in/..." class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Twitter / X URL') }}</label>
                                <input type="url" wire:model="form.twitter_url" placeholder="https://x.com/..." class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg" />
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Personal / Official Website') }}</label>
                                <input type="url" wire:model="form.website_url" placeholder="https://..." class="w-full py-1.5 px-3 text-sm bg-background border border-border rounded-lg" />
                            </div>
                        </div>

                        <!-- Visibility Switches -->
                        <div class="p-4 rounded-xl bg-muted/20 border border-border flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="form.is_featured" class="w-4 h-4 rounded text-violet-600 focus:ring-violet-500" />
                                <span class="text-xs font-medium text-foreground">
                                    {{ __('Highlight as Featured Achiever on public showcase') }}
                                </span>
                            </label>

                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="form.is_verified" class="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500" />
                                <span class="text-xs font-medium text-foreground">
                                    {{ __('Mark as Verified Service Officer') }}
                                </span>
                            </label>

                            <div class="flex items-center gap-2">
                                <span class="text-xs font-medium text-muted-foreground">{{ __('Profile Status:') }}</span>
                                <select wire:model="form.status" class="py-1 px-2.5 text-xs bg-background border border-border rounded-md">
                                    <option value="active">{{ __('Active') }}</option>
                                    <option value="inactive">{{ __('Inactive') }}</option>
                                    <option value="retired">{{ __('Retired') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="p-4 border-t border-border flex items-center justify-between bg-muted/30">
                    <div class="flex items-center gap-2">
                        @if($formTab !== 'personal')
                            <button
                                type="button"
                                wire:click="$set('formTab', '{{ $formTab === 'bio' ? 'courses' : ($formTab === 'courses' ? 'postings' : ($formTab === 'postings' ? 'examinations' : ($formTab === 'examinations' ? 'service' : 'personal'))) }}')"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium border border-border hover:bg-muted"
                            >
                                {{ __('Back') }}
                            </button>
                        @endif

                        @if($formTab !== 'bio')
                            <button
                                type="button"
                                wire:click="$set('formTab', '{{ $formTab === 'personal' ? 'service' : ($formTab === 'service' ? 'examinations' : ($formTab === 'examinations' ? 'postings' : ($formTab === 'postings' ? 'courses' : 'bio'))) }}')"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium bg-violet-50 text-violet-700 hover:bg-violet-100 dark:bg-violet-950 dark:text-violet-300"
                            >
                                {{ __('Next Step') }} →
                            </button>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <x-ui.button variant="outline" size="sm" wire:click="$set('showFormModal', false)">
                            {{ __('Cancel') }}
                        </x-ui.button>

                        <x-ui.button variant="default" size="sm" wire:click="saveAlumni" class="bg-violet-600 hover:bg-violet-700 text-white">
                            <x-icon name="check" class="w-4 h-4 mr-1"/>
                            {{ $editingId ? __('Update Alumnus') : __('Save Alumnus') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- QUICK MANAGE POSTINGS HISTORY MODAL -->
    @if($showPostingsHistoryModal && $managePostingAlumni)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs overflow-y-auto animate-in fade-in duration-200">
            <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">
                <div class="p-5 border-b border-border flex items-center justify-between bg-muted/30">
                    <div class="flex items-center gap-2.5">
                        <span class="p-2 rounded-xl bg-emerald-100 dark:bg-emerald-950/70 text-emerald-600 dark:text-emerald-400">
                            <x-icon name="map-pin" class="w-5 h-5" />
                        </span>
                        <div>
                            <h3 class="text-base font-bold text-foreground">
                                {{ __('Manage Posting History:') }} {{ $managePostingAlumni->full_name }}
                            </h3>
                            <p class="text-xs text-muted-foreground">
                                {{ __('Track all government postings, transfers, current active posting, and dates.') }}
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('showPostingsHistoryModal', false)"
                        class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-muted"
                    >
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-5 overflow-y-auto space-y-5 flex-1">
                    <!-- Add New Posting Form Section -->
                    <div class="p-4 rounded-xl bg-muted/30 border border-border space-y-3">
                        <h4 class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
                            <x-icon name="plus" class="w-3.5 h-3.5 text-emerald-600" />
                            {{ __('Record New Transfer / Posting') }}
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs font-medium text-foreground block mb-1">{{ __('Designation') }} <span class="text-rose-500">*</span></label>
                                <input type="text" wire:model="quickPosting.designation" placeholder="e.g. Additional District Magistrate (ADM)" class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg" />
                                @error('quickPosting.designation') <span class="text-rose-500 text-[10px]">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="text-xs font-medium text-foreground block mb-1">{{ __('Department / Ministry') }}</label>
                                <input type="text" wire:model="quickPosting.department_ministry" placeholder="e.g. Home & Hill Affairs" class="w-full py-1.5 px-3 text-xs bg-background border border-border rounded-lg" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Posting City') }}</label>
                                <input type="text" wire:model="quickPosting.posting_city" placeholder="e.g. Asansol" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('District') }}</label>
                                <input type="text" wire:model="quickPosting.posting_district" placeholder="e.g. Paschim Bardhaman" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('State') }}</label>
                                <input type="text" wire:model="quickPosting.posting_state" placeholder="West Bengal" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('From Date') }}</label>
                                <input type="date" wire:model="quickPosting.from_date" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('To Date') }}</label>
                                <input type="date" wire:model="quickPosting.to_date" placeholder="{{ __('Leave blank if current') }}" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                            </div>
                            <div>
                                <label class="text-xs font-medium text-muted-foreground block mb-1">{{ __('Govt Order No') }}</label>
                                <input type="text" wire:model="quickPosting.order_number" placeholder="Order #" class="w-full py-1 px-2.5 text-xs bg-background border border-border rounded-lg" />
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-2">
                            <label class="inline-flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" wire:model="quickPosting.is_current" class="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500" />
                                <span class="text-xs font-medium text-foreground">{{ __('This is their Current Active Posting') }}</span>
                            </label>

                            <x-ui.button variant="default" size="sm" wire:click="saveQuickPosting" class="bg-emerald-600 hover:bg-emerald-700 text-white">
                                <x-icon name="plus" class="w-3.5 h-3.5 mr-1" />
                                {{ __('Add Posting Record') }}
                            </x-ui.button>
                        </div>
                    </div>

                    <!-- Existing Postings Chronological Timeline -->
                    <div class="space-y-3">
                        <h4 class="text-xs font-bold text-foreground uppercase tracking-wider">
                            {{ __('Posting History Timeline') }} ({{ $managePostingAlumni->postings->count() }})
                        </h4>

                        <div class="divide-y divide-border border border-border rounded-xl bg-background overflow-hidden">
                            @forelse($managePostingAlumni->postings as $postingItem)
                                <div class="p-3.5 flex items-start justify-between gap-3 hover:bg-muted/20 transition-colors">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-bold text-sm text-foreground">{{ $postingItem->designation }}</span>
                                            @if($postingItem->is_current)
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 border border-emerald-300 dark:border-emerald-700">
                                                    {{ __('CURRENT POSTING') }}
                                                </span>
                                            @endif
                                        </div>

                                        <p class="text-xs text-muted-foreground">
                                            {{ $postingItem->posting_city ? $postingItem->posting_city . ', ' : '' }}{{ $postingItem->posting_state ?? 'India' }}
                                            @if($postingItem->department_ministry)
                                                • {{ $postingItem->department_ministry }}
                                            @endif
                                        </p>

                                        <p class="text-[11px] font-mono text-muted-foreground">
                                            <x-icon name="calendar" class="w-3 h-3 inline mr-1 text-emerald-600" />
                                            {{ $postingItem->duration }}
                                            @if($postingItem->order_number)
                                                • {{ __('Order:') }} {{ $postingItem->order_number }}
                                            @endif
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="deleteQuickPosting({{ $postingItem->id }})"
                                        class="p-1 rounded text-rose-500 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950 transition-colors"
                                        title="{{ __('Delete posting') }}"
                                    >
                                        <x-icon name="trash-2" class="w-4 h-4" />
                                    </button>
                                </div>
                            @empty
                                <div class="p-6 text-center text-xs text-muted-foreground">
                                    {{ __('No career postings added yet. Record their first posting above.') }}
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t border-border bg-muted/20 text-right">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('showPostingsHistoryModal', false)">
                        {{ __('Done') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- CONVERT STUDENT TO ALUMNI MODAL -->
    @if($showConvertModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-xl overflow-hidden flex flex-col">
                <div class="p-5 border-b border-border flex items-center justify-between bg-muted/30">
                    <div class="flex items-center gap-2">
                        <span class="p-2 rounded-xl bg-emerald-100 dark:bg-emerald-950/70 text-emerald-600 dark:text-emerald-400">
                            <x-icon name="user-check" class="w-5 h-5" />
                        </span>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ __('Convert Student to Alumni') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Search enrolled students to automatically extract profile and courses.') }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="$set('showConvertModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-5 space-y-4">
                    <div class="relative">
                        <x-icon name="search" class="w-4 h-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="studentSearchQuery"
                            placeholder="{{ __('Type student name, email, roll no, or enrollment number...') }}"
                            class="w-full pl-9 pr-3 py-2 text-sm bg-background border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500"
                        />
                    </div>

                    <div class="max-h-64 overflow-y-auto divide-y divide-border rounded-xl border border-border bg-background">
                        @forelse($this->studentSearchResults as $student)
                            <div class="p-3 flex items-center justify-between hover:bg-muted/40 transition-colors">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $student->avatar }}" alt="" class="w-9 h-9 rounded-full object-cover border border-border" />
                                    <div>
                                        <div class="font-semibold text-sm text-foreground">{{ $student->name }}</div>
                                        <div class="text-xs text-muted-foreground">{{ $student->email }} • {{ $student->enrollments->count() }} {{ __('courses') }}</div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    wire:click="selectStudentForConversion({{ $student->id }})"
                                    class="px-3 py-1 rounded-md text-xs font-semibold bg-emerald-600 text-white hover:bg-emerald-700 transition-colors"
                                >
                                    {{ __('Convert') }} →
                                </button>
                            </div>
                        @empty
                            <div class="p-6 text-center text-xs text-muted-foreground">
                                {{ strlen($studentSearchQuery) < 2 ? __('Type at least 2 characters to search students.') : __('No eligible students found without alumni records.') }}
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="p-4 border-t border-border bg-muted/20 text-right">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('showConvertModal', false)">
                        {{ __('Close') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- DOSSIER SHOWCASE MODAL WITH MULTIPLE EXAMS & POSTING PROGRESSION -->
    @if($showViewModal && $viewingAlumni)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs overflow-y-auto animate-in fade-in duration-200">
            <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-3xl overflow-hidden flex flex-col max-h-[92vh]">
                <!-- Header with photo and service -->
                <div class="bg-gradient-to-r from-violet-600 to-indigo-700 p-6 text-white relative">
                    <button
                        type="button"
                        wire:click="$set('showViewModal', false)"
                        class="absolute top-4 right-4 text-white/80 hover:text-white p-1 rounded-lg hover:bg-white/10"
                    >
                        <x-icon name="x" class="w-5 h-5" />
                    </button>

                    <div class="flex flex-col sm:flex-row items-center sm:items-start gap-5">
                        <img
                            src="{{ $viewingAlumni->photo_display_url }}"
                            alt="{{ $viewingAlumni->full_name }}"
                            class="w-24 h-24 rounded-full object-cover ring-4 ring-white/30 shadow-lg shrink-0"
                        />
                        <div class="text-center sm:text-left space-y-1">
                            <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                                <h2 class="text-2xl font-bold">{{ $viewingAlumni->full_name }}</h2>
                                @if($viewingAlumni->all_india_rank)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-extrabold bg-amber-400 text-amber-950 shadow-xs">
                                        AIR {{ $viewingAlumni->all_india_rank }}
                                    </span>
                                @elseif($viewingAlumni->state_rank)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-extrabold bg-emerald-400 text-emerald-950 shadow-xs">
                                        Rank {{ $viewingAlumni->state_rank }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-sm font-semibold text-violet-200">
                                {{ $viewingAlumni->service_name }}
                                @if($viewingAlumni->cadre)
                                    ({{ $viewingAlumni->cadre }} Cadre)
                                @endif
                                • {{ $viewingAlumni->designation }}
                            </p>
                            <p class="text-xs text-white/80 flex items-center justify-center sm:justify-start gap-1">
                                <x-icon name="map-pin" class="w-3.5 h-3.5 text-violet-200" />
                                {{ $viewingAlumni->posting_city ? $viewingAlumni->posting_city . ', ' : '' }}{{ $viewingAlumni->posting_state ?? 'India' }}
                                @if($viewingAlumni->department_ministry)
                                    • {{ $viewingAlumni->department_ministry }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Dossier Content Body -->
                <div class="p-6 overflow-y-auto space-y-5 text-sm">
                    <!-- Bio & Journey -->
                    @if($viewingAlumni->short_bio)
                        <div>
                            <h4 class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-1.5">{{ __('Bio & Profile') }}</h4>
                            <p class="text-foreground leading-relaxed">{{ $viewingAlumni->short_bio }}</p>
                        </div>
                    @endif

                    <!-- Multiple Examination Selections Table -->
                    @if($viewingAlumni->examinations->isNotEmpty())
                        <div class="space-y-2">
                            <h4 class="text-xs font-bold text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                                <x-icon name="medal" class="w-4 h-4 text-violet-600" />
                                {{ __('Examination Selections & Merit Ranks') }}
                            </h4>
                            <div class="border border-border rounded-xl overflow-hidden">
                                <table class="w-full text-xs text-left">
                                    <thead class="bg-muted/50 text-muted-foreground font-semibold">
                                        <tr>
                                            <th class="p-2.5">{{ __('Exam Name') }}</th>
                                            <th class="p-2.5">{{ __('Year') }}</th>
                                            <th class="p-2.5">{{ __('Service') }}</th>
                                            <th class="p-2.5">{{ __('Rank') }}</th>
                                            <th class="p-2.5">{{ __('Optional Subject') }}</th>
                                            <th class="p-2.5">{{ __('Roll #') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        @foreach($viewingAlumni->examinations as $examItem)
                                            <tr class="hover:bg-muted/20">
                                                <td class="p-2.5 font-bold text-foreground">
                                                    {{ $examItem->examination_name }}
                                                    @if($examItem->is_primary)
                                                        <span class="text-[10px] text-violet-600 font-bold ml-1">★ Primary</span>
                                                    @endif
                                                </td>
                                                <td class="p-2.5 font-semibold">{{ $examItem->exam_year }}</td>
                                                <td class="p-2.5">{{ $examItem->service_allotted ?: '—' }}</td>
                                                <td class="p-2.5 font-bold">
                                                    @if($examItem->all_india_rank)
                                                        <span class="text-amber-600">AIR {{ $examItem->all_india_rank }}</span>
                                                    @elseif($examItem->state_rank)
                                                        <span class="text-emerald-600">State Rank {{ $examItem->state_rank }}</span>
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td class="p-2.5">{{ $examItem->optional_subject ?: '—' }}</td>
                                                <td class="p-2.5 font-mono text-muted-foreground">{{ $examItem->roll_number ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <!-- Career Posting Progression Timeline -->
                    @if($viewingAlumni->postings->isNotEmpty())
                        <div class="space-y-2">
                            <h4 class="text-xs font-bold text-muted-foreground uppercase tracking-wider flex items-center gap-1.5">
                                <x-icon name="map-pin" class="w-4 h-4 text-emerald-600" />
                                {{ __('Career Posting History Progression') }}
                            </h4>
                            <div class="relative border-l-2 border-violet-200 dark:border-violet-900 ml-3 pl-4 space-y-4">
                                @foreach($viewingAlumni->postings as $pPost)
                                    <div class="relative">
                                        <div class="absolute -left-[23px] top-1 w-3 h-3 rounded-full {{ $pPost->is_current ? 'bg-emerald-500 ring-4 ring-emerald-100 dark:ring-emerald-950' : 'bg-muted-foreground' }}"></div>
                                        <div class="space-y-0.5">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold text-foreground">{{ $pPost->designation }}</span>
                                                @if($pPost->is_current)
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                        {{ __('Current Posting') }}
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="text-xs text-muted-foreground">
                                                {{ $pPost->posting_city ? $pPost->posting_city . ', ' : '' }}{{ $pPost->posting_state ?? 'India' }}
                                                @if($pPost->department_ministry)
                                                    • {{ $pPost->department_ministry }}
                                                @endif
                                            </p>
                                            <p class="text-[11px] font-mono text-muted-foreground">
                                                {{ $pPost->duration }}
                                                @if($pPost->order_number)
                                                    • Order: {{ $pPost->order_number }}
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($viewingAlumni->success_story)
                        <div class="p-4 rounded-xl bg-violet-50/50 dark:bg-violet-950/30 border border-violet-100 dark:border-violet-900/40">
                            <h4 class="text-xs font-bold text-violet-900 dark:text-violet-300 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                                <x-icon name="sparkles" class="w-4 h-4 text-violet-600 dark:text-violet-400" />
                                {{ __('Preparation Journey & Success Story') }}
                            </h4>
                            <p class="text-sm text-foreground/90 whitespace-pre-line leading-relaxed">{{ $viewingAlumni->success_story }}</p>
                        </div>
                    @endif

                    <!-- Enrolled Courses & Batches Timeline -->
                    <div>
                        <h4 class="text-xs font-bold text-muted-foreground uppercase tracking-wider mb-2">{{ __('Academic & Course History at SNTCSSC') }}</h4>
                        <div class="space-y-2">
                            @forelse($viewingAlumni->courseBatches as $cb)
                                <div class="p-3 rounded-lg bg-muted/30 border border-border flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <span class="p-2 rounded-lg bg-background text-violet-600 border border-border">
                                            <x-icon name="graduation-cap" class="w-4 h-4" />
                                        </span>
                                        <div>
                                            <div class="font-semibold text-foreground text-sm">{{ $cb->course_name }}</div>
                                            <div class="text-xs text-muted-foreground">
                                                {{ $cb->batch_name }}
                                                @if($cb->completion_year)
                                                    • {{ __('Completed in') }} {{ $cb->completion_year }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    @if($cb->enrollment_no)
                                        <span class="text-xs font-mono text-muted-foreground">
                                            {{ $cb->enrollment_no }}
                                        </span>
                                    @endif
                                </div>
                            @empty
                                <div class="text-xs text-muted-foreground italic">{{ __('No past enrolled courses recorded.') }}</div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Contact & Office Info Grid -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-3 border-t border-border text-xs">
                        <div>
                            <span class="text-muted-foreground block mb-0.5">{{ __('Email') }}</span>
                            <span class="font-medium text-foreground">{{ $viewingAlumni->email ?: 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block mb-0.5">{{ __('Phone') }}</span>
                            <span class="font-medium text-foreground">{{ $viewingAlumni->phone ?: 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block mb-0.5">{{ __('Official Office Address') }}</span>
                            <span class="font-medium text-foreground">{{ $viewingAlumni->office_address ?: 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="text-muted-foreground block mb-0.5">{{ __('Social & Professional Profiles') }}</span>
                            <div class="flex items-center gap-3 mt-1">
                                @if($viewingAlumni->linkedin_url)
                                    <a href="{{ $viewingAlumni->linkedin_url }}" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1">
                                        <x-icon name="linkedin" class="w-3.5 h-3.5" /> LinkedIn
                                    </a>
                                @endif
                                @if($viewingAlumni->twitter_url)
                                    <a href="{{ $viewingAlumni->twitter_url }}" target="_blank" class="text-sky-500 hover:underline flex items-center gap-1">
                                        <x-icon name="twitter" class="w-3.5 h-3.5" /> Twitter
                                    </a>
                                @endif
                                @if($viewingAlumni->website_url)
                                    <a href="{{ $viewingAlumni->website_url }}" target="_blank" class="text-violet-600 hover:underline flex items-center gap-1">
                                        <x-icon name="globe" class="w-3.5 h-3.5" /> Website
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t border-border bg-muted/20 flex items-center justify-between">
                    <span class="text-xs text-muted-foreground">
                        {{ __('Registered on') }} {{ $viewingAlumni->created_at->format('M d, Y') }}
                        @if($viewingAlumni->creator)
                            {{ __('by') }} {{ $viewingAlumni->creator->name }}
                        @endif
                    </span>

                    <x-ui.button variant="outline" size="sm" wire:click="$set('showViewModal', false)">
                        {{ __('Close Dossier') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- BULK IMPORT MODAL -->
    @if($showImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-lg overflow-hidden flex flex-col">
                <div class="p-5 border-b border-border flex items-center justify-between bg-muted/30">
                    <div class="flex items-center gap-2">
                        <span class="p-2 rounded-xl bg-violet-100 dark:bg-violet-950/70 text-violet-600 dark:text-violet-400">
                            <x-icon name="upload" class="w-5 h-5" />
                        </span>
                        <div>
                            <h3 class="text-base font-bold text-foreground">{{ __('Import Alumni in Bulk') }}</h3>
                            <p class="text-xs text-muted-foreground">{{ __('Upload CSV or Excel files with alumni officers data.') }}</p>
                        </div>
                    </div>
                    <button type="button" wire:click="$set('showImportModal', false)" class="text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="p-4 rounded-xl bg-violet-50/60 dark:bg-violet-950/30 border border-violet-100 dark:border-violet-900/40 text-xs text-foreground space-y-2">
                        <div class="font-bold text-violet-900 dark:text-violet-300 flex items-center gap-1.5">
                            <x-icon name="info" class="w-4 h-4 text-violet-600" />
                            {{ __('Instructions & Sample Template') }}
                        </div>
                        <p class="text-muted-foreground">
                            {{ __('Ensure column headers match: first_name, last_name, email, service_type, service_name, designation, posting_city, courses, batches, exam_year.') }}
                        </p>
                        <button
                            type="button"
                            wire:click="downloadImportTemplate"
                            class="inline-flex items-center gap-1 font-semibold text-violet-700 dark:text-violet-300 hover:underline pt-1"
                        >
                            <x-icon name="download" class="w-3.5 h-3.5" />
                            {{ __('Download Sample CSV Template') }}
                        </button>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-foreground block mb-1">{{ __('Select Spreadsheet File (.csv, .xlsx)') }}</label>
                        <input
                            type="file"
                            wire:model="importFile"
                            accept=".csv,.xlsx,.xls,.txt"
                            class="w-full text-xs file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-violet-600 file:text-white hover:file:bg-violet-700 cursor-pointer"
                        />
                        @error('importFile')
                            <span class="text-rose-500 text-xs block mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    @if($importSummary)
                        <div class="p-3 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 text-xs space-y-1">
                            <div class="font-bold text-emerald-800 dark:text-emerald-300">
                                {{ __('Successfully imported :count records!', ['count' => $importSummary['imported']]) }}
                            </div>
                            @if($importSummary['skipped'] > 0)
                                <div class="text-muted-foreground">
                                    {{ __('Skipped :count rows.', ['count' => $importSummary['skipped']]) }}
                                </div>
                            @endif
                            @if(!empty($importSummary['errors']))
                                <ul class="list-disc pl-4 text-rose-600 dark:text-rose-400 max-h-24 overflow-y-auto">
                                    @foreach($importSummary['errors'] as $err)
                                        <li>{{ $err }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="p-4 border-t border-border bg-muted/20 flex items-center justify-between">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('showImportModal', false)">
                        {{ __('Close') }}
                    </x-ui.button>

                    <x-ui.button variant="default" size="sm" wire:click="executeImport" class="bg-violet-600 hover:bg-violet-700 text-white">
                        <x-icon name="upload" class="w-4 h-4 mr-1"/>
                        {{ __('Start Import') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- SOFT DELETE CONFIRMATION MODAL -->
    @if($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-md p-6 space-y-4">
                <div class="w-12 h-12 rounded-full bg-rose-100 dark:bg-rose-950/70 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto">
                    <x-icon name="trash-2" class="w-6 h-6" />
                </div>
                <div class="text-center space-y-1">
                    <h3 class="text-base font-bold text-foreground">{{ __('Move Alumnus to Trash?') }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ __('This alumni officer profile will be soft deleted. You can restore it anytime from the Archived / Trash tab.') }}
                    </p>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('showDeleteModal', false)">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button variant="default" size="sm" wire:click="executeDelete" class="bg-rose-600 hover:bg-rose-700 text-white">
                        {{ __('Move to Trash') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- FORCE DELETE MODAL -->
    @if($showForceDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in duration-200">
            <div class="bg-card border border-border rounded-2xl shadow-xl w-full max-w-md p-6 space-y-4">
                <div class="w-12 h-12 rounded-full bg-rose-100 dark:bg-rose-950/70 text-rose-600 dark:text-rose-400 flex items-center justify-center mx-auto">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <div class="text-center space-y-1">
                    <h3 class="text-base font-bold text-rose-600 dark:text-rose-400">{{ __('Permanently Purge Record?') }}</h3>
                    <p class="text-xs text-muted-foreground">
                        {{ __('This action CANNOT be undone. All course/batch associations and uploaded images will be permanently erased.') }}
                    </p>
                </div>
                <div class="flex items-center justify-end gap-2 pt-2">
                    <x-ui.button variant="outline" size="sm" wire:click="$set('showForceDeleteModal', false)">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button variant="default" size="sm" wire:click="executeForceDelete" class="bg-rose-600 hover:bg-rose-700 text-white">
                        {{ __('Purge Forever') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <!-- SERVICE CATEGORIES MANAGEMENT CATALOG MODAL -->
    @if($showCategoriesModal)
        <div wire:key="alumni-service-categories-modal" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="fixed inset-0 bg-background/80 backdrop-blur-xs transition-opacity"
                 wire:click="$set('showCategoriesModal', false)"
                 aria-hidden="true"></div>

            <div class="flex min-h-full items-center justify-center p-3 sm:p-6 text-center">
                <div class="relative bg-card text-card-foreground rounded-xl sm:rounded-2xl border border-border shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden text-left align-middle animate-in fade-in-50 zoom-in-95"
                     @click.stop>
                    <!-- Header (Sticky) -->
                    <div class="px-4 py-3.5 sm:px-6 sm:py-4 flex items-center justify-between border-b border-border bg-muted/30 shrink-0">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center shrink-0">
                                <x-icon name="award" class="w-4 h-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-foreground truncate">
                                    {{ __('Manage Alumni Service Categories') }}
                                </h3>
                                <p class="text-[11px] text-muted-foreground truncate">{{ __('Dynamic civil service branches, state administrative tracks, banking, and custom departmental selections.') }}</p>
                            </div>
                        </div>
                        <button type="button" wire:click="$set('showCategoriesModal', false)"
                                class="text-muted-foreground hover:text-foreground p-1.5 rounded-lg hover:bg-muted transition-colors shrink-0 cursor-pointer"
                                aria-label="{{ __('Close modal') }}">
                            <x-icon name="x" class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Search & Action Toolbar -->
                    <div class="p-3 sm:p-4 border-b border-border bg-muted/10 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5 shrink-0">
                        <div class="relative flex-1">
                            <x-icon name="search" class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                            <input type="search"
                                   wire:model.live.debounce.300ms="categorySearch"
                                   placeholder="{{ __('Search categories by name, code, or short name...') }}"
                                   class="w-full pl-8 pr-3 py-1.5 text-xs bg-background border border-border rounded-lg text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-500 transition-colors" />
                        </div>
                        @can('alumni.edit')
                            <x-ui.button variant="default" size="sm" wire:click="openCreateCategoryModal" class="bg-violet-600 hover:bg-violet-700 text-white shrink-0 text-xs h-8 gap-1.5">
                                <x-icon name="plus" class="w-3.5 h-3.5" />
                                <span>{{ __('Add Custom Category') }}</span>
                            </x-ui.button>
                        @endcan
                    </div>

                    <!-- Table of Categories (Scrollable) -->
                    <div class="overflow-y-auto flex-1 p-0 scrollbar-thin">
                        <div class="min-w-full inline-block align-middle">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs divide-y divide-border">
                                    <thead class="bg-muted/40 text-muted-foreground font-semibold sticky top-0 z-10 backdrop-blur-xs">
                                        <tr>
                                            <th class="px-3.5 py-2.5 sm:px-4">{{ __('Category / Code') }}</th>
                                            <th class="px-3.5 py-2.5 sm:px-4">{{ __('Short Name & Description') }}</th>
                                            <th class="px-3.5 py-2.5 sm:px-4 text-center">{{ __('Track Type') }}</th>
                                            <th class="px-3.5 py-2.5 sm:px-4 text-center">{{ __('Alumni Count') }}</th>
                                            <th class="px-3.5 py-2.5 sm:px-4 text-center">{{ __('Status') }}</th>
                                            <th class="px-3.5 py-2.5 sm:px-4 text-right">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border bg-card">
                                        @forelse($this->allCategoriesList as $cat)
                                            <tr wire:key="category-row-{{ $cat->id }}" class="hover:bg-muted/30 transition-colors">
                                                <td class="px-3.5 py-3 sm:px-4 whitespace-nowrap">
                                                    <div class="flex items-center gap-2.5">
                                                        <div class="w-7 h-7 rounded-md flex items-center justify-center shrink-0 text-violet-600 dark:text-violet-400 bg-violet-50 dark:bg-violet-950/50 border border-violet-200 dark:border-violet-800">
                                                            <x-icon name="{{ $cat->icon ?: 'award' }}" class="w-3.5 h-3.5" />
                                                        </div>
                                                        <div>
                                                            <div class="font-semibold text-foreground">{{ $cat->name }}</div>
                                                            <code class="text-[10px] text-muted-foreground bg-muted px-1.5 py-0.5 rounded font-mono">{{ $cat->code }}</code>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-muted-foreground max-w-xs truncate" title="{{ $cat->description }}">
                                                    @if($cat->short_name)
                                                        <span class="font-medium text-foreground mr-1">[{{ $cat->short_name }}]</span>
                                                    @endif
                                                    {{ $cat->description ?: '-' }}
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-center whitespace-nowrap">
                                                    @if($cat->is_system)
                                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 border border-blue-200 dark:border-blue-800/50">
                                                            {{ __('System Core') }}
                                                        </span>
                                                    @else
                                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-violet-50 text-violet-700 dark:bg-violet-950/40 dark:text-violet-300 border border-violet-200 dark:border-violet-800/50">
                                                            {{ __('Custom Track') }}
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-center whitespace-nowrap">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-muted text-foreground">
                                                        {{ number_format($cat->alumni_count ?? 0) }}
                                                    </span>
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-center whitespace-nowrap">
                                                    @can('alumni.edit')
                                                        <button type="button" wire:click="toggleCategoryStatus({{ $cat->id }})"
                                                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-semibold transition-colors cursor-pointer {{ $cat->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                            {{ $cat->is_active ? __('Active') : __('Inactive') }}
                                                        </button>
                                                    @else
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $cat->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                            {{ $cat->is_active ? __('Active') : __('Inactive') }}
                                                        </span>
                                                    @endcan
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-right whitespace-nowrap">
                                                    <div class="flex items-center justify-end gap-1">
                                                        @can('alumni.edit')
                                                            <button type="button" wire:click="editCategory({{ $cat->id }})"
                                                                    class="p-1.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer" title="{{ __('Edit') }}">
                                                                <x-icon name="edit-2" class="w-3.5 h-3.5" />
                                                            </button>
                                                        @endcan
                                                        @if(! $cat->is_system)
                                                            @can('alumni.delete')
                                                                <button type="button" wire:click="deleteCategory({{ $cat->id }})" wire:confirm="{{ __('Are you sure you want to delete this custom service category?') }}"
                                                                        class="p-1.5 rounded text-destructive/80 hover:text-destructive hover:bg-destructive/10 transition-colors cursor-pointer" title="{{ __('Delete') }}">
                                                                    <x-icon name="trash" class="w-3.5 h-3.5" />
                                                                </button>
                                                            @endcan
                                                        @endif
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                                    {{ __('No service categories found matching your search.') }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="px-4 py-3 sm:px-6 sm:py-3.5 border-t border-border flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-between gap-2.5 bg-muted/20 shrink-0">
                        <span class="text-muted-foreground text-[11px] leading-relaxed text-center sm:text-left">
                            {{ __('Custom categories immediately become selectable across alumni forms, student conversions, and REST API endpoints.') }}
                        </span>
                        <x-ui.button variant="outline" size="sm" wire:click="$set('showCategoriesModal', false)"
                                class="shrink-0 text-center">
                            {{ __('Close') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- CREATE / EDIT SERVICE CATEGORY MODAL -->
    @if($showCategoryFormModal)
        <div wire:key="alumni-category-form-modal" class="fixed inset-0 z-[60] overflow-y-auto">
            <div class="fixed inset-0 bg-background/80 backdrop-blur-xs transition-opacity"
                 wire:click="$set('showCategoryFormModal', false)"
                 aria-hidden="true"></div>

            <div class="flex min-h-full items-center justify-center p-3 sm:p-6 text-center">
                <div class="relative bg-card text-card-foreground rounded-xl sm:rounded-2xl border border-border shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden text-left align-middle animate-in fade-in-50 zoom-in-95"
                     @click.stop>
                    <!-- Header (Sticky) -->
                    <div class="px-4 py-3.5 sm:px-6 sm:py-4 flex items-center justify-between border-b border-border bg-muted/30 shrink-0">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center shrink-0">
                                <x-icon name="award" class="w-4 h-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-foreground truncate">
                                    {{ $editingCategoryId ? __('Edit Service Category') : __('Add Custom Service Category') }}
                                </h3>
                                <p class="text-[11px] text-muted-foreground truncate">{{ __('Define a custom service branch or departmental examination selection track.') }}</p>
                            </div>
                        </div>
                        <button type="button" wire:click="$set('showCategoryFormModal', false)"
                                class="text-muted-foreground hover:text-foreground p-1.5 rounded-lg hover:bg-muted transition-colors shrink-0 cursor-pointer"
                                aria-label="{{ __('Close modal') }}">
                            <x-icon name="x" class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Body (Scrollable) -->
                    <div class="p-4 sm:p-6 overflow-y-auto space-y-3.5 sm:space-y-4 flex-1 text-xs scrollbar-thin">
                        <!-- Category Name -->
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Service Category Name') }} <span class="text-destructive">*</span></label>
                            <input type="text" wire:model="categoryForm.name" placeholder="{{ __('e.g., West Bengal Audit & Accounts Service, Railway Management') }}"
                                   class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-violet-500 focus:outline-none transition-shadow" />
                            @error('categoryForm.name') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        </div>

                        <!-- Code / Slug -->
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Code / Key Identifier') }} <span class="text-muted-foreground font-normal">({{ __('Auto-generated from name if left blank') }})</span></label>
                            <input type="text" wire:model="categoryForm.code" placeholder="{{ __('e.g., WBAAS, IRMS, Forest Service') }}"
                                   class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-violet-500 focus:outline-none font-mono transition-shadow" />
                            @error('categoryForm.code') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        </div>

                        <!-- Short Name -->
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Short Name / Abbreviation') }}</label>
                            <input type="text" wire:model="categoryForm.short_name" placeholder="{{ __('e.g., WBA&AS, IRMS, WBFS') }}"
                                   class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-violet-500 focus:outline-none transition-shadow" />
                            @error('categoryForm.short_name') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Description') }}</label>
                            <textarea wire:model="categoryForm.description" rows="2" placeholder="{{ __('Optional summary of what this examination or civil service track represents...') }}"
                                      class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-violet-500 focus:outline-none transition-shadow"></textarea>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <!-- Icon -->
                            <div>
                                <label class="block font-semibold text-foreground mb-1">{{ __('Icon') }}</label>
                                <select wire:model="categoryForm.icon"
                                        class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-violet-500 focus:outline-none transition-shadow">
                                    <option value="award">{{ __('Award (Default)') }}</option>
                                    <option value="shield">{{ __('Shield / Police') }}</option>
                                    <option value="landmark">{{ __('Landmark / Government') }}</option>
                                    <option value="briefcase">{{ __('Briefcase / Corporate') }}</option>
                                    <option value="scale">{{ __('Scale / Judicial') }}</option>
                                    <option value="compass">{{ __('Compass / Forest') }}</option>
                                    <option value="building">{{ __('Building / PSU') }}</option>
                                    <option value="credit-card">{{ __('Bank / Financial') }}</option>
                                    <option value="star">{{ __('Star / Special') }}</option>
                                    <option value="flag">{{ __('Flag / National') }}</option>
                                    <option value="graduation-cap">{{ __('Graduation / Academic') }}</option>
                                    <option value="map-pin">{{ __('Map Pin / Regional') }}</option>
                                </select>
                            </div>

                            <!-- Badge Color -->
                            <div>
                                <label class="block font-semibold text-foreground mb-1">{{ __('Badge Color') }}</label>
                                <select wire:model="categoryForm.badge_color"
                                        class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-violet-500 focus:outline-none transition-shadow">
                                    <option value="violet">{{ __('Violet') }}</option>
                                    <option value="blue">{{ __('Blue') }}</option>
                                    <option value="emerald">{{ __('Emerald') }}</option>
                                    <option value="amber">{{ __('Amber') }}</option>
                                    <option value="rose">{{ __('Rose') }}</option>
                                    <option value="purple">{{ __('Purple') }}</option>
                                    <option value="cyan">{{ __('Cyan') }}</option>
                                    <option value="indigo">{{ __('Indigo') }}</option>
                                    <option value="slate">{{ __('Slate') }}</option>
                                </select>
                            </div>

                            <!-- Display Order -->
                            <div>
                                <label class="block font-semibold text-foreground mb-1">{{ __('Sort Order') }}</label>
                                <input type="number" wire:model="categoryForm.display_order" min="1" max="999"
                                       class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-violet-500 focus:outline-none transition-shadow" />
                            </div>
                        </div>

                        <!-- Active Status Toggle Card -->
                        <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-2">
                            <label class="flex items-start gap-2.5 cursor-pointer">
                                <input type="checkbox" wire:model.live="categoryForm.is_active" class="mt-0.5 rounded border-border text-violet-600 focus:ring-violet-500 h-4 w-4 shrink-0" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-semibold text-foreground">{{ __('Active Status') }}</span>
                                        @if($categoryForm['is_active'] ?? true)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 shrink-0">
                                                {{ __('Active') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400 shrink-0">
                                                {{ __('Inactive') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[11px] text-muted-foreground leading-relaxed mt-1">
                                        {{ __('Active categories appear in candidate entry forms, student conversion wizards, directory filter dropdowns, and public API feeds.') }}
                                    </p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Footer (Sticky) -->
                    <div class="px-4 py-3 sm:px-6 sm:py-3.5 border-t border-border flex items-center justify-end gap-2.5 bg-muted/20 shrink-0">
                        <x-ui.button variant="outline" size="sm" wire:click="$set('showCategoryFormModal', false)"
                                class="text-xs">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button variant="default" size="sm" wire:click="saveCategory" wire:loading.attr="disabled"
                                class="bg-violet-600 hover:bg-violet-700 text-white gap-1.5 text-xs">
                            <x-icon name="check" class="w-4 h-4" />
                            <span wire:loading.remove wire:target="saveCategory">{{ $editingCategoryId ? __('Update Category') : __('Create Category') }}</span>
                            <span wire:loading wire:target="saveCategory">{{ __('Saving...') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
