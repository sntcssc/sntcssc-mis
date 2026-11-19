<?php

namespace App\Services\StudyMaterial;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudyMaterial;
use App\Models\StudyMaterialAccessLog;
use App\Models\StudyMaterialFile;
use App\Models\StudyMaterialShare;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Services\FileUploadService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\TelegramService;
use App\Services\WhatsAppService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class StudyMaterialService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected WhatsAppService $whatsAppService,
        protected TelegramService $telegramService,
        protected EmailService $emailService,
        protected SmsService $smsService
    ) {}

    /**
     * Create study material with files, batch associations, and optional notification dispatch.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $uploadedFiles
     * @param  array<string, mixed>  $notifyConfig
     */
    public function createMaterial(array $data, array $uploadedFiles = [], array $notifyConfig = []): StudyMaterial
    {
        return DB::transaction(function () use ($data, $uploadedFiles, $notifyConfig) {
            $batchIds = $data['batch_ids'] ?? [];
            $studentIds = $data['student_ids'] ?? [];

            // Password hash if protected
            $sharePassword = ! empty($data['share_password']) ? Hash::make($data['share_password']) : null;

            $thumbnailPath = null;
            if (! empty($data['thumbnail_file']) && $data['thumbnail_file'] instanceof UploadedFile) {
                $thumbnailPath = $data['thumbnail_file']->store('study-materials/thumbnails', 'public');
            }

            $displayOrder = isset($data['display_order']) && $data['display_order'] !== ''
                ? (int) $data['display_order']
                : (int) (StudyMaterial::max('display_order') + 1);

            $material = StudyMaterial::create([
                'course_id' => ! empty($data['course_id']) ? (int) $data['course_id'] : null,
                'title' => trim($data['title']),
                'subject' => ! empty($data['subject']) ? trim($data['subject']) : null,
                'topic' => ! empty($data['topic']) ? trim($data['topic']) : null,
                'module_code' => ! empty($data['module_code']) ? trim($data['module_code']) : null,
                'material_type' => $data['material_type'] ?? StudyMaterial::TYPE_LECTURE_NOTES,
                'description' => $data['description'] ?? null,
                'content' => $data['content'] ?? null,
                'external_url' => ! empty($data['external_url']) ? trim($data['external_url']) : null,
                'thumbnail_path' => $thumbnailPath,
                'thumbnail_url' => ! empty($data['thumbnail_url']) ? trim($data['thumbnail_url']) : null,
                'class_date' => ! empty($data['class_date']) ? Carbon::parse($data['class_date'])->toDateString() : null,
                'display_order' => $displayOrder,
                'access_level' => $data['access_level'] ?? StudyMaterial::ACCESS_ENROLLED_ONLY,
                'share_token' => Str::random(40),
                'share_expires_at' => ! empty($data['share_expires_at']) ? Carbon::parse($data['share_expires_at']) : null,
                'share_password' => $sharePassword,
                'is_pinned' => (bool) ($data['is_pinned'] ?? false),
                'status' => $data['status'] ?? StudyMaterial::STATUS_PUBLISHED,
                'created_by' => auth()->id(),
            ]);

            // Sync batches
            if (! empty($batchIds)) {
                $material->batches()->sync($batchIds);
            }

            // Sync selective students if restricted
            if ($material->access_level === StudyMaterial::ACCESS_RESTRICTED_STUDENTS && ! empty($studentIds)) {
                $material->students()->sync($studentIds);
            }

            // Save uploaded files
            $this->storeFiles($material, $uploadedFiles);

            // Audit log
            AuditLogService::log(
                event: 'study_material_created',
                description: "Created study material '{$material->title}' for course #{$material->course_id}",
                newValues: [
                    'id' => $material->id,
                    'title' => $material->title,
                    'course_id' => $material->course_id,
                    'material_type' => $material->material_type,
                    'status' => $material->status,
                    'batches' => $batchIds,
                    'class_date' => $material->class_date?->toDateString(),
                    'display_order' => $material->display_order,
                ]
            );

            // Dispatch notification if requested
            if (! empty($notifyConfig['enabled'])) {
                $channels = $notifyConfig['channels'] ?? ['database', 'email'];
                $recipientType = $notifyConfig['recipient_type'] ?? 'batch'; // 'batch' or 'specific'
                $specificStudentIds = $notifyConfig['student_ids'] ?? [];
                $customMessage = $notifyConfig['custom_message'] ?? null;

                $this->shareMaterial(
                    material: $material,
                    channels: $channels,
                    recipientConfig: [
                        'type' => $recipientType,
                        'student_ids' => $specificStudentIds,
                    ],
                    customMessage: $customMessage
                );
            }

            return $material->load(['course', 'batches', 'files', 'students']);
        });
    }

    /**
     * Update study material, sync associations, and handle file additions / deletions.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $newFiles
     * @param  array<int>  $deleteFileIds
     */
    public function updateMaterial(
        StudyMaterial $material,
        array $data,
        array $newFiles = [],
        array $deleteFileIds = []
    ): StudyMaterial {
        return DB::transaction(function () use ($material, $data, $newFiles, $deleteFileIds) {
            $oldValues = $material->only(['title', 'course_id', 'subject', 'material_type', 'status', 'access_level', 'is_pinned', 'class_date', 'display_order']);

            $thumbnailPath = $material->thumbnail_path;
            if (! empty($data['remove_thumbnail'])) {
                if ($thumbnailPath && Storage::disk('public')->exists($thumbnailPath)) {
                    Storage::disk('public')->delete($thumbnailPath);
                }
                $thumbnailPath = null;
            }

            if (! empty($data['thumbnail_file']) && $data['thumbnail_file'] instanceof UploadedFile) {
                if ($thumbnailPath && Storage::disk('public')->exists($thumbnailPath)) {
                    Storage::disk('public')->delete($thumbnailPath);
                }
                $thumbnailPath = $data['thumbnail_file']->store('study-materials/thumbnails', 'public');
            }

            $updatePayload = [
                'course_id' => array_key_exists('course_id', $data) ? (! empty($data['course_id']) ? (int) $data['course_id'] : null) : $material->course_id,
                'title' => trim($data['title']),
                'subject' => ! empty($data['subject']) ? trim($data['subject']) : null,
                'topic' => ! empty($data['topic']) ? trim($data['topic']) : null,
                'module_code' => ! empty($data['module_code']) ? trim($data['module_code']) : null,
                'material_type' => $data['material_type'] ?? $material->material_type,
                'description' => $data['description'] ?? null,
                'content' => $data['content'] ?? null,
                'external_url' => ! empty($data['external_url']) ? trim($data['external_url']) : null,
                'thumbnail_path' => $thumbnailPath,
                'thumbnail_url' => array_key_exists('thumbnail_url', $data) ? (! empty($data['thumbnail_url']) ? trim($data['thumbnail_url']) : null) : $material->thumbnail_url,
                'class_date' => array_key_exists('class_date', $data) ? (! empty($data['class_date']) ? Carbon::parse($data['class_date'])->toDateString() : null) : $material->class_date,
                'display_order' => array_key_exists('display_order', $data) ? (int) $data['display_order'] : $material->display_order,
                'access_level' => $data['access_level'] ?? $material->access_level,
                'share_expires_at' => ! empty($data['share_expires_at']) ? Carbon::parse($data['share_expires_at']) : null,
                'is_pinned' => (bool) ($data['is_pinned'] ?? false),
                'status' => $data['status'] ?? $material->status,
                'updated_by' => auth()->id(),
            ];

            if (! empty($data['share_password'])) {
                $updatePayload['share_password'] = Hash::make($data['share_password']);
            } elseif (array_key_exists('remove_share_password', $data) && $data['remove_share_password']) {
                $updatePayload['share_password'] = null;
            }

            $material->update($updatePayload);

            // Sync batches
            if (isset($data['batch_ids'])) {
                $material->batches()->sync($data['batch_ids']);
            }

            // Sync selective students
            if ($material->access_level === StudyMaterial::ACCESS_RESTRICTED_STUDENTS && isset($data['student_ids'])) {
                $material->students()->sync($data['student_ids']);
            }

            // Delete requested files
            if (! empty($deleteFileIds)) {
                $filesToDelete = StudyMaterialFile::where('study_material_id', $material->id)
                    ->whereIn('id', $deleteFileIds)
                    ->get();

                foreach ($filesToDelete as $file) {
                    if (Storage::disk($file->disk ?? 'public')->exists($file->file_path)) {
                        Storage::disk($file->disk ?? 'public')->delete($file->file_path);
                    }
                    $file->delete();
                }
            }

            // Store new files
            $this->storeFiles($material, $newFiles);

            // Audit log
            AuditLogService::log(
                event: 'study_material_updated',
                description: "Updated study material '{$material->title}' (#{$material->id})",
                oldValues: $oldValues,
                newValues: $material->only(['title', 'course_id', 'subject', 'material_type', 'status', 'access_level', 'is_pinned', 'class_date', 'display_order'])
            );

            return $material->load(['course', 'batches', 'files', 'students']);
        });
    }

    /**
     * Update material display order sequence.
     */
    public function updateDisplayOrder(StudyMaterial $material, int $newOrder): StudyMaterial
    {
        $material->update(['display_order' => max(0, $newOrder)]);

        AuditLogService::log(
            event: 'study_material_reordered',
            description: "Updated display order of study material '{$material->title}' to {$newOrder}",
            newValues: ['display_order' => $newOrder]
        );

        return $material;
    }

    /**
     * Swap or move display order up or down.
     */
    public function moveDisplayOrder(StudyMaterial $material, string $direction = 'up'): bool
    {
        $currentOrder = (int) $material->display_order;

        if ($direction === 'up') {
            $swapWith = StudyMaterial::where('display_order', '<', $currentOrder)
                ->orderByDesc('display_order')
                ->first();
        } else {
            $swapWith = StudyMaterial::where('display_order', '>', $currentOrder)
                ->orderBy('display_order')
                ->first();
        }

        if (! $swapWith) {
            $newOrder = $direction === 'up' ? max(0, $currentOrder - 1) : $currentOrder + 1;
            $material->update(['display_order' => $newOrder]);

            return true;
        }

        $swapOrder = (int) $swapWith->display_order;
        $material->update(['display_order' => $swapOrder]);
        $swapWith->update(['display_order' => $currentOrder]);

        return true;
    }

    /**
     * Store uploaded files for a study material.
     *
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $files
     */
    protected function storeFiles(StudyMaterial $material, array $files): void
    {
        $currentMaxOrder = (int) $material->files()->max('display_order');

        foreach ($files as $file) {
            if (! $file) {
                continue;
            }

            $currentMaxOrder++;
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
            $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
            $fileSize = $file->getSize() ?: 0;

            // Store file using FileUploadService
            $storedPath = FileUploadService::store(
                file: $file,
                folder: "study-materials/{$material->course_id}",
                prefix: Str::slug(Str::limit($material->title, 20, '')),
                disk: 'public'
            );

            StudyMaterialFile::create([
                'study_material_id' => $material->id,
                'file_name' => $originalName,
                'file_path' => $storedPath,
                'file_size' => $fileSize,
                'file_type' => $extension,
                'mime_type' => $mimeType,
                'disk' => 'public',
                'download_count' => 0,
                'display_order' => $currentMaxOrder,
            ]);
        }
    }

    /**
     * Attach new files to an existing study material with DB transaction and audit log.
     *
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $files
     * @return array<int, StudyMaterialFile>
     */
    public function attachFiles(StudyMaterial $material, array $files, ?User $actor = null): array
    {
        return DB::transaction(function () use ($material, $files, $actor) {
            $currentMaxOrder = (int) $material->files()->max('display_order');
            $createdModels = [];

            foreach ($files as $file) {
                if (! $file) {
                    continue;
                }

                $currentMaxOrder++;
                $originalName = $file->getClientOriginalName();
                $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
                $mimeType = $file->getClientMimeType() ?: 'application/octet-stream';
                $fileSize = $file->getSize() ?: 0;

                $storedPath = FileUploadService::store(
                    file: $file,
                    folder: "study-materials/{$material->course_id}",
                    prefix: Str::slug(Str::limit($material->title, 20, '')),
                    disk: 'public'
                );

                $fileModel = StudyMaterialFile::create([
                    'study_material_id' => $material->id,
                    'file_name' => $originalName,
                    'file_path' => $storedPath,
                    'file_size' => $fileSize,
                    'file_type' => $extension,
                    'mime_type' => $mimeType,
                    'disk' => 'public',
                    'download_count' => 0,
                    'display_order' => $currentMaxOrder,
                ]);

                $createdModels[] = $fileModel;
            }

            AuditLogService::log(
                event: 'study_material_files_attached',
                description: 'Attached '.count($createdModels)." file(s) to study material '{$material->title}' (#{$material->id})",
                auditable: $material,
                newValues: ['attached_count' => count($createdModels)],
                userId: $actor?->id
            );

            return $createdModels;
        });
    }

    /**
     * Detach (soft delete) a file from a study material with DB transaction and audit log.
     */
    public function detachFile(StudyMaterial $material, int $fileId, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($material, $fileId, $actor) {
            $file = StudyMaterialFile::where('study_material_id', $material->id)
                ->where('id', $fileId)
                ->firstOrFail();

            $fileName = $file->file_name;

            if (Storage::disk($file->disk ?? 'public')->exists($file->file_path)) {
                Storage::disk($file->disk ?? 'public')->delete($file->file_path);
            }

            $file->delete();

            AuditLogService::log(
                event: 'study_material_file_detached',
                description: "Deleted file '{$fileName}' (#{$fileId}) from study material '{$material->title}'",
                auditable: $material,
                userId: $actor?->id
            );

            return true;
        });
    }

    /**
     * Soft delete a study material.
     */
    public function deleteMaterial(StudyMaterial $material): bool
    {
        return DB::transaction(function () use ($material) {
            $title = $material->title;
            $id = $material->id;

            $result = $material->delete();

            AuditLogService::log(
                event: 'study_material_deleted',
                description: "Soft deleted study material '{$title}' (#{$id})",
                oldValues: ['id' => $id, 'title' => $title]
            );

            return (bool) $result;
        });
    }

    /**
     * Restore a soft-deleted study material.
     */
    public function restoreMaterial(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $material = StudyMaterial::withTrashed()->findOrFail($id);
            $material->restore();

            AuditLogService::log(
                event: 'study_material_restored',
                description: "Restored study material '{$material->title}' (#{$id})",
                newValues: ['id' => $id, 'title' => $material->title]
            );

            return true;
        });
    }

    /**
     * Force delete a study material and its physical storage files.
     */
    public function forceDeleteMaterial(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $material = StudyMaterial::withTrashed()->with(['files'])->findOrFail($id);

            // Remove physical files
            foreach ($material->files as $file) {
                if (Storage::disk($file->disk ?? 'public')->exists($file->file_path)) {
                    Storage::disk($file->disk ?? 'public')->delete($file->file_path);
                }
            }

            $title = $material->title;
            $material->forceDelete();

            AuditLogService::log(
                event: 'study_material_purged',
                description: "Permanently purged study material '{$title}' (#{$id}) and associated files",
                oldValues: ['id' => $id, 'title' => $title]
            );

            return true;
        });
    }

    /**
     * Share study material with students across channels (WhatsApp, Email, SMS, Telegram, In-App).
     *
     * @param  array<string>  $channels  ['whatsapp', 'email', 'sms', 'telegram', 'in_app']
     * @param  array{type: string, student_ids?: array<int>}  $recipientConfig
     * @return array{total_recipients: int, successful: int, failed: int, channels: array<string, int>}
     */
    public function shareMaterial(
        StudyMaterial $material,
        array $channels,
        array $recipientConfig = ['type' => 'batch'],
        ?string $customMessage = null
    ): array {
        $material->loadMissing(['course', 'batches', 'files']);

        // Resolve recipients
        $recipients = $this->resolveRecipients($material, $recipientConfig);

        $successful = 0;
        $failed = 0;
        $channelStats = array_fill_keys($channels, 0);

        $downloadUrl = $material->files->isNotEmpty()
            ? route('study-materials.download', ['studyMaterial' => $material->id])
            : ($material->external_url ?: route('student.study-materials.index'));

        $publicUrl = $material->public_share_url;

        $title = $material->title;
        $courseName = $material->course?->name ?? 'Course';
        $batchNames = $material->batches->pluck('name')->implode(', ') ?: 'All Batches';
        $subject = $material->subject ? "Subject: {$material->subject}" : '';

        // Prepare message payloads
        $notificationTitle = "New Study Material: {$title}";
        $notificationBody = $customMessage ?: "Study material '{$title}' has been uploaded for {$courseName} ({$batchNames}). {$subject}";

        $waText = "*SNTCSSC Study Material*\n";
        $waText .= "📖 *{$title}*\n";
        $waText .= "🎓 Course: {$courseName}\n";
        $waText .= "🏷️ Batches: {$batchNames}\n";
        if ($material->subject) {
            $waText .= "📚 Subject: {$material->subject}\n";
        }
        if ($material->topic) {
            $waText .= "📝 Topic: {$material->topic}\n";
        }
        if (! empty($customMessage)) {
            $waText .= "\n💬 *Note from Faculty:*\n{$customMessage}\n";
        }
        $waText .= "\n🔗 Access / Download Material:\n{$downloadUrl}";

        $smsText = "SNTCSSC: New material '{$title}' is available for {$courseName}. Access here: {$downloadUrl}";

        foreach ($recipients as $recipient) {
            $hasSuccessForRecipient = false;

            // 1. In-App Notification (Database & Echo broadcast)
            if (in_array('in_app', $channels, true) || in_array('database', $channels, true)) {
                try {
                    $this->notificationService->sendInternal(
                        user: $recipient,
                        type: 'study_material',
                        title: $notificationTitle,
                        body: $notificationBody,
                        actionUrl: $downloadUrl,
                        actionLabel: __('Open Study Material')
                    );
                    $channelStats['in_app'] = ($channelStats['in_app'] ?? 0) + 1;
                    $this->recordShareLog($material, StudyMaterialShare::CHANNEL_IN_APP, $recipient, 'sent');
                    $hasSuccessForRecipient = true;
                } catch (Throwable $e) {
                    $this->recordShareLog($material, StudyMaterialShare::CHANNEL_IN_APP, $recipient, 'failed', $e->getMessage());
                }
            }

            // 2. Email Channel
            if (in_array(StudyMaterialShare::CHANNEL_EMAIL, $channels, true) && ! empty($recipient->email)) {
                try {
                    $emailHtml = view('emails.study_material_notification', [
                        'user' => $recipient,
                        'material' => $material,
                        'customMessage' => $customMessage,
                        'downloadUrl' => $downloadUrl,
                        'publicUrl' => $publicUrl,
                    ])->render();

                    $res = $this->emailService->sendDirect($recipient->email, $notificationTitle, $emailHtml);
                    if ($res['success']) {
                        $channelStats['email'] = ($channelStats['email'] ?? 0) + 1;
                        $this->recordShareLog($material, StudyMaterialShare::CHANNEL_EMAIL, $recipient, 'sent');
                        $hasSuccessForRecipient = true;
                    } else {
                        $this->recordShareLog($material, StudyMaterialShare::CHANNEL_EMAIL, $recipient, 'failed', $res['error'] ?? 'Email delivery failed');
                    }
                } catch (Throwable $e) {
                    $this->recordShareLog($material, StudyMaterialShare::CHANNEL_EMAIL, $recipient, 'failed', $e->getMessage());
                }
            }

            // 3. WhatsApp Channel
            if (in_array(StudyMaterialShare::CHANNEL_WHATSAPP, $channels, true)) {
                $phone = $recipient->whatsapp_no ?: $recipient->phone;
                if (! empty($phone)) {
                    try {
                        $res = $this->whatsAppService->sendDirect($phone, $waText);
                        if ($res['success']) {
                            $channelStats['whatsapp'] = ($channelStats['whatsapp'] ?? 0) + 1;
                            $this->recordShareLog($material, StudyMaterialShare::CHANNEL_WHATSAPP, $recipient, 'sent');
                            $hasSuccessForRecipient = true;
                        } else {
                            $this->recordShareLog($material, StudyMaterialShare::CHANNEL_WHATSAPP, $recipient, 'failed', $res['error'] ?? 'WhatsApp delivery failed');
                        }
                    } catch (Throwable $e) {
                        $this->recordShareLog($material, StudyMaterialShare::CHANNEL_WHATSAPP, $recipient, 'failed', $e->getMessage());
                    }
                }
            }

            // 4. SMS Channel
            if (in_array(StudyMaterialShare::CHANNEL_SMS, $channels, true) && ! empty($recipient->phone)) {
                try {
                    $res = $this->smsService->sendDirect($recipient->phone, $smsText);
                    if ($res['success']) {
                        $channelStats['sms'] = ($channelStats['sms'] ?? 0) + 1;
                        $this->recordShareLog($material, StudyMaterialShare::CHANNEL_SMS, $recipient, 'sent');
                        $hasSuccessForRecipient = true;
                    } else {
                        $this->recordShareLog($material, StudyMaterialShare::CHANNEL_SMS, $recipient, 'failed', $res['error'] ?? 'SMS delivery failed');
                    }
                } catch (Throwable $e) {
                    $this->recordShareLog($material, StudyMaterialShare::CHANNEL_SMS, $recipient, 'failed', $e->getMessage());
                }
            }

            // 5. Telegram Channel
            if (in_array(StudyMaterialShare::CHANNEL_TELEGRAM, $channels, true)) {
                try {
                    $res = $this->telegramService->sendFormatted(
                        title: $notificationTitle,
                        body: $notificationBody,
                        actionUrl: $downloadUrl,
                        actionLabel: 'Download Material'
                    );
                    if ($res['success']) {
                        $channelStats['telegram'] = ($channelStats['telegram'] ?? 0) + 1;
                        $this->recordShareLog($material, StudyMaterialShare::CHANNEL_TELEGRAM, $recipient, 'sent');
                        $hasSuccessForRecipient = true;
                    } else {
                        $this->recordShareLog($material, StudyMaterialShare::CHANNEL_TELEGRAM, $recipient, 'failed', $res['error'] ?? 'Telegram delivery failed');
                    }
                } catch (Throwable $e) {
                    $this->recordShareLog($material, StudyMaterialShare::CHANNEL_TELEGRAM, $recipient, 'failed', $e->getMessage());
                }
            }

            if ($hasSuccessForRecipient) {
                $successful++;
            } else {
                $failed++;
            }
        }

        AuditLogService::log(
            event: 'study_material_shared',
            description: "Shared study material '{$material->title}' with {$recipients->count()} recipients across channels: ".implode(', ', $channels),
            newValues: [
                'study_material_id' => $material->id,
                'channels' => $channels,
                'total_recipients' => $recipients->count(),
                'successful' => $successful,
                'failed' => $failed,
            ]
        );

        return [
            'total_recipients' => $recipients->count(),
            'successful' => $successful,
            'failed' => $failed,
            'channels' => $channelStats,
        ];
    }

    /**
     * Resolve user recipients for study material based on configuration.
     *
     * @param  array{type: string, student_ids?: array<int>}  $recipientConfig
     * @return Collection<int, User>
     */
    public function resolveRecipients(StudyMaterial $material, array $recipientConfig = []): Collection
    {
        $type = $recipientConfig['type'] ?? 'batch';
        $specificStudentIds = $recipientConfig['student_ids'] ?? [];

        if ($type === 'specific' && ! empty($specificStudentIds)) {
            return User::whereIn('id', $specificStudentIds)->get();
        }

        // Default to students enrolled in course and attached batches
        $batchIds = $material->batches()->pluck('batches.id')->all();

        $query = Enrollment::where('course_id', $material->course_id)
            ->where('status', Enrollment::STATUS_ACTIVE);

        if (! empty($batchIds)) {
            $query->whereIn('batch_id', $batchIds);
        }

        $userIds = $query->pluck('user_id')->unique()->values();

        return User::whereIn('id', $userIds)->get();
    }

    /**
     * Record a share audit log row.
     */
    protected function recordShareLog(
        StudyMaterial $material,
        string $channel,
        User $recipient,
        string $status,
        ?string $errorMessage = null
    ): StudyMaterialShare {
        return StudyMaterialShare::create([
            'study_material_id' => $material->id,
            'channel' => $channel,
            'recipient_type' => 'student',
            'recipient_id' => $recipient->id,
            'recipient_contact' => $channel === StudyMaterialShare::CHANNEL_EMAIL ? $recipient->email : ($recipient->whatsapp_no ?: $recipient->phone),
            'recipient_name' => $recipient->name,
            'status' => $status,
            'error_message' => $errorMessage,
            'shared_by' => auth()->id(),
            'metadata' => [
                'title' => $material->title,
                'course_id' => $material->course_id,
            ],
        ]);
    }

    /**
     * Record access (view, download, external link click) to study material.
     */
    public function recordAccess(
        StudyMaterial $material,
        string $action = StudyMaterialAccessLog::ACTION_VIEW,
        ?int $fileId = null,
        ?User $user = null,
        ?Subscriber $subscriber = null,
        ?array $leadDetails = null,
        bool $isVerified = false,
        array $metadata = []
    ): void {
        try {
            $effectiveUser = $user ?: auth()->user();

            if ($action === StudyMaterialAccessLog::ACTION_DOWNLOAD) {
                $material->increment('download_count');
                if ($fileId) {
                    StudyMaterialFile::where('id', $fileId)->increment('download_count');
                }
            } else {
                $material->increment('view_count');
            }

            StudyMaterialAccessLog::create([
                'study_material_id' => $material->id,
                'study_material_file_id' => $fileId,
                'user_id' => $effectiveUser?->id,
                'subscriber_id' => $subscriber?->id,
                'action' => $action,
                'guest_name' => $leadDetails['name'] ?? null,
                'guest_email' => $leadDetails['email'] ?? null,
                'guest_phone' => $leadDetails['phone'] ?? null,
                'is_verified' => $isVerified,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'metadata' => ! empty($metadata) ? $metadata : null,
            ]);
        } catch (Throwable $e) {
            Log::warning("StudyMaterialService recordAccess failed: {$e->getMessage()}");
        }
    }

    /**
     * Get real-time statistics & KPIs for study material management.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $totalMaterials = StudyMaterial::count();
        $publishedMaterials = StudyMaterial::where('status', StudyMaterial::STATUS_PUBLISHED)->count();
        $draftMaterials = StudyMaterial::where('status', StudyMaterial::STATUS_DRAFT)->count();
        $archivedMaterials = StudyMaterial::where('status', StudyMaterial::STATUS_ARCHIVED)->count();

        $totalFiles = StudyMaterialFile::count();
        $totalStorageBytes = (int) StudyMaterialFile::sum('file_size');
        $totalDownloads = (int) StudyMaterial::sum('download_count');
        $totalViews = (int) StudyMaterial::sum('view_count');

        $totalShares = StudyMaterialShare::count();
        $sharesByChannel = [
            'whatsapp' => StudyMaterialShare::where('channel', StudyMaterialShare::CHANNEL_WHATSAPP)->count(),
            'email' => StudyMaterialShare::where('channel', StudyMaterialShare::CHANNEL_EMAIL)->count(),
            'sms' => StudyMaterialShare::where('channel', StudyMaterialShare::CHANNEL_SMS)->count(),
            'telegram' => StudyMaterialShare::where('channel', StudyMaterialShare::CHANNEL_TELEGRAM)->count(),
            'in_app' => StudyMaterialShare::where('channel', StudyMaterialShare::CHANNEL_IN_APP)->count(),
        ];

        // Type breakdown
        $typeDistribution = StudyMaterial::select('material_type', DB::raw('count(*) as count'))
            ->groupBy('material_type')
            ->pluck('count', 'material_type')
            ->all();

        // Course breakdown
        $courseDistribution = Course::has('studyMaterials')
            ->withCount('studyMaterials')
            ->orderByDesc('study_materials_count')
            ->limit(8)
            ->get(['id', 'name', 'code']);

        return [
            'total_materials' => $totalMaterials,
            'published_materials' => $publishedMaterials,
            'draft_materials' => $draftMaterials,
            'archived_materials' => $archivedMaterials,
            'total_files' => $totalFiles,
            'total_storage_bytes' => $totalStorageBytes,
            'formatted_storage' => $this->formatBytes($totalStorageBytes),
            'total_downloads' => $totalDownloads,
            'total_views' => $totalViews,
            'total_shares' => $totalShares,
            'shares_by_channel' => $sharesByChannel,
            'type_distribution' => $typeDistribution,
            'course_distribution' => $courseDistribution,
        ];
    }

    /**
     * Helper to format byte sizes into readable units.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $power), 2).' '.($units[$power] ?? 'B');
    }
}
