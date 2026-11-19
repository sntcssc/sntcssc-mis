<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialFile;
use App\Models\StudyMaterialShare;
use App\Models\StudyMaterialType;
use App\Models\User;
use App\Services\StudyMaterial\StudyMaterialImportExportService;
use App\Services\StudyMaterial\StudyMaterialService;
use App\Services\StudyMaterial\StudyMaterialTypeService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Study Materials Management')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'tab')]
    public string $activeTab = 'all'; // all, lecture_notes, e_book_pdf, slide_deck, video_recording, question_bank, pinned, trashed

    #[Url(as: 'course')]
    public string $courseFilter = '';

    #[Url(as: 'batch')]
    public string $batchFilter = '';

    #[Url(as: 'type')]
    public string $typeFilter = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    #[Url(as: 'access')]
    public string $accessFilter = '';

    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';
    public int $perPage = 12;
    public string $viewMode = 'grid'; // grid, table

    // Create / Edit Modal State
    public bool $showFormModal = false;
    public ?int $editingId = null;
    public string $formTab = 'general'; // general, files, content, notifications

    public array $form = [
        'course_id' => '',
        'batch_ids' => [],
        'student_ids' => [],
        'title' => '',
        'subject' => '',
        'topic' => '',
        'module_code' => '',
        'material_type' => 'lecture_notes',
        'class_date' => '',
        'display_order' => 0,
        'thumbnail_url' => '',
        'description' => '',
        'content' => '',
        'external_url' => '',
        'access_level' => 'enrolled_only',
        'share_password' => '',
        'share_expires_at' => '',
        'is_pinned' => false,
        'status' => 'published',
    ];

    public $thumbnailFile = null;
    public ?string $existingThumbnailUrl = null;
    public bool $removeThumbnail = false;

    public $newFiles = [];
    public array $existingFiles = [];
    public array $deleteFileIds = [];

    // Notification Options on Save
    public bool $notifyOnSave = false;
    public array $notifyChannels = ['database', 'email', 'whatsapp'];
    public string $notifyAudience = 'batch'; // 'batch' or 'specific'
    public array $notifySpecificStudents = [];
    public string $notifyCustomMessage = '';

    // Dedicated Share Modal State
    public bool $showShareModal = false;
    public ?StudyMaterial $sharingMaterial = null;
    public array $shareChannels = ['whatsapp', 'email', 'sms', 'telegram', 'in_app'];
    public string $shareTarget = 'batch'; // 'batch' or 'specific'
    public array $shareSelectedStudents = [];
    public string $shareCustomMessage = '';
    public ?array $shareResult = null;
    public bool $isSharing = false;

    // View Dossier / Detail Modal State
    public bool $showDetailModal = false;
    public ?StudyMaterial $viewingMaterial = null;

    // Import Modal State
    public bool $showImportModal = false;
    public $importFile = null;
    public ?array $importSummary = null;

    // Delete confirmation state
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public bool $isForceDelete = false;

    // Material Types Management State
    public bool $showTypesModal = false;
    public bool $showTypeFormModal = false;
    public ?int $editingTypeId = null;
    public array $typeForm = [
        'name' => '',
        'code' => '',
        'description' => '',
        'icon' => 'file-text',
        'badge_color' => 'primary',
        'is_public' => true,
        'is_active' => true,
        'display_order' => 0,
    ];

    public function mount(): void
    {
        $user = auth()->user();
        if ($user && $user->hasRole('Student') && ! $user->hasRole(['Super Administrator', 'Administrator', 'Faculty', 'Staff'])) {
            abort(403);
        }

        abort_unless($user && ($user->can('study_materials.view') || $user->hasRole('Super Administrator')), 403);
    }

    public function openTypesModal(): void
    {
        abort_unless(auth()->user()->can('study_materials.view') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->showTypesModal = true;
    }

    public function openCreateTypeModal(): void
    {
        abort_unless(auth()->user()->can('study_materials.create') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->resetErrorBag();
        $this->resetValidation();
        $this->editingTypeId = null;
        $this->typeForm = [
            'name' => '',
            'code' => '',
            'description' => '',
            'icon' => 'file-text',
            'badge_color' => 'primary',
            'is_public' => true,
            'is_active' => true,
            'display_order' => (int) (StudyMaterialType::max('display_order') + 1),
        ];
        $this->showTypeFormModal = true;
    }

    public function openQuickCreateTypeModal(): void
    {
        $this->openCreateTypeModal();
    }

    public function editType(int $typeId): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->resetErrorBag();
        $this->resetValidation();
        $type = StudyMaterialType::findOrFail($typeId);
        $this->editingTypeId = $type->id;
        $this->typeForm = [
            'name' => $type->name,
            'code' => $type->code,
            'description' => $type->description ?? '',
            'icon' => $type->icon ?? 'file-text',
            'badge_color' => $type->badge_color ?? 'primary',
            'is_public' => (bool) $type->is_public,
            'is_active' => (bool) $type->is_active,
            'display_order' => (int) $type->display_order,
        ];
        $this->showTypeFormModal = true;
    }

    public function saveType(StudyMaterialTypeService $typeService): void
    {
        if ($this->editingTypeId) {
            abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        } else {
            abort_unless(auth()->user()->can('study_materials.create') || auth()->user()->hasRole('Super Administrator'), 403);
        }

        $this->validate([
            'typeForm.name' => ['required', 'string', 'max:100'],
            'typeForm.code' => ['nullable', 'string', 'max:64'],
            'typeForm.description' => ['nullable', 'string', 'max:500'],
            'typeForm.icon' => ['required', 'string', 'max:50'],
            'typeForm.badge_color' => ['required', 'string', 'max:30'],
            'typeForm.is_public' => ['boolean'],
            'typeForm.is_active' => ['boolean'],
        ], [
            'typeForm.name.required' => __('Please provide a name for the material type.'),
        ]);

        try {
            if ($this->editingTypeId) {
                $type = StudyMaterialType::findOrFail($this->editingTypeId);
                $typeService->updateType($type, $this->typeForm, auth()->user());
                Toast::dispatch($this, 'success', __('Material type updated successfully.'));
            } else {
                $type = $typeService->createType($this->typeForm, auth()->user());
                Toast::dispatch($this, 'success', __('Custom material type created successfully.'));
                if ($this->showFormModal) {
                    $this->form['material_type'] = $type->code;
                }
            }

            unset($this->allMaterialTypesList);
            $this->showTypeFormModal = false;
            $this->editingTypeId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save material type: ').$e->getMessage());
        }
    }

    public function toggleTypeStatus(int $typeId, StudyMaterialTypeService $typeService): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        try {
            $type = StudyMaterialType::findOrFail($typeId);
            $typeService->toggleStatus($type, auth()->user());
            unset($this->allMaterialTypesList);
            Toast::dispatch($this, 'success', __('Status updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function toggleTypeVisibility(int $typeId, StudyMaterialTypeService $typeService): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        try {
            $type = StudyMaterialType::findOrFail($typeId);
            $typeService->toggleVisibility($type, auth()->user());
            unset($this->allMaterialTypesList);
            Toast::dispatch($this, 'success', __('Visibility updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function deleteType(int $typeId, StudyMaterialTypeService $typeService): void
    {
        abort_unless(auth()->user()->can('study_materials.delete') || auth()->user()->hasRole('Super Administrator'), 403);
        try {
            $type = StudyMaterialType::findOrFail($typeId);
            $typeService->deleteType($type, auth()->user());
            unset($this->allMaterialTypesList);
            Toast::dispatch($this, 'success', __('Custom material type deleted successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    #[Computed]
    public function allMaterialTypesList()
    {
        return StudyMaterialType::ordered()->get();
    }

    public function updatedFormCourseId($value): void
    {
        // Reset selected batches when course changes
        $this->form['batch_ids'] = [];
    }

    #[Computed]
    public function courses()
    {
        return Course::active()->orderBy('name')->get(['id', 'name', 'code']);
    }

    #[Computed]
    public function availableBatches()
    {
        if (empty($this->form['course_id'])) {
            return collect();
        }

        $course = Course::find($this->form['course_id']);
        if (! $course) {
            return collect();
        }

        return $course->batches()->orderBy('name')->get(['batches.id', 'batches.name', 'batches.code', 'batches.academic_year']);
    }

    #[Computed]
    public function courseFilterBatches()
    {
        if (empty($this->courseFilter)) {
            return Batch::orderBy('name')->get(['id', 'name', 'code']);
        }

        $course = Course::find($this->courseFilter);
        return $course ? $course->batches()->orderBy('name')->get(['batches.id', 'batches.name', 'batches.code']) : collect();
    }

    #[Computed]
    public function eligibleStudents()
    {
        if (empty($this->form['course_id'])) {
            return collect();
        }

        $query = Enrollment::with('user')
            ->where('course_id', $this->form['course_id'])
            ->where('status', Enrollment::STATUS_ACTIVE);

        if (! empty($this->form['batch_ids'])) {
            $query->whereIn('batch_id', $this->form['batch_ids']);
        }

        return $query->get()->pluck('user')->unique('id')->filter()->values();
    }

    #[Computed]
    public function shareEligibleStudents()
    {
        if (! $this->sharingMaterial) {
            return collect();
        }

        $batchIds = $this->sharingMaterial->batches()->pluck('batches.id')->all();
        $query = Enrollment::with('user')
            ->where('course_id', $this->sharingMaterial->course_id)
            ->where('status', Enrollment::STATUS_ACTIVE);

        if (! empty($batchIds)) {
            $query->whereIn('batch_id', $batchIds);
        }

        return $query->get()->pluck('user')->unique('id')->filter()->values();
    }

    #[Computed]
    public function stats(): array
    {
        $service = app(StudyMaterialService::class);
        return $service->getStatistics();
    }

    #[Computed]
    public function materials()
    {
        $query = StudyMaterial::with(['course', 'batches', 'files', 'creator']);

        if ($this->activeTab === 'trashed') {
            $query->onlyTrashed();
        } else {
            $query->withoutTrashed();

            if ($this->activeTab === 'pinned') {
                $query->where('is_pinned', true);
            } elseif (in_array($this->activeTab, array_keys(StudyMaterial::materialTypes()), true)) {
                $query->where('material_type', $this->activeTab);
            }
        }

        // Search
        if ($search = trim($this->search)) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('topic', 'like', "%{$search}%")
                    ->orWhere('module_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('course', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('batches', fn ($b) => $b->where('name', 'like', "%{$search}%"));
            });
        }

        // Filters
        if ($this->courseFilter !== '') {
            $query->where('course_id', $this->courseFilter);
        }

        if ($this->batchFilter !== '') {
            $query->whereHas('batches', fn ($b) => $b->where('batches.id', $this->batchFilter));
        }

        if ($this->typeFilter !== '') {
            $query->where('material_type', $this->typeFilter);
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->accessFilter !== '') {
            $query->where('access_level', $this->accessFilter);
        }

        // Sorting
        if ($this->sortField === 'downloads') {
            $query->orderBy('download_count', $this->sortDirection);
        } elseif ($this->sortField === 'views') {
            $query->orderBy('view_count', $this->sortDirection);
        } elseif ($this->sortField === 'title') {
            $query->orderBy('title', $this->sortDirection);
        } elseif ($this->sortField === 'class_date') {
            $query->orderBy('class_date', $this->sortDirection)->orderByDesc('id');
        } elseif ($this->sortField === 'display_order') {
            $query->orderBy('display_order', $this->sortDirection)->orderByDesc('id');
        } else {
            // Pin priority first, then display sequence, then date
            $query->orderByDesc('is_pinned')->orderBy('display_order', 'asc')->orderBy($this->sortField, $this->sortDirection);
        }

        return $query->paginate($this->perPage);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        abort_unless(auth()->user()->can('study_materials.create') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->editingId = null;
        $this->formTab = 'general';
        $maxOrder = (int) (StudyMaterial::max('display_order') ?? 0);
        $this->form = [
            'course_id' => $this->courses->first()?->id ?? '',
            'batch_ids' => [],
            'student_ids' => [],
            'title' => '',
            'subject' => '',
            'topic' => '',
            'module_code' => '',
            'material_type' => StudyMaterial::TYPE_LECTURE_NOTES,
            'class_date' => '',
            'display_order' => $maxOrder + 1,
            'thumbnail_url' => '',
            'description' => '',
            'content' => '',
            'external_url' => '',
            'access_level' => StudyMaterial::ACCESS_ENROLLED_ONLY,
            'share_password' => '',
            'share_expires_at' => '',
            'is_pinned' => false,
            'status' => StudyMaterial::STATUS_PUBLISHED,
        ];

        $this->thumbnailFile = null;
        $this->existingThumbnailUrl = null;
        $this->removeThumbnail = false;
        $this->newFiles = [];
        $this->existingFiles = [];
        $this->deleteFileIds = [];
        $this->notifyOnSave = false;
        $this->notifyChannels = ['database', 'email', 'whatsapp'];
        $this->notifyAudience = 'batch';
        $this->notifySpecificStudents = [];
        $this->notifyCustomMessage = '';

        $this->resetValidation();
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        $material = StudyMaterial::with(['course', 'batches', 'files', 'students'])->findOrFail($id);

        $this->editingId = $material->id;
        $this->formTab = 'general';
        $this->form = [
            'course_id' => $material->course_id,
            'batch_ids' => $material->batches->pluck('id')->all(),
            'student_ids' => $material->students->pluck('id')->all(),
            'title' => $material->title,
            'subject' => $material->subject ?? '',
            'topic' => $material->topic ?? '',
            'module_code' => $material->module_code ?? '',
            'material_type' => $material->material_type,
            'class_date' => $material->class_date ? $material->class_date->format('Y-m-d') : '',
            'display_order' => (int) $material->display_order,
            'thumbnail_url' => $material->thumbnail_url ?? '',
            'description' => $material->description ?? '',
            'content' => $material->content ?? '',
            'external_url' => $material->external_url ?? '',
            'access_level' => $material->access_level,
            'share_password' => '',
            'share_expires_at' => $material->share_expires_at ? $material->share_expires_at->format('Y-m-d\TH:i') : '',
            'is_pinned' => (bool) $material->is_pinned,
            'status' => $material->status,
        ];

        $this->thumbnailFile = null;
        $this->existingThumbnailUrl = $material->thumbnail_image_url;
        $this->removeThumbnail = false;

        $this->existingFiles = $material->files->map(fn ($f) => [
            'id' => $f->id,
            'name' => $f->file_name,
            'size' => $f->formatted_size,
            'type' => $f->file_type,
            'icon' => $f->file_icon,
        ])->toArray();

        $this->newFiles = [];
        $this->deleteFileIds = [];
        $this->notifyOnSave = false;

        $this->resetValidation();
        $this->showFormModal = true;
    }

    public function removeThumbnailImage(): void
    {
        $this->thumbnailFile = null;
        $this->existingThumbnailUrl = null;
        $this->form['thumbnail_url'] = '';
        $this->removeThumbnail = true;
    }

    public function moveOrder(int $id, string $direction, StudyMaterialService $service): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        try {
            $material = StudyMaterial::findOrFail($id);
            $service->moveDisplayOrder($material, $direction);
            Toast::dispatch($this, 'success', __('Material sequence updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to reorder: ').$e->getMessage());
        }
    }

    public function updateOrder(int $id, int $newOrder, StudyMaterialService $service): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        try {
            $material = StudyMaterial::findOrFail($id);
            $service->updateDisplayOrder($material, max(0, $newOrder));
            Toast::dispatch($this, 'success', __('Material display order updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to update order: ').$e->getMessage());
        }
    }

    public function toggleDeleteFile(int $fileId): void
    {
        if (in_array($fileId, $this->deleteFileIds, true)) {
            $this->deleteFileIds = array_diff($this->deleteFileIds, [$fileId]);
        } else {
            $this->deleteFileIds[] = $fileId;
        }
    }

    public function selectAllBatches(): void
    {
        $allIds = $this->availableBatches->pluck('id')->all();
        $this->form['batch_ids'] = $allIds;
    }

    public function clearBatches(): void
    {
        $this->form['batch_ids'] = [];
    }

    public function saveMaterial(StudyMaterialService $service): void
    {
        if ($this->editingId) {
            abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        } else {
            abort_unless(auth()->user()->can('study_materials.create') || auth()->user()->hasRole('Super Administrator'), 403);
        }

        $isStandalone = in_array($this->form['material_type'] ?? '', [
            StudyMaterial::TYPE_CURRENT_AFFAIRS,
            StudyMaterial::TYPE_PIB_SANDHAN,
        ], true);

        $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.course_id' => [
                $isStandalone ? 'nullable' : 'required',
                'nullable',
                'exists:courses,id',
            ],
            'form.material_type' => ['required', 'string'],
            'form.status' => ['required', 'in:published,draft,archived'],
            'form.access_level' => ['required', 'in:enrolled_only,public_link,restricted_students'],
            'form.class_date' => ['nullable', 'date'],
            'form.display_order' => ['nullable', 'integer', 'min:0'],
            'form.thumbnail_url' => ['nullable', 'url', 'max:1000'],
            'thumbnailFile' => ['nullable', 'image', 'max:5120'], // 5MB max
            'form.external_url' => ['nullable', 'url', 'max:500'],
            'newFiles.*' => ['nullable', 'file', 'max:102400'], // 100MB per file
        ], [
            'form.title.required' => __('Please enter a title for the study material.'),
            'form.course_id.required' => __('Please select the target academic course.'),
            'form.external_url.url' => __('External link must be a valid URL.'),
            'form.thumbnail_url.url' => __('Custom thumbnail link must be a valid image URL.'),
            'thumbnailFile.image' => __('Thumbnail must be a valid image file (PNG, JPG, WEBP).'),
            'thumbnailFile.max' => __('Thumbnail image size cannot exceed 5MB.'),
            'newFiles.*.max' => __('Individual file size must not exceed 100MB.'),
        ]);

        try {
            $payload = $this->form;
            if (empty($payload['course_id'])) {
                $payload['course_id'] = null;
            }

            if ($this->thumbnailFile) {
                $payload['thumbnail_file'] = $this->thumbnailFile;
            }
            $payload['remove_thumbnail'] = $this->removeThumbnail;

            $notifyConfig = [
                'enabled' => $this->notifyOnSave,
                'channels' => $this->notifyChannels,
                'recipient_type' => $this->notifyAudience,
                'student_ids' => $this->notifySpecificStudents,
                'custom_message' => $this->notifyCustomMessage,
            ];

            if ($this->editingId) {
                $material = StudyMaterial::findOrFail($this->editingId);
                $service->updateMaterial(
                    material: $material,
                    data: $payload,
                    newFiles: $this->newFiles,
                    deleteFileIds: $this->deleteFileIds
                );
                Toast::dispatch($this, 'success', __('Study material updated successfully.'));
            } else {
                $service->createMaterial(
                    data: $payload,
                    uploadedFiles: $this->newFiles,
                    notifyConfig: $notifyConfig
                );
                Toast::dispatch($this, 'success', __('New study material created successfully.'));
            }

            $this->showFormModal = false;
            $this->editingId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Operation failed: ').$e->getMessage());
        }
    }

    public function openShareModal(int $id): void
    {
        abort_unless(auth()->user()->can('study_materials.share') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->sharingMaterial = StudyMaterial::with(['course', 'batches', 'files'])->findOrFail($id);
        $this->shareChannels = ['whatsapp', 'email', 'sms', 'telegram', 'in_app'];
        $this->shareTarget = 'batch';
        $this->shareSelectedStudents = [];
        $this->shareCustomMessage = '';
        $this->shareResult = null;
        $this->isSharing = false;

        $this->showShareModal = true;
    }

    public function dispatchShare(StudyMaterialService $service): void
    {
        abort_unless(auth()->user()->can('study_materials.share') || auth()->user()->hasRole('Super Administrator'), 403);
        if (! $this->sharingMaterial) {
            return;
        }

        if (empty($this->shareChannels)) {
            Toast::dispatch($this, 'warning', __('Please select at least one share channel.'));
            return;
        }

        $this->isSharing = true;

        try {
            $recipientConfig = [
                'type' => $this->shareTarget,
                'student_ids' => $this->shareSelectedStudents,
            ];

            $result = $service->shareMaterial(
                material: $this->sharingMaterial,
                channels: $this->shareChannels,
                recipientConfig: $recipientConfig,
                customMessage: $this->shareCustomMessage
            );

            $this->shareResult = $result;
            Toast::dispatch($this, 'success', __("Dispatched to :count students across selected channels.", ['count' => $result['successful']]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Share failed: ').$e->getMessage());
        } finally {
            $this->isSharing = false;
        }
    }

    public function openDetailModal(int $id): void
    {
        $this->viewingMaterial = StudyMaterial::with(['course', 'batches', 'files', 'creator', 'shares.sender', 'accessLogs.user'])
            ->findOrFail($id);
        $this->showDetailModal = true;
    }

    public function togglePin(int $id): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        $material = StudyMaterial::findOrFail($id);
        $material->update(['is_pinned' => ! $material->is_pinned]);
        Toast::dispatch($this, 'info', $material->is_pinned ? __('Material pinned to top.') : __('Material unpinned.'));
    }

    public function toggleStatus(int $id, string $status): void
    {
        abort_unless(auth()->user()->can('study_materials.edit') || auth()->user()->hasRole('Super Administrator'), 403);
        $material = StudyMaterial::findOrFail($id);
        $material->update(['status' => $status]);
        Toast::dispatch($this, 'success', __("Status changed to :status.", ['status' => ucfirst($status)]));
    }

    public function confirmDelete(int $id, bool $force = false): void
    {
        abort_unless(auth()->user()->can('study_materials.delete') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->deletingId = $id;
        $this->isForceDelete = $force;
        $this->showDeleteModal = true;
    }

    public function executeDelete(StudyMaterialService $service): void
    {
        abort_unless(auth()->user()->can('study_materials.delete') || auth()->user()->hasRole('Super Administrator'), 403);
        if (! $this->deletingId) {
            return;
        }

        try {
            if ($this->isForceDelete) {
                $service->forceDeleteMaterial($this->deletingId);
                Toast::dispatch($this, 'success', __('Study material permanently purged.'));
            } else {
                $material = StudyMaterial::findOrFail($this->deletingId);
                $service->deleteMaterial($material);
                Toast::dispatch($this, 'success', __('Study material moved to trash.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Delete failed: ').$e->getMessage());
        } finally {
            $this->showDeleteModal = false;
            $this->deletingId = null;
        }
    }

    public function restoreMaterial(int $id, StudyMaterialService $service): void
    {
        abort_unless(auth()->user()->can('study_materials.restore') || auth()->user()->hasRole('Super Administrator'), 403);
        try {
            $service->restoreMaterial($id);
            Toast::dispatch($this, 'success', __('Study material restored successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Restore failed: ').$e->getMessage());
        }
    }

    public function export(string $format = 'csv')
    {
        abort_unless(auth()->user()->can('study_materials.export') || auth()->user()->hasRole('Super Administrator'), 403);
        $query = StudyMaterial::with(['course', 'batches', 'files']);

        if ($this->courseFilter !== '') {
            $query->where('course_id', $this->courseFilter);
        }
        if ($this->batchFilter !== '') {
            $query->whereHas('batches', fn ($b) => $b->where('batches.id', $this->batchFilter));
        }
        if ($this->typeFilter !== '') {
            $query->where('material_type', $this->typeFilter);
        }
        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return StudyMaterialImportExportService::export($query, $format);
    }

    public function openImportModal(): void
    {
        abort_unless(auth()->user()->can('study_materials.import') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->importFile = null;
        $this->importSummary = null;
        $this->showImportModal = true;
    }

    public function runImport(): void
    {
        abort_unless(auth()->user()->can('study_materials.import') || auth()->user()->hasRole('Super Administrator'), 403);
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ]);

        try {
            $path = $this->importFile->getRealPath();
            $result = StudyMaterialImportExportService::importMaterials($path);
            $this->importSummary = $result;

            if ($result['imported'] > 0) {
                Toast::dispatch($this, 'success', __("Import completed: :count materials imported successfully.", ['count' => $result['imported']]));
            } else {
                Toast::dispatch($this, 'warning', __('No records were imported. Please check spreadsheet headers.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Import error: ').$e->getMessage());
        }
    }

    public function downloadSampleTemplate()
    {
        abort_unless(auth()->user()->can('study_materials.import') || auth()->user()->hasRole('Super Administrator'), 403);
        return StudyMaterialImportExportService::downloadTemplate();
    }
}; ?>

<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Study Materials & Course Resources') }}</h1>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    {{ number_format($this->stats['total_materials']) }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Dynamic batch-wise course study materials, notes, recordings, syllabus, and multi-channel sharing.') }}
            </p>
        </div>

        <!-- Top Actions -->
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.study-materials.statistics') }}" wire:navigate
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="bar-chart-2" class="w-4 h-4 text-primary" />
                <span>{{ __('Analytics') }}</span>
            </a>

            <!-- Export Dropdown -->
            @can('study_materials.export')
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                        <x-icon name="download" class="w-4 h-4 text-muted-foreground" />
                        <span>{{ __('Export') }}</span>
                        <x-icon name="chevron-down" class="w-3 h-3 text-muted-foreground" />
                    </button>
                    <div x-show="open" @click.away="open = false" x-cloak
                         class="absolute right-0 mt-1 w-36 bg-popover text-popover-foreground rounded-lg shadow-lg border border-border py-1 z-30">
                        <button wire:click="export('csv')" @click="open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-muted flex items-center gap-2">
                            <x-icon name="file-text" class="w-3.5 h-3.5" />
                            <span>{{ __('CSV Document') }}</span>
                        </button>
                        <button wire:click="export('xlsx')" @click="open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-muted flex items-center gap-2">
                            <x-icon name="file-spreadsheet" class="w-3.5 h-3.5" />
                            <span>{{ __('Excel (.xlsx)') }}</span>
                        </button>
                        <button wire:click="export('pdf')" @click="open = false" class="w-full text-left px-3 py-1.5 text-xs hover:bg-muted flex items-center gap-2">
                            <x-icon name="file" class="w-3.5 h-3.5" />
                            <span>{{ __('PDF Document') }}</span>
                        </button>
                    </div>
                </div>
            @endcan

            <!-- Import Button -->
            @can('study_materials.import')
                <button wire:click="openImportModal" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                    <x-icon name="upload" class="w-4 h-4 text-muted-foreground" />
                    <span>{{ __('Import') }}</span>
                </button>
            @endcan

            <!-- Material Types Catalog Button -->
            <button wire:click="openTypesModal" type="button"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-lg border border-border bg-card text-foreground hover:bg-muted shadow-sm transition-colors">
                <x-icon name="tag" class="w-4 h-4 text-primary" />
                <span>{{ __('Material Types') }}</span>
            </button>

            <!-- New Material Button -->
            @can('study_materials.create')
                <button wire:click="openCreateModal" type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm transition-all">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('Upload Material') }}</span>
                </button>
            @endcan
        </div>
    </div>

    <!-- Real-Time Institutional KPI Stat Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Total Materials -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Total Materials') }}</span>
                    <h3 class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['total_materials']) }}</h3>
                </div>
                <div class="w-10 h-10 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0">
                    <x-icon name="folder" class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-center gap-2 mt-3 text-xs text-muted-foreground">
                <span class="text-emerald-600 font-semibold">{{ $this->stats['published_materials'] }} {{ __('Active') }}</span>
                <span>&bull;</span>
                <span>{{ $this->stats['draft_materials'] }} {{ __('Drafts') }}</span>
            </div>
        </div>

        <!-- Files & Storage -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Attached Files') }}</span>
                    <h3 class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['total_files']) }}</h3>
                </div>
                <div class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
                    <x-icon name="hard-drive" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-3 text-xs text-muted-foreground">
                <span class="font-semibold text-foreground">{{ $this->stats['formatted_storage'] }}</span> {{ __('Storage Consumed') }}
            </div>
        </div>

        <!-- Total Downloads -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Student Downloads') }}</span>
                    <h3 class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['total_downloads']) }}</h3>
                </div>
                <div class="w-10 h-10 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center shrink-0">
                    <x-icon name="download-cloud" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-3 text-xs text-muted-foreground">
                <span class="font-semibold text-foreground">{{ number_format($this->stats['total_views']) }}</span> {{ __('Online Views') }}
            </div>
        </div>

        <!-- Shares Dispatched -->
        <div class="bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Shares Dispatched') }}</span>
                    <h3 class="text-2xl font-bold text-foreground mt-1">{{ number_format($this->stats['total_shares']) }}</h3>
                </div>
                <div class="w-10 h-10 rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0">
                    <x-icon name="share-2" class="w-5 h-5" />
                </div>
            </div>
            <div class="flex items-center gap-1.5 mt-3 text-xs text-muted-foreground">
                <span class="text-emerald-600 font-medium">WA: {{ $this->stats['shares_by_channel']['whatsapp'] ?? 0 }}</span>
                <span>&bull;</span>
                <span class="text-blue-600 font-medium">Email: {{ $this->stats['shares_by_channel']['email'] ?? 0 }}</span>
            </div>
        </div>

        <!-- Course Coverage -->
        <div class="col-span-2 lg:col-span-1 bg-card rounded-xl border border-border p-4 shadow-sm relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-medium text-muted-foreground">{{ __('Courses Active') }}</span>
                    <h3 class="text-2xl font-bold text-foreground mt-1">{{ $this->stats['course_distribution']->count() }}</h3>
                </div>
                <div class="w-10 h-10 rounded-lg bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center shrink-0">
                    <x-icon name="graduation-cap" class="w-5 h-5" />
                </div>
            </div>
            <div class="mt-3 text-xs text-muted-foreground truncate">
                {{ __('Organized by batch & subject') }}
            </div>
        </div>
    </div>

    <!-- Scope Filter Tabs -->
    <div class="border-b border-border">
        <nav class="flex space-x-2 overflow-x-auto scrollbar-none py-1 text-xs">
            <button wire:click="setTab('all')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'all' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="layers" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('All Materials') }}
            </button>
            <button wire:click="setTab('current_affairs')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'current_affairs' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="newspaper" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Current Affairs') }}
            </button>
            <button wire:click="setTab('pib_sandhan')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'pib_sandhan' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="book-marked" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('PIB Sandhan') }}
            </button>
            <button wire:click="setTab('lecture_notes')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'lecture_notes' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="file-text" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Lecture Notes') }}
            </button>
            <button wire:click="setTab('e_book_pdf')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'e_book_pdf' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="book-open" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('E-Books & PDFs') }}
            </button>
            <button wire:click="setTab('slide_deck')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'slide_deck' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="presentation" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Slide Decks') }}
            </button>
            <button wire:click="setTab('video_recording')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'video_recording' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="video" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Recordings') }}
            </button>
            <button wire:click="setTab('question_bank')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'question_bank' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="clipboard-check" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Mock Tests') }}
            </button>
            <button wire:click="setTab('pinned')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'pinned' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="pin" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Pinned Priority') }}
            </button>
            <button wire:click="setTab('trashed')"
                    class="px-3 py-2 rounded-lg font-medium whitespace-nowrap transition-colors {{ $activeTab === 'trashed' ? 'bg-destructive text-destructive-foreground shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground' }}">
                <x-icon name="trash-2" class="w-3.5 h-3.5 inline mr-1" />
                {{ __('Trash Archive') }}
            </button>
        </nav>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-card rounded-xl border border-border p-4 shadow-sm space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <!-- Search -->
            <div class="lg:col-span-2 relative">
                <x-icon name="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
                <input type="text" wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('Search by title, subject, topic, course...') }}"
                       class="w-full pl-9 pr-4 py-2 text-xs rounded-lg border border-border bg-background text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary focus:outline-none" />
                @if($search)
                    <button wire:click="$set('search', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                        <x-icon name="x" class="w-3.5 h-3.5" />
                    </button>
                @endif
            </div>

            <!-- Course Filter -->
            <div>
                <select wire:model.live="courseFilter" class="w-full py-2 px-3 text-xs rounded-lg border border-border bg-background text-foreground focus:ring-2 focus:ring-primary focus:outline-none">
                    <option value="">{{ __('All Courses') }}</option>
                    @foreach($this->courses as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                    @endforeach
                </select>
            </div>

            <!-- Batch Filter -->
            <div>
                <select wire:model.live="batchFilter" class="w-full py-2 px-3 text-xs rounded-lg border border-border bg-background text-foreground focus:ring-2 focus:ring-primary focus:outline-none">
                    <option value="">{{ __('All Batches') }}</option>
                    @foreach($this->courseFilterBatches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Material Type Filter -->
            <div>
                <select wire:model.live="typeFilter" class="w-full py-2 px-3 text-xs rounded-lg border border-border bg-background text-foreground focus:ring-2 focus:ring-primary focus:outline-none">
                    <option value="">{{ __('All Material Types') }}</option>
                    @foreach(StudyMaterial::materialTypes() as $k => $label)
                        <option value="{{ $k }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Status Filter -->
            <div>
                <select wire:model.live="statusFilter" class="w-full py-2 px-3 text-xs rounded-lg border border-border bg-background text-foreground focus:ring-2 focus:ring-primary focus:outline-none">
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="published">{{ __('Published') }}</option>
                    <option value="draft">{{ __('Draft') }}</option>
                    <option value="archived">{{ __('Archived') }}</option>
                </select>
            </div>
        </div>

        <div class="flex items-center justify-between pt-2 border-t border-border/50 text-xs text-muted-foreground">
            <div class="flex items-center gap-3">
                <span>{{ __('Showing') }} <strong class="text-foreground">{{ $this->materials->total() }}</strong> {{ __('resources') }}</span>
                @if($search || $courseFilter || $batchFilter || $typeFilter || $statusFilter)
                    <button wire:click="$set('search', ''); $set('courseFilter', ''); $set('batchFilter', ''); $set('typeFilter', ''); $set('statusFilter', '');"
                            class="text-primary hover:underline font-medium">
                        {{ __('Clear Filters') }}
                    </button>
                @endif
            </div>

            <div class="flex items-center gap-2">
                <!-- Grid vs Table View Switcher -->
                <div class="flex items-center bg-muted rounded-lg p-0.5 border border-border">
                    <button type="button" wire:click="$set('viewMode', 'grid')"
                            class="p-1 rounded {{ $viewMode === 'grid' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' }}"
                            title="{{ __('Grid Cards View') }}">
                        <x-icon name="grid" class="w-3.5 h-3.5" />
                    </button>
                    <button type="button" wire:click="$set('viewMode', 'table')"
                            class="p-1 rounded {{ $viewMode === 'table' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground' }}"
                            title="{{ __('Table List View') }}">
                        <x-icon name="list" class="w-3.5 h-3.5" />
                    </button>
                </div>

                <!-- Sort Field -->
                <select wire:model.live="sortField" class="py-1 px-2 text-xs rounded-lg border border-border bg-background text-foreground">
                    <option value="display_order">{{ __('Display Order (Sequence)') }}</option>
                    <option value="class_date">{{ __('Class Date') }}</option>
                    <option value="created_at">{{ __('Newest First') }}</option>
                    <option value="title">{{ __('Alphabetical') }}</option>
                    <option value="downloads">{{ __('Most Downloaded') }}</option>
                    <option value="views">{{ __('Most Viewed') }}</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Materials Content Display -->
    @if($this->materials->isEmpty())
        <div class="bg-card rounded-2xl border border-dashed border-border p-12 text-center">
            <div class="w-16 h-16 rounded-full bg-primary/10 text-primary mx-auto flex items-center justify-center mb-4">
                <x-icon name="folder-open" class="w-8 h-8" />
            </div>
            <h3 class="text-base font-semibold text-foreground">{{ __('No Study Materials Found') }}</h3>
            <p class="text-xs text-muted-foreground max-w-sm mx-auto mt-1">
                {{ __('No study materials match your current search, tab or filter criteria. Upload course notes or clear your filters.') }}
            </p>
            @can('study_materials.create')
                <button wire:click="openCreateModal" class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('Upload First Study Material') }}</span>
                </button>
            @endcan
        </div>
    @elseif($viewMode === 'grid')
        <!-- Grid View -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($this->materials as $material)
                <div class="bg-card rounded-xl border border-border hover:border-primary/50 shadow-sm transition-all flex flex-col justify-between group overflow-hidden relative {{ $material->is_pinned ? 'ring-1 ring-primary/40' : '' }}">
                    @if($material->is_pinned)
                        <div class="absolute top-0 right-0 bg-primary text-primary-foreground text-[10px] font-bold px-2 py-0.5 rounded-bl-lg shadow-sm flex items-center gap-1 z-10">
                            <x-icon name="pin" class="w-3 h-3" />
                            <span>{{ __('PINNED') }}</span>
                        </div>
                    @endif

                    @if($material->thumbnail_image_url)
                        <div class="h-36 w-full bg-muted/60 relative overflow-hidden border-b border-border">
                            <img src="{{ $material->thumbnail_image_url }}" alt="{{ $material->title }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
                            @if($material->class_date)
                                <div class="absolute bottom-2 left-2 bg-background/90 text-foreground backdrop-blur-sm text-[10px] font-semibold px-2 py-0.5 rounded shadow-sm flex items-center gap-1">
                                    <x-icon name="calendar" class="w-3 h-3 text-primary" />
                                    <span>{{ __('Class: :date', ['date' => $material->class_date->format('d M Y')]) }}</span>
                                </div>
                            @endif
                            <div class="absolute bottom-2 right-2 bg-background/90 text-foreground backdrop-blur-sm text-[10px] font-mono font-semibold px-2 py-0.5 rounded shadow-sm">
                                #{{ $material->display_order }}
                            </div>
                        </div>
                    @endif

                    <!-- Card Body -->
                    <div class="p-5 space-y-3">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-[11px] font-semibold bg-primary/10 text-primary border border-primary/20">
                                <x-icon name="{{ $material->material_type_icon }}" class="w-3 h-3" />
                                {{ $material->material_type_label }}
                            </span>
                            @if($material->class_date && !$material->thumbnail_image_url)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800">
                                    <x-icon name="calendar" class="w-3 h-3" />
                                    {{ __('Class: :date', ['date' => $material->class_date->format('d M Y')]) }}
                                </span>
                            @endif
                            @if($material->subject)
                                <span class="px-2 py-0.5 rounded-md text-[11px] font-medium bg-muted text-muted-foreground border border-border">
                                    {{ $material->subject }}
                                </span>
                            @endif
                            <span class="ml-auto inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase
                                  {{ $material->status === 'published' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400' : ($material->status === 'draft' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300') }}">
                                {{ $material->status }}
                            </span>
                        </div>

                        <div>
                            <h3 class="text-base font-bold text-foreground group-hover:text-primary transition-colors line-clamp-2" title="{{ $material->title }}">
                                {{ $material->title }}
                            </h3>
                            @if($material->topic)
                                <p class="text-xs text-muted-foreground mt-0.5 flex items-center gap-1">
                                    <x-icon name="tag" class="w-3 h-3" />
                                    <span>{{ $material->topic }}</span>
                                    @if($material->module_code)
                                        <span class="font-mono text-[10px] text-muted-foreground/70">({{ $material->module_code }})</span>
                                    @endif
                                </p>
                            @endif
                        </div>

                        <!-- Course & Batch Info -->
                        <div class="pt-2 border-t border-border/50 text-xs space-y-1">
                            <div class="flex items-center justify-between text-muted-foreground">
                                <span class="font-medium text-foreground truncate max-w-[180px]">{{ $material->course?->name }}</span>
                                <span class="text-[11px] px-1.5 py-0.5 rounded bg-muted font-mono">{{ $material->course?->code }}</span>
                            </div>
                            <div class="text-[11px] text-muted-foreground flex items-center gap-1">
                                <x-icon name="layers" class="w-3 h-3 text-primary/70 shrink-0" />
                                <span class="truncate" title="{{ $material->batches_summary }}">{{ $material->batches_summary }}</span>
                            </div>
                        </div>

                        <!-- Files Summary -->
                        @if($material->files->isNotEmpty())
                            <div class="space-y-1.5 bg-muted/40 rounded-lg p-2.5 border border-border/60">
                                <div class="flex items-center justify-between text-[11px] text-muted-foreground">
                                    <span class="font-semibold text-foreground flex items-center gap-1">
                                        <x-icon name="paperclip" class="w-3 h-3" />
                                        {{ $material->files->count() }} {{ __('Attached Files') }}
                                    </span>
                                    <span>{{ $material->formatted_total_size }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1">
                                    @foreach($material->files->take(3) as $f)
                                        <a href="{{ $f->download_url }}" class="inline-flex items-center gap-1 text-[10px] bg-card text-foreground px-2 py-0.5 rounded border border-border hover:bg-muted transition-colors truncate max-w-[140px]" title="{{ $f->file_name }}">
                                            <x-icon name="{{ $f->file_icon }}" class="w-3 h-3 text-primary" />
                                            <span class="truncate">{{ $f->file_name }}</span>
                                        </a>
                                    @endforeach
                                    @if($material->files->count() > 3)
                                        <span class="text-[10px] text-muted-foreground self-center">+{{ $material->files->count() - 3 }} {{ __('more') }}</span>
                                    @endif
                                </div>
                            </div>
                        @elseif($material->external_url)
                            <div class="bg-blue-50/50 dark:bg-blue-950/20 rounded-lg p-2.5 border border-blue-200/50 dark:border-blue-800/40 text-xs">
                                <a href="{{ $material->external_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1.5 truncate font-medium">
                                    <x-icon name="external-link" class="w-3.5 h-3.5 shrink-0" />
                                    <span class="truncate">{{ $material->external_url }}</span>
                                </a>
                            </div>
                        @endif
                    </div>

                    <!-- Card Footer Actions -->
                    <div class="bg-muted/30 px-5 py-3 border-t border-border flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2.5 text-muted-foreground text-[11px]">
                            <div class="inline-flex items-center gap-0.5 bg-muted rounded px-1.5 py-0.5 text-[10px] font-mono" title="{{ __('Sequence Order') }}">
                                <span class="text-muted-foreground mr-0.5">#{{ $material->display_order }}</span>
                                @can('study_materials.edit')
                                    <button type="button" wire:click="moveOrder({{ $material->id }}, 'up')" class="hover:text-primary p-0.5" title="{{ __('Move Up') }}">
                                        <x-icon name="chevron-up" class="w-3 h-3" />
                                    </button>
                                    <button type="button" wire:click="moveOrder({{ $material->id }}, 'down')" class="hover:text-primary p-0.5" title="{{ __('Move Down') }}">
                                        <x-icon name="chevron-down" class="w-3 h-3" />
                                    </button>
                                @endcan
                            </div>
                            <span title="{{ __('Downloads') }}" class="flex items-center gap-1">
                                <x-icon name="download" class="w-3 h-3" />
                                {{ number_format($material->download_count) }}
                            </span>
                            <span title="{{ __('Views') }}" class="flex items-center gap-1">
                                <x-icon name="eye" class="w-3 h-3" />
                                {{ number_format($material->view_count) }}
                            </span>
                        </div>

                        <div class="flex items-center gap-1">
                            @if($activeTab === 'trashed')
                                @can('study_materials.restore')
                                    <button wire:click="restoreMaterial({{ $material->id }})" class="p-1.5 text-muted-foreground hover:text-emerald-600 rounded-md hover:bg-card" title="{{ __('Restore') }}">
                                        <x-icon name="rotate-ccw" class="w-4 h-4" />
                                    </button>
                                @endcan
                                @can('study_materials.delete')
                                    <button wire:click="confirmDelete({{ $material->id }}, true)" class="p-1.5 text-muted-foreground hover:text-destructive rounded-md hover:bg-card" title="{{ __('Purge Permanently') }}">
                                        <x-icon name="trash-2" class="w-4 h-4" />
                                    </button>
                                @endcan
                            @else
                                <!-- Share Button -->
                                @can('study_materials.share')
                                    <button wire:click="openShareModal({{ $material->id }})" class="p-1.5 text-muted-foreground hover:text-primary rounded-md hover:bg-card" title="{{ __('Share Material via Channels') }}">
                                        <x-icon name="share-2" class="w-4 h-4" />
                                    </button>
                                @endcan

                                <!-- Download Button -->
                                @can('study_materials.download')
                                    @if($material->files->isNotEmpty())
                                        <a href="{{ route('study-materials.download', ['studyMaterial' => $material->id]) }}" class="p-1.5 text-muted-foreground hover:text-emerald-600 rounded-md hover:bg-card" title="{{ __('Download Files') }}">
                                            <x-icon name="download" class="w-4 h-4" />
                                        </a>
                                    @endif
                                @endcan

                                <!-- View Detail -->
                                <button wire:click="openDetailModal({{ $material->id }})" class="p-1.5 text-muted-foreground hover:text-foreground rounded-md hover:bg-card" title="{{ __('View Details') }}">
                                    <x-icon name="eye" class="w-4 h-4" />
                                </button>

                                <!-- Edit -->
                                @can('study_materials.edit')
                                    <button wire:click="openEditModal({{ $material->id }})" class="p-1.5 text-muted-foreground hover:text-primary rounded-md hover:bg-card" title="{{ __('Edit Material') }}">
                                        <x-icon name="edit" class="w-4 h-4" />
                                    </button>
                                @endcan

                                <!-- Delete -->
                                @can('study_materials.delete')
                                    <button wire:click="confirmDelete({{ $material->id }}, false)" class="p-1.5 text-muted-foreground hover:text-destructive rounded-md hover:bg-card" title="{{ __('Move to Trash') }}">
                                        <x-icon name="trash" class="w-4 h-4" />
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
        <div class="bg-card rounded-xl border border-border shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-left">
                    <thead class="bg-muted/50 border-b border-border font-semibold text-muted-foreground uppercase tracking-wider text-[11px]">
                        <tr>
                            <th class="py-3 px-3 text-center w-16">{{ __('Order') }}</th>
                            <th class="py-3 px-4">{{ __('Study Material') }}</th>
                            <th class="py-3 px-4">{{ __('Class Date') }}</th>
                            <th class="py-3 px-4">{{ __('Course & Batches') }}</th>
                            <th class="py-3 px-4">{{ __('Category / Type') }}</th>
                            <th class="py-3 px-4">{{ __('Files & Size') }}</th>
                            <th class="py-3 px-4 text-center">{{ __('Metrics') }}</th>
                            <th class="py-3 px-4">{{ __('Status') }}</th>
                            <th class="py-3 px-4 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @foreach($this->materials as $material)
                            <tr class="hover:bg-muted/30 transition-colors {{ $material->is_pinned ? 'bg-primary/[0.02]' : '' }}">
                                <td class="py-3 px-3 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <span class="font-mono text-xs font-semibold text-foreground bg-muted/60 px-1.5 py-0.5 rounded border border-border/50">
                                            {{ $material->display_order }}
                                        </span>
                                        @can('study_materials.edit')
                                            <div class="flex flex-col">
                                                <button type="button" wire:click="moveOrder({{ $material->id }}, 'up')" class="text-muted-foreground hover:text-primary transition-colors p-0.5" title="{{ __('Move Up') }}">
                                                    <x-icon name="chevron-up" class="w-3 h-3" />
                                                </button>
                                                <button type="button" wire:click="moveOrder({{ $material->id }}, 'down')" class="text-muted-foreground hover:text-primary transition-colors p-0.5" title="{{ __('Move Down') }}">
                                                    <x-icon name="chevron-down" class="w-3 h-3" />
                                                </button>
                                            </div>
                                        @endcan
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-start gap-2.5">
                                        @if($material->thumbnail_image_url)
                                            <img src="{{ $material->thumbnail_image_url }}" alt="{{ $material->title }}" class="w-9 h-9 rounded-lg object-cover border border-border shrink-0 mt-0.5" />
                                        @else
                                            <div class="w-9 h-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0 mt-0.5">
                                                <x-icon name="{{ $material->material_type_icon }}" class="w-4 h-4" />
                                            </div>
                                        @endif
                                        <div>
                                            <div class="flex items-center gap-1.5">
                                                <button wire:click="openDetailModal({{ $material->id }})" class="font-bold text-foreground hover:text-primary transition-colors text-left line-clamp-1">
                                                    {{ $material->title }}
                                                </button>
                                                @if($material->is_pinned)
                                                    <span class="text-primary" title="{{ __('Pinned to Top') }}"><x-icon name="pin" class="w-3 h-3 inline" /></span>
                                                @endif
                                            </div>
                                            @if($material->subject || $material->topic)
                                                <p class="text-[11px] text-muted-foreground mt-0.5">
                                                    {{ $material->subject }} &bull; {{ $material->topic }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap">
                                    @if($material->class_date)
                                        <span class="inline-flex items-center gap-1 text-[11px] font-medium text-foreground bg-primary/5 px-2 py-0.5 rounded border border-primary/20">
                                            <x-icon name="calendar" class="w-3 h-3 text-primary" />
                                            {{ $material->class_date->format('d M Y') }}
                                        </span>
                                    @else
                                        <span class="text-muted-foreground text-[11px] italic">{{ __('Not specified') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-medium text-foreground">{{ $material->course?->name }}</div>
                                    <div class="text-[11px] text-muted-foreground">{{ $material->batches_summary }}</div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-muted text-foreground">
                                        {{ $material->material_type_label }}
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    @if($material->files->isNotEmpty())
                                        <div class="font-medium text-foreground">{{ $material->files->count() }} {{ __('file(s)') }}</div>
                                        <div class="text-[11px] text-muted-foreground">{{ $material->formatted_total_size }}</div>
                                    @elseif($material->external_url)
                                        <a href="{{ $material->external_url }}" target="_blank" class="text-blue-600 hover:underline flex items-center gap-1 text-[11px]">
                                            <x-icon name="external-link" class="w-3 h-3" />
                                            <span>{{ __('External Link') }}</span>
                                        </a>
                                    @else
                                        <span class="text-muted-foreground">{{ __('Notes only') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <div class="flex items-center justify-center gap-2 text-muted-foreground">
                                        <span title="{{ __('Downloads') }}" class="flex items-center gap-0.5 font-semibold text-foreground">
                                            <x-icon name="download" class="w-3 h-3 text-muted-foreground" />
                                            {{ $material->download_count }}
                                        </span>
                                        <span>&bull;</span>
                                        <span title="{{ __('Views') }}" class="flex items-center gap-0.5">
                                            <x-icon name="eye" class="w-3 h-3" />
                                            {{ $material->view_count }}
                                        </span>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase
                                          {{ $material->status === 'published' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400' : ($material->status === 'draft' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300') }}">
                                        {{ $material->status }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($activeTab === 'trashed')
                                            @can('study_materials.restore')
                                                <button wire:click="restoreMaterial({{ $material->id }})" class="p-1 text-muted-foreground hover:text-emerald-600 rounded" title="{{ __('Restore') }}">
                                                    <x-icon name="rotate-ccw" class="w-4 h-4" />
                                                </button>
                                            @endcan
                                            @can('study_materials.delete')
                                                <button wire:click="confirmDelete({{ $material->id }}, true)" class="p-1 text-muted-foreground hover:text-destructive rounded" title="{{ __('Purge Permanently') }}">
                                                    <x-icon name="trash-2" class="w-4 h-4" />
                                                </button>
                                            @endcan
                                        @else
                                            @can('study_materials.share')
                                                <button wire:click="openShareModal({{ $material->id }})" class="p-1 text-muted-foreground hover:text-primary rounded" title="{{ __('Share') }}">
                                                    <x-icon name="share-2" class="w-4 h-4" />
                                                </button>
                                            @endcan
                                            @can('study_materials.download')
                                                @if($material->files->isNotEmpty())
                                                    <a href="{{ route('study-materials.download', ['studyMaterial' => $material->id]) }}" class="p-1 text-muted-foreground hover:text-emerald-600 rounded" title="{{ __('Download') }}">
                                                        <x-icon name="download" class="w-4 h-4" />
                                                    </a>
                                                @endif
                                            @endcan
                                            <button wire:click="openDetailModal({{ $material->id }})" class="p-1 text-muted-foreground hover:text-foreground rounded" title="{{ __('View') }}">
                                                <x-icon name="eye" class="w-4 h-4" />
                                            </button>
                                            @can('study_materials.edit')
                                                <button wire:click="openEditModal({{ $material->id }})" class="p-1 text-muted-foreground hover:text-primary rounded" title="{{ __('Edit') }}">
                                                    <x-icon name="edit" class="w-4 h-4" />
                                                </button>
                                            @endcan
                                            @can('study_materials.delete')
                                                <button wire:click="confirmDelete({{ $material->id }}, false)" class="p-1 text-muted-foreground hover:text-destructive rounded" title="{{ __('Trash') }}">
                                                    <x-icon name="trash" class="w-4 h-4" />
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
        {{ $this->materials->links() }}
    </div>

    <!-- 1. CREATE / EDIT STUDY MATERIAL MODAL -->
    @if($showFormModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-background/80 backdrop-blur-sm">
            <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden animate-in fade-in-50 zoom-in-95">
                <!-- Modal Header -->
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/30">
                    <div>
                        <h2 class="text-lg font-bold text-foreground">
                            {{ $editingId ? __('Edit Study Material') : __('Upload & Publish Study Material') }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ __('Associate materials with courses & batches, attach multi-format files, and notify enrolled students.') }}
                        </p>
                    </div>
                    <button wire:click="$set('showFormModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg hover:bg-muted">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Modal Sub-Tabs -->
                <div class="border-b border-border px-6 bg-muted/10">
                    <div class="flex space-x-6 text-xs font-medium">
                        <button type="button" wire:click="$set('formTab', 'general')"
                                class="py-2.5 border-b-2 transition-colors {{ $formTab === 'general' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
                            {{ __('1. General & Course Association') }}
                        </button>
                        <button type="button" wire:click="$set('formTab', 'files')"
                                class="py-2.5 border-b-2 transition-colors {{ $formTab === 'files' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
                            {{ __('2. Files & External Media') }}
                            @if(count($newFiles) + count($existingFiles) > 0)
                                <span class="ml-1 px-1.5 py-0.2 rounded-full bg-primary/20 text-primary text-[10px]">{{ count($newFiles) + count($existingFiles) }}</span>
                            @endif
                        </button>
                        <button type="button" wire:click="$set('formTab', 'content')"
                                class="py-2.5 border-b-2 transition-colors {{ $formTab === 'content' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
                            {{ __('3. Rich Notes & Content') }}
                        </button>
                        <button type="button" wire:click="$set('formTab', 'notifications')"
                                class="py-2.5 border-b-2 transition-colors {{ $formTab === 'notifications' ? 'border-primary text-primary font-bold' : 'border-transparent text-muted-foreground hover:text-foreground' }}">
                            {{ __('4. Instant Notification & Sharing') }}
                        </button>
                    </div>
                </div>

                <!-- Modal Scrollable Body -->
                <div class="p-6 overflow-y-auto space-y-4 flex-1 text-xs">
                    <!-- TAB 1: GENERAL -->
                    @if($formTab === 'general')
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Course -->
                                <div>
                                    <label class="block font-semibold text-foreground mb-1">
                                        {{ __('Course') }} <span class="text-muted-foreground font-normal">({{ __('Optional for Public Materials') }})</span>
                                    </label>
                                    <select wire:model.live="form.course_id" class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none">
                                        <option value="">{{ __('-- General / All Courses (Public Publication) --') }}</option>
                                        @foreach($this->courses as $c)
                                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                                        @endforeach
                                    </select>
                                    @error('form.course_id') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                                </div>

                                <!-- Material Type -->
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block font-semibold text-foreground">{{ __('Material Type') }} <span class="text-destructive">*</span></label>
                                        <button type="button" wire:click="openQuickCreateTypeModal" class="text-primary hover:underline text-[11px] font-medium flex items-center gap-1">
                                            <x-icon name="plus" class="w-3 h-3" />
                                            <span>{{ __('+ Custom Type') }}</span>
                                        </button>
                                    </div>
                                    <select wire:model="form.material_type" class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none">
                                        @foreach(StudyMaterial::materialTypes() as $k => $label)
                                            <option value="{{ $k }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <!-- Batches Association -->
                            <div class="p-4 rounded-xl border border-border bg-muted/20 space-y-2">
                                <div class="flex items-center justify-between">
                                    <label class="font-semibold text-foreground flex items-center gap-1.5">
                                        <x-icon name="layers" class="w-4 h-4 text-primary" />
                                        <span>{{ __('Target Batches') }}</span>
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <button type="button" wire:click="selectAllBatches" class="text-primary hover:underline text-[11px] font-medium">
                                            {{ __('Select All Batches') }}
                                        </button>
                                        <span>&bull;</span>
                                        <button type="button" wire:click="clearBatches" class="text-muted-foreground hover:underline text-[11px]">
                                            {{ __('Clear') }}
                                        </button>
                                    </div>
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ __('Leave unchecked to automatically apply to ALL active and upcoming batches of this course.') }}
                                </p>

                                @if($this->availableBatches->isNotEmpty())
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 pt-2">
                                        @foreach($this->availableBatches as $batch)
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-border bg-card hover:bg-muted/50 cursor-pointer transition-colors text-xs">
                                                <input type="checkbox" value="{{ $batch->id }}" wire:model="form.batch_ids" class="rounded border-border text-primary focus:ring-primary" />
                                                <div class="truncate">
                                                    <span class="font-semibold text-foreground block truncate">{{ $batch->name }}</span>
                                                    <span class="text-[10px] text-muted-foreground">{{ $batch->academic_year ?: $batch->code }}</span>
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="text-[11px] text-muted-foreground italic py-1">
                                        {{ __('Please select a course first to view its associated batches.') }}
                                    </div>
                                @endif
                            </div>

                            <!-- Title -->
                            <div>
                                <label class="block font-semibold text-foreground mb-1">{{ __('Material Title') }} <span class="text-destructive">*</span></label>
                                <input type="text" wire:model="form.title" placeholder="{{ __('e.g., Indian Polity & Governance - Fundamental Rights Comprehensive Notes') }}"
                                       class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                @error('form.title') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                            </div>

                            <!-- Subject, Topic, Module Code -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block font-medium text-foreground mb-1">{{ __('Subject') }}</label>
                                    <input type="text" wire:model="form.subject" placeholder="{{ __('e.g., Indian Polity') }}"
                                           class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                </div>
                                <div>
                                    <label class="block font-medium text-foreground mb-1">{{ __('Topic / Chapter') }}</label>
                                    <input type="text" wire:model="form.topic" placeholder="{{ __('e.g., Articles 12-35 Fundamental Rights') }}"
                                           class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                </div>
                                <div>
                                    <label class="block font-medium text-foreground mb-1">{{ __('Module Code') }}</label>
                                    <input type="text" wire:model="form.module_code" placeholder="{{ __('e.g., GS-POL-02') }}"
                                           class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                </div>
                            </div>

                            <!-- Description -->
                            <div>
                                <label class="block font-medium text-foreground mb-1">{{ __('Brief Summary / Instructions') }}</label>
                                <textarea wire:model="form.description" rows="3" placeholder="{{ __('Provide brief guidelines for students reading this material...') }}"
                                          class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none"></textarea>
                            </div>

                            <!-- Class Date & Display Order -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 p-4 rounded-xl border border-border bg-muted/20">
                                <div>
                                    <label class="block font-semibold text-foreground mb-1 flex items-center gap-1.5">
                                        <x-icon name="calendar" class="w-3.5 h-3.5 text-primary" />
                                        <span>{{ __('Class / Session Date') }}</span>
                                        <span class="text-muted-foreground font-normal">({{ __('Optional') }})</span>
                                    </label>
                                    <input type="date" wire:model="form.class_date"
                                           class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                    <span class="text-[10px] text-muted-foreground mt-0.5 block">
                                        {{ __('Associates this material with a specific lecture day so students can easily find notes for that day\'s class.') }}
                                    </span>
                                    @error('form.class_date') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block font-semibold text-foreground mb-1 flex items-center gap-1.5">
                                        <x-icon name="arrow-up-down" class="w-3.5 h-3.5 text-primary" />
                                        <span>{{ __('Display Order / Sequence') }}</span>
                                    </label>
                                    <input type="number" min="0" wire:model="form.display_order" placeholder="0"
                                           class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                    <span class="text-[10px] text-muted-foreground mt-0.5 block">
                                        {{ __('Sequence priority (lower numbers appear first). Can also be adjusted directly from the materials table.') }}
                                    </span>
                                    @error('form.display_order') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <!-- Status, Pinned & Access Level -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 border-t border-border/60">
                                <div>
                                    <label class="block font-medium text-foreground mb-1">{{ __('Publication Status') }}</label>
                                    <select wire:model="form.status" class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs">
                                        <option value="published">{{ __('Published (Visible to Students)') }}</option>
                                        <option value="draft">{{ __('Draft (Hidden from Students)') }}</option>
                                        <option value="archived">{{ __('Archived') }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block font-medium text-foreground mb-1">{{ __('Access Level') }}</label>
                                    <select wire:model="form.access_level" class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs">
                                        <option value="enrolled_only">{{ __('Enrolled Course/Batch Students Only') }}</option>
                                        <option value="public_link">{{ __('Anyone with Public Link') }}</option>
                                        <option value="restricted_students">{{ __('Restricted Selected Students') }}</option>
                                    </select>
                                </div>

                                <div class="flex items-center gap-2 pt-6">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" wire:model="form.is_pinned" class="rounded border-border text-primary focus:ring-primary" />
                                        <span class="font-semibold text-foreground">{{ __('Pin to Top of List') }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endif

                    <!-- TAB 2: FILES & EXTERNAL MEDIA -->
                    @if($formTab === 'files')
                        <div class="space-y-4">
                            <!-- Multi-file Upload Box -->
                            <div class="border-2 border-dashed border-border hover:border-primary rounded-2xl p-6 text-center transition-colors bg-muted/10">
                                <x-icon name="upload-cloud" class="w-10 h-10 text-primary mx-auto mb-2" />
                                <h4 class="font-semibold text-foreground text-sm">{{ __('Drag & drop or select study files to upload') }}</h4>
                                <p class="text-muted-foreground text-xs mt-1">
                                    {{ __('Supports PDF, DOCX, PPTX, XLSX, ZIP, Images, EPUB, Video/Audio files up to 100MB each.') }}
                                </p>
                                <label class="mt-4 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary text-primary-foreground font-semibold text-xs cursor-pointer hover:bg-primary/90 shadow transition-all">
                                    <x-icon name="paperclip" class="w-4 h-4" />
                                    <span>{{ __('Browse Files') }}</span>
                                    <input type="file" wire:model="newFiles" multiple class="hidden" />
                                </label>
                                <div wire:loading wire:target="newFiles" class="text-xs text-primary font-medium mt-2">
                                    {{ __('Uploading and verifying files...') }}
                                </div>
                            </div>

                            <!-- List of Newly Selected Files -->
                            @if(!empty($newFiles))
                                <div class="space-y-2">
                                    <h5 class="font-semibold text-foreground text-xs">{{ __('New Files Pending Upload:') }}</h5>
                                    <div class="space-y-1.5">
                                        @foreach($newFiles as $index => $file)
                                            <div class="flex items-center justify-between p-2.5 rounded-lg bg-primary/5 border border-primary/20 text-xs">
                                                <div class="flex items-center gap-2 truncate">
                                                    <x-icon name="file" class="w-4 h-4 text-primary shrink-0" />
                                                    <span class="font-medium text-foreground truncate">{{ $file->getClientOriginalName() }}</span>
                                                    <span class="text-[10px] text-muted-foreground font-mono">({{ round($file->getSize() / 1024, 1) }} KB)</span>
                                                </div>
                                                <button type="button" wire:click="$set('newFiles.{{ $index }}', null)" class="text-destructive hover:text-destructive/80 p-1">
                                                    <x-icon name="x" class="w-3.5 h-3.5" />
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- List of Existing Files (for Edit) -->
                            @if(!empty($existingFiles))
                                <div class="space-y-2 pt-2 border-t border-border">
                                    <h5 class="font-semibold text-foreground text-xs">{{ __('Existing Attached Files:') }}</h5>
                                    <div class="space-y-1.5">
                                        @foreach($existingFiles as $ef)
                                            @php $isMarkedForDelete = in_array($ef['id'], $deleteFileIds, true); @endphp
                                            <div class="flex items-center justify-between p-2.5 rounded-lg border transition-colors {{ $isMarkedForDelete ? 'bg-destructive/10 border-destructive/30 line-through opacity-60' : 'bg-muted/30 border-border' }}">
                                                <div class="flex items-center gap-2 truncate">
                                                    <x-icon name="{{ $ef['icon'] }}" class="w-4 h-4 text-primary shrink-0" />
                                                    <span class="font-medium text-foreground truncate">{{ $ef['name'] }}</span>
                                                    <span class="text-[10px] text-muted-foreground font-mono">({{ $ef['size'] }})</span>
                                                </div>
                                                <button type="button" wire:click="toggleDeleteFile({{ $ef['id'] }})"
                                                        class="px-2 py-1 rounded text-[11px] font-semibold transition-colors {{ $isMarkedForDelete ? 'bg-muted text-foreground' : 'text-destructive hover:bg-destructive/10' }}">
                                                    {{ $isMarkedForDelete ? __('Undo Remove') : __('Remove') }}
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- External Link / Video Recording URL -->
                            <div class="pt-2 border-t border-border">
                                <label class="block font-semibold text-foreground mb-1 flex items-center gap-1.5">
                                    <x-icon name="external-link" class="w-4 h-4 text-primary" />
                                    <span>{{ __('External Resource / Class Recording URL') }}</span>
                                </label>
                                <input type="url" wire:model="form.external_url" placeholder="{{ __('https://youtu.be/... or Google Drive / Zoom recording link') }}"
                                       class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                <span class="text-[11px] text-muted-foreground mt-0.5 block">
                                    {{ __('Students can directly launch high-bandwidth video lectures or cloud folders without exhausting server storage.') }}
                                </span>
                            </div>

                            <!-- Optional Thumbnail Image (Upload or Custom URL) -->
                            <div class="pt-4 border-t border-border space-y-3">
                                <div>
                                    <h4 class="font-semibold text-foreground text-xs flex items-center gap-1.5">
                                        <x-icon name="image" class="w-4 h-4 text-primary" />
                                        <span>{{ __('Thumbnail Cover Image') }}</span>
                                        <span class="text-muted-foreground font-normal">({{ __('Optional') }})</span>
                                    </h4>
                                    <p class="text-[11px] text-muted-foreground mt-0.5">
                                        {{ __('Attach a visual cover/thumbnail for this study material. It will be showcased on student cards and external website/app integration.') }}
                                    </p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-muted/20 p-4 rounded-xl border border-border">
                                    <!-- Option A: File Upload -->
                                    <div class="space-y-2">
                                        <label class="block font-medium text-foreground text-[11px]">{{ __('Option A: Upload Image File (PNG, JPG, WEBP max 5MB)') }}</label>
                                        <label class="flex flex-col items-center justify-center border-2 border-dashed border-border hover:border-primary rounded-xl p-4 cursor-pointer bg-card transition-colors">
                                            <x-icon name="upload" class="w-6 h-6 text-primary mb-1" />
                                            <span class="text-[11px] font-semibold text-foreground">{{ __('Select Thumbnail File') }}</span>
                                            <span class="text-[10px] text-muted-foreground mt-0.5">{{ __('Recommended aspect ratio 16:9 or 4:3') }}</span>
                                            <input type="file" wire:model="thumbnailFile" accept="image/*" class="hidden" />
                                        </label>
                                        <div wire:loading wire:target="thumbnailFile" class="text-[11px] text-primary font-medium">
                                            {{ __('Uploading thumbnail...') }}
                                        </div>
                                        @error('thumbnailFile') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                                    </div>

                                    <!-- Option B: Custom URL -->
                                    <div class="space-y-2">
                                        <label class="block font-medium text-foreground text-[11px]">{{ __('Option B: Custom Image URL (CDN / Hosted Image)') }}</label>
                                        <input type="url" wire:model.live="form.thumbnail_url" placeholder="https://example.com/images/polity-thumbnail.jpg"
                                               class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                        <span class="text-[10px] text-muted-foreground block">
                                            {{ __('You can directly paste an external image link if hosted on Google Drive, Cloudinary, AWS S3, or Unsplash.') }}
                                        </span>
                                        @error('form.thumbnail_url') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <!-- Thumbnail Preview & Remove Button -->
                                @php
                                    $hasThumbnailPreview = false;
                                    $previewSrc = null;
                                    if ($thumbnailFile) {
                                        try {
                                            $previewSrc = $thumbnailFile->temporaryUrl();
                                            $hasThumbnailPreview = true;
                                        } catch (\Throwable $e) {}
                                    } elseif ($existingThumbnailUrl && !$removeThumbnail) {
                                        $previewSrc = $existingThumbnailUrl;
                                        $hasThumbnailPreview = true;
                                    } elseif (!empty($form['thumbnail_url'])) {
                                        $previewSrc = $form['thumbnail_url'];
                                        $hasThumbnailPreview = true;
                                    }
                                @endphp

                                @if($hasThumbnailPreview)
                                    <div class="flex items-center gap-4 p-3 rounded-xl border border-primary/20 bg-primary/5">
                                        <img src="{{ $previewSrc }}" alt="Thumbnail preview" class="w-20 h-14 rounded-lg object-cover border border-border shrink-0 shadow-sm" />
                                        <div class="truncate flex-1 text-xs">
                                            <p class="font-semibold text-foreground">{{ __('Active Thumbnail Preview') }}</p>
                                            <p class="text-[10px] text-muted-foreground truncate">{{ $thumbnailFile ? $thumbnailFile->getClientOriginalName() : ($existingThumbnailUrl ?: $form['thumbnail_url']) }}</p>
                                        </div>
                                        <button type="button" wire:click="removeThumbnailImage" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-destructive hover:bg-destructive/10 text-[11px] font-semibold transition-colors">
                                            <x-icon name="trash" class="w-3.5 h-3.5" />
                                            <span>{{ __('Remove Thumbnail') }}</span>
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- TAB 3: RICH NOTES / CONTENT -->
                    @if($formTab === 'content')
                        <div class="space-y-3">
                            <label class="block font-semibold text-foreground">
                                {{ __('Lecture Notes & Structured Content') }}
                            </label>
                            <p class="text-[11px] text-muted-foreground">
                                {{ __('Optional embedded text or markdown notes. Students can read this directly in the portal without downloading files.') }}
                            </p>
                            <textarea wire:model="form.content" rows="12" placeholder="{{ __('Write lecture notes, key bullet points, exam tips, syllabus references...') }}"
                                      class="w-full p-4 rounded-xl border border-border bg-background text-foreground font-mono text-xs focus:ring-2 focus:ring-primary focus:outline-none leading-relaxed"></textarea>
                        </div>
                    @endif

                    <!-- TAB 4: INSTANT NOTIFICATION & SHARING -->
                    @if($formTab === 'notifications')
                        <div class="space-y-4">
                            <div class="p-4 rounded-xl border border-border bg-muted/20 space-y-3">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" wire:model.live="notifyOnSave" class="rounded border-border text-primary focus:ring-primary" />
                                    <div>
                                        <span class="font-bold text-foreground text-sm">{{ __('Notify Enrolled Students Automatically on Save') }}</span>
                                        <p class="text-muted-foreground text-[11px]">
                                            {{ __('Replaces noisy WhatsApp groups by broadcasting structured alerts with direct resource download links.') }}
                                        </p>
                                    </div>
                                </label>
                            </div>

                            @if($notifyOnSave)
                                <div class="space-y-4 p-4 rounded-xl border border-primary/20 bg-primary/[0.02]">
                                    <!-- Channels Selection -->
                                    <div>
                                        <label class="font-semibold text-foreground block mb-2">{{ __('Notification Channels:') }}</label>
                                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                                <input type="checkbox" value="database" wire:model="notifyChannels" class="rounded border-border text-primary" />
                                                <x-icon name="bell" class="w-4 h-4 text-primary" />
                                                <span class="font-medium text-foreground">{{ __('In-App Alert') }}</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                                <input type="checkbox" value="email" wire:model="notifyChannels" class="rounded border-border text-blue-600" />
                                                <x-icon name="mail" class="w-4 h-4 text-blue-600" />
                                                <span class="font-medium text-foreground">{{ __('Email') }}</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                                <input type="checkbox" value="whatsapp" wire:model="notifyChannels" class="rounded border-border text-emerald-600" />
                                                <x-icon name="message-circle" class="w-4 h-4 text-emerald-600" />
                                                <span class="font-medium text-foreground">{{ __('WhatsApp') }}</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                                <input type="checkbox" value="sms" wire:model="notifyChannels" class="rounded border-border text-amber-600" />
                                                <x-icon name="smartphone" class="w-4 h-4 text-amber-600" />
                                                <span class="font-medium text-foreground">{{ __('SMS') }}</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Recipient Audience -->
                                    <div>
                                        <label class="font-semibold text-foreground block mb-2">{{ __('Target Audience:') }}</label>
                                        <div class="flex items-center gap-4">
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio" value="batch" wire:model.live="notifyAudience" class="text-primary focus:ring-primary" />
                                                <span>{{ __('All Enrolled Students in Selected Batches') }}</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio" value="specific" wire:model.live="notifyAudience" class="text-primary focus:ring-primary" />
                                                <span>{{ __('Select Specific Students') }}</span>
                                            </label>
                                        </div>

                                        @if($notifyAudience === 'specific')
                                            <div class="mt-3 p-3 rounded-lg border border-border bg-card max-h-40 overflow-y-auto space-y-1">
                                                @foreach($this->eligibleStudents as $student)
                                                    <label class="flex items-center gap-2 text-xs py-1 hover:bg-muted/50 px-2 rounded cursor-pointer">
                                                        <input type="checkbox" value="{{ $student->id }}" wire:model="notifySpecificStudents" class="rounded border-border text-primary" />
                                                        <span class="font-medium text-foreground">{{ $student->name }}</span>
                                                        <span class="text-muted-foreground text-[10px]">({{ $student->email }})</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Custom Message Note -->
                                    <div>
                                        <label class="font-semibold text-foreground block mb-1">{{ __('Custom Faculty Note (Optional):') }}</label>
                                        <input type="text" wire:model="notifyCustomMessage" placeholder="{{ __('e.g., Please review Chapter 3 before tomorrow\'s 10 AM class.') }}"
                                               class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Modal Footer -->
                <div class="px-6 py-3 border-t border-border flex items-center justify-between bg-muted/20">
                    <button type="button" wire:click="$set('showFormModal', false)"
                            class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs">
                        {{ __('Cancel') }}
                    </button>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="saveMaterial"
                                class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg bg-primary text-primary-foreground font-semibold text-xs hover:bg-primary/90 shadow">
                            <x-icon name="check" class="w-4 h-4" />
                            <span>{{ $editingId ? __('Update Material') : __('Save & Publish Material') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- 2. DEDICATED SHARE STUDY MATERIAL MODAL -->
    @if($showShareModal && $sharingMaterial)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-background/80 backdrop-blur-sm">
            <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-2xl w-full max-w-2xl overflow-hidden animate-in fade-in-50 zoom-in-95">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/30">
                    <div>
                        <h2 class="text-base font-bold text-foreground flex items-center gap-2">
                            <x-icon name="share-2" class="w-5 h-5 text-primary" />
                            <span>{{ __('Share Study Material') }}</span>
                        </h2>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ $sharingMaterial->title }}
                        </p>
                    </div>
                    <button wire:click="$set('showShareModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg hover:bg-muted">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="p-6 space-y-4 text-xs">
                    <!-- Public Share Link Box -->
                    <div class="p-3.5 rounded-xl border border-border bg-muted/30 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-foreground flex items-center gap-1.5">
                                <x-icon name="link" class="w-4 h-4 text-primary" />
                                <span>{{ __('Public Direct Link') }}</span>
                            </span>
                            <span class="text-[10px] text-muted-foreground">
                                {{ __('Accessible on web and mobile') }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" readonly value="{{ $sharingMaterial->public_share_url }}"
                                   class="w-full px-3 py-1.5 rounded-lg border border-border bg-background text-foreground text-xs font-mono select-all" />
                            <button type="button" @click="navigator.clipboard.writeText('{{ $sharingMaterial->public_share_url }}'); alert('Link copied to clipboard!');"
                                    class="px-3 py-1.5 rounded-lg bg-card border border-border hover:bg-muted font-semibold text-foreground text-xs shrink-0 flex items-center gap-1">
                                <x-icon name="copy" class="w-3.5 h-3.5" />
                                <span>{{ __('Copy') }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Channel Selection Checkboxes -->
                    <div>
                        <label class="font-semibold text-foreground block mb-2">{{ __('Select Broadcast Channels:') }}</label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                <input type="checkbox" value="whatsapp" wire:model="shareChannels" class="rounded border-border text-emerald-600" />
                                <x-icon name="message-circle" class="w-4 h-4 text-emerald-600" />
                                <span class="font-medium text-foreground">{{ __('WhatsApp') }}</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                <input type="checkbox" value="email" wire:model="shareChannels" class="rounded border-border text-blue-600" />
                                <x-icon name="mail" class="w-4 h-4 text-blue-600" />
                                <span class="font-medium text-foreground">{{ __('Email') }}</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                <input type="checkbox" value="sms" wire:model="shareChannels" class="rounded border-border text-amber-600" />
                                <x-icon name="smartphone" class="w-4 h-4 text-amber-600" />
                                <span class="font-medium text-foreground">{{ __('SMS') }}</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-border bg-card cursor-pointer">
                                <input type="checkbox" value="telegram" wire:model="shareChannels" class="rounded border-border text-sky-500" />
                                <x-icon name="send" class="w-4 h-4 text-sky-500" />
                                <span class="font-medium text-foreground">{{ __('Telegram') }}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Target Recipient Selection -->
                    <div>
                        <label class="font-semibold text-foreground block mb-2">{{ __('Target Recipients:') }}</label>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" value="batch" wire:model.live="shareTarget" class="text-primary focus:ring-primary" />
                                <span>{{ __('All Enrolled Students (:count)', ['count' => $this->shareEligibleStudents->count()]) }}</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" value="specific" wire:model.live="shareTarget" class="text-primary focus:ring-primary" />
                                <span>{{ __('Select Specific Students') }}</span>
                            </label>
                        </div>

                        @if($shareTarget === 'specific')
                            <div class="mt-3 p-3 rounded-lg border border-border bg-muted/20 max-h-40 overflow-y-auto space-y-1">
                                @foreach($this->shareEligibleStudents as $student)
                                    <label class="flex items-center gap-2 text-xs py-1 hover:bg-muted/50 px-2 rounded cursor-pointer">
                                        <input type="checkbox" value="{{ $student->id }}" wire:model="shareSelectedStudents" class="rounded border-border text-primary" />
                                        <span class="font-medium text-foreground">{{ $student->name }}</span>
                                        <span class="text-muted-foreground text-[10px]">({{ $student->phone ?: $student->email }})</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Custom Message Note -->
                    <div>
                        <label class="font-semibold text-foreground block mb-1">{{ __('Personal Note / Instructions (Optional):') }}</label>
                        <textarea wire:model="shareCustomMessage" rows="2" placeholder="{{ __('Add a personal note to accompany the material...') }}"
                                  class="w-full px-3 py-2 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none"></textarea>
                    </div>

                    <!-- Execution Results Box -->
                    @if($shareResult)
                        <div class="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 text-xs">
                            <span class="font-bold">{{ __('Dispatch Summary:') }}</span>
                            <div class="flex items-center gap-3 mt-1">
                                <span>Total: {{ $shareResult['total_recipients'] }}</span>
                                <span>&bull;</span>
                                <span class="text-emerald-600 font-semibold">Success: {{ $shareResult['successful'] }}</span>
                                <span>&bull;</span>
                                <span class="text-destructive font-semibold">Failed: {{ $shareResult['failed'] }}</span>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Footer -->
                <div class="px-6 py-3 border-t border-border flex items-center justify-between bg-muted/20">
                    <button type="button" wire:click="$set('showShareModal', false)"
                            class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs">
                        {{ __('Close') }}
                    </button>
                    <button type="button" wire:click="dispatchShare" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg bg-primary text-primary-foreground font-semibold text-xs hover:bg-primary/90 shadow disabled:opacity-50">
                        <x-icon name="send" class="w-4 h-4" />
                        <span wire:loading.remove wire:target="dispatchShare">{{ __('Dispatch Material Now') }}</span>
                        <span wire:loading wire:target="dispatchShare">{{ __('Sending...') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- 3. DETAIL DOSSIER MODAL -->
    @if($showDetailModal && $viewingMaterial)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-background/80 backdrop-blur-sm">
            <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col overflow-hidden animate-in fade-in-50 zoom-in-95">
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/30">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-primary/10 text-primary">
                            {{ $viewingMaterial->material_type_label }}
                        </span>
                        <h2 class="text-base font-bold text-foreground truncate max-w-lg">
                            {{ $viewingMaterial->title }}
                        </h2>
                    </div>
                    <button wire:click="$set('showDetailModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg hover:bg-muted">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 overflow-y-auto space-y-5 text-xs flex-1">
                    <!-- Thumbnail Banner if Available -->
                    @if($viewingMaterial->thumbnail_image_url)
                        <div class="h-44 w-full rounded-xl overflow-hidden border border-border relative bg-muted/40">
                            <img src="{{ $viewingMaterial->thumbnail_image_url }}" alt="{{ $viewingMaterial->title }}" class="w-full h-full object-cover" />
                        </div>
                    @endif

                    <!-- Key Metadata -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 p-4 rounded-xl bg-muted/30 border border-border">
                        <div>
                            <span class="block text-muted-foreground text-[10px]">{{ __('Course') }}</span>
                            <span class="font-semibold text-foreground truncate block">{{ $viewingMaterial->course?->name ?: __('General / Public') }}</span>
                        </div>
                        <div>
                            <span class="block text-muted-foreground text-[10px]">{{ __('Batches') }}</span>
                            <span class="font-semibold text-foreground truncate block">{{ $viewingMaterial->batches_summary }}</span>
                        </div>
                        <div>
                            <span class="block text-muted-foreground text-[10px]">{{ __('Class Date') }}</span>
                            <span class="font-semibold text-foreground flex items-center gap-1">
                                <x-icon name="calendar" class="w-3 h-3 text-primary shrink-0" />
                                <span>{{ $viewingMaterial->class_date?->format('d M Y') ?? __('Not set') }}</span>
                            </span>
                        </div>
                        <div>
                            <span class="block text-muted-foreground text-[10px]">{{ __('Sequence Order') }}</span>
                            <span class="font-semibold text-foreground font-mono">#{{ $viewingMaterial->display_order }}</span>
                        </div>
                        <div>
                            <span class="block text-muted-foreground text-[10px]">{{ __('Subject & Topic') }}</span>
                            <span class="font-semibold text-foreground truncate block">{{ $viewingMaterial->subject ?: 'N/A' }} / {{ $viewingMaterial->topic ?: 'N/A' }}</span>
                        </div>
                        <div>
                            <span class="block text-muted-foreground text-[10px]">{{ __('Downloads / Views') }}</span>
                            <span class="font-semibold text-foreground">{{ $viewingMaterial->download_count }} / {{ $viewingMaterial->view_count }}</span>
                        </div>
                    </div>

                    <!-- Description -->
                    @if($viewingMaterial->description)
                        <div>
                            <h4 class="font-semibold text-foreground mb-1">{{ __('Overview / Description:') }}</h4>
                            <p class="text-muted-foreground leading-relaxed whitespace-pre-line bg-muted/20 p-3 rounded-lg border border-border">
                                {{ $viewingMaterial->description }}
                            </p>
                        </div>
                    @endif

                    <!-- Files -->
                    <div>
                        <h4 class="font-semibold text-foreground mb-2 flex items-center justify-between">
                            <span>{{ __('Attached Files (:count):', ['count' => $viewingMaterial->files->count()]) }}</span>
                            <span class="text-muted-foreground font-normal">{{ $viewingMaterial->formatted_total_size }}</span>
                        </h4>
                        <div class="space-y-2">
                            @forelse($viewingMaterial->files as $file)
                                <div class="flex items-center justify-between p-3 rounded-xl border border-border bg-card">
                                    <div class="flex items-center gap-2.5 truncate">
                                        <x-icon name="{{ $file->file_icon }}" class="w-5 h-5 text-primary shrink-0" />
                                        <div class="truncate">
                                            <p class="font-semibold text-foreground truncate">{{ $file->file_name }}</p>
                                            <p class="text-[10px] text-muted-foreground">{{ $file->formatted_size }} &bull; {{ $file->download_count }} downloads</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('study-materials.preview', ['studyMaterial' => $viewingMaterial->id, 'fileIndex' => $file->id]) }}" target="_blank"
                                           class="px-3 py-1.5 rounded-lg border border-border bg-card hover:bg-muted font-medium text-xs">
                                            {{ __('Preview') }}
                                        </a>
                                        <a href="{{ $file->download_url }}"
                                           class="px-3 py-1.5 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 font-medium text-xs flex items-center gap-1">
                                            <x-icon name="download" class="w-3.5 h-3.5" />
                                            <span>{{ __('Download') }}</span>
                                        </a>
                                    </div>
                                </div>
                            @empty
                                <div class="text-muted-foreground italic">{{ __('No attached files.') }}</div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Share Logs History -->
                    <div>
                        <h4 class="font-semibold text-foreground mb-2">{{ __('Share & Broadcast Audit Logs:') }}</h4>
                        <div class="max-h-40 overflow-y-auto rounded-lg border border-border divide-y divide-border/60">
                            @forelse($viewingMaterial->shares->take(15) as $share)
                                <div class="p-2.5 flex items-center justify-between text-[11px] bg-card">
                                    <div class="flex items-center gap-2">
                                        <span class="uppercase font-mono text-[9px] px-1.5 py-0.5 rounded font-bold
                                              {{ $share->channel === 'whatsapp' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300' }}">
                                            {{ $share->channel }}
                                        </span>
                                        <span class="text-foreground font-medium">{{ $share->recipient_name }}</span>
                                        <span class="text-muted-foreground">({{ $share->recipient_contact }})</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="capitalize text-muted-foreground">{{ $share->status }}</span>
                                        <span class="text-muted-foreground/70">{{ $share->created_at?->diffForHumans() }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="p-3 text-muted-foreground italic text-center">{{ __('No shares dispatched yet.') }}</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="px-6 py-3 border-t border-border flex items-center justify-end bg-muted/20">
                    <button type="button" wire:click="$set('showDetailModal', false)"
                            class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- 4. IMPORT MODAL -->
    @if($showImportModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-background/80 backdrop-blur-sm">
            <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-2xl w-full max-w-lg overflow-hidden animate-in fade-in-50 zoom-in-95">
                <div class="px-6 py-4 border-b border-border flex items-center justify-between bg-muted/30">
                    <h2 class="text-base font-bold text-foreground flex items-center gap-2">
                        <x-icon name="upload" class="w-5 h-5 text-primary" />
                        <span>{{ __('Bulk Import Study Materials') }}</span>
                    </h2>
                    <button wire:click="$set('showImportModal', false)" class="text-muted-foreground hover:text-foreground p-1 rounded-lg hover:bg-muted">
                        <x-icon name="x" class="w-5 h-5" />
                    </button>
                </div>

                <div class="p-6 space-y-4 text-xs">
                    <p class="text-muted-foreground">
                        {{ __('Import study materials in bulk from CSV or Excel spreadsheet. Course codes and batch names will be mapped automatically.') }}
                    </p>

                    <div class="p-3.5 rounded-xl border border-border bg-muted/30 flex items-center justify-between">
                        <div>
                            <span class="font-semibold text-foreground block">{{ __('Sample Template') }}</span>
                            <span class="text-[11px] text-muted-foreground">{{ __('Download ready-to-fill CSV template') }}</span>
                        </div>
                        <button type="button" wire:click="downloadSampleTemplate"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-border bg-card hover:bg-muted font-medium text-xs shadow-sm">
                            <x-icon name="download" class="w-3.5 h-3.5" />
                            <span>{{ __('Download CSV') }}</span>
                        </button>
                    </div>

                    <div>
                        <label class="block font-semibold text-foreground mb-1">{{ __('Select Spreadsheet File:') }}</label>
                        <input type="file" wire:model="importFile" accept=".csv,.xlsx,.xls,.txt"
                               class="w-full text-xs text-muted-foreground file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer" />
                        @error('importFile') <span class="text-destructive text-[11px] mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    @if($importSummary)
                        <div class="p-3 rounded-xl border {{ $importSummary['imported'] > 0 ? 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 border-amber-200' }}">
                            <p class="font-bold">{{ __('Import Results:') }}</p>
                            <p class="mt-0.5">{{ __('Imported: :imported | Skipped: :skipped', ['imported' => $importSummary['imported'], 'skipped' => $importSummary['skipped']]) }}</p>
                            @if(!empty($importSummary['errors']))
                                <ul class="list-disc list-inside mt-2 text-[10px] space-y-0.5">
                                    @foreach(array_slice($importSummary['errors'], 0, 5) as $err)
                                        <li>{{ $err }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="px-6 py-3 border-t border-border flex items-center justify-between bg-muted/20">
                    <button type="button" wire:click="$set('showImportModal', false)"
                            class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs">
                        {{ __('Close') }}
                    </button>
                    <button type="button" wire:click="runImport" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg bg-primary text-primary-foreground font-semibold text-xs hover:bg-primary/90 shadow">
                        <x-icon name="upload" class="w-4 h-4" />
                        <span wire:loading.remove wire:target="runImport">{{ __('Start Ingestion') }}</span>
                        <span wire:loading wire:target="runImport">{{ __('Importing...') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- 5. DELETE CONFIRMATION MODAL -->
    @if($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-background/80 backdrop-blur-sm">
            <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-2xl w-full max-w-md p-6 text-center animate-in fade-in-50 zoom-in-95">
                <div class="w-12 h-12 rounded-full bg-destructive/10 text-destructive mx-auto flex items-center justify-center mb-4">
                    <x-icon name="alert-triangle" class="w-6 h-6" />
                </div>
                <h3 class="text-base font-bold text-foreground">
                    {{ $isForceDelete ? __('Permanently Purge Study Material?') : __('Move Study Material to Trash?') }}
                </h3>
                <p class="text-xs text-muted-foreground mt-2">
                    {{ $isForceDelete ? __('This action will permanently delete the study material, access logs, and all attached files from the server disk. This cannot be undone.') : __('Students will no longer be able to access this study material. You can restore it later from the Trash Archive.') }}
                </p>

                <div class="flex items-center justify-center gap-3 mt-6">
                    <button type="button" wire:click="$set('showDeleteModal', false)"
                            class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" wire:click="executeDelete"
                            class="px-5 py-2 rounded-lg bg-destructive text-destructive-foreground hover:bg-destructive/90 font-semibold text-xs shadow">
                        {{ $isForceDelete ? __('Yes, Purge Permanently') : __('Yes, Move to Trash') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- 6. MATERIAL TYPES MANAGEMENT MODAL -->
    @if($showTypesModal)
        <div wire:key="study-material-types-management-modal" class="fixed inset-0 z-50 overflow-y-auto">
            <!-- Dedicated Backdrop -->
            <div class="fixed inset-0 bg-background/80 backdrop-blur-sm transition-opacity"
                 wire:click="$set('showTypesModal', false)"
                 aria-hidden="true"></div>

            <!-- Centering container -->
            <div class="flex min-h-full items-center justify-center p-3 sm:p-6 text-center">
                <!-- Modal Card Container -->
                <div class="relative bg-card text-card-foreground rounded-xl sm:rounded-2xl border border-border shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col overflow-hidden text-left align-middle animate-in fade-in-50 zoom-in-95"
                     @click.stop>
                    <!-- Header -->
                    <div class="px-4 py-3.5 sm:px-6 sm:py-4 border-b border-border flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-muted/30 shrink-0">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 sm:w-9 sm:h-9 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                <x-icon name="tag" class="w-4 h-4 sm:w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm sm:text-base font-bold text-foreground truncate">{{ __('Study Material Types & Categories') }}</h2>
                                <p class="text-[11px] sm:text-xs text-muted-foreground line-clamp-1 sm:line-clamp-none">{{ __('Manage custom material types, external API visibility, and publications like PIB Sandhan & Current Affairs.') }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 self-end sm:self-auto shrink-0">
                            @can('study_materials.create')
                                <button type="button" wire:click="openCreateTypeModal"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground text-xs font-semibold hover:bg-primary/90 shadow-sm transition-colors cursor-pointer">
                                    <x-icon name="plus" class="w-3.5 h-3.5" />
                                    <span>{{ __('+ Add Custom Type') }}</span>
                                </button>
                            @endcan
                            <button type="button" wire:click="$set('showTypesModal', false)"
                                    class="text-muted-foreground hover:text-foreground p-1.5 rounded-lg hover:bg-muted transition-colors cursor-pointer"
                                    aria-label="{{ __('Close modal') }}">
                                <x-icon name="x" class="w-5 h-5" />
                            </button>
                        </div>
                    </div>

                    <!-- Body (Scrollable) -->
                    <div class="p-3 sm:p-6 overflow-y-auto space-y-3 sm:space-y-4 flex-1 text-xs">
                        <!-- Mobile Scroll Hint -->
                        <div class="sm:hidden flex items-center gap-1.5 text-[11px] text-muted-foreground px-1">
                            <x-icon name="arrow-left-right" class="w-3.5 h-3.5 shrink-0 text-primary" />
                            <span>{{ __('Scroll horizontally to view all columns.') }}</span>
                        </div>

                        <div class="rounded-xl border border-border overflow-hidden bg-card">
                            <div class="overflow-x-auto scrollbar-thin">
                                <table class="w-full text-left divide-y divide-border min-w-[650px] sm:min-w-[700px]">
                                    <thead class="bg-muted/50 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                                        <tr>
                                            <th class="px-3.5 py-3 sm:px-4">{{ __('Type Name & Code') }}</th>
                                            <th class="px-3.5 py-3 sm:px-4">{{ __('Description') }}</th>
                                            <th class="px-3.5 py-3 sm:px-4 text-center">{{ __('Visibility & External API Access') }}</th>
                                            <th class="px-3.5 py-3 sm:px-4 text-center">{{ __('Scope') }}</th>
                                            <th class="px-3.5 py-3 sm:px-4 text-center">{{ __('Status') }}</th>
                                            <th class="px-3.5 py-3 sm:px-4 text-right">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border bg-card">
                                        @forelse($this->allMaterialTypesList as $type)
                                            <tr class="hover:bg-muted/30 transition-colors">
                                                <td class="px-3.5 py-3 sm:px-4">
                                                    <div class="flex items-center gap-2.5">
                                                        <div class="w-7 h-7 rounded-md bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                                            <x-icon name="{{ $type->icon ?? 'file-text' }}" class="w-3.5 h-3.5" />
                                                        </div>
                                                        <div class="min-w-0">
                                                            <span class="font-bold text-foreground block truncate">{{ $type->name }}</span>
                                                            <code class="text-[10px] text-muted-foreground bg-muted px-1.5 py-0.2 rounded font-mono">{{ $type->code }}</code>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-muted-foreground max-w-xs truncate" title="{{ $type->description }}">
                                                    {{ $type->description ?: '-' }}
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-center whitespace-nowrap">
                                                    @can('study_materials.edit')
                                                        <button type="button" wire:click="toggleTypeVisibility({{ $type->id }})"
                                                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold transition-all cursor-pointer shadow-xs {{ $type->is_public ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 hover:bg-emerald-100 border border-emerald-200 dark:border-emerald-800' : 'bg-muted text-muted-foreground hover:bg-muted/80 border border-border' }}"
                                                                title="{{ __('Click to toggle Public Web/App vs Internal Enrolled visibility') }}">
                                                            <x-icon name="{{ $type->is_public ? 'globe' : 'lock' }}" class="w-3 h-3 shrink-0" />
                                                            <span>{{ $type->is_public ? __('Public Web / App') : __('Internal Enrolled') }}</span>
                                                        </button>
                                                    @else
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-medium {{ $type->is_public ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800' : 'bg-muted text-muted-foreground border border-border' }}">
                                                            <x-icon name="{{ $type->is_public ? 'globe' : 'lock' }}" class="w-3 h-3 shrink-0" />
                                                            <span>{{ $type->is_public ? __('Public Web / App') : __('Internal Enrolled') }}</span>
                                                        </span>
                                                    @endcan
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-center whitespace-nowrap">
                                                    @if($type->is_system)
                                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                                                            {{ __('System Core') }}
                                                        </span>
                                                    @else
                                                        <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300">
                                                            {{ __('Custom Type') }}
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-center whitespace-nowrap">
                                                    @can('study_materials.edit')
                                                        <button type="button" wire:click="toggleTypeStatus({{ $type->id }})"
                                                                class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold transition-colors cursor-pointer {{ $type->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                            {{ $type->is_active ? __('Active') : __('Inactive') }}
                                                        </button>
                                                    @else
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold {{ $type->is_active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                                            {{ $type->is_active ? __('Active') : __('Inactive') }}
                                                        </span>
                                                    @endcan
                                                </td>
                                                <td class="px-3.5 py-3 sm:px-4 text-right whitespace-nowrap">
                                                    <div class="flex items-center justify-end gap-1">
                                                        @can('study_materials.edit')
                                                            <button type="button" wire:click="editType({{ $type->id }})"
                                                                    class="p-1.5 rounded text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer" title="{{ __('Edit') }}">
                                                                <x-icon name="edit-2" class="w-3.5 h-3.5" />
                                                            </button>
                                                        @endcan
                                                        @if(! $type->is_system)
                                                            @can('study_materials.delete')
                                                                <button type="button" wire:click="deleteType({{ $type->id }})" wire:confirm="{{ __('Are you sure you want to delete this custom material type?') }}"
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
                                                    {{ __('No material types cataloged yet.') }}
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
                        <span class="text-muted-foreground text-[11px] leading-relaxed text-center sm:text-left">{{ __('Material types can be used dynamically across admin uploads, public web APIs, and student portals.') }}</span>
                        <button type="button" wire:click="$set('showTypesModal', false)"
                                class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs transition-colors shrink-0 text-center cursor-pointer">
                            {{ __('Close') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- 7. CREATE / EDIT CUSTOM MATERIAL TYPE MODAL -->
    @if($showTypeFormModal)
        <div wire:key="study-material-type-form-modal" class="fixed inset-0 z-[60] overflow-y-auto">
            <!-- Dedicated Backdrop -->
            <div class="fixed inset-0 bg-background/80 backdrop-blur-sm transition-opacity"
                 wire:click="$set('showTypeFormModal', false)"
                 aria-hidden="true"></div>

            <!-- Centering container -->
            <div class="flex min-h-full items-center justify-center p-3 sm:p-6 text-center">
                <!-- Modal Card Container -->
                <div class="relative bg-card text-card-foreground rounded-xl sm:rounded-2xl border border-border shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden text-left align-middle animate-in fade-in-50 zoom-in-95"
                     @click.stop>
                    <!-- Header (Sticky) -->
                    <div class="px-4 py-3.5 sm:px-6 sm:py-4 flex items-center justify-between border-b border-border bg-muted/30 shrink-0">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                <x-icon name="tag" class="w-4 h-4" />
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-foreground truncate">
                                    {{ $editingTypeId ? __('Edit Material Type') : __('Add Custom Material Type') }}
                                </h3>
                                <p class="text-[11px] text-muted-foreground truncate">{{ __('Define a dynamic material category for publications, handouts, or public downloads.') }}</p>
                            </div>
                        </div>
                        <button type="button" wire:click="$set('showTypeFormModal', false)"
                                class="text-muted-foreground hover:text-foreground p-1.5 rounded-lg hover:bg-muted transition-colors shrink-0 cursor-pointer"
                                aria-label="{{ __('Close modal') }}">
                            <x-icon name="x" class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Body (Scrollable) -->
                    <div class="p-4 sm:p-6 overflow-y-auto space-y-3.5 sm:space-y-4 flex-1 text-xs scrollbar-thin">
                        <!-- Type Name -->
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Material Type Name') }} <span class="text-destructive">*</span></label>
                            <input type="text" wire:model="typeForm.name" placeholder="{{ __('e.g., PIB Sandhan, Monthly Current Affairs, Prelims Booster') }}"
                                   class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none transition-shadow" />
                            @error('typeForm.name') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        </div>

                        <!-- Code / Slug -->
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Code / Slug Identifier') }} <span class="text-muted-foreground font-normal">({{ __('Auto-generated if blank') }})</span></label>
                            <input type="text" wire:model="typeForm.code" placeholder="{{ __('e.g., pib_sandhan, current_affairs') }}"
                                   class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none font-mono transition-shadow" />
                            @error('typeForm.code') <span class="text-destructive text-[11px] mt-0.5 block">{{ $message }}</span> @enderror
                        </div>

                        <!-- Description -->
                        <div>
                            <label class="block font-semibold text-foreground mb-1">{{ __('Description') }}</label>
                            <textarea wire:model="typeForm.description" rows="2" placeholder="{{ __('Optional summary of what this material type covers...') }}"
                                      class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none transition-shadow"></textarea>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <!-- Icon -->
                            <div>
                                <label class="block font-semibold text-foreground mb-1">{{ __('Icon') }}</label>
                                <select wire:model="typeForm.icon" class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none transition-shadow">
                                    <option value="file-text">{{ __('File / Notes (file-text)') }}</option>
                                    <option value="newspaper">{{ __('Newspaper (newspaper)') }}</option>
                                    <option value="book-marked">{{ __('Book Marked (book-marked)') }}</option>
                                    <option value="book-open">{{ __('Book Open (book-open)') }}</option>
                                    <option value="presentation">{{ __('Slide Deck (presentation)') }}</option>
                                    <option value="video">{{ __('Video (video)') }}</option>
                                    <option value="headphones">{{ __('Audio (headphones)') }}</option>
                                    <option value="clipboard-check">{{ __('Question Bank (clipboard-check)') }}</option>
                                    <option value="compass">{{ __('Syllabus (compass)') }}</option>
                                    <option value="bookmark">{{ __('Compendium (bookmark)') }}</option>
                                    <option value="folder">{{ __('Folder (folder)') }}</option>
                                    <option value="layers">{{ __('Layers (layers)') }}</option>
                                </select>
                            </div>

                            <!-- Badge Color -->
                            <div>
                                <label class="block font-semibold text-foreground mb-1">{{ __('Badge Color') }}</label>
                                <select wire:model="typeForm.badge_color" class="w-full py-2 px-3 rounded-lg border border-border bg-background text-foreground text-xs focus:ring-2 focus:ring-primary focus:outline-none transition-shadow">
                                    <option value="primary">{{ __('Primary (Brand)') }}</option>
                                    <option value="emerald">{{ __('Emerald (Green)') }}</option>
                                    <option value="blue">{{ __('Blue') }}</option>
                                    <option value="amber">{{ __('Amber (Orange)') }}</option>
                                    <option value="purple">{{ __('Purple') }}</option>
                                    <option value="rose">{{ __('Rose (Red)') }}</option>
                                    <option value="cyan">{{ __('Cyan') }}</option>
                                    <option value="indigo">{{ __('Indigo') }}</option>
                                    <option value="slate">{{ __('Slate') }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Visibility & Status Controls -->
                        <div class="pt-3 border-t border-border space-y-3">
                            <span class="text-xs font-semibold text-foreground block">{{ __('Visibility & Access Settings') }}</span>

                            <!-- Visibility Toggle Card -->
                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-2">
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input type="checkbox" wire:model.live="typeForm.is_public" class="mt-0.5 rounded border-border text-primary focus:ring-primary h-4 w-4 shrink-0" />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-semibold text-foreground">{{ __('Public API Access & Discovery') }}</span>
                                            @if($typeForm['is_public'] ?? true)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 shrink-0">
                                                    <x-icon name="globe" class="w-3 h-3" />
                                                    <span>{{ __('Public Access') }}</span>
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-muted text-muted-foreground border border-border shrink-0">
                                                    <x-icon name="lock" class="w-3 h-3" />
                                                    <span>{{ __('Internal Only') }}</span>
                                                </span>
                                            @endif
                                        </div>
                                        <p class="text-[11px] text-muted-foreground leading-relaxed mt-1">
                                            @if($typeForm['is_public'] ?? true)
                                                {{ __('Public visitors on open web portals and mobile apps can discover, view, and download materials in this category via public REST APIs.') }}
                                            @else
                                                {{ __('Restricted strictly to authenticated enrolled students and internal faculty members. Hidden from external web visitors.') }}
                                            @endif
                                        </p>
                                    </div>
                                </label>
                            </div>

                            <!-- Active Status Toggle Card -->
                            <div class="p-3 rounded-xl border border-border bg-muted/20 space-y-2">
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input type="checkbox" wire:model.live="typeForm.is_active" class="mt-0.5 rounded border-border text-primary focus:ring-primary h-4 w-4 shrink-0" />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-semibold text-foreground">{{ __('Active Status') }}</span>
                                            @if($typeForm['is_active'] ?? true)
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
                                            {{ __('Active material types appear in admin upload dropdowns, category filters, and student resource hubs.') }}
                                        </p>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Footer (Sticky) -->
                    <div class="px-4 py-3 sm:px-6 sm:py-3.5 border-t border-border flex items-center justify-end gap-2.5 bg-muted/20 shrink-0">
                        <button type="button" wire:click="$set('showTypeFormModal', false)"
                                class="px-4 py-2 rounded-lg border border-border bg-card text-foreground hover:bg-muted font-medium text-xs transition-colors cursor-pointer">
                            {{ __('Cancel') }}
                        </button>
                        <button type="button" wire:click="saveType" wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg bg-primary text-primary-foreground font-semibold text-xs hover:bg-primary/90 shadow transition-colors cursor-pointer">
                            <x-icon name="check" class="w-4 h-4" />
                            <span wire:loading.remove wire:target="saveType">{{ $editingTypeId ? __('Update Type') : __('Create Type') }}</span>
                            <span wire:loading wire:target="saveType">{{ __('Saving...') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
